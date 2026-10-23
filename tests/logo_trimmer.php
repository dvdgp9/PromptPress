<?php

declare(strict_types=1);

/**
 * LOGO-TRIM — Recorte del margen vacío de un logo al subirlo.
 *
 * Genera imágenes en un directorio temporal (no toca BD ni storage): lienzo
 * transparente con el dibujo en el centro, JPG con fondo blanco, PNG de paleta,
 * imagen sin margen y una "foto" sin fondo liso, que no se debe tocar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\LogoTrimmer;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '     ' . $detail . PHP_EOL;
    }
}

if (!function_exists('imagecreatetruecolor')) {
    echo "SKIP sin GD\n";
    exit(0);
}

$dir = sys_get_temp_dir() . '/pp-logo-trim-' . bin2hex(random_bytes(4));
mkdir($dir);

/** Lienzo $w×$h con un rectángulo de dibujo en [$x0,$y0]-[$x1,$y1]. */
function canvas(int $w, int $h, ?array $bg, array $box): \GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $fill = $bg === null ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, ...$bg);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $fill);
    imagefilledrectangle($im, $box[0], $box[1], $box[2], $box[3], imagecolorallocate($im, 30, 50, 70));
    return $im;
}

try {
    // 1. Como el logo real del cliente: 596×596 con el dibujo en 430×269.
    $png = $dir . '/transparent.png';
    imagepng(canvas(596, 596, null, [83, 163, 512, 431]), $png);
    check('PNG transparente: se recorta', LogoTrimmer::trim($png, 'image/png'));
    [$w, $h] = getimagesize($png);
    // 430×269 más un 2 % del lado mayor (9 px) por cada lado.
    check('PNG transparente: queda el dibujo con un poco de aire', $w === 448 && $h === 287, "{$w}×{$h}");
    $im = imagecreatefrompng($png);
    check('PNG transparente: sigue siendo transparente', ((imagecolorat($im, 0, 0) >> 24) & 0x7F) === 127);

    // 2. JPG con fondo blanco.
    $jpg = $dir . '/white.jpg';
    imagejpeg(canvas(800, 400, [255, 255, 255], [300, 150, 499, 249]), $jpg, 95);
    check('JPG con fondo blanco: se recorta', LogoTrimmer::trim($jpg, 'image/jpeg'));
    [$w, $h] = getimagesize($jpg);
    check('JPG con fondo blanco: tamaño cercano al dibujo', abs($w - 208) <= 4 && abs($h - 108) <= 4, "{$w}×{$h}");

    // 3. PNG de paleta (imagecolorat devuelve índices si no se convierte).
    $pal = $dir . '/palette.png';
    $tc = canvas(400, 400, null, [150, 150, 249, 249]);
    imagetruecolortopalette($tc, false, 16);
    imagepng($tc, $pal);
    check('PNG de paleta: se recorta', LogoTrimmer::trim($pal, 'image/png'));
    [$w, $h] = getimagesize($pal);
    check('PNG de paleta: tamaño del dibujo', abs($w - 104) <= 4 && abs($h - 104) <= 4, "{$w}×{$h}");

    // 4. Sin margen: no se reescribe.
    $full = $dir . '/full.png';
    imagepng(canvas(300, 100, null, [0, 0, 299, 99]), $full);
    $before = md5_file($full);
    check('sin margen: no se toca', !LogoTrimmer::trim($full, 'image/png') && md5_file($full) === $before);

    // 5. "Foto": esquinas de colores distintos, no hay fondo liso que quitar.
    $photo = $dir . '/photo.png';
    $im = canvas(300, 300, [255, 255, 255], [100, 100, 199, 199]);
    imagesetpixel($im, 299, 299, imagecolorallocate($im, 200, 0, 0));
    imagepng($im, $photo);
    $before = md5_file($photo);
    check('esquinas distintas: no se toca', !LogoTrimmer::trim($photo, 'image/png') && md5_file($photo) === $before);

    // 6. Totalmente vacía y archivo que no es imagen: no rompe.
    $empty = $dir . '/empty.png';
    $im = imagecreatetruecolor(50, 50);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagepng($im, $empty);
    check('imagen vacía: no se toca', !LogoTrimmer::trim($empty, 'image/png'));
    $junk = $dir . '/junk.png';
    file_put_contents($junk, 'no soy una imagen');
    check('archivo corrupto: no rompe', !LogoTrimmer::trim($junk, 'image/png'));
    check('MIME no soportado: no hace nada', !LogoTrimmer::trim($png, 'image/svg+xml'));

    // 7. Imagen grande: se muestrea y sigue recortando bien.
    $big = $dir . '/big.png';
    imagepng(canvas(3000, 3000, null, [1000, 1200, 1999, 1799]), $big);
    $t = microtime(true);
    check('imagen grande: se recorta', LogoTrimmer::trim($big, 'image/png'));
    $ms = (microtime(true) - $t) * 1000;
    [$w, $h] = getimagesize($big);
    check('imagen grande: tamaño cercano al dibujo', abs($w - 1040) <= 8 && abs($h - 640) <= 8, "{$w}×{$h}");
    check('imagen grande: tarda poco', $ms < 3000, round($ms) . ' ms');
} finally {
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLO(S)\n";
exit($failed === 0 ? 0 : 1);
