<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S3 — Contrato de integración de la barra lateral.
// El comportamiento real se completa con QA en navegador; aquí fijamos que las
// piezas críticas no desaparezcan por una refactorización posterior.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function structureUiCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');

structureUiCheck('Studio publica endpoint estructural', str_contains($view, 'data-structure-url='));
structureUiCheck('lista estructural persiste fuera del estado vacío',
    strpos($view, 'id="side-sections"') < strpos($view, 'id="side-empty"')
    && strpos($view, 'id="side-sections"') > strpos($view, 'id="edit-panel"')
);
structureUiCheck('hay región viva de estado estructural',
    str_contains($view, 'id="structure-status"') && str_contains($view, 'aria-live="polite"')
);
structureUiCheck('hay explicación del punto de inserción', str_contains($view, 'id="studio-insert-placement"'));

structureUiCheck('JS conecta move/delete al endpoint',
    str_contains($js, 'function updateCanvasStructure(')
    && str_contains($js, 'body.dataset.structureUrl')
    && str_contains($js, "fd.append('action'")
    && str_contains($js, "fd.append('direction'")
);
structureUiCheck('JS crea puntos de inserción explícitos',
    str_contains($js, 'function createInsertPoint(')
    && str_contains($js, "fd.append('position'")
    && str_contains($js, 'insertPlacement')
);
structureUiCheck('JS ofrece recuperación y foco posterior',
    str_contains($js, 'focus_section')
    && str_contains($js, 'pendingStructureFocus')
    && str_contains($js, 'doUndo(')
);
structureUiCheck('acciones deterministas no necesitan abrir el chat',
    str_contains($js, 'function showStructureStatus(')
    && str_contains($js, 'function insertFormItem(')
);
// STUDIO-UX F1 — Duplicar vive junto a mover/eliminar, no dentro del chat.
structureUiCheck('la fila ofrece duplicar como acción directa',
    str_contains($js, "structureActionButton('duplicate'")
    && str_contains($js, "duplicate: 'js.cv.duplicate_section'")
    && str_contains($js, 'duplicate:')
);

structureUiCheck('CSS cubre filas, acciones, inserción y estados',
    str_contains($css, '.cvstudio-seclist__row')
    && str_contains($css, '.cvstudio-seclist__actions')
    && str_contains($css, '.cvstudio-insertpoint')
    && str_contains($css, '.cvstudio-structure-status')
);
structureUiCheck('acciones táctiles no dependen solo de hover',
    str_contains($css, '@media (hover:none)')
    && str_contains($css, '.cvstudio-seclist__actions')
);
structureUiCheck('reduced motion contempla estructura',
    str_contains($css, 'prefers-reduced-motion')
    && str_contains($css, 'cvstudio-insertpoint')
);

$requiredKeys = [
    'js.cv.add_here', 'js.cv.move_up', 'js.cv.move_down', 'js.cv.delete_section',
    'js.cv.section_moved', 'js.cv.section_deleted', 'js.cv.undo_action',
    'js.cv.insert_before_x', 'js.cv.insert_after_x', 'js.cv.insert_at_start',
    'js.cv.insert_at_end', 'js.cv.structure_error', 'js.cv.inserting_form',
    'js.cv.duplicate_section', 'js.cv.duplicating_section', 'js.cv.section_duplicated',
];
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter($requiredKeys, static fn(string $key): bool => !isset($catalog[$key])));
    structureUiCheck('microcopy estructural completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
