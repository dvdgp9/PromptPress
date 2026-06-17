<?php

namespace App\Services;

/**
 * Schemas declarativos para cada tipo de sección.
 *
 * Tipos de field soportados por el renderer JS:
 *   - text       input[type=text]
 *   - textarea   textarea (N rows)
 *   - url        input[type=url]
 *   - select     <select> con {value: label}
 *   - image      input[type=url] + preview (upload real en T8.1)
 *   - number     input[type=number]
 *   - repeater   lista dinámica de sub-items con add/remove
 *
 * Cada field puede tener:
 *   key          clave en el JSON (obligatorio)
 *   label        etiqueta visible
 *   type         uno de los anteriores
 *   placeholder  placeholder del input
 *   help         texto de ayuda (small)
 *   options      para 'select' → [value => label]
 *   rows         para 'textarea'
 *   min          para 'number'
 *   fields       para 'repeater' → array de fields hijos (mismo formato)
 *   itemLabel    para 'repeater' → nombre singular (ej. "Beneficio")
 *   default      valor por defecto si el content no lo tiene
 *
 * Cada tipo declara además `variants`: subdiseños visuales del mismo tipo.
 * La variante elegida se persiste en `page_sections.style.variant` y la lee
 * `SectionRenderer` para añadir clase `pp-section--{type}--{variant}` y
 * decidir el layout. Si la variante no existe, el renderer hace fallback a
 * `default` para no romper páginas pre-existentes.
 */
final class SectionSchemas
{
    public static function all(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'description' => 'Sección de cabecera con título principal y llamada a la acción.',
                'variants' => [
                    'default'       => 'Centrado',
                    'split'         => 'Dividido (texto + media)',
                    'with-image-bg' => 'Sobre imagen de fondo',
                    'poster-stack'  => 'Poster editorial',
                    'statement-left'=> 'Declaración lateral',
                    'metric-led'    => 'Titular + métricas',
                ],
                'fields' => [
                    ['key' => 'heading',     'label' => 'Título principal', 'type' => 'text',     'placeholder' => 'Diseño Web que Convierte'],
                    ['key' => 'subheading',  'label' => 'Subtítulo',        'type' => 'textarea', 'rows' => 2, 'placeholder' => 'Sitios que impulsan tu negocio'],
                    ['key' => 'eyebrow',     'label' => 'Etiqueta superior (opcional)', 'type' => 'text', 'placeholder' => 'Estudio de diseño', 'help' => 'Texto pequeño sobre el título.'],
                    ['key' => 'cta_text',    'label' => 'Texto del botón',  'type' => 'text',     'placeholder' => 'Solicitar presupuesto'],
                    ['key' => 'cta_url',     'label' => 'Destino del botón',    'type' => 'link',      'placeholder' => '/contacto'],
                    ['key' => 'cta_text_secondary', 'label' => 'Botón secundario (opcional)', 'type' => 'text', 'placeholder' => 'Saber más'],
                    ['key' => 'cta_url_secondary',  'label' => 'Destino botón secundario',         'type' => 'link'],
                    ['key' => 'image_url',   'label' => 'Imagen (variante Dividido)', 'type' => 'image', 'help' => 'Se muestra junto al texto en la variante Dividido.'],
                    ['key' => 'background_image', 'label' => 'Imagen de fondo (variante Sobre imagen)', 'type' => 'image', 'placeholder' => 'https://...'],
                ],
            ],

            'text_image' => [
                'label' => 'Texto + Imagen',
                'description' => 'Bloque con texto a un lado e imagen al otro.',
                'variants' => [
                    'default'    => 'Equilibrado',
                    'wide-media' => 'Imagen ancha',
                    'card'       => 'Tarjeta elevada',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título',          'type' => 'text',     'placeholder' => '¿Qué ofrecemos?'],
                    ['key' => 'body',       'label' => 'Cuerpo del texto', 'type' => 'textarea', 'rows' => 6],
                    ['key' => 'image_url',  'label' => 'Imagen',          'type' => 'image'],
                    ['key' => 'image_side', 'label' => 'Posición imagen', 'type' => 'select', 'options' => ['right' => 'Derecha', 'left' => 'Izquierda'], 'default' => 'right'],
                    ['key' => 'cta_text',   'label' => 'Texto del botón (opcional)', 'type' => 'text'],
                    ['key' => 'cta_url',    'label' => 'Destino del botón',   'type' => 'link'],
                ],
            ],

