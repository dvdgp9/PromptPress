<?php

declare(strict_types=1);

// STUDIO-UX F5 — Reordenar arrastrando, en una sola escritura.
//
// Con solo ↑/↓ de un paso, llevar la última parte de una página de siete al
// principio eran seis viajes al servidor, seis versiones y seis recargas del
// iframe. `reorderSections()` acepta el orden final completo y lo aplica de una
// vez: un gesto, una versión, una recarga.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;

$failed = 0;
function reorderCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

/** @return string[] */
function reorderIds(?string $html): array
{
    if ($html === null) return [];
    return array_column(CanvasService::listSections($html), 'id');
}

$base = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="services"><h2>Servicios</h2></section>'
    . '<section data-pp-section="faqs"><h2>Preguntas</h2></section>'
    . '<section data-pp-section="cta"><p>Cierre</p></section>';

// --- Reordenar de verdad -----------------------------------------------------
$out = CanvasService::reorderSections($base, ['cta', 'hero', 'faqs', 'services']);
reorderCheck('aplica el orden pedido de una vez', reorderIds($out) === ['cta', 'hero', 'faqs', 'services'], (string) $out);
reorderCheck('no pierde contenido al reordenar',
    str_contains((string) $out, '<h2>Servicios</h2>') && str_contains((string) $out, '<p>Cierre</p>'),
    (string) $out
);

// Llevar la última al principio: lo que antes eran seis movimientos.
$out = CanvasService::reorderSections($base, ['cta', 'hero', 'services', 'faqs']);
reorderCheck('la última pasa a primera en un solo paso', reorderIds($out) === ['cta', 'hero', 'services', 'faqs'], (string) $out);

// El mismo orden es un no-op válido, no un error.
$same = CanvasService::reorderSections($base, ['hero', 'services', 'faqs', 'cta']);
reorderCheck('el mismo orden es no-op válido', reorderIds($same) === ['hero', 'services', 'faqs', 'cta'], (string) $same);

// --- Rechazos: el orden tiene que ser EXACTAMENTE el mismo conjunto ---------
reorderCheck('rechaza una lista incompleta',
    CanvasService::reorderSections($base, ['hero', 'services']) === null);
reorderCheck('rechaza ids desconocidos',
    CanvasService::reorderSections($base, ['hero', 'services', 'faqs', 'fantasma']) === null);
reorderCheck('rechaza duplicados',
    CanvasService::reorderSections($base, ['hero', 'hero', 'services', 'faqs']) === null);
reorderCheck('rechaza una lista vacía',
    CanvasService::reorderSections($base, []) === null);

// --- Los bloques dinámicos siguen siendo texto persistido -------------------
$embeds = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="contacto">{{form:391}}</section>'
    . '<section data-pp-section="recursos">{{resources:featured|limit=2}}</section>';
$out = CanvasService::reorderSections($embeds, ['recursos', 'contacto', 'hero']);
reorderCheck('reordenar no expande ni pierde los placeholders',
    reorderIds($out) === ['recursos', 'contacto', 'hero']
        && str_contains((string) $out, '{{form:391}}')
        && str_contains((string) $out, '{{resources:featured|limit=2}}'),
    (string) $out
);

// --- Contrato de la UI -------------------------------------------------------
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');

reorderCheck('el endpoint acepta la acción de reordenar',
    str_contains($controller, "\$action === 'reorder'") && str_contains($controller, 'reorderSections('));
reorderCheck('las filas de la lista son arrastrables',
    str_contains($js, 'draggable') && str_contains($js, "'dragstart'") && str_contains($js, "'drop'"));
reorderCheck('se guarda UNA vez, al soltar',
    str_contains($js, 'function commitReorder('));
reorderCheck('hay indicación visual de dónde va a caer',
    str_contains($css, '.cvstudio-seclist__item.is-dragging') && str_contains($css, '.cvstudio-seclist__item.is-drop-target'));
reorderCheck('el teclado sigue pudiendo mover sin arrastrar',
    str_contains($js, "structureActionButton('up'") && str_contains($js, "structureActionButton('down'"));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['js.cv.reorder_hint', 'js.cv.sections_reordered'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    reorderCheck('microcopia de reordenar completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
