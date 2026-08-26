<?php

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\DesignSystem;
use App\Services\PageTemplateService;
use App\Services\Renderer\SectionRenderer;
use App\Services\SectionSchemas;
use App\Services\VersionService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * CRUD de secciones de una página.
 * En T3.2 el contenido/estilo se edita como JSON genérico.
 * T3.3 reemplazará por formularios específicos por tipo.
 *
 * Todas las respuestas son JSON (API tipo REST consumida por sections-editor.js).
 */
class SectionController
{
    /** Tipos de sección soportados. T3.3 añadirá schemas y forms para cada uno. */
    public const SECTION_TYPES = [
        'hero'         => 'section_type.hero',
        'text_image'   => 'section_type.text_image',
        'benefits'     => 'section_type.benefits',
        'testimonials' => 'section_type.testimonials',
        'stats'        => 'section_type.stats',
        'gallery'      => 'section_type.gallery',
        'steps'        => 'section_type.steps',
        'logos_strip'  => 'section_type.logos_strip',
        'pricing'      => 'section_type.pricing',
        'faq'          => 'section_type.faq',
        'cta'          => 'section_type.cta',
        'form'         => 'section_type.form',
        'booking'      => 'section_type.booking',
        'article_body' => 'section_type.article_body',
        'posts_listing'=> 'section_type.posts_listing',
        'custom_block' => 'section_type.custom_block',
        'generic'      => 'section_type.generic',
    ];

    /**
     * Los mismos tipos con la etiqueta ya traducida. La constante guarda CLAVES
     * (se evalúa antes de saber el idioma) y todo el código que solo comprueba
     * `isset(SECTION_TYPES[$x])` sigue funcionando igual.
     *
     * @return array<string,string>
     */
    public static function sectionTypesForView(?int $siteId = null): array
    {
        $siteId = $siteId ?? \Core\Auth::siteId();
        $out = [];
        foreach (self::SECTION_TYPES as $type => $key) {
            // MODULOS M2 — un tipo que depende de un módulo apagado no se ofrece:
            // se elegiría en el desplegable y luego no pintaría nada.
            if (!\App\Services\SectionSchemas::isAvailableForSite($type, $siteId)) {
                continue;
            }
            $out[$type] = __($key);
        }
        return $out;
    }

    // ----------------------------------------------------------------------
    // Crear sección (en una página)
    // POST /admin/pages/{pageId}/sections
    // ----------------------------------------------------------------------
    public function store(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['pageId'] ?? 0);
        self::requirePage($pageId, $siteId);

        $type = (string) Request::post('section_type', 'generic');
        if (!isset(self::SECTION_TYPES[$type])
            || !\App\Services\SectionSchemas::isAvailableForSite($type, $siteId)) {
            Response::json(['ok' => false, 'error' => __('section.error.bad_type')], 422);
        }

