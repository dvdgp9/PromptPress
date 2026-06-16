<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 1 (S1.2) — Utilidades de color sRGB ↔ CIE LAB.
 *
 * Lab es perceptualmente uniforme: mezclar dos colores en Lab da resultados
 * mucho más "limpios" que en RGB (no aparece el gris sucio del medio).
 *
 * Pipeline:
 *   hex → componentes 0-255 → sRGB normalizado [0,1]
 *       → linear RGB (gamma inversa)
 *       → XYZ (matriz D65)
 *       → Lab (función f y illuminant D65)
 *
 * Y la vuelta. La precisión es suficiente para mezclas de paleta UI; no
 * pretende ser color management profesional.
 *
 * Sin dependencias externas; solo math nativo de PHP.
 */
final class ColorMath
{
    // Reference white D65.
    private const REF_X = 0.95047;
    private const REF_Y = 1.00000;
    private const REF_Z = 1.08883;

    // CIE epsilon y kappa para la función f de Lab.
    private const EPSILON = 0.008856;
    private const KAPPA   = 903.3;

    /**
     * Normaliza un valor hex (#abc, #aabbcc, abc, aabbcc) a #aabbcc.
     * Devuelve null si no parsea.
     */
    public static function normalizeHex(string $hex): ?string
    {
        $h = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $h)) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return null;
        return '#' . strtolower($h);
    }

    /**
     * Hex → [r,g,b] 0-255.
     */
    public static function hexToRgb(string $hex): array
    {
        $n = self::normalizeHex($hex) ?? '#000000';
        $n = substr($n, 1);
        return [
            hexdec(substr($n, 0, 2)),
            hexdec(substr($n, 2, 2)),
            hexdec(substr($n, 4, 2)),
        ];
    }

    /**
     * [r,g,b] 0-255 → hex.
     */
    public static function rgbToHex(array $rgb): string
    {
        $clamp = static fn (float|int $v): int => max(0, min(255, (int) round((float) $v)));
        return sprintf('#%02x%02x%02x', $clamp($rgb[0]), $clamp($rgb[1]), $clamp($rgb[2]));
    }

    /**
     * Hex → LAB [L, a, b].
     */
    public static function hexToLab(string $hex): array
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        // sRGB → linear
        $rl = self::srgbToLinear($r / 255.0);
        $gl = self::srgbToLinear($g / 255.0);
        $bl = self::srgbToLinear($b / 255.0);

        // linear RGB → XYZ (D65)
        $X = 0.4124564 * $rl + 0.3575761 * $gl + 0.1804375 * $bl;
        $Y = 0.2126729 * $rl + 0.7151522 * $gl + 0.0721750 * $bl;
        $Z = 0.0193339 * $rl + 0.1191920 * $gl + 0.9503041 * $bl;

        // XYZ → Lab
        $fx = self::fLab($X / self::REF_X);
        $fy = self::fLab($Y / self::REF_Y);
        $fz = self::fLab($Z / self::REF_Z);

        return [
            116.0 * $fy - 16.0,
            500.0 * ($fx - $fy),
            200.0 * ($fy - $fz),
        ];
    }

    /**
     * LAB [L, a, b] → hex.
     */
    public static function labToHex(array $lab): string
    {
        [$L, $a, $b] = $lab;

        $fy = ($L + 16.0) / 116.0;
        $fx = $a / 500.0 + $fy;
        $fz = $fy - $b / 200.0;

        $X = self::fLabInverse($fx) * self::REF_X;
        $Y = self::fLabInverse($fy) * self::REF_Y;
        $Z = self::fLabInverse($fz) * self::REF_Z;

        // XYZ → linear RGB
        $rl =  3.2404542 * $X - 1.5371385 * $Y - 0.4985314 * $Z;
        $gl = -0.9692660 * $X + 1.8760108 * $Y + 0.0415560 * $Z;
        $bl =  0.0556434 * $X - 0.2040259 * $Y + 1.0572252 * $Z;

        $r = self::linearToSrgb($rl) * 255.0;
        $g = self::linearToSrgb($gl) * 255.0;
        $b = self::linearToSrgb($bl) * 255.0;

        return self::rgbToHex([$r, $g, $b]);
    }

    /**
     * Mezcla N colores hex en Lab según pesos. Los pesos se normalizan.
     *
     * @param array<int,string> $hexColors lista de hex strings
     * @param array<int,float>  $weights   pesos no negativos (se normalizan)
     */
    public static function mixLab(array $hexColors, array $weights): string
    {
        $n = count($hexColors);
        if ($n === 0) return '#000000';
        if ($n !== count($weights)) {
            throw new \InvalidArgumentException('ColorMath::mixLab — número de pesos no coincide.');
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            // Pesos inválidos → usar el primero.
            return self::normalizeHex($hexColors[0]) ?? '#000000';
        }

        $L = 0.0; $a = 0.0; $b = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $w = $weights[$i] / $totalWeight;
            [$Li, $ai, $bi] = self::hexToLab($hexColors[$i]);
            $L += $w * $Li;
            $a += $w * $ai;
            $b += $w * $bi;
        }

        return self::labToHex([$L, $a, $b]);
    }

    /**
     * Distancia euclídea entre dos colores en Lab. Útil para "qué tan distintos
     * son visualmente". Δ < 2 → casi idénticos. Δ ~10 → notable. Δ > 25 → muy distintos.
     */
    public static function deltaE(string $hex1, string $hex2): float
    {
        $a = self::hexToLab($hex1);
        $b = self::hexToLab($hex2);
        return sqrt(
            ($a[0] - $b[0]) ** 2
          + ($a[1] - $b[1]) ** 2
          + ($a[2] - $b[2]) ** 2
        );
    }

    // -------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------

    private static function srgbToLinear(float $c): float
    {
        $c = max(0.0, min(1.0, $c));
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function linearToSrgb(float $c): float
    {
        $c = max(0.0, min(1.0, $c));
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    private static function fLab(float $t): float
    {
        return $t > self::EPSILON
            ? $t ** (1.0 / 3.0)
            : (self::KAPPA * $t + 16.0) / 116.0;
    }

    private static function fLabInverse(float $t): float
    {
        $t3 = $t ** 3;
        return $t3 > self::EPSILON
            ? $t3
            : (116.0 * $t - 16.0) / self::KAPPA;
    }
}
