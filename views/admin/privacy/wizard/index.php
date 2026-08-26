<?php
/**
 * Wizard de Privacidad — vista principal con stepper + slot del paso actual.
 *
 * @var int   $wizardStep         paso pedido por URL (?step=) o calculado
 * @var int   $wizardTotalSteps   4
 * @var int   $wizardCurrentStep  paso real según estado del manifest
 * @var bool  $wizardDone         si venimos de /finish con éxito
 * @var array $manifest
 * @var array $legalInput, $legalErrors
 * @var array $legalPagesState, $legalTypes
 * @var array $trackingCatalog, $trackingCategories
 * @var array $formsList
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$steps = [
    1 => [__('privacy.tab_legal'),        __('privacy.wizard.step1_help')],
    2 => [__('privacy.wizard.step2'),     __('privacy.wizard.step2_help')],
    3 => [__('privacy.wizard.step3'),     __('privacy.wizard.step3_help')],
];

// El paso solicitado nunca puede ser mayor que el "current" real (sino el
// usuario salta pasos sin haberlos validado). Lo capamos suavemente.
$activeStep = min($wizardStep, $wizardCurrentStep);
if ($wizardDone) $activeStep = 3;
?>

<?php \Core\View::start('title'); ?><?= e(__('privacy.wizard.title')) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/privacy-generate.js')) ?>?v=<?= @filemtime(PP_ROOT . '/admin/assets/js/privacy-generate.js') ?: '1' ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('privacy.wizard.title')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('privacy.wizard.intro')) ?></p>
    </div>
    <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/privacy?tab=summary')) ?>"><?= e(__('privacy.wizard.skip')) ?></a>
</div>

<nav class="pp-wizard__steps" aria-label="Pasos del asistente">
    <?php foreach ($steps as $n => $info):
        $isActive    = $n === $activeStep;
        $isCompleted = $n < $wizardCurrentStep || ($wizardDone && $n <= 4);
        $isClickable = $n <= $wizardCurrentStep;
        $cls = 'pp-wizard__step';
        if ($isActive)    $cls .= ' is-active';
        if ($isCompleted && !$isActive) $cls .= ' is-completed';
        if (!$isClickable) $cls .= ' is-locked';
    ?>
    <?php if ($isClickable && !$isActive): ?>
    <a href="<?= e(base_url('admin/privacy/wizard?step=' . $n)) ?>" class="<?= e($cls) ?>">
    <?php else: ?>
    <div class="<?= e($cls) ?>" aria-current="<?= $isActive ? 'step' : 'false' ?>">
    <?php endif; ?>
        <span class="pp-wizard__step-num"><?= $isCompleted && !$isActive ? '✓' : $n ?></span>
        <span class="pp-wizard__step-text">
            <strong><?= e($info[0]) ?></strong>
            <small><?= e($info[1]) ?></small>
        </span>
    <?php if ($isClickable && !$isActive): ?></a><?php else: ?></div><?php endif; ?>
    <?php endforeach; ?>
</nav>

<div class="pp-wizard__body">
    <?php
    if ($wizardDone) {
        include __DIR__ . '/step_3_done.php';
    } else {
        switch ($activeStep) {
            case 1: include __DIR__ . '/step_1.php'; break;
            case 2: include __DIR__ . '/step_2.php'; break;
            case 3: include __DIR__ . '/step_3.php'; break;
        }
    }
    ?>
</div>
