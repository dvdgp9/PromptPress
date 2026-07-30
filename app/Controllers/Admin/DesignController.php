<?php

namespace App\Controllers\Admin;

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
                Session::flash('error', 'Ponle un nombre a la tipografía (por ejemplo, el que aparece en tu manual de marca).');
                Response::redirect(base_url('admin/design#fonts'));
            }
            $familyId = CustomFontService::ensureFamily($siteId, $name, $role);
        } else {
            $owned = Database::selectOne('SELECT id FROM site_font_families WHERE id = ? AND site_id = ? LIMIT 1', [$familyId, $siteId]);
            if (!$owned) Response::redirect(base_url('admin/design#fonts'));
        }

        $files = self::normalizeFontFiles(Request::file('font_files'));
        if ($files === []) {
            Session::flash('error', 'Selecciona al menos un archivo de fuente (WOFF2, WOFF, TTF u OTF).');
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
            Session::flash('success', $ok === 1 ? 'Fuente añadida. Ya se está usando en tu web.' : $ok . ' archivos añadidos. Ya se están usando en tu web.');
        } elseif ($ok > 0) {
            Session::flash('error', $ok . ' archivo(s) añadidos, pero hubo problemas: ' . implode(' · ', $problems));
        } else {
            Session::flash('error', 'No se pudo añadir ninguna fuente. ' . implode(' · ', $problems));
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
            'both'    => (string) $owned['name'] . ' se usará en títulos y textos.',
            'heading' => (string) $owned['name'] . ' se usará en los títulos.',
            'body'    => (string) $owned['name'] . ' se usará en los textos.',
            default   => (string) $owned['name'] . ' ya no se usa en la web (sigue guardada).',
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
        Session::flash('success', 'Tipografía eliminada.' . ($changed ? ' Las secciones que la usaban vuelven a la fuente por defecto.' : ''));
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
        $brand = BrandService::data($siteId);
        $logoSlots = [];
        foreach (BrandService::LOGO_VARIANTS as $key => $meta) {
            $path = $key === 'dark' ? (string) $brand['logo_dark_path'] : (string) $brand['logo_path'];
            $exists = $path !== '' && is_file(PP_ROOT . '/' . ltrim($path, '/'));
            $logoSlots[$key] = [
                'label'   => $meta['label'],
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
            'customFontRoles'  => CustomFontService::ROLES,
            'customFontWeights'=> CustomFontService::WEIGHT_LABELS,
            'customFontCss'    => CustomFontService::renderFontFaceCss($siteId),
            'fontWeightGaps'   => self::fontWeightGaps($siteId, $ctx['tokens']),
            'fontHeavyFiles'   => self::heavyFontFiles($siteId),
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
                'role'    => $role === 'heading' ? 'los títulos' : 'los textos y botones',
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