            'benefits' => [
                'label' => 'Beneficios',
                'description' => 'Grid de ventajas o características con icono + título + descripción.',
                'variants' => [
                    'default'         => 'Grid simple',
                    'cards-icon-top'  => 'Tarjetas con icono destacado',
                    'numbered'        => 'Numerado',
                    'offset-grid'     => 'Grid asimétrico',
                    'manifesto'       => 'Manifiesto sin iconos',
                    'proof-strip'     => 'Franja de prueba',
                ],
                'fields' => [
                    ['key' => 'heading',     'label' => 'Título de la sección', 'type' => 'text', 'placeholder' => '¿Por qué elegirnos?'],
                    ['key' => 'subheading',  'label' => 'Subtítulo',            'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Beneficios',
                        'type' => 'repeater',
                        'itemLabel' => 'Beneficio',
                        'fields' => [
                            ['key' => 'icon',        'label' => 'Icono (nombre)', 'type' => 'text',     'placeholder' => 'rocket', 'help' => 'Nombre de icono (rocket, shield, chart, heart, star...)'],
                            ['key' => 'title',       'label' => 'Título',         'type' => 'text'],
                            ['key' => 'description', 'label' => 'Descripción',    'type' => 'textarea', 'rows' => 3],
                        ],
                    ],
                ],
            ],

            'faq' => [
                'label' => 'FAQ',
                'description' => 'Preguntas frecuentes con respuestas.',
                'variants' => [
                    'default'     => 'Lista clásica',
                    'accordion'   => 'Acordeón minimal',
                    'two-columns' => 'Dos columnas',
                ],
                'fields' => [
                    ['key' => 'heading', 'label' => 'Título', 'type' => 'text', 'placeholder' => 'Preguntas frecuentes'],
                    [
                        'key' => 'items',
                        'label' => 'Preguntas',
                        'type' => 'repeater',
                        'itemLabel' => 'Pregunta',
                        'fields' => [
                            ['key' => 'question', 'label' => 'Pregunta', 'type' => 'text'],
                            ['key' => 'answer',   'label' => 'Respuesta', 'type' => 'textarea', 'rows' => 4],
                        ],
                    ],
                ],
            ],

            'cta' => [
                'label' => 'Llamada a la acción',
                'description' => 'Bloque final con título + descripción + botón.',
                'variants' => [
                    'default' => 'Banner ancho',
                    'card'    => 'Tarjeta centrada',
                    'split'   => 'Texto + botón en línea',
                    'poster-close' => 'Cierre tipo poster',
                    'quiet-inline' => 'Inline editorial',
                ],
                'fields' => [
                    ['key' => 'heading',     'label' => 'Título',          'type' => 'text',     'placeholder' => '¿Listo para empezar?'],
                    ['key' => 'description', 'label' => 'Descripción',     'type' => 'textarea', 'rows' => 3],
                    ['key' => 'cta_text',    'label' => 'Texto del botón', 'type' => 'text',     'placeholder' => 'Contactar'],
                    ['key' => 'cta_url',     'label' => 'Destino del botón',   'type' => 'link',      'placeholder' => '/contacto'],
                ],
            ],

            'testimonials' => [
                'label' => 'Testimonios',
                'description' => 'Citas de clientes con autor y rol.',
                'variants' => [
                    'default'        => 'Grid de tarjetas',
                    'featured-quote' => 'Cita destacada',
                    'quote-wall'     => 'Muro de citas',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título de la sección', 'type' => 'text', 'placeholder' => 'Lo que dicen nuestros clientes'],
                    ['key' => 'subheading', 'label' => 'Subtítulo', 'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Testimonios',
                        'type' => 'repeater',
                        'itemLabel' => 'Testimonio',
                        'fields' => [
                            ['key' => 'quote',      'label' => 'Cita',          'type' => 'textarea', 'rows' => 4, 'placeholder' => 'Texto de la cita…'],
                            ['key' => 'author',     'label' => 'Autor',         'type' => 'text',     'placeholder' => 'Nombre y apellidos'],
                            ['key' => 'role',       'label' => 'Cargo / empresa','type' => 'text',     'placeholder' => 'CEO, Empresa S.L.'],
                            ['key' => 'avatar_url', 'label' => 'Foto (URL)',    'type' => 'image'],
                        ],
                    ],
                ],
            ],

