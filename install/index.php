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
