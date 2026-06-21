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
 */
final class FormTemplates
{
    /**
     * Catálogo completo: key => [label, description, content].
     * El content tiene el mismo shape que `FormStore` espera (heading, fields,
     * lawful_basis, etc.) + `form_type`.
     *
     * @return array<string,array{label:string,description:string,content:array<string,mixed>}>
     */
    public static function catalog(): array
    {
        return [
            'contact' => [
                'label'       => 'Contacto',
                'description' => 'El clásico: nombre, email y mensaje. Para "contáctanos".',
                'content'     => self::wrap('contact', [
                    'heading'         => 'Contacta con nosotros',
                    'success_message' => 'Gracias, te contactaremos pronto.',
                    'submit_text'     => 'Enviar',
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field('Nombre',  'nombre',  'text',     true),
                        self::field('Email',   'email',   'email',    true),
                        self::field('Mensaje', 'mensaje', 'textarea', true),
                    ],
                ]),
            ],
            'newsletter' => [
                'label'       => 'Newsletter',
                'description' => 'Suscripción por email. Marca consentimiento de marketing.',
                'content'     => self::wrap('newsletter', [
                    'heading'          => 'Suscríbete a nuestra newsletter',
                    'success_message'  => '¡Listo! Revisa tu correo para confirmar la suscripción.',
                    'submit_text'      => 'Suscribirme',
                    'lawful_basis'     => 'consent',
                    'marketing_opt_in' => '1',
                    'fields'           => [
                        self::field('Email', 'email', 'email', true),
                    ],
                ]),
            ],
            'quote' => [
                'label'       => 'Presupuesto',
                'description' => 'Solicitud de presupuesto: contacto + qué necesita el cliente.',
                'content'     => self::wrap('quote', [
                    'heading'         => 'Pide tu presupuesto',
                    'success_message' => 'Gracias, prepararemos tu presupuesto y te lo enviaremos pronto.',
                    'submit_text'     => 'Solicitar presupuesto',
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field('Nombre',          'nombre',    'text',     true),
                        self::field('Email',           'email',     'email',    true),
                        self::field('Teléfono',        'telefono',  'tel',      false),
                        self::field('¿Qué necesitas?', 'necesidad', 'textarea', true),
                    ],
                ]),
            ],
            'booking' => [
                'label'       => 'Reserva / cita',
                'description' => 'Para pedir cita o reservar: contacto + fecha preferida.',
                'content'     => self::wrap('booking', [
                    'heading'         => 'Reserva tu cita',
                    'success_message' => 'Hemos recibido tu solicitud de reserva. Te confirmaremos en breve.',
                    'submit_text'     => 'Reservar',
                    'lawful_basis'    => 'legitimate_interest',
                    'fields'          => [
                        self::field('Nombre',          'nombre',    'text', true),
                        self::field('Email',           'email',     'email', true),
                        self::field('Teléfono',        'telefono',  'tel',  true),
                        self::field('Fecha preferida', 'fecha',     'date', true),
                    ],
                ]),
            ],
            'job' => [
                'label'       => 'Empleo',
                'description' => 'Candidaturas: contacto + adjuntar CV.',
                'content'     => self::wrap('job', [
                    'heading'         => 'Trabaja con nosotros',
                    'success_message' => 'Gracias por tu interés. Revisaremos tu candidatura.',
                    'submit_text'     => 'Enviar candidatura',
                    'lawful_basis'    => 'legitimate_interest',
                    'retention_period'=> '12 meses tras el cierre del proceso de selección',
                    'fields'          => [
                        self::field('Nombre',  'nombre',  'text',  true),
                        self::field('Email',   'email',   'email', true),
                        self::field('Teléfono','telefono','tel',   false),
                        array_merge(self::field('CV', 'cv', 'file', true), [
                            'file_accept'  => 'documents',
                            'file_max_mb'  => 5,
                        ]),
                        self::field('Mensaje', 'mensaje', 'textarea', false),
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
    public static function content(string $key): array
    {
        $catalog = self::catalog();
        $key = isset($catalog[$key]) ? $key : 'contact';
        return $catalog[$key]['content'];
    }

    /** Etiqueta humana de un tipo (o el propio tipo si no está en catálogo). */
    public static function label(string $key): string
    {
        return self::catalog()[$key]['label'] ?? $key;
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
    private static function wrap(string $type, array $partial): array
    {
        $defaults = [
            'form_type'             => $type,
            'heading'               => 'Formulario',
            'description'           => '',
            'submit_text'           => 'Enviar',
            'success_message'       => 'Gracias, hemos recibido tu mensaje.',
            'fields'                => [],
            'lawful_basis'          => 'legitimate_interest',
            'retention_period'      => '12 meses tras la última comunicación',
            'marketing_opt_in'      => '0',
            'autoresponder_enabled' => '0',
            'autoresponder_subject' => 'Hemos recibido tu mensaje',
            'autoresponder_body'    => "Hola {{nombre}}:\n\nGracias por escribirnos. Hemos recibido tu mensaje y te responderemos lo antes posible.\n\nUn saludo,\n{{sitio}}",
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
