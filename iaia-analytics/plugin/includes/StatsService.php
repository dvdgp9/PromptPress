<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * StatsService — datos del dashboard de analítica.
 *
 * Portado de PromptPress (app/Modules/Analytics/StatsService.php). Fusiona
 * los agregados de iaia_daily (días completos, consolidados por RollupService)
 * con una consulta EN VIVO sobre iaia_events para el día en curso, de modo
 * que el dashboard siempre muestra datos frescos sin esperar al rollup.
 *
 * Nota de semántica: los "visitantes" de un rango multi-día son la SUMA de
 * únicos diarios (el hash rota cada día por privacidad, no se puede
 * deduplicar entre días). Para la dimensión 'event', el campo pageviews
 * almacena el nº de ocurrencias del evento.
 */
final class StatsService
{
    public const RANGES = [7, 30, 90];

    /**
     * Todos los datos del dashboard para un rango de N días (hoy incluido).
     *
     * @return array{
     *   range:int, series:array, totals:array, prev:array,
     *   pages:array, referrers:array, devices:array, browsers:array, events:array
     * }
     */
    public static function forRange(int $days): array
    {
        $days  = in_array($days, self::RANGES, true) ? $days : 30;
        $now   = current_time('timestamp');
        $today = date('Y-m-d', $now);
        $start = date('Y-m-d', $now - ($days - 1) * DAY_IN_SECONDS);

        // Rango anterior (para deltas): mismos N días justo antes.
        $prevStart = date('Y-m-d', $now - (2 * $days - 1) * DAY_IN_SECONDS);
        $prevEnd   = date('Y-m-d', $now - $days * DAY_IN_SECONDS);

        $series = self::dailySeries($start, $today);

        $totPv  = 0; $totVis = 0;
        foreach ($series as $point) { $totPv += $point['pv']; $totVis += $point['vis']; }

        $events = self::topDim('event', $start, $today, 20);
        $totEvents = 0;
        foreach ($events as $ev) { $totEvents += $ev['pv']; }

        [$prevPv, $prevVis] = self::periodTotals($prevStart, $prevEnd);

        return [
            'range'     => $days,
            'series'    => $series,
            'totals'    => [
                'pageviews' => $totPv,
                'visitors'  => $totVis,
                'events'    => $totEvents,
                'avgPerDay' => $days > 0 ? (int) round($totPv / $days) : 0,
            ],
            'prev'      => ['pageviews' => $prevPv, 'visitors' => $prevVis],
            'pages'     => self::topDim('page',     $start, $today, 8),
            'referrers' => self::topDim('referrer', $start, $today, 8),
            'devices'   => self::topDim('device',   $start, $today, 3),
            'browsers'  => self::topDim('browser',  $start, $today, 6),
            'events'    => $events,
        ];
    }

