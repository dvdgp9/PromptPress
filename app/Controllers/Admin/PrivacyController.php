<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\LegalPageGenerator;
use App\Services\Compliance\NifValidator;
use App\Services\Compliance\TrackingCatalog;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * E-GDPR G2 — Panel Privacidad.
 *
 * Una sola ruta de entrada `/admin/privacy` que renderiza siempre la misma
 * vista con tabs internas. El tab activo se elige por parámetro de URL
 * (`?tab=...`) o por método específico (POST por tab).
 *
 * Tabs (G2 entrega Resumen + Datos de tu empresa; G3-G5 rellenarán el resto):
 *   - summary   → estado y gaps
 *   - legal     → datos del responsable (form)
 *   - pages     → páginas legales (placeholder en G2, real en G3)
 *   - cookies   → integraciones + banner (placeholder en G2, real en G4)
 *   - forms     → metadatos de forms (placeholder en G2, real en G5)
 */
class PrivacyController
{
    private const VALID_TABS = ['summary', 'legal', 'pages', 'cookies', 'forms'];

    // ----------------------------------------------------------------------
    // GET /admin/privacy[?tab=...]
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();

        // WZ2.2 — Si el wizard nunca se ha completado y no llega tab explícito,
        // llevamos al usuario al asistente. Sigue siendo posible saltarlo con ?tab=...
        $tabParam = (string) Request::get('tab', '');
        if ($tabParam === '' && !ComplianceService::wizardCompleted($siteId)) {
            Response::redirect(base_url('admin/privacy/wizard'));
        }

        $tab = $this->normalizeTab($tabParam !== '' ? $tabParam : 'summary');
        $this->render($siteId, $tab, [], []);
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/legal — guardar datos del responsable
    // ----------------------------------------------------------------------
    /**
     * Recoge los campos de "Datos de tu empresa" desde $_POST.
     * Compartido entre `saveLegal` (tabs) y `PrivacyWizardController::saveStep1`.
     */
    public static function collectLegalInput(): array
    {
        $input = [
            'legal_name'       => trim((string) Request::post('legal_name', '')),
            'brand_name'       => trim((string) Request::post('brand_name', '')),
            'tax_id'           => trim((string) Request::post('tax_id', '')),
            'registry_details' => trim((string) Request::post('registry_details', '')),
            'address'          => trim((string) Request::post('address', '')),
            'email'            => trim((string) Request::post('email', '')),
            'phone'            => trim((string) Request::post('phone', '')),
            'country'          => trim((string) Request::post('country', 'ES')) ?: 'ES',
            'dpo'              => null,
        ];
        if (Request::post('has_dpo', '') === '1') {
            $input['dpo'] = [
                'name'  => trim((string) Request::post('dpo_name', '')),
                'email' => trim((string) Request::post('dpo_email', '')),
            ];
        }
        return $input;
    }

    /**
     * Valida + persiste los datos legales. Devuelve errores (vacío si OK).
     */
    public static function applyLegal(int $siteId, array $input): array
    {
        $errors = self::validateLegalStatic($input);
        if (!empty($errors)) return $errors;
        ComplianceService::patch($siteId, ['controller' => $input]);
        return [];
    }

    public function saveLegal(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $input = self::collectLegalInput();
        $errors = self::validateLegalStatic($input);

        if (!empty($errors)) {
            $this->render($siteId, 'legal', $input, $errors);
            return;
        }

        ComplianceService::patch($siteId, ['controller' => $input]);
        Session::flash('success', __('priv.ok.legal_saved'));
        Response::redirect(base_url('admin/privacy?tab=legal'));
    }

