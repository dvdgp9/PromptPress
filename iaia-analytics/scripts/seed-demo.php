<?php
/**
 * seed-demo.php — siembra ~30 días de datos de demo para ver el dashboard.
 *
 * Ejecutar con: dev/wp.sh eval-file scripts/seed-demo.php
 * Determinista (mt_srand fijo) para poder comparar entre ejecuciones.
 * Solo para el entorno de pruebas: borra los eventos/rollups existentes.
 */

use IaiaAnalytics\Schema;

global $wpdb;

$events = Schema::events();
$daily  = Schema::daily();

$wpdb->query("DELETE FROM {$events}");
$wpdb->query("DELETE FROM {$daily}");
delete_option('iaia_analytics_rollup_last');

mt_srand(42);

$paths    = ['/', '/', '/', '/blog', '/blog/post-1', '/servicios', '/contacto'];
$refs     = [null, null, null, 'google.com', 'google.com', 'instagram.com', 'bing.com'];
$devices  = ['desktop', 'desktop', 'mobile', 'mobile', 'mobile', 'tablet'];
$browsers = ['chrome', 'chrome', 'safari', 'safari', 'firefox', 'edge'];

$now = current_time('timestamp');
$total = 0;
for ($ago = 29; $ago >= 0; $ago--) {
    $day = date('Y-m-d', $now - $ago * DAY_IN_SECONDS);
    // Tráfico con tendencia creciente y algo de ruido.
    $n = 5 + (int) round((29 - $ago) * 0.8) + mt_rand(0, 6);
    for ($i = 0; $i < $n; $i++) {
        $visitor = 'v' . $day . '_' . mt_rand(1, max(2, (int) ($n / 2)));
        $wpdb->insert($events, [
            'site_id'       => Schema::SITE_ID,
            'created_at'    => $day . sprintf(' %02d:%02d:00', mt_rand(8, 22), mt_rand(0, 59)),
            'event_type'    => 'pageview',
            'path'          => $paths[mt_rand(0, count($paths) - 1)],
            'referrer_host' => $refs[mt_rand(0, count($refs) - 1)],
            'device'        => $devices[mt_rand(0, count($devices) - 1)],
            'browser'       => $browsers[mt_rand(0, count($browsers) - 1)],
            'visitor_hash'  => str_pad(md5($visitor), 32, '0'),
        ]);
        $total++;
    }
    // Alguna conversión suelta.
    if (mt_rand(0, 2) === 0) {
        $wpdb->insert($events, [
            'site_id'       => Schema::SITE_ID,
            'created_at'    => $day . ' 12:30:00',
            'event_type'    => 'form_submit',
            'path'          => '/contacto',
            'referrer_host' => null,
            'device'        => 'desktop',
            'browser'       => 'chrome',
            'visitor_hash'  => str_pad(md5('conv' . $day), 32, '0'),
        ]);
        $total++;
    }
}

echo "Sembrados {$total} eventos en 30 días.\n";
