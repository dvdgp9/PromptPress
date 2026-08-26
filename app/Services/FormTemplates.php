<?php

declare(strict_types=1);

namespace App\Services;

/**
 * FORMS-R T1 — Catálogo de plantillas de formulario tipadas.
 *
 * Cada plantilla es un schema vetado (campos + defaults RGPD) con un
 * `form_type` que identifica su propósito. Lo eligen tanto humanos (editor)
 * como la IA (materialización por intención, `{{form:contact}}`).
 *
 * El `form_type` es la base para: deduplicación (un form por tipo) y, más
 * adelante, el evento de conversión asociado.
 *
 * FORMS-LANG T3 — los textos salen del diccionario `Microcopy` en el idioma
 * que se pida (por defecto, el principal del sitio). Antes estaban en
 * castellano literal aquí, y como el catálogo se COPIA a la base de datos al
 * crear el formulario, una web en francés nacía con formularios en castellano
 * y no había render que lo arreglara después.
 *
 * Lo que NO se traduce, a propósito:
 *   - el `name` de cada campo (`nombre`, `email`…): es la clave con la que se
 *     guardan las respuestas y la variable del autorespondedor (`{{nombre}}`).
 *     Traducirlo rompería las bandejas de entrada ya existentes.
 *   - `label` y `description` del catálogo: son textos del PANEL, y el panel
 *     está en castellano.
 */
