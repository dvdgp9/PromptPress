<?php

declare(strict_types=1);

use Core\Request;
use Core\Response;
use Core\Session;

/**
 * InstallerApp
 *
 * Mini-app que gestiona el wizard de instalación.
 * Pasos:
 *   1. requirements  — verificación de requisitos del servidor
 *   2. database      — config DB + ejecuta migraciones (T1.2)
 *   3. admin         — usuario admin + sitio (T1.3)
 *   4. ai_provider   — proveedor IA + API key (T1.4)
 *   5. complete      — finalización
 */
final class InstallerApp
{
    private const STEPS = [
        'requirements' => 'Requisitos',
        'database'     => 'Base de datos',
        'admin'        => 'Administrador',
        'ai_provider'  => 'IA',
        'complete'     => 'Finalizar',
    ];

    public static function run(): void
    {
        // Bloquear si el sistema ya está instalado
        if (is_installed()) {
            self::renderAlreadyInstalled();
            return;
        }

        $step = self::extractStep();

        // Validar paso
        if (!array_key_exists($step, self::STEPS)) {
            $step = 'requirements';
        }

        // No se puede saltar adelante: validar progreso de la sesión
        $maxAllowed = (string) (Session::get('install_max_step') ?? 'requirements');
        if (!self::isStepAllowed($step, $maxAllowed)) {
            Response::redirect(self::stepUrl($maxAllowed));
        }

        // Ejecutar paso
        $stepFile = __DIR__ . '/steps/' . $step . '.php';
        if (!is_file($stepFile)) {
            // Stubs para pasos aún no implementados
            self::renderStub($step);
            return;
        }
        require $stepFile;
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function currentStepIndex(string $current): int
    {
        $keys = array_keys(self::STEPS);
        $idx = array_search($current, $keys, true);
        return $idx === false ? 0 : (int) $idx;
    }

    public static function unlockNextStep(string $completedStep): void
    {
        $keys = array_keys(self::STEPS);
        $idx = array_search($completedStep, $keys, true);
        if ($idx === false || !isset($keys[$idx + 1])) {
            return;
        }
        $next = $keys[$idx + 1];
        $current = (string) (Session::get('install_max_step') ?? 'requirements');
        // Solo avanzar, nunca retroceder el unlock
        if (self::currentStepIndex($next) > self::currentStepIndex($current)) {
            Session::set('install_max_step', $next);
        }
    }

    /** Render del layout del instalador con un paso. */
    public static function renderStep(string $step, string $title, string $content): void
    {
        $layoutFile = __DIR__ . '/views/layout.php';
        $stepKey  = $step;
        $stepName = self::STEPS[$step] ?? $step;
        $steps    = self::STEPS;
        $stepIdx  = self::currentStepIndex($step);
        ob_start();
        require $layoutFile;
        Response::html((string) ob_get_clean());
    }

    /**
     * Extrae el paso actual: primero ?step= (más portable),
     * en su defecto desde el path /install/<step> (Apache con rewrite).
     */
    private static function extractStep(): string
    {
        $fromQuery = Request::get('step');
        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }
        $path = trim(parse_url(Request::path(), PHP_URL_PATH) ?: '/', '/');
        $parts = explode('/', $path);
        if (($parts[0] ?? '') === 'install') {
            array_shift($parts);
        }
        $step = $parts[0] ?? '';
        return $step !== '' ? $step : 'requirements';
    }

    /** URL absoluta para un paso. */
    public static function stepUrl(string $step): string
    {
        return base_url('install/?step=' . urlencode($step));
    }

    /**
     * Escribe config/config.php con la configuración de DB y app_key.
     *
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $db
     */
    public static function writeConfigFile(array $db, string $appKey): bool
    {
        $content = self::buildConfigContent($db, $appKey);
        $bytes = @file_put_contents(PP_CONFIG_FILE, $content, LOCK_EX);
        if ($bytes === false) {
            return false;
        }
        @chmod(PP_CONFIG_FILE, 0640);
        return true;
    }

    /**
     * Genera el contenido de config/config.php sin escribirlo.
     *
     * Se usa para el fallback manual: si /config no es escribible, mostramos
     * este texto y el usuario lo sube con el gestor de archivos del hosting.
     *
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $db
     */
    public static function buildConfigContent(array $db, string $appKey): string
    {
        $template = <<<'PHP'
<?php
/**
 * PromptPress — configuración generada por el instalador.
 * NO subas este archivo a control de versiones (ver .gitignore).
 */

return [
    'db' => [
        'host'    => %s,
        'port'    => %d,
        'name'    => %s,
        'user'    => %s,
        'pass'    => %s,
        'charset' => %s,
    ],
    'app_key' => %s,
    'env'     => 'production',
];
PHP;
        $content = sprintf(
            $template,
            var_export($db['host'], true),
            (int) $db['port'],
            var_export($db['name'], true),
            var_export($db['user'], true),
            var_export($db['pass'], true),
            var_export($db['charset'], true),
            var_export($appKey, true),
        );
        return $content;
    }

    /**
     * Escribe config/image_bank.php con la Access Key de Unsplash del cliente.
     *
     * Archivo aparte (no config.php) porque: (a) `config.php` lo regenera el
     * instalador y perdería la clave en reinstalaciones; (b) está gitignored,
     * así la clave nunca llega al repo; (c) `core/App::boot()` lo fusiona bajo
     * config.php automáticamente. Devuelve false si no se pudo escribir.
     */
    public static function writeImageBankFile(string $accessKey): bool
    {
        $content = self::buildImageBankContent($accessKey);
        $path = PP_CONFIG . '/image_bank.php';
        $bytes = @file_put_contents($path, $content, LOCK_EX);
        if ($bytes === false) {
            return false;
        }
        @chmod($path, 0640);
        return true;
    }

    /** Genera el contenido de config/image_bank.php con la key dada. */
    public static function buildImageBankContent(string $accessKey): string
    {
        $template = <<<'PHP'
<?php
/**
 * PromptPress — Banco de imágenes (Unsplash). Generado por el instalador.
 * Archivo GITIGNORED: no se sube al repo y el instalador no lo pisa salvo que
 * vuelvas a introducir una clave. `core/App::boot()` lo fusiona bajo config.php.
 */

return [
    'image_bank' => [
        'provider'   => 'unsplash',
        'access_key' => %s,
        'app_name'   => 'promptpress',
        'cache_ttl'  => 86400,
    ],
];
PHP;
        return sprintf($template, var_export($accessKey, true));
    }

    private static function isStepAllowed(string $requested, string $maxAllowed): bool
    {
        return self::currentStepIndex($requested) <= self::currentStepIndex($maxAllowed);
    }

    private static function renderAlreadyInstalled(): void
    {
        $title = 'Ya instalado';
        $content = '<div class="pp-alert pp-alert--warn">'
            . '<h2>El sistema ya está instalado</h2>'
            . '<p>PromptPress ya ha sido configurado en este servidor. Si quieres reinstalar, '
            . 'borra <code>config/config.php</code> y <code>install/.installed</code> manualmente.</p>'
            . '<p><a class="pp-btn pp-btn--primary" href="' . e(base_url('admin/')) . '">Ir al panel</a></p>'
            . '</div>';
        self::renderStep('requirements', $title, $content);
    }

    private static function renderStub(string $step): void
    {
        $title = 'Paso pendiente';
        $content = '<div class="pp-alert pp-alert--info">'
            . '<h2>Paso "' . e($step) . '" en desarrollo</h2>'
            . '<p>Este paso será implementado en una tarea futura.</p>'
            . '</div>';
        self::renderStep($step, $title, $content);
    }
}
