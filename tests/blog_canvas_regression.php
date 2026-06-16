<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ArticleTemplateService;
use App\Services\CacheService;
use App\Services\Canvas\CanvasService;
use App\Services\PostMetaService;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

$failed = 0;
function check_blog_canvas(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId <= 0) {
    echo "SKIP no_site\n";
    exit(0);
}

PostMetaService::ensureSchema();
SectionRenderer::setSiteContext($siteId);

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$pageIds = [];
$previousTemplate = Database::selectOne(
    'SELECT setting_value, is_encrypted FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, ArticleTemplateService::SETTING_KEY]
);

$insertPage = static function (string $title, string $slug, string $type, string $status = 'published') use ($siteId, &$pageIds): int {
    $now = date('Y-m-d H:i:s');
    Database::execute(
        'INSERT INTO pages
            (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at, published_at)
         VALUES (?, ?, ?, ?, "sections", ?, 0, 999, ?, ?, ?)',
        [$siteId, $title, $slug, $type, $status, $now, $now, $status === 'published' ? $now : null]
    );
    $id = (int) Database::lastInsertId();
    $pageIds[] = $id;
    return $id;
};

try {
    $blocks = [
        'blocks' => [
            ['type' => 'paragraph', 'text' => 'Primer párrafo editorial para comprobar el render largo.'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Un subtítulo'],
            ['type' => 'list', 'style' => 'unordered', 'items' => ['Punto uno', 'Punto dos']],
            ['type' => 'quote', 'text' => 'Una cita del artículo.', 'attribution' => 'PromptPress'],
        ],
    ];

    $articleIds = [];
    for ($i = 1; $i <= 4; $i++) {
        $id = $insertPage('BC7 Entrada ' . $i, 'bc7-post-' . $i . '-' . $suffix, 'article');
        $articleIds[] = $id;
        Database::execute(
            'INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
             VALUES (?, "article_body", 0, ?, NULL, "editable", ?, ?)',
            [$id, json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );
        PostMetaService::save($id, [
            'excerpt' => 'Resumen temporal ' . $i,
            'reading_minutes' => 2,
            'author_name' => 'Equipo PromptPress',
        ]);
    }

    $legalId = $insertPage('BC7 Legal', 'bc7-legal-' . $suffix, 'legal');
    Database::execute(
        'INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
         VALUES (?, "article_body", 0, ?, NULL, "editable", ?, ?)',
        [$legalId, json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
    );

    $listing = SectionRenderer::render([
        'id' => 0,
        'section_type' => 'posts_listing',
        'content' => json_encode(['heading' => 'Últimas entradas', 'subheading' => 'Regresión BC7', 'limit' => '4'], JSON_UNESCAPED_UNICODE),
        'style' => json_encode(['variant' => 'featured-first']),
    ]);
    check_blog_canvas('classic_posts_listing_cards', substr_count($listing, 'class="pp-post-card ') === 4, $listing);
    check_blog_canvas('classic_posts_listing_variant', str_contains($listing, 'pp-posts-listing--v-featured-first'), $listing);

    $hasForm = false;
    $canvas = CanvasService::expandPlaceholders('{{posts:recent|limit=2|variant=editorial-list|heading=Blog BC7}}', $siteId, $hasForm);
    check_blog_canvas('canvas_posts_limit_two', substr_count($canvas, 'class="pp-post-card ') === 2, $canvas);
    check_blog_canvas('canvas_posts_editorial_variant', str_contains($canvas, 'pp-section--posts_listing--editorial-list'), $canvas);
    check_blog_canvas('canvas_posts_no_form_flag', $hasForm === false);

    $articleBody = SectionRenderer::render([
        'id' => 0,
        'section_type' => 'article_body',
        'content' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'style' => null,
    ]);
    check_blog_canvas('article_body_renders_paragraph', str_contains($articleBody, 'Primer párrafo editorial'), $articleBody);
    check_blog_canvas('legal_shared_article_body_safe', str_contains($articleBody, 'pp-article-body__quote'), $articleBody);

    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
         VALUES (?, ?, "visual", 0)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
        [$siteId, ArticleTemplateService::SETTING_KEY]
    );
    check_blog_canvas('article_template_setting_visual', ArticleTemplateService::forSite($siteId) === 'visual');

    $target = Database::selectOne('SELECT * FROM pages WHERE id = ? LIMIT 1', [$articleIds[0]]);
    CacheService::put($siteId, (string) $target['slug'], 'stale');
    check_blog_canvas('cache_put_before_invalidate', CacheService::get($siteId, (string) $target['slug']) === 'stale');
    CacheService::invalidatePage($siteId, $target);
    check_blog_canvas('cache_invalidated_for_article', CacheService::get($siteId, (string) $target['slug']) === null);
} finally {
    if (!empty($pageIds)) {
        Database::execute('DELETE FROM pages WHERE id IN (' . implode(',', array_fill(0, count($pageIds), '?')) . ')', $pageIds);
    }
    Database::execute(
        'DELETE FROM settings WHERE site_id = ? AND setting_key = ?',
        [$siteId, ArticleTemplateService::SETTING_KEY]
    );
    if ($previousTemplate) {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, ?)',
            [
                $siteId,
                ArticleTemplateService::SETTING_KEY,
                (string) $previousTemplate['setting_value'],
                (int) $previousTemplate['is_encrypted'],
            ]
        );
    }
}

exit($failed > 0 ? 1 : 0);
