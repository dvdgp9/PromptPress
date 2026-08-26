<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Services\LanguageService;
use App\Services\Microcopy;

/**
 * BookingFields — qué datos se le piden al cliente al reservar (MODULOS M8).
 *
 * El formulario del widget era fijo (nombre, email, teléfono, notas). Aquí vive
 * su definición, por servicio, para que cada negocio pida lo suyo: la matrícula
 * del coche, cuántas personas van, alergias, "acepto las condiciones"…
 *
 * Dos clases de campo:
 *
 *   - BASE (`phone`, `notes`): existían antes y siguen teniendo su columna en
 *     `booking_bookings`. Se pueden ocultar, hacer obligatorios y renombrar,
 *     pero no borrar ni cambiar de tipo.
 *   - PROPIOS: los que añade el gestor. Se guardan juntos en
 *     `booking_bookings.extra_json`, con su etiqueta al lado del valor, para que
 *     una reserva vieja siga siendo legible aunque el campo se borre después.
 *
 * `name` y `email` NO están aquí a propósito: son fijos. Sin email no hay
 * confirmación ni enlace de cancelación, y el nombre es lo que identifica la
 * reserva en el panel.
 *
 * Regla de oro: el widget puede pintar lo que quiera, pero **la validación que
 * cuenta es la de aquí**, en el servidor, contra la definición guardada.
 */
final class BookingFields
{
    /** Tipos que puede tener un campo propio. */
    public const TYPES = ['text', 'textarea', 'tel', 'email', 'number', 'date', 'select', 'checkbox'];

    /** Campos base configurables, en el orden en que se pintan. */
    public const BASE = ['phone', 'notes'];

    /** Tope de campos propios por servicio: un formulario más largo no se rellena. */
    public const MAX_CUSTOM = 12;

    /** Tope de opciones de un desplegable. */
    public const MAX_OPTIONS = 30;

    /**
     * Claves que no puede usar un campo propio: chocarían con el resto del
     * cuerpo JSON de la API o con los campos base.
     */
    private const RESERVED = [
        'name', 'email', 'phone', 'notes', 'start', 'service_id', 'lang',
        'company_url', '_pp_ts', 'id', 'status',
    ];

    /**
     * Configuración por defecto: exactamente el formulario de siempre.
     * Es lo que se usa cuando el servicio no tiene nada guardado.
     *
     * @return array{phone:array{enabled:bool,required:bool,label:string}, notes:array{enabled:bool,required:bool,label:string}, custom:array<int,array<string,mixed>>}
     */
    public static function defaults(): array
    {
        return [
            'phone'  => ['enabled' => true, 'required' => false, 'label' => ''],
            'notes'  => ['enabled' => true, 'required' => false, 'label' => ''],
            'custom' => [],
        ];
    }

