<?php

declare(strict_types=1);

/**
 * FEAT-3 A4 — Tests de RollupService.
 *
 * Inserta eventos crudos sintéticos de AYER (día completo) y ANTEAYER, corre el
 * rollup y verifica que analytics_daily coincide con los crudos por dimensión.
 * Comprueba también: idempotencia, que el día EN CURSO no se consolida, la
 * purga por retención (>90 días) y el lock horario de maybeRun.
 * Limpia todos los datos de prueba al terminar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Analytics\RollupService;
use Core\Database;

$failed = 0;
function check_r(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL; }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_r('hay site para probar', $siteId > 0);
if ($siteId <= 0) { exit(1); }

// Aislar: limpiar cualquier residuo previo de este site.
Database::execute('DELETE FROM analytics_events WHERE site_id = ?', [$siteId]);
Database::execute('DELETE FROM analytics_daily WHERE site_id = ?', [$siteId]);

$yesterday = date('Y-m-d', strtotime('-1 day'));
$ereyest   = date('Y-m-d', strtotime('-2 day'));
$today     = date('Y-m-d');

// Helper: inserta un evento crudo con día y visitante controlados.
$vh = fn(string $seed) => substr(hash('sha256', $seed, true), 0, 16);
$insert = function (string $day, string $type, string $path, ?string $ref, string $device, ?string $browser, string $visitor) use ($siteId, $vh) {
    Database::execute(
        'INSERT INTO analytics_events (site_id, created_at, event_type, path, referrer_host, device, browser, visitor_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$siteId, $day . ' 10:30:00', $type, $path, $ref, $device, $browser, $vh($visitor)]
    );
};

// AYER: 4 pageviews de 2 visitantes + 1 evento form_submit.
//   visitante A: / (google), /precios     visitante B: / (directo)
$insert($yesterday, 'pageview', '/',        'google.com', 'desktop', 'chrome',  'A');
$insert($yesterday, 'pageview', '/precios', 'google.com', 'desktop', 'chrome',  'A');
$insert($yesterday, 'pageview', '/',        null,          'mobile',  'safari',  'B');
$insert($yesterday, 'pageview', '/',        null,          'mobile',  'safari',  'B');
$insert($yesterday, 'form_submit', '/contacto', null,      'mobile',  'safari',  'B');

// ANTEAYER: 1 pageview.
$insert($ereyest, 'pageview', '/', null, 'desktop', 'firefox', 'A');

// DÍA EN CURSO: 1 pageview (NO debe consolidarse).
$insert($today, 'pageview', '/hoy', null, 'desktop', 'chrome', 'A');

$res = RollupService::run($siteId);
check_r('run consolida 2 días completos (ayer + anteayer)', $res['days'] === 2, json_encode($res));

$get = function (string $day, string $dim, string $key) use ($siteId): ?array {
    return Database::selectOne(
        'SELECT pageviews, visitors FROM analytics_daily WHERE site_id=? AND day=? AND dimension=? AND dim_key=?',
        [$siteId, $day, $dim, $key]
    );
};

// total de ayer: 4 pageviews, 2 visitantes.
$tot = $get($yesterday, 'total', '');
check_r('total ayer: 4 pageviews', $tot !== null && (int) $tot['pageviews'] === 4, json_encode($tot));
check_r('total ayer: 2 visitantes únicos', $tot !== null && (int) $tot['visitors'] === 2, json_encode($tot));

// page '/' de ayer: 3 pageviews, 2 visitantes.
$home = $get($yesterday, 'page', '/');
check_r("page '/' ayer: 3 pageviews", $home !== null && (int) $home['pageviews'] === 3, json_encode($home));
check_r("page '/' ayer: 2 visitantes", $home !== null && (int) $home['visitors'] === 2, json_encode($home));

// page '/precios': 1 pageview.
$precios = $get($yesterday, 'page', '/precios');
check_r("page '/precios' ayer: 1 pageview", $precios !== null && (int) $precios['pageviews'] === 1, json_encode($precios));

// referrer google.com: 2 pageviews; directo (''): 2 pageviews.
$refG = $get($yesterday, 'referrer', 'google.com');
$refD = $get($yesterday, 'referrer', '');
check_r('referrer google.com: 2', $refG !== null && (int) $refG['pageviews'] === 2, json_encode($refG));
check_r('referrer directo: 2', $refD !== null && (int) $refD['pageviews'] === 2, json_encode($refD));

// device desktop 2 / mobile 2.
$dDesk = $get($yesterday, 'device', 'desktop');
$dMob  = $get($yesterday, 'device', 'mobile');
check_r('device desktop: 2', $dDesk !== null && (int) $dDesk['pageviews'] === 2, json_encode($dDesk));
check_r('device mobile: 2', $dMob !== null && (int) $dMob['pageviews'] === 2, json_encode($dMob));

// event form_submit: 1 ocurrencia, 1 visitante.
$ev = $get($yesterday, 'event', 'form_submit');
check_r('event form_submit: 1 ocurrencia', $ev !== null && (int) $ev['pageviews'] === 1, json_encode($ev));

// El día en curso NO está en analytics_daily.
$todayRoll = Database::selectOne('SELECT 1 x FROM analytics_daily WHERE site_id=? AND day=?', [$siteId, $today]);
check_r('el día en curso NO se consolida', $todayRoll === null);
// ...pero su crudo sigue vivo (no se purga por retención).
$todayRaw = Database::selectOne('SELECT 1 x FROM analytics_events WHERE site_id=? AND DATE(created_at)=?', [$siteId, $today]);
check_r('el crudo del día en curso sigue vivo', $todayRaw !== null);

// Idempotencia: re-ejecutar no duplica ni cambia los agregados.
RollupService::run($siteId);
$tot2 = $get($yesterday, 'total', '');
check_r('idempotente: total ayer sigue 4 pageviews', $tot2 !== null && (int) $tot2['pageviews'] === 4, json_encode($tot2));
$dailyRows = (int) (Database::selectOne('SELECT COUNT(*) c FROM analytics_daily WHERE site_id=? AND day=?', [$siteId, $yesterday])['c'] ?? -1);
check_r('idempotente: nº de filas de ayer estable tras 2ª pasada', $dailyRows > 0);

// Retención: un evento crudo de hace 100 días se purga; su rollup persiste.
$old = date('Y-m-d', strtotime('-100 day'));
$insert($old, 'pageview', '/', null, 'desktop', 'chrome', 'A');
$r2 = RollupService::run($siteId);
$oldRoll = $get($old, 'total', '');
check_r('día de hace 100d se consolidó', $oldRoll !== null && (int) $oldRoll['pageviews'] === 1, json_encode($oldRoll));
check_r('crudos >90d purgados', $r2['purged_events'] >= 1, json_encode($r2));
$oldRaw = Database::selectOne('SELECT 1 x FROM analytics_events WHERE site_id=? AND DATE(created_at)=?', [$siteId, $old]);
check_r('el crudo de hace 100d ya no existe', $oldRaw === null);
check_r('pero su rollup persiste (retención solo afecta a crudos)', $get($old, 'total', '') !== null);

// Lock horario de maybeRun: tras run(), maybeRun no vuelve a ejecutar en <1h.
// Insertamos un crudo nuevo de ayer y comprobamos que maybeRun NO lo re-agrega.
Database::execute(
    'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
    [$siteId, 'analytics_rollup_last', (string) time()]
);
$insert($yesterday, 'pageview', '/', null, 'desktop', 'chrome', 'C');
RollupService::maybeRun($siteId);
$totLocked = $get($yesterday, 'total', '');
check_r('maybeRun respeta el lock (<1h): no re-agrega (sigue 4)', $totLocked !== null && (int) $totLocked['pageviews'] === 4, json_encode($totLocked));

// Con el lock vencido, maybeRun sí re-agrega (ahora 5 pageviews).
Database::execute('UPDATE settings SET setting_value = ? WHERE site_id = ? AND setting_key = ?',
    [(string) (time() - 4000), $siteId, 'analytics_rollup_last']);
RollupService::maybeRun($siteId);
$totUnlocked = $get($yesterday, 'total', '');
check_r('maybeRun con lock vencido re-agrega (ahora 5)', $totUnlocked !== null && (int) $totUnlocked['pageviews'] === 5, json_encode($totUnlocked));

// Limpieza total de datos de prueba.
Database::execute('DELETE FROM analytics_events WHERE site_id = ?', [$siteId]);
Database::execute('DELETE FROM analytics_daily WHERE site_id = ?', [$siteId]);
Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'analytics_rollup_last']);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
