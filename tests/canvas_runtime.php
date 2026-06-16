<?php

// FH1 — Tests del runtime Canvas: sanitizado HTML/CSS, estructura de
// secciones, placeholders, versionado y restore. Sin llamadas IA.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasSanitizer;
use App\Services\Canvas\CanvasService;
use App\Services\PostMetaService;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  → ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

// ----------------------------------------------------------------------
// 1. Sanitizado HTML
// ----------------------------------------------------------------------
$r = CanvasSanitizer::sanitizeHtml(
    '<section data-pp-section="hero" onclick="evil()"><h1>Hola</h1>'
    . '<script>alert(1)</script>'
    . '<a href="javascript:evil()">mal</a><a href="/contacto">bien</a>'
    . '<iframe src="https://x.com"></iframe>'
    . '<form><input name="x"></form>'
    . '</section>'
);
check('script_dropped', !str_contains($r['html'], '<script'), $r['html']);
check('iframe_dropped', !str_contains($r['html'], '<iframe'), $r['html']);
check('form_dropped', !str_contains($r['html'], '<form'), $r['html']);
check('onclick_stripped', !str_contains($r['html'], 'onclick'), $r['html']);
check('js_href_stripped', !str_contains($r['html'], 'javascript:'), $r['html']);
check('good_href_kept', str_contains($r['html'], 'href="/contacto"'), $r['html']);

// <style> embebido se extrae al canal CSS
$r = CanvasSanitizer::sanitizeHtml('<section data-pp-section="a"><style>.x{color:red}</style><p>Hi</p></section>');
check('style_extracted', !str_contains($r['html'], '<style') && str_contains($r['css'], '.x{color:red}'), json_encode($r));

// Nodos sueltos de primer nivel → sección envoltorio; ids únicos
$r = CanvasSanitizer::sanitizeHtml('<div class="loose">Suelto</div><section data-pp-section="dup"><p>a</p></section><section data-pp-section="dup"><p>b</p></section>');
$sections = preg_match_all('/<section[^>]*data-pp-section="([^"]+)"/', $r['html'], $m);
check('loose_wrapped', $sections === 3, $r['html']);
check('section_ids_unique', count(array_unique($m[1])) === 3, json_encode($m[1]));

// style attr scrubbing
$r = CanvasSanitizer::sanitizeHtml('<section data-pp-section="s"><div style="color:red;position:fixed;top:0">X</div><div style="background:url(javascript:1)">Y</div></section>');
check('inline_fixed_neutralized', !preg_match('/position\s*:\s*fixed/i', $r['html']), $r['html']);
check('inline_js_url_neutralized', !str_contains($r['html'], 'javascript'), $r['html']);

// ----------------------------------------------------------------------
// 2. CSS: limpieza + scoping
// ----------------------------------------------------------------------
$css = '@import url(evil.css); :root{--x:1} .hero{color:red;position:fixed} @media (max-width:600px){.hero{color:blue}} @keyframes spin{to{transform:rotate(1turn)}} @font-face{font-family:X;src:url(x.woff)}';
$scoped = CanvasSanitizer::sanitizeCss($css, '#pp-canvas-9');
check('css_import_removed', !str_contains($scoped, '@import'), $scoped);
check('css_root_scoped', str_contains($scoped, '#pp-canvas-9{--x:1}'), $scoped);
check('css_selector_scoped', str_contains($scoped, '#pp-canvas-9 .hero'), $scoped);
check('css_media_inner_scoped', (bool) preg_match('/@media[^{]+\{#pp-canvas-9 \.hero/', $scoped), $scoped);
check('css_keyframes_kept', str_contains($scoped, '@keyframes spin'), $scoped);
check('css_fontface_removed', !str_contains($scoped, '@font-face'), $scoped);
check('css_fixed_neutralized', !preg_match('/position\s*:\s*fixed/i', $scoped), $scoped);

// ----------------------------------------------------------------------
// 3. Servicio: save → versión → restore, y placeholders
// ----------------------------------------------------------------------
$siteId = 1;
$now = date('Y-m-d H:i:s');
Database::execute(
    'INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, "Canvas Test", ?, "landing", "canvas", "draft", 0, 999, ?, ?)',
    [$siteId, 'canvas-test-' . substr(bin2hex(random_bytes(3)), 0, 6), $now, $now]
);
$pageId = (int) Database::lastInsertId();

$v1 = CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Versión 1</h1></section>', '.hero{color:red}', 'generate');
$v2 = CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Versión 2</h1></section>', '.hero{color:blue}', 'chat');

