<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use App\Services\BrandService;
use App\Services\Canvas\CanvasCancelToken;
use App\Services\Canvas\CanvasChatService;
use App\Services\Canvas\CanvasSectionTemplates;
use App\Services\Canvas\CanvasService;
use App\Services\DesignSystem;
use App\Services\EditLock;
use App\Services\FormStore;
use App\Services\FormPlacementStore;
use App\Services\FormTemplates;
use App\Services\ImageBankService;
use App\Services\SeoIndexingService;
use App\Services\SeoRedirectService;
use App\Services\VisualStyleService;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * FH3 — Studio Live: edición conversacional de páginas canvas.
 *
 *   GET  /admin/canvas/{id}            → UI del studio (iframe + chat)
 *   GET  /admin/canvas/{id}/preview    → render de la página (aunque sea draft)
 *                                        con overlay de selección de secciones
 *   POST /admin/canvas/{id}/chat       → aplica una petición de cambio (IA)
 *   GET  /admin/canvas/{id}/versions   → historial
 *   POST /admin/canvas/{id}/restore    → restaurar versión
 *   POST /admin/canvas/{id}/publish    → publicar/despublicar
 */
final class CanvasController
{
    public function studio(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);

        $canvas = CanvasService::get((int) $page['id']) ?? ['html' => '', 'css' => ''];
        $pageLang = \App\Services\LanguageService::forPage($page, $siteId);
        $resourcesEnabled = \App\Modules\ModuleRegistry::isEnabled($siteId, 'resources');
        $publishedResources = $resourcesEnabled ? self::resourcesForStudio($siteId, $pageLang) : [];