        // sort_order: último + 1
        $last = Database::selectOne(
            'SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM page_sections WHERE page_id = ?',
            [$pageId]
        );
        $sortOrder = ((int) ($last['max_order'] ?? -1)) + 1;

        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO page_sections
                (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pageId, $type, $sortOrder,
                json_encode(self::defaultContent($type), JSON_UNESCAPED_UNICODE),
                null, 'editable', $now, $now,
            ]
        );
        $sectionId = (int) Database::lastInsertId();
        self::touchPage($pageId);

        Response::json([
            'ok'      => true,
            'section' => self::fetchSection($sectionId, $pageId),
        ]);
    }

    // ----------------------------------------------------------------------
    // Actualizar sección
    // POST /admin/sections/{id}
    // ----------------------------------------------------------------------
    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id     = (int) ($params['id'] ?? 0);
        $section = self::findOrFail($id, $siteId);

        $type        = (string) Request::post('section_type', $section['section_type']);
        $contentRaw  = (string) Request::post('content', '{}');
        $styleRaw    = (string) Request::post('style', '');
        $status      = (string) Request::post('status', $section['status']);
        $versionReason = (string) Request::post('version_reason', 'before_manual_update');
        if (!in_array($versionReason, ['before_manual_update', 'before_ai_edit'], true)) {
            $versionReason = 'before_manual_update';
        }

        if (!isset(self::SECTION_TYPES[$type])) {
            Response::json(['ok' => false, 'error' => __('section.error.bad_type')], 422);
        }
        if (!in_array($status, ['editable', 'locked'], true)) {
            Response::json(['ok' => false, 'error' => __('page_ctrl.bad_status2')], 422);
        }

        // Validar JSON de content
        $content = json_decode($contentRaw, true);
        if ($contentRaw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            Response::json([
                'ok'    => false,
                'error' => __('section.error.bad_content_json', ['error' => json_last_error_msg()]),
            ], 422);
        }
        if (!is_array($content)) {
            $content = [];
        }

        // Validar JSON de style (puede estar vacío)
        $style = null;
        if (trim($styleRaw) !== '') {
            $style = json_decode($styleRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::json([
                    'ok'    => false,
                    'error' => __('section.error.bad_style_json', ['error' => json_last_error_msg()]),
                ], 422);
            }
        }

        // Saneamiento de `style.variant`: si llega una variante desconocida,
        // se elimina (el renderer hará fallback a default igualmente, pero
        // dejamos la BD limpia). Mantener el resto del style intacto.
        if (is_array($style) && array_key_exists('variant', $style)) {
            $variantRaw = is_string($style['variant']) ? trim($style['variant']) : '';
            if ($variantRaw === '' || $variantRaw === 'default') {
                unset($style['variant']);
            } elseif (!SectionSchemas::isValidVariant($type, $variantRaw)) {
                unset($style['variant']);
            } else {
                $style['variant'] = $variantRaw;
            }
            if (empty($style)) $style = null;
        }

        $newContentJson = json_encode($content, JSON_UNESCAPED_UNICODE);
        $newStyleJson = $style !== null ? json_encode($style, JSON_UNESCAPED_UNICODE) : null;
        $hasChanges = $type !== (string) $section['section_type']
            || $newContentJson !== (string) $section['content']
            || $newStyleJson !== ($section['style'] !== null ? (string) $section['style'] : null)
            || $status !== (string) $section['status'];

        if ($hasChanges) {
            VersionService::snapshotSection($section, Auth::id(), $versionReason);
        }

        Database::execute(
            'UPDATE page_sections
             SET section_type = ?, content = ?, style = ?, status = ?
             WHERE id = ?',
            [
                $type,
                $newContentJson,
                $newStyleJson,
                $status,
                $id,
            ]
        );
        self::touchPage((int) $section['page_id']);

        Response::json([
            'ok'      => true,
            'section' => self::fetchSection($id, (int) $section['page_id']),
        ]);
    }

    // ----------------------------------------------------------------------
    // Borrar sección
    // POST /admin/sections/{id}/delete
    // ----------------------------------------------------------------------
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id     = (int) ($params['id'] ?? 0);
        $section = self::findOrFail($id, $siteId);

        VersionService::snapshotSection($section, Auth::id(), 'before_delete');

        Database::execute('DELETE FROM page_sections WHERE id = ?', [$id]);
        self::touchPage((int) $section['page_id']);

        Response::json(['ok' => true, 'id' => $id]);
    }

    // ----------------------------------------------------------------------
    // Historial de versiones de una sección
    // GET /admin/sections/{id}/versions
    // ----------------------------------------------------------------------
    public function versions(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        self::findOrFail($id, $siteId);

        $versions = array_map(static function (array $v): array {
            return [
                'id'         => (int) $v['id'],
                'reason'     => (string) $v['reason'],
                'label'      => VersionService::reasonLabel((string) $v['reason']),
                'created_at' => (string) $v['created_at'],
                'username'   => (string) ($v['username'] ?? 'Sistema'),
            ];
        }, VersionService::sectionVersions($id));

        Response::json([
            'ok'       => true,
            'versions' => $versions,
        ]);
    }

    // ----------------------------------------------------------------------
    // Restaurar una versión de sección
    // POST /admin/sections/{id}/versions/{versionId}/restore
    // ----------------------------------------------------------------------
    public function restoreVersion(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $versionId = (int) ($params['versionId'] ?? 0);
        $section = self::findOrFail($id, $siteId);
        $version = VersionService::loadSectionVersion($id, $versionId);

        if ($version === null) {
            Response::json(['ok' => false, 'error' => __('section.error.version_not_found')], 404);
        }

        $data = $version['data'];
        $type = (string) ($data['section_type'] ?? '');
        $status = (string) ($data['status'] ?? 'editable');
        if (!isset(self::SECTION_TYPES[$type]) || !in_array($status, ['editable', 'locked'], true)) {
            Response::json(['ok' => false, 'error' => __('section.error.not_restorable')], 422);
        }

        $contentRaw = (string) ($data['content'] ?? '{}');
        json_decode($contentRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::json(['ok' => false, 'error' => __('section.error.version_bad_content')], 422);
        }

        $styleRaw = $data['style'] ?? null;
        if ($styleRaw !== null) {
            json_decode((string) $styleRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::json(['ok' => false, 'error' => __('section.error.version_bad_style')], 422);
            }
        }

        VersionService::snapshotSection($section, Auth::id(), 'before_restore');

        Database::execute(
            'UPDATE page_sections
             SET section_type = ?, content = ?, style = ?, status = ?
             WHERE id = ?',
            [$type, $contentRaw, $styleRaw, $status, $id]
        );
        self::touchPage((int) $section['page_id']);

        Response::json([
            'ok'      => true,
            'section' => self::fetchSection($id, (int) $section['page_id']),
        ]);
    }

    // ----------------------------------------------------------------------
    // Reordenar secciones (batch)
    // POST /admin/pages/{pageId}/sections/reorder
    // Body: order=id1,id2,id3,...   (form-encoded) — orden deseado
    // ----------------------------------------------------------------------
    public function reorder(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['pageId'] ?? 0);
        self::requirePage($pageId, $siteId);

        $orderRaw = (string) Request::post('order', '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $orderRaw))));

        if (empty($ids)) {
            Response::json(['ok' => false, 'error' => __('section.error.no_order')], 422);
        }

        // Verificar que todos los ids pertenezcan al page
        $existing = Database::select(
            'SELECT id FROM page_sections WHERE page_id = ?',
            [$pageId]
        );
        $existingIds = array_map(fn($r) => (int) $r['id'], $existing);
        sort($existingIds);
        $sortedInput = $ids;
        sort($sortedInput);
        if ($existingIds !== $sortedInput) {
            Response::json([
                'ok'    => false,
                'error' => __('section.error.order_mismatch'),
            ], 422);
        }

        // Aplicar nuevo orden
        foreach ($ids as $pos => $secId) {
            Database::execute(
                'UPDATE page_sections SET sort_order = ? WHERE id = ? AND page_id = ?',
                [$pos, $secId, $pageId]
            );
        }
        self::touchPage($pageId);

        Response::json(['ok' => true, 'count' => count($ids)]);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function findOrFail(int $id, int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT s.* FROM page_sections s
             JOIN pages p ON p.id = s.page_id
             WHERE s.id = ? AND p.site_id = ?
             LIMIT 1',
            [$id, $siteId]
        );
        if (!$row) {
            Response::json(['ok' => false, 'error' => __('section.error.not_found')], 404);
        }
        return $row;
    }

    private static function requirePage(int $pageId, int $siteId): array
    {
        $page = Database::selectOne(
            'SELECT id FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
            [$pageId, $siteId]
        );
        if (!$page) {
            Response::json(['ok' => false, 'error' => __('post_ctrl.page_not_found')], 404);
        }
        return $page;
    }

    private static function fetchSection(int $id, int $pageId): array
    {
        $row = Database::selectOne(
            'SELECT id, page_id, section_type, sort_order, content, style, status
             FROM page_sections WHERE id = ? AND page_id = ?',
            [$id, $pageId]
        );
        if (!$row) return [];
        // Decodificar JSONs para el frontend
        $row['content_json'] = $row['content'];
        $row['style_json']   = $row['style'];
        return $row;
    }

    private static function touchPage(int $pageId): void
    {
        Database::execute(
            'UPDATE pages SET updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $pageId]
        );
        // T7.3: invalidar cache público de esta página (y home si aplica).
        $page = Database::selectOne(
            'SELECT site_id, slug, page_type FROM pages WHERE id = ?',
            [$pageId]
        );
        if ($page) {
            CacheService::invalidatePage((int) $page['site_id'], $page);
        }
    }

    // ----------------------------------------------------------------------
    // E1.9 — Preview HTML iframe-ready de una sección en una variante concreta
    // GET /admin/sections/variant-preview?type={t}&variant={v}
    // Devuelve un mini-documento HTML con UNA sola sección renderizada con
    // contenido placeholder y el design system del sitio. Pensado para servirse
    // en iframes pequeños dentro del variant picker.
    // ----------------------------------------------------------------------
    public function variantPreview(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($isPost) CSRF::check();

        $type    = (string) ($isPost ? Request::post('type', '')         : Request::get('type', ''));
        $variant = (string) ($isPost ? Request::post('variant', 'default') : Request::get('variant', 'default'));
        if (!isset(self::SECTION_TYPES[$type])) {
            http_response_code(404);
            echo '<!doctype html><meta charset=utf-8><body style="font-family:system-ui;padding:24px;color:#64748b">' . e(__('section.error.bad_type_short')) . '</body>';
            return;
        }
        $variant = SectionSchemas::normalizeVariant($type, $variant);

        if ($isPost) {
            // Modo "contenido real": el cliente pasa el content/style actual del editor.
            $contentRaw = (string) Request::post('content', '');
            $styleRaw   = (string) Request::post('style', '');
            $contentArr = $contentRaw !== '' ? json_decode($contentRaw, true) : null;
            $styleArr   = $styleRaw   !== '' ? json_decode($styleRaw, true)   : null;
            $content = is_array($contentArr) ? $contentArr : [];
            // Forzar la variante recibida sobre el style enviado (chip determina la variante a renderizar).
            $style = is_array($styleArr) ? $styleArr : [];
            if ($variant !== 'default') $style['variant'] = $variant;
            else unset($style['variant']);
        } else {
            // Modo "placeholder": GET para chips de fallback / primer pintado.
            $content = PageTemplateService::placeholderContent($type, $type . '-' . $variant);
            $style   = $variant !== 'default' ? ['variant' => $variant] : null;

            // ETag solo en GET (POST con contenido variable no se cachea).
            $tokens = DesignSystem::load($siteId);
            $codeFiles = [
                __DIR__ . '/../../Services/Renderer/SectionRenderer.php',
                __DIR__ . '/../../Services/DesignSystem.php',
                __DIR__ . '/../../Services/PageTemplateService.php',
                __DIR__ . '/../../Services/SectionSchemas.php',
            ];
            $codeMtime = 0;
            foreach ($codeFiles as $f) {
                if (is_file($f)) {
                    $m = @filemtime($f);
                    if ($m && $m > $codeMtime) $codeMtime = $m;
                }
            }
            $etag = '"' . sha1(json_encode([
                'v' => 1, 'site' => $siteId, 'type' => $type, 'variant' => $variant,
                'tokens' => $tokens, 'mtime' => $codeMtime,
            ], JSON_UNESCAPED_UNICODE)) . '"';
            $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
            $clientEtag = preg_replace('/^W\//', '', $ifNoneMatch);
            if ($clientEtag !== '' && $clientEtag === $etag) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: private, max-age=600, must-revalidate');
                header('Vary: Cookie');
                return;
            }
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=600, must-revalidate');
            header('Vary: Cookie');
        }

        $fake = [
            'id'           => 1,
            'section_type' => $type,
            'content_json' => $content,
            'style_json'   => $style ?: null,
        ];

        SectionRenderer::setSiteContext($siteId);
        $body = SectionRenderer::render($fake);
        // El iframe es `sandbox="allow-same-origin"`: los scripts NO se ejecutan
        // aquí. Se quitan para que el navegador no llene la consola del panel de
        // "Blocked script execution" cada vez que se abre una sección que trae
        // uno (el calendario de reservas). Lo que se ve en su lugar es el avance
        // que el propio bloque pinta en el servidor.
        $body = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body) ?? $body;
        $designHead = DesignSystem::renderHead($siteId);

        $html  = '<!doctype html><html lang="es"><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=1200">';
        $html .= '<meta name="robots" content="noindex,nofollow">';
        $html .= $designHead;
        $html .= '<style>html,body{margin:0;padding:0;background:#fff}'
              .  'body{pointer-events:none}'
              .  '.pp-section{margin:0;padding-top:36px;padding-bottom:36px}'
              .  '</style>';
        $html .= '</head><body>';
        $html .= $body;
        $html .= '</body></html>';

        if ($isPost) {
            header('Cache-Control: no-store');
        }
        Response::html($html);
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Response::json(['ok' => false, 'error' => 'No hay sitio activo'], 401);
        }
        return $siteId;
    }

    /**
     * Contenido por defecto para cada tipo de sección (placeholders hasta T3.3).
     */
    // i18n-ignore-start: CONTENIDO por defecto de una sección nueva. Lo lee el
    // VISITANTE de la web, no el gestor: su idioma es el del sitio, no el del
    // panel. Traducirlo aquí sería meterle el idioma equivocado (ver la nota de
    // `PageController` en el scratchpad).
    private static function defaultContent(string $type): array
    {
        return match ($type) {
            'hero'         => ['heading' => 'Título destacado', 'subheading' => '', 'cta_text' => '', 'cta_url' => ''],
            'text_image'   => ['heading' => '', 'body' => '', 'image_url' => '', 'image_side' => 'right'],
            'benefits'     => ['heading' => 'Nuestros beneficios', 'items' => []],
            'testimonials' => ['heading' => 'Lo que dicen nuestros clientes', 'items' => []],
            'stats'        => ['heading' => '', 'items' => []],
            'gallery'      => ['heading' => '', 'items' => []],
            'steps'        => ['heading' => 'Cómo trabajamos', 'items' => []],
            'logos_strip'  => ['heading' => 'Confían en nosotros', 'items' => []],
            'pricing'      => ['heading' => 'Planes y precios', 'items' => []],
            'faq'          => ['heading' => 'Preguntas frecuentes', 'items' => []],
            'cta'          => ['heading' => '¿Listo para empezar?', 'description' => '', 'cta_text' => '', 'cta_url' => ''],
            'form'         => ['heading' => 'Contacto', 'fields' => []],
            'article_body' => ['blocks' => []],
            'posts_listing'=> ['heading' => 'Últimas entradas', 'subheading' => '', 'limit' => '6'],
            'custom_block' => [
                'version' => 'ppb:1',
                'html' => '',
                'fields' => new \stdClass(),
                'source' => ['kind' => 'manual'],
                'rationale' => new \stdClass(),
                'validation' => ['sanitized' => false, 'warnings' => [], 'removed' => []],
            ],
            default        => [],
        };
    }
    // i18n-ignore-end
}
