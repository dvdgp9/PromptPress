<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\ModuleRegistry;
use App\Services\BrandService;
use App\Services\DesignSystem;
use App\Services\VisualStyleService;
use Core\Database;
use Core\Response;

final class ResourceRenderer
{
    public static function send(int $siteId, array $page, int $status = 200): never
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ?', [$siteId]) ?? [];
        $lang = (string) ($page['lang'] ?? 'es');
        $title = trim((string) ($page['title'] ?? ''));
        $desc = trim((string) ($page['description'] ?? ''));
        $siteName = (string) ($site['name'] ?? '');
        $style = VisualStyleService::selectedForSite($siteId);
        $css = PP_ROOT . '/public/css/resources.css';

        $h = '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . ($siteName !== '' && $siteName !== $title ? ' — ' . e($siteName) : '') . '</title>'
            . ($desc !== '' ? '<meta name="description" content="' . e($desc) . '">' : '')
            . '<link rel="canonical" href="' . e((string) $page['canonical']) . '">';
        foreach ((array) ($page['alternates'] ?? []) as $code => $url) {
            $h .= '<link rel="alternate" hreflang="' . e((string) $code) . '" href="' . e((string) $url) . '">';
        }
        $h .= '<meta property="og:type" content="' . e((string) ($page['type'] ?? 'website')) . '">'
            . '<meta property="og:title" content="' . e($title) . '">'
            . ($desc !== '' ? '<meta property="og:description" content="' . e($desc) . '">' : '')
            . (!empty($page['image']) ? '<meta property="og:image" content="' . e((string) $page['image']) . '">' : '')
            . DesignSystem::renderHead($siteId, $style)
            . '<link rel="stylesheet" href="' . e(base_url('public/css/resources.css')) . '?v=' . e((string) (@filemtime($css) ?: PP_VERSION)) . '">'
            . '</head><body class="' . e(VisualStyleService::bodyClass($style)) . '">'
            . BrandService::publicHeader($siteId, null, $lang)
            . '<main class="pp-resources">' . (string) $page['body'] . '</main>'
            . BrandService::publicFooter($siteId)
            . '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
            $js = PP_ROOT . '/public/js/pp-analytics.js';
            $h .= '<script src="' . e(base_url('public/js/pp-analytics.js')) . '?v=' . e((string) (@filemtime($js) ?: PP_VERSION)) . '" data-site="' . $siteId . '" defer></script>';
        }
        Response::html($h . '</body></html>', $status);
    }

    public static function imageUrl(?string $path): string
    {
        $path = trim((string) $path);
        return $path === '' ? '' : base_url(ltrim($path, '/'));
    }
}
