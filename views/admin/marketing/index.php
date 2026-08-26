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

<?php \Core\View::start('title'); ?><?= e(__('nav.marketing')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('nav.marketing')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('marketing.intro')) ?></p>
    </div>
    <?php if ($needsBanner): ?>
    <span class="pp-status-pill pp-status-pill--green" title="<?= e(__('marketing.cookie_banner')) ?>"><?= e(__('marketing.banner_active')) ?></span>
    <?php endif; ?>
</div>

<!-- ============ Integraciones de catálogo ============ -->
<section class="pp-privacy-section">
    <header class="pp-privacy-section__head">
        <h3><?= e(__('marketing.integrations')) ?></h3>
        <p><?= e(__('marketing.integrations_help')) ?></p>
    </header>

    <?php
    $cookiesFormAction = base_url('admin/marketing/integrations');
    include __DIR__ . '/../privacy/_tracking_cards.php';
    ?>
</section>

<!-- ============ Código personalizado ============ -->
<section class="pp-privacy-section">
    <header class="pp-privacy-section__head">
        <h3><?= e(__('marketing.custom_code')) ?></h3>
        <p><?= e(__('marketing.custom_code_help')) ?></p>
    </header>

    <div class="pp-privacy-notice pp-privacy-notice--quiet">
        <strong><?= e(__('marketing.your_responsibility')) ?></strong> <?= e(__('marketing.responsibility_help')) ?>
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
                    <span class="pp-snippet__badge pp-snippet__badge--<?= $senabled ? 'on' : 'off' ?>"><?= e($senabled ? __('modules.active') : __('marketing.paused')) ?></span>
                </span>
            </summary>
            <form method="POST" action="<?= e(base_url('admin/marketing/custom')) ?>" class="pp-snippet__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="custom_id" value="<?= e($sid) ?>">

                <div class="pp-form-row">
                    <div class="pp-form-group">
                        <label><?= e(__('onboarding.type.font_name')) ?></label>
                        <input type="text" name="label" maxlength="120" value="<?= e($slabel) ?>" required>
                    </div>
                    <div class="pp-form-group">
                        <label><?= e(__('marketing.consent_category')) ?></label>
                        <select name="category">
                            <?php foreach ($catList as $ck => $cl): ?>
                            <option value="<?= e($ck) ?>" <?= $ck === $scat ? 'selected' : '' ?>><?= e($cl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pp-form-group">
                        <label><?= e(__('marketing.placement')) ?></label>
                        <select name="placement">
                            <?php foreach ($placeList as $pk => $pl): ?>
                            <option value="<?= e($pk) ?>" <?= $pk === $splace ? 'selected' : '' ?>><?= e($pl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pp-form-group">
                    <label><?= e(__('marketing.code')) ?></label>
                    <textarea name="code" rows="6" class="pp-snippet__code" spellcheck="false"><?= e($scode) ?></textarea>
                </div>

                <label class="pp-snippet__enable">
                    <input type="checkbox" name="enabled" value="1" <?= $senabled ? 'checked' : '' ?>>
                    <?= e(__('marketing.active_load')) ?>
                </label>

                <div class="pp-form-actions pp-snippet__actions">
                    <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('common.save')) ?></button>
                </div>
            </form>
            <form method="POST" action="<?= e(base_url('admin/marketing/custom/delete')) ?>"
                  onsubmit="return confirm(<?= e(json_encode(__('marketing.confirm_delete_snippet'), JSON_UNESCAPED_UNICODE)) ?>);" class="pp-snippet__delete">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="custom_id" value="<?= e($sid) ?>">
                <button type="submit" class="pp-btn pp-btn--danger"><?= e(__('common.delete')) ?></button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <details class="pp-snippet pp-snippet--new">
        <summary class="pp-snippet__summary"><span class="pp-snippet__name">+ <?= e(__('marketing.add_snippet')) ?></span></summary>
        <form method="POST" action="<?= e(base_url('admin/marketing/custom')) ?>" class="pp-snippet__form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label><?= e(__('onboarding.type.font_name')) ?></label>
                    <input type="text" name="label" maxlength="120" placeholder="<?= e(__('marketing.snippet_placeholder')) ?>" required>
                </div>
                <div class="pp-form-group">
                    <label><?= e(__('marketing.consent_category')) ?></label>
                    <select name="category">
                        <?php foreach ($catList as $ck => $cl): ?>
                        <option value="<?= e($ck) ?>" <?= $ck === 'advertising' ? 'selected' : '' ?>><?= e($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-form-group">
                    <label><?= e(__('marketing.placement')) ?></label>
                    <select name="placement">
                        <?php foreach ($placeList as $pk => $pl): ?>
                        <option value="<?= e($pk) ?>" <?= $pk === 'body_end' ? 'selected' : '' ?>><?= e($pl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pp-form-group">
                <label><?= e(__('marketing.code')) ?></label>
                <textarea name="code" rows="6" class="pp-snippet__code" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"></textarea>
            </div>

            <label class="pp-snippet__enable">
                <input type="checkbox" name="enabled" value="1" checked>
                <?= e(__('marketing.active_load')) ?>
            </label>

            <div class="pp-form-actions pp-snippet__actions">
                <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('marketing.add_snippet')) ?></button>
            </div>
        </form>
    </details>
</section>
