<?php
/**
 * test-rollup.php — test de integración de RollupService + StatsService.
 *
 * Ejecutar con: dev/wp.sh eval-file scripts/test-rollup.php
 *
 * Siembra eventos de ayer, anteayer y de hace 91 días, fuerza el rollup y
 * comprueba: agregados por dimensión, purga de eventos >90 días, purga de
 * salts >2 días y la fusión rollup+live de StatsService. Imprime PASS/FAIL
 * por aserción y sale con código 1 si algo falla (info de debug incluida).
 *
 * Sin declare(strict_types): wp eval-file ejecuta el código con eval() y no
 * admite la declaración.
 */

use IaiaAnalytics\RollupService;
use IaiaAnalytics\Schema;
use IaiaAnalytics\StatsService;

global $wpdb;

$events = Schema::events();
$daily  = Schema::daily();
$salts  = Schema::salts();

$fail = 0;
$check = function (string $name, bool $ok, string $debug = '') use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . "  {$name}" . ($ok || $debug === '' ? '' : "  [{$debug}]") . "\n";
    if (!$ok) {
        $fail++;
    }
};

// --- Preparación: partir de tablas limpias (solo datos de test). -----------
$wpdb->query("DELETE FROM {$events}");
$wpdb->query("DELETE FROM {$daily}");
delete_option('iaia_analytics_rollup_last');

$now       = current_time('timestamp');
$yesterday = date('Y-m-d', $now - DAY_IN_SECONDS);
$before    = date('Y-m-d', $now - 2 * DAY_IN_SECONDS);
$ancient   = date('Y-m-d', $now - 91 * DAY_IN_SECONDS);

$insert = function (string $day, string $type, string $path, ?string $ref, string $device, string $browser, string $hash) use ($wpdb, $events): void {
    $wpdb->insert($events, [
        'site_id'       => Schema::SITE_ID,
        'created_at'    => $day . ' 10:00:00',
        'event_type'    => $type,
        'path'          => $path,
        'referrer_host' => $ref,
        'device'        => $device,
        'browser'       => $browser,
        'visitor_hash'  => str_pad($hash, 32, '0'),
    ]);
};

// Ayer: 3 pageviews de 2 visitantes (2 en /, 1 en /blog) + 1 form_submit.
$insert($yesterday, 'pageview', '/', 'google.com', 'desktop', 'chrome', 'v1');
$insert($yesterday, 'pageview', '/', null, 'mobile', 'safari', 'v2');
$insert($yesterday, 'pageview', '/blog', 'google.com', 'desktop', 'chrome', 'v1');
$insert($yesterday, 'form_submit', '/contacto', null, 'desktop', 'chrome', 'v1');
// Anteayer: 1 pageview.
$insert($before, 'pageview', '/', null, 'tablet', 'firefox', 'v3');
// Hace 91 días: debe purgarse.
$insert($ancient, 'pageview', '/vieja', null, 'desktop', 'other', 'v4');
// Salt viejo: debe purgarse.
$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$salts} (day, salt) VALUES (%s, %s)", $ancient, str_repeat('a', 64)));

// --- Rollup forzado. --------------------------------------------------------
$result = RollupService::run();
$check('rollup procesa 3 días', $result['days'] === 3, 'days=' . $result['days']);
$check('purga eventos >90 días', $result['purged_events'] === 1, 'purged=' . $result['purged_events']);

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT dimension, dim_key, pageviews, visitors FROM {$daily} WHERE day = %s ORDER BY dimension, dim_key",
    $yesterday
), ARRAY_A);
$byKey = [];
foreach ($rows as $r) {
    $byKey[$r['dimension'] . '|' . $r['dim_key']] = [(int) $r['pageviews'], (int) $r['visitors']];
}

