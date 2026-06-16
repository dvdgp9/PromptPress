<?php

namespace App\Services;

final class SeoStructuredDataService
{
    public static function render(array $site, array $page, string $effectiveDescription = '', ?array $postMeta = null): string
    {
        $graph = self::graph($site, $page, $effectiveDescription, $postMeta);
        if ($graph === []) return '';

        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') return '';

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @return array<int,array<string,mixed>> */
    public static function graph(array $site, array $page, string $effectiveDescription = '', ?array $postMeta = null): array
    {
        $base = self::baseUrl($site);
        $canonical = SeoIndexingService::normalizeCanonical((string) ($page['canonical_url'] ?? ''));
        $url = $canonical ?? self::pageUrl($base, $page);
        $siteName = trim((string) ($site['name'] ?? ''));
        $title = trim((string) (($page['meta_title'] ?? '') ?: ($page['title'] ?? '')));
        $description = trim($effectiveDescription);
        $isArticle = (($page['page_type'] ?? '') === 'article');

        $graph = [];
        $websiteId = $base . '/#website';
        $orgId = $base . '/#organization';

        if ($siteName !== '') {
            $graph[] = self::clean([
                '@type' => 'Organization',
                '@id' => $orgId,
                'name' => $siteName,
                'url' => $base . '/',
            ]);
        }

        $graph[] = self::clean([
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => $base . '/',
            'name' => $siteName !== '' ? $siteName : null,
            'publisher' => $siteName !== '' ? ['@id' => $orgId] : null,
        ]);

        $pageNode = self::clean([
            '@type' => $isArticle ? 'Article' : 'WebPage',
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $title !== '' ? $title : null,
            'headline' => $isArticle && $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
            'isPartOf' => ['@id' => $websiteId],
            'mainEntityOfPage' => $isArticle ? ['@id' => $url . '#webpage'] : null,
            'datePublished' => $isArticle ? self::dateIso((string) ($page['published_at'] ?? '')) : null,
            'dateModified' => self::dateIso((string) ($page['updated_at'] ?? '')) ?: null,
            'publisher' => $isArticle && $siteName !== '' ? ['@id' => $orgId] : null,
        ]);

        if ($isArticle && is_array($postMeta)) {
            $author = trim((string) ($postMeta['author_name'] ?? ''));
            if ($author !== '') {
                $pageNode['author'] = ['@type' => 'Person', 'name' => $author];
            }
            $image = self::absoluteMediaUrl((string) ($postMeta['featured_image_path'] ?? ''));
            if ($image !== '') {
                $pageNode['image'] = [$image];
            }
        }

        $graph[] = $pageNode;

        return array_values(array_filter($graph));
    }

    private static function pageUrl(string $base, array $page): string
    {
        if (($page['page_type'] ?? '') === 'home') {
            return $base . '/';
        }
        return $base . '/' . ltrim((string) ($page['slug'] ?? ''), '/');
    }

    private static function absoluteMediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return base_url(ltrim($path, '/'));
    }

    private static function baseUrl(array $site): string
    {
        $configured = rtrim(trim((string) ($site['url'] ?? '')), '/');
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }
        return rtrim(base_url(''), '/');
    }

    private static function dateIso(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $ts = strtotime($raw);
        return $ts ? gmdate('c', $ts) : null;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private static function clean(array $node): array
    {
        foreach ($node as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($node[$key]);
            }
        }
        return $node;
    }
}
