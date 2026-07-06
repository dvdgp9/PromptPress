<?php

declare(strict_types=1);

/**
 * Rutas del módulo Commerce / PromptCommerce (FEAT-3 Fase C).
 *
 * C2: solo admin (CRUD de productos). El catálogo público /tienda/*, el
 * carrito/checkout y los pedidos llegan en C3-C6. Guard `requireEnabled`
 * → 404 con el módulo apagado.
 */

use App\Modules\Commerce\CommerceAdminController;
use App\Modules\Commerce\ShopController;
use App\Modules\Commerce\StripeWebhookController;
use App\Modules\ModuleRegistry;
use Core\Router;

return function (Router $router, string $key, array $adminMiddlewares): void {
    $guard = ModuleRegistry::requireEnabled($key);

    // Tienda pública (C3). Rutas dinámicas, sin caché de páginas; el slug
    // `tienda` queda reservado con el módulo activo (documentado en el diseño).
    $router->get('/tienda',          [ShopController::class, 'index'],   [$guard]);
    $router->get('/tienda/p/{slug}', [ShopController::class, 'product'], [$guard]);
    // Carrito + checkout invitado (C4).
    $router->get('/tienda/carrito',           [ShopController::class, 'cart'],           [$guard]);
    $router->post('/tienda/carrito',          [ShopController::class, 'cartUpdate'],     [$guard]);
    $router->get('/tienda/checkout',          [ShopController::class, 'checkout'],       [$guard]);
    $router->post('/tienda/checkout',         [ShopController::class, 'checkoutSubmit'], [$guard]);
    $router->get('/tienda/gracias/{number}',  [ShopController::class, 'thanks'],         [$guard]);
    // Stripe (C5): reintento de pago (crea una sesión nueva y redirige) y
    // webhook de confirmación (firmado por Stripe; sin sesión ni CSRF).
    $router->get('/tienda/pagar/{number}',    [ShopController::class, 'payRetry'],       [$guard]);
    $router->post('/tienda/stripe/webhook',   [StripeWebhookController::class, 'handle'], [$guard]);

    $router->group('/admin', function (Router $r) use ($guard): void {
        $r->get('/commerce',                       [CommerceAdminController::class, 'index'],   [$guard]);
        $r->get('/commerce/pagos',                 [CommerceAdminController::class, 'payments'],     [$guard]);
        $r->post('/commerce/pagos',                [CommerceAdminController::class, 'paymentsSave'], [$guard]);
        $r->get('/commerce/pedidos',               [CommerceAdminController::class, 'orders'],       [$guard]);
        $r->get('/commerce/pedidos/{id}',          [CommerceAdminController::class, 'order'],         [$guard]);
        $r->post('/commerce/pedidos/{id}/status',  [CommerceAdminController::class, 'orderStatus'],   [$guard]);
        $r->post('/commerce/pedidos/{id}/notes',   [CommerceAdminController::class, 'orderNotes'],    [$guard]);
        $r->post('/commerce/products',             [CommerceAdminController::class, 'create'],  [$guard]);
        $r->get('/commerce/products/{id}',         [CommerceAdminController::class, 'edit'],    [$guard]);
        $r->post('/commerce/products/{id}',        [CommerceAdminController::class, 'update'],  [$guard]);
        $r->post('/commerce/products/{id}/delete', [CommerceAdminController::class, 'destroy'], [$guard]);
    }, $adminMiddlewares);
};
