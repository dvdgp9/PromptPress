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

<?php \Core\View::start('title'); ?>Tienda<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Tienda</h2>
        <p class="pp-page-header__lead">Gestiona los productos de tu tienda online. Los precios se muestran <?= $pricesIncludeTax ? 'con IVA incluido' : 'sin IVA (se añade en el carrito)' ?>.</p>
    </div>
    <div class="pp-page-header__actions">
        <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/commerce/pedidos')) ?>">Pedidos</a>
        <a class="pp-btn pp-btn--ghost" href="<?= e(base_url('admin/commerce/pagos')) ?>">Métodos de pago</a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-card pp-booking-new">
    <form method="post" action="<?= e(base_url('admin/commerce/products')) ?>" class="pp-booking-new__form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="text" name="name" maxlength="160" required
               placeholder="Ej: Camiseta, Curso online, Entrada…">
        <button type="submit" class="pp-btn pp-btn--primary">+ Nuevo producto</button>
    </form>
</div>

<?php if ($products === []): ?>
    <div class="pp-card pp-booking-empty">
        <p>Todavía no hay productos. Crea el primero con el nombre de lo que vendes y configura su precio e imagen.</p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Producto</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Estado</th>
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
                    <td><?= $p['stock'] === null ? '<span class="pp-booking-soft">Ilimitado</span>' : (int) $p['stock'] ?></td>
                    <td>
                        <?php if ((int) $p['active'] === 1): ?>
                            <span class="pp-status-pill pp-status-pill--green">Activo</span>
                        <?php else: ?>
                            <span class="pp-status-pill">Borrador</span>
                        <?php endif; ?>
                    </td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/commerce/products/' . (int) $p['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(base_url('admin/commerce/products/' . (int) $p['id'] . '/delete')) ?>"
                              class="pp-inline-form"
                              onsubmit="return confirm('¿Eliminar «<?= e((string) $p['name']) ?>»?');">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
