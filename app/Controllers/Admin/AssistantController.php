<?php

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
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
            Response::json(['ok' => false, 'error' => 'No se recibió ningún archivo válido.'], 422);
        }
        if ($file['size'] > DocumentController::MAX_SIZE) {
            $maxMb = (int) (DocumentController::MAX_SIZE / 1024 / 1024);
            Response::json(['ok' => false, 'error' => "El archivo supera los {$maxMb} MB permitidos."], 422);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            Response::json(['ok' => false, 'error' => 'Archivo subido no válido.'], 422);
        }

        $type = self::detectType($file);
        if ($type === null) {
            Response::json(['ok' => false, 'error' => 'Tipo no soportado. Sube PDF, DOCX o TXT.'], 422);
        }

        // TextExtractor necesita la extensión correcta en algunos parsers; el tmp
        // de PHP no la tiene, así que copiamos a un temporal con extensión.
        $tmpBase = tempnam(sys_get_temp_dir(), 'ppa_');
        $tmpPath = $tmpBase . '.' . $type;
        if ($tmpBase === false || !move_uploaded_file($file['tmp_name'], $tmpPath)) {
            Response::json(['ok' => false, 'error' => 'No se pudo procesar el archivo.'], 500);
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
            Response::json(['ok' => false, 'error' => 'No se pudo extraer texto del documento: ' . $extractError], 422);
        }

        $truncated = false;
        if (mb_strlen($text) > self::MAX_EXTRACT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_EXTRACT_CHARS);
            $truncated = true;
        }
        if (trim($text) === '') {
            Response::json(['ok' => false, 'error' => 'El documento no contiene texto extraíble (¿es un PDF escaneado?).'], 422);
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
        if (mb_strlen($instruction) > 4000) {
            Response::json(['ok' => false, 'error' => 'La petición es demasiado larga (máx. 4000 caracteres).'], 422);
        }
        if (mb_strlen($docText) > self::MAX_EXTRACT_CHARS) {
            $docText = mb_substr($docText, 0, self::MAX_EXTRACT_CHARS);
        }
        if ($instruction === '' && trim($docText) === '') {
            Response::json(['ok' => false, 'error' => 'Escribe la petición o adjunta un documento.'], 422);
        }

        $requestText = $instruction !== ''
            ? $instruction
            : 'Aplica los cambios descritos en el documento adjunto.';

        @set_time_limit(180);
        try {
            $plan = SiteAssistantPlanner::plan($siteId, $requestText, $docText);
        } catch (AIException $e) {
            $errorId = substr(bin2hex(random_bytes(6)), 0, 10);
            error_log('[assistant plan] error_id=' . $errorId . ' site=' . $siteId . ' ai status=' . $e->getHttpStatus() . ': ' . $e->getMessage());
            $message = match (true) {
                in_array($e->getHttpStatus(), [401, 403], true) => 'La configuración del proveedor de IA no es válida. Revisa Ajustes de IA.',
                $e->getHttpStatus() === 429 => 'El proveedor de IA ha alcanzado temporalmente su límite. Espera un momento y vuelve a intentarlo.',
                $e->getHttpStatus() >= 500 => 'El proveedor de IA no está disponible ahora mismo. Vuelve a intentarlo en un rato.',
                default => 'No he podido generar el plan. Prueba a formular la petición de otra forma.',
            };
            Response::json(['ok' => false, 'error' => $message, 'error_id' => $errorId], 502);
        } catch (\Throwable $e) {
            error_log('[assistant plan] site=' . $siteId . ' ' . get_class($e) . ': ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No he podido generar el plan. Inténtalo de nuevo.'], 502);
        }

        Response::json(['ok' => true, 'plan' => $plan]);
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
            Response::json(['ok' => false, 'error' => 'No se recibió ningún cambio que aplicar.'], 422);
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

        @set_time_limit(180);
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
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
