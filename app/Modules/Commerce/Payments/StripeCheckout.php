<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

use App\Modules\Commerce\CommerceSettings;
use Core\Database;

/**
 * StripeCheckout — pago con tarjeta vía Stripe Checkout hosted (C5).
 *
 * `start()` crea una Checkout Session con los importes snapshot del pedido y
 * redirige a la página de pago de Stripe (cero datos de tarjeta en nuestro
 * servidor). La confirmación llega por el webhook (StripeWebhookController)
 * y, como refuerzo, la página de gracias reconcilia contra la API si el
 * webhook aún no ha llegado. Referencia: cursor/stripe-api.md.
 */
final class StripeCheckout implements PaymentMethodInterface
{
    /** Vida de la sesión de pago (mínimo de Stripe: 30 min). */
    private const SESSION_TTL = 3600;

    public function key(): string
    {
        return 'stripe';
    }

    public function label(int $siteId): string
    {
        return 'Tarjeta de crédito o débito (pago seguro con Stripe)';
    }

    public function isConfigured(int $siteId): bool
    {
        return StripeConfig::isConfigured($siteId);
    }

    public function start(int $siteId, array $order): PaymentStart
    {
        $secretKey = StripeConfig::secretKey($siteId);
        if ($secretKey === null) {
            return PaymentStart::instructions(self::failureHtml($order));
        }

        $thanksUrl = base_url('tienda/gracias/' . $order['order_number'] . '?k=' . $order['access_key']);
        $params = [
            'mode'                => 'payment',
            'line_items'          => self::lineItems($order),
            'success_url'         => $thanksUrl . '&pago=ok',
            'cancel_url'          => $thanksUrl . '&pago=cancelado',
            'customer_email'      => (string) $order['customer_email'],
            'client_reference_id' => (string) $order['order_number'],
            'expires_at'          => time() + self::SESSION_TTL,
            'metadata'            => [
                'order_id'     => (string) $order['id'],
                'order_number' => (string) $order['order_number'],
                'site_id'      => (string) $siteId,
            ],
        ];

        try {
            $session = StripeApi::createCheckoutSession($secretKey, $params);
            $url = (string) ($session['url'] ?? '');
            $sessionId = (string) ($session['id'] ?? '');
            if ($url === '' || $sessionId === '') {
                throw new \RuntimeException('Stripe: la sesión no trae url/id');
            }
            Database::execute(
                'UPDATE commerce_orders SET payment_ref = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [mb_substr($sessionId, 0, 120), (int) $order['id']]
            );
            return PaymentStart::redirect($url);
        } catch (\Throwable $e) {
            logger('StripeCheckout start pedido ' . $order['order_number'] . ': ' . $e->getMessage(), 'ERROR');
            return PaymentStart::instructions(self::failureHtml($order));
        }
    }

    /**
     * Bloque para la página de gracias mientras el pedido siga pendiente:
     * el pago no se completó (canceló en Stripe, falló la tarjeta o el
     * webhook aún no llegó) → botón para reintentar.
     */
    public function pendingInstructions(int $siteId, array $order): ?string
    {
        $payUrl = base_url('tienda/pagar/' . $order['order_number'] . '?k=' . $order['access_key']);
        $html  = '<p>El pago con tarjeta <strong>todavía no se ha completado</strong>.</p>';
        $html .= '<p><a class="pp-btn pp-btn--lg" href="' . e($payUrl) . '">Completar el pago ahora</a></p>';
        $html .= '<p>Si acabas de pagar, la confirmación puede tardar unos segundos: recarga esta página. '
            . 'Si no, puedes volver a intentarlo con el botón; tu pedido queda guardado.</p>';
        return $html;
    }

    /**
     * Líneas de la Checkout Session desde el snapshot del pedido, en céntimos
     * BRUTOS (line_total_cents ya lleva el IVA resuelto en ambos modos), de
     * modo que la suma en Stripe coincide exactamente con total_cents.
     *
     * Si el bruto de la línea no es divisible por la cantidad (redondeo por
     * línea en modo IVA excluido), se envía como 1 unidad con "× N" en el
     * nombre para no perder ni un céntimo.
     *
     * @param array<string,mixed> $order
     * @return array<int, array<string,mixed>>
     */
    public static function lineItems(array $order): array
    {
        $items = [];
        foreach ($order['items'] as $it) {
            $qty = max(1, (int) $it['quantity']);
            $lineTotal = (int) $it['line_total_cents'];
            $name = (string) $it['product_name'];
            if ($lineTotal % $qty === 0) {
                $unit = intdiv($lineTotal, $qty);
            } else {
                $name .= ' × ' . $qty;
                $unit = $lineTotal;
                $qty = 1;
            }
            $items[] = self::lineItem($name, $unit, $qty);
        }
        if ((int) $order['shipping_cents'] > 0) {
            $items[] = self::lineItem('Envío', (int) $order['shipping_cents'], 1);
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private static function lineItem(string $name, int $unitAmountCents, int $qty): array
    {
        return [
            'price_data' => [
                'currency'     => strtolower(CommerceSettings::CURRENCY),
                'unit_amount'  => $unitAmountCents,
                'product_data' => ['name' => mb_substr($name, 0, 250)],
            ],
            'quantity' => $qty,
        ];
    }

    /** Fallback si Stripe no responde: el pedido no se pierde, se reintenta. */
    private static function failureHtml(array $order): string
    {
        $payUrl = base_url('tienda/pagar/' . $order['order_number'] . '?k=' . $order['access_key']);
        return '<p>No hemos podido conectar con la pasarela de pago en este momento. '
            . 'Tu pedido <strong>' . e((string) $order['order_number']) . '</strong> queda guardado.</p>'
            . '<p><a class="pp-btn pp-btn--lg" href="' . e($payUrl) . '">Reintentar el pago con tarjeta</a></p>';
    }
}
