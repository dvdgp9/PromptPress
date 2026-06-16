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
                <h3>Datos de tu empresa</h3>
                <p>Estos datos aparecen en el aviso legal y la política de privacidad. Si eres autónomo, pon tu nombre completo en "Razón social".</p>
            </div>
        </div>

        <div class="pp-form-group <?= isset($e['legal_name']) ? 'has-error' : '' ?>">
            <label for="legal_name">Razón social <span class="pp-req">*</span></label>
            <input type="text" id="legal_name" name="legal_name" required maxlength="255"
                   value="<?= e($v['legal_name'] ?? '') ?>"
                   placeholder="Ej: Mi Empresa SL · Juan García López">
            <small>El nombre legal con el que facturas. Si eres autónomo, tu nombre completo.</small>
            <?php if (isset($e['legal_name'])): ?><small class="pp-err"><?= e($e['legal_name']) ?></small><?php endif; ?>
        </div>

        <div class="pp-form-group">
            <label for="brand_name">Nombre comercial (opcional)</label>
            <input type="text" id="brand_name" name="brand_name" maxlength="255"
                   value="<?= e($v['brand_name'] ?? '') ?>"
                   placeholder="Tal y como te conoce tu cliente">
            <small>Si tu marca se llama distinto que tu razón social. Si no, deja vacío.</small>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($e['tax_id']) ? 'has-error' : '' ?>">
                <label for="tax_id">NIF / CIF / NIE</label>
                <input type="text" id="tax_id" name="tax_id" maxlength="20"
                       value="<?= e($v['tax_id'] ?? '') ?>"
                       placeholder="Ej: B12345678">
                <small>Si tu país es España, lo validamos automáticamente.</small>
                <?php if (isset($e['tax_id'])): ?><small class="pp-err"><?= e($e['tax_id']) ?></small><?php endif; ?>
            </div>

            <div class="pp-form-group">
                <label for="country">País</label>
                <select id="country" name="country">
                    <?php
                    $countries = ['ES' => 'España', 'PT' => 'Portugal', 'FR' => 'Francia', 'IT' => 'Italia', 'DE' => 'Alemania', 'GB' => 'Reino Unido', 'MX' => 'México', 'AR' => 'Argentina', 'CO' => 'Colombia', 'CL' => 'Chile', 'PE' => 'Perú', 'US' => 'Estados Unidos', 'OTHER' => 'Otro'];
                    $sel = $v['country'] ?? 'ES';
                    foreach ($countries as $code => $name):
                    ?>
                    <option value="<?= e($code) ?>" <?= $sel === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Determina las normas que aplican.</small>
            </div>
        </div>

        <div class="pp-form-group <?= isset($e['address']) ? 'has-error' : '' ?>">
            <label for="address">Dirección completa <span class="pp-req">*</span></label>
            <input type="text" id="address" name="address" required maxlength="500"
                   value="<?= e($v['address'] ?? '') ?>"
                   placeholder="Calle, número, código postal, ciudad">
            <small>La dirección fiscal o domicilio social. Aparece en el aviso legal.</small>
            <?php if (isset($e['address'])): ?><small class="pp-err"><?= e($e['address']) ?></small><?php endif; ?>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($e['email']) ? 'has-error' : '' ?>">
                <label for="email">Email de contacto <span class="pp-req">*</span></label>
                <input type="email" id="email" name="email" required maxlength="255"
                       value="<?= e($v['email'] ?? '') ?>"
                       placeholder="contacto@tu-dominio.com">
                <small>Donde te pueden escribir para temas legales y de privacidad.</small>
                <?php if (isset($e['email'])): ?><small class="pp-err"><?= e($e['email']) ?></small><?php endif; ?>
            </div>

            <div class="pp-form-group">
                <label for="phone">Teléfono (opcional)</label>
                <input type="text" id="phone" name="phone" maxlength="50"
                       value="<?= e($v['phone'] ?? '') ?>"
                       placeholder="Solo si quieres publicarlo">
                <small>No es obligatorio mostrar el teléfono.</small>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="registry_details">Datos registrales (opcional)</label>
            <input type="text" id="registry_details" name="registry_details" maxlength="500"
                   value="<?= e($v['registry_details'] ?? '') ?>"
                   placeholder='Ej: Inscrita en el Registro Mercantil de Madrid, tomo X, folio Y'>
            <small>Solo si eres sociedad mercantil y quieres incluirlos en el aviso legal.</small>
        </div>
    </div>

    <div class="pp-form-card">
        <div class="pp-form-card__head">
            <div>
                <h3>Delegado de Protección de Datos (DPO)</h3>
                <p>Solo si tu empresa tiene uno designado. La mayoría de pymes no necesita DPO.</p>
            </div>
        </div>

        <div class="pp-form-group">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="has_dpo" value="1" data-toggle-target="#dpo-fields" <?= $hasDpo ? 'checked' : '' ?>>
                Tengo un DPO designado
            </label>
        </div>

        <div id="dpo-fields" class="pp-form-row" <?= $hasDpo ? '' : 'hidden' ?>>
            <div class="pp-form-group">
                <label for="dpo_name">Nombre del DPO</label>
                <input type="text" id="dpo_name" name="dpo_name" maxlength="255"
                       value="<?= e($dpoName) ?>">
            </div>
            <div class="pp-form-group <?= isset($e['dpo_email']) ? 'has-error' : '' ?>">
                <label for="dpo_email">Email del DPO</label>
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
