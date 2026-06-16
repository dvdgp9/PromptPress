<?php

namespace Core;

use Throwable;

/**
 * Bootstrap principal de PromptPress.
 *
 * Responsabilidades:
 *  - Inicializar entorno (errores, sesión)
 *  - Cargar configuración
 *  - Construir Request, Router, Response
 *  - Manejar errores no capturados
 */
final class App
{
    private static ?array $config = null;
    private static bool   $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        // 1. Entorno PHP
        self::configureErrorHandling();

        // 2. Helpers globales
        require_once PP_CORE . '/Helpers.php';

        // 3. Cargar config si existe (no obligatoria si estamos en instalador)
        if (is_file(PP_CONFIG_FILE)) {
            self::$config = require PP_CONFIG_FILE;
        } else {
            self::$config = [];
        }

        // 3b. Defaults locales fuera de control de versiones. El instalador
        // regenera `config.php` con solo db/app_key/env, así que valores
        // compartidos por toda la instalación (p. ej. la Access Key universal
        // de Unsplash) viven aquí: el instalador NUNCA toca este archivo, por
        // lo que sobreviven a reinstalaciones, y al estar gitignored no llegan
        // al repo. `config.php` tiene prioridad si define las mismas claves.
        $localDefaults = PP_CONFIG . '/image_bank.php';
        if (is_file($localDefaults)) {
            $extra = require $localDefaults;
            if (is_array($extra)) {
                self::$config = array_replace_recursive($extra, self::$config);
            }
        }

        // 4. Sesión segura
        Session::start();

        // 5. Migraciones automáticas solo si la instalación lo habilita.
        // En hosting compartido conviene que sea opt-in para evitar sorpresas
        // en cada request; el CLI `php database/migrate.php` es el camino normal.
        self::runAutoMigrationsIfEnabled();

        self::$booted = true;
    }

    public static function run(): void
    {
        self::boot();

        try {
            $path = Request::path();

            // Delegación al instalador: cualquier ruta /install* la maneja install/index.php
            if (str_starts_with($path, '/install')) {
                require PP_INSTALL . '/index.php';
                return;
            }

            // Si no está instalado y se intenta entrar a /admin, redirigir al instalador
            if (!is_installed() && str_starts_with($path, '/admin')) {
                redirect(base_url('install/'));
            }

            $router = new Router();
            require PP_APP . '/routes.php'; // espera $router en scope
            $router->dispatch(Request::method(), Request::path());

        } catch (Throwable $e) {
            self::handleException($e);
        }
    }

    public static function config(): array
    {
        return self::$config ?? [];
    }

    private static function configureErrorHandling(): void
    {
        $isDev = (defined('PP_ENV') && PP_ENV === 'development');
        ini_set('display_errors', $isDev ? '1' : '0');
        ini_set('display_startup_errors', $isDev ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', PP_STORAGE . '/logs/php-errors.log');
        error_reporting(E_ALL);
        // Asegurar UTF-8 si mbstring está disponible. El instalador ya avisa
        // si falta la extensión; no debe provocar un 500 antes de poder verlo.
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }
        // Timezone por defecto (puede sobrescribirse)
        if (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }

    private static function runAutoMigrationsIfEnabled(): void
    {
        $enabled = (bool) (self::$config['migrations']['auto_run'] ?? false);
        if (!$enabled || !is_file(PP_INSTALLED_FLAG)) {
            return;
        }

        require_once PP_ROOT . '/database/Migrator.php';

        $migrator = new \PromptPress\Database\Migrator(
            Database::connection(),
            PP_ROOT . '/database/migrations'
        );
        $result = $migrator->run();

        if (!empty($result['errors'])) {
            $first = $result['errors'][0];
            throw new \RuntimeException('Migration failed: ' . $first['name'] . ' — ' . $first['error']);
        }
    }

    private static function handleException(Throwable $e): void
    {
        logger($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'ERROR');
        $isDev = (defined('PP_ENV') && PP_ENV === 'development');

        http_response_code(500);
        if ($isDev) {
            echo '<pre style="font:13px monospace;padding:1rem;background:#fee;border:1px solid #c00;">';
            echo "ERROR: " . e($e->getMessage()) . "\n";
            echo "File:  " . e($e->getFile()) . ':' . $e->getLine() . "\n\n";
            echo e($e->getTraceAsString());
            echo '</pre>';
        } else {
            echo '<h1>500 — Error interno</h1><p>Algo ha ido mal. Si el problema persiste, contacta con el administrador.</p>';
        }
        exit;
    }
}
