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

<?php \Core\View::start('title'); ?>Formularios<?php \Core\View::end(); ?>

<div class="pp-forms-wrap">
<div class="pp-page-header">
    <div>
        <h2>Formularios</h2>
        <p class="pp-page-intro">
            Crea formularios (contacto, inscripción…) y luego insértalos en tus páginas. Las respuestas que recibas aparecen en <a href="<?= e(base_url('admin/forms')) ?>">Mensajes</a>.
        </p>
    </div>
    <form method="POST" action="<?= e(base_url('admin/formularios/create')) ?>" class="pp-form-create">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="pp-form-create__label" for="form-template">Plantilla</label>
        <select id="form-template" name="template" class="pp-form-create__select">
            <?php foreach ($templates as $key => $tpl): ?>
            <option value="<?= e($key) ?>"><?= e($tpl['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn--primary">+ Nuevo formulario</button>
    </form>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($forms)): ?>
    <div class="pp-empty-state">
        <p><strong>Aún no tienes formularios.</strong></p>
        <p>Empieza desde una plantilla. Luego puedes ajustar los campos a tu gusto.</p>
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
                        <?= (int) $f['field_count'] ?> <?= $f['field_count'] == 1 ? 'campo' : 'campos' ?>
                        ·
                        <?php if ($used > 0): ?>
                            usado en <?= $used ?> <?= $used == 1 ? 'página' : 'páginas' ?>
                        <?php else: ?>
                            sin usar todavía
                        <?php endif; ?>
                    </span>
                </a>
                <div class="pp-forms-row__actions">
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/formularios/' . $f['id'])) ?>">Editar</a>
                    <form method="POST" action="<?= e(base_url('admin/formularios/' . $f['id'] . '/delete')) ?>"
                          onsubmit="return confirm('¿Eliminar este formulario? Si está insertado en alguna página, dejará de funcionar.');">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm">Eliminar</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
