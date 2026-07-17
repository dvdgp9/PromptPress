<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * RollupService — consolidación diaria de la analítica.
 *
 * Portado de PromptPress (app/Modules/Analytics/RollupService.php): rollup
 * PEREZOSO, sin dependencia de cron. Al abrir el dashboard se llama a
 * maybeRun(), que como mucho una vez por hora (lock en wp_options) consolida
 * en iaia_daily todos los días COMPLETOS (< hoy) que aún tengan eventos
 * crudos, y purga:
 *   - eventos crudos con más de 90 días (retención),
 *   - salts con más de 2 días (privacidad).
 *
 * El día en curso NO se consolida aquí: el dashboard lo calcula en vivo sobre
 * iaia_events y lo fusiona con los rollups.
 *
 * Fechas: todo se compara contra la fecha local de WordPress
 * (current_time), la misma con la que EventRecorder guarda created_at.
 *
 * iaia_daily almacena, por (site, day, dimension, dim_key):
 *   - pageviews: nº de pageviews (para la dimensión 'event', nº de ese evento),
 *   - visitors:  visitantes únicos (COUNT DISTINCT visitor_hash).
 * Dimensiones: 'total' (dim_key=''), 'page', 'referrer', 'device', 'browser',
 * 'event'.
 */
final class RollupService
{
    private const RETENTION_DAYS = 90;
    private const LOCK_OPTION    = 'iaia_analytics_rollup_last';
    private const LOCK_TTL       = 3600; // 1 h

    /**
     * Ejecuta el rollup como mucho una vez por hora. Idempotente y tolerante
     * a fallos: nunca lanza hacia el llamador (el dashboard).
     */
    public static function maybeRun(): void
    {
        try {
            $last = (int) get_option(self::LOCK_OPTION, 0);
            if ($last > 0 && (time() - $last) < self::LOCK_TTL) {
                return;
            }
            // Fijar el lock ANTES de trabajar para evitar doble ejecución
            // concurrente al abrir el dashboard.
            update_option(self::LOCK_OPTION, time(), false);
            self::run();
        } catch (\Throwable $e) {
            // no-op: la analítica no debe romper la vista.
        }
    }

    /**
     * Consolida todos los días completos con eventos crudos y purga. Fuerza
     * el trabajo (sin lock) — lo usan los scripts de test.
     *
     * @return array{days:int, purged_events:int}
     */
    public static function run(): array
    {
        global $wpdb;
        $events = Schema::events();
        $today  = current_time('Y-m-d');

        $days = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT DATE(created_at) FROM {$events}
             WHERE site_id = %d AND DATE(created_at) < %s",
            Schema::SITE_ID,
            $today
        ));

        foreach ($days as $day) {
            self::rollupDay((string) $day);
        }

        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - self::RETENTION_DAYS * DAY_IN_SECONDS);
        $purged = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$events} WHERE site_id = %d AND created_at < %s",
            Schema::SITE_ID,
            $cutoff
        ));
        EventRecorder::purgeOldSalts();

        return ['days' => count($days), 'purged_events' => (int) $purged];
    }

    /** Re-agrega por completo un día concreto (borra y reinserta sus filas). */
    private static function rollupDay(string $day): void
    {
        global $wpdb;
        $events = Schema::events();
        $daily  = Schema::daily();
        $siteId = Schema::SITE_ID;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$daily} WHERE site_id = %d AND day = %s",
            $siteId,
            $day
        ));

        // total: pageviews del día + visitantes únicos (de cualquier evento).
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$daily} (site_id, day, dimension, dim_key, pageviews, visitors)
             SELECT %d, %s, 'total', '',
                    SUM(event_type = 'pageview'),
                    COUNT(DISTINCT visitor_hash)
             FROM {$events}
             WHERE site_id = %d AND DATE(created_at) = %s",
            $siteId, $day, $siteId, $day
        ));

        // Dimensiones de pageview: página, referrer, dispositivo, navegador.
        $pageviewDims = [
            'page'     => 'path',
            'referrer' => "COALESCE(referrer_host, '')",
            'device'   => 'device',
            'browser'  => "COALESCE(browser, 'other')",
        ];
        foreach ($pageviewDims as $dimension => $expr) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$daily} (site_id, day, dimension, dim_key, pageviews, visitors)
                 SELECT %d, %s, %s, {$expr}, COUNT(*), COUNT(DISTINCT visitor_hash)
                 FROM {$events}
                 WHERE site_id = %d AND DATE(created_at) = %s AND event_type = 'pageview'
                 GROUP BY {$expr}",
                $siteId, $day, $dimension, $siteId, $day
            ));
        }

        // Eventos personalizados (todo lo que no sea pageview): pageviews = nº
        // de veces que ocurrió el evento; visitors = únicos que lo dispararon.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$daily} (site_id, day, dimension, dim_key, pageviews, visitors)
             SELECT %d, %s, 'event', event_type, COUNT(*), COUNT(DISTINCT visitor_hash)
             FROM {$events}
             WHERE site_id = %d AND DATE(created_at) = %s AND event_type <> 'pageview'
             GROUP BY event_type",
            $siteId, $day, $siteId, $day
        ));
    }
}
