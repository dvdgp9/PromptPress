<?php
/**
 * Tienda — listado de pedidos (FEAT-3 C6).
 *
 * @var array   $orders   filas de commerce_orders + item_count
 * @var array   $counts   conteo por estado
 * @var array   $filters  {status, method, q}
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
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
$methodLabels = ['stripe' => __('order.method.card'), 'manual' => __('order.method.transfer')];

// Enlace a un filtro conservando búsqueda y método.
$filterUrl = static function (string $status) use ($filters): string {
    $qs = array_filter([
        'status' => $status,
        'method' => (string) $filters['method'],
        'q'      => (string) $filters['q'],
    ], static fn (string $v): bool => $v !== '');
    return base_url('admin/commerce/pedidos') . ($qs !== [] ? '?' . http_build_query($qs) : '');
};
$total = array_sum($counts);
?>

<?php \Core\View::start('title'); ?>Pedidos<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('shop.orders')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('order.intro')) ?></p>
    </div>
    <div>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce')) ?>">← <?= e(__('order.products')) ?></a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-order-tabs">
    <a class="pp-order-tab<?= $filters['status'] === '' ? ' is-active' : '' ?>" href="<?= e($filterUrl('')) ?>">
        <?= e(__('inbox.all')) ?> <span class="pp-order-tab__count"><?= (int) $total ?></span>
    </a>
    <?php foreach ($statusLabels as $key => $label): ?>
        <a class="pp-order-tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e($filterUrl($key)) ?>">
            <?= e($label) ?> <span class="pp-order-tab__count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="pp-card pp-booking-filters">
    <form method="get" action="<?= e(base_url('admin/commerce/pedidos')) ?>" class="pp-booking-filters__form">
        <input type="hidden" name="status" value="<?= e((string) $filters['status']) ?>">
        <input type="search" name="q" value="<?= e((string) $filters['q']) ?>" placeholder="<?= e(__('order.search_placeholder')) ?>" class="pp-order-search">
        <select name="method">
            <option value=""><?= e(__('order.any_method')) ?></option>
            <option value="stripe" <?= $filters['method'] === 'stripe' ? 'selected' : '' ?>><?= e(__('order.method.card')) ?></option>
            <option value="manual" <?= $filters['method'] === 'manual' ? 'selected' : '' ?>><?= e(__('order.method.transfer')) ?></option>
        </select>
        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('bank.search')) ?></button>
    </form>
</div>

<?php if ($orders === []): ?>
    <div class="pp-card pp-booking-empty">
        <p><?= $total === 0
            ? __('order.empty')
            : __('order.empty_filtered') ?></p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th><?= e(__('order.order')) ?></th>
                    <th><?= e(__('order.customer')) ?></th>
                    <th><?= e(__('order.total')) ?></th>
                    <th><?= e(__('order.payment')) ?></th>
                    <th><?= e(__('table.status')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): $st = (string) $o['status']; ?>
                <tr>
                    <td>
                        <a href="<?= e(base_url('admin/commerce/pedidos/' . (int) $o['id'])) ?>"><strong><?= e((string) $o['order_number']) ?></strong></a>
                        <br><span class="pp-booking-soft"><?= e((new DateTimeImmutable((string) $o['created_at']))->format('d/m/Y H:i')) ?> · <?= e(__('order.n_items', ['n' => (int) $o['item_count']])) ?><span hidden>artículo<?= (int) $o['item_count'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td>
                        <?= e((string) $o['customer_name']) ?><br>
                        <span class="pp-booking-soft"><?= e((string) $o['customer_email']) ?></span>
                    </td>
                    <td><strong><?= e(CommerceSettings::format((int) $o['total_cents'])) ?></strong></td>
                    <td><span class="pp-booking-soft"><?= e($methodLabels[(string) $o['payment_method']] ?? (string) $o['payment_method']) ?></span></td>
                    <td><span class="pp-status-pill<?= $statusPill[$st] ?? '' ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/commerce/pedidos/' . (int) $o['id'])) ?>"><?= e(__('common.view')) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
