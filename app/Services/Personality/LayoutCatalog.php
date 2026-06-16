<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 2 — Catálogo de los 50 layouts (variantes) con metadatos para el
 * `LayoutSelector`. Cada layout tiene:
 *   - axes: posición en los 4 ejes de layout (density, hierarchy,
 *     alignment_bias, compositional_balance), valores 0..1.
 *   - requires: campos del content que DEBEN existir para usar este layout
 *     (si faltan → hard penalty).
 *   - incompatible_skin: condiciones del vector skin que descartan este layout
 *     (p.ej. `formality > 0.85`).
 *   - good_for / pairs_well / avoid_for: hints suaves (los bonuses los usa
 *     Slice 5; aquí los registramos para no perder la info de D0c).
 *
 * Los slugs respetan los del `SectionSchemas::variants`. Si añadimos una
 * variante nueva al schema, hay que añadirla aquí o caerá a defaults.
 */
final class LayoutCatalog
{
    /**
     * Catálogo completo. Estructura: [type => [variant => meta]].
     */
    public const CATALOG = [
        // ============================================================
        // HERO
        // ============================================================
        'hero' => [
            'default' => [
                'axes' => ['density' => 0.40, 'hierarchy' => 0.55, 'alignment_bias' => 0.20, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
                'good_for' => ['presence', 'services'],
                'pairs_well' => ['benefits/default', 'cta/default'],
            ],
            'split' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.55, 'alignment_bias' => 0.60, 'compositional_balance' => 0.40],
                'requires' => ['image_url'],
                'incompatible_skin' => [],
                'good_for' => ['services', 'product'],
                'pairs_well' => ['text_image/default', 'benefits/default'],
            ],
            'with-image-bg' => [
                'axes' => ['density' => 0.30, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.55],
                'requires' => ['background_image'],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.90]],
                'good_for' => ['portfolio', 'product'],
                'pairs_well' => ['benefits/cards-icon-top', 'gallery/mosaic'],
            ],
            'poster-stack' => [
                'axes' => ['density' => 0.25, 'hierarchy' => 0.95, 'alignment_bias' => 0.65, 'compositional_balance' => 0.75],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
                'good_for' => ['portfolio', 'product'],
                'pairs_well' => ['cta/poster-close', 'gallery/editorial-strip'],
            ],
            'statement-left' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.85, 'alignment_bias' => 0.85, 'compositional_balance' => 0.60],
                'requires' => [],
                'incompatible_skin' => [],
                'good_for' => ['services', 'product'],
                'pairs_well' => ['benefits/manifesto', 'cta/quiet-inline'],
            ],
            'metric-led' => [
                'axes' => ['density' => 0.70, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
                'good_for' => ['product', 'seo'],
                'pairs_well' => ['stats/inline-bar', 'benefits/proof-strip'],
            ],
        ],

        // ============================================================
        // TEXT + IMAGE
        // ============================================================
        'text_image' => [
            'default' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.45, 'alignment_bias' => 0.45, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'wide-media' => [
                'axes' => ['density' => 0.30, 'hierarchy' => 0.55, 'alignment_bias' => 0.55, 'compositional_balance' => 0.45],
                'requires' => ['image_url'],
                'incompatible_skin' => [],
            ],
            'card' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // BENEFITS
        // ============================================================
        'benefits' => [
            'default' => [
                'axes' => ['density' => 0.60, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'cards-icon-top' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.55, 'alignment_bias' => 0.35, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'numbered' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.60, 'alignment_bias' => 0.35, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'offset-grid' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.70, 'alignment_bias' => 0.70, 'compositional_balance' => 0.65],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
            'manifesto' => [
                'axes' => ['density' => 0.40, 'hierarchy' => 0.85, 'alignment_bias' => 0.75, 'compositional_balance' => 0.70],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
            'proof-strip' => [
                'axes' => ['density' => 0.75, 'hierarchy' => 0.40, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // FAQ
        // ============================================================
        'faq' => [
            'default' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'accordion' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'two-columns' => [
                'axes' => ['density' => 0.65, 'hierarchy' => 0.45, 'alignment_bias' => 0.45, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // CTA
        // ============================================================
        'cta' => [
            'default' => [
                'axes' => ['density' => 0.35, 'hierarchy' => 0.65, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'card' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.55, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'split' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.55, 'alignment_bias' => 0.65, 'compositional_balance' => 0.40],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'poster-close' => [
                'axes' => ['density' => 0.30, 'hierarchy' => 0.95, 'alignment_bias' => 0.55, 'compositional_balance' => 0.75],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
            'quiet-inline' => [
                'axes' => ['density' => 0.30, 'hierarchy' => 0.40, 'alignment_bias' => 0.55, 'compositional_balance' => 0.45],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // TESTIMONIALS
        // ============================================================
        'testimonials' => [
            'default' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.35, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'featured-quote' => [
                'axes' => ['density' => 0.30, 'hierarchy' => 0.80, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'quote-wall' => [
                'axes' => ['density' => 0.85, 'hierarchy' => 0.50, 'alignment_bias' => 0.50, 'compositional_balance' => 0.60],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
        ],

        // ============================================================
        // STATS
        // ============================================================
        'stats' => [
            'default' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.55, 'alignment_bias' => 0.30, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'inline-bar' => [
                'axes' => ['density' => 0.75, 'hierarchy' => 0.45, 'alignment_bias' => 0.45, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'scoreboard' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.85, 'alignment_bias' => 0.65, 'compositional_balance' => 0.65],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
        ],

        // ============================================================
        // GALLERY
        // ============================================================
        'gallery' => [
            'default' => [
                'axes' => ['density' => 0.60, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'mosaic' => [
                'axes' => ['density' => 0.75, 'hierarchy' => 0.60, 'alignment_bias' => 0.55, 'compositional_balance' => 0.70],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'editorial-strip' => [
                'axes' => ['density' => 0.40, 'hierarchy' => 0.85, 'alignment_bias' => 0.75, 'compositional_balance' => 0.75],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
        ],

        // ============================================================
        // STEPS
        // ============================================================
        'steps' => [
            'default' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.55, 'alignment_bias' => 0.30, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'horizontal' => [
                'axes' => ['density' => 0.65, 'hierarchy' => 0.55, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'staggered-cards' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.65, 'alignment_bias' => 0.65, 'compositional_balance' => 0.65],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // LOGOS_STRIP
        // ============================================================
        'logos_strip' => [
            'default' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.30, 'alignment_bias' => 0.35, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'marquee' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.30, 'alignment_bias' => 0.55, 'compositional_balance' => 0.35],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // PRICING
        // ============================================================
        'pricing' => [
            'default' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.55, 'alignment_bias' => 0.30, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'comparison' => [
                'axes' => ['density' => 0.80, 'hierarchy' => 0.50, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'editorial-list' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.70, 'alignment_bias' => 0.65, 'compositional_balance' => 0.55],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
            'split-value' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.75, 'alignment_bias' => 0.60, 'compositional_balance' => 0.55],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // FORM
        // ============================================================
        'form' => [
            'default' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'inline-card' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.35, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'with-side-image' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.55, 'alignment_bias' => 0.55, 'compositional_balance' => 0.35],
                'requires' => ['image_url'],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // POSTS_LISTING
        // ============================================================
        'posts_listing' => [
            'default' => [
                'axes' => ['density' => 0.60, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.25],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'editorial-list' => [
                'axes' => ['density' => 0.45, 'hierarchy' => 0.70, 'alignment_bias' => 0.65, 'compositional_balance' => 0.50],
                'requires' => [],
                'incompatible_skin' => [['axis' => 'formality', 'op' => '>', 'value' => 0.85]],
            ],
            'featured-first' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.75, 'alignment_bias' => 0.55, 'compositional_balance' => 0.55],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],

        // ============================================================
        // ARTICLE_BODY
        // ============================================================
        'article_body' => [
            'default' => [
                'axes' => ['density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'narrow' => [
                'axes' => ['density' => 0.40, 'hierarchy' => 0.45, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
                'requires' => [],
                'incompatible_skin' => [],
            ],
            'wide' => [
                'axes' => ['density' => 0.55, 'hierarchy' => 0.55, 'alignment_bias' => 0.35, 'compositional_balance' => 0.30],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ],
    ];

    /**
     * Devuelve todas las variantes registradas para un tipo de sección.
     * Si el tipo no existe → devuelve solo 'default' con axes neutros.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function variantsFor(string $type): array
    {
        return self::CATALOG[$type] ?? [
            'default' => [
                'axes' => ['density' => 0.5, 'hierarchy' => 0.5, 'alignment_bias' => 0.5, 'compositional_balance' => 0.5],
                'requires' => [],
                'incompatible_skin' => [],
            ],
        ];
    }

    /**
     * Devuelve la ficha de un layout concreto, o null si no existe.
     */
    public static function get(string $type, string $variant): ?array
    {
        return self::CATALOG[$type][$variant] ?? null;
    }

    /**
     * Comprueba si una variante está soportada en el catálogo.
     */
    public static function isKnown(string $type, string $variant): bool
    {
        return isset(self::CATALOG[$type][$variant]);
    }

    /**
     * Evalúa si un layout es incompatible con el vector skin del sitio.
     * Por ejemplo: layouts con alta compositional_balance suelen ser incompatibles
     * con sitios muy formales (formality > 0.85).
     *
     * @param array{axis:string,op:string,value:float}[] $rules
     */
    public static function isIncompatibleWithSkin(array $rules, array $skinVector): bool
    {
        foreach ($rules as $r) {
            $axis = (string) ($r['axis'] ?? '');
            $op   = (string) ($r['op'] ?? '');
            $val  = (float) ($r['value'] ?? 0.0);
            $current = (float) ($skinVector[$axis] ?? 0.5);
            $hit = match ($op) {
                '>'  => $current >  $val,
                '>=' => $current >= $val,
                '<'  => $current <  $val,
                '<=' => $current <= $val,
                default => false,
            };
            if ($hit) return true;
        }
        return false;
    }
}
