<?php

namespace App\Services;

final class SeoIndexingService
{
    public static function normalizeCanonical(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) return null;
        if (mb_strlen($url) > 500) {
            $url = mb_substr($url, 0, 500);
        }
        return $url;
    }

    public static function robotsMeta(array $page): string
    {
        return ((int) ($page['seo_noindex'] ?? 0) === 1) ? 'noindex,follow' : '';
    }

    public static function canonicalForPage(array $site, array $page): string
    {
        $override = self::normalizeCanonical((string) ($page['canonical_url'] ?? ''));
        if ($override !== null) return $override;

        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        if ($siteUrl === '') return '';
        if (($page['page_type'] ?? '') === 'home') return $siteUrl . '/';
        return $siteUrl . '/' . ltrim((string) ($page['slug'] ?? ''), '/');
    }
}
