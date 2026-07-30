<?php

declare(strict_types=1);

namespace App\Services\Canvas;

/**
 * CANCEL — Marca de cancelación de una generación del Studio.
 *
 * Abortar el `fetch` en el navegador NO detiene a PHP: la petición sigue viva,
 * el modelo responde y el cambio se guardaría igual. El usuario creería haber
 * parado algo y se encontraría la página modificada.
 *
 * Por eso el navegador, además de abortar, avisa al servidor con el mismo
 * `request_id`. El pipeline mira esta marca JUSTO ANTES de guardar y descarta
 * el resultado si está puesta.
 *
 * Se guarda en disco (no en sesión) a propósito: la petición del chat mantiene
 * el bloqueo de sesión de PHP durante toda la llamada a la IA, así que un
 * `session_start()` en la petición de cancelar se quedaría esperando justo a lo
 * que quiere cancelar.
 */
final class CanvasCancelToken
{
    /** Las marcas viejas se barren para que la carpeta no crezca sin fin. */
    private const TTL_SECONDS = 1800;

    public static function isValidId(string $requestId): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $requestId) === 1;
    }

    /** Marca la petición como cancelada. */
    public static function cancel(int $siteId, string $requestId): bool
    {
        if (!self::isValidId($requestId)) return false;

        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;

        self::sweep($dir);
        return @file_put_contents(self::path($siteId, $requestId), (string) time(), LOCK_EX) !== false;
    }

    /**
     * ¿Se pidió cancelar esta petición? Consume la marca: una cancelación vale
     * para una ejecución.
     */
    public static function isCancelled(int $siteId, string $requestId): bool
    {
        if (!self::isValidId($requestId)) return false;

        $path = self::path($siteId, $requestId);
        if (!is_file($path)) return false;

        @unlink($path);
        return true;
    }

    public static function forget(int $siteId, string $requestId): void
    {
        if (!self::isValidId($requestId)) return;
        $path = self::path($siteId, $requestId);
        if (is_file($path)) @unlink($path);
    }

    private static function dir(): string
    {
        return PP_ROOT . '/storage/cache/canvas-cancel';
    }

    private static function path(int $siteId, string $requestId): string
    {
        return self::dir() . '/' . $siteId . '-' . sha1($requestId) . '.cancel';
    }

    private static function sweep(string $dir): void
    {
        foreach (glob($dir . '/*.cancel') ?: [] as $file) {
            if (@filemtime($file) < time() - self::TTL_SECONDS) @unlink($file);
        }
    }
}
