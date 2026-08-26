<?php
/**
 * Módulos — activación por sitio (FEAT-3, F0.1).
 *
 * @var array  $modules  lista de {key,label,description,available,enabled}
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.modules')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('nav.modules')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('modules.intro')) ?></p>
    </div>
</div>

<div class="pp-modules-grid">
    <?php foreach ($modules as $mod): ?>
    <div class="pp-card pp-module-card<?= $mod['enabled'] ? ' is-enabled' : '' ?>">
        <div class="pp-module-card__head">
            <h3 class="pp-module-card__title"><?= e($mod['label']) ?></h3>
            <?php if (!$mod['available']): ?>
                <span class="pp-status-pill"><?= e(__('modules.soon')) ?></span>
            <?php elseif ($mod['enabled']): ?>
                <span class="pp-status-pill pp-status-pill--green"><?= e(__('modules.active')) ?></span>
            <?php else: ?>
                <span class="pp-status-pill"><?= e(__('modules.inactive')) ?></span>
            <?php endif; ?>
        </div>
        <p class="pp-module-card__desc"><?= e($mod['description']) ?></p>

        <?php if ($mod['available']): ?>
        <form method="post" action="<?= e(base_url('admin/modules/toggle')) ?>" class="pp-module-card__form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="module" value="<?= e($mod['key']) ?>">
            <input type="hidden" name="enabled" value="<?= $mod['enabled'] ? '0' : '1' ?>">
            <button type="submit" class="pp-btn <?= $mod['enabled'] ? 'pp-btn--ghost' : 'pp-btn--primary' ?>">
                <?= e($mod['enabled'] ? __('modules.disable') : __('modules.enable')) ?>
            </button>
        </form>
        <?php else: ?>
        <button type="button" class="pp-btn pp-btn--ghost" disabled><?= e(__('modules.unavailable')) ?></button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
