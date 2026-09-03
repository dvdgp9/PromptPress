<?php

// STUDIO-UX F1 — Duplicar una sección top-level sin pasar por la IA.
// Contrato puro sobre el DOM: no toca BD ni HTTP. Se escribe antes que el
// endpoint para fijar las reglas (id único, orden, ids internos, placeholders).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;

$failed = 0;
function dupCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

/** @return string[] */
function dupIds(?string $html): array
{
    if ($html === null) return [];
    return array_column(CanvasService::listSections($html), 'id');
}

$base = '<section data-pp-section="hero"><h1>Inicio</h1></section>'
    . '<section data-pp-section="services"><h2>Servicios</h2><p>Tres cosas</p></section>'
    . '<section data-pp-section="cta"><p>Cierre</p></section>';

// --- Caso base: la copia entra justo detrás del original ---------------------
$out = CanvasService::duplicateSection($base, 'services');
dupCheck('duplicate_returns_array', is_array($out), var_export($out, true));
dupCheck(
    'duplicate_inserts_right_after_source',
    dupIds($out['html'] ?? null) === ['hero', 'services', 'services-2', 'cta'],
    implode(',', dupIds($out['html'] ?? null))
);
dupCheck('duplicate_reports_new_id', ($out['id'] ?? '') === 'services-2', (string) ($out['id'] ?? ''));
dupCheck(
    'duplicate_copies_content',
    substr_count((string) ($out['html'] ?? ''), '<h2>Servicios</h2>') === 2
        && substr_count((string) ($out['html'] ?? ''), '<p>Tres cosas</p>') === 2,
    (string) ($out['html'] ?? '')
);

// --- Duplicar dos veces: sufijos correlativos, nunca repetidos ---------------
$twice = CanvasService::duplicateSection((string) $out['html'], 'services');
dupCheck(
    'duplicate_twice_uses_next_free_suffix',
    dupIds($twice['html'] ?? null) === ['hero', 'services', 'services-3', 'services-2', 'cta'],
    implode(',', dupIds($twice['html'] ?? null))
);

// Duplicar la COPIA no encadena sufijos ("services-2-2"): se parte de la base.
$fromCopy = CanvasService::duplicateSection((string) $out['html'], 'services-2');
dupCheck(
    'duplicate_of_a_copy_strips_numeric_suffix',
    ($fromCopy['id'] ?? '') === 'services-3',
    (string) ($fromCopy['id'] ?? '')
);

// --- Etiqueta de los bloques funcionales -------------------------------------
$labelled = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="booking-cf4f" data-pp-label="Calendario: Consulta inicial"><p>Reserva</p></section>';
$out = CanvasService::duplicateSection($labelled, 'booking-cf4f');
dupCheck(
    'duplicate_marks_the_label_so_the_list_is_not_ambiguous',
    str_contains((string) ($out['html'] ?? ''), 'data-pp-label="Calendario: Consulta inicial (2)"'),
    (string) ($out['html'] ?? '')
);

// --- Placeholders dinámicos: se copian tal cual, sin expandir -----------------
$embed = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="contacto">{{form:391}}{{resources:featured|limit=2}}</section>';
$out = CanvasService::duplicateSection($embed, 'contacto');
dupCheck(
    'duplicate_preserves_dynamic_placeholders',
    substr_count((string) ($out['html'] ?? ''), '{{form:391}}') === 2
        && substr_count((string) ($out['html'] ?? ''), '{{resources:featured|limit=2}}') === 2,
    (string) ($out['html'] ?? '')
);

// --- ids internos: no puede haber dos elementos con el mismo id --------------
$withIds = '<section data-pp-section="faqs">'
    . '<details id="faq-1"><summary>Una</summary><p>Respuesta</p></details>'
    . '<a href="#faq-1">Ir a la primera</a>'
    . '<a href="/contacto">Enlace externo a la sección</a>'
    . '</section>';
$out = CanvasService::duplicateSection($withIds, 'faqs');
$html = (string) ($out['html'] ?? '');
dupCheck('duplicate_rewrites_inner_ids', str_contains($html, 'id="faq-1-2"') && substr_count($html, 'id="faq-1"') === 1, $html);
dupCheck('duplicate_rewrites_internal_anchors', str_contains($html, 'href="#faq-1-2"'), $html);
dupCheck('duplicate_keeps_external_links_untouched', substr_count($html, 'href="/contacto"') === 2, $html);

// --- Rechazos ----------------------------------------------------------------
dupCheck('duplicate_unknown_section_rejected', CanvasService::duplicateSection($base, 'missing') === null);
dupCheck('duplicate_empty_id_rejected', CanvasService::duplicateSection($base, '') === null);
dupCheck(
    'duplicate_ignores_nested_sections',
    CanvasService::duplicateSection('<section data-pp-section="wrap"><section data-pp-section="inner"><p>x</p></section></section>', 'inner') === null
);

// --- STUDIO-UX F8: duplicar desde "Añadir" respeta el punto de inserción -----
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');

dupCheck('el endpoint acepta un ancla distinta del origen',
    str_contains($controller, "Request::post('anchor', '')")
    && str_contains($controller, 'CanvasService::sectionHtml('));
dupCheck('el menú "Añadir" empieza por reutilizar, no por plantillas neutras',
    strpos($view, 'cv.block_reuse_category') < strpos($view, 'cv.block_content_category')
    && strpos($view, 'studio-duplicate-part') < strpos($view, "data-section-template=\"text\""));
dupCheck('traer de otra página va con reutilizar, antes que las plantillas',
    strpos($view, 'studio-copy-section') < strpos($view, 'cv.block_content_category'));
dupCheck('el submenú manda ancla y posición aparte del origen',
    str_contains($js, "fd.append('anchor'") && str_contains($js, "data-duplicate-part"));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['cv.block_reuse_category', 'cv.duplicate_part', 'cv.duplicate_part_desc', 'js.cv.duplicate_part_pick'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    dupCheck('microcopia de reutilizar completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
