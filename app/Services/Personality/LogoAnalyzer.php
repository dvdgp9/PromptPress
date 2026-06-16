<?php

declare(strict_types=1);

namespace App\Services\Personality;

/**
 * D-Slice 4 (S4.2) — Análisis pixel-level del logo del sitio.
 *
 * Extrae señales cromáticas sin dependencias externas (solo GD):
 *   - Paleta dominante (top 3 colores tras ignorar fondo neutro).
 *   - Saturación media ponderada por luminancia.
 *   - Temperatura cromática del hue dominante (cálido/frío).
 *   - Aspect ratio del bounding box (proxy débil para wordmark vs símbolo).
 *
 * Las contribuciones a los 4 ejes skin se calculan según D0g:
 *   - saturation > 0.70 → energy +0.20, modernity +0.10
 *   - saturation < 0.30 → energy -0.15
 *   - hue ∈ cálido → warmth +0.20
 *   - hue ∈ frío → warmth -0.20
 *   - aspect_ratio > 2.5 (wordmark dominante) → señal débil de formality
 *
 * Peso en PersonalityInference: 0.20 cuando hay logo.
 *
 * SVG: no se rasteriza (sin Imagick no podemos). Se devuelve `supported=false`
 * y el extractor cae en silencio (peso 0).
 */
final class LogoAnalyzer
{
    /** Lado del lienzo para muestrear (100×100 es suficiente para color). */
    private const SAMPLE_SIZE = 100;

