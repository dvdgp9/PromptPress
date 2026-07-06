<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use Core\Database;

/**
 * RollupService — consolidación diaria de la analítica (FEAT-3 A4).
 *
 * Diseño (cursor/analytics-design.md §6): rollup PEREZOSO, sin dependencia de
 * cron. Al abrir el dashboard se llama a maybeRun(), que como mucho una vez por
 * hora (lock en `settings`) consolida en `analytics_daily` todos los días
 * COMPLETOS (< hoy) que aún tengan eventos crudos, y purga:
 *   - eventos crudos con más de 90 días (retención aprobada por el usuario),
 *   - salts con más de 2 días (privacidad).
 *
 * El día en curso NO se consolida aquí: el dashboard lo calcula en vivo sobre
 * `analytics_events` y lo fusiona con los rollups.
 *
 * `analytics_daily` almacena, por (site, day, dimension, dim_key):
 *   - pageviews: nº de pageviews (para la dimensión 'event', nº de ese evento),
 *   - visitors:  visitantes únicos (COUNT DISTINCT visitor_hash).
 * Dimensiones: 'total' (dim_key=''), 'page', 'referrer', 'device', 'browser',
 * 'event'.
 */
final class RollupService
{
    private const RETENTION_DAYS = 90;
    private const LOCK_KEY       = 'analytics_rollup_last';
    private const LOCK_TTL       = 3600; // 1 h

    /**
     * Ejecuta el rollup como mucho una vez por hora por sitio. Idempotente y
     * tolerante a fallos: nunca lanza hacia el llamador (el dashboard).
     */
    public static function maybeRun(int $siteId): void
    {
        try {
            $last = self::getSetting($siteId, self::LOCK_KEY);
            if ($last !== null && (time() - (int) $last) < self::LOCK_TTL) {
                return;
            }
            // Fijar el lock ANTES de trabajar para evitar doble ejecución
            // concurrente al abrir el dashboard.
            self::setSetting($siteId, self::LOCK_KEY, (string) time());
            self::run($siteId);
        } catch (\Throwable $e) {
            // no-op: la analítica no debe romper la vista.
        }
    }

    /**
     * Consolida todos los días completos con eventos crudos y purga. Fuerza
     * el trabajo (sin lock) — lo usa el CLI y los tests.
     *
     * @return array{days:int, purged_events:int}
     */
    public static function run(int $siteId): array
    {
        $days = Database::select(
            'SELECT DISTINCT DATE(created_at) AS d
             FROM analytics_events
             WHERE site_id = ? AND DATE(created_at) < CURRENT_DATE',
            [$siteId]
        );

        foreach ($days as $row) {
            self::rollupDay($siteId, (string) $row['d']);
        }

        $purged = Database::execute(
            'DELETE FROM analytics_events
             WHERE site_id = ? AND created_at < (NOW() - INTERVAL ' . self::RETENTION_DAYS . ' DAY)',
            [$siteId]
        );
        EventRecorder::purgeOldSalts();

        return ['days' => count($days), 'purged_events' => $purged];
    }

    /** Re-agrega por completo un día concreto (borra y reinserta sus filas). */
    private static function rollupDay(int $siteId, string $day): void
    {
        Database::execute(
            'DELETE FROM analytics_daily WHERE site_id = ? AND day = ?',
            [$siteId, $day]
        );

        // total: pageviews del día + visitantes únicos (de cualquier evento).
        Database::execute(
            "INSERT INTO analytics_daily (site_id, day, dimension, dim_key, pageviews, visitors)
             SELECT ?, ?, 'total', '',
                    SUM(event_type = 'pageview'),
                    COUNT(DISTINCT visitor_hash)
             FROM analytics_events
             WHERE site_id = ? AND DATE(created_at) = ?",
            [$siteId, $day, $siteId, $day]
        );

        // Dimensiones de pageview: página, referrer, dispositivo, navegador.
        $pageviewDims = [
            'page'     => 'path',
            'referrer' => "COALESCE(referrer_host, '')",
            'device'   => 'device',
            'browser'  => "COALESCE(browser, 'other')",
        ];
        foreach ($pageviewDims as $dimension => $expr) {
            Database::execute(
                "INSERT INTO analytics_daily (site_id, day, dimension, dim_key, pageviews, visitors)
                 SELECT ?, ?, ?, {$expr}, COUNT(*), COUNT(DISTINCT visitor_hash)
                 FROM analytics_events
                 WHERE site_id = ? AND DATE(created_at) = ? AND event_type = 'pageview'
                 GROUP BY {$expr}",
                [$siteId, $day, $dimension, $siteId, $day]
            );
        }

        // Eventos personalizados (todo lo que no sea pageview): pageviews = nº
        // de veces que ocurrió el evento; visitors = únicos que lo dispararon.
        Database::execute(
            "INSERT INTO analytics_daily (site_id, day, dimension, dim_key, pageviews, visitors)
             SELECT ?, ?, 'event', event_type, COUNT(*), COUNT(DISTINCT visitor_hash)
             FROM analytics_events
             WHERE site_id = ? AND DATE(created_at) = ? AND event_type <> 'pageview'
             GROUP BY event_type",
            [$siteId, $day, $siteId, $day]
        );
    }

    private static function getSetting(int $siteId, string $key): ?string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $key]
        );
        return $row === null ? null : (string) $row['setting_value'];
    }

    private static function setSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, $key, $value]
        );
    }
}
