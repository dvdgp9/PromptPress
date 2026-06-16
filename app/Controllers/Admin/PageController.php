<?php

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use App\Services\BrandService;
use App\Services\DesignSystem;
use App\Services\ImageBankService;
use App\Services\PageTemplateService;
use App\Services\PalettePresets;
use App\Services\Renderer\CustomBlockGenerator;
use App\Services\Renderer\SectionRenderer;
use App\Services\SectionSchemas;
use App\Services\SeoIndexingService;
use App\Services\SeoRedirectService;
use App\Services\VisualStyleService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * CRUD de páginas.
 * Gestiona sólo la metadata (título, slug, tipo, SEO, estado).
 * El editor de secciones se implementa en T3.2.
 */
class PageController
{
    public const PAGE_TYPES = [
        'home'    => 'Inicio',
        'service' => 'Servicio',
        'product' => 'Producto',
        'landing' => 'Landing',
        'article' => 'Artículo',
        'contact' => 'Contacto',
        'legal'   => 'Legal',
    ];

    private const STATUSES = ['draft', 'published'];

    // ----------------------------------------------------------------------
    // Listado
    // ----------------------------------------------------------------------
    public function index(array $params = []): void
    {
        $siteId = self::requireSiteId();
        self::ensureHierarchySchema();
        self::inferInitialHierarchy($siteId);
        self::repairFlatOnboardingHierarchy($siteId);

        $pages = Database::select(
            'SELECT id, title, slug, page_type, parent_id, nav_label, tree_sort_order,
                    status, updated_at, published_at
             FROM pages WHERE site_id = ?
             ORDER BY tree_sort_order ASC, sort_order ASC, updated_at DESC',
            [$siteId]
        );

        $data = DashboardController::getCommonData();
        $data['pages']       = $pages;
        $data['pageTypes']   = self::PAGE_TYPES;
        $data['pageTree']    = self::buildPageTree($pages);
        $data['pageOptions'] = self::pageOptions($pages);
        $data['csrf']        = CSRF::token();
        $data['aiMeta']      = AIProviderFactory::currentMeta($siteId);
        $data['hasMemory']   = self::siteHasMemory($siteId);

        View::send('admin/pages/index', $data);
    }

    // ----------------------------------------------------------------------
    // Lista de páginas para el selector de enlaces (JSON) — Integridad de enlaces
    // GET /admin/pages/list
    // ----------------------------------------------------------------------
    public function listJson(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $rows = Database::select(
            'SELECT id, title, slug, page_type, status FROM pages
             WHERE site_id = ? ORDER BY tree_sort_order ASC, sort_order ASC, title ASC',
            [$siteId]
        );
        Response::json([
            'ok'    => true,
            'pages' => array_map([self::class, 'pageLinkInfo'], $rows),
        ]);
    }

    // ----------------------------------------------------------------------
    // Crear página "al vuelo" desde el selector de enlaces (JSON)
    // POST /admin/pages/quick  { title, page_type? }
    // ----------------------------------------------------------------------
    public function quickCreate(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $title = trim((string) Request::post('title', ''));
        if ($title === '' || mb_strlen($title) > 500) {
            Response::json(['ok' => false, 'errors' => ['title' => 'El título es obligatorio (máx. 500 caracteres).']], 422);
        }
        $type = (string) Request::post('page_type', 'landing');
        if (!isset(self::PAGE_TYPES[$type])) {
            $type = 'landing';
        }
        $slug = self::uniqueSlug($siteId, slugify($title));

        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO pages
                (site_id, title, slug, page_type, meta_title, meta_description,
                 seo_noindex, seo_exclude_sitemap, canonical_url,
                 status, sort_order, created_by, created_at, updated_at, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$siteId, $title, $slug, $type, null, null, 0, 0, null, 'draft', 0, Auth::id(), $now, $now, null]
        );
        $id = (int) Database::connection()->lastInsertId();

