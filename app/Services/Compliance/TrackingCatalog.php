<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * E-GDPR G4 — Catálogo estático de servicios de tracking soportados.
 *
 * Cada entrada describe metadatos legales del servicio (categoría, processor,
 * transferencia internacional) + qué campos de configuración necesita
 * (measurement_id, pixel_id, etc.).
 *
 * El manifest persiste por servicio:
 *   { key: 'ga4', enabled: bool, config: { measurement_id: '...' } }
 */
final class TrackingCatalog
{
    /** Categorías de consentimiento, en orden de aparición en el banner. */
    public const CATEGORIES = [
        'necessary'      => [
            'label'       => 'Necesarias',
            'description' => 'Imprescindibles para que la web funcione. No se pueden desactivar.',
            'always_on'   => true,
        ],
        'analytics'      => [
            'label'       => 'Analítica',
            'description' => 'Nos ayudan a entender cómo se usa la web para mejorarla.',
            'always_on'   => false,
        ],
        'advertising'    => [
            'label'       => 'Marketing',
            'description' => 'Permiten mostrar anuncios relevantes y medir su eficacia.',
            'always_on'   => false,
        ],
        'external_media' => [
            'label'       => 'Multimedia externa',
            'description' => 'Vídeos y mapas embebidos (YouTube, Vimeo, Google Maps).',
            'always_on'   => false,
        ],
    ];

    /**
     * Servicios disponibles. La key es el identificador interno; el valor
     * incluye nombre humano, categoría, processor y los campos de config.
     */
    public static function services(): array
    {
        return [
            'ga4' => [
                'name'                 => 'Google Analytics 4',
                'category'             => 'analytics',
                'processor'            => 'Google LLC',
                'transfer_outside_eea' => true,
                'short_description'    => 'Analítica de visitas anonimizable.',
                'config_fields'        => [
                    'measurement_id' => [
                        'label'       => 'ID de medición',
                        'placeholder' => 'G-XXXXXXXXXX',
                        'help'        => 'Lo encuentras en Google Analytics → Admin → Flujos de datos.',
                        'pattern'     => '^G-[A-Z0-9]{4,}$',
                    ],
                ],
            ],
            'meta_pixel' => [
                'name'                 => 'Meta Pixel',
                'category'             => 'advertising',
                'processor'            => 'Meta Platforms Ireland Ltd.',
                'transfer_outside_eea' => true,
                'short_description'    => 'Píxel de Facebook/Instagram para campañas.',
                'config_fields'        => [
                    'pixel_id' => [
                        'label'       => 'ID de Pixel',
                        'placeholder' => '123456789012345',
                        'help'        => 'Es un número de 15-16 dígitos. Lo encuentras en el Administrador de Eventos de Meta.',
                        'pattern'     => '^[0-9]{10,20}$',
                    ],
                ],
            ],
            'gtm' => [
                'name'                 => 'Google Tag Manager',
                'category'             => 'analytics',
                'processor'            => 'Google LLC',
                'transfer_outside_eea' => true,
                'short_description'    => 'Contenedor de etiquetas: gestiona varios píxeles y eventos desde un solo sitio.',
                'config_fields'        => [
                    'container_id' => [
                        'label'       => 'ID de contenedor',
                        'placeholder' => 'GTM-XXXXXXX',
                        'help'        => 'Lo encuentras en Google Tag Manager, arriba junto al nombre del contenedor.',
                        'pattern'     => '^GTM-[A-Z0-9]{4,}$',
                    ],
                ],
            ],
            'google_ads' => [
                'name'                 => 'Google Ads',
                'category'             => 'advertising',
                'processor'            => 'Google LLC',
                'transfer_outside_eea' => true,
                'short_description'    => 'Seguimiento de conversiones y remarketing de campañas de Google Ads.',
                'config_fields'        => [
                    'conversion_id' => [
                        'label'       => 'ID de conversión',
                        'placeholder' => 'AW-123456789',
                        'help'        => 'Empieza por "AW-". Está en Google Ads → Herramientas → Conversiones.',
                        'pattern'     => '^AW-[0-9]{6,}$',
                    ],
                ],
            ],
            'tiktok_pixel' => [
                'name'                 => 'TikTok Pixel',
                'category'             => 'advertising',
                'processor'            => 'TikTok Technology Ltd.',
                'transfer_outside_eea' => true,
                'short_description'    => 'Píxel de TikTok para medir conversiones y optimizar anuncios.',
                'config_fields'        => [
                    'pixel_id' => [
                        'label'       => 'ID de Pixel',
                        'placeholder' => 'CXXXXXXXXXXXXXXXXXXX',
                        'help'        => 'Lo encuentras en el Administrador de Eventos de TikTok → Píxeles web.',
                        'pattern'     => '^[A-Z0-9]{15,30}$',
                    ],
                ],
            ],
            'linkedin' => [
                'name'                 => 'LinkedIn Insight Tag',
                'category'             => 'advertising',
                'processor'            => 'LinkedIn Ireland Unlimited Company',
                'transfer_outside_eea' => true,
                'short_description'    => 'Etiqueta de LinkedIn para seguimiento de conversiones B2B y remarketing.',
                'config_fields'        => [
                    'partner_id' => [
                        'label'       => 'ID de partner',
                        'placeholder' => '1234567',
                        'help'        => 'Número que aparece en LinkedIn Campaign Manager → Insight Tag.',
                        'pattern'     => '^[0-9]{5,12}$',
                    ],
                ],
            ],
            'recaptcha' => [
                'name'                 => 'Google reCAPTCHA',
                'category'             => 'necessary',
                'processor'            => 'Google LLC',
                'transfer_outside_eea' => true,
                'short_description'    => 'Protección anti-spam en formularios. Se considera necesario para que los forms funcionen.',
                'config_fields'        => [
                    'site_key' => [
                        'label'       => 'Site key (clave pública)',
                        'placeholder' => '6Lc...',
                        'help'        => 'Clave del sitio que se incrusta en el frontend. Genérala en google.com/recaptcha/admin.',
                        'pattern'     => '^[A-Za-z0-9_-]{20,}$',
                    ],
                    'secret_key' => [
                        'label'       => 'Secret key (clave privada)',
                        'placeholder' => '6Lc...',
                        'help'        => 'Solo se usa en servidor para validar respuestas. No se expone al frontend.',
                        'pattern'     => '^[A-Za-z0-9_-]{20,}$',
                    ],
                ],
            ],
        ];
    }

