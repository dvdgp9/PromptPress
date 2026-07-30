<?php

declare(strict_types=1);

/**
 * Repara el HTML de las páginas Canvas que quedó con el andamiaje de `pp-ux.js`
 * incrustado (track, flechas, `data-pp-ux-ready`, clases `pp-ux-*`).
 *
 * Esas páginas guardaron el DOM VIVO desde el Studio, así que al recargarlas el
 * carrusel salía sin listeners: tira horizontal congelada y flechas muertas.
 *
 * Uso:
 *   php scripts/repair_canvas_runtime_markup.php --dry-run   (solo informa)
 *   php scripts/repair_canvas_runtime_markup.php             (aplica)
 *
 * Es idempotente: pasarlo dos veces no cambia nada la segunda vez.
 */

require_once dirname(__DIR__) . '/config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$dryRun = in_array('--dry-run', $argv, true);

$rows = Database::select('SELECT page_id, html FROM page_canvas ORDER BY page_id ASC');
$touched = 0;
$checked = 0;

foreach ($rows as $row) {
    $checked++;
    $pageId = (int) $row['page_id'];
    $html = (string) $row['html'];
    if (!str_contains($html, 'pp-ux-')) continue;

    $clean = CanvasService::cleanRuntimeBehaviorMarkup($html);
    if ($clean === $html || trim($clean) === '') continue;

    $removed = [];
    foreach ([
        'flechas'         => 'pp-ux-slider__arrow',
        'track'           => 'pp-ux-slider__track',
        'marca de inicio' => 'data-pp-ux-ready',
        'clases runtime'  => 'pp-ux-',
    ] as $label => $needle) {
        $before = substr_count($html, $needle);
        $after  = substr_count($clean, $needle);
        if ($before > $after) $removed[] = $label . ' (' . ($before - $after) . ')';
    }

    echo 'página ' . $pageId . ': ' . ($removed === [] ? 'limpieza menor' : implode(', ', $removed)) . PHP_EOL;
    $touched++;

    if (!$dryRun) {
        Database::execute('UPDATE page_canvas SET html = ? WHERE page_id = ?', [$clean, $pageId]);
    }
}

echo PHP_EOL;
echo 'Páginas revisadas: ' . $checked . PHP_EOL;
echo ($dryRun ? 'Se repararían: ' : 'Reparadas: ') . $touched . PHP_EOL;
if ($dryRun && $touched > 0) {
    echo 'Vuelve a lanzarlo sin --dry-run para aplicarlo.' . PHP_EOL;
}
if (!$dryRun && $touched > 0) {
    echo 'Recuerda vaciar la caché del sitio si la tienes activa.' . PHP_EOL;
}
