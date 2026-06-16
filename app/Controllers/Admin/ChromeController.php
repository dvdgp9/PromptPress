<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\BrandService;
use App\Services\ChromeService;
use App\Services\DesignSystem;
use App\Services\VisualStyleService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * CHROME-EDITOR — Editor del header y el pie del sitio ("Header y pie").
 * Guarda la config con ChromeService y ofrece una vista previa en vivo.
 */
class ChromeController
{
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $data = DashboardController::getCommonData();
        $data['csrf'] = CSRF::token();
        $data['config'] = ChromeService::load($siteId);
        $data['pages'] = $this->sitePages($siteId);
        View::send('admin/chrome/index', $data);
    }

    public function save(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden('Solo un administrador puede editar el header y el pie.');
        }
        $siteId = $this->requireSiteId();
        $config = ChromeService::sanitize($this->decodePayload());
        ChromeService::save($siteId, $config);
        Session::flash('success', 'Header y pie actualizados.');
        Response::redirect(base_url('admin/chrome'));
    }

    /** Render del sitio (header + muestra + footer) con la config ENVIADA, sin guardar. */
    public function preview(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $config = ChromeService::sanitize($this->decodePayload());

        $styleSlug = VisualStyleService::selectedForSite($siteId);
        $html = '<!doctype html><html lang="es"><head>'
              . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
              . '<meta name="robots" content="noindex,nofollow">'
              . DesignSystem::renderHead($siteId, $styleSlug)
              . '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">'
              . BrandService::publicHeader($siteId, $config)
              . '<main class="pp-section"><div class="container" style="padding:64px 24px;text-align:center">'
              . '<h1 style="font-family:var(--pp-font-heading)">Vista previa</h1>'
              . '<p style="color:var(--pp-text-muted)">Así se ven el header y el pie con los cambios actuales. El contenido de las páginas no se modifica.</p>'
              . '</div></main>'
              . BrandService::publicFooter($siteId, $config)
              . '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>'
              . '</body></html>';

        Response::html($html);
    }

    /** Decodifica el JSON de configuración enviado por el editor. */
    private function decodePayload(): array
    {
        $raw = (string) (Request::post('config_json') ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** Páginas del sitio para el selector del menú. */
    private function sitePages(int $siteId): array
    {
        try {
            $rows = Database::select(
                "SELECT id, title, slug, page_type, status FROM pages
                 WHERE site_id = ?
                 ORDER BY (page_type='home') DESC, tree_sort_order ASC, sort_order ASC, id ASC
                 LIMIT 100",
                [$siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn(array $r) => [
            'id'        => (int) $r['id'],
            'title'     => (string) $r['title'],
            'page_type' => (string) $r['page_type'],
            'status'    => (string) $r['status'],
        ], $rows);
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
