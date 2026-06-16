/**
 * Auto-slug desde el título mientras el usuario escribe.
 * Solo se autogenera si el usuario NO ha editado el slug manualmente.
 */
(function () {
    'use strict';

    var form = document.getElementById('pp-page-form');
    var title = document.getElementById('title');
    var slug  = document.getElementById('slug');
    if (!title || !slug || !form) return;

    // Si el slug ya tiene valor (edit mode), marcar como touched
    var touched = slug.value.trim() !== '';

    slug.addEventListener('input', function () {
        touched = slug.value.trim() !== '';
    });

    slug.addEventListener('blur', function () {
        // Si el usuario borra el slug, permitimos auto-gen de nuevo
        if (slug.value.trim() === '') touched = false;
    });

    title.addEventListener('input', function () {
        if (touched) return;
        slug.value = slugify(title.value);
    });

    function slugify(text) {
        return (text || '')
            .toString()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '') // strip diacritics
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    // Contadores de caracteres para SEO
    ['meta_title', 'meta_description'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var counter = document.createElement('small');
        counter.className = 'pp-char-count';
        el.parentNode.insertBefore(counter, el.nextSibling);
        var update = function () {
            counter.textContent = el.value.length + ' / ' + el.maxLength;
        };
        el.addEventListener('input', update);
        update();
    });

    initSeoAssistant();
    initAiPageCreator();

    function initSeoAssistant() {
        var button = document.getElementById('pp-ai-seo-btn');
        var panel = document.getElementById('pp-ai-seo-panel');
        var metaTitle = document.getElementById('meta_title');
        var metaDescription = document.getElementById('meta_description');
        var pageType = document.getElementById('page_type');
        if (!button || !panel || !metaTitle || !metaDescription || !pageType) return;

        var baseUrl = (form.dataset.baseUrl || '').replace(/\/$/, '');
        var csrf = form.dataset.csrf || (form.querySelector('input[name="_csrf"]') || {}).value || '';

        button.addEventListener('click', function () {
            var pageTitle = title.value.trim();
            if (!pageTitle) {
                renderPanel('error', 'Añade primero un título de página para que la IA tenga una base clara.');
                title.focus();
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Generando...';
            renderPanel('loading', 'Analizando contenido y preparando propuesta SEO. Si el modelo tarda demasiado, te avisaremos aquí.');

            postForm('/admin/ai/actions/run', {
                action: 'improve_seo',
                input_json: JSON.stringify({
                    page_title: pageTitle,
                    page_type: selectedText(pageType),
                    current_slug: slug.value.trim(),
                    current_meta_title: metaTitle.value.trim(),
                    current_meta_description: metaDescription.value.trim(),
                    page_content: collectPageContent(pageTitle, pageType, metaTitle, metaDescription)
                })
            }).then(function (body) {
                renderSuggestion(body);
            }).catch(function (err) {
                renderPanel('error', err.message || 'No se pudo generar la propuesta SEO.');
            }).finally(function () {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.textContent = 'Mejorar con IA';
            });
        });

        function postForm(path, data) {
            var params = new URLSearchParams();
            params.set('_csrf', csrf);
            Object.keys(data).forEach(function (key) { params.set(key, data[key]); });
            return fetchWithTimeout(baseUrl + path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin',
            }, 60000).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) {
                        throw new Error(body.error || ('HTTP ' + res.status));
                    }
                    return body;
                });
            });
        }

        function renderPanel(type, message) {
            panel.hidden = false;
            panel.className = 'pp-ai-seo-panel pp-ai-seo-panel--' + type;
            panel.innerHTML = '<div class="pp-ai-seo-panel__status">' + escapeHtml(message) + '</div>';
        }

        function renderSuggestion(body) {
            var data = body.data || {};
            var suggestion = {
                meta_title: String(data.seo_title || '').trim(),
                meta_description: String(data.meta_description || '').trim(),
                slug: String(data.slug || '').trim()
            };
            if (!suggestion.meta_title || !suggestion.meta_description || !suggestion.slug) {
                renderPanel('error', 'La IA respondió, pero faltan campos SEO en la propuesta.');
                return;
            }

            panel.hidden = false;
            panel.className = 'pp-ai-seo-panel pp-ai-seo-panel--ready';
            panel.innerHTML = [
                '<div class="pp-ai-seo-panel__head">',
                    '<div>',
                        '<strong>Propuesta SEO lista</strong>',
                        '<span>' + escapeHtml(formatAiMeta(body)) + '</span>',
                    '</div>',
                    '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-ai-seo-apply="all">Aplicar todo</button>',
                '</div>',
                warningHtml(body.warnings || []),
                suggestionRow('Meta título', suggestion.meta_title, 'meta_title', metaTitle.value.length + ' actual'),
                suggestionRow('Meta descripción', suggestion.meta_description, 'meta_description', metaDescription.value.length + ' actual'),
                suggestionRow('Slug', suggestion.slug, 'slug', slug.value ? '/' + slug.value : 'sin slug'),
            ].join('');

            panel.querySelectorAll('[data-ai-seo-apply]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    applySuggestion(btn.getAttribute('data-ai-seo-apply'), suggestion);
                });
            });
        }

        function warningHtml(warnings) {
            if (!warnings.length) return '';
            return '<div class="pp-ai-seo-warnings">' + warnings.map(function (w) {
                return '<span>' + escapeHtml(w) + '</span>';
            }).join('') + '</div>';
        }

        function suggestionRow(label, value, key, current) {
            return [
                '<div class="pp-ai-seo-suggestion">',
                    '<div>',
                        '<span>' + escapeHtml(label) + '</span>',
                        '<strong>' + escapeHtml(value) + '</strong>',
                        '<small>' + escapeHtml(current) + '</small>',
                    '</div>',
                    '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-ai-seo-apply="' + escapeHtml(key) + '">Aplicar</button>',
                '</div>'
            ].join('');
        }

        function applySuggestion(target, suggestion) {
            if (target === 'all' || target === 'meta_title') {
                metaTitle.value = suggestion.meta_title;
                metaTitle.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (target === 'all' || target === 'meta_description') {
                metaDescription.value = suggestion.meta_description;
                metaDescription.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (target === 'all' || target === 'slug') {
                slug.value = suggestion.slug;
                slug.dispatchEvent(new Event('input', { bubbles: true }));
                touched = true;
            }
        }
    }

    function initAiPageCreator() {
        var button = document.getElementById('pp-ai-create-page-btn');
        var status = document.getElementById('pp-ai-create-page-status');
        var goal = document.getElementById('ai_page_goal');
        var audience = document.getElementById('ai_target_audience');
        var details = document.getElementById('ai_extra_context');
        var pageType = document.getElementById('page_type');
        if (!button || !status || !goal || !audience || !details || !pageType) return;

        // Selector de modelo (opcional): muestra el campo libre al elegir "Otro".
        var modelChoice = document.getElementById('ai_model_choice');
        var modelCustom = document.getElementById('ai_model_custom');
        if (modelChoice && modelCustom) {
            modelChoice.addEventListener('change', function () {
                var isCustom = modelChoice.value === '__custom__';
                modelCustom.hidden = !isCustom;
                if (isCustom) modelCustom.focus();
            });
        }
        function resolveChosenModel() {
            if (!modelChoice) return '';
            if (modelChoice.value === '') return '';                 // principal (por defecto)
            if (modelChoice.value === '__custom__') return modelCustom ? modelCustom.value.trim() : '';
            return modelChoice.value;                                 // auxiliar u otro listado
        }

        var baseUrl = (form.dataset.baseUrl || '').replace(/\/$/, '');
        var csrf = form.dataset.csrf || (form.querySelector('input[name="_csrf"]') || {}).value || '';

        button.addEventListener('click', function () {
            var pageTitle = title.value.trim();
            var pageGoal = goal.value.trim();
            if (!pageTitle) {
                showCreateStatus('error', 'Añade primero el título de la página.');
                title.focus();
                return;
            }
            if (!pageGoal) {
                showCreateStatus('error', 'Describe el objetivo de la página para generar un borrador útil.');
                goal.focus();
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Generando...';
            showCreateStatus('loading', 'Generando estructura, contenido inicial y SEO. Estamos creando varias secciones; puede tardar hasta un par de minutos.');

            var params = new URLSearchParams();
            params.set('_csrf', csrf);
            params.set('title', pageTitle);
            params.set('page_type', pageType.value);
            params.set('ai_page_goal', pageGoal);
            params.set('ai_target_audience', audience.value.trim());
            params.set('ai_extra_context', details.value.trim());
            params.set('ai_model', resolveChosenModel());

            fetchWithTimeout(baseUrl + '/admin/pages/ai-create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin',
            }, 180000).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                showCreateStatus('success', 'Borrador creado con ' + body.sections_count + ' secciones. ' + formatUsageSummary(body.ai_usage) + ' Abriendo editor...');
                window.location.href = body.edit_url;
            }).catch(function (err) {
                showCreateStatus('error', err.message || 'No se pudo crear la página con IA.');
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.textContent = 'Generar borrador';
            });
        });

        function showCreateStatus(type, message) {
            status.hidden = false;
            status.className = 'pp-ai-page-builder__status pp-ai-page-builder__status--' + type;
            status.textContent = message;
        }
    }

    function collectPageContent(pageTitle, pageType, metaTitle, metaDescription) {
        var chunks = [
            'Titulo: ' + pageTitle,
            'Tipo: ' + selectedText(pageType),
            'Meta titulo actual: ' + (metaTitle.value.trim() || '(vacio)'),
            'Meta descripcion actual: ' + (metaDescription.value.trim() || '(vacia)')
        ];

        document.querySelectorAll('[data-field="content"]').forEach(function (el) {
            var raw = (el.value || '').trim();
            if (!raw) return;
            try {
                chunks.push(extractText(JSON.parse(raw)));
            } catch (e) {
                chunks.push(raw);
            }
        });

        return chunks.join('\n').replace(/\n{3,}/g, '\n\n').slice(0, 6000);
    }

    function extractText(value) {
        var out = [];
        walk(value);
        return out.join('\n');

        function walk(v) {
            if (v == null) return;
            if (typeof v === 'string') {
                var trimmed = v.trim();
                if (trimmed) out.push(trimmed);
                return;
            }
            if (Array.isArray(v)) {
                v.forEach(walk);
                return;
            }
            if (typeof v === 'object') {
                Object.keys(v).forEach(function (key) { walk(v[key]); });
            }
        }
    }

    function selectedText(select) {
        return select.options[select.selectedIndex] ? select.options[select.selectedIndex].textContent.trim() : select.value;
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        options = options || {};
        options.signal = controller.signal;
        return fetch(url, options).catch(function (err) {
            if (err && err.name === 'AbortError') {
                throw new Error('La llamada IA ha tardado demasiado. No se ha aplicado ningún cambio; prueba de nuevo o usa un modelo más rápido.');
            }
            throw err;
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    function formatAiMeta(body) {
        var provider = body.provider || 'IA';
        var model = body.model || '';
        var tokens = Number(body.tokens_in || 0) + ' -> ' + Number(body.tokens_out || 0) + ' tokens';
        var cost = typeof body.estimated_cost === 'number' ? ' · $' + body.estimated_cost.toFixed(6) : '';
        return provider + (model ? ' · ' + model : '') + ' · ' + tokens + cost;
    }

    function formatUsageSummary(usage) {
        if (!usage) return '';
        var calls = Number(usage.calls || 0);
        var tokens = Number(usage.tokens_in || 0) + Number(usage.tokens_out || 0);
        var cost = typeof usage.estimated_cost === 'number' ? ' · $' + usage.estimated_cost.toFixed(6) : '';
        return calls + ' llamadas IA · ' + tokens + ' tokens' + cost + '.';
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
