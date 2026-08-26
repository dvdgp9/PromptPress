/**
 * Editor de servicio reservable (FEAT-3 B2).
 *
 * Añade/quita franjas del horario semanal y excepciones de fecha clonando
 * los <template> de la vista. Los índices de los names solo tienen que ser
 * únicos (el backend itera el array sin mirar las claves), así que se usa
 * un contador global.
 */
(function () {
    'use strict';

    var form = document.getElementById('pp-booking-editor');
    if (!form) return;

    var rangeTpl = document.getElementById('pp-booking-range-tpl');
    var exceptionTpl = document.getElementById('pp-booking-exception-tpl');
    var uid = 1000; // por encima de cualquier índice renderizado por PHP

    function setNames(root, prefix) {
        root.querySelectorAll('[data-name]').forEach(function (input) {
            input.name = prefix + '[' + input.getAttribute('data-name') + ']';
        });
    }

    /** El aviso "Cerrado" solo se ve mientras el día no tenga ninguna franja. */
    function syncClosedHint(day) {
        var hint = day.querySelector('[data-closed-hint]');
        if (!hint) return;
        hint.hidden = day.querySelectorAll('[data-range]').length > 0;
    }

    /** Añade una franja (con valores opcionales) al día indicado. */
    function addRange(day, start, end) {
        var row = rangeTpl.content.firstElementChild.cloneNode(true);
        setNames(row, 'hours[' + day.getAttribute('data-weekday') + '][' + (uid++) + ']');
        if (start) row.querySelector('[data-name="start"]').value = start;
        if (end) row.querySelector('[data-name="end"]').value = end;
        day.querySelector('[data-ranges]').appendChild(row);
        syncClosedHint(day);
        return row;
    }

    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn) return;

        if (btn.hasAttribute('data-add-range')) {
            addRange(btn.closest('[data-weekday]'));
        } else if (btn.hasAttribute('data-remove-range')) {
            var dayOfRange = btn.closest('[data-weekday]');
            btn.closest('[data-range]').remove();
            if (dayOfRange) syncClosedHint(dayOfRange);
        } else if (btn.hasAttribute('data-add-exception')) {
            var ex = exceptionTpl.content.firstElementChild.cloneNode(true);
            setNames(ex, 'exceptions[' + (uid++) + ']');
            form.querySelector('[data-exceptions]').appendChild(ex);
        } else if (btn.hasAttribute('data-remove-exception')) {
            btn.closest('[data-exception]').remove();
        }
    });

    // Checkbox "Cerrado": oculta la franja horaria de la excepción y viceversa.
    form.addEventListener('change', function (ev) {
        if (!ev.target.hasAttribute('data-ex-closed')) return;
        var row = ev.target.closest('[data-exception]');
        var range = row.querySelector('.pp-booking-exception__range');
        range.hidden = ev.target.checked;
        if (ev.target.checked) {
            range.querySelectorAll('input[type="time"]').forEach(function (i) { i.value = ''; });
        }
    });

    // ------------------------------------------------------------------
    // MODULOS M8 — Campos del formulario de reserva.
    // ------------------------------------------------------------------
    var fieldTpl = document.getElementById('pp-booking-field-tpl');
    var fieldsBox = form.querySelector('[data-custom-fields]');

    /** El cuadro de opciones solo tiene sentido en un desplegable. */
    function syncFieldType(row) {
        var type = row.querySelector('[data-field-type]');
        var options = row.querySelector('[data-field-options]');
        if (type && options) options.hidden = type.value !== 'select';
    }

    /** Un campo que no se pide no puede ser obligatorio. */
    function syncBaseField(row) {
        var enabled = row.querySelector('[data-base-enabled]');
        var required = row.querySelector('[data-base-required]');
        if (!enabled || !required) return;
        required.disabled = !enabled.checked;
        if (!enabled.checked) required.checked = false;
    }

    form.querySelectorAll('.pp-booking-field--base').forEach(syncBaseField);
    form.querySelectorAll('[data-custom-field]').forEach(syncFieldType);

    if (fieldsBox && fieldTpl) {
        form.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button');
            if (!btn) return;
            if (btn.hasAttribute('data-add-field')) {
                var max = parseInt(fieldsBox.dataset.max || '12', 10);
                if (fieldsBox.querySelectorAll('[data-custom-field]').length >= max) {
                    alert(pp.t('js.bk.fields_max', { n: max }));
                    return;
                }
                var row = fieldTpl.content.firstElementChild.cloneNode(true);
                setNames(row, 'fields[custom][' + (uid++) + ']');
                fieldsBox.appendChild(row);
                syncFieldType(row);
                var label = row.querySelector('input[type="text"]');
                if (label) label.focus();
            } else if (btn.hasAttribute('data-remove-field')) {
                btn.closest('[data-custom-field]').remove();
            }
        });

        form.addEventListener('change', function (ev) {
            if (ev.target.hasAttribute('data-field-type')) {
                syncFieldType(ev.target.closest('[data-custom-field]'));
            } else if (ev.target.hasAttribute('data-base-enabled')) {
                syncBaseField(ev.target.closest('.pp-booking-field--base'));
            }
        });
    }

    // ------------------------------------------------------------------
    // MODULOS M9 — Plantillas de email por servicio.
    // ------------------------------------------------------------------
    form.querySelectorAll('[data-email-type]').forEach(function (box) {
        var subject = box.querySelector('input[type="text"]');
        var body = box.querySelector('[data-email-body]');
        var warn = box.querySelector('[data-email-warn]');
        var badge = box.querySelector('.pp-booking-email__badge');
        var fill = box.querySelector('[data-email-fill]');
        var clear = box.querySelector('[data-email-clear]');
        var isCancelled = box.getAttribute('data-email-type') === 'cancelled';

        function refresh() {
            var custom = subject.value.trim() !== '' || body.value.trim() !== '';
            if (badge) badge.textContent = pp.t(custom ? 'js.bk.mail_custom' : 'js.bk.mail_default');
            // El enlace de cancelar solo tiene sentido mientras la reserva vive.
            if (warn) warn.hidden = isCancelled || body.value.trim() === '' || body.value.indexOf('{cancelar}') !== -1;
        }

        if (fill) {
            fill.addEventListener('click', function () {
                subject.value = fill.getAttribute('data-default-subject') || '';
                body.value = fill.getAttribute('data-default-body') || '';
                refresh();
                body.focus();
            });
        }
        if (clear) {
            clear.addEventListener('click', function () {
                subject.value = '';
                body.value = '';
                refresh();
            });
        }
        subject.addEventListener('input', refresh);
        body.addEventListener('input', refresh);
        refresh();
    });

    // ------------------------------------------------------------------
    // Horario rápido: la misma franja en varios días de una vez.
    // Solo toca el formulario; nada se guarda hasta "Guardar servicio".
    // ------------------------------------------------------------------
    var quick = form.querySelector('[data-quick]');
    if (quick) {
        var msg = quick.querySelector('[data-quick-msg]');
        var dayBtns = Array.prototype.slice.call(quick.querySelectorAll('[data-quick-day]'));

        function selectedDays() {
            return dayBtns.filter(function (b) { return b.getAttribute('aria-pressed') === 'true'; })
                          .map(function (b) { return b.getAttribute('data-quick-day'); });
        }

        function setDay(btn, on) {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('is-on', on);
        }

        function say(text, isError) {
            msg.textContent = text || '';
            msg.classList.toggle('is-error', !!isError);
        }

        quick.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button');
            if (!btn) return;

            if (btn.hasAttribute('data-quick-day')) {
                setDay(btn, btn.getAttribute('aria-pressed') !== 'true');
                say('');
                return;
            }

            if (btn.hasAttribute('data-quick-preset')) {
                // 0=lunes … 6=domingo (misma convención que booking_hours).
                var preset = btn.getAttribute('data-quick-preset');
                dayBtns.forEach(function (b) {
                    var wd = parseInt(b.getAttribute('data-quick-day'), 10);
                    setDay(b, preset === 'all' ? true : wd <= 4);
                });
                say('');
                return;
            }

            if (!btn.hasAttribute('data-quick-apply')) return;

            var days = selectedDays();
            var start = quick.querySelector('[data-quick-start]').value;
            var end = quick.querySelector('[data-quick-end]').value;

            if (!days.length) { say(pp.t('js.bk.quick_no_days'), true); return; }
            if (!start || !end) { say(pp.t('js.bk.quick_no_hours'), true); return; }
            if (start >= end) { say(pp.t('js.bk.quick_bad_hours'), true); return; }

            // Aplicar SUSTITUYE lo que hubiera en esos días: es lo que se espera
            // al decir "lunes a viernes de 8 a 16". Si hay algo que perder, se
            // pregunta antes, porque el horario a medio configurar puede ser un
            // rato de trabajo.
            var withRanges = days.filter(function (wd) {
                return form.querySelector('[data-weekday="' + wd + '"]').querySelectorAll('[data-range]').length > 0;
            });
            var overwriteKey = withRanges.length === 1 ? 'js.bk.quick_overwrite_one' : 'js.bk.quick_overwrite_many';
            if (withRanges.length && !confirm(pp.t(overwriteKey, { n: withRanges.length }))) {
                return;
            }

            days.forEach(function (wd) {
                var day = form.querySelector('[data-weekday="' + wd + '"]');
                day.querySelectorAll('[data-range]').forEach(function (r) { r.remove(); });
                addRange(day, start, end);
            });
            say(pp.t(days.length === 1 ? 'js.bk.quick_done_one' : 'js.bk.quick_done_many', { n: days.length }));
        });
    }
})();
