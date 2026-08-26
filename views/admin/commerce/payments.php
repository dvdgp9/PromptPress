<?php
/**
 * Tienda — métodos de pago (FEAT-3 C5).
 *
 * @var 'test'|'live' $mode
 * @var bool    $configured          hay clave secreta para el modo activo
 * @var ?string $maskedSkTest        "sk_test_••••1234" o null
 * @var ?string $maskedSkLive
 * @var ?string $maskedWhsecTest
 * @var ?string $maskedWhsecLive
 * @var string  $webhookUrl
 * @var string  $manualInstructions
 * @var string[] $errors             pueden llevar HTML propio (negritas)
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('shop.payment_methods')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('shop.payment_methods')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('pay.intro')) ?></p>
    </div>
    <div>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce')) ?>">← <?= e(__('pay.back_to_shop')) ?></a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= $err ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/commerce/pagos')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-card pp-stripe-card">
        <div class="pp-stripe-card__head">
            <h3><?= e(__('pay.stripe_title')) ?></h3>
            <?php if ($configured && $mode === 'live'): ?>
                <span class="pp-status-pill pp-status-pill--green"><?= e(__('pay.active_live')) ?></span>
            <?php elseif ($configured): ?>
                <span class="pp-status-pill pp-status-pill--green"><?= e(__('pay.active_test')) ?></span>
            <?php else: ?>
                <span class="pp-status-pill"><?= e(__('settings_ai.not_configured')) ?></span>
            <?php endif; ?>
        </div>
        <p class="pp-booking-soft"><?= e(__('pay.stripe_help')) ?></p>

        <ol class="pp-stripe-steps">
            <li><?= __('pay.step1.html', ['enlace' => '<a href="https://dashboard.stripe.com/register" target="_blank" rel="noopener">stripe.com</a>']) ?></li>
            <li><?= __('pay.step2.html') ?></li>
            <li><?= __('pay.step3.html') ?></li>
        </ol>

        <div class="pp-form-group">
            <label><?= e(__('pay.mode')) ?></label>
            <div class="pp-stripe-modes">
                <label class="pp-stripe-mode<?= $mode === 'test' ? ' is-active' : '' ?>">
                    <input type="radio" name="stripe_mode" value="test" <?= $mode === 'test' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('pay.mode_test')) ?></strong><br><small><?= e(__('pay.mode_test_help')) ?></small></span>
                </label>
                <label class="pp-stripe-mode<?= $mode === 'live' ? ' is-active' : '' ?>">
                    <input type="radio" name="stripe_mode" value="live" <?= $mode === 'live' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('pay.mode_live')) ?></strong><br><small><?= e(__('pay.mode_live_help')) ?></small></span>
                </label>
            </div>
        </div>

        <div class="pp-stripe-keys">
            <fieldset class="pp-stripe-keyset<?= $mode === 'test' ? ' is-active' : '' ?>">
                <legend><?= e(__('pay.test_keys')) ?> <?= $mode === 'test' ? '<span class="pp-status-pill pp-status-pill--green">' . e(__('pay.in_use')) . '</span>' : '' ?></legend>
                <div class="pp-form-group">
                    <label for="pp-sk-test"><?= e(__('pay.secret_key')) ?> <span class="pp-ai-optional-tag"><?= e(__('pay.starts_with', ['prefijo' => 'sk_test_'])) ?></span></label>
                    <input type="password" id="pp-sk-test" name="sk_test" autocomplete="off"
                           placeholder="<?= $maskedSkTest !== null ? e(__('pay.saved_key', ['clave' => $maskedSkTest])) : 'sk_test_…' ?>">
                </div>
                <div class="pp-form-group">
                    <label for="pp-whsec-test"><?= e(__('pay.webhook_secret')) ?> <span class="pp-ai-optional-tag"><?= e(__('pay.starts_with', ['prefijo' => 'whsec_'])) ?></span></label>
                    <input type="password" id="pp-whsec-test" name="whsec_test" autocomplete="off"
                           placeholder="<?= $maskedWhsecTest !== null ? e(__('pay.saved_secret', ['clave' => $maskedWhsecTest])) : 'whsec_… (paso 3)' ?>">
                </div>
            </fieldset>

            <fieldset class="pp-stripe-keyset<?= $mode === 'live' ? ' is-active' : '' ?>">
                <legend><?= e(__('pay.live_keys')) ?> <?= $mode === 'live' ? '<span class="pp-status-pill pp-status-pill--green">' . e(__('pay.in_use')) . '</span>' : '' ?></legend>
                <div class="pp-form-group">
                    <label for="pp-sk-live"><?= e(__('pay.secret_key')) ?> <span class="pp-ai-optional-tag"><?= e(__('pay.starts_with', ['prefijo' => 'sk_live_'])) ?></span></label>
                    <input type="password" id="pp-sk-live" name="sk_live" autocomplete="off"
                           placeholder="<?= $maskedSkLive !== null ? e(__('pay.saved_key', ['clave' => $maskedSkLive])) : 'sk_live_…' ?>">
                </div>
                <div class="pp-form-group">
                    <label for="pp-whsec-live"><?= e(__('pay.webhook_secret')) ?> <span class="pp-ai-optional-tag"><?= e(__('pay.starts_with', ['prefijo' => 'whsec_'])) ?></span></label>
                    <input type="password" id="pp-whsec-live" name="whsec_live" autocomplete="off"
                           placeholder="<?= $maskedWhsecLive !== null ? e(__('pay.saved_secret', ['clave' => $maskedWhsecLive])) : 'whsec_… (paso 3)' ?>">
                </div>
            </fieldset>
        </div>

        <div class="pp-form-group">
            <label><?= e(__('pay.webhook_url')) ?> <span class="pp-ai-optional-tag"><?= e(__('pay.for_step3')) ?></span></label>
            <div class="pp-stripe-webhook">
                <code id="pp-webhook-url"><?= e($webhookUrl) ?></code>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm"
                        onclick="navigator.clipboard.writeText(document.getElementById('pp-webhook-url').textContent).then(() => { this.textContent = '✓ ' + pp.t('js.bank.imported_f'); setTimeout(() => this.textContent = 'Copiar', 1500); });">Copiar</button>
            </div>
            <small><?= e(__('pay.keys_note')) ?></small>
        </div>
    </div>

    <div class="pp-card pp-manual-card">
        <h3><?= e(__('pay.manual_title')) ?></h3>
        <p class="pp-booking-soft"><?= e(__('pay.manual_help')) ?></p>
        <div class="pp-form-group">
            <label for="pp-manual-instr"><?= e(__('pay.instructions')) ?></label>
            <textarea id="pp-manual-instr" name="manual_instructions" rows="4"
                      placeholder="<?= e(__('pay.instructions_placeholder')) ?>"><?= e($manualInstructions) ?></textarea>
        </div>
    </div>

    <div class="pp-stripe-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('pay.save')) ?></button>
        <?php if ($maskedSkTest !== null || $maskedSkLive !== null): ?>
        <button type="submit" name="disable_stripe" value="1" class="pp-btn pp-btn--ghost pp-btn--danger-text"
                onclick="return confirm(<?= e(json_encode(__('pay.confirm_disable'), JSON_UNESCAPED_UNICODE)) ?>);">
            Desactivar pago con tarjeta
        </button>
        <?php endif; ?>
    </div>
</form>
