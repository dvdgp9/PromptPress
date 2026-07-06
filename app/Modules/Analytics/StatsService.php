<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use Core\Database;

/**
 * StatsService — datos del dashboard de analítica (FEAT-3 A5).
 *
 * Fusiona los agregados de `analytics_daily` (días completos, consolidados por
 * RollupService) con una consulta EN VIVO sobre `analytics_events` para el día
 * en curso, de modo que el dashboard siempre muestra datos frescos sin esperar
 * al rollup.
 *
 * Nota de semántica (ver scratchpad A4): los "visitantes" de un rango
 * multi-día son la SUMA de únicos diarios (el hash rota cada día por
 * privacidad, no se puede deduplicar entre días). Para la dimensión 'event',
 * el campo pageviews almacena el nº de ocurrencias del evento.
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
    public static function forRange(int $siteId, int $days): array
    {
        $days  = in_array($days, self::RANGES, true) ? $days : 30;
        $today = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));

        // Rango anterior (para deltas): mismos N días justo antes.
        $prevStart = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' day'));
        $prevEnd   = date('Y-m-d', strtotime('-' . $days . ' day'));

        $series = self::dailySeries($siteId, $start, $today);

        $totPv  = 0; $totVis = 0;
        foreach ($series as $point) { $totPv += $point['pv']; $totVis += $point['vis']; }

        $events = self::topDim($siteId, 'event', $start, $today, 20);
        $totEvents = 0;
        foreach ($events as $ev) { $totEvents += $ev['pv']; }

        [$prevPv, $prevVis] = self::periodTotals($siteId, $prevStart, $prevEnd);

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
            'pages'     => self::topDim($siteId, 'page',     $start, $today, 8),
            'referrers' => self::topDim($siteId, 'referrer', $start, $today, 8),
            'devices'   => self::topDim($siteId, 'device',   $start, $today, 3),
            'browsers'  => self::topDim($siteId, 'browser',  $start, $today, 6),
            'events'    => $events,
        ];
    }

    /**
     * Serie diaria continua [start..today]: rollups para días completos,
     * consulta en vivo para hoy, y ceros donde no hubo tráfico.
     *
     * @return array<int, array{d:string, pv:int, vis:int}>
     */
    private static function dailySeries(int $siteId, string $start, string $today): array
    {
        $byDay = [];
        foreach (Database::select(
            "SELECT day, pageviews, visitors FROM analytics_daily
             WHERE site_id = ? AND dimension = 'total' AND day BETWEEN ? AND ?",
            [$siteId, $start, $today]
        ) as $row) {
            $byDay[(string) $row['day']] = [(int) $row['pageviews'], (int) $row['visitors']];
        }

        // Hoy, en vivo (los rollups nunca incluyen el día en curso).
        $live = Database::selectOne(
            "SELECT SUM(event_type = 'pageview') pv, COUNT(DISTINCT visitor_hash) vis
             FROM analytics_events WHERE site_id = ? AND DATE(created_at) = ?",
            [$siteId, $today]
        );
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
    private static function topDim(int $siteId, string $dimension, string $start, string $today, int $limit): array
    {
        $acc = [];
        foreach (Database::select(
            'SELECT dim_key k, SUM(pageviews) pv, SUM(visitors) vis
             FROM analytics_daily
             WHERE site_id = ? AND dimension = ? AND day BETWEEN ? AND ?
             GROUP BY dim_key',
            [$siteId, $dimension, $start, $today]
        ) as $row) {
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
            foreach (Database::select(
                "SELECT {$expr} k, COUNT(*) pv, COUNT(DISTINCT visitor_hash) vis
                 FROM analytics_events
                 WHERE site_id = ? AND DATE(created_at) = ? AND {$where}
                 GROUP BY {$expr}",
                [$siteId, $today]
            ) as $row) {
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
            $out[] = ['k' => $k, 'pv' => $pv, 'vis' => $vis];
        }
        return $out;
    }

    /** Totales (pageviews, visitantes) de un periodo, para calcular deltas. */
    private static function periodTotals(int $siteId, string $start, string $end): array
    {
        $row = Database::selectOne(
            "SELECT COALESCE(SUM(pageviews),0) pv, COALESCE(SUM(visitors),0) vis
             FROM analytics_daily
             WHERE site_id = ? AND dimension = 'total' AND day BETWEEN ? AND ?",
            [$siteId, $start, $end]
        );
        return [(int) ($row['pv'] ?? 0), (int) ($row['vis'] ?? 0)];
    }
}
