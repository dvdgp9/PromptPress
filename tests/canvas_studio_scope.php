<?php

declare(strict_types=1);

// STUDIO-UX B1/B2/B3 — Un solo modelo mental en la barra lateral.
// B1: seleccionar algo no puede hacer desaparecer «Añadir a la página».
// B2: los atajos con modificador llegan también desde dentro del lienzo.
// B3: deseleccionar cierra el panel de verdad (P6: pintaba «Guardado» sin nada
// seleccionado porque el panel seguía vivo con controles huérfanos).

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function scopeCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$view    = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
$js      = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css     = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');
$overlay = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');

// --- B1: la barra deja de ser excluyente -------------------------------------
// «Añadir» cuelga de la barra, no del estado vacío: por eso su indentación es la
// de un hijo directo del <aside>, no la de algo dentro de #side-empty.
scopeCheck(
    'añadir a la página cuelga de la barra, no del estado vacío',
    str_contains($view, "\n    <div class=\"cvstudio-side__block\" id=\"studio-add-block\">")
);
scopeCheck(
    'el orden sigue siendo partes → añadir',
    strpos($view, 'id="side-sections"') < strpos($view, 'id="studio-add-block"')
);
scopeCheck(
    'showSide solo decide entre panel y explicación',
    (bool) preg_match(
        '~function showSide\(which\) \{\s*panel\.hidden = which !== \'panel\';\s*if \(sideEmpty\) sideEmpty\.hidden = which === \'panel\';\s*\}~',
        $js
    )
);
scopeCheck(
    'el bloque suelto tiene su propio aire',
    str_contains($css, '.cvstudio-side>.cvstudio-side__block{padding:16px 16px 20px')
);

// --- B2: deshacer y Esc desde dentro del lienzo -------------------------------
scopeCheck(
    'el overlay reenvía también los atajos con modificador',
    str_contains($overlay, 'mods: {mod: mod, shift: e.shiftKey, alt: e.altKey}')
);
scopeCheck(
    'el navegador no deshace por su cuenta dentro del iframe',
    (bool) preg_match('~if\(mod\) e\.preventDefault\(\);~', $overlay)
);
scopeCheck(
    'el padre recibe las modificadoras del overlay',
    str_contains($js, 'studioShortcut(d.key, d.mods)')
);
scopeCheck(
    'deshacer y rehacer viven en el mismo sitio que los demás atajos',
    (bool) preg_match('~function studioShortcut\(key, mods\)~', $js)
        && str_contains($js, 'doRedo()') && str_contains($js, 'doUndo(undoBtn)')
);
scopeCheck(
    'Esc sube de ámbito venga de donde venga',
    (bool) preg_match('~function studioShortcut\(key, mods\)[\s\S]{0,1200}climbScope\(\)~', $js)
);

// --- B3: deseleccionar cierra el panel ----------------------------------------
scopeCheck(
    'el overlay contesta al deselect del padre',
    (bool) preg_match(
        "~d\.type === 'deselect'[\s\S]{0,700}post\('element-deselected'\)~",
        $overlay
    )
);
scopeCheck(
    'el padre cierra el panel al recibirlo',
    (bool) preg_match("~d\.type === 'element-deselected'[^\n]*closePanel\(\)~", $js)
);

echo $failed === 0 ? PHP_EOL . 'OK' . PHP_EOL : PHP_EOL . $failed . ' FALLOS' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
