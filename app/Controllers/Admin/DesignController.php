<?php

namespace App\Controllers\Admin;

use App\Services\BrandPaletteService;
use App\Services\BrandService;
use App\Services\CacheService;
use App\Services\CustomFontService;
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
        // DESIGN-MANDA T2 — El formulario enseña los tokens EFECTIVOS (lo que la
        // web sirve de verdad), no los crudos de `design_system`. Con `load()`
        // el panel mostraba un color y el sitio pintaba otro, porque el skin y
        // la paleta los pisaban después.
        $this->render([
            'tokens' => DesignSystem::effective($siteId),
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
            [$tokens, $errors] = DesignSystem::validateCategory($cat, $input, $siteId);
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

        // DESIGN-MANDA T4 — La línea base se calcula ANTES de guardar nada: es
        // la foto de lo heredado contra la que se decide qué tocó el usuario.
        $baseline = DesignSystem::baseline($siteId);

        foreach ($allTokens as $cat => $tokens) {
            DesignSystem::saveCategory($siteId, $cat, $tokens);
        }

        // DESIGN-MANDA T3 — Los colores del formulario se vuelcan a la paleta a
        // medida (`site_palette_custom`), que YA está por encima del skin en la
        // cadena de precedencia. Sin esto el usuario guardaba un color y el skin
        // inferido lo pisaba: el panel decía una cosa y la web pintaba otra.
        // Mismo "único camino de escritura" que usa el paso 2 del onboarding.
        $contrastWarnings = self::syncPaletteFromColors($siteId, (array) ($allTokens['colors'] ?? []));

        // DESIGN-MANDA T4 — Y lo que no es color (tipografías, escala, radios,
        // sombra) se guarda como override manual, que se aplica por encima del
        // skin. Si un campo vuelve a su valor heredado, su override se borra.
        DesignSystem::saveManualTokens(
            $siteId,
            DesignSystem::diffManualTokens($allTokens, $baseline)
        );

        // DESIGN-MANDA T7 — La dirección visual ya no se elige aquí: el campo
        // `visual_style` no existe en el formulario y no se persiste.

        // T7.3: el design system afecta a TODAS las páginas → flush completo.
        CacheService::flush($siteId);

        Session::flash('success', __('design.flash.saved'));
        // DESIGN-MANDA T5 — El contraste no bloquea ni corrige a escondidas: el
        // color es decisión del usuario. Se guarda y se avisa de qué par falla.
        if ($contrastWarnings !== []) {
            Session::flash('warning', __('design.contrast.warning', [
                'pares' => implode('; ', $contrastWarnings),
            ]));
        }
        Response::redirect(base_url('admin/design'));
    }

    // ----------------------------------------------------------------------
    // DESIGN-MANDA T10/T11 — Editor de paleta dentro de Diseño.
    //
    // Antes esto solo existía en el paso 2 del onboarding, un flujo de un solo
    // uso: pasado el alta, no había forma de volver a derivar la paleta de la
    // marca. La lógica vive en `BrandPaletteService`; aquí solo hay HTTP.
    // ----------------------------------------------------------------------

    /** POST /admin/design/brand-colors — guarda los colores de marca. */
    public function saveBrandColors(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $posted = Request::post('brand_palette', []);
        BrandPaletteService::saveBrandColors($siteId, is_array($posted) ? $posted : []);

        Response::json(['ok' => true, 'colors' => BrandPaletteService::brandColors($siteId)]);
    }

    /** POST /admin/design/extract-logo-colors — colores dominantes del logo. */
    public function extractLogoColors(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $colors = BrandPaletteService::extractFromLogos($siteId);
        if ($colors === []) {
            Response::json(['ok' => false, 'error' => __('onboarding.error.no_logo_colors')], 422);
        }

        Response::json(['ok' => true, 'colors' => $colors]);
    }

    /** POST /admin/design/generate-palette — propuestas de paleta con IA. */
    public function generatePalette(): void
    {
        @set_time_limit(120);
        CSRF::check();
        $siteId = self::requireSiteId();

        $posted = Request::post('brand_palette', []);
        $brand = BrandPaletteService::cleanHexList(is_array($posted) ? $posted : []);
        if ($brand === []) {
            // Sin colores de marca declarados seguimos teniendo el principal
            // del propio formulario.
            $brand = BrandPaletteService::cleanHexList([Request::post('primary_color_hex', '')]);
        }
        if ($brand === []) {
            Response::json(['ok' => false, 'error' => __('onboarding.error.need_brand_color')], 422);
        }

        $result = BrandPaletteService::propose($siteId, $brand);
        if ($result['palettes'] === []) {
            Response::json(['ok' => false, 'error' => $result['error'] ?: __('design.palette.error')], 502);
        }

        Response::json([
            'ok'       => true,
            'palettes' => $result['palettes'],
            'model'    => $result['model'],
            'fallback' => $result['fallback'],
            'notice'   => $result['fallback'] ? __('onboarding.palette.ai_fallback') : '',
        ]);
    }

    /**
     * DESIGN-MANDA T3 — Vuelca los colores del formulario a `site_palette_custom`.
     *
     * Se guarda TAL CUAL, sin `enforceContrast()`: la paleta del onboarding la
     * propone la IA y ahí corregir tiene sentido, pero aquí el hex lo ha elegido
     * una persona y devolverle otro distinto sin decir nada es peor que un
     * aviso.
     *
     * @param array<string,mixed> $colors
     * @return array<int,string> avisos de contraste ya traducidos
     */
    private static function syncPaletteFromColors(int $siteId, array $colors): array
    {
        if ($colors === []) return [];

        // Mapeo tokens del panel => claves de la paleta. `secondary`, `success`
        // y `danger` no tienen equivalente: no los pisa nadie, así que viven
        // solo en `design_system`.
        $palette = [
            'accent'      => (string) ($colors['primary']      ?? ''),
            'accent_dark' => (string) ($colors['primary_dark'] ?? ''),
            'accent_2'    => (string) ($colors['accent']       ?? ''),
            'bg'          => (string) ($colors['bg']           ?? ''),
            'surface'     => (string) ($colors['surface']      ?? ''),
            'text'        => (string) ($colors['text']         ?? ''),
            'muted'       => (string) ($colors['text_muted']   ?? ''),
            'line'        => (string) ($colors['border']       ?? ''),
        ];

        if (!BrandPaletteService::save($siteId, $palette)) return [];

        $warnings = [];
        foreach (BrandPaletteService::contrastReport($palette) as $issue) {
            $warnings[] = __('design.contrast.pair.' . $issue['pair'])
                . ' (' . number_format((float) $issue['value'], 1) . ':1'
                . ' < ' . number_format((float) $issue['min'], 1) . ':1)';
        }
        return $warnings;
    }

    public function updateLogo(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        // LOGO2 — Variante por FONDO: 'light' (fondos claros) o 'dark' (oscuros).
        $variant = (string) Request::post('variant', 'light');
        if (!isset(BrandService::LOGO_VARIANTS[$variant])) $variant = 'light';
        $settingKey = BrandService::LOGO_VARIANTS[$variant]['setting'];

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

        $filename = ($variant === 'dark' ? 'logo-dark-' : 'logo-') . bin2hex(random_bytes(8)) . '.' . $ext;
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
            self::storeSiteSetting($siteId, $settingKey, $relative);
            self::storeSiteSetting($siteId, $settingKey . '_media_id', (string) Database::lastInsertId());
        } catch (\Throwable $e) {
            @unlink($absolute);
            error_log('[design logo] site=' . $siteId . ' save failed: ' . $e->getMessage());
            Session::flash('error', 'No se pudo registrar el logo.');
            Response::redirect(base_url('admin/design'));
        }

        CacheService::flush($siteId);
        Session::flash('success', $variant === 'dark'
            ? 'Logo para fondos oscuros actualizado.'
            : 'Logo para fondos claros actualizado.');
        Response::redirect(base_url('admin/design'));
    }

    /**
     * POST /admin/design/logo/primary — cuál se usa cuando no se sabe el fondo.
     */
    public function updateLogoPrimary(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $variant = (string) Request::post('variant', 'light');
        if (!isset(BrandService::LOGO_VARIANTS[$variant])) $variant = 'light';

        self::storeSiteSetting($siteId, 'site_logo_primary', $variant);
        CacheService::flush($siteId);
        Session::flash('success', $variant === 'dark'
            ? 'Marcado como principal el logo para fondos oscuros.'
            : 'Marcado como principal el logo para fondos claros.');
        Response::redirect(base_url('admin/design'));
    }

    public function deleteLogo(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $variant = (string) Request::post('variant', 'light');
        if (!isset(BrandService::LOGO_VARIANTS[$variant])) $variant = 'light';
        $settingKey = BrandService::LOGO_VARIANTS[$variant]['setting'];

        // `site_logo_media_id` es la clave heredada del logo único; las nuevas
        // siguen el patrón `{clave}_media_id`. Se miran las dos para no dejar
        // filas huérfanas en instalaciones que vienen de la versión anterior.
        $mediaKeys = [$settingKey . '_media_id'];
        if ($variant === 'light') $mediaKeys[] = 'site_logo_media_id';

        $keys = array_merge([$settingKey], $mediaKeys);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $settings = Database::select(
            "SELECT setting_key, setting_value FROM settings WHERE site_id = ? AND setting_key IN ($placeholders)",
            array_merge([$siteId], $keys)
        );
        $current = [];
        foreach ($settings as $setting) $current[(string) $setting['setting_key']] = (string) $setting['setting_value'];

        Database::execute(
            "DELETE FROM settings WHERE site_id = ? AND setting_key IN ($placeholders)",
            array_merge([$siteId], $keys)
        );

        foreach ($mediaKeys as $mk) {
            if ((int) ($current[$mk] ?? 0) > 0) {
                Database::execute('DELETE FROM media WHERE id = ? AND site_id = ?', [(int) $current[$mk], $siteId]);
            }
        }

        $relative = (string) ($current[$settingKey] ?? '');
        $brandPrefix = 'storage/uploads/' . $siteId . '/brand/';
        if ($relative !== '' && str_starts_with($relative, $brandPrefix)) {
            $absolute = PP_ROOT . '/' . $relative;
            if (is_file($absolute)) @unlink($absolute);
        }

        // Si se borra justo la variante marcada como principal, el principal
        // pasa a la otra. `logoPathFor()` no sirve aquí porque ya devuelve la
        // otra variante como recambio: el ajuste se quedaría apuntando a un
        // hueco vacío y el panel enseñaría "Principal" sobre un slot sin logo.
        if (BrandService::data($siteId)['logo_primary'] === $variant) {
            self::storeSiteSetting($siteId, 'site_logo_primary', $variant === 'dark' ? 'light' : 'dark');
        }

        CacheService::flush($siteId);
        Session::flash('success', $variant === 'dark'
            ? 'Logo para fondos oscuros eliminado.'
            : 'Logo para fondos claros eliminado.');
        Response::redirect(base_url('admin/design'));
    }

    // ----------------------------------------------------------------------
    // FONTS — Tipografías propias del cliente (brandbook)
    // ----------------------------------------------------------------------

    /**
     * POST /admin/design/fonts
     * Sube uno o varios archivos a una familia (nueva o existente) y le asigna rol.
     */
    public function uploadFont(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $familyId = (int) Request::post('family_id', 0);
        $role = (string) Request::post('role', 'both');

        if ($familyId <= 0) {
            $name = trim((string) Request::post('family_name', ''));
            if ($name === '') {
                Session::flash('error', __('design.error.font_name_required'));
                Response::redirect(base_url('admin/design#fonts'));
            }
            $familyId = CustomFontService::ensureFamily($siteId, $name, $role);
        } else {
            $owned = Database::selectOne('SELECT id FROM site_font_families WHERE id = ? AND site_id = ? LIMIT 1', [$familyId, $siteId]);
            if (!$owned) Response::redirect(base_url('admin/design#fonts'));
        }

        $files = self::normalizeFontFiles(Request::file('font_files'));
        if ($files === []) {
            Session::flash('error', __('font.err.pick_file'));
            Response::redirect(base_url('admin/design#fonts'));
        }

        $ok = 0;
        $problems = [];
        foreach ($files as $file) {
            $result = CustomFontService::addFile($siteId, $familyId, $file);
            if ($result['ok']) {
                $ok++;
            } else {
                $problems[] = trim((string) ($file['name'] ?? 'archivo')) . ': ' . (string) $result['error'];
            }
        }

        if ($ok > 0) {
            DesignSystem::syncCustomFontTokens($siteId);
            CacheService::flush($siteId);
        }

        if ($ok > 0 && $problems === []) {
            Session::flash('success', __($ok === 1 ? 'design.flash.font_added_one' : 'design.flash.font_added_n', ['n' => $ok]));
        } elseif ($ok > 0) {
            Session::flash('error', __('design.error.partial_upload', ['n' => $ok, 'problemas' => implode(' · ', $problems)]));
        } else {
            Session::flash('error', __('design.error.no_font_added', ['problemas' => implode(' · ', $problems)]));
        }
        Response::redirect(base_url('admin/design#fonts'));
    }

    /** POST /admin/design/fonts/role */
    public function updateFontRole(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $familyId = (int) Request::post('family_id', 0);
        $role = (string) Request::post('role', 'none');
        $owned = Database::selectOne('SELECT name FROM site_font_families WHERE id = ? AND site_id = ? LIMIT 1', [$familyId, $siteId]);
        if (!$owned) Response::redirect(base_url('admin/design#fonts'));

        CustomFontService::assignRole($siteId, $familyId, $role);
        DesignSystem::syncCustomFontTokens($siteId);
        CacheService::flush($siteId);
        Session::flash('success', match (CustomFontService::normalizeRole($role)) {
            'both'    => __('design.flash.font_role_both', ['fuente' => (string) $owned['name']]),
            'heading' => __('design.flash.font_role_heading', ['fuente' => (string) $owned['name']]),
            'body'    => __('design.flash.font_role_body', ['fuente' => (string) $owned['name']]),
            default   => __('design.flash.font_role_none', ['fuente' => (string) $owned['name']]),
        });
        Response::redirect(base_url('admin/design#fonts'));
    }

    /** POST /admin/design/fonts/file/delete */
    public function deleteFontFile(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        CustomFontService::deleteFile($siteId, (int) Request::post('file_id', 0));
        DesignSystem::syncCustomFontTokens($siteId);
        CacheService::flush($siteId);
        Session::flash('success', 'Peso eliminado.');
        Response::redirect(base_url('admin/design#fonts'));
    }

    /** POST /admin/design/fonts/file/cut — corrige peso/estilo de un archivo. */
    public function updateFontCut(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        CustomFontService::updateFile(
            $siteId,
            (int) Request::post('file_id', 0),
            (int) Request::post('weight', 400),
            (string) Request::post('style', 'normal')
        );
        CacheService::flush($siteId);
        Session::flash('success', 'Peso actualizado.');
        Response::redirect(base_url('admin/design#fonts'));
    }

    /** POST /admin/design/fonts/delete */
    public function deleteFontFamily(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $familyId = (int) Request::post('family_id', 0);
        $family = Database::selectOne('SELECT slug FROM site_font_families WHERE id = ? AND site_id = ? LIMIT 1', [$familyId, $siteId]);
        if (!$family) Response::redirect(base_url('admin/design#fonts'));

        CustomFontService::deleteFamily($siteId, $familyId);

        // Si algún token apuntaba a esta familia, devolverlo a una fuente válida:
        // dejar `custom:{slug}` colgando haría que la web cayera al stack del
        // sistema sin que el usuario entendiera por qué.
        $token = 'custom:' . (string) $family['slug'];
        $tokens = DesignSystem::load($siteId);
        $changed = false;
        foreach (['font_heading', 'font_body'] as $key) {
            if (($tokens['typography'][$key] ?? '') === $token) {
                $tokens['typography'][$key] = 'Inter';
                $changed = true;
            }
        }
        if ($changed) DesignSystem::saveCategory($siteId, 'typography', $tokens['typography']);

        CacheService::flush($siteId);
        Session::flash('success', __('design.flash.font_deleted') . ($changed ? ' ' . __('design.flash.font_deleted_reset') : ''));
        Response::redirect(base_url('admin/design#fonts'));
    }

    /**
     * `Request::file()` devuelve el array crudo de $_FILES: para un input
     * `multiple` cada clave es un array paralelo. Lo normalizamos a una lista
     * de archivos individuales.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeFontFiles(mixed $raw): array
    {
        if (!is_array($raw) || !isset($raw['name'])) return [];
        if (!is_array($raw['name'])) {
            return ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = [
                'name'     => (string) $raw['name'][$i],
                'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
                'size'     => (int) ($raw['size'][$i] ?? 0),
                'error'    => (int) ($raw['error'][$i] ?? UPLOAD_ERR_OK),
            ];
        }
        return $out;
    }

    private static function validateLogoUpload(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Selecciona un archivo de logo.';
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return __('design.error.logo_upload');
        if (($file['size'] ?? 0) <= 0 || (int) $file['size'] > 2 * 1024 * 1024) return 'El logo debe pesar menos de 2 MB.';
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) return __('documents.error.invalid_upload');
        $mime = (string) mime_content_type($tmp);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return 'El logo debe ser PNG, JPG o WebP.';
        return @getimagesize($tmp) === false ? __('design.error.not_an_image') : null;
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
        DesignSystem::forgetSkin($siteId);
        // DESIGN-MANDA T6 — "Restablecer" tiene que volver a los defaults DE
        // VERDAD: si dejáramos la paleta a medida o los overrides manuales, el
        // sitio seguiría pintando lo de antes y el botón mentiría.
        // `regenerate()` NO los toca, a propósito: regenerar el skin con IA no
        // debe pisar lo que el usuario decidió a mano.
        DesignSystem::clearManualTokens($siteId);
        BrandPaletteService::clear($siteId);
        CacheService::flush($siteId);
        Session::flash('success', __('design.flash.reset'));
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
                ? ' ' . __('design.flash.no_signals')
                : ' ' . __('design.flash.sources_used', ['fuentes' => implode(', ', $sources)]);
            Session::flash('success', __('design.flash.regenerated') . $sourcesNote);
        } catch (\Throwable $e) {
            Session::flash('error', __('design.error.regenerate', ['error' => $e->getMessage()]));
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
        $brand = BrandService::data($siteId);
        $logoSlots = [];
        foreach (BrandService::LOGO_VARIANTS as $key => $meta) {
            $path = $key === 'dark' ? (string) $brand['logo_dark_path'] : (string) $brand['logo_path'];
            $exists = $path !== '' && is_file(PP_ROOT . '/' . ltrim($path, '/'));
            $logoSlots[$key] = [
                // ADMIN-I18N: `$meta['label']` es una clave, no texto. Diseño
                // se traduce en una fase posterior, pero la etiqueta tiene que
                // resolverse YA o esta pantalla enseñaría la clave en crudo.
                'label'   => BrandService::variantLabel($key),
                'path'    => $path,
                'url'     => $exists ? BrandService::publicLogoUrl($siteId, $path, $key) : '',
                'missing' => $path !== '' && !$exists,
                'primary' => $brand['logo_primary'] === $key,
            ];
        }

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'logoSlots'    => $logoSlots,
            'logoPrimary'  => (string) $brand['logo_primary'],
            'schema'       => DesignSystem::schema(),
            'tokens'       => $ctx['tokens'],
            'errors'       => $ctx['errors'],
            'fontOptions'  => DesignSystem::fontOptions($siteId),
            'cssVars'      => DesignSystem::toCssVars($ctx['tokens'], $siteId),
            // FONTS — tipografías propias + aviso de pesos que el sitio usa y
            // el cliente no ha subido (si no, el navegador finge la negrita).
            'customFonts'      => CustomFontService::families($siteId),
            'customFontRoles'  => CustomFontService::roles(),
            'customFontWeights'=> CustomFontService::WEIGHT_LABELS,
            'customFontCss'    => CustomFontService::renderFontFaceCss($siteId),
            'fontWeightGaps'   => self::fontWeightGaps($siteId, $ctx['tokens']),
            'fontHeavyFiles'   => self::heavyFontFiles($siteId),
            'googleFonts'  => DesignSystem::googleFontsUsed($ctx['tokens']),
            'csrf'         => CSRF::token(),
            // DESIGN-MANDA T10 — Colores de marca (materia prima de la paleta).
            'brandColors'  => BrandPaletteService::brandColors($siteId),
            // DESIGN-MANDA T5 — Tipografías de marca que mandan sobre el select.
            'fontRoleOwner' => self::fontRoleOwners($siteId),
            'logoPath' => $ctxLogoPath,
            'logoUrl' => \App\Services\BrandService::logoUrl($siteId),
            'logoMissing' => $ctxLogoPath !== '' && !is_file(PP_ROOT . '/' . ltrim($ctxLogoPath, '/')),
        ]);
        View::send('admin/design/index', $data);
    }

    /**
     * DESIGN-MANDA T5 — Campos de tipografía que están mandados por una familia
     * de marca, para poder decirlo en el propio campo. Callarlo reproduce con
     * las letras el problema que acabamos de arreglar con los colores.
     *
     * @return array<string,string> clave del token => nombre de la familia
     */
    private static function fontRoleOwners(int $siteId): array
    {
        $out = [];
        foreach (['font_heading' => 'heading', 'font_body' => 'body'] as $tokenKey => $role) {
            $fam = CustomFontService::familyForRole($siteId, $role);
            if ($fam !== null) $out[$tokenKey] = (string) $fam['name'];
        }
        return $out;
    }

    /**
     * FONTS — Pesos que el sitio pide a cada rol y que la familia asignada no
     * tiene subidos. Sin este aviso el navegador inventa la negrita (fake bold)
     * y el cliente ve su marca "casi bien" sin saber por qué.
     *
     * @return array<int,array{family:string,role:string,missing:array<int,string>}>
     */
    private static function fontWeightGaps(int $siteId, array $tokens): array
    {
        $typo = $tokens['typography'] ?? [];
        $needed = [
            'heading' => [(int) ($typo['weight_bold'] ?? 700)],
            'body'    => [(int) ($typo['weight_regular'] ?? 400), (int) (($tokens['buttons']['font_weight'] ?? 600))],
        ];

        $out = [];
        foreach ($needed as $role => $weights) {
            $family = CustomFontService::familyForRole($siteId, $role);
            if ($family === null) continue;
            $have = array_column(array_filter($family['files'], static fn ($f) => $f['style'] === 'normal'), 'weight');
            $missing = [];
            foreach (array_unique($weights) as $w) {
                if (!in_array($w, $have, true)) {
                    $missing[] = CustomFontService::WEIGHT_LABELS[$w] ?? (string) $w;
                }
            }
            if ($missing === []) continue;
            $key = $family['slug'] . '|' . $role;
            $out[$key] = [
                'family'  => (string) $family['name'],
                'role'    => $role === 'heading' ? __('design.role_headings') : __('design.role_body'),
                'missing' => $missing,
            ];
        }
        return array_values($out);
    }

    /**
     * FONTS — Archivos que van a hacer esperar al visitante: TTF/OTF (sin
     * comprimir para web) o cualquier corte que se pase de tamaño.
     *
     * Un TTF ronda los 400-500 KB; el mismo tipo en WOFF2 baja a 30-60 KB. Esa
     * diferencia es la que se ve como parpadeo al cargar la página.
     *
     * @return array{files:array<int,array{name:string,label:string,size:string,format:string}>,total:string}
     */
    private static function heavyFontFiles(int $siteId): array
    {
        $heavy = [];
        $total = 0;

        foreach (CustomFontService::families($siteId) as $family) {
            if ($family['role'] === 'none') continue; // si no se usa, no molesta al visitante
            foreach ($family['files'] as $file) {
                $total += (int) $file['file_size'];
                $isRaw = in_array($file['format'], ['ttf', 'otf'], true);
                if (!$isRaw && (int) $file['file_size'] < 120 * 1024) continue;
                $heavy[] = [
                    'name'   => (string) $family['name'],
                    'label'  => (string) $file['label'],
                    'size'   => self::humanBytes((int) $file['file_size']),
                    'format' => strtoupper((string) $file['format']),
                ];
            }
        }

        return ['files' => $heavy, 'total' => self::humanBytes($total)];
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        return max(1, (int) round($bytes / 1024)) . ' KB';
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
