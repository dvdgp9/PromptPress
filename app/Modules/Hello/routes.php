<?php

declare(strict_types=1);

/**
 * Rutas del módulo de prueba (Hello).
 *
 * Devuelve un callable que ModuleRegistry::registerRoutes() invoca con el
 * router, la clave del módulo y los middlewares del área admin. Cada ruta se
 * protege con el guard `requireEnabled`, que responde 404 si el módulo está
 * desactivado para el sitio activo.
 */

use App\Modules\Hello\HelloController;
use App\Modules\ModuleRegistry;
use Core\Router;

return function (Router $router, string $key, array $adminMiddlewares): void {
    $guard = ModuleRegistry::requireEnabled($key);

    // Ruta admin (auth + onboarding + guard de módulo).
    $router->group('/admin', function (Router $r) use ($guard): void {
        $r->get('/modules/hello', [HelloController::class, 'index'], [$guard]);
    }, $adminMiddlewares);

    // Ruta pública (solo guard de módulo): demuestra el 404 sin sesión.
    $router->get('/_module/hello/ping', [HelloController::class, 'ping'], [$guard]);
};
