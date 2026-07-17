<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasCrossPageReference;
use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function checkCrossPageReference(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
checkCrossPageReference('site_fixture_exists', $site !== null);
if ($site === null) exit(1);

$siteId = (int) $site['id'];
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$now = date('Y-m-d H:i:s');
$pageIds = [];

try {
    foreach ([
        ['Página Destino ' . $suffix, 'destino-' . $suffix],
        ['Página Modelo ' . $suffix, 'modelo-' . $suffix],
    ] as [$title, $slug]) {
        Database::execute(
            'INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
             VALUES (?, ?, ?, "landing", "canvas", "draft", 0, 999, ?, ?)',
            [$siteId, $title, $slug, $now, $now]
        );
        $pageIds[] = (int) Database::lastInsertId();
    }

    [$targetId, $sourceId] = $pageIds;
    CanvasService::save(
        $targetId,
        '<section data-pp-section="hero"><h1>Texto que debe conservarse</h1></section>',
        '.target-hero{color:#111}',
        'test'
    );
    CanvasService::save(
        $sourceId,
        '<section data-pp-section="sec-2"><div class="unique-layout-marker"><h2>Nuestro equipo referente</h2><p>Texto de referencia</p></div></section>'
        . '<section data-pp-section="metodologia"><div class="method-grid">Método fuente</div></section>',
        '.unique-layout-marker{display:grid;grid-template-columns:2fr 1fr}.method-grid{gap:3rem}',
        'test'
    );

    $targetPage = ['id' => $targetId, 'title' => 'Página Destino ' . $suffix];
    $instruction = 'Haz la sección hero igual que la sección Nuestro equipo referente de la página Página Modelo ' . $suffix;
    $reference = CanvasCrossPageReference::resolve($siteId, $targetPage, $instruction);

    checkCrossPageReference('source_page_resolved', (int) ($reference['page_id'] ?? 0) === $sourceId, json_encode($reference));
    checkCrossPageReference('source_section_resolved_by_visible_heading', ($reference['section_id'] ?? '') === 'sec-2', json_encode($reference));
    checkCrossPageReference('source_html_included', str_contains((string) ($reference['html'] ?? ''), 'unique-layout-marker'));
    checkCrossPageReference('source_css_included', str_contains((string) ($reference['css'] ?? ''), 'grid-template-columns'));
    checkCrossPageReference('content_preserved_by_default', ($reference['copy_content'] ?? true) === false);

    $copyInstruction = $instruction . ' y copia también sus textos y contenido.';
    $copyReference = CanvasCrossPageReference::resolve($siteId, $targetPage, $copyInstruction);
    checkCrossPageReference('content_copy_requires_explicit_request', ($copyReference['copy_content'] ?? false) === true);

    $unrelated = CanvasCrossPageReference::resolve(
        $siteId,
        $targetPage,
        'Pon el titular más grande y el botón naranja.'
    );
    checkCrossPageReference('ordinary_edit_has_no_reference', $unrelated === null, json_encode($unrelated));

    $prompt = CanvasCrossPageReference::promptBlock((array) $reference);
    checkCrossPageReference(
        'prompt_protects_source_content',
        str_contains($prompt, 'conserva el contenido') && str_contains($prompt, 'NO modifiques la página de referencia'),
        $prompt
    );
} finally {
    if ($pageIds !== []) {
        Database::execute(
            'DELETE FROM pages WHERE id IN (' . implode(',', array_fill(0, count($pageIds), '?')) . ')',
            $pageIds
        );
    }
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
