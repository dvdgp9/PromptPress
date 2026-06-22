<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\CanvasController;

$failed = 0;
function check_canvas_image(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$intent = new ReflectionMethod(CanvasController::class, 'requestsImages');
$count = new ReflectionMethod(CanvasController::class, 'imageCount');

check_canvas_image('detect_images_es', $intent->invoke(null, 'Añade imágenes que no hay') === true);
check_canvas_image('detect_photo_es', $intent->invoke(null, 'Pon una fotografía del equipo') === true);
check_canvas_image('ignore_visual_word', $intent->invoke(null, 'Haz la sección más visual') === false);
check_canvas_image('count_img_and_background', $count->invoke(null, '<img src="/a.jpg"><div style="background-image:url(/b.jpg)"></div>') === 2);
check_canvas_image('count_no_images', $count->invoke(null, '<section><h2>Texto</h2></section>') === 0);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
