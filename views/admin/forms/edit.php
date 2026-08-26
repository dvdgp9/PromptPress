<?php
/**
 * @var int     $form_id
 * @var array   $form     content del formulario
 * @var array   $errors
 * @var ?string $notice
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');

$fieldTypes = [
    'text'     => __('field.text'),
    'email'    => __('field.email'),
    'tel'      => __('field.tel'),
    'textarea' => __('field.textarea'),
    'checkbox' => __('field.checkbox'),
    'select'   => __('field.select'),
    'number'   => __('field.number'),
    'date'     => __('field.date'),
    'url'      => 'URL',
    'file'     => __('field.file'),
];
$filePresets = [
    'documents' => __('field.accept.documents'),
    'images'    => __('field.accept.images'),
    'cv'        => __('field.accept.cv'),
    'custom'    => __('field.accept.custom'),
];
$fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
$lawful = (string) ($form['lawful_basis'] ?? 'legitimate_interest');
$arEnabled = (string) ($form['autoresponder_enabled'] ?? '0') === '1';

$renderFieldRow = function (int $i, array $f) use ($fieldTypes, $filePresets): string {
    $label = (string) ($f['label'] ?? '');
    $name = (string) ($f['name'] ?? '');
    $type = (string) ($f['field_type'] ?? 'text');
    $req = (string) ($f['required'] ?? '0') === '1';
    $ph = (string) ($f['placeholder'] ?? '');
    $options = $f['options'] ?? [];
    $optionsText = is_array($options) ? implode("\n", array_map('strval', $options)) : (string) $options;
    $fileAccept = (string) ($f['file_accept'] ?? 'documents');
    $fileMaxMb = (int) ($f['file_max_mb'] ?? 5);
    $fileCustomExt = (string) ($f['file_custom_ext'] ?? '');
    $typeLabel = (string) ($fieldTypes[$type] ?? $fieldTypes['text']);
    ob_start(); ?>
    <div class="pp-fb-row" data-fb-row>
        <div class="pp-fb-row__head">
            <span class="pp-fb-row__drag" aria-hidden="true">⠿</span>
            <div class="pp-fb-row__identity">
                <strong data-fb-title><?= e($label !== '' ? $label : __('form_edit.untitled_field')) ?></strong>
                <span><b data-fb-type-label><?= e($typeLabel) ?></b><i data-fb-required><?= $req ? 'Obligatorio' : 'Opcional' ?></i></span>
            </div>
            <button type="button" class="pp-fb-row__remove" data-fb-remove title="<?= e(__('form_edit.remove_field')) ?>" aria-label="<?= e(__('form_edit.remove_field')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="pp-fb-row__grid">
            <label class="pp-fb-control">
                <span><?= e(__('form_edit.field_label')) ?></span>
                <input type="text" name="fields[<?= $i ?>][label]" value="<?= e($label) ?>" placeholder="<?= e(__('form_edit.field_label_placeholder')) ?>" data-fb-label required>
            </label>
            <label class="pp-fb-control">
                <span><?= e(__('form_edit.field_type')) ?></span>
                <select name="fields[<?= $i ?>][field_type]" aria-label="<?= e(__('form_edit.field_type')) ?>" data-fb-type>
                    <?php foreach ($fieldTypes as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= $type === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="pp-fb-control pp-fb-control--wide">
                <span><?= e(__('form_edit.field_help')) ?></span>
                <input type="text" name="fields[<?= $i ?>][placeholder]" value="<?= e($ph) ?>" placeholder="<?= e(__('form_edit.field_help_placeholder')) ?>">
            </label>
            <input type="hidden" name="fields[<?= $i ?>][name]" value="<?= e($name) ?>" data-fb-name>
            <label class="pp-fb-switch pp-fb-row__req">
                <input type="checkbox" name="fields[<?= $i ?>][required]" value="1" <?= $req ? 'checked' : '' ?>>
                <span><?= e(__('form_edit.required')) ?></span>
            </label>
            <label class="pp-fb-control pp-fb-row__options" data-fb-options-wrap <?= $type === 'select' ? '' : 'hidden' ?>>
                <span><?= e(__('form_edit.select_options')) ?></span>
                <textarea name="fields[<?= $i ?>][options]" rows="3" placeholder="<?= e(__('form_edit.options_placeholder')) ?>" data-fb-options><?= e($optionsText) ?></textarea>
            </label>
            <div class="pp-fb-row__file" data-fb-file <?= $type === 'file' ? '' : 'hidden' ?>>
                <div class="pp-fb-row__file-head">
                    <strong><?= e(__('form_edit.file_upload')) ?></strong>
                    <span><?= e(__('form_edit.file_upload_help')) ?></span>
                </div>
                <label class="pp-fb-control">
                    <span><?= e(__('form_edit.allowed_formats')) ?></span>
                    <select name="fields[<?= $i ?>][file_accept]" aria-label="<?= e(__('form_edit.allowed_formats')) ?>" data-fb-file-accept>
                        <?php foreach ($filePresets as $val => $lbl): ?>
                            <option value="<?= e($val) ?>" <?= $fileAccept === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="pp-fb-control">
                    <span><?= e(__('form_edit.max_size')) ?></span>
                    <input type="number" name="fields[<?= $i ?>][file_max_mb]" value="<?= e((string) max(1, min(10, $fileMaxMb))) ?>" min="1" max="10" step="1" aria-label="<?= e(__('form_edit.max_size_mb')) ?>" placeholder="MB">
                </label>
                <label class="pp-fb-control pp-fb-control--wide" data-fb-file-custom-wrap <?= $fileAccept === 'custom' ? '' : 'hidden' ?>>
                    <span><?= e(__('form_edit.custom_ext')) ?></span>
                    <input type="text" name="fields[<?= $i ?>][file_custom_ext]" value="<?= e($fileCustomExt) ?>" placeholder="pdf,jpg,png" data-fb-file-custom>
                </label>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>

<?php \Core\View::start('title'); ?>Editar formulario<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    var RUN_URL = <?= json_encode(base_url('admin/ai/actions/run')) ?>;
    var CSRF = <?= json_encode($csrf) ?>;
    var ALLOWED_TYPES = ['text', 'email', 'tel', 'textarea', 'checkbox', 'select', 'number', 'date', 'url', 'file'];

    var list = document.getElementById('pp-fb-list');
    var addBtn = document.getElementById('pp-fb-add');
    var tpl = document.getElementById('pp-fb-template');
    var counter = 10000; // índices nuevos, fuera del rango de los renderizados

    function slugify(s) {
        return String(s || '').toLowerCase().trim()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function selectedText(select) {
        return select && select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex].text : '';
    }

    // Auto-genera el id (name) a partir de la etiqueta, salvo que el usuario lo haya tocado a mano.
    function wireRow(row) {
        var label = row.querySelector('[data-fb-label]');
        var name = row.querySelector('[data-fb-name]');
        var type = row.querySelector('[data-fb-type]');
        var title = row.querySelector('[data-fb-title]');
        var typeLabel = row.querySelector('[data-fb-type-label]');
        var requiredLabel = row.querySelector('[data-fb-required]');
        var requiredInput = row.querySelector('.pp-fb-row__req input[type=checkbox]');
        var options = row.querySelector('[data-fb-options]');
        var optionsWrap = row.querySelector('[data-fb-options-wrap]');
        var fileConfig = row.querySelector('[data-fb-file]');
        var fileAccept = row.querySelector('[data-fb-file-accept]');
        var fileCustom = row.querySelector('[data-fb-file-custom]');
        var fileCustomWrap = row.querySelector('[data-fb-file-custom-wrap]');
        var drag = row.querySelector('.pp-fb-row__drag');
        function syncSummary() {
            if (title) title.textContent = (label && label.value.trim()) ? label.value.trim() : pp.t('js.form_edit.untitled_field');
            if (typeLabel) typeLabel.textContent = selectedText(type) || 'Texto';
            if (requiredLabel) requiredLabel.textContent = requiredInput && requiredInput.checked ? 'Obligatorio' : 'Opcional';
        }
        if (drag) {
            drag.addEventListener('mousedown', function () { row.draggable = true; });
            drag.addEventListener('touchstart', function () { row.draggable = true; }, { passive: true });
        }
        row.addEventListener('dragstart', function (e) {
            if (!row.draggable) { e.preventDefault(); return; }
            row.classList.add('is-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
            }
        });
        row.addEventListener('dragend', function () {
            row.classList.remove('is-dragging');
            row.draggable = false;
        });
        if (label && name) {
            label.addEventListener('input', function () {
                if (!name.dataset.touched) name.value = slugify(label.value);
                syncSummary();
            });
        }
        if (type && options) {
            var syncOptions = function () {
                if (optionsWrap) optionsWrap.hidden = type.value !== 'select';
                if (fileConfig) fileConfig.hidden = type.value !== 'file';
                syncSummary();
            };
            type.addEventListener('change', syncOptions);
            syncOptions();
        }
        if (requiredInput) requiredInput.addEventListener('change', syncSummary);
        if (fileAccept && fileCustom) {
            var syncCustom = function () {
                if (fileCustomWrap) fileCustomWrap.hidden = fileAccept.value !== 'custom';
            };
            fileAccept.addEventListener('change', syncCustom);
            syncCustom();
        }
        syncSummary();
        var rm = row.querySelector('[data-fb-remove]');
        if (rm) rm.addEventListener('click', function () {
            if (list.querySelectorAll('[data-fb-row]').length <= 1) {
                // No dejar el formulario sin ningún campo: vaciar en vez de borrar.
                label.value = ''; if (name) name.value = '';
                if (options) options.value = '';
                if (fileCustom) fileCustom.value = '';
                syncSummary();
                return;
            }
            row.remove();
        });
    }

    // Crea una fila (opcionalmente con valores) y la añade al final.
    function makeRow(values) {
        var html = tpl.innerHTML.replace(/9999/g, String(counter++));
        var tmp = document.createElement('div');
        tmp.innerHTML = html.trim();
        var row = tmp.firstElementChild;
        if (values) {
            var label = row.querySelector('[data-fb-label]');
            var name = row.querySelector('[data-fb-name]');
            var sel = row.querySelector('[data-fb-type]');
            var req = row.querySelector('.pp-fb-row__req input[type=checkbox]');
            var options = row.querySelector('[data-fb-options]');
            var fileAccept = row.querySelector('[data-fb-file-accept]');
            var fileMax = row.querySelector('input[type=number]');
            var fileCustom = row.querySelector('[data-fb-file-custom]');
            if (label) label.value = values.label || '';
            if (name) name.value = slugify(values.label || '');
            if (sel) sel.value = (ALLOWED_TYPES.indexOf(values.field_type) !== -1) ? values.field_type : 'text';
            if (req) req.checked = !!values.required;
            if (options && Array.isArray(values.options)) options.value = values.options.join('\n');
            if (fileAccept && values.file_accept) fileAccept.value = values.file_accept;
            if (fileMax && values.file_max_mb) fileMax.value = values.file_max_mb;
            if (fileCustom && values.file_custom_ext) fileCustom.value = values.file_custom_ext;
        }
        list.appendChild(row);
        wireRow(row);
        return row;
    }

    Array.prototype.forEach.call(list.querySelectorAll('[data-fb-row]'), wireRow);

    list.addEventListener('dragover', function (e) {
        var dragged = list.querySelector('.is-dragging');
        if (!dragged) return;
        e.preventDefault();
        var target = e.target.closest('[data-fb-row]');
        if (!target || target === dragged) return;
        var rect = target.getBoundingClientRect();
        if ((e.clientY - rect.top) > rect.height / 2) target.after(dragged);
        else target.before(dragged);
    });

    addBtn.addEventListener('click', function () {
        var row = makeRow(null);
        var l = row.querySelector('[data-fb-label]');
        if (l) l.focus();
    });

    // Toggle de los campos de autorrespuesta.
    var arToggle = document.getElementById('pp-f-ar-enabled');
    var arFields = document.getElementById('pp-ar-fields');
    if (arToggle && arFields) {
        arToggle.addEventListener('change', function () { arFields.hidden = !arToggle.checked; });
    }

    // --- IA -----------------------------------------------------------
    function runAction(action, input) {
        var fd = new FormData();
        fd.append('_csrf', CSRF);
        fd.append('action', action);
        fd.append('input_json', JSON.stringify(input));
        return fetch(RUN_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }
    function setVal(id, v) { var el = document.getElementById(id); if (el && v != null && v !== '') el.value = v; }

    var designBtn = document.getElementById('pp-ai-design');
    var descIn = document.getElementById('pp-ai-desc');
    var designStatus = document.getElementById('pp-ai-design-status');
    if (designBtn) {
        designBtn.addEventListener('click', function () {
            var desc = (descIn.value || '').trim();
            if (desc.length < 5) { descIn.focus(); return; }
            designBtn.disabled = true;
            designStatus.hidden = false; designStatus.classList.remove('is-error');
            designStatus.textContent = pp.t('js.form_edit.generating');
            runAction('design_form', { description: desc })
                .then(function (res) {
                    if (!res || !res.ok || !res.data) throw new Error((res && res.error) || pp.t('js.form_edit.generate_failed'));
                    var d = res.data;
                    setVal('pp-f-heading', d.heading);
                    setVal('pp-f-desc', d.description);
                    setVal('pp-f-submit', d.submit_text);
                    setVal('pp-f-success', d.success_message);
                    if (Array.isArray(d.fields) && d.fields.length) {
                        list.innerHTML = '';
                        d.fields.forEach(function (f) { makeRow(f); });
                    }
                    if (d.autoresponder_subject || d.autoresponder_body) {
                        setVal('pp-f-ar-subject', d.autoresponder_subject);
                        setVal('pp-f-ar-body', d.autoresponder_body);
                        // Si la IA propone autorrespuesta, la dejamos activada y visible.
                        if (arToggle && !arToggle.checked) { arToggle.checked = true; arFields.hidden = false; }
                    }
                    designStatus.textContent = pp.t('js.form_edit.generated_ok');
                })
                .catch(function (e) {
                    designStatus.classList.add('is-error');
                    designStatus.textContent = pp.t('js.form_edit.generate_error', { error: e.message });
                })
                .finally(function () { designBtn.disabled = false; });
        });
    }

    var arBtn = document.getElementById('pp-ai-autoresp');
    if (arBtn) {
        arBtn.addEventListener('click', function () {
            var heading = (document.getElementById('pp-f-heading').value || '').trim();
            var labels = Array.prototype.map.call(list.querySelectorAll('[data-fb-label]'), function (i) { return i.value; })
                .filter(function (v) { return v; });
            // i18n-ignore: resumen que viaja a la IA, no se pinta en pantalla.
            var summary = 'Título: ' + heading + '\nCampos: ' + labels.join(', ');
            arBtn.disabled = true;
            var prev = arBtn.textContent; arBtn.textContent = pp.t('js.form_edit.drafting');
            runAction('draft_form_autoresponder', { form_summary: summary })
                .then(function (res) {
                    if (!res || !res.ok || !res.data) throw new Error('error');
                    setVal('pp-f-ar-subject', res.data.subject);
                    var body = document.getElementById('pp-f-ar-body');
                    if (body && res.data.body) body.value = res.data.body;
                })
                .catch(function () { /* silencioso: se puede escribir a mano */ })
                .finally(function () { arBtn.disabled = false; arBtn.textContent = prev; });
        });
    }
})();
</script>
<?php \Core\View::end(); ?>

