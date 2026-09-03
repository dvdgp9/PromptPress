<?php

declare(strict_types=1);

// STUDIO-UX F3 — Texto rico mínimo en la edición inline: negrita, cursiva y
// enlace sobre la selección, sin pasar por la IA.
//
// Hasta ahora el overlay editaba en `plaintext-only`: poner una palabra en
// negrita o un enlace dentro de una frase exigía pedirle al modelo que
// reescribiera la sección entera (6,7 s de media). Aquí se fija el contrato del
// overlay y —lo que de verdad puede romperse— que el formato sobreviva al
// guardado y que un enlace peligroso no lo haga.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasSanitizer;
use App\Services\Canvas\CanvasService;

$failed = 0;
function richCheck(string $name, bool $ok, string $detail = ''): void
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

// --- Contrato del overlay ----------------------------------------------------
richCheck('la edición inline deja de ser texto plano',
    !str_contains($controller, "contentEditable = 'plaintext-only'"),
    'sigue en plaintext-only'
);
richCheck('hay barra de selección con negrita, cursiva y enlace',
    str_contains($controller, 'pp-studio-rt')
    && str_contains($controller, "'bold'")
    && str_contains($controller, "'italic'")
    && str_contains($controller, "createLink")
);
richCheck('se puede quitar un enlace existente', str_contains($controller, 'unlink'));
// Sin styleWithCSS=false el navegador escribe <span style="font-weight:bold">,
// que el saneado de estilos puede recortar y ensucia el HTML de la página.
richCheck('el formato se escribe como etiqueta, no como estilo en línea',
    str_contains($controller, "execCommand('styleWithCSS', false, false)")
);
// Pegar desde Word/Docs arrastra spans y estilos: entra como texto plano.
richCheck('el pegado entra como texto plano',
    str_contains($controller, "addEventListener('paste'")
    && str_contains($controller, "insertText")
);
richCheck('la barra no roba la selección al pulsarla',
    str_contains($controller, "rt.addEventListener('mousedown'")
);
richCheck('el salto de línea no mete divs del navegador',
    str_contains($controller, 'insertLineBreak')
);
richCheck('el overlay recibe destinos de enlace y microcopia del padre',
    str_contains($controller, "d.type === 'studio-config'") && str_contains($js, "type: 'studio-config'")
);

// --- Microcopia en los cuatro idiomas ---------------------------------------
$requiredKeys = [
    'js.cv.rt_bold', 'js.cv.rt_italic', 'js.cv.rt_link',
    'js.cv.rt_unlink', 'js.cv.rt_link_url', 'js.cv.rt_link_apply', 'js.cv.rt_link_page',
];
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter($requiredKeys, static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''));
    richCheck('microcopia de texto rico completa en ' . $lang, $missing === [], implode(', ', $missing));
}

// --- Lo que de verdad puede romperse: el guardado ---------------------------
$rich = '<section data-pp-section="intro">'
    . '<p>Somos un <b>centro oficial</b> con <i>veinte años</i> de <a href="/contacto">experiencia</a>.</p>'
    . '</section>';

$clean = CanvasService::normalizeEditedSectionHtml($rich);
richCheck('normalizeEditedSectionHtml conserva negrita, cursiva y enlace',
    str_contains($clean, '<b>centro oficial</b>')
    && str_contains($clean, '<i>veinte años</i>')
    && str_contains($clean, '<a href="/contacto">experiencia</a>'),
    $clean
);

$sanitized = CanvasSanitizer::sanitizeHtml($rich);
richCheck('el saneado de página conserva el formato',
    str_contains($sanitized['html'], '<b>centro oficial</b>')
    && str_contains($sanitized['html'], '<i>veinte años</i>')
    && str_contains($sanitized['html'], 'href="/contacto"'),
    $sanitized['html']
);

// Round-trip completo: sección editada → página → sección otra vez.
$page = '<section data-pp-section="hero"><h1>Hero</h1></section>' . $rich;
$replaced = CanvasService::replaceSection($page, 'intro', $clean);
richCheck('el formato sobrevive al round-trip de sección',
    $replaced !== null
    && str_contains((string) $replaced, '<b>centro oficial</b>')
    && array_column(CanvasService::listSections((string) $replaced), 'id') === ['hero', 'intro'],
    (string) $replaced
);

// Un enlace peligroso pierde el href: el texto se queda, el vector no.
$evil = '<section data-pp-section="intro"><p>Pulsa <a href="javascript:alert(1)">aquí</a>.</p></section>';
$evilClean = CanvasSanitizer::sanitizeHtml($evil);
richCheck('un enlace con javascript: pierde el href',
    !str_contains($evilClean['html'], 'javascript:') && str_contains($evilClean['html'], 'aquí'),
    $evilClean['html']
);

// El overlay se limpia igual que antes: nada de contenteditable persistido.
$dirty = '<section data-pp-section="intro"><p contenteditable="true" class="pp-studio-editing">Texto <b>en curso</b></p></section>';
$dirtyClean = CanvasService::normalizeEditedSectionHtml($dirty);
richCheck('el estado de edición no se persiste, el formato sí',
    !str_contains($dirtyClean, 'contenteditable')
    && !str_contains($dirtyClean, 'pp-studio-editing')
    && str_contains($dirtyClean, '<b>en curso</b>'),
    $dirtyClean
);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
