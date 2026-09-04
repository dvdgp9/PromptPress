<?php

declare(strict_types=1);

// STUDIO-UX A2/A3′ — Devolverle sitio al lienzo. Contrato de integración: el
// comportamiento fino se comprueba en navegador (anchos reales a 1440 y 1024),
// pero las piezas que lo sostienen no pueden desaparecer en una refactorización.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function canvasRoomCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
$js   = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css  = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');
$overlay = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');

// --- A2: un solo control para apartar barra y chat ----------------------------
// Plegar la barra y "ver solo la página" hacían casi lo mismo (decisión del
// usuario, 04/09/2026): queda uno, y la página sigue EDITABLE dentro de él.
canvasRoomCheck('la barra lateral es direccionable', str_contains($view, 'id="studio-side"'));
canvasRoomCheck(
    'hay un único control de lienzo ancho',
    str_contains($view, 'id="studio-canvas-wide"')
        && str_contains($view, 'aria-pressed="false"')
        && !str_contains($view, 'id="studio-side-toggle"')
);
canvasRoomCheck(
    'ancho: ni barra ni chat, y sin marco',
    str_contains($css, '.cvstudio-body.is-canvas-wide .cvstudio-side,')
        && str_contains($css, '.cvstudio-body.is-canvas-wide .cvstudio-dock{display:none}')
        && str_contains($css, '.cvstudio-body.is-canvas-wide .cvstudio-stage{padding:0}')
);
canvasRoomCheck(
    'el estado se recuerda',
    str_contains($js, "WIDE_KEY = 'pp-studio-canvas-wide'") && str_contains($js, 'localStorage.setItem(WIDE_KEY')
);
canvasRoomCheck(
    'el lienzo ancho no apaga la edición',
    !str_contains($js, "type: 'chrome'") && !str_contains($overlay, 'pp-studio-chromeless'),
    'el modo silencioso del overlay se retiró al fusionar los dos modos'
);

// --- A3′: el chat solo ocupa cuando lo pides ---------------------------------
// El dock cuelga de `main` (escenario + barra), no del escenario: desplegado se
// apoya en la columna de la barra lateral y no le quita ancho al lienzo.
canvasRoomCheck(
    'el chat cuelga de la columna derecha, no del lienzo',
    str_contains($css, '.cvstudio-main{flex:1;display:flex;min-height:0;position:relative}')
        && str_contains($css, '.cvstudio-dock{position:absolute;right:12px;bottom:12px')
);
canvasRoomCheck(
    'el panel del chat cabe en la columna de la barra (380px)',
    str_contains($css, '.cvstudio-dock__panel{width:min(356px,calc(100vw - 24px))')
);
canvasRoomCheck(
    'el chat está fuera del escenario en el marcado',
    strpos($view, 'id="chat-dock"') > strpos($view, 'id="studio-frame-wrap"')
        && strpos($view, 'id="chat-dock"') < strpos($view, 'id="studio-side"')
        && !preg_match('~<div class="cvstudio-stage">.*?id="chat-dock".*?</div>\s*<!-- STUDIO-UX A3~s', $view)
);
canvasRoomCheck(
    'el chat arranca plegado',
    str_contains($js, "dockPref = localStorage.getItem(DOCK_KEY) || '0'")
        && str_contains($js, "setDock(dockPref === '1', false)")
);
canvasRoomCheck(
    'plegado, la píldora avisa de que el cambio está',
    str_contains($js, "pp.t('js.cv.change_ready')") && str_contains($js, 'showPillNotice(')
);

canvasRoomCheck(
    'el lienzo ancho no recarga el iframe',
    !preg_match('~function setCanvasWide\([^)]*\)\s*\{[^}]*reloadPreview~s', $js)
);
canvasRoomCheck(
    'Esc devuelve el panel antes de subir de ámbito',
    (bool) preg_match("~if \(canvasIsWide\(\)\) \{ setCanvasWide\(false\); return true; \}~", $js)
);

// --- El teclado tiene que llegar desde dentro del lienzo ----------------------
// P5: los listeners viven en el documento del padre, pero el foco del usuario
// está dentro del iframe. Sin reenvío, cualquier atajo nuevo nace muerto.
canvasRoomCheck(
    'el overlay reenvía las teclas sueltas al padre',
    str_contains($overlay, "post('key'"),
    'CanvasController::overlayScript'
);
canvasRoomCheck(
    'el overlay no reenvía mientras se escribe',
    (bool) preg_match('~post\(\'key\'~', $overlay) && str_contains($overlay, 'if(editing) return;')
);
canvasRoomCheck(
    'el padre atiende las teclas reenviadas',
    str_contains($js, "d.type === 'key'")
);
canvasRoomCheck(
    'los atajos no se disparan escribiendo en el panel',
    str_contains($js, 'function studioShortcut(')
        && (bool) preg_match('~INPUT\|TEXTAREA\|SELECT~', $js)
);

// --- i18n: sin literales nuevos en español -----------------------------------
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    $file = (string) file_get_contents(PP_ROOT . '/lang/admin/' . $lang . '.php');
    canvasRoomCheck(
        'microcopia del lienzo ancho en ' . $lang,
        str_contains($file, "'js.cv.canvas_wide'") && str_contains($file, "'js.cv.canvas_wide_exit'")
            && str_contains($file, "'js.cv.change_ready'")
            // Las claves de los dos modos viejos no pueden quedarse huérfanas.
            && !str_contains($file, "'js.cv.hide_panel'")
            && !str_contains($file, "'js.cv.canvas_only'")
    );
}

echo $failed === 0 ? PHP_EOL . 'OK' . PHP_EOL : PHP_EOL . $failed . ' FALLOS' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
