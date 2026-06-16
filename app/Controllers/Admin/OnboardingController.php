<?php

namespace App\Controllers\Admin;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\CacheService;
use App\Services\DesignSystem;
use App\Services\DocumentSummarizer;
use App\Services\ImageBankService;
use App\Services\PageTemplateService;
use App\Services\PalettePresets;
use App\Services\Renderer\CustomBlockGenerator;
use App\Services\SectionSchemas;
use App\Services\TextExtractor;
use App\Services\VisualStyleService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

final class OnboardingController
{
    private const STEPS = [
        1 => 'Negocio',
        2 => 'Identidad',
        3 => 'IA',
        4 => 'Documento',
        5 => 'Arquitectura',
    ];

    private const SWATCHES = ['#ea580c', '#2563eb', '#16a34a', '#dc2626', '#7c3aed', '#db2777', '#d97706', '#0f172a'];

    private const TYPOGRAPHY = [
        'Inter / Inter' => ['heading' => 'Inter', 'body' => 'Inter', 'label' => 'Sobrio y digital'],
        'Plus Jakarta Sans / Inter' => ['heading' => 'Plus Jakarta Sans', 'body' => 'Inter', 'label' => 'SaaS moderno'],
        'Geist / Geist' => ['heading' => 'Geist', 'body' => 'Geist', 'label' => 'Producto premium'],
        'Outfit / Inter' => ['heading' => 'Outfit', 'body' => 'Inter', 'label' => 'Directo y comercial'],
        'Space Grotesk / DM Sans' => ['heading' => 'Space Grotesk', 'body' => 'DM Sans', 'label' => 'Editorial tecnológico'],
        'Playfair Display / Source Sans 3' => ['heading' => 'Playfair Display', 'body' => 'Source Sans 3', 'label' => 'Elegante editorial'],
        'Manrope / Manrope' => ['heading' => 'Manrope', 'body' => 'Manrope', 'label' => 'Moderno y técnico'],
        'Space Grotesk / Inter' => ['heading' => 'Space Grotesk', 'body' => 'Inter', 'label' => 'Innovador y nítido'],
        'DM Sans / DM Sans' => ['heading' => 'DM Sans', 'body' => 'DM Sans', 'label' => 'Cercano y claro'],
        'Lora / Inter' => ['heading' => 'Lora', 'body' => 'Inter', 'label' => 'Sereno y editorial'],
        'Fraunces / Inter' => ['heading' => 'Fraunces', 'body' => 'Inter', 'label' => 'Cálido y de autor'],
        'Montserrat / Open Sans' => ['heading' => 'Montserrat', 'body' => 'Open Sans', 'label' => 'Corporativo amable'],
        'IBM Plex Sans / IBM Plex Sans' => ['heading' => 'IBM Plex Sans', 'body' => 'IBM Plex Sans', 'label' => 'Funcional y profesional'],
    ];

    private const AI_MODELS = [
        'google/gemini-3-flash-preview' => [
            'name' => 'Gemini 3 Flash',
            'badge' => 'Recomendado',
            'summary' => 'Equilibrio fuerte para crear páginas completas con buena velocidad y coste contenido.',
            'model_light' => 'google/gemini-3.1-flash-lite',
        ],
        'google/gemini-3.5-flash' => [
            'name' => 'Gemini 3.5 Flash',
            'badge' => 'Más calidad',
            'summary' => 'Más capaz para páginas largas y contenido donde importa más la calidad final.',
            'model_light' => 'google/gemini-3.1-flash-lite',
        ],
    ];

    public function index(): void
    {
        $siteId = self::requireSiteId();
        $step = max(1, min(5, (int) Request::get('step', self::currentStep($siteId))));
        self::storeSetting($siteId, 'onboarding_current_step', (string) $step);

        $designValues = self::loadDesignValues($siteId);
        View::send('admin/onboarding/index', [
            'site' => self::site($siteId),
            'csrf' => CSRF::token(),
            'steps' => self::STEPS,
            'step' => $step,
            'memoryFields' => MemoryController::FIELDS,
            'memoryValues' => MemoryController::loadValues($siteId),
            'designValues' => $designValues,
            'paletteCards' => PalettePresets::cards((string) ($designValues['primary_color'] ?? '#ea580c')),
            'selectedPalettePreset' => PalettePresets::selectedForSite($siteId) ?? PalettePresets::defaultSlug(),
            'brandValues' => self::loadBrandValues($siteId),
            'referenceValues' => self::loadReferenceValues($siteId),
            'aiValues' => self::loadAiValues($siteId),
            'aiModels' => self::AI_MODELS,
            'swatches' => self::SWATCHES,
            'typographyOptions' => self::TYPOGRAPHY,
            'document' => self::latestDocument($siteId),
            'documents' => self::latestDocuments($siteId),
            // F22.T22.1 — intent guardado (si el usuario ya pasó por el paso 5).
            'savedIntent' => self::loadSetting($siteId, 'onboarding_intent', ''),
        ]);
    }

    public function saveStep(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $step = (int) ($params['step'] ?? 1);
        if ($step === 1) {
            self::saveMemory($siteId);
            // E-GDPR G6 — datos legales opcionales (panel desplegable).
            self::saveOptionalCompliance($siteId);
            self::storeSetting($siteId, 'onboarding_current_step', '2');
            Response::redirect(base_url('admin/onboarding?step=2'));
        }
        if ($step === 2) {
            self::saveDesign($siteId);
            self::storeSetting($siteId, 'onboarding_current_step', '3');
            Response::redirect(base_url('admin/onboarding?step=3'));
        }
        if ($step === 3) {
            self::saveAI($siteId);
            self::storeSetting($siteId, 'onboarding_current_step', '4');
            Response::redirect(base_url('admin/onboarding?step=4'));
        }
        if ($step === 4) {
            self::saveDocument($siteId);
            self::storeSetting($siteId, 'onboarding_current_step', '5');
            Response::redirect(base_url('admin/onboarding?step=5'));
        }
        Response::redirect(base_url('admin/onboarding?step=5'));
    }

    public function skip(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $step = max(1, min(5, (int) Request::post('step', 1)));
        if ($step >= 5) {
            self::complete($siteId, false);
            Session::flash('success', 'Listo. Entramos al mapa vacío.');
            Response::redirect(base_url('admin/pages'));
        }
        $next = $step + 1;
        self::storeSetting($siteId, 'onboarding_current_step', (string) $next);
        Response::redirect(base_url('admin/onboarding?step=' . $next));
    }

    public function exitToPanel(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        self::complete($siteId, true);
        Response::redirect(base_url('admin/'));
    }

    public function autofillMemory(): void
    {
        @set_time_limit(180);
        CSRF::check();
        $siteId = self::requireSiteId();
        $files = self::uploadedFiles('dossier');
        if ($files === []) {
            Response::json(['ok' => false, 'error' => 'Sube uno o varios PDF, DOCX o TXT para que la IA pueda leerlos.'], 422);
        }

        try {
            $docs = [];
            foreach ($files as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $docs[] = self::saveExtractedDocument($siteId, $file);
            }
            if ($docs === []) {
                Response::json(['ok' => false, 'error' => 'No se ha podido leer ningún documento válido.'], 422);
            }

            $result = AIActionRunner::run(Actions::EXTRACT_BUSINESS_PROFILE, [
                'document_text' => mb_substr(self::combineDocumentTexts($docs), 0, 22000),
                'field_schema' => self::memoryFieldSchema(),
            ], $siteId);
            $profile = self::normalizeBusinessProfile((array) ($result['data'] ?? []));
            Response::json([
                'ok' => true,
                'fields' => $profile['fields'],
                'company_name' => $profile['company_name'],
                'confidence' => $profile['confidence'],
                'notes' => $profile['notes'],
                'documents' => self::documentResponseList($docs),
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
            ]);
        } catch (AIException $e) {
            Response::json(['ok' => false, 'error' => 'No hemos podido interpretar los documentos con IA: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            Response::json([
                'ok' => false,
                'error' => 'No hemos podido leer los documentos: ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
            ], 422);
        }
    }

    public function uploadReferences(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $reason = null;
        $saved = self::saveReferenceImages($siteId, $reason);
        $refs = self::loadReferenceValues($siteId);
        if ($saved <= 0 && ($refs['count'] ?? 0) <= 0) {
            $message = $reason ?: 'No se pudo guardar ninguna referencia. Usa PNG, JPG o WebP de hasta 8 MB.';
            Response::json(['ok' => false, 'error' => $message], 422);
        }
        Response::json([
            'ok' => true,
            'saved' => $saved,
            'count' => (int) ($refs['count'] ?? 0),
        ]);
    }


