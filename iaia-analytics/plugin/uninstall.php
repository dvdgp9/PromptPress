<?php
/**
 * uninstall.php — se ejecuta al BORRAR el plugin desde wp-admin.
 *
 * POR DEFECTO CONSERVA LOS DATOS: borrar el plugin (p. ej. para subir una
 * versión nueva) no destruye el histórico de analítica. Las tablas solo se
 * eliminan si la opción `iaia_analytics_delete_on_uninstall` vale '1'
 * (podrá activarse desde una futura página de ajustes; hoy, vía
 * `wp option update iaia_analytics_delete_on_uninstall 1` o similar).
 *
 * Desactivar el plugin nunca borra nada.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (get_option('iaia_analytics_delete_on_uninstall') === '1') {
    global $wpdb;
    foreach (['iaia_events', 'iaia_daily', 'iaia_salts'] as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
    }
    delete_option('iaia_analytics_delete_on_uninstall');
    delete_option('iaia_analytics_version');
    delete_option('iaia_analytics_rollup_last');
}
