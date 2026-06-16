<?php

namespace App\Services;

use Core\Database;

/**
 * Servicio de medios (T8.1).
 *
 * Maneja upload de imágenes:
 *  - Valida MIME y extensión (whitelist).
 *  - Redimensiona si excede MAX_WIDTH (preserva aspecto, vía GD).
 *  - Guarda en storage/uploads/{site_id}/{uuid}.{ext}.
 *  - Inserta fila en `media` con metadatos.
 *
 * Las imágenes son servidas estáticamente (storage/uploads/.htaccess solo bloquea PHP).
 */
final class MediaService
{
    public const MAX_SIZE   = 10 * 1024 * 1024; // 10 MB
    public const MAX_WIDTH  = 1920;             // Límite superior para reescalado
    public const MAX_HEIGHT = 1920;

    /** mime → extensión canónica */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * Valida un $_FILES item. Devuelve null si OK, o string con el error.
     */
    public static function validate(?array $file): ?string
    {
        if (!is_array($file) || !isset($file['error'])) {
            return 'No se recibió ningún archivo.';
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'La imagen excede el tamaño máximo permitido por el servidor.';
            case UPLOAD_ERR_NO_FILE:
                return 'Debes seleccionar una imagen.';
            case UPLOAD_ERR_PARTIAL:
                return 'La subida se interrumpió. Inténtalo de nuevo.';
            default:
                return 'Error al subir el archivo (código ' . $file['error'] . ').';
        }
        if ($file['size'] > self::MAX_SIZE) {
            return 'La imagen supera los ' . (self::MAX_SIZE / 1024 / 1024) . ' MB permitidos.';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return 'Archivo subido no válido.';
        }
        if (self::detectMime($file) === null) {
            return 'Tipo de imagen no soportado. Usa JPG, PNG, WebP o GIF.';
        }
        return null;
    }

    /**
     * Procesa un upload válido y crea la fila en `media`.
     * @return array fila insertada (con id)
     * @throws \RuntimeException si algo del filesystem o BD falla
     */
    public static function store(array $file, int $siteId, ?int $userId, ?string $altText = null): array
    {
        $mime = self::detectMime($file);
        if ($mime === null) {
            throw new \RuntimeException('Tipo de imagen no soportado.');
        }
        $ext = self::ALLOWED[$mime];

        // Crear directorio destino
        $dir = self::dirFor($siteId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de medios.');
        }

        // Mover el archivo
        $uuid     = bin2hex(random_bytes(16));
        $filename = $uuid . '.' . $ext;
        $destPath = $dir . '/' . $filename;
        $relPath  = 'storage/uploads/' . $siteId . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('No se pudo guardar la imagen subida.');
        }

        // Resize si excede límites (in-place, preservando ext)
        [$width, $height] = self::resizeIfNeeded($destPath, $mime);

        $finalSize = filesize($destPath) ?: $file['size'];

        Database::execute(
            'INSERT INTO media
                (site_id, filename, original_name, mime_type, file_size, path,
                 alt_text, width, height, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $filename,
                mb_substr((string) $file['name'], 0, 255),
                $mime,
                $finalSize,
                $relPath,
                $altText !== null && $altText !== '' ? mb_substr($altText, 0, 500) : null,
                $width ?: null,
                $height ?: null,
                $userId,
            ]
        );

