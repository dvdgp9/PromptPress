<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Prepara medios ya resueltos para una llamada visual del Assistant.
 * No consulta URLs ni confía en rutas del navegador: acepta únicamente paths
 * internos que AssistantMediaReferences ya acotó al sitio autenticado.
 */
final class AssistantVisionImages
{
    public const MAX_IMAGES = 4;
    public const MAX_DIMENSION = 1600;
    private const MAX_FILE_BYTES = 10485760;
    private const MAX_PIXELS = 25000000;

    /**
     * @param array<string,mixed> $bundle
     * @return array{images:array<int,array<string,mixed>>,manifest:array<int,array<string,mixed>>,skipped_refs:string[]}
     */
    public static function prepare(array $bundle, int $siteId): array
    {
        $images = [];
        $manifest = [];
        $skipped = [];
        $seenMedia = [];

        foreach ((array) ($bundle['media'] ?? []) as $media) {
            $ref = trim((string) ($media['ref'] ?? ''));
            if (count($images) >= self::MAX_IMAGES) {
                if ($ref !== '') $skipped[] = $ref;
                continue;
            }
            $mediaId = (int) ($media['media_id'] ?? 0);
            $source = (string) ($media['source'] ?? '');
            if (
                ($media['status'] ?? '') !== 'stored'
                || ($media['source_kind'] ?? '') !== 'media'
                || $mediaId <= 0
                || isset($seenMedia[$mediaId])
                || !self::safeOwnedPath($source, $siteId)
            ) {
                if ($ref !== '') $skipped[] = $ref;
                continue;
            }

            $absolute = PP_ROOT . '/' . ltrim($source, '/');
            $prepared = self::prepareFile($absolute);
            if ($prepared === null) {
                if ($ref !== '') $skipped[] = $ref;
                continue;
            }
            $seenMedia[$mediaId] = true;
            $images[] = [
                'mime' => $prepared['mime'],
                'data' => base64_encode($prepared['binary']),
                'media_id' => $mediaId,
                'width' => $prepared['width'],
                'height' => $prepared['height'],
                'bytes' => strlen($prepared['binary']),
            ];
            $manifest[] = [
                'ref' => $ref,
                'media_id' => $mediaId,
                'alt' => mb_substr(trim((string) ($media['alt'] ?? '')), 0, 300),
                'role' => in_array((string) ($media['role'] ?? ''), ['reference', 'evidence', 'publishable_asset'], true)
                    ? (string) $media['role']
                    : 'unknown',
                'mime' => $prepared['mime'],
                'width' => $prepared['width'],
                'height' => $prepared['height'],
                'bytes' => strlen($prepared['binary']),
            ];
        }

        return [
            'images' => $images,
            'manifest' => $manifest,
            'skipped_refs' => array_values(array_unique($skipped)),
        ];
    }

    private static function safeOwnedPath(string $path, int $siteId): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) return false;
        return preg_match('#^/storage/uploads/' . preg_quote((string) $siteId, '#') . '/[a-zA-Z0-9._/-]+$#', $path) === 1;
    }

    /** @return array{mime:string,binary:string,width:int,height:int}|null */
    private static function prepareFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0 || $bytes > self::MAX_FILE_BYTES) return null;
        $info = @getimagesize($path);
        if (!is_array($info)) return null;
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) return null;
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
        if (!function_exists('imagecreatetruecolor')) return null;

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
        if ($source === false) return null;

        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $outputMime = $mime === 'image/gif' ? 'image/png' : $mime;
        if (in_array($outputMime, ['image/png', 'image/webp'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        $written = match ($outputMime) {
            'image/jpeg' => imagejpeg($target, null, 85),
            'image/webp' => function_exists('imagewebp') ? imagewebp($target, null, 82) : false,
            default => imagepng($target, null, 6),
        };
        $binary = ob_get_clean();
        imagedestroy($target);
        if (!$written || !is_string($binary) || $binary === '') return null;

        return [
            'mime' => $outputMime,
            'binary' => $binary,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }
}
