<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\FormI18n;
use App\Services\FormDefinition;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\FormTranslator;
use App\Services\FormPlacementStore;
use App\Services\LanguageService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * FormsController (FORMS F2/F3) — apartado "Formularios".
 *
 * Crea/edita/borra formularios como entidades propias (vía FormStore). Cada
 * formulario define sus campos, su autorrespuesta y a dónde llega el aviso.
 */
class FormsController
{
    private const FIELD_TYPES = FormDefinition::TYPES;
    private const FILE_PRESETS = ['documents', 'images', 'cv', 'custom'];

    public function index(): void
    {
        $siteId = $this->requireSiteId();
        View::send('admin/forms/list', array_merge(
            DashboardController::getCommonData(),
            [
                'forms'     => FormStore::all($siteId),
                'usage'     => $this->usageMap($siteId),
                'templates' => FormTemplates::catalogForView(),
                'notice'    => Session::flash('notice'),
                'error'     => Session::flash('error'),
                'csrf'      => CSRF::token(),
            ]
        ));
    }

    public function create(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $template = (string) Request::post('template', 'contact');
        $id = FormTemplates::exists($template)
            ? FormStore::createFromTemplate($siteId, $template)
            : FormStore::create($siteId);
        Session::flash('notice', __('forms.created', ['plantilla' => FormTemplates::label($template)]));
        Response::redirect(base_url('admin/formularios/' . $id));
    }

    /** Generate a validated draft; only the list's create action persists a new form. */
    public function design(): void
    {
        $this->generateWithAI();
    }

