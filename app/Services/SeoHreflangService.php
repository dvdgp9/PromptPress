<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * PromptPress — Anotaciones `hreflang` para páginas traducidas (I18N-FULL T4.1).
 *
 * Sin esto, dos versiones idiomáticas de la misma página compiten entre sí en
 * buscadores y pueden tratarse como contenido duplicado. Con `hreflang`, Google
 * entiende que son la misma página en otro idioma y sirve la que toca a cada
 * usuario.
 *
 * Reglas que se respetan aquí:
 *   - **Recíprocos**: todas las versiones del grupo declaran a todas (incluida
 *     a sí mismas). Un hreflang que no se devuelve se ignora.
 *   - **URLs absolutas**, como exige la especificación.
 *   - **`x-default`** apunta al idioma principal: es la versión a la que mandar
 *     a quien no encaja en ningún idioma declarado.
 *   - Si una página NO tiene traducciones publicadas, no se emite nada:
 *     declararse sola es ruido sin valor.
 */
final class SeoHreflangService
{
    /**
     * Versiones publicadas de una página, por idioma.
     *
     * Devuelve `[]` cuando no hay al menos dos: una página sola no necesita
     * anotación.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $site
     * @return array<string,string> idioma => URL absoluta
     */
    public static function alternatesFor(int $siteId, array $page, array $site): array
    {
        $group = trim((string) ($page['translation_group'] ?? ''));
        if ($group === '' || !LanguageService::isMultilingual($siteId)) {
            return [];
        }

        $siteUrl = rtrim((string) ($site['url'] ?? ''), '/');
        if ($siteUrl === '') {
            return [];
        }

        try {
            $rows = Database::select(
                "SELECT slug, language, page_type FROM pages
                 WHERE site_id = ? AND translation_group = ? AND status = 'published'
                   AND COALESCE(seo_noindex, 0) = 0",
                [$siteId, $group]
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $lang = LanguageService::normalize((string) ($row['language'] ?? ''));
            if (isset($out[$lang])) {
                continue;
            }
            $out[$lang] = SeoIndexingService::isPrimaryHome($site, $row)
                ? $siteUrl . '/'
                : $siteUrl . '/' . ltrim((string) $row['slug'], '/');
        }

        return count($out) >= 2 ? $out : [];
    }

    /**
     * Etiquetas `<link rel="alternate">` para el `<head>`, incluida `x-default`.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $site
     */
    public static function renderTags(int $siteId, array $page, array $site): string
    {
        $alternates = self::alternatesFor($siteId, $page, $site);
        if ($alternates === []) {
            return '';
        }

        $html = '';
        foreach ($alternates as $lang => $url) {
            $html .= '<link rel="alternate" hreflang="' . e($lang) . '" href="' . e($url) . '">';
        }

        $primary = LanguageService::primaryFor($siteId);
        $default = $alternates[$primary] ?? reset($alternates);
        $html .= '<link rel="alternate" hreflang="x-default" href="' . e((string) $default) . '">';

        return $html;
    }

    /**
     * Bloque `<xhtml:link>` para una entrada del sitemap.
     *
     * @param array<string,string> $alternates
     */
    public static function sitemapLinks(array $alternates, string $primary, string $indent = '    '): string
    {
        if ($alternates === []) {
            return '';
        }

        $lines = [];
        foreach ($alternates as $lang => $url) {
            $lines[] = $indent . '<xhtml:link rel="alternate" hreflang="' . self::xml($lang) . '" href="' . self::xml($url) . '"/>';
        }
        $default = $alternates[$primary] ?? reset($alternates);
        $lines[] = $indent . '<xhtml:link rel="alternate" hreflang="x-default" href="' . self::xml((string) $default) . '"/>';

        return implode("\n", $lines);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