    /**
     * Analiza un logo y devuelve { palette, dominant_hue, saturation, aspect_ratio, signals }.
     * Devuelve null si no se puede procesar (formato no soportado, archivo inexistente, etc.).
     *
     * @param string $absolutePath ruta absoluta al archivo del logo
     * @return array{palette:array<int,string>,dominant_hue:int,saturation:float,aspect_ratio:float,signals:array<string,float>}|null
     */
    public static function analyze(string $absolutePath): ?array
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }
        if (!extension_loaded('gd')) {
            return null;
        }
        $info = @getimagesize($absolutePath);
        if (!$info) return null;
        [$origW, $origH, $type] = [$info[0], $info[1], $info[2]];
        $aspectRatio = $origH > 0 ? $origW / $origH : 1.0;

        $img = match ($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($absolutePath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($absolutePath),
            default        => false, // SVG, BMP, etc. → no soportado en este slice
        };
        if (!$img) return null;

        // Reescalar a SAMPLE_SIZE × SAMPLE_SIZE (ratio aproximado, no exacto).
        $sampleW = self::SAMPLE_SIZE;
        $sampleH = self::SAMPLE_SIZE;
        $sample = imagecreatetruecolor($sampleW, $sampleH);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        $transparent = imagecolorallocatealpha($sample, 0, 0, 0, 127);
        imagefilledrectangle($sample, 0, 0, $sampleW, $sampleH, $transparent);
        imagealphablending($sample, true);
        imagecopyresampled($sample, $img, 0, 0, 0, 0, $sampleW, $sampleH, $origW, $origH);
        imagedestroy($img);

        // Histograma de hue (bins de 30°) + acumulado de saturación.
        $hueBins = array_fill(0, 12, 0.0); // 12 bins x 30°
        $sumSat = 0.0;
        $sumLum = 0.0;
        $pixelsCounted = 0;
        $colorHist = []; // hex → count

        for ($y = 0; $y < $sampleH; $y++) {
            for ($x = 0; $x < $sampleW; $x++) {
                $rgba = imagecolorat($sample, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha > 60) continue; // muy transparente → ignorar (fondo)

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8)  & 0xFF;
                $b = ($rgba)       & 0xFF;

                // HSL
                [$h, $s, $l] = self::rgbToHsl($r, $g, $b);

                // Ignorar casi blanco/negro puros (fondo neutro probable).
                if ($l > 0.95 || $l < 0.05) continue;
                // Ignorar grises muy desaturados (no aportan info cromática).
                if ($s < 0.10) continue;

                $bin = (int) floor($h / 30) % 12;
                $weight = 0.5 + $s * 0.5; // saturados pesan más
                $hueBins[$bin] += $weight;
                $sumSat += $s * $weight;
                $sumLum += $weight;
                $pixelsCounted++;

                // Histograma de color cuantizado (32 buckets por canal).
                $hex = sprintf('#%02x%02x%02x', $r & 0xE0, $g & 0xE0, $b & 0xE0);
                $colorHist[$hex] = ($colorHist[$hex] ?? 0) + 1;
            }
        }
        imagedestroy($sample);

        // Caso "logo apagado": grises puros o blanco/negro. Tratamos saturación
        // como 0 (no hay color) pero seguimos evaluando aspect_ratio.
        if ($pixelsCounted === 0) {
            $signals = ['energy' => 0.5 - 0.15];   // gris/monocromo → baja energía
            if ($aspectRatio > 2.5) $signals['formality'] = 0.5 + 0.10;
            foreach ($signals as $k => $v) $signals[$k] = max(0.0, min(1.0, $v));
            return [
                'palette'      => [],
                'dominant_hue' => -1,
                'saturation'   => 0.0,
                'aspect_ratio' => round($aspectRatio, 3),
                'signals'      => $signals,
            ];
        }

        $dominantBin = (int) array_search(max($hueBins), $hueBins);
        $dominantHue = $dominantBin * 30 + 15; // centro del bin
        $avgSat = $sumLum > 0 ? $sumSat / $sumLum : 0.0;

        // Top 3 colores del histograma cuantizado.
        arsort($colorHist);
        $palette = array_slice(array_keys($colorHist), 0, 3);

        // Mapeo a señales por eje (D0g).
        $signals = [];

        // Saturación.
        if ($avgSat > 0.70) {
            $signals['energy']    = ($signals['energy']    ?? 0.5) + 0.20;
            $signals['modernity'] = ($signals['modernity'] ?? 0.5) + 0.10;
        } elseif ($avgSat < 0.30) {
            $signals['energy'] = ($signals['energy'] ?? 0.5) - 0.15;
        }

        // Temperatura cromática.
        // Cálido: 0-60° (rojo-naranja-amarillo) o 300-360° (rosa-magenta-rojo).
        // Frío: 180-240° (cian-azul).
        $isWarm = ($dominantHue <= 60) || ($dominantHue >= 300);
        $isCool = ($dominantHue >= 180 && $dominantHue <= 240);
        if ($isWarm) $signals['warmth'] = ($signals['warmth'] ?? 0.5) + 0.20;
        elseif ($isCool) $signals['warmth'] = ($signals['warmth'] ?? 0.5) - 0.20;

        // Aspect ratio: wordmark muy ancho → sesgo débil de formality
        // (asume tipo serio/corporativo). NO inverso para símbolos.
        if ($aspectRatio > 2.5) {
            $signals['formality'] = ($signals['formality'] ?? 0.5) + 0.10;
        }

        // Clamp [0, 1] todas las señales.
        foreach ($signals as $k => $v) {
            $signals[$k] = max(0.0, min(1.0, $v));
        }

        return [
            'palette'      => $palette,
            'dominant_hue' => $dominantHue,
            'saturation'   => round($avgSat, 3),
            'aspect_ratio' => round($aspectRatio, 3),
            'signals'      => $signals,
        ];
    }

    /**
     * RGB (0-255) → HSL (h:0-360, s:0-1, l:0-1).
     */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $rn = $r / 255.0;
        $gn = $g / 255.0;
        $bn = $b / 255.0;
        $max = max($rn, $gn, $bn);
        $min = min($rn, $gn, $bn);
        $l = ($max + $min) / 2.0;
        $h = 0.0; $s = 0.0;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2.0 - $max - $min) : $d / ($max + $min);
            if ($max === $rn)      $h = (($gn - $bn) / $d) + ($gn < $bn ? 6.0 : 0.0);
            elseif ($max === $gn)  $h = (($bn - $rn) / $d) + 2.0;
            else                   $h = (($rn - $gn) / $d) + 4.0;
            $h *= 60.0;
        }
        return [$h, $s, $l];
    }
}
