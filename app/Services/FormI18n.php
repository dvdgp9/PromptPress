<?php

declare(strict_types=1);

namespace App\Services;

/**
 * FORMS-LANG T6 — Un formulario, sus textos en varios idiomas.
 *
 * Decisión de la fase de plan (opción B): NO se duplica el formulario por
 * idioma. Un formulario sigue siendo UNA entidad —una bandeja de entrada, una
 * configuración RGPD, un `{{form:12}}` que las páginas traducidas copian tal
 * cual— y lo que se traduce son sus textos, guardados dentro de su propio JSON:
 *
 *   {
 *     "language": "fr",                 // idioma BASE (el de heading/fields)
 *     "heading": "Contactez-nous",
 *     "fields": [{"name": "nombre", "label": "Nom", ...}],
 *     "i18n": {
 *       "es": {
 *         "heading": "Contacta con nosotros",
 *         "fields": {"nombre": {"label": "Nombre", "placeholder": ""}}
 *       }
 *     }
 *   }
 *
 * Los campos se indexan por su `name`, nunca por posición: si alguien añade o
 * reordena campos, la traducción que exista sigue casando y la que falte cae al
 * texto base. El `name` en sí NO se traduce jamás: es la clave con la que se
 * guardan las respuestas y la variable del autorespondedor.
 */
final class FormI18n
{
    /**
     * Claves de texto del formulario que se traducen. La autorrespuesta entra
     * porque es un correo AL VISITANTE: si escribió en francés, la confirmación
     * en castellano se lee como un error del sitio.
     */
    public const TEXT_KEYS = [
        'heading', 'description', 'submit_text', 'success_message',
        'autoresponder_subject', 'autoresponder_body',
    ];

    /**
     * Variables que el autorespondedor sustituye al enviar. Una traducción que
     * se las coma dejaría el correo con el saludo a medias, así que se descarta.
     */
    private const REQUIRED_TOKENS = ['{{nombre}}', '{{sitio}}'];

    /** Claves de texto de cada campo que se traducen (`name` NUNCA). */
    public const FIELD_KEYS = ['label', 'placeholder'];

    /**
     * Idioma base de un formulario. Los creados antes de FORMS-LANG no lo
     * guardan: eran castellano fijo del catálogo.
     *
     * @param array<string,mixed> $content
     */
    public static function baseLanguage(array $content): string
    {
        $lang = trim((string) ($content['language'] ?? ''));
        return $lang !== '' ? LanguageService::normalize($lang) : LanguageService::DEFAULT;
    }

    /**
     * Idiomas para los que hay traducción guardada.
     *
     * @param array<string,mixed> $content
     * @return array<int,string>
     */
    public static function translatedLanguages(array $content): array
    {
        $i18n = is_array($content['i18n'] ?? null) ? $content['i18n'] : [];
        return array_values(array_filter(
            array_map(static fn($k): string => LanguageService::normalize((string) $k), array_keys($i18n)),
            static fn(string $code): bool => is_array($i18n[$code] ?? null) && $i18n[$code] !== []
        ));
    }

    /**
     * Content listo para pintar en un idioma.
     *
     * Si el idioma pedido es el base, o no hay traducción para él, se devuelve
     * el content tal cual: media web traducida es peor que una coherente, pero
     * un hueco vacío es peor que un texto en otro idioma.
     *
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public static function resolve(array $content, ?string $lang): array
    {
        if ($lang === null) {
            return $content;
        }
        $lang = LanguageService::normalize($lang);
        if ($lang === self::baseLanguage($content)) {
            return $content;
        }

        $i18n = is_array($content['i18n'] ?? null) ? $content['i18n'] : [];
        $tr = is_array($i18n[$lang] ?? null) ? $i18n[$lang] : [];
        if ($tr === []) {
            return $content;
        }

        foreach (self::TEXT_KEYS as $key) {
            $value = trim((string) ($tr[$key] ?? ''));
            if ($value !== '') {
                $content[$key] = $value;
            }
        }

        $fieldTr = is_array($tr['fields'] ?? null) ? $tr['fields'] : [];
        if ($fieldTr !== [] && is_array($content['fields'] ?? null)) {
            foreach ($content['fields'] as $idx => $field) {
                if (!is_array($field)) continue;
                $name = (string) ($field['name'] ?? '');
                $entry = is_array($fieldTr[$name] ?? null) ? $fieldTr[$name] : [];
                foreach (self::FIELD_KEYS as $key) {
                    $value = trim((string) ($entry[$key] ?? ''));
                    if ($value !== '') {
                        $content['fields'][$idx][$key] = $value;
                    }
                }
                // Las opciones de un select se traducen por posición: no tienen
                // clave estable y su valor ES el texto que ve el visitante.
                $options = is_array($entry['options'] ?? null) ? array_values($entry['options']) : [];
                if ($options !== [] && is_array($field['options'] ?? null)
                    && count($options) === count($field['options'])) {
                    $content['fields'][$idx]['options'] = array_map('strval', $options);
                }
            }
        }

        return $content;
    }

    /**
     * Textos traducibles de un formulario, en la forma que se le manda a la IA
     * y que se recibe de vuelta.
     *
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public static function extractTexts(array $content): array
    {
        $out = [];
        foreach (self::TEXT_KEYS as $key) {
            $value = trim((string) ($content[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') continue;
            $entry = [];
            foreach (self::FIELD_KEYS as $key) {
                $value = trim((string) ($field[$key] ?? ''));
                if ($value !== '') {
                    $entry[$key] = $value;
                }
            }
            $options = is_array($field['options'] ?? null)
                ? array_values(array_filter(array_map('strval', $field['options'])))
                : [];
            if ($options !== []) {
                $entry['options'] = $options;
            }
            if ($entry !== []) {
                $out['fields'][$name] = $entry;
            }
        }

        return $out;
    }

    /**
     * Deja en el content la traducción a un idioma, saneada: solo claves
     * conocidas, solo campos que existen y solo texto.
     *
     * @param array<string,mixed> $content
     * @param array<string,mixed> $texts
     * @return array<string,mixed> content actualizado
     */
    public static function withTranslation(array $content, string $lang, array $texts): array
    {
        $lang = LanguageService::normalize($lang);
        $clean = self::sanitizeTexts($content, $texts);
        if ($clean === []) {
            return $content;
        }

        $i18n = is_array($content['i18n'] ?? null) ? $content['i18n'] : [];
        $i18n[$lang] = $clean;
        $content['i18n'] = $i18n;
        return $content;
    }

