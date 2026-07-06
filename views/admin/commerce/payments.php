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

<?php \Core\View::start('title'); ?>Métodos de pago<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Métodos de pago</h2>
        <p class="pp-page-header__lead">Configura cómo cobran tus clientes: con tarjeta a través de Stripe y/o por transferencia u otro pago acordado.</p>
    </div>
    <div>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce')) ?>">← Volver a la tienda</a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= $err ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/commerce/pagos')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-card pp-stripe-card">
        <div class="pp-stripe-card__head">
            <h3>Pago con tarjeta (Stripe)</h3>
            <?php if ($configured && $mode === 'live'): ?>
                <span class="pp-status-pill pp-status-pill--green">Activo · cobros reales</span>
            <?php elseif ($configured): ?>
                <span class="pp-status-pill pp-status-pill--green">Activo · modo pruebas</span>
            <?php else: ?>
                <span class="pp-status-pill">Sin configurar</span>
            <?php endif; ?>
        </div>
        <p class="pp-booking-soft">Tus clientes pagan con tarjeta en una página segura de Stripe: tu web nunca ve los datos de la tarjeta, y el pedido se marca como pagado automáticamente. Stripe cobra una pequeña comisión por transacción.</p>

        <ol class="pp-stripe-steps">
            <li>Crea una cuenta gratuita en <a href="https://dashboard.stripe.com/register" target="_blank" rel="noopener">stripe.com</a> (o entra si ya la tienes).</li>
            <li>En Stripe, ve a <strong>Desarrolladores → Claves de API</strong> y copia la <strong>clave secreta</strong>. Pégala abajo y guarda: el pago con tarjeta ya aparecerá en tu tienda.</li>
            <li>Para que los pedidos se confirmen solos incluso si el cliente cierra la pestaña, ve a <strong>Desarrolladores → Webhooks → Añadir endpoint</strong>, pega la URL de abajo, selecciona los eventos <code>checkout.session.*</code> y copia aquí el <strong>secreto de firma</strong> (whsec_…).</li>
        </ol>

        <div class="pp-form-group">
            <label>Modo de funcionamiento</label>
            <div class="pp-stripe-modes">
                <label class="pp-stripe-mode<?= $mode === 'test' ? ' is-active' : '' ?>">
                    <input type="radio" name="stripe_mode" value="test" <?= $mode === 'test' ? 'checked' : '' ?>>
                    <span><strong>Modo pruebas</strong><br><small>Nadie paga de verdad. Prueba la tienda con la tarjeta 4242 4242 4242 4242.</small></span>
                </label>
                <label class="pp-stripe-mode<?= $mode === 'live' ? ' is-active' : '' ?>">
                    <input type="radio" name="stripe_mode" value="live" <?= $mode === 'live' ? 'checked' : '' ?>>
                    <span><strong>Modo real</strong><br><small>Cobros de verdad a tus clientes. Actívalo cuando todo funcione en pruebas.</small></span>
                </label>
            </div>
        </div>

        <div class="pp-stripe-keys">
            <fieldset class="pp-stripe-keyset<?= $mode === 'test' ? ' is-active' : '' ?>">
                <legend>Claves del modo pruebas <?= $mode === 'test' ? '<span class="pp-status-pill pp-status-pill--green">en uso</span>' : '' ?></legend>
                <div class="pp-form-group">
                    <label for="pp-sk-test">Clave secreta <span class="pp-ai-optional-tag">empieza por sk_test_</span></label>
                    <input type="password" id="pp-sk-test" name="sk_test" autocomplete="off"
                           placeholder="<?= $maskedSkTest !== null ? 'Guardada: ' . e($maskedSkTest) . ' — pega otra para cambiarla' : 'sk_test_…' ?>">
                </div>
                <div class="pp-form-group">
                    <label for="pp-whsec-test">Secreto del webhook <span class="pp-ai-optional-tag">empieza por whsec_</span></label>
                    <input type="password" id="pp-whsec-test" name="whsec_test" autocomplete="off"
                           placeholder="<?= $maskedWhsecTest !== null ? 'Guardado: ' . e($maskedWhsecTest) . ' — pega otro para cambiarlo' : 'whsec_… (paso 3)' ?>">
                </div>
            </fieldset>

            <fieldset class="pp-stripe-keyset<?= $mode === 'live' ? ' is-active' : '' ?>">
                <legend>Claves del modo real <?= $mode === 'live' ? '<span class="pp-status-pill pp-status-pill--green">en uso</span>' : '' ?></legend>
                <div class="pp-form-group">
                    <label for="pp-sk-live">Clave secreta <span class="pp-ai-optional-tag">empieza por sk_live_</span></label>
                    <input type="password" id="pp-sk-live" name="sk_live" autocomplete="off"
                           placeholder="<?= $maskedSkLive !== null ? 'Guardada: ' . e($maskedSkLive) . ' — pega otra para cambiarla' : 'sk_live_…' ?>">
                </div>
                <div class="pp-form-group">
                    <label for="pp-whsec-live">Secreto del webhook <span class="pp-ai-optional-tag">empieza por whsec_</span></label>
                    <input type="password" id="pp-whsec-live" name="whsec_live" autocomplete="off"
                           placeholder="<?= $maskedWhsecLive !== null ? 'Guardado: ' . e($maskedWhsecLive) . ' — pega otro para cambiarlo' : 'whsec_… (paso 3)' ?>">
                </div>
            </fieldset>
        </div>

        <div class="pp-form-group">
            <label>URL del webhook <span class="pp-ai-optional-tag">para el paso 3</span></label>
            <div class="pp-stripe-webhook">
                <code id="pp-webhook-url"><?= e($webhookUrl) ?></code>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm"
                        onclick="navigator.clipboard.writeText(document.getElementById('pp-webhook-url').textContent).then(() => { this.textContent = '✓ Copiada'; setTimeout(() => this.textContent = 'Copiar', 1500); });">Copiar</button>
            </div>
            <small>Las claves se guardan cifradas y nunca vuelven a mostrarse completas. Deja un campo en blanco para conservar la clave ya guardada.</small>
        </div>
    </div>

    <div class="pp-card pp-manual-card">
        <h3>Transferencia u otro pago acordado</h3>
        <p class="pp-booking-soft">Siempre disponible: el pedido queda pendiente y lo marcas como pagado cuando recibas el dinero. Escribe aquí lo que verá el cliente al terminar la compra (por ejemplo, tu IBAN o cómo pagar contra reembolso).</p>
        <div class="pp-form-group">
            <label for="pp-manual-instr">Instrucciones para el cliente</label>
            <textarea id="pp-manual-instr" name="manual_instructions" rows="4"
                      placeholder="Ej: Haz una transferencia a ES00 0000 0000 0000 0000 0000 indicando el número de pedido. Enviamos en cuanto recibamos el pago."><?= e($manualInstructions) ?></textarea>
        </div>
    </div>

    <div class="pp-stripe-actions">
        <button type="submit" class="pp-btn pp-btn--primary">Guardar configuración</button>
        <?php if ($maskedSkTest !== null || $maskedSkLive !== null): ?>
        <button type="submit" name="disable_stripe" value="1" class="pp-btn pp-btn--ghost pp-btn--danger-text"
                onclick="return confirm('¿Desactivar el pago con tarjeta? Se eliminarán las claves de Stripe guardadas y dejará de ofrecerse en el checkout.');">
            Desactivar pago con tarjeta
        </button>
        <?php endif; ?>
    </div>
</form>
