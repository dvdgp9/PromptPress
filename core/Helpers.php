<?php
/**
 * Funciones helper globales de PromptPress.
 * Cargadas explícitamente por App::boot().
 */

if (!function_exists('e')) {
    /** Escape para HTML (XSS-safe). */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('slugify')) {
    /** Convierte un string a slug URL-friendly. */
    function slugify(string $text): string
    {
        $text = trim($text);
        // Transliteración básica
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }
}

if (!function_exists('config')) {
    /** Acceso a configuración cargada (key dotted: 'db.host'). */
    function config(string $key, mixed $default = null): mixed
    {
        $config = \Core\App::config();
        $segments = explode('.', $key);
        $value = $config;
        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }
}

if (!function_exists('is_installed')) {
    /** Comprueba si el sistema está instalado. */
    function is_installed(): bool
    {
        return is_file(PP_CONFIG_FILE) && is_file(PP_INSTALLED_FLAG);
    }
}

if (!function_exists('base_url')) {
    /**
     * URL base de la aplicación (raíz pública, sin barra final).
     * Asume que la app vive en la raíz del docroot. Para subcarpetas,
     * define la constante PP_BASE_PATH = '/subcarpeta' en config/config.php.
     */
    function base_url(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $prefix = defined('PP_BASE_PATH') ? rtrim('/' . trim((string) PP_BASE_PATH, '/'), '/') : '';
        $base   = $scheme . '://' . $host . $prefix;
        return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('redirect')) {
    /** Redirección y exit. */
    function redirect(string $url, int $status = 302): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }
}

if (!function_exists('logger')) {
    /** Log simple a /storage/logs/app.log */
    function logger(string $message, string $level = 'INFO'): void
    {
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
        @file_put_contents(PP_STORAGE . '/logs/app.log', $line, FILE_APPEND);
    }
}
