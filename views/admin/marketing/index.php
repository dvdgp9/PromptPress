<?php
/**
 * MKT — Panel de Marketing.
 *
 * @var array  $manifest
 * @var array  $trackingCatalog
 * @var array  $trackingCategories
 * @var array  $serviceState
 * @var array  $customSnippets
 * @var array  $customCategories   key => label
 * @var array  $customPlacements   key => label
 * @var bool   $needsBanner
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$catList = $customCategories;
$placeList = $customPlacements;
?>

<?php \Core\View::start('title'); ?>Marketing<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Marketing</h2>
        <p class="pp-page-header__lead">Conecta tus herramientas de medición y publicidad. Cada script se carga solo cuando el visitante acepta su categoría de cookies, así que cumples con el RGPD sin esfuerzo.</p>
    </div>
    <?php if ($needsBanner): ?>
    <span class="pp-status-pill pp-status-pill--green" title="Banner de cookies">Banner activo</span>
    <?php endif; ?>
</div>

<!-- ============ Integraciones de catálogo ============ -->
<section class="pp-privacy-section">
    <header class="pp-privacy-section__head">
        <h3>Integraciones</h3>
        <p>Activa solo las herramientas que uses y pega su identificador. No hace falta tocar código.</p>
    </header>

    <?php
    $cookiesFormAction = base_url('admin/marketing/integrations');
    include __DIR__ . '/../privacy/_tracking_cards.php';
    ?>
</section>

<!-- ============ Código personalizado ============ -->
<section class="pp-privacy-section">
    <header class="pp-privacy-section__head">
        <h3>Código personalizado</h3>
        <p>¿Tu herramienta no está en la lista? Pega aquí cualquier snippet (Pinterest, Hotjar, un chat…). Elige su categoría de consentimiento y dónde inyectarlo.</p>
    </header>

    <div class="pp-privacy-notice pp-privacy-notice--quiet">
        <strong>Bajo tu responsabilidad.</strong> El código se inserta tal cual en tu web, solo tras el consentimiento de la categoría que elijas. Pega únicamente código de proveedores en los que confíes.
    </div>

    <?php if (!empty($customSnippets)): ?>
    <div class="pp-snippet-list">
        <?php foreach ($customSnippets as $snip):
            $sid = (string) ($snip['id'] ?? '');
            $slabel = (string) ($snip['label'] ?? 'Snippet');
            $scat = (string) ($snip['category'] ?? 'analytics');
            $splace = (string) ($snip['placement'] ?? 'body_end');
            $scode = (string) ($snip['code'] ?? '');
            $senabled = !empty($snip['enabled']);
        ?>
        <details class="pp-snippet">
            <summary class="pp-snippet__summary">
                <span class="pp-snippet__name"><?= e($slabel) ?></span>
                <span class="pp-snippet__meta">
                    <?= e($catList[$scat] ?? $scat) ?>
                    <span class="pp-snippet__badge pp-snippet__badge--<?= $senabled ? 'on' : 'off' ?>"><?= $senabled ? 'Activo' : 'Pausado' ?></span>
                </span>
            </summary>
            <form method="POST" action="<?= e(base_url('admin/marketing/custom')) ?>" class="pp-snippet__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="custom_id" value="<?= e($sid) ?>">

                <div class="pp-form-row">
                    <div class="pp-form-group">
                        <label>Nombre</label>
                        <input type="text" name="label" maxlength="120" value="<?= e($slabel) ?>" required>
                    </div>
                    <div class="pp-form-group">
                        <label>Categoría de consentimiento</label>
                        <select name="category">
                            <?php foreach ($catList as $ck => $cl): ?>
                            <option value="<?= e($ck) ?>" <?= $ck === $scat ? 'selected' : '' ?>><?= e($cl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pp-form-group">
                        <label>Ubicación</label>
                        <select name="placement">
                            <?php foreach ($placeList as $pk => $pl): ?>
                            <option value="<?= e($pk) ?>" <?= $pk === $splace ? 'selected' : '' ?>><?= e($pl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pp-form-group">
                    <label>Código</label>
                    <textarea name="code" rows="6" class="pp-snippet__code" spellcheck="false"><?= e($scode) ?></textarea>
                </div>

                <label class="pp-snippet__enable">
                    <input type="checkbox" name="enabled" value="1" <?= $senabled ? 'checked' : '' ?>>
                    Activo (cárgalo en la web pública)
                </label>

                <div class="pp-form-actions pp-snippet__actions">
                    <button type="submit" class="pp-btn pp-btn--primary">Guardar</button>
                </div>
            </form>
            <form method="POST" action="<?= e(base_url('admin/marketing/custom/delete')) ?>"
                  onsubmit="return confirm('¿Eliminar este snippet?');" class="pp-snippet__delete">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="custom_id" value="<?= e($sid) ?>">
                <button type="submit" class="pp-btn pp-btn--danger">Eliminar</button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <details class="pp-snippet pp-snippet--new">
        <summary class="pp-snippet__summary"><span class="pp-snippet__name">+ Añadir snippet</span></summary>
        <form method="POST" action="<?= e(base_url('admin/marketing/custom')) ?>" class="pp-snippet__form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label>Nombre</label>
                    <input type="text" name="label" maxlength="120" placeholder="Pixel de Pinterest" required>
                </div>
                <div class="pp-form-group">
                    <label>Categoría de consentimiento</label>
                    <select name="category">
                        <?php foreach ($catList as $ck => $cl): ?>
                        <option value="<?= e($ck) ?>" <?= $ck === 'advertising' ? 'selected' : '' ?>><?= e($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-form-group">
                    <label>Ubicación</label>
                    <select name="placement">
                        <?php foreach ($placeList as $pk => $pl): ?>
                        <option value="<?= e($pk) ?>" <?= $pk === 'body_end' ? 'selected' : '' ?>><?= e($pl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pp-form-group">
                <label>Código</label>
                <textarea name="code" rows="6" class="pp-snippet__code" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"></textarea>
            </div>

            <label class="pp-snippet__enable">
                <input type="checkbox" name="enabled" value="1" checked>
                Activo (cárgalo en la web pública)
            </label>

            <div class="pp-form-actions pp-snippet__actions">
                <button type="submit" class="pp-btn pp-btn--primary">Añadir snippet</button>
            </div>
        </form>
    </details>
</section>
