<?php

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\AssistantContentNormalizer;
use App\Services\AssistantMediaReferences;
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

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'csrf'       => CSRF::token(),
            'maxSize'    => DocumentController::MAX_SIZE,
            'allowedExt' => DocumentController::ALLOWED_EXT,
        ]);
        View::send('admin/assistant/index', $data);
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
            Response::json(['ok' => false, 'error' => __('asst.err.empty_doc')], 422);
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

        $normalized = null;
        if (trim($richHtml) !== '') {
            $normalized = AssistantMediaReferences::resolve(
                AssistantContentNormalizer::normalize($richHtml, $instruction),
                $siteId
            );
            $richContext = trim((string) ($normalized['prompt_text'] ?? ''));
            if ($richContext !== '') {
                $docText = "[Contenido enriquecido pegado]\n" . $richContext
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

        $requestText = $normalized !== null
            ? 'Analiza y clasifica la petición pegada respetando su estructura y el orden de sus referencias.'
            : ($instruction !== ''
            ? $instruction
            : 'Aplica los cambios descritos en el documento adjunto.');

        @set_time_limit(180);
        try {
            $plan = SiteAssistantPlanner::plan($siteId, $requestText, $docText);
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

        $result = SiteAssistantJobs::createJob($siteId, $requestText, $summary, $items, Auth::id());
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
