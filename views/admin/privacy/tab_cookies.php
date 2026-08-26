<?php
/**
 * @var array $manifest
 * @var array $trackingCatalog   keyed por service key, con metadatos y config_fields
 * @var array $trackingCategories
 * @var string $csrf
 */

use App\Services\Compliance\TrackingCatalog;

$services = (array) ($manifest['tracking']['services'] ?? []);
$serviceState = [];
foreach ($services as $s) {
    if (isset($s['key'])) $serviceState[$s['key']] = $s;
}

$banner = (array) ($manifest['banner'] ?? []);
$needsBanner = TrackingCatalog::needsBanner($manifest);
$activeCategories = TrackingCatalog::activeCategories($manifest);

$cookiesFormAction  = $cookiesFormAction  ?? base_url('admin/privacy/cookies');
$cookiesSubmitLabel = $cookiesSubmitLabel ?? 'Guardar cambios';
$hideCookiesSubmit  = $hideCookiesSubmit  ?? false;
$hideBannerSection  = $hideBannerSection  ?? false;
// El panel de Privacidad standalone delega las integraciones a Marketing; el
// wizard de onboarding (que NO marca esto) sigue mostrando las tarjetas inline.
$hideTrackingSection = $hideTrackingSection ?? false;
?>

<div class="pp-privacy-cookies">

    <!-- Banner status indicator -->
    <?php if ($needsBanner): ?>
    <div class="pp-privacy-notice pp-privacy-notice--info">
        <?= __('privacy.cookies.banner_on.html') ?></div>
    <?php else: ?>
    <div class="pp-privacy-notice pp-privacy-notice--quiet">
        <?= __('privacy.cookies.banner_off.html') ?></div>
    <?php endif; ?>

    <!-- Integraciones -->
    <?php if ($hideTrackingSection): ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3><?= e(__('privacy.cookies.tracking')) ?></h3>
            <p><?= e(__('privacy.cookies.moved_to_marketing')) ?></p>
        </header>
        <p><a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/marketing')) ?>"><?= e(__('privacy.cookies.go_marketing')) ?> →</a></p>
    </section>
    <?php else: ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3><?= e(__('privacy.cookies.tracking')) ?></h3>
            <p><?= e(__('privacy.cookies.tracking_help')) ?></p>
        </header>

        <?php include __DIR__ . '/_tracking_cards.php'; ?>
    </section>
    <?php endif; ?>

    <!-- Banner textos -->
    <?php if (!$hideBannerSection): ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3><?= e(__('privacy.cookies.banner_texts')) ?></h3>
            <p><?= e(__('privacy.cookies.banner_texts_help')) ?></p>
        </header>

        <form method="POST" action="<?= e(base_url('admin/privacy/banner')) ?>" class="pp-banner-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="pp-form-group">
                <label for="banner_title"><?= e(__('table.title')) ?></label>
                <input type="text" id="banner_title" name="title" maxlength="120"
                       value="<?= e((string) ($banner['title'] ?? '')) ?>"
                       placeholder="<?= e(__('privacy.cookies.title_placeholder')) ?>">
            </div>

            <div class="pp-form-group">
                <label for="banner_description"><?= e(__('form_edit.description')) ?></label>
                <textarea id="banner_description" name="description" rows="3" maxlength="500"
                          placeholder="<?= e(__('priv.banner_desc_ph')) ?>"><?= e((string) ($banner['description'] ?? '')) ?></textarea>
            </div>

            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label for="banner_accept"><?= e(__('privacy.cookies.accept_label')) ?></label>
                    <input type="text" id="banner_accept" name="accept_label" maxlength="60"
                           value="<?= e((string) ($banner['accept_label'] ?? '')) ?>"
                           placeholder="<?= e(__('privacy.cookies.accept_placeholder')) ?>">
                </div>
                <div class="pp-form-group">
                    <label for="banner_reject"><?= e(__('privacy.cookies.reject_label')) ?></label>
                    <input type="text" id="banner_reject" name="reject_label" maxlength="60"
                           value="<?= e((string) ($banner['reject_label'] ?? '')) ?>"
                           placeholder="<?= e(__('privacy.cookies.reject_placeholder')) ?>">
                </div>
                <div class="pp-form-group">
                    <label for="banner_configure"><?= e(__('privacy.cookies.configure_label')) ?></label>
                    <input type="text" id="banner_configure" name="configure_label" maxlength="60"
                           value="<?= e((string) ($banner['configure_label'] ?? '')) ?>"
                           placeholder="<?= e(__('privacy.cookies.configure_placeholder')) ?>">
                </div>
            </div>

            <div class="pp-form-actions">
                <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('privacy.cookies.save_texts')) ?></button>
            </div>
        </form>
    </section>
    <?php endif; ?>

</div>