        // Vista standalone (sin layout admin): el studio es una app a pantalla completa.
        View::send('admin/canvas/studio', [
            'page' => $page,
            // FH9 — tokens de marca para que el chrome del Studio use --pp-primary.
            'brandVars' => DesignSystem::renderCssVars(DesignSystem::effective($siteId), $siteId),
            'sections' => CanvasService::listSections($canvas['html']),
            'versionsCount' => count(CanvasService::versions((int) $page['id'])),
            'history' => CanvasService::historyState((int) $page['id']),
            // FH7 — destinos de enlace para el panel de edición (botones/CTAs).
            'linkTargets' => Database::select(
                "SELECT title, slug FROM pages WHERE site_id = ? AND slug <> '__forms' ORDER BY title ASC LIMIT 100",
                [$siteId]
            ),
            // FORMS F5 — formularios disponibles para insertar en el Studio.
            'forms' => FormStore::all($siteId),
            'formTemplates' => FormTemplates::catalogForView(),
            // MODULOS M2/M5 — servicios reservables para el botón "+ Calendario".
            // El botón solo existe si hay algo que insertar: con el módulo
            // apagado, o encendido pero sin ningún servicio activo, no se pinta
            // (mismo criterio que la pantalla de Reservas).
            'bookingServices' => \App\Modules\ModuleRegistry::isEnabled($siteId, 'booking')
                ? \App\Modules\Booking\BookingEmbedRenderer::embeddableServices($siteId)
                : [],
            // R6 — solo ofrecemos un bloque que pueda enseñar contenido real
            // en el idioma de esta página. Sin publicaciones, no hay vía muerta.
            'publishedResources' => $publishedResources,
            'resourcesModuleEnabled' => $resourcesEnabled,
            'hasPublishedResources' => $resourcesEnabled && \App\Modules\Resources\ResourceStore::hasPublished($siteId),
            'resourcePageLanguage' => \App\Services\LanguageService::label($pageLang),
            // Selector de modelo de IA para el chat (principal + auxiliar + sugeridos).
            'aiModels' => self::chatModelOptions($siteId),
            // ¿Está Unsplash configurado? (habilita la búsqueda en el selector de imágenes)
            'bankAvailable' => ImageBankService::isAvailable(),
        ]);
    }

    /** Render completo de la página (estado actual, aunque sea draft) + overlay. */
    public function preview(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        $pageLang = \App\Services\LanguageService::forPage($page, $siteId);

        $canvas = CanvasService::renderDraft($pageId, $siteId, $pageLang);
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ?', [$siteId]) ?? [];
        $styleSlug = VisualStyleService::selectedForSite($siteId);

        $h  = '<!doctype html><html lang="' . e($pageLang) . '"><head>';
        $h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="robots" content="noindex">';
        $h .= '<title>' . e((string) $page['title']) . ' — preview</title>';
        $h .= DesignSystem::renderHead($siteId, $styleSlug);
        if (!empty($canvas['has_resources'])) {
            $resourcesCss = PP_ROOT . '/public/css/resources.css';
            $h .= '<link rel="stylesheet" href="' . e(base_url('public/css/resources.css')) . '?v=' . e((string) (@filemtime($resourcesCss) ?: PP_VERSION)) . '">';
        }
        $h .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $h .= BrandService::publicHeader($siteId, null, $pageLang);
        $h .= '<main>' . $canvas['html'] . '</main>';
        // STUDIO-UX A1 — sin chrome de consentimiento: el banner tapaba el pie del
        // lienzo en todas las sesiones de edición, y aquí no hay visitante al que
        // preguntarle nada.
        $h .= BrandService::publicFooter($siteId, null, $pageLang, false);
        $h .= '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        // ?clean=1 → vista limpia para "Ver página" cuando es borrador (sin el
        // overlay de selección/edición, que solo tiene sentido en el iframe).
        if (Request::get('clean') !== '1') {
            $h .= self::overlayScript();
        }
        $h .= '</body></html>';

        Response::html($h);
    }

    /** Aplica una petición de cambio del chat. JSON. */
    public function chat(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $instruction = trim((string) Request::post('instruction', ''));
        $sectionId = trim((string) Request::post('section', ''));
        $elementContext = trim((string) Request::post('element_context', ''));
        if ($instruction === '' || mb_strlen($instruction) > 1200) {
            Response::json(['ok' => false, 'error' => __('canvas.error.describe_change')], 422);
        }

        // STUDIO-2 B1/B2 — memoria de la conversación y camino del elemento
        // seleccionado. Ambos son opcionales y llegan del navegador: se validan
        // y se acotan aquí antes de entrar en el prompt.
        $chatContext = [
            'history' => self::parseChatHistory((string) Request::post('history', '')),
            'element_path' => preg_match('/^\d+(\.\d+){0,11}$/', (string) Request::post('element_path', ''))
                ? (string) Request::post('element_path', '')
                : '',
        ];

        // Modelo elegido por el usuario para ESTE cambio (opcional). Solo se
        // acepta si está en la lista permitida del sitio: nunca un ID arbitrario.
        $chosenModel = trim((string) Request::post('model', ''));
        if ($chosenModel !== '' && in_array($chosenModel, self::chatModelIds($siteId), true)) {
            AIProviderFactory::setModelOverride($chosenModel);
        }

        // CANCEL — Identificador de ESTA generación, para poder pararla.
        $requestId = trim((string) Request::post('request_id', ''));
        if (!CanvasCancelToken::isValidId($requestId)) $requestId = '';

        // Liberamos el bloqueo de sesión antes de la llamada larga a la IA: si
        // no, la petición de "Parar" se quedaría esperando precisamente a la
        // generación que quiere cancelar.
        Session::close();

        // F5-T4: el pipeline (imágenes, enrutado sección/página, verificación y
        // guardado) vive en CanvasChatService, compartido con el asistente central.
        // Margen para el timeout HTTP del proveedor (hasta 180s en página completa).
        @set_time_limit(240);
        try {
            $outcome = CanvasChatService::applyInstruction($siteId, $page, $instruction, $sectionId, $elementContext, 'chat', '', $requestId, $chatContext);
        } catch (\App\Services\Canvas\SectionGoneException $e) {
            // STUDIO-UX F10 — Borraron la sección mientras la IA trabajaba. Es un
            // conflicto, no un fallo: la página no se toca y se dice por qué.
            Response::json(['ok' => false, 'error' => __('canvas.err.section_gone')], 409);
        } catch (AIException $e) {
            $errorId = substr(bin2hex(random_bytes(6)), 0, 10);
            error_log('[canvas chat] error_id=' . $errorId . ' page=' . $pageId . ' ai status=' . $e->getHttpStatus() . ': ' . $e->getMessage());
            $message = self::chatErrorMessage($e, $sectionId !== '');
            Response::json(['ok' => false, 'error' => $message, 'error_id' => $errorId], 502);
        } catch (\Throwable $e) {
            error_log('[canvas chat] page=' . $pageId . ' ' . get_class($e) . ': ' . $e->getMessage());
            Response::json([
                'ok' => false,
                'error' => __('canvas.error.cant_apply'),
            ], 502);
        }

        if (!$outcome['ok']) {
            Response::json(['ok' => false, 'error' => (string) $outcome['error']], (int) ($outcome['http'] ?? 502));
        }

        Response::json([
            'ok' => true,
            'reply' => $outcome['reply'],
            'history' => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($outcome['saved']['html']),
            // B3 — para que el Studio lleve al usuario a lo que ha cambiado.
            'changed_section' => $sectionId,
        ]);
    }

    /**
     * STUDIO-2 B4 — Mensaje de error por CAUSA, con la salida sugerida. Antes
     * cualquier AIException sin status caía en "la IA no devolvió un cambio
     * válido": ni ayudaba a diagnosticar ni le decía al usuario qué hacer.
     *
     * @param bool $scoped ¿el cambio iba sobre una sección concreta?
     */
    private static function chatErrorMessage(AIException $e, bool $scoped): string
    {
        $status = $e->getHttpStatus();
        $detail = mb_strtolower($e->getMessage());
        // i18n-ignore-start: NO son textos de interfaz, son fragmentos del mensaje
        // de excepción que se comparan para clasificar el fallo. Si alguien
        // traduce el mensaje original (`CanvasChatService`), esta detección deja
        // de funcionar en silencio y el usuario ve el error genérico.
        $isTimeout = $status === 408
            || str_contains($detail, 'timeout')
            || str_contains($detail, 'timed out')
            || str_contains($detail, 'operation timed out')
            || str_contains($detail, 'se agotó el tiempo');
        // El sobre incompleto suele ser una respuesta truncada por longitud.
        $isTruncated = str_contains($detail, 'sobre de texto')
            || str_contains($detail, 'sobre válido')
            || str_contains($detail, 'ni html ni estilos');
        // i18n-ignore-end

        return match (true) {
            in_array($status, [401, 403], true) => __('canvas.error.bad_provider'),
            $status === 429 => __('canvas.error.rate_limited'),
            $status >= 500 => __('canvas.error.provider_down'),
            $isTimeout => $scoped
                ? __('canvas.error.timeout_scoped')
                : __('canvas.error.timeout_page'),
            $isTruncated => $scoped
                ? __('canvas.error.truncated_scoped')
                : __('canvas.error.truncated_page'),
            default => __('canvas.error.no_valid_change'),
        };
    }

    /**
     * STUDIO-2 B1 — Turnos anteriores del chat, tal como los manda el navegador.
     * Se acotan en número y longitud: es contexto, no un historial completo.
     *
     * @return array<int,array{q:string,a:string,scope:string}>
     */
    private static function parseChatHistory(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 12000) return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach (array_slice($decoded, -4) as $turn) {
            if (!is_array($turn)) continue;
            $q = trim((string) ($turn['q'] ?? ''));
            if ($q === '') continue;
            $out[] = [
                'q' => mb_substr($q, 0, 300),
                'a' => mb_substr(trim((string) ($turn['a'] ?? '')), 0, 300),
                'scope' => mb_substr(trim((string) ($turn['scope'] ?? '')), 0, 60),
            ];
        }
        return $out;
    }

    /** FORMS-R T3 — Inserta uno existente o lo crea desde plantilla. */
    public function insertForm(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $formId = (int) Request::post('form_id', 0);
        $template = trim((string) Request::post('template', ''));
        if ($formId <= 0 && $template !== '') {
            if (!FormTemplates::exists($template)) {
                Response::json(['ok' => false, 'error' => 'Plantilla de formulario no valida.'], 422);
            }
            $formId = FormStore::createFromTemplate($siteId, $template);
        }
        $form = FormStore::find($siteId, $formId);
        if ($form === null) {
            Response::json(['ok' => false, 'error' => 'Formulario no encontrado.'], 404);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
        }

        $sectionId = trim((string) Request::post('section', ''));
        $sourceLabel = trim((string) Request::post('source_label', ''));
        $heading = (string) ($form['heading'] ?? 'Formulario');
        $embedId = 'form-' . $formId . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $embed = '<section data-pp-section="' . $embedId . '" data-pp-label="' . e($heading)
            . '" class="pp-canvas-form-embed">{{form:' . $formId . '}}</section>';
        $html = self::insertAtRequestedPosition($canvas['html'], $embed, $sectionId);
        $saved = CanvasService::save($pageId, $html, $canvas['css'], 'insert', 'Formulario insertado: ' . $heading);
        FormPlacementStore::record($formId, $pageId, $sourceLabel !== '' ? $sourceLabel : $sectionId);

        Response::json([
            'ok'       => true,
            'reply'    => __($sectionId !== '' ? 'canvas.form_added_here' : 'canvas.form_added_end', ['formulario' => $heading]),
            'form'     => ['id' => $formId, 'heading' => $heading],
            'history'  => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
            'changed_section' => $embedId,
        ]);
    }

    /**
     * MODULOS M2 — Insertar el calendario de reservas en el punto activo.
     *
     * Mismo camino que `insertForm()`: en una página canvas el gestor no escribe
     * HTML, así que el calendario se añade con un botón del Studio y se guarda
     * como el placeholder `{{booking:N}}` dentro de su propia sección. Queda
     * listado en "Partes de esta página", se puede mover y borrar como el resto,
     * y el chat puede seguir hablando de él.
     */
    public function insertBooking(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        self::requireEditLock((int) $page['id']);
        if (!\App\Modules\ModuleRegistry::isEnabled($siteId, 'booking')) {
            Response::json(['ok' => false, 'error' => __('cv.booking.module_off')], 422);
        }

        // "auto" (o vacío) = el primer servicio activo, que es lo que ofrece el
        // menú por defecto para no obligar a elegir.
        $raw = trim((string) Request::post('service_id', ''));
        $isAuto = ($raw === '' || $raw === 'auto' || $raw === '0');

        // RSV-TABS — "3,7" inserta un calendario con una pestaña por servicio.
        // Si de la lista solo sobrevive uno, entra como calendario normal: una
        // pestaña sola no es una pestaña.
        $tabIds = $isAuto || !str_contains($raw, ',')
            ? []
            : \App\Modules\Booking\BookingEmbedRenderer::resolveServiceIds($siteId, $raw);

        $serviceId = $tabIds !== []
            ? $tabIds[0]
            : \App\Modules\Booking\BookingEmbedRenderer::resolveServiceId($siteId, $isAuto ? 0 : (int) $raw);
        if ($serviceId === null) {
            Response::json(['ok' => false, 'error' => __('cv.booking.no_services')], 422);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
        }

        $service = Database::selectOne(
            'SELECT name FROM booking_services WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $serviceId]
        );
        $name = (string) ($service['name'] ?? '');
        $label = __('cv.booking.section_label', ['servicio' => $name]);

        // Se guarda `auto` si el gestor no eligió servicio: así la página sigue
        // funcionando si más adelante cambia cuál es el primer servicio activo.
        $ref = count($tabIds) > 1
            ? implode(',', $tabIds)
            : ($isAuto ? 'auto' : (string) $serviceId);
        $sectionId = trim((string) Request::post('section', ''));
        // El id de la sección no puede llevar comas: es un `data-pp-section`.
        $embedId = 'booking-' . str_replace(',', '-', $ref) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $embed = '<section data-pp-section="' . $embedId . '" data-pp-label="' . e($label) . '"'
            . ' class="pp-canvas-booking-embed">{{booking:' . $ref . '}}</section>';
        $html = self::insertAtRequestedPosition($canvas['html'], $embed, $sectionId);
        $saved = CanvasService::save($pageId, $html, $canvas['css'], 'insert', $label);

        Response::json([
            'ok'       => true,
            'reply'    => __($sectionId !== '' ? 'cv.booking.added_here' : 'cv.booking.added_end', ['servicio' => $name]),
            'history'  => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
            'changed_section' => $embedId,
        ]);
    }

    /** Recursos publicados que tiene sentido ofrecer en Studio. */
    public static function resourcesForStudio(int $siteId, string $lang): array
    {
        if (!\App\Modules\ModuleRegistry::isEnabled($siteId, 'resources')) return [];
        return \App\Modules\Resources\ResourceStore::publishedForLanguage($siteId, $lang);
    }

    /** R6 — inserta un bloque dinámico de recursos tras la parte activa. */
    /**
     * STUDIO-UX F6 — Otras páginas canvas del sitio con sus partes, para poder
     * traerse una tal cual. Solo lectura. JSON.
     */
    public function copySources(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        $rows = Database::select(
            "SELECT p.id, p.title, c.html
             FROM pages p
             JOIN page_canvas c ON c.page_id = p.id
             WHERE p.site_id = ? AND p.id <> ? AND p.render_mode = 'canvas'
               AND p.status <> 'trash'
             ORDER BY p.title ASC
             LIMIT 60",
            [$siteId, $pageId]
        );

        $out = [];
        foreach ($rows as $row) {
            $sections = CanvasService::listSections((string) $row['html']);
            if ($sections === []) continue;
            $out[] = [
                'id'       => (int) $row['id'],
                'title'    => (string) $row['title'],
                'sections' => $sections,
            ];
        }
        Response::json(['ok' => true, 'pages' => $out]);
    }

    /**
     * STUDIO-UX F6 — Trae una sección de otra página, literal. Sin IA: el chat
     * ya sabía imitarla, pero costaba una generación entera.
     */
    public function copySection(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();
        self::requireEditLock((int) $page['id']);

        $sourcePageId = (int) Request::post('source_page', 0);
        $sectionId = trim((string) Request::post('source_section', ''));
        if ($sourcePageId <= 0 || $sectionId === '' || $sourcePageId === $pageId) {
            Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
        }

        // El origen tiene que ser del MISMO sitio: si no, un id válido de otro
        // sitio dejaría copiar contenido ajeno.
        $source = self::findCanvasPage($sourcePageId, $siteId);
        $sourceCanvas = CanvasService::get((int) $source['id']);
        $targetCanvas = CanvasService::get($pageId);
        if ($sourceCanvas === null || $targetCanvas === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
        }

        $position = trim((string) Request::post('position', ''));
        if (!in_array($position, ['before', 'after'], true)) $position = 'after';
        $anchorId = trim((string) Request::post('section', ''));
        if ($anchorId === '') {
            $existing = CanvasService::listSections((string) $targetCanvas['html']);
            $anchorId = (string) ($existing[count($existing) - 1]['id'] ?? '');
            $position = 'after';
        }

        $copied = CanvasService::copySectionInto(
            (string) $targetCanvas['html'],
            (string) $targetCanvas['css'],
            (string) $sourceCanvas['html'],
            (string) $sourceCanvas['css'],
            $sectionId,
            $anchorId,
            $position,
            '-c' . substr(bin2hex(random_bytes(3)), 0, 5)
        );
        if ($copied === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
        }

        $saved = CanvasService::save(
            $pageId,
            $copied['html'],
            $copied['css'],
            'structure',
            $copied['label'] . ' — ' . __('canvas.hist.copied')
        );
        Response::json([
            'ok' => true,
            'changed' => true,
            'action' => 'copy',
            'changed_section' => $copied['id'],
            'focus_section' => $copied['id'],
            'reply' => __('cv.section_copied', ['pagina' => (string) $source['title']]),
            'history' => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
        ]);
    }

    public function insertResources(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $lang = \App\Services\LanguageService::forPage($page, $siteId);
        $resources = self::resourcesForStudio($siteId, $lang);
        if ($resources === []) {
            $error = \App\Modules\ModuleRegistry::isEnabled($siteId, 'resources')
                ? __('cv.resources.no_published')
                : __('cv.resources.module_off');
            Response::json(['ok' => false, 'error' => $error], 422);
        }

        $limit = max(1, min(6, (int) Request::post('limit', 3)));
        $limit = min($limit, count($resources));
        $canvas = CanvasService::get($pageId);
        if ($canvas === null) Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);

        // El contenido insertado pertenece a la página, no al idioma del panel.
        // No persistimos un heading traducido: el renderer lo resuelve en cada
        // render con el idioma actual de la página. Así tampoco queda obsoleto
        // si una página cambia de idioma más adelante.
        $label = \App\Services\Microcopy::t('resources.title', $lang);
        $sectionId = trim((string) Request::post('section', ''));
        $embedId = 'resources-' . substr(bin2hex(random_bytes(5)), 0, 10);
        $placeholder = '{{resources:featured|limit=' . $limit . '}}';
        $embed = '<section data-pp-section="' . $embedId . '" data-pp-label="' . e($label) . '"'
            . ' class="pp-canvas-resources-embed">' . $placeholder . '</section>';
        $html = self::insertAtRequestedPosition($canvas['html'], $embed, $sectionId);
        $saved = CanvasService::save($pageId, $html, $canvas['css'], 'insert', $label);

        Response::json([
            'ok' => true,
            'reply' => __($sectionId !== '' ? 'cv.resources.added_here' : 'cv.resources.added_end', ['n' => $limit]),
            'history' => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
            'changed_section' => $embedId,
        ]);
    }

    /**
     * STUDIO-STRUCTURE S2 — Mueve o elimina una parte top-level sin IA.
     * Cada cambio real crea exactamente una versión Canvas; un límite de orden
     * es un no-op explícito y no ensucia el historial.
     */
    public function updateCanvasStructure(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();
        self::requireEditLock((int) $page['id']);

        $action = trim((string) Request::post('action', ''));
        $sectionId = trim((string) Request::post('section', ''));

        // STUDIO-UX F5 — Reordenar arrastrando: llega el orden final completo y
        // se aplica en una sola escritura (antes, llevar la última parte al
        // principio eran seis viajes, seis versiones y seis recargas).
        if ($action === 'reorder') {
            $canvas = CanvasService::get($pageId);
            if ($canvas === null) {
                Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
            }
            $order = Request::post('order', []);
            $order = is_array($order) ? array_values(array_filter(array_map('strval', $order))) : [];
            $newHtml = CanvasService::reorderSections((string) $canvas['html'], $order);
            if ($newHtml === null) {
                // La lista no cuadra con la página: el cliente venía de un DOM
                // viejo. Mejor un conflicto que un reordenado a medias.
                Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
            }
            $saved = CanvasService::save($pageId, $newHtml, (string) $canvas['css'], 'structure', __('canvas.hist.reorder'));
            Response::json([
                'ok' => true,
                'changed' => true,
                'action' => $action,
                'changed_section' => '',
                'focus_section' => $order[0] ?? '',
                'history' => CanvasService::historyState($pageId),
                'sections' => CanvasService::listSections($saved['html']),
            ]);
        }

        if ($action !== 'insert_template' && $sectionId === '') {
            Response::json(['ok' => false, 'error' => __('canvas.error.missing_section')], 422);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
        }

        if ($action === 'insert_template') {
            $template = trim((string) Request::post('template', ''));
            $position = trim((string) Request::post('position', ''));
            if (!in_array($position, ['before', 'after'], true)) {
                Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
            }

            $lang = \App\Services\LanguageService::forPage($page, $siteId);
            $block = CanvasSectionTemplates::render(
                $template,
                $lang,
                null,
                base_url('public/assets/img/studio-placeholder.svg')
            );
            if ($block === null) {
                Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
            }

            $newHtml = CanvasService::insertSectionRelative(
                (string) $canvas['html'],
                $block['html'],
                $sectionId,
                $position
            );
            if ($newHtml === null) {
                Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
            }

            $saved = CanvasService::save(
                $pageId,
                $newHtml,
                (string) $canvas['css'],
                'structure',
                $block['label'] . ' — ' . __('canvas.hist.change')
            );
            Response::json([
                'ok' => true,
                'changed' => true,
                'action' => $action,
                'reply' => __('cv.template_added', ['bloque' => $block['label']]),
                'changed_section' => $block['id'],
                'focus_section' => $block['id'],
                'history' => CanvasService::historyState($pageId),
                'sections' => CanvasService::listSections($saved['html']),
            ]);
        }

        $before = CanvasService::listSections($canvas['html']);
        $index = null;
        $label = $sectionId;
        foreach ($before as $i => $part) {
            if ((string) ($part['id'] ?? '') !== $sectionId) continue;
            $index = $i;
            $label = (string) ($part['label'] ?? $sectionId);
            break;
        }
        if ($index === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
        }

        $focusSection = $sectionId;
        $changedSection = $sectionId;
        if ($action === 'move') {
            $direction = trim((string) Request::post('direction', ''));
            if (!in_array($direction, ['up', 'down'], true)) {
                Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
            }
            $newHtml = CanvasService::moveSection($canvas['html'], $sectionId, $direction);
            $summary = $label . ' — ' . __('canvas.hist.change');
        } elseif ($action === 'duplicate') {
            // STUDIO-UX F1 — La copia se queda seleccionada: es la que el
            // usuario va a editar, no el original.
            $copy = CanvasService::duplicateSection($canvas['html'], $sectionId);
            $newHtml = $copy['html'] ?? null;
            // STUDIO-UX F8 — Desde "Añadir a la página" el usuario ya ha elegido
            // DÓNDE. Sin esto la copia caía siempre pegada al original y se
            // ignoraba el punto de inserción que acababa de marcar.
            $anchor = trim((string) Request::post('anchor', ''));
            $anchorPos = trim((string) Request::post('position', ''));
            if ($newHtml !== null && $anchor !== '' && in_array($anchorPos, ['before', 'after'], true)) {
                $detached = CanvasService::deleteSection($newHtml, (string) $copy['id']);
                $fragment = CanvasService::sectionHtml($newHtml, (string) $copy['id']);
                $placed = ($detached !== null && $fragment !== null)
                    ? CanvasService::insertSectionRelative($detached, $fragment, $anchor, $anchorPos)
                    : null;
                if ($placed !== null) $newHtml = $placed;
            }
            $summary = $label . ' — ' . __('canvas.hist.duplicate');
            $focusSection = (string) ($copy['id'] ?? $sectionId);
            $changedSection = $focusSection;
        } elseif ($action === 'delete') {
            $newHtml = CanvasService::deleteSection($canvas['html'], $sectionId);
            $summary = $label . ' — ' . __('canvas.hist.change');
            $next = $before[$index + 1]['id'] ?? $before[$index - 1]['id'] ?? '';
            $focusSection = (string) $next;
        } else {
            Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
        }

        if ($newHtml === null) {
            // El DOM pudo cambiar entre el listado y la operación; se trata como
            // conflicto recuperable, no como una inserción/mutación aproximada.
            Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
        }

        if ($newHtml === trim((string) $canvas['html'])) {
            Response::json([
                'ok' => true,
                'changed' => false,
                'action' => $action,
                'changed_section' => $changedSection,
                'focus_section' => $focusSection,
                'history' => CanvasService::historyState($pageId),
                'sections' => $before,
            ]);
        }

        $saved = CanvasService::save($pageId, $newHtml, $canvas['css'], 'structure', $summary);
        Response::json([
            'ok' => true,
            'changed' => true,
            'action' => $action,
            'changed_section' => $action === 'delete' ? '' : $changedSection,
            'focus_section' => $focusSection,
            'history' => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
        ]);
    }

    /**
     * Posición común para los bloques funcionales del Studio.
     * Sin `position` conserva el contrato anterior (después o al final).
     */
    private static function insertAtRequestedPosition(string $pageHtml, string $insertHtml, string $sectionId): string
    {
        $position = trim((string) Request::post('position', ''));
        if ($position === '') {
            return CanvasService::insertAfterSection($pageHtml, $insertHtml, $sectionId);
        }
        if (!in_array($position, ['before', 'after'], true)) {
            Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
        }

        $result = CanvasService::insertSectionRelative($pageHtml, $insertHtml, $sectionId, $position);
        if ($result === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 409);
        }
        return $result;
    }

    /**
     * FH4 — Guardado de edición directa (texto/imagen) de UNA sección,
     * sin IA. El iframe envía la sección serializada; aquí se revierten los
     * embeds a placeholders, se integra en la página y se versiona (`inline`).
     */
    public function updateSection(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $sectionId = trim((string) Request::post('section', ''));
        $sectionHtml = (string) Request::post('html', '');
        if ($sectionId === '' || trim($sectionHtml) === '') {
            Response::json(['ok' => false, 'error' => __('canvas.error.missing_section')], 422);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.no_canvas')], 404);
        }

        $clean = CanvasService::normalizeEditedSectionHtml($sectionHtml);
        $newHtml = CanvasService::replaceSection($canvas['html'], $sectionId, $clean);
        if ($newHtml === null) {
            Response::json(['ok' => false, 'error' => __('canvas.error.part_not_found')], 404);
        }

        $summary = CanvasChatService::sectionLabel($sectionId) . ' — ' . __('canvas.hist.inline');
        CanvasService::save($pageId, $newHtml, $canvas['css'], 'inline', $summary);
        Response::json(['ok' => true, 'history' => CanvasService::historyState($pageId)]);
    }

    /**
     * CANCEL — POST /admin/canvas/{id}/cancel
     * Marca una generación en curso para que NO se guarde.
     */
    public function cancel(array $params = []): void
    {
        $siteId = self::requireSiteId();
        self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();

        $requestId = trim((string) Request::post('request_id', ''));
        if (!CanvasCancelToken::isValidId($requestId)) {
            Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
        }

        // Cerrar la sesión cuanto antes: esta petición solo escribe un fichero.
        Session::close();
        CanvasCancelToken::cancel($siteId, $requestId);

        Response::json(['ok' => true]);
    }

    /** FH6 — Deshacer: mueve el puntero a la versión anterior. */
    public function undo(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $state = CanvasService::undo((int) $page['id']);
        Response::json($state !== null
            ? ['ok' => true, 'history' => $state]
            : ['ok' => false, 'error' => 'No hay nada que deshacer.'], $state !== null ? 200 : 409);
    }

    /** FH6 — Rehacer: mueve el puntero a la versión siguiente. */
    public function redo(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $state = CanvasService::redo((int) $page['id']);
        Response::json($state !== null
            ? ['ok' => true, 'history' => $state]
            : ['ok' => false, 'error' => 'No hay nada que rehacer.'], $state !== null ? 200 : 409);
    }


    public function versions(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        Response::json([
            'ok' => true,
            'history' => CanvasService::historyState((int) $page['id']),
            'versions' => array_map(static function (array $v): array {
                $summary = trim((string) ($v['summary'] ?? ''));
                $fallback = match ((string) $v['origin']) {
                    'generate' => __('canvas.hist.generate'),
                    'chat' => 'Cambio por chat',
                    'restore' => __('canvas.hist.restore'),
                    'inline' => __('canvas.hist.inline'),
                    default => __('canvas.hist.edit'),
                };
                return [
                    'id' => (int) $v['id'],
                    'origin' => (string) $v['origin'],
                    'label' => $summary !== '' ? $summary : $fallback,
                    'kind' => match ((string) $v['origin']) {
                        'generate' => __('canvas.hist.generation'),
                        'chat' => __('canvas.hist.chat'),
                        'inline' => __('canvas.hist.inline'),
                        default => __('canvas.hist.change'),
                    },
                    'is_current' => (bool) ($v['is_current'] ?? false),
                    'created_at' => (string) $v['created_at'],
                ];
            }, CanvasService::versions((int) $page['id'])),
        ]);
    }

    public function restore(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $versionId = (int) Request::post('version_id', '0');
        $state = $versionId > 0 ? CanvasService::restore((int) $page['id'], $versionId) : null;
        Response::json($state !== null
            ? ['ok' => true, 'reply' => __('canvas.version_restored'), 'history' => $state]
            : ['ok' => false, 'error' => __('canvas.error.version_not_found')], $state !== null ? 200 : 404);
    }

    public function publish(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();
        self::requireEditLock((int) $page['id']);
        $publish = Request::post('publish', '1') === '1';
        Database::execute(
            "UPDATE pages SET status = ?, published_at = ?, updated_at = NOW() WHERE id = ?",
            [$publish ? 'published' : 'draft', $publish ? date('Y-m-d H:i:s') : null, $pageId]
        );
        // STUDIO-UX C4 — Publicar deja al aire el estado de trabajo ACTUAL, y
        // vuelve a hacerlo cada vez (es también el "Publicar cambios").
        // Despublicar suelta el puntero: la página deja de tener versión viva.
        $publish ? CanvasService::markPublished($pageId) : CanvasService::clearPublished($pageId);
        \App\Services\CacheService::flush($siteId);
        Response::json([
            'ok' => true,
            'status' => $publish ? 'published' : 'draft',
            'history' => CanvasService::historyState($pageId),
        ]);
    }

    /**
     * FH8 — Guarda los ajustes SEO de la página canvas (meta_title,
     * meta_description, slug) desde el modal "Ajustes" del Studio. JSON.
     */
    public function saveSettings(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();
        self::requireEditLock((int) $page['id']);

        $metaTitle = trim((string) Request::post('meta_title', ''));
        $metaDescription = trim((string) Request::post('meta_description', ''));
        $slugInput = trim((string) Request::post('slug', ''));
        $seoNoindex = Request::post('seo_noindex', '') === '1' ? 1 : 0;
        $seoExcludeSitemap = Request::post('seo_exclude_sitemap', '') === '1' ? 1 : 0;
        $canonicalUrl = SeoIndexingService::normalizeCanonical((string) Request::post('canonical_url', ''));
        if (trim((string) Request::post('canonical_url', '')) !== '' && $canonicalUrl === null) {
            Response::json(['ok' => false, 'error' => 'La canonical debe empezar por http:// o https://.'], 422);
        }

        // El "home" siempre cuelga de "/"; no se le toca el slug.
        if (($page['page_type'] ?? '') === 'home') {
            $slug = (string) $page['slug'];
        } else {
            $base = slugify($slugInput !== '' ? $slugInput : (string) $page['title']);
            $slug = PageController::uniqueSlug($siteId, $base, $pageId);
        }

        Database::execute(
            'UPDATE pages SET meta_title = ?, meta_description = ?, slug = ?, seo_noindex = ?, seo_exclude_sitemap = ?, canonical_url = ?, updated_at = NOW() WHERE id = ?',
            [$metaTitle !== '' ? $metaTitle : null, $metaDescription !== '' ? $metaDescription : null, $slug, $seoNoindex, $seoExcludeSitemap, $canonicalUrl, $pageId]
        );

        if (($page['status'] ?? '') === 'published'
            && (string) ($page['slug'] ?? '') !== $slug
            && ($page['page_type'] ?? '') !== 'home'
        ) {
            try {
                SeoRedirectService::createAutomaticSlugRedirect(
                    $siteId,
                    (string) $page['slug'],
                    $slug,
                    $pageId,
                    \Core\Auth::id()
                );
            } catch (\Throwable $e) {
                error_log('[SEO] automatic canvas redirect failed for page ' . $pageId . ': ' . $e->getMessage());
            }
        }
        \App\Services\CacheService::flush($siteId);

        Response::json([
            'ok' => true,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'slug' => $slug,
            'seo_noindex' => $seoNoindex,
            'seo_exclude_sitemap' => $seoExcludeSitemap,
            'canonical_url' => $canonicalUrl,
            'public_url' => base_url(ltrim($slug, '/')),
        ]);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /**
     * Modelos seleccionables en el chat del Studio: el principal y el auxiliar
     * configurados, más la lista curada del proveedor. Devuelve IDs únicos en
     * orden (principal primero). Sirve para pintar el selector y para validar
     * el modelo que llega en la petición (no aceptamos IDs arbitrarios).
     *
     * @return string[]
     */
    private static function chatModelIds(int $siteId): array
    {
        $meta = AIProviderFactory::currentMeta($siteId);
        $ids = array_merge(
            [(string) ($meta['model'] ?? ''), (string) ($meta['model_light'] ?? '')],
            SettingsAIController::suggestedModelsFor((string) ($meta['provider'] ?? ''))
        );
        return array_values(array_unique(array_filter($ids, static fn ($m) => $m !== '')));
    }

    /** Etiqueta legible para un ID de modelo ("google/gemini-3.5-flash" → "Gemini 3.5 Flash"). */
    private static function humanModelLabel(string $id): string
    {
        $tail = strpos($id, '/') !== false ? substr($id, strrpos($id, '/') + 1) : $id;
        $tail = str_replace(['-', '_'], ' ', $tail);
        $tail = preg_replace('/\s*:\s*free\b/i', ' (gratis)', $tail) ?? $tail;
        return ucwords(trim($tail));
    }

    /** Opciones del selector de modelo del Studio: [id, label, default]. */
    private static function chatModelOptions(int $siteId): array
    {
        $ids = self::chatModelIds($siteId);
        $main = (string) (AIProviderFactory::currentMeta($siteId)['model'] ?? '');
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'label' => self::humanModelLabel($id) . ($id === $main ? ' (actual)' : ''),
                'default' => $id === $main,
            ];
        }
        return $out;
    }

    /**
     * Overlay de selección para el iframe del studio: resalta secciones al
     * pasar el ratón, selección con clic, y comunica con el parent.
     */
    /**
     * Overlay del studio dentro del iframe de preview:
     *  - hover/clic en sección → selección para el chat (FH3)
     *  - clic en un TEXTO → edición directa con el cursor (FH4)
     *  - clic en una IMAGEN → selector de la biblioteca (FH4)
     * Los embeds del sistema ([data-pp-placeholder]) no se editan inline.
     */
    private static function overlayScript(): string
    {
        return <<<'HTML'
<style>
  [data-pp-section]{transition:outline-color .15s ease}
  [data-pp-section].pp-studio-hover{outline:2px dashed color-mix(in srgb, var(--pp-primary) 65%, transparent);outline-offset:-2px}
  [data-pp-section].pp-studio-selected{outline:3px solid var(--pp-primary);outline-offset:-3px}
  .pp-studio-tag{position:absolute;z-index:9999;background:var(--pp-primary);color:var(--pp-on-primary,#fff);font:600 12px/1 var(--pp-font-body,sans-serif);padding:6px 10px;border-radius:6px;pointer-events:none;transform:translateY(-100%)}
  /* SEC-BAR — acciones de estructura sobre la propia seccion (patron Elementor).
     La barra monta sobre la linea superior de la seccion seleccionada; si es la
     primera y no hay sitio arriba, se mete dentro. z-index por debajo de
     .pp-studio-rt: cuando se edita texto manda la barra de formato. */
  .pp-studio-secbar{position:absolute;z-index:9998;display:flex;align-items:center;gap:2px;background:#fff;border:1px solid #e5e7eb;border-radius:9px;box-shadow:0 10px 30px rgba(17,24,39,.18);padding:3px}
  .pp-studio-secbar[hidden]{display:none}
  .pp-studio-secbar button{display:grid;place-items:center;width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:#4b5563;cursor:pointer;padding:0}
  .pp-studio-secbar button:hover:not(:disabled){background:#f3f4f6;color:#111827}
  .pp-studio-secbar button:disabled{opacity:.28;cursor:default}
  .pp-studio-secbar button.is-danger:hover:not(:disabled){background:#fef2f2;color:#b91c1c}
  .pp-studio-secbar svg{display:block}
  /* SEC-BAR-4 — "+" en la juntura entre dos partes. */
  .pp-studio-addhere{position:absolute;z-index:9997;display:inline-flex;align-items:center;gap:6px;transform:translate(-50%,-50%);background:var(--pp-primary);color:var(--pp-on-primary,#fff);border:0;border-radius:999px;padding:7px 13px;font:700 12px/1 system-ui,-apple-system,'Segoe UI',sans-serif;cursor:pointer;box-shadow:0 8px 22px rgba(17,24,39,.26)}
  .pp-studio-addhere[hidden]{display:none}
  .pp-studio-addhere svg{display:block}
  .pp-studio-text-hover{outline:1.5px dashed color-mix(in srgb, var(--pp-primary) 55%, transparent);outline-offset:3px;cursor:text;border-radius:2px}
  .pp-studio-box-hover{outline:2px solid color-mix(in srgb, var(--pp-primary) 65%, transparent);outline-offset:3px;cursor:pointer}
  .pp-studio-editing{outline:2px solid var(--pp-primary);outline-offset:3px;border-radius:2px;cursor:text}
  /* STUDIO-UX F3 — barra de formato sobre la selección */
  .pp-studio-rt{position:absolute;z-index:10000;display:flex;align-items:center;gap:2px;background:#fff;border:1px solid #e5e7eb;border-radius:9px;box-shadow:0 10px 30px rgba(17,24,39,.18);padding:4px;font:500 13px/1 system-ui,-apple-system,'Segoe UI',sans-serif;color:#374151}
  .pp-studio-rt[hidden]{display:none}
  .pp-studio-rt button{display:grid;place-items:center;min-width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:inherit;font:inherit;cursor:pointer;padding:0 7px}
  .pp-studio-rt button:hover{background:#f3f4f6;color:#111827}
  .pp-studio-rt button.is-on{background:var(--pp-primary);color:var(--pp-on-primary,#fff)}
  .pp-studio-rt button[hidden]{display:none}
  .pp-studio-rt svg{display:block}
  .pp-studio-rt__link{display:flex;align-items:center;gap:4px;margin-left:4px;padding-left:6px;border-left:1px solid #e5e7eb}
  .pp-studio-rt__link[hidden]{display:none}
  .pp-studio-rt__link select,.pp-studio-rt__link input{height:28px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#374151;font:inherit;font-size:12px;padding:0 6px;max-width:170px}
  .pp-studio-rt__link input{min-width:150px}
  .pp-studio-rt__link button{background:var(--pp-primary);color:var(--pp-on-primary,#fff);font-weight:600;font-size:12px}
  .pp-studio-rt__link button:hover{background:var(--pp-primary);filter:brightness(1.08);color:var(--pp-on-primary,#fff)}
  [data-pp-section] img:not([data-pp-no-edit]):hover{outline:2.5px solid var(--pp-primary);outline-offset:2px;cursor:pointer;filter:brightness(.92)}
  [data-pp-placeholder]{cursor:pointer}
  /* STUDIO-2 B3 — destello sobre la parte que acaba de cambiar. */
  @keyframes pp-studio-flash{0%{box-shadow:inset 0 0 0 3px var(--pp-primary,#111827)}60%{box-shadow:inset 0 0 0 3px var(--pp-primary,#111827)}100%{box-shadow:inset 0 0 0 3px transparent}}
  .pp-studio-flash{animation:pp-studio-flash 1.8s ease-out}
  @media (prefers-reduced-motion:reduce){.pp-studio-flash{animation:none}}
</style>
<script>
(function(){
  // Etiquetas que son texto sin discusión. Se conservan como atajo: cuando el
  // clic cae dentro de un <h2> o un <p>, se edita ESE, no el <span> de dentro.
  var EDITABLE = 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,a';

  // Lo que puede vivir DENTRO de un texto sin que deje de ser un texto: formato
  // en línea e iconos. Un <p> o un <div> que solo contenga esto sigue siendo
  // una frase que se edita entera.
  var INLINE_OK = 'a,abbr,b,br,code,em,i,mark,s,small,span,strong,sub,sup,time,u,svg,path,g,use,circle,rect,line,polyline,polygon';

  // Lo que NO es texto por mucho que lleve letras dentro: media, controles,
  // listas y contenedores de página.
  var NOT_TEXT = 'img,picture,video,audio,iframe,canvas,input,textarea,select,button,form,label,ul,ol,dl,table,section,header,footer,nav,main,article,aside,figure';

  /** ¿Tiene texto propio, no solo texto de sus hijos? */
  function hasOwnText(el){
    for(var n = el.firstChild; n; n = n.nextSibling){
      if(n.nodeType === 3 && n.nodeValue.trim() !== '') return true;
    }
    return false;
  }

  /**
   * ¿Este elemento es "un texto" que se puede editar a mano?
   *
   * Antes esto era una lista de etiquetas, y por eso había textos intocables:
   * un sobretítulo escrito como `<span class="eyebrow">CONTACT & RÉSERVATIONS</span>`
   * o un chip como `<div class="badge">Nuevo</div>` no estaban en la lista, así
   * que el clic no hacía nada y había que pedirle el cambio a la IA. La IA
   * maqueta con las etiquetas que le parecen, así que la lista nunca iba a
   * estar completa: mejor mirar QUÉ es el elemento y no cómo se llama.
   */
  function isTextish(el){
    if(!el || el.nodeType !== 1 || !el.matches) return false;
    if(el.hasAttribute('data-pp-section')) return false;
    if(el.matches(NOT_TEXT)) return false;
    if((el.textContent || '').trim() === '') return false;
    var kids = el.children;
    for(var i = 0; i < kids.length; i++){
      if(!kids[i].matches(INLINE_OK)) return false;
    }
    // Varios hijos y ni una letra propia = una FILA de cosas (una tira de
    // chips, por ejemplo), no una frase. Editarla entera fundiría las piezas en
    // un solo bloque de texto.
    if(kids.length > 1 && !hasOwnText(el)) return false;
    return true;
  }

  /**
   * Qué hay que editar cuando se toca `el`: el texto de siempre si lo hay y,
   * si no, el envoltorio de texto más EXTERNO que siga siéndolo. Lo de "más
   * externo" importa: en `<div class="chip"><span>Nuevo</span></div>` se edita
   * el chip entero, no el span de dentro.
   */
  function editableFrom(el){
    if(!el || !el.closest) return null;
    // EMB-4 — Dentro de un embed no se edita nada porque su HTML se regenera…
    // salvo los textos que SON opciones del placeholder, que sí sobreviven
    // porque al guardar se escriben ahí. El servidor los marca.
    var embField = el.closest('[data-pp-embed-field]');
    if(embField) return embField;
    if(inEmbed(el)) return null;
    var sec = sectionOf(el);
    if(!sec) return null;
    var quick = el.closest(EDITABLE);
    if(quick && sectionOf(quick)) return quick;
    var best = null;
    for(var cur = el; cur && cur !== sec; cur = cur.parentElement){
      if(isTextish(cur)) best = cur;
    }
    return best;
  }
  var selected = null, tag = null, editing = null, editingOriginal = '', activeTarget = null;

  // Igual que en el servidor: si la sección trae `data-pp-label` (los bloques
  // insertados desde el panel), ese es el nombre que entiende el gestor.
  function label(id, el){
    if(el){ var l = (el.getAttribute('data-pp-label')||'').trim(); if(l) return l; }
    var s = id.replace(/[-_]+/g,' '); return s.charAt(0).toUpperCase()+s.slice(1);
  }
  function post(type, data){ parent.postMessage(Object.assign({source:'pp-studio', type:type}, data||{}), '*'); }
  function sectionOf(el){ return el.closest('[data-pp-section]'); }
  function inEmbed(el){ return !!el.closest('[data-pp-placeholder]'); }

  // ---------- RSV-UI: ajustes del calendario de reservas ----------
  // Dentro de un embed no se puede tocar nada (el HTML se regenera en cada
  // render), así que lo editable es el PLACEHOLDER: `booking:auto|days=14|width=full`
  // es lo único que sobrevive al guardado, porque `normalizeEditedSectionHtml()`
  // reconstruye el embed a partir de `data-pp-placeholder`.
  var BOOKING_WIDTHS = ['card','wide','full'];

  function bookingEmbedOf(sec){
    return sec ? sec.querySelector('[data-pp-placeholder^="booking:"]') : null;
  }

  function bookingOpts(embed){
    var ref = embed ? (embed.getAttribute('data-pp-placeholder') || '') : '';
    // `service` es la referencia tal cual está guardada: `auto` (el primero
    // activo, que puede cambiar solo) o el id de un servicio concreto.
    var head = ref.split('|')[0];
    var out = { days: '14', width: 'card', service: head.slice(head.indexOf(':') + 1) || 'auto' };
    ref.split('|').slice(1).forEach(function(kv){
      var i = kv.indexOf('=');
      if(i < 0) return;
      var k = kv.slice(0, i).trim(), v = kv.slice(i + 1).trim();
      if(k === 'days' || k === 'width') out[k] = v;
    });
    if(BOOKING_WIDTHS.indexOf(out.width) < 0) out.width = 'card';
    return out;
  }

  // Reescribe una opción del placeholder conservando el resto. El orden
  // canónico lo vuelve a fijar el servidor al guardar; aquí basta con que la
  // clave quede una sola vez.
  function setBookingOpt(embed, key, value){
    var ref = embed.getAttribute('data-pp-placeholder') || '';
    var head = ref.split('|')[0];
    var opts = bookingOpts(embed);
    opts[key] = value;
    embed.setAttribute('data-pp-placeholder', head + '|days=' + opts.days + '|width=' + opts.width);
    return opts;
  }

  // Cambia el SERVICIO conservando ancho y días: cambiar de calendario no puede
  // deshacer de paso cómo estaba colocado en la página.
  function setBookingService(embed, ref){
    var opts = bookingOpts(embed);
    embed.setAttribute('data-pp-placeholder', 'booking:' + ref + '|days=' + opts.days + '|width=' + opts.width);
    return opts;
  }

  // ---------- EMB-OPT: los ajustes de CUALQUIER embed ----------
  // El calendario ya tenía los suyos (arriba). Esto es lo mismo para el resto:
  // formulario, entradas, productos y recursos guardan sus ajustes en el propio
  // placeholder, `tipo:ref|clave=valor`, que es lo único que sobrevive al
  // guardado. La diferencia con el calendario es que aquí el HTML lo tiene que
  // rehacer el servidor, así que cada cambio pide recargar la vista.
  function embedOf(sec){
    return sec ? sec.querySelector('[data-pp-placeholder]') : null;
  }
  function embedKind(embed){
    var ref = embed ? (embed.getAttribute('data-pp-placeholder') || '') : '';
    var i = ref.indexOf(':');
    return i < 0 ? '' : ref.slice(0, i).toLowerCase();
  }
  function embedOpts(embed){
    var ref = embed ? (embed.getAttribute('data-pp-placeholder') || '') : '';
    var parts = ref.split('|');
    var head = parts[0];
    var out = { ref: head.slice(head.indexOf(':') + 1) };
    parts.slice(1).forEach(function(kv){
      var i = kv.indexOf('=');
      if(i < 0) return;
      out[kv.slice(0, i).trim()] = kv.slice(i + 1).trim();
    });
    return out;
  }
  // `|` y `}` cortarían el placeholder por la mitad, y los saltos de línea no
  // sobreviven al ida y vuelta: fuera antes de escribir nada.
  function cleanOptValue(v){
    return String(v == null ? '' : v).replace(/[|}{\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function setEmbedOpt(embed, key, value){
    var kind = embedKind(embed);
    var opts = embedOpts(embed);
    var clean = cleanOptValue(value);
    // Vacío = quitar la opción, no guardarla en blanco: así el bloque vuelve a
    // su comportamiento por defecto en vez de quedarse con un hueco.
    if(clean === '') delete opts[key]; else opts[key] = clean;
    var out = kind + ':' + opts.ref;
    Object.keys(opts).forEach(function(k){
      if(k !== 'ref') out += '|' + k + '=' + opts[k];
    });
    embed.setAttribute('data-pp-placeholder', out);
    return opts;
  }

  function showTag(el){
    if(!tag){ tag = document.createElement('div'); tag.className='pp-studio-tag'; document.body.appendChild(tag); }
    var r = el.getBoundingClientRect();
    tag.textContent = label(el.getAttribute('data-pp-section'), el);
    tag.style.left = (r.left + window.scrollX + 12) + 'px';
    tag.style.top = (r.top + window.scrollY + 28) + 'px';
    tag.style.display = 'block';
  }
  function hideTag(){ if(tag) tag.style.display='none'; }

  // ---------- SEC-BAR: acciones de la parte seleccionada, sobre ella ----------
  // Son las MISMAS cuatro acciones de la lista de partes, puestas donde el
  // usuario esta mirando. El overlay no toca la base de datos: manda la orden
  // al padre, que ya sabe llamar al endpoint de estructura (y deshacer).
  var secbar = null, addHere = null, addHereFor = null;
  var SECBAR_ICONS = {
    up: '<path d="M6 15l6-6 6 6"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    duplicate: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
    'delete': '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13"/>'
  };
  function svgIcon(path){
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
      + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }
  function sectionsInOrder(){
    return Array.prototype.slice.call(document.querySelectorAll('[data-pp-section]'));
  }
  function secbarTitles(){
    return {
      up: t('move_up','Mover hacia arriba'),
      down: t('move_down','Mover hacia abajo'),
      duplicate: t('duplicate_section','Duplicar'),
      'delete': t('delete_section','Eliminar')
    };
  }
  function buildSecbar(){
    if(secbar) return secbar;
    secbar = document.createElement('div');
    secbar.className = 'pp-studio-secbar';
    secbar.setAttribute('role','toolbar');
    secbar.hidden = true;
    ['up','down','duplicate','delete'].forEach(function(action){
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('data-secbar', action);
      if(action === 'delete') b.className = 'is-danger';
      b.innerHTML = svgIcon(SECBAR_ICONS[action]);
      // mousedown: sin esto el clic en la barra sale de la seccion y la
      // deselecciona antes de que llegue el click.
      b.addEventListener('mousedown', function(ev){ ev.preventDefault(); ev.stopPropagation(); });
      b.addEventListener('click', function(ev){
        ev.preventDefault(); ev.stopPropagation();
        if(!selected || b.disabled) return;
        post('structure', { action: action, id: selected.getAttribute('data-pp-section') });
      });
      secbar.appendChild(b);
    });
    document.body.appendChild(secbar);
    relabelSecbar();
    return secbar;
  }
  // Las etiquetas llegan del padre (studio-config) DESPUES de construirse la
  // barra, asi que hay que poder reescribirlas.
  function relabelSecbar(){
    if(!secbar) return;
    var titles = secbarTitles();
    Array.prototype.forEach.call(secbar.querySelectorAll('[data-secbar]'), function(b){
      var txt = titles[b.getAttribute('data-secbar')] || '';
      b.title = txt;
      b.setAttribute('aria-label', txt);
    });
  }
  function showSecbar(){
    if(!selected || editing){ hideSecbar(); return; }
    var bar = buildSecbar();
    var all = sectionsInOrder(), i = all.indexOf(selected);
    bar.querySelector('[data-secbar="up"]').disabled = i <= 0;
    bar.querySelector('[data-secbar="down"]').disabled = i < 0 || i === all.length - 1;
    bar.hidden = false;
    positionSecbar();
  }
  function hideSecbar(){ if(secbar) secbar.hidden = true; }
  function positionSecbar(){
    if(!secbar || secbar.hidden || !selected) return;
    var r = selected.getBoundingClientRect();
    var h = secbar.offsetHeight || 36, w = secbar.offsetWidth || 140;
    // Con sitio arriba, la barra cabalga sobre la linea de la seccion; pegada
    // al borde superior de la pagina (el hero), se mete dentro.
    var top = r.top >= h ? r.top + window.scrollY - h + 14 : r.top + window.scrollY + 8;
    var left = Math.max(r.left + window.scrollX + 8, r.right + window.scrollX - w - 12);
    secbar.style.top = top + 'px';
    secbar.style.left = Math.max(4, left) + 'px';
  }

  // ---------- SEC-BAR-4: "+" en la juntura entre partes ----------
  function buildAddHere(){
    if(addHere) return addHere;
    addHere = document.createElement('button');
    addHere.type = 'button';
    addHere.className = 'pp-studio-addhere';
    addHere.hidden = true;
    addHere.innerHTML = svgIcon('<path d="M12 5v14M5 12h14"/>') + '<span></span>';
    addHere.addEventListener('mousedown', function(ev){ ev.preventDefault(); ev.stopPropagation(); });
    addHere.addEventListener('click', function(ev){
      ev.preventDefault(); ev.stopPropagation();
      if(!addHereFor) return;
      post('insert-here', { anchor: addHereFor.id, position: addHereFor.position });
    });
    document.body.appendChild(addHere);
    return addHere;
  }
  function hideAddHere(){ if(addHere) addHere.hidden = true; addHereFor = null; }
  function updateAddHere(e){
    if(addHere && !addHere.hidden && (e.target === addHere || addHere.contains(e.target))) return;
    if(editing){ hideAddHere(); return; }
    var sec = sectionOf(e.target);
    if(!sec){ hideAddHere(); return; }
    var r = sec.getBoundingClientRect(), NEAR = 26, pos = null;
    if(e.clientY - r.top <= NEAR) pos = 'before';
    else if(r.bottom - e.clientY <= NEAR) pos = 'after';
    if(!pos){ hideAddHere(); return; }
    var b = buildAddHere();
    addHereFor = { id: sec.getAttribute('data-pp-section'), position: pos };
    b.querySelector('span').textContent = t('add_here','Anadir aqui');
    b.hidden = false;
    b.style.left = (r.left + r.width / 2 + window.scrollX) + 'px';
    b.style.top = ((pos === 'before' ? r.top : r.bottom) + window.scrollY) + 'px';
  }
  // Freno por reloj, no por requestAnimationFrame: en una pestana que no se
  // esta pintando el rAF no llega nunca y el "+" no volveria a salir.
  var addHereTick = 0;
  document.addEventListener('mousemove', function(e){
    var now = Date.now();
    if(now - addHereTick < 60) return;
    addHereTick = now;
    updateAddHere(e);
  }, {passive:true});
  document.addEventListener('mouseleave', hideAddHere);

  window.addEventListener('scroll', function(){ positionSecbar(); hideAddHere(); }, true);
  // El ancho del lienzo cambia con una transicion CSS del padre: sin la
  // segunda pasada la barra se queda donde estaba el borde antiguo.
  window.addEventListener('resize', function(){ positionSecbar(); setTimeout(positionSecbar, 320); });

  function selectSection(sec, toggle, editingFlag){
    if(toggle && selected === sec){
      sec.classList.remove('pp-studio-selected'); selected = null;
      hideSecbar();
      post('section-deselected');
      return;
    }
    if(selected && selected !== sec) selected.classList.remove('pp-studio-selected');
    selected = sec; sec.classList.add('pp-studio-selected');
    showSecbar();
    post('section-selected', { id: sec.getAttribute('data-pp-section'), label: label(sec.getAttribute('data-pp-section'), sec), editing: !!editingFlag });
  }

  // ---------- Serializado y guardado de la sección editada ----------
  // Re-monta un comportamiento de pp-ux tras cambiarlo en caliente.
  function remountBehaviors(el){
    if(window.ppUx && typeof window.ppUx.remount === 'function') window.ppUx.remount(el);
  }

  function serializeAndSave(sec){
    var clone = sec.cloneNode(true);
    clone.querySelectorAll('[contenteditable]').forEach(function(n){ n.removeAttribute('contenteditable'); });
    clone.querySelectorAll('[data-pp-edit-box]').forEach(function(n){ n.removeAttribute('data-pp-edit-box'); });
    clone.querySelectorAll('[data-pp-img-edit],[data-pp-bg-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); n.removeAttribute('data-pp-bg-edit'); });
    if(sec.matches('[data-pp-bg-edit]')) clone.removeAttribute('data-pp-bg-edit');
    clone.querySelectorAll('.pp-studio-editing,.pp-studio-text-hover,.pp-studio-box-hover,.pp-studio-hover,.pp-studio-selected').forEach(function(n){
      n.classList.remove('pp-studio-editing','pp-studio-text-hover','pp-studio-box-hover','pp-studio-hover','pp-studio-selected');
      if(!n.getAttribute('class')) n.removeAttribute('class');
    });
    clone.classList.remove('pp-studio-hover','pp-studio-selected');
    if(!clone.getAttribute('class')) clone.removeAttribute('class');
    post('section-changed', { id: sec.getAttribute('data-pp-section'), html: clone.outerHTML });
  }
  function saveTargetSection(){ var sec = sectionOf(activeTarget); if(sec) serializeAndSave(sec); }

  // ---------- Panel contextual: describir y aplicar ----------

  function elementKind(el){
    if(!el) return null;
    if(el.tagName === 'IMG') return 'image';
    if(el.tagName === 'A' || el.tagName === 'BUTTON') return 'link';
    if(el.matches && el.matches('[data-pp-edit-box]')) return 'box';
    if(el.matches && el.matches('h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,span')) return 'text';
    // Un sobretítulo en <div> o un chip son texto aunque no estén en la lista:
    // si se pueden editar a mano, el panel tiene que ofrecer tamaño, color y
    // alineación, no los controles de una caja cualquiera.
    if(isTextish(el)) return 'text';
    if(el.matches && el.matches('[data-pp-section]')) return 'section';
    return null;
  }

  function visualBoxFrom(el){
    var sec = sectionOf(el); var cur = el;
    while(cur && cur !== sec){
      if(cur.matches && cur.matches('div,span,strong,small,article,aside')){
        var cs = getComputedStyle(cur); var text = (cur.textContent || '').trim();
        var bg = cs.backgroundColor && cs.backgroundColor !== 'transparent' && cs.backgroundColor !== 'rgba(0, 0, 0, 0)';
        var shaped = parseFloat(cs.borderRadius) > 0 || parseFloat(cs.paddingLeft) > 6 || parseFloat(cs.paddingTop) > 4;
        if(text && text.length <= 240 && (bg || shaped)) return cur;
      }
      cur = cur.parentElement;
    }
    return null;
  }

  // Un enlace "parece botón" si tiene relleno o forma de botón (clase o fondo).
  function looksLikeButton(el){
    if(/pp-btn|btn|cta/i.test(el.className || '')) return true;
    var cs = getComputedStyle(el);
    var bg = cs.backgroundColor;
    var hasBg = bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)';
    var hasPad = parseFloat(cs.paddingLeft) > 6 && parseFloat(cs.paddingTop) > 4;
    return hasBg || hasPad;
  }

  // Imagen de fondo de una sección (cubre la sección, no es contenido en flujo).
  function bgImageOf(sec){
    var imgs = sec.querySelectorAll('img');
    var sr = sec.getBoundingClientRect();
    for(var i=0;i<imgs.length;i++){
      var im = imgs[i]; var cs = getComputedStyle(im); var r = im.getBoundingClientRect();
      var coverFit = cs.objectFit === 'cover';
      var coversW = r.width >= sr.width * 0.85;
      var coversH = r.height >= sr.height * 0.5;
      var absish = /absolute|fixed/.test(getComputedStyle(im.parentNode).position) || /absolute|fixed/.test(cs.position);
      if((coverFit && coversW && coversH) || (absish && coversW)) return im;
    }
    return null;
  }

  // Separa las capas de un `background-image` respetando los paréntesis:
  // "linear-gradient(a,b),url(x)" → ["linear-gradient(a,b)", "url(x)"].
  function splitLayers(value){
    var out = [], depth = 0, cur = '';
    for(var i=0;i<value.length;i++){
      var c = value.charAt(i);
      if(c === '(') depth++;
      else if(c === ')') depth--;
      else if(c === ',' && depth === 0){ out.push(cur); cur = ''; continue; }
      cur += c;
    }
    if(cur.trim() !== '') out.push(cur);
    return out;
  }

  // Capas del fondo leídas del estilo COMPUTADO (no solo del inline): el velo
  // que pone la IA suele vivir en la hoja de estilos de la página, y leer solo
  // el inline lo perdía al cambiar la foto.
  function bgLayers(el){
    var bi = el ? (getComputedStyle(el).backgroundImage || '') : '';
    if(bi === '' || bi === 'none') return { veils: [], url: null };
    var veils = [], url = null;
    splitLayers(bi).forEach(function(layer){
      var l = layer.trim();
      // i18n-ignore: comentario dentro del JS embebido, no es interfaz.
      if(l === '' || l === 'none') return;   // 'none' no es un velo: es una capa vacía
      if(/^url\(/i.test(l)){ if(url === null) url = l; }
      else veils.push(l);
    });
    return { veils: veils, url: url };
  }

  // Fondo aplicado por CSS (`background-image: url(...)`), inline o por hoja de
  // estilos. Devuelve la URL de la imagen (ignora capas linear-gradient de velo).
  function cssBgUrlOf(el){
    var url = bgLayers(el).url;
    if(!url) return null;
    var m = url.match(/url\((['"]?)([^'")]+)\1\)/i);
    return m ? m[2] : null;
  }

  // El estilo computado devuelve URLs ABSOLUTAS. Guardarlas ataría la página al
  // dominio actual (y rompería las imágenes al cambiar de dominio), así que las
  // del propio sitio vuelven a ruta relativa antes de escribirlas.
  function siteUrl(u){
    try {
      var p = new URL(u, location.href);
      return p.origin === location.origin ? p.pathname + p.search : u;
    } catch(e){ return u; }
  }

  // ¿Quién lleva DE VERDAD el fondo de esta sección? Puede ser la propia
  // sección, un envoltorio interior (la IA suele crear un `.hero__bg`) o un
  // <img> de cobertura. Asumir que siempre era la <section> dejaba el panel sin
  // controles de fondo en cuanto la IA reestructuraba el hero.
  function resolveBgTarget(sec){
    if(!sec) return null;
    var img = bgImageOf(sec);
    if(img) return { el: img, kind: 'img' };
    if(cssBgUrlOf(sec)) return { el: sec, kind: 'css' };
    var sr = sec.getBoundingClientRect();
    var nodes = sec.querySelectorAll('*');
    for(var i=0;i<nodes.length;i++){
      if(!cssBgUrlOf(nodes[i])) continue;
      var r = nodes[i].getBoundingClientRect();
      if(r.width >= sr.width * 0.85 && r.height >= sr.height * 0.5) return { el: nodes[i], kind: 'css' };
    }
    return null;
  }

  // Envoltorio con aire de "caja" (relleno, fondo o esquinas). Sirve para las
  // migas de ámbito: son los saltos intermedios entre el elemento y la sección.
  function isBoxLike(el){
    if(!el || !el.matches || !el.matches('div,span,strong,small,article,aside,figure,header,footer')) return false;
    var cs = getComputedStyle(el);
    var bg = cs.backgroundColor && cs.backgroundColor !== 'transparent' && cs.backgroundColor !== 'rgba(0, 0, 0, 0)';
    return !!bg || parseFloat(cs.borderRadius) > 0 || parseFloat(cs.paddingLeft) > 6 || parseFloat(cs.paddingTop) > 4;
  }

  // Lee las props editables del elemento para prerellenar el panel.
  function describe(el, kind){
    var cs = el ? getComputedStyle(el) : null;
    var p = { kind: kind };
    if(kind === 'text' || kind === 'link' || kind === 'box'){
      p.fontSize = cs ? Math.round(parseFloat(cs.fontSize)) : null;
      p.bold = cs ? (parseInt(cs.fontWeight,10) >= 600) : false;
      p.italic = cs ? (cs.fontStyle === 'italic') : false;
      p.align = el.style.textAlign || (cs ? cs.textAlign : '');
      p.color = cs ? cs.color : '';
      p.text = (el.textContent || '').trim();
    }
    if(kind === 'box' && cs){
      p.fill = cs.backgroundColor;
      p.radiusTopLeft = Math.round(parseFloat(cs.borderTopLeftRadius)) || 0;
      p.radiusTopRight = Math.round(parseFloat(cs.borderTopRightRadius)) || 0;
      p.radiusBottomRight = Math.round(parseFloat(cs.borderBottomRightRadius)) || 0;
      p.radiusBottomLeft = Math.round(parseFloat(cs.borderBottomLeftRadius)) || 0;
    }
    if(kind === 'link'){
      p.href = el.getAttribute('href') || '';
      p.newTab = el.getAttribute('target') === '_blank';
      p.text = (el.textContent || '').trim();
      p.isButton = looksLikeButton(el);
      if(p.isButton && cs) p.fill = cs.backgroundColor;
    }
    if(kind === 'image'){
      p.alt = el.getAttribute('alt') || '';
    }
    if(kind === 'section'){
      p.pad = el.getAttribute('data-pp-pad') || 'default';
      // ANCLAS — el id de la sección es su destino de enlace (#ancla).
      p.anchor = el.getAttribute('id') || '';
      p.reveal = el.getAttribute('data-pp-behavior') === 'reveal';
      // Carrusel dentro de la sección: disposición actual y nº de fotos, para
      // poder ofrecer los controles de galería en el panel.
      var slider = el.querySelector('[data-pp-behavior="slider"]');
      p.slider = slider ? (slider.getAttribute('data-pp-slider') || 'strip') : '';
      p.sliderPhotos = slider ? slider.querySelectorAll('img').length : 0;
      // El fondo puede ser un <img> de cobertura, un background-image por CSS
      // en la propia sección o en un envoltorio interior.
      p.hasBgImage = !!resolveBgTarget(el);
      p.bgcolor = cs ? cs.backgroundColor : '';
      // Si la sección lleva un calendario de reservas, el panel enseña además
      // sus ajustes: era la única pieza del lienzo sin forma de cambiar el
      // ancho, y a 420px fijos se veía como una tarjetita perdida.
      var bkEmbed = bookingEmbedOf(el);
      p.booking = bkEmbed ? bookingOpts(bkEmbed) : null;
      // EMB-2 — El resto de embeds (formulario, entradas, productos, recursos)
      // tenían opciones escritas en el servidor y ninguna forma de tocarlas.
      var emb = embedOf(el);
      var kind = embedKind(emb);
      p.embed = (emb && kind && kind !== 'booking') ? { kind: kind, opts: embedOpts(emb) } : null;
    }
    return p;
  }

  // ---------- Migas de ámbito (Sección ▸ Bloque ▸ elemento) ----------
  // Cuando la IA envuelve el contenido en una caja (p. ej. un velo blanco sobre
  // la foto de fondo), el clic cae SIEMPRE en esa caja y la sección —única con
  // los controles de fondo— quedaba inalcanzable. La cadena permite subir.
  var activeChain = [];

  function buildChain(el){
    var sec = sectionOf(el);
    var chain = [];
    var cur = el;
    while(cur){
      var k = elementKind(cur) || (isBoxLike(cur) ? 'box' : null);
      if(k) chain.push({ el: cur, kind: k });
      if(cur === sec) break;
      cur = cur.parentElement;
    }
    if(sec && (chain.length === 0 || chain[chain.length - 1].el !== sec)){
      chain.push({ el: sec, kind: 'section' });
    }
    chain.reverse();                      // de fuera hacia dentro
    if(chain.length > 5) chain = [chain[0]].concat(chain.slice(chain.length - 4));
    return chain;
  }

  function chainIndexOf(el){
    for(var i = 0; i < activeChain.length; i++) if(activeChain[i].el === el) return i;
    return -1;
  }

  // Camino del elemento dentro de su sección como índices de hijos ("2.0.1").
  // El backend lo usa para marcar EXACTAMENTE ese nodo en el HTML que ve la IA:
  // describirlo en prosa no distingue dos titulares iguales.
  function pathWithinSection(el){
    var sec = sectionOf(el);
    if(!sec || el === sec) return '';
    var parts = [];
    var cur = el;
    while(cur && cur !== sec){
      var parent = cur.parentElement;
      if(!parent) return '';
      var idx = Array.prototype.indexOf.call(parent.children, cur);
      if(idx < 0) return '';
      parts.unshift(idx);
      cur = parent;
    }
    return parts.length && parts.length <= 12 ? parts.join('.') : '';
  }

  function reportSelection(el, keepChain){
    var kind = elementKind(el) || (isBoxLike(el) ? 'box' : null);
    if(!kind) return;
    activeTarget = el;
    var sec = sectionOf(el);
    if(sec && selected !== sec){
      if(selected) selected.classList.remove('pp-studio-selected');
      selected = sec; sec.classList.add('pp-studio-selected');
    }
    if(!keepChain) activeChain = buildChain(el);
    post('element-selected', {
      kind: kind,
      props: describe(el, kind),
      sectionId: sec ? sec.getAttribute('data-pp-section') : '',
      sectionLabel: sec ? label(sec.getAttribute('data-pp-section'), sec) : '',
      chain: activeChain.map(function(c){ return { kind: c.kind }; }),
      chainIndex: chainIndexOf(el),
      elementPath: pathWithinSection(el),
      // STUDIO-UX F2 — qué puede hacer el panel con este elemento.
      structure: (function(){
        if(!canRestructure(el)) return null;
        var sibs = elementSiblings(el);
        var i = sibs.indexOf(el);
        return { canPrev: i > 0, canNext: i > -1 && i < sibs.length - 1, canDelete: sibs.length > 1 };
      })()
    });
  }

  // Cambio de ámbito desde las migas: mismo elemento activo, otra "altura".
  function selectScope(index){
    var item = activeChain[index];
    if(!item || !item.el) return;
    if(item.kind === 'box'){
      document.querySelectorAll('[data-pp-edit-box]').forEach(function(n){ n.removeAttribute('data-pp-edit-box'); });
      item.el.setAttribute('data-pp-edit-box','1');
    }
    if(item.kind === 'image'){
      document.querySelectorAll('[data-pp-img-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); });
      item.el.setAttribute('data-pp-img-edit','1');
    }
    var sec = sectionOf(item.el) || item.el;
    if(sec) selectSection(sec, false);
    item.el.scrollIntoView({ block: 'nearest' });
    reportSelection(item.el, true);
  }

  var PAD_PRESETS = { 'default':'', 'compact':'48', 'normal':'72', 'roomy':'112' };
  var RADIUS_PRESETS = { 'sharp':'0', 'soft':'8px', 'round':'16px', 'pill':'999px' };
  var DIM_PRESETS = { 'none':'', 'soft':'brightness(0.82)', 'medium':'brightness(0.62)', 'strong':'brightness(0.42)' };
  // Velo translúcido sobre fondos CSS para "atenuar" (hacer la imagen menos visible).
  var VEIL_PRESETS = { 'none':0, 'soft':0.35, 'medium':0.6, 'strong':0.8 };

  // Resuelve un valor de color a CSS: 'reset'→'', '#hex'→hex, token→var(--pp-token).
  function colorCss(v){
    if(!v || v === 'reset') return '';
    if(v.charAt(0) === '#') return v;
    return 'var(--pp-' + v + ')';
  }

  // STUDIO-UX F2 — Hermanos de elemento: base de duplicar/mover/eliminar.
  // Solo cuentan los elementos, no los nodos de texto entre ellos.
  function elementSiblings(el){
    if(!el || !el.parentNode) return [];
    return Array.prototype.filter.call(el.parentNode.children, function(n){ return n.nodeType === 1; });
  }

  // Una sección top-level se duplica desde la lista lateral (F1), no aquí; y
  // dentro de un embed ({{form:N}} y compañía) el HTML se regenera al render,
  // así que tocarlo se perdería en el siguiente guardado.
  function canRestructure(el){
    if(!el || !el.parentNode) return false;
    if(el.hasAttribute('data-pp-section')) return false;
    if(inEmbed(el)) return false;
    return sectionOf(el) !== null;
  }

  // El clon no puede arrastrar los ids del original: dos elementos con el mismo
  // id rompen anclas y asociaciones label/input.
  function stripIds(el){
    if(el.hasAttribute('id')) el.removeAttribute('id');
    el.querySelectorAll('[id]').forEach(function(n){ n.removeAttribute('id'); });
  }

  function restructure(msg){
    var el = activeTarget;
    if(!canRestructure(el)) return true;
    var sec = sectionOf(el);
    var sibs = elementSiblings(el);
    var i = sibs.indexOf(el);

    if(msg.op === 'el-duplicate'){
      var copy = el.cloneNode(true);
      stripIds(copy);
      copy.classList.remove('pp-studio-selected','pp-studio-hover','pp-studio-text-hover','pp-studio-box-hover','pp-studio-editing');
      copy.removeAttribute('data-pp-edit-box');
      copy.removeAttribute('data-pp-img-edit');
      el.parentNode.insertBefore(copy, el.nextSibling);
      serializeAndSave(sec);
      // El marcador visual se MUEVE al clon (no se copia): la copia es la que
      // se va a editar, y dejarlo en los dos marcaba dos elementos a la vez.
      ['data-pp-edit-box','data-pp-img-edit'].forEach(function(attr){
        if(el.hasAttribute(attr)){ el.removeAttribute(attr); copy.setAttribute(attr,'1'); }
      });
      copy.scrollIntoView({ block:'nearest' });
      reportSelection(copy);
      return true;
    }

    if(msg.op === 'el-delete'){
      if(sibs.length < 2) return true;   // no dejamos el contenedor vacío
      el.parentNode.removeChild(el);
      activeTarget = null; activeChain = [];
      serializeAndSave(sec);
      post('element-deselected');
      return true;
    }

    if(msg.op === 'el-move'){
      if(msg.value === 'prev' && i > 0) el.parentNode.insertBefore(el, sibs[i - 1]);
      else if(msg.value === 'next' && i < sibs.length - 1) el.parentNode.insertBefore(sibs[i + 1], el);
      else return true;
      serializeAndSave(sec);
      el.scrollIntoView({ block:'nearest' });
      reportSelection(el);
      return true;
    }

    return false;
  }

  function applyToTarget(msg){
    var el = activeTarget;
    if(!el) return;
    if(msg.op === 'el-duplicate' || msg.op === 'el-delete' || msg.op === 'el-move'){ restructure(msg); return; }

    // RSV-UI — cambiar QUÉ servicio enseña un calendario ya insertado. El
    // panel manda la referencia a guardar (`auto` o un id), el id concreto con
    // el que remontar el widget ahora mismo, y el nombre para la lista de partes.
    // EMB-2 — Un ajuste de embed: se escribe en el placeholder, se guarda y se
    // repide la página, porque el bloque lo pinta el servidor a partir de ahí.
    if(msg.op === 'embedopt' && msg.value){
      var embSec = sectionOf(el);
      var embEl = embedOf(embSec);
      if(!embEl) return;
      var key = String(msg.value.key || '');
      if(!/^[a-z_-]{1,20}$/.test(key)) return;
      setEmbedOpt(embEl, key, msg.value.value);
      serializeAndSave(embSec);
      post('reload-preview');
      return;
    }

    if(msg.op === 'bookingservice' && msg.value){
      var svcSec = sectionOf(el);
      var svcEmbed = bookingEmbedOf(svcSec);
      if(!svcEmbed) return;
      var wanted = String(msg.value.ref || '');
      var resolved = parseInt(msg.value.resolved, 10) || 0;
      // RSV-TABS — `auto`, un id, o una lista `3,7` (pestañas).
      if(!/^(auto|\d{1,10}(,\d{1,10})*)$/.test(wanted) || resolved <= 0) return;
      var wasTabs = /,/.test(bookingOpts(svcEmbed).service);
      setBookingService(svcEmbed, wanted);
      var svcBox = svcEmbed.matches('[data-pp-booking]') ? svcEmbed : svcEmbed.querySelector('[data-pp-booking]');
      if(svcBox){
        svcBox.setAttribute('data-service', String(resolved));
        // El widget trae del servidor el nombre y la duración del servicio
        // anterior; remontar es lo que los pone al día.
        if(typeof window.ppBookingMount === 'function') window.ppBookingMount(svcBox);
      }
      // El nombre de la parte lo puso `insertBooking` con el servicio de
      // entonces: sin esto, "Partes de esta página" seguiría nombrando uno que
      // ya no se enseña.
      if(svcSec && typeof msg.value.label === 'string' && msg.value.label !== ''){
        svcSec.setAttribute('data-pp-label', msg.value.label);
      }
      if(!msg.preview) serializeAndSave(svcSec);
      // RSV-TABS — La barra de pestañas la pinta el servidor a partir del
      // placeholder, así que cuando aparece o desaparece no basta con remontar
      // el widget: hay que volver a pedir la página.
      if(!msg.preview && (wasTabs || /,/.test(wanted))) post('reload-preview');
      return;
    }

    // RSV-UI — ancho y ventana de agenda del calendario de reservas.
    if(msg.op === 'bookingwidth' || msg.op === 'bookingdays'){
      var bkSec = sectionOf(el);
      var bkEmbed = bookingEmbedOf(bkSec);
      if(!bkEmbed) return;
      var isWidth = msg.op === 'bookingwidth';
      var val = String(msg.value || '');
      if(isWidth && BOOKING_WIDTHS.indexOf(val) < 0) return;
      if(!isWidth && !/^\d{1,2}$/.test(val)) return;
      setBookingOpt(bkEmbed, isWidth ? 'width' : 'days', val);
      // El contenedor del widget es el propio embed (o el div de dentro, según
      // venga del render). Se le cambia el atributo y se refresca en vivo: sin
      // esto habría que recargar para ver el efecto de lo que acabas de pulsar.
      var box = bkEmbed.matches('[data-pp-booking]') ? bkEmbed : bkEmbed.querySelector('[data-pp-booking]');
      if(box){
        if(isWidth){
          box.setAttribute('data-width', val);
          BOOKING_WIDTHS.forEach(function(w){
            box.classList.remove('pp-booking-embed--w-' + w, 'ppbk--w-' + w);
          });
          box.classList.add('pp-booking-embed--w-' + val, 'ppbk--w-' + val);
        } else {
          box.setAttribute('data-days', val);
          // La agenda hay que volver a pedirla: el widget expone su montaje.
          if(typeof window.ppBookingMount === 'function') window.ppBookingMount(box);
        }
      }
      if(!msg.preview) serializeAndSave(bkSec);
      return;
    }
    var sectionOps = { pad:1, reveal:1, bgcolor:1, bgimg:1, bgdim:1, sliderlayout:1, gallery:1, anchor:1 };

    if(msg.op === 'size'){
      var cur = Math.round(parseFloat(getComputedStyle(el).fontSize)) || 16;
      if(msg.value === 'reset'){ el.style.removeProperty('font-size'); }
      else {
        var next = msg.value === 'up' ? Math.min(96, Math.round(cur*1.12)) : Math.max(11, Math.round(cur/1.12));
        el.style.fontSize = next + 'px';
      }
    }
    else if(msg.op === 'bold'){ el.style.fontWeight = msg.value ? '700' : ''; }
    else if(msg.op === 'italic'){ el.style.fontStyle = msg.value ? 'italic' : ''; }
    else if(msg.op === 'align'){ el.style.textAlign = msg.value || ''; }
    else if(msg.op === 'color'){
      if(msg.value === 'reset') el.style.removeProperty('color');
      else el.style.color = colorCss(msg.value);
    }
    else if(msg.op === 'fill'){
      if(msg.value === 'reset') el.style.removeProperty('background');
      else if(msg.value === 'none'){ el.style.background = 'transparent'; }
      else el.style.background = colorCss(msg.value);
    }
    else if(msg.op === 'radius'){
      if(msg.value === 'reset') el.style.removeProperty('border-radius');
      else el.style.borderRadius = RADIUS_PRESETS[msg.value] || msg.value;
    }
    else if(msg.op === 'corner-radius' && msg.value){
      var cornerMap = {'top-left':'border-top-left-radius','top-right':'border-top-right-radius','bottom-right':'border-bottom-right-radius','bottom-left':'border-bottom-left-radius'};
      var prop = cornerMap[msg.value.corner];
      if(prop) el.style.setProperty(prop, Math.max(0, Math.min(200, parseInt(msg.value.px,10) || 0)) + 'px');
    }
    else if(msg.op === 'link'){ if(msg.value) el.setAttribute('href', msg.value); }
    else if(msg.op === 'newtab'){
      if(msg.value){ el.setAttribute('target','_blank'); el.setAttribute('rel','noopener'); }
      else { el.removeAttribute('target'); el.removeAttribute('rel'); }
    }
    else if(msg.op === 'settext'){ if(typeof msg.value === 'string') el.textContent = msg.value; }
    else if(msg.op === 'alt'){ el.setAttribute('alt', msg.value || ''); }
    else if(sectionOps[msg.op]){
      var sec = sectionOf(el); if(!sec) return;
      if(msg.op === 'pad'){
        sec.setAttribute('data-pp-pad', msg.value);
        var px = PAD_PRESETS[msg.value];
        if(px){ sec.style.paddingTop = px+'px'; sec.style.paddingBottom = px+'px'; }
        else { sec.style.removeProperty('padding-top'); sec.style.removeProperty('padding-bottom'); }
      } else if(msg.op === 'anchor'){
        // ANCLAS — el panel manda el ancla ya normalizada; vacía significa
        // "vuelve a la de por defecto" (la del propio data-pp-section).
        var an = String(msg.value || '').trim();
        sec.setAttribute('id', an !== '' ? an : sec.getAttribute('data-pp-section'));
      } else if(msg.op === 'reveal'){
        if(msg.value) sec.setAttribute('data-pp-behavior','reveal');
        else if(sec.getAttribute('data-pp-behavior')==='reveal') sec.removeAttribute('data-pp-behavior');
      } else if(msg.op === 'bgcolor'){
        // backgroundColor (no shorthand) para no borrar una imagen de fondo CSS.
        if(msg.value === 'reset') sec.style.removeProperty('background-color');
        else sec.style.backgroundColor = colorCss(msg.value);
      } else if(msg.op === 'bgdim'){
        var dimTarget = resolveBgTarget(sec);
        if(dimTarget && dimTarget.kind === 'img'){
          var f = DIM_PRESETS[msg.value] || '';
          if(f) dimTarget.el.style.filter = f; else dimTarget.el.style.removeProperty('filter');
        } else if(dimTarget){ // fondo por CSS: velo translúcido sobre la imagen
          var u = cssBgUrlOf(dimTarget.el);
          if(u){
            u = siteUrl(u);
            var a = VEIL_PRESETS[msg.value] || 0;
            dimTarget.el.style.backgroundImage = a > 0
              ? 'linear-gradient(rgba(255,255,255,'+a+'),rgba(255,255,255,'+a+')),url("'+u+'")'
              : 'url("'+u+'")';
            if(!dimTarget.el.style.backgroundSize) dimTarget.el.style.backgroundSize = 'cover';
            if(!dimTarget.el.style.backgroundPosition) dimTarget.el.style.backgroundPosition = 'center';
          }
        }
      } else if(msg.op === 'sliderlayout'){
        // Disposición del carrusel: tira horizontal, una a una o vertical.
        var sl = sec.querySelector('[data-pp-behavior="slider"]');
        if(sl){
          if(msg.value === 'strip') sl.removeAttribute('data-pp-slider');
          else sl.setAttribute('data-pp-slider', msg.value);
          remountBehaviors(sl);
        }
      } else if(msg.op === 'gallery'){
        // Sustituye las fotos del carrusel por las elegidas en la biblioteca.
        var slg = sec.querySelector('[data-pp-behavior="slider"]');
        var photos = Array.isArray(msg.value) ? msg.value : [];
        if(slg && photos.length){
          var host = slg.querySelector('.pp-ux-slider__track') || slg;
          var slides = Array.prototype.filter.call(host.children, function(n){ return n.nodeType === 1 && !n.classList.contains('pp-ux-slider__arrow') && !n.classList.contains('pp-ux-slider__dots'); });
          var template = slides[0];
          if(template){
            // El primer slide hace de plantilla: así las fotos nuevas heredan
            // el maquetado que ya tenía la galería (pies, estilos, proporción).
            var frag = document.createDocumentFragment();
            photos.forEach(function(ph){
              var node = template.cloneNode(true);
              var img = node.querySelector('img');
              if(!img){ img = document.createElement('img'); node.insertBefore(img, node.firstChild); }
              img.setAttribute('src', ph.src);
              img.setAttribute('alt', ph.alt || '');
              frag.appendChild(node);
            });
            slides.forEach(function(n){ n.parentNode.removeChild(n); });
            host.appendChild(frag);
            remountBehaviors(slg);
          }
        }
      } else if(msg.op === 'bgimg'){
        var bgT = resolveBgTarget(sec);
        if(msg.value === 'mark'){
          document.querySelectorAll('[data-pp-img-edit],[data-pp-bg-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); n.removeAttribute('data-pp-bg-edit'); });
          if(bgT && bgT.kind === 'img'){ bgT.el.setAttribute('data-pp-img-edit','1'); }
          else if(bgT){ bgT.el.setAttribute('data-pp-bg-edit','1'); }
          else { sec.setAttribute('data-pp-bg-edit','1'); } // aún no hay fondo: lo estrena la sección
          return; // el padre abrirá la biblioteca; replace-image guardará
        }
        if(msg.value === 'remove'){
          if(bgT && bgT.kind === 'img'){
            var wrap = bgT.el.closest('[class*=overlay], [class*=bg], [class*=image], [class*=media]');
            if(wrap && wrap !== sec && sectionOf(wrap) === sec) wrap.remove(); else bgT.el.remove();
          } else if(bgT){ // fondo CSS: quitarlo (none inline gana a la hoja de estilos)
            bgT.el.style.backgroundImage = 'none';
            bgT.el.style.removeProperty('background-size');
            bgT.el.style.removeProperty('background-position');
          }
        }
      }
      if(!msg.preview) serializeAndSave(sec);
      return;
    }
    if(!msg.preview) saveTargetSection();
  }

  // ---------- STUDIO-UX F3: barra de formato sobre la selección ----------
  // Antes se editaba en `plaintext-only`: poner una palabra en negrita o un
  // enlace dentro de una frase obligaba a pedirle a la IA que reescribiera la
  // sección entera. Ahora es una selección y un clic.
  var rt = null, rtLinkRow = null, rtUrl = null, rtPageSel = null, savedRange = null;
  var studioLabels = {}, linkTargets = [];

  function t(key, fallback){ return studioLabels[key] || fallback; }

  function buildToolbar(){
    if(rt) return rt;
    rt = document.createElement('div');
    rt.className = 'pp-studio-rt';
    rt.setAttribute('role', 'toolbar');
    rt.innerHTML = ''
      + '<button type="button" data-rt="bold" title="' + t('bold','Negrita') + '"><b>B</b></button>'
      + '<button type="button" data-rt="italic" title="' + t('italic','Cursiva') + '"><i>I</i></button>'
      + '<button type="button" data-rt="link" title="' + t('link','Enlace') + '">'
        + '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>'
      + '</button>'
      + '<button type="button" data-rt="unlink" title="' + t('unlink','Quitar enlace') + '" hidden>'
        + '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 7l3 3-3 3M7 17l-3-3 3-3"/><path d="M4 4l16 16"/></svg>'
      + '</button>'
      + '<div class="pp-studio-rt__link" hidden>'
        + '<select data-rt-page><option value="">' + t('link_page','Elegir una página…') + '</option></select>'
        + '<input type="text" data-rt-url placeholder="' + t('link_url','https://… o /pagina') + '">'
        + '<button type="button" data-rt="apply-link">' + t('link_apply','Enlazar') + '</button>'
      + '</div>';
    document.body.appendChild(rt);
    rtLinkRow = rt.querySelector('.pp-studio-rt__link');
    rtUrl = rt.querySelector('[data-rt-url]');
    rtPageSel = rt.querySelector('[data-rt-page]');
    fillLinkTargets();

    // Sin esto el navegador quita la selección al pulsar y `execCommand` no
    // tiene sobre qué actuar.
    rt.addEventListener('mousedown', function(e){
      if(e.target.closest('input,select')) return;
      e.preventDefault();
    });

    rt.addEventListener('click', function(e){
      var btn = e.target.closest('[data-rt]');
      if(!btn) return;
      var op = btn.dataset.rt;
      if(op === 'bold' || op === 'italic'){ exec(op); return; }
      if(op === 'unlink'){ exec('unlink'); return; }
      if(op === 'link'){ openLinkRow(); return; }
      if(op === 'apply-link'){ applyLink(); return; }
    });

    rtPageSel.addEventListener('change', function(){ if(rtPageSel.value) rtUrl.value = rtPageSel.value; });
    rtUrl.addEventListener('keydown', function(e){
      if(e.key === 'Enter'){ e.preventDefault(); applyLink(); }
      if(e.key === 'Escape'){ e.preventDefault(); closeLinkRow(); }
    });
    return rt;
  }

  function exec(cmd, value){
    if(!editing) return;
    editing.focus();
    restoreRange();
    document.execCommand(cmd, false, value || null);
    positionToolbar();
    saveRange();
  }

  function saveRange(){
    var sel = window.getSelection();
    if(sel && sel.rangeCount) savedRange = sel.getRangeAt(0).cloneRange();
  }
  function restoreRange(){
    if(!savedRange) return;
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }

  function openLinkRow(){
    saveRange();
    fillLinkTargets();   // las secciones (y sus anclas) pueden haber cambiado
    rtLinkRow.hidden = false;
    var a = currentLink();
    rtUrl.value = a ? (a.getAttribute('href') || '') : '';
    rtUrl.focus();
    rtUrl.select();
    positionToolbar();
  }
  function closeLinkRow(){
    if(rtLinkRow) rtLinkRow.hidden = true;
    if(editing){ editing.focus(); restoreRange(); }
    positionToolbar();
  }
  function applyLink(){
    var url = (rtUrl.value || '').trim();
    if(url === ''){ closeLinkRow(); return; }
    if(editing) editing.focus();
    restoreRange();
    document.execCommand('createLink', false, url);
    closeLinkRow();
    if(editing) serializeAndSave(sectionOf(editing));
  }

  function currentLink(){
    var sel = window.getSelection();
    if(!sel || !sel.anchorNode) return null;
    var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;
    var a = node && node.closest ? node.closest('a') : null;
    return (a && editing && editing.contains(a)) ? a : null;
  }

  function selectionInsideEditing(){
    var sel = window.getSelection();
    if(!editing || !sel || sel.isCollapsed || !sel.rangeCount) return false;
    var r = sel.getRangeAt(0);
    return editing.contains(r.commonAncestorContainer.nodeType === 1
      ? r.commonAncestorContainer
      : r.commonAncestorContainer.parentNode);
  }

  function positionToolbar(){
    if(!rt || rt.hidden) return;
    var sel = window.getSelection();
    if(!sel || !sel.rangeCount) return;
    var r = sel.getRangeAt(0).getBoundingClientRect();
    if(!r.width && !r.height) return;
    var box = rt.getBoundingClientRect();
    var left = Math.max(8, Math.min(window.innerWidth - box.width - 8, r.left + r.width / 2 - box.width / 2));
    var top = r.top + window.scrollY - box.height - 10;
    if(top < window.scrollY + 4) top = r.bottom + window.scrollY + 10;
    rt.style.left = Math.round(left + window.scrollX) + 'px';
    rt.style.top = Math.round(top) + 'px';
  }

  function refreshToolbar(){
    if(!editing){ hideToolbar(); return; }
    // Con la fila de enlace abierta el foco está en el input: la selección se
    // ha ido, pero la barra debe seguir ahí.
    if(rt && rtLinkRow && !rtLinkRow.hidden) return;
    if(!selectionInsideEditing()){ hideToolbar(); return; }
    buildToolbar();
    rt.hidden = false;
    saveRange();
    var unlinkBtn = rt.querySelector('[data-rt="unlink"]');
    if(unlinkBtn) unlinkBtn.hidden = !currentLink();
    rt.querySelector('[data-rt="bold"]').classList.toggle('is-on', document.queryCommandState('bold'));
    rt.querySelector('[data-rt="italic"]').classList.toggle('is-on', document.queryCommandState('italic'));
    positionToolbar();
  }

  function hideToolbar(){
    if(!rt) return;
    rt.hidden = true;
    if(rtLinkRow) rtLinkRow.hidden = true;
    savedRange = null;
  }

  document.addEventListener('selectionchange', function(){ setTimeout(refreshToolbar, 0); });
  window.addEventListener('scroll', positionToolbar, true);
  window.addEventListener('resize', positionToolbar);

  function fillLinkTargets(){
    if(!rtPageSel) return;
    var opts = ['<option value="">' + t('link_page','Elegir una página…') + '</option>'];
    linkTargets.forEach(function(p){
      opts.push('<option value="' + p.url + '">' + p.title + '</option>');
    });
    // ANCLAS — enlazar a otra parte de ESTA página, no solo a otra página.
    var inner = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-pp-section]'), function(s){
      var an = s.getAttribute('id');
      if(!an) return;
      inner.push('<option value="#' + an + '">' + label(s.getAttribute('data-pp-section'), s) + '</option>');
    });
    if(inner.length){
      opts.push('<optgroup label="' + t('link_section','Sección de esta página') + '">' + inner.join('') + '</optgroup>');
    }
    rtPageSel.innerHTML = opts.join('');
  }

  // ---------- Edición de texto ----------
  function startEdit(el){
    if(editing === el) return;
    endEdit(true);
    editing = el;
    editingOriginal = el.innerHTML;
    // F3 — `true`, no `plaintext-only`: el texto admite negrita, cursiva y
    // enlaces. El pegado y el Enter se controlan más abajo para que el
    // navegador no meta spans con estilo ni divs sueltos.
    el.contentEditable = 'true';
    try { document.execCommand('styleWithCSS', false, false); } catch(e) { /* navegadores viejos */ }
    el.classList.add('pp-studio-editing');
    hideSecbar(); hideAddHere();
    var sec = sectionOf(el);
    if(sec) selectSection(sec, false, true);
    hideSecbar();
    reportSelection(el);
    // El foco debe quedarse AQUÍ (el panel del chat no debe robarlo).
    setTimeout(function(){ if(editing === el && document.activeElement !== el) el.focus(); }, 0);
  }
  function endEdit(commit){
    if(!editing) return;
    hideToolbar();
    var el = editing; editing = null;
    el.removeAttribute('contenteditable');
    el.classList.remove('pp-studio-editing');
    if(!el.getAttribute('class')) el.removeAttribute('class');
    if(!commit){ el.innerHTML = editingOriginal; return; }
    if(el.innerHTML !== editingOriginal){
      var sec = sectionOf(el);
      // EMB-4 — Un título de embed no se guarda como HTML: se escribe en el
      // placeholder, que es lo único que sobrevive al regenerado del bloque.
      var field = el.getAttribute('data-pp-embed-field');
      var fieldEmbed = field ? embedOf(sec) : null;
      if(field && fieldEmbed){
        setEmbedOpt(fieldEmbed, field, el.textContent || '');
        if(sec) serializeAndSave(sec);
        post('reload-preview');
      } else if(sec) {
        serializeAndSave(sec);
      }
    }
    showSecbar();
  }

  // mousedown (no click) para que el navegador coloque el cursor donde tocas.
  document.addEventListener('mousedown', function(e){
    var t = e.target;
    if(secbar && !secbar.hidden && secbar.contains(t)) return;    // barra de la seccion
    if(addHere && !addHere.hidden && addHere.contains(t)) return; // "+" de insercion
    if(rt && !rt.hidden && rt.contains(t)) return;                // barra de formato
    if(editing && (editing === t || editing.contains(t))) return; // seguir editando
    // EMB-4 — La puerta se abre también DENTRO de un embed, pero solo para los
    // textos marcados: `editableFrom()` es quien decide, y para el resto del
    // embed sigue devolviendo null.
    if(t.closest && (!inEmbed(t) || t.closest('[data-pp-embed-field]'))){
      var txt = editableFrom(t);
      // Los enlaces se editan desde el `click` (hay que frenar la navegación).
      if(txt && txt.tagName !== 'A'){ startEdit(txt); return; }
    }
    if(editing) endEdit(true);
  });

  document.addEventListener('click', function(e){
    var t = e.target;
    if(editing && (editing === t || editing.contains(t))) return;

    // Comportamientos interactivos (acordeon, flechas de slider): dejarlos
    // funcionar tambien dentro del studio para poder probarlos.
    if(t.closest && (t.closest('summary') || t.closest('.pp-ux-slider__arrow'))) return;

    // Imagen → seleccionar y mostrar su panel (Reemplazar abre la biblioteca).
    if(t.tagName === 'IMG' && sectionOf(t) && !inEmbed(t)){
      e.preventDefault(); e.stopPropagation();
      document.querySelectorAll('[data-pp-img-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); });
      t.setAttribute('data-pp-img-edit','1');
      selectSection(sectionOf(t), false);
      reportSelection(t);
      return;
    }

    // Enlaces/CTAs: editar su texto al hacer clic (sin navegar).
    if(t.closest && t.closest('a') && sectionOf(t) && !inEmbed(t)){
      e.preventDefault(); e.stopPropagation();
      startEdit(t.closest('a'));
      return;
    }

    var box = visualBoxFrom(t);
    if(box && !inEmbed(box)){
      e.preventDefault(); e.stopPropagation();
      document.querySelectorAll('[data-pp-edit-box]').forEach(function(n){ n.removeAttribute('data-pp-edit-box'); });
      box.setAttribute('data-pp-edit-box','1');
      selectSection(sectionOf(box), false);
      reportSelection(box);
      return;
    }

    var s = sectionOf(t);
    if(!s) return;
    e.preventDefault(); e.stopPropagation();
    if(editableFrom(t)) return; // ya en edición por mousedown
    var wasSelected = (selected === s);
    selectSection(s, true);
    if(!wasSelected) reportSelection(s); // recién seleccionada → panel de sección
    else { activeTarget = null; post('element-deselected'); }
  }, true);

  document.addEventListener('keydown', function(e){
    if(!editing) return;
    if(e.key === 'Escape'){
      // Con la fila de enlace abierta, Esc solo la cierra.
      if(rt && rtLinkRow && !rtLinkRow.hidden){ e.preventDefault(); closeLinkRow(); return; }
      e.preventDefault(); endEdit(false); return;
    }
    // F3 — atajos de toda la vida sobre la selección.
    if((e.metaKey || e.ctrlKey) && !e.altKey){
      var k = e.key.toLowerCase();
      if(k === 'b'){ e.preventDefault(); exec('bold'); return; }
      if(k === 'i'){ e.preventDefault(); exec('italic'); return; }
    }
    if(e.key === 'Enter'){
      if(editing.tagName !== 'P' && editing.tagName !== 'LI'){ e.preventDefault(); endEdit(true); return; }
      // En párrafos y listas, salto de línea explícito: el `contenteditable`
      // por defecto mete <div> sueltos dentro del propio elemento.
      e.preventDefault();
      if(!document.execCommand('insertLineBreak')) document.execCommand('insertHTML', false, '<br>');
    }
  });

  // STUDIO-UX A2/A4 — Los atajos del Studio viven en el documento del padre,
  // pero el foco del usuario está aquí dentro en cuanto toca la página: sin
  // este reenvío nacen muertos (P5). Solo teclas sueltas y solo fuera de
  // cualquier escritura, formularios de la propia página incluidos. Los atajos
  // con modificador (deshacer) son cosa de B2.
  document.addEventListener('keydown', function(e){
    if(editing) return;
    var t = e.target;
    if(t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || '') || t.isContentEditable === true)) return;
    var mod = e.metaKey || e.ctrlKey;
    // B2 — Solo lo que el Studio sabe atender: teclas sueltas y deshacer/rehacer.
    if(mod && e.key.toLowerCase() !== 'z') return;
    post('key', {key: e.key, mods: {mod: mod, shift: e.shiftKey, alt: e.altKey}});
    if(mod) e.preventDefault();   // que el navegador no deshaga por su cuenta
  });

  // Pegar desde Word/Docs/una web arrastra spans, estilos y fuentes: entra
  // como texto plano y el formato lo pone el usuario con la barra.
  document.addEventListener('paste', function(e){
    if(!editing) return;
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    document.execCommand('insertText', false, text);
  });

  document.addEventListener('focusout', function(e){
    if(editing && e.target === editing) setTimeout(function(){
      if(!editing) return;
      // El foco puede haberse ido a la barra de formato: eso no cierra la edición.
      if(document.activeElement === editing) return;
      if(rt && !rt.hidden && rt.contains(document.activeElement)) return;
      endEdit(true);
    }, 0);
  });

  // ---------- Hover ----------
  document.addEventListener('mouseover', function(e){
    var s = sectionOf(e.target);
    document.querySelectorAll('.pp-studio-hover').forEach(function(x){ x.classList.remove('pp-studio-hover'); });
    document.querySelectorAll('.pp-studio-text-hover').forEach(function(x){ x.classList.remove('pp-studio-text-hover'); });
    document.querySelectorAll('.pp-studio-box-hover').forEach(function(x){ x.classList.remove('pp-studio-box-hover'); });
    if(!s){ hideTag(); return; }
    s.classList.add('pp-studio-hover'); showTag(s);
    if(!inEmbed(e.target) || e.target.closest('[data-pp-embed-field]')){
      var txt = editableFrom(e.target);
      if(txt && txt !== editing) txt.classList.add('pp-studio-text-hover');
      else { var box = visualBoxFrom(e.target); if(box) box.classList.add('pp-studio-box-hover'); }
    }
  });

  // ---------- Mensajes del parent ----------
  window.addEventListener('message', function(e){
    var d = e.data || {};
    if(d.source !== 'pp-studio-parent') return;
    if(d.type === 'studio-config'){
      // F3 — el overlay no tiene catálogo propio: microcopia y destinos de
      // enlace llegan del panel, que sí sabe el idioma del usuario.
      if(d.labels) studioLabels = d.labels;
      if(Array.isArray(d.linkTargets)) linkTargets = d.linkTargets;
      if(rt){ rt.remove(); rt = null; }   // se reconstruye con las etiquetas buenas
      relabelSecbar();
      return;
    }
    if(d.type === 'apply'){ applyToTarget(d); return; }
    if(d.type === 'select-scope'){ selectScope(d.index); return; }
    if(d.type === 'deselect'){
      // STUDIO-UX B3 — Contestar SIEMPRE. Antes esto solo limpiaba lo de aquí y
      // el panel del padre se quedaba abierto con los controles de un elemento
      // que ya nadie tenía cogido: pulsar un color no hacía nada y la interfaz
      // igualmente decía «Guardado» (P6).
      if(selected) selected.classList.remove('pp-studio-selected');
      selected = null; activeTarget = null; activeChain = [];
      hideTag(); hideSecbar(); hideAddHere();
      post('element-deselected');
      return;
    }
    if(d.type === 'scroll-to' && d.y != null){ window.scrollTo(0, d.y); }
    if(d.type === 'select' && d.id){
      var el = document.querySelector('[data-pp-section="'+d.id+'"]');
      if(el){
        selectSection(el, false);
        el.scrollIntoView({behavior:'smooth', block:'start'});
        // Desde la lista de partes de la barra lateral: abrir también su panel.
        if(d.panel) reportSelection(el);
      }
    }
    // B3 — Tras aplicar un cambio: llevar la vista a la parte tocada y darle un
    // destello. Sin esto la página se recargaba y el usuario tenía que buscar
    // qué había cambiado.
    if(d.type === 'flash' && d.id){
      var fl = document.querySelector('[data-pp-section="'+d.id+'"]');
      if(fl){
        fl.scrollIntoView({behavior:'smooth', block:'center'});
        fl.classList.remove('pp-studio-flash');
        void fl.offsetWidth;                 // reinicia la animación
        fl.classList.add('pp-studio-flash');
        setTimeout(function(){ fl.classList.remove('pp-studio-flash'); }, 1800);
      }
    }
    // Resalte al pasar el ratón por la lista de partes (sin seleccionar nada).
    if(d.type === 'highlight'){
      document.querySelectorAll('.pp-studio-hover').forEach(function(x){ x.classList.remove('pp-studio-hover'); });
      if(d.id && d.on){
        var hl = document.querySelector('[data-pp-section="'+d.id+'"]');
        if(hl) hl.classList.add('pp-studio-hover');
      }
    }
    if(d.type === 'replace-image' && d.src){
      var img = document.querySelector('[data-pp-img-edit]');
      if(img){
        img.src = d.src;
        if(d.alt) img.alt = d.alt;
        img.removeAttribute('data-pp-img-edit');
        var sec = sectionOf(img);
        if(sec) serializeAndSave(sec);
        return;
      }
      // Fondo por CSS: poner/cambiar la imagen como background-image inline.
      var bgEl = document.querySelector('[data-pp-bg-edit]');
      if(bgEl){
        // Conserva TODAS las capas que no son la foto (velos, degradados),
        // vengan del inline o de la hoja de estilos: cambiar la foto no puede
        // llevarse por delante la capa blanca que puso la IA.
        var keep = bgLayers(bgEl).veils.join(',');
        bgEl.style.backgroundImage = (keep !== '' ? keep + ',' : '') + 'url("'+d.src+'")';
        if(!bgEl.style.backgroundSize) bgEl.style.backgroundSize = 'cover';
        if(!bgEl.style.backgroundPosition) bgEl.style.backgroundPosition = 'center';
        bgEl.removeAttribute('data-pp-bg-edit');
        var secB = sectionOf(bgEl);
        if(secB) serializeAndSave(secB);
      }
    }
  });

  function brandPalette(){
    // Resuelve cada token a su color computado (rgb) usando una sonda, para
    // poder comparar con el color actual de los elementos.
    var probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
    (document.querySelector('.pp-canvas') || document.body).appendChild(probe);
    var resolve = function(token){ probe.style.color = 'var(--pp-' + token + ')'; return getComputedStyle(probe).color; };
    var pal = {
      primary: resolve('primary'), 'text': resolve('text'), 'text-muted': resolve('text-muted'),
      'on-primary': resolve('on-primary'), surface: resolve('surface')
    };
    probe.remove();
    return pal;
  }
  // Cada parte viaja con su nombre visible (data-pp-label si lo trae), para que
  // la lista "Partes de esta página" no tenga que adivinarlo desde el id.
  post('ready', { scrollY: 0, palette: brandPalette(), sections: Array.prototype.map.call(document.querySelectorAll('[data-pp-section]'), function(s){
    var id = s.getAttribute('data-pp-section');
    return { id: id, label: label(id, s), anchor: s.getAttribute('id') || '' };
  }) });
  window.addEventListener('scroll', function(){ hideTag(); }, {passive:true});
})();
</script>
HTML;
    }

    /**
     * EDIT-LOCK L2 — El endpoint del bloqueo: coger, latir y soltar.
     *
     * Las tres operaciones van por una sola ruta porque comparten todo salvo
     * una línea, y tres rutas para eso solo servirían para que un día alguien
     * proteja dos y se olvide de la tercera.
     */
    public function lock(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();

        $op = (string) Request::post('op', 'take');
        $token = trim((string) Request::post('token', ''));
        $userId = (int) \Core\Auth::id();

        if ($token === '') {
            Response::json(['ok' => false, 'error' => __('lock.err.no_token')], 422);
        }

        if ($op === 'ping') {
            $alive = EditLock::heartbeat(EditLock::ENTITY_PAGE, $pageId, $token);
            Response::json([
                'ok'     => $alive,
                'status' => self::lockPayload($pageId, $token),
            ], $alive ? 200 : 409);
        }

        if ($op === 'release') {
            EditLock::release(EditLock::ENTITY_PAGE, $pageId, $token);
            Response::json(['ok' => true]);
        }

        // 'take' — con `force` es «editar de todas formas».
        $force = Request::post('force') === '1';
        $result = EditLock::acquire(EditLock::ENTITY_PAGE, $pageId, $userId, $token, $force);
        EditLock::prune();

        Response::json([
            'ok'     => $result['ok'],
            'status' => self::lockPayload($pageId, $token),
        ], $result['ok'] ? 200 : 409);
    }

    /**
     * Lo que necesita saber el navegador: si puede escribir y, si no, de quién
     * es la página. Nunca devuelve el token ajeno — no le sirve de nada al
     * cliente y sería la llave para suplantar a otra pestaña.
     *
     * @return array<string,mixed>
     */
    private static function lockPayload(int $pageId, string $token): array
    {
        $status = EditLock::status(EditLock::ENTITY_PAGE, $pageId);
        $mine = $status['held'] && $status['token'] === $token;
        return [
            'mine'     => $mine,
            'held'     => $status['held'],
            'username' => $mine ? null : $status['username'],
        ];
    }

    /**
     * EDIT-LOCK L2 — Cortafuegos de TODA escritura del Studio.
     *
     * El Studio autoguarda en cada acción, así que aquí no basta con avisar en
     * la interfaz: sin esta comprobación, una pestaña que perdió el lock —o que
     * nunca lo tuvo— seguiría escribiendo la página entera a espaldas de quien
     * la está editando.
     *
     * Contesta 409 y no 403 a propósito: no es un problema de permisos, es que
     * el recurso está ocupado. El navegador lo distingue para enseñar «la está
     * editando Ana» en vez de «acceso denegado».
     */
    private static function requireEditLock(int $pageId): void
    {
        $token = trim((string) Request::post('lock_token', ''));
        if (EditLock::heldBy(EditLock::ENTITY_PAGE, $pageId, $token)) {
            return;
        }

        $status = EditLock::status(EditLock::ENTITY_PAGE, $pageId);

        // Nadie la tiene: esta escritura se queda el lock y sigue. Sin esto, un
        // fallo de red al arrancar el Studio —o un lock caducado tras un rato
        // sin tocar nada— dejaría al usuario delante de una página que no puede
        // guardar sin saber por qué. Que no haya dueño no es motivo para
        // rechazar a nadie; tenerlo otro, sí.
        if (!$status['held'] && $token !== '') {
            $acquired = EditLock::acquire(
                EditLock::ENTITY_PAGE,
                $pageId,
                (int) \Core\Auth::id(),
                $token
            );
            if ($acquired['ok']) {
                return;
            }
            $status = EditLock::status(EditLock::ENTITY_PAGE, $pageId);
        }
        Response::json([
            'ok'     => false,
            'error'  => $status['held'] && is_string($status['username'])
                ? __('lock.taken_by', ['nombre' => $status['username']])
                : __('lock.lost'),
            'lock'   => self::lockPayload($pageId, $token),
        ], 409);
    }

    private static function findCanvasPage(int $pageId, int $siteId): array
    {
        $page = Database::selectOne(
            "SELECT * FROM pages WHERE id = ? AND site_id = ? AND render_mode = 'canvas' LIMIT 1",
            [$pageId, $siteId]
        );
        if (!$page) Response::notFound();
        return $page;
    }

    private static function requireSiteId(): int
    {
        $siteId = \Core\Auth::siteId();
        if ($siteId === null) Response::redirect(base_url('admin/logout'));
        return $siteId;
    }
}
