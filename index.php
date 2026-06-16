<?php
/**
 * PromptPress — Front Controller
 *
 * Toda petición HTTP entra aquí (vía .htaccess / nginx try_files).
 */

declare(strict_types=1);

// Built-in dev server: si la URL apunta a un archivo estático real,
// devolver false para que PHP lo sirva con su content-type correcto.
// (Apache/Nginx no llegan aquí gracias a las RewriteCond del .htaccess)
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if ($requested !== '/' && is_file(__DIR__ . $requested)) {
        return false;
    }
}

// 1. Constantes y paths
require_once __DIR__ . '/config/constants.php';

// 2. Autoloader propio
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

// 3. Composer (opcional, si existe)
if (is_file(PP_ROOT . '/vendor/autoload.php')) {
    require_once PP_ROOT . '/vendor/autoload.php';
}

// 4. Bootstrap + dispatch
\Core\App::run();
