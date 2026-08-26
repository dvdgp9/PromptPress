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
        $confirmed = (string) $booking['status'] === 'confirmed';
        $when = self::humanWhen($booking, $tz, $lang);
        $cancelUrl = base_url('_booking/cancel/' . $booking['id'] . '?token=' . $booking['cancel_token']);

        // MODULOS M9 — el texto sale de la plantilla del servicio si el gestor la
        // ha reescrito, y si no de la de siempre (traducida en los 6 idiomas).
        $mail = BookingEmails::render(
            $service,
            $confirmed ? 'confirmed' : 'received',
            $lang,
            self::tokens($booking, $service, $siteName, $when, $cancelUrl, $lang)
        );
        $msg = new MailMessage((string) $booking['customer_email'], $mail['subject'], $mail['body'], '', (string) $booking['customer_name']);
        if ($confirmed) {
            $msg->attach(self::buildIcs($booking, $service, $siteName, $lang), 'reserva.ics', 'text/calendar; method=REQUEST');
        }
        self::deliverToCustomer($siteId, (int) $booking['id'], $msg);

        // El aviso al admin va entero en el idioma del PANEL (fecha incluida):
        // lo lee quien gestiona el sitio, no el cliente. Media frase en un
        // idioma y la fecha en otro queda peor que cualquiera de los dos.
        $whenAdmin = self::humanWhen($booking, $tz, \App\Services\AdminI18n::locale());
        self::notifyAdmin($siteId, __('bk.mail.admin_new_body', [
            'estado'   => __($confirmed ? 'bk.mail.admin_auto_confirmed' : 'bk.mail.admin_pending'),
            'servicio' => (string) $service['name'],
            'fecha'    => $whenAdmin,
            'cliente'  => (string) $booking['customer_name'],
            'email'    => (string) $booking['customer_email'],
            'telefono' => $booking['customer_phone'] !== null ? "\n" . __('bk.mail.phone') . ': ' . $booking['customer_phone'] : '',
            'notas'    => $booking['notes'] !== null ? "\n" . __('bk.mail.notes') . ': ' . $booking['notes'] : '',
            // MODULOS M8 — lo que el cliente ha contestado en los campos propios
            // del servicio: si el gestor los pidió, es que los necesita, así que
            // van en el mismo aviso y no solo en el panel.
            'extra'    => self::answersBlock($booking),
            'url'      => base_url('admin/booking/reservas'),
        ]), __('bk.mail.admin_new_subject', ['servicio' => (string) $service['name'], 'fecha' => $whenAdmin]));
    }

    /**
     * Email al cliente cuando el admin confirma o cancela.
     *
     * Devuelve qué pasó con el envío ('sent', 'skipped' si el sitio no tiene
     * email configurado, 'failed' si el envío falló) para que el panel pueda
     * decir la verdad en el aviso: sin esto avisaba "hemos escrito al cliente"
     * incluso en un sitio sin SMTP, donde nadie recibió nada.
     */
    public static function sendStatusChange(int $siteId, int $bookingId, string $newStatus): string
    {
        $ctx = self::context($siteId, $bookingId);
        if ($ctx === null) {
            return 'skipped';
        }
        [$booking, $service, $siteName, $tz] = $ctx;
        $lang = self::language($siteId, $booking);
        $when = self::humanWhen($booking, $tz, $lang);

        $cancelUrl = base_url('_booking/cancel/' . $booking['id'] . '?token=' . $booking['cancel_token']);
        $mail = BookingEmails::render(
            $service,
            $newStatus === 'confirmed' ? 'confirmed' : 'cancelled',
            $lang,
            self::tokens($booking, $service, $siteName, $when, $cancelUrl, $lang)
        );
        $msg = new MailMessage((string) $booking['customer_email'], $mail['subject'], $mail['body'], '', (string) $booking['customer_name']);
        if ($newStatus === 'confirmed') {
            $msg->attach(self::buildIcs($booking, $service, $siteName, $lang), 'reserva.ics', 'text/calendar; method=REQUEST');
        }
        return self::deliverToCustomer($siteId, (int) $booking['id'], $msg);
    }

    /** Aviso al admin cuando el CLIENTE cancela con su link. */
    public static function notifyCustomerCancelled(int $siteId, int $bookingId): void
    {
        $ctx = self::context($siteId, $bookingId);
        if ($ctx === null) {
            return;
        }
        [$booking, $service, , $tz] = $ctx;
        self::notifyAdmin($siteId, __('bk.mail.admin_cancel_body', [
            'servicio' => (string) $service['name'],
            'fecha'    => self::humanWhen($booking, $tz, \App\Services\AdminI18n::locale()),
            'cliente'  => (string) $booking['customer_name'],
            'email'    => (string) $booking['customer_email'],
            'url'      => base_url('admin/booking/reservas'),
        ]), __('bk.mail.admin_cancel_subject', ['servicio' => (string) $service['name']]));
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
    /** @return string el email_status resultante: 'sent', 'skipped' o 'failed'. */
    private static function deliverToCustomer(int $siteId, int $bookingId, MailMessage $msg): string
    {
        try {
            if (!MailService::isConfigured($siteId)) {
                self::mark($bookingId, 'skipped', null);
                return 'skipped';
            }
            $result = MailService::send($siteId, $msg, 'booking');
            $status = $result->ok ? 'sent' : 'failed';
            self::mark($bookingId, $status, $result->ok ? null : (string) $result->error);
            return $status;
        } catch (\Throwable $e) {
            self::mark($bookingId, 'failed', $e->getMessage());
            return 'failed';
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

    /**
     * Valores de los `{tokens}` de las plantillas de email.
     *
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $service
     * @return array<string,string>
     */
    private static function tokens(array $booking, array $service, string $siteName, string $when, string $cancelUrl, string $lang): array
    {
        $t = static fn (string $key): string => Microcopy::t($key, $lang);
        $detalles = '• ' . $t('mail.booking.field_service') . ': ' . (string) $service['name'] . "\n"
                  . '• ' . $t('mail.booking.field_when') . ': ' . $when;
        $precio = trim((string) ($service['price_label'] ?? ''));

        return [
            'cliente'    => (string) $booking['customer_name'],
            'servicio'   => (string) $service['name'],
            'fecha'      => $when,
            'precio'     => $precio,
            'sitio'      => $siteName,
            'detalles'   => $detalles,
            'cancelar'   => $cancelUrl,
            'respuestas' => ltrim(self::answersBlock($booking), "\n"),
        ];
    }

    /** Respuestas a los campos propios, como líneas "Etiqueta: valor". */
    private static function answersBlock(array $booking): string
    {
        $lines = '';
        foreach (BookingFields::answers($booking) as $ans) {
            $lines .= "\n" . $ans['label'] . ': ' . $ans['value'];
        }
        return $lines;
    }

    private static function mark(int $bookingId, string $status, ?string $error): void
    {
        Database::execute(
            'UPDATE booking_bookings SET email_status = ?, email_error = ? WHERE id = ?',
            [$status, $error !== null ? mb_substr($error, 0, 255) : null, $bookingId]
        );
    }
}
