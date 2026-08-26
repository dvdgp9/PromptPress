<?php
/** @var array $legalInput */
/** @var array $legalErrors */
/** @var string $csrf */
$v  = $legalInput;
$e  = $legalErrors;
$hasDpo = is_array($v['dpo'] ?? null) && !empty($v['dpo']['name'] ?? '');
$dpoName  = $hasDpo ? ($v['dpo']['name']  ?? '') : '';
$dpoEmail = $hasDpo ? ($v['dpo']['email'] ?? '') : '';
$formAction      = $formAction      ?? base_url('admin/privacy/legal');
$submitLabel     = $submitLabel     ?? 'Guardar datos';
$hideSubmit      = $hideSubmit      ?? false;
?>

<form method="POST" action="<?= e($formAction) ?>" class="pp-form pp-privacy-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-card">
        <div class="pp-form-card__head">
            <div>
                <h3><?= e(__('privacy.tab_legal')) ?></h3>
                <p><?= e(__('privacy.legal.intro')) ?></p>
            </div>
        </div>

        <div class="pp-form-group <?= isset($e['legal_name']) ? 'has-error' : '' ?>">
            <label for="legal_name"><?= e(__('privacy.legal.company')) ?> <span class="pp-req">*</span></label>
            <input type="text" id="legal_name" name="legal_name" required maxlength="255"
                   value="<?= e($v['legal_name'] ?? '') ?>"
                   placeholder="<?= e(__('onboarding.legal.name_placeholder')) ?>">
            <small><?= e(__('onboarding.legal.name_help')) ?></small>
            <?php if (isset($e['legal_name'])): ?><small class="pp-err"><?= e($e['legal_name']) ?></small><?php endif; ?>
        </div>

        <div class="pp-form-group">
            <label for="brand_name"><?= e(__('privacy.legal.brand')) ?></label>
            <input type="text" id="brand_name" name="brand_name" maxlength="255"
                   value="<?= e($v['brand_name'] ?? '') ?>"
                   placeholder="<?= e(__('privacy.legal.brand_placeholder')) ?>">
            <small><?= e(__('privacy.legal.brand_help')) ?></small>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($e['tax_id']) ? 'has-error' : '' ?>">
                <label for="tax_id"><?= e(__('onboarding.legal.tax_id')) ?></label>
                <input type="text" id="tax_id" name="tax_id" maxlength="20"
                       value="<?= e($v['tax_id'] ?? '') ?>"
                       placeholder="<?= e(__('privacy.legal.tax_placeholder')) ?>">
                <small><?= e(__('privacy.legal.tax_help')) ?></small>
                <?php if (isset($e['tax_id'])): ?><small class="pp-err"><?= e($e['tax_id']) ?></small><?php endif; ?>
            </div>

            <div class="pp-form-group">
                <label for="country"><?= e(__('privacy.legal.country')) ?></label>
                <select id="country" name="country">
                    <?php
                    // Las claves son códigos ISO; solo la etiqueta se traduce.
                    $countries = ['ES' => __('country.ES'), 'PT' => __('country.PT'), 'FR' => __('country.FR'), 'IT' => __('country.IT'), 'DE' => __('country.DE'), 'GB' => __('country.GB'), 'MX' => __('country.MX'), 'AR' => __('country.AR'), 'CO' => __('country.CO'), 'CL' => __('country.CL'), 'PE' => __('country.PE'), 'US' => __('country.US'), 'OTHER' => __('country.OTHER')];
                    $sel = $v['country'] ?? 'ES';
                    foreach ($countries as $code => $name):
                    ?>
                    <option value="<?= e($code) ?>" <?= $sel === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(__('privacy.legal.country_help')) ?></small>
            </div>
        </div>

        <div class="pp-form-group <?= isset($e['address']) ? 'has-error' : '' ?>">
            <label for="address"><?= e(__('onboarding.legal.address')) ?> <span class="pp-req">*</span></label>
            <input type="text" id="address" name="address" required maxlength="500"
                   value="<?= e($v['address'] ?? '') ?>"
                   placeholder="<?= e(__('onboarding.legal.address_placeholder')) ?>">
            <small><?= e(__('privacy.legal.address_help')) ?></small>
            <?php if (isset($e['address'])): ?><small class="pp-err"><?= e($e['address']) ?></small><?php endif; ?>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($e['email']) ? 'has-error' : '' ?>">
                <label for="email"><?= e(__('privacy.legal.email')) ?> <span class="pp-req">*</span></label>
                <input type="email" id="email" name="email" required maxlength="255"
                       value="<?= e($v['email'] ?? '') ?>"
                       placeholder="contacto@tu-dominio.com">
                <small><?= e(__('privacy.legal.email_help')) ?></small>
                <?php if (isset($e['email'])): ?><small class="pp-err"><?= e($e['email']) ?></small><?php endif; ?>
            </div>

            <div class="pp-form-group">
                <label for="phone"><?= e(__('privacy.legal.phone')) ?></label>
                <input type="text" id="phone" name="phone" maxlength="50"
                       value="<?= e($v['phone'] ?? '') ?>"
                       placeholder="<?= e(__('privacy.legal.phone_placeholder')) ?>">
                <small><?= e(__('privacy.legal.phone_help')) ?></small>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="registry_details"><?= e(__('privacy.legal.registry')) ?></label>
            <input type="text" id="registry_details" name="registry_details" maxlength="500"
                   value="<?= e($v['registry_details'] ?? '') ?>"
                   placeholder="<?= e(__('priv.registry_ph')) ?>">
            <small><?= e(__('privacy.legal.registry_help')) ?></small>
        </div>
    </div>

    <div class="pp-form-card">
        <div class="pp-form-card__head">
            <div>
                <h3><?= e(__('privacy.legal.dpo_title')) ?></h3>
                <p><?= e(__('privacy.legal.dpo_help')) ?></p>
            </div>
        </div>

        <div class="pp-form-group">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="has_dpo" value="1" data-toggle-target="#dpo-fields" <?= $hasDpo ? 'checked' : '' ?>>
                <?= e(__('privacy.legal.has_dpo')) ?>
            </label>
        </div>

        <div id="dpo-fields" class="pp-form-row" <?= $hasDpo ? '' : 'hidden' ?>>
            <div class="pp-form-group">
                <label for="dpo_name"><?= e(__('privacy.legal.dpo_name')) ?></label>
                <input type="text" id="dpo_name" name="dpo_name" maxlength="255"
                       value="<?= e($dpoName) ?>">
            </div>
            <div class="pp-form-group <?= isset($e['dpo_email']) ? 'has-error' : '' ?>">
                <label for="dpo_email"><?= e(__('privacy.legal.dpo_email')) ?></label>
                <input type="email" id="dpo_email" name="dpo_email" maxlength="255"
                       value="<?= e($dpoEmail) ?>">
                <?php if (isset($e['dpo_email'])): ?><small class="pp-err"><?= e($e['dpo_email']) ?></small><?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$hideSubmit): ?>
    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e($submitLabel) ?></button>
    </div>
    <?php endif; ?>
</form>

<script>
(function() {
    var cb = document.querySelector('[data-toggle-target="#dpo-fields"]');
    var target = document.getElementById('dpo-fields');
    if (!cb || !target) return;
    cb.addEventListener('change', function() {
        target.hidden = !cb.checked;
    });
})();
</script>
