<?php

declare(strict_types=1);

/**
 * UPD — Actualización desde un ZIP subido a mano y vuelta atrás.
 *
 * CUIDADO: este test ejecuta el despliegue REAL sobre la instalación donde
 * corre; no hay forma de probar `deploy()` de verdad sin hacerlo. Para que sea
 * inofensivo, el paquete de prueba lleva copias BYTE A BYTE de los archivos que
 * ya existen (index.php, config/constants.php…), de modo que sobrescribirlos no
 * cambia nada, más un archivo centinela en `storage/` que se borra al final.
 *
 * La primera versión de este test metía un `index.php` de mentira en el paquete
 * y dejó la instalación de desarrollo sin front controller. De ahí la regla:
 * un paquete de prueba NUNCA lleva contenido inventado para una ruta real.
 *
 * Solo corre en desarrollo: en producción se niega.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\MaintenanceMode;
use App\Services\UpdateInstallerService;

if (PP_ENV !== 'development' && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Este test despliega archivos sobre la instalación actual.\n"
        . "Solo se ejecuta con PP_ENV=development (o --force si sabes lo que haces).\n");
    exit(2);
}

$failed = 0;
function check_upd(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

// El centinela vive en `storage/` (la raíz de storage NO está excluida del
// despliegue; sus subcarpetas de datos sí).
$sentinelRel = 'storage/.pp-update-test-sentinel';
$sentinelAbs = PP_ROOT . '/' . $sentinelRel;
$tmp = sys_get_temp_dir() . '/pp-upd-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);

/**
 * Paquete con la huella de PromptPress usando COPIAS EXACTAS de archivos reales
 * (sobrescribirlos es un no-op) + los extras que pida el test.
 */
function build_package(string $dir, string $name, array $extraFiles = []): string
{
    $root = $dir . '/' . $name;
    // Archivos reales que forman la huella: se copian tal cual.
    $mirror = [
        'index.php'                 => PP_ROOT . '/index.php',
        'config/constants.php'      => PP_ROOT . '/config/constants.php',
        'core/App.php'              => PP_ROOT . '/core/App.php',
        'app/routes.php'            => PP_ROOT . '/app/routes.php',
    ];
    foreach ($mirror as $rel => $src) {
        $abs = $root . '/' . $rel;
        if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
        copy($src, $abs);
    }
    // La huella pide database/migrations: copiamos una migración existente.
    $someMigration = (glob(PP_ROOT . '/database/migrations/*.sql') ?: [])[0] ?? null;
    if ($someMigration !== null) {
        mkdir($root . '/database/migrations', 0775, true);
        copy($someMigration, $root . '/database/migrations/' . basename($someMigration));
    }

    foreach ($extraFiles as $rel => $contents) {
        $abs = $root . '/' . $rel;
        if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $contents);
    }

    $zipPath = $dir . '/' . $name . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) continue;
        $zip->addFile($f->getPathname(), $name . '/' . ltrim(str_replace($root, '', $f->getPathname()), '/'));
    }
    $zip->close();
    return $zipPath;
}

function fake_upload(string $path): array
{
    $copy = $path . '.upload';
    copy($path, $copy);
    return ['name' => basename($path), 'tmp_name' => $copy, 'size' => filesize($copy), 'error' => UPLOAD_ERR_OK];
}

$indexHashBefore = hash_file('sha256', PP_ROOT . '/index.php');
$constantsHashBefore = hash_file('sha256', PP_ROOT . '/config/constants.php');

