<?php

declare(strict_types=1);

/**
 * I18N-FULL FASE 4 — SEO multi-idioma.
 *
 * Tres cosas que, mal hechas, hacen daño de verdad en buscadores:
 *   - `hreflang` recíprocos + `x-default`, para que Google sepa que son
 *     versiones idiomáticas y no contenido duplicado;
 *   - el sitemap declarando esas alternativas;
 *   - el `canonical`, que ANTES de esta fase apuntaba la home francesa a la
 *     raíz del sitio (o sea: «soy un duplicado de la home castellana»).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Public\SeoController;
use App\Services\LanguageService;
use App\Services\SeoHreflangService;
use App\Services\SeoIndexingService;
use Core\Database;

$failed = 0;
function checkSeo(string $name, bool $ok, string $detail = ''): void
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
Database::execute(
    "DELETE FROM pages WHERE site_id = ? AND (slug LIKE 'qa4-%' OR slug LIKE 'fr/qa4-%' OR slug = 'fr')",
    [$siteId]
);
$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

$site = Database::selectOne('SELECT id, name, language, url FROM sites WHERE id = ?', [$siteId]);
$base = rtrim((string) $site['url'], '/');

$made = [];
$mk = function (string $title, string $slug, string $type, string $lang, string $grp) use ($siteId, &$made): array {
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode, created_at, updated_at, published_at)
         VALUES (?, ?, ?, ?, ?, ?, 'published', 'sections', NOW(), NOW(), NOW())",
        [$siteId, $title, $slug, $type, $lang, $grp]
    );
    $id = (int) Database::lastInsertId();
    $made[] = $id;
    return Database::selectOne('SELECT * FROM pages WHERE id = ?', [$id]);
};

$grp     = 'qa4-' . bin2hex(random_bytes(3));
$esPage  = $mk('Servicios QA4', 'qa4-servicios', 'service', $primary, $grp);
$frPage  = $mk('Services QA4', 'fr/qa4-services', 'service', 'fr', $grp);
$frHome  = $mk('Accueil QA4', 'fr', 'home', 'fr', 'qa4-home-fr');
$esAlone = $mk('Solo ES QA4', 'qa4-solo', 'landing', $primary, 'qa4-solo-grp');

// ---------------------------------------------------------------------------
// T4.3 — Canonical (el fallo más grave que había)
// ---------------------------------------------------------------------------

checkSeo(
    'secondary_home_canonical_is_not_the_site_root',
    SeoIndexingService::canonicalForPage($site, $frHome) === $base . '/fr',
    'canonical de la home francesa: ' . SeoIndexingService::canonicalForPage($site, $frHome)
        . ' (antes decía «soy la home castellana»)'
);

$esHome = Database::selectOne(
    "SELECT * FROM pages WHERE site_id = ? AND page_type = 'home' AND language = ? AND status = 'published' LIMIT 1",
    [$siteId, $primary]
);
if ($esHome !== null) {
    checkSeo(
        'primary_home_canonical_is_still_the_root',
        SeoIndexingService::canonicalForPage($site, $esHome) === $base . '/',
        SeoIndexingService::canonicalForPage($site, $esHome)
    );
}

checkSeo(
    'inner_page_canonical_keeps_its_prefix',
    SeoIndexingService::canonicalForPage($site, $frPage) === $base . '/fr/qa4-services',
    SeoIndexingService::canonicalForPage($site, $frPage)
);

// ---------------------------------------------------------------------------
// T4.1 — hreflang recíprocos + x-default
// ---------------------------------------------------------------------------

$alts = SeoHreflangService::alternatesFor($siteId, $esPage, $site);
checkSeo(
    'alternates_include_both_languages',
    isset($alts[$primary], $alts['fr'])
        && $alts['fr'] === $base . '/fr/qa4-services'
        && $alts[$primary] === $base . '/qa4-servicios',
    json_encode($alts, JSON_UNESCAPED_SLASHES)
);

// Recíprocos: lo que ve la francesa tiene que ser lo mismo que ve la castellana.
$altsFr = SeoHreflangService::alternatesFor($siteId, $frPage, $site);
checkSeo(
    'alternates_are_reciprocal',
    $altsFr == $alts,
    'ES: ' . json_encode($alts, JSON_UNESCAPED_SLASHES) . ' · FR: ' . json_encode($altsFr, JSON_UNESCAPED_SLASHES)
);

// Una página sin traducir NO debe emitir hreflang: declararse sola es ruido.
checkSeo(
    'untranslated_page_emits_no_alternates',
    SeoHreflangService::alternatesFor($siteId, $esAlone, $site) === [],
    json_encode(SeoHreflangService::alternatesFor($siteId, $esAlone, $site))
);

$tags = SeoHreflangService::renderTags($siteId, $esPage, $site);
checkSeo(
    'head_tags_are_well_formed',
    str_contains($tags, '<link rel="alternate" hreflang="fr" href="' . $base . '/fr/qa4-services">')
        && str_contains($tags, 'hreflang="' . $primary . '"')
        && str_contains($tags, 'hreflang="x-default"'),
    $tags
);

// x-default apunta al idioma principal: es la versión a la que mandar a quien
// no encaja en ningún idioma declarado.
checkSeo(
    'x_default_points_to_the_primary_language',
    str_contains($tags, '<link rel="alternate" hreflang="x-default" href="' . $base . '/qa4-servicios">'),
    $tags
);

checkSeo(
    'monolingual_page_gets_no_tags',
    SeoHreflangService::renderTags($siteId, $esAlone, $site) === '',
    'una página sin versiones alternativas no debe ensuciar el <head>'
);

// ---------------------------------------------------------------------------
// T4.2 — Sitemap con alternates
// ---------------------------------------------------------------------------

$xml = SeoController::sitemapXml($siteId);

checkSeo(
    'sitemap_declares_the_xhtml_namespace',
    str_contains($xml, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"'),
    substr($xml, 0, 200)
);

checkSeo(
    'sitemap_lists_the_secondary_home_with_its_prefix',
    str_contains($xml, '<loc>' . $base . '/fr</loc>'),
    'la home francesa debe aparecer como /fr, no como la raíz'
);

checkSeo(
    'sitemap_includes_alternates_for_translated_pages',
    str_contains($xml, '<xhtml:link rel="alternate" hreflang="fr" href="' . $base . '/fr/qa4-services"/>')
        && str_contains($xml, '<xhtml:link rel="alternate" hreflang="x-default"'),
    'sin alternates el sitemap no aporta nada al multi-idioma'
);

$rootCount = substr_count($xml, '<loc>' . $base . '/</loc>');
checkSeo(
    'site_root_appears_exactly_once',
    $rootCount === 1,
    'la raíz aparece ' . $rootCount . ' veces (la home francesa la duplicaba)'
);

// XML válido de verdad, no solo cadenas que lo parecen.
$prev = libxml_use_internal_errors(true);
$parsed = simplexml_load_string($xml);
libxml_use_internal_errors($prev);
checkSeo('sitemap_is_valid_xml', $parsed !== false, 'el sitemap no parsea');

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

if ($made !== []) {
    Database::execute('DELETE FROM pages WHERE id IN (' . implode(',', array_map('intval', $made)) . ')');
}
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkSeo(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary],
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
