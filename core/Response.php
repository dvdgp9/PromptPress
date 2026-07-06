<?php

namespace Core;

/**
 * Helpers de respuesta HTTP.
 */
final class Response
{
    public static function html(string $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $body;
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url, int $status = 302): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }

    /** 204 sin cuerpo — para endpoints de telemetría (analytics collect). */
    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function notFound(string $message = 'Página no encontrada'): never
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h1>404 — ' . e($message) . '</h1>';
        exit;
    }

    public static function forbidden(string $message = 'Acceso denegado'): never
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<h1>403 — ' . e($message) . '</h1>';
        exit;
    }
}
