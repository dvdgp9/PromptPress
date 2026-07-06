<?php

declare(strict_types=1);

/**
 * Rutas del módulo Analytics (FEAT-3).
 *
 * Todas se protegen con el guard `requireEnabled`, que devuelve 404 si el
 * módulo está apagado para el sitio.
 */

use App\Modules\Analytics\AnalyticsController;
use App\Modules\ModuleRegistry;
use Core\Router;

return function (Router $router, string $key, array $adminMiddlewares): void {
    $guard = ModuleRegistry::requireEnabled($key);

    // Ingesta pública, stateless. Prefijo /_analytics para no chocar con el
    // catch-all de slugs públicos.
    $router->post('/_analytics/collect', [AnalyticsController::class, 'collect'], [$guard]);

    // Dashboard admin (A5): auth + onboarding + módulo activo.
    $router->group('/admin', function (Router $r) use ($guard): void {
        $r->get('/analytics',      [AnalyticsController::class, 'dashboard'], [$guard]);
        $r->get('/analytics/data', [AnalyticsController::class, 'data'],      [$guard]);
    }, $adminMiddlewares);
};
