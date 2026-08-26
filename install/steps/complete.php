<?php
/**
 * Paso 5: Finalización del wizard.
 *
 * Solo se llega aquí si los pasos previos se completaron y `install/.installed` existe.
 */

use Core\Session;

// Si por alguna razón llegamos aquí sin la flag, redirigir al paso anterior
if (!is_file(PP_INSTALLED_FLAG)) {
    \Core\Response::redirect(InstallerApp::stepUrl('ai_provider'));
}

$warning = (string) (Session::get('install_warning') ?? '');
Session::set('install_warning', null);

$adminUrl = base_url('admin/');

ob_start();
?>
<div class="pp-complete">
    <div class="pp-complete__icon">✓</div>
    <h1 class="pp-step-title"><?= e(__('inst.fin.title')) ?></h1>
    <p class="pp-step-intro">
        <?= e(__('inst.fin.intro')) ?>
    </p>

    <?php if ($warning !== ''): ?>
        <div class="pp-alert pp-alert--warn">
            <strong><?= e(__('inst.fin.warning')) ?>:</strong> <?= e($warning) ?>
        </div>
    <?php endif; ?>

    <div class="pp-alert pp-alert--info">
        <strong><?= e(__('inst.fin.next_steps')) ?></strong>
        <ul style="margin: 0.5rem 0 0 1.25rem;">
            <li><?= __('inst.fin.next1.html') ?></li>
            <li><?= e(__('inst.fin.next2')) ?></li>
            <li><?= e(__('inst.fin.next3')) ?></li>
        </ul>
    </div>

    <div class="pp-form__actions" style="justify-content: center;">
        <a href="<?= e($adminUrl) ?>" class="pp-btn pp-btn--primary pp-btn--lg">
            <?= e(__('inst.fin.go_panel')) ?> →
        </a>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('complete', __('inst.fin.step_title'), $content);
