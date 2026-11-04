<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\Commerce\CartService;
use App\Modules\Commerce\CommerceSettings;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\Payments\StripeApi;
use App\Modules\Commerce\Payments\StripeCheckout;
use App\Modules\Commerce\Payments\StripeConfig;
use App\Modules\ModuleRegistry;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BookingPayment — cobrar con Stripe al reservar (BOOK-PAGO).
 *
 * Contrato: Booking es dueño del hueco; Commerce del cobro. Una reserva de
 * pago nace `awaiting_payment` con `hold_until_utc` y un pedido de Commerce de
 * una línea (`commerce_order_items.booking_id`). Todo cambio del PEDIDO
 * (webhook de Stripe, admin de la Tienda) llega aquí por `syncFromOrder`,
 * llamado desde OrderStore::transition: es el único sitio que pasa una reserva
 * de pago a confirmada.
 *
 * Doble cobro: al reintentar se reutiliza la sesión de Stripe abierta en vez
 * de crear otra, y la sesión caduca a la vez que el bloqueo del hueco.
 */
final class BookingPayment
{
    /** ¿El servicio cobra al reservar? @param array<string,mixed> $service */
    public static function requiresPayment(array $service): bool
    {
        return (int) ($service['requires_payment'] ?? 0) === 1 && (int) ($service['price_cents'] ?? 0) > 0;
    }

    /** ¿Puede este sitio cobrar reservas? Tienda activa + Stripe configurado. */
    public static function available(int $siteId): bool
    {
        return ModuleRegistry::isEnabled($siteId, 'commerce') && StripeConfig::isConfigured($siteId);
    }

    /** Minutos que se guarda el hueco: lo que dura la sesión de Stripe + 1 de margen. */
    public static function holdMinutes(): int
    {
        return BookingService::PAYMENT_HOLD_MINUTES + 1;
    }

