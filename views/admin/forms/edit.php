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
    'text'     => 'Texto',
    'email'    => 'Email',
    'tel'      => 'Teléfono',
    'textarea' => 'Área de texto',
    'checkbox' => 'Casilla',
    'select'   => 'Selector',
    'number'   => 'Número',
    'date'     => 'Fecha',
    'url'      => 'URL',
    'file'     => 'Archivo',
];
$filePresets = [
    'documents' => 'Documentos (PDF, DOCX, TXT)',
    'images'    => 'Imágenes (JPG, PNG, WebP)',
    'cv'        => 'CV / portfolio (PDF, DOCX)',
    'custom'    => 'Personalizado',
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
    ob_start(); ?>
    <div class="pp-fb-row" data-fb-row>
        <span class="pp-fb-row__drag" aria-hidden="true">⠿</span>
        <div class="pp-fb-row__grid">
            <input type="text" name="fields[<?= $i ?>][label]" value="<?= e($label) ?>" placeholder="Etiqueta (ej. Nombre)" data-fb-label required>
            <select name="fields[<?= $i ?>][field_type]" aria-label="Tipo de campo">
                <?php foreach ($fieldTypes as $val => $lbl): ?>
                    <option value="<?= e($val) ?>" <?= $type === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="fields[<?= $i ?>][placeholder]" value="<?= e($ph) ?>" placeholder="Texto de ayuda (opcional)">
            <input type="hidden" name="fields[<?= $i ?>][name]" value="<?= e($name) ?>" data-fb-name>
            <label class="pp-checkbox-label pp-fb-row__req">
                <input type="checkbox" name="fields[<?= $i ?>][required]" value="1" <?= $req ? 'checked' : '' ?>>
                Obligatorio
            </label>
            <textarea name="fields[<?= $i ?>][options]" rows="2" placeholder="Opciones del selector, una por línea" data-fb-options <?= $type === 'select' ? '' : 'hidden' ?>><?= e($optionsText) ?></textarea>
            <div class="pp-fb-row__file" data-fb-file <?= $type === 'file' ? '' : 'hidden' ?>>
                <select name="fields[<?= $i ?>][file_accept]" aria-label="Tipos de archivo permitidos" data-fb-file-accept>
                    <?php foreach ($filePresets as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= $fileAccept === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="fields[<?= $i ?>][file_max_mb]" value="<?= e((string) max(1, min(10, $fileMaxMb))) ?>" min="1" max="10" step="1" aria-label="Tamaño máximo en MB" placeholder="MB">
                <input type="text" name="fields[<?= $i ?>][file_custom_ext]" value="<?= e($fileCustomExt) ?>" placeholder="Extensiones: pdf,jpg,png" data-fb-file-custom <?= $fileAccept === 'custom' ? '' : 'hidden' ?>>
            </div>
        </div>
        <button type="button" class="pp-fb-row__remove" data-fb-remove title="Quitar campo" aria-label="Quitar campo">✕</button>
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

    // Auto-genera el id (name) a partir de la etiqueta, salvo que el usuario lo haya tocado a mano.
    function wireRow(row) {
        var label = row.querySelector('[data-fb-label]');
        var name = row.querySelector('[data-fb-name]');
        var type = row.querySelector('select');
        var options = row.querySelector('[data-fb-options]');
        var fileConfig = row.querySelector('[data-fb-file]');
        var fileAccept = row.querySelector('[data-fb-file-accept]');
        var fileCustom = row.querySelector('[data-fb-file-custom]');
        if (label && name) {
            label.addEventListener('input', function () {
                if (!name.dataset.touched) name.value = slugify(label.value);
            });
        }
        if (type && options) {
            var syncOptions = function () {
                options.hidden = type.value !== 'select';
                if (fileConfig) fileConfig.hidden = type.value !== 'file';
            };
            type.addEventListener('change', syncOptions);
            syncOptions();
        }
        if (fileAccept && fileCustom) {
            var syncCustom = function () {
                fileCustom.hidden = fileAccept.value !== 'custom';
            };
            fileAccept.addEventListener('change', syncCustom);
            syncCustom();
        }
        var rm = row.querySelector('[data-fb-remove]');
        if (rm) rm.addEventListener('click', function () {
            if (list.querySelectorAll('[data-fb-row]').length <= 1) {
                // No dejar el formulario sin ningún campo: vaciar en vez de borrar.
                label.value = ''; if (name) name.value = '';
                if (options) options.value = '';
                if (fileCustom) fileCustom.value = '';
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
            var sel = row.querySelector('select');
            var req = row.querySelector('input[type=checkbox]');
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
            designStatus.textContent = 'Generando el formulario…';
            runAction('design_form', { description: desc })
                .then(function (res) {
                    if (!res || !res.ok || !res.data) throw new Error((res && res.error) || 'No se pudo generar');
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
                    designStatus.textContent = 'Listo. Revísalo y ajústalo a tu gusto.';
                })
                .catch(function (e) {
                    designStatus.classList.add('is-error');
                    designStatus.textContent = 'No se pudo generar: ' + e.message;
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
            var summary = 'Título: ' + heading + '\nCampos: ' + labels.join(', ');
            arBtn.disabled = true;
            var prev = arBtn.textContent; arBtn.textContent = 'Redactando…';
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
        <a class="pp-back-link" href="<?= e(base_url('admin/formularios')) ?>">← Formularios</a>
        <h2>Editar formulario</h2>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong>Revisa lo siguiente:</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/formularios/' . $form_id)) ?>" class="pp-form" id="pp-form-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel pp-form-ai-box">
        <div class="pp-ai-section-head"><div><h3>✨ Crear con IA</h3><p>Describe el formulario que necesitas y la IA propone los campos y la autorrespuesta. Podrás ajustar todo después.</p></div></div>
        <div class="pp-form-ai-row">
            <input type="text" id="pp-ai-desc" maxlength="300"
                   placeholder="Ej: inscripción a un curso con nombre, email, teléfono y nivel de experiencia">
            <button type="button" class="pp-btn pp-btn--secondary" id="pp-ai-design">Generar</button>
        </div>
        <small id="pp-ai-design-status" class="pp-design-hint" hidden></small>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Cabecera</h3><p>Lo que ve el visitante encima del formulario.</p></div></div>
        <div class="pp-form-group">
            <label for="pp-f-heading">Título</label>
            <input type="text" id="pp-f-heading" name="heading" maxlength="160" required value="<?= e((string) ($form['heading'] ?? '')) ?>" placeholder="Contacta con nosotros">
        </div>
        <div class="pp-form-group">
            <label for="pp-f-desc">Descripción <span class="pp-ai-optional-tag">opcional</span></label>
            <textarea id="pp-f-desc" name="description" rows="2" maxlength="500" placeholder="Una línea que invite a escribir."><?= e((string) ($form['description'] ?? '')) ?></textarea>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Campos</h3><p>Los datos que pides. Arrastra para reordenar, o quita los que no necesites.</p></div></div>
        <div id="pp-fb-list" class="pp-fb-list">
            <?php if ($fields === []): ?>
                <?= $renderFieldRow(0, ['label' => '', 'field_type' => 'text', 'required' => '0']) ?>
            <?php else: foreach ($fields as $i => $f): ?>
                <?= $renderFieldRow((int) $i, is_array($f) ? $f : []) ?>
            <?php endforeach; endif; ?>
        </div>
        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-fb-add">+ Añadir campo</button>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Al enviarse</h3><p>Qué pasa cuando alguien completa el formulario.</p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-f-submit">Texto del botón</label>
                <input type="text" id="pp-f-submit" name="submit_text" maxlength="60" value="<?= e((string) ($form['submit_text'] ?? 'Enviar')) ?>" placeholder="Enviar">
            </div>
            <div class="pp-form-group">
                <label for="pp-f-notify">Avisar a este email</label>
                <input type="email" id="pp-f-notify" name="notify_email" maxlength="255" value="<?= e((string) ($form['notify_email'] ?? '')) ?>" placeholder="Por defecto, el del sitio">
                <small>A dónde llega el aviso con cada respuesta. Si lo dejas vacío, usamos el del sitio.</small>
            </div>
        </div>
        <div class="pp-form-group">
            <label for="pp-f-success">Mensaje de éxito</label>
            <input type="text" id="pp-f-success" name="success_message" maxlength="240" value="<?= e((string) ($form['success_message'] ?? '')) ?>" placeholder="Gracias, te contactaremos pronto.">
            <small>Lo que ve el visitante tras enviar.</small>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Autorrespuesta al visitante <span class="pp-ai-optional-tag">opcional</span></h3><p>Un correo automático de "te hemos recibido". Necesita un campo de tipo Email en el formulario.</p></div></div>
        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="autoresponder_enabled" value="1" id="pp-f-ar-enabled" <?= $arEnabled ? 'checked' : '' ?>>
                Enviar autorrespuesta automática
            </label>
        </div>
        <div id="pp-ar-fields" <?= $arEnabled ? '' : 'hidden' ?>>
            <div class="pp-form-group">
                <label for="pp-f-ar-subject">Asunto</label>
                <input type="text" id="pp-f-ar-subject" name="autoresponder_subject" maxlength="200" value="<?= e((string) ($form['autoresponder_subject'] ?? '')) ?>" placeholder="Hemos recibido tu mensaje">
            </div>
            <div class="pp-form-group">
                <label for="pp-f-ar-body">Mensaje
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" id="pp-ai-autoresp" style="float:right;">✨ Redáctalo con IA</button>
                </label>
                <textarea id="pp-f-ar-body" name="autoresponder_body" rows="6" maxlength="4000" placeholder="Hola {{nombre}}: gracias por escribirnos…"><?= e((string) ($form['autoresponder_body'] ?? '')) ?></textarea>
                <small>Puedes usar <code>{{nombre}}</code> y <code>{{sitio}}</code>: se sustituyen por los datos reales al enviar.</small>
            </div>
        </div>
    </section>

    <details class="pp-ai-config-panel pp-forms-legal">
        <summary><strong>Privacidad (RGPD)</strong> · ajustes legales del formulario</summary>
        <div class="pp-form-group">
            <label for="pp-f-lawful">Base legal del tratamiento</label>
            <select id="pp-f-lawful" name="lawful_basis">
                <option value="legitimate_interest" <?= $lawful === 'legitimate_interest' ? 'selected' : '' ?>>Interés legítimo (atender tu consulta)</option>
                <option value="consent" <?= $lawful === 'consent' ? 'selected' : '' ?>>Consentimiento explícito</option>
                <option value="contract" <?= $lawful === 'contract' ? 'selected' : '' ?>>Ejecución de un contrato o precontrato</option>
            </select>
        </div>
        <div class="pp-form-group">
            <label for="pp-f-retention">Plazo de conservación</label>
            <input type="text" id="pp-f-retention" name="retention_period" maxlength="160" value="<?= e((string) ($form['retention_period'] ?? '')) ?>" placeholder="12 meses tras la última comunicación">
        </div>
        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="marketing_opt_in" value="1" <?= (string) ($form['marketing_opt_in'] ?? '0') === '1' ? 'checked' : '' ?>>
                Pedir consentimiento de marketing por separado
            </label>
            <small>Para usar el email en newsletter u ofertas: aparece una casilla aparte, no premarcada.</small>
        </div>
    </details>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><span class="pp-icon pp-icon--check"></span> Guardar</button>
        <a href="<?= e(base_url('admin/formularios')) ?>" class="pp-btn pp-btn--ghost">Cancelar</a>
    </div>
</form>
</div>

<template id="pp-fb-template">
    <?= $renderFieldRow(9999, ['label' => '', 'field_type' => 'text', 'required' => '0']) ?>
</template>
