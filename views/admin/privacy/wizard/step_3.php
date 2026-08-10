<?php
/** Paso 3 — Resumen + acción única "Generar las 3 páginas". */
$controller = (array) ($manifest['controller'] ?? []);
$services   = array_values(array_filter(
    (array) ($manifest['tracking']['services'] ?? []),
    fn ($s) => !empty($s['enabled'])
));
$formsCount = count($formsList);
$alreadyGenerated = array_filter($legalPagesState, fn ($p) => $p !== null);

// Tipos aplicables al sitio (3, o 4 si hay tienda). El JS los usa para pintar
// una fila de progreso por página.
$genTypes = [];
foreach ($legalTypes as $typeKey => $typeInfo) {
    $genTypes[] = ['key' => $typeKey, 'label' => $typeInfo['label']];
}
$genCount = count($genTypes);
$allGenerated = count($alreadyGenerated) >= $genCount;
?>

<div class="pp-wizard__intro">
    <h3>Todo listo. La IA va a redactar tus <?= $genCount ?> páginas legales.</h3>
    <p>Usará los datos que has rellenado. Cualquier hueco quedará marcado como <code>TODO-LEGAL:</code> para que lo revises tú.</p>
</div>

<div class="pp-wizard__summary">
    <article class="pp-wizard__summary-card">
        <h4>Datos de tu empresa</h4>
        <p><strong><?= e($controller['legal_name'] ?? '') ?></strong></p>
        <p><?= e($controller['address'] ?? '') ?></p>
        <p><?= e($controller['email'] ?? '') ?> <?php if (!empty($controller['tax_id'])): ?>· <?= e($controller['tax_id']) ?><?php endif; ?></p>
    </article>

    <article class="pp-wizard__summary-card">
        <h4>Cookies y tracking</h4>
        <?php if ($services === []): ?>
            <p>Sin servicios externos activos. No habrá banner de cookies.</p>
        <?php else: ?>
            <p><?= count($services) ?> servicio<?= count($services) === 1 ? '' : 's' ?> activo<?= count($services) === 1 ? '' : 's' ?> — se mostrará el banner de cookies.</p>
            <ul>
            <?php foreach ($services as $s): ?>
                <li><?= e($trackingCatalog[$s['key']]['name'] ?? $s['key']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="pp-wizard__summary-card pp-wizard__summary-card--auto">
        <h4>Formularios <span class="pp-wizard__auto-pill">Automático</span></h4>
        <?php if ($formsCount === 0): ?>
            <p>Aún no tienes formularios. Cuando añadas uno, recibirá <strong>solo</strong> la nota de privacidad bajo los campos, con enlace a tu Política. No hay que volver al asistente.</p>
        <?php else: ?>
            <p><?= $formsCount ?> formulario<?= $formsCount === 1 ? '' : 's' ?> detectado<?= $formsCount === 1 ? '' : 's' ?>. Bajo cada uno aparecerá automáticamente:</p>
            <p class="pp-wizard__summary-quote"><em>"Tus datos se tratarán… Más información en nuestra política de privacidad."</em></p>
        <?php endif; ?>
    </article>
</div>

<form method="POST" action="<?= e(base_url('admin/privacy/wizard/finish')) ?>" class="pp-wizard__finish"
      data-legal-generate="<?= e(json_encode($genTypes, JSON_UNESCAPED_UNICODE)) ?>"
      data-generate-url="<?= e(base_url('admin/privacy/pages/generate')) ?>"
      data-finish-url="<?= e(base_url('admin/privacy/wizard/finish')) ?>"
      data-done-url="<?= e(base_url('admin/privacy/wizard?step=3&done=1')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">
        <?= $allGenerated ? 'Regenerar las ' . $genCount . ' páginas con IA' : 'Generar las ' . $genCount . ' páginas con IA' ?>
    </button>
    <p class="pp-wizard__finish-note">Cada página tarda unos segundos. Verás el progreso aquí mismo y podrás editar cualquier texto después.</p>
</form>

<div class="pp-wizard__nav">
    <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/privacy/wizard?step=2')) ?>">← Atrás</a>
</div>