<div class="pp-forms-wrap pp-forms-wrap--editor">
<div class="pp-page-header">
    <div>
        <a class="pp-back-link" href="<?= e(base_url('admin/formularios')) ?>">← <?= e(__('forms.title')) ?></a>
        <h2><?= e(__('form_edit.title')) ?></h2>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong><?= e(__('settings_ai.check_errors')) ?></strong>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php
// FORMS-LANG T5/T8 — idiomas del formulario. Se enseña siempre: aunque el
// sitio tenga un solo idioma, un formulario heredado puede estar en otro
// (webs creadas antes de esto: nacían en castellano pasara lo que pasara).
$baseLang    = $baseLang    ?? \App\Services\LanguageService::DEFAULT;
$primaryLang = $primaryLang ?? $baseLang;
$siteLangs   = $siteLangs   ?? [$primaryLang];
$translated  = $translated  ?? [];
$langLabels  = $langLabels  ?? \App\Services\LanguageService::LANGUAGES;
$pendingLangs = array_values(array_filter(
    $siteLangs,
    fn (string $code): bool => $code !== $baseLang && !in_array($code, $translated, true)
));
$baseIsWrong = $baseLang !== $primaryLang;
?>
<section class="pp-form-langs<?= $baseIsWrong ? ' is-warning' : '' ?>">
    <div class="pp-form-langs__head">
        <h3><?= e(__('form_edit.languages')) ?></h3>
        <p>
            <?= __('form_edit.written_in.html', ['idioma' => '<strong>' . e($langLabels[$baseLang] ?? $baseLang) . '</strong>']) ?>
            <?php if ($baseIsWrong): ?>
                <?= __('form_edit.wrong_base.html', ['idioma' => '<strong>' . e($langLabels[$primaryLang] ?? $primaryLang) . '</strong>']) ?>
            <?php elseif (count($siteLangs) > 1): ?>
                <?= e(__('form_edit.shared_inbox')) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="pp-form-langs__list">
        <?php foreach ($siteLangs as $code): ?>
            <?php
            $isBase = $code === $baseLang;
            $isDone = in_array($code, $translated, true);
            $state  = $isBase ? 'base' : ($isDone ? 'done' : 'pending');
            $stateLabel = $isBase ? __('form_edit.state_base') : ($isDone ? __('form_edit.state_done') : __('form_edit.state_pending'));
            ?>
            <div class="pp-form-lang pp-form-lang--<?= e($state) ?>">
                <div>
                    <strong><?= e($langLabels[$code] ?? $code) ?></strong>
                    <small><?= e($stateLabel) ?></small>
                </div>
                <?php if (!$isBase): ?>
                <form method="POST" action="<?= e(base_url('admin/formularios/' . $form_id . '/translate')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="lang" value="<?= e($code) ?>">
                    <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">
                        <?php if ($code === $primaryLang): ?><?= e(__('form_edit.translate_to', ['idioma' => $langLabels[$code] ?? $code])) ?>
                        <?php elseif ($isDone): ?><?= e(__('form_edit.retranslate')) ?>
                        <?php else: ?><?= e(__('form_edit.translate_ai')) ?><?php endif; ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($pendingLangs !== [] && !$baseIsWrong): ?>
    <p class="pp-form-langs__note">
        <?= e(__('form_edit.untranslated_note', ['idioma' => $langLabels[$baseLang] ?? $baseLang])) ?>
    </p>
    <?php endif; ?>
