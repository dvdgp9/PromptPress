<?php
/**
 * Partial compartido — formulario de integraciones de tracking (tarjetas
 * toggle + ID). Lo consumen el panel de Marketing y el wizard de privacidad.
 *
 * Variables esperadas:
 * @var array  $trackingCatalog     keyed por service key (metadatos + config_fields)
 * @var array  $trackingCategories  TrackingCatalog::CATEGORIES
 * @var array  $serviceState        estado persistido por key
 * @var string $csrf
 * @var string $cookiesFormAction   action del form
 * @var string $cookiesSubmitLabel  (opcional) texto del botón
 * @var bool   $hideCookiesSubmit   (opcional) ocultar botón (lo controla el wizard)
 */

$serviceState       = $serviceState       ?? [];
$cookiesFormAction  = $cookiesFormAction  ?? base_url('admin/marketing/integrations');
$cookiesSubmitLabel = $cookiesSubmitLabel ?? 'Guardar cambios';
$hideCookiesSubmit  = $hideCookiesSubmit  ?? false;
?>
<form method="POST" action="<?= e($cookiesFormAction) ?>" class="pp-tracking-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <?php foreach ($trackingCatalog as $key => $def):
        $state = $serviceState[$key] ?? null;
        $enabled = !empty($state['enabled']);
        $config  = (array) ($state['config'] ?? []);
        $catLabel = $trackingCategories[$def['category']]['label'] ?? $def['category'];
    ?>
    <article class="pp-tracking-card" data-tracking-key="<?= e($key) ?>">
        <div class="pp-tracking-card__main">
            <label class="pp-tracking-card__toggle">
                <input type="checkbox" name="enabled_<?= e($key) ?>" value="1" <?= $enabled ? 'checked' : '' ?>
                       data-toggle-tracking>
                <span class="pp-tracking-card__switch" aria-hidden="true"></span>
            </label>
            <div class="pp-tracking-card__info">
                <div class="pp-tracking-card__title-row">
                    <h4><?= e($def['name']) ?></h4>
                    <span class="pp-tracking-card__category">Categoría: <?= e($catLabel) ?></span>
                </div>
                <p class="pp-tracking-card__desc"><?= e($def['short_description']) ?></p>
                <p class="pp-tracking-card__processor">
                    Proveedor: <?= e($def['processor']) ?>
                    <?php if ($def['transfer_outside_eea']): ?>· transferencia fuera del EEE<?php endif; ?>
                </p>
            </div>
        </div>

        <div class="pp-tracking-card__config" <?= !$enabled ? 'hidden' : '' ?>>
            <?php foreach ($def['config_fields'] as $fieldKey => $fieldDef):
                $value = (string) ($config[$fieldKey] ?? '');
            ?>
            <div class="pp-form-group">
                <label for="config_<?= e($key) ?>_<?= e($fieldKey) ?>"><?= e($fieldDef['label']) ?></label>
                <input type="text"
                       id="config_<?= e($key) ?>_<?= e($fieldKey) ?>"
                       name="config_<?= e($key) ?>_<?= e($fieldKey) ?>"
                       value="<?= e($value) ?>"
                       placeholder="<?= e($fieldDef['placeholder']) ?>">
                <?php if (!empty($fieldDef['help'])): ?>
                    <small><?= e($fieldDef['help']) ?></small>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </article>
    <?php endforeach; ?>

    <?php if (!$hideCookiesSubmit): ?>
    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e($cookiesSubmitLabel) ?></button>
    </div>
    <?php endif; ?>
</form>

<script>
(function() {
    document.querySelectorAll('.pp-tracking-card').forEach(function(card) {
        var toggle = card.querySelector('[data-toggle-tracking]');
        var config = card.querySelector('.pp-tracking-card__config');
        if (!toggle || !config) return;
        toggle.addEventListener('change', function() {
            config.hidden = !toggle.checked;
            card.classList.toggle('is-enabled', toggle.checked);
        });
        if (toggle.checked) card.classList.add('is-enabled');
    });
})();
</script>