$versions = CanvasService::versions($pageId);
check('two_versions_created', count($versions) === 2, json_encode($versions));

$current = CanvasService::get($pageId);
check('current_is_v2', str_contains($current['html'] ?? '', 'Versión 2'));

$oldestId = (int) end($versions)['id'];
$restoreState = CanvasService::restore($pageId, $oldestId);
check('restore_ok', $restoreState !== null);
$current = CanvasService::get($pageId);
check('restored_to_v1', str_contains($current['html'] ?? '', 'Versión 1'), (string) ($current['html'] ?? ''));
check('restore_moves_pointer_without_new_version', count(CanvasService::versions($pageId)) === 2);

// Placeholder de formulario: usar una sección form real del sitio si existe.
$formSection = Database::selectOne(
    "SELECT ps.id FROM page_sections ps JOIN pages p ON p.id = ps.page_id
     WHERE p.site_id = ? AND ps.section_type = 'form' LIMIT 1",
    [$siteId]
);
if ($formSection) {
    $hasForm = false;
    $out = CanvasService::expandPlaceholders('<p>antes</p>{{form:' . (int) $formSection['id'] . '}}<p>después</p>', $siteId, $hasForm);
    check('form_placeholder_expanded', str_contains($out, 'forms/' . (int) $formSection['id']) && $hasForm, mb_substr($out, 0, 300));
} else {
    echo "SKIP form_placeholder_expanded (no hay secciones form en el sitio 1)\n";
}
$hasForm = false;
$out = CanvasService::expandPlaceholders('{{form:no-existe-xyz}}', $siteId, $hasForm);
check('form_placeholder_missing_safe', str_contains($out, '<!--') && !$hasForm, $out);

// Placeholder de posts: debe usar el contrato real de posts_listing (`limit`),
// no una clave antigua que caiga al default del renderer.
PostMetaService::ensureSchema();
$postIds = [];
for ($i = 1; $i <= 4; $i++) {
    Database::execute(
        'INSERT INTO pages
            (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at, published_at)
         VALUES (?, ?, ?, "article", "sections", "published", 0, 999, ?, ?, ?)',
        [
            $siteId,
            'Canvas Embed Post ' . $i,
            'canvas-embed-post-' . $i . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            $now,
            $now,
            date('Y-m-d H:i:s', strtotime('+' . $i . ' minutes')),
        ]
    );
    $postIds[] = (int) Database::lastInsertId();
}
$hasForm = false;
$out = CanvasService::expandPlaceholders('{{posts:recent}}', $siteId, $hasForm);
check('posts_placeholder_limit_three', substr_count($out, 'class="pp-post-card ') === 3, $out);
check('posts_placeholder_marker_present', str_contains($out, 'data-pp-placeholder="posts:recent"'), mb_substr($out, 0, 300));

$hasForm = false;
$out = CanvasService::expandPlaceholders('{{ posts: recent | limit = 2 | variant = featured-first | heading = Blog Test | subheading = Sub Test | unknown = ignored }}', $siteId, $hasForm);
check('posts_placeholder_options_limit', substr_count($out, 'class="pp-post-card ') === 2, $out);
check('posts_placeholder_options_variant', str_contains($out, 'pp-section--posts_listing--featured-first'), mb_substr($out, 0, 500));
check('posts_placeholder_options_copy', str_contains($out, 'Blog Test') && str_contains($out, 'Sub Test'), mb_substr($out, 0, 500));
check('posts_placeholder_options_marker_canonical', str_contains($out, 'data-pp-placeholder="posts:recent|limit=2|variant=featured-first|heading=Blog Test|subheading=Sub Test"'), mb_substr($out, 0, 500));
if (!empty($postIds)) {
    Database::execute('DELETE FROM pages WHERE id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ')', $postIds);
}

// renderPublic: wrapper + css scoped
$render = CanvasService::renderPublic($pageId, $siteId);
check('render_has_wrapper', str_contains($render['html'], 'id="pp-canvas-' . $pageId . '"'), mb_substr($render['html'], 0, 200));
check('render_css_scoped', str_contains($render['html'], '#pp-canvas-' . $pageId . ' .hero'), mb_substr($render['html'], -300));

// Anti-slop: em-dash normalizado a guion
$r = CanvasSanitizer::sanitizeHtml('<section data-pp-section="h"><h1>Web rápida — y clara</h1></section>');
check('emdash_normalized', !str_contains($r['html'], "\xE2\x80\x94") && str_contains($r['html'], 'rápida - y clara'), $r['html']);