    /**
     * Definición efectiva de un servicio (fila de `booking_services`).
     * Un JSON ausente o ilegible devuelve los valores por defecto: una reserva
     * nunca se queda sin formulario por un dato corrupto.
     *
     * @param array<string,mixed> $service
     * @return array<string,mixed>
     */
    public static function forService(array $service): array
    {
        $raw = (string) ($service['fields_json'] ?? '');
        if (trim($raw) === '') {
            return self::defaults();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::normalize($decoded) : self::defaults();
    }

    /**
     * Limpia y acota una definición venida del panel (o de la BD).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        foreach (self::BASE as $key) {
            $in = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $out[$key] = [
                'enabled'  => self::truthy($in['enabled'] ?? true),
                'required' => self::truthy($in['required'] ?? false),
                'label'    => mb_substr(trim(strip_tags((string) ($in['label'] ?? ''))), 0, 60),
            ];
            // Un campo oculto no puede ser obligatorio: sería imposible reservar.
            if (!$out[$key]['enabled']) {
                $out[$key]['required'] = false;
            }
        }

        $custom = [];
        $used = [];
        foreach ((array) ($raw['custom'] ?? []) as $field) {
            if (!is_array($field) || count($custom) >= self::MAX_CUSTOM) {
                continue;
            }
            $label = mb_substr(trim(strip_tags((string) ($field['label'] ?? ''))), 0, 60);
            if ($label === '') {
                continue; // sin etiqueta no hay nada que pedirle al cliente
            }
            $type = (string) ($field['type'] ?? 'text');
            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }

            $key = self::slug((string) ($field['key'] ?? ''), $label, $used);
            $used[$key] = true;

            $clean = [
                'key'         => $key,
                'label'       => $label,
                'type'        => $type,
                'required'    => self::truthy($field['required'] ?? false),
                'placeholder' => mb_substr(trim(strip_tags((string) ($field['placeholder'] ?? ''))), 0, 80),
            ];

            if ($type === 'select') {
                $options = [];
                foreach ((array) ($field['options'] ?? []) as $opt) {
                    $opt = mb_substr(trim(strip_tags((string) $opt)), 0, 80);
                    if ($opt !== '' && !in_array($opt, $options, true) && count($options) < self::MAX_OPTIONS) {
                        $options[] = $opt;
                    }
                }
                // Un desplegable sin opciones no sirve: pasa a texto libre.
                if ($options === []) {
                    $clean['type'] = 'text';
                } else {
                    $clean['options'] = $options;
                }
            }

            $custom[] = $clean;
        }
        $out['custom'] = $custom;

        return $out;
    }

    /**
     * La definición tal y como la necesita el widget: una lista plana y ordenada
     * con la etiqueta ya traducida al idioma en el que se está reservando.
     *
     * @param array<string,mixed> $service fila de booking_services
     * @return array<int, array<string,mixed>>
     */
    public static function forWidget(array $service, string $lang): array
    {
        $lang = LanguageService::normalize($lang);
        $def = self::forService($service);
        $out = [];

        // Los dos fijos van siempre y en primer lugar.
        $out[] = ['key' => 'name',  'label' => Microcopy::t('booking.ph_name', $lang),  'type' => 'text',  'required' => true, 'core' => true];
        $out[] = ['key' => 'email', 'label' => Microcopy::t('booking.ph_email', $lang), 'type' => 'email', 'required' => true, 'core' => true];

        foreach (self::BASE as $key) {
            if (!$def[$key]['enabled']) {
                continue;
            }
            // La etiqueta se compone según esté configurado: un teléfono que el
            // gestor ha hecho obligatorio no puede seguir diciendo "(opcional)".
            $label = $def[$key]['label'] !== ''
                ? $def[$key]['label']
                : Microcopy::t($key === 'phone' ? 'booking.field_phone' : 'booking.field_notes', $lang);
            $label .= $def[$key]['required']
                ? ' *'
                : ' ' . Microcopy::t('booking.optional', $lang);
            $out[] = [
                'key'      => $key,
                'label'    => $label,
                'type'     => $key === 'phone' ? 'tel' : 'textarea',
                'required' => $def[$key]['required'],
                'core'     => true,
            ];
        }

        foreach ($def['custom'] as $field) {
            $out[] = [
                'key'         => $field['key'],
                'label'       => $field['label'],
                'type'        => $field['type'],
                'required'    => $field['required'],
                'placeholder' => $field['placeholder'] ?? '',
                'options'     => $field['options'] ?? [],
                'core'        => false,
            ];
        }

        return $out;
    }

    /**
     * Valida las respuestas del cliente contra la definición del servicio.
     *
     * Devuelve los valores limpios de los campos PROPIOS (etiqueta incluida,
     * para que la reserva se siga leyendo si mañana se borra el campo) y los
     * errores por clave, con el mismo formato que usa el resto de la API.
     *
     * @param array<string,mixed> $service fila de booking_services
     * @param array<string,mixed> $input   cuerpo JSON de la petición
     * @return array{values:array<string,array{label:string,value:string}>, errors:array<string,string>, phone_required:bool, notes_required:bool}
     */
    public static function validate(array $service, array $input, string $lang): array
    {
        $lang = LanguageService::normalize($lang);
        $def = self::forService($service);
        $errors = [];
        $values = [];

        $required = static fn (string $label): string =>
            Microcopy::t('booking.err_field_required', $lang, ['campo' => $label]);

        // Base: solo hay que comprobar el "obligatorio" que haya pedido el gestor.
        foreach (self::BASE as $key) {
            if (!$def[$key]['enabled'] || !$def[$key]['required']) {
                continue;
            }
            if (trim((string) ($input[$key] ?? '')) === '') {
                $label = $def[$key]['label'] !== ''
                    ? $def[$key]['label']
                    : Microcopy::t($key === 'phone' ? 'booking.field_phone' : 'booking.field_notes', $lang);
                $errors[$key] = $required(self::cleanLabel($label));
            }
        }

        foreach ($def['custom'] as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;
            $type = $field['type'];

            if ($type === 'checkbox') {
                $checked = self::truthy($raw);
                if ($field['required'] && !$checked) {
                    $errors[$key] = $required($field['label']);
                    continue;
                }
                $values[$key] = [
                    'label' => $field['label'],
                    'value' => $checked ? Microcopy::t('booking.yes', $lang) : Microcopy::t('booking.no', $lang),
                ];
                continue;
            }

            $value = trim((string) (is_scalar($raw) ? $raw : ''));
            if ($value === '') {
                if ($field['required']) {
                    $errors[$key] = $required($field['label']);
                }
                continue; // vacío y opcional: no se guarda ruido
            }

            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$key] = Microcopy::t('booking.err_field_number', $lang, ['campo' => $field['label']]);
                        continue 2;
                    }
                    break;
                case 'date':
                    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                    if ($d === false || $d->format('Y-m-d') !== $value) {
                        $errors[$key] = Microcopy::t('booking.err_field_date', $lang, ['campo' => $field['label']]);
                        continue 2;
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$key] = Microcopy::t('booking.err_field_email', $lang, ['campo' => $field['label']]);
                        continue 2;
                    }
                    break;
                case 'select':
                    // Nunca se acepta un valor fuera de la lista, venga de donde venga.
                    if (!in_array($value, (array) ($field['options'] ?? []), true)) {
                        $errors[$key] = Microcopy::t('booking.err_field_option', $lang, ['campo' => $field['label']]);
                        continue 2;
                    }
                    break;
            }

            $values[$key] = [
                'label' => $field['label'],
                'value' => mb_substr($value, 0, $type === 'textarea' ? 2000 : 255),
            ];
        }

        return [
            'values' => $values,
            'errors' => $errors,
            'phone_required' => $def['phone']['enabled'] && $def['phone']['required'],
            'notes_required' => $def['notes']['enabled'] && $def['notes']['required'],
        ];
    }

    /**
     * Respuestas guardadas de una reserva, listas para pintar.
     *
     * @param array<string,mixed> $booking fila de booking_bookings
     * @return array<int, array{label:string, value:string}>
     */
    public static function answers(array $booking): array
    {
        $raw = (string) ($booking['extra_json'] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }
        return $out;
    }

    /** Etiqueta sin el asterisco de obligatorio que llevan los placeholders base. */
    private static function cleanLabel(string $label): string
    {
        return trim(rtrim(trim($label), '*'));
    }

    /**
     * Clave estable para un campo propio. Se respeta la que ya tuviera (para no
     * romper las respuestas guardadas al reordenar o renombrar) y, si no hay, se
     * deriva de la etiqueta.
     *
     * @param array<string,bool> $used
     */
    private static function slug(string $current, string $label, array $used): string
    {
        $base = strtolower(trim($current !== '' ? $current : $label));
        // i18n-ignore-start: tabla de transliteración y lista de valores
        // "verdadero", no texto de interfaz.
        $base = strtr($base, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
        ]);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? '';
        $base = trim($base, '_');
        $base = mb_substr($base !== '' ? $base : 'campo', 0, 40);
        if (in_array($base, self::RESERVED, true)) {
            $base = 'c_' . $base;
        }
        $key = $base;
        $i = 2;
        while (isset($used[$key])) {
            $key = mb_substr($base, 0, 36) . '_' . $i;
            $i++;
        }
        return $key;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
        // i18n-ignore-end
    }
}
