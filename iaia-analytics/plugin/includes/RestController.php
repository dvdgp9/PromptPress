<?php

declare(strict_types=1);

namespace IaiaAnalytics;

use WP_REST_Request;
use WP_REST_Response;

/**
 * RestController — endpoints REST del plugin.
 *
 * - POST /iaia-analytics/v1/collect: ingesta pública stateless (adaptación de
 *   AnalyticsController::collect de PromptPress). Responde 204 SIEMPRE, se
 *   registre o no el evento: no filtra información y nunca rompe al cliente.
 * - GET /iaia-analytics/v1/stats?range=N: datos del dashboard para el cambio
 *   de rango sin recargar. Solo administradores (manage_options).
 */
final class RestController
{
    public const NAMESPACE = 'iaia-analytics/v1';

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/collect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'collect'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/stats', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'stats'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        ]);
    }

    public static function collect(WP_REST_Request $request): WP_REST_Response
    {
        $data = $request->get_json_params();
        if (is_array($data)) {
            $path      = (string) ($data['p'] ?? '/');
            $referrer  = isset($data['r']) ? (string) $data['r'] : null;
            $eventType = isset($data['e']) && $data['e'] !== '' ? (string) $data['e'] : 'pageview';

            EventRecorder::record(
                $eventType,
                $path,
                $referrer,
                self::clientIp(),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        }
        return new WP_REST_Response(null, 204);
    }

    public static function stats(WP_REST_Request $request): WP_REST_Response
    {
        RollupService::maybeRun();
        $range = (int) $request->get_param('range');
        return new WP_REST_Response([
            'ok'    => true,
            'stats' => StatsService::forRange($range),
        ], 200);
    }

    /**
     * IP del cliente. Solo se usa en memoria para el hash de visitante del
     * día; jamás se persiste. REMOTE_ADDR basta: si hay proxy inverso, el
     * hosting debe configurarlo para reescribirla (comportamiento estándar).
     */
    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
