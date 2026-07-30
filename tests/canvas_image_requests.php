<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasChatService;

$failed = 0;
function check_canvas_image(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$intent = new ReflectionMethod(CanvasChatService::class, 'requestsImages');
$count = new ReflectionMethod(CanvasChatService::class, 'imageCount');

check_canvas_image('detect_images_es', $intent->invoke(null, 'Añade imágenes que no hay') === true);
check_canvas_image('detect_photo_es', $intent->invoke(null, 'Pon una fotografía del equipo') === true);
check_canvas_image('ignore_visual_word', $intent->invoke(null, 'Haz la sección más visual') === false);
// Mención de un elemento que CONTIENE imágenes ≠ petición de imagen (layout).
check_canvas_image('ignore_ref_box_photo', $intent->invoke(null, 'Ponle menos anchura a la caja de foto+texto para que los otros elementos tengan más espacio') === false);
check_canvas_image('ignore_ref_image_block', $intent->invoke(null, 'Hazme el bloque de imágenes más grande') === false);
check_canvas_image('detect_bg_photo', $intent->invoke(null, 'Cambia la estructura por foto de fondo y CTA centrado') === true);
check_canvas_image('ignore_remove_bg', $intent->invoke(null, 'Quita la imagen de fondo') === false);
check_canvas_image('count_img_and_background', $count->invoke(null, '<img src="/a.jpg"><div style="background-image:url(/b.jpg)"></div>') === 2);
check_canvas_image('count_no_images', $count->invoke(null, '<section><h2>Texto</h2></section>') === 0);

// STUDIO-2 C2 — solo se va al banco si el usuario lo pide con esas palabras.
check_canvas_image('bank_explicit_unsplash', CanvasChatService::requestsImageBank('busca una foto en Unsplash de un aula') === true);
check_canvas_image('bank_explicit_stock', CanvasChatService::requestsImageBank('pon una imagen de stock de oficina') === true);
check_canvas_image('bank_explicit_internet', CanvasChatService::requestsImageBank('coge una foto de internet') === true);
check_canvas_image('bank_not_implied', CanvasChatService::requestsImageBank('pon una foto de fondo en el hero') === false);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
