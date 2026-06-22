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

    public function updateLogo(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $file = Request::file('logo');
        $error = self::validateLogoUpload($file);
        if ($error !== null) {
            Session::flash('error', $error);
            Response::redirect(base_url('admin/design'));
        }

        $tmp = (string) $file['tmp_name'];
        $mime = (string) mime_content_type($tmp);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
        $dir = PP_ROOT . '/storage/uploads/' . $siteId . '/brand';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Session::flash('error', 'No se pudo crear la carpeta del logo.');
            Response::redirect(base_url('admin/design'));
        }

        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $absolute = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $absolute) || !is_file($absolute) || !is_readable($absolute)) {
            Session::flash('error', 'No se pudo guardar el logo.');
            Response::redirect(base_url('admin/design'));
        }

        $size = @getimagesize($absolute);
        $relative = 'storage/uploads/' . $siteId . '/brand/' . $filename;
        try {
            Database::execute(
                'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$siteId, $filename, mb_substr((string) $file['name'], 0, 255), $mime, (int) $file['size'], $relative, 'Logo', (int) ($size[0] ?? 0) ?: null, (int) ($size[1] ?? 0) ?: null, Auth::id()]
            );
            self::storeSiteSetting($siteId, 'site_logo_path', $relative);
            self::storeSiteSetting($siteId, 'site_logo_media_id', (string) Database::lastInsertId());
        } catch (\Throwable $e) {
            @unlink($absolute);
            error_log('[design logo] site=' . $siteId . ' save failed: ' . $e->getMessage());
            Session::flash('error', 'No se pudo registrar el logo.');
            Response::redirect(base_url('admin/design'));
        }

        CacheService::flush($siteId);
        Session::flash('success', 'Logo actualizado.');
        Response::redirect(base_url('admin/design'));
    }

    public function deleteLogo(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $settings = Database::select(
            "SELECT setting_key, setting_value FROM settings WHERE site_id = ? AND setting_key IN ('site_logo_path', 'site_logo_media_id')",
            [$siteId]
        );
        $current = [];
        foreach ($settings as $setting) $current[(string) $setting['setting_key']] = (string) $setting['setting_value'];
        Database::execute("DELETE FROM settings WHERE site_id = ? AND setting_key IN ('site_logo_path', 'site_logo_media_id')", [$siteId]);
        if ((int) ($current['site_logo_media_id'] ?? 0) > 0) {
            Database::execute('DELETE FROM media WHERE id = ? AND site_id = ?', [(int) $current['site_logo_media_id'], $siteId]);
        }
        $relative = (string) ($current['site_logo_path'] ?? '');
        $brandPrefix = 'storage/uploads/' . $siteId . '/brand/';
        if ($relative !== '' && str_starts_with($relative, $brandPrefix)) {
            $absolute = PP_ROOT . '/' . $relative;
            if (is_file($absolute)) @unlink($absolute);
        }
        CacheService::flush($siteId);
        Session::flash('success', 'Logo eliminado. La cabecera mostrará el nombre del sitio.');
        Response::redirect(base_url('admin/design'));
    }

    private static function validateLogoUpload(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Selecciona un archivo de logo.';
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return 'La subida del logo no se completó.';
        if (($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) return 'El logo debe pesar menos de 2 MB.';
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return 'El archivo recibido no es una subida válida.';
        $mime = (string) mime_content_type($tmp);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return 'El logo debe ser PNG, JPG o WebP.';
        return @getimagesize($tmp) === false ? 'El archivo no contiene una imagen válida.' : null;
    }

    private static function storeSiteSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, $key, $value]
        );
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
        $ctxLogoPath = (string) ((Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?',
            [$siteId, 'site_logo_path']
        )['setting_value'] ?? ''));
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
            'logoPath' => $ctxLogoPath,
            'logoUrl' => \App\Services\BrandService::logoUrl($siteId),
            'logoMissing' => $ctxLogoPath !== '' && !is_file(PP_ROOT . '/' . ltrim($ctxLogoPath, '/')),
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
