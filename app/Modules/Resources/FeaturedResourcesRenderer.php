<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\ModuleRegistry;
use App\Services\AdminI18n;
use App\Services\LanguageService;
use App\Services\Microcopy;

/** Tarjetas reales de recursos para {{resources:featured}} en Canvas. */
final class FeaturedResourcesRenderer
{
    /** @param array<string,mixed> $options */
    public static function render(int $siteId, string $lang, array $options = []): string
    {
        if (!ModuleRegistry::isEnabled($siteId, 'resources')) return '';

        $lang = LanguageService::normalize($lang);
        $limit = max(1, min(12, (int) ($options['limit'] ?? 3)));
        $items = array_slice(ResourceStore::publishedForLanguage($siteId, $lang), 0, $limit);
        if ($items === []) return '';

        $heading = mb_substr(trim((string) ($options['heading'] ?? '')), 0, 120);
        // Las primeras versiones de Studio persistían el heading automático en
        // el idioma del gestor. Reconocer únicamente esos defaults permite
        // reparar bloques existentes al renderizar, sin tocar headings que el
        // usuario haya personalizado de verdad.
        if ($heading === '' || self::isAutomaticHeading($heading)) {
            $heading = Microcopy::t('resources.title', $lang);
        }
        $prefix = LanguageService::prefixFor($siteId, $lang);
        $base = ($prefix !== '' ? $prefix . '/' : '') . 'recursos/';
        $cards = '';

        foreach ($items as $item) {
            $cover = ResourceRenderer::imageUrl($item['cover_path'] ?? null);
            $format = strtoupper(pathinfo((string) ($item['original_filename'] ?? ''), PATHINFO_EXTENSION)) ?: 'FILE';
            $visual = $cover !== ''
                ? '<img class="pp-featured-resources__cover" src="' . e($cover) . '" alt="' . e((string) ($item['cover_alt'] ?: $item['title'])) . '" loading="lazy">'
                : '<span class="pp-featured-resources__cover pp-featured-resources__cover--empty" aria-hidden="true"><span>' . e($format) . '</span></span>';
            $url = base_url($base . (string) $item['slug']);
            $cards .= '<article class="pp-featured-resources__card"><a href="' . e($url) . '">'
                . $visual . '<span class="pp-featured-resources__body">'
                . ((string) ($item['category'] ?? '') !== '' ? '<span class="pp-featured-resources__category">' . e((string) $item['category']) . '</span>' : '')
                . '<span class="pp-featured-resources__title">' . e((string) $item['title']) . '</span>'
                . '<span class="pp-featured-resources__link">' . e(Microcopy::t('resources.view', $lang)) . ' →</span>'
                . '</span></a></article>';
        }

        return '<section class="pp-featured-resources" aria-label="' . e($heading) . '">'
            . '<div class="pp-featured-resources__inner"><header class="pp-featured-resources__head">'
            . '<h2>' . e($heading) . '</h2>'
            . '</header><div class="pp-featured-resources__grid">' . $cards . '</div></div></section>';
    }

    private static function isAutomaticHeading(string $heading): bool
    {
        $needle = mb_strtolower(trim($heading));
        if ($needle === '') return true;

        $automatic = [];
        foreach (AdminI18n::LOCALES as $locale) {
            $catalog = AdminI18n::catalog($locale);
            foreach (['cv.resources.default_heading', 'cv.resources.section_label'] as $key) {
                $value = mb_strtolower(trim((string) ($catalog[$key] ?? '')));
                if ($value !== '') $automatic[$value] = true;
            }
        }
        foreach (array_keys(LanguageService::LANGUAGES) as $locale) {
            $value = mb_strtolower(trim(Microcopy::t('resources.title', $locale)));
            if ($value !== '') $automatic[$value] = true;
        }

        return isset($automatic[$needle]);
    }
}