    /**
     * Crea el pedido de Commerce que cobra la reserva y lo enlaza.
     *
     * @param array<string,mixed> $service
     * @param array<string,mixed> $booking fila de booking_bookings
     * @return array<string,mixed> pedido con items
     */
    public static function createOrder(int $siteId, array $service, array $booking, ?string $ipHash): array
    {
        $tz = BookingService::siteTimezone($siteId);
        $when = (new DateTimeImmutable((string) $booking['starts_at_utc'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))->format('d/m/Y H:i');
        [$total, $tax] = CartService::lineAmounts(
            (int) $service['price_cents'],
            (float) ($service['tax_rate'] ?? 21),
            1,
            CommerceSettings::pricesIncludeTax($siteId)
        );
        $order = OrderStore::createForBooking($siteId, [
            'name'  => (string) $booking['customer_name'],
            'email' => (string) $booking['customer_email'],
            'phone' => (string) ($booking['customer_phone'] ?? ''),
        ], [
            'booking_id'       => (int) $booking['id'],
            'name'             => (string) $service['name'] . ' — ' . $when,
            'unit_price_cents' => (int) $service['price_cents'],
            'tax_rate'         => (float) ($service['tax_rate'] ?? 21),
            'total_cents'      => $total,
            'tax_cents'        => $tax,
        ], (string) ($booking['language'] ?? ''), $ipHash);

        Database::execute(
            'UPDATE booking_bookings SET order_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [(int) $order['id'], (int) $booking['id']]
        );
        return $order;
    }

    /**
     * URL a la que mandar al cliente para pagar, o null si ya no se puede
     * (pagada, cancelada o bloqueo caducado). Si Stripe falla, devuelve la
     * página de la reserva, que ofrece reintentar.
     */
    public static function checkoutUrl(int $siteId, int $bookingId): ?string
    {
        $booking = self::booking($siteId, $bookingId);
        if ($booking === null || !self::isPayable($booking)) {
            return null;
        }
        $order = OrderStore::find($siteId, (int) $booking['order_id']);
        if ($order === null || (string) $order['status'] !== 'pending_payment') {
            return null;
        }
        $secretKey = StripeConfig::secretKey($siteId);
        if ($secretKey === null) {
            return self::statusUrl($booking);
        }

        try {
            // Reintento: si la sesión anterior sigue abierta, la misma. Crear
            // otra dejaría dos sesiones cobrables para un solo hueco.
            $ref = (string) ($order['payment_ref'] ?? '');
            if (str_starts_with($ref, 'cs_')) {
                $prev = StripeApi::getCheckoutSession($secretKey, $ref);
                if ((string) ($prev['payment_status'] ?? '') === 'paid') {
                    \App\Modules\Commerce\StripeWebhookController::markPaid($siteId, $prev);
                    return self::statusUrl($booking);
                }
                if ((string) ($prev['status'] ?? '') === 'open' && (string) ($prev['url'] ?? '') !== '') {
                    return (string) $prev['url'];
                }
            }

            // Stripe exige que la sesión dure al menos 30 min. Si el primer
            // intento falló y queda menos, se amplía el bloqueo: el hueco ya es
            // de esta reserva, nadie más lo puede haber cogido.
            $minUntil = time() + BookingService::PAYMENT_HOLD_MINUTES * 60 + 30;
            $holdTs = strtotime((string) $booking['hold_until_utc'] . ' UTC');
            if ($holdTs < $minUntil + 30) {
                $holdTs = time() + self::holdMinutes() * 60;
                Database::execute(
                    'UPDATE booking_bookings SET hold_until_utc = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [gmdate('Y-m-d H:i:s', $holdTs), $bookingId]
                );
                $booking['hold_until_utc'] = gmdate('Y-m-d H:i:s', $holdTs);
            }

            $back = self::statusUrl($booking);
            $session = StripeApi::createCheckoutSession($secretKey, [
                'mode'                => 'payment',
                'line_items'          => StripeCheckout::lineItems($order),
                'success_url'         => $back . '&pago=ok',
                'cancel_url'          => $back . '&pago=cancelado',
                'customer_email'      => (string) $order['customer_email'],
                'client_reference_id' => (string) $order['order_number'],
                // Nunca más tarde que el bloqueo: no se puede pagar un hueco liberado.
                'expires_at'          => max($minUntil, $holdTs - 30),
                'metadata'            => [
                    'order_id'     => (string) $order['id'],
                    'order_number' => (string) $order['order_number'],
                    'site_id'      => (string) $siteId,
                    'booking_id'   => (string) $bookingId,
                ],
            ]);
            $url = (string) ($session['url'] ?? '');
            $sessionId = (string) ($session['id'] ?? '');
            if ($url === '' || $sessionId === '') {
                // i18n-ignore: excepción interna, va al log.
                throw new \RuntimeException('Stripe: la sesión no trae url/id');
            }
            Database::execute(
                'UPDATE commerce_orders SET payment_ref = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [mb_substr($sessionId, 0, 120), (int) $order['id']]
            );
            return $url;
        } catch (\Throwable $e) {
            logger('BookingPayment checkout reserva ' . $bookingId . ': ' . $e->getMessage(), 'ERROR');
            return self::statusUrl($booking);
        }
    }

    /**
     * Si el cliente vuelve de Stripe antes que el webhook, se pregunta a
     * Stripe (idempotente: markPaid no repite nada).
     */
    public static function reconcile(int $siteId, int $bookingId): void
    {
        $booking = self::booking($siteId, $bookingId);
        if ($booking === null || (string) $booking['status'] !== 'awaiting_payment' || empty($booking['order_id'])) {
            return;
        }
        $order = OrderStore::find($siteId, (int) $booking['order_id']);
        $ref = (string) ($order['payment_ref'] ?? '');
        $secretKey = StripeConfig::secretKey($siteId);
        if ($order === null || $secretKey === null || !str_starts_with($ref, 'cs_')) {
            return;
        }
        try {
            $session = StripeApi::getCheckoutSession($secretKey, $ref);
            if ((string) ($session['payment_status'] ?? '') === 'paid') {
                \App\Modules\Commerce\StripeWebhookController::markPaid($siteId, $session);
            }
        } catch (\Throwable $e) {
            logger('BookingPayment reconcile reserva ' . $bookingId . ': ' . $e->getMessage(), 'WARNING');
        }
    }

    /**
     * Refleja en la reserva un cambio de estado de su pedido. Lo llama
     * OrderStore::transition después de confirmar la transición.
     */
    public static function syncFromOrder(int $siteId, int $orderId, string $to): void
    {
        $row = Database::selectOne(
            'SELECT booking_id FROM commerce_order_items WHERE order_id = ? AND booking_id IS NOT NULL LIMIT 1',
            [$orderId]
        );
        if ($row === null) {
            return;
        }
        $booking = self::booking($siteId, (int) $row['booking_id']);
        if ($booking === null) {
            return;
        }
        $bookingId = (int) $booking['id'];
        $status = (string) $booking['status'];

        if ($to === 'paid') {
            if ($status === 'cancelled') {
                // Pagó una reserva que ya se había cancelado (p. ej. con la
                // pestaña de Stripe abierta): no se resucita el hueco, que puede
                // ser de otro. Se avisa para devolver el dinero.
                logger('BookingPayment: pago recibido de la reserva cancelada ' . $bookingId, 'WARNING');
                BookingMailer::notifyAdminPaidCancelled($siteId, $bookingId);
                return;
            }
            if ($status !== 'awaiting_payment') {
                return; // ya confirmada: réplica del webhook
            }
            if (strtotime((string) $booking['hold_until_utc'] . ' UTC') < time()) {
                logger('BookingPayment: pago tras caducar el bloqueo de la reserva ' . $bookingId . '; se confirma igualmente', 'WARNING');
            }
            Database::execute(
                "UPDATE booking_bookings SET status = 'confirmed', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$bookingId]
            );
            try {
                BookingMailer::sendCreated($siteId, $bookingId);
            } catch (\Throwable) {
                // el email nunca rompe la confirmación
            }
            return;
        }

        if ($to === 'cancelled' && $status !== 'cancelled') {
            Database::execute(
                "UPDATE booking_bookings SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$bookingId]
            );
            // Solo se escribe al cliente si la reserva llegó a ser suya (pagada);
            // una que caducó sin pagar no le debe un email.
            if ($status === 'confirmed' || $status === 'pending') {
                try {
                    BookingMailer::sendStatusChange($siteId, $bookingId, 'cancelled');
                } catch (\Throwable) {
                    // silencioso a propósito
                }
            }
        }
    }

    /**
     * El lado contrario: la RESERVA se cancela (cliente o panel). Si no estaba
     * pagada, se cancela su pedido; si lo estaba, el pedido se queda pagado y
     * la devolución se hace a mano en Stripe.
     *
     * @return bool true si la reserva estaba pagada (hay que devolver)
     */
    public static function onBookingCancelled(int $siteId, int $bookingId): bool
    {
        $booking = self::booking($siteId, $bookingId);
        if ($booking === null || empty($booking['order_id'])) {
            return false;
        }
        $order = Database::selectOne(
            'SELECT id, status FROM commerce_orders WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, (int) $booking['order_id']]
        );
        if ($order === null) {
            return false;
        }
        if ((string) $order['status'] === 'pending_payment') {
            OrderStore::transition($siteId, (int) $order['id'], 'cancelled');
            return false;
        }
        return (string) $order['status'] === 'paid';
    }

    /** ¿La reserva tiene un pedido pagado? @param array<string,mixed> $booking */
    public static function isPaid(int $siteId, array $booking): bool
    {
        if (empty($booking['order_id'])) {
            return false;
        }
        $order = Database::selectOne(
            'SELECT status FROM commerce_orders WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, (int) $booking['order_id']]
        );
        return $order !== null && in_array((string) $order['status'], ['paid', 'shipped'], true);
    }

    /**
     * Limpieza perezosa (sin cron): las reservas cuyo bloqueo caducó sin pago
     * pasan a canceladas y su pedido también. Ya no ocupaban plaza (ACTIVE_SQL);
     * esto solo deja ordenados los listados del panel.
     */
    public static function expireStale(int $siteId): int
    {
        $rows = Database::select(
            "SELECT id, order_id FROM booking_bookings
              WHERE site_id = ? AND status = 'awaiting_payment' AND hold_until_utc <= UTC_TIMESTAMP()
              LIMIT 200",
            [$siteId]
        );
        foreach ($rows as $r) {
            Database::execute(
                "UPDATE booking_bookings SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status = 'awaiting_payment'",
                [(int) $r['id']]
            );
            if (!empty($r['order_id'])) {
                $o = Database::selectOne('SELECT status FROM commerce_orders WHERE id = ?', [(int) $r['order_id']]);
                if ($o !== null && (string) $o['status'] === 'pending_payment') {
                    OrderStore::transition($siteId, (int) $r['order_id'], 'cancelled');
                }
            }
        }
        return count($rows);
    }

    /** Página pública de la reserva de pago (vuelta de Stripe, reintento). @param array<string,mixed> $booking */
    public static function statusUrl(array $booking): string
    {
        return base_url('_booking/pago/' . (int) $booking['id'] . '?token=' . (string) $booking['cancel_token']);
    }

    /** @param array<string,mixed> $booking */
    public static function isPayable(array $booking): bool
    {
        return (string) $booking['status'] === 'awaiting_payment'
            && !empty($booking['order_id'])
            && strtotime((string) $booking['hold_until_utc'] . ' UTC') > time();
    }

    /** @return array<string,mixed>|null */
    private static function booking(int $siteId, int $bookingId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $bookingId]
        );
    }
}
