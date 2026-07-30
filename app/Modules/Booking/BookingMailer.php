<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Services\DateFormat;
use App\Services\FormSubmissionService;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailService;
use Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BookingMailer — emails del ciclo de vida de una reserva (B5).
 *
 * Reglas (booking-design.md §7):
 *   - Al crear: email al cliente (confirmada con ICS si auto_confirm, o
 *     "pendiente de confirmación") con link de cancelación + aviso al admin.
 *   - Al confirmar desde el admin: email al cliente con ICS.
 *   - Al cancelar (admin o cliente): email de estado al implicado contrario.
 *
 * Un fallo de SMTP NUNCA pierde la reserva: el resultado se registra en
 *  booking_bookings.email_status/email_error ('sent'|'failed'|'skipped',
 * mismo vocabulario que form_submissions).
 */
final class BookingMailer
{
    /** Email(s) al crear la reserva. Actualiza email_status en la fila. */
    public static function sendCreated(int $siteId, int $bookingId): void
    {
        $ctx = self::context($siteId, $bookingId);
        if ($ctx === null) {
            return;
        }
        [$booking, $service, $siteName, $tz] = $ctx;

        $lang = self::language($siteId, $booking);
        $t = static fn (string $key, array $vars = []): string => Microcopy::t($key, $lang, $vars);
        $confirmed = (string) $booking['status'] === 'confirmed';
        $when = self::humanWhen($booking, $tz, $lang);
        $cancelUrl = base_url('_booking/cancel/' . $booking['id'] . '?token=' . $booking['cancel_token']);

        $subject = $t($confirmed ? 'mail.booking.confirmed_subject' : 'mail.booking.received_subject', [
            'service' => (string) $service['name'],
            'when'    => $when,
        ]);
        $lines = [
            $t('mail.greeting', ['name' => $booking['customer_name']]),
            '',
            $t($confirmed ? 'mail.booking.confirmed_intro' : 'mail.booking.received_intro'),
            '',
            '• ' . $t('mail.booking.field_service') . ': ' . $service['name'],
            '• ' . $t('mail.booking.field_when') . ': ' . $when,
            '',
            $t('mail.booking.cancel_intro'),
            $cancelUrl,
            '',
            $siteName,
        ];
        $msg = new MailMessage((string) $booking['customer_email'], $subject, implode("\n", $lines), '', (string) $booking['customer_name']);
        if ($confirmed) {
            $msg->attach(self::buildIcs($booking, $service, $siteName, $lang), 'reserva.ics', 'text/calendar; method=REQUEST');
        }
        self::deliverToCustomer($siteId, (int) $booking['id'], $msg);

        // El aviso al admin va ENTERO en castellano, fecha incluida: el panel
        // es castellano y una fecha en francés dentro de un texto español
        // quedaba a medio camino.
        $whenAdmin = self::humanWhen($booking, $tz);
        self::notifyAdmin($siteId, sprintf(
            "Nueva reserva %s\n\nServicio: %s\nFecha: %s\nCliente: %s <%s>%s%s\n\nGestión: %s",
            $confirmed ? '(confirmada automáticamente)' : '(pendiente de confirmar)',
            $service['name'],
            $whenAdmin,
            $booking['customer_name'],
            $booking['customer_email'],
            $booking['customer_phone'] !== null ? "\nTeléfono: " . $booking['customer_phone'] : '',
            $booking['notes'] !== null ? "\nNotas: " . $booking['notes'] : '',
            base_url('admin/booking/reservas')
        ), 'Nueva reserva: ' . $service['name'] . ' — ' . $whenAdmin);
    }

    /** Email al cliente cuando el admin confirma o cancela. */
    public static function sendStatusChange(int $siteId, int $bookingId, string $newStatus): void
    {
        $ctx = self::context($siteId, $bookingId);
        if ($ctx === null) {
            return;
        }
        [$booking, $service, $siteName, $tz] = $ctx;
        $lang = self::language($siteId, $booking);
        $t = static fn (string $key, array $vars = []): string => Microcopy::t($key, $lang, $vars);
        $when = self::humanWhen($booking, $tz, $lang);
        $vars = ['service' => (string) $service['name'], 'when' => $when];

        if ($newStatus === 'confirmed') {
            $subject = $t('mail.booking.confirmed_subject', $vars);
            $body = $t('mail.greeting', ['name' => $booking['customer_name']]) . "\n\n"
                . $t('mail.booking.confirmed_now') . "\n\n"
                . '• ' . $t('mail.booking.field_service') . ': ' . $service['name'] . "\n"
                . '• ' . $t('mail.booking.field_when') . ': ' . $when . "\n\n"
                . $t('mail.booking.cancel_inline', [
                    'url' => base_url('_booking/cancel/' . $booking['id'] . '?token=' . $booking['cancel_token']),
                ]) . "\n\n" . $siteName;
            $msg = new MailMessage((string) $booking['customer_email'], $subject, $body, '', (string) $booking['customer_name']);
            $msg->attach(self::buildIcs($booking, $service, $siteName, $lang), 'reserva.ics', 'text/calendar; method=REQUEST');
        } else {
            $subject = $t('mail.booking.cancelled_subject', $vars);
            $body = $t('mail.greeting', ['name' => $booking['customer_name']]) . "\n\n"
                . $t('mail.booking.cancelled_body', $vars) . "\n\n"
                . $t('mail.booking.book_again') . "\n\n" . $siteName;
            $msg = new MailMessage((string) $booking['customer_email'], $subject, $body, '', (string) $booking['customer_name']);
        }
        self::deliverToCustomer($siteId, (int) $booking['id'], $msg);
    }

