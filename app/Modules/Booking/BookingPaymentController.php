<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\ModuleRegistry;
use App\Services\LanguageService;
use App\Services\Microcopy;
use Core\Database;
use Core\Request;
use Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BookingPaymentController — página pública de una reserva de pago (BOOK-PAGO).
 *
 *   GET /_booking/pago/{id}?token=…           → estado (vuelta de Stripe, reintento)
 *   GET /_booking/pago/{id}/stripe?token=…    → manda a Stripe (sesión abierta o nueva)
 *
 * El token es el mismo `cancel_token` de la reserva: solo lo tiene quien la hizo.
 */
final class BookingPaymentController
{
    public function show(array $params = []): void
    {
        $booking = $this->load($params);
        $siteId = (int) $booking['site_id'];
        $id = (int) $booking['id'];

        // Vuelve de Stripe antes que el webhook: se pregunta a Stripe.
        if ((string) Request::get('pago', '') === 'ok') {
            BookingPayment::reconcile($siteId, $id);
            $booking = $this->load($params);
        }

        $lang = trim((string) ($booking['language'] ?? '')) !== ''
            ? LanguageService::normalize((string) $booking['language'])
            : LanguageService::codeFor($siteId);
        $t = static fn (string $key, array $vars = []): string => Microcopy::t('booking.' . $key, $lang, $vars);
        $tz = BookingService::siteTimezone($siteId);
        $service = Database::selectOne('SELECT name FROM booking_services WHERE id = ? LIMIT 1', [(int) $booking['service_id']]);
        $detail = '<p class="detail"><strong>' . e((string) ($service['name'] ?? $t('fallback_service'))) . '</strong><br>'
            . e($this->local((string) $booking['starts_at_utc'], $tz, 'd/m/Y H:i')) . '</p>';
        $status = (string) $booking['status'];

        if ($status === 'confirmed') {
            BookingCancelController::renderPage($siteId, $t('pay_done_title'),
                '<p class="ok">' . e($t('pay_done_body')) . '</p>' . $detail, $lang);
        }

        if ($status === 'awaiting_payment' && BookingPayment::isPayable($booking)) {
            $html = '';
            if ((string) Request::get('pago', '') === 'cancelado') {
                $html .= '<p>' . e($t('pay_cancelled_note')) . '</p>';
            } elseif ((string) Request::get('pago', '') === 'ok') {
                // Pagó pero Stripe aún no lo confirma (segundos): que recargue.
                $html .= '<p>' . e($t('pay_processing')) . '</p>';
            } else {
                $html .= '<p>' . e($t('pay_pending_body')) . '</p>';
            }
            $html .= $detail
                . '<p><a class="btn" href="' . e(base_url('_booking/pago/' . $id . '/stripe?token=' . $booking['cancel_token'])) . '">'
                . e($t('pay_button')) . '</a></p>'
                . '<p class="soft">' . e($t('pay_hold_note', [
                    'time' => $this->local((string) $booking['hold_until_utc'], $tz, 'H:i'),
                ])) . '</p>';
            BookingCancelController::renderPage($siteId, $t('pay_pending_title'), $html, $lang);
        }

        if ($status === 'awaiting_payment') {
            // Bloqueo caducado: se deja ordenado (reserva y pedido cancelados).
            BookingPayment::expireStale($siteId);
            BookingCancelController::renderPage($siteId, $t('pay_expired_title'),
                '<p>' . e($t('pay_expired_body')) . '</p>', $lang);
        }

        if ($status === 'cancelled') {
            $paid = BookingPayment::isPaid($siteId, $booking);
            BookingCancelController::renderPage($siteId,
                $paid ? $t('cancel_already_title') : $t('pay_expired_title'),
                '<p>' . e($paid ? $t('cancel_already_body') : $t('pay_expired_body')) . '</p>', $lang);
        }

        // pending (servicio sin pago): no es página de pago.
        BookingCancelController::renderPage($siteId, $t('sent_title'), '<p>' . e($t('created_pending')) . '</p>' . $detail, $lang);
    }

    public function stripe(array $params = []): void
    {
        $booking = $this->load($params);
        $url = BookingPayment::checkoutUrl((int) $booking['site_id'], (int) $booking['id']);
        Response::redirect($url ?? BookingPayment::statusUrl($booking));
    }

    /** @return array<string,mixed> */
    private function load(array $params): array
    {
        $siteId = ModuleRegistry::resolveSiteId();
        $id = (int) ($params['id'] ?? 0);
        $token = trim((string) Request::get('token', ''));
        if ($siteId === null || $id <= 0 || $token === '' || strlen($token) > 64) {
            Response::notFound();
        }
        $booking = Database::selectOne('SELECT * FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1', [$siteId, $id]);
        if ($booking === null || !hash_equals((string) $booking['cancel_token'], $token)) {
            Response::notFound();
        }
        return $booking;
    }

    private function local(string $utc, string $tz, string $format): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format($format);
    }
}
