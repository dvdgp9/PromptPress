<?php

namespace App\Services;

/**
 * Cache de páginas renderizadas (T7.3).
 *
 * Almacena el HTML completo de cada página pública en disco bajo
 * `storage/cache/pages/site_{N}/{slug}.html`. La invalidación es:
 *  - explícita: al editar páginas, secciones, design system o memoria del sitio.
 *  - por TTL: tras `DEFAULT_TTL` segundos el cache se considera viejo.
 *
 * El directorio `storage/cache/` ya está protegido por `.htaccess` (Require all denied),
 * así que los archivos nunca se sirven directamente — siempre vía PHP.
 */
final class CacheService
{
    /** TTL por defecto: 1 hora. La invalidación explícita cubre los cambios reales. */
    public const DEFAULT_TTL = 3600;

    /** Slug interno para la home. Slug real puede ser cualquier cosa. */
    public const HOME_KEY = '__home';

    /** Devuelve HTML cacheado o null si no existe / está expirado. */
    public static function get(int $siteId, string $slug, int $ttl = self::DEFAULT_TTL): ?string
    {
        $path = self::pathFor($siteId, $slug);
        if (!is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            return null;
        }
        $html = @file_get_contents($path);
        return $html === false ? null : $html;
    }

    /** Escribe el HTML al cache. Crea el directorio si no existe. Silencioso ante fallos. */
    public static function put(int $siteId, string $slug, string $html): bool
    {
        $path = self::pathFor($siteId, $slug);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // Escritura atómica: tmp + rename para evitar lecturas a medias.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $html) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Borra una entrada concreta (silencioso si no existe). */
    public static function forget(int $siteId, string $slug): void
    {
        $path = self::pathFor($siteId, $slug);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Borra todo el cache de un sitio. Usado al cambiar design system o memoria. */
    public static function flush(int $siteId): void
    {
        $dir = self::dirFor($siteId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Invalida el cache de una página, además de la home si aplica.
     * Acepta el array de la fila `pages` (necesita slug + page_type).
     * Si el slug ha cambiado (ej. en update), pasar también `$oldSlug`.
     */
    public static function invalidatePage(int $siteId, array $page, ?string $oldSlug = null): void
    {
        $slug = (string) ($page['slug'] ?? '');
        if ($slug !== '') {
            self::forget($siteId, $slug);
        }
        if ($oldSlug !== null && $oldSlug !== '' && $oldSlug !== $slug) {
            self::forget($siteId, $oldSlug);
        }
        // Si esta página actúa como home, también invalidar la entrada __home.
        if (($page['page_type'] ?? '') === 'home' || $slug === 'home') {
            self::forget($siteId, self::HOME_KEY);
        }
    }

    // ======================================================================
    // Internos
    // ======================================================================

    private static function dirFor(int $siteId): string
    {
        return PP_STORAGE . '/cache/pages/site_' . $siteId;
    }

    private static function pathFor(int $siteId, string $slug): string
    {
        return self::dirFor($siteId) . '/' . self::sanitizeSlug($slug) . '.html';
    }

    /**
     * Convierte un slug en nombre de archivo seguro.
     * - Slugs anidados `a/b/c` → `a__b__c` (no creamos subdirs por ahora; T7.4 evaluará si conviene)
     * - Solo a-z 0-9 - _ permitidos; resto eliminado
     * - Nunca permite `..` ni quedar vacío
     */
    private static function sanitizeSlug(string $slug): string
    {
        if ($slug === self::HOME_KEY) {
            return self::HOME_KEY;
        }
        $s = strtolower(trim($slug, '/'));
        $s = str_replace('/', '__', $s);
        $s = preg_replace('/[^a-z0-9\-_]/', '', $s) ?? '';
        if ($s === '' || $s === '.' || $s === '..') {
            return '_invalid';
        }
        return substr($s, 0, 200);
    }
}
