<?php
/**
 * @var array  $providers         factory providers map
 * @var array  $suggested_models  por proveedor
 * @var array  $model_presets     presets UI por proveedor
 * @var string $current_provider
 * @var string $current_model
 * @var string $current_model_light
 * @var bool   $has_api_key
 * @var array  $errors
 * @var ?string $notice
 * @var string $csrf
 * @var bool   $unsplash_configured
 * @var string $unsplash_masked
 * @var ?string $image_notice
 * @var ?string $image_error
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Ajustes · IA<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    var providerSel = document.getElementById('pp-ai-provider');
    var modelInput  = document.getElementById('pp-ai-model');
    var help        = document.getElementById('pp-ai-model-help');
    var presetGroups = Array.prototype.slice.call(document.querySelectorAll('[data-ai-provider-presets]'));
    var presetButtons = Array.prototype.slice.call(document.querySelectorAll('[data-ai-model-preset]'));
    var suggestions = <?= json_encode($suggested_models, JSON_UNESCAPED_SLASHES) ?>;

    function updateSelectedPreset() {
        var current = modelInput.value.trim();
        presetButtons.forEach(function (button) {
            button.classList.toggle('is-selected', button.getAttribute('data-ai-model-preset') === current);
        });
    }

    function refresh() {
        var p = providerSel.value;
        var arr = suggestions[p] || [];
        if (arr.length) modelInput.placeholder = arr[0];
        presetGroups.forEach(function (group) {
            group.hidden = group.getAttribute('data-ai-provider-presets') !== p;
        });
        if (help) {
            help.innerHTML = arr.length
                ? 'También puedes escribir cualquier ID compatible. Ejemplos: ' + arr.slice(0, 4).map(function (m) { return '<code>' + m + '</code>'; }).join(', ')
                : 'Escribe el ID exacto del modelo que quieres usar.';
        }
        updateSelectedPreset();
    }

    presetButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            modelInput.value = button.getAttribute('data-ai-model-preset') || '';
            updateSelectedPreset();
            modelInput.focus({ preventScroll: true });
        });
    });
    providerSel.addEventListener('change', refresh);
    modelInput.addEventListener('input', updateSelectedPreset);
    refresh();
})();
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header pp-ai-settings-header">
    <div>
        <span class="pp-ai-kicker">Motor de contenido</span>
        <h2>Ajustes · IA</h2>
        <p class="pp-page-intro">
            Elige el modelo base que usará PromptPress para generar y mejorar contenido. Puedes empezar con un preset y cambiar a un ID avanzado cuando quieras.
        </p>
    </div>
    <div class="pp-ai-status-card <?= $has_api_key ? 'is-ready' : 'is-empty' ?>">
        <span class="pp-ai-status-dot"></span>
        <strong><?= $has_api_key ? 'API key configurada' : 'Falta API key' ?></strong>
        <small><?= $has_api_key ? 'Las pruebas pueden usar la credencial guardada.' : 'Guarda una credencial para activar las llamadas reales.' ?></small>
    </div>
</div>

<nav class="pp-settings-tabs" aria-label="Secciones de ajustes">
    <a href="<?= e(base_url('admin/settings')) ?>">General</a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>" class="is-active">IA</a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>">Correo</a>
</nav>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong>Revisa lo siguiente:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/settings/ai')) ?>" class="pp-form pp-ai-settings-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel" aria-labelledby="pp-ai-config-title">
        <div class="pp-ai-section-head">
            <div>
                <h3 id="pp-ai-config-title">Modelo principal</h3>
                <p>Se usa para tareas de creación de contenido: <strong>generar páginas con IA</strong> y <strong>generar secciones</strong>. Prioriza calidad sobre coste.</p>
            </div>
        </div>

        <div class="pp-form-group pp-ai-provider-field">
            <label for="pp-ai-provider">Proveedor</label>
            <select id="pp-ai-provider" name="provider" required>
                <?php foreach ($providers as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $code === $current_provider ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>OpenRouter es el mejor laboratorio para probar familias de modelos sin cambiar integración.</small>
        </div>

        <div class="pp-ai-presets-wrap">
            <?php foreach ($model_presets as $providerCode => $presets): ?>
                <div class="pp-ai-model-grid" data-ai-provider-presets="<?= e($providerCode) ?>">
                    <?php foreach ($presets as $preset): ?>
                        <?php $selected = $preset['model'] === $current_model; ?>
                        <button type="button"
                                class="pp-ai-model-card pp-ai-model-card--<?= e((string) ($preset['tone'] ?? 'standard')) ?><?= $selected ? ' is-selected' : '' ?>"
                                data-ai-model-preset="<?= e($preset['model']) ?>">
                            <span class="pp-ai-model-card__top">
                                <span class="pp-ai-model-card__name"><?= e($preset['name']) ?></span>
                                <span class="pp-ai-model-card__badge"><?= e($preset['badge']) ?></span>
                            </span>
                            <span class="pp-ai-model-card__summary"><?= e($preset['summary']) ?></span>
                            <span class="pp-ai-model-card__meta">
                                <span><?= e($preset['use_case']) ?></span>
                                <span><?= e($preset['cost']) ?> / 1M tokens</span>
                            </span>
                            <code><?= e($preset['model']) ?></code>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pp-form-group">
            <label for="pp-ai-model">ID del modelo</label>
            <input type="text" id="pp-ai-model" name="model"
                   value="<?= e($current_model) ?>" required maxlength="100"
                   list="pp-ai-model-list"
                   placeholder="anthropic/claude-3.5-haiku">
            <datalist id="pp-ai-model-list">
                <?php foreach ($suggested_models as $providerCode => $models): ?>
                    <?php foreach ($models as $m): ?>
                        <option value="<?= e($m) ?>"></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </datalist>
            <small id="pp-ai-model-help" class="pp-design-hint"></small>
        </div>
    </section>

    <section class="pp-ai-config-panel" aria-labelledby="pp-ai-config-aux-title">
        <div class="pp-ai-section-head">
            <div>
                <h3 id="pp-ai-config-aux-title">Modelo auxiliar <span class="pp-ai-optional-tag">opcional</span></h3>
                <p>Se usa para tareas cortas y frecuentes: <strong>reescribir texto</strong> y <strong>mejorar SEO</strong>. Aquí prima la rapidez y el coste bajo. Si lo dejas vacío, estas tareas usarán el modelo principal.</p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-ai-model-light">ID del modelo auxiliar</label>
            <input type="text" id="pp-ai-model-light" name="model_light"
                   value="<?= e($current_model_light ?? '') ?>" maxlength="100"
                   list="pp-ai-model-list"
                   placeholder="Ej: google/gemini-3.1-flash-lite, anthropic/claude-3.5-haiku, gpt-4o-mini">
            <small class="pp-design-hint">
                Sugerencia: usa la misma familia de proveedor que el modelo principal y elige una variante <em>flash / mini / haiku / lite</em>.
                Compatible con la misma API key configurada abajo.
            </small>
        </div>
    </section>

    <div class="pp-form-card">
        <h3>Credenciales</h3>

        <div class="pp-form-group">
            <label for="pp-ai-key">API Key</label>
            <input type="password" id="pp-ai-key" name="api_key"
                   placeholder="<?= $has_api_key ? '•••••••••••••• (deja vacío para no cambiar)' : 'sk-... / sk-or-v1-...' ?>"
                   autocomplete="new-password">
            <small>
                <?php if ($has_api_key): ?>
                    Ya hay una key guardada. Déjalo en blanco para mantenerla, o pega una nueva para reemplazarla.
                <?php else: ?>
                    Requerida. Se encripta con la clave única de esta instalación antes de guardarla.
                <?php endif; ?>
            </small>
        </div>

        <div class="pp-ai-security-note">
            <strong>Guardado seguro</strong>
            <span>La key se encripta con AES-256-GCM y no vuelve a mostrarse en pantalla.</span>
        </div>

        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="test_connection" value="1" checked>
                Verificar conexión antes de guardar
            </label>
            <small>Hace una llamada mínima al modelo seleccionado para evitar guardar una configuración rota.</small>
        </div>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            Guardar
        </button>
        <?php if ($has_api_key): ?>
            <a href="<?= e(base_url('admin/ai/test')) ?>" class="pp-btn pp-btn--secondary">Probar con un prompt real →</a>
        <?php endif; ?>
    </div>
</form>

<section class="pp-ai-config-panel" aria-labelledby="pp-img-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-img-title">Imágenes · Unsplash <span class="pp-ai-optional-tag">opcional</span></h3>
            <p>Permite que las páginas se generen con fotos reales de Unsplash. La clave es única para toda la instalación.</p>
        </div>
        <div class="pp-ai-status-card <?= $unsplash_configured ? 'is-ready' : 'is-empty' ?>">
            <span class="pp-ai-status-dot"></span>
            <strong><?= $unsplash_configured ? 'Conectado' : 'Sin configurar' ?></strong>
            <small><?= $unsplash_configured ? e($unsplash_masked) : 'Las páginas se generan sin imágenes.' ?></small>
        </div>
    </div>

    <?php if ($image_notice): ?>
        <div class="pp-alert pp-alert--success"><?= e($image_notice) ?></div>
    <?php endif; ?>
    <?php if ($image_error): ?>
        <div class="pp-alert pp-alert--error"><?= e($image_error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= e(base_url('admin/settings/images')) ?>" class="pp-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="pp-form-group">
            <label for="pp-unsplash-key">Unsplash Access Key</label>
            <input type="password" id="pp-unsplash-key" name="unsplash_key"
                   placeholder="<?= $unsplash_configured ? '•••••••••••••• (deja vacío para no cambiar)' : 'Tu Access Key de Unsplash' ?>"
                   autocomplete="new-password">
            <small>
                Consíguela gratis en <a href="https://unsplash.com/developers" target="_blank" rel="noopener">unsplash.com/developers</a>
                (crea una app → copia la <em>Access Key</em>; 50 imágenes/hora en modo demo). Verificamos la clave antes de guardarla.
            </small>
        </div>

        <?php if ($unsplash_configured): ?>
            <div class="pp-form-group pp-ai-test-row">
                <label class="pp-checkbox-label">
                    <input type="checkbox" name="remove_unsplash" value="1">
                    Quitar la clave actual (desactivar imágenes)
                </label>
            </div>
        <?php endif; ?>

        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--primary">
                <span class="pp-icon pp-icon--check"></span>
                Guardar imágenes
            </button>
        </div>
    </form>
</section>
