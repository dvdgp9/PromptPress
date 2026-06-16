<?php

namespace App\Controllers\Public;

use App\Services\CacheService;
use App\Services\ArticleTemplateService;
use App\Services\BrandService;
use App\Services\DesignSystem;
use App\Services\PostMetaService;
use App\Services\Renderer\SectionRenderer;
use App\Services\Seo404Service;
use App\Services\SeoIndexingService;
use App\Services\SeoRedirectService;
use App\Services\SeoStructuredDataService;
use App\Services\VisualStyleService;
use Core\Database;
use Core\Response;

/**
 * Renderiza páginas públicas publicadas (T7.2).
 *
 * Rutas:
 *   GET /           → home (page_type=home publicada, o slug='home')
 *   GET /{slug}     → página por slug, 404 si no existe o no publicada
 *
 * En fases posteriores (T7.3) añadiremos cache. T7.4 añadirá URL anidadas.
 */
final class PageController
{
    public function home(array $params = []): void
    {
        $siteId = self::requireSiteId();

        $page = Database::selectOne(
            "SELECT * FROM pages
             WHERE site_id = ? AND status = 'published' AND page_type = 'home'
             ORDER BY updated_at DESC LIMIT 1",
            [$siteId]
        ) ?? Database::selectOne(
            "SELECT * FROM pages
             WHERE site_id = ? AND status = 'published' AND slug = 'home'
             LIMIT 1",
            [$siteId]
        );

        if (!$page) {
            self::renderFallback($siteId);
            return;
        }

        if (!self::pageHasForm((int) $page['id'])) {
            $cached = CacheService::get($siteId, CacheService::HOME_KEY);
            if ($cached !== null) {
                self::serve($cached, true);
            }
        }
        self::render($page, $siteId, CacheService::HOME_KEY);
    }

    public function show(array $params = []): void
    {
        $slug   = (string) ($params['slug'] ?? '');
        $siteId = self::requireSiteId();

        // Validar slug (solo a-z, 0-9, -, _, /)
        if ($slug === '' || !preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $slug)) {
            Response::notFound();
        }

        $page = Database::selectOne(
            "SELECT * FROM pages
             WHERE site_id = ? AND slug = ? AND status = 'published'
             LIMIT 1",
            [$siteId, $slug]
        );
        if (!$page) {
            self::redirectOrRecord404($siteId, '/' . ltrim($slug, '/'));
            Response::notFound();
        }

