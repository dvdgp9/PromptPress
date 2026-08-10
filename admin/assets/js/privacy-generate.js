/**
 * WZ-UX — Progreso real al generar las páginas legales con IA.
 *
 * Mejora progresiva: los formularios siguen funcionando sin JS (POST clásico
 * que genera todo en una sola petición). Con JS interceptamos el submit y
 * generamos página a página contra `/admin/privacy/pages/generate` en modo
 * JSON, pintando una fila por página con su estado.
 *
 * Marcado esperado en el form:
 *   data-legal-generate='[{"key":"privacy_policy","label":"Política de privacidad"}, ...]'
 *   data-generate-url="…/admin/privacy/pages/generate"   (opcional; por defecto action)
 *   data-finish-url="…/admin/privacy/wizard/finish"      (solo wizard)
 *   data-done-url="…"                                    (a dónde ir al terminar)
 */
(function () {
    'use strict';

    var forms = document.querySelectorAll('form[data-legal-generate]');
    if (!forms.length || typeof window.fetch !== 'function') return;

    Array.prototype.forEach.call(forms, function (form) {
        form.addEventListener('submit', function (ev) {
            // Tras un fallo parcial reintentamos solo las que quedaron mal.
            var types = parseTypes(form.dataset.retryTypes || form.getAttribute('data-legal-generate'));
            if (!types.length) return; // sin config → submit normal
            ev.preventDefault();
            run(form, types);
        });
    });

    function parseTypes(raw) {
        try {
            var parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed.filter(function (t) { return t && t.key; }) : [];
        } catch (e) {
            return [];
        }
    }

    function run(form, types) {
        if (form.dataset.busy === '1') return;
        form.dataset.busy = '1';

        var button = form.querySelector('button[type="submit"], button:not([type])');
        var originalLabel = button ? button.innerHTML : '';
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = 'Generando con IA…';
        }
        // Deshabilita el resto de botones de generación mientras dura el proceso.
        toggleOtherButtons(form, true);

        var panel = buildPanel(form, types);
        var csrf = (form.querySelector('input[name="_csrf"]') || {}).value || '';
        var url = form.getAttribute('data-generate-url') || form.getAttribute('action');
        var results = [];

        generateAt(0);

        function generateAt(index) {
            if (index >= types.length) {
                finish();
                return;
            }
            setRow(panel, index, 'active', 'Escribiendo con IA…');
            setSummary(panel, 'Página ' + (index + 1) + ' de ' + types.length + '. Puede tardar unos segundos por página.');
            setBar(panel, index / types.length);

            postJson(url, { _csrf: csrf, type: types[index].key })
                .then(function (body) {
                    var todos = Number(body.todos || 0);
                    setRow(panel, index, 'done', todos > 0 ? 'Lista · ' + todos + ' TODO-LEGAL' : 'Lista');
                    results.push({ ok: true, type: types[index].key, todos: todos });
                    setBar(panel, (index + 1) / types.length);
                    generateAt(index + 1);
                })
                .catch(function (err) {
                    setRow(panel, index, 'error', err.message || 'No se pudo generar');
                    results.push({ ok: false, type: types[index].key, label: types[index].label, error: err.message });
                    setBar(panel, (index + 1) / types.length);
                    generateAt(index + 1);
                });
        }

        function finish() {
            var failed = results.filter(function (r) { return !r.ok; });
            var totalTodos = results.reduce(function (acc, r) { return acc + (r.todos || 0); }, 0);

            if (failed.length) {
                form.dataset.retryTypes = JSON.stringify(failed.map(function (r) {
                    return { key: r.type, label: r.label };
                }));
                release(failed.length === 1 ? 'Reintentar la que falló' : 'Reintentar las ' + failed.length + ' que fallaron');
                setSummary(panel, 'Se generaron ' + (results.length - failed.length) + ' de ' + results.length +
                    ' páginas. Vuelve a pulsar el botón para reintentar las que fallaron.');
                setTitle(panel, failed.length === results.length
                    ? 'No se pudo generar ninguna página'
                    : 'Faltan páginas por generar');
                panel.classList.add('is-error');
                return;
            }
            form.dataset.retryTypes = '';

            var finishUrl = form.getAttribute('data-finish-url');
            var doneUrl = form.getAttribute('data-done-url') || window.location.href;

            setSummary(panel, 'Listo. ' + results.length + ' páginas generadas' +
                (totalTodos > 0 ? ' · ' + totalTodos + ' campos marcados como TODO-LEGAL para que revises.' : '.'));
            setBar(panel, 1);

            if (!finishUrl) {
                if (button) button.innerHTML = 'Abriendo tus páginas…';
                window.location.href = doneUrl;
                return;
            }

            postJson(finishUrl, { _csrf: csrf, finish_only: true })
                .then(function (body) {
                    window.location.href = body.redirect_url || doneUrl;
                })
                .catch(function () {
                    // Las páginas existen; solo falló marcar el wizard como hecho.
                    window.location.href = doneUrl;
                });
        }

        function release(label) {
            form.dataset.busy = '';
            toggleOtherButtons(form, false);
            if (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.innerHTML = label || originalLabel;
            }
        }
    }

    function toggleOtherButtons(currentForm, disabled) {
        var others = document.querySelectorAll('form[data-legal-generate] button[type="submit"]');
        Array.prototype.forEach.call(others, function (btn) {
            if (currentForm.contains(btn)) return;
            btn.disabled = disabled;
        });
    }

    function buildPanel(form, types) {
        // Por defecto el panel va justo debajo del form; con data-progress-target
        // se cuelga al final de un ancestro (p. ej. la tarjeta de la página) para
        // no romper layouts en fila.
        var selector = form.getAttribute('data-progress-target');
        var host = selector && form.closest ? (form.closest(selector) || form.parentNode) : form.parentNode;

        var existing = host.querySelector('[data-legal-progress]');
        if (existing) existing.parentNode.removeChild(existing);

        var panel = document.createElement('div');
        panel.className = 'pp-legalgen';
        panel.setAttribute('data-legal-progress', '');
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');
        panel.innerHTML = '<strong data-legal-title>La IA está redactando tus páginas legales</strong>'
            + '<small data-legal-summary>Preparando la primera página…</small>'
            + '<div class="pp-legalgen__bar"><i data-legal-bar></i></div>'
            + types.map(function (t, i) {
                return '<p data-legal-row="' + i + '" class="is-pending"><span></span><em>'
                    + escapeHtml(t.label || t.key) + '</em><small>En cola</small></p>';
            }).join('');
        if (host === form.parentNode) {
            host.insertBefore(panel, form.nextSibling);
        } else {
            host.appendChild(panel);
        }
        if (panel.scrollIntoView) panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return panel;
    }

    function setRow(panel, index, state, message) {
        var row = panel.querySelector('[data-legal-row="' + index + '"]');
        if (!row) return;
        row.className = state === 'active' ? 'is-active' : (state === 'error' ? 'is-error' : 'is-done');
        var status = row.querySelector('small');
        if (status) status.textContent = message || '';
    }

    function setTitle(panel, text) {
        var el = panel.querySelector('[data-legal-title]');
        if (el) el.textContent = text;
    }

    function setSummary(panel, text) {
        var el = panel.querySelector('[data-legal-summary]');
        if (el) el.textContent = text;
    }

    function setBar(panel, ratio) {
        var bar = panel.querySelector('[data-legal-bar]');
        if (bar) bar.style.width = Math.round(Math.max(0, Math.min(1, ratio)) * 100) + '%';
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.text().then(function (text) {
                var body = {};
                try { body = text ? JSON.parse(text) : {}; } catch (e) { body = {}; }
                if (!res.ok || body.ok === false) {
                    throw new Error(body.error || ('Error ' + res.status));
                }
                return body;
            });
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
