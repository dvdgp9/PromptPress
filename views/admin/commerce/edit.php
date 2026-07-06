<?php
/**
 * Tienda — editor de producto (FEAT-3 C2).
 *
 * @var array    $product           commerce_products + media_path (o draft con errores)
 * @var bool     $pricesIncludeTax
 * @var string[] $errors
 * @var ?string  $notice
 * @var string   $csrf
 */
\Core\View::extend('admin/layout');
use App\Modules\Commerce\CommerceSettings;

$pid = (int) $product['id'];
$priceInput = CommerceSettings::centsToInput((int) ($product['price_cents'] ?? 0));
$mediaId = (int) ($product['media_id'] ?? 0);
$mediaPath = (string) ($product['media_path'] ?? '');
$taxRates = ['21.00' => '21 % (general)', '10.00' => '10 % (reducido)', '4.00' => '4 % (superreducido)', '0.00' => '0 % (exento)'];
$currentTax = number_format((float) ($product['tax_rate'] ?? 21), 2, '.', '');
?>

<?php \Core\View::start('title'); ?>Tienda · <?= e((string) $product['name']) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e((string) $product['name']) ?></h2>
        <p class="pp-page-header__lead"><a href="<?= e(base_url('admin/commerce')) ?>">← Volver a productos</a></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/commerce/products/' . $pid)) ?>" class="pp-form" id="pp-commerce-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Producto</h3><p>Lo que ve el cliente en la tienda.</p></div></div>
        <div class="pp-form-group">
            <label for="pp-cp-name">Nombre</label>
            <input type="text" id="pp-cp-name" name="name" maxlength="160" required value="<?= e((string) $product['name']) ?>">
        </div>
        <div class="pp-form-group">
            <label for="pp-cp-desc">Descripción <span class="pp-ai-optional-tag">opcional</span></label>
            <textarea id="pp-cp-desc" name="description" rows="4" maxlength="8000"><?= e((string) ($product['description'] ?? '')) ?></textarea>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Imagen</h3><p>Se elige de tu biblioteca de medios.</p></div></div>
        <div class="pp-commerce-media" data-media-picker>
            <input type="hidden" name="media_id" value="<?= $mediaId > 0 ? $mediaId : '' ?>" data-media-input>
            <div class="pp-commerce-media__preview<?= $mediaPath !== '' ? '' : ' is-empty' ?>" data-media-preview>
                <?php if ($mediaPath !== ''): ?>
                    <img src="<?= e(base_url(ltrim($mediaPath, '/'))) ?>" alt="">
                <?php endif; ?>
            </div>
            <div class="pp-commerce-media__actions">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-media-open>Elegir imagen</button>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm<?= $mediaPath !== '' ? '' : ' is-hidden' ?>" data-media-clear>Quitar</button>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Precio y stock</h3><p><?= $pricesIncludeTax ? 'Escribe el precio final, con IVA incluido.' : 'Escribe el precio sin IVA; se añadirá en el carrito.' ?></p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-cp-price">Precio <?= $pricesIncludeTax ? '(IVA incl.)' : '(sin IVA)' ?> €</label>
                <input type="text" id="pp-cp-price" name="price" inputmode="decimal" value="<?= e($priceInput) ?>" placeholder="0,00">
            </div>
            <div class="pp-form-group">
                <label for="pp-cp-tax">Tipo de IVA</label>
                <select id="pp-cp-tax" name="tax_rate">
                    <?php foreach ($taxRates as $rate => $label): ?>
                        <option value="<?= e($rate) ?>" <?= $currentTax === $rate ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pp-form-group">
                <label for="pp-cp-stock">Stock <span class="pp-ai-optional-tag">vacío = ilimitado</span></label>
                <input type="number" id="pp-cp-stock" name="stock" min="0" max="1000000" value="<?= $product['stock'] === null ? '' : (int) $product['stock'] ?>" placeholder="Ilimitado">
            </div>
            <div class="pp-form-group">
                <label for="pp-cp-active">Estado</label>
                <select id="pp-cp-active" name="active">
                    <option value="1" <?= (int) $product['active'] === 1 ? 'selected' : '' ?>>Activo (a la venta)</option>
                    <option value="0" <?= (int) $product['active'] === 1 ? '' : 'selected' ?>>Borrador (oculto)</option>
                </select>
            </div>
        </div>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">Guardar producto</button>
    </div>
</form>

<!-- Modal selector de imagen -->
<div class="pp-modal pp-commerce-media-modal" id="pp-commerce-media-modal" role="dialog" aria-modal="true" aria-labelledby="pp-cmm-title" hidden>
    <div class="pp-modal__backdrop" data-media-close></div>
    <div class="pp-modal__dialog">
        <header class="pp-modal__header">
            <h3 id="pp-cmm-title">Elegir imagen</h3>
            <button type="button" class="pp-modal__close" data-media-close aria-label="Cerrar">×</button>
        </header>
        <div class="pp-modal__body">
            <div class="pp-commerce-media-grid" data-media-grid>
                <p class="pp-booking-soft">Cargando…</p>
            </div>
        </div>
        <footer class="pp-modal__footer">
            <p class="pp-booking-soft">¿No ves la imagen? Súbela primero en <a href="<?= e(base_url('admin/media')) ?>" target="_blank" rel="noopener">Medios</a>.</p>
        </footer>
    </div>
</div>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/commerce-product-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/commerce-product-editor.js')) ?>?v=<?= e($jsVer) ?>"
        data-library="<?= e(base_url('admin/media/library')) ?>"></script>
<?php \Core\View::end(); ?>
