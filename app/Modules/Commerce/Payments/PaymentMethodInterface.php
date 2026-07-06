<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

/**
 * PaymentMethodInterface — contrato de los métodos de pago (C4/C5).
 *
 * El checkout solo ofrece métodos `isConfigured()`. `start()` se llama justo
 * después de crear el pedido `pending_payment` y decide a dónde va el cliente.
 * La confirmación del pago llega por otro canal (webhook de Stripe en C5, o
 * el admin marcando pagado en C6) y usa OrderStore::transition('paid').
 */
interface PaymentMethodInterface
{
    /** Clave persistida en commerce_orders.payment_method ('stripe' | 'manual'). */
    public function key(): string;

    /** Texto del radio en el checkout. */
    public function label(int $siteId): string;

    /** ¿Está listo para ofrecerse en este sitio? */
    public function isConfigured(int $siteId): bool;

    /**
     * Inicia el pago de un pedido recién creado.
     *
     * @param array<string,mixed> $order fila de commerce_orders con 'items'
     */
    public function start(int $siteId, array $order): PaymentStart;

    /**
     * HTML para la página de gracias mientras el pedido siga pendiente de
     * pago. A diferencia de start(), debe ser PURO (sin efectos: la página
     * de gracias se recarga; Stripe no puede crear una sesión por visita).
     *
     * @param array<string,mixed> $order
     */
    public function pendingInstructions(int $siteId, array $order): ?string;
}
