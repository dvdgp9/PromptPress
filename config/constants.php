<?php
/**
 * Constantes globales de PromptPress.
 * Paths y versión. Cargado por index.php antes que nada.
 */

if (!defined('PP_VERSION')) {
    define('PP_VERSION', '0.1.0-dev');
}

// Path raíz del proyecto (sin barra final)
if (!defined('PP_ROOT')) {
    define('PP_ROOT', dirname(__DIR__));
}

// Paths principales
define('PP_CONFIG',  PP_ROOT . '/config');
define('PP_CORE',    PP_ROOT . '/core');
define('PP_APP',     PP_ROOT . '/app');
define('PP_VIEWS',   PP_ROOT . '/views');
define('PP_STORAGE', PP_ROOT . '/storage');
define('PP_PUBLIC',  PP_ROOT . '/public');
define('PP_INSTALL', PP_ROOT . '/install');

// Archivos clave
define('PP_CONFIG_FILE',     PP_CONFIG . '/config.php');
define('PP_INSTALLED_FLAG',  PP_INSTALL . '/.installed');

// Entorno por defecto (puede sobrescribirse en config.php)
if (!defined('PP_ENV')) {
    define('PP_ENV', 'production'); // 'production' | 'development'
}
