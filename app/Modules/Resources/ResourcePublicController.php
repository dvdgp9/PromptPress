<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\ModuleRegistry;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\Renderer\SectionRenderer;
use Core\Response;

final class ResourcePublicController
{
    public function index(array $params = []): void
    {
        [$siteId, $lang] = self::context($params);
        $tr = self::copy($lang);
        $items = ResourceStore::publishedForLanguage($siteId, $lang);
        $cards = '';
        foreach ($items as $item) {
            $cards .= self::card($siteId, $lang, $item, $tr);
        }

        $body = '<div class="pp-resources__container">'
            . '<header class="pp-resources__intro"><div><p class="pp-resources__eyebrow">' . e($tr('resources.eyebrow')) . '</p>'
            . '<h1>' . e($tr('resources.title')) . '</h1><p class="pp-resources__lead">' . e($tr('resources.intro')) . '</p></div>'
            . ($items !== [] ? '<p class="pp-resources__count" aria-label="' . count($items) . '">' . count($items) . '</p>' : '')
            . '</header>'
            . ($items === []
                ? '<div class="pp-resources__empty"><p>' . e($tr('resources.empty')) . '</p></div>'
                : '<div class="pp-resources__grid">' . $cards . '</div>')
            . '</div>';

        ResourceRenderer::send($siteId, [
            'lang' => $lang,
            'title' => $tr('resources.title'),
            'description' => $tr('resources.intro'),
            'canonical' => self::catalogUrl($siteId, $lang),
            'alternates' => self::catalogAlternates($siteId),
            'body' => $body,
        ]);
    }

    public function detail(array $params = []): void
    {
        [$siteId, $lang] = self::context($params);
        $tr = self::copy($lang);
        $resource = ResourceStore::findPublishedBySlug($siteId, $lang, (string) ($params['slug'] ?? ''));
        if ($resource === null) Response::notFound();

        $cover = ResourceRenderer::imageUrl($resource['cover_path'] ?? null);
        $format = strtoupper(pathinfo((string) $resource['original_filename'], PATHINFO_EXTENSION));
        $meta = $tr('resources.file', ['format' => $format, 'size' => self::formatBytes((int) $resource['file_size'])]);
        $catalogUrl = self::catalogUrl($siteId, $lang);
        $detailUrl = self::detailUrl($siteId, $lang, (string) $resource['slug']);

        $visual = $cover !== ''
            ? '<img class="pp-resource__cover" src="' . e($cover) . '" alt="' . e((string) ($resource['cover_alt'] ?: $resource['title'])) . '">'
            : '<div class="pp-resource__cover pp-resource__cover--empty" aria-hidden="true"><span>' . e($format) . '</span></div>';

        $action = (string) $resource['access_mode'] === 'direct'
            ? '<a class="pp-resource__download" href="' . e(self::downloadUrl($siteId, $lang, (string) $resource['slug'])) . '">' . e($tr('resources.download')) . '</a>'
            : self::conditionedAccess($siteId, $lang, $resource, $tr);

        $body = '<div class="pp-resources__container">'
            . '<a class="pp-resource__back" href="' . e($catalogUrl) . '">← ' . e($tr('resources.back')) . '</a>'
            . '<article class="pp-resource">' . $visual . '<div class="pp-resource__content">'
            . ((string) ($resource['category'] ?? '') !== '' ? '<p class="pp-resources__eyebrow">' . e((string) $resource['category']) . '</p>' : '')
            . '<h1>' . e((string) $resource['title']) . '</h1><p class="pp-resource__meta">' . e($meta) . '</p>'
            . ((string) ($resource['description'] ?? '') !== '' ? '<div class="pp-resource__description">' . nl2br(e((string) $resource['description'])) . '</div>' : '')
            . $action . '</div></article></div>';

        ResourceRenderer::send($siteId, [
            'lang' => $lang,
            'title' => (string) $resource['title'],
            'description' => self::description((string) ($resource['description'] ?? '')),
            'canonical' => $detailUrl,
            'alternates' => self::detailAlternates($siteId, $resource),
            'image' => $cover,
            'type' => 'article',
            'body' => $body,
        ]);
    }

