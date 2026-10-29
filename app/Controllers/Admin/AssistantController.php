<?php

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantContentNormalizer;
use App\Services\AssistantConversation;
use App\Services\AssistantConversationStore;
use App\Services\AssistantMediaReferences;
use App\Services\AssistantScreenContext;
use App\Services\AssistantSourceEnvelope;
use App\Services\AssistantProposal;
use App\Services\FormAssistantEditor;
use App\Services\PageSeoAssistantEditor;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use App\Services\TextExtractor;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * FEAT-5 — Asistente central del sitio: cambios multi-página por chat/documento.
 *
 * F5-T1: UI del chat + extracción de texto de un documento adjunto (stateless:
 * el texto extraído viaja con la petición del chat, no se persiste en BD).
 * El documento se procesa en memoria y se descarta: no es un "documento base"
 * del sitio (para eso está /admin/documents), es contexto de UNA petición.
 */
class AssistantController
{
    /** Límite de texto extraído que se conserva como contexto (caracteres). */
    public const MAX_EXTRACT_CHARS = 60000;
    /** HTML saneado del cliente; el backend lo vuelve a normalizar siempre. */
    public const MAX_RICH_HTML_BYTES = 500000;

    // ----------------------------------------------------------------------
    // GET /admin/assistant
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();

