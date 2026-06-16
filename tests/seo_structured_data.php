<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\SeoStructuredDataService;

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

$failed = 0;
function check_structured(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$site = ['name' => 'PromptPress Demo', 'url' => 'https://example.test'];
$page = [
    'title' => 'Servicios',
    'meta_title' => 'Servicios profesionales',
    'slug' => 'servicios',
    'page_type' => 'service',
    'updated_at' => '2026-06-15 10:30:00',
];

$graph = SeoStructuredDataService::graph($site, $page, 'Descripción comercial clara.');
check_structured('website_node_present', ($graph[1]['@type'] ?? '') === 'WebSite');
check_structured('webpage_node_present', ($graph[2]['@type'] ?? '') === 'WebPage');
check_structured('webpage_url', ($graph[2]['url'] ?? '') === 'https://example.test/servicios');
check_structured('webpage_description', ($graph[2]['description'] ?? '') === 'Descripción comercial clara.');
check_structured('no_fake_author_on_page', !isset($graph[2]['author']));

$article = [
    'title' => 'Guía de SEO',
    'meta_title' => '',
    'slug' => 'guia-seo',
    'page_type' => 'article',
    'published_at' => '2026-06-10 09:00:00',
    'updated_at' => '2026-06-15 11:00:00',
];
$meta = [
    'author_name' => 'Ana García',
    'featured_image_path' => 'storage/uploads/1/seo.jpg',
];
$articleGraph = SeoStructuredDataService::graph($site, $article, 'Resumen SEO.', $meta);
$articleNode = $articleGraph[2] ?? [];
check_structured('article_node_present', ($articleNode['@type'] ?? '') === 'Article');
check_structured('article_headline', ($articleNode['headline'] ?? '') === 'Guía de SEO');
check_structured('article_author_person', ($articleNode['author']['name'] ?? '') === 'Ana García');
check_structured('article_image_absolute', str_contains((string) ($articleNode['image'][0] ?? ''), '/storage/uploads/1/seo.jpg'));
check_structured('article_dates_iso', str_contains((string) ($articleNode['datePublished'] ?? ''), '2026-06-10T'));

$html = SeoStructuredDataService::render($site, $article, 'Resumen SEO.', $meta);
check_structured('render_script_tag', str_starts_with($html, '<script type="application/ld+json">'));
check_structured('render_has_schema_context', str_contains($html, 'https://schema.org'));

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
