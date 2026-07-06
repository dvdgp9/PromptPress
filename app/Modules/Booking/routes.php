<?php

declare(strict_types=1);

/**
 * Rutas del módulo Booking (FEAT-3 Fase B).
 *
 * B2: solo admin (CRUD de servicios). La API pública /api/booking/v1/* se
 * añade en B4. Guard `requireEnabled` → 404 con el módulo apagado.
 */

use App\Modules\Booking\BookingAdminController;
use App\Modules\Booking\BookingApiController;
use App\Modules\Booking\BookingCancelController;
use App\Modules\ModuleRegistry;
use Core\Router;

return function (Router $router, string $key, array $adminMiddlewares): void {
    $guard = ModuleRegistry::requireEnabled($key);

    // API pública JSON (B4) — stateless, sin CSRF; contrato en booking-design.md §6.
    $router->get('/api/booking/v1/services',                    [BookingApiController::class, 'services'],     [$guard]);
    $router->get('/api/booking/v1/services/{id}/availability',  [BookingApiController::class, 'availability'], [$guard]);
    $router->post('/api/booking/v1/bookings',                   [BookingApiController::class, 'create'],       [$guard]);
    $router->post('/api/booking/v1/bookings/{id}/cancel',       [BookingApiController::class, 'cancel'],       [$guard]);
    foreach (['/api/booking/v1/services', '/api/booking/v1/services/{id}/availability',
              '/api/booking/v1/bookings', '/api/booking/v1/bookings/{id}/cancel'] as $p) {
        $router->options($p, [BookingApiController::class, 'preflight'], [$guard]);
    }

    // Link de cancelación del email (B5): GET confirma, POST ejecuta.
    $router->get('/_booking/cancel/{id}',  [BookingCancelController::class, 'show'],   [$guard]);
    $router->post('/_booking/cancel/{id}', [BookingCancelController::class, 'cancel'], [$guard]);

    $router->group('/admin', function (Router $r) use ($guard): void {
        $r->get('/booking',                        [BookingAdminController::class, 'index'],   [$guard]);
        $r->post('/booking/services',              [BookingAdminController::class, 'create'],  [$guard]);
        $r->get('/booking/services/{id}',          [BookingAdminController::class, 'edit'],    [$guard]);
        $r->post('/booking/services/{id}',         [BookingAdminController::class, 'update'],  [$guard]);
        $r->post('/booking/services/{id}/delete',  [BookingAdminController::class, 'destroy'], [$guard]);
        // Gestión de reservas (B5).
        $r->get('/booking/reservas',               [BookingAdminController::class, 'bookings'],      [$guard]);
        $r->post('/booking/reservas/{id}/status',  [BookingAdminController::class, 'bookingStatus'], [$guard]);
        // Integración externa: API key + orígenes (B6).
        $r->post('/booking/integration',           [BookingAdminController::class, 'integration'],   [$guard]);
    }, $adminMiddlewares);
};
