(function () {
    'use strict';

    var root = document.getElementById('pp-onboarding');
    if (!root) return;

    var csrf = root.dataset.csrf || '';
    var baseUrl = (root.dataset.baseUrl || '').replace(/\/$/, '');
    var step = Number(root.dataset.step || 1);
    var isGenerating = false;
    // ONB-REV — intent activo del paso 5 (para "Volver a proponer" con force).
    var currentIntent = '';

    bindButtons();
    bindMemoryWarning();
    bindMemoryAutofill();
    bindDesignPreview();
    bindBrandPalette();
    bindSitePalette();
    bindDropzone();
    bindBusinessPhotos();
    bindLeaveGuard();
    if (step === 5) bindIntentPicker();

    function bindButtons() {
        root.querySelectorAll('[data-onboarding-form]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('[data-next-button]');
                setBusy(button, true, 'Guardando…');
            });
        });
    }

    function bindMemoryWarning() {
        var field = root.querySelector('[name="business_description"]');
        var warning = root.querySelector('[data-business-warning]');
        if (!field || !warning) return;
        var update = function () {
            warning.hidden = field.value.trim() === '' || field.value.trim().length >= 20;
        };
        field.addEventListener('input', update);
        update();
    }

    function bindMemoryAutofill() {
        var panel = root.querySelector('[data-memory-autofill]');
        if (!panel) return;
        var fileInput = panel.querySelector('[data-memory-autofill-file]');
        var fileLabel = panel.querySelector('[data-memory-autofill-file-label]');
        var button = panel.querySelector('[data-memory-autofill-button]');
        var status = panel.querySelector('[data-memory-autofill-status]');
        if (!fileInput || !button || !status) return;

        fileInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(fileInput.files || []);
            var total = files.reduce(function (sum, file) { return sum + (file.size || 0); }, 0);
            if (fileLabel) {
                fileLabel.textContent = files.length === 0
                    ? pp.t('js.onb.choose_docs')
                    : (files.length === 1 ? files[0].name : pp.t('js.onb.docs_selected', { n: files.length }));
            }
            status.textContent = files.length
                ? 'Listo para analizar: ' + (files.length === 1 ? formatBytes(total) : files.length + ' documentos · ' + formatBytes(total))
                : '';
            status.className = '';
        });

        button.addEventListener('click', function () {
            var files = Array.prototype.slice.call(fileInput.files || []);
            if (!files.length) {
                status.textContent = pp.t('js.onb.pick_docs');
                status.className = 'is-error';
                return;
            }
            var data = new FormData();
            data.set('_csrf', csrf);
            files.forEach(function (file) { data.append('dossier[]', file); });
            setBusy(button, true, 'Leyendo documentos…');
            status.textContent = pp.t('js.onb.extracting');
            status.className = 'is-loading';
            fetch(baseUrl + '/admin/onboarding/autofill-memory', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (res) {
                return res.text().then(function (text) {
                    var body = {};
                    try {
                        body = text ? JSON.parse(text) : {};
                    } catch (err) {
                        throw new Error(res.ok ? pp.t('js.onb.bad_response') : ('HTTP ' + res.status + ': ' + pp.t('js.onb.not_json')));
                    }
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                applyMemoryFields(body.fields || {});
                var msg = 'Campos rellenados. Revisa y ajusta lo que quieras antes de continuar.';
                if (body.company_name) msg += ' Empresa detectada: ' + body.company_name + '.';
                if (body.documents && body.documents.length > 1) msg += ' ' + pp.t('js.onb.docs_read', { n: body.documents.length });
                if (body.model) msg += ' Modelo: ' + body.model + '.';
                status.textContent = msg;
                status.className = 'is-success';
            }).catch(function (err) {
                status.textContent = err.message || 'No hemos podido analizar los documentos.';
                status.className = 'is-error';
            }).finally(function () {
                setBusy(button, false, 'Rellenar con IA');
            });
        });
    }

    function applyMemoryFields(fields) {
        Object.keys(fields || {}).forEach(function (key) {
            var field = root.querySelector('[name="' + cssEscape(key) + '"]');
            if (!field) return;
            field.value = fields[key] || '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function bindDesignPreview() {
        var form = root.querySelector('[data-design-form]');
        var preview = root.querySelector('[data-design-preview]');
        if (!form || !preview) return;

        form.addEventListener('input', function (event) {
            if (event.target.matches('[data-color-custom]')) {
                var name = event.target.getAttribute('data-color-custom');
                syncHex(name, event.target.value);
                var radio = form.querySelector('[name="' + cssEscape(name) + '"][value="' + cssEscape(event.target.value) + '"]');
                if (!radio) {
                    form.querySelectorAll('[name="' + cssEscape(name) + '"]').forEach(function (item) {
                        item.checked = false;
                    });
                }
            }
            if (event.target.matches('[data-color-hex]')) {
                var hexName = event.target.getAttribute('data-color-hex');
                var clean = normalizeHex(event.target.value);
                if (clean) {
                    syncColorPicker(hexName, clean);
                    form.querySelectorAll('[name="' + cssEscape(hexName) + '"]').forEach(function (item) {
                        item.checked = false;
                    });
                }
            }
            updatePreview();
        });
        form.addEventListener('change', function (event) {
            if (event.target.matches('input[type="radio"][name$="_color"]')) {
                var custom = form.querySelector('[data-color-custom="' + cssEscape(event.target.name) + '"]');
                if (custom) custom.value = event.target.value;
                syncHex(event.target.name, event.target.value);
            }
            updatePreview();
        });
        updatePreview();

        function updatePreview() {
            var primary = selectedColor(form, 'primary_color') || '#ea580c';
            var secondary = selectedColor(form, 'secondary_color') || '#1c1917';
            // ONB2 O2.5 — Si hay paleta elegida, manda ella: es lo que va a
            // tener la web. El color principal solo es la semilla.
            var palette = selectedPalette(form);
            if (palette) {
                primary = palette.accent || primary;
                secondary = palette.text || secondary;
                preview.style.background = palette.bg || '';
                preview.style.borderColor = palette.line || '';
            }
            var radius = form.querySelector('[name="border_radius"]');
            var radiusValue = radius ? radius.value : '8';
            var radiusLabel = form.querySelector('[data-radius-label]');
            var font = form.querySelector('[data-preview-font]');
            var selectedFont = font && font.selectedOptions ? font.selectedOptions[0] : null;
            var brandName = form.querySelector('[data-brand-name]');
            var previewName = root.querySelector('[data-preview-brand-name]');
            var previewKicker = root.querySelector('[data-preview-brand-kicker]');
            var name = brandName && brandName.value.trim() ? brandName.value.trim() : 'Tu marca';
            if (radiusLabel) radiusLabel.textContent = Number(radiusValue) >= 60 ? 'Redondas' : radiusValue + ' px';
            if (previewName) previewName.textContent = name;
            if (previewKicker) previewKicker.textContent = name;
            preview.style.setProperty('--ob-primary', primary);
            preview.style.setProperty('--ob-secondary', secondary);
            updatePaletteCards(primary);
            preview.style.setProperty('--ob-radius', radiusValue + 'px');
            preview.style.setProperty('--ob-font-heading', fontStack(selectedFont ? selectedFont.dataset.heading : 'Inter'));
            preview.style.setProperty('--ob-font-body', fontStack(selectedFont ? selectedFont.dataset.body : 'Inter'));
        }

        function updatePaletteCards(primary) {
            var cards = form.querySelectorAll('[data-palette-swatches]');
            if (!cards.length) return;
            cards.forEach(function (wrap) {
                var slug = wrap.getAttribute('data-palette-swatches') || '';
                var colors = paletteFor(slug, primary);
                wrap.querySelectorAll('b').forEach(function (dot, index) {
                    if (colors[index]) dot.style.background = colors[index];
                });
            });
        }

        function selectedPalette(scope) {
            var hidden = scope.querySelector('[data-palette-value]');
            if (!hidden || !hidden.value) return null;
            try { return JSON.parse(hidden.value); } catch (err) { return null; }
        }

        function syncHex(name, value) {
            var hex = form.querySelector('[data-color-hex="' + cssEscape(name) + '"]');
            if (!hex || !normalizeHex(value)) return;
            // ONB2 O2.3 — Si el picker propio está montado sobre el campo, hay que
            // avisarle: escribir `.value` a pelo deja su muestra con el color viejo.
            if (hex.ppColorPicker) hex.ppColorPicker.sync(normalizeHex(value));
            else hex.value = normalizeHex(value);
        }

        function syncColorPicker(name, value) {
            var picker = form.querySelector('[data-color-custom="' + cssEscape(name) + '"]');
            if (picker) picker.value = value;
        }

        function paletteFor(slug, primary) {
            var p = normalizeHex(primary) || '#ea580c';
            var dark = mix('#111111', p, 0.06);
            var accentWarm = shiftHue(p, 46);
            var accentCool = shiftHue(p, -82);
            var accentOpp = shiftHue(p, 172);
            if (slug === 'night-citrus' || slug === 'depth-teal') {
                if (slug === 'depth-teal') {
                    return [mix('#0f1d22', p, 0.20), mix('#162a30', p, 0.22), '#eef6f3', p, mix('#75e0c1', accentCool, 0.45)];
                }
                return [mix('#101014', p, 0.24), mix('#191a20', p, 0.30), '#f4f6fb', p, mix('#ffb547', accentWarm, 0.28)];
            }
            if (slug === 'cream-ink') {
                return [mix('#f3ddc9', p, 0.13), mix('#fff7e8', p, 0.08), dark, p, mix('#c97a2b', accentWarm, 0.40)];
            }
            if (slug === 'ink-bone') {
                return [mix('#f5f1e8', p, 0.06), '#ffffff', mix('#101010', p, 0.04), p, mix('#f2c94c', accentWarm, 0.28)];
            }
            if (slug === 'paper-cobalt') {
                return [mix('#fcfaf4', p, 0.04), '#ffffff', mix('#0d1a3d', p, 0.10), p, mix('#f2c94c', accentOpp, 0.22)];
            }
            if (slug === 'agave') {
                return [mix('#eaf1dc', p, 0.08), '#ffffff', mix('#1c2818', p, 0.12), p, mix('#c19c4f', accentWarm, 0.38)];
            }
            if (slug === 'boutique-rosa') {
                return [mix('#fde7e8', p, 0.10), '#ffffff', mix('#3b1c1c', p, 0.12), p, mix('#ce8b76', accentWarm, 0.34)];
            }
            return ['#ffffff', '#f5f5f4', '#0f0f0f', p, accentOpp];
        }

        function mix(a, b, weightB) {
            var ar = hexToRgb(a), br = hexToRgb(b);
            return rgbToHex(
                Math.round(ar[0] * (1 - weightB) + br[0] * weightB),
                Math.round(ar[1] * (1 - weightB) + br[1] * weightB),
                Math.round(ar[2] * (1 - weightB) + br[2] * weightB)
            );
        }

        function shiftHue(hex, deg) {
            var rgb = hexToRgb(hex);
            var hsl = rgbToHsl(rgb[0], rgb[1], rgb[2]);
            hsl[0] = (hsl[0] + deg + 360) % 360;
            return hslToHex(hsl[0], hsl[1], hsl[2]);
        }

        function hexToRgb(hex) {
            hex = (normalizeHex(hex) || '#000000').slice(1);
            return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
        }

        function rgbToHex(r, g, b) {
            return '#' + [r, g, b].map(function (n) {
                return Math.max(0, Math.min(255, n)).toString(16).padStart(2, '0');
            }).join('');
        }

        function rgbToHsl(r, g, b) {
            r /= 255; g /= 255; b /= 255;
            var max = Math.max(r, g, b), min = Math.min(r, g, b);
            var h = 0, s = 0, l = (max + min) / 2;
            if (max !== min) {
                var d = max - min;
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
                else if (max === g) h = (b - r) / d + 2;
                else h = (r - g) / d + 4;
                h *= 60;
            }
            return [h, s, l];
        }

        function hslToHex(h, s, l) {
            var c = (1 - Math.abs(2 * l - 1)) * s;
            var x = c * (1 - Math.abs((h / 60) % 2 - 1));
            var m = l - c / 2;
            var rgb = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
            return rgbToHex(Math.round((rgb[0] + m) * 255), Math.round((rgb[1] + m) * 255), Math.round((rgb[2] + m) * 255));
        }
    }

    /**
     * ONB2 O2.4 — Colores de marca: lista de HEX (con el picker propio en cada
     * uno) más un botón que los saca del logo ya subido.
     */
    function bindBrandPalette() {
        var panel = root.querySelector('[data-brand-palette]');
        if (!panel) return;
        var list = panel.querySelector('[data-brand-palette-list]');
        var addBtn = panel.querySelector('[data-brand-palette-add]');
        var extractBtn = panel.querySelector('[data-brand-palette-extract]');
        var status = panel.querySelector('[data-brand-palette-status]');
        var max = Number(panel.dataset.max || 5);

        function colors() {
            return Array.prototype.map.call(
                list.querySelectorAll('input[name="brand_palette[]"]'),
                function (input) { return (input.value || '').toLowerCase(); }
            );
        }

        function addColor(hex) {
            if (colors().length >= max) return false;
            if (colors().indexOf(String(hex).toLowerCase()) !== -1) return false;
            var item = document.createElement('div');
            item.className = 'pp-onboarding-brandpalette__item';
            item.innerHTML = '<input type="text" name="brand_palette[]" maxlength="7" data-pp-color aria-label="Color de marca">'
                + '<button type="button" data-brand-palette-remove aria-label="Quitar este color">×</button>';
            var input = item.querySelector('input');
            input.value = hex;
            list.appendChild(item);
            if (window.PPColorPicker) window.PPColorPicker.attach(input);
            updateAddState();
            return true;
        }

        function updateAddState() {
            if (addBtn) addBtn.disabled = colors().length >= max;
        }

        list.addEventListener('click', function (event) {
            var button = event.target.closest('[data-brand-palette-remove]');
            if (!button) return;
            button.closest('.pp-onboarding-brandpalette__item').remove();
            updateAddState();
        });

        if (addBtn) addBtn.addEventListener('click', function () {
            // Un color nuevo arranca del principal: es el que el usuario ya ha
            // mirado, y así el picker abre en un sitio con sentido.
            var primary = root.querySelector('[data-color-hex="primary_color"]');
            addColor((primary && normalizeHex(primary.value)) || '#2563eb');
        });

        if (extractBtn) extractBtn.addEventListener('click', function () {
            var data = new FormData();
            data.set('_csrf', csrf);
            setBusy(extractBtn, true, 'Mirando el logo…');
            if (status) { status.textContent = ''; status.className = ''; }
            fetch(baseUrl + '/admin/onboarding/extract-logo-colors', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (res) {
                return res.text().then(function (text) {
                    var body = {};
                    try { body = text ? JSON.parse(text) : {}; }
                    catch (err) { throw new Error(pp.t('js.onb.bad_response')); }
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                var added = 0;
                (body.colors || []).forEach(function (hex) { if (addColor(hex)) added++; });
                if (status) {
                    status.textContent = added === 0
                        ? 'Esos colores ya estaban en la lista.'
                        : (added === 1 ? pp.t('js.onb.logo_color_one') : pp.t('js.onb.logo_colors_other', { n: added }));
                    status.className = added === 0 ? '' : 'is-success';
                }
            }).catch(function (err) {
                if (status) {
                    status.textContent = err.message || 'No hemos podido leer el logo.';
                    status.className = 'is-error';
                }
            }).finally(function () {
                setBusy(extractBtn, false, 'Extraer del logo');
            });
        });

        updateAddState();
    }

    /**
     * ONB2 O2.5 — Paletas de la web: se piden al servidor (que llama a la IA y
     * corrige los contrastes) y se pintan como tarjetas elegibles. Lo que viaja
     * en el formulario es el JSON de la paleta marcada.
     */
    function bindSitePalette() {
        var field = root.querySelector('[data-palette-field]');
        if (!field) return;
        var grid = field.querySelector('[data-palette-grid]');
        var empty = field.querySelector('[data-palette-empty]');
        var button = field.querySelector('[data-palette-generate]');
        var status = field.querySelector('[data-palette-status]');
        var hidden = field.querySelector('[data-palette-value]');

        function applySelection() {
            var checked = grid.querySelector('input[name="palette_choice"]:checked');
            if (!checked) { hidden.value = ''; return; }
            var raw = checked.getAttribute('data-palette-tokens') || '';
            hidden.value = raw;
            // El preview lo repinta `bindDesignPreview`, que ya sabe que la
            // paleta manda sobre el color principal: basta con avisarle.
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }

        grid.addEventListener('change', function (event) {
            if (event.target.name === 'palette_choice') applySelection();
        });

        function card(palette) {
            var order = ['bg', 'surface', 'text', 'accent', 'accent_2'];
            var label = document.createElement('label');
            label.className = 'pp-onboarding-palette-card';
            var dots = order.map(function (key) {
                return '<b style="background:' + (palette.tokens[key] || '#ffffff') + '"></b>';
            }).join('');
            label.innerHTML = '<input type="radio" name="palette_choice">'
                + '<span><strong></strong><i>' + dots + '</i><em></em></span>';
            label.querySelector('strong').textContent = palette.name || 'Paleta';
            label.querySelector('em').textContent = palette.rationale || '';
            label.querySelector('input').setAttribute('data-palette-tokens', JSON.stringify(palette.tokens));
            return label;
        }

        if (button) button.addEventListener('click', function () {
            var data = new FormData();
            data.set('_csrf', csrf);
            root.querySelectorAll('input[name="brand_palette[]"]').forEach(function (input) {
                data.append('brand_palette[]', input.value);
            });
            var primary = root.querySelector('[data-color-hex="primary_color"]');
            if (primary) data.set('primary_color_hex', primary.value);

            setBusy(button, true, 'Pensando en color…');
            status.textContent = pp.t('js.onb.deriving_palettes');
            status.className = 'is-loading';
            fetch(baseUrl + '/admin/onboarding/generate-palette', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (res) {
                return res.text().then(function (text) {
                    var body = {};
                    try { body = text ? JSON.parse(text) : {}; }
                    catch (err) { throw new Error(pp.t('js.onb.bad_response')); }
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                var palettes = body.palettes || [];
                // Las propuestas nuevas sustituyen a las anteriores, pero la
                // paleta ya guardada se queda: es a lo que puede volver.
                grid.querySelectorAll('[data-palette-generated]').forEach(function (el) { el.remove(); });
                palettes.forEach(function (palette) {
                    var el = card(palette);
                    el.setAttribute('data-palette-generated', '1');
                    grid.appendChild(el);
                });
                if (palettes.length) {
                    var first = grid.querySelector('[data-palette-generated] input');
                    if (first) { first.checked = true; applySelection(); }
                }
                if (empty) empty.hidden = true;
                status.textContent = body.notice
                    || (pp.t('js.onb.palettes_ready', { n: palettes.length }) + (body.model ? ' · ' + body.model : '') + '. ' + pp.t('js.onb.pick_palette'));
                status.className = body.fallback ? '' : 'is-success';
            }).catch(function (err) {
                status.textContent = err.message || 'No hemos podido preparar las paletas.';
                status.className = 'is-error';
            }).finally(function () {
                setBusy(button, false, 'Generar paletas con IA');
            });
        });

        applySelection();
    }

    function bindDropzone() {
        var input = root.querySelector('.pp-onboarding-dropzone input[type="file"]');
        var state = root.querySelector('[data-file-state]');
        if (input && state) input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files || []);
            if (!files.length) {
                state.textContent = '';
                return;
            }
            var total = files.reduce(function (sum, file) { return sum + (file.size || 0); }, 0);
            if (files.length === 1) {
                state.textContent = files[0].name + ' · ' + formatBytes(files[0].size);
                return;
            }
            state.textContent = pp.t('js.onb.docs_selected', { n: files.length }) + ' · ' + formatBytes(total);
        });

        // ONB2 O2.2 — Dos zonas de logo (fondo claro / fondo oscuro): el mismo
        // comportamiento para cada una, y solo la principal alimenta el preview.
        var designPreview = root.querySelector('[data-design-preview]');
        root.querySelectorAll('[data-logo-dropzone]').forEach(function (wrap) {
            var input = wrap.querySelector('input[type="file"]');
            var state = wrap.querySelector('[data-logo-state]');
            var slot = wrap.querySelector(':scope > span');
            var img = wrap.querySelector('img');
            var variant = wrap.getAttribute('data-logo-dropzone') || 'light';
            if (!input || !state) return;

            input.addEventListener('change', function () {
                var file = input.files && input.files[0] ? input.files[0] : null;
                if (!file) return;
                state.textContent = file.name + ' · ' + formatBytes(file.size) + ' · ' + pp.t('js.onb.will_save_one');
                state.className = 'is-success';
                wrap.classList.add('has-file');
                if (!img && slot) {
                    slot.innerHTML = '<img src="" alt="">';
                    img = slot.querySelector('img');
                }
                // Subir una versión y no tener ninguna marcada por defecto deja
                // el sitio sin logo: marcamos esta si nadie ha elegido aún.
                var primary = root.querySelector('input[name="logo_primary"]:checked');
                if (!primary) {
                    var own = root.querySelector('input[name="logo_primary"][value="' + cssEscape(variant) + '"]');
                    if (own) own.checked = true;
                }
                if (typeof FileReader !== 'undefined') {
                    var reader = new FileReader();
                    reader.onload = function (event) {
                        if (event && event.target && typeof event.target.result === 'string') {
                            if (img) img.src = event.target.result;
                            var chosen = root.querySelector('input[name="logo_primary"]:checked');
                            if (!chosen || chosen.value === variant) updateDesignPreviewLogo(event.target.result);
                        }
                    };
                    reader.readAsDataURL(file);
                }
            });
        });

        // FONTS · ONB2 O2.7 — Un hueco por rol (títulos / textos), cada uno con
        // su propio estado; y la casilla "la misma para ambos" oculta el de textos.
        root.querySelectorAll('[data-onboarding-fonts]').forEach(function (input) {
            var state = input.closest('label').querySelector('[data-onboarding-fonts-state]');
            if (!state) return;
            input.addEventListener('change', function () {
                var files = input.files ? Array.prototype.slice.call(input.files) : [];
                if (!files.length) {
                    state.textContent = pp.t('js.onb.no_file');
                    state.className = '';
                    return;
                }
                var total = files.reduce(function (sum, f) { return sum + f.size; }, 0);
                state.textContent = files.length + (files.length === 1 ? ' archivo' : ' archivos')
                    + ' · ' + formatBytes(total) + ' · ' + pp.t('js.onb.will_save_other');
                state.className = 'is-success';
            });
        });

        var fontsSame = root.querySelector('[data-fonts-same]');
        if (fontsSame) {
            var bodySlot = root.querySelector('[data-font-slot="body"]');
            var headingSlot = root.querySelector('[data-font-slot="heading"]');
            var syncSlots = function () {
                if (bodySlot) bodySlot.hidden = fontsSame.checked;
                if (headingSlot) {
                    var title = headingSlot.querySelector('strong');
                    if (title) title.textContent = fontsSame.checked ? pp.t('js.onb.for_both') : pp.t('js.onb.for_headings');
                }
            };
            fontsSame.addEventListener('change', syncSlots);
            syncSlots();
        }

        var referenceInput = root.querySelector('[data-reference-dropzone] input[type="file"]');
        var referenceWrap = root.querySelector('[data-reference-dropzone]');
        var referenceState = root.querySelector('[data-reference-state]');
        if (referenceInput && referenceState) referenceInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(referenceInput.files || []);
            if (!files.length) return;
            if (referenceWrap) referenceWrap.classList.add('has-file');
            var total = files.reduce(function (sum, file) { return sum + (file.size || 0); }, 0);
            referenceState.textContent = pp.t(files.length === 1 ? 'js.onb.refs_selected_one' : 'js.onb.refs_selected_other', { n: files.length }) + ' · ' + formatBytes(total) + ' · Guardando…';
            referenceState.className = 'is-loading';

            var data = new FormData();
            data.set('_csrf', csrf);
            files.forEach(function (file) { data.append('visual_references[]', file); });
            fetch(baseUrl + '/admin/onboarding/upload-references', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                var count = Number(body.count || files.length);
                referenceState.textContent = pp.t(count === 1 ? 'js.onb.refs_saved_one' : 'js.onb.refs_saved_other', { n: count });
                referenceState.className = 'is-success';
                referenceInput.value = '';
            }).catch(function (err) {
                referenceState.textContent = err.message || 'No se pudieron guardar las referencias.';
                referenceState.className = 'is-error';
            });
        });

        function updateDesignPreviewLogo(src) {
            if (!designPreview) return;
            var logo = designPreview.querySelector('[data-preview-logo]');
            var fallback = designPreview.querySelector('[data-preview-logo-fallback]');
            if (!logo) {
                logo = document.createElement('img');
                logo.setAttribute('data-preview-logo', '');
                logo.alt = '';
                var brand = designPreview.querySelector('.pp-onboarding-preview-brand');
                if (brand) brand.insertBefore(logo, brand.firstChild);
            }
            logo.src = src;
            if (fallback) fallback.remove();
        }
    }

    // ONB-FOTOS — Fotos propias del paso 4. Tres cosas que tienen que pasar en
    // este orden: subir (una petición por foto, para no chocar con
    // `post_max_size`), describir con IA (por lotes, con progreso) y solo
    // entonces dejar avanzar. Sin descripción la foto llega al modelo como un
    // nombre de archivo y la generación acaba tirando del banco de imágenes.
    function bindBusinessPhotos() {
        var panel = root.querySelector('[data-photos]');
        if (!panel) return;

        var input = panel.querySelector('[data-photos-input]');
        var dropzone = panel.querySelector('[data-photos-dropzone]');
        var grid = panel.querySelector('[data-photos-grid]');
        var status = panel.querySelector('[data-photos-status]');
        var nextButton = root.querySelector('[data-next-button]');
        var max = Number(panel.dataset.max || 12);
        var busy = false;

        if (!input || !grid || !status) return;

        input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files || []);
            // Vaciar el input es lo que evita que el POST del paso vuelva a
            // subir las mismas fotos (el camino sin JS sigue vivo en el server).
            input.value = '';
            if (files.length) uploadAll(files);
        });

        if (dropzone) {
            ['dragenter', 'dragover'].forEach(function (name) {
                dropzone.addEventListener(name, function (event) {
                    event.preventDefault();
                    dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (name) {
                dropzone.addEventListener(name, function () { dropzone.classList.remove('is-dragover'); });
            });
            dropzone.addEventListener('drop', function (event) {
                event.preventDefault();
                var files = Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.files) || []);
                if (files.length) uploadAll(files);
            });
        }

        grid.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-photo-remove]');
            if (remove) removePhoto(remove.closest('[data-photo-id]'));
        });

        grid.addEventListener('change', function (event) {
            var field = event.target.closest('[data-photo-alt]');
            if (field) saveAlt(field.closest('[data-photo-id]'), field.value);
        });

        function uploadAll(files) {
            var slots = max - grid.querySelectorAll('[data-photo-id]').length;
            if (slots <= 0) {
                setStatus(pp.t('js.onb.photos_full', { max: max }), 'is-error');
                return;
            }
            var queue = files.slice(0, slots);
            var skipped = files.length - queue.length;
            setBusyState(true);

            var index = 0;
            var uploaded = 0;
            var lastError = '';

            var next = function () {
                if (index >= queue.length) {
                    if (uploaded === 0) {
                        setStatus(lastError || 'No se pudo subir ninguna foto.', 'is-error');
                        setBusyState(false);
                        return;
                    }
                    var tail = skipped > 0 ? ' ' + pp.t('js.onb.photos_skipped', { n: skipped, max: max }) : '';
                    setStatus(uploaded + (uploaded === 1 ? ' foto subida' : ' fotos subidas') + tail + '. Analizando con IA…', 'is-loading');
                    describeMissing();
                    return;
                }
                var file = queue[index++];
                setStatus(pp.t('js.onb.uploading', { archivo: file.name, i: index, total: queue.length }), 'is-loading');
                var data = new FormData();
                data.set('_csrf', csrf);
                data.set('photo', file);
                fetch(panel.dataset.uploadUrl, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (res) {
                        return res.json().then(function (body) {
                            if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                            return body;
                        });
                    })
                    .then(function (body) {
                        uploaded++;
                        addCard(body.item);
                    })
                    .catch(function (err) { lastError = err.message || 'No se pudo subir la foto.'; })
                    .then(next);
            };
            next();
        }

        // Los lotes los decide el servidor (3 por petición); aquí solo se repite
        // mientras queden y se pinta el avance.
        function describeMissing() {
            fetch(panel.dataset.describeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ _csrf: csrf })
            }).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                (body.items || []).forEach(function (item) { applyAlt(item.id, item.alt_text); });
                var remaining = Number(body.remaining || 0);
                if (body.blocked || remaining <= 0) {
                    setStatus(pendingCount() > 0
                        ? pp.t('js.onb.photos_missing_alt')
                        : pp.t('js.onb.photos_analyzed'),
                        pendingCount() > 0 ? 'is-error' : 'is-success');
                    setBusyState(false);
                    return;
                }
                setStatus(pp.t('js.onb.analyzing', { n: remaining }), 'is-loading');
                describeMissing();
            }).catch(function (err) {
                setStatus((err.message || 'No hemos podido analizar las fotos.')
                    + ' Puedes describirlas a mano o continuar igualmente.', 'is-error');
                setBusyState(false);
            });
        }

        function addCard(item) {
            if (!item || !item.id) return;
            var li = document.createElement('li');
            li.className = 'pp-onboarding-photo';
            li.setAttribute('data-photo-id', String(item.id));
            li.innerHTML = '<div class="pp-onboarding-photo__thumb">'
                + '<img src="' + escapeHtml(item.url) + '" alt="">'
                + '<button type="button" class="pp-onboarding-photo__remove" data-photo-remove aria-label="Quitar esta foto">×</button>'
                + '</div>'
                + '<textarea class="pp-onboarding-photo__alt" rows="3" data-photo-alt placeholder="' + escapeHtml(pp.t('js.onb.no_alt')) + '">'
                + escapeHtml(item.alt_text || '') + '</textarea>'
                + '<small data-photo-state>' + (item.alt_text ? 'Descrita' : 'Analizando…') + '</small>';
            grid.appendChild(li);
            grid.hidden = false;
        }

        function applyAlt(id, alt) {
            var card = grid.querySelector('[data-photo-id="' + cssEscape(String(id)) + '"]');
            if (!card) return;
            var field = card.querySelector('[data-photo-alt]');
            var state = card.querySelector('[data-photo-state]');
            if (field) field.value = alt || '';
            if (state) state.textContent = pp.t(alt ? 'js.onb.described' : 'js.onb.undescribed');
        }

        function pendingCount() {
            var pending = 0;
            grid.querySelectorAll('[data-photo-alt]').forEach(function (field) {
                if (!field.value.trim()) pending++;
            });
            return pending;
        }

        function saveAlt(card, value) {
            if (!card) return;
            var state = card.querySelector('[data-photo-state]');
            if (state) state.textContent = pp.t('js.saving');
            fetch(panel.dataset.altUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ _csrf: csrf, id: card.dataset.photoId, alt_text: value })
            }).then(function (res) { return res.json(); })
              .then(function (body) {
                  if (state) state.textContent = body && body.ok
                      ? pp.t(body.alt_text ? 'js.onb.described' : 'js.onb.undescribed')
                      : 'No se pudo guardar';
              })
              .catch(function () { if (state) state.textContent = pp.t('js.onb.save_failed'); });
        }

        function removePhoto(card) {
            if (!card) return;
            fetch(panel.dataset.deleteUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({ _csrf: csrf, id: card.dataset.photoId })
            }).then(function (res) { return res.json(); })
              .then(function (body) {
                  if (!body || !body.ok) throw new Error(body && body.error);
                  card.remove();
                  if (!grid.querySelector('[data-photo-id]')) {
                      grid.hidden = true;
                      setStatus(pp.t('js.onb.photos_none'), '');
                  }
              })
              .catch(function () { setStatus(pp.t('js.onb.photo_remove_failed'), 'is-error'); });
        }

        function setStatus(text, className) {
            status.hidden = false;
            status.textContent = text;
            status.className = 'pp-onboarding-photos__status ' + (className || '');
        }

        // Mientras se sube o se describe, avanzar generaría la web sin las fotos
        // (o con fotos que el modelo aún no entiende).
        function setBusyState(value) {
            busy = value;
            if (nextButton) {
                nextButton.disabled = value;
                nextButton.classList.toggle('is-busy', value);
            }
            if (input) input.disabled = value;
        }

        window.addEventListener('beforeunload', function (event) {
            if (!busy) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }

    function bindLeaveGuard() {
        window.addEventListener('beforeunload', function (event) {
            if (!isGenerating) return;
            event.preventDefault();
            event.returnValue = '';
        });
        document.addEventListener('click', function (event) {
            if (!isGenerating) return;
            var target = event.target.closest('a, button[type="submit"], form button:not([type]), .pp-onboarding-topbar button');
            if (!target) return;
            if (target.matches('[data-next-button]')) return;
            var ok = window.confirm(pp.t('js.onb.confirm_leave'));
            if (!ok) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    }

    // F22.T22.1 — Picker de intent antes del análisis de arquitectura.
    // Si ya hay intent guardado en sesión previa, salta directo al análisis.
    function bindIntentPicker() {
        var archStep = root.querySelector('[data-architecture-step]');
        if (!archStep) return;
        var picker = archStep.querySelector('[data-intent-picker]');
        var savedIntent = archStep.dataset.intentSaved || '';

        if (savedIntent) {
            // Ya elegimos antes: ocultar picker y arrancar análisis automático.
            if (picker) picker.hidden = true;
            startAnalysis(savedIntent);
            return;
        }
        if (!picker) { startAnalysis(''); return; }

        var goBtn   = picker.querySelector('[data-intent-go]');
        var skipBtn = picker.querySelector('[data-intent-skip]');
        var cards   = picker.querySelectorAll('.pp-onboarding-intent-card');

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                cards.forEach(function (c) { c.classList.remove('is-active'); });
                card.classList.add('is-active');
                var input = card.querySelector('input[type="radio"]');
                if (input) input.checked = true;
                if (goBtn) goBtn.disabled = false;
            });
        });

        if (goBtn) goBtn.addEventListener('click', function () {
            var selected = picker.querySelector('input[name="intent"]:checked');
            var intent = selected ? selected.value : '';
            picker.hidden = true;
            startAnalysis(intent);
        });
        if (skipBtn) skipBtn.addEventListener('click', function () {
            picker.hidden = true;
            startAnalysis('');
        });
    }

    function startAnalysis(intent, force) {
        var loading = root.querySelector('[data-arch-loading]');
        if (loading) loading.hidden = false;
        var msg = loading && loading.querySelector('[data-loading-msg]');
        if (msg && intent) {
            // Las claves son los slugs de intent, que son datos: no se traducen.
            var labels = {
                presence:  pp.t('js.onb.loading_presence'),
                services:  pp.t('js.onb.loading_services'),
                seo:       pp.t('js.onb.loading_seo'),
                portfolio: pp.t('js.onb.loading_portfolio'),
                product:   pp.t('js.onb.loading_product')
            };
            msg.textContent = labels[intent] || pp.t('js.onb.loading_default');
        }
        analyzeArchitecture(intent, force);
    }

    function analyzeArchitecture(intent, force) {
        currentIntent = intent || '';
        var loading = root.querySelector('[data-arch-loading]');
        var resultWrap = root.querySelector('[data-arch-result]');
        var errorWrap = root.querySelector('[data-arch-error]');
        var button = root.querySelector('[data-next-button]');
        if (!loading || !resultWrap) return;

        var slowTimer = setTimeout(function () {
            var p = loading.querySelector('p');
            if (p) p.textContent = pp.t('js.onb.taking_longer');
        }, 15000);

        // ONB-REV — el análisis SEO añade una 2ª llamada IA (ideas de blog);
        // margen extra. force=1 salta la caché del servidor.
        post('/admin/onboarding/analyze', { intent: intent || '', force: force ? '1' : '' }, 120000)
            .then(function (body) {
                clearTimeout(slowTimer);
                loading.hidden = true;
                renderArchitecture(body.architecture || {}, body.blog_posts || []);
                resultWrap.hidden = false;
            })
            .catch(function () {
                clearTimeout(slowTimer);
                loading.hidden = true;
                if (errorWrap) errorWrap.hidden = false;
                if (button) button.disabled = true;
                toggleFooter(false);
            });

        // ONB-REV — bind único: "Volver a proponer" re-entra por aquí y antes
        // duplicaba el listener del botón principal.
        if (button && !button.dataset.archBound) {
            button.dataset.archBound = '1';
            button.addEventListener('click', handleArchitectureNext);
        }
    }

    // ONB-REV T1 — el footer del paso 5 nace oculto (el picker de intent trae
    // sus propios CTAs); solo se muestra cuando hay propuesta en pantalla.
    function toggleFooter(show) {
        var foot = root.querySelector('[data-onboarding-footer]');
        if (foot) foot.hidden = !show;
    }

    function renderArchitecture(architecture, blogPosts) {
        var resultWrap = root.querySelector('[data-arch-result]');
        var pages = Array.isArray(architecture.missing_pages) ? architecture.missing_pages : [];
        // D-Slice 1 (S1.13) — Ya no usamos visual_styles del backend; el skin
        // se compone automáticamente al entrar al stage "style".
        root.dataset.visualStyle = '';
        var rows = pages.map(function (page, index) {
            var priority = priorityLabel(page);
            var checked = isEssentialPage(page);
            return [
                '<label class="pp-onboarding-page-card" style="--delay:' + index + '">',
                    '<input type="checkbox" data-proposed-page="' + escapeHtml(JSON.stringify(page)) + '" ' + (checked ? 'checked' : '') + '>',
                    '<span class="pp-onboarding-check"></span>',
                    '<span class="pp-onboarding-page-card__body">',
                        '<small>' + escapeHtml(priority) + '</small>',
                        '<strong>' + escapeHtml(page.title || pp.t('js.onb.page')) + '</strong>',
                        '<em>' + escapeHtml(page.reason || page.goal || '') + '</em>',
                    '</span>',
                    '<span class="pp-onboarding-page-type">' + escapeHtml(typeLabel(page.page_type || 'landing')) + '</span>',
                    '<details><summary>' + escapeHtml(pp.t('js.onb.more_detail')) + '</summary><p>' + escapeHtml(page.goal || page.architecture_context || page.reason || '') + '</p></details>',
                '</label>'
            ].join('');
        }).join('');
        var selectedCount = pages.filter(function (page) {
            return isEssentialPage(page);
        }).length;
        var route = pages.slice(0, 5).map(function (page) {
            return '<span>' + escapeHtml(page.title || pp.t('js.onb.page')) + '</span>';
        }).join('');

        // ONB-REV T4 — Entradas de blog sugeridas (solo llegan con intent SEO):
        // premarcadas, deseleccionables, se generan tras las páginas.
        var postRows = (blogPosts || []).map(function (post, index) {
            return [
                '<label class="pp-onboarding-page-card" style="--delay:' + index + '">',
                    '<input type="checkbox" data-proposed-post="' + escapeHtml(JSON.stringify(post)) + '" checked>',
                    '<span class="pp-onboarding-check"></span>',
                    '<span class="pp-onboarding-page-card__body">',
                        '<small>Blog</small>',
                        '<strong>' + escapeHtml(post.title || pp.t('js.onb.post')) + '</strong>',
                        '<em>' + escapeHtml(post.angle || '') + '</em>',
                    '</span>',
                    '<span class="pp-onboarding-page-type">' + escapeHtml(pp.t('js.onb.post')) + '</span>',
                '</label>'
            ].join('');
        }).join('');
        var blogGroup = postRows === '' ? '' : [
            '<div class="pp-onboarding-blog-group">',
                '<header><strong>' + escapeHtml(pp.t('js.onb.posts_title')) + '</strong><span>' + escapeHtml(pp.t('js.onb.posts_desc')) + '</span></header>',
                '<div class="pp-onboarding-page-list">' + postRows + '</div>',
            '</div>'
        ].join('');

        resultWrap.innerHTML = [
            '<section class="pp-onboarding-flow-guide">',
                '<article class="is-active" data-flow-step="structure"><small>1</small><strong>' + escapeHtml(pp.t('js.onb.flow_pages')) + '</strong><p>' + escapeHtml(pp.t('js.onb.flow_pages_desc')) + '</p></article>',
                '<article data-flow-step="style"><small>2</small><strong>' + escapeHtml(pp.t('js.onb.flow_style')) + '</strong><p>' + escapeHtml(pp.t('js.onb.flow_style_desc')) + '</p></article>',
                '<article data-flow-step="generate"><small>3</small><strong>' + escapeHtml(pp.t('js.onb.flow_generate')) + '</strong><p>' + escapeHtml(pp.t('js.onb.flow_generate_desc')) + '</p></article>',
            '</section>',
            '<section class="pp-onboarding-structure-panel" data-arch-stage="structure">',
                '<div class="pp-onboarding-route" aria-label="Ruta sugerida">' + route + '</div>',
                '<div class="pp-onboarding-arch-toolbar"><strong data-selection-count>' + escapeHtml(pp.t('js.onb.n_selected', { n: selectedCount })) + '</strong><span>' + escapeHtml(pp.t('js.onb.essentials_checked')) + '</span></div>',
                '<div class="pp-onboarding-page-list">' + rows + '</div>',
                blogGroup,
                '<div class="pp-onboarding-alt-actions">',
                    '<button type="button" data-create-home>' + escapeHtml(pp.t('js.onb.only_home')) + '</button>',
                    '<button type="button" data-reanalyze>' + escapeHtml(pp.t('js.onb.reanalyze')) + '</button>',
                    '<form method="POST" action="' + baseUrl + '/admin/onboarding/skip"><input type="hidden" name="_csrf" value="' + escapeHtml(csrf) + '"><input type="hidden" name="step" value="5"><button type="submit">' + escapeHtml(pp.t('js.onb.empty_map')) + '</button></form>',
                '</div>',
            '</section>',
            '<section class="pp-onboarding-style-panel" data-arch-stage="style" hidden>',
                renderSkinPreviewStage(),
                '<div class="pp-onboarding-style-summary"><strong data-style-count>' + escapeHtml(pp.t('js.onb.pages_selected', { n: selectedCount })) + '</strong><span>' + escapeHtml(pp.t('js.onb.same_style')) + '</span><button type="button" data-back-to-structure>' + escapeHtml(pp.t('js.onb.back_to_pages')) + '</button></div>',
                '<p class="pp-onboarding-create-note" data-create-note>' + escapeHtml(pp.t('js.onb.generation_time')) + '</p>',
            '</section>',
            '<div class="pp-onboarding-generation" data-generation hidden></div>'
        ].join('');
        root.dataset.archStage = 'structure';
        setArchitectureStage('structure');
        toggleFooter(true);
        // ONB-REV — bind único: "Volver a proponer" repinta este contenedor y
        // antes se acumulaban listeners delegados (nudges dobles, etc.).
        if (!resultWrap.dataset.bound) {
            resultWrap.dataset.bound = '1';
            resultWrap.addEventListener('change', syncCreateButton);
            resultWrap.addEventListener('click', function (e) {
            var back = e.target.closest('[data-back-to-structure]');
            if (back) {
                e.preventDefault();
                setArchitectureStage('structure');
                return;
            }
            var home = e.target.closest('[data-create-home]');
            if (home) {
                e.preventDefault();
                createHomeOnly();
                return;
            }
            // ONB-REV T2 — descartar propuesta cacheada y pedir otra.
            var re = e.target.closest('[data-reanalyze]');
            if (re) {
                e.preventDefault();
                resultWrap.hidden = true;
                toggleFooter(false);
                startAnalysis(currentIntent, true);
                return;
            }
            // D-Slice 1 (S1.13/S1.14) — nudge chips + regenerar.
            var nudgeBtn = e.target.closest('[data-nudge-axis]');
            if (nudgeBtn) {
                e.preventDefault();
                if (nudgeBtn.classList.contains('is-busy')) return;
                applyNudge(
                    nudgeBtn.getAttribute('data-nudge-axis'),
                    nudgeBtn.getAttribute('data-nudge-direction'),
                    nudgeBtn
                );
                return;
            }
            var regen = e.target.closest('[data-regenerate-skin]');
            if (regen) {
                e.preventDefault();
                if (regen.classList.contains('is-busy')) return;
                composeAndShowPreview({ force: true });
                return;
            }
            });
        }
        syncCreateButton();

        // FH6 — Generamos el Inicio canvas en SEGUNDO PLANO mientras el
        // usuario elige páginas: cuando llegue al paso de estilo, el preview
        // sale (casi) al instante. Best-effort: si falla, compose-skin lo
        // genera ahí con su propio loading.
        prepareHomeInBackground();
    }

    // FH6 — Promesa del prefetch del Inicio; composeAndShowPreview espera a
    // que termine antes de pedir el preview, para no duplicar generación.
    var homePrepPromise = null;

    function prepareHomeInBackground() {
        var homeData = findHomePageData();
        if (!homeData || !homeData.title) return;
        homePrepPromise = post('/admin/onboarding/prepare-home', {
            home_page: JSON.stringify(homeData)
        }, 180000, false).catch(function (err) {
            // i18n-ignore: traza de consola para depurar, no se pinta en la UI.
            console.warn('prepareHome failed (se generará en el paso de estilo):', err);
        });
    }

    function handleArchitectureNext() {
        var stage = root.dataset.archStage || 'structure';
        if (stage === 'structure') {
            if (root.querySelectorAll('[data-proposed-page]:checked').length === 0) return;
            setArchitectureStage('style');
            return;
        }
        createSelectedPages();
    }

    function setArchitectureStage(stage) {
        var previous = root.dataset.archStage;
        root.dataset.archStage = stage;
        root.querySelectorAll('[data-arch-stage]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-arch-stage') !== stage;
        });
        root.querySelectorAll('[data-flow-step]').forEach(function (item) {
            var key = item.getAttribute('data-flow-step');
            item.classList.toggle('is-active', key === stage);
            item.classList.toggle('is-done', (stage === 'style' || stage === 'generate') && key === 'structure');
        });
        var button = root.querySelector('[data-next-button]');
        if (button) {
            button.textContent = (stage === 'style' ? pp.t('js.onb.generate_with_style') : pp.t('js.onb.continue_to_style')) + ' →';
        }
        var count = root.querySelector('[data-style-count]');
        if (count) {
            count.textContent = selectionLabel();
        }
        // D-Slice 1 (S1.13) — al entrar al stage "style" por primera vez,
        // disparar compose-skin para poblar el iframe de preview.
        if (stage === 'style' && previous !== 'style' && !root.dataset.skinComposed) {
            composeAndShowPreview({ force: false });
        }
        syncCreateButton();
    }

    // D-Slice 1 (S1.13) — Markup del sub-stage "style": preview iframe +
    // 3 pares de nudge chips + acciones (regenerar / continuar implícito en
    // el botón principal del footer).
    function renderSkinPreviewStage() {
        return [
            '<section class="pp-onboarding-skin">',
                '<header class="pp-onboarding-skin__head">',
                    '<small>Tu estilo</small>',
                    '<h3>' + escapeHtml(pp.t('js.onb.skin_title')) + '</h3>',
                    '<p>' + escapeHtml(pp.t('js.onb.skin_desc')) + '</p>',
                '</header>',
                '<div class="pp-onboarding-skin__preview" data-skin-preview>',
                    '<div class="pp-onboarding-skin__loading" data-skin-loading>',
                        '<div><span></span><span></span><span></span></div>',
                        '<p>Componiendo tu preview…</p>',
                    '</div>',
                    '<iframe data-skin-iframe title="' + pp.t('js.onb.skin_preview_title') + '" hidden></iframe>',
                    '<div class="pp-onboarding-skin__error" data-skin-error hidden>',
                        '<p>' + escapeHtml(pp.t('js.onb.skin_failed')) + '</p>',
                    '</div>',
                '</div>',
                '<div class="pp-onboarding-skin__nudges" data-skin-nudges hidden>',
                    '<span class="pp-onboarding-skin__nudges-label">' + escapeHtml(pp.t('js.onb.nudges_label')) + '</span>',
                    nudgePairHtml('warmth',    pp.t('js.onb.nudge_warmer'),  pp.t('js.onb.nudge_soberer')),
                    nudgePairHtml('modernity', pp.t('js.onb.nudge_modern'),  pp.t('js.onb.nudge_classic')),
                    nudgePairHtml('energy',    pp.t('js.onb.nudge_bolder'),  pp.t('js.onb.nudge_softer')),
                    '<button type="button" class="pp-onboarding-skin__regen" data-regenerate-skin>Regenerar desde cero</button>',
                '</div>',
            '</section>'
        ].join('');
    }

    function nudgePairHtml(axis, upLabel, downLabel) {
        return [
            '<div class="pp-onboarding-skin__nudge-pair">',
                '<button type="button" data-nudge-axis="' + axis + '" data-nudge-direction="up">' + escapeHtml(upLabel) + '</button>',
                '<button type="button" data-nudge-axis="' + axis + '" data-nudge-direction="down">' + escapeHtml(downLabel) + '</button>',
            '</div>'
        ].join('');
    }

    // D-Slice 1 (S1.13) — Pide al servidor componer (o recomponer) el skin,
    // y refresca el iframe. force=true para regenerar desde cero ignorando
    // el flag de "ya está compuesto".
    function composeAndShowPreview(opts) {
        opts = opts || {};
        var wrap = root.querySelector('[data-skin-preview]');
        var loading = root.querySelector('[data-skin-loading]');
        var iframe = root.querySelector('[data-skin-iframe]');
        var errorBox = root.querySelector('[data-skin-error]');
        var nudges = root.querySelector('[data-skin-nudges]');
        if (!wrap || !iframe) return;

        if (loading)  loading.hidden = false;
        if (iframe)   iframe.hidden = true;
        if (errorBox) errorBox.hidden = true;
        // FH6 — mientras se genera el Inicio canvas real, avisamos al usuario.
        var loadingText = loading && loading.querySelector('p');
        if (loadingText) loadingText.textContent = pp.t('js.onb.creating_home');

        // FH6 — si el prefetch del Inicio sigue en marcha, esperamos a que
        // acabe (el backend reutilizará su borrador y responderá rápido).
        var prep = homePrepPromise || Promise.resolve();
        prep.then(function () {
            return post('/admin/onboarding/compose-skin', {
                force: opts.force ? '1' : '',
                home_page: JSON.stringify(findHomePageData())
            }, 180000, false);
        })
            .then(function (body) {
                if (!body || !body.ok) throw new Error((body && body.error) || 'No se pudo componer el estilo.');
                root.dataset.skinComposed = '1';
                if (loading) loading.hidden = true;
                if (iframe) {
                    iframe.src = body.preview_url || '/admin/onboarding/skin-preview?t=' + Date.now();
                    iframe.hidden = false;
                }
                if (nudges) nudges.hidden = false;
            })
            .catch(function (err) {
                console.warn('composeAndShowPreview failed:', err);
                if (loading) loading.hidden = true;
                if (errorBox) {
                    errorBox.hidden = false;
                    var p = errorBox.querySelector('p');
                    if (p) {
                        p.textContent = (err && err.message)
                            ? 'Error: ' + err.message
                            : pp.t('js.onb.skin_failed');
                    }
                }
                if (nudges) nudges.hidden = true;
            });
    }

    // D-Slice 1 (S1.14) — Aplica un nudge al vector y recarga el iframe.
    function applyNudge(axis, direction, btn) {
        var iframe = root.querySelector('[data-skin-iframe]');
        var loading = root.querySelector('[data-skin-loading]');
        if (!iframe) return;
        if (btn) btn.classList.add('is-busy');
        if (loading) loading.hidden = false;
        // FH6 — el nudge solo recompone tokens y recarga el iframe (sin IA).
        var loadingText = loading && loading.querySelector('p');
        if (loadingText) loadingText.textContent = pp.t('js.onb.applying_nudge');
        iframe.hidden = true;

        post('/admin/onboarding/nudge', { axis: axis, direction: direction }, 30000, false)
            .then(function (body) {
                if (!body || !body.ok) throw new Error((body && body.error) || 'No se pudo aplicar el ajuste.');
                if (loading) loading.hidden = true;
                iframe.src = body.preview_url || '/admin/onboarding/skin-preview?t=' + Date.now();
                iframe.hidden = false;
            })
            .catch(function (err) {
                console.warn('applyNudge failed:', err);
                if (loading) loading.hidden = true;
                iframe.hidden = false;
            })
            .finally(function () {
                if (btn) btn.classList.remove('is-busy');
            });
    }

    // (D-Slice 1) `renderHomeStyleSelector` / `collectHomeStyleOptions` /
    // `visualStyleLabel` eliminados: ya no mostramos los 9 visual_styles
    // fijos en onboarding. El skin se compone a medida vía compose-skin.

    // FH6 — `resolveTemplateForPage` / `templateLabelFor` eliminados: el
    // onboarding ya no envía `template_slug`, así el backend genera siempre
    // en modo canvas. Las plantillas clásicas siguen disponibles desde
    // "/admin/pages > Crear desde plantilla".

    function pagePayload(input) {
        var page = JSON.parse(input.getAttribute('data-proposed-page') || '{}');
        delete page.template_slug;
        if (root.dataset.visualStyle) page.visual_style = root.dataset.visualStyle;
        return page;
    }

    function createSelectedPages() {
        var checked = Array.prototype.slice.call(root.querySelectorAll('[data-proposed-page]:checked'));
        if (!checked.length) return;
        var pages = checked.map(pagePayload);
        // ONB-REV T5 — entradas de blog marcadas: se encolan tras las páginas.
        var posts = Array.prototype.slice.call(root.querySelectorAll('[data-proposed-post]:checked')).map(function (input) {
            return JSON.parse(input.getAttribute('data-proposed-post') || '{}');
        });
        runCreate(pages, posts);
    }

    function findHomePageInput() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-proposed-page]')).find(function (input) {
            var page = JSON.parse(input.getAttribute('data-proposed-page') || '{}');
            return (page.page_type || '') === 'home' || (page.title || '').toLowerCase() === 'inicio';
        });
    }

    // FH6 — datos del Inicio seleccionado, para que el preview del paso 5
    // genere la home canvas real con su título/objetivo. Vacío si no hay home.
    function findHomePageData() {
        var input = findHomePageInput();
        return input ? pagePayload(input) : {};
    }

    function createHomeOnly() {
        var firstHome = findHomePageInput();
        if (!firstHome) return;
        runCreate([pagePayload(firstHome)], []);
    }

    function runCreate(pages, posts) {
        posts = posts || [];
        // ONB-REV T5 — cola única: primero páginas (canvas), después entradas
        // de blog (artículo estructurado, más rápido). Misma barra de progreso.
        var items = pages.map(function (page) {
            return { kind: 'page', data: page, label: page.title || pp.t('js.onb.page') };
        }).concat(posts.map(function (post) {
            return { kind: 'post', data: post, label: pp.t('js.onb.post') + ' · ' + (post.title || pp.t('js.onb.untitled')) };
        }));
        if (!items.length) return;
        var button = root.querySelector('[data-next-button]');
        var gen = root.querySelector('[data-generation]');
        var created = [];
        var failed = [];
        isGenerating = true;
        root.classList.add('is-generating');
        setArchitectureStage('generate');
        var busyLabel = pp.t('js.onb.generating_pages', { n: pages.length }) + (posts.length ? pp.t('js.onb.and_posts', { n: posts.length }) : '') + '…';
        setBusy(button, true, busyLabel);
        if (gen) {
            gen.hidden = false;
            gen.innerHTML = '<strong>Creando borradores con IA</strong>'
                + '<small data-gen-summary>' + escapeHtml(pp.t('js.onb.preparing_first')) + '</small>'
                + '<div class="pp-onboarding-generation__bar"><i data-gen-bar></i></div>'
                + items.map(function (item, index) {
                    return '<p data-gen-row="' + index + '" class="' + (index === 0 ? 'is-active' : 'is-pending') + '"><span></span><em>' + escapeHtml(item.label) + '</em><small>' + (index === 0 ? 'Generando ahora' : 'En cola') + '</small></p>';
                }).join('');
        }

        createItemAt(0);

        function createItemAt(index) {
            if (index >= items.length) {
                finishInteractiveCreate();
                return;
            }
            updateGenerationProgress(index, items.length, created.length, failed.length);
            var item = items[index];
            var req = item.kind === 'post'
                ? post('/admin/onboarding/create-post', { post: item.data }, 240000, true)
                : post('/admin/onboarding/create-pages', { pages: [item.data], complete: false }, 240000, true);
            req
                .then(function (body) {
                    var itemFailed = Array.isArray(body.failed) ? body.failed : [];
                    var itemCreated = Array.isArray(body.created) ? body.created : [];
                    created = created.concat(itemCreated);
                    failed = failed.concat(itemFailed);
                    markGenerationRow(index, itemFailed.length ? 'error' : 'done', itemFailed[0] && itemFailed[0].error ? itemFailed[0].error : pp.t('js.onb.draft_created'));
                    createItemAt(index + 1);
                })
                .catch(function (err) {
                    failed.push({ title: item.label, error: err.message || 'Error al generar' });
                    markGenerationRow(index, 'error', err.message || 'Error al generar');
                    createItemAt(index + 1);
                });
        }

        function finishInteractiveCreate() {
            post('/admin/onboarding/create-pages', { pages: [], finish_only: true }, 30000, true)
                .then(function (body) {
                    if (failed.length > 0) {
                        isGenerating = false;
                        root.classList.remove('is-generating');
                        setBusy(button, false, 'Ir al mapa →');
                        if (gen) {
                            var summary = gen.querySelector('[data-gen-summary]');
                            if (summary) summary.textContent = pp.t('js.onb.created_summary', { creadas: created.length, fallidas: failed.length });
                            gen.insertAdjacentHTML('beforeend', '<p class="pp-onboarding-warning">' + escapeHtml(pp.t('js.onb.some_failed')) + '</p><p><button type="button" data-go-pages>' + escapeHtml(pp.t('js.onb.go_to_map')) + '</button></p>');
                            var go = gen.querySelector('[data-go-pages]');
                            if (go) go.addEventListener('click', function () {
                                window.location.href = body.redirect_url || (baseUrl + '/admin/pages');
                            });
                        }
                        return;
                    }
                    updateGenerationProgress(items.length, items.length, created.length, 0);
                    isGenerating = false;
                    window.location.href = body.redirect_url || (baseUrl + '/admin/pages');
                })
                .catch(function () {
                    isGenerating = false;
                    root.classList.remove('is-generating');
                    setBusy(button, false, 'Ir al mapa →');
                    if (gen) gen.insertAdjacentHTML('beforeend', '<p class="pp-onboarding-warning">' + escapeHtml(pp.t('js.onb.finish_failed')) + '</p>');
                });
        }

    }

    function updateGenerationProgress(index, total, created, failed) {
        var gen = root.querySelector('[data-generation]');
        if (!gen) return;
        var summary = gen.querySelector('[data-gen-summary]');
        var bar = gen.querySelector('[data-gen-bar]');
        if (summary) summary.textContent = pp.t('js.onb.draft_progress', { i: Math.min(index + 1, total), total: total, creados: created }) + (failed ? '. ' + pp.t('js.onb.with_errors') + ': ' + failed : '') + '.';
        if (bar) bar.style.width = Math.round((index / Math.max(total, 1)) * 100) + '%';
        gen.querySelectorAll('[data-gen-row]').forEach(function (row) {
            var rowIndex = Number(row.getAttribute('data-gen-row') || 0);
            if (rowIndex === index && !row.classList.contains('is-done') && !row.classList.contains('is-error')) {
                row.className = 'is-active';
                var status = row.querySelector('small');
                if (status) status.textContent = pp.t('js.onb.generating_now');
            }
        });
    }

    function markGenerationRow(index, state, message) {
        var row = root.querySelector('[data-gen-row="' + index + '"]');
        if (!row) return;
        row.className = state === 'error' ? 'is-error' : 'is-done';
        var status = row.querySelector('small');
        if (status) status.textContent = message || (state === 'error' ? 'Error' : 'Creada');
        var bar = root.querySelector('[data-gen-bar]');
        var total = root.querySelectorAll('[data-gen-row]').length;
        if (bar) bar.style.width = Math.round(((index + 1) / Math.max(total, 1)) * 100) + '%';
        var next = root.querySelector('[data-gen-row="' + (index + 1) + '"]');
        if (next && !next.classList.contains('is-done') && !next.classList.contains('is-error')) {
            next.className = 'is-active';
            var nextStatus = next.querySelector('small');
            if (nextStatus) nextStatus.textContent = pp.t('js.onb.preparing');
        }
    }

    function syncCreateButton() {
        var button = root.querySelector('[data-next-button]');
        if (!button) return;
        button.disabled = root.querySelectorAll('[data-proposed-page]:checked').length === 0;
        var toolbar = root.querySelector('[data-selection-count]');
        if (toolbar) toolbar.textContent = selectionLabel();
        var style = root.querySelector('[data-style-count]');
        if (style) style.textContent = selectionLabel();
    }

    // ONB-REV T4 — "8 páginas + 12 entradas seleccionadas".
    function selectionLabel() {
        var pages = root.querySelectorAll('[data-proposed-page]:checked').length;
        var posts = root.querySelectorAll('[data-proposed-post]:checked').length;
        var label = pp.t(pages === 1 ? 'js.onb.pages_one' : 'js.onb.pages_other', { n: pages });
        if (posts > 0) label += ' + ' + pp.t(posts === 1 ? 'js.onb.posts_one' : 'js.onb.posts_other', { n: posts });
        return pp.t('js.onb.n_selected_suffix', { texto: label });
    }

    function post(path, data, timeoutMs, json) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs || 30000);
        var options = { method: 'POST', credentials: 'same-origin', signal: controller.signal };
        if (json) {
            data._csrf = csrf;
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(data);
        } else {
            var params = new URLSearchParams();
            params.set('_csrf', csrf);
            Object.keys(data || {}).forEach(function (key) { params.set(key, data[key]); });
            options.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
            options.body = params.toString();
        }
        return fetch(baseUrl + path, options).then(function (res) {
            return res.text().then(function (text) {
                clearTimeout(timer);
                var body = {};
                try {
                    body = text ? JSON.parse(text) : {};
                } catch (err) {
                    throw new Error(res.ok ? pp.t('js.onb.empty_response') : ('HTTP ' + res.status + ': ' + pp.t('js.onb.not_json')));
                }
                if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                return body;
            });
        }).catch(function (err) {
            clearTimeout(timer);
            if (err && err.name === 'AbortError') throw new Error(pp.t('js.onb.timeout'));
            throw err;
        });
    }

    function selectedColor(form, name) {
        var checked = form.querySelector('[name="' + cssEscape(name) + '"]:checked');
        var custom = form.querySelector('[data-color-custom="' + cssEscape(name) + '"]');
        var hex = form.querySelector('[data-color-hex="' + cssEscape(name) + '"]');
        return checked ? checked.value : (normalizeHex(hex ? hex.value : '') || (custom ? custom.value : ''));
    }

    function normalizeHex(value) {
        var v = String(value || '').trim();
        if (/^[0-9a-fA-F]{6}$/.test(v)) v = '#' + v;
        return /^#[0-9a-fA-F]{6}$/.test(v) ? v.toLowerCase() : '';
    }

    function fontStack(value) {
        var family = String(value || 'Inter').replace(/"/g, '');
        return '"' + family + '", Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    }

    function typeLabel(type) {
        // Las claves son `page_type` de base de datos: no se traducen.
        return {
            home: pp.t('js.onb.type_home'),
            service: pp.t('js.onb.type_service'),
            contact: pp.t('js.onb.type_contact'),
            article: pp.t('js.onb.type_article'),
            landing: pp.t('js.onb.type_landing')
        }[type] || pp.t('js.onb.page');
    }

    /**
      * ¿Esta página va marcada de serie? Se decide sobre los DATOS, nunca sobre
      * la etiqueta: antes se comparaba el texto visible ('Imprescindible'), y
      * traducirlo habría dejado todas las casillas desmarcadas sin avisar.
      */
    function isEssentialPage(page) {
        var slug = (page.page_type || '').toLowerCase();
        return slug === 'home' || slug === 'contact' || page.priority === 'high';
    }

    function priorityLabel(page) {
        if (isEssentialPage(page) && (page.page_type === 'home' || page.page_type === 'contact')) {
            return pp.t('js.onb.priority_essential');
        }
        return {
            high: pp.t('js.onb.priority_high'),
            medium: pp.t('js.onb.priority_medium'),
            low: pp.t('js.onb.priority_low')
        }[page.priority] || pp.t('js.onb.priority_medium');
    }

    function setBusy(button, busy, label) {
        if (!button) return;
        button.disabled = !!busy;
        if (label) button.textContent = label;
        button.classList.toggle('is-busy', !!busy);
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 KB';
        return bytes > 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
        return String(value).replace(/"/g, '\\"');
    }
})();
