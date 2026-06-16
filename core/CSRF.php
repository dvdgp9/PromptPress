<?php

namespace Core;

/**
 * Protección CSRF basada en token de sesión.
 *
 * Uso:
 *   <input type="hidden" name="_csrf" value="<?= CSRF::token() ?>">
 *   CSRF::validate(Request::post('_csrf')); // lanza si inválido
 */
final class CSRF
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function validate(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);
        if (!is_string($expected) || !is_string($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /** Renueva el token (tras login, cambios sensibles). */
    public static function renew(): string
    {
        Session::forget(self::SESSION_KEY);
        return self::token();
    }

    /** Helper que lanza si el CSRF de la petición es inválido. */
    public static function check(): void
    {
        $token = Request::post('_csrf') ?? Request::input('_csrf');
        if ($token === null && Request::isJson()) {
            $json = Request::json();
            $token = $json['_csrf'] ?? null;
        }
        if (!self::validate(is_string($token) ? $token : null)) {
            Response::forbidden('CSRF token inválido o ausente');
        }
    }
}
