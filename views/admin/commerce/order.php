<?php
/**
 * Tienda — detalle de un pedido (FEAT-3 C6).
 *
 * @var array    $order       fila de commerce_orders con 'items'
 * @var string[] $nextStates  estados a los que se puede pasar desde el actual
 * @var ?string  $notice
 * @var ?string  $error
 * @var string   $csrf
 */
\Core\View::extend('admin/layout');
use App\Modules\Commerce\CommerceSettings;

$statusLabels = [
    'pending_payment' => __('order.status.pending'),
    'paid'            => __('order.status.paid'),
    'shipped'         => __('order.status.shipped'),
    'cancelled'       => __('order.status.cancelled'),
];
$statusPill = [
    'pending_payment' => ' pp-status-pill--yellow',
    'paid'            => ' pp-status-pill--green',
    'shipped'         => ' pp-status-pill--green',
    'cancelled'       => ' pp-status-pill--muted',
];
$methodLabels = ['stripe' => __('order.method.card_stripe'), 'manual' => __('order.method.transfer_agreed')];
$emailLabels  = ['sent' => __('mail.sent'), 'failed' => '⚠ ' . __('inbox.mail.failed'), 'skipped' => __('order.email_not_configured'), 'unknown' => '—'];
$actionLabels = [
    'paid'      => [__('order.mark_paid'), 'pp-btn--primary', ''],
    'shipped'   => [__('order.mark_shipped'), 'pp-btn--primary', ''],
    'cancelled' => [__('order.cancel'), 'pp-btn--ghost pp-btn--danger-text', __('order.confirm_cancel', ['pedido' => (string) ($order['order_number'] ?? '')])],
];
$st = (string) $order['status'];
$hasShipping = trim((string) ($order['ship_address'] ?? '')) !== '';
$statusUrl = base_url('admin/commerce/pedidos/' . (int) $order['id'] . '/status');
?>

<?php \Core\View::start('title'); ?>Pedido <?= e((string) $order['order_number']) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('order.order')) ?> <?= e((string) $order['order_number']) ?>
            <span class="pp-status-pill<?= $statusPill[$st] ?? '' ?>"><?= e($statusLabels[$st] ?? $st) ?></span>
        </h2>
        <p class="pp-page-header__lead">
            <a href="<?= e(base_url('admin/commerce/pedidos')) ?>">← <?= e(__('order.all_orders')) ?></a>
            · <?= e(__('order.received_on', ['fecha' => (new DateTimeImmutable((string) $order['created_at']))->format('d/m/Y H:i')])) ?>
        </p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<?php if ($nextStates !== []): ?>
<div class="pp-card pp-order-actions">
    <div class="pp-order-actions__label">
        <strong><?= e(__('order.what_to_do')) ?></strong>
        <?php if ($st === 'pending_payment'): ?>
            <span class="pp-booking-soft"><?= e(__('order.mark_paid_help')) ?></span>
        <?php elseif ($st === 'paid'): ?>
            <span class="pp-booking-soft"><?= e(__('order.mark_shipped_help')) ?></span>
        <?php endif; ?>
    </div>
    <div class="pp-order-actions__buttons">
        <?php foreach ($nextStates as $to): [$label, $cls, $confirm] = $actionLabels[$to]; ?>
            <form method="post" action="<?= e($statusUrl) ?>" class="pp-inline-form"
                  <?= $confirm !== '' ? 'onsubmit="return confirm(' . e(json_encode($confirm, JSON_HEX_QUOT | JSON_HEX_APOS)) . ');"' : '' ?>>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="status" value="<?= e($to) ?>">
                <button type="submit" class="pp-btn <?= e($cls) ?>"><?= e($label) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="pp-order-grid">
    <div class="pp-card pp-order-lines">
        <h3><?= e(__('order.items')) ?></h3>
        <table class="pp-table">
            <tbody>
                <?php foreach ($order['items'] as $it): ?>
                <tr>
                    <td>
                        <?= e((string) $it['product_name']) ?>
                        <span class="pp-booking-soft">× <?= (int) $it['quantity'] ?></span>
                    </td>
                    <td class="pp-order-num"><?= e(CommerceSettings::format((int) $it['line_total_cents'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <dl class="pp-shop-totals pp-order-totals">
            <div><dt><?= e(__('order.subtotal')) ?></dt><dd><?= e(CommerceSettings::format((int) $order['subtotal_cents'])) ?></dd></div>
            <?php if ((int) $order['shipping_cents'] > 0): ?>
                <div><dt><?= e(__('order.shipping')) ?></dt><dd><?= e(CommerceSettings::format((int) $order['shipping_cents'])) ?></dd></div>
            <?php endif; ?>
            <div class="pp-shop-totals__grand"><dt><?= e(__('order.total')) ?></dt><dd><?= e(CommerceSettings::format((int) $order['total_cents'])) ?></dd></div>
            <div class="pp-shop-totals__tax"><dt><?= e(__('order.includes_tax')) ?></dt><dd><?= e(CommerceSettings::format((int) $order['tax_cents'])) ?></dd></div>
        </dl>
    </div>

    <div class="pp-order-side">
        <div class="pp-card pp-order-block">
            <h3><?= e(__('order.customer')) ?></h3>
            <p>
                <strong><?= e((string) $order['customer_name']) ?></strong><br>
                <a href="mailto:<?= e((string) $order['customer_email']) ?>"><?= e((string) $order['customer_email']) ?></a>
                <?php if ($order['customer_phone'] !== null): ?><br><?= e((string) $order['customer_phone']) ?><?php endif; ?>
            </p>
            <?php if ($hasShipping): ?>
                <h4><?= e(__('order.shipping')) ?></h4>
                <p class="pp-booking-soft">
                    <?= e((string) $order['ship_address']) ?><br>
                    <?= e(trim((string) $order['ship_postcode'] . ' ' . (string) $order['ship_city'])) ?>
                    <?php if ($order['ship_province'] !== null): ?><br><?= e((string) $order['ship_province']) ?><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($order['notes'] !== null && trim((string) $order['notes']) !== ''): ?>
                <h4><?= e(__('order.customer_note')) ?></h4>
                <p class="pp-booking-soft">«<?= e((string) $order['notes']) ?>»</p>
            <?php endif; ?>
        </div>

        <div class="pp-card pp-order-block">
            <h3><?= e(__('order.payment')) ?></h3>
            <p>
                <?= e($methodLabels[(string) $order['payment_method']] ?? (string) $order['payment_method']) ?><br>
                <span class="pp-booking-soft">
                    <?= e(__('order.email_to_customer')) ?>: <?= e($emailLabels[(string) $order['email_status']] ?? (string) $order['email_status']) ?>
                </span>
                <?php if ($order['payment_ref'] !== null && trim((string) $order['payment_ref']) !== ''): ?>
                    <br><span class="pp-booking-soft"><?= e(__('order.reference')) ?>: <code><?= e((string) $order['payment_ref']) ?></code></span>
                <?php endif; ?>
            </p>
        </div>

        <div class="pp-card pp-order-block">
            <h3><?= e(__('order.internal_notes')) ?></h3>
            <p class="pp-booking-soft"><?= e(__('order.notes_help')) ?></p>
            <form method="post" action="<?= e(base_url('admin/commerce/pedidos/' . (int) $order['id'] . '/notes')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <textarea name="admin_notes" rows="3" placeholder="<?= e(__('order.notes_placeholder')) ?>"><?= e((string) ($order['admin_notes'] ?? '')) ?></textarea>
                <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('order.save_notes')) ?></button>
            </form>
        </div>
    </div>
</div>
