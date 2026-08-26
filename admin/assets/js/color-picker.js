/**
 * ONB2 O2.3 — Selector de color propio (sin dependencias).
 *
 * Se engancha a cualquier `input[data-pp-color]` que contenga un HEX: lo deja
 * como campo de texto (escribir el HEX a mano sigue siendo la vía más rápida
 * para quien lo tiene) y le añade una muestra que abre un panel con área de
 * saturación/luminosidad, tono, cuentagotas y colores recientes.
 *
 * Cada cambio se escribe en el input original y dispara `input` + `change`,
 * así que el código que ya escuchaba ese campo no se entera del cambio.
 *
 * API: se auto-inicializa al cargar y expone `PPColorPicker.attach(input)`
 * para los campos que aparezcan después (paleta de marca, O2.4).
 */
(function () {
    'use strict';

    var RECENTS_KEY = 'pp-color-recents';
    var RECENTS_MAX = 8;

    // ---------------------------------------------------------------- color

    function normalizeHex(value) {
        var v = String(value == null ? '' : value).trim().toLowerCase();
        if (v.charAt(0) !== '#') v = '#' + v;
        if (/^#[0-9a-f]{3}$/.test(v)) {
            v = '#' + v.charAt(1) + v.charAt(1) + v.charAt(2) + v.charAt(2) + v.charAt(3) + v.charAt(3);
        }
        return /^#[0-9a-f]{6}$/.test(v) ? v : null;
    }

    function hexToRgb(hex) {
        return {
            r: parseInt(hex.slice(1, 3), 16),
            g: parseInt(hex.slice(3, 5), 16),
            b: parseInt(hex.slice(5, 7), 16)
        };
    }

    function rgbToHex(r, g, b) {
        function part(n) {
            var s = Math.max(0, Math.min(255, Math.round(n))).toString(16);
            return s.length === 1 ? '0' + s : s;
        }
        return '#' + part(r) + part(g) + part(b);
    }

    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        var h = 0;
        if (d !== 0) {
            if (max === r) h = ((g - b) / d + (g < b ? 6 : 0));
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h *= 60;
        }
        return { h: h, s: max === 0 ? 0 : d / max, v: max };
    }

    function hsvToHex(h, s, v) {
        h = ((h % 360) + 360) % 360;
        var c = v * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = v - c;
        var rgb = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x]
                : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
        return rgbToHex((rgb[0] + m) * 255, (rgb[1] + m) * 255, (rgb[2] + m) * 255);
    }

    /** Blanco o negro, el que más contraste tenga: para el texto de la muestra. */
    function readableOn(hex) {
        var c = hexToRgb(hex);
        var lum = [c.r, c.g, c.b].map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        var l = 0.2126 * lum[0] + 0.7152 * lum[1] + 0.0722 * lum[2];
        return l > 0.45 ? '#111111' : '#ffffff';
    }

    // -------------------------------------------------------------- recents

    function readRecents() {
        try {
            var raw = window.localStorage.getItem(RECENTS_KEY);
            var list = raw ? JSON.parse(raw) : [];
            return Array.isArray(list) ? list.filter(normalizeHex).slice(0, RECENTS_MAX) : [];
        } catch (e) {
            return [];
        }
    }

    function pushRecent(hex) {
        try {
            var list = readRecents().filter(function (c) { return c !== hex; });
            list.unshift(hex);
            window.localStorage.setItem(RECENTS_KEY, JSON.stringify(list.slice(0, RECENTS_MAX)));
        } catch (e) { /* modo privado: los recientes son un extra, no un requisito */ }
    }

    // ------------------------------------------------------------ componente

    function attach(input) {
        if (!input || input.dataset.ppColorReady === '1') return null;
        input.dataset.ppColorReady = '1';

        var value = normalizeHex(input.value) || '#000000';
        var hsv = rgbToHsv(hexToRgb(value).r, hexToRgb(value).g, hexToRgb(value).b);
        var open = false;

        var wrap = document.createElement('span');
        wrap.className = 'pp-cp';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.classList.add('pp-cp__hex');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'pp-cp__trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        wrap.insertBefore(trigger, input);

        var panel = document.createElement('div');
        panel.className = 'pp-cp__panel';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', pp.t('js.cp.panel_aria'));
        panel.innerHTML =
            '<div class="pp-cp__area" tabindex="0" role="application" aria-label="' + pp.t('js.cp.area_aria') + '">'
          + '<i class="pp-cp__area-handle"></i></div>'
            // Un <div>, no un <label>: la página anfitriona puede tener reglas
            // para `label input` (el onboarding esconde así los radios de los
            // swatches) y se llevarían por delante el control de tono.
          + '<div class="pp-cp__hue">'
          + '<input type="range" min="0" max="360" step="1" aria-label="' + pp.t('js.cp.hue_aria') + '"></div>'
          + '<div class="pp-cp__foot">'
          + '<button type="button" class="pp-cp__eyedrop" hidden aria-label="' + pp.t('js.cp.eyedrop_aria') + '">⊙</button>'
          + '<div class="pp-cp__recents"></div>'
          + '</div>';
        wrap.appendChild(panel);

        var area = panel.querySelector('.pp-cp__area');
        var handle = panel.querySelector('.pp-cp__area-handle');
        var hue = panel.querySelector('.pp-cp__hue input');
        var eyedrop = panel.querySelector('.pp-cp__eyedrop');
        var recentsBox = panel.querySelector('.pp-cp__recents');

        function currentHex() { return hsvToHex(hsv.h, hsv.s, hsv.v); }

        function paint() {
            var hex = currentHex();
            trigger.style.background = hex;
            trigger.style.color = readableOn(hex);
            trigger.setAttribute('aria-label', pp.t('js.cp.trigger_aria', { hex: hex }));
            area.style.background =
                'linear-gradient(to top,#000,transparent),linear-gradient(to right,#fff,' + hsvToHex(hsv.h, 1, 1) + ')';
            handle.style.left = (hsv.s * 100) + '%';
            handle.style.top = ((1 - hsv.v) * 100) + '%';
            handle.style.background = hex;
            hue.value = String(Math.round(hsv.h));
            area.setAttribute('aria-valuetext', hex);
        }

        // `emitting` corta el bucle: nuestro propio evento `input` vuelve a
        // entrar por el listener del campo HEX, que llamaría a commit otra vez.
        var emitting = false;

        function commit(hex, fromInput) {
            if (!fromInput) input.value = hex;
            paint();
            emitting = true;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            emitting = false;
        }

        function setFromHex(hex, fromInput) {
            var rgb = hexToRgb(hex);
            var next = rgbToHsv(rgb.r, rgb.g, rgb.b);
            // Un color acromático no tiene tono propio: conservamos el que había
            // para que mover la luminosidad a negro y volver no reinicie el tono.
            hsv = { h: next.s === 0 ? hsv.h : next.h, s: next.s, v: next.v };
            commit(hex, fromInput);
        }

        function renderRecents() {
            var list = readRecents();
            recentsBox.innerHTML = '';
            list.forEach(function (hex) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'pp-cp__recent';
                b.style.background = hex;
                b.title = hex;
                b.setAttribute('aria-label', pp.t('js.cp.use_aria', { hex: hex }));
                b.addEventListener('click', function () { setFromHex(hex); });
                recentsBox.appendChild(b);
            });
            recentsBox.hidden = list.length === 0;
        }

        // --- área saturación / luminosidad
        function pickFromEvent(event) {
            var box = area.getBoundingClientRect();
            var x = Math.min(Math.max(event.clientX - box.left, 0), box.width);
            var y = Math.min(Math.max(event.clientY - box.top, 0), box.height);
            hsv.s = box.width ? x / box.width : 0;
            hsv.v = box.height ? 1 - y / box.height : 0;
            commit(currentHex());
        }

        area.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            // `preventScroll`: enfocar puede desplazar la página, y entonces el
            // rectángulo que leemos ya no corresponde a las coordenadas del
            // evento — el color elegido saldría desplazado (o negro del todo).
            area.focus({ preventScroll: true });
            pickFromEvent(event);
            // La captura es una comodidad (seguir arrastrando fuera del área);
            // si el navegador la rechaza, el color ya está elegido igualmente.
            try { area.setPointerCapture(event.pointerId); } catch (e) { /* noop */ }
            var move = function (e) { pickFromEvent(e); };
            var up = function () {
                area.removeEventListener('pointermove', move);
                area.removeEventListener('pointerup', up);
                pushRecent(currentHex());
                renderRecents();
            };
            area.addEventListener('pointermove', move);
            area.addEventListener('pointerup', up);
        });

        area.addEventListener('keydown', function (event) {
            var step = event.shiftKey ? 0.1 : 0.02;
            var handled = true;
            if (event.key === 'ArrowLeft') hsv.s = Math.max(0, hsv.s - step);
            else if (event.key === 'ArrowRight') hsv.s = Math.min(1, hsv.s + step);
            else if (event.key === 'ArrowUp') hsv.v = Math.min(1, hsv.v + step);
            else if (event.key === 'ArrowDown') hsv.v = Math.max(0, hsv.v - step);
            else handled = false;
            if (handled) {
                event.preventDefault();
                commit(currentHex());
            }
        });

        hue.addEventListener('input', function () {
            hsv.h = parseInt(hue.value, 10) || 0;
            commit(currentHex());
        });

        // --- campo HEX: se acepta lo que sea válido, y lo inválido no destruye
        //     el valor anterior (se restaura al salir del campo).
        // Sirve para lo que escribe el usuario Y para los cambios externos
        // (un swatch que reescribe el campo y dispara `input`).
        input.addEventListener('input', function () {
            if (emitting) return;
            var hex = normalizeHex(input.value);
            if (hex) setFromHex(hex, true);
        });
        input.addEventListener('blur', function () {
            var hex = normalizeHex(input.value);
            input.value = hex || currentHex();
            if (hex) pushRecent(hex);
            renderRecents();
            paint();
        });

        // --- cuentagotas (solo donde el navegador lo trae)
        if (typeof window.EyeDropper === 'function') {
            eyedrop.hidden = false;
            eyedrop.addEventListener('click', function () {
                new window.EyeDropper().open().then(function (result) {
                    var hex = normalizeHex(result.sRGBHex);
                    if (hex) { setFromHex(hex); pushRecent(hex); renderRecents(); }
                }).catch(function () { /* cancelado con Escape */ });
            });
        }

        // --- apertura / cierre
        function toggle(next) {
            open = next;
            panel.hidden = !open;
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) { renderRecents(); paint(); }
        }
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            toggle(!open);
        });
        document.addEventListener('click', function (event) {
            if (open && !wrap.contains(event.target)) toggle(false);
        });
        wrap.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && open) { toggle(false); trigger.focus(); }
        });

        /**
         * Para quien cambia el color desde fuera (un swatch, una paleta): pone
         * el valor y repinta SIN disparar eventos. Si los disparara, el propio
         * formulario reaccionaría al cambio que él acaba de provocar — en el
         * onboarding eso desmarcaba el swatch recién pulsado.
         */
        function syncExternal(hex) {
            var clean = normalizeHex(hex);
            if (!clean) return;
            var rgb = hexToRgb(clean);
            var next = rgbToHsv(rgb.r, rgb.g, rgb.b);
            hsv = { h: next.s === 0 ? hsv.h : next.h, s: next.s, v: next.v };
            input.value = clean;
            paint();
        }

        paint();
        var api = { set: setFromHex, sync: syncExternal, get: currentHex };
        input.ppColorPicker = api;
        return api;
    }

    function init(root) {
        (root || document).querySelectorAll('input[data-pp-color]').forEach(attach);
    }

    window.PPColorPicker = { attach: attach, init: init, normalizeHex: normalizeHex, readableOn: readableOn };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
