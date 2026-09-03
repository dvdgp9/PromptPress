<?php

declare(strict_types=1);

// STUDIO-UX F10 — Un cambio con IA no puede llevarse por delante lo que el
// usuario tocó a mano mientras tanto.
//
// El chat lee la página al empezar (`CanvasService::get`), tarda entre 7 y 34
// segundos en volver del modelo y ESCRIBE LA PÁGINA ENTERA a partir de aquella
// foto. La edición manual no está bloqueada durante ese rato, así que un retoque
// en otra sección desaparecía en silencio al guardar la IA.
//
// La corrección: al integrar el resultado se relee el estado actual y el cambio
// se aplica sobre él, no sobre la foto vieja.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function raceCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
raceCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'studio-f10-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Studio F10', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();

try {
    $original = '<section data-pp-section="hero"><h1>Hero original</h1></section>'
        . '<section data-pp-section="precios"><h2>Precios originales</h2></section>';
    CanvasService::save($pageId, $original, '.base{color:#000}', 'generate', 'Base F10');

    // t0 — la IA lee la página y se va a trabajar 30 segundos.
    $snapshot = CanvasService::get($pageId);

    // t0+10s — el usuario retoca A MANO otra sección distinta.
    $manual = str_replace('<h2>Precios originales</h2>', '<h2>Precios corregidos a mano</h2>', $original);
    CanvasService::save($pageId, $manual, '.base{color:#000}', 'inline', 'Precios — Edición directa');

    // t0+30s — vuelve la IA con su cambio para la sección "hero".
    $aiSectionHtml = '<section data-pp-section="hero"><h1>Hero reescrito por la IA</h1></section>';
    $integrated = CanvasService::integrateSectionEdit(
        $pageId,
        (string) ($snapshot['html'] ?? ''),
        (string) ($snapshot['css'] ?? ''),
        'hero',
        $aiSectionHtml,
        '.nuevo{color:red}'
    );

    raceCheck('la integración devuelve html y css', is_array($integrated) && isset($integrated['html'], $integrated['css']));
    raceCheck(
        'el cambio de la IA se aplica',
        str_contains((string) ($integrated['html'] ?? ''), 'Hero reescrito por la IA'),
        (string) ($integrated['html'] ?? '')
    );
    raceCheck(
        'y NO se lleva por delante el retoque manual de otra sección',
        str_contains((string) ($integrated['html'] ?? ''), 'Precios corregidos a mano'),
        (string) ($integrated['html'] ?? '')
    );
    raceCheck(
        'el css nuevo se añade sobre el actual, no sobre la foto vieja',
        str_contains((string) ($integrated['css'] ?? ''), '.base{color:#000}')
            && str_contains((string) ($integrated['css'] ?? ''), '.nuevo{color:red}'),
        (string) ($integrated['css'] ?? '')
    );

    // Sin cambios de HTML (cambio solo de estilo): la sección se conserva intacta.
    $onlyCss = CanvasService::integrateSectionEdit(
        $pageId,
        (string) ($snapshot['html'] ?? ''),
        (string) ($snapshot['css'] ?? ''),
        'hero',
        '',
        '.solo-estilo{color:blue}'
    );
    raceCheck(
        'un cambio solo de estilo no reescribe el HTML actual',
        str_contains((string) ($onlyCss['html'] ?? ''), 'Hero original')
            && str_contains((string) ($onlyCss['html'] ?? ''), 'Precios corregidos a mano')
            && str_contains((string) ($onlyCss['css'] ?? ''), '.solo-estilo{color:blue}'),
        (string) ($onlyCss['html'] ?? '')
    );

    // Si la sección desapareció mientras la IA trabajaba, conflicto explícito.
    $withoutHero = CanvasService::deleteSection($manual, 'hero');
    CanvasService::save($pageId, (string) $withoutHero, '.base{color:#000}', 'structure', 'Hero eliminado');
    $gone = CanvasService::integrateSectionEdit(
        $pageId,
        (string) ($snapshot['html'] ?? ''),
        (string) ($snapshot['css'] ?? ''),
        'hero',
        $aiSectionHtml,
        ''
    );
    raceCheck('si la sección ya no existe se avisa en vez de resucitarla', $gone === null);
} finally {
    Database::execute('DELETE FROM page_versions WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

// --- Contratos ---------------------------------------------------------------
$chat = (string) file_get_contents(PP_ROOT . '/app/Services/Canvas/CanvasChatService.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');

raceCheck('el chat integra sobre el estado actual, no sobre su foto inicial',
    str_contains($chat, 'integrateSectionEdit('));
$canvasCtrl = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
raceCheck('un conflicto de sección se contesta como 409, no como fallo genérico',
    str_contains($chat, 'SectionGoneException')
    && str_contains($canvasCtrl, 'SectionGoneException $e')
    && str_contains($canvasCtrl, "__('canvas.err.section_gone')"));

// F7 (mitad superviviente): el selector de modelo sale del composer.
raceCheck('el selector de modelo ya no vive en el composer del chat',
    !str_contains($view, 'id="chat-model"'));
raceCheck('el modelo se elige en Ajustes',
    str_contains($view, 'id="settings-ai-model"'));
raceCheck('el chat sigue pudiendo mandar el modelo elegido',
    str_contains($js, "fd.append('model'"));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['canvas.err.section_gone', 'cv.settings_model'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    raceCheck('microcopia completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
