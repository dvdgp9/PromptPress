<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Seo404Service;
use App\Services\SeoIndexingService;
use App\Services\SeoRedirectService;
use App\Services\SeoTechnicalAuditService;
use App\Controllers\Public\SeoController as PublicSeoController;
use Core\Database;

$failed = 0;
function check_seo(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId <= 0) {
    echo "SKIP no_site\n";
    exit(0);
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$paths = [
    '/seo-old-' . $suffix,
    '/seo-new-' . $suffix,
    '/seo-a-' . $suffix,
    '/seo-b-' . $suffix,
    '/missing-seo-' . $suffix,
];

try {
    check_seo('normalize_full_url', SeoRedirectService::normalizePath('https://example.com/foo/bar/') === '/foo/bar');
    check_seo('normalize_plain_slug', SeoRedirectService::normalizePath('foo') === '/foo');
    check_seo('ignore_static_asset_404', Seo404Service::shouldIgnore('/public/app.css') === true);
    check_seo('do_not_ignore_public_slug_404', Seo404Service::shouldIgnore('/servicio-roto') === false);
    check_seo('canonical_accepts_absolute', SeoIndexingService::normalizeCanonical('https://example.com/a') === 'https://example.com/a');
    check_seo('canonical_rejects_relative', SeoIndexingService::normalizeCanonical('/a') === null);
    $stats = SeoTechnicalAuditService::htmlStats('<h1>Uno</h1><h1>Dos</h1><img src="/a.jpg"><img src="/b.jpg" alt="Foto">');
    check_seo('technical_counts_h1', (int) $stats['h1_count'] === 2, json_encode($stats));
    check_seo('technical_counts_missing_alt', (int) $stats['images_without_alt'] === 1, json_encode($stats));

    $r1 = SeoRedirectService::createManual($siteId, $paths[0], $paths[1], 301, null);
    check_seo('manual_redirect_created', (int) ($r1['status_code'] ?? 0) === 301, json_encode($r1));
    check_seo('manual_redirect_normalized', ($r1['source_path'] ?? '') === $paths[0] && ($r1['target_path'] ?? '') === $paths[1]);

    $r2 = SeoRedirectService::createManual($siteId, $paths[0], $paths[1] . '-updated', 302, null);
    check_seo('manual_redirect_idempotent_update', (int) $r2['id'] === (int) $r1['id']);
    check_seo('manual_redirect_updates_target', (int) $r2['status_code'] === 302 && $r2['target_path'] === $paths[1] . '-updated');

    SeoRedirectService::createManual($siteId, $paths[3], $paths[2], 301, null);
    $loopThrown = false;
    try {
        SeoRedirectService::createManual($siteId, $paths[2], $paths[3], 301, null);
    } catch (\Throwable $e) {
        $loopThrown = true;
    }
    check_seo('simple_loop_rejected', $loopThrown);

    $_SERVER['QUERY_STRING'] = 'x=1';
    $_SERVER['HTTP_REFERER'] = 'https://ref.example/source';
    $_SERVER['HTTP_USER_AGENT'] = 'PromptPress Test Agent';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    Seo404Service::record($siteId, $paths[4], 'x=1');
    Seo404Service::record($siteId, $paths[4], 'x=1');
    $log = Database::selectOne(
        'SELECT * FROM seo_404_logs WHERE site_id = ? AND requested_path = ? LIMIT 1',
        [$siteId, $paths[4]]
    );
    check_seo('404_log_created', $log !== null);
    check_seo('404_log_aggregates_hits', (int) ($log['hit_count'] ?? 0) === 2, json_encode($log));

    $now = date('Y-m-d H:i:s');
    $homeSlug = 'seo-home-' . $suffix;
    $articleSlug = 'seo-articulo-' . $suffix;
    $draftSlug = 'seo-borrador-' . $suffix;
    $noindexSlug = 'seo-noindex-' . $suffix;
    $excludedSlug = 'seo-excluded-' . $suffix;
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description, status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
         VALUES
            (?, 'SEO Home Test', ?, 'home', NULL, NULL, NULL, NULL, 'published', 0, 0, NULL, ?, ?, ?),
            (?, 'SEO Article Test', ?, 'article', NULL, NULL, NULL, NULL, 'published', 0, 0, NULL, ?, ?, ?),
            (?, 'SEO Draft Test', ?, 'landing', NULL, NULL, NULL, NULL, 'draft', 0, 0, NULL, ?, ?, NULL)",
        [$siteId, $homeSlug, $now, $now, $now, $siteId, $articleSlug, $now, $now, $now, $siteId, $draftSlug, $now, $now]
    );
    $xml = PublicSeoController::sitemapXml($siteId);
    check_seo('sitemap_has_urlset', str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'));
    check_seo('sitemap_home_uses_root', str_contains($xml, '<loc>http://localhost/</loc>') || preg_match('#<loc>https?://[^<]+/</loc>#', $xml) === 1, $xml);
    check_seo('sitemap_includes_published_article', str_contains($xml, '/' . $articleSlug . '</loc>'), $xml);
    check_seo('sitemap_excludes_draft', !str_contains($xml, $draftSlug), $xml);
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description, seo_noindex, seo_exclude_sitemap, status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
         VALUES
            (?, 'SEO Noindex Test', ?, 'landing', NULL, NULL, NULL, NULL, 1, 0, 'published', 0, 0, NULL, ?, ?, ?),
            (?, 'SEO Excluded Test', ?, 'landing', NULL, NULL, NULL, NULL, 0, 1, 'published', 0, 0, NULL, ?, ?, ?)",
        [$siteId, $noindexSlug, $now, $now, $now, $siteId, $excludedSlug, $now, $now, $now]
    );
    $xml2 = PublicSeoController::sitemapXml($siteId);
    check_seo('sitemap_excludes_noindex', !str_contains($xml2, $noindexSlug), $xml2);
    check_seo('sitemap_excludes_manual_exclusion', !str_contains($xml2, $excludedSlug), $xml2);
} finally {
    Database::execute(
        'DELETE FROM pages WHERE site_id = ? AND slug IN (?, ?, ?, ?, ?)',
        [$siteId, 'seo-home-' . $suffix, 'seo-articulo-' . $suffix, 'seo-borrador-' . $suffix, 'seo-noindex-' . $suffix, 'seo-excluded-' . $suffix]
    );
    Database::execute(
        'DELETE FROM seo_404_logs WHERE site_id = ? AND requested_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')',
        array_merge([$siteId], $paths)
    );
    Database::execute(
        'DELETE FROM seo_redirects WHERE site_id = ? AND source_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')',
        array_merge([$siteId], $paths)
    );
}

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
