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

        // OJO (I18N-FULL T4.3): solo la home del idioma PRINCIPAL es la raíz.
        // Antes, cualquier página con page_type='home' devolvía `/`, así que la
        // home francesa se declaraba canónica de la castellana — es decir, le
        // decía a Google «soy un duplicado», que es la forma más rápida de que
        // te desindexen la versión traducida.
        if (self::isPrimaryHome($site, $page)) {
            return $siteUrl . '/';
        }

        return $siteUrl . '/' . ltrim((string) ($page['slug'] ?? ''), '/');
    }

    /**
     * ¿Esta página es la home del idioma principal (la que vive en la raíz)?
     *
     * `sites.language` ES el idioma principal: así lo sembró la migración de la
     * fase 1 y así lo mantiene Ajustes. Las páginas sin idioma asignado (filas
     * anteriores a la migración) cuentan como principales.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $page
     */
    public static function isPrimaryHome(array $site, array $page): bool
    {
        if (($page['page_type'] ?? '') !== 'home') {
            return false;
        }
        $pageLang = strtolower(trim((string) ($page['language'] ?? '')));
        $siteLang = strtolower(trim((string) ($site['language'] ?? '')));
        return $pageLang === '' || $siteLang === '' || $pageLang === $siteLang;
    }
}
