<?php

namespace App\Controllers\Public;

use Core\Database;

final class SeoController
{
    public function sitemap(array $params = []): void
    {
        $siteId = self::siteId();
        $xml = self::sitemapXml($siteId);

        http_response_code(200);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
        echo $xml;
        exit;
    }

    public function robots(array $params = []): void
    {
        $site = self::site();
        $base = self::siteBaseUrl($site);

        $body = "User-agent: *\n"
              . "Allow: /\n"
              . "Disallow: /admin/\n"
              . "Disallow: /install/\n"
              . "\n"
              . "Sitemap: " . $base . "/sitemap.xml\n";

        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
        echo $body;
        exit;
    }

    public static function sitemapXml(int $siteId): string
    {
        $site = self::site($siteId);
        $base = self::siteBaseUrl($site);
        $pages = Database::select(
            "SELECT id, slug, page_type, language, translation_group, updated_at, published_at
             FROM pages
             WHERE site_id = ? AND status = 'published'
               AND COALESCE(seo_noindex, 0) = 0
               AND COALESCE(seo_exclude_sitemap, 0) = 0
             ORDER BY
                CASE WHEN page_type = 'home' THEN 0 ELSE 1 END,
                COALESCE(published_at, updated_at) DESC,
                id DESC",
            [$siteId]
        );

        $out = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $out[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
               . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        $primaryLang = \App\Services\LanguageService::primaryFor($siteId);
        // Solo UNA página por idioma se sirve realmente en la raíz (la que
        // resuelve el controlador público). Si el sitio tiene varias marcadas
        // como `home` —pasa con páginas de prueba—, las demás deben listarse con
        // su slug propio, no colapsar todas en `/`.
        $rootPageIds = [];
        foreach (\App\Services\LanguageService::activeFor($siteId) as $activeLang) {
            $rootPage = \App\Controllers\Public\PageController::homePageFor($siteId, $activeLang);
            if ($rootPage !== null) {
                $rootPageIds[(int) $rootPage['id']] = true;
            }
        }
        // Un sitemap no debe declarar dos veces la misma URL. Puede pasar si el
        // sitio tiene varias páginas marcadas como `home` (todas apuntan a `/`).
        $seen = [];
        foreach ($pages as $page) {
            $loc = self::pageUrl($base, $page, $site, isset($rootPageIds[(int) $page['id']]));
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $lastmod = self::lastmod($page);
            $priority = (($page['page_type'] ?? '') === 'home') ? '1.0' : ((($page['page_type'] ?? '') === 'article') ? '0.7' : '0.8');

            $out[] = '  <url>';
            $out[] = '    <loc>' . self::xml($loc) . '</loc>';
            if ($lastmod !== '') {
                $out[] = '    <lastmod>' . self::xml($lastmod) . '</lastmod>';
            }
            $out[] = '    <changefreq>' . ((($page['page_type'] ?? '') === 'article') ? 'weekly' : 'monthly') . '</changefreq>';
            $out[] = '    <priority>' . $priority . '</priority>';
            // I18N-FULL T4.2 — versiones idiomáticas de esta misma página.
            $alternates = \App\Services\SeoHreflangService::alternatesFor($siteId, $page, $site);
            $links = \App\Services\SeoHreflangService::sitemapLinks($alternates, $primaryLang);
            if ($links !== '') {
                $out[] = $links;
            }
            $out[] = '  </url>';
        }
        $out[] = '</urlset>';

        return implode("\n", $out) . "\n";
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $site */
    private static function pageUrl(string $base, array $page, array $site = [], bool $isRootPage = true): string
    {
        // Solo la home del idioma principal vive en la raíz: la home francesa es
        // `/fr`. Y solo si esta página es la que de verdad se sirve ahí.
        if ($isRootPage && \App\Services\SeoIndexingService::isPrimaryHome($site, $page)) {
            return $base . '/';
        }
        return $base . '/' . ltrim((string) ($page['slug'] ?? ''), '/');
    }

    private static function lastmod(array $page): string
    {
        $raw = (string) ($page['updated_at'] ?? $page['published_at'] ?? '');
        if ($raw === '') return '';
        $ts = strtotime($raw);
        return $ts ? gmdate('Y-m-d', $ts) : '';
    }

    private static function site(?int $siteId = null): array
    {
        // `language` es imprescindible: sin él no se puede distinguir la home
        // del idioma principal (que vive en `/`) de la de un idioma secundario.
        if ($siteId !== null && $siteId > 0) {
            return Database::selectOne('SELECT id, url, language FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?? [];
        }
        return Database::selectOne('SELECT id, url, language FROM sites ORDER BY id ASC LIMIT 1') ?? [];
    }

    private static function siteId(): int
    {
        return (int) (self::site()['id'] ?? 0);
    }

    private static function siteBaseUrl(array $site): string
    {
        $configured = rtrim(trim((string) ($site['url'] ?? '')), '/');
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }
        return rtrim(base_url(''), '/');
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