    /**
     * Validaciones del form de datos legales. Estática para que el wizard
     * pueda invocarla sin instanciar el controller.
     */
    public static function validateLegalStatic(array $input): array
    {
        $errors = [];

        if ($input['legal_name'] === '') {
            $errors['legal_name'] = __('priv.err.legal_name');
        } elseif (mb_strlen($input['legal_name']) > 255) {
            $errors['legal_name'] = __('priv.err.max255');
        }

        if ($input['email'] === '') {
            $errors['email'] = __('priv.err.email_required');
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = __('priv.err.email_invalid');
        }

        if ($input['address'] === '') {
            $errors['address'] = __('priv.err.address');
        }

        // Validación NIF/CIF/NIE solo si país = ES y se ha rellenado.
        if ($input['country'] === 'ES' && $input['tax_id'] !== '') {
            if (!NifValidator::isValid($input['tax_id'])) {
                $errors['tax_id'] = __('priv.err.tax_id');
            }
        }

        // DPO email si se marca "tengo DPO".
        if (is_array($input['dpo']) && !empty($input['dpo']['email'])) {
            if (!filter_var($input['dpo']['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['dpo_email'] = __('priv.err.dpo_email');
            }
        }

        return $errors;
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/pages/generate — generar/regenerar página legal (G3)
    // ----------------------------------------------------------------------
    public function generateLegalPage(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        // WZ-UX — el front genera página a página vía fetch para poder pintar
        // progreso real. Misma ruta: si el body es JSON respondemos JSON, si no
        // seguimos con el flujo clásico de redirect + flash (fallback sin JS).
        $isJson = Request::isJson();
        $type = $isJson
            ? (string) (Request::json()['type'] ?? '')
            : (string) Request::post('type', '');

        if (!isset(LegalPageGenerator::typesFor($siteId)[$type])) {
            if ($isJson) {
                Response::json(['ok' => false, 'error' => __('priv.err.bad_type')], 422);
            }
            Session::flash('error', __('priv.err.bad_type'));
            Response::redirect(base_url('admin/privacy?tab=pages'));
        }

        $manifest = ComplianceService::manifest($siteId);
        $controller = (array) $manifest['controller'];
        $missingCore = array_filter(['legal_name', 'address', 'email'],
            fn ($k) => trim((string) ($controller[$k] ?? '')) === '');
        if (!empty($missingCore)) {
            $msg = __('priv.err.missing_core');
            if ($isJson) {
                Response::json(['ok' => false, 'error' => $msg, 'redirect_url' => base_url('admin/privacy?tab=legal')], 422);
            }
            Session::flash('error', $msg);
            Response::redirect(base_url('admin/privacy?tab=legal'));
        }

        try {
            $result = LegalPageGenerator::generate($siteId, $type);
        } catch (AIException $e) {
            if ($isJson) {
                Response::json(['ok' => false, 'error' => __('priv.err.ai', ['detalle' => $e->getMessage()])], 502);
            }
            Session::flash('error', __('priv.err.ai', ['detalle' => $e->getMessage()]));
            Response::redirect(base_url('admin/privacy?tab=pages'));
        } catch (\Throwable $e) {
            if ($isJson) {
                Response::json(['ok' => false, 'error' => __('priv.err.generate', ['detalle' => $e->getMessage()])], 500);
            }
            Session::flash('error', __('priv.err.generate', ['detalle' => $e->getMessage()]));
            Response::redirect(base_url('admin/privacy?tab=pages'));
        }

        if ($isJson) {
            Response::json([
                'ok'      => true,
                'type'    => $type,
                'title'   => $result['title'],
                'todos'   => (int) $result['todos'],
                'page_id' => (int) $result['page_id'],
            ]);
        }

        $todosNote = $result['todos'] > 0
            ? ' ' . __('priv.todos_note', ['n' => (string) (int) $result['todos']])
            : '';
        Session::flash('success', __('priv.ok.page_generated', ['titulo' => (string) $result['title']]) . $todosNote);
        Response::redirect(base_url('admin/privacy?tab=pages'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/pages/generate-all — generar las 3 páginas en lote (WZ1)
    // ----------------------------------------------------------------------
    public function generateAll(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $result = LegalPageGenerator::generateAllLegalPages($siteId);

        if (!$result['ok'] && isset($result['error']) && !isset($result['results'])) {
            // Precondición fallida: faltan datos del responsable.
            Session::flash('error', __('priv.err.missing_core_all'));
            Response::redirect(base_url('admin/privacy?tab=legal'));
        }

        $generated = (int) ($result['generated'] ?? 0);
        $failed    = (int) ($result['failed'] ?? 0);
        $results   = (array) ($result['results'] ?? []);

        $totalTodos = 0;
        $failedTitles = [];
        foreach ($results as $type => $r) {
            if (!empty($r['ok'])) {
                $totalTodos += (int) ($r['todos'] ?? 0);
            } else {
                $label = LegalPageGenerator::TYPES[$type]['label'] ?? $type;
                $failedTitles[] = $label . ' (' . ($r['error'] ?? __('priv.err.unknown')) . ')';
            }
        }

        if ($failed > 0) {
            $msg = __('priv.err.partial', [
                'hechas' => (string) $generated,
                'total'  => (string) ($generated + $failed),
                'fallos' => implode(' · ', $failedTitles),
            ]);
            Session::flash('error', $msg);
        } else {
            $todosNote = $totalTodos > 0
                ? ' ' . __('priv.todos_note_short', ['n' => (string) $totalTodos])
                : '';
            Session::flash('success', __('priv.ok.all_generated') . $todosNote);
        }

        Response::redirect(base_url('admin/privacy?tab=pages'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/cookies — guardar toggles de integraciones de tracking
    // ----------------------------------------------------------------------
    /**
     * Procesa el form de cookies/tracking desde $_POST y persiste si es válido.
     * Devuelve array de errores (vacío si OK). Compartido con el wizard.
     */
    public static function applyCookies(int $siteId): array
    {
        $catalog = TrackingCatalog::services();
        $services = [];
        foreach ($catalog as $key => $def) {
            $enabled = Request::post('enabled_' . $key, '') === '1';
            $config = [];
            foreach ((array) $def['config_fields'] as $field => $fieldDef) {
                $config[$field] = trim((string) Request::post('config_' . $key . '_' . $field, ''));
            }
            $services[] = [
                'key'     => $key,
                'enabled' => $enabled,
                'config'  => $config,
            ];
        }

        $errors = [];
        foreach ($services as $s) {
            if (!$s['enabled']) continue;
            $def = $catalog[$s['key']] ?? null;
            if (!$def) continue;
            foreach ((array) $def['config_fields'] as $field => $fieldDef) {
                $value = (string) ($s['config'][$field] ?? '');
                if ($value === '') {
                    $errors[$s['key']] = 'Para activar ' . $def['name'] . ' necesitas rellenar ' . $fieldDef['label'] . '.';
                    break;
                }
                if (!empty($fieldDef['pattern']) && !preg_match('/' . str_replace('/', '\\/', $fieldDef['pattern']) . '/', $value)) {
                    $errors[$s['key']] = $fieldDef['label'] . ' tiene un formato inesperado. Revisa el valor.';
                    break;
                }
            }
        }
        if (!empty($errors)) return $errors;

        ComplianceService::patch($siteId, ['tracking' => ['services' => $services]]);
        \App\Services\CacheService::flush($siteId);
        return [];
    }

    public function saveCookies(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $errors = self::applyCookies($siteId);
        if (!empty($errors)) {
            Session::flash('error', reset($errors));
            Response::redirect(base_url('admin/privacy?tab=cookies'));
        }

        Session::flash('success', __('priv.ok.cookies_saved'));
        Response::redirect(base_url('admin/privacy?tab=cookies'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/privacy/banner — guardar textos editables del banner
    // ----------------------------------------------------------------------
    public function saveBanner(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $banner = [
            'title'           => trim((string) Request::post('title', '')),
            'description'     => trim((string) Request::post('description', '')),
            'accept_label'    => trim((string) Request::post('accept_label', '')),
            'reject_label'    => trim((string) Request::post('reject_label', '')),
            'configure_label' => trim((string) Request::post('configure_label', '')),
        ];
        $banner = array_filter($banner, fn ($v) => $v !== '');

        // Incrementar versión cada vez que cambian los textos del banner
        // (invalida consent previos si es necesario en el futuro).
        $current = ComplianceService::manifest($siteId);
        $version = (int) ($current['banner']['version'] ?? 1) + 1;
        $banner['version'] = $version;

        ComplianceService::patch($siteId, ['banner' => $banner]);
        \App\Services\CacheService::flush($siteId);

        Session::flash('success', __('priv.ok.banner_saved'));
        Response::redirect(base_url('admin/privacy?tab=cookies'));
    }

    private function normalizeTab(string $tab): string
    {
        return in_array($tab, self::VALID_TABS, true) ? $tab : 'summary';
    }

    /**
     * Renderiza la vista con el tab activo. Pasa al view el manifest, status,
     * gaps, datos de los inputs (para repintar tras error) y errores.
     */
    private function render(int $siteId, string $tab, array $legalInput, array $legalErrors): void
    {
        $manifest = ComplianceService::manifest($siteId);
        $status   = ComplianceService::status($siteId);

        // Si no hay input (GET o save OK), pintar los valores guardados.
        if (empty($legalInput)) {
            $legalInput = (array) $manifest['controller'];
        }

        // Para el tab "Páginas legales": estado real de las 3 páginas legales.
        $legalPagesState = self::loadLegalPagesState($siteId);

        // Para el tab "Formularios": lista de form sections con metadatos legales.
        $formsList = self::loadFormsList($siteId);

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'tab'             => $tab,
            'manifest'        => $manifest,
            'status'          => $status,
            'legalInput'      => $legalInput,
            'legalErrors'     => $legalErrors,
            'legalPagesState' => $legalPagesState,
            'legalTypes'      => LegalPageGenerator::typesFor($siteId),
            'trackingCatalog' => TrackingCatalog::servicesForView(),
            'trackingCategories' => TrackingCatalog::categoriesForView(),
            'formsList'       => $formsList,
            'csrf'            => CSRF::token(),
        ]);
        View::send('admin/privacy/index', $data);
    }

    /**
     * Para cada tipo de página legal, comprueba si existe ya en `pages` y
     * devuelve sus datos (id, title, updated_at) o null.
     *
     * @return array<string, array{id:int,title:string,slug:string,updated_at:string}|null>
     */
    public static function loadLegalPagesState(int $siteId): array
    {
        $out = [];
        foreach (LegalPageGenerator::typesFor($siteId) as $key => $info) {
            // LEGAL-SLUG — se busca por el id del manifest y por cualquiera de
            // los slugs conocidos del tipo: buscar solo por el slug castellano
            // haría "desaparecer" del panel las páginas de una web en otro
            // idioma, y el botón de generar crearía un duplicado.
            $row = LegalPageGenerator::findExistingPage($siteId, $key);
            $out[$key] = $row ? [
                'id'         => (int) $row['id'],
                'title'      => (string) $row['title'],
                'slug'       => (string) $row['slug'],
                'updated_at' => (string) $row['updated_at'],
                'status'     => (string) $row['status'],
            ] : null;
        }
        return $out;
    }

    /**
     * E-GDPR G5 — Lista todos los formularios del sitio con sus metadatos legales.
     * @return array<int,array{section_id:int,page_id:int,page_title:string,page_slug:string,heading:string,lawful_basis:string,retention_period:string,marketing_opt_in:bool,fields_count:int}>
     */
    public static function loadFormsList(int $siteId): array
    {
        try {
            $rows = \Core\Database::select(
                "SELECT ps.id AS section_id, ps.content, p.id AS page_id, p.title AS page_title, p.slug AS page_slug, p.status AS page_status
                 FROM page_sections ps
                 INNER JOIN pages p ON p.id = ps.page_id
                 WHERE p.site_id = ? AND ps.section_type = 'form'
                 ORDER BY p.title, ps.sort_order",
                [$siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $content = json_decode((string) $r['content'], true);
            if (!is_array($content)) $content = [];
            $out[] = [
                'section_id'       => (int) $r['section_id'],
                'page_id'          => (int) $r['page_id'],
                'page_title'       => (string) $r['page_title'],
                'page_slug'        => (string) $r['page_slug'],
                'page_status'      => (string) ($r['page_status'] ?? 'draft'),
                'heading'          => trim((string) ($content['heading'] ?? '')),
                'lawful_basis'     => (string) ($content['lawful_basis'] ?? 'legitimate_interest'),
                // i18n-ignore: valor por defecto del CONTENIDO del formulario, no
                // texto de panel: acaba en la política de privacidad que lee el visitante.
                'retention_period' => (string) ($content['retention_period'] ?? '12 meses tras la última comunicación'),
                'marketing_opt_in' => ((string) ($content['marketing_opt_in'] ?? '0')) === '1',
                'fields_count'     => is_array($content['fields'] ?? null) ? count($content['fields']) : 0,
            ];
        }
        return $out;
    }

    public static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('bk.err.no_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
