<?php
/**
 * @var array   $forms     [{id,heading,field_count,updated_at}]
 * @var array   $usage     formId => nº páginas donde se usa
 * @var array   $templates key => [label, description, content]
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
$templates = $templates ?? [];
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('forms.title')) ?><?php \Core\View::end(); ?>

<div class="pp-forms-wrap">
<div class="pp-page-header">
    <div>
        <h2><?= e(__('forms.title')) ?></h2>
        <p class="pp-page-intro">
            <?= __('forms.intro.html', ['enlace' => '<a href="' . e(base_url('admin/forms')) . '">' . e(__('nav.messages')) . '</a>']) ?>
        </p>
    </div>
    <form method="POST" action="<?= e(base_url('admin/formularios/create')) ?>" class="pp-form-create">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="pp-form-create__label" for="form-template"><?= e(__('forms.template')) ?></label>
        <select id="form-template" name="template" class="pp-form-create__select">
            <?php foreach ($templates as $key => $tpl): ?>
            <option value="<?= e($key) ?>"><?= e($tpl['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('forms.new')) ?></button>
    </form>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($forms)): ?>
    <div class="pp-empty-state">
        <p><strong><?= e(__('forms.empty_title')) ?></strong></p>
        <p><?= e(__('forms.empty_text')) ?></p>
        <div class="pp-form-templates">
            <?php foreach ($templates as $key => $tpl): ?>
            <form method="POST" action="<?= e(base_url('admin/formularios/create')) ?>" class="pp-form-template-card">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="template" value="<?= e($key) ?>">
                <button type="submit" class="pp-form-template-card__btn">
                    <span class="pp-form-template-card__title"><?= e($tpl['label']) ?></span>
                    <span class="pp-form-template-card__desc"><?= e($tpl['description']) ?></span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="pp-forms-list">
        <?php foreach ($forms as $f): ?>
            <?php $used = (int) ($usage[$f['id']] ?? 0); ?>
            <div class="pp-forms-row">
                <a class="pp-forms-row__main" href="<?= e(base_url('admin/formularios/' . $f['id'])) ?>">
                    <span class="pp-forms-row__title"><?= e($f['heading']) ?></span>
                    <span class="pp-forms-row__meta">
                        <?= e(__((int) $f['field_count'] === 1 ? 'forms.fields_one' : 'forms.fields_other', ['n' => (int) $f['field_count']])) ?>
                        ·
                        <?php if ($used > 0): ?>
                            <?= e(__($used === 1 ? 'forms.used_one' : 'forms.used_other', ['n' => $used])) ?>
                        <?php else: ?>
                            <?= e(__('forms.unused')) ?>
                        <?php endif; ?>
                    </span>
                </a>
                <div class="pp-forms-row__actions">
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/formularios/' . $f['id'])) ?>"><?= e(__('common.edit')) ?></a>
                    <form method="POST" action="<?= e(base_url('admin/formularios/' . $f['id'] . '/delete')) ?>"
                          onsubmit="return confirm(<?= e(json_encode(__('forms.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm"><?= e(__('common.delete')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
