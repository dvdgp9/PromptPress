<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\ArticleTemplateService;
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
    public const LANGUAGES = [
        'es' => 'Español',
        'en' => 'English',
        'ca' => 'Català',
        'gl' => 'Galego',
        'eu' => 'Euskara',
        'fr' => 'Français',
        'pt' => 'Português',
    ];

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
        $errors = $this->validate($input);

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

        CacheService::flush($siteId);
        Session::flash('notice', 'Ajustes generales guardados. Caché pública regenerada.');
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

    private function render(array $ctx): void
    {
        View::send('admin/settings/index', array_merge(
            DashboardController::getCommonData(),
            [
                'site'      => $ctx['site'],
                'resetCounts' => $ctx['resetCounts'] ?? [],
                'updateStatus' => $ctx['updateStatus'] ?? null,
                'languages' => self::LANGUAGES,
                'timezones' => self::TIMEZONES,
                'articleTemplate' => $ctx['articleTemplate'] ?? ArticleTemplateService::forSite((int) $ctx['site']['id']),
                'articleTemplateOptions' => $this->articleTemplateOptions(),
                'errors'    => $ctx['errors'],
                'notice'    => $ctx['notice'],
                'csrf'      => CSRF::token(),
            ]
        ));
    }

    private function validate(array $input): array
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
