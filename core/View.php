<?php

namespace Core;

/**
 * Template engine mínimo: extract + include.
 * Las vistas viven en /views y reciben variables como array.
 */
final class View
{
    private static string $layout = '';
    private static array  $sections = [];
    private static string $currentSection = '';

    /**
     * Renderiza una vista y devuelve el HTML.
     * @param string $view ruta relativa a /views, sin extensión (ej: 'admin/dashboard')
     * @param array  $data variables a inyectar
     */
    public static function render(string $view, array $data = []): string
    {
        $file = PP_VIEWS . '/' . ltrim($view, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        // Reset estado de layout para cada render top-level
        self::$layout = '';
        self::$sections = [];

        $content = self::renderFile($file, $data);

        // Si la vista declaró un layout, renderizarlo
        if (self::$layout !== '') {
            $layoutFile = PP_VIEWS . '/' . ltrim(self::$layout, '/') . '.php';
            if (!is_file($layoutFile)) {
                throw new \RuntimeException("Layout not found: " . self::$layout);
            }
            self::$sections['content'] = $content;
            $content = self::renderFile($layoutFile, $data);
        }

        return $content;
    }

    /** Atajo: renderiza y envía como respuesta HTML. */
    public static function send(string $view, array $data = [], int $status = 200): never
    {
        Response::html(self::render($view, $data), $status);
    }

    private static function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /** Llamada desde una vista para declarar el layout que la envuelve. */
    public static function extend(string $layout): void
    {
        self::$layout = $layout;
    }

    /** Inicia la captura de una sección (usado dentro de vistas). */
    public static function start(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    /** Finaliza la captura de la sección actual. */
    public static function end(): void
    {
        if (self::$currentSection === '') {
            return;
        }
        self::$sections[self::$currentSection] = (string) ob_get_clean();
        self::$currentSection = '';
    }

    /** Recupera el contenido de una sección dentro del layout. */
    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }
}
