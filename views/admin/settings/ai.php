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

<?php \Core\View::start('title'); ?><?= e(__('settings_ai.title')) ?><?php \Core\View::end(); ?>

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
                ? pp.t('js.settings_ai.examples') + ' ' + arr.slice(0, 4).map(function (m) { return '<code>' + m + '</code>'; }).join(', ')
                : pp.t('js.settings_ai.exact_id');
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
        <span class="pp-ai-kicker"><?= e(__('settings_ai.kicker')) ?></span>
        <h2><?= e(__('settings_ai.title')) ?></h2>
        <p class="pp-page-intro">
            <?= e(__('settings_ai.intro')) ?>
        </p>
    </div>
    <div class="pp-ai-status-card <?= $has_api_key ? 'is-ready' : 'is-empty' ?>">
        <span class="pp-ai-status-dot"></span>
        <strong><?= e($has_api_key ? __('settings_ai.key_set') : __('settings_ai.key_missing')) ?></strong>
        <small><?= e($has_api_key ? __('settings_ai.key_set_help') : __('settings_ai.key_missing_help')) ?></small>
    </div>
</div>

<nav class="pp-settings-tabs" aria-label="<?= e(__('settings.tabs_aria')) ?>">
    <a href="<?= e(base_url('admin/settings')) ?>"><?= e(__('settings.tab.general')) ?></a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>" class="is-active"><?= e(__('settings.tab.ai')) ?></a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>"><?= e(__('settings.tab.mail')) ?></a>
</nav>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong><?= e(__('settings_ai.check_errors')) ?></strong>
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
                <h3 id="pp-ai-config-title"><?= e(__('settings_ai.main_model')) ?></h3>
                <p><?= __('settings_ai.main_model_help.html') ?></p>
            </div>
        </div>

        <div class="pp-form-group pp-ai-provider-field">
            <label for="pp-ai-provider"><?= e(__('settings_ai.provider')) ?></label>
            <select id="pp-ai-provider" name="provider" required>
                <?php foreach ($providers as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $code === $current_provider ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(__('settings_ai.provider_help')) ?></small>
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
                                <span><?= e($preset['cost']) ?> <?= e(__('settings_ai.per_million')) ?></span>
                            </span>
                            <code><?= e($preset['model']) ?></code>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pp-form-group">
            <label for="pp-ai-model"><?= e(__('settings_ai.model_id')) ?></label>
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
                <h3 id="pp-ai-config-aux-title"><?= e(__('settings_ai.aux_model')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></h3>
                <p><?= __('settings_ai.aux_model_help.html') ?></p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-ai-model-light"><?= e(__('settings_ai.aux_model_id')) ?></label>
            <input type="text" id="pp-ai-model-light" name="model_light"
                   value="<?= e($current_model_light ?? '') ?>" maxlength="100"
                   list="pp-ai-model-list"
                   placeholder="<?= e(__('settings_ai.aux_placeholder')) ?>">
            <small class="pp-design-hint">
                <?= __('settings_ai.aux_hint.html') ?>
            </small>
        </div>
    </section>

    <div class="pp-form-card">
        <h3><?= e(__('settings_ai.credentials')) ?></h3>

        <div class="pp-form-group">
            <label for="pp-ai-key">API Key</label>
            <input type="password" id="pp-ai-key" name="api_key"
                   placeholder="<?= e($has_api_key ? __('settings_ai.key_placeholder_set') : 'sk-... / sk-or-v1-...') ?>"
                   autocomplete="new-password">
            <small>
                <?= e($has_api_key ? __('settings_ai.key_exists_help') : __('settings_ai.key_required_help')) ?>
            </small>
        </div>

        <div class="pp-ai-security-note">
            <strong><?= e(__('settings_ai.secure_title')) ?></strong>
            <span><?= e(__('settings_ai.secure_text')) ?></span>
        </div>

        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="test_connection" value="1" checked>
                <?= e(__('settings_ai.verify_connection')) ?>
            </label>
            <small><?= e(__('settings_ai.verify_help')) ?></small>
        </div>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            <?= e(__('common.save')) ?>
        </button>
        <?php if ($has_api_key): ?>
            <a href="<?= e(base_url('admin/ai/test')) ?>" class="pp-btn pp-btn--secondary"><?= e(__('settings_ai.test_prompt')) ?> →</a>
        <?php endif; ?>
    </div>
</form>

<section class="pp-ai-config-panel" aria-labelledby="pp-img-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-img-title"><?= e(__('settings_ai.images_title')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></h3>
            <p><?= e(__('settings_ai.images_help')) ?></p>
        </div>
        <div class="pp-ai-status-card <?= $unsplash_configured ? 'is-ready' : 'is-empty' ?>">
            <span class="pp-ai-status-dot"></span>
            <strong><?= e($unsplash_configured ? __('settings_ai.connected') : __('settings_ai.not_configured')) ?></strong>
            <small><?= $unsplash_configured ? e($unsplash_masked) : e(__('settings_ai.images_off')) ?></small>
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
            <label for="pp-unsplash-key"><?= e(__('settings_ai.unsplash_key')) ?></label>
            <input type="password" id="pp-unsplash-key" name="unsplash_key"
                   placeholder="<?= e($unsplash_configured ? __('settings_ai.key_placeholder_set') : __('settings_ai.unsplash_placeholder')) ?>"
                   autocomplete="new-password">
            <small>
                <?= __('settings_ai.unsplash_help.html') ?>
            </small>
        </div>

        <?php if ($unsplash_configured): ?>
            <div class="pp-form-group pp-ai-test-row">
                <label class="pp-checkbox-label">
                    <input type="checkbox" name="remove_unsplash" value="1">
                    <?= e(__('settings_ai.remove_key')) ?>
                </label>
            </div>
        <?php endif; ?>

        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--primary">
                <span class="pp-icon pp-icon--check"></span>
                <?= e(__('settings_ai.save_images')) ?>
            </button>
        </div>
    </form>
</section>