            'stats' => [
                'label' => 'Estadísticas',
                'description' => 'Números destacados con etiquetas.',
                'variants' => [
                    'default'    => 'Grid grande',
                    'inline-bar' => 'Fila con divisores',
                    'scoreboard' => 'Marcador editorial',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título (opcional)', 'type' => 'text'],
                    ['key' => 'subheading', 'label' => 'Subtítulo',         'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Datos',
                        'type' => 'repeater',
                        'itemLabel' => 'Dato',
                        'fields' => [
                            ['key' => 'value',  'label' => 'Valor',  'type' => 'text', 'placeholder' => '98', 'help' => 'Solo el número (sin %, +, etc.)'],
                            ['key' => 'suffix', 'label' => 'Sufijo', 'type' => 'text', 'placeholder' => '%',  'help' => 'Ej. %, +, K, M…'],
                            ['key' => 'label',  'label' => 'Etiqueta', 'type' => 'text', 'placeholder' => 'Satisfacción'],
                        ],
                    ],
                ],
            ],

            'gallery' => [
                'label' => 'Galería',
                'description' => 'Conjunto de imágenes con pies opcionales.',
                'variants' => [
                    'default' => 'Grid uniforme',
                    'mosaic'  => 'Mosaico asimétrico',
                    'editorial-strip' => 'Tira editorial',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título (opcional)', 'type' => 'text'],
                    ['key' => 'subheading', 'label' => 'Subtítulo',         'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Imágenes',
                        'type' => 'repeater',
                        'itemLabel' => 'Imagen',
                        'fields' => [
                            ['key' => 'image_url', 'label' => 'Imagen', 'type' => 'image'],
                            ['key' => 'caption',   'label' => 'Pie (opcional)', 'type' => 'text'],
                            ['key' => 'alt',       'label' => 'Texto alternativo', 'type' => 'text', 'help' => 'Para accesibilidad y SEO.'],
                        ],
                    ],
                ],
            ],

            'steps' => [
                'label' => 'Pasos / Proceso',
                'description' => 'Pasos numerados de un proceso.',
                'variants' => [
                    'default'    => 'Vertical numerado',
                    'horizontal' => 'Línea horizontal',
                    'staggered-cards' => 'Tarjetas escalonadas',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título de la sección', 'type' => 'text', 'placeholder' => 'Cómo trabajamos'],
                    ['key' => 'subheading', 'label' => 'Subtítulo', 'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Pasos',
                        'type' => 'repeater',
                        'itemLabel' => 'Paso',
                        'fields' => [
                            ['key' => 'title',       'label' => 'Título del paso', 'type' => 'text', 'placeholder' => 'Reunión inicial'],
                            ['key' => 'description', 'label' => 'Descripción',     'type' => 'textarea', 'rows' => 3],
                        ],
                    ],
                ],
            ],

            'logos_strip' => [
                'label' => 'Banda de logos',
                'description' => 'Logos de clientes, partners o medios.',
                'variants' => [
                    'default' => 'Grid centrado',
                    'marquee' => 'Carrusel infinito',
                ],
                'fields' => [
                    ['key' => 'heading', 'label' => 'Título (opcional)', 'type' => 'text', 'placeholder' => 'Confían en nosotros'],
                    [
                        'key' => 'items',
                        'label' => 'Logos',
                        'type' => 'repeater',
                        'itemLabel' => 'Logo',
                        'fields' => [
                            ['key' => 'logo_url', 'label' => 'Imagen del logo', 'type' => 'image'],
                            ['key' => 'name',     'label' => 'Nombre',          'type' => 'text', 'help' => 'Para alt y accesibilidad.'],
                            ['key' => 'link_url', 'label' => 'Enlace (opcional)', 'type' => 'link'],
                        ],
                    ],
                ],
            ],

