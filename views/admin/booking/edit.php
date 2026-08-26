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

<?php \Core\View::start('title'); ?><?= e(__('bk.title')) ?> · <?= e((string) $service['name']) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e((string) $service['name']) ?></h2>
        <p class="pp-page-header__lead"><a href="<?= e(base_url('admin/booking')) ?>">← <?= e(__('bk.back_to_services')) ?></a></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/booking/services/' . $sid)) ?>" class="pp-form" id="pp-booking-editor" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.col.service')) ?></h3><p><?= e(__('bk.svc.lead')) ?></p></div></div>
        <div class="pp-form-group">
            <label for="pp-bs-name"><?= e(__('common.name')) ?></label>
            <input type="text" id="pp-bs-name" name="name" maxlength="120" required value="<?= e((string) $service['name']) ?>">
        </div>
        <div class="pp-form-group">
            <label for="pp-bs-desc"><?= e(__('common.description')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
            <textarea id="pp-bs-desc" name="description" rows="2" maxlength="4000"><?= e((string) ($service['description'] ?? '')) ?></textarea>
        </div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-bs-price"><?= e(__('bk.svc.price')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
                <input type="text" id="pp-bs-price" name="price_label" maxlength="60" value="<?= e((string) ($service['price_label'] ?? '')) ?>" placeholder="<?= e(__('bk.svc.price_ph')) ?>">
                <small><?= e(__('bk.svc.price_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-active"><?= e(__('table.status')) ?></label>
                <select id="pp-bs-active" name="active">
                    <option value="1" <?= (int) $service['active'] === 1 ? 'selected' : '' ?>><?= e(__('bk.svc.active_opt')) ?></option>
                    <option value="0" <?= (int) $service['active'] === 1 ? '' : 'selected' ?>><?= e(__('bk.svc.inactive_opt')) ?></option>
                </select>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.slots.title')) ?></h3><p><?= e(__('bk.slots.lead')) ?></p></div></div>
        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-bs-duration"><?= e(__('bk.slots.duration')) ?></label>
                <input type="number" id="pp-bs-duration" name="duration_min" min="5" max="480" step="5" required value="<?= (int) $service['duration_min'] ?>">
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-buffer"><?= e(__('bk.slots.buffer')) ?></label>
                <input type="number" id="pp-bs-buffer" name="buffer_min" min="0" max="240" step="5" value="<?= (int) $service['buffer_min'] ?>">
                <small><?= e(__('bk.slots.buffer_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-capacity"><?= e(__('bk.slots.capacity')) ?></label>
                <input type="number" id="pp-bs-capacity" name="capacity" min="1" max="500" value="<?= (int) $service['capacity'] ?>">
                <small><?= e(__('bk.slots.capacity_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-confirm"><?= e(__('bk.slots.confirmation')) ?></label>
                <select id="pp-bs-confirm" name="auto_confirm">
                    <option value="0" <?= (int) $service['auto_confirm'] === 1 ? '' : 'selected' ?>><?= e(__('bk.slots.manual')) ?></option>
                    <option value="1" <?= (int) $service['auto_confirm'] === 1 ? 'selected' : '' ?>><?= e(__('bk.slots.auto')) ?></option>
                </select>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-notice"><?= e(__('bk.slots.min_notice')) ?></label>
                <input type="number" id="pp-bs-notice" name="min_notice_hours" min="0" max="720" value="<?= (int) $service['min_notice_hours'] ?>">
                <small><?= e(__('bk.slots.min_notice_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-bs-advance"><?= e(__('bk.slots.max_advance')) ?></label>
                <input type="number" id="pp-bs-advance" name="max_advance_days" min="1" max="365" value="<?= (int) $service['max_advance_days'] ?>">
                <small><?= e(__('bk.slots.max_advance_help')) ?></small>
            </div>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.week.title')) ?></h3><p><?= e(__('bk.week.lead')) ?></p></div></div>

        <?php /* Horario rápido: la mayoría de los negocios repiten la misma franja
                 varios días ("lunes a viernes de 8 a 16"), y hacerlo día a día son
                 cinco veces el mismo gesto. Marca los días, pon la hora y aplica.
                 Los campos de aquí NO llevan `name`: no se envían, solo rellenan
                 las franjas de abajo, que son las que se guardan. */ ?>
        <div class="pp-booking-quick" data-quick>
            <div class="pp-booking-quick__row">
                <span class="pp-booking-quick__label"><?= e(__('bk.quick.days')) ?></span>
                <div class="pp-booking-quick__days" role="group" aria-label="<?= e(__('bk.quick.days')) ?>">
                    <?php foreach ($weekdays as $wd => $label): ?>
                    <button type="button" class="pp-booking-quick__day" data-quick-day="<?= (int) $wd ?>"
                            aria-pressed="false" title="<?= e($label) ?>"><?= e(mb_substr($label, 0, 2)) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="pp-booking-quick__presets">
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-quick-preset="weekdays"><?= e(__('bk.quick.preset_weekdays')) ?></button>
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-quick-preset="all"><?= e(__('bk.quick.preset_all')) ?></button>
                </div>
            </div>
            <div class="pp-booking-quick__row">
                <span class="pp-booking-quick__label"><?= e(__('bk.quick.hours')) ?></span>
                <input type="time" data-quick-start value="09:00" aria-label="<?= e(__('bk.quick.from')) ?>">
                <span>–</span>
                <input type="time" data-quick-end value="17:00" aria-label="<?= e(__('bk.quick.to')) ?>">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-quick-apply><?= e(__('bk.quick.apply')) ?></button>
                <span class="pp-booking-quick__msg" data-quick-msg role="status" aria-live="polite"></span>
            </div>
            <p class="pp-booking-quick__hint"><?= e(__('bk.quick.hint')) ?></p>
        </div>

        <div class="pp-booking-week">
            <?php foreach ($weekdays as $wd => $label): ?>
            <div class="pp-booking-day" data-weekday="<?= (int) $wd ?>">
                <div class="pp-booking-day__name"><?= e($label) ?></div>
                <div class="pp-booking-day__ranges" data-ranges>
                    <?php /* Un día sin franjas está cerrado. Sin este aviso la fila
                             quedaba vacía y se leía como "sin configurar". */ ?>
                    <span class="pp-booking-day__closed" data-closed-hint <?= ($hours[$wd] ?? []) !== [] ? 'hidden' : '' ?>><?= e(__('bk.week.closed')) ?></span>
                    <?php foreach (($hours[$wd] ?? []) as $i => $range): ?>
                    <div class="pp-booking-range" data-range>
                        <input type="time" name="hours[<?= (int) $wd ?>][<?= (int) $i ?>][start]" value="<?= e($range['start']) ?>" required>
                        <span>–</span>
                        <input type="time" name="hours[<?= (int) $wd ?>][<?= (int) $i ?>][end]" value="<?= e($range['end']) ?>" required>
                        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-range aria-label="<?= e(__('bk.week.remove_range')) ?>">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-add-range>+ <?= e(__('bk.week.add_range')) ?></button>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.exc.title')) ?></h3><p><?= e(__('bk.exc.lead')) ?></p></div></div>
        <div class="pp-booking-exceptions" data-exceptions>
            <?php foreach ($exceptions as $i => $ex): ?>
            <div class="pp-booking-exception" data-exception>
                <input type="date" name="exceptions[<?= (int) $i ?>][date]" value="<?= e($ex['date']) ?>" required>
                <label class="pp-booking-exception__closed">
                    <input type="checkbox" name="exceptions[<?= (int) $i ?>][closed]" value="1" <?= $ex['closed'] ? 'checked' : '' ?> data-ex-closed>
                    <?= e(__('bk.exc.closed')) ?>
                </label>
                <span class="pp-booking-exception__range" <?= $ex['closed'] ? 'hidden' : '' ?>>
                    <input type="time" name="exceptions[<?= (int) $i ?>][start]" value="<?= e((string) ($ex['start'] ?? '')) ?>">
                    <span>–</span>
                    <input type="time" name="exceptions[<?= (int) $i ?>][end]" value="<?= e((string) ($ex['end'] ?? '')) ?>">
                </span>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-exception aria-label="<?= e(__('bk.exc.remove')) ?>">×</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-add-exception>+ <?= e(__('bk.exc.add')) ?></button>
    </section>

    <?php /* MODULOS M8 — Qué datos se le piden al cliente al reservar.
             Nombre y email no se listan: son fijos y no se pueden quitar (sin
             email no hay confirmación ni enlace de cancelación). */ ?>
    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.fields.title')) ?></h3><p><?= e(__('bk.fields.lead')) ?></p></div></div>

        <div class="pp-booking-fields">
            <div class="pp-booking-fields__fixed">
                <span class="pp-booking-fields__lock" aria-hidden="true">🔒</span>
                <span><?= __('bk.fields.fixed.html') ?></span>
            </div>

            <?php foreach (\App\Modules\Booking\BookingFields::BASE as $bk): $conf = $fieldsDef[$bk]; ?>
            <div class="pp-booking-field pp-booking-field--base">
                <div class="pp-booking-field__name">
                    <strong><?= e(__('bk.fields.base.' . $bk)) ?></strong>
                    <small><?= e(__('bk.fields.base.' . $bk . '_help')) ?></small>
                </div>
                <?php /* El input oculto delante de cada casilla es lo que hace que
                         "desmarcado" llegue al servidor: una casilla sin marcar no
                         se envía, y sin esto quitar un campo no se guardaba nunca. */ ?>
                <label class="pp-booking-field__toggle">
                    <input type="hidden" name="fields[<?= e($bk) ?>][enabled]" value="0">
                    <input type="checkbox" name="fields[<?= e($bk) ?>][enabled]" value="1" <?= $conf['enabled'] ? 'checked' : '' ?> data-base-enabled>
                    <?= e(__('bk.fields.ask')) ?>
                </label>
                <label class="pp-booking-field__toggle">
                    <input type="hidden" name="fields[<?= e($bk) ?>][required]" value="0">
                    <input type="checkbox" name="fields[<?= e($bk) ?>][required]" value="1" <?= $conf['required'] ? 'checked' : '' ?> data-base-required>
                    <?= e(__('bk.fields.required')) ?>
                </label>
                <input type="text" class="pp-booking-field__label" name="fields[<?= e($bk) ?>][label]"
                       value="<?= e($conf['label']) ?>" maxlength="60"
                       placeholder="<?= e(__('bk.fields.label_ph')) ?>">
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pp-booking-customfields" data-custom-fields data-max="<?= (int) \App\Modules\Booking\BookingFields::MAX_CUSTOM ?>">
            <?php foreach ($fieldsDef['custom'] as $i => $cf): ?>
            <div class="pp-booking-field pp-booking-field--custom" data-custom-field>
                <span class="pp-drag-handle" aria-hidden="true">⋮⋮</span>
                <input type="hidden" name="fields[custom][<?= (int) $i ?>][key]" value="<?= e($cf['key']) ?>">
                <input type="text" name="fields[custom][<?= (int) $i ?>][label]" value="<?= e($cf['label']) ?>"
                       maxlength="60" placeholder="<?= e(__('bk.fields.custom_label_ph')) ?>" required>
                <select name="fields[custom][<?= (int) $i ?>][type]" data-field-type>
                    <?php foreach (\App\Modules\Booking\BookingFields::TYPES as $t): ?>
                    <option value="<?= e($t) ?>" <?= $cf['type'] === $t ? 'selected' : '' ?>><?= e(__('bk.fields.type.' . $t)) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="pp-booking-field__options" name="fields[custom][<?= (int) $i ?>][options_raw]"
                       value="<?= e(implode(', ', (array) ($cf['options'] ?? []))) ?>"
                       placeholder="<?= e(__('bk.fields.options_ph')) ?>"
                       data-field-options <?= $cf['type'] === 'select' ? '' : 'hidden' ?>>
                <label class="pp-booking-field__toggle">
                    <input type="hidden" name="fields[custom][<?= (int) $i ?>][required]" value="0">
                    <input type="checkbox" name="fields[custom][<?= (int) $i ?>][required]" value="1" <?= !empty($cf['required']) ? 'checked' : '' ?>>
                    <?= e(__('bk.fields.required')) ?>
                </label>
                <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-field aria-label="<?= e(__('bk.fields.remove')) ?>">×</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-add-field>+ <?= e(__('bk.fields.add')) ?></button>
        <p class="pp-booking-fields__hint"><?= e(__('bk.fields.hint', ['max' => (string) \App\Modules\Booking\BookingFields::MAX_CUSTOM])) ?></p>
    </section>

    <?php /* MODULOS M9 — Los tres mensajes que recibe el cliente. Vacío = la
             plantilla de siempre, traducida; en cuanto se escribe algo, manda
             lo escrito. El botón "Ver la plantilla por defecto" la copia en el
             cuadro para no partir de una hoja en blanco. */ ?>
    <section class="pp-ai-config-panel">
        <div class="pp-ai-section-head"><div><h3><?= e(__('bk.emails.title')) ?></h3><p><?= e(__('bk.emails.lead')) ?></p></div></div>

        <?php if (empty($mailReady)): ?>
        <div class="pp-alert pp-alert--warning pp-booking-mailwarn" data-pp-persist>
            <div>
                <strong><?= e(__('bk.mail_off.title')) ?></strong>
                <p><?= e(__('bk.mail_off.text')) ?></p>
            </div>
            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/settings/mail')) ?>"><?= e(__('bk.mail_off.cta')) ?></a>
        </div>
        <?php endif; ?>

        <div class="pp-booking-emails">
            <?php foreach (\App\Modules\Booking\BookingEmails::TYPES as $type): $conf = $emailsDef[$type]; $isCustom = trim($conf['subject']) !== '' || trim($conf['body']) !== ''; ?>
            <details class="pp-seo-advanced pp-booking-email" data-email-type="<?= e($type) ?>" <?= $isCustom ? 'open' : '' ?>>
                <summary>
                    <?= e(__('bk.emails.type.' . $type)) ?>
                    <span class="pp-booking-email__badge"><?= e($isCustom ? __('bk.emails.custom') : __('bk.emails.default')) ?></span>
                </summary>
                <div class="pp-seo-advanced__body">
                    <p class="pp-booking-email__when"><?= e(__('bk.emails.when.' . $type)) ?></p>
                    <div class="pp-form-group">
                        <label for="pp-mail-<?= e($type) ?>-subject"><?= e(__('bk.emails.subject')) ?></label>
                        <input type="text" id="pp-mail-<?= e($type) ?>-subject" name="emails[<?= e($type) ?>][subject]"
                               maxlength="180" value="<?= e($conf['subject']) ?>"
                               placeholder="<?= e($emailDefaults[$type]['subject']) ?>">
                    </div>
                    <div class="pp-form-group">
                        <label for="pp-mail-<?= e($type) ?>-body"><?= e(__('bk.emails.body')) ?></label>
                        <textarea id="pp-mail-<?= e($type) ?>-body" name="emails[<?= e($type) ?>][body]" rows="10"
                                  maxlength="4000" data-email-body
                                  placeholder="<?= e($emailDefaults[$type]['body']) ?>"><?= e($conf['body']) ?></textarea>
                        <small><?= e(__('bk.emails.body_help')) ?></small>
                    </div>
                    <div class="pp-booking-email__actions">
                        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-email-fill
                                data-default-subject="<?= e($emailDefaults[$type]['subject']) ?>"
                                data-default-body="<?= e($emailDefaults[$type]['body']) ?>"><?= e(__('bk.emails.use_default')) ?></button>
                        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-email-clear><?= e(__('bk.emails.reset')) ?></button>
                        <span class="pp-booking-email__warn" data-email-warn hidden><?= e(__('bk.emails.no_cancel_warning')) ?></span>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        </div>

        <p class="pp-booking-email__tokens">
            <strong><?= e(__('bk.emails.tokens')) ?></strong>
            <?php foreach (\App\Modules\Booking\BookingEmails::TOKENS as $tk): ?>
            <code>{<?= e($tk) ?>}</code>
            <?php endforeach; ?>
        </p>
        <p class="pp-booking-email__tokens-help"><?= e(__('bk.emails.tokens_help')) ?></p>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('bk.save_service')) ?></button>
    </div>
</form>

<template id="pp-booking-field-tpl">
    <div class="pp-booking-field pp-booking-field--custom" data-custom-field>
        <span class="pp-drag-handle" aria-hidden="true">⋮⋮</span>
        <input type="hidden" data-name="key" value="">
        <input type="text" data-name="label" maxlength="60" placeholder="<?= e(__('bk.fields.custom_label_ph')) ?>" required>
        <select data-name="type" data-field-type>
            <?php foreach (\App\Modules\Booking\BookingFields::TYPES as $t): ?>
            <option value="<?= e($t) ?>"><?= e(__('bk.fields.type.' . $t)) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" class="pp-booking-field__options" data-name="options_raw"
               placeholder="<?= e(__('bk.fields.options_ph')) ?>" data-field-options hidden>
        <label class="pp-booking-field__toggle">
            <input type="hidden" data-name="required" value="0">
            <input type="checkbox" data-name="required" value="1">
            <?= e(__('bk.fields.required')) ?>
        </label>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-field aria-label="<?= e(__('bk.fields.remove')) ?>">×</button>
    </div>
</template>

<template id="pp-booking-range-tpl">
    <div class="pp-booking-range" data-range>
        <input type="time" data-name="start" required>
        <span>–</span>
        <input type="time" data-name="end" required>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-range aria-label="<?= e(__('bk.week.remove_range')) ?>">×</button>
    </div>
</template>

<template id="pp-booking-exception-tpl">
    <div class="pp-booking-exception" data-exception>
        <input type="date" data-name="date" required>
        <label class="pp-booking-exception__closed">
            <input type="checkbox" value="1" checked data-ex-closed data-name="closed">
            <?= e(__('bk.exc.closed')) ?>
        </label>
        <span class="pp-booking-exception__range" hidden>
            <input type="time" data-name="start">
            <span>–</span>
            <input type="time" data-name="end">
        </span>
        <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-remove-exception aria-label="<?= e(__('bk.exc.remove')) ?>">×</button>
    </div>
</template>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/booking-service-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/booking-service-editor.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>
