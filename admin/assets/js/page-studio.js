(function () {
    'use strict';

    var root = document.getElementById('pp-page-studio');
    if (!root) return;

    var baseUrl = (root.dataset.baseUrl || '').replace(/\/$/, '');
    var csrf = root.dataset.csrf || '';
    var configured = root.dataset.aiConfigured === '1';
    var opportunitiesWrap = document.getElementById('pp-studio-opportunities');
    var ideaInput = document.getElementById('pp-studio-idea');
    var notesInput = document.getElementById('pp-studio-notes');
    var refreshBtn = document.getElementById('pp-studio-refresh');
    var briefBtn = document.getElementById('pp-studio-brief-btn');
    var briefWrap = document.getElementById('pp-studio-brief');
    var currentBrief = null;
    var currentIdea = '';
    var templateGrid = document.getElementById('pp-studio-template-grid');
    var templateForm = document.getElementById('pp-studio-template-form');
    var templateSlug = document.getElementById('pp-studio-template-slug');
    var visualStyle = document.getElementById('pp-studio-visual-style');
    var templateTitle = document.getElementById('pp-studio-template-title');
    var templateGoal = document.getElementById('pp-studio-template-goal');
    var templateStatus = document.getElementById('pp-studio-template-status');
    var templateSubmit = document.getElementById('pp-studio-template-submit');

    if (!configured) {
        renderOpportunitiesError('Configura el proveedor de IA para detectar oportunidades.');
        return;
    }

    refreshBtn && refreshBtn.addEventListener('click', function () {
        loadOpportunities(true);
    });

    briefBtn && briefBtn.addEventListener('click', function () {
        var idea = (ideaInput.value || '').trim();
        if (!idea) {
            setComposerError('Elige una oportunidad o describe qué página quieres crear.');
            ideaInput.focus();
            return;
        }
        requestBrief(idea);
    });

    root.querySelectorAll('[data-studio-back]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            showPanel(btn.getAttribute('data-studio-back') || 'opportunities');
        });
    });
    bindStudioModes();
    bindTemplateFlow();
    bindReferenceFlow();

    loadOpportunities(false);

    function loadOpportunities(force) {
        if (!opportunitiesWrap) return;
        opportunitiesWrap.innerHTML = skeletonHtml();
        setButtonBusy(refreshBtn, true, force ? 'Actualizando' : null);

        postForm('/admin/pages/ai-opportunities', {
            notes: (notesInput && notesInput.value || '').trim(),
            force: force ? '1' : ''
        }, 45000).then(function (body) {
            renderOpportunities(body);
        }).catch(function (err) {
            renderOpportunitiesError(err.message || 'No se pudieron cargar las oportunidades.');
        }).finally(function () {
            setButtonBusy(refreshBtn, false, 'Actualizar sugerencias');
        });
    }

    function renderOpportunities(body) {
        var data = body.data || {};
        var items = Array.isArray(data.opportunities) ? data.opportunities : [];
        if (!items.length) {
            renderOpportunitiesError('No he detectado oportunidades claras. Describe la página que quieres crear y preparo el plan.');
            return;
        }

        var html = '';
        if (data.site_summary) {
            html += '<p class="pp-studio-summary">' + escapeHtml(data.site_summary) + '</p>';
        }
        if (body.cached) {
            html += '<p class="pp-studio-note">Análisis guardado' + (body.cached_at ? ' · ' + escapeHtml(formatDate(body.cached_at)) : '') + '</p>';
        } else if (body.fallback && body.error_note) {
            html += '<p class="pp-studio-note">Sugerencias locales: ' + escapeHtml(body.error_note) + '</p>';
        } else {
            html += '<p class="pp-studio-note">' + escapeHtml(formatAiMeta(body)) + '</p>';
        }
        html += '<div class="pp-studio-opportunity-grid">';
        items.forEach(function (item, index) {
            var priority = item.priority || 'medium';
            var typeIcon = pageTypeIcon(item.page_type);
            html += [
                '<button type="button" class="pp-studio-opportunity pp-studio-opportunity--' + escapeHtml(priority) + '" data-opp-index="' + index + '" style="--pp-stagger:' + index + '">',
                    '<div class="pp-studio-opportunity__top">',
                        '<span class="pp-studio-priority pp-studio-priority--' + escapeHtml(priority) + '">' + escapeHtml(priorityLabel(priority)) + '</span>',
                        '<span class="pp-studio-opportunity__type">',
                            '<span class="pp-studio-opportunity__type-icon">' + typeIcon + '</span>',
                            escapeHtml(typeLabel(item.page_type)),
                        '</span>',
                    '</div>',
                    '<h4>' + escapeHtml(item.title || '') + '</h4>',
                    '<p>' + escapeHtml(item.reason || item.goal || '') + '</p>',
                    '<span class="pp-studio-opportunity__cta">',
                        'Usar esta idea',
                        '<span class="pp-studio-opportunity__cta-arrow" aria-hidden="true">→</span>',
                    '</span>',
                '</button>'
            ].join('');
        });
        html += '</div>';
        opportunitiesWrap.innerHTML = html;

        opportunitiesWrap.querySelectorAll('[data-opp-index]').forEach(function (card) {
            card.addEventListener('click', function () {
                var item = items[Number(card.getAttribute('data-opp-index'))] || {};
                currentIdea = opportunityToIdea(item);
                ideaInput.value = currentIdea;
                requestBrief(currentIdea);
            });
        });
    }

    function requestBrief(idea) {
        setComposerError('');
        currentIdea = idea;
        setButtonBusy(briefBtn, true, 'Preparando');
        showPanel('brief');
        briefWrap.innerHTML = skeletonHtml();

        postForm('/admin/pages/ai-brief', {
            page_idea: idea,
            notes: (notesInput && notesInput.value || '').trim()
        }, 60000).then(function (body) {
            currentBrief = body.data || {};
            renderBrief(currentBrief, body);
        }).catch(function (err) {
            briefWrap.innerHTML = '<div class="pp-alert pp-alert--error">' + escapeHtml(err.message || 'No se pudo preparar el plan.') + '</div>';
        }).finally(function () {
            setButtonBusy(briefBtn, false, 'Preparar plan');
        });
    }

    function renderBrief(brief, body) {
        var sections = Array.isArray(brief.sections) ? brief.sections : [];
        var form = brief.recommended_form || {};
        var questions = Array.isArray(brief.questions) ? brief.questions : [];

        briefWrap.innerHTML = [
            '<div class="pp-studio-brief__title">',
                '<div>',
                    '<span>' + escapeHtml(typeLabel(brief.page_type)) + '</span>',
                    '<h4>' + escapeHtml(brief.title || 'Nueva página') + '</h4>',
                '</div>',
                '<small>' + escapeHtml(formatAiMeta(body)) + '</small>',
            '</div>',
            '<div class="pp-studio-brief__grid">',
                metricBlock('Objetivo', brief.goal || ''),
                metricBlock('Público', brief.audience || 'Inferido desde el sitio'),
                metricBlock('SEO', brief.seo_intent || 'Optimizado según contexto'),
                metricBlock('CTA', brief.primary_cta || 'Contacto'),
            '</div>',
            formBlock(form),
            questionsBlock(questions),
            '<div class="pp-studio-sections">',
                '<h5>Estructura propuesta</h5>',
                sections.map(sectionRow).join(''),
            '</div>',
            '<div class="pp-studio-brief__actions">',
                '<button type="button" class="pp-btn pp-btn--secondary" data-studio-back="opportunities">Ajustar idea</button>',
                '<button type="button" class="pp-btn pp-btn--primary" id="pp-studio-generate-btn">Crear página completa</button>',
            '</div>'
        ].join('');

        briefWrap.querySelectorAll('[data-studio-back]').forEach(function (btn) {
            btn.addEventListener('click', function () { showPanel('opportunities'); });
        });
        var generateBtn = document.getElementById('pp-studio-generate-btn');
        generateBtn && generateBtn.addEventListener('click', function () {
            generatePage(generateBtn);
        });
    }

    function generatePage(button) {
        if (!currentBrief) return;
        showPanel('generate');
        setButtonBusy(button, true, 'Creando');
        startProgressPulse();

        postForm('/admin/pages/ai-create', {
            title: currentBrief.title || 'Nueva página',
            page_type: currentBrief.page_type || 'landing',
            ai_page_goal: currentBrief.goal || currentIdea,
            ai_target_audience: currentBrief.audience || '',
            ai_extra_context: [
                currentBrief.tone ? 'Tono: ' + currentBrief.tone : '',
                currentBrief.seo_intent ? 'Intencion SEO: ' + currentBrief.seo_intent : '',
                currentBrief.primary_cta ? 'CTA principal: ' + currentBrief.primary_cta : '',
                currentBrief.extra_context || ''
            ].filter(Boolean).join('\n'),
            ai_brief_json: JSON.stringify(currentBrief)
        }, 180000).then(function (body) {
            window.location.href = body.edit_url;
        }).catch(function (err) {
            showPanel('brief');
            briefWrap.insertAdjacentHTML('afterbegin', '<div class="pp-alert pp-alert--error">' + escapeHtml(err.message || 'No se pudo crear la página.') + '</div>');
        }).finally(function () {
            setButtonBusy(button, false, 'Crear página completa');
        });
    }

    function startProgressPulse() {
        var items = root.querySelectorAll('.pp-studio-progress li');
        var index = 0;
        items.forEach(function (item, i) { item.classList.toggle('is-active', i === 0); });
        var timer = setInterval(function () {
            if (!root.querySelector('[data-studio-panel="generate"]:not([hidden])')) {
                clearInterval(timer);
                return;
            }
            index = Math.min(index + 1, items.length - 1);
            items.forEach(function (item, i) {
                item.classList.toggle('is-active', i === index);
                item.classList.toggle('is-done', i < index);
            });
            if (index === items.length - 1) clearInterval(timer);
        }, 4500);
    }

    function showPanel(name) {
        root.querySelectorAll('[data-studio-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-studio-panel') !== name;
        });
        root.querySelectorAll('[data-step-indicator]').forEach(function (step) {
            var stepName = step.getAttribute('data-step-indicator');
            step.classList.toggle('is-active', stepName === name);
            step.classList.toggle('is-done', stepOrder(stepName) < stepOrder(name));
        });
    }

    function postForm(path, data, timeoutMs) {
        var params = new URLSearchParams();
        params.set('_csrf', csrf);
        Object.keys(data).forEach(function (key) { params.set(key, data[key]); });
        return fetchWithTimeout(baseUrl + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
            credentials: 'same-origin'
        }, timeoutMs).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                return body;
            });
        });
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        options = options || {};
        options.signal = controller.signal;
        return fetch(url, options).catch(function (err) {
            if (err && err.name === 'AbortError') {
                throw new Error('La IA ha tardado demasiado. Puedes reintentar o usar un modelo más rápido.');
            }
            throw err;
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    function setButtonBusy(button, busy, label) {
        if (!button) return;
        if (label !== null && label !== undefined) button.textContent = label;
        button.disabled = !!busy;
        button.classList.toggle('is-busy', !!busy);
        if (busy) button.setAttribute('aria-busy', 'true');
        else button.removeAttribute('aria-busy');
    }

    function setComposerError(message) {
        var old = root.querySelector('.pp-studio-compose__error');
        if (old) old.remove();
        if (!message) return;
        var div = document.createElement('div');
        div.className = 'pp-studio-compose__error';
        div.textContent = message;
        briefBtn.parentNode.insertBefore(div, briefBtn);
    }

    function renderOpportunitiesError(message) {
        opportunitiesWrap.innerHTML = '<div class="pp-empty pp-empty--inline"><div class="pp-empty__title">' + escapeHtml(message) + '</div><div class="pp-empty__text">Puedes continuar escribiendo una idea propia en el cuadro inferior.</div></div>';
    }

    function bindStudioModes() {
        root.querySelectorAll('[data-studio-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-studio-mode') || 'idea';
                root.querySelectorAll('[data-studio-mode]').forEach(function (b) {
                    var active = b === btn;
                    b.classList.toggle('is-active', active);
                    b.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                root.querySelectorAll('[data-studio-mode-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-studio-mode-panel') !== mode;
                });
            });
        });
    }

    function bindTemplateFlow() {
        if (!templateGrid || !templateForm || !templateSlug) return;
        templateGrid.querySelectorAll('[data-template-slug]').forEach(function (card) {
            card.addEventListener('click', function () {
                templateGrid.querySelectorAll('.pp-studio-template-card').forEach(function (c) {
                    c.classList.toggle('is-active', c === card);
                });
                templateSlug.value = card.getAttribute('data-template-slug') || '';
                if (visualStyle) {
                    visualStyle.value = card.getAttribute('data-visual-style') || '';
                }
                if (templateTitle && !templateTitle.value.trim()) {
                    templateTitle.value = 'Nueva página';
                }
                if (templateGoal && !templateGoal.value.trim()) {
                    templateGoal.value = 'Crear una página completa orientada a conversión usando la dirección visual ' + (card.getAttribute('data-template-label') || 'seleccionada') + '.';
                }
            });
        });

        templateForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!templateTitle || !templateGoal || !templateTitle.value.trim() || !templateGoal.value.trim()) return;
            setButtonBusy(templateSubmit, true, 'Creando');
            if (templateStatus) templateStatus.textContent = 'Generando página con IA…';
            var fd = new FormData(templateForm);
            fetch(baseUrl + '/admin/pages/ai-create-from-template', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                if (templateStatus) templateStatus.textContent = body.image_warning || 'Página creada. Redirigiendo al editor…';
                window.location.href = body.edit_url;
            }).catch(function (err) {
                if (templateStatus) templateStatus.textContent = err.message || 'No se pudo crear la página.';
                setButtonBusy(templateSubmit, false, 'Crear con este estilo');
            });
        });
    }

    function metricBlock(label, value) {
        return '<div><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
    }

    function formBlock(form) {
        if (!form || !form.needed) {
            return '<div class="pp-studio-form-plan"><strong>Formulario</strong><span>No parece necesario para esta página.</span></div>';
        }
        var fields = Array.isArray(form.fields) ? form.fields : [];
        return [
            '<div class="pp-studio-form-plan">',
                '<strong>Formulario automático</strong>',
                '<span>' + escapeHtml(form.purpose || 'Captar mensajes desde la página.') + '</span>',
                '<div>',
                    fields.map(function (f) {
                        return '<em>' + escapeHtml(f.label || '') + (f.required ? ' *' : '') + '</em>';
                    }).join(''),
                '</div>',
            '</div>'
        ].join('');
    }

    function questionsBlock(questions) {
        if (!questions.length) return '';
        return '<div class="pp-studio-questions"><strong>Decisiones pendientes</strong>' + questions.map(function (q) {
            return '<span>' + escapeHtml(q) + '</span>';
        }).join('') + '</div>';
    }

    function sectionRow(section, index) {
        return [
            '<article>',
                '<span>' + (index + 1) + '</span>',
                '<div>',
                    '<strong>' + escapeHtml(section.heading || typeLabel(section.type)) + '</strong>',
                    '<small>' + escapeHtml(typeLabel(section.type)) + '</small>',
                    '<p>' + escapeHtml(section.purpose || '') + '</p>',
                '</div>',
            '</article>'
        ].join('');
    }

    function opportunityToIdea(item) {
        return [
            item.title || '',
            item.goal ? 'Objetivo: ' + item.goal : '',
            item.audience ? 'Publico: ' + item.audience : '',
            item.details ? 'Detalles: ' + item.details : ''
        ].filter(Boolean).join('\n');
    }

    function skeletonHtml() {
        return '<div class="pp-studio-skeleton"><span></span><span></span><span></span></div>';
    }

    function stepOrder(name) {
        return { opportunities: 1, brief: 2, generate: 3 }[name] || 0;
    }

    function typeLabel(type) {
        return {
            home: 'Inicio',
            service: 'Servicio',
            product: 'Producto',
            landing: 'Landing',
            article: 'Artículo',
            contact: 'Contacto',
            hero: 'Hero',
            text_image: 'Texto + imagen',
            benefits: 'Beneficios',
            faq: 'FAQ',
            cta: 'CTA',
            form: 'Formulario'
        }[type] || type || 'Página';
    }

    function priorityLabel(priority) {
        return { high: 'Prioritaria', medium: 'Recomendada', low: 'Opcional' }[priority] || 'Recomendada';
    }

    function pageTypeIcon(type) {
        var icons = {
            home:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 9-8 9 8v9a2 2 0 0 1-2 2h-4v-7h-6v7H5a2 2 0 0 1-2-2z"/></svg>',
            service: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
            product: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></svg>',
            landing: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/></svg>',
            article: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/></svg>',
            contact: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 6 10 7L22 6"/></svg>'
        };
        return icons[type] || icons.service;
    }

    function formatAiMeta(body) {
        if (!body || !body.model) return 'Listo para revisar';
        var tokens = Number(body.tokens_in || 0) + ' -> ' + Number(body.tokens_out || 0) + ' tokens';
        var cost = typeof body.estimated_cost === 'number' ? ' · $' + body.estimated_cost.toFixed(6) : '';
        return body.model + ' · ' + tokens + cost;
    }

    function formatDate(value) {
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function bindReferenceFlow() {
        var form = document.getElementById('pp-studio-reference-form');
        if (!form) return;
        var dz       = document.getElementById('pp-reference-dropzone');
        var input    = document.getElementById('pp-reference-input');
        var empty    = document.getElementById('pp-reference-empty');
        var previews = document.getElementById('pp-reference-previews');
        var titleEl  = document.getElementById('pp-reference-title');
        var goalEl   = document.getElementById('pp-reference-goal');
        var audEl    = form.querySelector('[name="ai_target_audience"]');
        var detEl    = form.querySelector('[name="ai_extra_context"]');
        var submit   = document.getElementById('pp-reference-submit');
        var status   = document.getElementById('pp-reference-status');
        var progress = document.getElementById('pp-reference-progress');

        var MAX = 4, MAX_BYTES = 8 * 1024 * 1024;
        var OK_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
        var files = [];

        function setStatus(msg, kind) {
            status.textContent = msg || '';
            status.className = 'pp-studio-status' + (kind ? ' pp-studio-status--' + kind : '');
        }

        function updateSubmit() {
            submit.disabled = !(files.length > 0 && titleEl.value.trim() && goalEl.value.trim());
        }

        function renderPreviews() {
            if (files.length === 0) {
                previews.hidden = true; previews.innerHTML = '';
                empty.hidden = false;
                return;
            }
            empty.hidden = true; previews.hidden = false;
            previews.innerHTML = '';
            files.forEach(function (f, i) {
                var item = document.createElement('div');
                item.className = 'pp-dropzone__preview';
                var url = URL.createObjectURL(f);
                item.innerHTML =
                    '<img src="' + url + '" alt="">' +
                    '<button type="button" class="pp-dropzone__remove" aria-label="Quitar" data-i="' + i + '">×</button>';
                item.querySelector('img').addEventListener('load', function () { URL.revokeObjectURL(url); });
                item.querySelector('.pp-dropzone__remove').addEventListener('click', function (e) {
                    e.stopPropagation();
                    files.splice(i, 1);
                    renderPreviews(); updateSubmit();
                });
                previews.appendChild(item);
            });
        }

        function addFiles(fileList) {
            var arr = Array.prototype.slice.call(fileList || []);
            for (var k = 0; k < arr.length; k++) {
                var f = arr[k];
                if (OK_TYPES.indexOf(f.type) === -1) { setStatus('«' + f.name + '» no es PNG, JPG ni WebP.', 'err'); continue; }
                if (f.size > MAX_BYTES) { setStatus('«' + f.name + '» supera los 8 MB.', 'err'); continue; }
                if (files.length >= MAX) { setStatus('Máximo ' + MAX + ' imágenes.', 'err'); break; }
                files.push(f);
                setStatus('');
            }
            renderPreviews(); updateSubmit();
        }

        // Dropzone: clic, teclado y drag&drop.
        dz.addEventListener('click', function (e) { if (e.target.closest('.pp-dropzone__remove')) return; input.click(); });
        dz.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
        input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
        ['dragenter', 'dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-dragover'); });
        });
        dz.addEventListener('drop', function (e) { if (e.dataTransfer) addFiles(e.dataTransfer.files); });

        // Conmutador del origen de contenido (escribir / desde un documento).
        form.querySelectorAll('[data-ref-source]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var src = tab.getAttribute('data-ref-source');
                form.querySelectorAll('[data-ref-source]').forEach(function (b) {
                    var on = b === tab;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                form.querySelectorAll('[data-ref-source-panel]').forEach(function (p) {
                    p.hidden = p.getAttribute('data-ref-source-panel') !== src;
                });
            });
        });

        titleEl.addEventListener('input', updateSubmit);
        goalEl.addEventListener('input', updateSubmit);

        function startReferenceProgress() {
            var items = progress.querySelectorAll('li');
            var index = 0;
            items.forEach(function (it, i) { it.classList.toggle('is-active', i === 0); it.classList.remove('is-done'); });
            var timer = setInterval(function () {
                if (progress.hidden) { clearInterval(timer); return; }
                index = Math.min(index + 1, items.length - 1);
                items.forEach(function (it, i) {
                    it.classList.toggle('is-active', i === index);
                    it.classList.toggle('is-done', i < index);
                });
                if (index === items.length - 1) clearInterval(timer);
            }, 4000);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (submit.disabled) return;
            setButtonBusy(submit, true, 'Generando');
            setStatus('Generando tu página…');
            progress.hidden = false;
            startReferenceProgress();

            var typeEl    = form.querySelector('[name="page_type"]');
            var contentEl = form.querySelector('[name="source_content"]');
            var docEl     = form.querySelector('[name="document_id"]');
            var seedEl    = form.querySelector('[name="seed_page_id"]');

            var fd = new FormData();
            fd.set('_csrf', form.querySelector('[name="_csrf"]').value);
            fd.set('title', titleEl.value.trim());
            fd.set('page_type', typeEl ? typeEl.value : 'landing');
            fd.set('ai_page_goal', goalEl.value.trim());
            fd.set('source_content', contentEl ? contentEl.value.trim() : '');
            fd.set('document_id', (docEl && docEl.value) ? docEl.value : '');
            fd.set('seed_page_id', (seedEl && seedEl.value) ? seedEl.value : '');
            fd.set('ai_target_audience', audEl ? audEl.value.trim() : '');
            fd.set('ai_extra_context', detEl ? detEl.value.trim() : '');
            files.forEach(function (f) { fd.append('references[]', f); });

            fetchWithTimeout(baseUrl + '/admin/pages/ai-from-reference', {
                method: 'POST', body: fd, credentials: 'same-origin'
            }, 240000).then(function (res) {
                return res.json().then(function (body) {
                    if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                    return body;
                });
            }).then(function (body) {
                setStatus('Página generada. Abriendo el Studio…', 'ok');
                window.location.href = body.edit_url;
            }).catch(function (err) {
                progress.hidden = true;
                setStatus(err.message || 'No se pudo generar la página.', 'err');
                setButtonBusy(submit, false, 'Generar página');
                updateSubmit();
            });
        });

        updateSubmit();
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