// ---------------------------------------------------------------
// 1. Un ZIP que no es PromptPress no se despliega
// ---------------------------------------------------------------
$bogusZip = $tmp . '/bogus.zip';
file_put_contents($tmp . '/hola.txt', 'no soy promptpress');
$z = new ZipArchive();
$z->open($bogusZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFile($tmp . '/hola.txt', 'cualquier-cosa/hola.txt');
$z->close();

$msg = '';
try { UpdateInstallerService::applyFromUpload(fake_upload($bogusZip)); } catch (\Throwable $e) { $msg = $e->getMessage(); }
check_upd('un ZIP que no es PromptPress se rechaza', $msg !== '', 'no lanzó error');
check_upd('el error dice qué falta', str_contains($msg, 'no parece un paquete de PromptPress'), $msg);
check_upd('no deja el sitio en mantenimiento', !MaintenanceMode::isActive());

// ---------------------------------------------------------------
// 2. Un archivo que no es ZIP
// ---------------------------------------------------------------
$notZip = $tmp . '/malo.zip';
file_put_contents($notZip, 'esto es texto plano, no un zip');
$msg2 = '';
try { UpdateInstallerService::applyFromUpload(fake_upload($notZip)); } catch (\Throwable $e) { $msg2 = $e->getMessage(); }
check_upd('un archivo que no es ZIP se rechaza', str_contains($msg2, 'no es un ZIP'), $msg2);

// ---------------------------------------------------------------
// 3. Checksum que no cuadra
// ---------------------------------------------------------------
$goodZip = build_package($tmp, 'paquete-v1', [$sentinelRel => "centinela v1\n"]);
$msg3 = '';
try { UpdateInstallerService::applyFromUpload(fake_upload($goodZip), str_repeat('a', 64)); } catch (\Throwable $e) { $msg3 = $e->getMessage(); }
check_upd('un checksum que no cuadra detiene la actualización', str_contains($msg3, 'Checksum'), $msg3);
check_upd('con checksum malo no se despliega nada', !is_file($sentinelAbs));

// ---------------------------------------------------------------
// 4. Actualización correcta
// ---------------------------------------------------------------
$configBefore = is_file(PP_CONFIG_FILE) ? file_get_contents(PP_CONFIG_FILE) : null;
$backupsBefore = count(UpdateInstallerService::backups());

$result = UpdateInstallerService::applyFromUpload(fake_upload($goodZip), hash_file('sha256', $goodZip));

check_upd('el archivo nuevo del paquete se despliega', is_file($sentinelAbs) && trim((string) file_get_contents($sentinelAbs)) === 'centinela v1');
check_upd('lee la versión declarada en el paquete', ($result['version'] ?? '') === PP_VERSION, var_export($result['version'] ?? null, true));
check_upd('deja copia de seguridad en disco', is_file((string) $result['backup']));
check_upd('la copia aparece en el listado', count(UpdateInstallerService::backups()) > $backupsBefore);
check_upd('config/config.php NO se toca', $configBefore === (is_file(PP_CONFIG_FILE) ? file_get_contents(PP_CONFIG_FILE) : null));
check_upd('el sitio vuelve a estar en línea', !MaintenanceMode::isActive());

// ---------------------------------------------------------------
// 5. Segunda versión + restaurar
// ---------------------------------------------------------------
$goodZip2 = build_package($tmp, 'paquete-v2', [$sentinelRel => "centinela v2\n"]);
$second = UpdateInstallerService::applyFromUpload(fake_upload($goodZip2));
check_upd('la segunda actualización sustituye el archivo', trim((string) file_get_contents($sentinelAbs)) === 'centinela v2');

// La copia creada por la SEGUNDA actualización contiene el estado con "v1".
$target = basename((string) $second['backup']);
$restore = UpdateInstallerService::restore($target);
check_upd('restaurar devuelve el archivo al estado anterior', trim((string) file_get_contents($sentinelAbs)) === 'centinela v1', (string) @file_get_contents($sentinelAbs));
check_upd('restaurar guarda antes el estado actual', str_contains((string) $restore['safety_backup'], 'prerestore'));
check_upd('tras restaurar, el sitio está en línea', !MaintenanceMode::isActive());

// Nombres manipulados (path traversal) no valen.
$badName = false;
try { UpdateInstallerService::restore('../../config/config.php'); } catch (\Throwable $e) { $badName = true; }
check_upd('un nombre de copia manipulado se rechaza', $badName);

// ---------------------------------------------------------------
// 6. Lo importante: la instalación real sigue intacta
// ---------------------------------------------------------------
check_upd('index.php sigue siendo el mismo', hash_file('sha256', PP_ROOT . '/index.php') === $indexHashBefore);
check_upd('config/constants.php sigue siendo el mismo', hash_file('sha256', PP_ROOT . '/config/constants.php') === $constantsHashBefore);

// ---------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------
if (is_file($sentinelAbs)) @unlink($sentinelAbs);
foreach (UpdateInstallerService::backups() as $b) {
    if (str_contains($b['name'], 'manual') || str_contains($b['name'], 'prerestore')) {
        @unlink(PP_STORAGE . '/updates/backups/' . $b['name']);
    }
}
foreach (glob(PP_STORAGE . '/updates/packages/*manual*.zip') ?: [] as $f) @unlink($f);
exec('rm -rf ' . escapeshellarg(PP_STORAGE . '/updates/extracted'));
exec('rm -rf ' . escapeshellarg($tmp));

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : $failed . ' fallos') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
