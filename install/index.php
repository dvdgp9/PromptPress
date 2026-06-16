<?php
/**
 * PromptPress — Instalador (front controller del wizard).
 *
 * Se invoca de dos formas:
 *   1) Directamente: Apache sirve install/index.php cuando entras a /install/
 *   2) Vía front controller raíz: index.php detecta /install y nos delega
 *
 * Maneja los pasos del wizard y bloquea acceso si el sistema ya está instalado.
 */

declare(strict_types=1);

try {
    // Si fuimos cargados directamente (no a través de index.php raíz),
    // inicializar constantes y autoloader.
    if (!defined('PP_VERSION')) {
        require_once dirname(__DIR__) . '/config/constants.php';
        require_once PP_CORE . '/Autoloader.php';
        \Core\Autoloader::register();
        if (is_file(PP_ROOT . '/vendor/autoload.php')) {
            require_once PP_ROOT . '/vendor/autoload.php';
        }
        \Core\App::boot();
    }

    require_once __DIR__ . '/InstallerApp.php';

    InstallerApp::run();
} catch (\Throwable $e) {
    $root = defined('PP_ROOT') ? PP_ROOT : dirname(__DIR__);
    $line = sprintf(
        "[%s] [INSTALL] %s @ %s:%d\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents($root . '/storage/logs/php-errors.log', $line, FILE_APPEND);

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Error instalador PromptPress</title>';
    echo '<div style="font:14px system-ui,-apple-system,Segoe UI,sans-serif;max-width:960px;margin:40px auto;padding:24px;border:1px solid #f1b5b5;background:#fff5f5;color:#3b0a0a;border-radius:8px">';
    echo '<h1 style="margin-top:0">Error del instalador PromptPress</h1>';
    echo '<p>El servidor ha devuelto un error antes de poder mostrar el asistente. Detalle técnico:</p>';
    echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #f3caca;padding:16px;border-radius:6px;overflow:auto">';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    echo 'Archivo: ' . htmlspecialchars($e->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':' . (int) $e->getLine();
    echo '</pre>';
    echo '<p>También se ha intentado escribir en <code>storage/logs/php-errors.log</code>.</p>';
    echo '</div>';
    exit;
}
