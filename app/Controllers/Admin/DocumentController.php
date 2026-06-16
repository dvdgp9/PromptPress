<?php

namespace App\Controllers\Admin;

use App\Services\DocumentSummarizer;
use App\Services\TextExtractor;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Gestión de documentos base (T4.2 upload + extracción; T4.3 list/show/delete).
 *
 * Flujo de upload:
 *   1. Validar ($_FILES) — mime, tamaño, tipo permitido
 *   2. Guardar en storage/documents/{site_id}/{uuid}.{ext}
 *   3. INSERT row status='processing'
 *   4. Extraer texto (síncrono). En éxito → status='ready' + summary.
 *      En error → status='error' y se preserva el archivo para diagnóstico.
 */
class DocumentController
{
    /** Tamaño máximo de archivo permitido (20MB). */
    public const MAX_SIZE = 20 * 1024 * 1024;

    /** Mapa de mime type → extensión canónica. */
    public const ALLOWED_MIME = [
        'application/pdf'                                                           => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/msword'                                                        => 'docx', // tolerancia
        'text/plain'                                                                => 'txt',
    ];

    /** Extensiones permitidas (fallback si el mime viene vacío). */
    public const ALLOWED_EXT = ['pdf', 'docx', 'txt'];

    // ----------------------------------------------------------------------
    // GET /admin/documents
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $rows = Database::select(
            'SELECT id, title, original_filename, file_type, status,
                    CHAR_LENGTH(COALESCE(extracted_text,"")) AS text_length,
                    summary, created_at
             FROM documents WHERE site_id = ?
             ORDER BY created_at DESC',
            [$siteId]
        );

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'documents' => $rows,
            'csrf'      => CSRF::token(),
            'maxSize'   => self::MAX_SIZE,
            'allowedExt' => self::ALLOWED_EXT,
        ]);
        View::send('admin/documents/index', $data);
    }

    // ----------------------------------------------------------------------
    // POST /admin/documents/upload
    // ----------------------------------------------------------------------
    public function upload(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $userId = Auth::id();

        // 1) Validación del archivo
        $file = $_FILES['file'] ?? null;
        $error = self::validateUpload($file);
        if ($error !== null) {
            Session::flash('error', $error);
            Response::redirect(base_url('admin/documents'));
            return;
        }

        $title = trim((string) Request::post('title', ''));
        if ($title === '') {
            $title = pathinfo($file['name'], PATHINFO_FILENAME);
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        // 2) Determinar tipo y extensión canónica
        $type = self::detectType($file);

        // 3) Generar ruta de destino: storage/documents/{site_id}/{uuid}.{ext}
        $basedir = self::documentsDir($siteId);
        if (!is_dir($basedir) && !mkdir($basedir, 0775, true) && !is_dir($basedir)) {
            Session::flash('error', 'No se pudo crear la carpeta de documentos.');
            Response::redirect(base_url('admin/documents'));
            return;
        }

        $uuid     = bin2hex(random_bytes(16));
        $destName = $uuid . '.' . $type;
        $destPath = $basedir . '/' . $destName;
        $relPath  = 'storage/documents/' . $siteId . '/' . $destName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Session::flash('error', 'No se pudo guardar el archivo subido.');
            Response::redirect(base_url('admin/documents'));
            return;
        }

        // 4) INSERT row status='processing'
        Database::execute(
            'INSERT INTO documents
                (site_id, title, original_filename, file_type, file_path, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, "processing", ?)',
            [$siteId, $title, $file['name'], $type, $relPath, $userId]
        );
        $docId = (int) Database::lastInsertId();

        // 5) Extraer texto + resumen (síncrono)
        @set_time_limit(120);
        try {
            $text    = TextExtractor::extract($destPath, $type);
            $summary = DocumentSummarizer::summarize($text);
            Database::execute(
                'UPDATE documents SET extracted_text = ?, summary = ?, status = "ready" WHERE id = ?',
                [$text, $summary, $docId]
            );
            Session::flash('success', 'Documento procesado correctamente.');
        } catch (\Throwable $e) {
            Database::execute(
                'UPDATE documents SET status = "error" WHERE id = ?',
                [$docId]
            );
            error_log('[DocumentController] Extracción fallida (doc ' . $docId . '): ' . $e->getMessage());
            Session::flash('error', 'El documento se subió pero la extracción de texto falló: ' . $e->getMessage());
        }

        Response::redirect(base_url('admin/documents'));
    }

    // ----------------------------------------------------------------------
    // GET /admin/documents/{id}
    // ----------------------------------------------------------------------
    public function show(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $doc    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        // Tamaño físico (puede no existir si se borró manualmente)
        $absPath = PP_ROOT . '/' . $doc['file_path'];
        $sizeBytes = is_file($absPath) ? filesize($absPath) : 0;

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'doc'       => $doc,
            'sizeBytes' => $sizeBytes,
            'csrf'      => CSRF::token(),
        ]);
        View::send('admin/documents/show', $data);
    }

    // ----------------------------------------------------------------------
    // POST /admin/documents/{id}/rename
    // ----------------------------------------------------------------------
    public function rename(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $doc    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        $title = trim((string) Request::post('title', ''));
        if ($title === '' || mb_strlen($title) > 255) {
            Session::flash('error', 'El título es obligatorio y no puede superar 255 caracteres.');
            Response::redirect(base_url('admin/documents/' . $doc['id']));
            return;
        }

        Database::execute('UPDATE documents SET title = ? WHERE id = ?', [$title, $doc['id']]);
        Session::flash('success', 'Título actualizado.');
        Response::redirect(base_url('admin/documents/' . $doc['id']));
    }

    // ----------------------------------------------------------------------
    // POST /admin/documents/{id}/retry — reintentar extracción
    // ----------------------------------------------------------------------
    public function retry(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $doc    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        $absPath = PP_ROOT . '/' . $doc['file_path'];
        if (!is_file($absPath)) {
            Session::flash('error', 'El archivo físico no existe. Sube de nuevo el documento.');
            Response::redirect(base_url('admin/documents/' . $doc['id']));
            return;
        }

        Database::execute('UPDATE documents SET status = "processing" WHERE id = ?', [$doc['id']]);
        @set_time_limit(120);
        try {
            $text    = TextExtractor::extract($absPath, $doc['file_type']);
            $summary = DocumentSummarizer::summarize($text);
            Database::execute(
                'UPDATE documents SET extracted_text = ?, summary = ?, status = "ready" WHERE id = ?',
                [$text, $summary, $doc['id']]
            );
            Session::flash('success', 'Extracción completada.');
        } catch (\Throwable $e) {
            Database::execute('UPDATE documents SET status = "error" WHERE id = ?', [$doc['id']]);
            error_log('[DocumentController::retry] doc ' . $doc['id'] . ': ' . $e->getMessage());
            Session::flash('error', 'La extracción falló de nuevo: ' . $e->getMessage());
        }
        Response::redirect(base_url('admin/documents/' . $doc['id']));
    }

    // ----------------------------------------------------------------------
    // POST /admin/documents/{id}/delete
    // ----------------------------------------------------------------------
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $doc    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        // Borrar archivo físico (best-effort)
        $absPath = PP_ROOT . '/' . $doc['file_path'];
        if (is_file($absPath)) {
            @unlink($absPath);
        }
        Database::execute('DELETE FROM documents WHERE id = ?', [$doc['id']]);

        Session::flash('success', 'Documento eliminado.');
        Response::redirect(base_url('admin/documents'));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function findOrFail(int $id, int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT d.*, u.username AS uploaded_by_username
             FROM documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.id = ? AND d.site_id = ?
             LIMIT 1',
            [$id, $siteId]
        );
        if (!$row) {
            Session::flash('error', 'Documento no encontrado.');
            Response::redirect(base_url('admin/documents'));
        }
        return $row;
    }

    /**
     * Devuelve null si el upload es válido, o un string con el error.
     */
    private static function validateUpload($file): ?string
    {
        if (!is_array($file) || !isset($file['error'])) {
            return 'No se recibió ningún archivo.';
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'El archivo excede el tamaño máximo permitido.';
            case UPLOAD_ERR_NO_FILE:
                return 'Debes seleccionar un archivo.';
            case UPLOAD_ERR_PARTIAL:
                return 'La subida se interrumpió. Inténtalo de nuevo.';
            default:
                return 'Error al subir el archivo (código ' . $file['error'] . ').';
        }
        if ($file['size'] > self::MAX_SIZE) {
            return 'El archivo supera los ' . (self::MAX_SIZE / 1024 / 1024) . ' MB permitidos.';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return 'Archivo subido no válido.';
        }

        $type = self::detectType($file);
        if ($type === null) {
            return 'Tipo de archivo no soportado. Sube PDF, DOCX o TXT.';
        }
        return null;
    }

    /**
     * Detecta el tipo del archivo subido (pdf|docx|txt) o null si no permitido.
     */
    private static function detectType(array $file): ?string
    {
        // 1) Por mime real del archivo
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file['tmp_name']) ?: null;
                finfo_close($finfo);
            }
        }
        if ($mime && isset(self::ALLOWED_MIME[$mime])) {
            return self::ALLOWED_MIME[$mime];
        }
        // 2) Fallback por extensión del nombre original
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, self::ALLOWED_EXT, true)) {
            return $ext;
        }
        return null;
    }

    public static function documentsDir(int $siteId): string
    {
        return PP_ROOT . '/storage/documents/' . $siteId;
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