    /** Aviso al admin cuando el CLIENTE cancela con su link. */
    public static function notifyCustomerCancelled(int $siteId, int $bookingId): void
    {
        $ctx = self::context($siteId, $bookingId);
        if ($ctx === null) {
            return;
        }
        [$booking, $service, , $tz] = $ctx;
        self::notifyAdmin($siteId, sprintf(
            "El cliente ha cancelado su reserva.\n\nServicio: %s\nFecha: %s\nCliente: %s <%s>\n\nGestión: %s",
            $service['name'],
            self::humanWhen($booking, $tz),
            $booking['customer_name'],
            $booking['customer_email'],
            base_url('admin/booking/reservas')
        ), 'Reserva cancelada por el cliente: ' . $service['name']);
    }

    /**
     * Evento iCalendar mínimo (texto plano, RFC 5545). DTSTART/DTEND en UTC;
     * UID estable por reserva para que reenvíos actualicen el mismo evento.
     */
    public static function buildIcs(array $booking, array $service, string $siteName, string $lang = 'es'): string
    {
        $fmt = static fn (string $utc): string =>
            (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $esc = static fn (string $s): string =>
            str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $s);
        $host = (string) (parse_url(base_url(''), PHP_URL_HOST) ?: 'promptpress');

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//PromptPress//Booking//ES',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:booking-' . $booking['id'] . '@' . $host,
            'DTSTAMP:' . $fmt((string) $booking['updated_at']),
            'DTSTART:' . $fmt((string) $booking['starts_at_utc']),
            'DTEND:' . $fmt((string) $booking['ends_at_utc']),
            'SUMMARY:' . $esc((string) $service['name'] . ' — ' . $siteName),
            // El .ics acaba en el calendario del CLIENTE: va en su idioma.
            'DESCRIPTION:' . $esc(Microcopy::t('mail.booking.ics_description', $lang, [
                'name' => (string) $booking['customer_name'],
            ])),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ]) . "\r\n";
    }

    // ======================================================================
    // Internos
    // ======================================================================

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string}|null */
    private static function context(int $siteId, int $bookingId): ?array
    {
        $booking = Database::selectOne(
            'SELECT * FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $bookingId]
        );
        if ($booking === null) {
            return null;
        }
        $service = Database::selectOne(
            'SELECT id, name, duration_min FROM booking_services WHERE id = ? LIMIT 1',
            [(int) $booking['service_id']]
        );
        if ($service === null) {
            return null;
        }
        $site = Database::selectOne('SELECT name, timezone FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?? [];
        return [
            $booking,
            $service,
            (string) ($site['name'] ?? 'PromptPress'),
            (string) ($site['timezone'] ?? '') !== '' ? (string) $site['timezone'] : 'Europe/Madrid',
        ];
    }

    /** "lunes 6 de julio de 2026, 09:00" en la zona del sitio. */
    /**
     * Fecha larga en el idioma del destinatario.
     *
     * Antes formateaba siempre con `es_ES` y un patrón que llevaba el `'de'`
     * castellano dentro: en un email en francés salía «mercredi 29 de juillet
     * de 2026». Ahora lo resuelve DateFormat, que además respeta el patrón
     * castellano exacto para no cambiar los sitios que ya funcionan.
     */
    private static function humanWhen(array $booking, string $tz, string $lang = 'es'): string
    {
        return DateFormat::humanDateTime(
            new DateTimeImmutable((string) $booking['starts_at_utc'], new DateTimeZone('UTC')),
            $lang,
            $tz
        );
    }

    /**
     * Idioma del email al cliente. Igual que en CommerceMailer: hoy el del
     * sitio; cuando la fase 1 (T1.2) añada `language` a `booking_bookings`,
     * mandará el idioma con el que el cliente reservó.
     *
     * @param array<string,mixed> $booking
     */
    private static function language(int $siteId, array $booking): string
    {
        $stored = trim((string) ($booking['language'] ?? ''));
        return $stored !== '' ? LanguageService::normalize($stored) : LanguageService::codeFor($siteId);
    }

    /** Envía al cliente y refleja el resultado en email_status/email_error. */
    private static function deliverToCustomer(int $siteId, int $bookingId, MailMessage $msg): void
    {
        try {
            if (!MailService::isConfigured($siteId)) {
                self::mark($bookingId, 'skipped', null);
                return;
            }
            $result = MailService::send($siteId, $msg, 'booking');
            self::mark($bookingId, $result->ok ? 'sent' : 'failed', $result->ok ? null : (string) $result->error);
        } catch (\Throwable $e) {
            self::mark($bookingId, 'failed', $e->getMessage());
        }
    }

    private static function notifyAdmin(int $siteId, string $body, string $subject): void
    {
        try {
            if (!MailService::isConfigured($siteId)) {
                return;
            }
            $to = FormSubmissionService::recipientForSite($siteId);
            if ($to === null || $to === '') {
                return;
            }
            MailService::send($siteId, new MailMessage($to, $subject, $body), 'booking');
        } catch (\Throwable) {
            // el aviso al admin nunca rompe el flujo
        }
    }

    private static function mark(int $bookingId, string $status, ?string $error): void
    {
        Database::execute(
            'UPDATE booking_bookings SET email_status = ?, email_error = ? WHERE id = ?',
            [$status, $error !== null ? mb_substr($error, 0, 255) : null, $bookingId]
        );
    }
}
