<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

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
        return 'Transferencia bancaria o pago acordado';
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
        $html = '<p>Tu pedido queda <strong>pendiente de pago</strong>.</p>';
        if ($custom !== '') {
            $html .= '<p>' . nl2br(e($custom)) . '</p>';
        } else {
            $html .= '<p>Te contactaremos por email con las instrucciones de pago.</p>';
        }
        $html .= '<p>Indica como concepto el número de pedido: <strong>'
            . e((string) $order['order_number']) . '</strong>.</p>';
        return $html;
    }
}
