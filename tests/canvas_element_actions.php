<?php

declare(strict_types=1);

// STUDIO-UX F2 — Duplicar, mover y eliminar un ELEMENTO dentro de su sección
// sin pasar por la IA. El overlay vive dentro del controlador (JS en PHP), así
// que aquí se fija el contrato: que las operaciones existan con sus guardas,
// que el panel las ofrezca, que la microcopia esté en los cuatro idiomas y —lo
// que de verdad puede romperse— que el HTML resultante sobreviva al guardado.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;

$failed = 0;
function elActionCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');

// --- Contrato del overlay ----------------------------------------------------
elActionCheck('el overlay implementa las tres operaciones',
    str_contains($controller, "msg.op === 'el-duplicate'")
    && str_contains($controller, "msg.op === 'el-delete'")
    && str_contains($controller, "msg.op === 'el-move'")
);
elActionCheck('las operaciones se desvían antes que las de estilo',
    str_contains($controller, "if(msg.op === 'el-duplicate' || msg.op === 'el-delete' || msg.op === 'el-move'){ restructure(msg); return; }")
);
elActionCheck('una sección top-level no se reestructura desde aquí (es F1)',
    str_contains($controller, "if(el.hasAttribute('data-pp-section')) return false;")
);
elActionCheck('los embeds quedan fuera: su HTML se regenera al render',
    str_contains($controller, 'function canRestructure(') && str_contains($controller, 'if(inEmbed(el)) return false;')
);
elActionCheck('el clon no arrastra ids duplicados', str_contains($controller, 'function stripIds('));
elActionCheck('eliminar no deja el contenedor vacío',
    str_contains($controller, 'if(sibs.length < 2) return true;')
);
elActionCheck('el marcador visual se mueve al clon, no se copia',
    str_contains($controller, "['data-pp-edit-box','data-pp-img-edit'].forEach(function(attr){")
    && str_contains($controller, 'el.removeAttribute(attr); copy.setAttribute(attr,')
);
elActionCheck('la selección informa de qué puede hacerse con el elemento',
    str_contains($controller, 'canPrev:') && str_contains($controller, 'canNext:') && str_contains($controller, 'canDelete:')
);

// --- Contrato del panel ------------------------------------------------------
elActionCheck('el panel pinta la fila de acciones del elemento',
    str_contains($js, 'function elActionsRow(') && str_contains($js, 'elActionsRow(d.structure)')
);
elActionCheck('el panel conecta las acciones al overlay',
    str_contains($js, "panel.querySelectorAll('[data-elop]')") && str_contains($js, 'applyOp(btn.dataset.elop')
);
// P6 — estas acciones NO pueden pintar "Guardado" antes de que el servidor lo
// confirme: el aviso lo da saveSectionInline.
elActionCheck('las acciones estructurales no anuncian guardado a ciegas',
    !preg_match("/applyOp\(btn\.dataset\.elop[^;]*;\s*showSaved\(/", $js)
);
elActionCheck('CSS cubre la fila de acciones y su estado deshabilitado',
    str_contains($css, '.cvstudio-panel__structure')
    && str_contains($css, '.cvstudio-elaction:disabled')
);

// --- Microcopia en los cuatro idiomas ---------------------------------------
$requiredKeys = [
    'js.cv.element_actions', 'js.cv.duplicate_element',
    'js.cv.move_element_prev', 'js.cv.move_element_next', 'js.cv.delete_element',
];
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter($requiredKeys, static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''));
    elActionCheck('microcopia de elemento completa en ' . $lang, $missing === [], implode(', ', $missing));
}

// --- Lo que de verdad puede romperse: el guardado --------------------------
// El overlay duplica en el DOM y manda la sección entera; si el saneado se
// comiera la copia, el usuario vería el cambio y lo perdería al recargar.
$sectionHtml = '<section data-pp-section="services"><div class="grid">'
    . '<article class="card"><h3>Oposiciones</h3><p>Una</p></article>'
    . '<article class="card"><h3>Idiomas</h3><p>Dos</p></article>'
    . '</div></section>';
$page = '<section data-pp-section="hero"><h1>Hero</h1></section>' . $sectionHtml;

$duplicated = str_replace(
    '<article class="card"><h3>Oposiciones</h3><p>Una</p></article>',
    '<article class="card"><h3>Oposiciones</h3><p>Una</p></article><article class="card"><h3>Oposiciones</h3><p>Una</p></article>',
    $sectionHtml
);
$clean = CanvasService::normalizeEditedSectionHtml($duplicated);
$saved = CanvasService::replaceSection($page, 'services', $clean);
elActionCheck(
    'la copia sobrevive al saneado y al reemplazo de sección',
    $saved !== null && substr_count((string) $saved, '<h3>Oposiciones</h3>') === 2,
    (string) $saved
);
elActionCheck(
    'duplicar un elemento no toca las demás secciones',
    array_column(CanvasService::listSections((string) $saved), 'id') === ['hero', 'services'],
    (string) $saved
);

// Eliminar un elemento es el camino inverso por la misma vía.
$removed = CanvasService::replaceSection(
    (string) $saved,
    'services',
    CanvasService::normalizeEditedSectionHtml($sectionHtml)
);
elActionCheck(
    'eliminar un elemento vuelve al estado anterior',
    $removed !== null && substr_count((string) $removed, '<h3>Oposiciones</h3>') === 1,
    (string) $removed
);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
