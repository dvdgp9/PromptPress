<?php
/**
 * Reservas — editor de servicio (FEAT-3 B2).
 *
 * Horario semanal: hours[<weekday>][<i>][start|end] (0=lunes).
 * Excepciones:     exceptions[<i>][date|closed|start|end].
 * Las filas se añaden/quitan con booking-service-editor.js (plantillas <template>).
 *
 * @var array    $service   booking_services + hours + exceptions
 * @var string[] $weekdays  etiquetas 0=lunes … 6=domingo
 * @var string[] $errors
 * @var ?string  $notice
 * @var string   $csrf
 */
\Core\View::extend('admin/layout');

$sid = (int) $service['id'];
$hours = is_array($service['hours'] ?? null) ? $service['hours'] : [];
$exceptions = is_array($service['exceptions'] ?? null) ? $service['exceptions'] : [];
?>

<?php \Core\View::start('title'); ?>Reservas · <?= e((string) $service['name']) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e((string) $service['name']) ?></h2>
        <p class="pp-page-header__lead"><a href="<?= e(base_url('admin/booking')) ?>">← Volver a servicios</a></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/booking/services/' . $sid)) ?>" class="pp-form" id="pp-booking-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Servicio</h3><p>Lo que ve el cliente al reservar.</p></div></div>
        <div class="pp-form-group">
            <label for="pp-bs-name">Nombre</label>
            <input type="text" id="pp-bs-name" name="name" maxlength="120" required value="<?= e((string) $service['name']) ?>">
        </div>
        <div class="pp-form-group">
            <label for="pp-bs-desc">Descripción <span class="pp-ai-optional-tag">opcional</span></label>
            <textarea id="pp-bs-desc" name="description" rows="2" maxlength="4000"><?= e((string) ($service['description'] ?? '')) ?></textarea>
        </div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-bs-price">Precio orientativo <span class="pp-ai-optional-tag">opcional</span></label>
                <input type="text" id="pp-bs-price" name="price_label" maxlength="60" value="<?= e((string) ($service['price_label'] ?? '')) ?>" placeholder="Ej: 30 €, Gratis">
                <small>Solo informativo: el cobro no se gestiona aquí.</small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-active">Estado</label>
                <select id="pp-bs-active" name="active">
                    <option value="1" <?= (int) $service['active'] === 1 ? 'selected' : '' ?>>Activo (se puede reservar)</option>
                    <option value="0" <?= (int) $service['active'] === 1 ? '' : 'selected' ?>>Inactivo (oculto)</option>
                </select>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Duración y plazas</h3><p>Cómo se generan los huecos reservables.</p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-bs-duration">Duración (minutos)</label>
                <input type="number" id="pp-bs-duration" name="duration_min" min="5" max="480" step="5" required value="<?= (int) $service['duration_min'] ?>">
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-buffer">Margen entre citas (minutos)</label>
                <input type="number" id="pp-bs-buffer" name="buffer_min" min="0" max="240" step="5" value="<?= (int) $service['buffer_min'] ?>">
                <small>Tiempo libre tras cada cita (recoger, desplazarte…).</small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-capacity">Plazas por hueco</label>
                <input type="number" id="pp-bs-capacity" name="capacity" min="1" max="500" value="<?= (int) $service['capacity'] ?>">
                <small>1 = cita individual. Más de 1 = clase o actividad en grupo.</small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-confirm">Confirmación</label>
                <select id="pp-bs-confirm" name="auto_confirm">
                    <option value="0" <?= (int) $service['auto_confirm'] === 1 ? '' : 'selected' ?>>Manual: yo confirmo cada reserva</option>
                    <option value="1" <?= (int) $service['auto_confirm'] === 1 ? 'selected' : '' ?>>Automática al reservar</option>
                </select>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-notice">Antelación mínima (horas)</label>
                <input type="number" id="pp-bs-notice" name="min_notice_hours" min="0" max="720" value="<?= (int) $service['min_notice_hours'] ?>">
                <small>No se podrá reservar con menos antelación que esta.</small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-advance">Ventana máxima (días)</label>
                <input type="number" id="pp-bs-advance" name="max_advance_days" min="1" max="365" value="<?= (int) $service['max_advance_days'] ?>">
                <small>Hasta cuántos días vista se puede reservar.</small>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Horario semanal</h3><p>Franjas en las que se ofrecen huecos, en la hora local del sitio. Un día sin franjas no acepta reservas.</p></div></div>
        <div class="pp-booking-week">
            <?php foreach ($weekdays as $wd => $label): ?>
            <div class="pp-booking-day" data-weekday="<?= (int) $wd ?>">
                <div class="pp-booking-day__name"><?= e($label) ?></div>
                <div class="pp-booking-day__ranges" data-ranges>
                    <?php foreach (($hours[$wd] ?? []) as $i => $range): ?>
                    <div class="pp-booking-range" data-range>
                        <input type="time" name="hours[<?= (int) $wd ?>][<?= (int) $i ?>][start]" value="<?= e($range['start']) ?>" required>
                        <span>–</span>
                        <input type="time" name="hours[<?= (int) $wd ?>][<?= (int) $i ?>][end]" value="<?= e($range['end']) ?>" required>
                        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-range aria-label="Quitar franja">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-add-range>+ Añadir franja</button>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3>Excepciones</h3><p>Días concretos que rompen el horario: festivos y vacaciones (cerrado) u horarios especiales.</p></div></div>
        <div class="pp-booking-exceptions" data-exceptions>
            <?php foreach ($exceptions as $i => $ex): ?>
            <div class="pp-booking-exception" data-exception>
                <input type="date" name="exceptions[<?= (int) $i ?>][date]" value="<?= e($ex['date']) ?>" required>
                <label class="pp-booking-exception__closed">
                    <input type="checkbox" name="exceptions[<?= (int) $i ?>][closed]" value="1" <?= $ex['closed'] ? 'checked' : '' ?> data-ex-closed>
                    Cerrado
                </label>
                <span class="pp-booking-exception__range" <?= $ex['closed'] ? 'hidden' : '' ?>>
                    <input type="time" name="exceptions[<?= (int) $i ?>][start]" value="<?= e((string) ($ex['start'] ?? '')) ?>">
                    <span>–</span>
                    <input type="time" name="exceptions[<?= (int) $i ?>][end]" value="<?= e((string) ($ex['end'] ?? '')) ?>">
                </span>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-exception aria-label="Quitar excepción">×</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-add-exception>+ Añadir excepción</button>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">Guardar servicio</button>
    </div>
</form>

<template id="pp-booking-range-tpl">
    <div class="pp-booking-range" data-range>
        <input type="time" data-name="start" required>
        <span>–</span>
        <input type="time" data-name="end" required>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-range aria-label="Quitar franja">×</button>
    </div>
</template>

<template id="pp-booking-exception-tpl">
    <div class="pp-booking-exception" data-exception>
        <input type="date" data-name="date" required>
        <label class="pp-booking-exception__closed">
            <input type="checkbox" value="1" checked data-ex-closed data-name="closed">
            Cerrado
        </label>
        <span class="pp-booking-exception__range" hidden>
            <input type="time" data-name="start">
            <span>–</span>
            <input type="time" data-name="end">
        </span>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-exception aria-label="Quitar excepción">×</button>
    </div>
</template>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/booking-service-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/booking-service-editor.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>