        $id = (int) Database::lastInsertId();
        return Database::selectOne('SELECT * FROM media WHERE id = ?', [$id]) ?? [];
    }

    /**
     * T18.4 — Inserta una imagen ya descargada (binario en disco) como fila `media`.
     *
     * Usado por ImageBankService al descargar de Unsplash. La imagen ya debe
     * estar en su sitio definitivo dentro de `storage/uploads/{site}/`. Esta
     * función se encarga del resize, los metadatos y la atribución.
     *
     * @param string $absolutePath ruta absoluta del archivo descargado
     * @param string $relPath      ruta relativa al PP_ROOT (lo que va en `media.path`)
     * @param string $mime         mime detectado (debe estar en ALLOWED)
     * @param int $siteId
     * @param int|null $userId
     * @param array{
     *   original_name?: string,
     *   alt_text?: string,
     *   source?: string,
     *   source_id?: string,
     *   source_url?: string,
     *   attribution_name?: string,
     *   attribution_url?: string,
     * } $meta
     * @return array fila media insertada
     */
    public static function storeFromBinary(
        string $absolutePath,
        string $relPath,
        string $mime,
        int $siteId,
        ?int $userId,
        array $meta = []
    ): array {
        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException('Tipo de imagen no soportado: ' . $mime);
        }
        if (!is_file($absolutePath)) {
            throw new \RuntimeException('No existe el archivo descargado: ' . $absolutePath);
        }

        [$width, $height] = self::resizeIfNeeded($absolutePath, $mime);
        $finalSize = filesize($absolutePath) ?: 0;

        $filename = basename($absolutePath);
        $original = isset($meta['original_name']) && $meta['original_name'] !== ''
            ? mb_substr((string) $meta['original_name'], 0, 255)
            : $filename;
        $alt = isset($meta['alt_text']) && $meta['alt_text'] !== ''
            ? mb_substr((string) $meta['alt_text'], 0, 500)
            : null;

        Database::execute(
            'INSERT INTO media
                (site_id, filename, original_name, mime_type, file_size, path,
                 alt_text, width, height, uploaded_by,
                 source, source_id, source_url, attribution_name, attribution_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId,
                $filename,
                $original,
                $mime,
                $finalSize,
                $relPath,
                $alt,
                $width ?: null,
                $height ?: null,
                $userId,
                (string) ($meta['source']            ?? 'upload'),
                isset($meta['source_id'])         && $meta['source_id']         !== '' ? mb_substr((string) $meta['source_id'], 0, 120)         : null,
                isset($meta['source_url'])        && $meta['source_url']        !== '' ? mb_substr((string) $meta['source_url'], 0, 500)        : null,
                isset($meta['attribution_name']) && $meta['attribution_name'] !== '' ? mb_substr((string) $meta['attribution_name'], 0, 160) : null,
                isset($meta['attribution_url'])  && $meta['attribution_url']  !== '' ? mb_substr((string) $meta['attribution_url'], 0, 500)  : null,
            ]
        );

        $id = (int) Database::lastInsertId();
        return Database::selectOne('SELECT * FROM media WHERE id = ?', [$id]) ?? [];
    }

    /** Devuelve la ruta absoluta de un site a `storage/uploads/{site}`. Crea el dir si no existe. */
    public static function ensureSiteDir(int $siteId): string
    {
        $dir = self::dirFor($siteId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de medios.');
        }
        return $dir;
    }

    /** Borra el archivo físico de un registro media (si existe) y la fila. */
    public static function delete(array $row): void
    {
        if (!empty($row['path'])) {
            $abs = PP_ROOT . '/' . ltrim((string) $row['path'], '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        if (!empty($row['id'])) {
            Database::execute('DELETE FROM media WHERE id = ?', [(int) $row['id']]);
        }
    }

    // ======================================================================
    // Internos
    // ======================================================================

    private static function dirFor(int $siteId): string
    {
        return PP_STORAGE . '/uploads/' . $siteId;
    }

    /** Detecta MIME via finfo (real); si no, fallback por extensión. Devuelve uno permitido o null. */
    private static function detectMime(array $file): ?string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file['tmp_name']) ?: null;
                finfo_close($finfo);
            }
        }
        if ($mime && isset(self::ALLOWED[$mime])) {
            return $mime;
        }
        // Fallback por extensión (algunos hostings devuelven mime raros)
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $extMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        if (isset($extMap[$ext]) && isset(self::ALLOWED[$extMap[$ext]])) {
            return $extMap[$ext];
        }
        return null;
    }

    /**
     * Si la imagen excede MAX_WIDTH/MAX_HEIGHT, la reescala in-place.
     * Devuelve [width, height] finales (0,0 si no se pudo medir).
     */
    private static function resizeIfNeeded(string $path, string $mime): array
    {
        $info = @getimagesize($path);
        if (!$info) {
            return [0, 0];
        }
        [$w, $h] = $info;

        // GIF: no tocar (puede ser animado y GD aplastaría a 1 frame)
        if ($mime === 'image/gif') {
            return [$w, $h];
        }
        if ($w <= self::MAX_WIDTH && $h <= self::MAX_HEIGHT) {
            return [$w, $h];
        }

        $ratio  = min(self::MAX_WIDTH / $w, self::MAX_HEIGHT / $h);
        $newW   = (int) floor($w * $ratio);
        $newH   = (int) floor($h * $ratio);

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
        if (!$src) {
            return [$w, $h];
        }

        $dst = imagecreatetruecolor($newW, $newH);
        // Preservar transparencia en PNG/WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($dst, $path, 85),
            'image/png'  => imagepng($dst, $path, 6),
            'image/webp' => imagewebp($dst, $path, 85),
            default      => false,
        };

        imagedestroy($src);
        imagedestroy($dst);
        return $ok ? [$newW, $newH] : [$w, $h];
    }
}
