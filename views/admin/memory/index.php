<?php
/**
 * @var array $values  field_key => field_value
 * @var array $errors  field_key => mensaje
 * @var array $fields  schema (MemoryController::FIELDS)
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Memoria del sitio<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Memoria del sitio</h2>
</div>

<p class="pp-page-intro">
    Esta información se inyectará en todas las llamadas a IA para generar contenido coherente con tu marca.
    Cuanto más completa y específica sea, mejor será el resultado.
</p>

<?php
// Flash messages
$flashSuccess = \Core\Session::flash('success');
$flashError   = \Core\Session::flash('error');
?>
<?php if ($flashSuccess): ?>
<div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="pp-alert pp-alert--error"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <strong>Revisa los errores:</strong>
    <ul style="margin: 8px 0 0 20px;">
        <?php foreach ($errors as $msg): ?>
        <li><?= e($msg) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php
// Progreso de completitud
$total  = count($fields);
$filled = 0;
foreach ($values as $v) { if (trim((string) $v) !== '') $filled++; }
$percent = $total > 0 ? (int) round(($filled / $total) * 100) : 0;
?>
<div class="pp-memory-progress">
    <div class="pp-memory-progress__label">
        <span><strong><?= $filled ?></strong> de <?= $total ?> campos completados</span>
        <span class="pp-memory-progress__pct"><?= $percent ?>%</span>
    </div>
    <div class="pp-memory-progress__bar">
        <div class="pp-memory-progress__fill" style="width: <?= $percent ?>%"></div>
    </div>
</div>

<form method="POST" action="<?= e(base_url('admin/memory')) ?>" class="pp-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-card">
        <h3>Sobre tu negocio</h3>

        <?php foreach (['business_description', 'target_audience', 'value_proposition'] as $key): ?>
            <?php renderMemoryField($key, $fields[$key], $values[$key] ?? '', $errors); ?>
        <?php endforeach; ?>
    </div>

    <div class="pp-form-card">
        <h3>Tono y estilo</h3>
        <?php renderMemoryField('tone_of_voice', $fields['tone_of_voice'], $values['tone_of_voice'] ?? '', $errors); ?>
    </div>

    <div class="pp-form-card">
        <h3>Oferta comercial</h3>
        <?php foreach (['services', 'unique_selling_points'] as $key): ?>
            <?php renderMemoryField($key, $fields[$key], $values[$key] ?? '', $errors); ?>
        <?php endforeach; ?>
    </div>

    <div class="pp-form-card">
        <h3>SEO y contacto</h3>
        <?php foreach (['keywords', 'contact_info'] as $key): ?>
            <?php renderMemoryField($key, $fields[$key], $values[$key] ?? '', $errors); ?>
        <?php endforeach; ?>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">Guardar memoria</button>
    </div>
</form>

<?php
/**
 * Renderiza un campo de memoria según su tipo.
 */
function renderMemoryField(string $key, array $def, string $value, array $errors): void
{
    $hasError = isset($errors[$key]);
    $rows     = $def['rows'] ?? 3;
?>
    <div class="pp-form-group <?= $hasError ? 'has-error' : '' ?>">
        <label for="mem_<?= e($key) ?>"><?= e($def['label']) ?></label>

        <?php if ($def['type'] === 'select'): ?>
        <select id="mem_<?= e($key) ?>" name="<?= e($key) ?>">
            <?php foreach ($def['options'] as $optVal => $optLabel): ?>
            <option value="<?= e($optVal) ?>" <?= $value === $optVal ? 'selected' : '' ?>>
                <?= e($optLabel) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php else: ?>
        <textarea id="mem_<?= e($key) ?>" name="<?= e($key) ?>"
                  rows="<?= (int) $rows ?>"
                  maxlength="5000"
                  placeholder="<?= e($def['placeholder'] ?? '') ?>"><?= e($value) ?></textarea>
        <?php endif; ?>

        <?php if (!empty($def['help'])): ?>
        <small><?= e($def['help']) ?></small>
        <?php endif; ?>
        <?php if ($hasError): ?>
        <small class="pp-err"><?= e($errors[$key]) ?></small>
        <?php endif; ?>
    </div>
<?php
}
?>
