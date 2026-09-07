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

        $items = ResourceStore::publishedForLanguage($siteId, $lang);

        // EMB-3 — Enseñar solo una categoría. Se compara sin distinguir
        // mayúsculas ni espacios de sobra porque la categoría es texto libre y
        // el gestor la escribe a mano cada vez.
        $category = mb_strtolower(trim((string) ($options['category'] ?? '')));
        if ($category !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $r): bool => mb_strtolower(trim((string) ($r['category'] ?? ''))) === $category
            ));
        }

        $items = array_slice($items, 0, $limit);
        // Sin nada que enseñar es mejor no dejar un encabezado suelto colgando.
        if ($items === []) return '';

        // `list` es una fila por recurso, para cuando el bloque acompaña a un
        // texto y una rejilla de tarjetas pesaría demasiado.
        $variant = ((string) ($options['variant'] ?? '')) === 'list' ? 'list' : 'grid';

        $heading = mb_substr(trim((string) ($options['heading'] ?? '')), 0, 120);
        // Las primeras versiones de Studio persistían el heading automático en
        // el idioma del gestor. Reconocer únicamente esos defaults permite
        // reparar bloques existentes al renderizar, sin tocar headings que el
        // usuario haya personalizado de verdad.
        if ($heading === '' || self::isAutomaticHeading($heading)) {
            $heading = Microcopy::t('resources.title', $lang);
        }
        $subheading = mb_substr(trim((string) ($options['subheading'] ?? '')), 0, 240);
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

        return '<section class="pp-featured-resources pp-featured-resources--' . $variant . '" aria-label="' . e($heading) . '">'
            . '<div class="pp-featured-resources__inner"><header class="pp-featured-resources__head">'
            // EMB-4 — editables a mano en el Studio; ver `renderForm()`.
            . '<h2 data-pp-embed-field="heading">' . e($heading) . '</h2>'
            . ($subheading !== ''
                ? '<p class="pp-featured-resources__sub" data-pp-embed-field="subheading">' . e($subheading) . '</p>'
                : '')
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
