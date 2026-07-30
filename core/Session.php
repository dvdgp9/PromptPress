<?php

namespace Core;

/**
 * Sesiones seguras.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('PPRESSSESSID');
        session_start();
    }

    /**
     * Cierra la sesión para escritura liberando su bloqueo.
     *
     * PHP mantiene un lock exclusivo del fichero de sesión durante TODA la
     * petición, así que dos peticiones del mismo visitante se sirven en serie.
     * En rutas que solo escupen un archivo (logo, fuentes) eso hace que los
     * assets esperen a la petición de la página: con fuentes propias se ve como
     * un parpadeo largo de la tipografía genérica a la de marca.
     *
     * Tras llamar a esto, `$_SESSION` se sigue pudiendo leer pero los cambios
     * ya no se guardan: úsalo solo cuando no quede nada que escribir.
     */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flush(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Flash message (un solo uso). */
    public static function flash(string $key, ?string $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }
}
