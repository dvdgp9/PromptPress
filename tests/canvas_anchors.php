<?php

declare(strict_types=1);

// ANCLAS — Enlazar a una sección de la MISMA página.
//
// Faltaban las dos mitades: las secciones no tenían un `id` al que apuntar
// (`data-pp-section` es el ancla interna del editor, no un id del documento) y
// el panel no ofrecía ni ponerle nombre a una sección ni apuntar a ella. Aquí
// se fija el contrato de las dos: que el ancla exista SIEMPRE, que sobreviva a
// las ediciones de la IA y que la interfaz la ofrezca.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasSanitizer;
use App\Services\Canvas\CanvasService;

$failed = 0;
function anchorCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

// --- El ancla existe siempre --------------------------------------------------
$out = CanvasSanitizer::sanitizeHtml(
    '<section data-pp-section="hero"><h1>Hola</h1></section>'
    . '<section data-pp-section="servicios"><h2>Servicios</h2></section>'
);
anchorCheck('cada sección top-level sale con su id',
    str_contains($out['html'], 'id="hero"') && str_contains($out['html'], 'id="servicios"'),
    $out['html']
);

$custom = CanvasSanitizer::sanitizeHtml('<section data-pp-section="sec-1" id="Contacto Comercial"><p>x</p></section>');
anchorCheck('un ancla escrita a mano se normaliza a slug',
    str_contains($custom['html'], 'id="contacto-comercial"'), $custom['html']);

$dupe = CanvasSanitizer::sanitizeHtml(
    '<section data-pp-section="a" id="contacto"><p>1</p></section>'
    . '<section data-pp-section="b" id="contacto"><p>2</p></section>'
);
anchorCheck('dos secciones nunca comparten ancla',
    str_contains($dupe['html'], 'id="contacto"') && str_contains($dupe['html'], 'id="contacto-2"'), $dupe['html']);

$reserved = CanvasSanitizer::sanitizeHtml('<section data-pp-section="cierre" id="pp-canvas-9"><p>x</p></section>');
anchorCheck('el prefijo pp- del sistema no se puede secuestrar',
    str_contains($reserved['html'], 'id="cierre"'), $reserved['html']);

anchorCheck('slugAnchor deja anclas usables',
    CanvasSanitizer::slugAnchor('  Nuestro Método ') === 'nuestro-metodo'
    && CanvasSanitizer::slugAnchor('3 pasos') === 's-3-pasos'
    && CanvasSanitizer::slugAnchor('!!!') === ''
);

// --- El enlace interno sobrevive al saneado -----------------------------------
$link = CanvasSanitizer::sanitizeHtml(
    '<section data-pp-section="hero"><a class="pp-btn" href="#contacto">Escríbenos</a></section>'
    . '<section data-pp-section="contacto"><h2>Contacto</h2></section>'
);
anchorCheck('href="#seccion" no se considera una URL peligrosa',
    str_contains($link['html'], 'href="#contacto"'), $link['html']);

// --- Estabilidad frente a la IA ----------------------------------------------
$page = '<section data-pp-section="hero" id="portada"><h1>a</h1></section>';
$replaced = CanvasService::replaceSection($page, 'hero', '<section><h1>b</h1></section>');
anchorCheck('una reescritura del modelo no se lleva por delante el ancla',
    is_string($replaced) && str_contains($replaced, 'id="portada"'), (string) $replaced);

$renamed = CanvasService::replaceSection($page, 'hero', '<section id="sobre-nosotros"><h1>b</h1></section>');
anchorCheck('cambiar el ancla a mano sí manda sobre la anterior',
    is_string($renamed) && str_contains($renamed, 'id="sobre-nosotros"') && !str_contains($renamed, 'id="portada"'),
    (string) $renamed);

// Páginas anteriores a esto (guardadas sin `id`): el render las ancla igual.
$legacy = new \ReflectionMethod(CanvasService::class, 'withSectionAnchors');
$legacy->setAccessible(true);
$rendered = (string) $legacy->invoke(null,
    '<section data-pp-section="hero" class="lx-hero"><h1>a</h1></section>'
    . '<section data-pp-section="contacto"><h2>b</h2></section>'
);
anchorCheck('una página vieja recibe su ancla al pintarse',
    str_contains($rendered, '<section id="hero" data-pp-section="hero" class="lx-hero">')
    && str_contains($rendered, '<section id="contacto" data-pp-section="contacto">'),
    $rendered
);
$kept = (string) $legacy->invoke(null, '<section id="portada" data-pp-section="hero"><h1>a</h1></section>');
anchorCheck('el respaldo no pisa un ancla que ya existe',
    substr_count($kept, 'id="portada"') === 1 && !str_contains($kept, 'id="hero"'), $kept);

// --- Lo que ve el panel -------------------------------------------------------
$sections = CanvasService::listSections(
    '<section data-pp-section="hero" id="portada"><h1>a</h1></section>'
    . '<section data-pp-section="servicios" id="servicios"><h2>b</h2></section>'
);
anchorCheck('la lista de partes viaja con el ancla de cada una',
    ($sections[0]['anchor'] ?? '') === 'portada' && ($sections[1]['anchor'] ?? '') === 'servicios',
    json_encode($sections, JSON_UNESCAPED_UNICODE)
);

// --- Contrato de interfaz -----------------------------------------------------
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
$design = (string) file_get_contents(PP_ROOT . '/app/Services/DesignSystem.php');

anchorCheck('el panel de sección deja poner el ancla',
    str_contains($js, "id=\"ep-anchor\"") && str_contains($js, "applyOp('anchor'"));
anchorCheck('el panel de enlace deja elegir una sección de la página',
    str_contains($js, "id=\"ep-section\"") && str_contains($js, 'linkDestinationField'));
// A dónde lleva un enlace es UNA decisión: primero el tipo de destino, y solo
// se ve el control de ese tipo. Tres campos apilados escribiendo en el mismo
// sitio parecían tres cosas que rellenar.
anchorCheck('el destino del enlace se elige una sola vez',
    str_contains($js, 'data-linkmode')
    && str_contains($js, 'data-destpane')
    && substr_count($js, "pp.t('js.cv.link_dest')") === 1
    && !str_contains($js, "pp.t('js.cv.or_url')")
);
anchorCheck('solo se ve el control del destino elegido',
    str_contains((string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css'), '.cvstudio-dest[hidden]{display:none}'));
anchorCheck('el overlay aplica y guarda el ancla',
    str_contains($controller, "msg.op === 'anchor'") && str_contains($controller, 'anchor:1'));
anchorCheck('la barra de texto ofrece las secciones como destino',
    str_contains($controller, "t('link_section'"));
anchorCheck('el destino no queda tapado por la cabecera sticky',
    str_contains($design, 'section[id]{scroll-margin-top'));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    $file = (string) file_get_contents(PP_ROOT . '/lang/admin/' . $lang . '.php');
    anchorCheck('microcopia del ancla en ' . $lang,
        str_contains($file, "'js.cv.anchor'")
        && str_contains($file, "'js.cv.anchor_hint'")
        && str_contains($file, "'js.cv.link_dest'")
        && str_contains($file, "'js.cv.dest_page'")
        && str_contains($file, "'js.cv.dest_section'")
        && str_contains($file, "'js.cv.dest_url'")
        && str_contains($file, "'js.cv.dest_section_hint'")
        && str_contains($file, "'js.cv.pick_section'")
        && str_contains($file, "'js.cv.rt_link_section'")
    );
}

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
