<?php

declare(strict_types=1);

/**
 * FAVICON — Icono de la web subido desde Diseño.
 *
 * Comprueba que solo se acepta un archivo nuestro (prefijo, nombre y que
 * exista), que la URL cambia con cada subida y que las etiquetas llegan a la
 * cabecera que comparten todas las páginas públicas.
 *
 * Trabaja sobre el sitio de dev y RESTAURA al terminar el ajuste
 * `site_favicon_path` y los archivos que crea.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandService;
use App\Services\DesignSystem;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '     ' . $detail . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId === 0) {
    echo "SKIP sin sitio de dev\n";
    exit(0);
}

$key = BrandService::FAVICON_SETTING;
$original = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);

$set = static function (?string $value) use ($siteId, $key): void {
    if ($value === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
        return;
    }
    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$siteId, $key, $value]
    );
};

$dir = 'storage/uploads/' . $siteId . '/brand';
@mkdir(PP_ROOT . '/' . $dir, 0775, true);
$png = $dir . '/favicon-0123456789abcdef.png';
$ico = $dir . '/favicon-fedcba9876543210.ico';
// PNG de 1×1 válido.
file_put_contents(PP_ROOT . '/' . $png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));
file_put_contents(PP_ROOT . '/' . $ico, "\x00\x00\x01\x00");

try {
    $set(null);
    check('sin ajuste no hay icono', BrandService::faviconPath($siteId) === '' && BrandService::faviconTags($siteId) === '');

    $set($png);
    check('PNG válido se acepta', BrandService::faviconPath($siteId) === $png);
    $url = BrandService::faviconUrl($siteId);
    check('la URL lleva el hash del archivo', str_ends_with($url, '/brand-assets/' . $siteId . '/favicon?v=0123456789abcdef'), $url);
    $tags = BrandService::faviconTags($siteId);
    check('PNG: icono + apple-touch-icon', str_contains($tags, 'rel="icon"') && str_contains($tags, 'type="image/png"') && str_contains($tags, 'rel="apple-touch-icon"'), $tags);
    $head = DesignSystem::renderHead($siteId);
    check('renderHead incluye el favicon', str_starts_with($head, '<link rel="icon"'), mb_substr($head, 0, 200));

    $set($ico);
    $tags = BrandService::faviconTags($siteId);
    check('ICO: sin apple-touch-icon', str_contains($tags, 'type="image/x-icon"') && !str_contains($tags, 'apple-touch-icon'), $tags);

    $set('/' . $png);
    check('la barra inicial no rompe la ruta', BrandService::faviconPath($siteId) === $png);

    foreach ([
        'otro sitio'          => 'storage/uploads/' . ($siteId + 1) . '/brand/favicon-0123456789abcdef.png',
        'salirse de la carpeta' => $dir . '/../../../../config/config.php',
        'nombre que no es nuestro' => $dir . '/logo-0123456789abcdef.png',
        'extensión no permitida' => $dir . '/favicon-0123456789abcdef.svg',
        'archivo que no existe' => $dir . '/favicon-aaaaaaaaaaaaaaaa.png',
    ] as $label => $bad) {
        $set($bad);
        check('rechaza: ' . $label, BrandService::faviconPath($siteId) === '' && BrandService::faviconTags($siteId) === '');
    }
} finally {
    @unlink(PP_ROOT . '/' . $png);
    @unlink(PP_ROOT . '/' . $ico);
    $set($original !== null ? (string) $original['setting_value'] : null);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLO(S)\n";
exit($failed === 0 ? 0 : 1);
