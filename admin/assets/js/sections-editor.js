/**
 * Editor de secciones — drag & drop, CRUD vía fetch, forms tipados por schema.
 * T3.3: usa window.PP_SECTION_SCHEMAS para renderizar un form específico por tipo.
 *       Los textareas JSON quedan como fallback avanzado (toggle "Ver JSON").
 */
(function () {
    'use strict';

    var SCHEMAS = window.PP_SECTION_SCHEMAS || {};

    var root = document.querySelector('.pp-sections-editor');
    if (!root) return;

    var pageId  = root.dataset.pageId;
    var pageTitle = root.dataset.pageTitle || 'Página';
    var pageGoal = root.dataset.pageGoal || '';
    var csrf    = root.dataset.csrf;
    var baseUrl = root.dataset.baseUrl.replace(/\/$/, '');
    var list    = document.getElementById('pp-sections-list');
    var noSectionsEmpty = document.getElementById('pp-no-sections');
    var layoutVariationsBtn = document.getElementById('pp-layout-variations-btn');
    var layoutVariationsPanel = document.getElementById('pp-layout-variations-panel');
    var activeImageInput = null;
    var mediaModal = null;
    var mediaGrid = null;
    var mediaSearch = null;
    var mediaStatus = null;
    var mediaLoaded = false;
    var mediaSearchTimer = null;
    var mediaActiveTab = 'library';

    // ============================================================
    // Fetch helpers
    // ============================================================
    function url(path) { return baseUrl + path; }

    function formBody(data) {
        var params = new URLSearchParams();
        params.set('_csrf', csrf);
        Object.keys(data).forEach(function (k) { params.set(k, data[k]); });
        return params.toString();
    }

    function gatherCurrentLayoutData() {
        return Array.from(list.children).map(function (card) {
            var type = card.dataset.sectionType || 'generic';
            var variant = 'default';
            if (card._bodyBuilt) {
                var styleTa = card.querySelector('.pp-section-style');
                variant = readVariantFromStyle(styleTa);
            } else {
                var preStyle = card.querySelector('[data-field="style"]');
                if (preStyle) {
                    var raw = (preStyle.value || '').trim();
                    if (raw) {
                        try {
                            var parsed = JSON.parse(raw);
                            if (parsed && typeof parsed.variant === 'string' && parsed.variant) {
                                variant = parsed.variant;
                            }
                        } catch (e) {}
                    }
                }
            }
            return {
                id: Number(card.dataset.sectionId || 0),
                type: type,
                variant: variant,
            };
        });
    }

    function renderVariationsLoading() {
        if (!layoutVariationsPanel) return;
        layoutVariationsPanel.hidden = false;
        var status = layoutVariationsPanel.querySelector('.pp-layout-variations__status');
        var listEl = layoutVariationsPanel.querySelector('.pp-layout-variations__list');
        status.className = 'pp-layout-variations__status is-loading';
        status.textContent = 'Generando variaciones de layout…';
        listEl.innerHTML = '';
    }

    function renderVariationsResult(resp) {
        if (!layoutVariationsPanel) return;
        layoutVariationsPanel.hidden = false;
        var status = layoutVariationsPanel.querySelector('.pp-layout-variations__status');
        var listEl = layoutVariationsPanel.querySelector('.pp-layout-variations__list');
        status.className = 'pp-layout-variations__status pp-ok';
        status.textContent = 'Variaciones listas · ' + aiMeta(resp);

        var cards = (resp.variations || []).map(function (v, idx) {
            var preview = v.preview_html || '';
            var rationale = v.rationale ? '<p class="pp-layout-variations__rationale">' + escapeHtml(v.rationale) + '</p>' : '';
            return ''
                + '<article class="pp-layout-variation-card">'
                + '  <header class="pp-layout-variation-card__head">'
                + '    <strong>' + escapeHtml(v.label || ('Variación ' + (idx + 1))) + '</strong>'
                + '  </header>'
                + '  <iframe class="pp-layout-variation-card__preview" title="Vista previa de ' + escapeHtml(v.label || ('variación ' + (idx + 1))) + '" loading="lazy"></iframe>'
                + rationale
                + '  <div class="pp-layout-variation-card__actions">'
                + '    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-preview-layout-variation="' + idx + '">Ver preview</button>'
                + '  <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-apply-layout-variation="' + idx + '">Aplicar esta</button>'
                + '  </div>'
                + '</article>';
        });
        listEl.innerHTML = cards.join('');

        listEl.querySelectorAll('.pp-layout-variation-card__preview').forEach(function (frame, idx) {
            var variation = (resp.variations || [])[idx] || {};
            frame.srcdoc = variation.preview_html || '';
        });

        listEl.querySelectorAll('[data-apply-layout-variation]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = Number(btn.getAttribute('data-apply-layout-variation'));
                var variation = (resp.variations || [])[idx];
                if (!variation) return;
                applyLayoutVariation(variation, btn, status);
            });
        });

        listEl.querySelectorAll('[data-preview-layout-variation]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = Number(btn.getAttribute('data-preview-layout-variation'));
                var variation = (resp.variations || [])[idx];
                if (!variation || !variation.preview_html) return;
                openVariationPreview(variation);
            });
        });
    }

    function openVariationPreview(variation) {
        var html = variation.preview_html || '';
        if (!html) {
            toast('Esta variación no tiene HTML de vista previa.', 'error');
            return;
        }
        var blob = new Blob([html], { type: 'text/html;charset=utf-8' });
        var previewUrl = URL.createObjectURL(blob);
        var win = window.open(previewUrl, '_blank');
        if (!win) {
            URL.revokeObjectURL(previewUrl);
            toast('El navegador bloqueó la vista previa emergente.', 'error');
            return;
        }
        setTimeout(function () {
            URL.revokeObjectURL(previewUrl);
        }, 60000);
    }

    function applyLayoutVariation(variation, btn, statusEl) {
        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Aplicando…';
        postJson('/admin/pages/' + pageId + '/ai-variations/apply', {
            variation_json: JSON.stringify(variation),
        })
        .then(function () {
            window.location.reload();
        })
        .catch(function (err) {
            statusEl.className = 'pp-layout-variations__status pp-err';
            statusEl.textContent = 'No se pudo aplicar la variación: ' + err.message;
            btn.disabled = false;
            btn.textContent = original;
        });
    }

    function requestLayoutVariations() {
        var layout = gatherCurrentLayoutData();
        if (!layout.length) {
            toast('Añade al menos una sección antes de pedir variaciones.', 'error');
            return;
        }
        renderVariationsLoading();
        if (layoutVariationsBtn) {
            layoutVariationsBtn.disabled = true;
            layoutVariationsBtn.textContent = 'Generando…';
        }
        postJson('/admin/pages/' + pageId + '/ai-variations', {
            goal: pageGoal,
            extra_context: 'Layout actual: ' + JSON.stringify(layout),
        }, 60000)
        .then(function (resp) {
            renderVariationsResult(resp);
        })
        .catch(function (err) {
            if (!layoutVariationsPanel) return;
            layoutVariationsPanel.hidden = false;
            var status = layoutVariationsPanel.querySelector('.pp-layout-variations__status');
            status.className = 'pp-layout-variations__status pp-err';
            status.textContent = 'No se pudieron generar variaciones: ' + err.message;
        })
        .finally(function () {
            if (layoutVariationsBtn) {
                layoutVariationsBtn.disabled = false;
                layoutVariationsBtn.textContent = 'Probar 3 variaciones IA';
            }
        });
    }

    function postForm(path, data, timeoutMs) {
        return fetchWithTimeout(url(path), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formBody(data),
            credentials: 'same-origin',
        }, timeoutMs || 90000).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body.ok) {
                    throw new Error(body.error || ('HTTP ' + res.status));
                }
                return body;
            });
        });
    }

    function fetchWithTimeout(targetUrl, options, timeoutMs) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        options = options || {};
        options.signal = controller.signal;
        return fetch(targetUrl, options).catch(function (err) {
            if (err && err.name === 'AbortError') {
                var seconds = Math.round((timeoutMs || 0) / 1000);
                var timeoutErr = new Error('La llamada ha superado el límite de ' + seconds + ' segundos. No se ha aplicado ningún cambio.');
                timeoutErr.isTimeout = true;
                throw timeoutErr;
            }
            throw err;
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    function postJson(path, payload, timeoutMs) {
        return fetchWithTimeout(url(path), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formBody(payload || {}),
            credentials: 'same-origin',
        }, timeoutMs || 90000).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body.ok) {
                    throw new Error(body.error || ('HTTP ' + res.status));
                }
                return body;
            });
        });
    }

    function aiMeta(resp) {
        var provider = resp.provider || 'IA';
        var model = resp.model || '';
        var tokens = Number(resp.tokens_in || 0) + ' -> ' + Number(resp.tokens_out || 0) + ' tokens';
        var cost = typeof resp.estimated_cost === 'number' ? ' · $' + resp.estimated_cost.toFixed(6) : '';
        return provider + (model ? ' · ' + model : '') + ' · ' + tokens + cost;
    }

    function setButtonBusy(btn, busy, label) {
        if (!btn) return;
        btn.disabled = busy;
        btn.classList.toggle('is-busy', busy);
        if (busy) {
            btn.setAttribute('aria-busy', 'true');
        } else {
            btn.removeAttribute('aria-busy');
        }
        if (label) btn.textContent = label;
    }

    // ============================================================
    // UI helpers
    // ============================================================
    function toast(msg, type) {
        type = type || 'success';
        var el = document.createElement('div');
        el.className = 'pp-toast pp-toast--' + type;
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('is-visible'); }, 10);
        setTimeout(function () {
            el.classList.remove('is-visible');
            setTimeout(function () { el.remove(); }, 300);
        }, 2500);
    }

    function typeLabel(type) {
        return (SCHEMAS[type] && SCHEMAS[type].label) || labelFallback(type);
    }
    function labelFallback(type) {
        var fallbacks = {
            hero: 'Hero', text_image: 'Texto + Imagen', benefits: 'Beneficios',
            faq: 'FAQ', cta: 'Llamada a la acción', form: 'Formulario', generic: 'Genérica'
        };
        return fallbacks[type] || type;
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function refreshEmptyState() {
        if (!noSectionsEmpty) return;
        noSectionsEmpty.hidden = list.children.length !== 0;
    }

    // ============================================================
    // Card chrome (E1.1) — cabecera visual de cada sección
    // ============================================================

    function truncate(str, n) {
        str = String(str || '');
        return str.length > n ? str.slice(0, n - 1).trimEnd() + '…' : str;
    }

    function pad2(n) {
        n = String(n);
        return n.length < 2 ? '0' + n : n;
    }

    function inferTitle(content) {
        var c = content;
        if (c == null) return '';
        if (typeof c === 'string') {
            try { c = JSON.parse(c); } catch (e) { return ''; }
        }
        if (!c || typeof c !== 'object') return '';
        var directKeys = ['heading', 'title', 'eyebrow'];
        for (var i = 0; i < directKeys.length; i++) {
            var v = c[directKeys[i]];
            if (typeof v === 'string' && v.trim()) return v.trim();
        }
        var items = Array.isArray(c.items) ? c.items : null;
        if (items && items.length) {
            var first = items[0] || {};
            var itemKeys = ['title', 'question', 'plan_name', 'name', 'quote', 'label'];
            for (var j = 0; j < itemKeys.length; j++) {
                if (typeof first[itemKeys[j]] === 'string' && first[itemKeys[j]].trim()) {
                    return first[itemKeys[j]].trim();
                }
            }
        }
        return '';
    }

    function inferVariantFromStyleStr(styleJson) {
        if (!styleJson) return 'default';
        try {
            var o = JSON.parse(styleJson);
            return (o && typeof o.variant === 'string' && o.variant) ? o.variant : 'default';
        } catch (e) { return 'default'; }
    }

    function statusMeta(status) {
        if (status === 'locked') return { key: 'locked', label: 'Bloqueada' };
        return { key: 'editable', label: 'Editable' };
    }

    // Iconos por tipo de sección (SVG inline, viewBox 24x24).
    var TYPE_ICONS_SVG = {
        hero:          '<rect x="3" y="5" width="18" height="9" rx="1.5"/><rect x="6.5" y="16.5" width="11" height="2.5" rx="1.2"/>',
        text_image:    '<rect x="3" y="5" width="8" height="14" rx="1.2"/><rect x="13" y="6" width="8" height="2" rx="1"/><rect x="13" y="10" width="8" height="2" rx="1"/><rect x="13" y="14" width="6" height="2" rx="1"/>',
        benefits:      '<rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="9.25" y="3" width="5.5" height="5.5" rx="1"/><rect x="15.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="9.25" width="5.5" height="5.5" rx="1"/><rect x="9.25" y="9.25" width="5.5" height="5.5" rx="1"/><rect x="15.5" y="9.25" width="5.5" height="5.5" rx="1"/>',
        faq:           '<rect x="3" y="5.5" width="18" height="2" rx="1"/><rect x="3" y="11" width="18" height="2" rx="1"/><rect x="3" y="16.5" width="13" height="2" rx="1"/>',
        cta:           '<rect x="2.5" y="6" width="19" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="8.5" y="11" width="7" height="2" rx="1"/>',
        testimonials:  '<path d="M5.5 13.5c0-2.6 1.6-4.5 4-4.5v1.8c-1.1 0-1.8 0.7-2 1.7h2v3.5H5.5v-2.5zm8 0c0-2.6 1.6-4.5 4-4.5v1.8c-1.1 0-1.8 0.7-2 1.7h2v3.5h-4v-2.5z"/>',
        stats:         '<rect x="3.5" y="14" width="3" height="6" rx=".5"/><rect x="10.5" y="9" width="3" height="11" rx=".5"/><rect x="17.5" y="4" width="3" height="16" rx=".5"/>',
        gallery:       '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
        steps:         '<circle cx="5.5" cy="12" r="2.8"/><circle cx="12" cy="12" r="2.8"/><circle cx="18.5" cy="12" r="2.8"/><rect x="8.3" y="11.3" width="1.4" height="1.4"/><rect x="14.3" y="11.3" width="1.4" height="1.4"/>',
        logos_strip:   '<rect x="2" y="9" width="5" height="6" rx="1"/><rect x="9.5" y="9" width="5" height="6" rx="1"/><rect x="17" y="9" width="5" height="6" rx="1"/>',
        pricing:       '<rect x="3" y="5" width="5" height="14" rx="1"/><rect x="9.5" y="3" width="5" height="18" rx="1"/><rect x="16" y="5" width="5" height="14" rx="1"/>',
        form:          '<rect x="3" y="4" width="18" height="3.5" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="10" width="18" height="3.5" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="16" width="9" height="3.5" rx="1.5"/>',
        posts_listing: '<rect x="3" y="4" width="6" height="6" rx="1"/><rect x="10.5" y="5" width="10.5" height="1.8" rx=".9"/><rect x="10.5" y="8.2" width="8" height="1.8" rx=".9"/><rect x="3" y="13" width="6" height="6" rx="1"/><rect x="10.5" y="14" width="10.5" height="1.8" rx=".9"/><rect x="10.5" y="17.2" width="8" height="1.8" rx=".9"/>',
        article_body:  '<rect x="3" y="5" width="18" height="2" rx="1"/><rect x="3" y="9" width="18" height="2" rx="1"/><rect x="3" y="13" width="18" height="2" rx="1"/><rect x="3" y="17" width="12" height="2" rx="1"/>',
        generic:       '<rect x="4" y="4" width="16" height="16" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/>'
    };

    function sectionThumbHtml(type) {
        var key = TYPE_ICONS_SVG[type] ? type : 'generic';
        return '<span class="pp-section-card__thumb pp-section-card__thumb--' + cssSafeJs(key) + '" aria-hidden="true">'
             + '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22">'
             + TYPE_ICONS_SVG[key]
             + '</svg></span>';
    }

    function cardHeaderHtml(s, order) {
        var typeText = typeLabel(s.section_type);
        var title = inferTitle(s.content);
        var st = statusMeta(s.status);
        var titleHtml = title
            ? '<span class="pp-section-card__title">' + escapeHtml(truncate(title, 90)) + '</span>'
            : '<span class="pp-section-card__title pp-section-card__title--empty">Sin contenido todavía</span>';
        return ''
            + '<span class="pp-drag-handle" title="Arrastra para reordenar" aria-hidden="true">'
            +   '<svg width="14" height="14" viewBox="0 0 16 16"><g fill="currentColor">'
            +     '<circle cx="6" cy="3" r="1.2"/><circle cx="10" cy="3" r="1.2"/>'
            +     '<circle cx="6" cy="8" r="1.2"/><circle cx="10" cy="8" r="1.2"/>'
            +     '<circle cx="6" cy="13" r="1.2"/><circle cx="10" cy="13" r="1.2"/>'
            +   '</g></svg>'
            + '</span>'
            + '<span class="pp-section-card__order">' + escapeHtml(pad2(order)) + '</span>'
            + sectionThumbHtml(s.section_type)
            + '<span class="pp-section-card__title-block">'
            +   '<span class="pp-section-card__eyebrow pp-section-card__type">' + escapeHtml(typeText) + '</span>'
            +   titleHtml
            + '</span>'
            + '<span class="pp-section-card__status-pill pp-section-card__status-pill--' + st.key + '">'
            +   escapeHtml(st.label)
            + '</span>'
            + '<button type="button" class="pp-section-card__toggle" aria-expanded="false" aria-label="Expandir sección">'
            +   '<svg class="pp-section-card__chevron" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">'
            +     '<path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
            +   '</svg>'
            + '</button>'
            + '<div class="pp-section-card__menu">'
            +   '<button type="button" class="pp-section-card__menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="Más acciones">'
            +     '<svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><g fill="currentColor">'
            +       '<circle cx="8" cy="3" r="1.4"/><circle cx="8" cy="8" r="1.4"/><circle cx="8" cy="13" r="1.4"/>'
            +     '</g></svg>'
            +   '</button>'
            +   '<div class="pp-section-card__menu-list" role="menu" hidden>'
            +     '<button type="button" role="menuitem" class="pp-section-card__menu-item pp-section-card__delete">'
            +       'Eliminar sección'
            +     '</button>'
            +   '</div>'
            + '</div>';
    }

    function refreshCardHeader(card) {
        if (!card) return;
        var header = card.querySelector('.pp-section-card__header');
        if (!header) return;
        var order = Array.prototype.indexOf.call(card.parentNode.children, card) + 1;
        var s = readSectionFromCard(card);
        // Si el body ya está construido, recoger valores en vivo (más fresco que los hidden).
        if (card._bodyBuilt) {
            try {
                var contentWrap = card.querySelector('.pp-section-content');
                if (contentWrap && card._sectionState) {
                    var live = collectCurrentContent(contentWrap, card._sectionState.type);
                    if (live) s.content = JSON.stringify(live);
                }
                var styleTa = card.querySelector('.pp-section-style');
                if (styleTa) s.style = styleTa.value || s.style;
                var stSel = card.querySelector('[data-meta="status"]');
                if (stSel) s.status = stSel.value || s.status;
            } catch (e) {}
        }
        var wasExpanded = header.querySelector('.pp-section-card__toggle');
        var expandedAttr = wasExpanded && wasExpanded.getAttribute('aria-expanded') === 'true';
        header.innerHTML = cardHeaderHtml(s, order);
        if (expandedAttr) {
            var toggle = header.querySelector('.pp-section-card__toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
                toggle.setAttribute('aria-label', 'Plegar sección');
            }
            card.classList.add('is-expanded');
        }
    }

    function refreshAllOrders() {
        Array.prototype.forEach.call(list.children, function (card) {
            var orderEl = card.querySelector('.pp-section-card__order');
            if (!orderEl) return;
            var n = Array.prototype.indexOf.call(card.parentNode.children, card) + 1;
            orderEl.textContent = pad2(n);
        });
    }

    function upgradeAllHeaders() {
        Array.prototype.forEach.call(list.children, function (card) {
            refreshCardHeader(card);
        });
    }

    function markDirty(card) {
        if (!card) return;
        card._isDirty = true;
        card.classList.add('is-dirty');
        var pill = card.querySelector('.pp-section-actions__dirty');
        if (pill) pill.hidden = false;
        var saveBtn = card.querySelector('.pp-section-card__save');
        if (saveBtn) saveBtn.classList.add('is-dirty');
    }

    function markClean(card) {
        if (!card) return;
        card._isDirty = false;
        card.classList.remove('is-dirty');
        var pill = card.querySelector('.pp-section-actions__dirty');
        if (pill) pill.hidden = true;
        var saveBtn = card.querySelector('.pp-section-card__save');
        if (saveBtn) saveBtn.classList.remove('is-dirty');
    }

    function closeAllMenus(except) {
        document.querySelectorAll('.pp-section-card__menu-list').forEach(function (el) {
            if (el === except) return;
            el.hidden = true;
            var btn = el.parentNode && el.parentNode.querySelector('.pp-section-card__menu-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    // ============================================================
    // Schema-driven form renderer
    // ============================================================

    var fieldCounter = 0;
    function uniqueId() { return 'pp-fld-' + (++fieldCounter); }

    /**
     * Renderiza un conjunto de fields a partir de un schema y un objeto `value`.
     * @param {Array}  fields   definición (array de field descriptors)
     * @param {Object} value    valores actuales (puede estar vacío)
     * @param {String} pathPrefix  prefijo para data-path (ej. '' o 'items[0]')
     * @returns {HTMLElement}  wrapper con los fields
     */
    function renderFields(fields, value, pathPrefix) {
        value = value || {};
        pathPrefix = pathPrefix || '';
        var wrap = document.createElement('div');
        wrap.className = 'pp-schema-fields';
        fields.forEach(function (f) {
            wrap.appendChild(renderField(f, value[f.key], pathPrefix));
        });
        return wrap;
    }

    function renderField(f, val, pathPrefix) {
        if (val === undefined || val === null) val = f.default !== undefined ? f.default : '';
        var fullPath = pathPrefix ? pathPrefix + '.' + f.key : f.key;

        var group = document.createElement('div');
        group.className = 'pp-form-group pp-schema-field';
        group.dataset.fieldType = f.type;
        group.dataset.fieldKey  = f.key;

        var id = uniqueId();
        var label = document.createElement('label');
        label.htmlFor = id;
        label.textContent = f.label || f.key;
        group.appendChild(label);

        var input;
        switch (f.type) {
            case 'textarea':
                input = document.createElement('textarea');
                input.rows = f.rows || 3;
                if (f.placeholder) input.placeholder = f.placeholder;
                input.value = val || '';
                break;

            case 'url':
                input = document.createElement('input');
                input.type = 'url';
                if (f.placeholder) input.placeholder = f.placeholder;
                input.value = val || '';
                break;

            case 'number':
                input = document.createElement('input');
                input.type = 'number';
                if (f.min !== undefined) input.min = f.min;
                input.value = val === '' ? '' : val;
                break;

            case 'select':
                input = document.createElement('select');
                var options = f.options || {};
                Object.keys(options).forEach(function (optVal) {
                    var opt = document.createElement('option');
                    opt.value = optVal;
                    opt.textContent = options[optVal];
                    if (String(val) === String(optVal)) opt.selected = true;
                    input.appendChild(opt);
                });
                break;

            case 'image':
                input = document.createElement('input');
                input.type = 'url';
                input.placeholder = f.placeholder || 'URL de la imagen';
                input.value = val || '';
                input.classList.add('pp-image-input');
                break;

            case 'link':
                // El valor vive en un input oculto; el selector visual se monta abajo.
                input = document.createElement('input');
                input.type = 'hidden';
                input.value = val || '';
                break;

            case 'repeater':
                input = renderRepeater(f, Array.isArray(val) ? val : []);
                break;

            default: // text
                input = document.createElement('input');
                input.type = 'text';
                if (f.placeholder) input.placeholder = f.placeholder;
                input.value = val || '';
        }
        input.id = id;
        input.className = (input.className || '') + ' pp-schema-input';
        input.dataset.key = f.key;
        input.dataset.type = f.type;
        if (f.type === 'repeater') {
            input.dataset.fields = JSON.stringify(f.fields);
        }
        if (f.type === 'image') {
            var imageRow = document.createElement('div');
            imageRow.className = 'pp-image-field-row';
            imageRow.appendChild(input);
            var pickBtn = document.createElement('button');
            pickBtn.type = 'button';
            pickBtn.className = 'pp-btn pp-btn--secondary pp-btn--sm pp-media-picker-btn';
            pickBtn.textContent = 'Elegir';
            pickBtn.addEventListener('click', function () {
                openMediaPicker(input);
            });
            imageRow.appendChild(pickBtn);
            group.appendChild(imageRow);
        } else {
            group.appendChild(input);
        }

        // Selector de enlace (integridad de navegación): páginas + externo + crear.
        if (f.type === 'link') {
            group.appendChild(buildLinkControl(input));
        }

        if (f.type === 'textarea') {
            group.appendChild(buildRewriteControls(input, f));
        }

        // Preview para imagen
        if (f.type === 'image' && val) {
            var preview = document.createElement('div');
            preview.className = 'pp-image-preview';
            preview.innerHTML = '<img src="' + escapeHtml(val) + '" alt="">';
            group.appendChild(preview);
            input.addEventListener('input', function () {
                preview.innerHTML = input.value ? '<img src="' + escapeHtml(input.value) + '" alt="">' : '';
            });
        } else if (f.type === 'image') {
            var preview2 = document.createElement('div');
            preview2.className = 'pp-image-preview';
            group.appendChild(preview2);
            input.addEventListener('input', function () {
                preview2.innerHTML = input.value ? '<img src="' + escapeHtml(input.value) + '" alt="">' : '';
            });
        }

        if (f.help) {
            var help = document.createElement('small');
            help.textContent = f.help;
            group.appendChild(help);
        }
        return group;
    }

    /**
     * Control de campo enlace (integridad de navegación). `hidden` es el input
     * portador del valor. Selector de páginas reales + opción externa + crear
     * página al vuelo, con aviso si el destino está en borrador.
     */
    function buildLinkControl(hidden) {
        var pages = Array.isArray(window.PP_PAGES) ? window.PP_PAGES : [];

        var wrap = document.createElement('div');
        wrap.className = 'pp-linkfield';

        var select = document.createElement('select');
        select.className = 'pp-linkfield__select';

        var optNone = document.createElement('option');
        optNone.value = '';
        optNone.textContent = '— Sin enlace —';
        select.appendChild(optNone);

        var grp = document.createElement('optgroup');
        grp.label = 'Tus páginas';
        pages.forEach(function (p) {
            var o = document.createElement('option');
            o.value = 'page:' + p.path;
            o.textContent = p.title + ' (' + p.path + ')' + (p.status !== 'published' ? ' · Borrador' : '');
            grp.appendChild(o);
        });
        if (pages.length) select.appendChild(grp);

        var optExt = document.createElement('option');
        optExt.value = '__external__';
        optExt.textContent = 'Enlace externo / personalizado…';
        select.appendChild(optExt);

        var optNew = document.createElement('option');
        optNew.value = '__create__';
        optNew.textContent = '+ Crear página nueva…';
        select.appendChild(optNew);

        var ext = document.createElement('input');
        ext.type = 'text';
        ext.className = 'pp-linkfield__external';
        ext.placeholder = 'https://ejemplo.com  o  /mi-pagina';
        ext.hidden = true;

        var hint = document.createElement('small');
        hint.className = 'pp-linkfield__hint';

        wrap.appendChild(select);
        wrap.appendChild(ext);
        wrap.appendChild(hint);

        function findPageByPath(path) {
            for (var i = 0; i < pages.length; i++) { if (pages[i].path === path) return pages[i]; }
            return null;
        }
        function setHint(msg, kind) {
            hint.textContent = msg || '';
            hint.className = 'pp-linkfield__hint' + (kind ? ' pp-linkfield__hint--' + kind : '');
        }
        function syncFromValue() {
            var v = (hidden.value || '').trim();
            if (v === '') { select.value = ''; ext.hidden = true; setHint(''); return; }
            var page = findPageByPath(v);
            if (page) {
                select.value = 'page:' + v;
                ext.hidden = true;
                if (page.status !== 'published') setHint('Esta página está en borrador: publícala para que el botón funcione en tu web.', 'warn');
                else setHint('');
            } else {
                select.value = '__external__';
                ext.hidden = false; ext.value = v;
                setHint('Enlace personalizado. Asegúrate de que la dirección es correcta.', '');
            }
        }

        select.addEventListener('change', function () {
            var val = select.value;
            if (val === '') { hidden.value = ''; ext.hidden = true; setHint(''); }
            else if (val === '__external__') {
                ext.hidden = false;
                ext.value = (hidden.value && !findPageByPath(hidden.value)) ? hidden.value : '';
                hidden.value = ext.value; ext.focus();
                setHint('Enlace personalizado. Asegúrate de que la dirección es correcta.', '');
            } else if (val === '__create__') {
                createPageInline();
            } else if (val.indexOf('page:') === 0) {
                var path = val.slice(5);
                hidden.value = path; ext.hidden = true;
                var page = findPageByPath(path);
                if (page && page.status !== 'published') setHint('Esta página está en borrador: publícala para que el botón funcione en tu web.', 'warn');
                else setHint('');
            }
        });

        ext.addEventListener('input', function () { hidden.value = ext.value.trim(); });

        function createPageInline() {
            var title = window.prompt('Nombre de la nueva página (ej. «Servicios»):');
            if (!title) { syncFromValue(); return; }
            postForm('/admin/pages/quick', { title: title })
                .then(function (resp) {
                    var p = resp.page;
                    if (!Array.isArray(window.PP_PAGES)) window.PP_PAGES = pages;
                    if (pages.indexOf(p) === -1) pages.push(p);
                    var og = select.querySelector('optgroup');
                    if (!og) { og = document.createElement('optgroup'); og.label = 'Tus páginas'; select.insertBefore(og, optExt); }
                    var o = document.createElement('option');
                    o.value = 'page:' + p.path;
                    o.textContent = p.title + ' (' + p.path + ') · Borrador';
                    og.appendChild(o);
                    hidden.value = p.path;
                    select.value = 'page:' + p.path;
                    ext.hidden = true;
                    setHint('Página «' + p.title + '» creada en borrador. Recuerda publicarla para que el enlace funcione.', 'warn');
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                })
                .catch(function (err) {
                    setHint('No se pudo crear la página: ' + (err.message || 'error'), 'err');
                    syncFromValue();
                });
        }

        syncFromValue();
        return wrap;
    }

    function buildRewriteControls(input, fieldDef) {
        var wrap = document.createElement('div');
        wrap.className = 'pp-field-ai-tools';
        wrap.innerHTML = ''
            + '<div class="pp-field-ai-tools__row">'
            + '  <select class="pp-field-ai-goal" aria-label="Objetivo de reescritura">'
            + '    <option value="Hazlo más claro y específico, manteniendo el significado.">Más claro</option>'
            + '    <option value="Hazlo más breve, directo y fácil de escanear.">Más corto</option>'
            + '    <option value="Hazlo más persuasivo y orientado a conversión, sin exagerar.">Más persuasivo</option>'
            + '    <option value="Hazlo más cercano y natural, sin perder profesionalidad.">Más cercano</option>'
            + '    <option value="Hazlo más profesional, concreto y sobrio.">Más profesional</option>'
            + '  </select>'
            + '  <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm pp-field-ai-rewrite">Reescribir</button>'
            + '</div>'
            + '<div class="pp-field-ai-status" aria-live="polite"></div>';

        var select = wrap.querySelector('.pp-field-ai-goal');
        var btn = wrap.querySelector('.pp-field-ai-rewrite');
        var status = wrap.querySelector('.pp-field-ai-status');
        btn.addEventListener('click', function () {
            runTextRewrite(input, fieldDef, select.value, btn, status);
        });
        return wrap;
    }

    function runTextRewrite(input, fieldDef, goal, btn, status) {
        var original = (input.value || '').trim();
        if (!original) {
            status.className = 'pp-field-ai-status pp-err';
            status.textContent = 'Escribe algo primero para poder reescribirlo.';
            return;
        }

        var card = input.closest('.pp-section-card');
        var type = card && card._sectionState ? card._sectionState.type : '';
        var fieldLabel = fieldDef.label || fieldDef.key || 'campo';
        var rewriteGoal = goal
            + '\nCampo: ' + fieldLabel
            + '\nPágina: ' + pageTitle
            + (type ? '\nTipo de sección: ' + typeLabel(type) : '')
            + '\nNo añadas HTML ni markdown. Devuelve solo el texto final para ese campo.';

        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'Reescribiendo...';
        status.className = 'pp-field-ai-status is-loading';
        status.innerHTML = '<span></span><span></span><em>Reescribiendo sin guardar todavía.</em>';

        postForm('/admin/ai/actions/run', {
            action: 'rewrite_text',
            input_json: JSON.stringify({
                original_text: original,
                rewrite_goal: rewriteGoal,
            }),
        })
        .then(function (resp) {
            input.value = String(resp.data || '').trim();
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (card) card._aiDraftApplied = true;
            status.className = 'pp-field-ai-status pp-ok';
            status.innerHTML = 'Texto reescrito. Revisa y guarda la sección.'
                + '<span class="pp-field-ai-meta">' + escapeHtml(aiMeta(resp)) + '</span>';
        })
        .catch(function (err) {
            status.className = 'pp-field-ai-status pp-err';
            status.textContent = 'No se pudo reescribir: ' + err.message;
        })
        .finally(function () {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.textContent = 'Reescribir';
        });
    }

    function computeItemSummary(fieldsWrap, fieldsDef) {
        var summaryFields = (fieldsDef || []).filter(function (f) {
            var t = f.type || 'text';
            return t === 'text' || t === 'textarea' || t === 'url' || t === 'number';
        });
        var values = [];
        summaryFields.forEach(function (f) {
            var group = fieldsWrap.querySelector(':scope > .pp-schema-field[data-field-key="' + cssSafeJs(f.key) + '"]');
            if (!group) return;
            var input = group.querySelector('input, textarea');
            if (!input) return;
            var v = (input.value || '').trim();
            if (v) values.push(v);
        });
        return {
            title: values[0] || '',
            meta: values.slice(1, 3).join(' · '),
        };
    }

    function renderRepeater(f, items) {
        var wrap = document.createElement('div');
        wrap.className = 'pp-repeater';
        wrap.dataset.key = f.key;

        var itemsWrap = document.createElement('div');
        itemsWrap.className = 'pp-repeater__items';
        wrap.appendChild(itemsWrap);

        function refreshSummary(item) {
            var fieldsWrap = item.querySelector(':scope > .pp-repeater__item-fields > .pp-schema-fields');
            if (!fieldsWrap) return;
            var s = computeItemSummary(fieldsWrap, f.fields);
            var titleEl = item.querySelector(':scope > .pp-repeater__item-header .pp-repeater__item-title');
            var metaEl  = item.querySelector(':scope > .pp-repeater__item-header .pp-repeater__item-meta');
            if (titleEl) {
                if (s.title) {
                    titleEl.textContent = s.title.length > 80 ? s.title.slice(0, 79) + '…' : s.title;
                    titleEl.classList.remove('pp-repeater__item-title--empty');
                } else {
                    titleEl.textContent = '(' + (f.itemLabel || 'Item').toLowerCase() + ' sin completar)';
                    titleEl.classList.add('pp-repeater__item-title--empty');
                }
            }
            if (metaEl) {
                metaEl.textContent = s.meta || '';
                metaEl.hidden = !s.meta;
            }
        }

        function setItemExpanded(item, expanded) {
            var fields = item.querySelector(':scope > .pp-repeater__item-fields');
            var toggle = item.querySelector(':scope > .pp-repeater__item-header .pp-repeater__item-toggle');
            if (!fields || !toggle) return;
            fields.hidden = !expanded;
            item.classList.toggle('is-expanded', expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function addItem(val, opts) {
            opts = opts || {};
            var idx = itemsWrap.children.length;
            var item = document.createElement('div');
            item.className = 'pp-repeater__item';
            item.draggable = true;

            var header = document.createElement('div');
            header.className = 'pp-repeater__item-header';
            header.innerHTML = ''
                + '<span class="pp-repeater__item-drag" title="Arrastra para reordenar" aria-hidden="true">'
                +   '<svg width="12" height="12" viewBox="0 0 16 16"><g fill="currentColor">'
                +     '<circle cx="6" cy="3" r="1.1"/><circle cx="10" cy="3" r="1.1"/>'
                +     '<circle cx="6" cy="8" r="1.1"/><circle cx="10" cy="8" r="1.1"/>'
                +     '<circle cx="6" cy="13" r="1.1"/><circle cx="10" cy="13" r="1.1"/>'
                +   '</g></svg>'
                + '</span>'
                + '<span class="pp-repeater__item-order">' + (idx + 1) + '</span>'
                + '<span class="pp-repeater__item-summary">'
                +   '<span class="pp-repeater__item-eyebrow">' + escapeHtml(f.itemLabel || 'Item') + '</span>'
                +   '<span class="pp-repeater__item-title"></span>'
                +   '<span class="pp-repeater__item-meta" hidden></span>'
                + '</span>'
                + '<button type="button" class="pp-repeater__item-toggle" aria-expanded="false" aria-label="Mostrar campos">'
                +   '<svg class="pp-repeater__item-chevron" width="12" height="12" viewBox="0 0 16 16" aria-hidden="true">'
                +     '<path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>'
                +   '</svg>'
                + '</button>'
                + '<button type="button" class="pp-repeater__item-remove" aria-label="Eliminar elemento">'
                +   '<svg width="12" height="12" viewBox="0 0 16 16" aria-hidden="true">'
                +     '<path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>'
                +   '</svg>'
                + '</button>';
            item.appendChild(header);

            var fieldsBox = document.createElement('div');
            fieldsBox.className = 'pp-repeater__item-fields';
            fieldsBox.hidden = true;
            fieldsBox.appendChild(renderFields(f.fields, val || {}, ''));
            item.appendChild(fieldsBox);

            // Eventos
            header.querySelector('.pp-repeater__item-toggle').addEventListener('click', function (e) {
                e.stopPropagation();
                setItemExpanded(item, fieldsBox.hidden);
            });
            // Click en summary (zona neutra) también colapsa/expande.
            header.querySelector('.pp-repeater__item-summary').addEventListener('click', function () {
                setItemExpanded(item, fieldsBox.hidden);
            });
            header.querySelector('.pp-repeater__item-remove').addEventListener('click', function (e) {
                e.stopPropagation();
                item.remove();
                renumberItems(itemsWrap, f.itemLabel || 'Item');
            });
            // Refresca summary cuando cualquier input cambia.
            fieldsBox.addEventListener('input', function () { refreshSummary(item); });

            itemsWrap.appendChild(item);
            refreshSummary(item);

            // Nuevos items se crean expandidos. Items cargados → colapsados.
            if (opts.startExpanded) setItemExpanded(item, true);
        }

        (items || []).forEach(function (v) { addItem(v, { startExpanded: false }); });

        var addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'pp-btn pp-btn--secondary pp-btn--sm pp-repeater__add';
        addBtn.textContent = '+ Añadir ' + (f.itemLabel || 'elemento').toLowerCase();
        addBtn.addEventListener('click', function () { addItem({}, { startExpanded: true }); });
        wrap.appendChild(addBtn);

        // Drag & drop entre items del repeater.
        var draggedItem = null;
        itemsWrap.addEventListener('dragstart', function (e) {
            var it = e.target.closest('.pp-repeater__item');
            if (!it || !itemsWrap.contains(it)) return;
            if (e.target.closest('.pp-repeater__item-fields')) { e.preventDefault(); return; }
            draggedItem = it;
            it.classList.add('is-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; } catch (err) {}
        });
        itemsWrap.addEventListener('dragend', function () {
            if (!draggedItem) return;
            draggedItem.classList.remove('is-dragging');
            draggedItem = null;
            renumberItems(itemsWrap, f.itemLabel || 'Item');
        });
        itemsWrap.addEventListener('dragover', function (e) {
            if (!draggedItem) return;
            e.preventDefault();
            var target = e.target.closest('.pp-repeater__item');
            if (!target || target === draggedItem) return;
            var rect = target.getBoundingClientRect();
            if ((e.clientY - rect.top) > rect.height / 2) target.after(draggedItem);
            else target.before(draggedItem);
        });

        return wrap;
    }

    function renumberItems(itemsWrap, itemLabel) {
        Array.from(itemsWrap.children).forEach(function (item, idx) {
            var orderEl = item.querySelector(':scope > .pp-repeater__item-header .pp-repeater__item-order');
            if (orderEl) orderEl.textContent = String(idx + 1);
        });
    }

    /**
     * Lee los valores de un wrapper de schema fields → objeto.
     */
    function collectFields(wrap) {
        var result = {};
        wrap.querySelectorAll(':scope > .pp-schema-field').forEach(function (group) {
            var type = group.dataset.fieldType;
            var key  = group.dataset.fieldKey;

            if (type === 'repeater') {
                var rep = group.querySelector('.pp-repeater');
                result[key] = collectRepeater(rep);
            } else {
                var input = group.querySelector('.pp-schema-input');
                if (!input) return;
                var v = input.value;
                if (type === 'number' && v !== '') v = Number(v);
                result[key] = v;
            }
        });
        return result;
    }

    function collectRepeater(rep) {
        var fieldsDef;
        try { fieldsDef = JSON.parse(rep.querySelector('.pp-schema-input, .pp-repeater').dataset.fields || '[]'); }
        catch (e) { fieldsDef = []; }
        var out = [];
        var items = rep.querySelectorAll(':scope > .pp-repeater__items > .pp-repeater__item');
        items.forEach(function (item) {
            // Estructura nueva: .pp-repeater__item-fields > .pp-schema-fields
            // Estructura antigua (fallback): .pp-schema-fields directo.
            var fieldsWrap = item.querySelector(':scope > .pp-repeater__item-fields > .pp-schema-fields')
                          || item.querySelector(':scope > .pp-schema-fields');
            if (fieldsWrap) out.push(collectFields(fieldsWrap));
        });
        return out;
    }

    // ============================================================
    // Card body: build / rebuild
    // ============================================================

    // Iconos de las tabs (Contenido · Diseño · IA · Avanzado).
    var TAB_ICONS = {
        content:  '<path d="M5 4h10l4 4v12H5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M15 4v4h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 12h8M8 16h6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        design:   '<circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="9" cy="9" r="1.2" fill="currentColor"/><circle cx="15" cy="9" r="1.2" fill="currentColor"/><circle cx="9" cy="15" r="1.2" fill="currentColor"/><circle cx="15.5" cy="14" r="2" fill="currentColor"/>',
        ai:       '<path d="M12 3l1.6 4.2L18 8.8l-3.4 2.8L15.8 16 12 13.6 8.2 16l1.2-4.4L6 8.8l4.4-1.6z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="18.5" cy="17.5" r="1.6" fill="currentColor"/>',
        advanced: '<circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v2.5M12 18.5V21M3 12h2.5M18.5 12H21M5.6 5.6l1.8 1.8M16.6 16.6l1.8 1.8M5.6 18.4l1.8-1.8M16.6 7.4l1.8-1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
    };

    function tabBtnHtml(name, label) {
        var isActive = name === 'content';
        return '<button type="button" role="tab" class="pp-section-tab' + (isActive ? ' is-active' : '') + '"'
            + ' data-tab="' + name + '"'
            + ' aria-selected="' + (isActive ? 'true' : 'false') + '"'
            + ' tabindex="' + (isActive ? '0' : '-1') + '">'
            + '<svg class="pp-section-tab__icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">' + TAB_ICONS[name] + '</svg>'
            + '<span class="pp-section-tab__label">' + label + '</span>'
            + '</button>';
    }

    function activateTab(nav, panels, name) {
        Array.prototype.forEach.call(nav.children, function (b) {
            if (!b.dataset || !b.dataset.tab) return;
            var active = b.dataset.tab === name;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-selected', active ? 'true' : 'false');
            b.setAttribute('tabindex', active ? '0' : '-1');
        });
        Array.prototype.forEach.call(panels.children, function (p) {
            if (!p.dataset || !p.dataset.panel) return;
            var match = p.dataset.panel === name;
            p.hidden = !match;
            p.classList.toggle('is-active', match);
        });
    }

    function buildCardBody(card, section) {
        var body = card.querySelector('.pp-section-card__body');
        body.innerHTML = '';

        // ----- Split: form izquierda · preview derecha (A1) -----
        var split = document.createElement('div');
        split.className = 'pp-section-card__split';
        body.appendChild(split);

        var leftCol = document.createElement('div');
        leftCol.className = 'pp-section-card__left';
        split.appendChild(leftCol);

        // ----- Tabs nav (en columna izquierda) -----
        var tabsNav = document.createElement('div');
        tabsNav.className = 'pp-section-tabs';
        tabsNav.setAttribute('role', 'tablist');
        tabsNav.innerHTML = ''
            + tabBtnHtml('content',  'Contenido')
            + tabBtnHtml('design',   'Diseño')
            + tabBtnHtml('ai',       'IA')
            + tabBtnHtml('advanced', 'Avanzado');
        leftCol.appendChild(tabsNav);

        // ----- Panels (en columna izquierda) -----
        var panels = document.createElement('div');
        panels.className = 'pp-section-panels';
        leftCol.appendChild(panels);

        // ----- Panel preview (columna derecha) -----
        var previewAside = document.createElement('aside');
        previewAside.className = 'pp-section-card__preview';
        previewAside.innerHTML = ''
            + '<div class="pp-section-card__preview-toolbar">'
            +   '<div class="pp-device-toggle" role="radiogroup" aria-label="Vista del dispositivo">'
            +     '<button type="button" class="pp-device-toggle__btn is-active" data-device="desktop" role="radio" aria-checked="true" title="Escritorio">'
            +       '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M9 20h6M12 16v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>'
            +     '</button>'
            +     '<button type="button" class="pp-device-toggle__btn" data-device="tablet" role="radio" aria-checked="false" title="Tablet">'
            +       '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="18" r="0.7" fill="currentColor"/></svg>'
            +     '</button>'
            +     '<button type="button" class="pp-device-toggle__btn" data-device="mobile" role="radio" aria-checked="false" title="Móvil">'
            +       '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><rect x="7" y="3" width="10" height="18" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="18" r="0.7" fill="currentColor"/></svg>'
            +     '</button>'
            +   '</div>'
            +   '<div class="pp-section-card__preview-status" aria-live="polite"></div>'
            + '</div>'
            + '<div class="pp-section-card__preview-stage is-device-desktop">'
            +   '<div class="pp-section-card__preview-frame">'
            +     '<iframe class="pp-section-card__preview-iframe" title="Vista previa de la sección" sandbox="allow-same-origin"></iframe>'
            +   '</div>'
            + '</div>';
        split.appendChild(previewAside);

        // Panel: Contenido (campos del schema).
        var contentPanel = document.createElement('section');
        contentPanel.className = 'pp-section-panel is-active';
        contentPanel.setAttribute('role', 'tabpanel');
        contentPanel.dataset.panel = 'content';
        var contentWrap = document.createElement('div');
        contentWrap.className = 'pp-section-content';
        contentPanel.appendChild(contentWrap);
        panels.appendChild(contentPanel);

        // Panel: Diseño (variant picker; placeholder para overrides futuros).
        var designPanel = document.createElement('section');
        designPanel.className = 'pp-section-panel';
        designPanel.setAttribute('role', 'tabpanel');
        designPanel.dataset.panel = 'design';
        designPanel.hidden = true;
        var variantRow = document.createElement('div');
        variantRow.className = 'pp-form-group pp-variant-picker';
        variantRow.innerHTML = ''
            + '<label class="pp-variant-picker__label">Estilo de sección</label>'
            + '<div class="pp-variant-picker__chips" role="radiogroup" aria-label="Estilo de sección"></div>'
            + '<small class="pp-variant-picker__hint">Cada estilo cambia el diseño visual sin tocar el contenido.</small>';
        designPanel.appendChild(variantRow);
        panels.appendChild(designPanel);
        var variantChips = variantRow.querySelector('.pp-variant-picker__chips');

        // Panel: IA (asistente de generación / reescritura).
        var aiPanelTab = document.createElement('section');
        aiPanelTab.className = 'pp-section-panel';
        aiPanelTab.setAttribute('role', 'tabpanel');
        aiPanelTab.dataset.panel = 'ai';
        aiPanelTab.hidden = true;
        var aiPanel = buildAiPanel(card, contentWrap);
        aiPanelTab.appendChild(aiPanel);
        panels.appendChild(aiPanelTab);

        // Panel: Avanzado (tipo + estado + JSON toggle + estilo JSON).
        var advPanel = document.createElement('section');
        advPanel.className = 'pp-section-panel';
        advPanel.setAttribute('role', 'tabpanel');
        advPanel.dataset.panel = 'advanced';
        advPanel.hidden = true;

        var metaRow = document.createElement('div');
        metaRow.className = 'pp-form-row';
        metaRow.innerHTML = ''
            + '<div class="pp-form-group"><label>Tipo de sección</label>'
            + '  <select class="pp-section-meta" data-meta="section_type">' + typeOptions(section.section_type) + '</select>'
            + '  <small>Cambiarlo regenera el formulario y resetea la variante.</small>'
            + '</div>'
            + '<div class="pp-form-group"><label>Estado</label>'
            + '  <select class="pp-section-meta" data-meta="status">'
            + '    <option value="editable"' + (section.status === 'editable' ? ' selected' : '') + '>Editable</option>'
            + '    <option value="locked"' + (section.status === 'locked' ? ' selected' : '') + '>Bloqueada</option>'
            + '  </select>'
            + '  <small>Bloqueada se marca con el pill ámbar en la cabecera.</small>'
            + '</div>';
        advPanel.appendChild(metaRow);

        var advBar = document.createElement('div');
        advBar.className = 'pp-json-toggle-bar';
        advBar.innerHTML = '<label><input type="checkbox" class="pp-json-toggle"> Editar contenido como JSON</label>'
            + '<small>Útil para depurar o pegar estructura completa de la sección.</small>';
        advPanel.appendChild(advBar);

        var styleGroup = document.createElement('div');
        styleGroup.className = 'pp-form-group';
        styleGroup.innerHTML = '<label>Estilo (JSON, opcional)</label>'
            + '<textarea class="pp-section-style pp-json-editor" rows="3" placeholder=\'{"background_color": "#...", "text_align": "center"}\'></textarea>'
            + '<small>Overrides puntuales de estilo. La variante elegida en "Diseño" se guarda aquí automáticamente.</small>';
        advPanel.appendChild(styleGroup);
        panels.appendChild(advPanel);

        var styleTa = styleGroup.querySelector('textarea');
        try {
            var s = JSON.parse(section.style || 'null');
            styleTa.value = s ? JSON.stringify(s, null, 2) : '';
        } catch (e) { styleTa.value = section.style || ''; }

        // ----- Actions (sticky bajo los paneles) -----
        var actions = document.createElement('div');
        actions.className = 'pp-form-actions pp-section-actions';
        actions.innerHTML = ''
            + '<span class="pp-section-actions__dirty" hidden>'
            +   '<span class="pp-section-actions__dot" aria-hidden="true"></span> Cambios sin guardar'
            + '</span>'
            + '<span class="pp-section-card__status" aria-live="polite"></span>'
            + '<button type="button" class="pp-btn pp-btn--secondary pp-section-card__versions">Historial</button>'
            + '<button type="button" class="pp-btn pp-btn--secondary pp-section-card__cancel">Cerrar</button>'
            + '<button type="button" class="pp-btn pp-btn--primary pp-section-card__save">Guardar sección</button>';
        body.appendChild(actions);

        // ----- Render inicial -----
        var currentContent = parseContent(section.content);
        card._sectionState = { content: currentContent, type: section.section_type };
        renderContentArea(contentWrap, section.section_type, currentContent, false);

        renderVariantChips(variantChips, card._sectionState.type, readVariantFromStyle(styleTa));

        // Listeners de "dirty" (cualquier edición marca cambios sin guardar).
        panels.addEventListener('input',  function () { markDirty(card); });
        panels.addEventListener('change', function () { markDirty(card); });

        // ----- Handlers -----
        variantChips.addEventListener('click', function (e) {
            var chip = e.target.closest('.pp-variant-chip');
            if (!chip) return;
            var v = chip.dataset.variant || 'default';
            writeVariantToStyle(styleTa, v);
            highlightVariantChip(variantChips, v);
            refreshCardHeader(card);
            markDirty(card);
        });

        metaRow.querySelector('[data-meta="status"]').addEventListener('change', function () {
            refreshCardHeader(card);
        });

        advBar.querySelector('.pp-json-toggle').addEventListener('change', function (e) {
            card._sectionState.content = collectCurrentContent(contentWrap, card._sectionState.type);
            renderContentArea(contentWrap, card._sectionState.type, card._sectionState.content, e.target.checked);
        });

        metaRow.querySelector('[data-meta="section_type"]').addEventListener('change', function (e) {
            card._sectionState.content = collectCurrentContent(contentWrap, card._sectionState.type);
            card._sectionState.type = e.target.value;
            var advOn = advBar.querySelector('.pp-json-toggle').checked;
            renderContentArea(contentWrap, card._sectionState.type, card._sectionState.content, advOn);
            writeVariantToStyle(styleTa, 'default');
            renderVariantChips(variantChips, card._sectionState.type, 'default');
            refreshCardHeader(card);
        });

        // Tabs: click + teclado (flechas).
        function onTabActivated(name) {
            if (name === 'design') {
                // Los chips solo tienen ancho medible cuando el panel es visible.
                fitChipPreviews(variantChips);
                observeChipResize(variantChips);
                // Refresca miniaturas con el contenido real del editor.
                refreshChipsWithRealContent(card, variantChips);
            }
        }
        tabsNav.addEventListener('click', function (e) {
            var btn = e.target.closest('.pp-section-tab');
            if (!btn) return;
            activateTab(tabsNav, panels, btn.dataset.tab);
            onTabActivated(btn.dataset.tab);
        });
        tabsNav.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            var tabs = Array.prototype.filter.call(tabsNav.children, function (b) { return b.dataset && b.dataset.tab; });
            var idx = tabs.findIndex(function (b) { return b.classList.contains('is-active'); });
            if (idx < 0) return;
            var next = e.key === 'ArrowRight' ? (idx + 1) % tabs.length : (idx - 1 + tabs.length) % tabs.length;
            activateTab(tabsNav, panels, tabs[next].dataset.tab);
            tabs[next].focus();
            onTabActivated(tabs[next].dataset.tab);
            e.preventDefault();
        });

        // ----- Live preview (A1) -----
        var stage = previewAside.querySelector('.pp-section-card__preview-stage');
        var previewIframe = previewAside.querySelector('.pp-section-card__preview-iframe');
        var previewStatus = previewAside.querySelector('.pp-section-card__preview-status');
        var previewDeviceBtns = previewAside.querySelectorAll('.pp-device-toggle__btn');
        card._previewCtl = {
            iframe: previewIframe, stage: stage, status: previewStatus,
            device: 'desktop',
            lastKey: '',
            timer: null,
            inflight: null,
        };

        function setDevice(name) {
            card._previewCtl.device = name;
            stage.classList.remove('is-device-desktop', 'is-device-tablet', 'is-device-mobile');
            stage.classList.add('is-device-' + name);
            previewDeviceBtns.forEach(function (b) {
                var active = b.dataset.device === name;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-checked', active ? 'true' : 'false');
            });
            schedulePreview(card, 0);
        }
        previewDeviceBtns.forEach(function (b) {
            b.addEventListener('click', function () { setDevice(b.dataset.device); });
        });

        // Cualquier edición programa un refresh del preview (debounced).
        leftCol.addEventListener('input',  function () { schedulePreview(card, 350); });
        leftCol.addEventListener('change', function () { schedulePreview(card, 200); });

        // Refresh inmediato cuando cambia variante.
        variantChips.addEventListener('click', function (e) {
            if (e.target.closest('.pp-variant-chip')) schedulePreview(card, 0);
        });

        // Primera carga del preview + observer de resize.
        schedulePreview(card, 0);
        observePreviewResize(card);
    }

    // ============================================================
    // Variantes (T18.1)
    // ============================================================

    function readVariantFromStyle(styleTa) {
        var raw = (styleTa && styleTa.value || '').trim();
        if (!raw) return 'default';
        try {
            var obj = JSON.parse(raw);
            if (obj && typeof obj === 'object' && typeof obj.variant === 'string' && obj.variant) {
                return obj.variant;
            }
        } catch (e) {}
        return 'default';
    }

    function writeVariantToStyle(styleTa, variant) {
        if (!styleTa) return;
        var raw = (styleTa.value || '').trim();
        var obj = {};
        if (raw) {
            try { var parsed = JSON.parse(raw); if (parsed && typeof parsed === 'object') obj = parsed; }
            catch (e) {}
        }
        if (variant && variant !== 'default') {
            obj.variant = variant;
        } else {
            delete obj.variant;
        }
        styleTa.value = Object.keys(obj).length ? JSON.stringify(obj, null, 2) : '';
    }

    function renderVariantChips(host, type, current) {
        var schema = SCHEMAS[type];
        var variants = (schema && schema.variants && typeof schema.variants === 'object')
            ? schema.variants
            : { 'default': 'Por defecto' };
        var keys = Object.keys(variants);
        if (keys.indexOf('default') === -1) keys.unshift('default');
        if (!variants['default']) variants['default'] = 'Por defecto';

        host.innerHTML = '';
        keys.forEach(function (k) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pp-variant-chip' + (k === current ? ' is-active' : '');
            btn.dataset.variant = k;
            btn.setAttribute('role', 'radio');
            btn.setAttribute('aria-checked', k === current ? 'true' : 'false');
            var previewSrc = url('/admin/sections/variant-preview?type=' + encodeURIComponent(type) + '&variant=' + encodeURIComponent(k));
            btn.innerHTML = ''
                + '<span class="pp-variant-chip__preview-wrap">'
                +   '<span class="pp-variant-chip__preview">'
                +     '<iframe loading="lazy" tabindex="-1" aria-hidden="true" src="' + escapeHtml(previewSrc) + '" title="' + escapeHtml(variants[k]) + '"></iframe>'
                +   '</span>'
                +   '<span class="pp-variant-chip__check" aria-hidden="true">'
                +     '<svg viewBox="0 0 16 16" width="12" height="12"><path d="M3.5 8.5l3 3 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                +   '</span>'
                + '</span>'
                + '<span class="pp-variant-chip__meta">'
                +   '<span class="pp-variant-chip__label">' + escapeHtml(variants[k]) + '</span>'
                +   '<span class="pp-variant-chip__current">Actual</span>'
                + '</span>';
            host.appendChild(btn);
        });
    }

    function highlightVariantChip(host, variant) {
        host.querySelectorAll('.pp-variant-chip').forEach(function (c) {
            var active = c.dataset.variant === variant;
            c.classList.toggle('is-active', active);
            c.setAttribute('aria-checked', active ? 'true' : 'false');
        });
    }

    // ============================================================
    // Live preview por card (A1)
    // ============================================================

    var DEVICE_WIDTHS = { desktop: 1200, tablet: 820, mobile: 390 };

    function schedulePreview(card, delay) {
        if (!card || !card._previewCtl) return;
        if (card._previewCtl.timer) clearTimeout(card._previewCtl.timer);
        card._previewCtl.timer = setTimeout(function () { requestPreview(card); }, Math.max(0, delay));
    }

    function requestPreview(card) {
        var ctl = card._previewCtl;
        if (!ctl || !ctl.iframe) return;
        var state = card._sectionState;
        if (!state) return;
        var contentWrap = card.querySelector('.pp-section-content');
        var styleTa = card.querySelector('.pp-section-style');
        if (!contentWrap) return;

        var liveContent;
        try { liveContent = collectCurrentContent(contentWrap, state.type) || {}; }
        catch (e) { return; }
        var contentJson = JSON.stringify(liveContent);
        var styleJson = (styleTa && styleTa.value || '').trim();

        var key = state.type + '|' + ctl.device + '|' + contentJson + '|' + styleJson;
        if (key === ctl.lastKey) return;
        ctl.lastKey = key;

        if (ctl.inflight && typeof ctl.inflight.abort === 'function') {
            try { ctl.inflight.abort(); } catch (e) {}
        }

        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('type', state.type);
        // Variante: la actual del style (no fuerza otra). El endpoint la lee de style.
        fd.append('variant', readVariantFromValue(styleJson));
        fd.append('content', contentJson);
        if (styleJson) fd.append('style', styleJson);

        ctl.stage.classList.add('is-loading');
        if (ctl.status) ctl.status.textContent = 'Actualizando…';

        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        ctl.inflight = ctrl;

        fetch(url('/admin/sections/variant-preview'), {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
            signal: ctrl ? ctrl.signal : undefined,
        })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)); })
        .then(function (html) {
            ctl.iframe.srcdoc = html;
            ctl.iframe.addEventListener('load', function onLoad() {
                ctl.iframe.removeEventListener('load', onLoad);
                ctl.stage.classList.remove('is-loading');
                ctl.stage.classList.add('is-loaded');
                if (ctl.status) ctl.status.textContent = '';
                fitPreviewScale(card);
                // Remeasure tras posible carga de imágenes / fuentes.
                setTimeout(function () { fitPreviewScale(card); }, 600);
            }, { once: true });
        })
        .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            ctl.stage.classList.remove('is-loading');
            if (ctl.status) ctl.status.textContent = 'Error en preview';
        });
    }

    function readVariantFromValue(styleJson) {
        if (!styleJson) return 'default';
        try {
            var o = JSON.parse(styleJson);
            return (o && typeof o.variant === 'string' && o.variant) ? o.variant : 'default';
        } catch (e) { return 'default'; }
    }

    function fitPreviewScale(card) {
        var ctl = card && card._previewCtl;
        if (!ctl || !ctl.stage) return;
        var frame = ctl.stage.querySelector('.pp-section-card__preview-frame');
        var iframe = ctl.iframe;
        if (!frame || !iframe) return;
        var deviceW = DEVICE_WIDTHS[ctl.device] || 1200;
        var stageW = ctl.stage.clientWidth || 600;
        // Padding interior del stage para no pegar al borde.
        var availW = Math.max(280, stageW - 32);
        var scale = Math.min(1, availW / deviceW);

        // Altura del contenido renderizado dentro del iframe.
        var contentH = 800;
        try {
            var doc = iframe.contentDocument;
            if (doc && doc.body) {
                var h = doc.body.scrollHeight;
                if (h && h > 0) contentH = h;
            }
        } catch (e) {}

        // Set explícito de width/height/transform en el iframe + dimensiones del frame
        // (sin depender de CSS vars heredadas, que dieron problemas con iframes).
        iframe.style.width = deviceW + 'px';
        iframe.style.height = contentH + 'px';
        iframe.style.transform = 'scale(' + scale.toFixed(4) + ')';
        iframe.style.transformOrigin = 'top left';
        frame.style.width  = Math.ceil(deviceW * scale) + 'px';
        frame.style.height = Math.ceil(contentH * scale) + 'px';
    }

    // Reaccionar a cambios de tamaño del stage (cambio de tab, resize de ventana).
    function observePreviewResize(card) {
        if (typeof ResizeObserver === 'undefined') return;
        var ctl = card._previewCtl;
        if (!ctl || ctl._resizeObserver) return;
        ctl._resizeObserver = new ResizeObserver(function () { fitPreviewScale(card); });
        ctl._resizeObserver.observe(ctl.stage);
    }

    // Recarga las miniaturas con el contenido REAL del editor (E1.10).
    // Lanza un POST por cada chip y setea iframe.srcdoc con el HTML devuelto.
    // Cachea por hash(type+variant+content+style) en el propio chip para no
    // refetch si nada relevante cambió.
    function refreshChipsWithRealContent(card, variantChips) {
        if (!variantChips || !card._sectionState) return;
        var contentWrap = card.querySelector('.pp-section-content');
        var styleTa     = card.querySelector('.pp-section-style');
        if (!contentWrap) return;
        var type = card._sectionState.type;
        var liveContent;
        try {
            liveContent = collectCurrentContent(contentWrap, type);
        } catch (e) {
            return;
        }
        var contentJson = JSON.stringify(liveContent || {});
        var baseStyle = {};
        if (styleTa && styleTa.value) {
            try {
                var p = JSON.parse(styleTa.value);
                if (p && typeof p === 'object') baseStyle = p;
            } catch (e) {}
        }
        var chips = variantChips.querySelectorAll('.pp-variant-chip');
        chips.forEach(function (chip) {
            var v = chip.dataset.variant || 'default';
            var styleObj = Object.assign({}, baseStyle);
            if (v !== 'default') styleObj.variant = v;
            else delete styleObj.variant;
            var styleJson = Object.keys(styleObj).length ? JSON.stringify(styleObj) : '';
            var key = type + '|' + v + '|' + contentJson + '|' + styleJson;
            if (chip._previewKey === key) return; // sin cambios → no refetch
            chip._previewKey = key;

            var iframe = chip.querySelector('iframe');
            if (!iframe) return;

            var fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('type', type);
            fd.append('variant', v);
            fd.append('content', contentJson);
            if (styleJson) fd.append('style', styleJson);

            fetch(url('/admin/sections/variant-preview'), {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html == null) return;
                iframe.removeAttribute('src');
                iframe.srcdoc = html;
            })
            .catch(function () { /* silencioso: el iframe queda con su src GET */ });
        });
    }

    // Calcula la escala que necesita cada chip para que un iframe de 1200px
    // de ancho quepa visualmente dentro del thumb. Reaplica al resize.
    function fitChipPreviews(host) {
        if (!host) return;
        var chips = host.querySelectorAll('.pp-variant-chip');
        chips.forEach(function (chip) {
            var wrap = chip.querySelector('.pp-variant-chip__preview-wrap');
            if (!wrap) return;
            var w = wrap.getBoundingClientRect().width;
            if (!w) return;
            chip.style.setProperty('--pp-vc-scale', (w / 1200).toFixed(4));
        });
    }
    var _chipFitObserver = null;
    function observeChipResize(host) {
        if (typeof ResizeObserver === 'undefined') return;
        if (_chipFitObserver) _chipFitObserver.disconnect();
        _chipFitObserver = new ResizeObserver(function () { fitChipPreviews(host); });
        _chipFitObserver.observe(host);
    }

    function cssSafeJs(s) {
        return String(s || '').replace(/[^a-zA-Z0-9_-]/g, '-');
    }

    function renderContentArea(wrap, type, content, forceJsonMode) {
        wrap.innerHTML = '';
        var schema = SCHEMAS[type];
        var useJson = forceJsonMode || !schema || schema.editor === 'json';

        if (useJson) {
            var group = document.createElement('div');
            group.className = 'pp-form-group';
            group.innerHTML = '<label>Contenido (JSON)</label>'
                + '<textarea class="pp-section-content-json pp-json-editor" rows="12"></textarea>'
                + '<small>' + (schema ? 'Modo avanzado activo. Desmarca la casilla para volver al formulario.' : 'Este tipo no tiene formulario tipado.') + '</small>';
            wrap.appendChild(group);
            var ta = group.querySelector('textarea');
            try {
                ta.value = JSON.stringify(content || {}, null, 2);
            } catch (e) {
                ta.value = '{}';
            }
        } else {
            var header = document.createElement('div');
            header.className = 'pp-schema-description';
            if (schema.description) header.textContent = schema.description;
            wrap.appendChild(header);
            wrap.appendChild(renderFields(schema.fields, content || {}, ''));
        }
    }

    function collectCurrentContent(wrap, type) {
        var jsonTa = wrap.querySelector('.pp-section-content-json');
        if (jsonTa) {
            try { return JSON.parse(jsonTa.value || '{}'); }
            catch (e) { return {}; } // se validará al guardar
        }
        var fieldsWrap = wrap.querySelector('.pp-schema-fields');
        if (fieldsWrap) return collectFields(fieldsWrap);
        return {};
    }

    function parseContent(contentRaw) {
        if (contentRaw == null) return {};
        if (typeof contentRaw === 'object') return contentRaw;
        try { return JSON.parse(contentRaw) || {}; }
        catch (e) { return {}; }
    }

    function typeOptions(selected) {
        var types = Object.keys(SCHEMAS);
        if (types.indexOf('generic') === -1) types.push('generic');
        // Respetar orden lógico
        var preferred = ['hero', 'text_image', 'benefits', 'faq', 'cta', 'form', 'custom_block', 'generic'];
        var ordered = preferred.filter(function (t) { return types.indexOf(t) !== -1; });
        types.forEach(function (t) {
            if (ordered.indexOf(t) === -1) ordered.push(t);
        });
        types = ordered;
        return types.map(function (t) {
            return '<option value="' + t + '"' + (t === selected ? ' selected' : '') + '>' + escapeHtml(typeLabel(t)) + '</option>';
        }).join('');
    }

    // ============================================================
    // Toggle / save / delete
    // ============================================================

    function toggleCardBody(card, forceOpen) {
        var body = card.querySelector('.pp-section-card__body');
        var btn  = card.querySelector('.pp-section-card__toggle');
        var open = forceOpen !== undefined ? forceOpen : body.hasAttribute('hidden');
        if (open) {
            if (!card._bodyBuilt) {
                var section = readSectionFromCard(card);
                buildCardBody(card, section);
                card._bodyBuilt = true;
            }
            body.removeAttribute('hidden');
            card.classList.add('is-expanded');
            if (btn) {
                btn.setAttribute('aria-expanded', 'true');
                btn.setAttribute('aria-label', 'Plegar sección');
            }
        } else {
            body.setAttribute('hidden', '');
            card.classList.remove('is-expanded');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.setAttribute('aria-label', 'Expandir sección');
            }
        }
    }

    function readSectionFromCard(card) {
        // Fuente de verdad: dataset + elementos pre-renderizados si vienen de HTML server-side
        var preType = card.querySelector('[data-field="section_type"]');
        var preStatus = card.querySelector('[data-field="status"]');
        var preContent = card.querySelector('[data-field="content"]');
        var preStyle   = card.querySelector('[data-field="style"]');
        return {
            id: card.dataset.sectionId,
            section_type: (preType && preType.value) || card.dataset.sectionType || 'generic',
            status: (preStatus && preStatus.value) || 'editable',
            content: preContent ? preContent.value : '{}',
            style: preStyle ? preStyle.value : null,
        };
    }

    function buildAiPanel(card, contentWrap) {
        var panel = document.createElement('div');
        panel.className = 'pp-section-ai-panel';
        panel.innerHTML = ''
            + '<div class="pp-section-ai-panel__head">'
            + '  <div>'
            + '    <strong>Asistente IA</strong>'
            + '    <span>Genera contenido estructurado para esta sección. Podrás revisarlo antes de guardar.</span>'
            + '  </div>'
            + '  <button type="button" class="pp-btn pp-btn--primary pp-section-ai-run">Generar con IA</button>'
            + '</div>'
            + '<div class="pp-section-ai-panel__body">'
            + '  <label>Instrucción para la IA</label>'
            + '  <textarea class="pp-section-ai-prompt" rows="3" placeholder="Ej: orienta el texto a pequeñas tiendas que venden online, tono directo y cercano."></textarea>'
            + '  <div class="pp-section-ai-status" aria-live="polite"></div>'
            + '</div>';

        var btn = panel.querySelector('.pp-section-ai-run');
        var prompt = panel.querySelector('.pp-section-ai-prompt');
        var status = panel.querySelector('.pp-section-ai-status');
        btn.addEventListener('click', function () {
            runSectionAI(card, contentWrap, prompt.value, btn, status);
        });
        return panel;
    }

    function showSectionSkeleton(contentWrap) {
        hideSectionSkeleton(contentWrap);
        contentWrap.classList.add('is-ai-loading');
        var sk = document.createElement('div');
        sk.className = 'pp-section-ai-skeleton';
        sk.setAttribute('aria-hidden', 'true');
        sk.innerHTML = ''
            + '<div class="pp-section-ai-skeleton__bar w-lg"></div>'
            + '<div class="pp-section-ai-skeleton__bar w-md"></div>'
            + '<div class="pp-section-ai-skeleton__field"></div>'
            + '<div class="pp-section-ai-skeleton__grid">'
            + '  <div></div><div></div>'
            + '</div>';
        contentWrap.appendChild(sk);
    }

    function hideSectionSkeleton(contentWrap) {
        contentWrap.classList.remove('is-ai-loading');
        var sk = contentWrap.querySelector('.pp-section-ai-skeleton');
        if (sk) sk.remove();
    }

    function renderSectionTimeout(status, retryFn) {
        status.className = 'pp-section-ai-status pp-err pp-section-ai-status--retry';
        status.innerHTML = ''
            + '<strong>La IA está tardando más de lo esperado.</strong>'
            + '<span>No se ha aplicado ningún cambio en esta sección.</span>'
            + '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm">Reintentar</button>';
        var retry = status.querySelector('button');
        retry.addEventListener('click', retryFn);
    }

    function runSectionAI(card, contentWrap, promptText, btn, status) {
        var body = card.querySelector('.pp-section-card__body');
        var typeSel = body.querySelector('[data-meta="section_type"]');
        var type = typeSel ? typeSel.value : (card.dataset.sectionType || 'generic');
        var currentContent = collectCurrentContent(contentWrap, type);
        var schema = SCHEMAS[type];
        if (!schema) {
            status.className = 'pp-section-ai-status pp-err';
            status.textContent = 'Este tipo de sección no tiene schema IA.';
            return;
        }

        setButtonBusy(btn, true, 'Generando');
        status.className = 'pp-section-ai-status is-loading';
        status.innerHTML = '<span></span><span></span><span></span><em>Generando borrador. Nada se guarda hasta que pulses Guardar sección.</em>';
        showSectionSkeleton(contentWrap);

        var input = {
            section_type: type,
            page_title: pageTitle,
            section_id: card.dataset.sectionId,
            current_content: currentContent,
            extra_context: (promptText || '').trim() || 'Genera una versión clara, concreta y útil para esta sección.',
        };

        postForm('/admin/ai/actions/run', {
            action: 'generate_section',
            input_json: JSON.stringify(input),
        }, 30000)
        .then(function (resp) {
            var generated = resp.data || {};
            hideSectionSkeleton(contentWrap);
            card._sectionState = { content: generated, type: type };
            renderContentArea(contentWrap, type, generated, false);
            card._aiDraftApplied = true;
            schedulePreview(card, 0);
            markDirty(card);
            status.className = 'pp-section-ai-status pp-ok';
            status.innerHTML = 'Borrador generado. Revisa los campos y pulsa <strong>Guardar sección</strong>.'
                + '<span class="pp-section-ai-meta">' + escapeHtml(aiMeta(resp)) + '</span>';
            if (resp.warnings && resp.warnings.length) {
                status.innerHTML += '<ul>' + resp.warnings.map(function (w) { return '<li>' + escapeHtml(w) + '</li>'; }).join('') + '</ul>';
            }
            toast('Borrador de sección generado. Revisa y guarda.', 'success');
        })
        .catch(function (err) {
            hideSectionSkeleton(contentWrap);
            if (err && err.isTimeout) {
                renderSectionTimeout(status, function () {
                    runSectionAI(card, contentWrap, promptText, btn, status);
                });
                toast('La generación tardó demasiado. Puedes reintentar.', 'error');
                return;
            }
            status.className = 'pp-section-ai-status pp-err';
            status.textContent = 'No se pudo generar: ' + err.message;
            toast('No se pudo generar la sección.', 'error');
        })
        .finally(function () {
            setButtonBusy(btn, false, 'Generar con IA');
        });
    }

    function saveSection(card) {
        var id = card.dataset.sectionId;
        var body = card.querySelector('.pp-section-card__body');
        var statusEl = body.querySelector('.pp-section-card__status');
        var typeSel = body.querySelector('[data-meta="section_type"]');
        var stSel   = body.querySelector('[data-meta="status"]');
        var styleTa = body.querySelector('.pp-section-style');
        var contentWrap = body.querySelector('.pp-section-content');

        var type = typeSel.value;
        var status = stSel.value;

        var contentObj = collectCurrentContent(contentWrap, type);

        // Validar style JSON
        var styleStr = (styleTa.value || '').trim();
        if (styleStr !== '') {
            try { JSON.parse(styleStr); }
            catch (e) {
                statusEl.textContent = 'Estilo: JSON inválido';
                statusEl.className = 'pp-section-card__status pp-err';
                return;
            }
        }

        var saveBtn = body.querySelector('.pp-section-card__save');
        statusEl.textContent = 'Guardando…';
        statusEl.className = 'pp-section-card__status is-saving';
        if (saveBtn) { saveBtn.disabled = true; saveBtn.dataset.prevText = saveBtn.textContent; saveBtn.textContent = 'Guardando…'; }

        postForm('/admin/sections/' + id, {
            section_type: type,
            content: JSON.stringify(contentObj),
            style: styleStr,
            status: status,
            version_reason: card._aiDraftApplied ? 'before_ai_edit' : 'before_manual_update',
        })
        .then(function (resp) {
            statusEl.innerHTML = ''
                + '<svg class="pp-section-card__status-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
                +   '<path d="M3.5 8.5l3 3 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>'
                + '<span>Guardado</span>';
            statusEl.className = 'pp-section-card__status pp-ok is-just-saved';
            // Sync state
            card._sectionState = { content: contentObj, type: type };
            card._aiDraftApplied = false;
            // Mantener los hidden inputs sincronizados (fuente de verdad si la card se colapsa).
            var preType = card.querySelector('[data-field="section_type"]');
            var preStatus = card.querySelector('[data-field="status"]');
            var preContent = card.querySelector('[data-field="content"]');
            var preStyle = card.querySelector('[data-field="style"]');
            if (preType) preType.value = type;
            if (preStatus) preStatus.value = status;
            if (preContent) preContent.value = JSON.stringify(contentObj);
            if (preStyle) preStyle.value = styleStr;
            // Refrescar la cabecera (título inferido, thumb de variante, pill de estado, tipo).
            refreshCardHeader(card);
            markClean(card);
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveBtn.dataset.prevText || 'Guardar sección';
            }
            setTimeout(function () {
                statusEl.textContent = '';
                statusEl.className = 'pp-section-card__status';
            }, 2200);
        })
        .catch(function (err) {
            statusEl.textContent = 'Error: ' + err.message;
            statusEl.className = 'pp-section-card__status pp-err';
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.textContent = saveBtn.dataset.prevText || 'Guardar sección';
            }
        });
    }

    function deleteSection(card) {
        if (!confirm('¿Eliminar esta sección? No se puede deshacer.')) return;
        var id = card.dataset.sectionId;
        postForm('/admin/sections/' + id + '/delete', {})
            .then(function () {
                card.style.opacity = '0';
                card.style.transform = 'translateX(-20px)';
                setTimeout(function () {
                    card.remove();
                    refreshEmptyState();
                    refreshAllOrders();
                    toast('Sección eliminada');
                }, 200);
            })
            .catch(function (err) { toast('Error: ' + err.message, 'error'); });
    }

    // ============================================================
    // Section versions (T9.2)
    // ============================================================

    var versionsModal = null;
    var versionsList = null;
    var versionsStatus = null;
    var versionsActiveCard = null;

    function ensureVersionsModal() {
        if (versionsModal) return;
        versionsModal = document.createElement('div');
        versionsModal.className = 'pp-modal pp-versions-modal';
        versionsModal.hidden = true;
        versionsModal.setAttribute('aria-hidden', 'true');
        versionsModal.innerHTML = ''
            + '<div class="pp-modal__backdrop" data-close-versions-modal></div>'
            + '<div class="pp-modal__dialog pp-versions-modal__dialog" role="dialog" aria-labelledby="pp-versions-title">'
            + '  <header class="pp-modal__header">'
            + '    <h3 id="pp-versions-title">Historial de sección</h3>'
            + '    <button type="button" class="pp-modal__close" data-close-versions-modal aria-label="Cerrar">×</button>'
            + '  </header>'
            + '  <div class="pp-modal__body">'
            + '    <div class="pp-versions-status"></div>'
            + '    <div class="pp-versions-list"></div>'
            + '  </div>'
            + '  <footer class="pp-modal__footer">'
            + '    <button type="button" class="pp-btn pp-btn--secondary" data-close-versions-modal>Cerrar</button>'
            + '  </footer>'
            + '</div>';
        document.body.appendChild(versionsModal);
        versionsList = versionsModal.querySelector('.pp-versions-list');
        versionsStatus = versionsModal.querySelector('.pp-versions-status');
        versionsModal.querySelectorAll('[data-close-versions-modal]').forEach(function (el) {
            el.addEventListener('click', closeVersionsModal);
        });
        versionsList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-restore-version]');
            if (!btn || !versionsActiveCard) return;
            restoreVersion(versionsActiveCard, btn.dataset.restoreVersion);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && versionsModal && !versionsModal.hidden) closeVersionsModal();
        });
    }

    function openVersions(card) {
        ensureVersionsModal();
        versionsActiveCard = card;
        versionsModal.hidden = false;
        versionsModal.setAttribute('aria-hidden', 'false');
        versionsList.innerHTML = '';
        versionsStatus.textContent = 'Cargando historial…';

        fetch(url('/admin/sections/' + card.dataset.sectionId + '/versions'), { credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            })
            .then(function (body) {
                renderVersions(body.versions || []);
            })
            .catch(function (err) {
                versionsStatus.textContent = 'No se pudo cargar el historial: ' + err.message;
                versionsList.innerHTML = '';
            });
    }

    function closeVersionsModal() {
        if (!versionsModal) return;
        versionsModal.hidden = true;
        versionsModal.setAttribute('aria-hidden', 'true');
        versionsActiveCard = null;
    }

    function renderVersions(items) {
        if (!items.length) {
            versionsStatus.textContent = 'Aún no hay snapshots para esta sección.';
            versionsList.innerHTML = '';
            return;
        }
        versionsStatus.textContent = items.length + (items.length === 1 ? ' snapshot guardado' : ' snapshots guardados');
        versionsList.innerHTML = items.map(function (v) {
            return ''
                + '<div class="pp-version-row">'
                + '  <div class="pp-version-row__main">'
                + '    <strong>' + escapeHtml(v.label || v.reason || 'Snapshot') + '</strong>'
                + '    <span>' + escapeHtml(v.created_at || '') + ' · ' + escapeHtml(v.username || 'Sistema') + '</span>'
                + '  </div>'
                + '  <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-restore-version="' + escapeHtml(v.id) + '">Restaurar</button>'
                + '</div>';
        }).join('');
    }

    function restoreVersion(card, versionId) {
        if (!confirm('¿Restaurar esta versión? Se guardará un snapshot del estado actual antes de restaurar.')) return;
        versionsStatus.textContent = 'Restaurando…';
        postForm('/admin/sections/' + card.dataset.sectionId + '/versions/' + versionId + '/restore', {})
            .then(function () {
                toast('Versión restaurada');
                window.location.reload();
            })
            .catch(function (err) {
                versionsStatus.textContent = 'Error restaurando: ' + err.message;
            });
    }

    // ============================================================
    // Media picker (T8.2)
    // ============================================================

    function ensureMediaModal() {
        if (mediaModal) return;

        mediaModal = document.createElement('div');
        mediaModal.className = 'pp-modal pp-media-modal';
        mediaModal.id = 'pp-media-picker-modal';
        mediaModal.hidden = true;
        mediaModal.setAttribute('aria-hidden', 'true');
        mediaModal.innerHTML = ''
            + '<div class="pp-modal__backdrop" data-close-media-modal></div>'
            + '<div class="pp-modal__dialog pp-media-modal__dialog" role="dialog" aria-labelledby="pp-media-picker-title">'
            + '  <header class="pp-modal__header">'
            + '    <h3 id="pp-media-picker-title">Seleccionar imagen</h3>'
            + '    <button type="button" class="pp-modal__close" data-close-media-modal aria-label="Cerrar">×</button>'
            + '  </header>'
            + '  <nav class="pp-media-picker-tabs" role="tablist">'
            + '    <button type="button" class="pp-media-picker-tab is-active" data-media-tab="library" role="tab" aria-selected="true">Mi galería</button>'
            + '    <button type="button" class="pp-media-picker-tab" data-media-tab="stock" role="tab" aria-selected="false">Imágenes de relleno</button>'
            + '  </nav>'
            + '  <div class="pp-modal__body">'
            + '    <div class="pp-media-picker-toolbar" data-media-pane="library">'
            + '      <input type="search" class="pp-media-picker-search" placeholder="Buscar por nombre o alt">'
            + '      <button type="button" class="pp-btn pp-btn--primary pp-btn--sm pp-media-upload-btn">'
            + '        <span aria-hidden="true">↑</span> Subir imagen'
            + '      </button>'
            + '      <input type="file" class="pp-media-upload-input" accept="image/jpeg,image/png,image/webp,image/gif" hidden>'
            + '    </div>'
            + '    <div class="pp-media-dropzone" data-media-pane="library" hidden>'
            + '      <div class="pp-media-dropzone__inner">'
            + '        <strong>Arrastra una imagen aquí</strong>'
            + '        <span>o pulsa <em>Subir imagen</em>. JPG, PNG, WebP o GIF · máx. 10 MB.</span>'
            + '      </div>'
            + '    </div>'
            + '    <div class="pp-media-picker-toolbar" data-media-pane="stock" hidden>'
            + '      <select class="pp-media-stock-theme">'
            + '        <option value="business">Negocio / corporativo</option>'
            + '        <option value="health">Salud / clínica</option>'
            + '        <option value="tech">Tecnología</option>'
            + '        <option value="food">Comida / restaurante</option>'
            + '        <option value="service">Servicios profesionales</option>'
            + '        <option value="lifestyle">Personas / lifestyle</option>'
            + '        <option value="nature">Naturaleza / abstracto</option>'
            + '        <option value="random">Aleatorio</option>'
            + '      </select>'
            + '      <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm pp-media-stock-shuffle">Otras</button>'
            + '      <span class="pp-media-stock-hint">Placeholders profesionales — perfectos para previsualizar antes de subir tus fotos.</span>'
            + '    </div>'
            + '    <div class="pp-media-picker-status" aria-live="polite"></div>'
            + '    <div class="pp-media-picker-grid"></div>'
            + '  </div>'
            + '  <footer class="pp-modal__footer">'
            + '    <button type="button" class="pp-btn pp-btn--secondary" data-close-media-modal>Cancelar</button>'
            + '  </footer>'
            + '</div>';
        document.body.appendChild(mediaModal);

        mediaGrid = mediaModal.querySelector('.pp-media-picker-grid');
        mediaSearch = mediaModal.querySelector('.pp-media-picker-search');
        mediaStatus = mediaModal.querySelector('.pp-media-picker-status');
        var stockSelect  = mediaModal.querySelector('.pp-media-stock-theme');
        var stockShuffle = mediaModal.querySelector('.pp-media-stock-shuffle');
        var tabs = mediaModal.querySelectorAll('.pp-media-picker-tab');
        var panes = mediaModal.querySelectorAll('[data-media-pane]');
        var stockOffset = 0;

        function activateTab(name) {
            mediaActiveTab = name;
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-media-tab') === name;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panes.forEach(function (p) {
                p.hidden = p.getAttribute('data-media-pane') !== name;
            });
            if (name === 'library') {
                loadMedia(mediaSearch.value || '');
            } else {
                stockOffset = 0;
                renderStockGrid(stockSelect.value, stockOffset);
            }
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                activateTab(t.getAttribute('data-media-tab'));
            });
        });

        mediaModal.querySelectorAll('[data-close-media-modal]').forEach(function (el) {
            el.addEventListener('click', closeMediaPicker);
        });
        mediaSearch.addEventListener('input', function () {
            clearTimeout(mediaSearchTimer);
            mediaSearchTimer = setTimeout(function () {
                loadMedia(mediaSearch.value || '');
            }, 180);
        });
        stockSelect.addEventListener('change', function () {
            stockOffset = 0;
            renderStockGrid(stockSelect.value, stockOffset);
        });
        stockShuffle.addEventListener('click', function () {
            stockOffset += 12;
            renderStockGrid(stockSelect.value, stockOffset);
        });
        mediaGrid.addEventListener('click', function (e) {
            var card = e.target.closest('.pp-media-picker-item');
            if (!card || !activeImageInput) return;
            activeImageInput.value = card.dataset.url || '';
            activeImageInput.dispatchEvent(new Event('input', { bubbles: true }));
            closeMediaPicker();
            toast('Imagen seleccionada');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && mediaModal && !mediaModal.hidden) closeMediaPicker();
        });

        // ---- Upload inline (botón + drag&drop) ----
        var uploadBtn   = mediaModal.querySelector('.pp-media-upload-btn');
        var uploadInput = mediaModal.querySelector('.pp-media-upload-input');
        var dropzone    = mediaModal.querySelector('.pp-media-dropzone');
        var modalBody   = mediaModal.querySelector('.pp-modal__body');

        uploadBtn.addEventListener('click', function () { uploadInput.click(); });
        uploadInput.addEventListener('change', function () {
            if (uploadInput.files && uploadInput.files[0]) uploadMedia(uploadInput.files[0]);
            uploadInput.value = '';
        });

        // Drag & drop sobre el body cuando estamos en "Mi galería"
        var dragCounter = 0;
        function showDropzone() { if (mediaActiveTab === 'library') dropzone.hidden = false; }
        function hideDropzone() { dropzone.hidden = true; }

        modalBody.addEventListener('dragenter', function (e) {
            if (mediaActiveTab !== 'library') return;
            e.preventDefault();
            dragCounter++;
            showDropzone();
            dropzone.classList.add('is-active');
        });
        modalBody.addEventListener('dragover', function (e) {
            if (mediaActiveTab !== 'library') return;
            e.preventDefault();
        });
        modalBody.addEventListener('dragleave', function () {
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                dropzone.classList.remove('is-active');
                if (!mediaLoaded || (mediaGrid.children.length > 0)) hideDropzone();
            }
        });
        modalBody.addEventListener('drop', function (e) {
            if (mediaActiveTab !== 'library') return;
            e.preventDefault();
            dragCounter = 0;
            dropzone.classList.remove('is-active');
            var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) uploadMedia(file);
        });
    }

    function uploadMedia(file) {
        if (!file || !/^image\//.test(file.type)) {
            toast('Tipo de archivo no soportado.', 'error');
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            toast('La imagen supera 10 MB.', 'error');
            return;
        }

        mediaStatus.textContent = 'Subiendo "' + file.name + '"…';
        var status = mediaStatus;
        status.classList.add('is-uploading');

        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('file', file);

        fetch(url('/admin/media'), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                return body;
            });
        })
        .then(function (body) {
            status.classList.remove('is-uploading');
            if (activeImageInput && body.item && body.item.url) {
                activeImageInput.value = body.item.url;
                activeImageInput.dispatchEvent(new Event('input', { bubbles: true }));
                closeMediaPicker();
                toast('Imagen subida y seleccionada');
            } else {
                mediaStatus.textContent = 'Imagen subida.';
                if (mediaActiveTab === 'library') loadMedia(mediaSearch.value || '');
            }
        })
        .catch(function (err) {
            status.classList.remove('is-uploading');
            mediaStatus.textContent = 'Error al subir: ' + err.message;
            toast('No se pudo subir la imagen', 'error');
        });
    }

    function renderStockGrid(theme, offset) {
        ensureMediaModal();
        var seeds = stockSeeds(theme, offset, 12);
        mediaStatus.textContent = '12 imágenes de relleno · Pulsa "Otras" para ver más';
        mediaGrid.innerHTML = seeds.map(function (seed) {
            var thumb = 'https://picsum.photos/seed/' + encodeURIComponent(seed) + '/400/300';
            var full  = 'https://picsum.photos/seed/' + encodeURIComponent(seed) + '/1600/1000';
            return ''
                + '<button type="button" class="pp-media-picker-item pp-media-picker-item--stock" data-url="' + escapeHtml(full) + '">'
                + '  <span class="pp-media-picker-item__thumb"><img src="' + escapeHtml(thumb) + '" alt="" loading="lazy"></span>'
                + '  <span class="pp-media-picker-item__meta">1600 × 1000</span>'
                + '</button>';
        }).join('');
    }

    function stockSeeds(theme, offset, count) {
        var pools = {
            business:  ['office','meeting','workspace','desk','team','startup','laptop','suit','collab','lobby','briefcase','presentation'],
            health:    ['clinic','dental','smile','doctor','medical','wellness','treatment','hospital','dentist','health','care','consult'],
            tech:      ['code','server','circuit','network','data','cloud','dev','ai','minimal','grid','tech','interface'],
            food:      ['plate','dish','table','restaurant','chef','bistro','food','dinner','market','coffee','wine','dessert'],
            service:   ['handshake','consult','advise','plan','agency','strategy','client','meeting','growth','quality','document','signature'],
            lifestyle: ['portrait','urban','street','people','client','smile','candid','daylight','interior','calm','focus','natural'],
            nature:    ['ocean','forest','mountain','calm','minimal','abstract','sky','sunset','plant','geometry','horizon','wave'],
            random:    ['alpha','beta','gamma','delta','sigma','omega','prime','vector','pulse','flux','axis','core'],
        };
        var pool = pools[theme] || pools.random;
        var out = [];
        for (var i = 0; i < count; i++) {
            var idx = (offset + i) % pool.length;
            var bump = Math.floor((offset + i) / pool.length);
            out.push(pool[idx] + (bump > 0 ? '-' + bump : ''));
        }
        return out;
    }

    function openMediaPicker(input) {
        activeImageInput = input;
        ensureMediaModal();
        mediaModal.hidden = false;
        mediaModal.setAttribute('aria-hidden', 'false');
        mediaSearch.focus();
        loadMedia(mediaSearch.value || '');
    }

    function closeMediaPicker() {
        if (!mediaModal) return;
        mediaModal.hidden = true;
        mediaModal.setAttribute('aria-hidden', 'true');
        activeImageInput = null;
    }

    function loadMedia(query) {
        ensureMediaModal();
        mediaStatus.textContent = 'Cargando imágenes…';
        mediaGrid.innerHTML = '';
        var endpoint = '/admin/media/library';
        if (query) endpoint += '?q=' + encodeURIComponent(query);
        fetch(url(endpoint), { credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            })
            .then(function (body) {
                mediaLoaded = true;
                renderMediaItems(body.items || []);
            })
            .catch(function (err) {
                mediaStatus.textContent = 'No se pudo cargar la biblioteca: ' + err.message;
                mediaGrid.innerHTML = '';
            });
    }

    function renderMediaItems(items) {
        var dz = mediaModal && mediaModal.querySelector('.pp-media-dropzone');
        if (!items.length) {
            mediaStatus.textContent = 'Tu galería está vacía.';
            mediaGrid.innerHTML = '';
            if (dz && mediaActiveTab === 'library') dz.hidden = false;
            return;
        }
        if (dz) dz.hidden = true;
        mediaStatus.textContent = items.length + (items.length === 1 ? ' imagen disponible' : ' imágenes disponibles');
        mediaGrid.innerHTML = items.map(function (item) {
            var dims = item.width && item.height ? item.width + '×' + item.height : '—';
            var size = Math.max(1, Math.round((item.file_size || 0) / 1024)) + ' KB';
            return ''
                + '<button type="button" class="pp-media-picker-item" data-url="' + escapeHtml(item.url) + '">'
                + '  <span class="pp-media-picker-item__thumb"><img src="' + escapeHtml(item.url) + '" alt="' + escapeHtml(item.alt_text || '') + '" loading="lazy"></span>'
                + '  <span class="pp-media-picker-item__name" title="' + escapeHtml(item.name || '') + '">' + escapeHtml(item.name || 'Imagen') + '</span>'
                + '  <span class="pp-media-picker-item__meta">' + escapeHtml(dims) + ' · ' + escapeHtml(size) + '</span>'
                + '</button>';
        }).join('');
    }

    // ============================================================
    // Add section (modal)
    // ============================================================
    var modal     = document.getElementById('pp-add-section-modal');
    var addBtn    = document.getElementById('pp-add-section-btn');
    var createBtn = document.getElementById('pp-create-section-btn');
    var typeSel   = document.getElementById('pp-new-section-type');

    function openModal() { if (modal) { modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); typeSel.focus(); } }
    function closeModal() { if (modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); } }

    if (addBtn) addBtn.addEventListener('click', openModal);
    if (layoutVariationsBtn) layoutVariationsBtn.addEventListener('click', requestLayoutVariations);
    if (modal) {
        modal.querySelectorAll('[data-close-modal]').forEach(function (el) { el.addEventListener('click', closeModal); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    if (createBtn) {
        createBtn.addEventListener('click', function () {
            var type = typeSel.value;
            createBtn.disabled = true;
            createBtn.textContent = 'Creando…';
            postForm('/admin/pages/' + pageId + '/sections', { section_type: type })
                .then(function (resp) { closeModal(); appendSection(resp.section); toast('Sección creada'); })
                .catch(function (err) { toast('Error: ' + err.message, 'error'); })
                .finally(function () { createBtn.disabled = false; createBtn.textContent = 'Crear sección'; });
        });
    }

    function appendSection(s) {
        var li = document.createElement('li');
        li.className = 'pp-section-card';
        li.dataset.sectionId = s.id;
        li.dataset.sectionType = s.section_type;
        li.draggable = true;
        var order = list.children.length + 1;
        li.innerHTML = ''
            + '<header class="pp-section-card__header">'
            + cardHeaderHtml({
                id: s.id,
                section_type: s.section_type,
                status: s.status || 'editable',
                content: s.content || '{}',
                style: s.style || null,
            }, order)
            + '</header>'
            + '<div class="pp-section-card__body" hidden></div>';
        // Ocultos para que readSectionFromCard recupere valores iniciales
        var hiddenContainer = document.createElement('div');
        hiddenContainer.style.display = 'none';
        hiddenContainer.innerHTML = ''
            + '<input type="hidden" data-field="section_type" value="' + escapeHtml(s.section_type) + '">'
            + '<input type="hidden" data-field="status" value="' + escapeHtml(s.status || 'editable') + '">'
            + '<textarea data-field="content">' + escapeHtml(s.content || '{}') + '</textarea>'
            + (s.style ? '<textarea data-field="style">' + escapeHtml(s.style) + '</textarea>' : '');
        li.appendChild(hiddenContainer);
        list.appendChild(li);
        refreshEmptyState();
        li.scrollIntoView({ behavior: 'smooth', block: 'center' });
        toggleCardBody(li, true);
    }

    // ============================================================
    // Event delegation
    // ============================================================
    list.addEventListener('click', function (e) {
        var card = e.target.closest('.pp-section-card');
        if (!card) return;

        // Menu de acciones (overflow) en la cabecera.
        var menuBtn = e.target.closest('.pp-section-card__menu-btn');
        if (menuBtn && card.contains(menuBtn)) {
            e.stopPropagation();
            var menuList = menuBtn.parentNode.querySelector('.pp-section-card__menu-list');
            var willOpen = menuList && menuList.hidden;
            closeAllMenus(willOpen ? menuList : null);
            if (menuList) {
                menuList.hidden = !willOpen;
                menuBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            }
            return;
        }

        // Acciones dentro del body (botones existentes).
        if (e.target.closest('.pp-section-card__save')) { saveSection(card); return; }
        if (e.target.closest('.pp-section-card__versions')) { openVersions(card); return; }
        if (e.target.closest('.pp-section-card__cancel')) { toggleCardBody(card, false); return; }
        if (e.target.closest('.pp-section-card__delete')) { closeAllMenus(); deleteSection(card); return; }

        // Toggle: chevron explícito o cualquier zona "neutral" de la cabecera.
        var toggleBtn = e.target.closest('.pp-section-card__toggle');
        var inHeader = e.target.closest('.pp-section-card__header');
        if (toggleBtn || (inHeader && !e.target.closest('.pp-section-card__menu') && !e.target.closest('.pp-drag-handle') && !e.target.closest('button'))) {
            toggleCardBody(card);
            closeAllMenus();
        }
    });

    // Cerrar menús al clickar fuera o pulsar Escape.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pp-section-card__menu')) closeAllMenus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllMenus();
    });

    // ============================================================
    // Drag & drop
    // ============================================================
    var draggedCard = null;

    list.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.pp-section-card');
        if (!card) return;
        // Evitar drag cuando se interactúa con inputs/textareas dentro de la card abierta
        if (e.target.closest('.pp-section-card__body')) { e.preventDefault(); return; }
        draggedCard = card;
        card.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragend', function () {
        if (draggedCard) {
            draggedCard.classList.remove('is-dragging');
            draggedCard = null;
            sendReorder();
        }
    });

    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!draggedCard) return;
        var target = e.target.closest('.pp-section-card');
        if (!target || target === draggedCard) return;
        var rect = target.getBoundingClientRect();
        if ((e.clientY - rect.top) > rect.height / 2) target.after(draggedCard);
        else target.before(draggedCard);
    });

    function sendReorder() {
        var ids = Array.from(list.children).map(function (c) { return c.dataset.sectionId; });
        if (ids.length === 0) return;
        refreshAllOrders();
        postForm('/admin/pages/' + pageId + '/sections/reorder', { order: ids.join(',') })
            .catch(function (err) { toast('Error reordenando: ' + err.message, 'error'); });
    }

    // Upgrade inicial: convertir cabeceras pre-renderizadas por PHP al nuevo chrome (E1.1).
    upgradeAllHeaders();
})();
