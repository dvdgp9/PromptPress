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
    /** Claves de traducción: el nombre visible sale de `steps()`. */
    private const STEPS = [
        'requirements' => 'inst.step.requirements',
        'database'     => 'inst.step.database',
        'admin'        => 'inst.step.admin',
        'ai_provider'  => 'inst.step.ai',
        'complete'     => 'inst.step.complete',
    ];

    public static function run(): void
    {
        self::resolveLanguage();

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

    /** Los pasos con el nombre ya traducido, para pintar la barra de progreso. */
    public static function steps(): array
    {
        return array_map(static fn (string $key): string => __($key), self::STEPS);
    }

    /**
     * Idioma del instalador. Aquí no hay ni usuario ni sitio de los que
     * deducirlo, así que manda `?lang=`, luego lo que se eligió antes en esta
     * misma instalación y, si no hay nada, el idioma del navegador.
     */
    private static function resolveLanguage(): void
    {
        $requested = (string) (Request::get('lang') ?? '');
        if ($requested !== '' && in_array($requested, \App\Services\AdminI18n::LOCALES, true)) {
            Session::set('install_lang', $requested);
            \App\Services\AdminI18n::setLocale($requested);
            return;
        }
        $saved = (string) (Session::get('install_lang') ?? '');
        if ($saved !== '') {
            \App\Services\AdminI18n::setLocale($saved);
            return;
        }
        \App\Services\AdminI18n::setLocale(\App\Services\AdminI18n::resolveFrom(
            null,
            null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        ));
    }

    /** El idioma activo del instalador (para el selector del layout). */
    public static function language(): string
    {
        return \App\Services\AdminI18n::locale();
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
        $stepName = isset(self::STEPS[$step]) ? __(self::STEPS[$step]) : $step;
        $steps    = self::steps();
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
        // Fuente única en ImageBankService (también la usan los Ajustes admin).
        return \App\Services\ImageBankService::writeConfig($accessKey);
    }

    private static function isStepAllowed(string $requested, string $maxAllowed): bool
    {
        return self::currentStepIndex($requested) <= self::currentStepIndex($maxAllowed);
    }

    private static function renderAlreadyInstalled(): void
    {
        $title = __('inst.done.title');
        $content = '<div class="pp-alert pp-alert--warn">'
            . '<h2>' . e(__('inst.done.heading')) . '</h2>'
            . '<p>' . __('inst.done.body.html') . '</p>'
            . '<p><a class="pp-btn pp-btn--primary" href="' . e(base_url('admin/')) . '">' . e(__('inst.done.go_panel')) . '</a></p>'
            . '</div>';
        self::renderStep('requirements', $title, $content);
    }

    private static function renderStub(string $step): void
    {
        $title = __('inst.stub.title');
        $content = '<div class="pp-alert pp-alert--info">'
            . '<h2>' . e(__('inst.stub.heading', ['paso' => $step])) . '</h2>'
            . '<p>' . e(__('inst.stub.body')) . '</p>'
            . '</div>';
        self::renderStep($step, $title, $content);
    }
}
