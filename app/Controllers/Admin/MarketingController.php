<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\TrackingCatalog;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * MKT — Panel de Marketing.
 *
 * Punto único para que el dueño del sitio conecte sus herramientas de medición
 * y publicidad. Dos bloques:
 *   1. Integraciones de catálogo (GA4, Meta Pixel, GTM, Google Ads, TikTok,
 *      LinkedIn, reCAPTCHA): toggle + ID, sin tocar código.
 *   2. Código personalizado: snippets arbitrarios con categoría de
 *      consentimiento y ubicación (head / fin de body).
 *
 * Todo persiste en `manifest['tracking']` (el mismo store que usa el banner de
 * cookies), así que el consentimiento RGPD sigue gobernando la carga real de
 * cada script. Por eso comparte la lógica de validación con `PrivacyController`.
 */
class MarketingController
{
    // ----------------------------------------------------------------------
    // GET /admin/marketing
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $this->render($siteId, []);
    }

    // ----------------------------------------------------------------------
    // POST /admin/marketing/integrations — toggles del catálogo de tracking
    // ----------------------------------------------------------------------
    public function saveIntegrations(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        // Reusa la misma validación + persistencia que el wizard/privacidad.
        $errors = PrivacyController::applyCookies($siteId);
        if (!empty($errors)) {
            Session::flash('error', reset($errors));
            Response::redirect(base_url('admin/marketing'));
        }

        Session::flash('success', 'Integraciones de marketing actualizadas.');
        Response::redirect(base_url('admin/marketing'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/marketing/custom — alta o edición de un snippet personalizado
    // ----------------------------------------------------------------------
    public function saveCustom(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $id        = trim((string) Request::post('custom_id', ''));
        $label     = trim((string) Request::post('label', ''));
        $category  = trim((string) Request::post('category', ''));
        $placement = trim((string) Request::post('placement', 'body_end'));
        $code      = (string) Request::post('code', '');
        $enabled   = Request::post('enabled', '') === '1';

        $errors = [];
        if ($label === '') {
            $errors[] = 'Ponle un nombre al snippet para reconocerlo (p. ej. "Pixel de Pinterest").';
        }
        if (!isset(TrackingCatalog::CATEGORIES[$category])) {
            $errors[] = 'Elige una categoría de consentimiento válida.';
        }
        if (!isset(TrackingCatalog::PLACEMENTS[$placement])) {
            $placement = 'body_end';
        }
        if (trim($code) === '') {
            $errors[] = 'Pega el código del snippet.';
        }

        if (!empty($errors)) {
            Session::flash('error', reset($errors));
            Response::redirect(base_url('admin/marketing'));
        }

        $manifest = ComplianceService::manifest($siteId);
        $custom = (array) ($manifest['tracking']['custom'] ?? []);

        $entry = [
            'id'        => $id !== '' ? $id : self::newId(),
            'label'     => mb_substr($label, 0, 120),
            'category'  => $category,
            'placement' => $placement,
            'code'      => $code,
            'enabled'   => $enabled,
        ];

        // Edición si el id ya existe; alta en caso contrario.
        $found = false;
        foreach ($custom as $i => $c) {
            if ((string) ($c['id'] ?? '') === $entry['id']) {
                $custom[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $custom[] = $entry;
        }

        // Reindexar para mantener una lista numérica limpia (deepMerge la
        // reemplaza entera, así que el borrado de huecos es seguro).
        $custom = array_values($custom);

        ComplianceService::patch($siteId, ['tracking' => ['custom' => $custom]]);
        CacheService::flush($siteId);

        Session::flash('success', $found ? 'Snippet actualizado.' : 'Snippet añadido.');
        Response::redirect(base_url('admin/marketing'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/marketing/custom/delete — eliminar un snippet
    // ----------------------------------------------------------------------
    public function deleteCustom(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $id = trim((string) Request::post('custom_id', ''));
        if ($id === '') {
            Response::redirect(base_url('admin/marketing'));
        }

        $manifest = ComplianceService::manifest($siteId);
        $custom = (array) ($manifest['tracking']['custom'] ?? []);
        $custom = array_values(array_filter($custom, fn ($c) => (string) ($c['id'] ?? '') !== $id));

        ComplianceService::patch($siteId, ['tracking' => ['custom' => $custom]]);
        CacheService::flush($siteId);

        Session::flash('success', 'Snippet eliminado.');
        Response::redirect(base_url('admin/marketing'));
    }

    // ----------------------------------------------------------------------
    // Render
    // ----------------------------------------------------------------------
    private function render(int $siteId, array $extra): void
    {
        $manifest = ComplianceService::manifest($siteId);

        $services = (array) ($manifest['tracking']['services'] ?? []);
        $serviceState = [];
        foreach ($services as $s) {
            if (isset($s['key'])) $serviceState[$s['key']] = $s;
        }

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'manifest'           => $manifest,
            'trackingCatalog'    => TrackingCatalog::services(),
            'trackingCategories' => TrackingCatalog::CATEGORIES,
            'serviceState'       => $serviceState,
            'customSnippets'     => (array) ($manifest['tracking']['custom'] ?? []),
            'customCategories'   => TrackingCatalog::customCategoryChoices(),
            'customPlacements'   => TrackingCatalog::PLACEMENTS,
            'needsBanner'        => TrackingCatalog::needsBanner($manifest),
            'csrf'               => CSRF::token(),
        ], $extra);

        View::send('admin/marketing/index', $data);
    }

    private static function newId(): string
    {
        return 'c' . bin2hex(random_bytes(5));
    }

    public static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