// FH5 — los comportamientos declarativos pasan el sanitizado intactos
$r = CanvasSanitizer::sanitizeHtml('<section data-pp-section="faq"><div data-pp-behavior="accordion"><details><summary>Pregunta</summary><p>Respuesta</p></details></details></div><div data-pp-behavior="reveal" data-pp-reveal-delay="2"><p>Tarjeta</p></div><span data-pp-behavior="counter">120</span></section>');
check('behavior_attrs_kept', substr_count($r['html'], 'data-pp-behavior') === 3, $r['html']);
check('details_summary_kept', str_contains($r['html'], '<details') && str_contains($r['html'], '<summary'), $r['html']);

// FH6 — compactación de CSS: duplicados exactos fuera (queda la última copia)
$dupes = '.a{color:red}.b{margin:0}.a{color:red}@media (max-width:600px){.a{color:blue}}.a{color:red}';
$compact = CanvasSanitizer::compactCss($dupes);
check('css_dedupe_exact', substr_count($compact, '.a{color:red}') === 1, $compact);
check('css_dedupe_keeps_others', str_contains($compact, '.b{margin:0}') && str_contains($compact, '@media'), $compact);
// el duplicado conservado es el ÚLTIMO (después de la @media)
check('css_dedupe_keeps_last', strpos($compact, '.a{color:red}') > strpos($compact, '@media'), $compact);

// FH3 — helpers de sección
$multi = '<section data-pp-section="hero"><h1>Uno</h1></section><section data-pp-section="cierre"><p>Dos</p></section>';
check('list_sections', count(CanvasService::listSections($multi)) === 2);
check('extract_section', str_contains((string) CanvasService::extractSection($multi, 'cierre'), 'Dos'));
$replaced = CanvasService::replaceSection($multi, 'hero', '<section data-pp-section="otro-id"><h1>Nuevo</h1></section>');
check('replace_section_content', str_contains((string) $replaced, 'Nuevo') && str_contains((string) $replaced, 'Dos'), (string) $replaced);
check('replace_section_keeps_anchor', str_contains((string) $replaced, 'data-pp-section="hero"'), (string) $replaced);
check('replace_section_missing_null', CanvasService::replaceSection($multi, 'no-existe', '<section><p>x</p></section>') === null);

// FH4 — edición inline: el embed expandido vuelve a ser placeholder y se
// limpian los restos del overlay del studio.
$edited = '<section data-pp-section="cierre" class="cv-x pp-studio-selected">'
    . '<h2 contenteditable="true" class="pp-studio-editing">Título corregido</h2>'
    . '<div class="pp-canvas-embed" data-pp-placeholder="form:391"><section class="pp-section"><form action="/forms/391"><input></form></section></div>'
    . '<img src="/storage/uploads/1/a.jpg" alt="F" data-pp-img-edit="1">'
    . '</section>';
$norm = CanvasService::normalizeEditedSectionHtml($edited);
check('inline_embed_reverted', str_contains($norm, '{{form:391}}') && !str_contains($norm, '<form'), $norm);
check('inline_studio_residue_clean', !str_contains($norm, 'contenteditable') && !str_contains($norm, 'pp-studio-') && !str_contains($norm, 'data-pp-img-edit'), $norm);
check('inline_edit_kept', str_contains($norm, 'Título corregido') && str_contains($norm, 'class="cv-x"'), $norm);

$editedPosts = '<section data-pp-section="blog">'
    . '<div class="pp-canvas-embed" data-pp-placeholder="posts:recent|limit=2|variant=featured-first|heading=Blog Test"><section class="pp-section">cards</section></div>'
    . '</section>';
$normPosts = CanvasService::normalizeEditedSectionHtml($editedPosts);
check('inline_posts_embed_options_reverted', str_contains($normPosts, '{{posts:recent|limit=2|variant=featured-first|heading=Blog Test}}') && !str_contains($normPosts, 'cards'), $normPosts);

// El render público envuelve los embeds con el marcador reversible.
if ($formSection) {
    $hasForm = false;
    $out = CanvasService::expandPlaceholders('{{form:' . (int) $formSection['id'] . '}}', $siteId, $hasForm);
    check('embed_marker_present', str_contains($out, 'data-pp-placeholder="form:' . (int) $formSection['id'] . '"'), mb_substr($out, 0, 200));
}

// Limpieza
Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);

exit($failed > 0 ? 1 : 0);
