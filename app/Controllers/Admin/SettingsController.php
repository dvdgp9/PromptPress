<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AdminI18n;
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

    /**
     * Zonas horarias admitidas. La CLAVE es el identificador de la BD y no se
     * traduce nunca; la etiqueta se resuelve al pintarla (`timezoneOptions()`),
     * no aquí: una constante se evalúa antes de saber el idioma del usuario.
     */
    public const TIMEZONES = [
        'Europe/Madrid'          => 'timezone.europe_madrid',
        'Europe/London'          => 'timezone.europe_london',
        'Europe/Paris'           => 'timezone.europe_paris',
        'Europe/Berlin'          => 'timezone.europe_berlin',
        'America/New_York'       => 'timezone.america_new_york',
        'America/Mexico_City'    => 'timezone.america_mexico_city',
        'America/Bogota'         => 'timezone.america_bogota',
        'America/Buenos_Aires'   => 'timezone.america_buenos_aires',
        'America/Santiago'       => 'timezone.america_santiago',
        'UTC'                    => 'timezone.utc',
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
        $notice = __('settings.flash.saved');
        if ($languageChanged) {
            $notice .= ' ' . __('settings.flash.language_changed', ['idioma' => LanguageService::label($input['language'])]);
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
            Response::forbidden(__('common.access_denied'));
        }
        $confirmation = trim((string) Request::post('confirmation', ''));
        if ($confirmation !== (string) $site['name']) {
            Session::flash('error', __('settings.reset.name_mismatch'));
            Response::redirect(base_url('admin/settings'));
        }
        SiteResetService::reset($siteId);
        Auth::logout();
        Session::flash('success', __('settings.reset.done'));
        Response::redirect(base_url('admin/login'));
    }

    public function checkUpdates(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $status = UpdateService::checkNow($siteId);
        $msg = $status['has_update']
            ? __('settings.update.new_detected', ['version' => $status['latest_version'] ?? __('settings.update.unknown_version')])
            : $status['message'];
        Session::flash('notice', $msg);
        Response::redirect(base_url('admin/settings'));
    }

    public function applyUpdate(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden(__('common.access_denied'));
        }

        $siteId = $this->requireSiteId();

        try {
            $result = UpdateInstallerService::apply($siteId);
            $label = $result['version'] ? ('v' . $result['version']) : __('settings.update.the_new_version');
            Session::flash('notice', __('settings.update.applied', ['version' => $label, 'backup' => $result['backup']]));
        } catch (\Throwable $e) {
            Session::flash('error', __('settings.update.apply_failed', ['error' => $e->getMessage()]));
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
            Response::forbidden(__('common.access_denied'));
        }
        $this->requireSiteId();

        // Copiar cientos de archivos y migrar puede pasar del límite por defecto.
        @set_time_limit(300);

        $checksum = trim((string) Request::post('checksum', ''));

        try {
            $result = UpdateInstallerService::applyFromUpload($_FILES['package'] ?? [], $checksum);
            $label = $result['version'] ? ('v' . $result['version']) : __('settings.update.uploaded_package');
            Session::flash('notice', __('settings.update.applied_upload', [
                'version' => $label,
                'backup'  => basename($result['backup']),
            ]));
        } catch (\Throwable $e) {
            Session::flash('error', __('settings.update.apply_failed', ['error' => $e->getMessage()]));
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
            Response::forbidden(__('common.access_denied'));
        }
        $this->requireSiteId();
        @set_time_limit(300);

        $name = trim((string) Request::post('backup', ''));

        try {
            $result = UpdateInstallerService::restore($name);
            Session::flash('notice', __('settings.backups.restored', [
                'copia'  => $result['restored'],
                'previa' => $result['safety_backup'],
            ]));
        } catch (\Throwable $e) {
            Session::flash('error', __('settings.backups.restore_failed', ['error' => $e->getMessage()]));
        }

        Response::redirect(base_url('admin/settings'));
    }

    /**
     * Idioma del PANEL para el usuario logueado (ADMIN-I18N T0.4).
     *
     * Vacío = heredar del sitio, que es el defecto y lo que hace que una web
     * francesa dé panel francés sin configurar nada. Es preferencia personal:
     * solo toca la fila del usuario en sesión, nunca la del sitio.
     */
    public function panelLanguage(): void
    {
        CSRF::check();
        $userId = Auth::id();
        if ($userId === null) {
            Response::redirect(base_url('admin/login'));
        }

        $code = strtolower(trim((string) Request::post('panel_language', '')));
        $inherit = $code === '';

        if (!$inherit && !in_array($code, AdminI18n::LOCALES, true)) {
            Session::flash('error', __('settings.panel_language.unavailable'));
            Response::redirect(base_url('admin/settings'));
        }

        Database::execute('UPDATE users SET language = ? WHERE id = ?', [$inherit ? null : $code, $userId]);

        // Sin esto el aviso de abajo saldría en el idioma ANTERIOR: `locale()`
        // ya está resuelto y cacheado para este request.
        AdminI18n::forget();
        if (!$inherit) {
            AdminI18n::setLocale($code);
        }

        Session::flash('notice', $inherit
            ? __('settings.panel_language.saved_inherit')
            : __('settings.panel_language.saved', ['idioma' => LanguageService::label($code)]));

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
            Session::flash('error', __('settings.lang.invalid'));
        } elseif (!empty($result['already'])) {
            Session::flash('notice', __('settings.lang.already_active', ['idioma' => LanguageService::label($code)]));
        } else {
            Session::flash('notice', __('settings.lang.added', [
                'idioma' => LanguageService::label($code),
                'code'   => LanguageService::normalize($code),
            ]));
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
            Session::flash('notice', __('settings.lang.disabled', ['idioma' => LanguageService::label($code)]));
        } elseif (($result['error'] ?? '') === 'primary') {
            Session::flash('error', __('settings.lang.cannot_disable_primary'));
        } elseif (($result['error'] ?? '') === 'has_pages') {
            Session::flash('error', __('settings.lang.has_pages', [
                'idioma' => LanguageService::label($code),
                'n'      => (int) ($result['pages'] ?? 0),
            ]));
        } else {
            Session::flash('error', __('settings.lang.disable_failed'));
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
                // ADMIN-I18N — idioma del panel (preferencia del usuario, no del sitio).
                'panelLanguages' => $this->panelLanguageOptions(),
                'panelLanguage'  => $this->userPanelLanguage(),
                'panelLanguageInherited' => LanguageService::label(AdminI18n::resolveFrom(
                    null,
                    LanguageService::primaryFor((int) $ctx['site']['id']),
                    null
                )),
                'timezones' => $this->timezoneOptions(),
                'articleTemplate' => $ctx['articleTemplate'] ?? ArticleTemplateService::forSite((int) $ctx['site']['id']),
                'articleTemplateOptions' => $this->articleTemplateOptions(),
                'errors'    => $ctx['errors'],
                'notice'    => $ctx['notice'],
                'csrf'      => CSRF::token(),
            ]
        ));
    }

    /**
     * Idiomas que el panel sabe hablar hoy, con su etiqueta.
     *
     * Se deriva de `AdminI18n::LOCALES`, no de una lista propia: añadir
     * `lang/admin/ca.php` y su código a esa constante tiene que bastar para que
     * aparezca aquí, sin tocar Ajustes.
     *
     * @return array<string,string>
     */
    private function panelLanguageOptions(): array
    {
        $out = [];
        foreach (AdminI18n::LOCALES as $code) {
            $out[$code] = LanguageService::label($code);
        }
        return $out;
    }

    /**
     * Zonas horarias con la etiqueta ya traducida, en el orden de la constante.
     *
     * @return array<string,string>
     */
    private function timezoneOptions(): array
    {
        $out = [];
        foreach (self::TIMEZONES as $tz => $key) {
            $out[$tz] = __($key);
        }
        return $out;
    }

    /** Preferencia guardada del usuario, o '' si hereda del sitio. */
    private function userPanelLanguage(): string
    {
        $userId = Auth::id();
        if ($userId === null) {
            return '';
        }
        $row = Database::selectOne('SELECT language FROM users WHERE id = ? LIMIT 1', [$userId]);
        return (string) ($row['language'] ?? '');
    }

    private function validate(array $input, int $siteId): array
    {
        $errors = [];

        if ($input['name'] === '') {
            $errors['name'] = __('settings.error.name_required');
        } elseif (mb_strlen($input['name']) > 255) {
            $errors['name'] = __('settings.error.name_too_long');
        }

        if ($input['url'] === '') {
            $errors['url'] = __('settings.error.url_required');
        } elseif (mb_strlen($input['url']) > 500) {
            $errors['url'] = __('settings.error.url_too_long');
        } elseif (!filter_var($input['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = __('settings.error.url_invalid');
        } else {
            $scheme = strtolower((string) parse_url($input['url'], PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $errors['url'] = __('settings.error.url_scheme');
            }
        }

        if (!array_key_exists($input['language'], self::LANGUAGES)) {
            $errors['language'] = __('settings.error.language_invalid');
        } elseif (LanguageService::isMultilingual($siteId)
            && LanguageService::normalize($input['language']) !== LanguageService::primaryFor($siteId)) {
            // Cambiar el principal en una web multi-idioma reescribiría las URLs
            // de todas las páginas (el idioma sin prefijo pasaría a tenerlo).
            $errors['language'] = __('settings.error.language_locked');
        }

        if (!array_key_exists($input['timezone'], self::TIMEZONES)) {
            $errors['timezone'] = __('settings.error.timezone_invalid');
        }

        return $errors;
    }

    /** @return array<string,string> */
    private function articleTemplateOptions(): array
    {
        // Las claves son valores de BD; la etiqueta se traduce al pintarla.
        $all = array_intersect_key(ArticleTemplateService::options(), array_flip(['classic', 'visual']));
        $out = [];
        foreach (array_keys($all) as $slug) {
            $out[$slug] = __('article_template.' . $slug);
        }
        return $out;
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
            Session::flash('error', __('common.site_not_found'));
            Response::redirect(base_url('admin/logout'));
        }
        return $site;
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('common.no_active_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
