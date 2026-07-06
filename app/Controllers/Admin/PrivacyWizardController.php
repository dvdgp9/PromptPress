<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\LegalPageGenerator;
use App\Services\Compliance\TrackingCatalog;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * E-GDPR-WZ — Asistente guiado de privacidad.
 *
 * Recopila en 4 pasos los datos del manifest y termina generando las 3 páginas
 * legales en lote. El paso actual se deriva del estado del manifest (no se
 * persiste current_step), así que el usuario puede entrar y salir sin perder
 * el sitio donde lo dejó.
 *
 * Pasos:
 *   1. Datos de tu empresa     (manifest.controller)
 *   2. Cookies y tracking      (manifest.tracking.services)
 *   3. Formularios             (read-only — sólo "Siguiente")
 *   4. Generar paquete legal   (genera las 3 páginas + marca completed_at)
 */
class PrivacyWizardController
{
    private const TOTAL_STEPS = 3;

    // ----------------------------------------------------------------------
    // GET /admin/privacy/wizard[?step=N]
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = PrivacyController::requireSiteId();

        $requested = (int) Request::get('step', 0);
        $step = $requested >= 1 && $requested <= self::TOTAL_STEPS
            ? $requested
            : ComplianceService::wizardCurrentStep($siteId);

        $this->render($siteId, $step, [], []);
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/wizard/step1 — guardar datos del responsable
    // ----------------------------------------------------------------------
    public function saveStep1(): void
    {
        CSRF::check();
        $siteId = PrivacyController::requireSiteId();

        $input = PrivacyController::collectLegalInput();
        $errors = PrivacyController::applyLegal($siteId, $input);

        if (!empty($errors)) {
            $this->render($siteId, 1, $input, $errors);
            return;
        }

        Response::redirect(base_url('admin/privacy/wizard?step=2'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/wizard/step2 — guardar cookies/tracking
    // ----------------------------------------------------------------------
    public function saveStep2(): void
    {
        CSRF::check();
        $siteId = PrivacyController::requireSiteId();

        $errors = PrivacyController::applyCookies($siteId);
        if (!empty($errors)) {
            Session::flash('error', reset($errors));
            Response::redirect(base_url('admin/privacy/wizard?step=2'));
        }

        // Marca este paso como visto (incluso si no se activó ningún servicio,
        // es una decisión válida del usuario).
        ComplianceService::patch($siteId, ['wizard' => ['steps_seen' => ['cookies' => true]]]);

        Response::redirect(base_url('admin/privacy/wizard?step=3'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/wizard/finish — generar las 3 páginas legales — generar las 3 páginas legales
    // ----------------------------------------------------------------------
    public function finish(): void
    {
        CSRF::check();
        $siteId = PrivacyController::requireSiteId();

        $result = LegalPageGenerator::generateAllLegalPages($siteId);

        // Precondición fallida: faltan datos del responsable → volver al paso 1.
        if (!$result['ok'] && !isset($result['results'])) {
            Session::flash('error', 'Antes de generar tus páginas legales completa los datos de tu empresa.');
            Response::redirect(base_url('admin/privacy/wizard?step=1'));
        }

        $generated = (int) ($result['generated'] ?? 0);
        $failed    = (int) ($result['failed'] ?? 0);

        if ($failed > 0) {
            $failedTitles = [];
            foreach ((array) $result['results'] as $type => $r) {
                if (empty($r['ok'])) {
                    $label = LegalPageGenerator::TYPES[$type]['label'] ?? $type;
                    $failedTitles[] = $label;
                }
            }
            $msg = 'Se generaron ' . $generated . ' de ' . ($generated + $failed)
                 . ' páginas. Fallaron: ' . implode(' · ', $failedTitles)
                 . '. Pulsa de nuevo para reintentar.';
            Session::flash('error', $msg);
            Response::redirect(base_url('admin/privacy/wizard?step=3'));
        }

        // Éxito completo: marcar wizard como completado.
        ComplianceService::patch($siteId, ['wizard' => ['completed_at' => date('Y-m-d H:i:s')]]);

        Response::redirect(base_url('admin/privacy/wizard?step=3&done=1'));
    }

    // ======================================================================
    // Render
    // ======================================================================

    private function render(int $siteId, int $step, array $legalInput, array $legalErrors): void
    {
        $manifest = ComplianceService::manifest($siteId);

        if (empty($legalInput)) {
            $legalInput = (array) ($manifest['controller'] ?? []);
        }

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'wizardStep'         => $step,
            'wizardTotalSteps'   => self::TOTAL_STEPS,
            'wizardCurrentStep'  => ComplianceService::wizardCurrentStep($siteId),
            'wizardDone'         => Request::get('done', '') === '1',
            'manifest'           => $manifest,
            'legalInput'         => $legalInput,
            'legalErrors'        => $legalErrors,
            'legalPagesState'    => PrivacyController::loadLegalPagesState($siteId),
            'legalTypes'         => LegalPageGenerator::typesFor($siteId),
            'trackingCatalog'    => TrackingCatalog::services(),
            'trackingCategories' => TrackingCatalog::CATEGORIES,
            'formsList'          => PrivacyController::loadFormsList($siteId),
            'csrf'               => CSRF::token(),
        ]);

        View::send('admin/privacy/wizard/index', $data);
    }
}