            'pricing' => [
                'label' => 'Planes / Precios',
                'description' => 'Tarjetas de planes con precio y características.',
                'variants' => [
                    'default'    => 'Tarjetas',
                    'comparison' => 'Comparativa compacta',
                    'editorial-list' => 'Lista editorial',
                    'split-value'    => 'Valor destacado',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título', 'type' => 'text', 'placeholder' => 'Planes y precios'],
                    ['key' => 'subheading', 'label' => 'Subtítulo', 'type' => 'textarea', 'rows' => 2],
                    [
                        'key' => 'items',
                        'label' => 'Planes',
                        'type' => 'repeater',
                        'itemLabel' => 'Plan',
                        'fields' => [
                            ['key' => 'plan_name',   'label' => 'Nombre del plan', 'type' => 'text', 'placeholder' => 'Pro'],
                            ['key' => 'price',       'label' => 'Precio',          'type' => 'text', 'placeholder' => '49€'],
                            ['key' => 'period',      'label' => 'Periodo',         'type' => 'text', 'placeholder' => '/mes'],
                            ['key' => 'description', 'label' => 'Descripción breve', 'type' => 'textarea', 'rows' => 2],
                            ['key' => 'features',    'label' => 'Características', 'type' => 'textarea', 'rows' => 5, 'help' => 'Una por línea.'],
                            ['key' => 'cta_text',    'label' => 'Texto del botón', 'type' => 'text', 'placeholder' => 'Empezar'],
                            ['key' => 'cta_url',     'label' => 'Destino del botón',   'type' => 'link'],
                            ['key' => 'highlighted', 'label' => 'Destacar este plan', 'type' => 'select', 'options' => ['0' => 'No', '1' => 'Sí'], 'default' => '0'],
                        ],
                    ],
                ],
            ],

            'custom_block' => [
                'label' => 'Bloque PP-friendly',
                'description' => 'Bloque avanzado en PromptPress-friendly HTML. Se edita como JSON hasta que exista el editor visual específico.',
                'editor' => 'json',
                'variants' => [
                    'default' => 'Por defecto',
                ],
                'fields' => [],
            ],

