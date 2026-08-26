<?php

declare(strict_types=1);

/**
 * Rutas del módulo Recursos. El fichero queda preparado en R2; R3 añadirá
 * `resources` al ModuleRegistry y entonces estas rutas quedarán expuestas bajo
 * su guard de activación por sitio.
 */

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceAdminController;
use App\Modules\Resources\ResourceDownloadController;
use App\Modules\Resources\ResourcePublicController;
use Core\Router;

return function (Router $router, string $key, array $adminMiddlewares): void {
    $guard = ModuleRegistry::requireEnabled($key);
    $router->get('/recursos', [ResourcePublicController::class, 'index'], [$guard]);
    $router->get('/recursos/{slug}', [ResourcePublicController::class, 'detail'], [$guard]);
    $router->get('/{lang}/recursos', [ResourcePublicController::class, 'index'], [$guard]);
    $router->get('/{lang}/recursos/{slug}', [ResourcePublicController::class, 'detail'], [$guard]);
    $router->get('/recursos/{slug}/descargar', [ResourceDownloadController::class, 'direct'], [$guard]);
    $router->get('/{lang}/recursos/{slug}/descargar', [ResourceDownloadController::class, 'direct'], [$guard]);

    $router->group('/admin', function (Router $r) use ($guard): void {
        $r->get('/resources',                       [ResourceAdminController::class, 'index'],   [$guard]);
        $r->post('/resources',                      [ResourceAdminController::class, 'create'],  [$guard]);
        $r->get('/resources/{id}',                  [ResourceAdminController::class, 'edit'],    [$guard]);
        $r->post('/resources/{id}',                 [ResourceAdminController::class, 'update'],  [$guard]);
        $r->post('/resources/{id}/delete',          [ResourceAdminController::class, 'destroy'], [$guard]);
    }, $adminMiddlewares);
};
