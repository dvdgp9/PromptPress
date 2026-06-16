<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 1 (S1.4) — Composición del skin de un sitio a partir de su vector.
 *
 * Estrategia:
 *   1. Encontrar los 3 anchors más próximos al vector (en los 4 ejes skin).
 *   2. Si la distancia al #1 es muy pequeña (<0.10) → usar ese anchor tal cual.
 *   3. Si no → mezcla ponderada por inverso de la distancia:
 *      - Paleta: mix en espacio Lab (perceptualmente uniforme).
 *      - Tipografía: si los 3 comparten familia → usar esa;
 *        si no, usar la del anchor más cercano.
 *      - Radii: media aritmética redondeada.
 *      - shadow_level / effects / motion: voto mayoritario ponderado.
 *
 * El resultado se guarda en `sites.skin_json` y lo lee `DesignSystem::renderHead`
 * para emitir el `<style>` público.
 */
final class SkinComposer
{
    /** Umbral por debajo del cual se devuelve el anchor tal cual sin mezclar. */
    private const SNAP_THRESHOLD = 0.10;

    /**
     * @param array<string,float> $vector vector con (al menos) los 4 ejes skin
     * @param array<string,mixed> $anchors Anclas opcionales del usuario que pisan
     *   la salida (S1.15). Claves soportadas:
     *     - 'primary_color'   (hex #RRGGBB) → sustituye palette.primary
     *     - 'typography_pair' (string "Heading / Body") → sustituye typography.font_heading/body
     * @return array<string,mixed> skin_json materializado
     */
    public static function compose(array $vector, array $anchors = []): array
    {
        $near = SkinAnchors::nearestN($vector, 3);

        // Snap: si el anchor más cercano está casi encima, usarlo sin mezcla.
        if ($near[0]['distance'] < self::SNAP_THRESHOLD) {
            $skin = self::materializeAnchor($near[0]['anchor'], [$near[0]['anchor']['id']]);
        } else {
            // Pesos por inverso de distancia (con epsilon para evitar /0 cuando d ≈ 0).
            $anchorList = array_column($near, 'anchor');
            $weights = array_map(static fn ($x) => 1.0 / ($x['distance'] + 0.01), $near);
            $total = array_sum($weights);
            $weights = array_map(static fn ($w) => $w / $total, $weights);

            $skin = self::materializeMix($anchorList, $weights, array_column($anchorList, 'id'));
        }

        // S1.15 — Aplicar anclas del usuario (primary_color, typography_pair).
        // Estas pisan la composición. El resto del skin (paleta secundaria,
        // radios, sombras, motion) se mantiene como lo inferimos.
        return self::applyUserAnchors($skin, $anchors);
    }

    /**
     * Pisa el skin compuesto con las anclas del usuario.
     *
     * @param array<string,mixed> $skin    skin compuesto
     * @param array<string,mixed> $anchors anclas del usuario
     */
    private static function applyUserAnchors(array $skin, array $anchors): array
    {
        $applied = [];

        $primary = trim((string) ($anchors['primary_color'] ?? ''));
        if ($primary !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
            $primary = '#' . strtolower(substr($primary, 1));
            $skin['palette']['primary'] = $primary;
            // primary_dark se deriva: oscurecer el primary mezclándolo con negro.
            // 70% primary + 30% negro en LAB.
            $skin['palette']['primary_dark'] = ColorMath::mixLab([$primary, '#000000'], [0.7, 0.3]);
            $applied[] = 'primary_color';
        }

        $pair = trim((string) ($anchors['typography_pair'] ?? ''));
        if ($pair !== '' && str_contains($pair, '/')) {
            [$heading, $body] = array_map('trim', explode('/', $pair, 2));
            if ($heading !== '') {
                $skin['typography']['font_heading'] = $heading;
                $applied[] = 'font_heading';
            }
            if ($body !== '') {
                $skin['typography']['font_body'] = $body;
                $applied[] = 'font_body';
            }
        }

        if ($applied !== []) {
            $skin['_meta']['user_anchors'] = $applied;
        }
        return $skin;
    }

    // -----------------------------------------------------------------
    // Materialización
    // -----------------------------------------------------------------

    /**
     * Devuelve el anchor tal cual, enriquecido con metadatos de composición.
     *
     * @param array<int,string> $sourceIds
     */
    private static function materializeAnchor(array $anchor, array $sourceIds): array
    {
        return [
            'palette'      => $anchor['palette'],
            'typography'   => $anchor['typography'],
            'radii'        => $anchor['radii'],
            'shadow_level' => $anchor['shadow_level'],
            'effects'      => $anchor['effects'],
            'motion'       => $anchor['motion'],
            '_meta'        => [
                'mode'       => 'snap',
                'anchor_id'  => $anchor['id'],
                'sources'    => $sourceIds,
                'composed_at'=> date('c'),
            ],
        ];
    }