            'form' => [
                'label' => 'Formulario',
                'description' => 'Formulario de contacto con campos configurables.',
                'variants' => [
                    'default'         => 'Estándar',
                    'inline-card'     => 'Tarjeta elevada',
                    'with-side-image' => 'Con imagen al lado',
                ],
                'fields' => [
                    ['key' => 'heading',      'label' => 'Título',         'type' => 'text',     'placeholder' => 'Contacta con nosotros'],
                    ['key' => 'description',  'label' => 'Descripción',    'type' => 'textarea', 'rows' => 2],
                    ['key' => 'submit_text',  'label' => 'Texto del botón', 'type' => 'text',     'placeholder' => 'Enviar', 'default' => 'Enviar'],
                    ['key' => 'success_message', 'label' => 'Mensaje de éxito', 'type' => 'text', 'placeholder' => 'Gracias, te contactaremos pronto.'],
                    ['key' => 'image_url',    'label' => 'Imagen lateral (variante "Con imagen")', 'type' => 'image', 'help' => 'Solo se muestra en la variante "Con imagen al lado".'],
                    [
                        'key' => 'fields',
                        'label' => 'Campos del formulario',
                        'type' => 'repeater',
                        'itemLabel' => 'Campo',
                        'fields' => [
                            ['key' => 'label',       'label' => 'Etiqueta',    'type' => 'text'],
                            ['key' => 'name',        'label' => 'Nombre (id)', 'type' => 'text', 'help' => 'Solo letras, números, guión bajo. Ej: email, nombre'],
                            ['key' => 'field_type',  'label' => 'Tipo',        'type' => 'select', 'options' => ['text' => 'Texto', 'email' => 'Email', 'tel' => 'Teléfono', 'textarea' => 'Área de texto', 'select' => 'Selector', 'checkbox' => 'Casilla', 'number' => 'Número', 'date' => 'Fecha', 'url' => 'URL', 'file' => 'Archivo'], 'default' => 'text'],
                            ['key' => 'required',    'label' => 'Obligatorio', 'type' => 'select', 'options' => ['0' => 'No', '1' => 'Sí'], 'default' => '0'],
                            ['key' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text'],
                        ],
                    ],
                    // E-GDPR G5 — Metadatos legales del formulario.
                    // Generan automáticamente la nota de privacidad pública debajo del form.
                    ['key' => 'lawful_basis',    'label' => 'Base legal del tratamiento', 'type' => 'select',
                        'options' => [
                            'legitimate_interest' => 'Interés legítimo (atender tu consulta)',
                            'consent'             => 'Consentimiento explícito',
                            'contract'            => 'Ejecución de un contrato o precontrato',
                        ],
                        'default' => 'legitimate_interest',
                        'help'    => 'Casi siempre "Interés legítimo" para forms de contacto. Si pides datos sensibles o marketing, usa "Consentimiento".',
                    ],
                    ['key' => 'retention_period', 'label' => 'Plazo de conservación', 'type' => 'text',
                        'placeholder' => '12 meses tras la última comunicación',
                        'default'     => '12 meses tras la última comunicación',
                        'help'        => 'Cuánto tiempo conservas los datos antes de borrarlos.',
                    ],
                    ['key' => 'marketing_opt_in', 'label' => 'Pedir consentimiento de marketing por separado', 'type' => 'select',
                        'options' => ['0' => 'No', '1' => 'Sí'],
                        'default' => '0',
                        'help'    => 'Si quieres usar el email para newsletter u ofertas, activa esto: aparecerá una casilla aparte (no premarcada).',
                    ],
                ],
            ],

            // F21.T21.3 — Listado de entradas (índice de blog).
            // El contenido es dinámico: el renderer consulta `pages` y `post_meta`
            // para listar las últimas N entradas publicadas. Los fields aquí solo
            // permiten al usuario configurar título de la sección, subtítulo
            // opcional y cuántas mostrar.
            'posts_listing' => [
                'label' => 'Listado de entradas',
                'description' => 'Muestra las últimas entradas publicadas. Ideal para una página /blog o sección "Últimas novedades" en la home.',
                'variants' => [
                    'default'        => 'Grid de tarjetas',
                    'editorial-list' => 'Lista editorial',
                    'featured-first' => 'Destacada + resto',
                ],
                'fields' => [
                    ['key' => 'heading',    'label' => 'Título de la sección', 'type' => 'text', 'placeholder' => 'Últimas entradas'],
                    ['key' => 'subheading', 'label' => 'Subtítulo (opcional)', 'type' => 'textarea', 'rows' => 2, 'placeholder' => 'Lo que estamos publicando últimamente.'],
                    ['key' => 'limit',      'label' => 'Cuántas mostrar', 'type' => 'select', 'default' => '6',
                        'options' => ['3' => '3', '4' => '4', '6' => '6', '8' => '8', '12' => '12']],
                ],
            ],

            // F21.T21.2 — Cuerpo de artículo. Tipo especial: el contenido NO se edita
            // con el editor genérico de secciones (pp-sections-editor), sino con un
            // editor dedicado en /admin/posts/{id}/edit que entiende bloques editoriales
            // (párrafo, H2/H3, imagen, lista, cita, divisor). El schema declara solo
            // metadatos del tipo (variants), no campos visibles en el editor general.
            //
            // Content shape:
            //   {
            //     "blocks": [
            //       { "type": "paragraph",  "text": "..." },
            //       { "type": "heading",    "level": 2, "text": "..." },
            //       { "type": "image",      "src": "/storage/...", "alt": "...", "caption": "..." },
            //       { "type": "list",       "style": "unordered", "items": ["...", "..."] },
            //       { "type": "quote",      "text": "...", "attribution": "..." },
            //       { "type": "divider" }
            //     ]
            //   }
            'article_body' => [
                'label' => 'Cuerpo del artículo',
                'description' => 'Contenido editorial del artículo. Se edita en el editor dedicado de entradas, no aquí.',
                'editor' => 'article',
                'variants' => [
                    'default' => 'Editorial (cómodo)',
                    'narrow'  => 'Estrecho (más íntimo)',
                    'wide'    => 'Amplio (revista)',
                ],
                'fields' => [], // el editor genérico no debe pintar campos aquí
            ],
        ];
    }

    public static function forType(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * Devuelve el mapa de variantes [slug => label] de un tipo.
     * Si el tipo no existe o no declara variantes, devuelve solo `default`.
     */
    public static function variantsFor(string $type): array
    {
        $schema = self::forType($type);
        if (!$schema || empty($schema['variants']) || !is_array($schema['variants'])) {
            return ['default' => 'Por defecto'];
        }
        return $schema['variants'];
    }

    /**
     * Comprueba si una variante es válida para el tipo dado.
     */
    public static function isValidVariant(string $type, string $variant): bool
    {
        if ($variant === '' || $variant === 'default') return true;
        return array_key_exists($variant, self::variantsFor($type));
    }

    /**
     * Normaliza una variante: la devuelve si es válida, si no `default`.
     */
    public static function normalizeVariant(string $type, ?string $variant): string
    {
        $v = is_string($variant) ? trim($variant) : '';
        if ($v === '' || !self::isValidVariant($type, $v)) return 'default';
        return $v;
    }
}
