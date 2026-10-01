<?php

declare(strict_types=1);

/**
 * STORAGE-SKEL — Lo que necesita una instalación NUEVA hecha desde el zip.
 *
 * `build_package.php` excluía ENTERAS `storage/uploads`, `logs`, `documents`,
 * `resources` y `cache` (para no empaquetar datos de nadie), y con ellas se iban
 * también las carpetas y sus `.htaccess`. Resultado en una instalación desde la
 * release:
 *   - el paso 1 del instalador fallaba: `storage/uploads` y `storage/logs` no
 *     existían → «no escribible» (crítico);
 *   - y esas carpetas, al crearlas la app, nacían SIN su `Require all denied`.
 *
 * Además `storage/updates/` (copias de seguridad con `config/config.php` dentro)
 * nunca ha tenido `.htaccess`. Por eso la protección de fondo va en el
 * `.htaccess` raíz, que el actualizador sí despliega: así llega también a las
 * instalaciones que ya existen.
 *
 * Y el actualizador copiaba el `.htaccess` raíz encima del que hubiera,
 * llevándose los bloques que escribe cPanel (la versión de PHP del sitio).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\UpdateInstallerService;

$failed = 0;
function check_pkg(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$tmp = sys_get_temp_dir() . '/pp-pkg-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);

// ---------------------------------------------------------------------------
// 1. El paquete lleva el esqueleto de storage/, y nada de su contenido
// ---------------------------------------------------------------------------
// Datos de mentira en las carpetas: tienen que quedarse FUERA del zip.

$sentinels = [
    PP_STORAGE . '/uploads/pp-test-no-empaquetar.jpg',
    PP_STORAGE . '/logs/pp-test-no-empaquetar.log',
    PP_STORAGE . '/documents/pp-test-sub/no-empaquetar.pdf',
];
$zipPath = $tmp . '/p.zip';
try {
    foreach ($sentinels as $s) {
        @mkdir(dirname($s), 0775, true);
        file_put_contents($s, 'privado');
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(PP_ROOT . '/scripts/build_package.php')
        . ' --out=' . escapeshellarg($zipPath) . ' 2>&1', $out, $code);
} finally {
    foreach ($sentinels as $s) @unlink($s);
    @rmdir(PP_STORAGE . '/documents/pp-test-sub');
}
check_pkg('el paquete se arma', $code === 0 && is_file($zipPath), implode("\n", $out));

$names = [];
$zip = new ZipArchive();
if ($zip->open($zipPath) === true) {
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string) $zip->getNameIndex($i);
    $zip->close();
}

foreach (['uploads', 'documents', 'resources', 'logs', 'cache'] as $d) {
    check_pkg("storage/{$d}/ llega creada", in_array("storage/{$d}/", $names, true));
    check_pkg("storage/{$d}/.htaccess viaja", in_array("storage/{$d}/.htaccess", $names, true));
}
$leaked = array_values(array_filter($names, static fn(string $n): bool => str_contains($n, 'no-empaquetar')));
check_pkg('sin datos de las carpetas de storage', $leaked === [], json_encode($leaked));
check_pkg('sin config.php', !in_array('config/config.php', $names, true));

// ---------------------------------------------------------------------------
// 2. El .htaccess raíz cierra storage/ (menos uploads/)
// ---------------------------------------------------------------------------

$root = (string) file_get_contents(PP_ROOT . '/.htaccess');
$deny = strpos($root, 'RewriteRule ^storage/(?!uploads/) - [F,L]');
$noScripts = strpos($root, 'RewriteRule ^storage/uploads/.+\.(php[0-9]?|phtml|phar|pl|py|cgi|sh)$ - [F,NC,L]');
$front = strpos($root, 'RewriteRule ^ index.php');
check_pkg('niega storage/ salvo uploads/', $deny !== false);
check_pkg('y scripts dentro de uploads/', $noScripts !== false);
check_pkg('antes del front controller', $deny !== false && $noScripts !== false && $front !== false
    && $deny < $front && $noScripts < $front);

// ---------------------------------------------------------------------------
// 3. Actualizar no se lleva los bloques de cPanel del .htaccess
// ---------------------------------------------------------------------------

$cpanel = "# php -- BEGIN cPanel-generated handler, do not edit\n"
    . "<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n"
    . "# php -- END cPanel-generated handler, do not edit\n";
$new = "RewriteEngine On\nRewriteRule ^ index.php [QSA,L]\n";

file_put_contents($tmp . '/src', $new);
file_put_contents($tmp . '/dest', "RewriteEngine On\n# versión vieja\n\n" . $cpanel);
UpdateInstallerService::deployHtaccess($tmp . '/src', $tmp . '/dest');
$after = (string) file_get_contents($tmp . '/dest');
check_pkg('el .htaccess nuevo se despliega', str_starts_with($after, $new) && !str_contains($after, 'versión vieja'), $after);
check_pkg('con el bloque de cPanel conservado', str_contains($after, 'ea-php82'), $after);
UpdateInstallerService::deployHtaccess($tmp . '/src', $tmp . '/dest');
check_pkg('actualizar dos veces no lo duplica', substr_count((string) file_get_contents($tmp . '/dest'), 'BEGIN cPanel') === 1);

file_put_contents($tmp . '/dest', "Options -Indexes\n");
UpdateInstallerService::deployHtaccess($tmp . '/src', $tmp . '/dest');
check_pkg('sin bloques de cPanel queda el nuevo tal cual', file_get_contents($tmp . '/dest') === $new);

@unlink($tmp . '/dest');
UpdateInstallerService::deployHtaccess($tmp . '/src', $tmp . '/dest');
check_pkg('si no había, se copia', file_get_contents($tmp . '/dest') === $new);

// ---------------------------------------------------------------------------

foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
