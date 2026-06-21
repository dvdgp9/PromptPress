<?php

declare(strict_types=1);

namespace App\Services;

use Core\App;
use Core\Database;

final class FormSubmissionService
{
    public const MAX_FILE_MB = 10;

    private const MIME_BY_EXT = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
    ];

    private const PRESETS = [
        'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'],
        'images'    => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'cv'        => ['pdf', 'doc', 'docx'],
    ];

    public static function ensureSchema(): void
    {
        Database::execute(
            "CREATE TABLE IF NOT EXISTS form_submissions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id INT UNSIGNED NOT NULL,
                page_id INT UNSIGNED NOT NULL,
                section_id INT UNSIGNED NOT NULL,
                page_title VARCHAR(500) NOT NULL,
                section_heading VARCHAR(255) DEFAULT NULL,
                sender_name VARCHAR(255) DEFAULT NULL,
                sender_email VARCHAR(255) DEFAULT NULL,
                sender_phone VARCHAR(100) DEFAULT NULL,
                payload JSON NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                status ENUM('unread','read') NOT NULL DEFAULT 'unread',
                email_status ENUM('skipped','sent','failed') NOT NULL DEFAULT 'skipped',
                email_error VARCHAR(500) DEFAULT NULL,
                autoresponder_status ENUM('unknown','disabled','skipped','sent','failed') NOT NULL DEFAULT 'unknown',
                autoresponder_error VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME DEFAULT NULL,
                INDEX idx_form_submissions_site_date (site_id, created_at),
                INDEX idx_form_submissions_page_date (page_id, created_at),
                INDEX idx_form_submissions_section_date (section_id, created_at),
                INDEX idx_form_submissions_status (site_id, status),
                INDEX idx_form_submissions_rate (section_id, ip_hash, created_at),
                CONSTRAINT fk_form_submissions_site
                    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_form_submissions_page
                    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
                CONSTRAINT fk_form_submissions_section
                    FOREIGN KEY (section_id) REFERENCES page_sections(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function ipHash(string $ip): string
    {
        $key = (string) (App::config()['app_key'] ?? 'promptpress');
        return hash_hmac('sha256', $ip, $key);
    }

    public static function isRateLimited(int $sectionId, string $ipHash): bool
    {
        self::ensureSchema();
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n
             FROM form_submissions
             WHERE section_id = ? AND ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)',
            [$sectionId, $ipHash]
        );
        return (int) ($row['n'] ?? 0) >= 5;
    }

    public static function recipientForSite(int $siteId): ?string
    {
        $memory = Database::selectOne(
            'SELECT field_value FROM site_memory WHERE site_id = ? AND field_key = ? LIMIT 1',
            [$siteId, 'contact_info']
        );
        $text = (string) ($memory['field_value'] ?? '');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            return $m[0];
        }

        $user = Database::selectOne('SELECT email FROM users ORDER BY id ASC LIMIT 1');
        $email = (string) ($user['email'] ?? '');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @param array<string,mixed> $payload */
    public static function emailBody(array $meta, array $payload): string
    {
        $lines = [
            'Nuevo mensaje recibido desde PromptPress',
            '',
            'Página: ' . (string) ($meta['page_title'] ?? ''),
            'Sección: ' . (string) ($meta['section_heading'] ?? 'Formulario'),
            'Fecha: ' . date('Y-m-d H:i:s'),
            '',
            'Datos enviados:',
        ];

        foreach ($payload as $label => $value) {
            if (is_array($value) && ($value['type'] ?? '') === 'file') {
                $lines[] = '- ' . $label . ': ' . (string) ($value['original_name'] ?? 'Archivo adjunto')
                    . ' (' . self::formatBytes((int) ($value['size'] ?? 0)) . ')';
                continue;
            }
            $lines[] = '- ' . $label . ': ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value);
        }

        return implode("\n", $lines);
    }

    /** @return array<int,string> */
    public static function allowedExtensionsForField(array $field): array
    {
        $preset = (string) ($field['file_accept'] ?? 'documents');
        if ($preset === 'custom') {
            return self::normalizeExtensions((string) ($field['file_custom_ext'] ?? ''));
        }
        return self::PRESETS[$preset] ?? self::PRESETS['documents'];
    }

    public static function acceptAttributeForField(array $field): string
    {
        $extensions = self::allowedExtensionsForField($field);
        return implode(',', array_map(static fn(string $ext): string => '.' . $ext, $extensions));
    }

    public static function fileHelpForField(array $field): string
    {
        $extensions = self::allowedExtensionsForField($field);
        $maxMb = self::maxMbForField($field);
        return 'Formatos permitidos: ' . strtoupper(implode(', ', $extensions)) . '. Máximo ' . $maxMb . ' MB.';
    }

    public static function maxMbForField(array $field): int
    {
        $max = (int) ($field['file_max_mb'] ?? 5);
        return max(1, min(self::MAX_FILE_MB, $max));
    }

    /**
     * @return array{ok:bool,error?:string,file?:array<string,mixed>}
     */
    public static function storeUploadedFile(array $file, array $field, int $siteId, int $sectionId, string $fieldName): array
    {
        $error = self::validateUploadedFile($file, $field);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = self::detectMime((string) $file['tmp_name'], $ext);
        $dir = PP_STORAGE . '/form_uploads/' . $siteId . '/' . $sectionId . '/' . date('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de subida.'];
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
        }

        $relPath = ltrim(str_replace(PP_ROOT, '', $dest), '/');
        return [
            'ok' => true,
            'file' => [
                'type' => 'file',
                'field_name' => $fieldName,
                'original_name' => mb_substr(basename((string) $file['name']), 0, 255),
                'stored_name' => $stored,
                'path' => $relPath,
                'mime' => $mime,
                'extension' => $ext,
                'size' => (int) $file['size'],
            ],
        ];
    }

    public static function deleteFilesFromPayload(array $payload): void
    {
        foreach ($payload as $value) {
            if (!is_array($value) || ($value['type'] ?? '') !== 'file') {
                continue;
            }
            $path = self::safeStoredPath((string) ($value['path'] ?? ''));
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function safeStoredPath(string $relPath): ?string
    {
        $relPath = ltrim($relPath, '/');
        if ($relPath === '' || str_contains($relPath, '..') || !str_starts_with($relPath, 'storage/form_uploads/')) {
            return null;
        }
        $abs = PP_ROOT . '/' . $relPath;
        $base = PP_STORAGE . '/form_uploads';
        $realBase = realpath($base);
        $realFile = realpath($abs);
        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $realFile;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, ',', ''), '0'), ',') . ' KB';
        }
        return $bytes . ' B';
    }

    private static function validateUploadedFile(array $file, array $field): ?string
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return 'Archivo no válido.';
        }
        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return 'Debes seleccionar un archivo.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'El archivo supera el tamaño permitido por el servidor.';
            case UPLOAD_ERR_PARTIAL:
                return 'La subida del archivo se interrumpió. Inténtalo de nuevo.';
            default:
                return 'No se pudo subir el archivo (código ' . (int) $file['error'] . ').';
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return 'Archivo subido no válido.';
        }
        $maxBytes = self::maxMbForField($field) * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            return 'El archivo supera el máximo de ' . self::maxMbForField($field) . ' MB.';
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = self::allowedExtensionsForField($field);
        if ($ext === '' || !in_array($ext, $allowed, true) || !isset(self::MIME_BY_EXT[$ext])) {
            return 'Tipo de archivo no permitido.';
        }
        $mime = self::detectMime((string) $file['tmp_name'], $ext);
        if (!self::mimeMatchesExtension($mime, $ext)) {
            return 'El contenido del archivo no coincide con su extensión.';
        }
        return null;
    }

    /** @return array<int,string> */
    private static function normalizeExtensions(string $raw): array
    {
        $items = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        $out = [];
        foreach ($items as $item) {
            $ext = ltrim(trim($item), '.');
            if ($ext === '' || !isset(self::MIME_BY_EXT[$ext]) || in_array($ext, $out, true)) {
                continue;
            }
            $out[] = $ext;
        }
        return $out !== [] ? $out : self::PRESETS['documents'];
    }

    private static function detectMime(string $tmpPath, string $ext): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string) (finfo_file($finfo, $tmpPath) ?: '');
                finfo_close($finfo);
            }
        }
        return $mime !== '' ? $mime : (self::MIME_BY_EXT[$ext] ?? 'application/octet-stream');
    }

    private static function mimeMatchesExtension(string $mime, string $ext): bool
    {
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $mime === 'image/jpeg';
        }
        if ($ext === 'txt') {
            return in_array($mime, ['text/plain', 'text/x-plain', 'application/octet-stream'], true);
        }
        if ($ext === 'rtf') {
            return in_array($mime, ['application/rtf', 'text/rtf', 'application/octet-stream'], true);
        }
        if (in_array($ext, ['doc', 'docx', 'odt'], true)) {
            return in_array($mime, [self::MIME_BY_EXT[$ext], 'application/zip', 'application/octet-stream'], true);
        }
        return $mime === self::MIME_BY_EXT[$ext];
    }
}
