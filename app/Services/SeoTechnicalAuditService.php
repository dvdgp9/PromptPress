<?php

namespace App\Services;

use App\Services\Canvas\CanvasService;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

final class SeoTechnicalAuditService
{
    /** @return array<int,array<string,mixed>> */
    public static function audit(int $siteId, int $limit = 200): array
    {
        $pages = Database::select(
            "SELECT id, title, slug, page_type, render_mode, meta_title, meta_description,
                    seo_noindex, seo_exclude_sitemap, canonical_url, updated_at
             FROM pages
             WHERE site_id = ? AND status = 'published'
             ORDER BY updated_at DESC
             LIMIT " . max(1, min(500, $limit)),
            [$siteId]
        );

        $issues = [];
        foreach ($pages as $page) {
            $html = self::bodyHtml($siteId, $page);
            $stats = self::htmlStats($html);
            $pageType = (string) ($page['page_type'] ?? '');
            $title = trim((string) (($page['meta_title'] ?? '') ?: ($page['title'] ?? '')));
            $desc = trim((string) ($page['meta_description'] ?? ''));

            if ($stats['h1_count'] === 0) {
                $issues[] = self::issue($page, 'warning', 'h1_missing', 'Sin H1 detectable', 'La página no parece tener un título principal en el contenido público.');
            } elseif ($stats['h1_count'] > 1) {
                $issues[] = self::issue($page, 'warning', 'h1_multiple', 'Varios H1 detectados', 'Hay ' . $stats['h1_count'] . ' títulos H1. Normalmente debería haber uno principal.');
            }

            if ($stats['images_without_alt'] > 0) {
                $issues[] = self::issue($page, 'warning', 'image_alt_missing', 'Imágenes sin texto alternativo', $stats['images_without_alt'] . ' imagen(es) no tienen alt útil.');
            }

            if (trim((string) ($page['canonical_url'] ?? '')) !== '' && SeoIndexingService::normalizeCanonical((string) $page['canonical_url']) === null) {
                $issues[] = self::issue($page, 'error', 'canonical_invalid', 'Canonical inválida', 'La canonical debe ser una URL completa con http:// o https://.');
            }

            if ($pageType !== 'article' && $desc === '') {
                $issues[] = self::issue($page, 'info', 'og_description_missing', 'Descripción social vacía', 'Open Graph usará menos información porque no hay meta descripción.');
            }

            if ($title === '') {
                $issues[] = self::issue($page, 'info', 'og_title_missing', 'Título social vacío', 'Open Graph necesita un título legible para compartir enlaces.');
            }
        }

        return $issues;
    }

    /** @return array{h1_count:int,images_without_alt:int} */
    public static function htmlStats(string $html): array
    {
        if (trim($html) === '') {
            return ['h1_count' => 0, 'images_without_alt' => 0];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $h1Count = $doc->getElementsByTagName('h1')->length;
        $missingAlt = 0;
        foreach ($doc->getElementsByTagName('img') as $img) {
            $alt = trim((string) $img->getAttribute('alt'));
            if ($alt === '') $missingAlt++;
        }

        return ['h1_count' => $h1Count, 'images_without_alt' => $missingAlt];
    }

    private static function bodyHtml(int $siteId, array $page): string
    {
        $pageId = (int) ($page['id'] ?? 0);
        if (($page['render_mode'] ?? '') === 'canvas') {
            $rendered = CanvasService::renderPublic($pageId, $siteId);
            return (string) ($rendered['html'] ?? '');
        }

        $sections = Database::select(
            'SELECT id, section_type, sort_order, content, style, status
             FROM page_sections WHERE page_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$pageId]
        );
        SectionRenderer::setSiteContext($siteId);
        $html = SectionRenderer::renderMany($sections);
        if (($page['page_type'] ?? '') === 'article') {
            $html = '<h1>' . htmlspecialchars((string) ($page['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>' . $html;
        }
        return $html;
    }

    /** @return array<string,mixed> */
    private static function issue(array $page, string $severity, string $code, string $label, string $detail): array
    {
        return [
            'page_id' => (int) ($page['id'] ?? 0),
            'page_title' => (string) ($page['title'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'page_type' => (string) ($page['page_type'] ?? ''),
            'render_mode' => (string) ($page['render_mode'] ?? ''),
            'severity' => $severity,
            'code' => $code,
            'label' => $label,
            'detail' => $detail,
        ];
    }
}
