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
        <strong>El banner de cookies está activo</strong> en tu web pública porque hay servicios de tracking habilitados. Los visitantes verán el banner y los scripts solo cargan tras su consentimiento.
    </div>
    <?php else: ?>
    <div class="pp-privacy-notice pp-privacy-notice--quiet">
        <strong>Tu sitio no necesita banner de cookies todavía.</strong> No tienes servicios de analítica o marketing activos. Cuando actives alguno abajo, el banner aparecerá automáticamente en la web pública.
    </div>
    <?php endif; ?>

    <!-- Integraciones -->
    <?php if ($hideTrackingSection): ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3>Integraciones de tracking</h3>
            <p>Los píxeles y herramientas de medición (Google Analytics, Meta Pixel, etc.) se gestionan ahora desde el panel de Marketing. El banner se enciende solo cuando activas alguno.</p>
        </header>
        <p><a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/marketing')) ?>">Ir a Marketing →</a></p>
    </section>
    <?php else: ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3>Integraciones de tracking</h3>
            <p>Activa solo los servicios que realmente uses. Cada uno cargará su script únicamente cuando el visitante haya aceptado su categoría.</p>
        </header>

        <?php include __DIR__ . '/_tracking_cards.php'; ?>
    </section>
    <?php endif; ?>

    <!-- Banner textos -->
    <?php if (!$hideBannerSection): ?>
    <section class="pp-privacy-section">
        <header class="pp-privacy-section__head">
            <h3>Textos del banner</h3>
            <p>Personaliza lo que verán tus visitantes. Los botones de Aceptar y Rechazar siempre tienen el mismo peso visual (requisito legal).</p>
        </header>

        <form method="POST" action="<?= e(base_url('admin/privacy/banner')) ?>" class="pp-banner-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="pp-form-group">
                <label for="banner_title">Título</label>
                <input type="text" id="banner_title" name="title" maxlength="120"
                       value="<?= e((string) ($banner['title'] ?? '')) ?>"
                       placeholder="Cookies en este sitio">
            </div>

            <div class="pp-form-group">
                <label for="banner_description">Descripción</label>
                <textarea id="banner_description" name="description" rows="3" maxlength="500"
                          placeholder="Usamos cookies necesarias para que la web funcione…"><?= e((string) ($banner['description'] ?? '')) ?></textarea>
            </div>

            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label for="banner_accept">Texto botón Aceptar</label>
                    <input type="text" id="banner_accept" name="accept_label" maxlength="60"
                           value="<?= e((string) ($banner['accept_label'] ?? '')) ?>"
                           placeholder="Aceptar todas">
                </div>
                <div class="pp-form-group">
                    <label for="banner_reject">Texto botón Rechazar</label>
                    <input type="text" id="banner_reject" name="reject_label" maxlength="60"
                           value="<?= e((string) ($banner['reject_label'] ?? '')) ?>"
                           placeholder="Rechazar opcionales">
                </div>
                <div class="pp-form-group">
                    <label for="banner_configure">Texto botón Configurar</label>
                    <input type="text" id="banner_configure" name="configure_label" maxlength="60"
                           value="<?= e((string) ($banner['configure_label'] ?? '')) ?>"
                           placeholder="Configurar">
                </div>
            </div>

            <div class="pp-form-actions">
                <button type="submit" class="pp-btn pp-btn--primary">Guardar textos</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

</div>
