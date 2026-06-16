<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 1 (S1.3) — Catálogo de skin anchors curados.
 *
 * Un anchor es un punto materializado en el espacio 4-axis skin
 * (warmth, formality, modernity, energy). Tiene paleta, tipografía, radii,
 * sombras y motion. No es user-facing; es punto de interpolación para
 * `SkinComposer`.
 *
 * Los 8 anchors están definidos en `cursor/d0_design_reference.md` (D0e).
 * Cubren los corners del espacio + midpoints razonables.
 */
final class SkinAnchors
{
    /**
     * Los 8 anchors definitivos. Editable sin migración; cambios se reflejan
     * en el SkinComposer en runtime.
     */
    public const ANCHORS = [
        [
            'id' => 'clinical-precise',
            'vector' => ['warmth' => 0.10, 'formality' => 0.80, 'modernity' => 0.20, 'energy' => 0.25],
            'palette' => [
                'primary'      => '#0e4d8c',
                'primary_dark' => '#073259',
                'accent'       => '#1ea6a0',
                'bg'           => '#ffffff',
                'surface'      => '#f4f7fa',
                'text'         => '#0b1b2b',
                'text_muted'   => '#5b7080',
                'border'       => '#dbe3ec',
            ],
            'typography' => [
                'font_heading' => 'Inter',
                'font_body'    => 'Inter',
                'scale_ratio'  => '1.250',
                'weight_bold'  => '700',
                'letter_spacing_heading' => '-0.005em',
                'label_case'   => 'uppercase',
            ],
            'radii' => ['btn' => 4, 'card' => 6],
            'shadow_level' => 'subtle',
            'effects' => ['gradient' => false, 'glass' => false, 'noise' => false],
            'motion' => 'subtle',
        ],
        [
            'id' => 'modern-saas',
            'vector' => ['warmth' => 0.40, 'formality' => 0.50, 'modernity' => 0.60, 'energy' => 0.55],
            'palette' => [
                'primary'      => '#6366f1',
                'primary_dark' => '#4f46e5',
                'accent'       => '#22d3ee',
                'bg'           => '#ffffff',
                'surface'      => '#f9fafb',
                'text'         => '#1f2937',
                'text_muted'   => '#6b7280',
                'border'       => '#e5e7eb',
            ],
            'typography' => [
                'font_heading' => 'Inter',
                'font_body'    => 'Inter',
                'scale_ratio'  => '1.250',
                'weight_bold'  => '700',
                'letter_spacing_heading' => '-0.015em',
                'label_case'   => 'sentence',
            ],
            'radii' => ['btn' => 8, 'card' => 12],
            'shadow_level' => 'subtle',
            'effects' => ['gradient' => true, 'glass' => false, 'noise' => false],
            'motion' => 'subtle',
        ],
        [
            'id' => 'vibrant-tech',
            'vector' => ['warmth' => 0.35, 'formality' => 0.30, 'modernity' => 0.95, 'energy' => 0.90],
            'palette' => [
                'primary'      => '#7c3aed',
                'primary_dark' => '#5b21b6',
                'accent'       => '#22d3ee',
                'bg'           => '#0b0a14',
                'surface'      => '#161427',
                'text'         => '#f5f5ff',
                'text_muted'   => '#a3a3c2',
                'border'       => '#2a273f',
            ],
            'typography' => [
                'font_heading' => 'Work Sans',
                'font_body'    => 'Inter',
                'scale_ratio'  => '1.333',
                'weight_bold'  => '800',
                'letter_spacing_heading' => '-0.025em',
                'label_case'   => 'uppercase',
            ],
            'radii' => ['btn' => 12, 'card' => 16],
            'shadow_level' => 'dramatic',
            'effects' => ['gradient' => true, 'glass' => true, 'noise' => true],
            'motion' => 'pronounced',
        ],
        [
            'id' => 'warm-editorial',
            'vector' => ['warmth' => 0.85, 'formality' => 0.85, 'modernity' => 0.20, 'energy' => 0.20],
            'palette' => [
                'primary'      => '#8b5a2b',
                'primary_dark' => '#5e3c1c',
                'accent'       => '#c2410c',
                'bg'           => '#faf6f0',
                'surface'      => '#f0e9dc',
                'text'         => '#2a1f15',
                'text_muted'   => '#7a6855',
                'border'       => '#d9cdb6',
            ],
            'typography' => [
                'font_heading' => 'Playfair Display',
                'font_body'    => 'Lora',
                'scale_ratio'  => '1.333',
                'weight_bold'  => '700',
                'letter_spacing_heading' => '-0.02em',
                'label_case'   => 'uppercase',
            ],
            'radii' => ['btn' => 2, 'card' => 4],
            'shadow_level' => 'subtle',
            'effects' => ['gradient' => false, 'glass' => false, 'noise' => false],
            'motion' => 'none',
        ],
        [
            'id' => 'friendly-digital',
            'vector' => ['warmth' => 0.75, 'formality' => 0.30, 'modernity' => 0.65, 'energy' => 0.55],
            'palette' => [
                'primary'      => '#f97316',
                'primary_dark' => '#c2410c',
                'accent'       => '#facc15',
                'bg'           => '#fffaf3',
                'surface'      => '#fff1de',
                'text'         => '#2a1810',
                'text_muted'   => '#7c5e4a',
                'border'       => '#f1d8b8',
            ],
            'typography' => [
                'font_heading' => 'Nunito',
                'font_body'    => 'Nunito',
                'scale_ratio'  => '1.250',
                'weight_bold'  => '700',
                'letter_spacing_heading' => '-0.01em',
                'label_case'   => 'sentence',
            ],
            'radii' => ['btn' => 16, 'card' => 20],
            'shadow_level' => 'subtle',
            'effects' => ['gradient' => true, 'glass' => false, 'noise' => false],
            'motion' => 'subtle',
        ],
        [
            'id' => 'bold-poster',
            'vector' => ['warmth' => 0.50, 'formality' => 0.25, 'modernity' => 0.75, 'energy' => 0.85],
            'palette' => [
                'primary'      => '#ef4444',
                'primary_dark' => '#b91c1c',
                'accent'       => '#facc15',
                'bg'           => '#0f0f10',
                'surface'      => '#1c1c1e',
                'text'         => '#fafafa',
                'text_muted'   => '#a3a3a3',
                'border'       => '#2a2a2c',
            ],
            'typography' => [
                'font_heading' => 'Work Sans',
                'font_body'    => 'Inter',
                'scale_ratio'  => '1.500',
                'weight_bold'  => '900',
                'letter_spacing_heading' => '-0.04em',
                'label_case'   => 'uppercase',
            ],
            'radii' => ['btn' => 2, 'card' => 4],
            'shadow_level' => 'dramatic',
            'effects' => ['gradient' => false, 'glass' => false, 'noise' => true],
            'motion' => 'pronounced',
        ],
        [
            'id' => 'serene-wellness',
            'vector' => ['warmth' => 0.70, 'formality' => 0.50, 'modernity' => 0.45, 'energy' => 0.20],
            'palette' => [
                'primary'      => '#6b8e6e',
                'primary_dark' => '#48624a',
                'accent'       => '#cbb27a',
                'bg'           => '#fbfaf6',
                'surface'      => '#f1ede2',
                'text'         => '#2c2e2a',
                'text_muted'   => '#7a7d76',
                'border'       => '#dcd9cf',
            ],
            'typography' => [
                'font_heading' => 'Lora',
                'font_body'    => 'Source Sans 3',
                'scale_ratio'  => '1.200',
                'weight_bold'  => '600',
                'letter_spacing_heading' => '-0.005em',
                'label_case'   => 'sentence',
            ],
            'radii' => ['btn' => 10, 'card' => 14],
            'shadow_level' => 'subtle',
            'effects' => ['gradient' => false, 'glass' => false, 'noise' => false],
            'motion' => 'none',
        ],
        [
            'id' => 'institutional-classic',
            'vector' => ['warmth' => 0.20, 'formality' => 0.95, 'modernity' => 0.10, 'energy' => 0.20],
            'palette' => [
                'primary'      => '#1f2a44',
                'primary_dark' => '#0f172a',
                'accent'       => '#9ca3af',
                'bg'           => '#ffffff',
                'surface'      => '#f5f5f7',
                'text'         => '#0a0f1c',
                'text_muted'   => '#475569',
                'border'       => '#d1d5db',
            ],
            'typography' => [
                'font_heading' => 'Merriweather',
                'font_body'    => 'Source Sans 3',
                'scale_ratio'  => '1.250',
                'weight_bold'  => '700',
                'letter_spacing_heading' => '0',
                'label_case'   => 'uppercase',
            ],
            'radii' => ['btn' => 0, 'card' => 2],
            'shadow_level' => 'none',
            'effects' => ['gradient' => false, 'glass' => false, 'noise' => false],
            'motion' => 'none',
        ],
    ];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return self::ANCHORS;
    }

    /** @return array<string,mixed>|null */
    public static function byId(string $id): ?array
    {
        foreach (self::ANCHORS as $a) {
            if ($a['id'] === $id) return $a;
        }
        return null;
    }

    /**
     * Devuelve los N anchors más próximos al vector dado, ordenados por
     * distancia ascendente. La distancia se calcula sobre los 4 ejes skin
     * (warmth, formality, modernity, energy) — los ejes de layout se ignoran
     * aquí porque NO son parte del skin.
     *
     * @param array<string,float> $vector vector con (al menos) los 4 ejes skin
     * @return array<int,array{anchor:array<string,mixed>,distance:float}>
     */
    public static function nearestN(array $vector, int $n = 3): array
    {
        $w = (float) ($vector['warmth']    ?? 0.5);
        $f = (float) ($vector['formality'] ?? 0.5);
        $m = (float) ($vector['modernity'] ?? 0.5);
        $e = (float) ($vector['energy']    ?? 0.5);

        $scored = [];
        foreach (self::ANCHORS as $a) {
            $v = $a['vector'];
            $d = sqrt(
                ($v['warmth']    - $w) ** 2
              + ($v['formality'] - $f) ** 2
              + ($v['modernity'] - $m) ** 2
              + ($v['energy']    - $e) ** 2
            );
            $scored[] = ['anchor' => $a, 'distance' => $d];
        }

        usort($scored, fn ($x, $y) => $x['distance'] <=> $y['distance']);
        return array_slice($scored, 0, max(1, $n));
    }
}
