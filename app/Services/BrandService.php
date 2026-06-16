<?php

namespace App\Services;

use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\CookieBanner;
use App\Services\Compliance\TrackingCatalog;
use Core\Database;

final class BrandService
{
    public static function data(int $siteId): array
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?: [];
        $logo = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'site_logo_path']
        );

        return [
            'name' => trim((string) ($site['name'] ?? 'PromptPress')) ?: 'PromptPress',
            'logo_path' => trim((string) ($logo['setting_value'] ?? '')),
        ];
    }

    public static function logoUrl(int $siteId): string
    {
        $data = self::data($siteId);
        return $data['logo_path'] !== '' ? base_url($data['logo_path']) : '';
    }

    /**
     * D-MB2 R4 — Logo solo si el archivo existe en disco (un path huérfano en
     * settings producía un <img> roto + alt duplicando el nombre del sitio).
     */
    private static function brandMark(array $data): string
    {
        $name = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        $path = ltrim($data['logo_path'], '/');
        if ($path !== '' && is_file(PP_ROOT . '/' . $path)) {
            return '<img class="pp-site-header__logo-img" src="' . htmlspecialchars(base_url($path), ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '">';
        }
        return '<span class="pp-site-header__logo-fallback" aria-hidden="true">'
             . htmlspecialchars(mb_strtoupper(mb_substr($data['name'], 0, 1)), ENT_QUOTES, 'UTF-8')
             . '</span><span>' . $name . '</span>';
    }

    /**
     * D-MB2 R4 — Páginas publicadas de primer nivel para la navegación
     * (excluye legales y la home, que ya es el enlace de marca).
     *
     * @return array<int,array{title:string,slug:string,page_type:string}>
     */
    private static function navPages(int $siteId, int $limit = 6): array
    {
        try {
            $rows = Database::select(
                "SELECT title, slug, page_type FROM pages
                 WHERE site_id = ? AND status = 'published'
                   AND page_type NOT IN ('legal', 'article')
                   AND (parent_id IS NULL OR parent_id = 0)
                   AND slug NOT IN ('', 'inicio', 'home')
                 ORDER BY tree_sort_order ASC, sort_order ASC, id ASC
                 LIMIT " . (int) $limit,
                [$siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn(array $r) => [
            'title' => (string) $r['title'],
            'slug' => (string) $r['slug'],
            'page_type' => (string) $r['page_type'],
        ], $rows);
    }

    public static function publicHeader(int $siteId): string
    {
        $data = self::data($siteId);
        $pages = self::navPages($siteId);

        $links = '';
        $cta = '';
        foreach ($pages as $p) {
            $href = htmlspecialchars(base_url(ltrim($p['slug'], '/')), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
            if ($cta === '' && $p['page_type'] === 'contact') {
                $cta = '<a class="pp-btn pp-btn--primary pp-site-header__cta" href="' . $href . '">' . $title . '</a>';
                continue;
            }
            $links .= '<a class="pp-site-header__link" href="' . $href . '">' . $title . '</a>';
        }

        return '<header class="pp-site-header">'
             . '<div class="pp-site-header__inner">'
             . '<a class="pp-site-header__brand" href="' . htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') . '">'
             . self::brandMark($data)
             . '</a>'
             . ($links !== '' ? '<nav class="pp-site-header__nav" aria-label="Navegación principal">' . $links . '</nav>' : '')
             . $cta
             . '</div>'
             . '</header>';
    }

    /**
     * E-GDPR G3 — Footer público con enlaces a páginas legales publicadas.
     *
     * Renderiza solo las páginas con `page_type='legal'` que estén `published`.
     * Si no hay ninguna, devuelve un footer mínimo (©/marca) sin enlaces.
     */
    public static function publicFooter(int $siteId): string
    {
        $name = htmlspecialchars(self::data($siteId)['name'], ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        try {
            $legal = Database::select(
                "SELECT title, slug FROM pages
                 WHERE site_id = ? AND page_type = 'legal' AND status = 'published'
                 ORDER BY title ASC",
                [$siteId]
            );
        } catch (\Throwable $e) {
            $legal = [];
        }

        $links = '';
        foreach ($legal as $p) {
            $href  = htmlspecialchars(base_url(ltrim((string) $p['slug'], '/')), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8');
            $links .= '<a class="pp-site-footer__link" href="' . $href . '">' . $title . '</a>';
        }

        // Banner de cookies (E-GDPR G4): el JS se inyecta siempre (gestiona también
        // click-to-load de vídeos), pero el banner UI solo aparece si hay tracking activo.
        $manifest = ComplianceService::manifest($siteId);
        $needsBanner = TrackingCatalog::needsBanner($manifest);
        $reopenLink = $needsBanner
            ? '<a class="pp-site-footer__link" href="#" data-cb-reopen>Configurar cookies</a>'
            : '';
        $bannerHtml = CookieBanner::render($manifest);

        // D-MB2 R4 — footer "de agencia": banda oscura con marca + tagline,
        // navegación principal y enlaces legales; barra inferior con ©.
        $tagline = '';
        try {
            $mem = Database::selectOne(
                "SELECT field_value FROM site_memory
                 WHERE site_id = ? AND field_key IN ('value_proposition', 'business_description')
                 ORDER BY FIELD(field_key, 'value_proposition', 'business_description') LIMIT 1",
                [$siteId]
            );
            $tagline = trim((string) ($mem['field_value'] ?? ''));
            if (mb_strlen($tagline) > 160) {
                $tagline = rtrim(mb_substr($tagline, 0, 157), " \t.,;") . '…';
            }
        } catch (\Throwable $e) {
            // sin memoria → sin tagline
        }

        $navLinks = '';
        foreach (self::navPages($siteId) as $p) {
            $href = htmlspecialchars(base_url(ltrim($p['slug'], '/')), ENT_QUOTES, 'UTF-8');
            $navLinks .= '<a class="pp-site-footer__link" href="' . $href . '">'
                . htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') . '</a>';
        }

        $cols = '<div class="pp-site-footer__brandcol">'
              . '<span class="pp-site-footer__name">' . $name . '</span>'
              . ($tagline !== '' ? '<p class="pp-site-footer__tagline">' . htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') . '</p>' : '')
              . '</div>';
        if ($navLinks !== '') {
            $cols .= '<nav class="pp-site-footer__col" aria-label="Navegación"><span class="pp-site-footer__col-title">Explora</span>' . $navLinks . '</nav>';
        }
        if ($links !== '' || $reopenLink !== '') {
            $cols .= '<nav class="pp-site-footer__col" aria-label="Enlaces legales"><span class="pp-site-footer__col-title">Legal</span>' . $links . $reopenLink . '</nav>';
        }

        return '<footer class="pp-site-footer">'
             . '<div class="pp-site-footer__grid">' . $cols . '</div>'
             . '<div class="pp-site-footer__bottom"><span class="pp-site-footer__copy">© ' . $year . ' · ' . $name . '</span></div>'
             . '</footer>'
             . $bannerHtml;
    }
}
