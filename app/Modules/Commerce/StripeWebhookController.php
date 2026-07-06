<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Payments\StripeApi;
use App\Modules\Commerce\Payments\StripeConfig;
use App\Modules\ModuleRegistry;
use Core\Response;

/**
 * StripeWebhookController — POST /tienda/stripe/webhook (C5).
 *
 * Endpoint público sin sesión ni CSRF (Stripe firma cada petición). Verifica
 * la firma Stripe-Signature contra los signing secrets del sitio y confirma
 * pedidos: checkout.session.completed (payment_status=paid) o
 * checkout.session.async_payment_succeeded → transición a 'paid'.
 *
 * Idempotente por diseño: OrderStore::transition es transaccional y no-op si
 * el pedido ya está paid → los reintentos/duplicados de Stripe son inocuos.
 * Responder 200 rápido a todo evento válido para cortar los reintentos.
 * Referencia: cursor/stripe-api.md.
 */
final class StripeWebhookController
{
    public function handle(): void
    {
        $siteId = ModuleRegistry::resolveSiteId();
        if ($siteId === null) {
            Response::notFound();
        }

        $payload = (string) file_get_contents('php://input');
        $sigHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        if ($payload === '' || $sigHeader === '' || !self::signatureValid($siteId, $payload, $sigHeader)) {
            Response::json(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            Response::json(['error' => 'invalid payload'], 400);
        }

        $type = (string) ($event['type'] ?? '');
        $session = $event['data']['object'] ?? [];
        if (!is_array($session)) {
            $session = [];
        }

        switch ($type) {
            case 'checkout.session.completed':
                // Con tarjeta llega ya paid; métodos diferidos llegan unpaid
                // y confirman después vía async_payment_succeeded.
                if ((string) ($session['payment_status'] ?? '') === 'paid') {
                    self::markPaid($siteId, $session);
                }
                break;
            case 'checkout.session.async_payment_succeeded':
                self::markPaid($siteId, $session);
                break;
            case 'checkout.session.async_payment_failed':
                logger('Stripe: pago diferido fallido para el pedido '
                    . (string) ($session['metadata']['order_number'] ?? '?'), 'WARNING');
                break;
            default:
                // Evento que no nos interesa: 200 para que Stripe no reintente.
                break;
        }

        Response::json(['received' => true]);
    }

    /**
     * Confirma el pedido referenciado por la sesión. Reutilizado por la
     * reconciliación de la página de gracias (ShopController::thanks).
     *
     * @param array<string,mixed> $session Checkout Session (webhook o API)
     */
    public static function markPaid(int $siteId, array $session): void
    {
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);
        if ($orderId <= 0 || (int) ($session['metadata']['site_id'] ?? 0) !== $siteId) {
            return;
        }
        $order = OrderStore::find($siteId, $orderId);
        if ($order === null || (string) $order['payment_method'] !== 'stripe') {
            return;
        }

        if (OrderStore::transition($siteId, $orderId, 'paid')) {
            // payment_intent (pi_…) es la referencia útil para conciliar en
            // el Dashboard de Stripe; sustituye al id de sesión.
            $pi = $session['payment_intent'] ?? null;
            if (is_string($pi) && $pi !== '') {
                \Core\Database::execute(
                    'UPDATE commerce_orders SET payment_ref = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [mb_substr($pi, 0, 120), $orderId]
                );
            }
            try {
                CommerceMailer::sendStatusChange($siteId, $orderId, 'paid');
            } catch (\Throwable) {
                // el email nunca rompe la confirmación
            }
        }
    }

    /** Acepta la firma de cualquiera de los dos modos (cubre el cambio test↔live). */
    private static function signatureValid(int $siteId, string $payload, string $sigHeader): bool
    {
        foreach (['test', 'live'] as $mode) {
            $secret = StripeConfig::webhookSecret($siteId, $mode);
            if ($secret !== null && StripeApi::verifySignature($payload, $sigHeader, $secret)) {
                return true;
            }
        }
        return false;
    }
}
