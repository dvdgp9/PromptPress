<?php

declare(strict_types=1);

/**
 * I18N-FULL T5.2 — Motor de traducción de páginas (híbrido).
 *
 * Decisión de la fase 5: traducción LITERAL donde no cabe creatividad (legales,
 * artículos, contacto) y REESCRITURA nativa donde el texto vende (home,
 * servicios, landings). Donde más riesgo hay, además, más se puede validar:
 * en literal se exige que la estructura sea idéntica; en reescritura no, porque
 * el copy cambia a propósito.
 *
 * Este test NO llama a la IA (eso se verifica aparte con una llamada real):
 * cubre el criterio de modo, la reescritura de enlaces internos, la validación
 * y el contrato de los prompts.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AI\Actions;
use App\Services\LanguageService;
use App\Services\PageTranslator;
use Core\Database;

$failed = 0;
function checkTr(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 700) . PHP_EOL;
        }
    }
}

$siteId = 1;
Database::execute("DELETE FROM pages WHERE site_id = ? AND slug LIKE 'qa7-%'", [$siteId]);
$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// ---------------------------------------------------------------------------
// 1. Modo por tipo de página
// ---------------------------------------------------------------------------

checkTr(
    'legal_and_articles_are_translated_literally',
    PageTranslator::modeFor('legal') === PageTranslator::MODE_LITERAL
        && PageTranslator::modeFor('article') === PageTranslator::MODE_LITERAL
        && PageTranslator::modeFor('contact') === PageTranslator::MODE_LITERAL,
    'que la IA «mejore» un aviso legal es justo el riesgo que no queremos correr'
);

checkTr(
    'marketing_pages_are_rewritten',
    PageTranslator::modeFor('home') === PageTranslator::MODE_REWRITE
        && PageTranslator::modeFor('service') === PageTranslator::MODE_REWRITE
        && PageTranslator::modeFor('landing') === PageTranslator::MODE_REWRITE,
    'un titular traducido literalmente suena plano en el idioma destino'
);

checkTr(
    'unknown_page_type_defaults_to_literal',
    PageTranslator::modeFor('cualquier-cosa') === PageTranslator::MODE_LITERAL,
    'ante la duda, fidelidad'
);

// ---------------------------------------------------------------------------
// 2. Enlaces internos
// ---------------------------------------------------------------------------

$grp = 'qa7-' . bin2hex(random_bytes(3));
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, created_at, updated_at, published_at)
     VALUES (?, 'Contacto QA7', 'qa7-contacto', 'contact', ?, ?, 'published', NOW(), NOW(), NOW())",
    [$siteId, $primary, $grp]
);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, created_at, updated_at, published_at)
     VALUES (?, 'Contact QA7', 'fr/qa7-contact', 'contact', 'fr', ?, 'published', NOW(), NOW(), NOW())",
    [$siteId, $grp]
);

$html = '<p>Escríbenos desde <a href="/qa7-contacto">contacto</a> '
      . 'o mira <a href="/qa7-sin-traducir">esta otra</a>. '
      . 'Externo: <a href="https://ejemplo.test/x">fuera</a>. '
      . 'Ancla: <a href="#seccion">aquí</a>.</p>';
$rewritten = PageTranslator::rewriteInternalLinks($siteId, $html, 'fr');

checkTr(
    'internal_link_points_to_the_translated_page',
    str_contains($rewritten, 'href="/fr/qa7-contact"'),
    $rewritten
);

checkTr(
    'untranslated_target_keeps_the_original_link',
    str_contains($rewritten, 'href="/qa7-sin-traducir"'),
    'mejor un enlace que funciona en otro idioma que uno roto'
);

checkTr(
    'external_links_and_anchors_are_untouched',
    str_contains($rewritten, 'href="https://ejemplo.test/x"') && str_contains($rewritten, 'href="#seccion"'),
    $rewritten
);

// ---------------------------------------------------------------------------
// 3. Validación de la traducción
// ---------------------------------------------------------------------------

$source = '<section data-pp-section="hero"><h1 data-pp-field="title">Hola</h1>'
        . '<p data-pp-field="body">Texto</p></section>'
        . '<section data-pp-section="cta"><a data-pp-field="cta" href="/x">Ir</a></section>';

$goodLiteral = str_replace(['Hola', 'Texto', 'Ir'], ['Bonjour', 'Texte', 'Aller'], $source);
$check = PageTranslator::validateCanvas($source, $goodLiteral, PageTranslator::MODE_LITERAL);
checkTr('faithful_literal_translation_passes', $check['ok'] === true, json_encode($check, JSON_UNESCAPED_UNICODE));

// En literal, perder una sección es un fallo detectable automáticamente.
$lostSection = '<section data-pp-section="hero"><h1 data-pp-field="title">Bonjour</h1>'
             . '<p data-pp-field="body">Texte</p></section>';
$check = PageTranslator::validateCanvas($source, $lostSection, PageTranslator::MODE_LITERAL);
checkTr(
    'literal_mode_detects_lost_structure',
    $check['ok'] === false,
    json_encode($check, JSON_UNESCAPED_UNICODE)
);

// El mensaje de error tiene que ser legible por alguien no técnico.
checkTr(
    'validation_error_is_human_readable',
    isset($check['message'])
        && !preg_match('/data-pp-|<section|isomorf|regex|null/i', (string) $check['message'])
        && mb_strlen((string) $check['message']) > 20,
    (string) ($check['message'] ?? '(sin mensaje)')
);

// En reescritura el copy cambia: no se exige el mismo número de campos, pero sí
// que no se pierdan secciones enteras.
$rewrittenOk = '<section data-pp-section="hero"><h1 data-pp-field="title">Un titre qui vend</h1></section>'
             . '<section data-pp-section="cta"><a data-pp-field="cta" href="/x">Allons-y</a></section>';
$check = PageTranslator::validateCanvas($source, $rewrittenOk, PageTranslator::MODE_REWRITE);
checkTr('rewrite_mode_allows_copy_changes', $check['ok'] === true, json_encode($check, JSON_UNESCAPED_UNICODE));

$check = PageTranslator::validateCanvas($source, '<p>Casi nada</p>', PageTranslator::MODE_REWRITE);
checkTr(
    'rewrite_mode_still_rejects_a_gutted_page',
    $check['ok'] === false,
    'reescribir no es quedarse con un párrafo'
);

// ---------------------------------------------------------------------------
// 4. Contrato de los prompts
// ---------------------------------------------------------------------------

foreach ([Actions::TRANSLATE_PAGE_CANVAS, Actions::TRANSLATE_PAGE_SECTIONS] as $action) {
    $def = Actions::get($action);
    $instruction = (string) ($def['instruction'] ?? '');
    checkTr(
        'action_' . $action . '_carries_the_language_rule',
        str_contains($instruction, 'IDIOMA DE SALIDA (REGLA DURA') && str_contains($instruction, '{language}'),
        'sin la regla dura, el modelo imita el idioma del original'
    );
    checkTr(
        'action_' . $action . '_forbids_inventing_facts',
        stripos($instruction, 'no inventes') !== false || stripos($instruction, 'no añadas') !== false,
        'traducir no es añadir datos que no estaban'
    );
}

$canvasDef = (string) (Actions::get(Actions::TRANSLATE_PAGE_CANVAS)['instruction'] ?? '');
checkTr(
    'canvas_action_protects_structure_and_placeholders',
    str_contains($canvasDef, 'data-pp-') && str_contains($canvasDef, '{{form:'),
    'hay que preservar atributos de plataforma y placeholders de formulario'
);

checkTr(
    'canvas_action_uses_the_text_envelope',
    str_contains($canvasDef, '<pp-html>'),
    'el envelope de texto evita el JSON roto con HTML dentro (lección de FEAT-5)'
);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

Database::execute("DELETE FROM pages WHERE site_id = ? AND slug LIKE 'qa7-%'", [$siteId]);
Database::execute("DELETE FROM pages WHERE site_id = ? AND slug LIKE 'fr/qa7-%'", [$siteId]);
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkTr(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary],
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
