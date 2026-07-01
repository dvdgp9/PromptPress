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
    bindDropzone();
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
                    ? 'Elegir documentos'
                    : (files.length === 1 ? files[0].name : files.length + ' documentos seleccionados');
            }
            status.textContent = files.length
                ? 'Listo para analizar: ' + (files.length === 1 ? formatBytes(total) : files.length + ' documentos · ' + formatBytes(total))
                : '';
            status.className = '';
        });

        button.addEventListener('click', function () {
            var files = Array.prototype.slice.call(fileInput.files || []);
            if (!files.length) {
                status.textContent = 'Elige primero uno o varios documentos del negocio.';
                status.className = 'is-error';
                return;
            }
            var data = new FormData();
            data.set('_csrf', csrf);
            files.forEach(function (file) { data.append('dossier[]', file); });
            setBusy(button, true, 'Leyendo documentos…');
            status.textContent = 'Extrayendo información, cruzando documentos y preparando memoria inicial.';
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
                        throw new Error(res.ok ? 'Respuesta no válida del servidor.' : ('HTTP ' + res.status + ': el servidor no devolvió JSON.'));
                    }
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                applyMemoryFields(body.fields || {});
                var msg = 'Campos rellenados. Revisa y ajusta lo que quieras antes de continuar.';
                if (body.company_name) msg += ' Empresa detectada: ' + body.company_name + '.';
                if (body.documents && body.documents.length > 1) msg += ' Documentos leídos: ' + body.documents.length + '.';
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

        function syncHex(name, value) {
            var hex = form.querySelector('[data-color-hex="' + cssEscape(name) + '"]');
            if (hex && normalizeHex(value)) hex.value = normalizeHex(value);
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
            state.textContent = files.length + ' documentos seleccionados · ' + formatBytes(total);
        });

        var logoInput = root.querySelector('[data-logo-dropzone] input[type="file"]');
        var logoWrap = root.querySelector('[data-logo-dropzone]');
        var logoPreview = root.querySelector('[data-logo-dropzone] img');
        var logoSlot = root.querySelector('[data-logo-dropzone] > span');
        var designPreview = root.querySelector('[data-design-preview]');
        var logoState = root.querySelector('[data-logo-state]');
        if (logoInput && logoState) logoInput.addEventListener('change', function () {
            var file = logoInput.files && logoInput.files[0] ? logoInput.files[0] : null;
            if (!file) return;
            logoState.textContent = file.name + ' · ' + formatBytes(file.size) + ' · Se guardará al continuar';
            logoState.className = 'is-success';
            if (logoWrap) logoWrap.classList.add('has-file');
            if (!logoPreview && logoSlot) {
                logoSlot.innerHTML = '<img src="" alt="">';
                logoPreview = logoSlot.querySelector('img');
            }
            if (typeof FileReader !== 'undefined') {
                var reader = new FileReader();
                reader.onload = function (event) {
                    if (event && event.target && typeof event.target.result === 'string') {
                        if (logoPreview) logoPreview.src = event.target.result;
                        updateDesignPreviewLogo(event.target.result);
                    }
                };
                reader.readAsDataURL(file);
            }
        });

        var referenceInput = root.querySelector('[data-reference-dropzone] input[type="file"]');
        var referenceWrap = root.querySelector('[data-reference-dropzone]');
        var referenceState = root.querySelector('[data-reference-state]');
        if (referenceInput && referenceState) referenceInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(referenceInput.files || []);
            if (!files.length) return;
            if (referenceWrap) referenceWrap.classList.add('has-file');
            var total = files.reduce(function (sum, file) { return sum + (file.size || 0); }, 0);
            referenceState.textContent = files.length + ' referencia' + (files.length === 1 ? '' : 's') + ' seleccionada' + (files.length === 1 ? '' : 's') + ' · ' + formatBytes(total) + ' · Guardando…';
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
                referenceState.textContent = count + ' referencia' + (count === 1 ? '' : 's') + ' guardada' + (count === 1 ? '' : 's') + '. El preview del paso 5 usará IA con estas capturas.';
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
            var ok = window.confirm('La IA sigue generando páginas. Si sales ahora, puede que el proceso se interrumpa. ¿Quieres salir igualmente?');
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
            var labels = {
                presence:  'Diseñando una presencia mínima clara…',
                services:  'Pensando la estructura para captar clientes…',
                seo:       'Preparando un mapa con foco en SEO y blog…',
                portfolio: 'Diseñando un sitio para mostrar tu trabajo…',
                product:   'Planificando una landing para tu lanzamiento…'
            };
            msg.textContent = labels[intent] || 'Pensando en la mejor arquitectura para tu negocio…';
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
            if (p) p.textContent = 'La IA está siendo más cuidadosa de lo habitual. Un momento más…';
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
            var checked = priority === 'Imprescindible' || priority === 'Alta prioridad';
            return [
                '<label class="pp-onboarding-page-card" style="--delay:' + index + '">',
                    '<input type="checkbox" data-proposed-page="' + escapeHtml(JSON.stringify(page)) + '" ' + (checked ? 'checked' : '') + '>',
                    '<span class="pp-onboarding-check"></span>',
                    '<span class="pp-onboarding-page-card__body">',
                        '<small>' + escapeHtml(priority) + '</small>',
                        '<strong>' + escapeHtml(page.title || 'Página') + '</strong>',
                        '<em>' + escapeHtml(page.reason || page.goal || '') + '</em>',
                    '</span>',
                    '<span class="pp-onboarding-page-type">' + escapeHtml(typeLabel(page.page_type || 'landing')) + '</span>',
                    '<details><summary>Ver más detalle</summary><p>' + escapeHtml(page.goal || page.architecture_context || page.reason || '') + '</p></details>',
                '</label>'
            ].join('');
        }).join('');
        var selectedCount = pages.filter(function (page) {
            var priority = priorityLabel(page);
            return priority === 'Imprescindible' || priority === 'Alta prioridad';
        }).length;
        var route = pages.slice(0, 5).map(function (page) {
            return '<span>' + escapeHtml(page.title || 'Página') + '</span>';
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
                        '<strong>' + escapeHtml(post.title || 'Entrada') + '</strong>',
                        '<em>' + escapeHtml(post.angle || '') + '</em>',
                    '</span>',
                    '<span class="pp-onboarding-page-type">Entrada</span>',
                '</label>'
            ].join('');
        }).join('');
        var blogGroup = postRows === '' ? '' : [
            '<div class="pp-onboarding-blog-group">',
                '<header><strong>Entradas de blog para posicionar</strong><span>Las generamos junto a las páginas, ya redactadas como borrador. Desmarca las que no encajen.</span></header>',
                '<div class="pp-onboarding-page-list">' + postRows + '</div>',
            '</div>'
        ].join('');

        resultWrap.innerHTML = [
            '<section class="pp-onboarding-flow-guide">',
                '<article class="is-active" data-flow-step="structure"><small>1</small><strong>Páginas</strong><p>Elige qué crear.</p></article>',
                '<article data-flow-step="style"><small>2</small><strong>Estilo</strong><p>Tu diseño a medida.</p></article>',
                '<article data-flow-step="generate"><small>3</small><strong>Generación</strong><p>Creamos borradores.</p></article>',
            '</section>',
            '<section class="pp-onboarding-structure-panel" data-arch-stage="structure">',
                '<div class="pp-onboarding-route" aria-label="Ruta sugerida">' + route + '</div>',
                '<div class="pp-onboarding-arch-toolbar"><strong data-selection-count>' + selectedCount + ' seleccionadas</strong><span>Las imprescindibles vienen marcadas.</span></div>',
                '<div class="pp-onboarding-page-list">' + rows + '</div>',
                blogGroup,
                '<div class="pp-onboarding-alt-actions">',
                    '<button type="button" data-create-home>Crear solo "Inicio"</button>',
                    '<button type="button" data-reanalyze>Volver a proponer</button>',
                    '<form method="POST" action="' + baseUrl + '/admin/onboarding/skip"><input type="hidden" name="_csrf" value="' + escapeHtml(csrf) + '"><input type="hidden" name="step" value="5"><button type="submit">Empezar desde el mapa vacío</button></form>',
                '</div>',
            '</section>',
            '<section class="pp-onboarding-style-panel" data-arch-stage="style" hidden>',
                renderSkinPreviewStage(),
                '<div class="pp-onboarding-style-summary"><strong data-style-count>' + selectedCount + ' páginas seleccionadas</strong><span>Se generarán con este estilo único.</span><button type="button" data-back-to-structure>Volver a páginas</button></div>',
                '<p class="pp-onboarding-create-note" data-create-note>La generación puede tardar 1-2 min. Te llevaremos al mapa cuando termine.</p>',
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
            button.textContent = stage === 'style' ? 'Generar páginas con este estilo →' : 'Continuar al estilo →';
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
                    '<h3>Así te ha quedado</h3>',
                    '<p>Lo componemos para tu marca a partir de lo que nos has contado. Si subiste referencias, esta vista también se inspira en su estructura y ritmo.</p>',
                '</header>',
                '<div class="pp-onboarding-skin__preview" data-skin-preview>',
                    '<div class="pp-onboarding-skin__loading" data-skin-loading>',
                        '<div><span></span><span></span><span></span></div>',
                        '<p>Componiendo tu preview…</p>',
                    '</div>',
                    '<iframe data-skin-iframe title="Vista previa de tu estilo" hidden></iframe>',
                    '<div class="pp-onboarding-skin__error" data-skin-error hidden>',
                        '<p>No hemos podido componer el preview. Continúa igualmente — generaremos tu sitio con valores neutros.</p>',
                    '</div>',
                '</div>',
                '<div class="pp-onboarding-skin__nudges" data-skin-nudges hidden>',
                    '<span class="pp-onboarding-skin__nudges-label">¿Algo te chirría? Ajústalo:</span>',
                    nudgePairHtml('warmth',   'Más cálido',  'Más sobrio'),
                    nudgePairHtml('modernity','Más moderno', 'Más clásico'),
                    nudgePairHtml('energy',   'Más rotundo', 'Más suave'),
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
        if (loadingText) loadingText.textContent = 'Creando tu inicio…';

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
                            : 'No hemos podido componer el preview. Continúa igualmente — generaremos tu sitio con valores neutros.';
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
        if (loadingText) loadingText.textContent = 'Aplicando tu ajuste…';
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
            return { kind: 'page', data: page, label: page.title || 'Página' };
        }).concat(posts.map(function (post) {
            return { kind: 'post', data: post, label: 'Entrada · ' + (post.title || 'Sin título') };
        }));
        if (!items.length) return;
        var button = root.querySelector('[data-next-button]');
        var gen = root.querySelector('[data-generation]');
        var created = [];
        var failed = [];
        isGenerating = true;
        root.classList.add('is-generating');
        setArchitectureStage('generate');
        var busyLabel = 'Generando ' + pages.length + ' páginas' + (posts.length ? ' y ' + posts.length + ' entradas' : '') + '…';
        setBusy(button, true, busyLabel);
        if (gen) {
            gen.hidden = false;
            gen.innerHTML = '<strong>Creando borradores con IA</strong>'
                + '<small data-gen-summary>Preparando la primera página.</small>'
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
                    markGenerationRow(index, itemFailed.length ? 'error' : 'done', itemFailed[0] && itemFailed[0].error ? itemFailed[0].error : 'Borrador creado');
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
                            if (summary) summary.textContent = 'Hemos creado ' + created.length + ' páginas. ' + failed.length + ' necesitan revisarse.';
                            gen.insertAdjacentHTML('beforeend', '<p class="pp-onboarding-warning">Algunas páginas no se han podido crear. Puedes seguir con las que ya están listas.</p><p><button type="button" data-go-pages>Ir al mapa</button></p>');
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
                    if (gen) gen.insertAdjacentHTML('beforeend', '<p class="pp-onboarding-warning">Las páginas se han creado, pero no hemos podido cerrar el onboarding automáticamente. Entra al mapa para revisarlas.</p>');
                });
        }

    }

    function updateGenerationProgress(index, total, created, failed) {
        var gen = root.querySelector('[data-generation]');
        if (!gen) return;
        var summary = gen.querySelector('[data-gen-summary]');
        var bar = gen.querySelector('[data-gen-bar]');
        if (summary) summary.textContent = 'Borrador ' + Math.min(index + 1, total) + ' de ' + total + '. Creados: ' + created + (failed ? '. Con error: ' + failed : '') + '.';
        if (bar) bar.style.width = Math.round((index / Math.max(total, 1)) * 100) + '%';
        gen.querySelectorAll('[data-gen-row]').forEach(function (row) {
            var rowIndex = Number(row.getAttribute('data-gen-row') || 0);
            if (rowIndex === index && !row.classList.contains('is-done') && !row.classList.contains('is-error')) {
                row.className = 'is-active';
                var status = row.querySelector('small');
                if (status) status.textContent = 'Generando ahora';
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
            if (nextStatus) nextStatus.textContent = 'Preparando';
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
        var label = pages + ' página' + (pages === 1 ? '' : 's');
        if (posts > 0) label += ' + ' + posts + ' entrada' + (posts === 1 ? '' : 's');
        return label + ' seleccionadas';
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
                    throw new Error(res.ok ? 'Respuesta vacía o no válida del servidor.' : ('HTTP ' + res.status + ': el servidor no devolvió JSON.'));
                }
                if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                return body;
            });
        }).catch(function (err) {
            clearTimeout(timer);
            if (err && err.name === 'AbortError') throw new Error('La operación ha tardado demasiado. Prueba de nuevo.');
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
        return {
            home: 'Inicio',
            service: 'Servicio',
            contact: 'Contacto',
            article: 'Contenido',
            landing: 'Landing'
        }[type] || 'Página';
    }

    function priorityLabel(page) {
        var title = (page.title || '').toLowerCase();
        if (title === 'inicio' || title === 'contacto') return 'Imprescindible';
        return { high: 'Alta prioridad', medium: 'Media', low: 'Baja' }[page.priority] || 'Media';
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