$check('total ayer: 3 pv / 2 visitantes', ($byKey['total|'] ?? null) === [3, 2], json_encode($byKey['total|'] ?? null));
$check('page /: 2 pv', ($byKey['page|/'] ?? null) === [2, 2], json_encode($byKey['page|/'] ?? null));
$check('page /blog: 1 pv', ($byKey['page|/blog'] ?? null) === [1, 1], json_encode($byKey['page|/blog'] ?? null));
$check('referrer google.com: 2 pv / 1 vis', ($byKey['referrer|google.com'] ?? null) === [2, 1], json_encode($byKey['referrer|google.com'] ?? null));
$check('referrer directo: 1 pv', ($byKey['referrer|'] ?? null) === [1, 1], json_encode($byKey['referrer|'] ?? null));
$check('device desktop: 2 pv', ($byKey['device|desktop'] ?? null) === [2, 1], json_encode($byKey['device|desktop'] ?? null));
$check('browser chrome: 2 pv', ($byKey['browser|chrome'] ?? null) === [2, 1], json_encode($byKey['browser|chrome'] ?? null));
$check('event form_submit: 1 ocurrencia', ($byKey['event|form_submit'] ?? null) === [1, 1], json_encode($byKey['event|form_submit'] ?? null));

$oldSalts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$salts} WHERE day < %s", date('Y-m-d', $now)));
$check('purga TODA sal que no sea la de hoy', $oldSalts === 0, "old_salts={$oldSalts}");

// IP truncada: dos IPs del mismo /24 comparten hash del día; la anonimización
// hace irrecuperable la IP exacta incluso conociendo la sal.
$ref = new ReflectionMethod('IaiaAnalytics\\EventRecorder', 'anonymizeIp');
$check('IPv4 anonimizada a /24', $ref->invoke(null, '83.44.121.207') === '83.44.121.0', $ref->invoke(null, '83.44.121.207'));
$check('IPv6 anonimizada a /48', $ref->invoke(null, '2a02:9130:ab48:11:22:33:44:55') === '2a02:9130:ab48::', $ref->invoke(null, '2a02:9130:ab48:11:22:33:44:55'));

// --- Live de hoy + fusión en StatsService. ----------------------------------
$today = date('Y-m-d', $now);
$insert($today, 'pageview', '/', null, 'desktop', 'edge', 'hoy1');

$stats = StatsService::forRange(7);
$check('rango normalizado a 7', $stats['range'] === 7);
$check('serie de 7 puntos', count($stats['series']) === 7, 'n=' . count($stats['series']));
$last = end($stats['series']);
$check('hoy en vivo: 1 pv', $last['d'] === $today && $last['pv'] === 1, json_encode($last));
$check('totales fusionados: 5 pv', $stats['totals']['pageviews'] === 5, 'pv=' . $stats['totals']['pageviews']);
$check('visitantes = suma de únicos diarios (4)', $stats['totals']['visitors'] === 4, 'vis=' . $stats['totals']['visitors']);
$check('conversiones: 1', $stats['totals']['events'] === 1, 'ev=' . $stats['totals']['events']);
$topPage = $stats['pages'][0] ?? null;
// / acumula: 2 pv de ayer + 1 de anteayer (rollup) + 1 live de hoy = 4.
$check('top página / con 4 pv (3 rollup + 1 live)', $topPage !== null && $topPage['k'] === '/' && $topPage['pv'] === 4, json_encode($topPage));
$browsers = array_column($stats['browsers'], 'pv', 'k');
$check('edge (live) presente en navegadores', ($browsers['edge'] ?? 0) === 1, json_encode($browsers));

// --- Limpieza de los datos de test. -----------------------------------------
$wpdb->query("DELETE FROM {$events}");
$wpdb->query("DELETE FROM {$daily}");
delete_option('iaia_analytics_rollup_last');

echo $fail === 0 ? "\nTODO OK (" . 21 . " aserciones)\n" : "\n{$fail} FALLOS\n";
exit($fail === 0 ? 0 : 1);
