<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\CanvasController;

$method = new ReflectionMethod(CanvasController::class, 'overlayScript');
$script = (string) $method->invoke(null);
$checks = [
    'visual_box_detection' => str_contains($script, 'function visualBoxFrom(el)'),
    'box_selection' => str_contains($script, "reportSelection(box)"),
    'corner_operation' => str_contains($script, "msg.op === 'corner-radius'"),
    'temporary_marker_cleaned' => str_contains($script, "removeAttribute('data-pp-edit-box')"),
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL; if (!$ok) $failed++; }
exit($failed === 0 ? 0 : 1);