        Response::json([
            'ok'   => true,
            'page' => self::pageLinkInfo([
                'id' => $id, 'title' => $title, 'slug' => $slug,
                'page_type' => $type, 'status' => 'draft',
            ]),
        ]);
    }

    /** Normaliza una fila de página a la info que necesita el selector de enlaces. */
    private static function pageLinkInfo(array $p): array
    {
        $path = ($p['page_type'] ?? '') === 'home' ? '/' : '/' . ($p['slug'] ?? '');
        return [
            'id'     => (int) $p['id'],
            'title'  => (string) $p['title'],
            'slug'   => (string) $p['slug'],
            'path'   => $path,
            'status' => (string) $p['status'],
        ];
    }

    // ----------------------------------------------------------------------
    // Formulario de creación
    // ----------------------------------------------------------------------
    public function create(array $params = []): void
    {
        self::requireSiteId();
        $this->renderForm([
            'mode'   => 'create',
            'page'   => self::defaults(),
            'errors' => [],
        ]);
    }

    // ----------------------------------------------------------------------
    // AI Page Studio (Fase 14)
    // ----------------------------------------------------------------------
    public function studio(array $params = []): void
    {
        $siteId = self::requireSiteId();
        self::ensureHierarchySchema();

        $data = DashboardController::getCommonData();
        $data['csrf'] = CSRF::token();
        $data['pageTypes'] = self::PAGE_TYPES;
        $data['aiMeta'] = AIProviderFactory::currentMeta($siteId);
        $data['pages'] = self::loadExistingPages($siteId);
        $data['visualStyleCards'] = VisualStyleService::cardsForSite($siteId);
        $data['selectedVisualStyle'] = VisualStyleService::selectedForSite($siteId);
        $templates = PageTemplateService::all();
        $data['templateCards'] = array_map(function (string $slug, array $tpl): array {
            return [
                'slug' => $slug,
                'label' => (string) ($tpl['label'] ?? $slug),
                'description' => (string) ($tpl['description'] ?? ''),
                'page_type' => (string) ($tpl['page_type'] ?? 'landing'),
                'sections' => count($tpl['sections'] ?? []),
                'preview_url' => base_url('admin/pages/ai/templates/' . $slug . '/preview'),
            ];
        }, array_keys($templates), array_values($templates));

        View::send('admin/pages/studio', $data);
    }

    // POST /admin/pages/ai-opportunities
    public function aiOpportunities(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $notes = trim((string) Request::post('notes', ''));
        $force = (string) Request::post('force', '') === '1';

        $fingerprint = self::opportunityFingerprint($siteId, $notes);
        if (!$force) {
            $cached = self::cachedOpportunities($siteId, $fingerprint);
            if ($cached !== null) {
                $meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
                Response::json([
                    'ok' => true,
                    'cached' => true,
                    'cached_at' => $cached['cached_at'] ?? null,
                    'data' => $cached['data'] ?? self::fallbackOpportunities($siteId),
                    'model' => $meta['model'] ?? '',
                    'tokens_in' => $meta['tokens_in'] ?? 0,
                    'tokens_out' => $meta['tokens_out'] ?? 0,
                    'estimated_cost' => $meta['estimated_cost'] ?? 0,
                ]);
            }
        }

        try {
            $result = AIActionRunner::run(Actions::DISCOVER_PAGE_OPPORTUNITIES, [
                'existing_pages' => self::existingPagesPrompt($siteId),
                'extra_context' => $notes,
            ], $siteId);

            $data = self::normalizeOpportunities((array) ($result['data'] ?? []), $siteId);
            self::storeCachedOpportunities($siteId, $fingerprint, $data, $result);
            Response::json([
                'ok' => true,
                'cached' => false,
                'cached_at' => date('c'),
                'data' => $data,
                'provider' => $result['provider'] ?? '',
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
                'latency_ms' => $result['latency_ms'] ?? 0,
                'warnings' => $result['warnings'] ?? [],
            ]);
        } catch (AIException $e) {
            Response::json([
                'ok' => true,
                'fallback' => true,
                'cached' => false,
                'data' => self::fallbackOpportunities($siteId),
                'error_note' => $e->getMessage(),
            ]);
        }
    }

    // POST /admin/pages/ai-brief
    public function aiBrief(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $idea = trim((string) Request::post('page_idea', ''));
        $notes = trim((string) Request::post('notes', ''));

        if ($idea === '') {
            Response::json(['ok' => false, 'error' => 'Describe qué página quieres crear o elige una oportunidad.'], 422);
        }

        try {
            $result = AIActionRunner::run(Actions::GENERATE_PAGE_BRIEF, [
                'page_idea' => $idea,
                'existing_pages' => self::existingPagesPrompt($siteId),
                'extra_context' => $notes,
            ], $siteId);

            Response::json([
                'ok' => true,
                'data' => self::normalizeBrief((array) ($result['data'] ?? [])),
                'provider' => $result['provider'] ?? '',
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
                'latency_ms' => $result['latency_ms'] ?? 0,
                'warnings' => $result['warnings'] ?? [],
            ]);
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        }
    }

    // POST /admin/pages/architecture/analyze
    public function architectureAnalyze(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        self::ensureHierarchySchema();
        self::inferInitialHierarchy($siteId);

        $force = (string) Request::post('force', '') === '1';
        $fingerprint = self::architectureFingerprint($siteId);
        if (!$force) {
            $cached = self::cachedArchitecture($siteId, $fingerprint);
            if ($cached !== null) {
                $meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
                Response::json([
                    'ok' => true,
                    'cached' => true,
                    'cached_at' => $cached['cached_at'] ?? null,
                    'architecture' => $cached['architecture'] ?? self::fallbackArchitecture($siteId),
                    'warnings' => [],
                    'model' => $meta['model'] ?? '',
                    'tokens_in' => $meta['tokens_in'] ?? 0,
                    'tokens_out' => $meta['tokens_out'] ?? 0,
                    'estimated_cost' => $meta['estimated_cost'] ?? 0,
                ]);
            }
        }

        try {
            $result = AIActionRunner::run(Actions::ANALYZE_SITE_ARCHITECTURE, [
                'site_map_context' => self::siteMapContext($siteId),
            ], $siteId);
            $architecture = self::normalizeArchitecture((array) ($result['data'] ?? []), $siteId);
            self::storeCachedArchitecture($siteId, $fingerprint, $architecture, $result);

            Response::json([
                'ok' => true,
                'cached' => false,
                'cached_at' => date('c'),
                'architecture' => $architecture,
                'warnings' => $result['warnings'] ?? [],
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
            ]);
        } catch (AIException $e) {
            Response::json([
                'ok' => true,
                'cached' => false,
                'fallback' => true,
                'architecture' => self::fallbackArchitecture($siteId),
                'warnings' => [$e->getMessage()],
            ]);
        }
    }

    // POST /admin/pages/{id}/structure
    public function updateStructure(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        self::ensureHierarchySchema();

        $id = (int) ($params['id'] ?? 0);
        $page = self::findOrFail($id, $siteId);
        $parentRaw = trim((string) Request::post('parent_id', ''));
        $parentId = $parentRaw === '' || $parentRaw === '0' ? null : (int) $parentRaw;
        $navLabel = trim((string) Request::post('nav_label', ''));
        $treeOrder = max(0, (int) Request::post('tree_sort_order', 0));

        if ($parentId !== null) {
            if ($parentId === $id || !self::pageBelongsToSite($parentId, $siteId) || self::wouldCreateCycle($id, $parentId)) {
                Response::json(['ok' => false, 'error' => 'La jerarquía seleccionada no es válida.'], 422);
            }
        }

        Database::execute(
            'UPDATE pages SET parent_id = ?, nav_label = ?, tree_sort_order = ?, updated_at = ? WHERE id = ? AND site_id = ?',
            [$parentId, $navLabel !== '' ? $navLabel : null, $treeOrder, date('Y-m-d H:i:s'), $id, $siteId]
        );
        CacheService::invalidatePage($siteId, $page);

        Response::json(['ok' => true, 'message' => 'Estructura actualizada.']);
    }

    // ----------------------------------------------------------------------
    // Store (POST)
    // ----------------------------------------------------------------------
    public function store(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $input  = self::collectInput();
        $errors = self::validate($input, $siteId, null);

        if (!empty($errors)) {
            $this->renderForm([
                'mode'   => 'create',
                'page'   => $input,
                'errors' => $errors,
            ]);
        }

        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO pages
                (site_id, title, slug, page_type, meta_title, meta_description,
                 seo_noindex, seo_exclude_sitemap, canonical_url,
                 status, sort_order, created_by, created_at, updated_at, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $input['title'],
                $input['slug'],
                $input['page_type'],
                $input['meta_title'] ?: null,
                $input['meta_description'] ?: null,
                (int) $input['seo_noindex'],
                (int) $input['seo_exclude_sitemap'],
                $input['canonical_url'] ?: null,
                $input['status'],
                0,
                Auth::id(),
                $now, $now,
                $input['status'] === 'published' ? $now : null,
            ]
        );

        Session::flash('success', 'Página creada correctamente.');
        Response::redirect(base_url('admin/pages'));
    }

    // ----------------------------------------------------------------------
    // Crear página completa con IA (T10.4)
    // POST /admin/pages/ai-create
    // ----------------------------------------------------------------------
    public function aiCreate(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $title = trim((string) Request::post('title', ''));
        $pageType = (string) Request::post('page_type', 'landing');
        $goal = trim((string) Request::post('ai_page_goal', ''));
        $audience = trim((string) Request::post('ai_target_audience', ''));
        $details = trim((string) Request::post('ai_extra_context', ''));
        $brief = self::decodePostedBrief((string) Request::post('ai_brief_json', ''));
        $parentId = (int) Request::post('parent_id', 0);
        $architectureContext = trim((string) Request::post('architecture_context', ''));

        if ($brief !== []) {
            $title = trim((string) ($brief['title'] ?? $title));
            $pageType = (string) ($brief['page_type'] ?? $pageType);
            $goal = trim((string) ($brief['goal'] ?? $goal));
            $audience = trim((string) ($brief['audience'] ?? $audience));
            $briefContext = self::briefToContext($brief);
            $details = trim($details . ($details !== '' ? "\n\n" : '') . $briefContext);
        }
        if ($architectureContext !== '') {
            $details = trim($details . ($details !== '' ? "\n\n" : '') . "Contexto de arquitectura:\n" . $architectureContext);
        }

        if ($title === '') {
            Response::json(['ok' => false, 'error' => 'Añade un título para la página.'], 422);
        }
        if (!isset(self::PAGE_TYPES[$pageType])) {
            Response::json(['ok' => false, 'error' => 'Tipo de página inválido.'], 422);
        }
        if ($goal === '') {
            Response::json(['ok' => false, 'error' => 'Describe el objetivo de la página.'], 422);
        }
        if ($parentId > 0 && !self::pageBelongsToSite($parentId, $siteId)) {
            Response::json(['ok' => false, 'error' => 'La página padre no pertenece a este sitio.'], 422);
        }

        $extraContext = trim(
            ($audience !== '' ? "Público objetivo específico: {$audience}\n" : '')
          . ($details !== '' ? "Detalles adicionales: {$details}" : '')
        );

        try {
            $aiUsage = self::emptyAiUsage();

            $structure = self::structureFromBrief($brief);
            if ($structure === []) {
                $structureResult = AIActionRunner::run(Actions::GENERATE_PAGE_STRUCTURE, [
                    'page_title' => $title,
                    'page_goal' => $goal,
                    'extra_context' => $extraContext,
                ], $siteId);
                self::addAiUsage($aiUsage, $structureResult);
                $structure = $structureResult['data']['sections'] ?? [];
            }
            if (!is_array($structure) || $structure === []) {
                Response::json(['ok' => false, 'error' => 'La IA no propuso secciones válidas.'], 422);
            }

            $generatedSections = [];
            foreach (array_slice($structure, 0, 7) as $index => $item) {
                if (!is_array($item)) continue;
                $type = (string) ($item['type'] ?? '');
                if (!isset(SectionController::SECTION_TYPES[$type])) continue;
                $variant = SectionSchemas::normalizeVariant($type, (string) ($item['variant'] ?? 'default'));
                $variantHint = self::variantContentHint($type, $variant);

                $sectionContext = trim(
                    "Objetivo de la página: {$goal}\n"
                  . ($audience !== '' ? "Público objetivo: {$audience}\n" : '')
                  . "Rol de esta sección: " . (string) ($item['rationale'] ?? '') . "\n"
                  . "Variante visual elegida: {$variant}" . ($variantHint !== '' ? " — {$variantHint}" : '') . "\n"
                  . "Posición: " . ($index + 1) . " de " . count($structure) . "\n"
                  . $details
                );

                $sectionResult = AIActionRunner::run(Actions::GENERATE_SECTION, [
                    'section_type' => $type,
                    'page_title' => $title,
                    'extra_context' => $sectionContext,
                ], $siteId);
                self::addAiUsage($aiUsage, $sectionResult);

                $generatedSections[] = [
                    'type' => $type,
                    'variant' => $variant,
                    'content' => self::filterSectionContent($type, (array) ($sectionResult['data'] ?? [])),
                ];
            }
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        }

        if ($generatedSections === []) {
            Response::json(['ok' => false, 'error' => 'No se pudo generar contenido de secciones.'], 422);
        }

        // D-Slice 2 — LayoutSelector reemplaza la variante propuesta por la IA
        // con la elección determinista basada en el vector del sitio.
        // Si falla, mantenemos la variante de la IA como fallback.
        try {
            $picks = \App\Services\Personality\LayoutSelector::selectForPage($siteId, $generatedSections, $pageType);
            foreach ($generatedSections as $i => &$gs) {
                if (isset($picks[$i]['variant'])) {
                    $gs['variant'] = $picks[$i]['variant'];
                }
            }
            unset($gs);
        } catch (\Throwable $e) {
            error_log('LayoutSelector aiCreate failed: ' . $e->getMessage());
        }

        $pageContent = self::textFromGeneratedSections($generatedSections);
        $seo = ['seo_title' => '', 'meta_description' => '', 'slug' => slugify($title)];
        try {
            $seoResult = AIActionRunner::run(Actions::IMPROVE_SEO, [
                'page_title' => $title,
                'page_type' => self::PAGE_TYPES[$pageType],
                'current_slug' => slugify($title),
                'current_meta_title' => '',
                'current_meta_description' => '',
                'page_content' => $pageContent,
            ], $siteId);
            if (is_array($seoResult['data'] ?? null)) {
                $seo = array_merge($seo, $seoResult['data']);
            }
            self::addAiUsage($aiUsage, $seoResult);
        } catch (AIException) {
            // SEO es útil, pero no debe bloquear la creación de una página ya generada.
        }

        $slug = self::uniqueSlug($siteId, (string) ($seo['slug'] ?? slugify($title)));
        $now = date('Y-m-d H:i:s');

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            Database::execute(
                'INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId,
                    $title,
                    $slug,
                    $pageType,
                    $parentId > 0 ? $parentId : null,
                    null,
                    trim((string) ($seo['seo_title'] ?? '')) ?: null,
                    trim((string) ($seo['meta_description'] ?? '')) ?: null,
                    'draft',
                    0,
                    self::nextTreeOrder($siteId, $parentId > 0 ? $parentId : null),
                    Auth::id(),
                    $now, $now, null,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            foreach ($generatedSections as $pos => $section) {
                $variant = SectionSchemas::normalizeVariant((string) $section['type'], (string) ($section['variant'] ?? 'default'));
                Database::execute(
                    'INSERT INTO page_sections
                        (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $pageId,
                        $section['type'],
                        $pos,
                        json_encode($section['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $variant !== 'default' ? json_encode(['variant' => $variant], JSON_UNESCAPED_UNICODE) : null,
                        'editable',
                        $now,
                        $now,
                    ]
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok' => false, 'error' => 'Error guardando la página: ' . $e->getMessage()], 500);
        }

        // D-Slice 2 — Persistir preferencias inter-página para futuras páginas
        // del mismo page_type (first-write wins).
        try {
            \App\Services\Personality\LayoutSelector::rememberPage($siteId, $pageType, array_map(
                static fn ($s) => ['type' => $s['type'], 'variant' => $s['variant']],
                $generatedSections
            ));
        } catch (\Throwable $e) {
            error_log('LayoutSelector rememberPage aiCreate failed: ' . $e->getMessage());
        }

        Session::flash('success', 'Página generada con IA. Revísala antes de publicar.');
        Response::json([
            'ok' => true,
            'page_id' => $pageId,
            'edit_url' => base_url('admin/pages/' . $pageId . '/edit'),
            'sections_count' => count($generatedSections),
            'ai_usage' => $aiUsage ?? self::emptyAiUsage(),
        ]);
    }

    // ----------------------------------------------------------------------
    // D-MB — Crear página desde una REFERENCIA visual (captura) con IA de visión.
    // POST /admin/pages/ai-from-reference   (multipart: references[] + title + goal)
    // ----------------------------------------------------------------------
    public function aiCreateFromReference(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $title    = trim((string) Request::post('title', ''));
        $pageType = (string) Request::post('page_type', 'landing');
        $goal     = trim((string) Request::post('ai_page_goal', ''));
        $audience = trim((string) Request::post('ai_target_audience', ''));
        $details  = trim((string) Request::post('ai_extra_context', ''));

        if ($title === '') {
            Response::json(['ok' => false, 'error' => 'Añade un título para tu página.'], 422);
        }
        if (!isset(self::PAGE_TYPES[$pageType])) {
            $pageType = 'landing';
        }
        if ($goal === '') {
            Response::json(['ok' => false, 'error' => 'Describe el objetivo de tu página.'], 422);
        }

        // Procesar las imágenes de referencia subidas.
        [$images, $imgError] = self::readReferenceImages();
        if ($imgError !== null) {
            Response::json(['ok' => false, 'error' => $imgError], 422);
        }
        if ($images === []) {
            Response::json(['ok' => false, 'error' => 'Sube al menos una captura de referencia.'], 422);
        }

        $extraContext = trim(
            ($audience !== '' ? "Público objetivo específico: {$audience}\n" : '')
          . ($details !== '' ? "Detalles adicionales: {$details}" : '')
        );

        try {
            $aiUsage = self::emptyAiUsage();

            // DMB-F3.5 — Enfoque definitivo: la referencia no se mapea a un
            // catálogo cerrado de variantes. Cada bloque se escribe en
            // PromptPress-friendly HTML y se valida con CustomBlockSanitizer.
            $generatedSections = [];
            $blockPlan = self::referenceCustomBlockPlan($pageType);
            foreach ($blockPlan as $index => $block) {
                $sectionContext = trim(
                    "Objetivo de la página: {$goal}\n"
                  . ($audience !== '' ? "Público objetivo: {$audience}\n" : '')
                  . "Posición: " . ($index + 1) . " de " . count($blockPlan) . "\n"
                  . "Mantén coherencia entre bloques: ritmo, aire y criterio inspirados en la misma referencia.\n"
                  . $details
                );

                $blockResult = CustomBlockGenerator::generate($siteId, [
                    'page_title' => $title,
                    'block_goal' => (string) ($block['goal'] ?? $goal),
                    'section_role' => (string) ($block['role'] ?? ''),
                    'language' => 'es',
                    'available_images' => '',
                    'extra_context' => $sectionContext,
                    'is_first_section' => $index === 0,
                    '_images' => $images,
                ]);
                self::addAiUsage($aiUsage, $blockResult);

                $generatedSections[] = [
                    'type'    => 'custom_block',
                    'variant' => 'default',
                    'content' => (array) ($blockResult['content'] ?? []),
                ];
            }
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        }

        if ($generatedSections === []) {
            Response::json(['ok' => false, 'error' => 'No se pudo generar contenido para las secciones.'], 422);
        }

        // 3) SEO (no bloqueante).
        $pageContent = self::textFromGeneratedSections($generatedSections);
        $seo = ['seo_title' => '', 'meta_description' => '', 'slug' => slugify($title)];
        try {
            $seoResult = AIActionRunner::run(Actions::IMPROVE_SEO, [
                'page_title' => $title,
                'page_type' => self::PAGE_TYPES[$pageType],
                'current_slug' => slugify($title),
                'current_meta_title' => '',
                'current_meta_description' => '',
                'page_content' => $pageContent,
            ], $siteId);
            if (is_array($seoResult['data'] ?? null)) {
                $seo = array_merge($seo, $seoResult['data']);
            }
            self::addAiUsage($aiUsage, $seoResult);
        } catch (AIException) {}

        // 4) Persistir página + secciones.
        $slug = self::uniqueSlug($siteId, (string) ($seo['slug'] ?? slugify($title)));
        $now  = date('Y-m-d H:i:s');
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            Database::execute(
                'INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId, $title, $slug, $pageType, null, null,
                    trim((string) ($seo['seo_title'] ?? '')) ?: null,
                    trim((string) ($seo['meta_description'] ?? '')) ?: null,
                    'draft', 0, self::nextTreeOrder($siteId, null),
                    Auth::id(), $now, $now, null,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            foreach ($generatedSections as $pos => $section) {
                $variant = SectionSchemas::normalizeVariant((string) $section['type'], (string) ($section['variant'] ?? 'default'));
                Database::execute(
                    'INSERT INTO page_sections
                        (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $pageId, $section['type'], $pos,
                        json_encode($section['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $variant !== 'default' ? json_encode(['variant' => $variant], JSON_UNESCAPED_UNICODE) : null,
                        'editable', $now, $now,
                    ]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok' => false, 'error' => 'Error guardando la página: ' . $e->getMessage()], 500);
        }

        Session::flash('success', 'Página diseñada desde tu referencia. Revísala antes de publicar.');
        Response::json([
            'ok' => true,
            'page_id' => $pageId,
            'edit_url' => base_url('admin/pages/' . $pageId . '/edit'),
            'sections_count' => count($generatedSections),
            'ai_usage' => $aiUsage ?? self::emptyAiUsage(),
        ]);
    }

    /**
     * Lee y normaliza las imágenes de referencia subidas (`references[]`).
     * Valida tipo/tamaño/nº y reescala las muy grandes (máx. 1600px) para
     * controlar el coste de tokens. Devuelve [images, errorOrNull].
     *
     * @return array{0: array<int,array{mime:string,data:string}>, 1: ?string}
     */
    private static function readReferenceImages(): array
    {
        $files = $_FILES['references'] ?? null;
        if (!is_array($files) || !isset($files['tmp_name'])) {
            return [[], null];
        }
        // Normaliza a lista (input multiple → arrays paralelos).
        $names = (array) $files['tmp_name'];
        $errors = (array) ($files['error'] ?? []);
        $sizes  = (array) ($files['size'] ?? []);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $maxBytes = 8 * 1024 * 1024; // 8 MB por archivo
        $maxCount = 4;

        $out = [];
        $count = 0;
        foreach ($names as $i => $tmp) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($errors[$i] ?? 1) !== UPLOAD_ERR_OK) return [[], 'Error al subir una de las imágenes.'];
            if (++$count > $maxCount) return [[], 'Máximo ' . $maxCount . ' imágenes de referencia.'];
            if (($sizes[$i] ?? 0) > $maxBytes) return [[], 'Cada imagen debe pesar menos de 8 MB.'];
            if (!is_uploaded_file((string) $tmp)) return [[], 'Subida no válida.'];

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = (string) $finfo->file((string) $tmp);
            if (!isset($allowed[$mime])) return [[], 'Formato no soportado. Usa JPG, PNG o WebP.'];

            $raw = (string) file_get_contents((string) $tmp);
            $normalized = self::downscaleForVision($raw);
            if ($normalized === null) return [[], 'No se pudo procesar una de las imágenes.'];
            $out[] = $normalized;
        }
        return [$out, null];
    }

    /**
     * Plan mínimo para Studio "Desde una referencia" usando PP-friendly HTML.
     * Es deliberadamente corto: prueba el enfoque flexible sin crear todavía
     * el lenguaje de sitio completo que vivirá en onboarding.
     *
     * @return array<int,array{role:string,goal:string}>
     */
    private static function referenceCustomBlockPlan(string $pageType): array
    {
        $middle = match ($pageType) {
            'service' => [
                'role' => 'Bloque central de servicios, proceso o beneficios con 3 puntos claros.',
                'goal' => 'Explicar el valor del servicio con estructura clara, argumentos concretos y señales de confianza.',
            ],
            'product' => [
                'role' => 'Bloque central de producto con beneficios, prueba y detalles de decisión.',
                'goal' => 'Presentar el producto de forma comprensible y orientar la decisión sin inventar precios ni claims.',
            ],
            'contact' => [
                'role' => 'Bloque central de confianza y motivos para contactar.',
                'goal' => 'Reducir fricción antes del contacto y aclarar qué puede esperar el visitante.',
            ],
            default => [
                'role' => 'Bloque central de beneficios, método o argumentos principales.',
                'goal' => 'Desarrollar la propuesta de valor con puntos escaneables y una jerarquía visual clara.',
            ],
        };

        return [
            [
                'role' => 'Primer bloque/hero de la página, inspirado en la composición superior de la referencia.',
                'goal' => 'Captar atención, explicar la promesa principal y dirigir hacia la acción más importante.',
            ],
            $middle,
            [
                'role' => 'Bloque final de cierre o llamada a la acción, coherente con el aire de la referencia.',
                'goal' => 'Cerrar la página con una acción clara y una razón convincente para dar el siguiente paso.',
            ],
        ];
    }

    /**
     * Reescala una imagen si su lado mayor supera 1600px y la re-codifica
     * (JPEG q82). Devuelve {mime, data(base64)} o null si falla.
     *
     * @return array{mime:string,data:string}|null
     */
    private static function downscaleForVision(string $raw): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            // Sin GD: enviar tal cual (mejor que fallar).
            return ['mime' => 'image/png', 'data' => base64_encode($raw)];
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) return null;

        $w = imagesx($img); $h = imagesy($img);
        $max = 1600;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
            $resized = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }
        ob_start();
        imagejpeg($img, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return ['mime' => 'image/jpeg', 'data' => base64_encode($data)];
    }

    // ----------------------------------------------------------------------
    // T18.6 — Galería de plantillas (UI)
    // GET /admin/pages/ai/templates
    // ----------------------------------------------------------------------
    public function aiTemplatesGallery(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $templates = PageTemplateService::all();

        $cards = [];
        foreach ($templates as $slug => $tpl) {
            $cards[] = [
                'slug'        => $slug,
                'label'       => (string) ($tpl['label'] ?? $slug),
                'description' => (string) ($tpl['description'] ?? ''),
                'page_type'   => (string) ($tpl['page_type'] ?? 'landing'),
                'preview_url' => base_url('admin/pages/ai/templates/' . $slug . '/preview'),
                'sections'    => count($tpl['sections'] ?? []),
                'needs_bank'  => !empty($tpl['needs_images']),
            ];
        }

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'cards'         => $cards,
            'bankAvailable' => ImageBankService::isAvailable(),
            'csrf'          => CSRF::token(),
        ]);
        \Core\View::send('admin/pages/templates', $data);
    }

    // ----------------------------------------------------------------------
    // T18.6 — Preview HTML de una plantilla (con contenido placeholder)
    // GET /admin/pages/ai/templates/{slug}/preview
    // ----------------------------------------------------------------------
    public function aiTemplatePreview(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $slug = (string) ($params['slug'] ?? '');
        $tpl  = PageTemplateService::get($slug);
        if (!$tpl) {
            http_response_code(404);
            echo '<!doctype html><meta charset=utf-8><body style="font-family:system-ui;padding:24px">Plantilla no encontrada.</body>';
            return;
        }

        // Prioridad: query string > plantilla > sitio.
        $tplVisual = (string) ($tpl['visual_style'] ?? '');
        $defaultVisual = $tplVisual !== '' ? $tplVisual : VisualStyleService::selectedForSite($siteId);
        $styleSlug = VisualStyleService::normalizeSlug((string) Request::get('style', $defaultVisual));

        // Palette preset opcional: query string > plantilla > sitio (vía paletteForSite).
        $tplPalette = (string) ($tpl['palette_preset'] ?? '');
        $palettePreset = trim((string) Request::get('palette', $tplPalette));
        $palettePreset = $palettePreset !== '' && PalettePresets::get($palettePreset) ? $palettePreset : null;

        // ── Cierre Fase 19 (Tarea B) — Cache HTTP de previews ───────────────
        // ETag determinista: cualquier cambio en inputs invalida el cache del navegador.
        //   - site_id, slug, style, palette → identifican la combinación
        //   - tokens del design system del sitio → si cambian colores/tipografía, repinta
        //   - mtime de archivos clave → si actualizamos código, repinta sin caché
        $tokens = DesignSystem::load($siteId);
        $codeFiles = [
            __DIR__ . '/../../Services/Renderer/SectionRenderer.php',
            __DIR__ . '/../../Services/DesignSystem.php',
            __DIR__ . '/../../Services/VisualStyleService.php',
            __DIR__ . '/../../Services/PageTemplateService.php',
            __DIR__ . '/../../Services/PalettePresets.php',
            PP_ROOT . '/config/page_templates/' . $slug . '.json',
        ];
        $codeMtime = 0;
        foreach ($codeFiles as $f) {
            if (is_file($f)) {
                $m = @filemtime($f);
                if ($m && $m > $codeMtime) $codeMtime = $m;
            }
        }
        $etagPayload = [
            'v'        => 1,
            'site'     => $siteId,
            'slug'     => $slug,
            'style'    => $styleSlug,
            'palette'  => $palettePreset,
            'tokens'   => $tokens,
            'mtime'    => $codeMtime,
        ];
        $etag = '"' . sha1(json_encode($etagPayload, JSON_UNESCAPED_UNICODE)) . '"';

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        // Algunos proxies prefijan W/ (weak); aceptamos ambos.
        $clientEtag = preg_replace('/^W\//', '', $ifNoneMatch);
        if ($clientEtag !== '' && $clientEtag === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=600, must-revalidate');
            header('Vary: Cookie');
            return;
        }

        // Construimos secciones falsas con la misma estructura de page_sections, sin tocar BD.
        $fakeSections = [];
        foreach ($tpl['sections'] as $idx => $s) {
            $type = (string) ($s['type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$type])) continue;
            $variant = VisualStyleService::previewVariant($styleSlug, $type, $idx, $siteId);
            if ($variant === 'default') {
                $variant = (string) ($s['variant'] ?? 'default');
            }
            $content = PageTemplateService::placeholderContent($type, $slug . '-' . $idx);
            $fakeSections[] = [
                'id'           => $idx + 1,
                'section_type' => $type,
                'sort_order'   => $idx,
                'content_json' => $content,
                'style_json'   => $variant !== 'default' ? ['variant' => $variant] : null,
            ];
        }

        $designHead = DesignSystem::renderHead($siteId, $styleSlug, $palettePreset);
        SectionRenderer::setSiteContext($siteId);
        $body = SectionRenderer::renderMany($fakeSections);

        $html  = '<!doctype html><html lang="es"><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>Preview · ' . e((string) $tpl['label']) . '</title>';
        $html .= '<meta name="robots" content="noindex,nofollow">';
        $html .= $designHead;
        // Banderín "Vista previa" para que quede claro que es demo.
        $html .= '<style>body{padding-top:0}.pp-preview-flag{position:fixed;top:12px;right:12px;z-index:9999;background:#0f172a;color:#fff;font:600 11px/1 system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;padding:6px 10px;border-radius:999px;opacity:.85}</style>';
        $html .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $html .= '<div class="pp-preview-flag">Vista previa · contenido de muestra</div>';
        $html .= BrandService::publicHeader($siteId);
        $html .= $body;
        $html .= '</body></html>';

        // Cierre Fase 19 (Tarea B) — caché HTTP del navegador.
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=600, must-revalidate');
        header('Vary: Cookie');
        Response::html($html);
    }

    // ----------------------------------------------------------------------
    // T18.6 — Generación inicial desde plantilla
    // POST /admin/pages/ai-create-from-template
    // body: template_slug, title, goal, audience?, details?, parent_id?
    // ----------------------------------------------------------------------
    public function aiCreateFromTemplate(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        // Plantilla seleccionada (necesaria desde el inicio para poder leer
        // su `visual_style` / `palette_preset` por defecto).
        $tplSlug = trim((string) Request::post('template_slug', ''));
        $tplDeclared = $tplSlug !== '' ? PageTemplateService::get($tplSlug) : null;

        $visualStyle = trim((string) Request::post('visual_style', ''));
        if ($visualStyle === '' && $tplDeclared && !empty($tplDeclared['visual_style'])) {
            $visualStyle = (string) $tplDeclared['visual_style'];
        }
        if ($visualStyle !== '') {
            VisualStyleService::saveSelectedForSite($siteId, $visualStyle);
            CacheService::flush($siteId);
        }

        // Persistir paleta declarada por la plantilla (si la hay) salvo override.
        $palettePreset = trim((string) Request::post('palette_preset', ''));
        if ($palettePreset === '' && $tplDeclared && !empty($tplDeclared['palette_preset'])) {
            $palettePreset = (string) $tplDeclared['palette_preset'];
        }
        if ($palettePreset !== '' && PalettePresets::get($palettePreset)) {
            PalettePresets::saveSelectedForSite($siteId, $palettePreset);
            CacheService::flush($siteId);
        }

        try {
            $result = self::generatePageFromTemplate(
                $siteId,
                Auth::id(),
                trim((string) Request::post('template_slug', '')),
                trim((string) Request::post('title', '')),
                trim((string) Request::post('goal', '')),
                trim((string) Request::post('audience', '')),
                trim((string) Request::post('details', '')),
                (int) Request::post('parent_id', 0)
            );
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Error guardando la página: ' . $e->getMessage()], 500);
        }

        Session::flash('success', 'Página creada desde plantilla "' . ($result['template_label'] ?? '') . '". Revísala antes de publicar.');
        Response::json([
            'ok'              => true,
            'page_id'         => $result['page_id'],
            'edit_url'        => $result['edit_url'],
            'sections_count'  => $result['sections_count'],
            'images_applied'  => $result['images_applied'],
            'ai_usage'        => $result['ai_usage'],
        ]);
    }

    /**
     * T18.6 / T18.8 — Núcleo reutilizable: genera una página a partir de una
     * plantilla del catálogo, llamando a la IA por sección y descargando
     * imágenes del banco si aplica.
     *
     * Lanza:
     *   - \InvalidArgumentException si el input no es válido (slug, título…).
     *   - AIException si el LLM falla.
     *   - \RuntimeException si la persistencia falla.
     *
     * @return array{
     *   page_id:int, edit_url:string, sections_count:int, images_applied:int,
     *   ai_usage:array, template_label:string
     * }
     */
    public static function generatePageFromTemplate(
        int $siteId,
        ?int $userId,
        string $slug,
        string $title,
        string $goal,
        string $audience = '',
        string $details = '',
        int $parentId = 0
    ): array {
        $template = $slug !== '' ? PageTemplateService::get($slug) : null;
        if (!$template) {
            throw new \InvalidArgumentException('Plantilla no encontrada.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('Añade un título para la página.');
        }
        if ($goal === '') {
            throw new \InvalidArgumentException('Describe el objetivo de la página.');
        }
        if ($parentId > 0 && !self::pageBelongsToSite($parentId, $siteId)) {
            throw new \InvalidArgumentException('La página padre no pertenece a este sitio.');
        }

        $pageType = (string) ($template['page_type'] ?? 'landing');
        if (!isset(self::PAGE_TYPES[$pageType])) {
            $pageType = 'landing';
        }

        $sectionsTpl = is_array($template['sections'] ?? null) ? $template['sections'] : [];
        if (empty($sectionsTpl)) {
            throw new \InvalidArgumentException('La plantilla no define secciones.');
        }

        $bankAvailable = ImageBankService::isAvailable();
        $aiUsage = self::emptyAiUsage();
        $generated = [];
        $imagesApplied = 0;
        $visualStyleSlug = VisualStyleService::selectedForSite($siteId);
        $visualStyle = VisualStyleService::get($visualStyleSlug);
        $visualStyleHint = $visualStyle
            ? 'Dirección visual del sitio: ' . (string) ($visualStyle['label'] ?? $visualStyleSlug) . '. ' . (string) ($visualStyle['description'] ?? '')
            : '';

        // El bucle puede lanzar AIException — la dejamos propagar al caller.
        foreach ($sectionsTpl as $index => $tplSection) {
            if (!is_array($tplSection)) continue;
            $type = (string) ($tplSection['type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$type])) continue;
            $variant = SectionSchemas::normalizeVariant($type, (string) ($tplSection['variant'] ?? 'default'));
            $imageQuery = trim((string) ($tplSection['image_query'] ?? ''));

            $variantHint = self::variantContentHint($type, $variant);
            $sectionContext = trim(
                "Objetivo de la página: {$goal}\n"
              . ($audience !== '' ? "Público objetivo: {$audience}\n" : '')
              . "Posición: " . ($index + 1) . " de " . count($sectionsTpl) . "\n"
              . "Variante visual elegida: {$variant}" . ($variantHint !== '' ? " — {$variantHint}" : '') . "\n"
              . ($visualStyleHint !== '' ? "{$visualStyleHint}\n" : '')
              . ($details !== '' ? "Detalles adicionales:\n{$details}" : '')
            );

            $sectionResult = AIActionRunner::run(Actions::GENERATE_SECTION, [
                'section_type'  => $type,
                'page_title'    => $title,
                'extra_context' => $sectionContext,
            ], $siteId);
            self::addAiUsage($aiUsage, $sectionResult);

            $content = self::filterSectionContent($type, (array) ($sectionResult['data'] ?? []));

            // Hook de imágenes — solo si hay banco activo Y la plantilla declara query.
            if ($bankAvailable && $imageQuery !== '') {
                $applied = self::applyImagesToSection($content, $type, $variant, $imageQuery, $audience, $siteId, $userId);
                if ($applied > 0) $imagesApplied += $applied;
            }

            $generated[] = [
                'type'    => $type,
                'variant' => $variant,
                'content' => $content,
            ];
        }

        if (empty($generated)) {
            throw new \RuntimeException('No se pudo generar ninguna sección a partir de la plantilla.');
        }

        // D-Slice 2 — LayoutSelector reemplaza la variante de la plantilla
        // por la elegida según el vector del sitio. Fallback: variante original.
        try {
            $picks = \App\Services\Personality\LayoutSelector::selectForPage($siteId, $generated, $pageType);
            foreach ($generated as $i => &$g) {
                if (isset($picks[$i]['variant'])) {
                    $g['variant'] = $picks[$i]['variant'];
                }
            }
            unset($g);
        } catch (\Throwable $e) {
            error_log('LayoutSelector generatePageFromTemplate failed: ' . $e->getMessage());
        }

        // SEO best-effort.
        $pageContent = self::textFromGeneratedSections($generated);
        $seo = ['seo_title' => '', 'meta_description' => '', 'slug' => slugify($title)];
        try {
            $seoResult = AIActionRunner::run(Actions::IMPROVE_SEO, [
                'page_title'              => $title,
                'page_type'               => self::PAGE_TYPES[$pageType],
                'current_slug'            => slugify($title),
                'current_meta_title'      => '',
                'current_meta_description' => '',
                'page_content'            => $pageContent,
            ], $siteId);
            if (is_array($seoResult['data'] ?? null)) {
                $seo = array_merge($seo, $seoResult['data']);
            }
            self::addAiUsage($aiUsage, $seoResult);
        } catch (AIException) {
            // SEO falla → no bloquea creación.
        }

        $finalSlug = self::uniqueSlug($siteId, (string) ($seo['slug'] ?? slugify($title)));
        $now = date('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                'INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId, $title, $finalSlug, $pageType,
                    $parentId > 0 ? $parentId : null, null,
                    trim((string) ($seo['seo_title'] ?? '')) ?: null,
                    trim((string) ($seo['meta_description'] ?? '')) ?: null,
                    'draft', 0,
                    self::nextTreeOrder($siteId, $parentId > 0 ? $parentId : null),
                    $userId, $now, $now, null,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            foreach ($generated as $pos => $sec) {
                $styleJson = $sec['variant'] !== 'default'
                    ? json_encode(['variant' => $sec['variant']], JSON_UNESCAPED_UNICODE)
                    : null;
                Database::execute(
                    'INSERT INTO page_sections
                        (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $pageId, $sec['type'], $pos,
                        json_encode($sec['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $styleJson, 'editable', $now, $now,
                    ]
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new \RuntimeException('Error guardando la página: ' . $e->getMessage(), 0, $e);
        }

        // D-Slice 2 — Recordar preferencias de layout para el siguiente page_type.
        try {
            \App\Services\Personality\LayoutSelector::rememberPage($siteId, $pageType, array_map(
                static fn ($s) => ['type' => $s['type'], 'variant' => $s['variant']],
                $generated
            ));
        } catch (\Throwable $e) {
            error_log('LayoutSelector rememberPage generatePageFromTemplate failed: ' . $e->getMessage());
        }

        return [
            'page_id'         => $pageId,
            'edit_url'        => base_url('admin/pages/' . $pageId . '/edit'),
            'sections_count'  => count($generated),
            'images_applied'  => $imagesApplied,
            'ai_usage'        => $aiUsage,
            'template_label'  => (string) ($template['label'] ?? $slug),
        ];
    }

    // ----------------------------------------------------------------------
    // T18.7 — Variaciones IA de layout para una página existente
    // POST /admin/pages/{id}/ai-variations
    // ----------------------------------------------------------------------
    public function aiVariations(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findOrFail($pageId, $siteId);

        $sections = Database::select(
            'SELECT id, section_type, sort_order, style
             FROM page_sections
             WHERE page_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$pageId]
        );
        if (empty($sections)) {
            Response::json(['ok' => false, 'error' => 'Esta página no tiene secciones para variar.'], 422);
        }

        $layoutData = [];
        foreach ($sections as $s) {
            $type = (string) ($s['section_type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$type])) continue;
            $layoutData[] = [
                'id' => (int) ($s['id'] ?? 0),
                'type' => $type,
                'variant' => self::variantFromStyle((string) ($s['style'] ?? ''), $type),
            ];
        }
        if (empty($layoutData)) {
            Response::json(['ok' => false, 'error' => 'No hay secciones válidas para proponer variaciones.'], 422);
        }

        $layoutLines = [];
        foreach ($layoutData as $idx => $row) {
            $layoutLines[] = ($idx + 1) . '. ' . $row['type'] . ' (variant=' . $row['variant'] . ')';
        }

        try {
            $result = AIActionRunner::run(Actions::PROPOSE_LAYOUT_VARIATIONS, [
                'page_title' => (string) ($page['title'] ?? 'Página'),
                'page_goal' => trim((string) Request::post('goal', '')),
                'sections_layout' => implode("\n", $layoutLines),
                'sections_layout_data' => $layoutData,
                'extra_context' => trim((string) Request::post('extra_context', '')),
            ], $siteId);
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        }

        $rawVariations = (array) (($result['data']['variations'] ?? null));
        $variations = [];
        foreach (array_slice($rawVariations, 0, 3) as $i => $raw) {
            $normalized = self::normalizeVariation($raw, $layoutData, $i + 1, $siteId);
            if ($normalized !== null) $variations[] = $normalized;
        }
        if (empty($variations)) {
            Response::json(['ok' => false, 'error' => 'La IA no devolvió variaciones aplicables.'], 422);
        }

        Response::json([
            'ok' => true,
            'variations' => $variations,
            'provider' => $result['provider'] ?? '',
            'model' => $result['model'] ?? '',
            'tokens_in' => $result['tokens_in'] ?? 0,
            'tokens_out' => $result['tokens_out'] ?? 0,
            'estimated_cost' => $result['estimated_cost'] ?? 0,
            'warnings' => $result['warnings'] ?? [],
        ]);
    }

    // ----------------------------------------------------------------------
    // T18.7 — Aplicar una variación de layout existente
    // POST /admin/pages/{id}/ai-variations/apply
    // ----------------------------------------------------------------------
    public function applyVariation(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        self::findOrFail($pageId, $siteId);

        $raw = trim((string) Request::post('variation_json', ''));
        $variation = json_decode($raw, true);
        if (!is_array($variation)) {
            Response::json(['ok' => false, 'error' => 'Variación inválida.'], 422);
        }

        $sections = Database::select(
            'SELECT id, section_type, sort_order, style
             FROM page_sections
             WHERE page_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$pageId]
        );
        if (empty($sections)) {
            Response::json(['ok' => false, 'error' => 'La página no tiene secciones para aplicar variación.'], 422);
        }

        $baseLayout = [];
        foreach ($sections as $s) {
            $type = (string) ($s['section_type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$type])) continue;
            $baseLayout[] = [
                'id' => (int) ($s['id'] ?? 0),
                'type' => $type,
                'variant' => self::variantFromStyle((string) ($s['style'] ?? ''), $type),
            ];
        }
        if (empty($baseLayout)) {
            Response::json(['ok' => false, 'error' => 'No hay layout base aplicable.'], 422);
        }

        $normalized = self::normalizeVariation($variation, $baseLayout, 1, $siteId);
        if ($normalized === null) {
            Response::json(['ok' => false, 'error' => 'La variación no es compatible con el layout actual.'], 422);
        }

        $orderIds = [];
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            foreach ($normalized['sections'] as $idx => $sec) {
                $sectionId = (int) ($sec['id'] ?? 0);
                $type = (string) ($sec['type'] ?? '');
                $variant = SectionSchemas::normalizeVariant($type, (string) ($sec['variant'] ?? 'default'));
                $styleJson = self::styleJsonWithVariant($sectionId, $variant);
                Database::execute(
                    'UPDATE page_sections SET sort_order = ?, style = ?, updated_at = ? WHERE id = ? AND page_id = ?',
                    [$idx, $styleJson, date('Y-m-d H:i:s'), $sectionId, $pageId]
                );
                $orderIds[] = $sectionId;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok' => false, 'error' => 'No se pudo aplicar la variación: ' . $e->getMessage()], 500);
        }

        Response::json([
            'ok' => true,
            'applied' => [
                'label' => (string) ($normalized['label'] ?? 'Variación aplicada'),
                'sections' => $normalized['sections'],
                'order_ids' => $orderIds,
            ],
        ]);
    }

    /**
     * T18.6 — Hint de longitud/tono según la variante visual elegida. Se pasa
     * a la IA dentro de `extra_context` para que el contenido encaje con el
     * layout sin necesidad de instrucciones por cada combinación type+variant.
     */
    private static function variantContentHint(string $type, string $variant): string
    {
        return match (true) {
            $type === 'hero' && $variant === 'with-image-bg'        => 'heading muy corto (≤6 palabras), subheading 1 frase. El texto va sobre imagen, debe ser legible y directo.',
            $type === 'hero' && $variant === 'split'                => 'heading 1 línea, subheading 1-2 frases. Prefiere "eyebrow" si encaja.',
            $type === 'hero' && $variant === 'poster-stack'         => 'heading muy corto y contundente (3-7 palabras), estilo editorial/campaña. Usa eyebrow si aporta contexto.',
            $type === 'hero' && $variant === 'statement-left'       => 'heading claro, elegante y poco genérico; subheading con promesa concreta en 1-2 frases.',
            $type === 'hero' && $variant === 'metric-led'           => 'incluye una promesa medible o resultado concreto en heading/subheading sin inventar datos falsos.',
            $type === 'benefits' && $variant === 'numbered'         => 'items con descripciones de 1 frase corta. NO uses iconos (`icon`: ""), el número sustituye al icono.',
            $type === 'benefits' && $variant === 'cards-icon-top'   => 'items con descripciones de 1-2 frases. Iconos relevantes (rocket, shield, chart…).',
            $type === 'benefits' && in_array($variant, ['manifesto', 'proof-strip'], true) => '4-6 items muy directos. NO uses iconos (`icon`: ""), el layout funciona como manifiesto/prueba.',
            $type === 'benefits' && $variant === 'offset-grid'      => '4-5 items, uno puede ser más importante. Usa títulos con carácter, sin frases genéricas.',
            $type === 'cta' && $variant === 'card'                  => 'heading corto e impactante (≤8 palabras).',
            $type === 'cta' && $variant === 'split'                 => 'heading directo, descripción 1 frase.',
            $type === 'cta' && $variant === 'poster-close'          => 'heading muy corto (≤6 palabras), con cierre de campaña. Descripción breve.',
            $type === 'cta' && $variant === 'quiet-inline'          => 'heading sobrio y claro, descripción de 1 frase. Evita grandilocuencia.',
            $type === 'testimonials' && $variant === 'featured-quote' => 'devuelve solo 1 testimonio largo y memorable; deja la lista `items` con un único elemento.',
            $type === 'testimonials' && $variant === 'quote-wall'   => '3-5 testimonios más breves, variados y creíbles.',
            $type === 'stats' && $variant === 'inline-bar'          => '4 datos exactos. value: solo número, suffix: símbolo (%, +).',
            $type === 'stats' && $variant === 'scoreboard'          => '3-4 datos de impacto. Si no hay datos reales en contexto, usa métricas cualitativas prudentes.',
            $type === 'pricing' && $variant === 'comparison'        => '3 planes equivalentes. Marca uno con highlighted=1.',
            $type === 'pricing' && $variant === 'editorial-list'    => '2-4 ofertas claras como lista. Features cortas, una por línea.',
            $type === 'pricing' && $variant === 'split-value'       => '2-3 planes. Marca como highlighted=1 el plan con mejor encaje para el objetivo.',
            $type === 'faq' && $variant === 'two-columns'           => '6-8 preguntas para llenar las dos columnas equilibradamente.',
            $type === 'steps' && $variant === 'horizontal'          => '3-5 pasos breves (titulares ≤4 palabras, descripciones 1 frase).',
            $type === 'steps' && $variant === 'staggered-cards'     => '3 pasos con título breve y descripción de 1 frase. Cada paso debe sentirse distinto.',
            default                                                  => '',
        };
    }

    /**
     * T18.6 — Tras generar el contenido, intenta rellenar las imágenes que la
     * plantilla espera con fotos del banco. Modifica `$content` por referencia.
     *
     * @return int número de imágenes aplicadas
     */
    private static function applyImagesToSection(
        array &$content,
        string $type,
        string $variant,
        string $imageQuery,
        string $audience,
        int $siteId,
        ?int $userId
    ): int {
        // Construir query con keywords del contexto. Mantener simple: imagen + audiencia (1ª palabra).
        $extraTerm = '';
        if ($audience !== '') {
            $first = strtok($audience, ' ');
            if ($first !== false && mb_strlen($first) > 2) $extraTerm = ' ' . $first;
        }
        $query = trim($imageQuery . $extraTerm);
        $applied = 0;

        try {
            switch ($type) {
                case 'hero':
                    if ($variant === 'with-image-bg') {
                        $applied += self::fetchAndApply($content, 'background_image', $query, 'landscape', $siteId, $userId);
                    } elseif ($variant === 'split') {
                        $applied += self::fetchAndApply($content, 'image_url', $query, 'landscape', $siteId, $userId);
                    }
                    break;

                case 'text_image':
                    if (in_array($variant, ['default', 'wide-media', 'card'], true)) {
                        $applied += self::fetchAndApply($content, 'image_url', $query, 'landscape', $siteId, $userId);
                    }
                    break;

                case 'form':
                    if ($variant === 'with-side-image') {
                        $applied += self::fetchAndApply($content, 'image_url', $query, 'portrait', $siteId, $userId);
                    }
                    break;

                case 'gallery':
                    // Una búsqueda, varios items. Cogemos hasta tantos como pida la sección.
                    $items = is_array($content['items'] ?? null) ? $content['items'] : [];
                    if (empty($items)) break;
                    $results = ImageBankService::search($query, min(8, count($items) + 2), 'landscape');
                    if (empty($results)) break;
                    $i = 0;
                    foreach ($items as $idx => &$item) {
                        if (!is_array($item)) continue;
                        if (!isset($results[$i])) break;
                        try {
                            $row = ImageBankService::downloadToMedia($results[$i], $siteId, $userId, (string) ($item['caption'] ?? $item['alt'] ?? ''));
                            $item['image_url'] = '/' . ltrim((string) $row['path'], '/');
                            $applied++;
                        } catch (\Throwable $e) {
                            error_log('[aiCreateFromTemplate] gallery img error: ' . $e->getMessage());
                        }
                        $i++;
                    }
                    unset($item);
                    $content['items'] = $items;
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[aiCreateFromTemplate] applyImagesToSection error: ' . $e->getMessage());
        }

        return $applied;
    }

    /**
     * Busca una foto del banco y, si hay match, descárgala y rellena el campo
     * indicado del contenido. Devuelve 1 si aplicó, 0 si no.
     */
    private static function fetchAndApply(array &$content, string $field, string $query, string $orientation, int $siteId, ?int $userId): int
    {
        // Si el campo ya viene relleno con una URL real (raro porque GENERATE_SECTION debe vaciarlo), respetarla.
        if (!empty($content[$field]) && is_string($content[$field]) && trim($content[$field]) !== '') {
            return 0;
        }
        $results = ImageBankService::search($query, 6, $orientation);
        if (empty($results)) return 0;
        try {
            $row = ImageBankService::downloadToMedia($results[0], $siteId, $userId, (string) ($content['heading'] ?? ''));
            $content[$field] = '/' . ltrim((string) $row['path'], '/');
            return 1;
        } catch (\Throwable $e) {
            error_log('[fetchAndApply] error: ' . $e->getMessage());
            return 0;
        }
    }

    // ----------------------------------------------------------------------
    // Formulario de edición
    // ----------------------------------------------------------------------
    public function edit(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page   = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        // F21.T21.2 — Las entradas tienen su propio editor dedicado.
        // El editor de páginas no debe pintar secciones genéricas (hero/cta…)
        // para artículos. Redirigimos al editor especializado.
        if (($page['page_type'] ?? '') === 'article') {
            \Core\Response::redirect(base_url('admin/posts/' . (int) $page['id'] . '/edit'));
        }

        // FH3 — Las páginas canvas se editan en el Studio Live (chat), no aquí.
        if (($page['render_mode'] ?? 'sections') === 'canvas') {
            \Core\Response::redirect(base_url('admin/canvas/' . (int) $page['id']));
        }

        // Secciones ordenadas
        $sections = Database::select(
            'SELECT id, section_type, sort_order, content, style, status, updated_at
             FROM page_sections WHERE page_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$page['id']]
        );

        $this->renderForm([
            'mode'     => 'edit',
            'page'     => $page,
            'sections' => $sections,
            'errors'   => [],
        ]);
    }

    // ----------------------------------------------------------------------
    // Update (POST)
    // ----------------------------------------------------------------------
    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id     = (int) ($params['id'] ?? 0);
        $page   = self::findOrFail($id, $siteId);

        $input  = self::collectInput();
        $errors = self::validate($input, $siteId, $id);

        if (!empty($errors)) {
            $input['id'] = $id; // preservar id para volver a renderizar
            $this->renderForm([
                'mode'   => 'edit',
                'page'   => array_merge($page, $input),
                'errors' => $errors,
            ]);
        }

        // Gestionar published_at: si pasa a published y no tenía fecha, se setea; si vuelve a draft, se deja.
        $publishedAt = $page['published_at'];
        if ($input['status'] === 'published' && empty($publishedAt)) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        Database::execute(
            'UPDATE pages SET
                title = ?, slug = ?, page_type = ?,
                meta_title = ?, meta_description = ?,
                seo_noindex = ?, seo_exclude_sitemap = ?, canonical_url = ?,
                status = ?, published_at = ?
             WHERE id = ? AND site_id = ?',
            [
                $input['title'],
                $input['slug'],
                $input['page_type'],
                $input['meta_title'] ?: null,
                $input['meta_description'] ?: null,
                (int) $input['seo_noindex'],
                (int) $input['seo_exclude_sitemap'],
                $input['canonical_url'] ?: null,
                $input['status'],
                $publishedAt,
                $id, $siteId,
            ]
        );

        if (($page['status'] ?? '') === 'published'
            && (string) ($page['slug'] ?? '') !== (string) $input['slug']
            && ($page['page_type'] ?? '') !== 'home'
        ) {
            try {
                SeoRedirectService::createAutomaticSlugRedirect(
                    $siteId,
                    (string) $page['slug'],
                    (string) $input['slug'],
                    $id,
                    Auth::id()
                );
            } catch (\Throwable $e) {
                error_log('[SEO] automatic redirect failed for page ' . $id . ': ' . $e->getMessage());
            }
        }

        // T7.3: invalidar cache público — incluye slug viejo si cambió, y home si aplica.
        CacheService::invalidatePage(
            $siteId,
            ['slug' => $input['slug'], 'page_type' => $input['page_type']],
            (string) ($page['slug'] ?? '')
        );
        // Si la página vieja era home y la nueva ya no, también limpia __home.
        if (($page['page_type'] ?? '') === 'home' && $input['page_type'] !== 'home') {
            CacheService::forget($siteId, CacheService::HOME_KEY);
        }

        // E-GDPR G6 — Aviso suave si se publica con privacidad incompleta.
        $becameLive = $input['status'] === 'published'
            && (($page['status'] ?? 'draft') !== 'published');
        if ($becameLive) {
            try {
                $compliance = \App\Services\Compliance\ComplianceService::status($siteId);
                $level = $compliance['level'] ?? 'green';
                if ($level === 'orange' || $level === 'red') {
                    Session::flash(
                        'warning',
                        'Página publicada. Te recomendamos completar los datos de privacidad pendientes en /admin/privacy.'
                    );
                }
            } catch (\Throwable $e) {}
        }

        Session::flash('success', 'Página actualizada correctamente.');
        Response::redirect(base_url('admin/pages/' . $id . '/edit'));
    }

    // ----------------------------------------------------------------------
    // Destroy (POST)
    // ----------------------------------------------------------------------
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $id     = (int) ($params['id'] ?? 0);
        $page   = self::findOrFail($id, $siteId);

        // CASCADE en page_sections vía FK → no hace falta borrar manualmente
        Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$id, $siteId]);

        // T7.3: invalidar cache público.
        CacheService::invalidatePage($siteId, $page);

        Session::flash('success', 'Página eliminada correctamente.');
        Response::redirect(base_url('admin/pages'));
    }

    // ======================================================================
    // Helpers privados
    // ======================================================================

    private function renderForm(array $ctx): void
    {
        $data = DashboardController::getCommonData();
        $page = $ctx['page'] ?? [];
        $pageId = (int) ($page['id'] ?? 0);
        $isArticle = ($page['page_type'] ?? '') === 'article';

        // E-GDPR G6 — estado de cumplimiento (para pill contextual).
        $compliance = ['level' => 'green', 'gaps' => []];
        try {
            $siteId = Auth::siteId();
            if ($siteId !== null) {
                $compliance = \App\Services\Compliance\ComplianceService::status($siteId);
            }
        } catch (\Throwable $e) {}

        // Integridad de enlaces: páginas disponibles para el selector de destino.
        $pagesForLinks = [];
        try {
            $linkSiteId = Auth::siteId();
            if ($linkSiteId !== null) {
                $rows = Database::select(
                    'SELECT id, title, slug, page_type, status FROM pages
                     WHERE site_id = ? ORDER BY tree_sort_order ASC, sort_order ASC, title ASC',
                    [$linkSiteId]
                );
                $pagesForLinks = array_map([self::class, 'pageLinkInfo'], $rows);
            }
        } catch (\Throwable $e) {}

        $data = array_merge($data, [
            'mode'         => $ctx['mode'],
            'page'         => $page,
            'errors'       => $ctx['errors'],
            'pageTypes'    => self::PAGE_TYPES,
            'sections'     => $ctx['sections'] ?? [],
            'sectionTypes' => SectionController::SECTION_TYPES,
            'pagesForLinks' => $pagesForLinks,
            'csrf'         => CSRF::token(),
            'compliance'   => $compliance,
            // F21.T21.1 — metadatos de entrada solo cuando aplica.
            'isArticle'    => $isArticle,
            'postMeta'     => ($isArticle && $pageId > 0)
                ? \App\Services\PostMetaService::load($pageId)
                : [
                    'excerpt' => '', 'featured_image_path' => '', 'featured_image_alt' => '',
                    'reading_minutes' => null, 'author_name' => '',
                ],
        ]);
        View::send('admin/pages/form', $data);
    }

    private static function defaults(): array
    {
        return [
            'id'               => null,
            'title'            => '',
            'slug'             => '',
            'page_type'        => 'landing',
            'meta_title'       => '',
            'meta_description' => '',
            'seo_noindex'      => 0,
            'seo_exclude_sitemap' => 0,
            'canonical_url'    => '',
            'status'           => 'draft',
            'published_at'     => null,
        ];
    }

    private static function collectInput(): array
    {
        $input = [
            'title'            => trim((string) Request::post('title', '')),
            'slug'             => trim((string) Request::post('slug', '')),
            'page_type'        => (string) Request::post('page_type', 'landing'),
            'meta_title'       => trim((string) Request::post('meta_title', '')),
            'meta_description' => trim((string) Request::post('meta_description', '')),
            'seo_noindex'      => Request::post('seo_noindex', '') === '1' ? 1 : 0,
            'seo_exclude_sitemap' => Request::post('seo_exclude_sitemap', '') === '1' ? 1 : 0,
            'canonical_url'    => trim((string) Request::post('canonical_url', '')),
            'status'           => (string) Request::post('status', 'draft'),
        ];
        // Auto-slug desde el título si el usuario lo dejó vacío
        if ($input['slug'] === '' && $input['title'] !== '') {
            $input['slug'] = slugify($input['title']);
        }
        $canonical = SeoIndexingService::normalizeCanonical($input['canonical_url']);
        if ($canonical !== null) {
            $input['canonical_url'] = $canonical;
        }
        return $input;
    }

    /**
     * Valida datos. Si $id es null → creación. Si no → update (excluye ese id del check de unicidad).
     * @return array<string,string> errores por campo
     */
    private static function validate(array $input, int $siteId, ?int $id): array
    {
        $errors = [];

        if ($input['title'] === '') {
            $errors['title'] = 'El título es obligatorio.';
        } elseif (mb_strlen($input['title']) > 500) {
            $errors['title'] = 'El título no puede superar 500 caracteres.';
        }

        if (!isset(self::PAGE_TYPES[$input['page_type']])) {
            $errors['page_type'] = 'Tipo de página inválido.';
        }

        if (!in_array($input['status'], self::STATUSES, true)) {
            $errors['status'] = 'Estado inválido.';
        }

        if (mb_strlen($input['meta_title']) > 255) {
            $errors['meta_title'] = 'El meta título no puede superar 255 caracteres.';
        }
        if (mb_strlen($input['meta_description']) > 500) {
            $errors['meta_description'] = 'La meta descripción no puede superar 500 caracteres.';
        }
        if ($input['canonical_url'] !== '' && SeoIndexingService::normalizeCanonical($input['canonical_url']) === null) {
            $errors['canonical_url'] = 'La canonical debe ser una URL completa que empiece por http:// o https://.';
        }

        // (El slug ya viene autogenerado desde collectInput si estaba vacío)
        if ($input['slug'] === '') {
            $errors['slug'] = 'El slug es obligatorio (se autogenera del título si lo dejas vacío).';
        } elseif (!preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $input['slug'])) {
            $errors['slug'] = 'El slug solo puede contener minúsculas, números, guiones simples y barras (para anidar, ej: servicios/diseno-web).';
        } elseif (mb_strlen($input['slug']) > 500) {
            $errors['slug'] = 'El slug no puede superar 500 caracteres.';
        } else {
            // Check unicidad (site_id, slug)
            $sql = 'SELECT id FROM pages WHERE site_id = ? AND slug = ?';
            $params = [$siteId, $input['slug']];
            if ($id !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $id;
            }
            $exists = Database::selectOne($sql . ' LIMIT 1', $params);
            if ($exists) {
                $errors['slug'] = 'Ya existe una página con ese slug.';
            }
        }

        return $errors;
    }

    private static function findOrFail(int $id, int $siteId): array
    {
        $page = Database::selectOne(
            'SELECT * FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
            [$id, $siteId]
        );
        if (!$page) {
            Response::notFound('Página no encontrada');
        }
        return $page;
    }

    private static function ensureHierarchySchema(): void
    {
        $columns = Database::select('SHOW COLUMNS FROM pages');
        $names = array_column($columns, 'Field');

        if (!in_array('parent_id', $names, true)) {
            Database::execute('ALTER TABLE pages ADD COLUMN parent_id INT UNSIGNED DEFAULT NULL AFTER page_type');
        }
        if (!in_array('nav_label', $names, true)) {
            Database::execute('ALTER TABLE pages ADD COLUMN nav_label VARCHAR(255) DEFAULT NULL AFTER parent_id');
        }
        if (!in_array('tree_sort_order', $names, true)) {
            Database::execute('ALTER TABLE pages ADD COLUMN tree_sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER sort_order');
        }

        $indexes = Database::select('SHOW INDEX FROM pages');
        $indexNames = array_unique(array_map(fn($r) => (string) $r['Key_name'], $indexes));
        if (!in_array('idx_pages_parent_order', $indexNames, true)) {
            Database::execute('ALTER TABLE pages ADD INDEX idx_pages_parent_order (site_id, parent_id, tree_sort_order)');
        }
    }

    private static function inferInitialHierarchy(int $siteId): void
    {
        $done = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'page_hierarchy_inferred']
        );
        if ($done) return;

        $pages = Database::select(
            'SELECT id, title, slug, parent_id FROM pages WHERE site_id = ? ORDER BY slug ASC',
            [$siteId]
        );
        $bySlug = [];
        foreach ($pages as $p) {
            $bySlug[(string) $p['slug']] = (int) $p['id'];
        }

        foreach ($pages as $index => $p) {
            $slug = trim((string) $p['slug'], '/');
            $parentId = null;
            if (str_contains($slug, '/')) {
                $parentSlug = substr($slug, 0, (int) strrpos($slug, '/'));
                $parentId = $bySlug[$parentSlug] ?? null;
            }
            Database::execute(
                'UPDATE pages SET parent_id = COALESCE(parent_id, ?), nav_label = COALESCE(nav_label, ?), tree_sort_order = CASE WHEN tree_sort_order = 0 THEN ? ELSE tree_sort_order END WHERE id = ? AND site_id = ?',
                [$parentId, self::navLabelFromTitle((string) $p['title']), $index + 1, (int) $p['id'], $siteId]
            );
        }

        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, 'page_hierarchy_inferred', date('c')]
        );
    }

    private static function repairFlatOnboardingHierarchy(int $siteId): void
    {
        $linked = Database::selectOne('SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND parent_id IS NOT NULL', [$siteId]);
        if ((int) ($linked['n'] ?? 0) > 0) return;

        $pages = Database::select(
            'SELECT id, title, slug, page_type FROM pages WHERE site_id = ? ORDER BY tree_sort_order ASC, id ASC',
            [$siteId]
        );
        if (count($pages) < 2) return;

        $homeId = self::firstMatchingPageId($pages, 'home', ['inicio', 'home']);
        if ($homeId <= 0) return;
        $servicesId = self::firstMatchingPageId($pages, 'service', ['servicios', 'services']);
        $blogId = self::firstMatchingPageId($pages, 'article', ['blog']);

        foreach ($pages as $index => $page) {
            $id = (int) $page['id'];
            if ($id === $homeId) continue;
            $type = (string) ($page['page_type'] ?? '');
            $slug = trim((string) ($page['slug'] ?? ''), '/');
            $title = mb_strtolower(trim((string) ($page['title'] ?? '')));
            $parentId = $homeId;

            if ($type === 'service' && $servicesId > 0 && $id !== $servicesId && !in_array($slug, ['servicios', 'services'], true) && !in_array($title, ['servicios', 'services'], true)) {
                $parentId = $servicesId;
            } elseif ($type === 'article' && $blogId > 0 && $id !== $blogId && $slug !== 'blog' && $title !== 'blog') {
                $parentId = $blogId;
            }

            Database::execute(
                'UPDATE pages SET parent_id = ?, tree_sort_order = CASE WHEN tree_sort_order = 0 THEN ? ELSE tree_sort_order END WHERE id = ? AND site_id = ? AND parent_id IS NULL',
                [$parentId, $index + 1, $id, $siteId]
            );
        }
    }

    /** @param array<int,array<string,mixed>> $pages */
    private static function firstMatchingPageId(array $pages, string $type, array $slugs): int
    {
        foreach ($pages as $page) {
            $pageType = (string) ($page['page_type'] ?? '');
            $slug = trim((string) ($page['slug'] ?? ''), '/');
            $title = mb_strtolower(trim((string) ($page['title'] ?? '')));
            if ($pageType === $type || in_array($slug, $slugs, true) || in_array($title, $slugs, true)) {
                return (int) $page['id'];
            }
        }
        return 0;
    }

    /** @param array<int,array<string,mixed>> $pages */
    private static function buildPageTree(array $pages): array
    {
        $children = [];
        $byId = [];
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $byId[$id] = $page;
        }
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $parentId = isset($page['parent_id']) ? (int) $page['parent_id'] : 0;
            if ($parentId > 0 && !isset($byId[$parentId])) {
                $parentId = 0;
            }
            $children[$parentId][] = $id;
        }

        $make = function (int $id, int $depth = 0) use (&$make, &$children, &$byId): array {
            $page = $byId[$id];
            $nodeChildren = [];
            foreach (($children[$id] ?? []) as $childId) {
                $nodeChildren[] = $make((int) $childId, $depth + 1);
            }
            $page['depth'] = $depth;
            $page['children'] = $nodeChildren;
            return $page;
        };

        $roots = [];
        foreach (($children[0] ?? []) as $id) {
            $roots[] = $make((int) $id, 0);
        }
        return $roots;
    }

    /** @param array<int,array<string,mixed>> $pages */
    private static function pageOptions(array $pages): array
    {
        return array_map(fn($p) => [
            'id' => (int) $p['id'],
            'label' => (string) (($p['nav_label'] ?? '') ?: $p['title']),
            'slug' => (string) $p['slug'],
        ], $pages);
    }

    private static function navLabelFromTitle(string $title): string
    {
        return mb_substr(trim($title), 0, 255);
    }

    private static function pageBelongsToSite(int $pageId, int $siteId): bool
    {
        return (bool) Database::selectOne('SELECT id FROM pages WHERE id = ? AND site_id = ? LIMIT 1', [$pageId, $siteId]);
    }

    private static function siteHasMemory(int $siteId): bool
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS c FROM site_memory
             WHERE site_id = ? AND field_value IS NOT NULL AND TRIM(field_value) <> ''",
            [$siteId]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

    private static function wouldCreateCycle(int $pageId, int $parentId): bool
    {
        $seen = [$pageId => true];
        $current = $parentId;
        while ($current > 0) {
            if (isset($seen[$current])) return true;
            $seen[$current] = true;
            $row = Database::selectOne('SELECT parent_id FROM pages WHERE id = ? LIMIT 1', [$current]);
            $current = (int) ($row['parent_id'] ?? 0);
        }
        return false;
    }

    public static function nextTreeOrder(int $siteId, ?int $parentId): int
    {
        $row = $parentId === null
            ? Database::selectOne('SELECT COALESCE(MAX(tree_sort_order), 0) AS n FROM pages WHERE site_id = ? AND parent_id IS NULL', [$siteId])
            : Database::selectOne('SELECT COALESCE(MAX(tree_sort_order), 0) AS n FROM pages WHERE site_id = ? AND parent_id = ?', [$siteId, $parentId]);
        return ((int) ($row['n'] ?? 0)) + 1;
    }

    private static function architectureFingerprint(int $siteId): string
    {
        return self::opportunityFingerprint($siteId, 'architecture-v1');
    }

    private static function siteMapContext(int $siteId): string
    {
        $pages = Database::select(
            'SELECT id, title, slug, page_type, parent_id, nav_label, status, tree_sort_order, updated_at
             FROM pages
             WHERE site_id = ?
             ORDER BY parent_id ASC, tree_sort_order ASC, slug ASC',
            [$siteId]
        );
        $sectionStats = Database::select(
            'SELECT s.page_id,
                    COUNT(s.id) AS sections_count,
                    SUM(CASE WHEN s.section_type = "form" THEN 1 ELSE 0 END) AS forms_count
             FROM page_sections s
             JOIN pages p ON p.id = s.page_id
             WHERE p.site_id = ?
             GROUP BY s.page_id',
            [$siteId]
        );
        $statsByPage = [];
        foreach ($sectionStats as $row) {
            $statsByPage[(int) $row['page_id']] = $row;
        }

        $memory = Database::select(
            'SELECT field_key, field_value FROM site_memory WHERE site_id = ? ORDER BY field_key ASC',
            [$siteId]
        );
        $documents = Database::select(
            'SELECT title, status, summary FROM documents WHERE site_id = ? ORDER BY updated_at DESC, id DESC LIMIT 8',
            [$siteId]
        );

        $lines = ["Páginas actuales:"];
        foreach ($pages as $p) {
            $stats = $statsByPage[(int) $p['id']] ?? [];
            $lines[] = '- id=' . (int) $p['id']
                . ' | title=' . (string) $p['title']
                . ' | nav=' . (string) (($p['nav_label'] ?? '') ?: $p['title'])
                . ' | slug=/' . (string) $p['slug']
                . ' | type=' . (string) $p['page_type']
                . ' | parent_id=' . (string) ($p['parent_id'] ?? '')
                . ' | status=' . (string) $p['status']
                . ' | order=' . (int) ($p['tree_sort_order'] ?? 0)
                . ' | sections=' . (int) ($stats['sections_count'] ?? 0)
                . ' | forms=' . (int) ($stats['forms_count'] ?? 0);
        }

        $lines[] = "\nMemoria del sitio:";
        foreach ($memory as $m) {
            $lines[] = '- ' . (string) $m['field_key'] . ': ' . mb_substr(trim((string) $m['field_value']), 0, 800);
        }

        $lines[] = "\nDocumentos base:";
        foreach ($documents as $doc) {
            $summary = trim((string) ($doc['summary'] ?? ''));
            $lines[] = '- ' . (string) $doc['title']
                . ' | status=' . (string) $doc['status']
                . ($summary !== '' ? ' | summary=' . mb_substr($summary, 0, 800) : '');
        }

        return implode("\n", $lines);
    }

    /** @return array<string,mixed>|null */
    private static function cachedArchitecture(int $siteId, string $fingerprint): ?array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'site_architecture_cache']
        );
        if (!$row) return null;
        $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
        if (!is_array($decoded) || ($decoded['fingerprint'] ?? '') !== $fingerprint) return null;
        return $decoded;
    }

    private static function storeCachedArchitecture(int $siteId, string $fingerprint, array $architecture, array $result): void
    {
        $payload = json_encode([
            'fingerprint' => $fingerprint,
            'cached_at' => date('c'),
            'architecture' => $architecture,
            'meta' => [
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, 'site_architecture_cache', $payload]
        );
    }

    private static function normalizeArchitecture(array $data, int $siteId): array
    {
        $pages = self::loadExistingPages($siteId);
        $slugToId = [];
        foreach ($pages as $p) {
            $slugToId[(string) $p['slug']] = (int) $p['id'];
        }
        $allowedTypes = array_keys(self::PAGE_TYPES);
        $priorities = ['high', 'medium', 'low'];

        $missing = [];
        foreach ((array) ($data['missing_pages'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $title = trim((string) ($item['title'] ?? ''));
            $goal = trim((string) ($item['goal'] ?? ''));
            if ($title === '' || $goal === '') continue;
            $type = (string) ($item['page_type'] ?? 'landing');
            $priority = (string) ($item['priority'] ?? 'medium');
            $parentSlug = trim((string) ($item['parent_slug'] ?? ''), '/');
            $missing[] = [
                'title' => mb_substr($title, 0, 160),
                'page_type' => in_array($type, $allowedTypes, true) ? $type : 'landing',
                'parent_slug' => $parentSlug,
                'parent_id' => $parentSlug !== '' ? ($slugToId[$parentSlug] ?? null) : null,
                'goal' => mb_substr($goal, 0, 500),
                'reason' => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 260),
                'priority' => in_array($priority, $priorities, true) ? $priority : 'medium',
                'architecture_context' => mb_substr(trim((string) ($item['architecture_context'] ?? '')), 0, 700),
            ];
        }

        $groups = [];
        foreach ((array) ($data['suggested_groups'] ?? []) as $group) {
            if (!is_array($group)) continue;
            $label = trim((string) ($group['label'] ?? ''));
            if ($label === '') continue;
            $priority = (string) ($group['priority'] ?? 'medium');
            $groups[] = [
                'label' => mb_substr($label, 0, 120),
                'slug' => trim((string) ($group['slug'] ?? slugify($label)), '/'),
                'reason' => mb_substr(trim((string) ($group['reason'] ?? '')), 0, 260),
                'priority' => in_array($priority, $priorities, true) ? $priority : 'medium',
            ];
        }

        $diagnostics = [];
        foreach ((array) ($data['diagnostics'] ?? []) as $diag) {
            if (!is_array($diag)) continue;
            $label = trim((string) ($diag['label'] ?? ''));
            if ($label === '') continue;
            $severity = (string) ($diag['severity'] ?? 'info');
            $diagnostics[] = [
                'label' => mb_substr($label, 0, 120),
                'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
                'detail' => mb_substr(trim((string) ($diag['detail'] ?? '')), 0, 300),
            ];
        }

        return [
            'summary' => mb_substr(trim((string) ($data['summary'] ?? 'Arquitectura analizada.')), 0, 500),
            'health' => [
                'score' => max(0, min(100, (int) ($data['health']['score'] ?? 60))),
                'label' => mb_substr(trim((string) ($data['health']['label'] ?? 'En progreso')), 0, 120),
            ],
            'suggested_groups' => array_slice($groups, 0, 4),
            'missing_pages' => array_slice($missing, 0, 6),
            'diagnostics' => array_slice($diagnostics, 0, 5),
        ];
    }

    private static function fallbackArchitecture(int $siteId): array
    {
        $pages = self::loadExistingPages($siteId);
        $hasContact = array_filter($pages, fn($p) => ($p['page_type'] ?? '') === 'contact' || str_contains((string) ($p['slug'] ?? ''), 'contact'));
        return [
            'summary' => 'Arquitectura calculada localmente. La IA podrá afinarla cuando el proveedor responda.',
            'health' => ['score' => count($pages) > 3 ? 68 : 42, 'label' => count($pages) > 3 ? 'Base navegable' : 'Estructura inicial'],
            'suggested_groups' => [],
            'missing_pages' => $hasContact ? [] : [[
                'title' => 'Contacto',
                'page_type' => 'contact',
                'parent_slug' => '',
                'parent_id' => null,
                'goal' => 'Facilitar que los visitantes contacten y envíen solicitudes.',
                'reason' => 'No se ha detectado una página de contacto clara.',
                'priority' => 'high',
                'architecture_context' => 'Página de cierre de conversión para la navegación principal.',
            ]],
            'diagnostics' => [[
                'label' => 'Revisión local',
                'severity' => 'info',
                'detail' => 'Se ha generado una lectura básica sin llamada IA.',
            ]],
        ];
    }

    /** @param array<string,mixed> $content */
    private static function filterSectionContent(string $type, array $content): array
    {
        $schema = SectionSchemas::forType($type);
        if ($schema === null) return $content;

        $allowed = [];
        foreach (($schema['fields'] ?? []) as $field) {
            if (isset($field['key']) && array_key_exists($field['key'], $content)) {
                $allowed[$field['key']] = $content[$field['key']];
            }
        }
        return $allowed;
    }

    /** @param array<int,array{type:string,content:array<string,mixed>}> $sections */
    private static function textFromGeneratedSections(array $sections): string
    {
        $chunks = [];
        foreach ($sections as $section) {
            $chunks[] = self::flattenText($section['content']);
        }
        return mb_substr(trim(implode("\n", array_filter($chunks))), 0, 6000);
    }

    private static function flattenText(mixed $value): string
    {
        if (is_string($value)) return trim($value);
        if (is_scalar($value) || $value === null) return '';
        $out = [];
        foreach ((array) $value as $v) {
            $txt = self::flattenText($v);
            if ($txt !== '') $out[] = $txt;
        }
        return implode("\n", $out);
    }

    public static function uniqueSlug(int $siteId, string $base, ?int $ignoreId = null): string
    {
        $base = trim($base, '/');
        if ($base === '' || !preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', $base)) {
            $base = 'pagina-' . date('Ymd-His');
        }

        $slug = $base;
        $i = 2;
        // $ignoreId permite re-guardar una página sin que su propio slug cuente
        // como colisión (si no, guardar dos veces generaría "slug-2").
        $sql = 'SELECT id FROM pages WHERE site_id = ? AND slug = ?'
            . ($ignoreId !== null ? ' AND id <> ?' : '') . ' LIMIT 1';
        while (true) {
            $args = $ignoreId !== null ? [$siteId, $slug, $ignoreId] : [$siteId, $slug];
            if (!Database::selectOne($sql, $args)) {
                break;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /** @return array{calls:int,tokens_in:int,tokens_out:int,estimated_cost:float,models:array<int,string>} */
    private static function emptyAiUsage(): array
    {
        return [
            'calls' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0.0,
            'models' => [],
        ];
    }

    /** @param array{calls:int,tokens_in:int,tokens_out:int,estimated_cost:float,models:array<int,string>} $usage */
    private static function addAiUsage(array &$usage, array $result): void
    {
        $usage['calls']++;
        $usage['tokens_in'] += (int) ($result['tokens_in'] ?? 0);
        $usage['tokens_out'] += (int) ($result['tokens_out'] ?? 0);
        $usage['estimated_cost'] = round($usage['estimated_cost'] + (float) ($result['estimated_cost'] ?? 0), 6);
        $model = (string) ($result['model'] ?? '');
        if ($model !== '' && !in_array($model, $usage['models'], true)) {
            $usage['models'][] = $model;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function loadExistingPages(int $siteId): array
    {
        self::ensureHierarchySchema();
        return Database::select(
            'SELECT id, title, slug, page_type, parent_id, nav_label, tree_sort_order, status, updated_at
             FROM pages WHERE site_id = ?
             ORDER BY parent_id ASC, tree_sort_order ASC, page_type ASC, title ASC',
            [$siteId]
        );
    }

    private static function existingPagesPrompt(int $siteId): string
    {
        $pages = self::loadExistingPages($siteId);
        if ($pages === []) {
            return 'Todavía no hay páginas creadas.';
        }

        $lines = [];
        foreach ($pages as $p) {
            $lines[] = '- ' . (string) $p['title']
                . ' | tipo=' . (string) $p['page_type']
                . ' | slug=/' . (string) $p['slug']
                . ' | parent_id=' . (string) ($p['parent_id'] ?? '')
                . ' | estado=' . (string) $p['status'];
        }
        return implode("\n", $lines);
    }

    private static function opportunityFingerprint(int $siteId, string $notes = ''): string
    {
        $parts = ['site:' . $siteId, 'notes:' . trim($notes)];

        $site = Database::selectOne(
            'SELECT name, url, language, timezone, updated_at FROM sites WHERE id = ? LIMIT 1',
            [$siteId]
        );
        $parts[] = 'site_row:' . json_encode($site ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $memory = Database::select(
            'SELECT field_key, field_value, updated_at FROM site_memory WHERE site_id = ? ORDER BY field_key ASC',
            [$siteId]
        );
        $parts[] = 'memory:' . json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pages = Database::select(
            'SELECT id, title, slug, page_type, parent_id, nav_label, tree_sort_order, status, updated_at
             FROM pages WHERE site_id = ? ORDER BY id ASC',
            [$siteId]
        );
        $parts[] = 'pages:' . json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sections = Database::select(
            'SELECT s.id, s.page_id, s.section_type, s.sort_order, s.updated_at, MD5(COALESCE(s.content, "")) AS content_hash
             FROM page_sections s
             JOIN pages p ON p.id = s.page_id
             WHERE p.site_id = ?
             ORDER BY s.page_id ASC, s.sort_order ASC, s.id ASC',
            [$siteId]
        );
        $parts[] = 'sections:' . json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $documents = Database::select(
            'SELECT id, title, status, updated_at, MD5(COALESCE(summary, "")) AS summary_hash
             FROM documents WHERE site_id = ? ORDER BY id ASC',
            [$siteId]
        );
        $parts[] = 'documents:' . json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', implode("\n", $parts));
    }

    /** @return array<string,mixed>|null */
    private static function cachedOpportunities(int $siteId, string $fingerprint): ?array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'page_studio_opportunities_cache']
        );
        if (!$row || trim((string) ($row['setting_value'] ?? '')) === '') {
            return null;
        }

        $decoded = json_decode((string) $row['setting_value'], true);
        if (!is_array($decoded) || ($decoded['fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }
        if (!is_array($decoded['data'] ?? null)) {
            return null;
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private static function storeCachedOpportunities(int $siteId, string $fingerprint, array $data, array $result = []): void
    {
        $payload = json_encode([
            'fingerprint' => $fingerprint,
            'cached_at' => date('c'),
            'data' => $data,
            'meta' => [
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, 'page_studio_opportunities_cache', $payload]
        );
    }

    /** @return array<string,mixed> */
    private static function normalizeOpportunities(array $data, int $siteId): array
    {
        $items = [];
        $allowedTypes = array_keys(self::PAGE_TYPES);
        foreach ((array) ($data['opportunities'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $title = trim((string) ($item['title'] ?? ''));
            $goal = trim((string) ($item['goal'] ?? ''));
            if ($title === '' || $goal === '') continue;
            $type = (string) ($item['page_type'] ?? 'landing');
            $priority = (string) ($item['priority'] ?? 'medium');
            $source = (string) ($item['source'] ?? 'memory');
            $items[] = [
                'title' => mb_substr($title, 0, 140),
                'page_type' => in_array($type, $allowedTypes, true) ? $type : 'landing',
                'goal' => mb_substr($goal, 0, 420),
                'audience' => mb_substr(trim((string) ($item['audience'] ?? '')), 0, 180),
                'reason' => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 240),
                'priority' => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
                'source' => in_array($source, ['memory', 'documents', 'pages'], true) ? $source : 'memory',
                'details' => mb_substr(trim((string) ($item['details'] ?? '')), 0, 500),
            ];
        }

        if ($items === []) {
            return self::fallbackOpportunities($siteId);
        }

        return [
            'site_summary' => mb_substr(trim((string) ($data['site_summary'] ?? '')), 0, 300),
            'opportunities' => array_slice($items, 0, 5),
        ];
    }

    /** @return array<string,mixed> */
    private static function fallbackOpportunities(int $siteId): array
    {
        $pages = self::loadExistingPages($siteId);
        $hasContact = false;
        foreach ($pages as $p) {
            if (($p['page_type'] ?? '') === 'contact' || str_contains((string) ($p['slug'] ?? ''), 'contact')) {
                $hasContact = true;
                break;
            }
        }

        $opps = [[
            'title' => 'Página de servicio principal',
            'page_type' => 'service',
            'goal' => 'Presentar un servicio clave del negocio y convertir visitantes en solicitudes de contacto.',
            'audience' => '',
            'reason' => 'Es una página base para captar tráfico y leads de intención comercial.',
            'priority' => 'high',
            'source' => 'memory',
            'details' => 'PromptPress usará la memoria del sitio y los documentos cargados para concretarla.',
        ]];

        if (!$hasContact) {
            $opps[] = [
                'title' => 'Página de contacto con formulario',
                'page_type' => 'contact',
                'goal' => 'Facilitar que los visitantes contacten sin configurar manualmente un formulario.',
                'audience' => '',
                'reason' => 'No se ha detectado una página de contacto clara en la estructura actual.',
                'priority' => 'high',
                'source' => 'pages',
                'details' => 'Incluirá campos básicos de lead y llamada a la acción.',
            ];
        }

        return [
            'site_summary' => 'Oportunidades calculadas sin IA a partir de la estructura actual.',
            'opportunities' => $opps,
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeBrief(array $data): array
    {
        $allowedPageTypes = array_keys(self::PAGE_TYPES);
        $allowedSectionTypes = array_keys(SectionSchemas::all());
        $pageType = (string) ($data['page_type'] ?? 'landing');

        $sections = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            $type = (string) ($section['type'] ?? '');
            if (!in_array($type, $allowedSectionTypes, true)) continue;
            $sections[] = [
                'type' => $type,
                'heading' => mb_substr(trim((string) ($section['heading'] ?? '')), 0, 120),
                'purpose' => mb_substr(trim((string) ($section['purpose'] ?? '')), 0, 260),
            ];
        }

        return [
            'title' => mb_substr(trim((string) ($data['title'] ?? 'Nueva página')), 0, 160),
            'page_type' => in_array($pageType, $allowedPageTypes, true) ? $pageType : 'landing',
            'goal' => mb_substr(trim((string) ($data['goal'] ?? 'Crear una página útil para el sitio.')), 0, 500),
            'audience' => mb_substr(trim((string) ($data['audience'] ?? '')), 0, 220),
            'tone' => mb_substr(trim((string) ($data['tone'] ?? '')), 0, 180),
            'seo_intent' => mb_substr(trim((string) ($data['seo_intent'] ?? '')), 0, 240),
            'primary_cta' => mb_substr(trim((string) ($data['primary_cta'] ?? '')), 0, 100),
            'recommended_form' => self::normalizeRecommendedForm((array) ($data['recommended_form'] ?? [])),
            'sections' => array_slice($sections, 0, 7),
            'questions' => array_slice(array_values(array_filter(array_map('strval', (array) ($data['questions'] ?? [])))), 0, 3),
            'extra_context' => mb_substr(trim((string) ($data['extra_context'] ?? '')), 0, 1000),
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeRecommendedForm(array $form): array
    {
        $fields = [];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') continue;
            $type = (string) ($field['field_type'] ?? 'text');
            if (!in_array($type, ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'], true)) {
                $type = 'text';
            }
            $name = preg_replace('/[^a-z0-9_]+/', '_', strtolower(slugify((string) ($field['name'] ?? $label)))) ?: 'campo';
            $fields[] = [
                'label' => mb_substr($label, 0, 80),
                'name' => mb_substr($name, 0, 40),
                'field_type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 120),
            ];
        }

        return [
            'needed' => (bool) ($form['needed'] ?? false),
            'purpose' => mb_substr(trim((string) ($form['purpose'] ?? '')), 0, 240),
            'fields' => array_slice($fields, 0, 8),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodePostedBrief(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? self::normalizeBrief($decoded) : [];
    }

    /** @param array<string,mixed> $brief */
    private static function briefToContext(array $brief): string
    {
        if ($brief === []) return '';
        $lines = [
            'Brief aprobado por el usuario:',
            'Objetivo: ' . (string) ($brief['goal'] ?? ''),
            'Público: ' . (string) ($brief['audience'] ?? ''),
            'Tono: ' . (string) ($brief['tone'] ?? ''),
            'Intención SEO: ' . (string) ($brief['seo_intent'] ?? ''),
            'CTA principal: ' . (string) ($brief['primary_cta'] ?? ''),
        ];
        $form = (array) ($brief['recommended_form'] ?? []);
        if (($form['needed'] ?? false) === true) {
            $fields = [];
            foreach ((array) ($form['fields'] ?? []) as $field) {
                $fields[] = (string) ($field['label'] ?? '') . ' (' . (string) ($field['field_type'] ?? 'text') . ')';
            }
            $lines[] = 'Formulario recomendado: ' . (string) ($form['purpose'] ?? '');
            $lines[] = 'Campos del formulario: ' . implode(', ', array_filter($fields));
        }
        foreach ((array) ($brief['sections'] ?? []) as $i => $section) {
            if (!is_array($section)) continue;
            $lines[] = 'Sección ' . ($i + 1) . ': ' . (string) ($section['type'] ?? '') . ' — '
                . (string) ($section['heading'] ?? '') . ' — ' . (string) ($section['purpose'] ?? '');
        }
        return implode("\n", array_filter($lines));
    }

    /**
     * @param array<string,mixed> $brief
     * @return array<int,array<string,string>>
     */
    private static function structureFromBrief(array $brief): array
    {
        $out = [];
        $valid = array_keys(SectionSchemas::all());
        foreach ((array) ($brief['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            $type = (string) ($section['type'] ?? '');
            if (!in_array($type, $valid, true)) continue;
            $out[] = [
                'type' => $type,
                'variant' => SectionSchemas::normalizeVariant($type, (string) ($section['variant'] ?? 'default')),
                'rationale' => trim((string) ($section['purpose'] ?? $section['heading'] ?? '')),
            ];
        }
        return array_slice($out, 0, 7);
    }

    private static function variantFromStyle(string $styleRaw, string $type): string
    {
        $styleRaw = trim($styleRaw);
        if ($styleRaw === '') return 'default';
        $decoded = json_decode($styleRaw, true);
        if (!is_array($decoded)) return 'default';
        return SectionSchemas::normalizeVariant($type, (string) ($decoded['variant'] ?? 'default'));
    }

    private static function styleJsonWithVariant(int $sectionId, string $variant): ?string
    {
        $row = Database::selectOne('SELECT style FROM page_sections WHERE id = ? LIMIT 1', [$sectionId]);
        $current = trim((string) ($row['style'] ?? ''));
        $style = [];
        if ($current !== '') {
            $decoded = json_decode($current, true);
            if (is_array($decoded)) $style = $decoded;
        }
        if ($variant === 'default') {
            unset($style['variant']);
        } else {
            $style['variant'] = $variant;
        }
        if (empty($style)) return null;
        return json_encode($style, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<int,array{id:int,type:string,variant:string}> $layout */
    private static function normalizeVariation(mixed $raw, array $layout, int $index, int $siteId): ?array
    {
        if (!is_array($raw)) return null;
        $sections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];
        if (count($sections) !== count($layout)) return null;

        $pool = [];
        foreach ($layout as $row) {
            $pool[(string) $row['id']] = $row;
        }
        $countsExpected = array_count_values(array_map(static fn($r) => (string) $r['type'], $layout));
        $countsSeen = [];
        $outSections = [];

        foreach ($sections as $s) {
            if (!is_array($s)) return null;
            $type = (string) ($s['type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$type])) return null;
            $id = null;
            foreach ($pool as $poolId => $candidate) {
                if ((string) ($candidate['type'] ?? '') === $type) {
                    $id = (int) $poolId;
                    unset($pool[$poolId]);
                    break;
                }
            }
            if ($id === null) return null;

            $variant = SectionSchemas::normalizeVariant($type, (string) ($s['variant'] ?? 'default'));
            $countsSeen[$type] = (int) ($countsSeen[$type] ?? 0) + 1;
            $outSections[] = ['id' => $id, 'type' => $type, 'variant' => $variant];
        }

        if ($countsSeen !== $countsExpected) return null;

        $label = trim((string) ($raw['label'] ?? 'Variación ' . $index));
        if ($label === '') $label = 'Variación ' . $index;
        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return [
            'label' => mb_substr($label, 0, 48),
            'rationale' => mb_substr($rationale, 0, 220),
            'sections' => $outSections,
            'preview_html' => self::variationPreviewHtml($outSections, $siteId, 'variation-' . $index),
        ];
    }

    /** @param array<int,array{id:int,type:string,variant:string}> $sections */
    private static function variationPreviewHtml(array $sections, int $siteId, string $seed): string
    {
        $fakeSections = [];
        foreach ($sections as $index => $s) {
            $type = (string) ($s['type'] ?? 'generic');
            $variant = (string) ($s['variant'] ?? 'default');
            $fakeSections[] = [
                'id' => $index + 1,
                'section_type' => $type,
                'sort_order' => $index,
                'content_json' => PageTemplateService::placeholderContent($type, $seed . '-' . $index),
                'style_json' => $variant !== 'default' ? ['variant' => $variant] : null,
            ];
        }

        SectionRenderer::setSiteContext($siteId);
        $body = SectionRenderer::renderMany($fakeSections);
        $styleSlug = VisualStyleService::selectedForSite($siteId);
        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . DesignSystem::renderHead($siteId, $styleSlug)
            . '<style>html,body{margin:0;padding:0;background:#fff}.pp-section{min-height:auto!important}</style>'
            . '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">' . BrandService::publicHeader($siteId) . $body . '</body></html>';
    }

    // ----------------------------------------------------------------------
    // Preview admin (T7.1) — renderiza página con SectionRenderer + design system.
    // La URL pública final con slug llegará en T7.2/T7.4.
    // GET /admin/pages/{id}/preview
    // ----------------------------------------------------------------------
    public function preview(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page   = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        $title     = $page['meta_title'] ?: $page['title'];
        $metaDesc  = (string) ($page['meta_description'] ?? '');
        $styleSlug = VisualStyleService::selectedForSite($siteId);
        $designHead = DesignSystem::renderHead($siteId, $styleSlug);
        SectionRenderer::setSiteContext($siteId);

        // FH1/FH6 — páginas canvas: el cuerpo es HTML libre saneado, no
        // secciones. Sin esta rama el preview salía en blanco (page_sections
        // vacío para canvas).
        $isCanvas = (($page['render_mode'] ?? 'sections') === 'canvas');
        if ($isCanvas) {
            $canvas = \App\Services\Canvas\CanvasService::renderPublic((int) $page['id'], $siteId);
            $body = $canvas['html'];
        } else {
            $sections = Database::select(
                'SELECT id, section_type, sort_order, content, style, status
                 FROM page_sections WHERE page_id = ?
                 ORDER BY sort_order ASC, id ASC',
                [$page['id']]
            );
            $body = SectionRenderer::renderMany($sections);
        }

        $html  = '<!doctype html>';
        $html .= '<html lang="es"><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<title>' . e($title) . ' — Preview</title>';
        if ($metaDesc !== '') {
            $html .= '<meta name="description" content="' . e($metaDesc) . '">';
        }
        $html .= '<meta name="robots" content="noindex,nofollow">';
        $html .= $designHead;
        $html .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $html .= '<div style="position:fixed;top:0;left:0;right:0;background:#111;color:#fff;padding:6px 12px;font:12px system-ui;z-index:9999;display:flex;justify-content:space-between;align-items:center">'
              . '<span>Preview · ' . e($page['title']) . ' <small style="opacity:.6">(' . e($page['status']) . ')</small></span>'
              . '<a href="' . e(base_url('admin/pages/' . $page['id'] . '/edit')) . '" style="color:#9cf;text-decoration:none">← Volver al editor</a>'
              . '</div>';
        $html .= '<div style="padding-top:32px">' . BrandService::publicHeader($siteId) . '</div>';
        $html .= '<main>' . $body . '</main>';
        $html .= BrandService::publicFooter($siteId);
        // FH5 — comportamientos declarativos para que el canvas se vea como en público.
        $html .= '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        $html .= '</body></html>';

        Response::html($html);
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo en la sesión.');
            Response::redirect(base_url('admin/logout'));
        }
        return $siteId;
    }
}
