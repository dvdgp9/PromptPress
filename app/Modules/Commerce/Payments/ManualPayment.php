<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

use App\Services\Microcopy;

use App\Modules\Commerce\CommerceSettings;

/**
 * ManualPayment — transferencia / contra reembolso (C4).
 *
 * El pedido queda `pending_payment` con instrucciones (texto del sitio,
 * `commerce_manual_instructions`) y el nº de pedido como concepto. El admin
 * lo marca pagado a mano cuando llega el dinero (C6).
 */
final class ManualPayment implements PaymentMethodInterface
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(int $siteId): string
    {
        return Microcopy::site($siteId, 'shop.pay_manual');
    }

    /** Siempre disponible: es el método sin dependencias. */
    public function isConfigured(int $siteId): bool
    {
        return true;
    }

    public function start(int $siteId, array $order): PaymentStart
    {
        return PaymentStart::instructions((string) $this->pendingInstructions($siteId, $order));
    }

    /** Las instrucciones de pago manual son las mismas al crear y al recargar. */
    public function pendingInstructions(int $siteId, array $order): ?string
    {
        $custom = trim(CommerceSettings::get($siteId, 'commerce_manual_instructions'));
        // Los textos de Microcopy llevan <strong> inline y son contenido
        // nuestro: van sin escapar. Las instrucciones del usuario sí se escapan.
        $html = '<p>' . Microcopy::site($siteId, 'shop.manual_pending') . '</p>';
        if ($custom !== '') {
            $html .= '<p>' . nl2br(e($custom)) . '</p>';
        } else {
            $html .= '<p>' . e(Microcopy::site($siteId, 'shop.manual_contact')) . '</p>';
        }
        $html .= '<p>' . Microcopy::site($siteId, 'shop.manual_reference', [
            'number' => e((string) $order['order_number']),
        ]) . '</p>';
        return $html;
    }
}
