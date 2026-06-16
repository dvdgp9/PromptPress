<?php

namespace App\Controllers\Admin;

use App\Services\CacheService;
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
 * Design system — editor del aspecto visual del sitio público.
 * T5.1: form con 4 categorías (colors, typography, buttons, spacing) + preview en vivo.
 * T5.3: generación de CSS vars para páginas públicas (aparte).
 */
class DesignController
{
    // ----------------------------------------------------------------------
    // GET /admin/design
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $this->render([
            'tokens' => DesignSystem::load($siteId),
            'errors' => [],
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/design
    // ----------------------------------------------------------------------
    public function update(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $allErrors = [];
        $allTokens = [];

        foreach (DesignSystem::CATEGORIES as $cat) {
            $input = (array) (Request::post($cat, []) ?? []);
            [$tokens, $errors] = DesignSystem::validateCategory($cat, $input);
            $allTokens[$cat] = $tokens;
            foreach ($errors as $key => $msg) {
                $allErrors[$cat . '.' . $key] = $msg;
            }
        }

        if (!empty($allErrors)) {
            $this->render([
                'tokens' => $allTokens,
                'errors' => $allErrors,
            ]);
            return;
        }

        foreach ($allTokens as $cat => $tokens) {
            DesignSystem::saveCategory($siteId, $cat, $tokens);
        }

        // Cierre Fase 19 — persistir dirección visual elegida (si llega).
        $visualStyleRaw = (string) Request::post('visual_style', '');
        if ($visualStyleRaw !== '') {
            $normalized = VisualStyleService::normalizeSlug($visualStyleRaw);
            VisualStyleService::saveSelectedForSite($siteId, $normalized);
        }

        // T7.3: el design system afecta a TODAS las páginas → flush completo.
        CacheService::flush($siteId);

        Session::flash('success', 'Diseño guardado.');
        Response::redirect(base_url('admin/design'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/design/reset — vuelve a los defaults
    // ----------------------------------------------------------------------
    public function reset(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        Database::execute('DELETE FROM design_system WHERE site_id = ?', [$siteId]);
        Database::execute('UPDATE sites SET skin_json = NULL, personality = NULL WHERE id = ?', [$siteId]);
        CacheService::flush($siteId);
        Session::flash('success', 'Diseño restablecido a los valores por defecto.');
        Response::redirect(base_url('admin/design'));
    }

    // ----------------------------------------------------------------------
    // D-Slice 1 (S1.9) — Regenera el skin desde el vector inferido.
    // POST /admin/design/regenerate
    //
    // Ignora la marca `design_choice_origin = 'manual'`: el usuario lo está
    // pidiendo explícitamente. Reescribe `sites.personality` y `sites.skin_json`.
    // ----------------------------------------------------------------------
    // ----------------------------------------------------------------------
    // D-Slice 1 (S1.11) — Showcase de los 8 skin anchors.
    // GET /admin/_dev/skin-anchors
    //
    // Solo en local. Renderiza una vista demo de cada anchor para que el
    // usuario valide visualmente paletas + tipografías + radios antes de
    // congelar el catálogo.
    // ----------------------------------------------------------------------
    // ----------------------------------------------------------------------
    // D-Slice 5 (S5.6) — Test page generator: muestra TODAS las variantes
    // de TODOS los tipos de sección lado a lado, usando el SectionRenderer
    // real y el skin compuesto del sitio. Solo en local.
    // GET /admin/_dev/preview-all
    // ----------------------------------------------------------------------
    public function devPreviewAll(): void
    {
        self::requireSiteId();
        $catalog = \App\Services\Personality\LayoutCatalog::CATALOG;
        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'catalog' => $catalog,
        ]);
        View::send('admin/design/preview-all', $data);
    }

    public function devSkinAnchors(): void
    {
        self::requireSiteId();
        $anchors = \App\Services\Personality\SkinAnchors::all();
        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'anchors' => $anchors,
            // Vector del sitio actual (si existe) para mostrar cuál matchea.
            'currentVector' => self::currentVector(Auth::siteId()),
        ]);
        View::send('admin/design/showcase-anchors', $data);
    }

    private static function currentVector(?int $siteId): ?array
    {
        if ($siteId === null) return null;
        try {
            $row = Database::selectOne('SELECT personality FROM sites WHERE id = ?', [$siteId]);
            $p = json_decode((string) ($row['personality'] ?? ''), true);
            return is_array($p) && isset($p['vector']) ? (array) $p['vector'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function regenerate(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        try {
            $result = \App\Services\Personality\PersonalityInference::compose($siteId);
            CacheService::flush($siteId);
            $sources = (array) ($result['sources_used'] ?? []);
            $sourcesNote = $sources === []
                ? ' (sin señales suficientes: tu sitio se quedó con valores neutros).'
                : ' Hemos usado: ' . implode(', ', $sources) . '.';
            Session::flash('success', 'Diseño regenerado desde tus datos.' . $sourcesNote);
        } catch (\Throwable $e) {
            Session::flash('error', 'No pudimos regenerar el diseño: ' . $e->getMessage());
        }
        Response::redirect(base_url('admin/design'));
    }

    // ======================================================================
    private function render(array $ctx): void
    {
        $siteId = self::requireSiteId();
        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'schema'       => DesignSystem::schema(),
            'tokens'       => $ctx['tokens'],
            'errors'       => $ctx['errors'],
            'fontOptions'  => DesignSystem::FONT_OPTIONS,
            'cssVars'      => DesignSystem::toCssVars($ctx['tokens']),
            'googleFonts'  => DesignSystem::googleFontsUsed($ctx['tokens']),
            'csrf'         => CSRF::token(),
            // Cierre Fase 19 — dirección visual del sitio.
            'visualStyleCurrent' => VisualStyleService::selectedForSite($siteId),
            'visualStyleCards'   => VisualStyleService::cardsForSite($siteId),
        ]);
        View::send('admin/design/index', $data);
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
