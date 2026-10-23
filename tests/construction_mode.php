<?php

declare(strict_types=1);

/**
 * CONSTRUCCION — «Web en construcción».
 *
 * Qué rutas se tapan (solo visitas a páginas; nunca POST, panel, assets ni
 * APIs), que el ajuste se lee bien y que la portada lleva la marca, `noindex`
 * y el mensaje escapado.
 *
 * Trabaja sobre el sitio de dev y RESTAURA al terminar los dos ajustes que toca.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ConstructionMode;
use App\Services\Microcopy;
use App\Services\LanguageService;
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

// --- Rutas (sin BD) ---------------------------------------------------------
foreach (['/', '/contacto', '/servicios/diseno-web', '/sitemap.xml', '/tienda', '/administracion'] as $p) {
    check('tapa GET ' . $p, ConstructionMode::intercepts('GET', $p));
}
check('tapa HEAD /', ConstructionMode::intercepts('HEAD', '/'));
foreach (['/admin', '/admin/settings', '/install/', '/api/booking/v1/services', '/brand-assets/1/logo',
          '/brand-assets/1/favicon', '/design.css', '/favicon.ico', '/robots.txt', '/_health',
          '/public/css/construction.css', '/storage/uploads/1/x.jpg'] as $p) {
    check('deja pasar GET ' . $p, !ConstructionMode::intercepts('GET', $p));
}
check('deja pasar POST de formulario', !ConstructionMode::intercepts('POST', '/forms/12'));
check('deja pasar POST de reserva', !ConstructionMode::intercepts('POST', '/api/booking/v1/bookings'));

// --- Ajuste y portada -------------------------------------------------------
$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId === 0) {
    echo "SKIP sin sitio de dev\n";
    exit($failed === 0 ? 0 : 1);
}

$keys = [ConstructionMode::SETTING_ENABLED, ConstructionMode::SETTING_MESSAGE];
$original = [];
foreach ($keys as $k) {
    $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $k]);
    $original[$k] = $row !== null ? (string) $row['setting_value'] : null;
}
$set = static function (string $key, ?string $value) use ($siteId): void {
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

try {
    $set(ConstructionMode::SETTING_ENABLED, null);
    $set(ConstructionMode::SETTING_MESSAGE, null);
    check('sin ajuste está apagado', !ConstructionMode::isActive($siteId));
    check('sitio inexistente: apagado', !ConstructionMode::isActive(0));

    $set(ConstructionMode::SETTING_ENABLED, '0');
    check('«0» está apagado', !ConstructionMode::isActive($siteId));

    $set(ConstructionMode::SETTING_ENABLED, '1');
    check('«1» está encendido', ConstructionMode::isActive($siteId));

    $lang = LanguageService::codeFor($siteId);
    $html = ConstructionMode::render($siteId);
    check('portada: noindex', str_contains($html, 'name="robots" content="noindex, nofollow"'));
    check('portada: título traducido', str_contains($html, e(Microcopy::t('construction.title', $lang))));
    check('portada: mensaje por defecto sin mensaje propio', str_contains($html, e(Microcopy::t('construction.default_message', $lang))));
    check('portada: hoja propia versionada', (bool) preg_match('~public/css/construction\.css\?v=\d+~', $html));
    check('portada: idioma del sitio', str_contains($html, '<html lang="' . $lang . '"'));

    $set(ConstructionMode::SETTING_MESSAGE, "Abrimos en <b>marzo</b>\nEscríbenos");
    $html = ConstructionMode::render($siteId);
    check('portada: mensaje propio escapado', str_contains($html, 'Abrimos en &lt;b&gt;marzo&lt;/b&gt;<br />'), $html);
    check('portada: sin mensaje por defecto si hay propio', !str_contains($html, e(Microcopy::t('construction.default_message', $lang))));
} finally {
    foreach ($original as $k => $v) $set($k, $v);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLO(S)\n";
exit($failed === 0 ? 0 : 1);
