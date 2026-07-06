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

    form.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button');
        if (!btn) return;

        if (btn.hasAttribute('data-add-range')) {
            var day = btn.closest('[data-weekday]');
            var row = rangeTpl.content.firstElementChild.cloneNode(true);
            setNames(row, 'hours[' + day.getAttribute('data-weekday') + '][' + (uid++) + ']');
            day.querySelector('[data-ranges]').appendChild(row);
        } else if (btn.hasAttribute('data-remove-range')) {
            btn.closest('[data-range]').remove();
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
})();