final class FormTemplates
{
    /**
     * Catálogo completo: key => [label, description, content].
     * El content tiene el mismo shape que `FormStore` espera (heading, fields,
     * lawful_basis, etc.) + `form_type`.
     *
     * @param string|null $lang Idioma de los textos del `content`. Null =
     *                          castellano (el histórico), para que quien no
     *                          pase idioma se comporte como antes.
     * @return array<string,array{label:string,description:string,content:array<string,mixed>}>
     */
    public static function catalog(?string $lang = null): array
    {
        // OJO: `label` y `description` son CLAVES de traducción del panel, no
        // texto. Tienen dos consumidores: el selector de plantillas (que las
        // quiere traducidas, vía `catalogForView()`) y el prompt de Canvas
        // (`CanvasGenerator`), que necesita el castellano estable de siempre.
        $lang = LanguageService::normalize($lang ?? LanguageService::DEFAULT);
        $t = static fn(string $key): string => Microcopy::t($key, $lang);

        return [
            'contact' => [
                'label'       => 'form_tpl.contact.label',
                'description' => 'form_tpl.contact.desc',
                'content'     => self::wrap('contact', $lang, [
                    'heading'         => $t('form.tpl.contact.heading'),
                    'success_message' => $t('form.tpl.contact.success'),
                    'submit_text'     => $t('form.submit'),
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field($t('form.tpl.field.name'),    'nombre',  'text',     true),
                        self::field($t('form.tpl.field.email'),   'email',   'email',    true),
                        self::field($t('form.tpl.field.message'), 'mensaje', 'textarea', true),
                    ],
                ]),
            ],
            'newsletter' => [
                'label'       => 'form_tpl.newsletter.label',
                'description' => 'form_tpl.newsletter.desc',
                'content'     => self::wrap('newsletter', $lang, [
                    'heading'          => $t('form.tpl.newsletter.heading'),
                    'success_message'  => $t('form.tpl.newsletter.success'),
                    'submit_text'      => $t('form.tpl.newsletter.submit'),
                    'lawful_basis'     => 'consent',
                    'marketing_opt_in' => '1',
                    'fields'           => [
                        self::field($t('form.tpl.field.email'), 'email', 'email', true),
                    ],
                ]),
            ],
            'quote' => [
                'label'       => 'form_tpl.quote.label',
                'description' => 'form_tpl.quote.desc',
                'content'     => self::wrap('quote', $lang, [
                    'heading'         => $t('form.tpl.quote.heading'),
                    'success_message' => $t('form.tpl.quote.success'),
                    'submit_text'     => $t('form.tpl.quote.submit'),
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field($t('form.tpl.field.name'),  'nombre',    'text',     true),
                        self::field($t('form.tpl.field.email'), 'email',     'email',    true),
                        self::field($t('form.tpl.field.phone'), 'telefono',  'tel',      false),
                        self::field($t('form.tpl.field.need'),  'necesidad', 'textarea', true),
                    ],
                ]),
            ],
            'booking' => [
                'label'       => 'form_tpl.booking.label',
                'description' => 'form_tpl.booking.desc',
                'content'     => self::wrap('booking', $lang, [
                    'heading'         => $t('form.tpl.booking.heading'),
                    'success_message' => $t('form.tpl.booking.success'),
                    'submit_text'     => $t('form.tpl.booking.submit'),
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field($t('form.tpl.field.name'),  'nombre',   'text', true),
                        self::field($t('form.tpl.field.email'), 'email',    'email', true),
                        self::field($t('form.tpl.field.phone'), 'telefono', 'tel',  true),
                        self::field($t('form.tpl.field.date'),  'fecha',    'date', true),
                    ],
                ]),
            ],
            'job' => [
                'label'       => 'form_tpl.job.label',
                'description' => 'form_tpl.job.desc',
                'content'     => self::wrap('job', $lang, [
                    'heading'          => $t('form.tpl.job.heading'),
                    'success_message'  => $t('form.tpl.job.success'),
                    'submit_text'      => $t('form.tpl.job.submit'),
                    'lawful_basis'     => 'legitimate_interest',
                    'retention_period' => $t('form.retention_job'),
                    'fields'           => [
                        self::field($t('form.tpl.field.name'),  'nombre',  'text',  true),
                        self::field($t('form.tpl.field.email'), 'email',   'email', true),
                        self::field($t('form.tpl.field.phone'), 'telefono','tel',   false),
                        array_merge(self::field($t('form.tpl.field.cv'), 'cv', 'file', true), [
                            'file_accept'  => 'documents',
                            'file_max_mb'  => 5,
                        ]),
                        self::field($t('form.tpl.field.message'), 'mensaje', 'textarea', false),
                    ],
                ]),
            ],
        ];
    }

    /** Claves válidas del catálogo. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function exists(string $key): bool
    {
        return isset(self::catalog()[$key]);
    }

    /**
     * Devuelve el content listo para persistir de una plantilla. Si la clave no
     * existe, cae a 'contact'.
     *
     * @return array<string,mixed>
     */
    public static function content(string $key, ?string $lang = null): array
    {
        $catalog = self::catalog($lang);
        $key = isset($catalog[$key]) ? $key : 'contact';
        return $catalog[$key]['content'];
    }

    /** Etiqueta humana de un tipo (o el propio tipo si no está en catálogo). */
    /**
     * Catálogo con `label` y `description` ya traducidos al idioma del gestor.
     * Solo para pintar: el prompt de Canvas sigue usando `catalog()`.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function catalogForView(?string $lang = null): array
    {
        $out = [];
        foreach (self::catalog($lang) as $key => $tpl) {
            $tpl['label']       = __($tpl['label']);
            $tpl['description'] = __($tpl['description']);
            $out[$key] = $tpl;
        }
        return $out;
    }

    public static function label(string $key): string
    {
        $key2 = self::catalog()[$key]['label'] ?? null;
        return $key2 !== null ? __($key2) : $key;
    }

    // ----------------------------------------------------------------------
    // Helpers de construcción
    // ----------------------------------------------------------------------

    /**
     * Completa un content parcial con los defaults comunes (RGPD, autorrespuesta)
     * y sella el `form_type`.
     *
     * @param array<string,mixed> $partial
     * @return array<string,mixed>
     */
    private static function wrap(string $type, string $lang, array $partial): array
    {
        $defaults = [
            'form_type'             => $type,
            // FORMS-LANG T6 — idioma BASE de estos textos. Los formularios
            // anteriores a esto no lo llevan: se asume castellano (que es lo
            // que tenían escrito a mano en el catálogo).
            'language'              => $lang,
            'heading'               => Microcopy::t('form.tpl.default_heading', $lang),
            'description'           => '',
            'submit_text'           => Microcopy::t('form.submit', $lang),
            'success_message'       => Microcopy::t('form.success', $lang),
            'fields'                => [],
            'lawful_basis'          => 'legitimate_interest',
            'retention_period'      => Microcopy::t('form.retention_default', $lang),
            'marketing_opt_in'      => '0',
            'autoresponder_enabled' => '0',
            'autoresponder_subject' => Microcopy::t('form.tpl.autoresponder_subject', $lang),
            // `template()` y no `t()`: el cuerpo lleva `{{nombre}}` y `{{sitio}}`,
            // y `t()` limpia los `{token}` sin valor —dejaría el saludo a medias—.
            'autoresponder_body'    => Microcopy::template('form.tpl.autoresponder_body', $lang),
            'notify_email'          => '',
        ];
        return array_merge($defaults, $partial, ['form_type' => $type]);
    }

    /**
     * Atajo para definir un campo.
     *
     * @return array<string,string>
     */
    private static function field(string $label, string $name, string $type, bool $required): array
    {
        return [
            'label'       => $label,
            'name'        => $name,
            'field_type'  => $type,
            'required'    => $required ? '1' : '0',
            'placeholder' => '',
        ];
    }
}
