<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 4 (S4.1) — Tabla de "priors" por sector económico.
 *
 * Cada sector aporta un vector parcial sobre los 8 ejes. Es un sesgo bayesiano:
 * si todo lo demás falla, el sector solo ya da un punto de partida razonable.
 * Si otros extractores aportan, el agregador los pondera y vencen ellos.
 *
 * Peso en PersonalityInference: 0.15.
 *
 * La inferencia del slug del sector viene de la IA (`INFER_BRAND_PERSONALITY`
 * devuelve `inferred_sector`). Si el slug no matchea exacto al catálogo,
 * intentamos matching por keyword más cercano; si no, `default_unclassified`
 * → todos los ejes a 0.5.
 */
final class SectorPriors
{
    /**
     * Catálogo de 51 sectores con vector parcial por eje.
     * Estructura: [slug => [warmth, formality, modernity, energy, density, hierarchy, alignment_bias, compositional_balance]].
     * Las claves no presentes en un sector se asumen 0.5 (neutro).
     */
    public const PRIORS = [
        // Servicios profesionales
        'legal-firm'                => ['warmth' => 0.20, 'formality' => 0.95, 'modernity' => 0.20, 'energy' => 0.20, 'density' => 0.70, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'accounting'                => ['warmth' => 0.30, 'formality' => 0.80, 'modernity' => 0.40, 'energy' => 0.30, 'density' => 0.60, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'consulting-business'       => ['warmth' => 0.35, 'formality' => 0.70, 'modernity' => 0.55, 'energy' => 0.50, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'consulting-strategy'       => ['warmth' => 0.30, 'formality' => 0.80, 'modernity' => 0.65, 'energy' => 0.55, 'density' => 0.60, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'notary'                    => ['warmth' => 0.25, 'formality' => 0.95, 'modernity' => 0.15, 'energy' => 0.20, 'density' => 0.70, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],

        // Salud / médico
        'dental-clinic'             => ['warmth' => 0.50, 'formality' => 0.70, 'modernity' => 0.55, 'energy' => 0.40, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'medical-clinic'            => ['warmth' => 0.45, 'formality' => 0.75, 'modernity' => 0.50, 'energy' => 0.35, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'psychology-therapy'        => ['warmth' => 0.70, 'formality' => 0.55, 'modernity' => 0.50, 'energy' => 0.25, 'density' => 0.60, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'physiotherapy'             => ['warmth' => 0.55, 'formality' => 0.55, 'modernity' => 0.55, 'energy' => 0.45, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'veterinary'                => ['warmth' => 0.75, 'formality' => 0.50, 'modernity' => 0.55, 'energy' => 0.50, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],

        // Hospitality / restauración
        'hotel-luxury'              => ['warmth' => 0.85, 'formality' => 0.85, 'modernity' => 0.30, 'energy' => 0.30, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.60, 'compositional_balance' => 0.50],
        'hotel-boutique'            => ['warmth' => 0.80, 'formality' => 0.65, 'modernity' => 0.55, 'energy' => 0.40, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.50],
        'hotel-budget'              => ['warmth' => 0.55, 'formality' => 0.40, 'modernity' => 0.55, 'energy' => 0.55, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'restaurant-fine-dining'    => ['warmth' => 0.85, 'formality' => 0.85, 'modernity' => 0.25, 'energy' => 0.25, 'density' => 0.30, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.60],
        'restaurant-casual'         => ['warmth' => 0.75, 'formality' => 0.30, 'modernity' => 0.55, 'energy' => 0.60, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'cafe-specialty'            => ['warmth' => 0.85, 'formality' => 0.30, 'modernity' => 0.70, 'energy' => 0.50, 'density' => 0.40, 'hierarchy' => 0.60, 'alignment_bias' => 0.50, 'compositional_balance' => 0.50],
        'bar-cocktail'              => ['warmth' => 0.75, 'formality' => 0.40, 'modernity' => 0.65, 'energy' => 0.65, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.60, 'compositional_balance' => 0.50],
        'winery'                    => ['warmth' => 0.85, 'formality' => 0.70, 'modernity' => 0.30, 'energy' => 0.30, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'bakery-artisan'            => ['warmth' => 0.90, 'formality' => 0.40, 'modernity' => 0.40, 'energy' => 0.40, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],

        // Wellness / belleza
        'spa-wellness'              => ['warmth' => 0.80, 'formality' => 0.55, 'modernity' => 0.45, 'energy' => 0.20, 'density' => 0.40, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'yoga-meditation'           => ['warmth' => 0.75, 'formality' => 0.45, 'modernity' => 0.45, 'energy' => 0.20, 'density' => 0.40, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
        'gym-fitness-classic'       => ['warmth' => 0.40, 'formality' => 0.40, 'modernity' => 0.65, 'energy' => 0.85, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'gym-crossfit'              => ['warmth' => 0.35, 'formality' => 0.30, 'modernity' => 0.70, 'energy' => 0.95, 'density' => 0.50, 'hierarchy' => 0.80, 'alignment_bias' => 0.60, 'compositional_balance' => 0.50],
        'beauty-salon'              => ['warmth' => 0.75, 'formality' => 0.55, 'modernity' => 0.55, 'energy' => 0.55, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'cosmetics-natural'         => ['warmth' => 0.85, 'formality' => 0.50, 'modernity' => 0.55, 'energy' => 0.30, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],

        // Tech / digital
        'saas-b2b'                  => ['warmth' => 0.40, 'formality' => 0.55, 'modernity' => 0.70, 'energy' => 0.60, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'saas-developer-tools'      => ['warmth' => 0.30, 'formality' => 0.50, 'modernity' => 0.85, 'energy' => 0.65, 'density' => 0.60, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'ai-startup'                => ['warmth' => 0.35, 'formality' => 0.40, 'modernity' => 0.95, 'energy' => 0.80, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.50],
        'agency-digital'            => ['warmth' => 0.45, 'formality' => 0.45, 'modernity' => 0.75, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'agency-creative'           => ['warmth' => 0.55, 'formality' => 0.30, 'modernity' => 0.80, 'energy' => 0.85, 'density' => 0.40, 'hierarchy' => 0.90, 'alignment_bias' => 0.80, 'compositional_balance' => 0.70],
        'freelance-developer'       => ['warmth' => 0.40, 'formality' => 0.45, 'modernity' => 0.70, 'energy' => 0.55, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],

        // Creativos / arquitectura
        'photography-studio'        => ['warmth' => 0.55, 'formality' => 0.55, 'modernity' => 0.65, 'energy' => 0.40, 'density' => 0.40, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.70],
        'design-studio'             => ['warmth' => 0.50, 'formality' => 0.50, 'modernity' => 0.85, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.60],
        'architecture-firm'         => ['warmth' => 0.45, 'formality' => 0.70, 'modernity' => 0.60, 'energy' => 0.35, 'density' => 0.40, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.60],
        'interior-design'           => ['warmth' => 0.70, 'formality' => 0.65, 'modernity' => 0.55, 'energy' => 0.40, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.60, 'compositional_balance' => 0.50],
        'fashion-label-streetwear'  => ['warmth' => 0.50, 'formality' => 0.25, 'modernity' => 0.80, 'energy' => 0.85, 'density' => 0.40, 'hierarchy' => 0.90, 'alignment_bias' => 0.80, 'compositional_balance' => 0.70],
        'fashion-label-classic'     => ['warmth' => 0.65, 'formality' => 0.85, 'modernity' => 0.40, 'energy' => 0.40, 'density' => 0.40, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.50],

        // Educación
        'school-kids'               => ['warmth' => 0.85, 'formality' => 0.40, 'modernity' => 0.55, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
        'academy-language'          => ['warmth' => 0.65, 'formality' => 0.55, 'modernity' => 0.55, 'energy' => 0.55, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
        'online-courses'            => ['warmth' => 0.55, 'formality' => 0.45, 'modernity' => 0.75, 'energy' => 0.60, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'university-postgrad'       => ['warmth' => 0.40, 'formality' => 0.85, 'modernity' => 0.50, 'energy' => 0.40, 'density' => 0.60, 'hierarchy' => 0.70, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],

        // Servicios al hogar / industrial
        'home-renovation'           => ['warmth' => 0.55, 'formality' => 0.45, 'modernity' => 0.50, 'energy' => 0.50, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'gardening-landscaping'     => ['warmth' => 0.75, 'formality' => 0.40, 'modernity' => 0.50, 'energy' => 0.45, 'density' => 0.40, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
        'cleaning-services'         => ['warmth' => 0.55, 'formality' => 0.45, 'modernity' => 0.50, 'energy' => 0.50, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'manufacturing-b2b'         => ['warmth' => 0.30, 'formality' => 0.70, 'modernity' => 0.50, 'energy' => 0.45, 'density' => 0.60, 'hierarchy' => 0.60, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'logistics'                 => ['warmth' => 0.30, 'formality' => 0.65, 'modernity' => 0.55, 'energy' => 0.55, 'density' => 0.60, 'hierarchy' => 0.60, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'construction'              => ['warmth' => 0.40, 'formality' => 0.60, 'modernity' => 0.45, 'energy' => 0.55, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'real-estate-residential'   => ['warmth' => 0.55, 'formality' => 0.65, 'modernity' => 0.50, 'energy' => 0.45, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'real-estate-luxury'        => ['warmth' => 0.65, 'formality' => 0.85, 'modernity' => 0.40, 'energy' => 0.30, 'density' => 0.40, 'hierarchy' => 0.80, 'alignment_bias' => 0.60, 'compositional_balance' => 0.50],

        // Otros
        'nonprofit-ngo'             => ['warmth' => 0.85, 'formality' => 0.55, 'modernity' => 0.45, 'energy' => 0.50, 'density' => 0.50, 'hierarchy' => 0.60, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'events-weddings'           => ['warmth' => 0.85, 'formality' => 0.65, 'modernity' => 0.50, 'energy' => 0.45, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.50],
        'events-corporate'          => ['warmth' => 0.40, 'formality' => 0.70, 'modernity' => 0.65, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'pet-care'                  => ['warmth' => 0.85, 'formality' => 0.30, 'modernity' => 0.55, 'energy' => 0.60, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.30],
        'automotive-dealer'         => ['warmth' => 0.40, 'formality' => 0.55, 'modernity' => 0.60, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.70, 'alignment_bias' => 0.40, 'compositional_balance' => 0.30],
        'automotive-workshop'       => ['warmth' => 0.45, 'formality' => 0.40, 'modernity' => 0.50, 'energy' => 0.65, 'density' => 0.50, 'hierarchy' => 0.50, 'alignment_bias' => 0.30, 'compositional_balance' => 0.20],
        'jewelry-luxury'            => ['warmth' => 0.65, 'formality' => 0.95, 'modernity' => 0.30, 'energy' => 0.30, 'density' => 0.30, 'hierarchy' => 0.80, 'alignment_bias' => 0.70, 'compositional_balance' => 0.60],
        'jewelry-handmade'          => ['warmth' => 0.85, 'formality' => 0.55, 'modernity' => 0.45, 'energy' => 0.35, 'density' => 0.40, 'hierarchy' => 0.60, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'ecommerce-fashion'         => ['warmth' => 0.55, 'formality' => 0.45, 'modernity' => 0.65, 'energy' => 0.65, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'ecommerce-home-decor'      => ['warmth' => 0.70, 'formality' => 0.50, 'modernity' => 0.55, 'energy' => 0.45, 'density' => 0.40, 'hierarchy' => 0.70, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
        'ecommerce-gourmet-food'    => ['warmth' => 0.85, 'formality' => 0.55, 'modernity' => 0.45, 'energy' => 0.40, 'density' => 0.40, 'hierarchy' => 0.60, 'alignment_bias' => 0.50, 'compositional_balance' => 0.40],
    ];

    /**
     * Devuelve el vector parcial de un slug. Si no existe, intenta matching
     * "fuzzy" por palabras clave; si tampoco encaja, devuelve null.
     */
    public static function priorFor(string $slug): ?array
    {
        $slug = self::normalizeSlug($slug);
        if (isset(self::PRIORS[$slug])) {
            return self::PRIORS[$slug];
        }
        // Fuzzy match: dividir en tokens y buscar el sector con más coincidencias.
        $tokens = preg_split('/[-_\s\/]+/', $slug);
        if (!$tokens) return null;
        $tokens = array_filter(array_map('strtolower', $tokens), static fn ($t) => mb_strlen($t) >= 3);
        if (!$tokens) return null;

        $bestSlug = null;
        $bestScore = 0;
        foreach (array_keys(self::PRIORS) as $candidate) {
            $candidateTokens = explode('-', $candidate);
            $matches = count(array_intersect($candidateTokens, $tokens));
            if ($matches > $bestScore) {
                $bestScore = $matches;
                $bestSlug = $candidate;
            }
        }
        return $bestScore >= 1 ? self::PRIORS[$bestSlug] : null;
    }

    /**
     * Lista de slugs registrados (puede inyectarse en prompts IA).
     */
    public static function slugs(): array
    {
        return array_keys(self::PRIORS);
    }

    /**
     * Normaliza la entrada: lowercase, espacios → guiones, sin tildes ni
     * caracteres raros. Conserva guiones y números.
     */
    public static function normalizeSlug(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        $raw = strtr($raw, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ç'=>'c']);
        $raw = preg_replace('/[^a-z0-9]+/', '-', $raw) ?? '';
        return trim($raw, '-');
    }
}
