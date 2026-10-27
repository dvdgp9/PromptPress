<?php
/**
 * Tienda — editor de producto (FEAT-3 C2).
 *
 * @var array    $product           commerce_products + media_path (o draft con errores)
 * @var array    $resources         recursos con archivo que puede entregar (EBOOK-PAGO)
 * @var bool     $resourcesEnabled
 * @var ?array   $linkedResource    el recurso elegido, para avisar si sigue siendo gratis
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
// Las claves son tipos impositivos guardados; solo la etiqueta se traduce.
$taxRates = ['21.00' => __('shop.tax.general'), '10.00' => __('shop.tax.reduced'), '4.00' => __('shop.tax.super_reduced'), '0.00' => __('shop.tax.exempt')];
$currentTax = number_format((float) ($product['tax_rate'] ?? 21), 2, '.', '');
$productType = (string) ($product['product_type'] ?? 'physical') === 'digital' ? 'digital' : 'physical';
$resourceId = (int) ($product['resource_id'] ?? 0);
// Se puede elegir «Digital» si hay módulo Recursos, o si el producto ya lo era.
$digitalAvailable = $resourcesEnabled || $productType === 'digital';
?>

<?php \Core\View::start('title'); ?>Tienda · <?= e((string) $product['name']) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e((string) $product['name']) ?></h2>
        <p class="pp-page-header__lead"><a href="<?= e(base_url('admin/commerce')) ?>">← <?= e(__('shop.back_to_products')) ?></a></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($productType === 'digital' && $linkedResource !== null && (string) $linkedResource['access_mode'] !== 'sale' && (string) $linkedResource['status'] === 'published'): ?>
    <div class="pp-alert pp-alert--warning">
        <?= e(__('shop.digital_free_warning', ['recurso' => (string) $linkedResource['title']])) ?>
        <a href="<?= e(base_url('admin/resources/' . (int) $linkedResource['id'])) ?>"><?= e(__('shop.digital_open_resource')) ?></a>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(base_url('admin/commerce/products/' . $pid)) ?>" class="pp-form" id="pp-commerce-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('shop.product')) ?></h3><p><?= e(__('shop.product_help')) ?></p></div></div>
        <div class="pp-form-group">
            <label for="pp-cp-name"><?= e(__('onboarding.type.font_name')) ?></label>
            <input type="text" id="pp-cp-name" name="name" maxlength="160" required value="<?= e((string) $product['name']) ?>">
        </div>
        <div class="pp-form-group">
            <label for="pp-cp-desc"><?= e(__('form_edit.description')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
            <textarea id="pp-cp-desc" name="description" rows="4" maxlength="8000"><?= e((string) ($product['description'] ?? '')) ?></textarea>
        </div>
    </section>

    <?php /* EBOOK-PAGO — físico (se envía) o digital (entrega un recurso). */ ?>
    <section class="pp-ai-config-panel" data-product-type>
        <div class="pp-ai-section-head"><div><h3><?= e(__('shop.type_title')) ?></h3><p><?= e(__('shop.type_help')) ?></p></div></div>
        <div class="pp-resource-choice-grid">
            <label class="pp-resource-choice<?= $productType === 'physical' ? ' is-selected' : '' ?>">
                <input type="radio" name="product_type" value="physical" <?= $productType === 'physical' ? 'checked' : '' ?>>
                <span><strong><?= e(__('shop.type.physical')) ?></strong><small><?= e(__('shop.type.physical_help')) ?></small></span>
            </label>
            <label class="pp-resource-choice<?= $productType === 'digital' ? ' is-selected' : '' ?><?= $digitalAvailable ? '' : ' is-disabled' ?>">
                <input type="radio" name="product_type" value="digital" <?= $productType === 'digital' ? 'checked' : '' ?> <?= $digitalAvailable ? '' : 'disabled' ?>>
                <span><strong><?= e(__('shop.type.digital')) ?></strong><small><?= e($digitalAvailable ? __('shop.type.digital_help') : __('shop.digital_needs_resources')) ?></small></span>
            </label>
        </div>
        <div class="pp-form-group pp-commerce-digital" data-digital-settings <?= $productType === 'digital' ? '' : 'hidden' ?>>
            <label for="pp-cp-resource"><?= e(__('shop.digital_resource')) ?></label>
            <select id="pp-cp-resource" name="resource_id">
                <option value=""><?= e(__('shop.digital_resource_placeholder')) ?></option>
                <?php foreach ($resources as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $resourceId === (int) $r['id'] ? 'selected' : '' ?>>
                        <?= e((string) $r['title']) ?> · <?= e(strtoupper(pathinfo((string) $r['original_filename'], PATHINFO_EXTENSION))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e($resources === [] ? __('shop.digital_no_resources') : __('shop.digital_resource_help')) ?></small>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('js.post_editor.image')) ?></h3><p><?= e(__('shop.image_help')) ?></p></div></div>
        <div class="pp-commerce-media" data-media-picker>
            <input type="hidden" name="media_id" value="<?= $mediaId > 0 ? $mediaId : '' ?>" data-media-input>
            <div class="pp-commerce-media__preview<?= $mediaPath !== '' ? '' : ' is-empty' ?>" data-media-preview>
                <?php if ($mediaPath !== ''): ?>
                    <img src="<?= e(base_url(ltrim($mediaPath, '/'))) ?>" alt="">
                <?php endif; ?>
            </div>
            <div class="pp-commerce-media__actions">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-media-open><?= e(__('cv.pick_image')) ?></button>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm<?= $mediaPath !== '' ? '' : ' is-hidden' ?>" data-media-clear><?= e(__('post_meta.remove')) ?></button>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('shop.price_stock')) ?></h3><p><?= e($pricesIncludeTax ? __('shop.price_help_inc') : __('shop.price_help_exc')) ?></p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-cp-price"><?= e(__('shop.price')) ?> <?= e($pricesIncludeTax ? __('shop.tax_inc_short') : __('shop.tax_exc_short')) ?> €</label>
                <input type="text" id="pp-cp-price" name="price" inputmode="decimal" value="<?= e($priceInput) ?>" placeholder="0,00">
            </div>
            <div class="pp-form-group">
                <label for="pp-cp-tax"><?= e(__('shop.tax_rate')) ?></label>
                <select id="pp-cp-tax" name="tax_rate">
                    <?php foreach ($taxRates as $rate => $label): ?>
                        <option value="<?= e($rate) ?>" <?= $currentTax === $rate ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pp-form-group" data-physical-only <?= $productType === 'digital' ? 'hidden' : '' ?>>
                <label for="pp-cp-stock"><?= e(__('shop.stock')) ?> <span class="pp-ai-optional-tag"><?= e(__('shop.stock_hint')) ?></span></label>
                <input type="number" id="pp-cp-stock" name="stock" min="0" max="1000000" value="<?= $product['stock'] === null ? '' : (int) $product['stock'] ?>" placeholder="Ilimitado">
            </div>
            <div class="pp-form-group">
                <label for="pp-cp-active"><?= e(__('table.status')) ?></label>
                <select id="pp-cp-active" name="active">
                    <option value="1" <?= (int) $product['active'] === 1 ? 'selected' : '' ?>><?= e(__('shop.active_on_sale')) ?></option>
                    <option value="0" <?= (int) $product['active'] === 1 ? '' : 'selected' ?>><?= e(__('shop.draft_hidden')) ?></option>
                </select>
            </div>
        </div>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('shop.save_product')) ?></button>
    </div>
