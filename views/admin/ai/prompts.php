<?php
/**
 * @var array  $actions  Actions::all()
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Prompts<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
window.PP_AI_ACTIONS = <?= json_encode(array_map(fn($a) => [
    'label'    => $a['label'],
    'required' => (array) ($a['required'] ?? []),
    'output'   => $a['output'] ?? 'text',
], $actions), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

window.PP_AI_SAMPLES = {
    generate_section: { section_type: 'hero', page_title: 'Inicio', extra_context: 'Para una empresa de desarrollo web.' },
    rewrite_text:     { original_text: 'Somos una empresa con experiencia que hacemos webs.', rewrite_goal: 'Hazlo más específico y orientado a beneficios.' },
    improve_seo:      { page_title: 'Servicios de desarrollo web', page_content: 'Ofrecemos desarrollo web a medida con WordPress, tiendas online y mantenimiento. Llevamos 15 años ayudando a PYMEs.' },
    generate_page_structure: { page_title: 'Página de servicio SEO', page_goal: 'Convertir visitas en leads para una consultoría SEO B2B.' },
};

(function () {
    var form = document.getElementById('pp-ai-prompt-form');
    var select = document.getElementById('pp-ai-action-select');
    var inputTextarea = document.getElementById('pp-ai-input-json');
    var out = document.getElementById('pp-ai-prompt-output');
    if (!form) return;

    function loadSample() {
        var action = select.value;
        var sample = window.PP_AI_SAMPLES[action] || {};
        inputTextarea.value = JSON.stringify(sample, null, 2);
    }
    select.addEventListener('change', loadSample);
    loadSample();

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var mode = e.submitter && e.submitter.dataset.mode === 'execute' ? 'execute' : 'preview';
        runRequest(mode);
    });

    function runRequest(mode) {
        out.textContent = mode === 'execute' ? 'Ejecutando (llamando al modelo)…' : 'Generando prompt…';
        out.className = 'pp-ai-output';
        var fd = new FormData(form);
        var url = mode === 'execute'
            ? '<?= e(base_url('admin/ai/actions/run')) ?>'
            : form.action;
        if (mode === 'execute') {
            fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().then(function (d) { return [r.status, d]; }); })
                .then(function (arr) {
                    var status = arr[0], data = arr[1];
                    if (!data.ok) {
                        out.className = 'pp-ai-output pp-ai-output--error';
                        out.textContent = '❌ ' + (data.error || 'HTTP ' + status);
                        return;
                    }
                    out.className = 'pp-ai-output pp-ai-output--success';
                    var warn = (data.warnings || []).map(function (w) { return '<li>' + escapeHtml(w) + '</li>'; }).join('');
                    var html = '<div class="pp-ai-output__meta">'
                        + '<span>action: <strong>' + escapeHtml(data.action) + '</strong></span>'
                        + '<span>' + escapeHtml(data.provider) + ' / ' + escapeHtml(data.model) + '</span>'
                        + '<span>' + data.tokens_in + ' → ' + data.tokens_out + ' tokens</span>'
                        + '<span>' + data.latency_ms + ' ms</span>'
                        + '</div>'
                        + (warn ? '<div class="pp-ai-warn"><strong>Avisos:</strong><ul>' + warn + '</ul></div>' : '')
                        + '<h4 class="pp-ai-prompt-h4">Resultado (' + escapeHtml(data.output) + ')</h4>'
                        + '<pre class="pp-ai-prompt-pre">' + escapeHtml(data.output === 'json' ? JSON.stringify(data.data, null, 2) : String(data.data)) + '</pre>';
                    out.innerHTML = html;
                })
                .catch(function (err) {
                    out.className = 'pp-ai-output pp-ai-output--error';
                    out.textContent = 'Error de red: ' + err.message;
                });
            return;
        }
        fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return [r.status, d]; }); })
            .then(function (arr) {
                var status = arr[0], data = arr[1];
                if (!data.ok) {
                    out.className = 'pp-ai-output pp-ai-output--error';
                    out.textContent = '❌ ' + (data.error || 'HTTP ' + status);
                    return;
                }
                out.className = 'pp-ai-output pp-ai-output--success';
                var html = '<div class="pp-ai-output__meta">'
                    + '<span>action: <strong>' + escapeHtml(data.meta.action) + '</strong></span>'
                    + '<span>output: ' + escapeHtml(data.meta.output) + '</span>'
                    + '<span>~' + (data.tokens_estimate.system + data.tokens_estimate.user) + ' tokens estimados</span>'
                    + '<span>memory: ' + data.meta.memory_fields_used.length + ' campos</span>'
                    + '<span>docs: ' + data.meta.documents_used.length + '</span>'
                    + '</div>'
                    + '<h4 class="pp-ai-prompt-h4">System prompt</h4>'
                    + '<pre class="pp-ai-prompt-pre">' + escapeHtml(data.messages[0].content) + '</pre>'
                    + '<h4 class="pp-ai-prompt-h4">User prompt</h4>'
                    + '<pre class="pp-ai-prompt-pre">' + escapeHtml(data.messages[1].content) + '</pre>'
                    + '<h4 class="pp-ai-prompt-h4">Options</h4>'
                    + '<pre class="pp-ai-prompt-pre">' + escapeHtml(JSON.stringify(data.options, null, 2)) + '</pre>';
                out.innerHTML = html;
            })
            .catch(function (err) {
                out.className = 'pp-ai-output pp-ai-output--error';
                out.textContent = 'Error de red: ' + err.message;
            });
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Explorador de prompts</h2>
</div>
<p class="pp-page-intro">
    Vista previa del prompt generado para cada acción de IA, sin llamar al modelo.
    Útil para auditar qué contexto (memoria + documentos) se inyecta.
</p>

<form id="pp-ai-prompt-form" method="POST" action="<?= e(base_url('admin/ai/prompts/preview')) ?>" class="pp-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-field">
        <label for="pp-ai-action-select">Acción</label>
        <select id="pp-ai-action-select" name="action">
            <?php foreach ($actions as $key => $def): ?>
                <option value="<?= e($key) ?>"><?= e($def['label']) ?> — <code><?= e($key) ?></code></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="pp-form-field">
        <label for="pp-ai-input-json">Input (JSON con los campos de la acción)</label>
        <textarea id="pp-ai-input-json" name="input_json" rows="8" spellcheck="false"></textarea>
        <small class="pp-design-hint">Campos requeridos se listan en <code>Actions::all()</code>. El ejemplo se precarga automáticamente.</small>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--secondary" data-mode="preview">
            <span class="pp-icon pp-icon--check"></span>
            Ver prompt
        </button>
        <button type="submit" class="pp-btn pp-btn--primary" data-mode="execute">
            <span class="pp-icon pp-icon--ai"></span>
            Ejecutar con IA
        </button>
    </div>
</form>

<div id="pp-ai-prompt-output" class="pp-ai-output"></div>
