/**
 * Design system editor — tabs, color sync, live preview.
 */
(function () {
    'use strict';

    var SCHEMA = window.PP_DESIGN_SCHEMA || {};
    var FONTS  = window.PP_DESIGN_FONTS || {};

    var form    = document.getElementById('pp-design-form');
    var preview = document.getElementById('pp-design-preview');
    var frame   = document.getElementById('pp-design-preview-frame');
    if (!form || !preview) return;

    // Reset scroll of preview frame to top on load
    if (frame) {
        requestAnimationFrame(function () { frame.scrollTop = 0; });
    }

    // ---------- Viewport toggle (desktop/tablet/mobile) ----------
    if (frame) {
        var vpBtns = document.querySelectorAll('.pp-vp-btn');
        vpBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                var vp = b.dataset.viewport;
                frame.dataset.viewport = vp;
                vpBtns.forEach(function (x) { x.classList.toggle('is-active', x === b); });
            });
        });
    }

    // ---------- Tabs ----------
    var tabs = form.querySelectorAll('.pp-tab');
    var panels = form.querySelectorAll('.pp-tab-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.dataset.tab;
            tabs.forEach(function (t) {
                var on = t.dataset.tab === target;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.classList.toggle('is-active', p.dataset.panel === target);
            });
        });
    });

    // ---------- Color picker ↔ hex text sync ----------
    form.querySelectorAll('.pp-color-input').forEach(function (wrap) {
        var color = wrap.querySelector('input[type=color]');
        var hex   = wrap.querySelector('.pp-color-hex');
        if (!color || !hex) return;

        color.addEventListener('input', function () {
            hex.value = color.value;
            updatePreviewField(wrap.closest('.pp-design-field'), color.value);
        });
        hex.addEventListener('input', function () {
            var v = hex.value.trim();
            if (!v.startsWith('#')) v = '#' + v;
            if (/^#[0-9a-f]{6}$/i.test(v)) {
                color.value = v.toLowerCase();
                hex.classList.remove('is-invalid');
                updatePreviewField(wrap.closest('.pp-design-field'), v);
            } else {
                hex.classList.add('is-invalid');
            }
        });
        hex.addEventListener('blur', function () {
            if (!hex.value.startsWith('#')) hex.value = '#' + hex.value;
        });
    });

    // ---------- Range live value display ----------
    form.querySelectorAll('input[type=range]').forEach(function (range) {
        var field = range.closest('.pp-design-field');
        var numEl = field && field.querySelector('.pp-range-value__num');
        range.addEventListener('input', function () {
            if (numEl) numEl.textContent = range.value;
            updatePreviewField(field, range.value);
        });
    });

    // ---------- Select / font change ----------
    form.querySelectorAll('select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var field = sel.closest('.pp-design-field');
            updatePreviewField(field, sel.value);
            // Cargar Google Font dinámicamente si es font
            if (sel.dataset.ppDesignInput === 'font') {
                ensureGoogleFont(sel.value);
            }
        });
    });

    // ---------- Apply value to preview ----------
    function updatePreviewField(field, rawValue) {
        if (!field) return;
        var cssVar = field.dataset.cssVar;
        if (!cssVar) return;
        var type = field.dataset.type;
        var unit = field.dataset.unit || '';
        var value = rawValue;

        if (type === 'range') {
            value = value + unit;
        } else if (type === 'font') {
            value = fontCssValue(value);
        }

        // Shadow usa clase en lugar de valor CSS (tokens → preset real)
        if (cssVar === '--pp-btn-shadow') {
            applyShadow(rawValue);
        } else {
            preview.style.setProperty(cssVar, value);
        }
    }

    function applyShadow(level) {
        preview.classList.remove('pp-shadow-none', 'pp-shadow-sm', 'pp-shadow-md', 'pp-shadow-lg');
        preview.classList.add('pp-shadow-' + (level || 'sm'));
    }

    // Inicializa la clase de shadow según el valor actual del select
    var shadowSel = form.querySelector('[name="buttons[shadow]"]');
    if (shadowSel) applyShadow(shadowSel.value);

    function fontCssValue(fontKey) {
        var systemStack = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif';
        if (!fontKey || fontKey === 'system') return systemStack;
        var serifs = ['Playfair Display', 'Merriweather', 'Lora'];
        var fallback = serifs.indexOf(fontKey) !== -1
            ? 'Georgia, "Times New Roman", serif'
            : systemStack;
        return '"' + fontKey + '", ' + fallback;
    }

    // ---------- Google Fonts lazy loader ----------
    var loadedFonts = {};
    // Marcar como cargadas las ya inyectadas server-side
    document.querySelectorAll('link[href*="fonts.googleapis.com"]').forEach(function (link) {
        var m = link.href.match(/family=([^&]+)/g) || [];
        m.forEach(function (chunk) {
            var name = decodeURIComponent(chunk.replace('family=', '').split(':')[0]).replace(/\+/g, ' ');
            loadedFonts[name] = true;
        });
    });

    function ensureGoogleFont(fontKey) {
        if (!fontKey || fontKey === 'system') return;
        if (loadedFonts[fontKey]) return;
        var family = fontKey.replace(/ /g, '+');
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=' + family + ':wght@300;400;500;600;700;800;900&display=swap';
        document.head.appendChild(link);
        loadedFonts[fontKey] = true;
    }
})();
