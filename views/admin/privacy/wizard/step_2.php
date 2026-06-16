<?php
/** Paso 2 — Cookies y tracking. Reutiliza tab_cookies.php, ocultando el bloque
 *  de textos del banner (eso se queda para edición posterior en pestañas). */
$cookiesFormAction  = base_url('admin/privacy/wizard/step2');
$cookiesSubmitLabel = 'Siguiente: Generar páginas →';
$hideBannerSection  = true;
?>
<div class="pp-wizard__intro">
    <p>Si no usas servicios externos, déjalo todo apagado y avanza. Es una opción válida.</p>
</div>
<?php include __DIR__ . '/../tab_cookies.php'; ?>

<div class="pp-wizard__nav">
    <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/privacy/wizard?step=1')) ?>">← Atrás</a>
</div>
