<?php
/**
 * @var array $providerMeta  [provider, model, configured]
 * @var array $providers     nombre => label
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Test de IA<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    var form = document.getElementById('pp-ai-test-form');
    var out  = document.getElementById('pp-ai-test-output');
    var btn  = document.getElementById('pp-ai-test-submit');
    var spinner = document.getElementById('pp-ai-test-spinner');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        out.textContent = '';
        out.className = 'pp-ai-output';
        btn.disabled = true;
        spinner.hidden = false;

        var fd = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().then(function (data) { return [r.status, data]; }); })
          .then(function (arr) {
              var status = arr[0], data = arr[1];
              btn.disabled = false;
              spinner.hidden = true;
              if (!data.ok) {
                  out.className = 'pp-ai-output pp-ai-output--error';
                  out.textContent = '❌ ' + (data.error || ('HTTP ' + status));
                  return;
              }
              var r = data.response;
              out.className = 'pp-ai-output pp-ai-output--success';
              out.innerHTML = '<div class="pp-ai-output__meta">'
                  + '<span>' + escapeHtml(r.provider) + '</span>'
                  + '<span>' + escapeHtml(r.model) + '</span>'
                  + '<span>' + r.tokens_in + ' → ' + r.tokens_out + ' tokens</span>'
                  + '<span>' + r.latency_ms + ' ms</span>'
                  + '</div>'
                  + '<div class="pp-ai-output__content">' + escapeHtml(r.content) + '</div>';
          })
          .catch(function (err) {
              btn.disabled = false;
              spinner.hidden = true;
              out.className = 'pp-ai-output pp-ai-output--error';
              out.textContent = 'Error de red: ' + err.message;
          });
    });

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Test del proveedor de IA</h2>
</div>

<p class="pp-page-intro">
    Envía un prompt al proveedor configurado y verifica que la integración funciona end-to-end.
</p>

<?php if (!$providerMeta['configured']): ?>
    <div class="pp-alert pp-alert--error">
        <strong>No hay proveedor configurado.</strong>
        Completa la configuración en el instalador o añade los settings <code>ai_provider</code>,
        <code>ai_model</code> y <code>ai_api_key</code> en la base de datos.
    </div>
<?php else: ?>
    <div class="pp-ai-provider-card">
        <div class="pp-ai-provider-card__label">Proveedor activo</div>
        <div class="pp-ai-provider-card__body">
            <strong><?= e($providers[$providerMeta['provider']] ?? $providerMeta['provider']) ?></strong>
            <span class="pp-ai-provider-card__model"><?= e($providerMeta['model']) ?></span>
        </div>
    </div>
<?php endif; ?>

<form id="pp-ai-test-form" method="POST" action="<?= e(base_url('admin/ai/test')) ?>" class="pp-form pp-ai-test-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-field">
        <label for="pp-ai-prompt">Prompt</label>
        <textarea id="pp-ai-prompt" name="prompt" rows="4" maxlength="2000"
                  placeholder="Ej: Di 'hola' en tres idiomas."><?= e('Saluda brevemente como asistente de PromptPress.') ?></textarea>
    </div>

    <div class="pp-form-actions">
        <button type="submit" id="pp-ai-test-submit" class="pp-btn pp-btn--primary"
                <?= !$providerMeta['configured'] ? 'disabled' : '' ?>>
            <span class="pp-icon pp-icon--ai"></span>
            Enviar al modelo
        </button>
        <span id="pp-ai-test-spinner" class="pp-ai-spinner" hidden>Generando…</span>
    </div>
</form>

<div id="pp-ai-test-output" class="pp-ai-output"></div>
