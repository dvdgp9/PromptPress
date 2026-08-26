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

        $canvas = CanvasService::renderPublic($pageId, $siteId, $pageLang);
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
        $h .= BrandService::publicHeader($siteId);
        $h .= '<main>' . $canvas['html'] . '</main>';
        $h .= BrandService::publicFooter($siteId);
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
        if (!\App\Modules\ModuleRegistry::isEnabled($siteId, 'booking')) {
            Response::json(['ok' => false, 'error' => __('cv.booking.module_off')], 422);
        }

        // "auto" (o vacío) = el primer servicio activo, que es lo que ofrece el
        // menú por defecto para no obligar a elegir.
        $raw = trim((string) Request::post('service_id', ''));
        $isAuto = ($raw === '' || $raw === 'auto' || $raw === '0');
        $serviceId = \App\Modules\Booking\BookingEmbedRenderer::resolveServiceId($siteId, $isAuto ? 0 : (int) $raw);
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
        $ref = $isAuto ? 'auto' : (string) $serviceId;
        $sectionId = trim((string) Request::post('section', ''));
        $embedId = 'booking-' . $ref . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
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
    public function insertResources(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
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

        $action = trim((string) Request::post('action', ''));
        $sectionId = trim((string) Request::post('section', ''));
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
        if ($action === 'move') {
            $direction = trim((string) Request::post('direction', ''));
            if (!in_array($direction, ['up', 'down'], true)) {
                Response::json(['ok' => false, 'error' => __('canvas.error.bad_request')], 422);
            }
            $newHtml = CanvasService::moveSection($canvas['html'], $sectionId, $direction);
            $summary = $label . ' — ' . __('canvas.hist.change');
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
                'changed_section' => $sectionId,
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
            'changed_section' => $action === 'delete' ? '' : $sectionId,
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
        CSRF::check();
        $publish = Request::post('publish', '1') === '1';
        Database::execute(
            "UPDATE pages SET status = ?, published_at = ?, updated_at = NOW() WHERE id = ?",
            [$publish ? 'published' : 'draft', $publish ? date('Y-m-d H:i:s') : null, (int) $page['id']]
        );
        \App\Services\CacheService::flush($siteId);
        Response::json(['ok' => true, 'status' => $publish ? 'published' : 'draft']);
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
  .pp-studio-text-hover{outline:1.5px dashed color-mix(in srgb, var(--pp-primary) 55%, transparent);outline-offset:3px;cursor:text;border-radius:2px}
  .pp-studio-box-hover{outline:2px solid color-mix(in srgb, var(--pp-primary) 65%, transparent);outline-offset:3px;cursor:pointer}
  .pp-studio-editing{outline:2px solid var(--pp-primary);outline-offset:3px;border-radius:2px;cursor:text}
  [data-pp-section] img:not([data-pp-no-edit]):hover{outline:2.5px solid var(--pp-primary);outline-offset:2px;cursor:pointer;filter:brightness(.92)}
  [data-pp-placeholder]{cursor:pointer}
  /* STUDIO-2 B3 — destello sobre la parte que acaba de cambiar. */
  @keyframes pp-studio-flash{0%{box-shadow:inset 0 0 0 3px var(--pp-primary,#111827)}60%{box-shadow:inset 0 0 0 3px var(--pp-primary,#111827)}100%{box-shadow:inset 0 0 0 3px transparent}}
  .pp-studio-flash{animation:pp-studio-flash 1.8s ease-out}
  @media (prefers-reduced-motion:reduce){.pp-studio-flash{animation:none}}
</style>
<script>
(function(){
  var EDITABLE = 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,a';
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

  function showTag(el){
    if(!tag){ tag = document.createElement('div'); tag.className='pp-studio-tag'; document.body.appendChild(tag); }
    var r = el.getBoundingClientRect();
    tag.textContent = label(el.getAttribute('data-pp-section'), el);
    tag.style.left = (r.left + window.scrollX + 12) + 'px';
    tag.style.top = (r.top + window.scrollY + 28) + 'px';
    tag.style.display = 'block';
  }
  function hideTag(){ if(tag) tag.style.display='none'; }

  function selectSection(sec, toggle, editingFlag){
    if(toggle && selected === sec){
      sec.classList.remove('pp-studio-selected'); selected = null;
      post('section-deselected');
      return;
    }
    if(selected && selected !== sec) selected.classList.remove('pp-studio-selected');
    selected = sec; sec.classList.add('pp-studio-selected');
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
      elementPath: pathWithinSection(el)
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

  function applyToTarget(msg){
    var el = activeTarget;
    if(!el) return;
    var sectionOps = { pad:1, reveal:1, bgcolor:1, bgimg:1, bgdim:1, sliderlayout:1, gallery:1 };

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

  // ---------- Edición de texto ----------
  function startEdit(el){
    if(editing === el) return;
    endEdit(true);
    editing = el;
    editingOriginal = el.innerHTML;
    try { el.contentEditable = 'plaintext-only'; } catch(e) { el.contentEditable = 'true'; }
    if(el.contentEditable !== 'plaintext-only' && el.contentEditable !== 'true') el.setAttribute('contenteditable','true');
    el.classList.add('pp-studio-editing');
    var sec = sectionOf(el);
    if(sec) selectSection(sec, false, true);
    reportSelection(el);
    // El foco debe quedarse AQUÍ (el panel del chat no debe robarlo).
    setTimeout(function(){ if(editing === el && document.activeElement !== el) el.focus(); }, 0);
  }
  function endEdit(commit){
    if(!editing) return;
    var el = editing; editing = null;
    el.removeAttribute('contenteditable');
    el.classList.remove('pp-studio-editing');
    if(!el.getAttribute('class')) el.removeAttribute('class');
    if(!commit){ el.innerHTML = editingOriginal; return; }
    if(el.innerHTML !== editingOriginal){
      var sec = sectionOf(el);
      if(sec) serializeAndSave(sec);
    }
  }

  // mousedown (no click) para que el navegador coloque el cursor donde tocas.
  document.addEventListener('mousedown', function(e){
    var t = e.target;
    if(editing && (editing === t || editing.contains(t))) return; // seguir editando
    if(t.closest && !inEmbed(t)){
      var txt = t.closest(EDITABLE);
      if(txt && sectionOf(txt) && txt.tagName !== 'A'){ startEdit(txt); return; }
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
    if(t.closest(EDITABLE) && !inEmbed(t)) return; // ya en edición por mousedown
    var wasSelected = (selected === s);
    selectSection(s, true);
    if(!wasSelected) reportSelection(s); // recién seleccionada → panel de sección
    else { activeTarget = null; post('element-deselected'); }
  }, true);

  document.addEventListener('keydown', function(e){
    if(!editing) return;
    if(e.key === 'Escape'){ e.preventDefault(); endEdit(false); }
    if(e.key === 'Enter' && editing.tagName !== 'P' && editing.tagName !== 'LI'){ e.preventDefault(); endEdit(true); }
  });
  document.addEventListener('focusout', function(e){
    if(editing && e.target === editing) setTimeout(function(){ if(editing && document.activeElement !== editing) endEdit(true); }, 0);
  });

  // ---------- Hover ----------
  document.addEventListener('mouseover', function(e){
    var s = sectionOf(e.target);
    document.querySelectorAll('.pp-studio-hover').forEach(function(x){ x.classList.remove('pp-studio-hover'); });
    document.querySelectorAll('.pp-studio-text-hover').forEach(function(x){ x.classList.remove('pp-studio-text-hover'); });
    document.querySelectorAll('.pp-studio-box-hover').forEach(function(x){ x.classList.remove('pp-studio-box-hover'); });
    if(!s){ hideTag(); return; }
    s.classList.add('pp-studio-hover'); showTag(s);
    if(!inEmbed(e.target)){
      var txt = e.target.closest(EDITABLE);
      if(txt && txt !== editing && sectionOf(txt)) txt.classList.add('pp-studio-text-hover');
      else { var box = visualBoxFrom(e.target); if(box) box.classList.add('pp-studio-box-hover'); }
    }
  });

  // ---------- Mensajes del parent ----------
  window.addEventListener('message', function(e){
    var d = e.data || {};
    if(d.source !== 'pp-studio-parent') return;
    if(d.type === 'apply'){ applyToTarget(d); return; }
    if(d.type === 'select-scope'){ selectScope(d.index); return; }
    if(d.type === 'deselect' && selected){ selected.classList.remove('pp-studio-selected'); selected = null; activeTarget = null; activeChain = []; }
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
    return { id: id, label: label(id, s) };
  }) });
  window.addEventListener('scroll', function(){ hideTag(); }, {passive:true});
})();
</script>
HTML;
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
