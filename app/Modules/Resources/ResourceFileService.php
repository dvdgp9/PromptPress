<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use Core\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Único dueño de los binarios del módulo Recursos (R2).
 *
 * Valida por extensión + Fileinfo + firma estructural, genera nombres internos
 * aleatorios y nunca resuelve un path recibido del cliente.
 */
final class ResourceFileService
{
    /** Hook de tests; en producción siempre es null y manda move_uploaded_file. */
    public static mixed $moveOverride = null;

    private const MIME_BY_EXTENSION = [
        'pdf'  => 'application/pdf',
        'epub' => 'application/epub+zip',
    ];

    /** @return array{extension:string,mime:string,size:int,original_name:string} */
    public static function validateUpload(?array $file): array
    {
        if (!is_array($file)
            || !isset($file['error']) || is_array($file['error'])
            || !isset($file['tmp_name'], $file['name'], $file['size'])) {
            throw new InvalidArgumentException(__('resource.err.upload_structure'));
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadError($error));
        }

        $tmp = (string) $file['tmp_name'];
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp)) {
            throw new InvalidArgumentException(__('resource.err.upload_tmp_unreadable'));
        }

        $name = self::safeOriginalName((string) $file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::MIME_BY_EXTENSION)) {
            throw new InvalidArgumentException(__('resource.err.format_allowed'));
        }

        $actualSize = filesize($tmp);
        $reportedSize = (int) $file['size'];
        if ($actualSize === false || $actualSize <= 0 || $reportedSize <= 0) {
            throw new InvalidArgumentException(__('resource.err.file_empty'));
        }
        $limit = self::effectiveMaxSize();
        if ($actualSize > $limit || $reportedSize > $limit) {
            throw new InvalidArgumentException(
                __('resource.err.file_too_big', ['mb' => self::formatMb($limit)])
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) ($finfo->file($tmp) ?: ''));

        if ($extension === 'pdf') {
            if ($detected !== 'application/pdf' || !self::hasPdfHeader($tmp)) {
                throw new InvalidArgumentException(__('resource.err.pdf_invalid'));
            }
        } else {
            $zipMimes = ['application/epub+zip', 'application/zip', 'application/x-zip', 'application/x-zip-compressed'];
            if (!in_array($detected, $zipMimes, true) || !self::hasEpubOcfHeader($tmp)) {
                throw new InvalidArgumentException(__('resource.err.epub_invalid'));
            }
        }

        return [
            'extension'     => $extension,
            'mime'          => self::MIME_BY_EXTENSION[$extension],
            'size'          => (int) $actualSize,
            'original_name' => $name,
        ];
    }

    /**
     * Guarda un archivo nuevo y actualiza los metadatos. El anterior se retira
     * solo después de que la BD apunte correctamente al nuevo.
     *
     * @return array{relative_path:string,absolute_path:string,mime:string,size:int,original_name:string}
     */
    public static function storeUpload(int $siteId, int $resourceId, array $file): array
    {
        $resource = ResourceStore::find($siteId, $resourceId);
        if ($resource === null) {
            throw new InvalidArgumentException(__('resource.err.wrong_site'));
        }

        $validated = self::validateUpload($file);
        $dir = self::siteDirectory($siteId);
        self::ensureDirectory($dir);

        $filename = bin2hex(random_bytes(32)) . '.' . $validated['extension'];
        $absolute = $dir . '/' . $filename;
        $relative = 'storage/resources/' . $siteId . '/' . $filename;

        $moved = is_callable(self::$moveOverride)
            ? (bool) (self::$moveOverride)((string) $file['tmp_name'], $absolute)
            : move_uploaded_file((string) $file['tmp_name'], $absolute);

        if (!$moved || !is_file($absolute)) {
            if (is_file($absolute)) @unlink($absolute);
            throw new RuntimeException(
                __('resource.err.save_storage', ['site' => $siteId])
            );
        }
        @chmod($absolute, 0640);

        try {
            $updated = ResourceStore::update($siteId, $resourceId, [
                'file_path'         => $relative,
                'original_filename' => $validated['original_name'],
                'file_mime'         => $validated['mime'],
                'file_size'         => $validated['size'],
            ]);
            if (!$updated) {
                throw new RuntimeException(__('resource.err.disappeared'));
            }
        } catch (\Throwable $e) {
            @unlink($absolute);
            throw $e;
        }

        $oldRelative = trim((string) ($resource['file_path'] ?? ''));
        if ($oldRelative !== '' && $oldRelative !== $relative) {
            $oldAbsolute = self::absoluteFromRelative($siteId, $oldRelative, false);
            if ($oldAbsolute !== null && is_file($oldAbsolute) && !@unlink($oldAbsolute)) {
                error_log('[ResourceFileService] orphan after replace site=' . $siteId
                    . ' resource=' . $resourceId . ' path=' . $oldRelative);
            }
        }

        return [
            'relative_path' => $relative,
            'absolute_path' => $absolute,
            'mime'          => $validated['mime'],
            'size'          => $validated['size'],
            'original_name' => $validated['original_name'],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function prepareDirectDownload(int $siteId, string $language, string $slug): ?array
    {
        $resource = ResourceStore::findPublishedBySlug($siteId, $language, $slug);
        if ($resource === null || (string) $resource['access_mode'] !== 'direct') {
            return null;
        }

        return self::prepareResource($siteId, $resource);
    }

    /** @return array<string,mixed>|null */
    public static function prepareConditionedDownload(
        int $siteId,
        string $language,
        string $slug,
        string $token,
        ?int $now = null
    ): ?array {
        $resource = ResourceStore::findPublishedBySlug($siteId, $language, $slug);
        if ($resource === null || (string) $resource['access_mode'] !== 'form') return null;
        $authorized = ResourceAccessService::validateDownloadToken(
            $token,
            $siteId,
            (int) $resource['id'],
            $now
        );
        if ($authorized === null) return null;
        return self::prepareResource($siteId, $resource);
    }

    /** @return array<string,mixed>|null */
    private static function prepareResource(int $siteId, array $resource): ?array
    {

        $absolute = self::absoluteFromRelative($siteId, (string) ($resource['file_path'] ?? ''), true);
        if ($absolute === null) {
            self::logUnavailable($siteId, (int) $resource['id'], (string) ($resource['file_path'] ?? ''));
            return null;
        }

        $size = filesize($absolute);
        if ($size === false || $size <= 0 || (int) ($resource['file_size'] ?? 0) !== (int) $size) {
            self::logUnavailable($siteId, (int) $resource['id'], (string) ($resource['file_path'] ?? ''));
            return null;
        }

        $mime = (string) ($resource['file_mime'] ?? '');
        if (!in_array($mime, self::MIME_BY_EXTENSION, true)) {
            return null;
        }

        $resource['absolute_path'] = $absolute;
        $resource['actual_size'] = (int) $size;
        return $resource;
    }

    /** @param array<string,mixed> $prepared @return array<string,string> */
    public static function headersFor(array $prepared): array
    {
        $name = self::safeOriginalName((string) ($prepared['original_filename'] ?? 'recurso'));
        $fallback = self::asciiFilename($name);
        return [
            'Content-Type'              => (string) ($prepared['file_mime'] ?? 'application/octet-stream'),
            'Content-Length'            => (string) (int) ($prepared['actual_size'] ?? $prepared['file_size'] ?? 0),
            'Content-Disposition'       => 'attachment; filename="' . addcslashes($fallback, "\\\"")
                . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options'    => 'nosniff',
            'Cache-Control'             => 'private, no-store',
            'Accept-Ranges'             => 'none',
        ];
    }

    /** @param array<string,mixed> $prepared */
    public static function stream(array $prepared): never
    {
        $path = (string) ($prepared['absolute_path'] ?? '');
        $handle = $path !== '' ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            error_log('[ResourceFileService] stream open failed resource=' . (int) ($prepared['id'] ?? 0));
            Response::notFound();
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        foreach (self::headersFor($prepared) as $name => $value) {
            header($name . ': ' . $value);
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 64 * 1024);
            if ($chunk === false) break;
            echo $chunk;
            flush();
        }
        fclose($handle);
        exit;
    }

    /** Borra primero la fila; un fallo al unlink deja solo un huérfano inaccesible. */
    public static function deleteFileAndResource(int $siteId, int $resourceId): bool
    {
        $resource = ResourceStore::find($siteId, $resourceId);
        if ($resource === null) return false;

        $relative = (string) ($resource['file_path'] ?? '');
        if (!ResourceStore::delete($siteId, $resourceId)) return false;

        if ($relative !== '') {
            $absolute = self::absoluteFromRelative($siteId, $relative, false);
            if ($absolute !== null && is_file($absolute) && !@unlink($absolute)) {
                error_log('[ResourceFileService] orphan after delete site=' . $siteId
                    . ' resource=' . $resourceId . ' path=' . $relative);
            }
        }
        return true;
    }

    public static function effectiveMaxSize(): int
    {
        $limits = [ResourceStore::MAX_FILE_SIZE];
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));
        if ($upload > 0) $limits[] = $upload;
        if ($post > 0) $limits[] = max(1, $post - 256 * 1024);
        return max(1, min($limits));
    }

    private static function hasPdfHeader(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) return false;
        $prefix = fread($h, 5);
        fclose($h);
        return $prefix === '%PDF-';
    }

    /** Valida solo el primer local header OCF; nunca descomprime el contenedor. */
    private static function hasEpubOcfHeader(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) return false;
        $header = fread($h, 30);
        if (!is_string($header) || strlen($header) !== 30 || substr($header, 0, 4) !== "PK\x03\x04") {
            fclose($h);
            return false;
        }
        $p = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_len/vextra_len', substr($header, 4));
        if (!is_array($p)
            || ((int) $p['flags'] & 0x0001) !== 0
            || (int) $p['method'] !== 0
            || (int) $p['name_len'] !== 8
            || (int) $p['extra_len'] !== 0
            || (int) $p['compressed'] !== 20
            || (int) $p['uncompressed'] !== 20) {
            fclose($h);
            return false;
        }
        $name = fread($h, 8);
        $value = fread($h, 20);
        fclose($h);
        return $name === 'mimetype' && $value === 'application/epub+zip';
    }

    private static function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name, " .\t\n\r\0\x0B");
        $name = mb_substr($name, 0, 255);
        return $name !== '' ? $name : 'recurso';
    }

    private static function asciiFilename(string $name): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $ascii = is_string($ascii) ? $ascii : $name;
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '-', $ascii);
        $ascii = trim($ascii, " .-");
        return $ascii !== '' ? mb_substr($ascii, 0, 180) : 'recurso';
    }

    private static function siteDirectory(int $siteId): string
    {
        return PP_ROOT . '/storage/resources/' . $siteId;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(__('resource.err.storage_dir'));
        }
        // Los paquetes de actualización excluyen /storage/resources completo
        // para no copiar ebooks de una instalación. Por eso la protección se
        // autocura también en runtime si el directorio nace tras un update.
        $root = dirname($dir);
        $guard = $root . '/.htaccess';
        if (!is_file($guard)) {
            // i18n-ignore: contenido técnico de configuración Apache, no UI.
            $written = @file_put_contents(
                $guard,
                "# Los recursos nunca son accesibles por su path físico; solo via controller.\nRequire all denied\n", // i18n-ignore: Apache
                LOCK_EX
            );
            if ($written === false) {
                throw new RuntimeException(__('resource.err.storage_guard'));
            }
            @chmod($guard, 0644);
        }
    }

    private static function absoluteFromRelative(int $siteId, string $relative, bool $mustExist): ?string
    {
        $relative = ltrim(trim($relative), '/');
        $prefix = 'storage/resources/' . $siteId . '/';
        if (!str_starts_with($relative, $prefix) || str_contains($relative, '..')) return null;
        if (preg_match('/^[a-f0-9]{64}\.(pdf|epub)$/', basename($relative)) !== 1) return null;

        $candidate = PP_ROOT . '/' . $relative;
        if (!$mustExist) return $candidate;
        if (!is_file($candidate) || !is_readable($candidate)) return null;

        $real = realpath($candidate);
        $root = realpath(self::siteDirectory($siteId));
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return null;
        return $real;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') return 0;
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE   => __('resource.err.upload_ini'),
            UPLOAD_ERR_FORM_SIZE  => __('resource.err.upload_form'),
            UPLOAD_ERR_PARTIAL    => __('resource.err.upload_partial'),
            UPLOAD_ERR_NO_FILE    => __('resource.err.upload_none'),
            UPLOAD_ERR_NO_TMP_DIR => __('resource.err.upload_no_tmp'),
            UPLOAD_ERR_CANT_WRITE => __('resource.err.upload_write'),
            UPLOAD_ERR_EXTENSION  => __('resource.err.upload_extension'),
            default               => __('resource.err.upload_generic', ['code' => $error]),
        };
    }

    private static function formatMb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 2, '.', ''), '0'), '.');
    }

    private static function logUnavailable(int $siteId, int $resourceId, string $relative): void
    {
        error_log('[ResourceFileService] unavailable site=' . $siteId
            . ' resource=' . $resourceId . ' path=' . $relative);
    }
}
