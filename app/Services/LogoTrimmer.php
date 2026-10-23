<?php

declare(strict_types=1);

namespace App\Services;

/**
 * LOGO-TRIM — Recorta el margen vacío alrededor de un logo al subirlo.
 *
 * Muchos logos llegan exportados en un lienzo cuadrado con el dibujo en el
 * centro (p. ej. 596×596 con la marca ocupando 430×269). La cabecera, el pie y
 * la portada de construcción limitan el logo por ALTO, así que ese margen
 * transparente cuenta como logo y el dibujo se ve a menos de la mitad.
 *
 * Qué cuenta como "vacío": píxeles casi transparentes si la imagen tiene
 * canal alfa; si no (JPG, PNG opaco), los del color de las cuatro esquinas,
 * siempre que coincidan, porque eso es un fondo liso. Si las esquinas no
 * coinciden no hay fondo claro que quitar y la imagen se deja tal cual.
 *
 * Sin GD, o ante cualquier fallo, el archivo se queda como estaba: un logo
 * con margen es mejor que ningún logo.
 */
final class LogoTrimmer
{
    /** Alfa de GD (0 opaco … 127 transparente) a partir del cual es vacío. */
    private const ALPHA_EMPTY = 120;

    /** Distancia de color (suma RGB) para considerar un píxel "del fondo". */
    private const COLOR_TOLERANCE = 30;

    /** Aire que se deja alrededor del dibujo, en fracción del lado mayor. */
    private const PADDING = 0.02;

    /** Lado máximo que se analiza píxel a píxel; por encima se muestrea. */
    private const SCAN_MAX = 1200;

    /**
     * Recorta `$path` en el sitio. Devuelve true si lo ha recortado.
     */
    public static function trim(string $path, string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor') || !is_file($path)) return false;

        $img = match ($mime) {
            'image/png'  => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        if (!$img instanceof \GdImage) return false;
        // Un PNG de paleta devuelve índices en `imagecolorat`, no colores.
        if (!imageistruecolor($img)) imagepalettetotruecolor($img);

        try {
            $box = self::contentBox($img);
            if ($box === null) return false;

            $w = imagesx($img);
            $h = imagesy($img);
            [$x0, $y0, $x1, $y1] = $box;
            $pad = (int) ceil(max($x1 - $x0, $y1 - $y0) * self::PADDING);
            $x0 = max(0, $x0 - $pad);
            $y0 = max(0, $y0 - $pad);
            $x1 = min($w - 1, $x1 + $pad);
            $y1 = min($h - 1, $y1 + $pad);
            $cw = $x1 - $x0 + 1;
            $ch = $y1 - $y0 + 1;

            // Casi nada que quitar: no merece reescribir (y recomprimir) el archivo.
            if ($cw * $ch >= 0.95 * $w * $h) return false;

            $out = imagecreatetruecolor($cw, $ch);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopy($out, $img, 0, 0, $x0, $y0, $cw, $ch);

            $ok = match ($mime) {
                'image/png'  => imagepng($out, $path, 9),
                'image/jpeg' => imagejpeg($out, $path, 92),
                'image/webp' => function_exists('imagewebp') && imagewebp($out, $path, 92),
                default      => false,
            };
            return (bool) $ok;
        } catch (\Throwable $e) {
            error_log('[logo trim] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Caja [x0, y0, x1, y1] con contenido, o null si no hay fondo que quitar
     * (o la imagen está vacía).
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public static function contentBox(\GdImage $img): ?array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 2 || $h < 2) return null;

        $corners = [
            imagecolorat($img, 0, 0), imagecolorat($img, $w - 1, 0),
            imagecolorat($img, 0, $h - 1), imagecolorat($img, $w - 1, $h - 1),
        ];
        $transparent = true;
        foreach ($corners as $c) {
            if ((($c >> 24) & 0x7F) < self::ALPHA_EMPTY) $transparent = false;
        }
        $bg = $corners[0];
        if (!$transparent) {
            foreach ($corners as $c) {
                if (self::distance($c, $bg) > self::COLOR_TOLERANCE) return null;
            }
        }

        $isEmpty = static function (int $c) use ($transparent, $bg): bool {
            if ((($c >> 24) & 0x7F) >= self::ALPHA_EMPTY) return true;
            return !$transparent && self::distance($c, $bg) <= self::COLOR_TOLERANCE;
        };

        // En imágenes grandes basta una rejilla: el recorte pierde como mucho
        // `$step` píxeles de precisión, que el margen de aire ya cubre.
        $step = max(1, (int) ceil(max($w, $h) / self::SCAN_MAX));
        $x0 = $w; $y0 = $h; $x1 = -1; $y1 = -1;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if ($isEmpty(imagecolorat($img, $x, $y))) continue;
                if ($x < $x0) $x0 = $x;
                if ($x > $x1) $x1 = $x;
                if ($y < $y0) $y0 = $y;
                if ($y > $y1) $y1 = $y;
            }
        }
        if ($x1 < 0) return null;

        return [
            max(0, $x0 - $step + 1), max(0, $y0 - $step + 1),
            min($w - 1, $x1 + $step - 1), min($h - 1, $y1 + $step - 1),
        ];
    }

    private static function distance(int $a, int $b): int
    {
        return abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF))
             + abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF))
             + abs(($a & 0xFF) - ($b & 0xFF));
    }
}
