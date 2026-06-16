<?php

namespace Core;

/**
 * Wrapper de la petición HTTP entrante.
 * Centraliza acceso a $_GET, $_POST, $_SERVER y body JSON.
 */
final class Request
{
    private static ?array $jsonCache = null;

    public static function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Soporte de _method override en formularios (HTML no soporta PUT/DELETE)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        return $method;
    }

    /**
     * Path normalizado de la petición, sin query string.
     * Asume que la app vive en la raíz del docroot. Si vive en subcarpeta,
     * configura PP_BASE_PATH (constante) para que se quite el prefijo.
     */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH) ?? '/';

        if (defined('PP_BASE_PATH') && PP_BASE_PATH !== '' && PP_BASE_PATH !== '/') {
            $base = '/' . trim((string) PP_BASE_PATH, '/');
            if (str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
        }
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }
        return $uri;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    /** Lee body JSON de una petición. */
    public static function json(): array
    {
        if (self::$jsonCache !== null) {
            return self::$jsonCache;
        }
        $body = file_get_contents('php://input') ?: '';
        if ($body === '') {
            self::$jsonCache = [];
            return self::$jsonCache;
        }
        $decoded = json_decode($body, true);
        self::$jsonCache = is_array($decoded) ? $decoded : [];
        return self::$jsonCache;
    }

    public static function isJson(): bool
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        return stripos($ct, 'application/json') !== false;
    }

    public static function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public static function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
