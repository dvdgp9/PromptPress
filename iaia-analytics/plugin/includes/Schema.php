<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * Schema — ciclo de vida de las tablas del plugin.
 *
 * Adaptación del esquema de PromptPress (database/migrations/2026_07_02_analytics.sql):
 *  - Prefijo de tabla de WordPress ({$wpdb->prefix}iaia_*).
 *  - Sin FK a `sites`: site_id se conserva (valor fijo 1) para una futura
 *    compatibilidad multisite, pero no referencia nada.
 *  - visitor_hash y salt se guardan en HEX (CHAR) en lugar de BINARY para
 *    evitar los problemas de $wpdb con datos binarios no-UTF8.
 *
 * Privacidad: iaia_events NO guarda IP ni User-Agent; el visitante se
 * identifica con un hash truncado calculado con un salt diario aleatorio
 * (iaia_salts) que se purga a los 2 días.
 */
final class Schema
{
    public const SITE_ID = 1;

    public static function events(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iaia_events';
    }

    public static function daily(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iaia_daily';
    }

    public static function salts(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iaia_salts';
    }

    /** Hook de activación: crea las tablas si no existen. */
    public static function activate(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $events = self::events();
        $daily  = self::daily();
        $salts  = self::salts();

        // dbDelta es quisquilloso; estas CREATE son idempotentes y directas.
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$salts} (
                day  DATE NOT NULL,
                salt CHAR(64) NOT NULL,
                PRIMARY KEY (day)
            ) ENGINE=InnoDB {$charset}"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$events} (
                id            BIGINT UNSIGNED AUTO_INCREMENT,
                site_id       INT UNSIGNED NOT NULL DEFAULT 1,
                created_at    DATETIME NOT NULL,
                event_type    VARCHAR(50) NOT NULL DEFAULT 'pageview',
                path          VARCHAR(255) NOT NULL DEFAULT '/',
                referrer_host VARCHAR(120) DEFAULT NULL,
                device        VARCHAR(10) NOT NULL DEFAULT 'desktop',
                browser       VARCHAR(24) DEFAULT NULL,
                visitor_hash  CHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY idx_iaia_site_time (site_id, created_at)
            ) ENGINE=InnoDB {$charset}"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$daily} (
                site_id   INT UNSIGNED NOT NULL DEFAULT 1,
                day       DATE NOT NULL,
                dimension VARCHAR(12) NOT NULL,
                dim_key   VARCHAR(255) NOT NULL DEFAULT '',
                pageviews INT UNSIGNED NOT NULL DEFAULT 0,
                visitors  INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, day, dimension, dim_key)
            ) ENGINE=InnoDB {$charset}"
        );

        update_option('iaia_analytics_version', IAIA_ANALYTICS_VERSION, false);
    }
}