    public function createWithAI(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        try {
            $raw = Request::post('draft_json', '');
            if (!is_string($raw) || strlen($raw) > 100000) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            $draft = FormDefinition::fromAI($decoded);
            $content = array_replace(FormTemplates::content('contact', LanguageService::primaryFor($siteId)), $draft);
            $content['autoresponder_enabled'] = '0';
            $content['form_type'] = 'custom';
            $id = FormStore::create($siteId, $content);
            Session::flash('notice', __('forms.ai.created'));
            Response::json(['ok'=>true, 'edit_url'=>base_url('admin/formularios/' . $id)]);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 422);
        }
    }

    private function generateWithAI(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        try {
            $lang = LanguageService::primaryFor($siteId);
            $formId = (int) Request::post('form_id', 0);
            if ($formId > 0) {
                $existing = FormStore::find($siteId, $formId);
                if ($existing === null) Response::json(['ok'=>false, 'error'=>__('forms.error.not_found')], 404);
                $lang = FormI18n::baseLanguage($existing);
            }
            $description = Request::post('description', '');
            if (!is_string($description) || mb_strlen($description) > 12000) throw new AIException(__('forms.ai.description_error'));
            $description = trim($description);
            $image = $this->referenceImage();
            if (mb_strlen($description) < 5 && $image === null) throw new AIException(__('forms.ai.description_error'));
            $current = Request::post('current_form', '');
            if (!is_string($current) || strlen($current) > 60000) throw new AIException(__('forms.ai.invalid_output'));
            $input = [
                'description' => $description ?: 'Reproduce las preguntas y opciones del formulario de la imagen adjunta.',
                'extra_context' => 'Idioma del formulario: ' . LanguageService::promptLabel($lang)
                    . ($current !== '' ? "\nFormulario actual (datos de referencia para aplicar los cambios pedidos):\n" . $current : ''),
            ];
            if ($image !== null) $input['_images'] = [$image];
            $result = AIActionRunner::run(Actions::DESIGN_FORM, $input, $siteId);
            $draft = FormDefinition::fromAI($result['data']);
            Response::json(['ok'=>true, 'data'=>$draft]);
        } catch (AIException | \InvalidArgumentException $e) {
            Response::json(['ok'=>false, 'error'=>$e->getMessage()], 422);
        }
    }

    /** A single temporary upload, verified by content; never saved to the media library. */
    private function referenceImage(): ?array
    {
        $file = Request::file('reference');
        if ($file === null || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || (int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new AIException(__('forms.ai.image_error'));
        }
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!is_array($info) || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] * $info[1] > 20000000) throw new AIException(__('forms.ai.image_error'));
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || $raw === '') throw new AIException(__('forms.ai.image_error'));
        return ['mime'=>$mime, 'data'=>base64_encode($raw)];
    }

    public function edit(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $form = FormStore::find($siteId, $id);
        if ($form === null) {
            Session::flash('error', __('forms.error.not_found'));
            Response::redirect(base_url('admin/formularios'));
        }
        $this->renderEditor($siteId, $id, $form, []);
    }

    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $existing = FormStore::find($siteId, $id);
        if ($existing === null) {
            Session::flash('error', __('forms.error.not_found'));
            Response::redirect(base_url('admin/formularios'));
        }

        $content = $this->collectInput();
        // El tipo no se edita en el form; se preserva el de la plantilla original
        // (lo necesitan dedup y los eventos de conversión).
        $content['form_type'] = (string) ($existing['form_type'] ?? 'contact');
        // FORMS-LANG T6 — el editor solo edita el idioma base: idioma y
        // traducciones no viajan en el POST y se perderían al guardar.
        $content['language'] = FormI18n::baseLanguage($existing);
        if (is_array($existing['i18n'] ?? null) && $existing['i18n'] !== []) {
            $content['i18n'] = $existing['i18n'];
        }
        $errors = $this->validate($content);
        if ($errors !== []) {
            $this->renderEditor($siteId, $id, array_merge($content, ['id' => $id]), $errors);
            return;
        }

        FormStore::update($siteId, $id, $content);
        Session::flash('notice', __('forms.flash.saved'));
        Response::redirect(base_url('admin/formularios/' . $id));
    }

    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        if (FormStore::delete($siteId, $id)) {
            Session::flash('notice', __('forms.flash.deleted'));
        } else {
            Session::flash('error', __('forms.error.delete_failed'));
        }
        Response::redirect(base_url('admin/formularios'));
    }

    /**
     * FORMS-LANG T5/T8 — POST /admin/formularios/{id}/translate
     *
     * Dos comportamientos, según el idioma pedido:
     *  - el idioma PRINCIPAL del sitio y el formulario está en otro → se
     *    reescribe la base (el caso de la web francesa con formularios
     *    castellanos heredados);
     *  - cualquier otro idioma activo → se guarda como traducción, sin tocar
     *    la base ni la bandeja de entrada.
     */
    public function translate(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $form = FormStore::find($siteId, $id);
        if ($form === null) {
            Session::flash('error', __('forms.error.not_found'));
            Response::redirect(base_url('admin/formularios'));
        }

        $target = LanguageService::normalize((string) Request::post('lang', ''));
        if (!in_array($target, LanguageService::activeFor($siteId), true)) {
            Session::flash('error', __('forms.error.lang_inactive'));
            Response::redirect(base_url('admin/formularios/' . $id));
        }

        $base = FormI18n::baseLanguage($form);
        $primary = LanguageService::primaryFor($siteId);
        $rewriteBase = $target === $primary && $target !== $base;

        if ($target === $base) {
            Session::flash('notice', __('forms.already_in', ['idioma' => LanguageService::label($target)]));
            Response::redirect(base_url('admin/formularios/' . $id));
        }

        unset($form['id']);
        $result = $rewriteBase
            ? FormTranslator::toBase($siteId, $form, $target)
            : FormTranslator::toLanguage($siteId, $form, $target);

        if (!$result['ok']) {
            Session::flash('error', (string) ($result['message'] ?? __('forms.error.translate_failed')));
            Response::redirect(base_url('admin/formularios/' . $id));
        }

        FormStore::update($siteId, $id, $result['content']);
        Session::flash('notice', $rewriteBase
            ? __('forms.translated_base', ['idioma' => LanguageService::label($target)])
            : __('forms.translated_added', ['idioma' => LanguageService::label($target)]));
        Response::redirect(base_url('admin/formularios/' . $id));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** Recoge y normaliza el formulario enviado. @return array<string,mixed> */
    private function collectInput(): array
    {
        $fieldsRaw = Request::post('fields', []);
        $fields = [];
        if (is_array($fieldsRaw)) {
            foreach ($fieldsRaw as $f) {
                if (!is_array($f)) continue;
                $label = trim((string) ($f['label'] ?? ''));
                if ($label === '') continue; // fila vacía → se descarta
                $name = trim((string) ($f['name'] ?? ''));
                if ($name === '') $name = $label;
                $name = strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name));
                $type = (string) ($f['field_type'] ?? 'text');
                if (!in_array($type, self::FIELD_TYPES, true)) $type = 'text';
                $field = [
                    'label'       => mb_substr($label, 0, 120),
                    'name'        => mb_substr($name, 0, 60),
                    'field_type'  => $type,
                    'required'    => ((string) ($f['required'] ?? '0')) === '1' ? '1' : '0',
                    'placeholder' => mb_substr(trim((string) ($f['placeholder'] ?? '')), 0, 160),
                ];
                if (in_array($type, FormDefinition::CHOICE_TYPES, true)) {
                    $field['options'] = $this->normalizeOptions($f['options'] ?? '');
                }
                if ($type === 'file') {
                    $preset = (string) ($f['file_accept'] ?? 'documents');
                    $field['file_accept'] = in_array($preset, self::FILE_PRESETS, true) ? $preset : 'documents';
                    $field['file_max_mb'] = $this->normalizeFileMaxMb($f['file_max_mb'] ?? 5);
                    $field['file_custom_ext'] = $field['file_accept'] === 'custom'
                        ? implode(',', $this->normalizeExtensions($f['file_custom_ext'] ?? ''))
                        : '';
                }
                $fields[] = $field;
            }
        }

        return [
            'heading'         => mb_substr(trim((string) Request::post('heading', '')), 0, 160),
            'description'     => mb_substr(trim((string) Request::post('description', '')), 0, 500),
            'submit_text'     => mb_substr(trim((string) Request::post('submit_text', 'Enviar')) ?: 'Enviar', 0, 60),
            'success_message' => mb_substr(trim((string) Request::post('success_message', '')), 0, 240),
            'fields'          => $fields,
            'lawful_basis'    => (string) Request::post('lawful_basis', 'legitimate_interest'),
            'retention_period'=> mb_substr(trim((string) Request::post('retention_period', '')), 0, 160),
            'marketing_opt_in'=> ((string) Request::post('marketing_opt_in', '0')) === '1' ? '1' : '0',
            'autoresponder_enabled' => ((string) Request::post('autoresponder_enabled', '0')) === '1' ? '1' : '0',
            'autoresponder_subject' => mb_substr(trim((string) Request::post('autoresponder_subject', '')), 0, 200),
            'autoresponder_body'    => mb_substr((string) Request::post('autoresponder_body', ''), 0, 4000),
            'notify_email'    => mb_substr(trim((string) Request::post('notify_email', '')), 0, 255),
        ];
    }

    /** @param array<string,mixed> $c @return array<int,string> */
    private function validate(array $c): array
    {
        $errors = [];
        if ((string) $c['heading'] === '') {
            $errors[] = __('forms.error.title_required');
        }
        if (!is_array($c['fields']) || count($c['fields']) === 0) {
            $errors[] = __('forms.error.need_field');
        }
        $names = array_column((array) $c['fields'], 'name');
        $labels = array_column((array) $c['fields'], 'label');
        if (count($labels) !== count(array_unique($labels)) || count($names) !== count(array_unique($names)) || array_intersect($names, ['company_url', '_csrf', '_return', '_lang', '_pp_ts', '_pp_pow', '_marketing_consent']) !== []) {
            $errors[] = __('forms.ai.duplicate_names');
        }
        if (count((array) $c['fields']) > FormDefinition::MAX_FIELDS) $errors[] = __('forms.ai.too_many_fields');
        foreach ((array) $c['fields'] as $f) {
            if (in_array($f['field_type'] ?? '', FormDefinition::CHOICE_TYPES, true) && empty($f['options'])) {
                $errors[] = __('forms.ai.invalid_options');
                break;
            }
        }
        foreach ((array) $c['fields'] as $f) {
            if (($f['field_type'] ?? '') === 'file'
                && ($f['file_accept'] ?? '') === 'custom'
                && trim((string) ($f['file_custom_ext'] ?? '')) === ''
            ) {
                $errors[] = 'Los campos de archivo personalizados necesitan extensiones permitidas.';
                break;
            }
        }
        if ((string) $c['notify_email'] !== '' && !filter_var($c['notify_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('forms.error.bad_notice_email');
        }
        if ((string) $c['autoresponder_enabled'] === '1') {
            $hasEmailField = false;
            foreach ($c['fields'] as $f) {
                if (($f['field_type'] ?? '') === 'email') { $hasEmailField = true; break; }
            }
            if (!$hasEmailField) {
                $errors[] = __('forms.error.autoresponder_needs_email');
            }
        }
        return $errors;
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private function normalizeOptions(mixed $raw): array
    {
        $lines = is_array($raw)
            ? array_map('strval', $raw)
            : preg_split('/\R/', (string) $raw);
        $options = [];
        foreach ($lines ?: [] as $line) {
            $option = mb_substr(trim((string) $line), 0, 120);
            if ($option === '' || in_array($option, $options, true)) {
                continue;
            }
            $options[] = $option;
            if (count($options) >= 30) {
                break;
            }
        }
        return $options;
    }

    private function normalizeFileMaxMb(mixed $raw): int
    {
        $n = (int) $raw;
        return max(1, min(10, $n));
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private function normalizeExtensions(mixed $raw): array
    {
        $items = preg_split('/[\s,;]+/', strtolower((string) $raw)) ?: [];
        $allowed = ['pdf','doc','docx','txt','rtf','odt','jpg','jpeg','png','webp','gif'];
        $out = [];
        foreach ($items as $item) {
            $ext = ltrim(trim($item), '.');
            if ($ext === '' || !in_array($ext, $allowed, true) || in_array($ext, $out, true)) {
                continue;
            }
            $out[] = $ext;
        }
        return array_slice($out, 0, 12);
    }

    /**
     * Cuenta en cuántas páginas Canvas publicadas se usa cada formulario
     * (referencias {{form:id}}). Para mostrar "dónde se usa" en el listado.
     *
     * @return array<int,int>  formId => nº de páginas
     */
    private function usageMap(int $siteId): array
    {
        return FormPlacementStore::usageMap($siteId);
    }

    /** @param array<string,mixed> $form @param array<int,string> $errors */
    private function renderEditor(int $siteId, int $id, array $form, array $errors): void
    {
        View::send('admin/forms/edit', array_merge(
            DashboardController::getCommonData(),
            [
                'form_id'   => $id,
                'form'      => $form,
                'errors'    => $errors,
                // FORMS-LANG T8 — estado de idiomas del formulario.
                'baseLang'     => FormI18n::baseLanguage($form),
                'primaryLang'  => LanguageService::primaryFor($siteId),
                'siteLangs'    => LanguageService::activeFor($siteId),
                'translated'   => FormI18n::translatedLanguages($form),
                'langLabels'   => LanguageService::LANGUAGES,
                'notice'    => Session::flash('notice'),
                'csrf'      => CSRF::token(),
            ]
        ));
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