    /**
     * D-Slice 1 (S1.13/S1.14) — Compone el skin del sitio en el momento en que
     * el usuario llega al sub-stage "estilo" del step 5. Devuelve JSON con la
     * URL de preview para iframe + datos del vector actual.
     *
     * Endpoint: POST /admin/onboarding/compose-skin
     */
    public function composeSkin(): void
    {
        // FH6 — ahora compone el skin Y genera el Inicio canvas real (1-2
        // llamadas IA + banco de imágenes), igual que `createPages`. El límite
        // por defecto de 30s se queda corto y devolvía 500.
        @set_time_limit(300);
        CSRF::check();
        $siteId = self::requireSiteId();
        try {
            $result = \App\Services\Personality\PersonalityInference::compose($siteId);
            $force = (string) Request::post('force', '') === '1';

            // FH6 — El preview del paso 5 muestra el INICIO canvas real (no un
            // demo de bloques). Lo generamos una sola vez como borrador; los
            // nudges posteriores solo recomponen tokens y recargan el iframe,
            // sin nueva llamada IA. Si falla, el preview cae al demo de bloques.
            $homeItem = self::readHomePreviewItem();
            $homeReady = false;
            try {
                $homeReady = self::ensureHomeCanvasDraft($siteId, $homeItem, $force) > 0;
            } catch (\Throwable $e) {
                error_log('[composeSkin] home canvas preview failed: ' . get_class($e) . ': ' . $e->getMessage()
                    . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }

            \App\Services\CacheService::flush($siteId);
            Response::json([
                'ok'           => true,
                'preview_url'  => base_url('admin/onboarding/skin-preview?t=' . time()),
                'home_preview' => $homeReady,
                'vector'       => $result['personality']['vector'] ?? null,
                'sources_used' => $result['sources_used'] ?? [],
            ]);
        } catch (\Throwable $e) {
            error_log('[composeSkin] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            Response::json([
                'ok'    => false,
                'error' => 'No pudimos componer tu estilo: ' . $e->getMessage(),
                'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    /**
     * D-Slice 1 (S1.14) — Ajusta un eje del vector ±0.2 y recompone el skin.
     * Sin llamada IA — solo composición.
     *
     * Endpoint: POST /admin/onboarding/nudge
     * Body: axis=warmth|modernity|energy · direction=up|down
     */
    public function nudge(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $axis      = (string) Request::post('axis', '');
        $direction = (string) Request::post('direction', '');

        try {
            $result = \App\Services\Personality\PersonalityInference::applyNudge($siteId, $axis, $direction);
            \App\Services\CacheService::flush($siteId);
            Response::json([
                'ok'           => true,
                'preview_url'  => base_url('admin/onboarding/skin-preview?t=' . time()),
                'axis'         => $result['axis'],
                'direction'    => $result['direction'],
                'value_before' => $result['value_before'],
                'value_after'  => $result['value_after'],
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[nudge] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            Response::json(['ok' => false, 'error' => 'No pudimos aplicar el ajuste: ' . $e->getMessage()], 500);
        }
    }

    /**
     * D-Slice 1 (S1.13) — Renderiza una mini home demo con el skin actual del
     * sitio (vía `DesignSystem::renderHead` que ya lee `sites.skin_json`).
     * Se sirve dentro de un iframe en el step 5 reconvertido.
     *
     * Endpoint: GET /admin/onboarding/skin-preview
     */
    public function skinPreview(): void
    {
        $siteId = self::requireSiteId();
        $data = DashboardController::getCommonData();
        View::send('admin/onboarding/skin-preview', array_merge($data, [
            'siteId' => $siteId,
            'referencePreviewContent' => self::loadReferencePreviewContent($siteId),
            // FH6 — HTML del Inicio canvas real (si existe borrador); la vista
            // lo pinta con el skin vivo. Vacío → cae al demo de bloques.
            'homeCanvasHtml' => self::resolveHomeCanvasPreview($siteId),
        ]));
    }

    /**
     * FH6 — Genera el borrador del Inicio canvas en SEGUNDO PLANO mientras el
     * usuario todavía elige páginas en el paso de arquitectura. Idempotente:
     * si ya existe borrador, no regenera. El paso 5 (compose-skin) lo reutiliza.
     *
     * Endpoint: POST /admin/onboarding/prepare-home
     */
    public function prepareHome(): void
    {
        @set_time_limit(300);
        CSRF::check();
        $siteId = self::requireSiteId();
        try {
            $pageId = self::ensureHomeCanvasDraft($siteId, self::readHomePreviewItem(), false);
            Response::json(['ok' => true, 'ready' => $pageId > 0]);
        } catch (\Throwable $e) {
            error_log('[prepareHome] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            // Best-effort: el paso 5 tiene su propio fallback (genera ahí o demo).
            Response::json(['ok' => true, 'ready' => false, 'error_note' => $e->getMessage()]);
        }
    }

    /**
     * FH6 — Lee el item de "Inicio" que el front envía al entrar al paso 5,
     * para generar un preview con su título/objetivo reales. Tolera ausencia.
     *
     * @return array<string,mixed>
     */
    private static function readHomePreviewItem(): array
    {
        $raw = Request::post('home_page', '');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        if (is_array($raw)) return $raw;
        return [];
    }

    /**
     * FH6 — Garantiza que existe un borrador del Inicio canvas para el preview
     * del paso 5. Lo genera una sola vez (reutilizable en create-pages); con
     * $force=true lo regenera desde cero. Devuelve el page_id o 0.
     *
     * @param array<string,mixed> $homeItem
     */
    private static function ensureHomeCanvasDraft(int $siteId, array $homeItem, bool $force): int
    {
        $draft = json_decode(self::loadSetting($siteId, 'onboarding_home_draft', ''), true);
        $existingId = is_array($draft) ? (int) ($draft['page_id'] ?? 0) : 0;
        if ($existingId > 0 && !self::draftIsCanvas($existingId, $siteId)) $existingId = 0;
        if ($existingId > 0 && !$force) return $existingId;
        if ($existingId > 0 && $force) {
            self::deleteDraftPage($existingId);
        }

        $title = mb_substr(trim((string) ($homeItem['title'] ?? '')), 0, 160);
        if ($title === '') $title = 'Inicio';
        $goal = trim((string) ($homeItem['goal'] ?? $homeItem['reason'] ?? 'Presentar el negocio y dirigir a la acción principal.'));
        $context = trim((string) ($homeItem['architecture_context'] ?? $homeItem['reason'] ?? ''));
        $referenceImages = self::loadReferenceImagesForVision($siteId);

        $created = self::createReferenceCanvasPage(
            $siteId,
            $homeItem !== [] ? $homeItem : ['reason' => $goal],
            $title,
            'home',
            $goal,
            $context,
            0,
            $referenceImages
        );
        $pageId = (int) ($created['id'] ?? 0);
        if ($pageId > 0) {
            self::storeSetting($siteId, 'onboarding_home_draft', json_encode(
                ['page_id' => $pageId, 'title' => $title],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }
        return $pageId;
    }

    /** FH6 — HTML renderizado del Inicio canvas borrador, o '' si no hay. */
    private static function resolveHomeCanvasPreview(int $siteId): string
    {
        $draft = json_decode(self::loadSetting($siteId, 'onboarding_home_draft', ''), true);
        $pageId = is_array($draft) ? (int) ($draft['page_id'] ?? 0) : 0;
        if ($pageId <= 0 || !self::draftIsCanvas($pageId, $siteId)) return '';
        $rendered = \App\Services\Canvas\CanvasService::renderPublic($pageId, $siteId);
        return (string) ($rendered['html'] ?? '');
    }

    /** FH6 — ¿El page_id es una página canvas del sitio? */
    private static function draftIsCanvas(int $pageId, int $siteId): bool
    {
        if ($pageId <= 0) return false;
        $row = Database::selectOne(
            'SELECT id FROM pages WHERE id = ? AND site_id = ? AND render_mode = "canvas"',
            [$pageId, $siteId]
        );
        return $row !== null;
    }

    /** FH6 — Elimina un borrador canvas y sus filas asociadas. */
    private static function deleteDraftPage(int $pageId): void
    {
        if ($pageId <= 0) return;
        Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
        Database::execute('DELETE FROM page_versions WHERE page_id = ?', [$pageId]);
        Database::execute('DELETE FROM page_sections WHERE page_id = ?', [$pageId]);
        Database::execute('DELETE FROM pages WHERE id = ? AND status = "draft"', [$pageId]);
    }

    /**
     * FH6 — Contexto anti-clonación de heros: si esta página NO es la home y
     * ya existe una home canvas en el sitio, pasamos su hero al prompt con la
     * directiva de diseñar uno claramente distinto (más contenido, otra
     * composición). Mantiene coherencia de marca pero evita que todas las
     * páginas parezcan la misma al navegar.
     */
    private static function heroDifferentiationContext(int $siteId, string $type): string
    {
        if ($type === 'home') return '';
        $home = Database::selectOne(
            'SELECT id FROM pages WHERE site_id = ? AND page_type = "home" AND render_mode = "canvas" ORDER BY id DESC LIMIT 1',
            [$siteId]
        );
        if ($home === null) return '';
        $canvas = \App\Services\Canvas\CanvasService::get((int) $home['id']);
        if ($canvas === null) return '';
        $sections = \App\Services\Canvas\CanvasService::listSections($canvas['html']);
        $firstSlug = (string) ($sections[0]['id'] ?? '');
        $hero = $firstSlug !== ''
            ? \App\Services\Canvas\CanvasService::extractSection($canvas['html'], $firstSlug)
            : null;
        if ($hero === null || trim($hero) === '') return '';
        $hero = mb_substr(trim(preg_replace('/\s+/', ' ', $hero) ?? $hero), 0, 1500);

        return "\nHERO DE LA HOME DEL SITIO (ya publicado — NO lo repitas):\n```html\n{$hero}\n```\n"
             . "Esta es una página INTERIOR: su hero debe ser CLARAMENTE distinto al de la home en composición y normalmente más contenido en altura (banner compacto, split asimétrico, alineación diferente, sin foto o con tratamiento distinto…). "
             . "Mismo lenguaje de marca (tokens, tipografías, aire), pero que al navegar se note que es OTRA página, no un clon de la portada.";
    }

    /** FH6 — Nº de secciones canvas (data-pp-section) de un borrador. */
    private static function canvasSectionCount(int $pageId): int
    {
        $canvas = \App\Services\Canvas\CanvasService::get($pageId);
        if ($canvas === null) return 1;
        return max(1, substr_count($canvas['html'], 'data-pp-section'));
    }

    public function analyze(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        // F22.T22.2 — Aceptar y persistir el intent del usuario.
        $intent = self::normalizeIntent((string) Request::post('intent', ''));
        if ($intent !== '') {
            self::storeSetting($siteId, 'onboarding_intent', $intent);
        }

        try {
            $result = AIActionRunner::run(Actions::ANALYZE_SITE_ARCHITECTURE, [
                'site_map_context' => self::siteMapContext($siteId),
                'intent_directive' => self::intentDirective($intent),
            ], $siteId);
            Response::json([
                'ok' => true,
                'cached' => false,
                'intent' => $intent,
                'architecture' => self::enrichWithTemplates(self::normalizeArchitecture((array) ($result['data'] ?? []), $intent)),
                'model' => $result['model'] ?? '',
                'tokens_in' => $result['tokens_in'] ?? 0,
                'tokens_out' => $result['tokens_out'] ?? 0,
                'estimated_cost' => $result['estimated_cost'] ?? 0,
            ]);
        } catch (AIException $e) {
            Response::json([
                'ok' => true,
                'fallback' => true,
                'intent' => $intent,
                'architecture' => self::enrichWithTemplates(self::fallbackArchitecture($intent)),
                'error_note' => $e->getMessage(),
            ]);
        }
    }

    /** F22 — Valida intent contra los slugs conocidos. */
    private static function normalizeIntent(string $raw): string
    {
        $allowed = ['presence', 'services', 'seo', 'portfolio', 'product'];
        $raw = trim($raw);
        return in_array($raw, $allowed, true) ? $raw : '';
    }

    /**
     * F22.T22.2 — Devuelve la directiva concreta que se inyecta en el prompt
     * `ANALYZE_SITE_ARCHITECTURE`. Sesga el tipo, cantidad y prioridad de
     * páginas según el objetivo elegido por el usuario.
     */
    private static function intentDirective(string $intent): string
    {
        return match ($intent) {
            'presence' =>
                "OBJETIVO DEL USUARIO: Presencia online mínima.\n"
              . "- Proponer SOLO 2-3 páginas: Inicio (home) y Contacto (contact). Una tercera opcional (about/landing).\n"
              . "- NO incluir blog ni catálogo.\n"
              . "- Marca todas como priority=high (son lo imprescindible).",

            'services' =>
                "OBJETIVO DEL USUARIO: Captar clientes para servicios.\n"
              . "- Proponer 4-5 páginas: Inicio (home), Servicios (service), Sobre nosotros (landing/about), Contacto (contact).\n"
              . "- Si los servicios son varios y diferenciados, puedes proponer hijas bajo Servicios.\n"
              . "- NO incluir blog salvo que sea muy obvio que aporta. Prioriza estructura de conversión.",

            'seo' =>
                "OBJETIVO DEL USUARIO: Atraer tráfico orgánico (SEO).\n"
              . "- Proponer estructura base completa: Inicio, Servicios, Sobre nosotros, Contacto.\n"
              . "- OBLIGATORIO: incluir Blog (page_type=article) como página de alta prioridad.\n"
              . "- El blog es central, no opcional. En `reason` del blog explica el rol SEO.\n"
              . "- Opcional: una página de Casos/Recursos como hub editorial.",

            'portfolio' =>
                "OBJETIVO DEL USUARIO: Mostrar el trabajo / portfolio.\n"
              . "- Proponer 3-4 páginas: Inicio (home) con galería destacada, Portfolio (landing) con casos, Sobre mí (landing), Contacto.\n"
              . "- Prioriza la visualidad: Portfolio debe ser high priority.\n"
              . "- NO incluir blog ni precios.",

            'product' =>
                "OBJETIVO DEL USUARIO: Lanzar un producto/landing.\n"
              . "- Proponer estructura corta de conversión: Landing principal (landing, home_page=true si solo hay una), Precios (landing) si aplica, Contacto.\n"
              . "- Foco en conversión. Sin blog, sin sobre nosotros largo.\n"
              . "- Marca todo como priority=high.",

            default =>
                "OBJETIVO DEL USUARIO: No especificado.\n"
              . "Propón una estructura equilibrada y razonable para el negocio descrito en la memoria.",
        };
    }

    /**
     * T18.8 — Para cada página propuesta, añade un campo `suggested_templates`
     * con hasta 3 plantillas que encajan (sin coste IA extra: heurística pura).
     * El front renderiza chips de selección y permite preview por click.
     */
    private static function enrichWithTemplates(array $architecture): array
    {
        $all = PageTemplateService::all();
        if (empty($all)) return $architecture;

        $pages = $architecture['missing_pages'] ?? [];
        foreach ($pages as &$p) {
            if (!is_array($p)) continue;
            $slugs = PageTemplateService::suggestForPage(
                (string) ($p['page_type'] ?? 'landing'),
                (string) ($p['title'] ?? ''),
                (string) ($p['reason'] ?? $p['goal'] ?? ''),
                3
            );
            $tpls = [];
            foreach ($slugs as $slug) {
                if (!isset($all[$slug])) continue;
                $tpls[] = [
                    'slug'        => $slug,
                    'label'       => (string) ($all[$slug]['label'] ?? $slug),
                    'description' => (string) ($all[$slug]['description'] ?? ''),
                    'preview_url' => base_url('admin/pages/ai/templates/' . $slug . '/preview'),
                ];
            }
            $p['suggested_templates'] = $tpls;
            $p['default_template']    = $tpls[0]['slug'] ?? '';
        }
        unset($p);
        $architecture['missing_pages'] = $pages;
        $architecture['visual_styles'] = VisualStyleService::cardsForSite(self::requireSiteId());
        return $architecture;
    }

    public function createPages(): void
    {
        @set_time_limit(300);
        CSRF::check();
        $siteId = self::requireSiteId();
        $payload = Request::isJson() ? Request::json() : [];
        $items = $payload['pages'] ?? Request::post('pages', []);
        $complete = (bool) ($payload['complete'] ?? true);
        if (!empty($payload['finish_only'])) {
            self::complete($siteId, false);
            Session::flash('success', '¡Listo! Hemos creado tus borradores. Revísalos y publica cuando quieras.');
            Response::json([
                'ok' => true,
                'created' => [],
                'failed' => [],
                'requested' => 0,
                'redirect_url' => base_url('admin/pages'),
            ]);
        }
        if (!is_array($items) || $items === []) {
            Response::json(['ok' => false, 'error' => 'Elige al menos una página.'], 422);
        }

        // D-Slice 1 (S1.8) — Trigger 2: justo antes de generar páginas,
        // inferir personalidad y componer skin. Política R1 híbrida: solo
        // se aplica si el usuario NO tocó el diseño manualmente en step 2.
        // Aislado en try/catch para no romper la creación de páginas si falla.
        try {
            $origin = self::loadSetting($siteId, 'design_choice_origin', 'defaults');
            if ($origin !== 'manual') {
                \App\Services\Personality\PersonalityInference::compose($siteId);
                \App\Services\CacheService::flush($siteId);
            }
        } catch (\Throwable $e) {
            error_log('Trigger 2 PersonalityInference failed: ' . $e->getMessage());
        }

        // FH6 — Si el paso 5 ya generó el Inicio canvas como borrador, lo
        // reutilizamos en vez de regenerarlo (ahorra una compose IA y respeta
        // los nudges aplicados). El borrador ya está creado y persistido.
        $homeDraft = json_decode(self::loadSetting($siteId, 'onboarding_home_draft', ''), true);
        $homeDraftId = is_array($homeDraft) ? (int) ($homeDraft['page_id'] ?? 0) : 0;
        if ($homeDraftId > 0 && !self::draftIsCanvas($homeDraftId, $siteId)) $homeDraftId = 0;
        $homeReused = false;

        $created = [];
        $failed = [];
        foreach (array_slice($items, 0, 6) as $item) {
            if (!is_array($item)) continue;
            @set_time_limit(300);

            $isHome = (($item['page_type'] ?? '') === 'home')
                || mb_strtolower(trim((string) ($item['title'] ?? ''))) === 'inicio';
            if ($isHome && $homeDraftId > 0 && !$homeReused) {
                $homeReused = true;
                $created[] = [
                    'id'             => $homeDraftId,
                    'title'          => (string) ($item['title'] ?? 'Inicio'),
                    'edit_url'       => base_url('admin/pages/' . $homeDraftId . '/edit'),
                    'sections_count' => self::canvasSectionCount($homeDraftId),
                    'template'       => 'canvas-reference',
                    'reused'         => true,
                ];
                continue;
            }

            try {
                $created[] = self::createAiPage($siteId, $item);
            } catch (\Throwable $e) {
                $failed[] = [
                    'title' => (string) ($item['title'] ?? 'Página'),
                    'error' => $e->getMessage(),
                ];
            }
        }

        // El borrador del Inicio ya cumplió su función: limpiamos el puntero
        // y, si el usuario finalmente NO seleccionó el Inicio, eliminamos el
        // borrador huérfano para no dejar páginas fantasma.
        if ($homeDraftId > 0 && !$homeReused) {
            self::deleteDraftPage($homeDraftId);
        }
        self::storeSetting($siteId, 'onboarding_home_draft', '');

        // F22.T22.3 — Si el intent del usuario es SEO orgánico y han creado el
        // Blog, generamos automáticamente 3 entradas iniciales de partida para
        // que el blog no nazca vacío. Best-effort: si falla, no rompe el flujo.
        $seoPostsCreated = [];
        $intent = self::loadSetting($siteId, 'onboarding_intent', '');
        $createdBlog = false;
        foreach ($items as $item) {
            $title = mb_strtolower((string) ($item['title'] ?? ''));
            if (str_contains($title, 'blog') || ($item['page_type'] ?? '') === 'article') {
                $createdBlog = true;
                break;
            }
        }
        if ($intent === 'seo' && $createdBlog && empty($failed)) {
            $seoPostsCreated = self::generateSeoStarterPosts($siteId, 3);
        }

        if ($complete) {
            self::complete($siteId, false);
            $msg = '¡Listo! Te hemos creado ' . count($created) . ' borradores.';
            if (!empty($seoPostsCreated)) {
                $msg .= ' Además, hemos preparado ' . count($seoPostsCreated) . ' entradas iniciales en tu blog.';
            }
            $msg .= ' Revísalos y publica cuando quieras.';
            Session::flash('success', $msg);
        }
        Response::json([
            'ok' => true,
            'created' => $created,
            'failed' => $failed,
            'seo_posts' => $seoPostsCreated,
            'requested' => count($items),
            'redirect_url' => base_url('admin/pages'),
        ]);
    }

    /**
     * F22.T22.3 — Genera N entradas iniciales para sitios con intent SEO.
     * Best-effort: ningún fallo bloquea el cierre del onboarding.
     *
     * @return array<int,array{title:string, edit_url:string}>
     */
    private static function generateSeoStarterPosts(int $siteId, int $count = 3): array
    {
        // 1) Pedir N ideas con la acción que ya tenemos (tier light, ~1$/1k).
        try {
            $sugg = AIActionRunner::run(Actions::SUGGEST_RELATED_ARTICLES, [
                'existing_count' => '0',
                'existing_posts' => '(El blog está vacío. Propón pilares fundamentales del tema del negocio, con título concreto y específico.)',
                'count'          => (string) $count,
            ], $siteId);
        } catch (\Throwable $e) {
            error_log('[generateSeoStarterPosts] suggest error: ' . $e->getMessage());
            return [];
        }

        $suggestions = (array) (($sugg['data']['suggestions'] ?? []) ?: []);
        if (empty($suggestions)) return [];

        // 2) Por cada idea, llamar a GENERATE_ARTICLE y crear la entrada.
        $created = [];
        foreach (array_slice($suggestions, 0, $count) as $s) {
            @set_time_limit(180);
            $title = trim((string) ($s['title'] ?? ''));
            if ($title === '') continue;

            try {
                $articleResult = AIActionRunner::run(Actions::GENERATE_ARTICLE, [
                    'topic'         => $title,
                    'audience'      => (string) ($s['audience'] ?? 'lector general'),
                    'tone'          => 'profesional y cercano',
                    'length_label'  => 'medio',
                    'details'       => (string) ($s['angle'] ?? ''),
                ], $siteId);
                $articleData = (array) ($articleResult['data'] ?? []);

                $page = \App\Controllers\Admin\PostController::createPostFromAiPayload(
                    $siteId,
                    Auth::id(),
                    $articleData,
                    /* withFeaturedImage */ true
                );
                if ($page) {
                    $created[] = [
                        'title'    => (string) $page['title'],
                        'edit_url' => base_url('admin/posts/' . (int) $page['id'] . '/edit'),
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[generateSeoStarterPosts] generate article error: ' . $e->getMessage());
                continue;
            }
        }
        return $created;
    }

    /**
     * E-GDPR G6 — Guarda los 4 campos legales opcionales del paso 1 del onboarding.
     * Solo persiste los que el usuario haya rellenado; ignora el resto.
     */
    private static function saveOptionalCompliance(int $siteId): void
    {
        $legalName = trim((string) Request::post('legal_name', ''));
        $taxId     = trim((string) Request::post('legal_tax_id', ''));
        $address   = trim((string) Request::post('legal_address', ''));
        $email     = trim((string) Request::post('legal_email', ''));

        if ($legalName === '' && $taxId === '' && $address === '' && $email === '') {
            return; // El usuario no abrió el panel: no tocar el manifest.
        }

        $controller = [];
        if ($legalName !== '') $controller['legal_name'] = $legalName;
        if ($taxId !== '')     $controller['tax_id']     = $taxId;
        if ($address !== '')   $controller['address']    = $address;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $controller['email'] = $email;

        if (!empty($controller)) {
            try {
                \App\Services\Compliance\ComplianceService::patch($siteId, ['controller' => $controller]);
            } catch (\Throwable $e) {
                // graceful: el onboarding no debe romperse si compliance falla
            }
        }
    }

    private static function saveMemory(int $siteId): void
    {
        foreach (MemoryController::FIELDS as $key => $def) {
            $raw = trim((string) Request::post($key, ''));
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            if (($def['type'] ?? '') === 'select' && $raw !== '' && !isset($def['options'][$raw])) {
                $raw = '';
            }
            $raw = mb_substr($raw, 0, 5000);
            if ($raw === '') {
                Database::execute('DELETE FROM site_memory WHERE site_id = ? AND field_key = ?', [$siteId, $key]);
            } else {
                Database::execute(
                    'INSERT INTO site_memory (site_id, field_key, field_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)',
                    [$siteId, $key, $raw]
                );
            }
        }
    }

    private static function saveDesign(int $siteId): void
    {
        $siteName = trim((string) Request::post('site_name', ''));
        if ($siteName !== '') {
            Database::execute(
                'UPDATE sites SET name = ?, updated_at = NOW() WHERE id = ?',
                [mb_substr($siteName, 0, 255), $siteId]
            );
        }

        self::saveLogo($siteId);
        self::saveReferenceImages($siteId);

        $primaryRaw = (string) Request::post('primary_color_hex', Request::post('primary_color_custom', Request::post('primary_color', '#ea580c')));
        $secondaryRaw = (string) Request::post('secondary_color_hex', Request::post('secondary_color_custom', Request::post('secondary_color', '#1c1917')));
        $primary = self::color($primaryRaw, '#ea580c');
        $secondary = self::color($secondaryRaw, '#1c1917');
        $pair = (string) Request::post('typography_pair', 'Inter / Inter');
        $radius = (string) Request::post('border_radius', '8');
        $radiusPx = max(0, min(60, (int) $radius));
        $fonts = self::TYPOGRAPHY[$pair] ?? self::TYPOGRAPHY['Inter / Inter'];

        $tokens = DesignSystem::load($siteId);
        $tokens['colors']['primary'] = $primary;
        $tokens['colors']['primary_dark'] = $primary;
        $tokens['colors']['secondary'] = $secondary;
        $tokens['colors']['text'] = $secondary;
        $tokens['typography']['font_heading'] = $fonts['heading'];
        $tokens['typography']['font_body'] = $fonts['body'];
        $tokens['buttons']['radius'] = $radiusPx;
        $tokens['spacing']['radius_card'] = $radiusPx;

        foreach (['colors', 'typography', 'buttons', 'spacing'] as $cat) {
            DesignSystem::saveCategory($siteId, $cat, $tokens[$cat]);
        }
        $preset = (string) Request::post('palette_preset', PalettePresets::defaultSlug());
        PalettePresets::saveSelectedForSite($siteId, $preset);

        // D-Slice 1 — Decisión 3 (2026-05-18): el step 2 NO marca `manual`.
        // Sus inputs (color, fuente, radius, paleta) entran como anclas del
        // SkinComposer en Trigger 2. La marca `design_choice_origin = 'manual'`
        // sólo se setea cuando el usuario edita explícitamente algo en
        // `/admin/design` después de crear el sitio.

        CacheService::flush($siteId);
    }

    private static function saveAI(int $siteId): void
    {
        $mode = (string) Request::post('ai_model_choice', 'google/gemini-3-flash-preview');
        $advanced = $mode === 'advanced';
        $main = $advanced
            ? trim((string) Request::post('ai_model_advanced', ''))
            : $mode;
        if ($main === '' || mb_strlen($main) > 100) {
            $main = 'google/gemini-3-flash-preview';
        }

        $light = $advanced
            ? trim((string) Request::post('ai_model_light_advanced', ''))
            : (self::AI_MODELS[$main]['model_light'] ?? 'google/gemini-3.1-flash-lite');
        if ($light !== '' && mb_strlen($light) > 100) {
            $light = 'google/gemini-3.1-flash-lite';
        }

        self::storeSetting($siteId, 'ai_provider', 'openrouter');
        self::storeSetting($siteId, 'ai_model', $main);
        self::storeSetting($siteId, 'ai_model_light', $light);
    }

    private static function saveDocument(int $siteId): void
    {
        $files = self::uploadedFiles('files');
        if ($files === []) {
            $single = Request::file('file');
            if (is_array($single)) $files = [$single];
        }

        foreach ($files as $file) {
            self::saveOneDocument($siteId, $file);
        }
    }

    private static function saveOneDocument(int $siteId, array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
        $type = self::detectFileType($file);
        if ($type === null || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) return;

        $dir = PP_ROOT . '/storage/documents/' . $siteId;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = bin2hex(random_bytes(16)) . '.' . $type;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) return;
        $rel = 'storage/documents/' . $siteId . '/' . $name;
        $title = mb_substr(pathinfo((string) $file['name'], PATHINFO_FILENAME), 0, 255);

        Database::execute(
            'INSERT INTO documents (site_id, title, original_filename, file_type, file_path, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, "processing", ?)',
            [$siteId, $title ?: 'Documento base', (string) $file['name'], $type, $rel, Auth::id()]
        );
        $docId = (int) Database::lastInsertId();

        register_shutdown_function(static function () use ($dest, $type, $docId): void {
            @set_time_limit(120);
            try {
                $text = TextExtractor::extract($dest, $type);
                $summary = DocumentSummarizer::summarize($text);
                Database::execute(
                    'UPDATE documents SET extracted_text = ?, summary = ?, status = "ready" WHERE id = ?',
                    [$text, $summary, $docId]
                );
            } catch (\Throwable $e) {
                Database::execute('UPDATE documents SET status = "error" WHERE id = ?', [$docId]);
                error_log('[Onboarding] Documento fallido: ' . $e->getMessage());
            }
        });
    }

    /** @return array<int,array<string,mixed>> */
    private static function uploadedFiles(string $key): array
    {
        $raw = Request::file($key);
        if (!is_array($raw)) return [];

        $names = $raw['name'] ?? null;
        if (!is_array($names)) {
            return (($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ? [] : [$raw];
        }

        $files = [];
        foreach ($names as $i => $name) {
            $files[] = [
                'name' => $name,
                'type' => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }
        return $files;
    }

    /** @return array{id:int,title:string,text:string,summary:string} */
    private static function saveExtractedDocument(int $siteId, array $file): array
    {
        $type = self::detectFileType($file);
        if ($type === null) {
            throw new \RuntimeException('Formato no soportado. Usa PDF, DOCX o TXT.');
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new \RuntimeException('El archivo supera 10 MB.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('La subida no se ha completado correctamente.');
        }

        $dir = PP_ROOT . '/storage/documents/' . $siteId;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = bin2hex(random_bytes(16)) . '.' . $type;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('No se pudo guardar el archivo.');
        }

        $text = TextExtractor::extract($dest, $type);
        if (trim($text) === '') {
            throw new \RuntimeException('No hemos encontrado texto legible en el documento.');
        }
        $summary = DocumentSummarizer::summarize($text);
        $rel = 'storage/documents/' . $siteId . '/' . $name;
        $title = mb_substr(pathinfo((string) $file['name'], PATHINFO_FILENAME), 0, 255) ?: 'Dossier comercial';

        Database::execute(
            'INSERT INTO documents (site_id, title, original_filename, file_type, file_path, extracted_text, summary, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, "ready", ?)',
            [$siteId, $title, (string) $file['name'], $type, $rel, $text, $summary, Auth::id()]
        );

        return [
            'id' => (int) Database::lastInsertId(),
            'title' => $title,
            'text' => $text,
            'summary' => $summary,
        ];
    }

    /** @param array<int,array{id:int,title:string,text:string,summary:string}> $docs */
    private static function documentResponseList(array $docs): array
    {
        $out = [];
        foreach ($docs as $doc) {
            $out[] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
            ];
        }
        return $out;
    }

    /** @param array<int,array{id:int,title:string,text:string,summary:string}> $docs */
    private static function combineDocumentTexts(array $docs): string
    {
        $chunks = [];
        foreach ($docs as $i => $doc) {
            $chunks[] = "Documento " . ($i + 1) . ': ' . $doc['title']
                . "\n---\n"
                . mb_substr((string) $doc['text'], 0, 9000)
                . "\n---";
        }
        return implode("\n\n", $chunks);
    }

    private static function saveLogo(int $siteId): void
    {
        $file = Request::file('logo');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return;
        if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 2 * 1024 * 1024) return;
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) return;

        $original = (string) ($file['name'] ?? 'logo');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];
        if (!isset($allowed[$ext])) return;

        $mime = (string) (mime_content_type((string) $file['tmp_name']) ?: $allowed[$ext]);
        if ($ext !== 'svg' && !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return;
        if ($ext === 'svg' && !in_array($mime, ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'], true)) return;

        $dir = PP_ROOT . '/storage/uploads/' . $siteId . '/brand';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) return;

        $rel = 'storage/uploads/' . $siteId . '/brand/' . $filename;
        $width = null;
        $height = null;
        if ($ext !== 'svg') {
            $size = @getimagesize($dest);
            if (is_array($size)) {
                $width = (int) ($size[0] ?? 0) ?: null;
                $height = (int) ($size[1] ?? 0) ?: null;
            }
        }

        Database::execute(
            'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$siteId, $filename, mb_substr($original, 0, 255), $allowed[$ext], (int) $file['size'], $rel, 'Logo', $width, $height, Auth::id()]
        );
        self::storeSetting($siteId, 'site_logo_path', $rel);
        self::storeSetting($siteId, 'site_logo_media_id', Database::lastInsertId());
    }

    private static function saveReferenceImages(int $siteId, ?string &$reason = null): int
    {
        $reason = null;

        // Si el POST excede `post_max_size`, PHP vacía $_POST y $_FILES. Lo
        // detectamos para dar un mensaje útil en vez de un "no se guardó nada".
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && empty($_FILES['visual_references'])
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $postMax = self::iniBytes((string) ini_get('post_max_size'));
            if ($postMax > 0 && (int) $_SERVER['CONTENT_LENGTH'] > $postMax) {
                $reason = 'Las imágenes pesan demasiado en conjunto (límite del servidor: '
                    . self::formatBytes($postMax) . '). Sube menos referencias o más ligeras.';
                return 0;
            }
        }

        $files = $_FILES['visual_references'] ?? null;
        if (!is_array($files) || !isset($files['tmp_name'])) return 0;

        $tmpNames = (array) $files['tmp_name'];
        $errors = (array) ($files['error'] ?? []);
        $sizes = (array) ($files['size'] ?? []);
        $names = (array) ($files['name'] ?? []);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $maxBytes = 8 * 1024 * 1024;
        $maxCount = 4;
        $saved = [];
        $count = 0;

        foreach ($tmpNames as $i => $tmp) {
            $err = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            // El archivo superó upload_max_filesize (php.ini) o MAX_FILE_SIZE (form).
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $iniMax = self::iniBytes((string) ini_get('upload_max_filesize'));
                $reason = '«' . mb_substr((string) ($names[$i] ?? 'la imagen'), 0, 80)
                    . '» supera el límite del servidor por archivo'
                    . ($iniMax > 0 ? ' (' . self::formatBytes($iniMax) . ')' : '') . '.';
                error_log('[saveReferenceImages] UPLOAD_ERR_INI_SIZE site=' . $siteId
                    . ' file=' . ($names[$i] ?? '') . ' upload_max_filesize=' . ini_get('upload_max_filesize'));
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) continue;
            if (++$count > $maxCount) break;
            if (($sizes[$i] ?? 0) <= 0 || ($sizes[$i] ?? 0) > $maxBytes) {
                $reason = '«' . mb_substr((string) ($names[$i] ?? 'la imagen'), 0, 80) . '» supera los 8 MB.';
                continue;
            }
            if (!is_uploaded_file((string) $tmp)) continue;

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file((string) $tmp);
            if (!isset($allowed[$mime])) {
                $reason = '«' . mb_substr((string) ($names[$i] ?? 'la imagen'), 0, 80) . '» no es PNG, JPG ni WebP.';
                continue;
            }

            $dir = PP_ROOT . '/storage/uploads/' . $siteId . '/references';
            if (!is_dir($dir)) mkdir($dir, 0775, true);

            $filename = 'reference-' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
            $dest = $dir . '/' . $filename;
            if (!move_uploaded_file((string) $tmp, $dest)) continue;

            $rel = 'storage/uploads/' . $siteId . '/references/' . $filename;
            $saved[] = [
                'path' => $rel,
                'original_name' => mb_substr((string) ($names[$i] ?? $filename), 0, 255),
                'mime' => $mime,
                'size' => (int) ($sizes[$i] ?? 0),
                'created_at' => date('c'),
            ];
        }

        if ($saved === []) return 0;

        $previous = self::loadReferenceValues($siteId);
        foreach (($previous['items'] ?? []) as $item) {
            $path = PP_ROOT . '/' . ltrim((string) ($item['path'] ?? ''), '/');
            if (is_file($path)) @unlink($path);
        }

        self::storeSetting($siteId, 'onboarding_visual_references', json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'onboarding_reference_preview_content']);
        return count($saved);
    }

    /** Convierte un valor de ini estilo "8M"/"512K"/"1G" a bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        switch ($unit) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    /** Formatea bytes a una etiqueta legible (MB/KB). */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }

    private static function createAiPage(int $siteId, array $item): array
    {
        $title = mb_substr(trim((string) ($item['title'] ?? 'Nueva página')), 0, 160);
        $type = (string) ($item['page_type'] ?? 'landing');
        if (!isset(PageController::PAGE_TYPES[$type])) $type = 'landing';
        $goal = trim((string) ($item['goal'] ?? $item['reason'] ?? 'Crear una página útil para este sitio.'));
        $context = trim((string) ($item['architecture_context'] ?? $item['reason'] ?? ''));
        $parentId = self::resolveParentIdForPage($siteId, $item, $type, $title);
        $visualStyle = trim((string) ($item['visual_style'] ?? ''));
        if ($visualStyle !== '') {
            VisualStyleService::saveSelectedForSite($siteId, $visualStyle);
            CacheService::flush($siteId);
        }

        $referenceImages = self::loadReferenceImagesForVision($siteId);
        $templateSlug = trim((string) ($item['template_slug'] ?? ''));

        // FH2/FH6 — CANVAS es el camino por defecto para páginas de marketing,
        // CON o SIN referencias visuales. Excepciones: artículos (editorial) y
        // plantilla clásica elegida explícitamente por el usuario. Si canvas
        // falla, degradamos: con referencias → bloques PP-friendly; sin ellas
        // → flujo clásico de secciones (más abajo).
        if ($type !== 'article' && $templateSlug === '') {
            try {
                return self::createReferenceCanvasPage($siteId, $item, $title, $type, $goal, $context, $parentId, $referenceImages);
            } catch (\Throwable $e) {
                error_log('[createAiPage] canvas falló, fallback: ' . get_class($e) . ': ' . $e->getMessage());
                if ($referenceImages !== []) {
                    return self::createReferenceAiPage($siteId, $item, $title, $type, $goal, $context, $parentId, $referenceImages);
                }
                // sin referencias: continúa hacia el flujo clásico de secciones
            }
        }

        // T18.8 — si el front eligió una plantilla, delegamos al flujo de plantillas
        // (mismo path que "/admin/pages > Crear desde plantilla"). Aplica variantes,
        // descarga imágenes del banco y guarda con `style.variant` correcto.
        if ($templateSlug !== '' && PageTemplateService::get($templateSlug) !== null) {
            $result = PageController::generatePageFromTemplate(
                $siteId,
                Auth::id(),
                $templateSlug,
                $title,
                $goal,
                /* audience */ '',
                /* details  */ $context,
                /* parentId */ $parentId
            );
            return [
                'id'             => $result['page_id'],
                'title'          => $title,
                'edit_url'       => $result['edit_url'],
                'sections_count' => $result['sections_count'],
                'images_applied' => $result['images_applied'],
                'template'       => $templateSlug,
            ];
        }

        // Fallback: flujo libre (sin plantilla) — comportamiento histórico.
        $structureResult = AIActionRunner::run(Actions::GENERATE_PAGE_STRUCTURE, [
            'page_title' => $title,
            'page_goal' => $goal,
            'extra_context' => $context,
        ], $siteId);
        $structure = (array) ($structureResult['data']['sections'] ?? []);
        if ($structure === []) throw new \RuntimeException('La IA no propuso secciones válidas.');

        $sections = [];
        foreach (array_slice($structure, 0, 6) as $index => $section) {
            if (!is_array($section)) continue;
            $sectionType = (string) ($section['type'] ?? '');
            if (!isset(SectionController::SECTION_TYPES[$sectionType])) continue;
            $result = AIActionRunner::run(Actions::GENERATE_SECTION, [
                'section_type' => $sectionType,
                'page_title' => $title,
                'extra_context' => "Objetivo de la página: {$goal}\nRol de esta sección: " . (string) ($section['rationale'] ?? '') . "\n{$context}",
            ], $siteId);
            $sections[] = [
                'type' => $sectionType,
                'content' => self::filterSectionContent($sectionType, (array) ($result['data'] ?? [])),
            ];
        }
        if ($sections === []) throw new \RuntimeException('No se pudo generar contenido de secciones.');

        // D-Slice 2 — LayoutSelector elige la variante por sección.
        try {
            $picks = \App\Services\Personality\LayoutSelector::selectForPage($siteId, $sections, $type);
            foreach ($sections as $i => &$s) {
                $s['variant'] = $picks[$i]['variant'] ?? 'default';
            }
            unset($s);
        } catch (\Throwable $e) {
            error_log('LayoutSelector createAiPage failed: ' . $e->getMessage());
            foreach ($sections as &$s) { $s['variant'] = $s['variant'] ?? 'default'; }
            unset($s);
        }

        $slug = self::uniqueSlug($siteId, slugify($title));
        $now = date('Y-m-d H:i:s');
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                'INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, meta_title, meta_description, status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft", 0, ?, ?, ?, ?, NULL)',
                [$siteId, $title, $slug, $type, $parentId > 0 ? $parentId : null, $title, mb_substr($goal, 0, 155), self::nextOrder($siteId), Auth::id(), $now, $now]
            );
            $pageId = (int) Database::lastInsertId();
            foreach ($sections as $pos => $section) {
                $variant = (string) ($section['variant'] ?? 'default');
                $styleJson = $variant !== 'default'
                    ? json_encode(['variant' => $variant], JSON_UNESCAPED_UNICODE)
                    : null;
                Database::execute(
                    'INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, "editable", ?, ?)',
                    [$pageId, $section['type'], $pos, json_encode($section['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $styleJson, $now, $now]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Recordar layout_preferences para futuras páginas del mismo page_type.
        try {
            \App\Services\Personality\LayoutSelector::rememberPage($siteId, $type, array_map(
                static fn ($s) => ['type' => $s['type'], 'variant' => (string) ($s['variant'] ?? 'default')],
                $sections
            ));
        } catch (\Throwable $e) {
            error_log('LayoutSelector rememberPage createAiPage failed: ' . $e->getMessage());
        }

        return ['id' => $pageId, 'title' => $title, 'edit_url' => base_url('admin/pages/' . $pageId . '/edit')];
    }

    /**
     * @param array<int,array{mime:string,data:string}> $referenceImages
     */
    private static function createReferenceAiPage(int $siteId, array $item, string $title, string $type, string $goal, string $context, int $parentId, array $referenceImages): array
    {
        $sections = [];

        // D-MB — La ESTRUCTURA la dicta la referencia (no una plantilla fija).
        // Una llamada de visión describe el layout real de la captura (secciones,
        // orden, composición, aire) y eso pilota la escritura de cada bloque.
        // La marca (color/tipografía) sigue saliendo del skin; el texto, del negocio.
        $layout = self::describeReferenceLayout($siteId, $title, $goal, $context, $referenceImages);
        $designLanguage = (string) ($layout['design_language'] ?? '');
        $plan = $layout['plan'] !== [] ? $layout['plan'] : self::referenceCustomBlockPlan($type);
        // D-MB2 R1 — resolver fotos reales (Unsplash) para las secciones que las piden.
        $plan = self::resolvePlanImages($siteId, $plan);
        $totalBlocks = count($plan);
        $outlineSummary = self::formatOutlineSummary($plan);

        // Input del generador por-bloque para un índice del plan (lo usan tanto
        // el fallback por bloques como los reintentos de la composición).
        $blockInput = function (int $index) use ($plan, $totalBlocks, $title, $type, $goal, $context, $designLanguage, $outlineSummary, $item, $referenceImages): array {
            $block = $plan[$index] ?? [];
            $theme = trim((string) ($block['theme'] ?? ''));
            $availableImages = self::formatAvailableImages((array) ($block['images'] ?? []));
            return [
                'page_title' => $title,
                'block_goal' => (string) ($block['goal'] ?? $goal),
                'section_role' => (string) ($block['role'] ?? ''),
                'language' => 'es',
                'available_images' => $availableImages,
                'extra_context' => trim(
                    "Objetivo de la página: {$goal}\n"
                  . "Tipo de página: {$type}\n"
                  . "Página propuesta por onboarding: " . (string) ($item['reason'] ?? '') . "\n"
                  . ($designLanguage !== '' ? "LENGUAJE DE DISEÑO DEL SITIO (derivado de la referencia, compártelo en todas las secciones): {$designLanguage}\n" : '')
                  . "ESTRUCTURA COMPLETA DE LA PÁGINA (inspirada en la referencia, con el ritmo de fondos):\n{$outlineSummary}\n"
                  . "Estás generando el bloque " . ($index + 1) . " de {$totalBlocks}. Reprodúcelo con la composición indicada en su rol, manteniendo coherencia de aire/ritmo con el resto.\n"
                  . ($theme !== ''
                        ? "TEMA DE FONDO para esta sección: pon `data-ppb-theme=\"{$theme}\"` en el elemento raíz del bloque." . ($theme === 'image' ? " Usa el patrón ppb-cover con la imagen de available_images." : '') . "\n"
                        : "TEMA DE FONDO para esta sección: por defecto (sin data-ppb-theme), fondo de página.\n")
                  . ($availableImages !== '' && $theme !== 'image' ? "Tienes imagen disponible: úsala con ppb-media (frame/landscape/portrait según composición) si la composición de la sección lo pide.\n" : '')
                  . "Recuerda: hereda ESTRUCTURA y AIRE de la referencia, pero el color y la tipografía son los de la marca del usuario; no copies textos ni colores de la imagen.\n"
                  . $context
                ),
                'is_first_section' => $index === 0,
                '_images' => $referenceImages,
            ];
        };

        // D-MB2 R3 — intento principal: componer TODA la página en una llamada
        // (coherencia de ritmo + mucho más rápido). Fallback: bucle por bloques.
        $sections = self::composeReferencePage($siteId, $title, $goal, $context, $designLanguage, $plan, $referenceImages, $blockInput);

        if ($sections === []) {
            foreach ($plan as $index => $block) {
                $blockResult = CustomBlockGenerator::generate($siteId, $blockInput($index), 2);
                $sections[] = [
                    'type' => 'custom_block',
                    'variant' => 'default',
                    'content' => (array) ($blockResult['content'] ?? []),
                ];
            }
        }

        if ($sections === []) {
            throw new \RuntimeException('No se pudo generar contenido desde las referencias visuales.');
        }

        // D-MB2 R5 — linter de ritmo: corrige fondos repetidos y tramos planos.
        $sections = self::lintSectionRhythm($sections);

        $slug = self::uniqueSlug($siteId, slugify($title));
        $now = date('Y-m-d H:i:s');
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                'INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, meta_title, meta_description, status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft", 0, ?, ?, ?, ?, NULL)',
                [$siteId, $title, $slug, $type, $parentId > 0 ? $parentId : null, $title, mb_substr($goal, 0, 155), self::nextOrder($siteId), Auth::id(), $now, $now]
            );
            $pageId = (int) Database::lastInsertId();
            foreach ($sections as $pos => $section) {
                Database::execute(
                    'INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, "editable", ?, ?)',
                    [
                        $pageId,
                        $section['type'],
                        $pos,
                        json_encode($section['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        null,
                        $now,
                        $now,
                    ]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'id' => $pageId,
            'title' => $title,
            'edit_url' => base_url('admin/pages/' . $pageId . '/edit'),
            'sections_count' => count($sections),
            'template' => 'visual-reference',
        ];
    }

    /**
     * D-MB — Pide a la visión que describa la ESTRUCTURA real de la referencia
     * y la convierte en un plan de bloques (rol + objetivo por sección). Si la
     * llamada falla o no devuelve secciones usables, devuelve plan vacío para
     * que el caller caiga a la plantilla fija.
     *
     * @param array<int,array{mime:string,data:string}> $referenceImages
     * @return array{design_language:string, plan:array<int,array{role:string,goal:string}>}
     */
    private static function describeReferenceLayout(int $siteId, string $title, string $goal, string $context, array $referenceImages): array
    {
        if ($referenceImages === []) {
            return ['design_language' => '', 'plan' => []];
        }

        try {
            $result = AIActionRunner::run(Actions::DESCRIBE_REFERENCE_LAYOUT, [
                'page_title' => $title,
                'block_goal' => $goal,
                'language' => 'es',
                'extra_context' => $context,
                '_images' => $referenceImages,
            ], $siteId);
        } catch (\Throwable $e) {
            error_log('[describeReferenceLayout] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage());
            return ['design_language' => '', 'plan' => []];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $designLanguage = mb_substr(trim((string) ($data['design_language'] ?? '')), 0, 600);

        $plan = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            $role = trim((string) ($section['role'] ?? ''));
            $composition = trim((string) ($section['composition'] ?? ''));
            if ($role === '' && $composition === '') continue;

            $density = trim((string) ($section['density'] ?? ''));
            $emphasis = trim((string) ($section['emphasis'] ?? ''));
            $roleText = ($role !== '' ? $role : 'Sección de la página')
                . ($composition !== '' ? '. Composición: ' . $composition : '')
                . ($density !== '' ? '. Densidad: ' . $density : '')
                . ($emphasis !== '' ? '. Destaca: ' . $emphasis : '');

            // D-MB2 R2 — el tratamiento de fondo de la referencia → theme ppb.
            $background = strtolower(trim((string) ($section['background'] ?? '')));
            $theme = match ($background) {
                'suave'   => 'surface',
                'tintada' => 'tint',
                'intensa' => 'primary',
                'oscura'  => 'dark',
                'foto'    => 'image',
                default   => '',
            };

            // D-MB2 R1 — brief de imagen para resolver contra el banco de fotos.
            $imageBrief = null;
            $rawBrief = $section['image_brief'] ?? null;
            if (is_array($rawBrief)) {
                $subject = trim((string) ($rawBrief['subject'] ?? ''));
                if ($subject !== '') {
                    $orientation = strtolower(trim((string) ($rawBrief['orientation'] ?? '')));
                    $imageBrief = [
                        'subject' => mb_substr($subject, 0, 120),
                        'orientation' => in_array($orientation, ['landscape', 'portrait', 'squarish'], true) ? $orientation : 'landscape',
                        'count' => max(1, min(4, (int) ($rawBrief['count'] ?? 1))),
                    ];
                }
            }

            $plan[] = [
                'role' => mb_substr($roleText, 0, 500),
                'goal' => 'Desarrolla esta sección con contenido útil para el negocio del usuario, manteniendo la composición y el rol que ocupa en la referencia.',
                'theme' => $theme,
                'image_brief' => $imageBrief,
                'images' => [],
            ];
        }

        // Acotar para controlar latencia/coste sin perder la forma de la referencia.
        $plan = array_slice($plan, 0, 7);

        return ['design_language' => $designLanguage, 'plan' => $plan];
    }

    /**
     * D-MB2 R1 — Resuelve los `image_brief` del plan contra el banco de
     * imágenes (Unsplash). Best-effort: sin key, sin red o sin resultados, la
     * sección queda sin imagen y, si su theme era `image`, se degrada a `dark`
     * (una foto a sangre sin foto no existe). Nunca lanza.
     * Evita repetir la misma foto en dos secciones de la misma página.
     */
    private static function resolvePlanImages(int $siteId, array $plan): array
    {
        $available = ImageBankService::isAvailable();
        $usedIds = [];

        foreach ($plan as $i => $block) {
            $brief = $block['image_brief'] ?? null;
            if ($available && is_array($brief)) {
                $wanted = max(1, min(4, (int) ($brief['count'] ?? 1)));
                try {
                    $results = ImageBankService::search((string) $brief['subject'], $wanted + 5, (string) $brief['orientation']);
                    foreach ($results as $result) {
                        if (count($plan[$i]['images']) >= $wanted) break;
                        $resultId = (string) ($result['id'] ?? '');
                        if ($resultId === '' || isset($usedIds[$resultId])) continue;
                        $row = ImageBankService::downloadToMedia($result, $siteId, Auth::id());
                        $usedIds[$resultId] = true;
                        $plan[$i]['images'][] = [
                            'url' => '/' . ltrim((string) ($row['path'] ?? ''), '/'),
                            'alt' => trim((string) ($row['alt_text'] ?? '')) !== '' ? (string) $row['alt_text'] : (string) $brief['subject'],
                            'orientation' => (string) $brief['orientation'],
                        ];
                    }
                } catch (\Throwable $e) {
                    error_log('[resolvePlanImages] site=' . $siteId . ' "' . (string) $brief['subject'] . '" ' . get_class($e) . ': ' . $e->getMessage());
                }
            }

            if (($plan[$i]['images'] ?? []) === [] && ($plan[$i]['theme'] ?? '') === 'image') {
                $plan[$i]['theme'] = 'dark';
            }
        }

        return $plan;
    }

    /**
     * FH2 — Crea una página CANVAS desde la referencia visual: describe la
     * estructura (D-MB2), resuelve fotos reales y pide a la IA la página
     * completa en HTML+CSS libres. Persiste con render_mode='canvas' +
     * CanvasService::save (sanea + crea versión).
     */
    private static function createReferenceCanvasPage(int $siteId, array $item, string $title, string $type, string $goal, string $context, int $parentId, array $referenceImages): array
    {
        $hasRefs = $referenceImages !== [];
        $layout = self::describeReferenceLayout($siteId, $title, $goal, $context, $referenceImages);
        $designLanguage = (string) ($layout['design_language'] ?? '');
        $plan = $layout['plan'];
        if ($plan === [] && $hasRefs) $plan = self::referenceCustomBlockPlan($type);
        $plan = self::resolvePlanImages($siteId, $plan);

        // Outline en texto para el prompt (rol + fondo + imágenes por sección).
        $outline = [];
        foreach ($plan as $i => $block) {
            $theme = trim((string) ($block['theme'] ?? ''));
            $line = ($i + 1) . '. ' . (string) ($block['role'] ?? 'Sección')
                . "\n   Fondo en la referencia: " . ($theme !== '' ? $theme : 'claro/por defecto');
            $imgs = self::formatAvailableImages((array) ($block['images'] ?? []));
            $line .= $imgs !== ''
                ? "\n   Imágenes de ESTA sección:\n" . preg_replace('/^/m', '   ', $imgs)
                : "\n   Sin imágenes (diséñala sin foto).";
            $outline[] = $line;
        }

        // FH6 — sin referencias no hay briefs por sección: ofrecer un pool de
        // fotos genéricas del negocio para que el modelo las reparta.
        if ($outline === [] || !$hasRefs) {
            $pool = self::formatAvailableImages(self::genericBusinessImages($siteId));
            if ($pool !== '') {
                $outline[] = "IMÁGENES DISPONIBLES (repártelas donde mejor encajen, máximo una vez cada una):\n" . $pool;
            }
        }

        $generated = \App\Services\Canvas\CanvasGenerator::generate($siteId, [
            'title' => $title,
            'goal' => $goal,
            'language' => 'es',
            'design_language' => $designLanguage,
            'sections_outline' => implode("\n", $outline),
            'extra_context' => trim(
                "Tipo de página: {$type}\n"
              . "Página propuesta por onboarding: " . (string) ($item['reason'] ?? '') . "\n"
              . $context . "\n"
              . self::heroDifferentiationContext($siteId, $type)
            ),
            'reference_images' => $referenceImages,
        ], 2);

        $slug = self::uniqueSlug($siteId, slugify($title));
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO pages
                (site_id, title, slug, page_type, render_mode, parent_id, meta_title, meta_description, status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
             VALUES (?, ?, ?, ?, "canvas", ?, ?, ?, "draft", 0, ?, ?, ?, ?, NULL)',
            [$siteId, $title, $slug, $type, $parentId > 0 ? $parentId : null, $title, mb_substr($goal, 0, 155), self::nextOrder($siteId), Auth::id(), $now, $now]
        );
        $pageId = (int) Database::lastInsertId();
        \App\Services\Canvas\CanvasService::save($pageId, $generated['html'], $generated['css'], 'generate');

        return [
            'id' => $pageId,
            'title' => $title,
            'edit_url' => base_url('admin/pages/' . $pageId . '/edit'),
            'sections_count' => max(1, substr_count($generated['html'], 'data-pp-section')),
            'template' => 'canvas-reference',
        ];
    }

    /**
     * D-MB2 R3 — Compone la página completa en UNA llamada IA. Devuelve la
     * lista de secciones listas para insertar, o [] si la composición no es
     * usable (el caller cae al bucle por bloques). Las secciones individuales
     * que fallen el sanitizado se reintentan con el generador por-bloque.
     *
     * @param callable(int):array $blockInput input por-bloque para reintentos
     * @return array<int,array{type:string,variant:string,content:array}>
     */
    private static function composeReferencePage(int $siteId, string $title, string $goal, string $context, string $designLanguage, array $plan, array $referenceImages, callable $blockInput): array
    {
        $outline = [];
        foreach ($plan as $i => $block) {
            $theme = trim((string) ($block['theme'] ?? ''));
            $line = ($i + 1) . '. ' . (string) ($block['role'] ?? 'Sección')
                . "\n   Tema de fondo: " . ($theme !== ''
                    ? 'data-ppb-theme="' . $theme . '"' . ($theme === 'image' ? ' (usa el patrón ppb-cover con la imagen de esta sección)' : '')
                    : 'por defecto (sin data-ppb-theme)');
            $imgs = self::formatAvailableImages((array) ($block['images'] ?? []));
            $line .= $imgs !== ''
                ? "\n   Imágenes de ESTA sección (no las uses en otra):\n" . preg_replace('/^/m', '   ', $imgs)
                : "\n   Sin imágenes para esta sección (no inventes <img>).";
            $outline[] = $line;
        }

        // Hasta 2 intentos: si la página sale escasa de contenido (síntoma QA:
        // "tarjetas con solo un título, secciones de una frase"), se repite UNA
        // vez con feedback de densidad explícito.
        $densityNote = '';
        $aiSections = [];
        $result = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = AIActionRunner::run(Actions::COMPOSE_CUSTOM_PAGE_FROM_REFERENCE, [
                    'page_title' => $title,
                    'page_goal' => $goal,
                    'language' => 'es',
                    'design_language' => $designLanguage !== '' ? $designLanguage : '(no derivado; usa un aire sobrio y profesional coherente)',
                    'sections_outline' => implode("\n", $outline),
                    'extra_context' => trim($densityNote . "\n" . $context),
                    '_images' => $referenceImages,
                ], $siteId);
            } catch (\Throwable $e) {
                error_log('[composeReferencePage] site=' . $siteId . ' fallback a bucle por bloques: ' . get_class($e) . ': ' . $e->getMessage());
                return [];
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $aiSections = array_values((array) ($data['sections'] ?? []));
            // Composición demasiado incompleta → mejor el bucle por bloques.
            if (count($aiSections) < max(2, (int) ceil(count($plan) * 0.6))) {
                error_log('[composeReferencePage] site=' . $siteId . ' secciones insuficientes (' . count($aiSections) . '/' . count($plan) . '); fallback.');
                return [];
            }

            // Densidad: media de texto visible por sección. Umbral conservador.
            $totalText = 0;
            foreach ($aiSections as $aiSection) {
                $totalText += mb_strlen(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($aiSection['html'] ?? ''))) ?? ''));
            }
            $avg = $totalText / max(1, count($aiSections));
            if ($avg >= 220 || $attempt === 2) break;

            error_log('[composeReferencePage] site=' . $siteId . ' página escasa (media ' . (int) $avg . ' chars/sección); reintento con feedback de densidad.');
            $densityNote = "ATENCIÓN — tu intento anterior quedó ESCASO de contenido (media de " . (int) $avg . " caracteres por sección). "
                . "Repite la página con más sustancia: cada tarjeta con título + 1-2 frases, cada sección con texto de apoyo real, "
                . "sin cambiar la estructura del outline ni los themes.";
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        $pageRationale = is_array($data['rationale'] ?? null) ? $data['rationale'] : [];
        $source = [
            'kind' => 'reference',
            'provider' => (string) ($result['provider'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'created_at' => gmdate('c'),
            'composed' => true,
        ];

        $sections = [];
        foreach ($aiSections as $i => $aiSection) {
            $content = CustomBlockGenerator::buildContentFromAiData([
                'html' => (string) ($aiSection['html'] ?? ''),
                'rationale' => is_array($aiSection['rationale'] ?? null) ? $aiSection['rationale'] : $pageRationale,
            ], $source + ['attempt' => 1], [
                'site_id' => $siteId,
                'is_first_section' => $i === 0,
            ]);

            if (($content['validation']['sanitized'] ?? false) !== true) {
                // Reintento individual con el generador por-bloque (tiene retry propio).
                try {
                    $blockResult = CustomBlockGenerator::generate($siteId, $blockInput(min($i, count($plan) - 1)), 2);
                    $content = (array) ($blockResult['content'] ?? []);
                } catch (\Throwable $e) {
                    error_log('[composeReferencePage] site=' . $siteId . ' sección ' . ($i + 1) . ' descartada: ' . $e->getMessage());
                    continue;
                }
            }

            $sections[] = ['type' => 'custom_block', 'variant' => 'default', 'content' => $content];
        }

        return count($sections) >= max(2, (int) ceil(count($plan) * 0.6)) ? $sections : [];
    }

    /**
     * D-MB2 R5 — Linter de ritmo determinista sobre la página ya generada:
     *  a) dos secciones adyacentes con el mismo theme de banda (surface/tint/
     *     primary/dark) → la segunda pasa a fondo por defecto (evita bloques
     *     pegados del mismo color);
     *  b) tres secciones seguidas sin theme → la del medio pasa a `surface`
     *     (evita el "muro blanco" con huecos).
     * `image` queda exento: dos covers seguidos son una decisión válida.
     *
     * @param array<int,array{type:string,variant:string,content:array}> $sections
     */
    private static function lintSectionRhythm(array $sections): array
    {
        $themeOf = static function (array $section): string {
            $art = $section['content']['art'] ?? null;
            return is_array($art) ? trim((string) ($art['theme'] ?? '')) : '';
        };

        // a) bandas repetidas
        for ($i = 1, $n = count($sections); $i < $n; $i++) {
            $theme = $themeOf($sections[$i]);
            if ($theme === '' || $theme === 'image') continue;
            if ($theme === $themeOf($sections[$i - 1])) {
                self::setSectionArtTheme($sections[$i], '');
            }
        }

        // b) tramos planos
        for ($i = 2, $n = count($sections); $i < $n; $i++) {
            if ($themeOf($sections[$i]) === '' && $themeOf($sections[$i - 1]) === '' && $themeOf($sections[$i - 2]) === '') {
                self::setSectionArtTheme($sections[$i - 1], 'surface');
            }
        }

        return $sections;
    }

    /** Cambia el theme de un custom_block manteniendo content.art y el atributo del HTML en sincronía. */
    private static function setSectionArtTheme(array &$section, string $theme): void
    {
        if (!is_array($section['content']['art'] ?? null)) {
            $section['content']['art'] = ['theme' => '', 'pad' => ''];
        }
        $section['content']['art']['theme'] = $theme;

        $html = (string) ($section['content']['html'] ?? '');
        if ($html === '') return;
        if (preg_match('/data-ppb-theme="[a-z]+"/', $html)) {
            $html = preg_replace(
                '/data-ppb-theme="[a-z]+"/',
                $theme !== '' ? 'data-ppb-theme="' . $theme . '"' : '',
                $html,
                1
            ) ?? $html;
        } elseif ($theme !== '') {
            $html = preg_replace('/^(\s*<[a-z]+)/', '$1 data-ppb-theme="' . $theme . '"', $html, 1) ?? $html;
        }
        $section['content']['html'] = $html;
    }

    /**
     * FH6 — Pool de 3-4 fotos genéricas del negocio (Unsplash) para la
     * generación canvas SIN referencias. La query sale del sector inferido
     * (personality) o de la memoria. Best-effort: sin key o sin memoria → [].
     *
     * @return array<int,array{url:string,alt:string,orientation:string}>
     */
    private static function genericBusinessImages(int $siteId): array
    {
        if (!ImageBankService::isAvailable()) return [];

        $query = '';
        try {
            $site = Database::selectOne('SELECT personality FROM sites WHERE id = ?', [$siteId]);
            $personality = json_decode((string) ($site['personality'] ?? ''), true) ?: [];
            $query = str_replace('-', ' ', trim((string) ($personality['inferred_sector'] ?? '')));
            if ($query === '') {
                $mem = Database::selectOne(
                    "SELECT field_value FROM site_memory WHERE site_id = ? AND field_key = 'services' LIMIT 1",
                    [$siteId]
                );
                $query = mb_substr(trim((string) ($mem['field_value'] ?? '')), 0, 60);
            }
        } catch (\Throwable $e) {
            return [];
        }
        if ($query === '') return [];

        $images = [];
        try {
            foreach (ImageBankService::search($query, 6, 'landscape') as $result) {
                if (count($images) >= 4) break;
                $row = ImageBankService::downloadToMedia($result, $siteId, Auth::id());
                $images[] = [
                    'url' => '/' . ltrim((string) ($row['path'] ?? ''), '/'),
                    'alt' => trim((string) ($row['alt_text'] ?? '')) !== '' ? (string) $row['alt_text'] : $query,
                    'orientation' => 'landscape',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[genericBusinessImages] site=' . $siteId . ' "' . $query . '": ' . $e->getMessage());
        }
        return $images;
    }

    /** Texto de `available_images` para el prompt de un bloque. */
    private static function formatAvailableImages(array $images): string
    {
        $lines = [];
        foreach ($images as $img) {
            $url = trim((string) ($img['url'] ?? ''));
            if ($url === '') continue;
            $lines[] = '- ' . $url
                . ' | alt sugerido: ' . trim((string) ($img['alt'] ?? ''))
                . ' | orientación: ' . trim((string) ($img['orientation'] ?? ''));
        }
        return implode("\n", $lines);
    }

    /** Resumen legible del plan para dar a cada bloque visión del conjunto. */
    private static function formatOutlineSummary(array $plan): string
    {
        $lines = [];
        foreach ($plan as $i => $block) {
            $theme = trim((string) ($block['theme'] ?? ''));
            $lines[] = ($i + 1) . '. ' . (string) ($block['role'] ?? '')
                . ' [fondo: ' . ($theme !== '' ? $theme : 'claro/por defecto') . ']';
        }
        return implode("\n", $lines);
    }

    /** @return array<int,array{role:string,goal:string}> */
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

    private static function normalizeArchitecture(array $data, string $intent = ''): array
    {
        $missing = [];
        foreach ((array) ($data['missing_pages'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $title = trim((string) ($p['title'] ?? ''));
            if ($title === '') continue;
            $priority = (string) ($p['priority'] ?? 'medium');
            $missing[] = [
                'title' => mb_substr($title, 0, 120),
                'page_type' => (string) ($p['page_type'] ?? 'landing'),
                'parent_slug' => trim((string) ($p['parent_slug'] ?? ''), '/'),
                'parent_title' => mb_substr(trim((string) ($p['parent_title'] ?? '')), 0, 120),
                'parent_id' => isset($p['parent_id']) && (int) $p['parent_id'] > 0 ? (int) $p['parent_id'] : null,
                'goal' => mb_substr(trim((string) ($p['goal'] ?? $p['reason'] ?? '')), 0, 500),
                'reason' => mb_substr(trim((string) ($p['reason'] ?? '')), 0, 260),
                'priority' => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium',
                'architecture_context' => mb_substr(trim((string) ($p['architecture_context'] ?? '')), 0, 700),
            ];
        }
        return [
            'summary' => mb_substr(trim((string) ($data['summary'] ?? '')), 0, 500),
            'health' => [
                'score' => max(0, min(100, (int) ($data['health']['score'] ?? 60))),
                'label' => mb_substr(trim((string) ($data['health']['label'] ?? 'Estructura inicial')), 0, 120),
            ],
            'missing_pages' => self::proposalPages($missing, $intent),
        ];
    }

    private static function fallbackArchitecture(string $intent = ''): array
    {
        return [
            'summary' => 'No hemos podido analizar tu sitio en este momento. Puedes seguir con la propuesta básica o empezar desde el mapa vacío.',
            'health' => ['score' => 55, 'label' => 'Base preparada para empezar'],
            'missing_pages' => self::proposalPages([], $intent),
        ];
    }

    /**
     * F22.T22.2 — La propuesta base ahora depende del intent del usuario.
     * Sirve también como fallback cuando la IA falla.
     */
    private static function proposalPages(array $aiPages, string $intent = ''): array
    {
        $base = match ($intent) {
            'presence' => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Presentar el negocio en una sola página clara.', 'reason' => 'Lo mínimo viable para existir online.', 'priority' => 'high'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Permitir que te contacten.', 'reason' => 'Imprescindible.', 'priority' => 'high'],
            ],
            'services' => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Presentar el negocio y guiar hacia el servicio principal.', 'reason' => 'Puerta de entrada con propuesta de valor.', 'priority' => 'high'],
                ['title' => 'Servicios', 'page_type' => 'service', 'parent_slug' => 'inicio', 'goal' => 'Explicar el catálogo de servicios y abrir conversación.', 'reason' => 'Es la página que convierte.', 'priority' => 'high'],
                ['title' => 'Sobre nosotros', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Generar confianza explicando quién está detrás.', 'reason' => 'Indispensable para servicios profesionales.', 'priority' => 'high'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Facilitar el contacto inicial.', 'reason' => 'Punto final de conversión.', 'priority' => 'high'],
            ],
            'seo' => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Posicionar las palabras clave principales del negocio.', 'reason' => 'Base SEO del sitio.', 'priority' => 'high'],
                ['title' => 'Servicios', 'page_type' => 'service', 'parent_slug' => 'inicio', 'goal' => 'Página con keywords transaccionales.', 'reason' => 'Conversión orgánica.', 'priority' => 'high'],
                ['title' => 'Sobre nosotros', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Información del negocio para SEO local y E-E-A-T.', 'reason' => 'Refuerza autoridad.', 'priority' => 'medium'],
                ['title' => 'Blog', 'page_type' => 'article', 'parent_slug' => 'inicio', 'goal' => 'Hub de contenido para atraer tráfico orgánico de cola larga.', 'reason' => 'Eje central de la estrategia SEO.', 'priority' => 'high'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Capturar leads del tráfico orgánico.', 'reason' => 'Cierre.', 'priority' => 'high'],
            ],
            'portfolio' => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Selección visual de tu mejor trabajo y propuesta.', 'reason' => 'La primera impresión es el trabajo.', 'priority' => 'high'],
                ['title' => 'Portfolio', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Mostrar todos los proyectos con detalle.', 'reason' => 'Centro del sitio.', 'priority' => 'high'],
                ['title' => 'Sobre mí', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Historia, valores y formación.', 'reason' => 'Conecta con el visitante.', 'priority' => 'medium'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Encargo o briefing inicial.', 'reason' => 'Cierre comercial.', 'priority' => 'high'],
            ],
            'product' => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Landing principal del producto con propuesta y CTA.', 'reason' => 'Único punto de conversión.', 'priority' => 'high'],
                ['title' => 'Precios', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Comparativa de planes o tiers.', 'reason' => 'Cierre de decisión de compra.', 'priority' => 'high'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Resolver dudas previas a la compra.', 'reason' => 'Reduce fricción.', 'priority' => 'medium'],
            ],
            default => [
                ['title' => 'Inicio', 'page_type' => 'home', 'parent_slug' => '', 'goal' => 'Presentar el negocio y guiar hacia la acción principal.', 'reason' => 'La puerta de entrada del sitio.', 'priority' => 'high'],
                ['title' => 'Servicios', 'page_type' => 'service', 'parent_slug' => 'inicio', 'goal' => 'Explicar lo que ofreces y convertir visitas en solicitudes.', 'reason' => 'Alta prioridad para que el visitante entienda tu oferta.', 'priority' => 'high'],
                ['title' => 'Sobre nosotros', 'page_type' => 'landing', 'parent_slug' => 'inicio', 'goal' => 'Construir confianza y explicar quién está detrás del negocio.', 'reason' => 'Ayuda a que el sitio no parezca anónimo.', 'priority' => 'high'],
                ['title' => 'Contacto', 'page_type' => 'contact', 'parent_slug' => 'inicio', 'goal' => 'Facilitar que los visitantes contacten.', 'reason' => 'Imprescindible para capturar oportunidades.', 'priority' => 'high'],
                ['title' => 'Blog', 'page_type' => 'article', 'parent_slug' => 'inicio', 'goal' => 'Crear una base de contenido útil para SEO.', 'reason' => 'Baja prioridad, útil cuando quieras crecer en orgánico.', 'priority' => 'low'],
            ],
        };
        $seen = [];
        $out = [];
        foreach (array_merge($base, $aiPages) as $p) {
            $key = mb_strtolower((string) ($p['title'] ?? ''));
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $p;
        }
        return array_slice($out, 0, 8);
    }

    private static function resolveParentIdForPage(int $siteId, array $item, string $type, string $title): int
    {
        $explicitId = (int) ($item['parent_id'] ?? 0);
        if ($explicitId > 0 && self::pageIdBelongsToSite($siteId, $explicitId)) return $explicitId;

        $parentSlug = trim((string) ($item['parent_slug'] ?? ''), '/');
        if ($parentSlug !== '') {
            $bySlug = self::findPageIdBySlug($siteId, $parentSlug);
            if ($bySlug > 0) return $bySlug;
        }

        $parentTitle = trim((string) ($item['parent_title'] ?? ''));
        if ($parentTitle !== '') {
            $byTitle = self::findPageIdByTitle($siteId, $parentTitle);
            if ($byTitle > 0) return $byTitle;
        }

        $titleKey = mb_strtolower(trim($title));
        if ($type === 'service' && !in_array($titleKey, ['servicios', 'servicio'], true)) {
            $services = self::findPageIdBySlug($siteId, 'servicios') ?: self::findPageIdByTitle($siteId, 'Servicios');
            if ($services > 0) return $services;
        }
        if ($type === 'article' && !in_array($titleKey, ['blog', 'artículos', 'articulos'], true)) {
            $blog = self::findPageIdBySlug($siteId, 'blog') ?: self::findPageIdByTitle($siteId, 'Blog');
            if ($blog > 0) return $blog;
        }
        if ($type !== 'home' && $titleKey !== 'inicio') {
            $home = self::findPageIdBySlug($siteId, 'inicio') ?: self::findPageIdByTitle($siteId, 'Inicio');
            if ($home > 0) return $home;
        }
        return 0;
    }

    private static function pageIdBelongsToSite(int $siteId, int $pageId): bool
    {
        return (bool) Database::selectOne('SELECT id FROM pages WHERE id = ? AND site_id = ? LIMIT 1', [$pageId, $siteId]);
    }

    private static function findPageIdBySlug(int $siteId, string $slug): int
    {
        $slug = trim($slug, '/');
        if ($slug === '') return 0;
        $row = Database::selectOne('SELECT id FROM pages WHERE site_id = ? AND slug = ? LIMIT 1', [$siteId, $slug]);
        return (int) ($row['id'] ?? 0);
    }

    private static function findPageIdByTitle(int $siteId, string $title): int
    {
        $title = trim($title);
        if ($title === '') return 0;
        $row = Database::selectOne('SELECT id FROM pages WHERE site_id = ? AND LOWER(title) = LOWER(?) ORDER BY id ASC LIMIT 1', [$siteId, $title]);
        return (int) ($row['id'] ?? 0);
    }

    private static function complete(int $siteId, bool $skipped): void
    {
        self::storeSetting($siteId, 'onboarding_completed_at', date('c'));
        if ($skipped) self::storeSetting($siteId, 'onboarding_skipped', '1');
    }

    private static function currentStep(int $siteId): int
    {
        $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, 'onboarding_current_step']);
        $step = (int) ($row['setting_value'] ?? 1);
        return max(1, min(5, $step));
    }

    /** F22.T22.1 — helper genérico para leer una clave de settings. */
    private static function loadSetting(int $siteId, string $key, string $default = ''): string
    {
        $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $key]);
        return (string) ($row['setting_value'] ?? $default);
    }

    private static function storeSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, $key, $value]
        );
    }

    private static function loadDesignValues(int $siteId): array
    {
        $tokens = DesignSystem::load($siteId);
        return [
            'primary_color' => (string) ($tokens['colors']['primary'] ?? '#ea580c'),
            'secondary_color' => (string) ($tokens['colors']['secondary'] ?? '#1c1917'),
            'typography_pair' => 'Inter / Inter',
            'border_radius' => (string) max(0, min(60, (int) ($tokens['buttons']['radius'] ?? 8))),
        ];
    }

    private static function loadBrandValues(int $siteId): array
    {
        $site = self::site($siteId);
        $logo = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'site_logo_path']
        );
        return [
            'name' => (string) ($site['name'] ?? ''),
            'logo_path' => (string) ($logo['setting_value'] ?? ''),
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>,count:int} */
    private static function loadReferenceValues(int $siteId): array
    {
        $raw = self::loadSetting($siteId, 'onboarding_visual_references', '[]');
        $items = json_decode($raw, true);
        if (!is_array($items)) $items = [];
        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $path = (string) ($item['path'] ?? '');
            if ($path === '' || !is_file(PP_ROOT . '/' . ltrim($path, '/'))) continue;
            $valid[] = $item;
        }
        return ['items' => $valid, 'count' => count($valid)];
    }

    /** @return array<int,array{mime:string,data:string}> */
    private static function loadReferenceImagesForVision(int $siteId): array
    {
        $refs = self::loadReferenceValues($siteId);
        $out = [];
        foreach (array_slice($refs['items'], 0, 4) as $item) {
            $path = PP_ROOT . '/' . ltrim((string) ($item['path'] ?? ''), '/');
            if (!is_file($path)) continue;
            $raw = (string) @file_get_contents($path);
            if ($raw === '') continue;
            $normalized = self::downscaleForVision($raw);
            if ($normalized !== null) $out[] = $normalized;
        }
        return $out;
    }

    private static function hasReferencePreview(int $siteId): bool
    {
        $content = self::loadReferencePreviewContent($siteId);
        return is_array($content) && (($content['validation']['sanitized'] ?? false) === true);
    }

    /** @return array<string,mixed>|null */
    private static function loadReferencePreviewContent(int $siteId): ?array
    {
        $raw = self::loadSetting($siteId, 'onboarding_reference_preview_content', '');
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @return array{mime:string,data:string}|null */
    private static function downscaleForVision(string $raw): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return ['mime' => 'image/png', 'data' => base64_encode($raw)];
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) return null;

        $w = imagesx($img);
        $h = imagesy($img);
        $max = 1600;
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
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

    private static function loadAiValues(int $siteId): array
    {
        $rows = Database::select(
            'SELECT setting_key, setting_value FROM settings WHERE site_id = ? AND setting_key IN (?, ?, ?)',
            [$siteId, 'ai_provider', 'ai_model', 'ai_model_light']
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        $main = $map['ai_model'] ?? 'google/gemini-3-flash-preview';
        if ($main === '' || $main === 'google/gemini-3.1-flash-lite-preview' || $main === 'google/gemini-3.1-flash-lite') {
            $main = 'google/gemini-3-flash-preview';
        }
        $light = $map['ai_model_light'] ?? 'google/gemini-3.1-flash-lite';
        if ($light === 'google/gemini-3.1-flash-lite-preview') {
            $light = 'google/gemini-3.1-flash-lite';
        }
        return [
            'provider' => $map['ai_provider'] ?? 'openrouter',
            'model' => $main !== '' ? $main : 'google/gemini-3-flash-preview',
            'model_light' => $light,
            'is_recommended' => isset(self::AI_MODELS[$main]),
        ];
    }

    private static function latestDocument(int $siteId): ?array
    {
        return Database::selectOne('SELECT * FROM documents WHERE site_id = ? ORDER BY created_at DESC LIMIT 1', [$siteId]);
    }

    private static function latestDocuments(int $siteId): array
    {
        return Database::select('SELECT * FROM documents WHERE site_id = ? ORDER BY created_at DESC LIMIT 5', [$siteId]);
    }

    private static function siteMapContext(int $siteId): string
    {
        $memory = MemoryController::loadValues($siteId);
        $lines = ['Memoria del negocio:'];
        foreach ($memory as $key => $value) {
            if (trim((string) $value) !== '') $lines[] = '- ' . $key . ': ' . mb_substr((string) $value, 0, 900);
        }
        $docs = Database::select('SELECT title, status, summary FROM documents WHERE site_id = ? ORDER BY created_at DESC LIMIT 5', [$siteId]);
        $lines[] = "\nDocumentos:";
        foreach ($docs as $doc) {
            $lines[] = '- ' . (string) $doc['title'] . ' | status=' . (string) $doc['status'] . ' | summary=' . mb_substr((string) ($doc['summary'] ?? ''), 0, 700);
        }
        return implode("\n", $lines);
    }

    private static function color(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }

    private static function detectFileType(array $file): ?string
    {
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        return in_array($ext, ['pdf', 'docx', 'txt'], true) ? $ext : null;
    }

    private static function uniqueSlug(int $siteId, string $base): string
    {
        $base = trim($base, '/') ?: 'pagina';
        $slug = $base;
        $i = 2;
        while (Database::selectOne('SELECT id FROM pages WHERE site_id = ? AND slug = ? LIMIT 1', [$siteId, $slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private static function nextOrder(int $siteId): int
    {
        $row = Database::selectOne('SELECT COALESCE(MAX(tree_sort_order), 0) AS n FROM pages WHERE site_id = ?', [$siteId]);
        return ((int) ($row['n'] ?? 0)) + 1;
    }

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

    private static function memoryFieldSchema(): string
    {
        $lines = [];
        foreach (MemoryController::FIELDS as $key => $field) {
            $line = '- ' . $key . ': ' . (string) ($field['label'] ?? $key);
            if (!empty($field['help'])) {
                $line .= ' | ' . (string) $field['help'];
            }
            if (($field['type'] ?? '') === 'select') {
                $line .= ' | opciones: ' . implode(', ', array_keys((array) ($field['options'] ?? [])));
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** @return array{company_name:string,confidence:string,fields:array<string,string>,notes:array<int,string>} */
    private static function normalizeBusinessProfile(array $data): array
    {
        $fields = [];
        $rawFields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        foreach (MemoryController::FIELDS as $key => $field) {
            $value = trim((string) ($rawFields[$key] ?? ''));
            if (($field['type'] ?? '') === 'select') {
                $options = array_keys((array) ($field['options'] ?? []));
                if (!in_array($value, $options, true)) {
                    $value = self::closestTone($value, $options);
                }
            }
            $fields[$key] = mb_substr($value, 0, 5000);
        }
        $confidence = (string) ($data['confidence'] ?? 'medium');
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) $confidence = 'medium';
        $notes = [];
        foreach ((array) ($data['notes'] ?? []) as $note) {
            $note = trim((string) $note);
            if ($note !== '') $notes[] = mb_substr($note, 0, 180);
        }
        return [
            'company_name' => mb_substr(trim((string) ($data['company_name'] ?? '')), 0, 255),
            'confidence' => $confidence,
            'fields' => $fields,
            'notes' => array_slice($notes, 0, 4),
        ];
    }

    /** @param string[] $options */
    private static function closestTone(string $value, array $options): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') return '';
        foreach ($options as $option) {
            if ($value === mb_strtolower($option)) return $option;
        }
        foreach ($options as $option) {
            if (str_contains($value, mb_strtolower($option))) return $option;
        }
        return in_array('profesional', $options, true) ? 'profesional' : (string) ($options[0] ?? '');
    }

    private static function site(int $siteId): array
    {
        return Database::selectOne('SELECT * FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?? [];
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) Response::redirect(base_url('admin/logout'));
        return $siteId;
    }
}