</section>

<form method="POST" action="<?= e(base_url('admin/formularios/' . $form_id)) ?>" class="pp-form" id="pp-form-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel pp-form-ai-box">
        <div class="pp-ai-section-head"><div><h3>✨ <?= e(__('form_edit.ai_create')) ?></h3><p><?= e(__('form_edit.ai_create_help')) ?></p></div></div>
        <div class="pp-form-ai-row">
            <input type="text" id="pp-ai-desc" maxlength="300"
                   placeholder="<?= e(__('form_edit.ai_placeholder')) ?>">
            <button type="button" class="pp-btn pp-btn--secondary" id="pp-ai-design"><?= e(__('form_edit.generate')) ?></button>
        </div>
        <small id="pp-ai-design-status" class="pp-design-hint" hidden></small>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('form_edit.header')) ?></h3><p><?= e(__('form_edit.header_help')) ?></p></div></div>
        <div class="pp-form-group">
            <label for="pp-f-heading"><?= e(__('table.title')) ?></label>
            <input type="text" id="pp-f-heading" name="heading" maxlength="160" required value="<?= e((string) ($form['heading'] ?? '')) ?>" placeholder="Contacta con nosotros">
        </div>
        <div class="pp-form-group">
            <label for="pp-f-desc"><?= e(__('form_edit.description')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
            <textarea id="pp-f-desc" name="description" rows="2" maxlength="500" placeholder="<?= e(__('form_edit.description_placeholder')) ?>"><?= e((string) ($form['description'] ?? '')) ?></textarea>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('form_edit.fields')) ?></h3><p><?= e(__('form_edit.fields_help')) ?></p></div></div>
        <div id="pp-fb-list" class="pp-fb-list">
            <?php if ($fields === []): ?>
                <?= $renderFieldRow(0, ['label' => '', 'field_type' => 'text', 'required' => '0']) ?>
            <?php else: foreach ($fields as $i => $f): ?>
                <?= $renderFieldRow((int) $i, is_array($f) ? $f : []) ?>
            <?php endforeach; endif; ?>
        </div>
        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-fb-add"><?= e(__('form_edit.add_field')) ?></button>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('form_edit.on_submit')) ?></h3><p><?= e(__('form_edit.on_submit_help')) ?></p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-f-submit"><?= e(__('form_edit.button_text')) ?></label>
                <input type="text" id="pp-f-submit" name="submit_text" maxlength="60" value="<?= e((string) ($form['submit_text'] ?? 'Enviar')) ?>" placeholder="Enviar">
            </div>
            <div class="pp-form-group">
                <label for="pp-f-notify"><?= e(__('form_edit.notify_email')) ?></label>
                <input type="email" id="pp-f-notify" name="notify_email" maxlength="255" value="<?= e((string) ($form['notify_email'] ?? '')) ?>" placeholder="Por defecto, el del sitio">
                <small><?= e(__('form_edit.notify_help')) ?></small>
            </div>
        </div>
        <div class="pp-form-group">
            <label for="pp-f-success"><?= e(__('form_edit.success_message')) ?></label>
            <input type="text" id="pp-f-success" name="success_message" maxlength="240" value="<?= e((string) ($form['success_message'] ?? '')) ?>" placeholder="Gracias, te contactaremos pronto.">
            <small><?= e(__('form_edit.success_help')) ?></small>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('form_edit.autoresponder')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></h3><p>Un correo automático de "te hemos recibido". Necesita un campo de tipo Email en el formulario.</p></div></div>
        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="autoresponder_enabled" value="1" id="pp-f-ar-enabled" <?= $arEnabled ? 'checked' : '' ?>>
                <?= e(__('form_edit.send_autoresponder')) ?>
            </label>
        </div>
        <div id="pp-ar-fields" <?= $arEnabled ? '' : 'hidden' ?>>
            <div class="pp-form-group">
                <label for="pp-f-ar-subject"><?= e(__('form_edit.subject')) ?></label>
                <input type="text" id="pp-f-ar-subject" name="autoresponder_subject" maxlength="200" value="<?= e((string) ($form['autoresponder_subject'] ?? '')) ?>" placeholder="Hemos recibido tu mensaje">
            </div>
            <div class="pp-form-group">
                <label for="pp-f-ar-body">Mensaje
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" id="pp-ai-autoresp" style="float:right;">✨ <?= e(__('form_edit.draft_ai')) ?></button>
                </label>
                <textarea id="pp-f-ar-body" name="autoresponder_body" rows="6" maxlength="4000" placeholder="Hola {{nombre}}: gracias por escribirnos…"><?= e((string) ($form['autoresponder_body'] ?? '')) ?></textarea>
                <small><?= __('form_edit.tokens_help.html') ?></small>
            </div>
        </div>
    </section>

    <details class="pp-ai-config-panel pp-forms-legal">
        <summary><strong><?= e(__('form_edit.privacy')) ?></strong> · <?= e(__('form_edit.privacy_sub')) ?></summary>
        <div class="pp-form-group">
            <label for="pp-f-lawful"><?= e(__('form_edit.lawful_basis')) ?></label>
            <select id="pp-f-lawful" name="lawful_basis">
                <option value="legitimate_interest" <?= $lawful === 'legitimate_interest' ? 'selected' : '' ?>><?= e(__('form_edit.lawful.legitimate')) ?></option>
                <option value="consent" <?= $lawful === 'consent' ? 'selected' : '' ?>><?= e(__('form_edit.lawful.consent')) ?></option>
                <option value="contract" <?= $lawful === 'contract' ? 'selected' : '' ?>><?= e(__('form_edit.lawful.contract')) ?></option>
            </select>
        </div>
        <div class="pp-form-group">
            <label for="pp-f-retention"><?= e(__('form_edit.retention')) ?></label>
            <input type="text" id="pp-f-retention" name="retention_period" maxlength="160" value="<?= e((string) ($form['retention_period'] ?? '')) ?>" placeholder="<?= e(__('form_edit.retention_placeholder')) ?>">
        </div>
        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="marketing_opt_in" value="1" <?= (string) ($form['marketing_opt_in'] ?? '0') === '1' ? 'checked' : '' ?>>
                <?= e(__('form_edit.marketing_optin')) ?>
            </label>
            <small><?= e(__('form_edit.marketing_help')) ?></small>
        </div>
    </details>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><span class="pp-icon pp-icon--check"></span> <?= e(__('common.save')) ?></button>
        <a href="<?= e(base_url('admin/formularios')) ?>" class="pp-btn pp-btn--ghost"><?= e(__('common.cancel')) ?></a>
    </div>
</form>
</div>

<template id="pp-fb-template">
    <?= $renderFieldRow(9999, ['label' => '', 'field_type' => 'text', 'required' => '0']) ?>
</template>