    /**
     * Lee del manifest la config persistida de un servicio. Devuelve null si
     * no está activado.
     */
    public static function serviceConfig(array $manifest, string $key): ?array
    {
        $services = (array) ($manifest['tracking']['services'] ?? []);
        foreach ($services as $s) {
            if (($s['key'] ?? null) === $key) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Categorías que están "vivas" en el manifest = al menos un servicio
     * (de catálogo o snippet personalizado) habilitado en esa categoría.
     * La categoría `necessary` siempre cuenta.
     */
    public static function activeCategories(array $manifest): array
    {
        $active = ['necessary'];
        $services = (array) ($manifest['tracking']['services'] ?? []);
        $catalog  = self::services();
        foreach ($services as $s) {
            if (empty($s['enabled'])) continue;
            $key = (string) ($s['key'] ?? '');
            if (!isset($catalog[$key])) continue;
            $cat = $catalog[$key]['category'];
            if (!in_array($cat, $active, true)) $active[] = $cat;
        }
        // Snippets de código personalizado: cada uno declara su categoría.
        foreach (self::customForPublic($manifest) as $snippet) {
            $cat = $snippet['category'];
            if (!in_array($cat, $active, true)) $active[] = $cat;
        }
        return $active;
    }

    /**
     * Devuelve true si el sitio necesita banner de consentimiento, es decir:
     * hay al menos un servicio habilitado en una categoría que NO sea 'necessary'.
     */
    public static function needsBanner(array $manifest): bool
    {
        $active = self::activeCategories($manifest);
        foreach ($active as $cat) {
            if ($cat !== 'necessary') return true;
        }
        return false;
    }

    /**
     * Snapshot de servicios habilitados con su config + metadata,
     * útil para inyectar en el frontend público (JS gating).
     *
     * @return array<int,array{key:string,name:string,category:string,config:array}>
     */
    public static function enabledForPublic(array $manifest): array
    {
        $out = [];
        $services = (array) ($manifest['tracking']['services'] ?? []);
        $catalog  = self::services();
        foreach ($services as $s) {
            if (empty($s['enabled'])) continue;
            $key = (string) ($s['key'] ?? '');
            if (!isset($catalog[$key])) continue;
            $out[] = [
                'key'      => $key,
                'name'     => $catalog[$key]['name'],
                'category' => $catalog[$key]['category'],
                'config'   => (array) ($s['config'] ?? []),
            ];
        }
        return $out;
    }

    /** Ubicaciones válidas donde inyectar un snippet personalizado. */
    public const PLACEMENTS = [
        'head'     => 'En el <head> (carga temprana)',
        'body_end' => 'Antes de cerrar </body> (carga tardía)',
    ];

    /** Categorías que el usuario puede elegir para un snippet personalizado. */
    public static function customCategoryChoices(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $def) {
            $out[$key] = $def['label'];
        }
        return $out;
    }

    /**
     * Snippets de código personalizado habilitados, normalizados para el
     * frontend (consent gating). Descarta entradas sin código o categoría
     * inválida.
     *
     * @return array<int,array{id:string,label:string,category:string,placement:string,code:string}>
     */
    public static function customForPublic(array $manifest): array
    {
        $out = [];
        $custom = (array) ($manifest['tracking']['custom'] ?? []);
        foreach ($custom as $c) {
            if (empty($c['enabled'])) continue;
            $code = trim((string) ($c['code'] ?? ''));
            if ($code === '') continue;
            $category = (string) ($c['category'] ?? '');
            if (!isset(self::CATEGORIES[$category])) continue;
            $placement = (string) ($c['placement'] ?? 'body_end');
            if (!isset(self::PLACEMENTS[$placement])) $placement = 'body_end';
            $out[] = [
                'id'        => (string) ($c['id'] ?? ''),
                'label'     => (string) ($c['label'] ?? 'Snippet'),
                'category'  => $category,
                'placement' => $placement,
                'code'      => $code,
            ];
        }
        return $out;
    }
}
