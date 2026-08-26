<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Services\LanguageService;
use App\Services\Microcopy;

/**
 * BookingEmails — los mensajes que recibe el CLIENTE (MODULOS M9).
 *
 * Tres momentos, tres mensajes:
 *   - `received`  reserva recibida, pendiente de que el gestor la confirme;
 *   - `confirmed` reserva confirmada (lleva el .ics adjunto);
 *   - `cancelled` reserva cancelada.
 *
 * Cada servicio puede reescribir el asunto y el cuerpo de cualquiera de los
 * tres. Lo que no se reescribe usa la plantilla por defecto, que se compone con
 * el catálogo de textos y por tanto sigue traducida a los seis idiomas del
 * widget. En una web multi-idioma cada idioma tiene su propio servicio, así que
 * un texto a medida por servicio es también un texto por idioma.
 *
 * El cuerpo es una plantilla con `{tokens}`. Se reemplazan SIEMPRE los mismos,
 * en cualquier idioma del panel, porque un token que cambiara de nombre según
 * el idioma rompería la plantilla al cambiar de idioma:
 *
 *   {cliente} {servicio} {fecha} {precio} {sitio} {detalles} {cancelar} {respuestas}
 *
 * Nota deliberada: si el gestor quita `{cancelar}` de un mensaje, el cliente se
 * queda sin su enlace para anular. NO se añade por detrás — eso convertiría la
 * plantilla en una caja negra — pero el editor avisa de la consecuencia.
 */
final class BookingEmails
{
    /** Los tres mensajes al cliente, en el orden en que ocurren. */
    public const TYPES = ['received', 'confirmed', 'cancelled'];

    /** Tokens admitidos en el cuerpo (mismos nombres en todos los idiomas). */
    public const TOKENS = ['cliente', 'servicio', 'fecha', 'precio', 'sitio', 'detalles', 'cancelar', 'respuestas'];

    /**
     * Configuración vacía: los tres tipos sin nada reescrito.
     *
     * @return array<string, array{subject:string, body:string}>
     */
    private static function blank(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = ['subject' => '', 'body' => ''];
        }
        return $out;
    }

    /**
     * Configuración guardada del servicio, ya saneada.
     *
     * @param array<string,mixed> $service fila de booking_services
     * @return array<string, array{subject:string, body:string}>
     */
    public static function forService(array $service): array
    {
        $raw = (string) ($service['emails_json'] ?? '');
        if (trim($raw) === '') {
            return self::blank();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::normalize($decoded) : self::blank();
    }

    /**
     * Limpia una configuración venida del panel. Un asunto o un cuerpo en blanco
     * significa "usa el de siempre", así que se guardan como cadena vacía.
     *
     * @param array<string,mixed> $raw
     * @return array<string, array{subject:string, body:string}>
     */
    public static function normalize(array $raw): array
    {
        $out = self::blank();
        foreach (self::TYPES as $type) {
            $in = is_array($raw[$type] ?? null) ? $raw[$type] : [];
            $out[$type] = [
                // Sin HTML: estos emails se envían en texto plano.
                'subject' => mb_substr(trim(strip_tags((string) ($in['subject'] ?? ''))), 0, 180),
                'body'    => mb_substr(trim(strip_tags((string) ($in['body'] ?? ''))), 0, 4000),
            ];
        }
        return $out;
    }

    /** ¿Hay algo reescrito? Sirve para no guardar JSON inútil. */
    public static function isEmpty(array $config): bool
    {
        foreach (self::TYPES as $type) {
            if (trim((string) ($config[$type]['subject'] ?? '')) !== ''
                || trim((string) ($config[$type]['body'] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Plantilla POR DEFECTO de un tipo, en un idioma. Es exactamente el mensaje
     * que se enviaba antes de M9, expresado con tokens: así, al abrir el editor,
     * el gestor ve la estructura real y puede moverla, no una hoja en blanco.
     *
     * @return array{subject:string, body:string}
     */
    public static function defaultTemplate(string $type, string $lang): array
    {
        $lang = LanguageService::normalize($lang);
        // `template()` y no `t()`: `t()` limpia los `{token}` que quedan sin
        // valor y colapsa espacios, y aquí lo que se quiere es justo lo
        // contrario — una plantilla con sus tokens intactos y sus saltos de
        // línea, para que el gestor la vea tal cual y pueda moverla.
        $tpl = static fn (string $key): string => Microcopy::template($key, $lang);
        $swap = static fn (string $text, array $vars): string => str_replace(
            array_map(static fn (string $k): string => '{' . $k . '}', array_keys($vars)),
            array_values($vars),
            $text
        );

        $greeting = $swap($tpl('mail.greeting'), ['name' => '{cliente}']);

        if ($type === 'cancelled') {
            $vars = ['service' => '{servicio}', 'when' => '{fecha}'];
            return [
                'subject' => $swap($tpl('mail.booking.cancelled_subject'), $vars),
                'body'    => $greeting . "\n\n"
                    . $swap($tpl('mail.booking.cancelled_body'), $vars) . "\n\n"
                    . $tpl('mail.booking.book_again') . "\n\n"
                    . '{sitio}',
            ];
        }

        $confirmed = $type === 'confirmed';
        return [
            'subject' => $swap(
                $tpl($confirmed ? 'mail.booking.confirmed_subject' : 'mail.booking.received_subject'),
                ['service' => '{servicio}', 'when' => '{fecha}']
            ),
            'body'    => $greeting . "\n\n"
                . $tpl($confirmed ? 'mail.booking.confirmed_intro' : 'mail.booking.received_intro') . "\n\n"
                . '{detalles}' . "\n\n"
                . $tpl('mail.booking.cancel_intro') . "\n"
                . '{cancelar}' . "\n\n"
                . '{sitio}',
        ];
    }

    /**
     * Mensaje final listo para enviar: la plantilla del servicio si la hay, y si
     * no la de siempre, con los tokens ya sustituidos.
     *
     * @param array<string,mixed> $service  fila de booking_services
     * @param array<string,string> $vars    valores de los tokens
     * @return array{subject:string, body:string}
     */
    public static function render(array $service, string $type, string $lang, array $vars): array
    {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'received';
        }
        $lang = LanguageService::normalize($lang);
        $custom = self::forService($service);
        $default = self::defaultTemplate($type, $lang);

        $subject = trim($custom[$type]['subject'] ?? '') !== ''
            ? $custom[$type]['subject']
            : $default['subject'];
        $body = trim($custom[$type]['body'] ?? '') !== ''
            ? $custom[$type]['body']
            : $default['body'];

        return [
            'subject' => trim(self::replace($subject, $vars)),
            // Una plantilla a medida puede dejar líneas de más al quitar tokens
            // vacíos (un servicio sin precio, una reserva sin respuestas).
            'body'    => self::tidy(self::replace($body, $vars)),
        ];
    }

    /**
     * @param array<string,string> $vars
     */
    private static function replace(string $text, array $vars): string
    {
        foreach (self::TOKENS as $token) {
            $text = str_replace('{' . $token . '}', (string) ($vars[$token] ?? ''), $text);
        }
        return $text;
    }

    /** Colapsa los huecos que deja un token vacío y normaliza saltos. */
    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