</form>

<!-- Modal selector de imagen -->
<div class="pp-modal pp-commerce-media-modal" id="pp-commerce-media-modal" role="dialog" aria-modal="true" aria-labelledby="pp-cmm-title" hidden>
    <div class="pp-modal__backdrop" data-media-close></div>
    <div class="pp-modal__dialog">
        <header class="pp-modal__header">
            <h3 id="pp-cmm-title"><?= e(__('cv.pick_image')) ?></h3>
            <button type="button" class="pp-modal__close" data-media-close aria-label="<?= e(__('common.close')) ?>">×</button>
        </header>
        <div class="pp-modal__body">
            <div class="pp-commerce-media-grid" data-media-grid>
                <p class="pp-booking-soft"><?= e(__('cv.loading')) ?></p>
            </div>
        </div>
        <footer class="pp-modal__footer">
            <p class="pp-booking-soft"><?= e(__('shop.image_missing')) ?> <a href="<?= e(base_url('admin/media')) ?>" target="_blank" rel="noopener"><?= e(__('nav.media')) ?></a>.</p>
        </footer>
    </div>
</div>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/commerce-product-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/commerce-product-editor.js')) ?>?v=<?= e($jsVer) ?>"
        data-library="<?= e(base_url('admin/media/library')) ?>"></script>
<?php \Core\View::end(); ?>
