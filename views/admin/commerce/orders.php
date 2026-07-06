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
    'pending_payment' => 'Pendiente de pago',
    'paid'            => 'Pagado',
    'shipped'         => 'Enviado',
    'cancelled'       => 'Cancelado',
];
$statusPill = [
    'pending_payment' => ' pp-status-pill--yellow',
    'paid'            => ' pp-status-pill--green',
    'shipped'         => ' pp-status-pill--green',
    'cancelled'       => ' pp-status-pill--muted',
];
$methodLabels = ['stripe' => 'Tarjeta', 'manual' => 'Transferencia'];

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
        <h2>Pedidos</h2>
        <p class="pp-page-header__lead">Todos los pedidos de tu tienda. Marca cuándo recibes el pago y cuándo envías; avisamos al cliente por email en cada paso.</p>
    </div>
    <div>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce')) ?>">← Productos</a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-order-tabs">
    <a class="pp-order-tab<?= $filters['status'] === '' ? ' is-active' : '' ?>" href="<?= e($filterUrl('')) ?>">
        Todos <span class="pp-order-tab__count"><?= (int) $total ?></span>
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
        <input type="search" name="q" value="<?= e((string) $filters['q']) ?>" placeholder="Buscar nº de pedido, nombre o email…" class="pp-order-search">
        <select name="method">
            <option value="">Cualquier método de pago</option>
            <option value="stripe" <?= $filters['method'] === 'stripe' ? 'selected' : '' ?>>Tarjeta</option>
            <option value="manual" <?= $filters['method'] === 'manual' ? 'selected' : '' ?>>Transferencia</option>
        </select>
        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Buscar</button>
    </form>
</div>

<?php if ($orders === []): ?>
    <div class="pp-card pp-booking-empty">
        <p><?= $total === 0
            ? 'Todavía no has recibido ningún pedido. Cuando alguien compre en tu tienda, aparecerá aquí.'
            : 'No hay pedidos con estos filtros. Prueba a quitar la búsqueda o cambiar el estado.' ?></p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Cliente</th>
                    <th>Total</th>
                    <th>Pago</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): $st = (string) $o['status']; ?>
                <tr>
                    <td>
                        <a href="<?= e(base_url('admin/commerce/pedidos/' . (int) $o['id'])) ?>"><strong><?= e((string) $o['order_number']) ?></strong></a>
                        <br><span class="pp-booking-soft"><?= e((new DateTimeImmutable((string) $o['created_at']))->format('d/m/Y H:i')) ?> · <?= (int) $o['item_count'] ?> artículo<?= (int) $o['item_count'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td>
                        <?= e((string) $o['customer_name']) ?><br>
                        <span class="pp-booking-soft"><?= e((string) $o['customer_email']) ?></span>
                    </td>
                    <td><strong><?= e(CommerceSettings::format((int) $o['total_cents'])) ?></strong></td>
                    <td><span class="pp-booking-soft"><?= e($methodLabels[(string) $o['payment_method']] ?? (string) $o['payment_method']) ?></span></td>
                    <td><span class="pp-status-pill<?= $statusPill[$st] ?? '' ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/commerce/pedidos/' . (int) $o['id'])) ?>">Ver</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