        if (!self::pageHasForm((int) $page['id'])) {
            $cached = CacheService::get($siteId, $slug);
            if ($cached !== null) {
                self::serve($cached, true);
            }
        }
        self::render($page, $siteId, $slug);
    }

    private static function redirectOrRecord404(int $siteId, string $path): void
    {
        $redirect = SeoRedirectService::findActive($siteId, $path);
        if ($redirect) {
            SeoRedirectService::recordHit((int) $redirect['id']);
            $status = (int) $redirect['status_code'];
            if ($status === 410) {
                http_response_code(410);
                header('Content-Type: text/html; charset=UTF-8');
                echo '<h1>410 — Contenido retirado</h1>';
                exit;
            }

            $target = (string) ($redirect['target_path'] ?? '/');
            Response::redirect(base_url(ltrim($target, '/')), $status === 302 ? 302 : 301);
        }

        Seo404Service::record($siteId, $path, (string) ($_SERVER['QUERY_STRING'] ?? ''));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Compone el HTML completo y lo sirve. Si $cacheKey está dado, también lo persiste en cache.
     */
    private static function render(array $page, int $siteId, ?string $cacheKey = null): void
    {
        // FH1 — páginas "canvas": el cuerpo es HTML libre saneado, no secciones.
        $isCanvas = (($page['render_mode'] ?? 'sections') === 'canvas');
        $canvasHasForm = false;

        $sections = $isCanvas ? [] : Database::select(
            'SELECT id, section_type, sort_order, content, style, status
             FROM page_sections WHERE page_id = ?
             ORDER BY sort_order ASC, id ASC',
            [(int) $page['id']]
        );

        $site = Database::selectOne('SELECT name, language, url FROM sites WHERE id = ?', [$siteId]) ?? [];
        $lang = (string) ($site['language'] ?? 'es');

        $title    = (string) ($page['meta_title'] ?: $page['title']);
        $metaDesc = (string) ($page['meta_description'] ?? '');
        $siteName = (string) ($site['name'] ?? '');
        $canon    = SeoIndexingService::canonicalForPage($site, $page);

        $styleSlug = VisualStyleService::selectedForSite($siteId);
        $designHead = DesignSystem::renderHead($siteId, $styleSlug);
        SectionRenderer::setSiteContext($siteId);

        // F21.T21.2.d — Si la página es una entrada de blog, anteponemos un
        // hero automático (featured image + título + meta) antes del cuerpo.
        $isArticle = (($page['page_type'] ?? '') === 'article');
        $articleHero = '';
        $articleTemplate = ArticleTemplateService::DEFAULT;
        $postMeta = null;
        if ($isArticle) {
            $articleTemplate = ArticleTemplateService::forSite($siteId);
            $postMeta = PostMetaService::load((int) $page['id']);
            $articleHero = self::renderArticleHero($page, $postMeta);
            // Si no hay meta_description, usamos el excerpt para SEO/OG.
            if ($metaDesc === '' && !empty($postMeta['excerpt'])) {
                $metaDesc = (string) $postMeta['excerpt'];
            }
        }

        if ($isCanvas) {
            $canvas = \App\Services\Canvas\CanvasService::renderPublic((int) $page['id'], $siteId);
            $body = $canvas['html'];
            $canvasHasForm = $canvas['has_form'];
        } else {
            $body = SectionRenderer::renderMany($sections);
        }

        $h  = '<!doctype html>';
        $h .= '<html lang="' . e($lang) . '"><head>';
        $h .= '<meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<title>' . e($title) . ($siteName !== '' && $siteName !== $title ? ' — ' . e($siteName) : '') . '</title>';
        if ($metaDesc !== '') {
            $h .= '<meta name="description" content="' . e($metaDesc) . '">';
        }
        if ($canon !== '') {
            $h .= '<link rel="canonical" href="' . e($canon) . '">';
        }
        $robotsMeta = SeoIndexingService::robotsMeta($page);
        if ($robotsMeta !== '') {
            $h .= '<meta name="robots" content="' . e($robotsMeta) . '">';
        }
        // Open Graph básico
        $h .= '<meta property="og:type" content="' . ($isArticle ? 'article' : 'website') . '">';
        $h .= '<meta property="og:title" content="' . e($title) . '">';
        if ($metaDesc !== '') {
            $h .= '<meta property="og:description" content="' . e($metaDesc) . '">';
        }
        // og:image desde featured image si la entrada la tiene.
        if ($isArticle && $postMeta && !empty($postMeta['featured_image_path'])) {
            $ogImg = (string) $postMeta['featured_image_path'];
            if (!preg_match('#^https?://#i', $ogImg)) {
                $ogImg = base_url(ltrim($ogImg, '/'));
            }
            $h .= '<meta property="og:image" content="' . e($ogImg) . '">';
            $h .= '<meta name="twitter:card" content="summary_large_image">';
        }
        if ($canon !== '') {
            $h .= '<meta property="og:url" content="' . e($canon) . '">';
        }
        if ($siteName !== '') {
            $h .= '<meta property="og:site_name" content="' . e($siteName) . '">';
        }
        // twitter:card por defecto. Si ya se emitió summary_large_image arriba (article con imagen), no lo duplicamos.
        if (!$isArticle || !$postMeta || empty($postMeta['featured_image_path'])) {
            $h .= '<meta name="twitter:card" content="summary">';
        }
        $h .= SeoStructuredDataService::render($site, $page, $metaDesc, $postMeta);
        $h .= $designHead;
        $bodyClasses = [VisualStyleService::bodyClass($styleSlug)];
        if ($isArticle) {
            $bodyClasses[] = ArticleTemplateService::bodyClass($articleTemplate);
        }
        $h .= '</head><body class="' . e(implode(' ', array_filter($bodyClasses))) . '">';
        $h .= BrandService::publicHeader($siteId);
        $mainClass = $isArticle
            ? ' class="pp-article-page ' . e(ArticleTemplateService::bodyClass($articleTemplate)) . '"'
            : '';
        $h .= '<main' . $mainClass . '>' . $articleHero . $body . '</main>';
        $h .= BrandService::publicFooter($siteId);
        // FH5 — comportamientos declarativos (acordeón, slider, reveal, contador)
        // y menú móvil del header. Curado y único para todo el sitio.
        $h .= '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        $h .= '</body></html>';

        // T7.3: persistir en cache antes de servir. Las páginas con formularios
        // llevan CSRF por sesión, así que no se cachean como HTML estático.
        $cacheable = $isCanvas ? !$canvasHasForm : !self::sectionsHaveForm($sections);
        if ($cacheKey !== null && $cacheable) {
            CacheService::put($siteId, $cacheKey, $h);
        }
        self::serve($h, false);
    }

    /**
     * Sirve HTML al cliente con un header X-PP-Cache para diagnosticar HIT/MISS en dev.
     */
    /**
     * F21.T21.2.d — Render del hero automático de una entrada de blog.
     * Muestra featured image (si hay), título grande, y meta (autor · fecha · reading).
     * Se inserta automáticamente delante del cuerpo del artículo en el render público.
     */
    private static function renderArticleHero(array $page, array $meta): string
    {
        $title = (string) ($page['title'] ?? '');
        if ($title === '') return '';
        $publishedAt = (string) ($page['published_at'] ?? '');
        $img    = trim((string) ($meta['featured_image_path'] ?? ''));
        $imgAlt = (string) ($meta['featured_image_alt'] ?? '');
        $author = trim((string) ($meta['author_name'] ?? ''));
        $reading = (int) ($meta['reading_minutes'] ?? 0);
        $excerpt = trim((string) ($meta['excerpt'] ?? ''));

        $imgSrc = '';
        if ($img !== '') {
            $imgSrc = preg_match('#^https?://#i', $img) ? $img : base_url(ltrim($img, '/'));
        }

        $h = '<header class="pp-article-hero">';
        $h .= '<div class="pp-article-hero__inner container">';

        // Eyebrow meta (autor · fecha · reading time)
        $metaParts = [];
        if ($publishedAt !== '') {
            $ts = strtotime($publishedAt);
            if ($ts) {
                $months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
                $metaParts[] = '<time datetime="' . e(date('c', $ts)) . '">' . (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) . '</time>';
            }
        }
        if ($author !== '') $metaParts[] = '<span>' . e($author) . '</span>';
        if ($reading > 0)   $metaParts[] = '<span>' . (int) $reading . ' min de lectura</span>';
        if (!empty($metaParts)) {
            $h .= '<p class="pp-article-hero__meta">' . implode(' · ', $metaParts) . '</p>';
        }

        $h .= '<h1 class="pp-article-hero__title">' . e($title) . '</h1>';
        if ($excerpt !== '') {
            $h .= '<p class="pp-article-hero__lead">' . e($excerpt) . '</p>';
        }

        $h .= '</div>';

        // Featured image full-width fuera del container interno
        if ($imgSrc !== '') {
            $h .= '<figure class="pp-article-hero__media">';
            $h .= '<img src="' . e($imgSrc) . '" alt="' . e($imgAlt !== '' ? $imgAlt : $title) . '" loading="eager" decoding="async">';
            // Atribución pintada por el renderer si proviene del banco.
            $h .= SectionRenderer::publicImageAttribution($imgSrc);
            $h .= '</figure>';
        }

        $h .= '</header>';
        return $h;
    }

    private static function serve(string $html, bool $hit): never
    {
        header('X-PP-Cache: ' . ($hit ? 'HIT' : 'MISS'));
        Response::html($html);
    }

    /** Fallback para cuando no hay home publicada (instalación nueva). */
    private static function renderFallback(int $siteId): void
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ?', [$siteId]) ?? [];
        $siteName = (string) ($site['name'] ?? 'PromptPress');
        $adminUrl = e(base_url('admin/'));
        $styleSlug = VisualStyleService::selectedForSite($siteId);
        $designHead = DesignSystem::renderHead($siteId, $styleSlug);

        Response::html(
            '<!doctype html>'
          . '<html lang="es"><head>'
          . '<meta charset="utf-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<meta name="robots" content="noindex,nofollow">'
          . '<title>' . e($siteName) . '</title>'
          . $designHead
          . '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">'
          . BrandService::publicHeader($siteId)
          . '<div class="pp-section"><div class="container" style="text-align:center">'
          . '<h1>' . e($siteName) . '</h1>'
          . '<p>Aún no hay una página de inicio publicada.</p>'
          . '<p><a class="pp-btn pp-btn--primary" href="' . $adminUrl . '">Ir al panel de admin</a></p>'
          . '</div></div>'
          . '</body></html>'
        );
    }

    private static function requireSiteId(): int
    {
        $site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
        if (!$site) {
            Response::html(
                '<!doctype html><meta charset="utf-8"><title>PromptPress</title>'
              . '<div style="font-family:system-ui;padding:2rem;max-width:640px;margin:auto">'
              . '<h1>PromptPress</h1>'
              . '<p>Sistema no instalado. <a href="' . e(base_url('install/')) . '">Instalar</a></p></div>'
            );
        }
        return (int) $site['id'];
    }

    private static function pageHasForm(int $pageId): bool
    {
        $row = Database::selectOne(
            "SELECT id FROM page_sections WHERE page_id = ? AND section_type = 'form' LIMIT 1",
            [$pageId]
        );
        return $row !== null;
    }

    private static function sectionsHaveForm(array $sections): bool
    {
        foreach ($sections as $section) {
            if (($section['section_type'] ?? '') === 'form') return true;
        }
        return false;
    }
}