    /**
     * Serie diaria continua [start..today]: rollups para días completos,
     * consulta en vivo para hoy, y ceros donde no hubo tráfico.
     *
     * @return array<int, array{d:string, pv:int, vis:int}>
     */
    private static function dailySeries(string $start, string $today): array
    {
        global $wpdb;
        $daily  = Schema::daily();
        $events = Schema::events();

        $byDay = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT day, pageviews, visitors FROM {$daily}
             WHERE site_id = %d AND dimension = 'total' AND day BETWEEN %s AND %s",
            Schema::SITE_ID, $start, $today
        ), ARRAY_A);
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = [(int) $row['pageviews'], (int) $row['visitors']];
        }

        // Hoy, en vivo (los rollups nunca incluyen el día en curso).
        $live = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(event_type = 'pageview') pv, COUNT(DISTINCT visitor_hash) vis
             FROM {$events} WHERE site_id = %d AND DATE(created_at) = %s",
            Schema::SITE_ID, $today
        ), ARRAY_A);
        if ($live !== null && (int) ($live['vis'] ?? 0) > 0) {
            $byDay[$today] = [(int) $live['pv'], (int) $live['vis']];
        }

        $series = [];
        for ($d = $start; $d <= $today; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            [$pv, $vis] = $byDay[$d] ?? [0, 0];
            $series[] = ['d' => $d, 'pv' => $pv, 'vis' => $vis];
        }
        return $series;
    }

    /**
     * Top N de una dimensión en el rango: suma de rollups + live de hoy.
     *
     * @return array<int, array{k:string, pv:int, vis:int}>
     */
    private static function topDim(string $dimension, string $start, string $today, int $limit): array
    {
        global $wpdb;
        $daily  = Schema::daily();
        $events = Schema::events();

        $acc = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT dim_key k, SUM(pageviews) pv, SUM(visitors) vis
             FROM {$daily}
             WHERE site_id = %d AND dimension = %s AND day BETWEEN %s AND %s
             GROUP BY dim_key",
            Schema::SITE_ID, $dimension, $start, $today
        ), ARRAY_A);
        foreach ($rows as $row) {
            $acc[(string) $row['k']] = [(int) $row['pv'], (int) $row['vis']];
        }

        // Live de hoy para la misma dimensión.
        $liveSql = match ($dimension) {
            'page'     => ["path", "event_type = 'pageview'"],
            'referrer' => ["COALESCE(referrer_host, '')", "event_type = 'pageview'"],
            'device'   => ["device", "event_type = 'pageview'"],
            'browser'  => ["COALESCE(browser, 'other')", "event_type = 'pageview'"],
            'event'    => ["event_type", "event_type <> 'pageview'"],
            default    => null,
        };
        if ($liveSql !== null) {
            [$expr, $where] = $liveSql;
            $liveRows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$expr} k, COUNT(*) pv, COUNT(DISTINCT visitor_hash) vis
                 FROM {$events}
                 WHERE site_id = %d AND DATE(created_at) = %s AND {$where}
                 GROUP BY {$expr}",
                Schema::SITE_ID, $today
            ), ARRAY_A);
            foreach ($liveRows as $row) {
                $k = (string) $row['k'];
                $acc[$k] = [
                    ($acc[$k][0] ?? 0) + (int) $row['pv'],
                    ($acc[$k][1] ?? 0) + (int) $row['vis'],
                ];
            }
        }

        uasort($acc, fn($a, $b) => $b[0] <=> $a[0]);
        $out = [];
        foreach (array_slice($acc, 0, $limit, true) as $k => [$pv, $vis]) {
            $out[] = ['k' => (string) $k, 'pv' => $pv, 'vis' => $vis];
        }
        return $out;
    }

    /** Dimensiones válidas para drill-down y su expresión live sobre eventos. */
    public const DIMENSIONS = ['page', 'referrer', 'device', 'browser', 'event'];

    /**
     * Lista completa de una dimensión en el rango (drill-down F1), con
     * búsqueda opcional sobre la clave (buscador F2). Igual que topDim pero
     * sin recorte agresivo y con filtro.
     *
     * @return array<int, array{k:string, pv:int, vis:int}>
     */
    public static function listDim(string $dimension, int $days, string $search = '', int $limit = 200): array
    {
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            return [];
        }
        $rows = self::topDim($dimension, self::rangeStart($days), current_time('Y-m-d'), 10000);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                static fn(array $r): bool => str_contains(mb_strtolower($r['k']), $needle)
            ));
        }
        return array_slice($rows, 0, $limit);
    }

    /**
     * Detalle de UN valor de una dimensión (p. ej. una página concreta):
     * serie diaria propia + totales del rango + totales del rango anterior.
     * Devuelve la misma forma que forRange (listas vacías) para poder
     * reutilizar dashboard.js tal cual.
     */
    public static function keyStats(string $dimension, string $key, int $days): array
    {
        global $wpdb;
        $days  = in_array($days, self::RANGES, true) ? $days : 30;
        $now   = current_time('timestamp');
        $today = date('Y-m-d', $now);
        $start = self::rangeStart($days);
        $daily = Schema::daily();

        $byDay = [];
        if (in_array($dimension, self::DIMENSIONS, true)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT day, pageviews, visitors FROM {$daily}
                 WHERE site_id = %d AND dimension = %s AND dim_key = %s AND day BETWEEN %s AND %s",
                Schema::SITE_ID, $dimension, $key, $start, $today
            ), ARRAY_A);
            foreach ($rows as $row) {
                $byDay[(string) $row['day']] = [(int) $row['pageviews'], (int) $row['visitors']];
            }
            if (($live = self::liveForKey($dimension, $key, $today)) !== null) {
                $byDay[$today] = $live;
            }
        }

        $series = [];
        $totPv  = 0; $totVis = 0;
        for ($d = $start; $d <= $today; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            [$pv, $vis] = $byDay[$d] ?? [0, 0];
            $series[] = ['d' => $d, 'pv' => $pv, 'vis' => $vis];
            $totPv += $pv; $totVis += $vis;
        }

        // Rango anterior, para el delta de los KPIs.
        $prevStart = date('Y-m-d', $now - (2 * $days - 1) * DAY_IN_SECONDS);
        $prevEnd   = date('Y-m-d', $now - $days * DAY_IN_SECONDS);
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(pageviews),0) pv, COALESCE(SUM(visitors),0) vis
             FROM {$daily}
             WHERE site_id = %d AND dimension = %s AND dim_key = %s AND day BETWEEN %s AND %s",
            Schema::SITE_ID, $dimension, $key, $prevStart, $prevEnd
        ), ARRAY_A);

        return [
            'range'  => $days,
            'series' => $series,
            'totals' => [
                'pageviews' => $totPv,
                'visitors'  => $totVis,
                'events'    => 0,
                'avgPerDay' => $days > 0 ? (int) round($totPv / $days) : 0,
            ],
            'prev'      => ['pageviews' => (int) ($prev['pv'] ?? 0), 'visitors' => (int) ($prev['vis'] ?? 0)],
            'pages'     => [],
            'referrers' => [],
            'devices'   => [],
            'browsers'  => [],
            'events'    => [],
        ];
    }

    /** Live de hoy para una clave concreta de una dimensión, o null si nada. */
    private static function liveForKey(string $dimension, string $key, string $today): ?array
    {
        global $wpdb;
        $events = Schema::events();
        [$expr, $where] = match ($dimension) {
            'page'     => ['path', "event_type = 'pageview'"],
            'referrer' => ["COALESCE(referrer_host, '')", "event_type = 'pageview'"],
            'device'   => ['device', "event_type = 'pageview'"],
            'browser'  => ["COALESCE(browser, 'other')", "event_type = 'pageview'"],
            'event'    => ['event_type', "event_type <> 'pageview'"],
        };
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) pv, COUNT(DISTINCT visitor_hash) vis
             FROM {$events}
             WHERE site_id = %d AND DATE(created_at) = %s AND {$where} AND {$expr} = %s",
            Schema::SITE_ID, $today, $key
        ), ARRAY_A);
        if ($row === null || (int) $row['pv'] === 0) {
            return null;
        }
        return [(int) $row['pv'], (int) $row['vis']];
    }

    private static function rangeStart(int $days): string
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;
        return date('Y-m-d', current_time('timestamp') - ($days - 1) * DAY_IN_SECONDS);
    }

    /** Totales (pageviews, visitantes) de un periodo, para calcular deltas. */
    private static function periodTotals(string $start, string $end): array
    {
        global $wpdb;
        $daily = Schema::daily();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(pageviews),0) pv, COALESCE(SUM(visitors),0) vis
             FROM {$daily}
             WHERE site_id = %d AND dimension = 'total' AND day BETWEEN %s AND %s",
            Schema::SITE_ID, $start, $end
        ), ARRAY_A);
        return [(int) ($row['pv'] ?? 0), (int) ($row['vis'] ?? 0)];
    }
}