    /**
     * Mezcla los N anchors según los pesos dados (ya normalizados).
     *
     * @param array<int,array<string,mixed>> $anchors
     * @param array<int,float>               $weights normalizados, sum = 1
     * @param array<int,string>              $sourceIds para el _meta
     */
    private static function materializeMix(array $anchors, array $weights, array $sourceIds): array
    {
        return [
            'palette'      => self::mixPalette($anchors, $weights),
            'typography'   => self::mixTypography($anchors, $weights),
            'radii'        => self::mixRadii($anchors, $weights),
            'shadow_level' => self::weightedMajority($anchors, $weights, fn ($a) => $a['shadow_level']),
            'effects'      => [
                'gradient' => self::weightedMajorityBool($anchors, $weights, fn ($a) => $a['effects']['gradient']),
                'glass'    => self::weightedMajorityBool($anchors, $weights, fn ($a) => $a['effects']['glass']),
                'noise'    => self::weightedMajorityBool($anchors, $weights, fn ($a) => $a['effects']['noise']),
            ],
            'motion'       => self::weightedMajority($anchors, $weights, fn ($a) => $a['motion']),
            '_meta'        => [
                'mode'       => 'interpolated',
                'sources'    => $sourceIds,
                'weights'    => array_map(static fn ($w) => round($w, 4), $weights),
                'composed_at'=> date('c'),
            ],
        ];
    }

    /**
     * Mezcla la paleta. Distinguimos dos clases de tokens:
     *   - **Colores de marca** (primary, primary_dark, accent): se mezclan en
     *     LAB para que la interpolación entre anchors produzca un color
     *     intermedio coherente.
     *   - **Tokens de superficie** (bg, surface, text, text_muted, border):
     *     son categóricos (light vs dark). Mezclarlos en LAB entre un anchor
     *     light (#ffffff) y uno dark (#0b0a14) produce gris muddy. Solución:
     *     usar el valor del anchor con mayor peso (snap). Bonus: los nudges
     *     se vuelven más visibles al cruzar el umbral entre anchors.
     */
    private static function mixPalette(array $anchors, array $weights): array
    {
        $brandKeys   = ['primary', 'primary_dark', 'accent'];
        $surfaceKeys = ['bg', 'surface', 'text', 'text_muted', 'border'];

        $out = [];
        foreach ($brandKeys as $k) {
            $colors = array_map(static fn ($a) => $a['palette'][$k], $anchors);
            $out[$k] = ColorMath::mixLab($colors, $weights);
        }

        // Snap: tomar los tokens de superficie del anchor con mayor peso.
        $idxMax = array_keys($weights, max($weights))[0];
        foreach ($surfaceKeys as $k) {
            $out[$k] = $anchors[$idxMax]['palette'][$k];
        }
        return $out;
    }

    /**
     * Tipografía: consenso → si los 3 comparten valor, usar ese. Si no, usar
     * el del anchor más cercano (peso máximo).
     */
    private static function mixTypography(array $anchors, array $weights): array
    {
        $keys = ['font_heading', 'font_body', 'scale_ratio', 'weight_bold', 'letter_spacing_heading', 'label_case'];
        $out = [];
        foreach ($keys as $k) {
            $values = array_map(static fn ($a) => (string) $a['typography'][$k], $anchors);
            $unique = array_values(array_unique($values));
            if (count($unique) === 1) {
                $out[$k] = $unique[0];
            } else {
                // Mayor peso gana.
                $idxMax = array_keys($weights, max($weights))[0];
                $out[$k] = $values[$idxMax];
            }
        }
        return $out;
    }

    private static function mixRadii(array $anchors, array $weights): array
    {
        $btn  = 0.0; $card = 0.0;
        foreach ($anchors as $i => $a) {
            $btn  += $weights[$i] * (float) $a['radii']['btn'];
            $card += $weights[$i] * (float) $a['radii']['card'];
        }
        return [
            'btn'  => (int) round($btn),
            'card' => (int) round($card),
        ];
    }

    /**
     * Voto mayoritario ponderado: cada valor recibe la suma de los pesos que lo
     * propone. El de suma máxima gana. Para strings.
     *
     * @param callable(array):string $getter
     */
    private static function weightedMajority(array $anchors, array $weights, callable $getter): string
    {
        $score = [];
        foreach ($anchors as $i => $a) {
            $v = $getter($a);
            $score[$v] = ($score[$v] ?? 0.0) + $weights[$i];
        }
        arsort($score);
        return (string) array_key_first($score);
    }

    /**
     * Voto mayoritario ponderado para booleans: suma pesos de los `true`; si
     * supera 0.5 gana true.
     *
     * @param callable(array):bool $getter
     */
    private static function weightedMajorityBool(array $anchors, array $weights, callable $getter): bool
    {
        $sumTrue = 0.0;
        foreach ($anchors as $i => $a) {
            if ($getter($a)) $sumTrue += $weights[$i];
        }
        return $sumTrue > 0.5;
    }
}