    private static function context(array $params): array
    {
        $siteId = ModuleRegistry::resolveSiteId();
        if ($siteId === null) Response::notFound();
        $raw = trim((string) ($params['lang'] ?? ''));
        if ($raw !== '' && !ResourceStore::languageAvailableForSite($siteId, $raw)) Response::notFound();
        $lang = $raw !== '' ? LanguageService::normalize($raw) : LanguageService::primaryFor($siteId);
        return [$siteId, $lang];
    }

    private static function card(int $siteId, string $lang, array $item, callable $tr): string
    {
        $url = self::detailUrl($siteId, $lang, (string) $item['slug']);
        $cover = ResourceRenderer::imageUrl($item['cover_path'] ?? null);
        $format = strtoupper(pathinfo((string) $item['original_filename'], PATHINFO_EXTENSION));
        $visual = $cover !== ''
            ? '<img class="pp-resource-card__cover" src="' . e($cover) . '" alt="' . e((string) ($item['cover_alt'] ?: $item['title'])) . '" loading="lazy">'
            : '<span class="pp-resource-card__cover pp-resource-card__cover--empty" aria-hidden="true"><span>' . e($format) . '</span></span>';
        return '<article class="pp-resource-card"><a href="' . e($url) . '">' . $visual
            . '<span class="pp-resource-card__body">'
            . ((string) ($item['category'] ?? '') !== '' ? '<span class="pp-resource-card__category">' . e((string) $item['category']) . '</span>' : '')
            . '<span class="pp-resource-card__title">' . e((string) $item['title']) . '</span>'
            . ((string) ($item['description'] ?? '') !== '' ? '<span class="pp-resource-card__desc">' . e(self::description((string) $item['description'], 120)) . '</span>' : '')
            . '<span class="pp-resource-card__link">' . e($tr('resources.view')) . ' →</span></span></a></article>';
    }

    private static function conditionedAccess(int $siteId, string $lang, array $resource, callable $tr): string
    {
        $section = ResourceStore::formSection($siteId, (int) ($resource['form_id'] ?? 0));
        if ($section === null) {
            return '<div class="pp-resource__form-note pp-resource__form-note--unavailable"><p>'
                . e($tr('resources.unavailable')) . '</p></div>';
        }
        SectionRenderer::setSiteContext($siteId, $lang);
        $context = ResourceAccessService::issueFormContext($siteId, $resource);
        $form = SectionRenderer::render($section, [
            'form_hidden' => ['_resource_context' => $context],
        ]);
        return '<div class="pp-resource__access"><p class="pp-resource__access-intro">'
            . e($tr('resources.form_required')) . '</p>' . $form . '</div>';
    }

    private static function path(int $siteId, string $lang, string $suffix): string
    {
        $prefix = LanguageService::prefixFor($siteId, $lang);
        return ($prefix !== '' ? $prefix . '/' : '') . ltrim($suffix, '/');
    }
    private static function catalogUrl(int $siteId, string $lang): string { return base_url(self::path($siteId, $lang, 'recursos')); }
    private static function detailUrl(int $siteId, string $lang, string $slug): string { return base_url(self::path($siteId, $lang, 'recursos/' . $slug)); }
    private static function downloadUrl(int $siteId, string $lang, string $slug): string { return base_url(self::path($siteId, $lang, 'recursos/' . $slug . '/descargar')); }
    private static function catalogAlternates(int $siteId): array
    {
        $out = [];
        foreach (LanguageService::activeFor($siteId) as $lang) $out[$lang] = self::catalogUrl($siteId, $lang);
        $out['x-default'] = self::catalogUrl($siteId, LanguageService::primaryFor($siteId));
        return $out;
    }
    private static function detailAlternates(int $siteId, array $resource): array
    {
        $out = [];
        foreach (ResourceStore::publishedTranslations($siteId, (string) $resource['translation_group']) as $item) {
            foreach (ResourceStore::visibleLanguages($siteId, $item) as $language) {
                $out[$language] = self::detailUrl($siteId, $language, (string) $item['slug']);
            }
        }
        $primary = LanguageService::primaryFor($siteId);
        if (isset($out[$primary])) $out['x-default'] = $out[$primary];
        return $out;
    }
    private static function description(string $text, int $limit = 160): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, $limit);
    }
    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' MB' : max(1, (int) ceil($bytes / 1024)) . ' KB';
    }
    private static function copy(string $lang): callable
    {
        return static fn (string $key, array $vars = []): string => Microcopy::t($key, $lang, $vars);
    }
}
