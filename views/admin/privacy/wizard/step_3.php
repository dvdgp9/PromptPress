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
    <h3><?= e(__('privacy.wizard.ready', ['n' => $genCount])) ?></h3>
    <p><?= __('privacy.wizard.ready_help.html') ?></p>
</div>

<div class="pp-wizard__summary">
    <article class="pp-wizard__summary-card">
        <h4><?= e(__('privacy.tab_legal')) ?></h4>
        <p><strong><?= e($controller['legal_name'] ?? '') ?></strong></p>
        <p><?= e($controller['address'] ?? '') ?></p>
        <p><?= e($controller['email'] ?? '') ?> <?php if (!empty($controller['tax_id'])): ?>· <?= e($controller['tax_id']) ?><?php endif; ?></p>
    </article>

    <article class="pp-wizard__summary-card">
        <h4><?= e(__('privacy.wizard.step2')) ?></h4>
        <?php if ($services === []): ?>
            <p><?= e(__('privacy.wizard.no_services')) ?></p>
        <?php else: ?>
            <p><?= e(__(count($services) === 1 ? 'privacy.wizard.n_services_one' : 'privacy.wizard.n_services_other', ['n' => count($services)])) ?></p>
            <ul>
            <?php foreach ($services as $s): ?>
                <li><?= e($trackingCatalog[$s['key']]['name'] ?? $s['key']) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="pp-wizard__summary-card pp-wizard__summary-card--auto">
        <h4><?= e(__('forms.title')) ?> <span class="pp-wizard__auto-pill"><?= e(__('privacy.wizard.automatic')) ?></span></h4>
        <?php if ($formsCount === 0): ?>
            <p><?= __('privacy.wizard.no_forms.html') ?></p>
        <?php else: ?>
            <p><?= e(__($formsCount === 1 ? 'privacy.wizard.n_forms_one' : 'privacy.wizard.n_forms_other', ['n' => $formsCount])) ?></p>
            <p class="pp-wizard__summary-quote"><em><?= e(__('privacy.wizard.form_note_example')) ?></em></p>
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
        <?= e($allGenerated ? __('privacy.wizard.regenerate_n', ['n' => $genCount]) : __('privacy.wizard.generate_n', ['n' => $genCount])) ?>
    </button>
    <p class="pp-wizard__finish-note"><?= e(__('privacy.wizard.finish_note')) ?></p>
</form>

<div class="pp-wizard__nav">
    <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/privacy/wizard?step=2')) ?>">← <?= e(__('common.back')) ?></a>
</div>
