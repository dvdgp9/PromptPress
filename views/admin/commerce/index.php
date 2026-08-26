<?php
/**
 * Tienda — listado de productos (FEAT-3 C2).
 *
 * @var array   $products          filas de commerce_products + media_path
 * @var bool    $pricesIncludeTax
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');
use App\Modules\Commerce\CommerceSettings;
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.shop')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('nav.shop')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('shop.intro')) ?> <?= e($pricesIncludeTax ? __('shop.tax_included') : __('shop.tax_excluded')) ?></p>
    </div>
    <div class="pp-page-header__actions">
        <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/commerce/pedidos')) ?>"><?= e(__('shop.orders')) ?></a>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce/pagos')) ?>"><?= e(__('shop.payment_methods')) ?></a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-card pp-booking-new">
    <form method="post" action="<?= e(base_url('admin/commerce/products')) ?>" class="pp-booking-new__form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="text" name="name" maxlength="160" required
               placeholder="<?= e(__('shop.new_placeholder')) ?>">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('shop.new_product')) ?></button>
    </form>
</div>

<?php if ($products === []): ?>
    <div class="pp-card pp-booking-empty">
        <p><?= e(__('shop.empty')) ?></p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th></th>
                    <th><?= e(__('shop.product')) ?></th>
                    <th><?= e(__('shop.price')) ?></th>
                    <th><?= e(__('shop.stock')) ?></th>
                    <th><?= e(__('table.status')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td class="pp-commerce-thumb-cell">
                        <?php if (!empty($p['media_path'])): ?>
                            <img class="pp-commerce-thumb" src="<?= e(base_url(ltrim((string) $p['media_path'], '/'))) ?>" alt="">
                        <?php else: ?>
                            <span class="pp-commerce-thumb pp-commerce-thumb--empty" aria-hidden="true"></span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?= e(base_url('admin/commerce/products/' . (int) $p['id'])) ?>"><strong><?= e((string) $p['name']) ?></strong></a></td>
                    <td><?= e(CommerceSettings::format((int) $p['price_cents'])) ?></td>
                    <td><?= $p['stock'] === null ? '<span class="pp-booking-soft">' . e(__('shop.unlimited')) . '</span>' : (int) $p['stock'] ?></td>
                    <td>
                        <?php if ((int) $p['active'] === 1): ?>
                            <span class="pp-status-pill pp-status-pill--green"><?= e(__('modules.active')) ?></span>
                        <?php else: ?>
                            <span class="pp-status-pill"><?= e(__('status.draft')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/commerce/products/' . (int) $p['id'])) ?>"><?= e(__('common.edit')) ?></a>
                        <form method="post" action="<?= e(base_url('admin/commerce/products/' . (int) $p['id'] . '/delete')) ?>"
                              class="pp-inline-form"
                              onsubmit="return confirm(<?= e(json_encode(__('shop.confirm_delete', ['nombre' => (string) $p['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text"><?= e(__('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
