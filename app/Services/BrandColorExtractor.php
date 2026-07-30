<?php

namespace App\Services;

/**
 * ONB2 O2.4 — Colores dominantes de una imagen de marca (el logo).
 *
 * Sin IA a propósito: cuantizar los píxeles de un logo es determinista,
 * instantáneo y gratis. Un modelo de visión no acertaría más y costaría una
 * llamada por intento — y este botón está pensado para pulsarse varias veces.
 *
 * Devuelve HEX ordenados por presencia, ya filtrados: fuera lo transparente y
 * los blancos/negros casi puros (el fondo y el contorno de casi todo logo no
 * son "el color de la marca"), y fuera los que se parecen demasiado entre sí.
 */
final class BrandColorExtractor
{
    /** Cuadrícula a la que se reduce la imagen antes de contar. */
    private const SAMPLE = 72;

    /** Distancia mínima (RGB euclídea) para considerar dos colores distintos. */
    private const MIN_DISTANCE = 60.0;

    /**
     * @return array<int,string> HEX en minúsculas, como mucho $limit
     */
    public static function fromFile(string $absolutePath, int $limit = 5): array
    {
        if (!is_file($absolutePath)) return [];
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext === 'svg') return self::fromSvg($absolutePath, $limit);
        return self::fromRaster($absolutePath, $limit);
    }

    /**
     * En un SVG no hay píxeles que contar: los colores están escritos en el
     * propio XML (`fill`, `stroke`, `stop-color`, o dentro de un `style`).
     *
     * @return array<int,string>
     */
    private static function fromSvg(string $path, int $limit): array
    {
        $xml = (string) @file_get_contents($path);
        if ($xml === '') return [];

        $named = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000',
                  'blue' => '#0000ff', 'green' => '#008000', 'grey' => '#808080', 'gray' => '#808080'];

        $found = [];
        if (preg_match_all('/(?:fill|stroke|stop-color)\s*[:=]\s*["\']?\s*(#[0-9a-fA-F]{3,6}|rgb\([^)]+\)|[a-z]+)/i', $xml, $m)) {
            foreach ($m[1] as $raw) {
                $hex = self::parseColor(trim($raw), $named);
                if ($hex !== null) $found[] = $hex;
            }
        }

        // Sin píxeles no hay "cuánto" de cada color: el orden es el de aparición,
        // que en un SVG suele ir del elemento de fondo al detalle.
        $counts = array_count_values($found);
        arsort($counts);
        return self::preferChromatic(array_keys($counts), $limit);
    }

    /** @param array<string,string> $named */
    private static function parseColor(string $raw, array $named): ?string
    {
        $raw = strtolower($raw);
        if (isset($named[$raw])) $raw = $named[$raw];
        if (preg_match('/^rgb\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $raw, $m)) {
            $raw = sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $raw, $m)) {
            $raw = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $raw)) return null;
        return self::isNeutralExtreme($raw) ? null : $raw;
    }

    /** @return array<int,string> */
    private static function fromRaster(string $path, int $limit): array
    {
        if (!function_exists('imagecreatefromstring')) return [];
        $data = (string) @file_get_contents($path);
        if ($data === '') return [];
        $img = @imagecreatefromstring($data);
        if (!$img) return [];

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) { imagedestroy($img); return []; }

        $scale = max($w, $h) > self::SAMPLE ? self::SAMPLE / max($w, $h) : 1.0;
        $sw = max(1, (int) round($w * $scale));
        $sh = max(1, (int) round($h * $scale));
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagedestroy($img);

        $counts = [];
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 60) continue;           // casi transparente
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                // Agrupamos en pasos de 24 para que los degradados y el
                // antialiasing no cuenten como colores distintos.
                $key = (intdiv($r, 24) << 16) | (intdiv($g, 24) << 8) | intdiv($b, 24);
                if (!isset($counts[$key])) $counts[$key] = ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                $counts[$key]['n']++;
                $counts[$key]['r'] += $r;
                $counts[$key]['g'] += $g;
                $counts[$key]['b'] += $b;
            }
        }
        imagedestroy($small);
        if ($counts === []) return [];

        uasort($counts, fn(array $a, array $b): int => $b['n'] <=> $a['n']);

        $ordered = [];
        foreach ($counts as $bucket) {
            // El color del grupo es la media real de sus píxeles, no el centro
            // del cubo: así un azul corporativo sale exacto y no redondeado.
            $hex = sprintf(
                '#%02x%02x%02x',
                (int) round($bucket['r'] / $bucket['n']),
                (int) round($bucket['g'] / $bucket['n']),
                (int) round($bucket['b'] / $bucket['n'])
            );
            if (!self::isNeutralExtreme($hex)) $ordered[] = $hex;
        }

        return self::preferChromatic($ordered, $limit);
    }

    /**
     * Un logo en blanco y negro devolvía cinco grises casi iguales, que como
     * "paleta de marca" no dicen nada. Manda el color: si hay tonos, se
     * devuelven solo esos; si el logo es monocromo de verdad, se devuelve UN
     * neutro (el más oscuro, que es la tinta de la marca) y nada más.
     *
     * @param array<int,string> $ordered
     * @return array<int,string>
     */
    private static function preferChromatic(array $ordered, int $limit): array
    {
        $chromatic = array_values(array_filter($ordered, fn(string $hex): bool => !self::isGreyish($hex)));
        if ($chromatic !== []) return self::pickDistinct($chromatic, $limit);

        $neutrals = self::pickDistinct($ordered, 8);
        if ($neutrals === []) return [];
        usort($neutrals, fn(string $a, string $b): int => self::luma($a) <=> self::luma($b));
        return [$neutrals[0]];
    }

    private static function isGreyish(string $hex): bool
    {
        [$r, $g, $b] = self::rgb($hex);
        return (max($r, $g, $b) - min($r, $g, $b)) < 26;
    }

    private static function luma(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Blancos y negros casi puros: son fondo y contorno, no identidad. Un gris
     * medio sí se acepta — hay marcas que lo usan de verdad.
     */
    private static function isNeutralExtreme(string $hex): bool
    {
        [$r, $g, $b] = self::rgb($hex);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $isGrey = ($max - $min) < 18;
        return $isGrey && ($max > 236 || $max < 26);
    }

    /**
     * @param array<int,string> $candidates ya ordenados por importancia
     * @return array<int,string>
     */
    private static function pickDistinct(array $candidates, int $limit): array
    {
        $out = [];
        foreach ($candidates as $hex) {
            $tooClose = false;
            foreach ($out as $kept) {
                if (self::distance($hex, $kept) < self::MIN_DISTANCE) { $tooClose = true; break; }
            }
            if (!$tooClose) $out[] = $hex;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function distance(string $a, string $b): float
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sqrt(($r1 - $r2) ** 2 + ($g1 - $g2) ** 2 + ($b1 - $b2) ** 2);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
