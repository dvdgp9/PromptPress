<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\ArticleTemplateService;
use App\Services\LanguageService;
use App\Services\SiteResetService;
use App\Services\UpdateInstallerService;
use App\Services\UpdateService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Ajustes generales del sitio (T9.1).
 */
class SettingsController
{
    /**
     * El catálogo de idiomas vive en LanguageService (lo comparten Ajustes, el
     * instalador y el pipeline de generación). Se mantiene la constante por
     * compatibilidad con el código que ya la referenciaba.
     */
    public const LANGUAGES = LanguageService::LANGUAGES;

    public const TIMEZONES = [
        'Europe/Madrid'          => 'Europa / Madrid',
        'Europe/London'          => 'Europa / Londres',
        'Europe/Paris'           => 'Europa / París',
        'Europe/Berlin'          => 'Europa / Berlín',
        'America/New_York'       => 'América / Nueva York',
        'America/Mexico_City'    => 'América / Ciudad de México',
        'America/Bogota'         => 'América / Bogotá',
        'America/Buenos_Aires'   => 'América / Buenos Aires',
        'America/Santiago'       => 'América / Santiago de Chile',
        'UTC'                    => 'UTC',
    ];

    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $site = $this->loadSite($siteId);

        $this->render([
            'site'   => $site,
            'resetCounts' => SiteResetService::counts($siteId),
            'updateStatus' => UpdateService::status($siteId),
            'updateBackups' => UpdateInstallerService::backups(),
            'errors' => [],
            'notice' => Session::flash('notice'),
        ]);
    }

    public function update(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $site = $this->loadSite($siteId);

        $input = [
            'name'     => trim((string) Request::post('name', '')),
            'url'      => trim((string) Request::post('url', '')),
            'language' => (string) Request::post('language', 'es'),
            'timezone' => (string) Request::post('timezone', 'Europe/Madrid'),
            'article_template' => ArticleTemplateService::normalize((string) Request::post('article_template', ArticleTemplateService::DEFAULT)),
        ];
        $errors = $this->validate($input, $siteId);

        if ($errors !== []) {
            $this->render([
                'site'   => array_merge($site, $input),
                'resetCounts' => SiteResetService::counts($siteId),
                'articleTemplate' => $input['article_template'],
                'errors' => $errors,
                'notice' => null,
            ]);
            return;
        }

        $normalizedUrl = rtrim($input['url'], '/');
        if (preg_match('#^https?://[^/]+$#i', $normalizedUrl)) {
            $normalizedUrl .= '/';
        }

        Database::execute(
            'UPDATE sites
             SET name = ?, url = ?, language = ?, timezone = ?
             WHERE id = ?',
            [
                $input['name'],
                $normalizedUrl,
                $input['language'],
                $input['timezone'],
                $siteId,
            ]
        );
        $this->saveSetting($siteId, ArticleTemplateService::SETTING_KEY, $input['article_template']);

        // Cambiar el idioma NO traduce nada de lo ya escrito: sería destructivo
        // y silencioso. Solo afecta a lo que se resuelve en cada render
        // (textos de interfaz del sitio: botones, avisos, banner de cookies) y
        // a lo que la IA genere a partir de ahora. Se avisa explícitamente.
        $languageChanged = LanguageService::normalize($site['language'] ?? null) !== LanguageService::normalize($input['language']);
        if ($languageChanged) {
            // `sites.language` y el catálogo `site_languages` tienen que contar
            // lo mismo: si divergen, `primaryFor()` devuelve el idioma viejo y
            // los prefijos de URL dejan de cuadrar.
            LanguageService::setPrimary($siteId, $input['language']);
        }
        LanguageService::forget($siteId);

        CacheService::flush($siteId);
        $notice = 'Ajustes generales guardados. Caché pública regenerada.';
        if ($languageChanged) {
            $notice .= ' Idioma cambiado a ' . LanguageService::label($input['language']) . ':'
                . ' los textos automáticos del sitio (botones de formulario, avisos, banner de cookies)'
                . ' y todo lo que genere la IA a partir de ahora usarán el nuevo idioma.'
                . ' El contenido ya escrito, los títulos de página y las etiquetas del menú NO se traducen solos:'
                . ' revísalos o pídeselo al asistente.';
        }
        Session::flash('notice', $notice);
        Response::redirect(base_url('admin/settings'));
    }

    public function resetSite(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $site = $this->loadSite($siteId);
        if (Auth::role() !== 'admin') {
            Response::forbidden('Acceso denegado');
        }
        $confirmation = trim((string) Request::post('confirmation', ''));
        if ($confirmation !== (string) $site['name']) {
            Session::flash('error', 'El nombre no coincide. No hemos reiniciado nada.');
            Response::redirect(base_url('admin/settings'));
        }
        SiteResetService::reset($siteId);
        Auth::logout();
        Session::flash('success', 'Sitio reiniciado. Pasa de nuevo por el onboarding.');
        Response::redirect(base_url('admin/login'));
    }

    public function checkUpdates(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $status = UpdateService::checkNow($siteId);
        $msg = $status['has_update']
            ? 'Nueva versión detectada: ' . ($status['latest_version'] ?? 'desconocida') . '.'
            : $status['message'];
        Session::flash('notice', $msg);
        Response::redirect(base_url('admin/settings'));
    }

    public function applyUpdate(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden('Acceso denegado');
        }

        $siteId = $this->requireSiteId();

        try {
            $result = UpdateInstallerService::apply($siteId);
            $label = $result['version'] ? ('v' . $result['version']) : 'la nueva versión';
            Session::flash('notice', 'Actualización aplicada (' . $label . '). Backup: ' . $result['backup']);
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo aplicar la actualización: ' . $e->getMessage());
        }

        Response::redirect(base_url('admin/settings'));
    }

    /**
     * UPD — POST /admin/settings/upload-update
     * Actualiza la plataforma desde un ZIP subido a mano.
     */
    public function uploadUpdate(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden('Acceso denegado');
        }
        $this->requireSiteId();

        // Copiar cientos de archivos y migrar puede pasar del límite por defecto.
        @set_time_limit(300);

        $checksum = trim((string) Request::post('checksum', ''));

        try {
            $result = UpdateInstallerService::applyFromUpload($_FILES['package'] ?? [], $checksum);
            $label = $result['version'] ? ('v' . $result['version']) : 'el paquete subido';
            Session::flash('notice',
                'Actualización aplicada (' . $label . '). Se ha guardado una copia de seguridad previa: '
                . basename($result['backup']) . '. Si algo no funciona, puedes restaurarla desde aquí mismo.');
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo aplicar la actualización: ' . $e->getMessage());
        }

        Response::redirect(base_url('admin/settings'));
    }

    /**
     * UPD — POST /admin/settings/restore-update
     * Vuelve a una copia de seguridad anterior.
     */
    public function restoreUpdate(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden('Acceso denegado');
        }
        $this->requireSiteId();
        @set_time_limit(300);

        $name = trim((string) Request::post('backup', ''));

        try {
            $result = UpdateInstallerService::restore($name);
            Session::flash('notice',
                'Copia restaurada (' . $result['restored'] . '). Antes de restaurar guardamos el estado anterior en '
                . $result['safety_backup'] . ', por si necesitas volver.');
        } catch (\Throwable $e) {
            Session::flash('error', 'No se pudo restaurar la copia: ' . $e->getMessage());
        }

        Response::redirect(base_url('admin/settings'));
    }

    /**
     * Activa un idioma adicional para el sitio (I18N-FULL T1.3).
     *
     * Opt-in puro: hasta que alguien pulse aquí, el sitio sigue siendo de un
     * solo idioma y se comporta exactamente igual que antes.
     */
    public function addLanguage(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $code = (string) Request::post('code', '');

        $result = LanguageService::enable($siteId, $code);
        if (!($result['ok'] ?? false)) {
            Session::flash('error', 'Idioma no válido.');
        } elseif (!empty($result['already'])) {
            Session::flash('notice', LanguageService::label($code) . ' ya estaba activo.');
        } else {
            Session::flash('notice', sprintf(
                'Idioma %s añadido. Sus páginas vivirán bajo /%s/ y el idioma principal mantiene sus URLs actuales.'
                . ' Todavía no hay contenido traducido: créalo o pídeselo al asistente.',
                LanguageService::label($code),
                LanguageService::normalize($code)
            ));
        }
        Response::redirect(base_url('admin/settings'));
    }

    /**
     * Desactiva un idioma adicional. Nunca borra contenido: si el idioma
     * todavía tiene páginas, se rechaza y se dice cuántas.
     */
    public function removeLanguage(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $code = (string) Request::post('code', '');

        $result = LanguageService::disable($siteId, $code);
        if ($result['ok'] ?? false) {
            Session::flash('notice', 'Idioma ' . LanguageService::label($code) . ' desactivado.');
        } elseif (($result['error'] ?? '') === 'primary') {
            Session::flash('error', 'No puedes desactivar el idioma principal del sitio.');
        } elseif (($result['error'] ?? '') === 'has_pages') {
            Session::flash('error', sprintf(
                'No se puede desactivar %s: todavía tiene %d página(s). Bórralas o cámbialas de idioma primero'
                . ' — desactivar un idioma nunca borra contenido.',
                LanguageService::label($code),
                (int) ($result['pages'] ?? 0)
            ));
        } else {
            Session::flash('error', 'No se pudo desactivar el idioma.');
        }
        Response::redirect(base_url('admin/settings'));
    }

    private function render(array $ctx): void
    {
        View::send('admin/settings/index', array_merge(
            DashboardController::getCommonData(),
            [
                'site'      => $ctx['site'],
                'resetCounts' => $ctx['resetCounts'] ?? [],
                'updateStatus' => $ctx['updateStatus'] ?? null,
                'updateBackups' => $ctx['updateBackups'] ?? UpdateInstallerService::backups(),
                'languages' => self::LANGUAGES,
                'activeLanguages' => LanguageService::activeFor((int) $ctx['site']['id']),
                'primaryLanguage' => LanguageService::primaryFor((int) $ctx['site']['id']),
                'timezones' => self::TIMEZONES,
                'articleTemplate' => $ctx['articleTemplate'] ?? ArticleTemplateService::forSite((int) $ctx['site']['id']),
                'articleTemplateOptions' => $this->articleTemplateOptions(),
                'errors'    => $ctx['errors'],
                'notice'    => $ctx['notice'],
                'csrf'      => CSRF::token(),
            ]
        ));
    }

    private function validate(array $input, int $siteId): array
    {
        $errors = [];

        if ($input['name'] === '') {
            $errors['name'] = 'El nombre del sitio es obligatorio.';
        } elseif (mb_strlen($input['name']) > 255) {
            $errors['name'] = 'El nombre del sitio no puede superar 255 caracteres.';
        }

        if ($input['url'] === '') {
            $errors['url'] = 'La URL del sitio es obligatoria.';
        } elseif (mb_strlen($input['url']) > 500) {
            $errors['url'] = 'La URL del sitio no puede superar 500 caracteres.';
        } elseif (!filter_var($input['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'La URL debe ser válida y empezar por http:// o https://.';
        } else {
            $scheme = strtolower((string) parse_url($input['url'], PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $errors['url'] = 'La URL debe usar http:// o https://.';
            }
        }

        if (!array_key_exists($input['language'], self::LANGUAGES)) {
            $errors['language'] = 'Idioma no válido.';
        } elseif (LanguageService::isMultilingual($siteId)
            && LanguageService::normalize($input['language']) !== LanguageService::primaryFor($siteId)) {
            // Cambiar el principal en una web multi-idioma reescribiría las URLs
            // de todas las páginas (el idioma sin prefijo pasaría a tenerlo).
            $errors['language'] = 'No puedes cambiar el idioma principal mientras haya idiomas adicionales activos:'
                . ' cambiarían las URLs de todas las páginas. Desactiva primero los adicionales.';
        }

        if (!array_key_exists($input['timezone'], self::TIMEZONES)) {
            $errors['timezone'] = 'Zona horaria no válida.';
        }

        return $errors;
    }

    /** @return array<string,string> */
    private function articleTemplateOptions(): array
    {
        $all = ArticleTemplateService::options();
        return array_intersect_key($all, array_flip(['classic', 'visual']));
    }

    private function saveSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, $key, $value]
        );
    }

    private function loadSite(int $siteId): array
    {
        $site = Database::selectOne(
            'SELECT id, name, url, language, timezone, created_at, updated_at
             FROM sites WHERE id = ? LIMIT 1',
            [$siteId]
        );
        if (!$site) {
            Session::flash('error', 'No se encontró el sitio activo.');
            Response::redirect(base_url('admin/logout'));
        }
        return $site;
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