    /**
     * Reemplaza los textos BASE del formulario (no una traducción): es lo que
     * hace falta cuando un sitio de un solo idioma tiene el formulario en el
     * idioma equivocado, que es el caso que originó todo esto.
     *
     * @param array<string,mixed> $content
     * @param array<string,mixed> $texts
     * @return array<string,mixed>
     */
    public static function withBaseTexts(array $content, string $lang, array $texts): array
    {
        $clean = self::sanitizeTexts($content, $texts);
        $lang = LanguageService::normalize($lang);

        foreach (self::TEXT_KEYS as $key) {
            if (isset($clean[$key])) {
                $content[$key] = $clean[$key];
            }
        }
        $fieldTexts = is_array($clean['fields'] ?? null) ? $clean['fields'] : [];
        if (is_array($content['fields'] ?? null)) {
            foreach ($content['fields'] as $idx => $field) {
                if (!is_array($field)) continue;
                $entry = $fieldTexts[(string) ($field['name'] ?? '')] ?? null;
                if (!is_array($entry)) continue;
                foreach (self::FIELD_KEYS as $key) {
                    if (isset($entry[$key])) {
                        $content['fields'][$idx][$key] = $entry[$key];
                    }
                }
                if (isset($entry['options']) && is_array($field['options'] ?? null)
                    && count($entry['options']) === count($field['options'])) {
                    $content['fields'][$idx]['options'] = array_map('strval', $entry['options']);
                }
            }
        }

        $content['language'] = $lang;
        // La traducción a este idioma, si la había, sobra: ahora es la base.
        if (is_array($content['i18n'] ?? null)) {
            unset($content['i18n'][$lang]);
            if ($content['i18n'] === []) unset($content['i18n']);
        }
        return $content;
    }

    /**
     * ¿La traducción conserva las variables que tenía el original? Si el texto
     * base decía `{{nombre}}` y la traducción no, no vale: preferimos el
     * original en otro idioma a un correo con un hueco donde va el saludo.
     */
    private static function keepsTokens(string $original, string $translated): bool
    {
        foreach (self::REQUIRED_TOKENS as $token) {
            if (str_contains($original, $token) && !str_contains($translated, $token)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Filtra lo que devuelve la IA: solo claves conocidas, solo campos que
     * existen de verdad en el formulario y solo strings.
     *
     * @param array<string,mixed> $content
     * @param array<string,mixed> $texts
     * @return array<string,mixed>
     */
    private static function sanitizeTexts(array $content, array $texts): array
    {
        $out = [];
        foreach (self::TEXT_KEYS as $key) {
            $value = isset($texts[$key]) && is_scalar($texts[$key]) ? trim((string) $texts[$key]) : '';
            if ($value === '') {
                continue;
            }
            if (!self::keepsTokens((string) ($content[$key] ?? ''), $value)) {
                continue;
            }
            $out[$key] = mb_substr($value, 0, 2000);
        }

        $known = [];
        foreach ((is_array($content['fields'] ?? null) ? $content['fields'] : []) as $field) {
            if (is_array($field) && trim((string) ($field['name'] ?? '')) !== '') {
                $known[(string) $field['name']] = is_array($field['options'] ?? null)
                    ? count($field['options'])
                    : 0;
            }
        }

        $fields = is_array($texts['fields'] ?? null) ? $texts['fields'] : [];
        foreach ($fields as $name => $entry) {
            $name = (string) $name;
            if (!isset($known[$name]) || !is_array($entry)) continue;
            $clean = [];
            foreach (self::FIELD_KEYS as $key) {
                $value = isset($entry[$key]) && is_scalar($entry[$key]) ? trim((string) $entry[$key]) : '';
                if ($value !== '') {
                    $clean[$key] = mb_substr($value, 0, 500);
                }
            }
            if (isset($entry['options']) && is_array($entry['options'])
                && count($entry['options']) === $known[$name] && $known[$name] > 0) {
                $clean['options'] = array_map(
                    static fn($o): string => mb_substr(trim((string) $o), 0, 200),
                    array_values($entry['options'])
                );
            }
            if ($clean !== []) {
                $out['fields'][$name] = $clean;
            }
        }

        return $out;
    }
}
