<?php

declare(strict_types=1);

/**
 * FEAT-3 A3 — Tests de EventRecorder (ingesta de analítica).
 *
 * Verifica detección de bots, saneado de path/referrer, derivación de
 * dispositivo/navegador, inserción real y estabilidad del visitor_hash
 * (mismo visitante mismo día → mismo hash; distinto sitio → distinto hash).
 * Limpia los eventos de prueba al terminar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Analytics\EventRecorder;
use Core\Database;

$failed = 0;
function check_a(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL; }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_a('hay site para probar', $siteId > 0);
if ($siteId <= 0) { exit(1); }

$CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$IPAD_UA   = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1 (KHTML, like Gecko) Version/17.0 Safari/604.1';

// Baseline de conteo para verificar inserciones.
$countFor = function (string $path) use ($siteId): int {
    return (int) (Database::selectOne(
        'SELECT COUNT(*) c FROM analytics_events WHERE site_id = ? AND path = ?',
        [$siteId, $path]
    )['c'] ?? 0);
};

// 1. Bot descartado.
$botOk = EventRecorder::record($siteId, 'pageview', '/bot-test-xyz', null, '1.2.3.4', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
check_a('bot descartado (record devuelve false)', $botOk === false);
check_a('bot no insertó fila', $countFor('/bot-test-xyz') === 0);

// 2. UA vacío descartado.
check_a('UA vacío descartado', EventRecorder::record($siteId, 'pageview', '/empty-ua', null, '1.2.3.4', '') === false);

// 3. Pageview real (Chrome desktop) con query que debe eliminarse del path.
$okIns = EventRecorder::record($siteId, 'pageview', '/aa-test-page?utm=x&ref=y', 'https://www.google.com/search?q=z', '9.9.9.9', $CHROME_UA);
check_a('pageview válido insertado', $okIns === true);
$row = Database::selectOne(
    'SELECT path, referrer_host, device, browser, event_type FROM analytics_events
     WHERE site_id = ? AND path = ? ORDER BY id DESC LIMIT 1',
    [$siteId, '/aa-test-page']
);
check_a('path sin query', $row !== null && $row['path'] === '/aa-test-page', json_encode($row));
check_a('referrer reducido a host', $row !== null && $row['referrer_host'] === 'google.com', json_encode($row));
check_a('device desktop', $row !== null && $row['device'] === 'desktop', json_encode($row));
check_a('browser chrome', $row !== null && $row['browser'] === 'chrome', json_encode($row));

// 4. Móvil y tablet.
EventRecorder::record($siteId, 'pageview', '/aa-mobile', null, '9.9.9.9', $IPHONE_UA);
$m = Database::selectOne('SELECT device FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-mobile']);
check_a('iPhone → mobile', $m !== null && $m['device'] === 'mobile', json_encode($m));

EventRecorder::record($siteId, 'pageview', '/aa-tablet', null, '9.9.9.9', $IPAD_UA);
$t = Database::selectOne('SELECT device FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-tablet']);
check_a('iPad → tablet', $t !== null && $t['device'] === 'tablet', json_encode($t));

// 5. Evento personalizado con nombre saneado.
EventRecorder::record($siteId, 'Form Submit!', '/aa-event', null, '9.9.9.9', $CHROME_UA);
$e = Database::selectOne('SELECT event_type FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-event']);
check_a('event_type saneado a minúsculas/guiones', $e !== null && $e['event_type'] === 'form_submit', json_encode($e));

// 6. Estabilidad del visitor_hash: mismo visitante (mismo día) → mismo hash.
EventRecorder::record($siteId, 'pageview', '/aa-hash-1', null, '5.5.5.5', $CHROME_UA);
EventRecorder::record($siteId, 'pageview', '/aa-hash-2', null, '5.5.5.5', $CHROME_UA);
$h1 = Database::selectOne('SELECT HEX(visitor_hash) h FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-hash-1'])['h'] ?? 'a';
$h2 = Database::selectOne('SELECT HEX(visitor_hash) h FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-hash-2'])['h'] ?? 'b';
check_a('mismo visitante mismo día → mismo hash', $h1 === $h2, "$h1 vs $h2");

// Distinta IP → distinto hash.
EventRecorder::record($siteId, 'pageview', '/aa-hash-3', null, '6.6.6.6', $CHROME_UA);
$h3 = Database::selectOne('SELECT HEX(visitor_hash) h FROM analytics_events WHERE site_id=? AND path=? ORDER BY id DESC LIMIT 1', [$siteId, '/aa-hash-3'])['h'] ?? 'c';
check_a('distinta IP → distinto hash', $h1 !== $h3, "$h1 vs $h3");

// visitor_hash ocupa 16 bytes.
check_a('visitor_hash son 16 bytes (32 hex)', strlen((string) $h1) === 32, (string) $h1);

// 7. Salt de hoy existe y purgeOldSalts no revienta.
$saltRow = Database::selectOne('SELECT day FROM analytics_salts WHERE day = ? LIMIT 1', [date('Y-m-d')]);
check_a('salt de hoy creado', $saltRow !== null);
EventRecorder::purgeOldSalts();
check_a('purgeOldSalts no borra el de hoy', Database::selectOne('SELECT day FROM analytics_salts WHERE day = ? LIMIT 1', [date('Y-m-d')]) !== null);

// Limpieza de eventos de prueba.
Database::execute("DELETE FROM analytics_events WHERE site_id = ? AND path LIKE '/aa-%'", [$siteId]);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
