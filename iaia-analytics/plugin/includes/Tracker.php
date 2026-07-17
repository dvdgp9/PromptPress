<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * Tracker — inyección del script de tracking en el frontend.
 *
 * No se trackea a los usuarios logueados que pueden editar contenido
 * (current_user_can('edit_posts')): evita inflar los datos con las visitas
 * del propio administrador/redactores.
 */
final class Tracker
{
    public static function enqueue(): void
    {
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return;
        }

        $handle = 'iaia-analytics-tracker';
        wp_enqueue_script(
            $handle,
            IAIA_ANALYTICS_URL . 'assets/js/tracker.js',
            [],
            IAIA_ANALYTICS_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_localize_script($handle, 'IAIA_ANALYTICS', [
            'endpoint' => rest_url(RestController::NAMESPACE . '/collect'),
        ]);
    }
}
