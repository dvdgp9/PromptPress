<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * PromptPress — Fechas legibles en el idioma del destinatario.
 *
 * Nace de un problema concreto de los emails de reserva: `BookingMailer`
 * formateaba con `IntlDateFormatter('es_ES')` y un patrón que llevaba el `'de'`
 * castellano incrustado (`EEEE d 'de' MMMM 'de' y, HH:mm`). En un email en
 * francés eso producía «mercredi 29 de juillet de 2026».
 *
 * Estrategia:
 *   - Para los idiomas con patrón propio declarado abajo, se usa ese patrón.
 *     El castellano lo conserva EXACTO para que los sitios que ya funcionan no
 *     vean ni un carácter distinto.
 *   - Para el resto se deja el formato largo de ICU, que es correcto por
 *     construcción. Importa sobre todo en catalán y gallego, que apostrofan
 *     ante vocal («29 d'abril», no «29 de abril»): un patrón escrito a mano
 *     con `'de'` fijo estaría mal la mitad de los meses.
 *   - Sin la extensión `intl`, degrada a `d/m/Y H:i` como hacía antes.
 */
final class DateFormat
{
    /**
     * Patrones ICU por idioma. Ausente = formato largo por defecto de ICU.
     *
     * @var array<string,string>
     */
    private const PATTERNS = [
        'es' => "EEEE d 'de' MMMM 'de' y, HH:mm",
        'fr' => 'EEEE d MMMM y, HH:mm',
        'pt' => "EEEE, d 'de' MMMM 'de' y, HH:mm",
        'en' => 'EEEE, MMMM d, y, HH:mm',
    ];

    /** Fecha y hora largas, ya en la zona horaria del sitio. */
    public static function humanDateTime(DateTimeImmutable $moment, string $lang, string $tz): string
    {
        $lang = LanguageService::normalize($lang);
        $local = $moment->setTimezone(new DateTimeZone($tz));

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                self::icuLocale($lang),
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::SHORT,
                $tz
            );
            if (isset(self::PATTERNS[$lang])) {
                $formatter->setPattern(self::PATTERNS[$lang]);
            }
            $out = $formatter->format($local);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return $local->format('d/m/Y H:i');
    }

    /** Solo la fecha, sin hora (para asuntos y resúmenes cortos). */
    public static function humanDate(DateTimeImmutable $moment, string $lang, string $tz): string
    {
        $lang = LanguageService::normalize($lang);
        $local = $moment->setTimezone(new DateTimeZone($tz));

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                self::icuLocale($lang),
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                $tz
            );
            $out = $formatter->format($local);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return $local->format('d/m/Y');
    }

    /** Código de idioma → locale ICU con región razonable. */
    private static function icuLocale(string $lang): string
    {
        return match (LanguageService::normalize($lang)) {
            'es' => 'es_ES',
            'en' => 'en_GB',
            'ca' => 'ca_ES',
            'gl' => 'gl_ES',
            'eu' => 'eu_ES',
            'fr' => 'fr_FR',
            'pt' => 'pt_PT',
            default => 'es_ES',
        };
    }
}