        // ASST-V2 F1 — ?c=ID retoma una conversación; ?nueva=1 empieza de cero;
        // sin nada se reabre la última de los últimos días.
        $conversation = null;
        $recent = [];
        try {
            $requested = (int) Request::get('c', 0);
            if ($requested > 0) {
                $conversation = AssistantConversationStore::find($siteId, Auth::id(), $requested);
            } elseif ((string) Request::get('nueva', '') !== '1') {
                $conversation = AssistantConversationStore::latest($siteId, Auth::id());
            }
            $recent = AssistantConversationStore::recent($siteId, Auth::id());
        } catch (\Throwable $e) {
            // Sin la tabla (migración pendiente) el asistente sigue funcionando sin memoria.
            error_log('[assistant] conversations unavailable: ' . $e->getMessage());
        }

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'csrf'       => CSRF::token(),
            'maxSize'    => DocumentController::MAX_SIZE,
            'allowedExt' => DocumentController::ALLOWED_EXT,
            'conversation' => $conversation,
            'recentConversations' => $recent,
        ]);
        View::send('admin/assistant/index', $data);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/conversations/save — ASST-V2 F1: foto de la
    // conversación tras cada paso (plan recibido, aplicado, publicado).
    // ----------------------------------------------------------------------
    public function saveConversation(): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();
        $raw = (string) Request::post('snapshot', '');
        if (strlen($raw) > 2000000) {
            Response::json(['ok' => false, 'error' => __('asst.conv.too_big')], 413);
        }
        $snapshot = json_decode($raw, true);
        if (!is_array($snapshot)) {
            Response::json(['ok' => false, 'error' => __('asst.conv.save_failed')], 422);
        }
        $id = (int) Request::post('id', 0);
        try {
            $saved = AssistantConversationStore::save($siteId, Auth::id(), $id > 0 ? $id : null, $snapshot);
        } catch (\Throwable $e) {
            error_log('[assistant] save conversation site=' . $siteId . ': ' . $e->getMessage());
            $saved = null;
        }
        if ($saved === null) {
            Response::json(['ok' => false, 'error' => __('asst.conv.save_failed')], 422);
        }
        Response::json(['ok' => true, 'id' => $saved]);
    }

    // GET /admin/assistant/conversations/current — ASST-V2 F2: el panel
    // lateral pide la conversación al abrirse (no viaja en cada página).
    public function currentConversation(): void
    {
        $siteId = self::requireSiteId();
        $conversation = null;
        try {
            $conversation = AssistantConversationStore::latest($siteId, Auth::id());
        } catch (\Throwable $e) {
            error_log('[assistant] current conversation: ' . $e->getMessage());
        }
        Response::json(['ok' => true, 'conversation' => $conversation]);
    }

    // POST /admin/assistant/conversations/{id}/delete
    public function deleteConversation(array $params = []): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();
        AssistantConversationStore::delete($siteId, Auth::id(), (int) ($params['id'] ?? 0));
        Session::flash('notice', __('asst.conv.deleted'));
        Response::redirect(base_url('admin/assistant?nueva=1'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/extract — extrae texto del documento adjunto.
    // Responde JSON; el archivo NO se guarda (se procesa desde tmp y se borra).
    // ----------------------------------------------------------------------
    public function extract(): void
    {
        self::requireSiteId();
        CSRF::check();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => __('asst.err.no_file')], 422);
        }
        if ($file['size'] > DocumentController::MAX_SIZE) {
            $maxMb = (int) (DocumentController::MAX_SIZE / 1024 / 1024);
            Response::json(['ok' => false, 'error' => "El archivo supera los {$maxMb} MB permitidos."], 422);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            Response::json(['ok' => false, 'error' => __('asst.err.bad_file')], 422);
        }

        $type = self::detectType($file);
        if ($type === null) {
            Response::json(['ok' => false, 'error' => __('asst.err.unsupported')], 422);
        }

        // TextExtractor necesita la extensión correcta en algunos parsers; el tmp
        // de PHP no la tiene, así que copiamos a un temporal con extensión.
        $tmpBase = tempnam(sys_get_temp_dir(), 'ppa_');
        $tmpPath = $tmpBase . '.' . $type;
        if ($tmpBase === false || !move_uploaded_file($file['tmp_name'], $tmpPath)) {
            Response::json(['ok' => false, 'error' => __('asst.err.process')], 500);
        }

        // Ojo: Response::json hace exit (never), así que un finally no correría
        // en el camino de error. Limpiamos los temporales ANTES de responder.
        @set_time_limit(120);
        $text = null;
        $extractError = null;
        try {
            $text = TextExtractor::extract($tmpPath, $type);
        } catch (\Throwable $e) {
            $extractError = $e->getMessage();
        }
        @unlink($tmpPath);
        @unlink($tmpBase);

        if ($extractError !== null) {
            error_log('[AssistantController::extract] ' . $extractError);
            Response::json(['ok' => false, 'error' => __('asst.err.extract', ['detalle' => $extractError])], 422);
        }

        $truncated = false;
        if (mb_strlen($text) > self::MAX_EXTRACT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_EXTRACT_CHARS);
            $truncated = true;
        }
        if (trim($text) === '') {
            // `code` deja al navegador distinguir un PDF que solo trae imágenes
            // (un diseño): sus páginas ya viajan como imagen y no es un error.
            Response::json(['ok' => false, 'error' => __('asst.err.empty_doc'), 'code' => 'empty_doc'], 422);
        }

        Response::json([
            'ok'        => true,
            'filename'  => (string) $file['name'],
            'type'      => $type,
            'chars'     => mb_strlen($text),
            'truncated' => $truncated,
            'text'      => $text,
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/plan — F5-T2: petición (+doc) → plan clasificado.
    // ----------------------------------------------------------------------
    public function plan(): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();

        $instruction = trim((string) Request::post('instruction', ''));
        $docText     = (string) Request::post('doc_text', '');
        $richHtml    = (string) Request::post('rich_html', '');
        if (strlen($richHtml) > self::MAX_RICH_HTML_BYTES) {
            Response::json(['ok' => false, 'error' => __('asst.err.rich_payload')], 422);
        }
        if ($richHtml === '' && mb_strlen($instruction) > 4000) {
            Response::json(['ok' => false, 'error' => __('asst.err.too_long')], 422);
        }

        // ASST-CONV AC7 — lo ya hablado en esta pestaña: turnos, plan pendiente
        // y el material adjuntado antes (firmado; si caducó, se sigue sin él).
        $history = AssistantConversation::parseHistory((string) Request::post('history', ''));
        $currentPlan = AssistantConversation::parseCurrentPlan((string) Request::post('current_plan', ''));
        $followUp = $history !== [] || $currentPlan !== [];
        $previousBundle = null;
        $previousToken = trim((string) Request::post('source_token', ''));
        if ($previousToken !== '') {
            try {
                $previousBundle = AssistantSourceEnvelope::open($previousToken, $siteId)['bundle'];
            } catch (\InvalidArgumentException $e) {
                error_log('[assistant plan] site=' . $siteId . ' previous material discarded: ' . $e->getMessage());
            }
        }

        $normalized = null;
        if (trim($richHtml) !== '') {
            $normalized = AssistantContentNormalizer::normalize($richHtml, $instruction);
        }
        if ($previousBundle !== null) {
            $normalized = AssistantConversation::mergeBundles($previousBundle, $normalized);
        }
        if ($normalized !== null) {
            $normalized = AssistantMediaReferences::resolve($normalized, $siteId);
            $richContext = trim((string) ($normalized['prompt_text'] ?? ''));
            if ($richContext !== '') {
                // i18n-ignore: etiquetas del material que viaja dentro del prompt.
                $docText = ($followUp ? "[Material de la conversación]\n" : "[Contenido enriquecido pegado]\n") . $richContext
                    . (trim($docText) !== '' ? "\n\n[Documento adjunto]\n" . $docText : '');
            }
        }
        if (mb_strlen($docText) > self::MAX_EXTRACT_CHARS) {
            $docText = mb_substr($docText, 0, self::MAX_EXTRACT_CHARS);
        }
        if (($normalized !== null && trim($docText) === '')
            || ($normalized === null && $instruction === '' && trim($docText) === '')) {
            Response::json(['ok' => false, 'error' => __('asst.err.empty_request')], 422);
        }

        // En una conversación, el último mensaje es la petición: el material
        // acumulado ya va aparte. En el primero, lo pegado es la petición.
        $requestText = $followUp && $instruction !== ''
            ? mb_substr($instruction, 0, 4000)
            : ($normalized !== null
            ? 'Analiza y clasifica la petición pegada respetando su estructura y el orden de sus referencias.'
            : ($instruction !== ''
            ? $instruction
            : 'Aplica los cambios descritos en el documento adjunto.'));

        // ASST-V2 F2 — pantalla desde la que se escribe (panel lateral). El
        // navegador solo dice tipo e id; aquí se comprueba que existe.
        $screen = AssistantScreenContext::resolve(
            $siteId,
            (string) Request::post('context_type', ''),
            (int) Request::post('context_id', 0)
        );

        @set_time_limit(180);
        try {
            $plan = SiteAssistantPlanner::plan(
                $siteId,
                $requestText,
                $docText,
                $normalized,
                AssistantScreenContext::promptBlock($screen) . AssistantConversation::renderForPrompt($history, $currentPlan)
            );
            // ASST-V2 F2 — lo que no se aplica solo lleva un botón al sitio exacto.
            foreach ($plan['items'] as &$planItem) {
                if (($planItem['category'] ?? '') !== 'automatable_now') {
                    $planItem['link'] = AssistantScreenContext::deepLink($planItem, $siteId);
                }
            }
            unset($planItem);
            $roles = (array) ($plan['media_roles'] ?? []);
            // Al navegador como lista: un mapa id => rol con ids sueltos sería un
            // objeto JSON con claves numéricas, incómodo de recorrer.
            $plan['media_roles'] = array_map(
                static fn (int $mediaId, string $role): array => ['media_id' => $mediaId, 'role' => $role],
                array_keys($roles),
                array_values($roles)
            );
            if ($normalized !== null) {
                // ASST-CONV AC5 — el rol de cada imagen viaja firmado hasta el ejecutor.
                $normalized = SiteAssistantPlanner::applyMediaRoles($normalized, $roles);
                $plan['source_token'] = AssistantSourceEnvelope::issue(
                    $siteId,
                    $normalized,
                    is_array($plan['items'] ?? null) ? $plan['items'] : []
                );
            }
        } catch (AIException $e) {
            $errorId = substr(bin2hex(random_bytes(6)), 0, 10);
            error_log('[assistant plan] error_id=' . $errorId . ' site=' . $siteId . ' ai status=' . $e->getHttpStatus() . ': ' . $e->getMessage());
            $message = match (true) {
                in_array($e->getHttpStatus(), [401, 403], true) => __('asst.err.ai_config'),
                $e->getHttpStatus() === 429 => __('asst.err.ai_rate'),
                $e->getHttpStatus() >= 500 => __('asst.err.ai_down'),
                default => __('asst.err.rephrase'),
            };
            Response::json(['ok' => false, 'error' => $message, 'error_id' => $errorId], 502);
        } catch (\Throwable $e) {
            error_log('[assistant plan] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => __('asst.err.no_plan')], 502);
        }

        Response::json([
            'ok' => true,
            'plan' => $plan,
            'ingestion' => $normalized === null ? null : [
                'status' => $normalized['status'],
                'blocks' => count($normalized['blocks']),
                'images' => count($normalized['media']),
                'warnings' => $normalized['warnings'],
            ],
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/preview — ASST-V2 F3: cómo quedaría un formulario
    // antes de aplicar. No guarda nada; devuelve la propuesta firmada para que
    // «Aplicar» guarde exactamente lo que se ha enseñado.
    // ----------------------------------------------------------------------
    public function preview(): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();

        $item = json_decode((string) Request::post('item', ''), true);
        $capability = is_array($item) ? (string) ($item['capability_id'] ?? '') : '';
        $targetId = !is_array($item) ? 0 : (int) ($capability === 'forms.edit' ? ($item['form_id'] ?? 0) : ($item['page_id'] ?? 0));
        $instruction = is_array($item) ? trim((string) ($item['instruction'] ?? '')) : '';
        if (!in_array($capability, ['forms.edit', 'seo.page.edit'], true)
            || $targetId <= 0 || $instruction === '' || mb_strlen($instruction) > 4000) {
            Response::json(['ok' => false, 'error' => __('asst.err.preview_unsupported')], 422);
        }

        if (!AssistantCapabilityRegistry::roleCanExecute(Auth::role(), $capability)) {
            Response::json(['ok' => false, 'error' => __('asst.err.no_permission')], 403);
        }

        @set_time_limit(200);
        $started = microtime(true);
        $isForm = $capability === 'forms.edit';
        try {
            $proposal = $isForm
                ? FormAssistantEditor::propose($siteId, $targetId, $instruction)
                : PageSeoAssistantEditor::propose($siteId, $targetId, $instruction);
        } catch (AIException $e) {
            error_log('[assistant preview] site=' . $siteId . ' ' . $capability . ' target=' . $targetId . ' ai status=' . $e->getHttpStatus() . ': ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => __($isForm ? 'asst.err.form_no_valid_change' : 'asst.err.seo_no_valid_change')], 502);
        }
        if (!$proposal['ok']) {
            Response::json(['ok' => false, 'error' => (string) $proposal['error']], 422);
        }

        $token = AssistantProposal::seal($isForm ? 'form' : 'seo', $siteId, $targetId, $instruction, $proposal['content'], $proposal['base_hash']);
        $preview = $isForm
            ? FormAssistantEditor::preview($proposal['before'], $proposal['content'])
            : PageSeoAssistantEditor::preview($siteId, $targetId, $proposal['before'], $proposal['content']);
        error_log(sprintf('[assistant preview] site=%d %s target=%d changes=%d ms=%d',
            $siteId, $capability, $targetId, count($preview['changes']), (int) ((microtime(true) - $started) * 1000)));
        Response::json(['ok' => true, 'preview' => $preview, 'token' => $token]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/apply — F5-T4: crea el job con los items confirmados.
    // ----------------------------------------------------------------------
    public function apply(): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();

        $raw = (string) Request::post('items', '');
        $items = json_decode($raw, true);
        if (!is_array($items) || $items === []) {
            Response::json(['ok' => false, 'error' => __('asst.err.no_changes')], 422);
        }

        $requestText = trim((string) Request::post('request_text', ''));
        $summary     = trim((string) Request::post('summary', ''));

        $source = null;
        $sourceToken = trim((string) Request::post('source_token', ''));
        if ($sourceToken !== '') {
            try {
                $source = AssistantSourceEnvelope::open($sourceToken, $siteId);
            } catch (\InvalidArgumentException $e) {
                Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        } else {
            foreach ($items as $item) {
                if (is_array($item)
                    && (($item['source_block_ids'] ?? []) !== [] || ($item['media_ids'] ?? []) !== [])) {
                    Response::json([
                        'ok' => false,
                        'error' => 'El material confirmado ya no está disponible. Vuelve a proponer el plan.',
                    ], 422);
                }
            }
        }

        // El asistente no ejecuta lo que esta persona no puede hacer a mano.
        foreach ($items as $item) {
            if (is_array($item) && !AssistantCapabilityRegistry::roleCanExecute(Auth::role(), (string) ($item['capability_id'] ?? ''))) {
                Response::json(['ok' => false, 'error' => __('asst.err.no_permission')], 403);
            }
        }
        $result = SiteAssistantJobs::createJob($siteId, $requestText, $summary, $items, Auth::id(), $source);
        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => (string) $result['error']], 422);
        }
        Response::json(['ok' => true, 'job' => $result['job']]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/jobs/{id}/step — ejecuta el siguiente item pendiente.
    // El navegador lo llama en bucle hasta que job.status === 'done'.
    // ----------------------------------------------------------------------
    public function step(array $params = []): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();

        // Margen: hasta 2 intentos de una edición de página completa (180s de
        // timeout HTTP cada uno) + guardado.
        @set_time_limit(420);
        $result = SiteAssistantJobs::stepJob((int) ($params['id'] ?? 0), $siteId);
        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => (string) $result['error']], 404);
        }
        Response::json(['ok' => true, 'job' => $result['job']]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/assistant/jobs/{id}/publish — ASST-CONV AC8: publica de una
    // vez las páginas que dejó el trabajo, con las cautelas del servicio.
    // ----------------------------------------------------------------------
    public function publish(array $params = []): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();
        $result = SiteAssistantJobs::publishJob((int) ($params['id'] ?? 0), $siteId, (int) Auth::id());
        if (!$result['ok']) {
            Response::json(['ok' => false, 'error' => (string) $result['error']], 422);
        }
        Response::json(['ok' => true, 'pages' => $result['pages']]);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** Detecta pdf|docx|txt por mime real con fallback a extensión (como DocumentController). */
    private static function detectType(array $file): ?string
    {
        // finfo clasifica cualquier contenido "texto-ish" como text/plain aunque
        // el archivo se llame foto.png: si trae una extensión NO permitida,
        // rechazamos directamente (evita confusión, no restringe nada legítimo).
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, DocumentController::ALLOWED_EXT, true)) {
            return null;
        }

        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file['tmp_name']) ?: null;
                finfo_close($finfo);
            }
        }
        if ($mime && isset(DocumentController::ALLOWED_MIME[$mime])) {
            return DocumentController::ALLOWED_MIME[$mime];
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        return in_array($ext, DocumentController::ALLOWED_EXT, true) ? $ext : null;
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('bk.err.no_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
