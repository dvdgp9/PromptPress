<?php
/**
 * @var array  $issues        lista plana de problemas
 * @var array  $byPage        problemas agrupados por página
 * @var array  $sectionTypes  mapa tipo => etiqueta
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('links.title_short')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('links.title')) ?></h2>
</div>

<p class="pp-page-intro">
    <?= e(__('links.intro')) ?>
</p>

<?php if (empty($issues)): ?>
    <div class="pp-links-ok">
        <div class="pp-links-ok__icon" aria-hidden="true">✓</div>
        <div>
            <p class="pp-links-ok__title"><?= e(__('links.all_ok')) ?></p>
            <p class="pp-links-ok__hint"><?= e(__('links.no_broken')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="pp-alert pp-alert--warning">
        <?= e(__(count($issues) === 1 ? 'links.count_one' : 'links.count_other', ['n' => count($issues)])) ?>
    </div>

    <?php foreach ($byPage as $pageId => $group): ?>
    <div class="pp-form-card">
        <div class="pp-links-group__head">
            <h3><?= e($group['title']) ?></h3>
            <a href="<?= e(base_url('admin/pages/' . (int) $pageId . '/edit')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('links.edit_page')) ?> →</a>
        </div>
        <ul class="pp-links-list">
            <?php foreach ($group['issues'] as $i): ?>
            <li class="pp-links-item">
                <code class="pp-links-item__link"><?= e($i['link']) ?></code>
                <span class="pp-links-item__where"><?= e(__('links.in_section', ['seccion' => $sectionTypes[$i['section_type']] ?? $i['section_type']])) ?></span>
                <?php if ($i['problem'] === 'missing'): ?>
                    <span class="pp-links-badge pp-links-badge--missing"><?= e(__('links.missing')) ?></span>
                <?php else: ?>
                    <span class="pp-links-badge pp-links-badge--draft"><?= e(__('links.draft')) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>

    <p class="pp-form-hint">
        <?= __('links.how_to_fix.html') ?>
    </p>
<?php endif; ?>
