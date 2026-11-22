<?php

declare(strict_types=1);

/**
 * ANL-ADMIN — Las visitas desde el navegador de alguien del equipo no cuentan.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Analytics\DeviceOptOut;
use App\Modules\Analytics\EventRecorder;
use Core\Database;

$failed = 0;
function check_dev(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  -> ' . $detail . PHP_EOL; }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
$path = '/__test-anl-admin-' . bin2hex(random_bytes(4));
$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36';
$count = static fn (): int => (int) Database::selectOne('SELECT COUNT(*) c FROM analytics_events WHERE site_id = ? AND path = ?', [$siteId, $path])['c'];

try {
    unset($_COOKIE[DeviceOptOut::COOKIE]);
    check_dev('sin cookie: la visita cuenta', EventRecorder::record($siteId, 'pageview', $path, null, '203.0.113.7', $ua) && $count() === 1);

    DeviceOptOut::markAdminDevice();
    check_dev('al pasar por el panel el dispositivo queda marcado', DeviceOptOut::isExcluded());
    check_dev('marcado: la visita no cuenta', !EventRecorder::record($siteId, 'pageview', $path, null, '203.0.113.7', $ua) && $count() === 1);
    check_dev('marcado: una conversión tampoco', !EventRecorder::record($siteId, 'form_submit', $path, null, '203.0.113.7', $ua) && $count() === 1);

    DeviceOptOut::count();
    check_dev('«que cuenten»: vuelve a contar', EventRecorder::record($siteId, 'pageview', $path, null, '203.0.113.7', $ua) && $count() === 2);
    DeviceOptOut::markAdminDevice();
    check_dev('«que cuenten» se respeta al volver al panel', !DeviceOptOut::isExcluded() && DeviceOptOut::isExplicitlyCounted());

    DeviceOptOut::exclude();
    check_dev('«que no cuenten» lo vuelve a excluir', DeviceOptOut::isExcluded());

    $_COOKIE[DeviceOptOut::COOKIE] = 'basura';
    check_dev('un valor raro no excluye', !DeviceOptOut::isExcluded());
} finally {
    Database::execute('DELETE FROM analytics_events WHERE site_id = ? AND path = ?', [$siteId, $path]);
}

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
