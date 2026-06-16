(function () {
    'use strict';

    var root = document.getElementById('pp-site-map');
    if (!root) return;

    var baseUrl = (root.dataset.baseUrl || '').replace(/\/$/, '');
    var csrf = root.dataset.csrf || '';
    var configured = root.dataset.aiConfigured === '1';
    var architectPanel = document.getElementById('pp-architect-panel');
    var architectBody = document.getElementById('pp-architect-body');
    var architectToggle = document.getElementById('pp-architect-toggle');
    var suggestions = document.getElementById('pp-map-suggestions');
    var inspector = document.getElementById('pp-map-inspector');
    var pageData = readPageData();
    var runBtn = document.getElementById('pp-architect-run');
    var refreshBtn = document.getElementById('pp-architect-refresh');
    var architectAnalyzed = false;

    bindTabs();
    bindStructureForms();
    bindCreateButtons();
    bindInspector();
    bindFocusChips();
    bindCanvasDismiss();
    bindDensityControls();

    runBtn && runBtn.addEventListener('click', function () {
        expandArchitect();
        analyze(true);
    });
    refreshBtn && refreshBtn.addEventListener('click', function () { analyze(true); });
    architectToggle && architectToggle.addEventListener('click', function () {
        if (architectPanel.classList.contains('is-collapsed')) {
            expandArchitect();
            if (configured && !architectAnalyzed) analyze(false);
        } else {
            collapseArchitect();
        }
    });

    function expandArchitect() {
        if (!architectPanel) return;
        architectPanel.classList.remove('is-collapsed');
        if (architectBody) architectBody.hidden = false;
        if (refreshBtn) refreshBtn.hidden = !architectAnalyzed;
        if (architectToggle) {
            architectToggle.setAttribute('aria-expanded', 'true');
            architectToggle.setAttribute('aria-label', 'Ocultar diagnóstico');
        }
        if (!configured && architectBody && !architectBody.dataset.errored) {
            renderArchitectError('Configura IA para activar el diagnóstico de arquitectura.');
        }
    }

    function collapseArchitect() {
        if (!architectPanel) return;
        architectPanel.classList.add('is-collapsed');
        if (architectBody) architectBody.hidden = true;
        if (refreshBtn) refreshBtn.hidden = true;
        if (architectToggle) {
            architectToggle.setAttribute('aria-expanded', 'false');
            architectToggle.setAttribute('aria-label', 'Mostrar diagnóstico');
        }
    }

    function bindTabs() {
        root.querySelectorAll('[data-map-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var name = btn.getAttribute('data-map-tab');
                root.querySelectorAll('[data-map-tab]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                root.querySelectorAll('[data-map-view]').forEach(function (view) {
                    var active = view.getAttribute('data-map-view') === name;
                    view.classList.toggle('is-active', active);
                    view.hidden = !active;
                });
            });
        });
    }

    function bindStructureForms() {
        root.querySelectorAll('.pp-map-structure-form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var button = form.querySelector('button[type="submit"]');
                setButtonBusy(button, true, 'Guardando');
                postForm(form.getAttribute('action'), formDataObject(form), 30000)
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(function (err) {
                        showToast(err.message || 'No se pudo guardar la estructura.', 'error');
                    })
                    .finally(function () {
                        setButtonBusy(button, false, 'Guardar estructura');
                    });
            });
        });
    }

    function bindCreateButtons() {
        root.addEventListener('click', function (event) {
            var suggested = event.target.closest('[data-create-suggested]');
            if (suggested) {
                createSuggested(JSON.parse(suggested.getAttribute('data-create-suggested') || '{}'), suggested);
                return;
            }
            var child = event.target.closest('[data-create-child]');
            if (child) {
                openChildComposer(child);
            }
        });
    }

    function bindInspector() {
        root.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-inspect-page]');
            var card = trigger ? trigger.closest('.pp-map-card') : event.target.closest('.pp-map-card');
            if (!card) return;
            if (card.classList.contains('pp-map-card--ghost')) return;
            if (!trigger && event.target.closest('a, button, input, select, textarea, summary')) return;
            if (trigger) event.preventDefault();
            selectCard(card);
            if (trigger) renderInspector(card);
        });
    }

    function bindFocusChips() {
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-focus-page]');
            if (!button) return;
            var id = button.getAttribute('data-focus-page');
            var card = root.querySelector('[data-page-id="' + cssEscape(id) + '"] .pp-map-card');
            if (!card) return;
            root.querySelectorAll('[data-focus-page].is-active').forEach(function (item) {
                item.classList.remove('is-active');
            });
            button.classList.add('is-active');
            selectCard(card);
            card.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        });
    }

    function bindDensityControls() {
        root.querySelectorAll('[data-map-density]').forEach(function (button) {
            button.addEventListener('click', function () {
                var mode = button.getAttribute('data-map-density') === 'compact' ? 'compact' : 'cozy';
                root.classList.toggle('is-compact-map', mode === 'compact');
                root.querySelectorAll('[data-map-density]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
        });
    }

    function selectCard(card) {
        root.querySelectorAll('.pp-map-card.is-selected').forEach(function (item) {
            item.classList.remove('is-selected');
        });
        card.classList.add('is-selected');
        applyBranchFocus(card);
        renderInspector(card);
    }

    function bindCanvasDismiss() {
        root.addEventListener('click', function (event) {
            if (!event.target.closest('.pp-map-canvas')) return;
            if (event.target.closest('.pp-map-card, .pp-map-nav-preview, .pp-map-intelligence')) return;
            clearInspector();
        });
        if (inspector) {
            inspector.addEventListener('click', function (event) {
                if (event.target === inspector || event.target.closest('[data-close-inspector]')) {
                    closeInspector();
                }
            });
            inspector.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeInspector();
            });
        }
    }

    function clearInspector() {
        root.querySelectorAll('.pp-map-card.is-selected, .pp-map-card.is-in-branch, .pp-map-card.is-dimmed').forEach(function (item) {
            item.classList.remove('is-selected', 'is-in-branch', 'is-dimmed');
        });
        root.querySelectorAll('[data-focus-page].is-active').forEach(function (item) {
            item.classList.remove('is-active');
        });
        closeInspector();
    }

    function closeInspector() {
        if (!inspector) return;
        inspector.hidden = true;
        inspector.innerHTML = '';
    }

    function analyze(force) {
        if (!architectBody) return;
        if (!configured) {
            renderArchitectError('Configura IA para activar el diagnóstico de arquitectura.');
            return;
        }
        architectBody.innerHTML = skeletonHtml();
        delete architectBody.dataset.errored;
        if (suggestions) suggestions.innerHTML = '';
        setButtonBusy(runBtn, true, force ? 'Analizando' : null);
        setButtonBusy(refreshBtn, true, force ? 'Analizando' : null);

        postForm('/admin/pages/architecture/analyze', { force: force ? '1' : '' }, 60000)
            .then(function (body) {
                renderArchitecture(body);
                architectAnalyzed = true;
                if (refreshBtn) refreshBtn.hidden = false;
            })
            .catch(function (err) {
                renderArchitectError(err.message || 'No se pudo analizar la arquitectura.');
            })
            .finally(function () {
                setButtonBusy(runBtn, false, 'Analizar sitio');
                setButtonBusy(refreshBtn, false, 'Reanalizar');
            });
    }

    function renderArchitecture(body) {
        var architecture = body.architecture || {};
        var health = architecture.health || {};
        var diagnostics = Array.isArray(architecture.diagnostics) ? architecture.diagnostics : [];
        var missing = Array.isArray(architecture.missing_pages) ? architecture.missing_pages : [];
        var groups = Array.isArray(architecture.suggested_groups) ? architecture.suggested_groups : [];

        architectBody.innerHTML = [
            '<div class="pp-architect-health">',
                '<div class="pp-architect-health__score">' + escapeHtml(String(health.score || 0)) + '</div>',
                '<div><strong>' + escapeHtml(health.label || 'Arquitectura en progreso') + '</strong>',
                '<span>' + escapeHtml(architecture.summary || '') + '</span></div>',
            '</div>',
            '<p class="pp-studio-note">' + (body.cached ? 'Análisis guardado' + (body.cached_at ? ' · ' + escapeHtml(formatDate(body.cached_at)) : '') : formatAiMeta(body)) + '</p>',
            diagnostics.length ? '<div class="pp-architect-diagnostics">' + diagnostics.map(diagnosticHtml).join('') + '</div>' : ''
        ].join('');

        renderSuggestions(missing, groups);
    }

    function renderSuggestions(missing, groups) {
        clearGhostNodes();
        if (suggestions) suggestions.innerHTML = '';

        if (!groups.length && !missing.length) {
            if (suggestions) {
                suggestions.innerHTML = '<section class="pp-map-ai-lane pp-map-ai-lane--quiet"><div class="pp-map-ai-lane__head"><strong>No hay huecos prioritarios</strong><span>El análisis no detectó nuevas ramas urgentes.</span></div></section>';
            }
            return;
        }

        injectGhostMissing(missing);

        if (groups.length && suggestions) {
            var html = '<section class="pp-map-ai-lane"><div class="pp-map-ai-lane__head"><strong>Ramas sugeridas</strong><span>Grupos que podrían ordenar mejor la navegación</span></div><div class="pp-map-ai-branches">';
            html += groups.map(function (g) {
                var payload = {
                    title: g.label || 'Nueva rama',
                    page_type: 'landing',
                    parent_id: '',
                    goal: 'Crear una página agrupadora para ordenar esta rama del sitio.',
                    reason: g.reason || '',
                    architecture_context: 'Rama sugerida por AI Site Architect: /' + (g.slug || '')
                };
                return [
                    '<article class="pp-map-ghost-node pp-map-ghost-node--group">',
                        '<div class="pp-map-ghost-node__top"><span aria-hidden="true">R</span><div><strong>' + escapeHtml(g.label || '') + '</strong><code>/' + escapeHtml(g.slug || '') + '</code></div></div>',
                        '<p>' + escapeHtml(g.reason || '') + '</p>',
                        '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-create-suggested="' + escapeHtml(JSON.stringify(payload)) + '">Crear rama</button>',
                    '</article>'
                ].join('');
            }).join('');
            html += '</div></section>';
            suggestions.innerHTML = html;
        }
    }

    function clearGhostNodes() {
        root.querySelectorAll('.pp-map-node--ghost').forEach(function (node) {
            node.remove();
        });
        root.querySelectorAll('.pp-map-children--ghost-only').forEach(function (list) {
            if (!list.querySelector('.pp-map-node')) list.remove();
        });
    }

    function injectGhostMissing(missing) {
        if (!missing || !missing.length) return;
        var tree = root.querySelector('.pp-map-tree');
        if (!tree) return;

        missing.forEach(function (p) {
            var parentId = p.parent_id ? Number(p.parent_id) : 0;
            var parentNode = parentId ? root.querySelector('.pp-map-node[data-page-id="' + cssEscape(String(parentId)) + '"]') : null;
            var ghostLi = buildGhostNode(p);

            if (parentNode) {
                var children = parentNode.querySelector(':scope > .pp-map-children');
                if (!children) {
                    children = document.createElement('ol');
                    children.className = 'pp-map-children pp-map-children--ghost-only';
                    parentNode.appendChild(children);
                }
                children.appendChild(ghostLi);
            } else {
                tree.appendChild(ghostLi);
            }
        });
    }

    function buildGhostNode(p) {
        var li = document.createElement('li');
        li.className = 'pp-map-node pp-map-node--ghost';
        li.setAttribute('data-ghost', '1');

        var priority = (p.priority || 'medium').toLowerCase();
        var payload = JSON.stringify(p);
        var title = p.title || 'Página sugerida';
        var slug = p.slug ? '/' + p.slug : '';

        li.innerHTML = [
            '<article class="pp-map-card pp-map-card--ghost pp-map-card--ghost-' + escapeHtml(priority) + '">',
                '<div class="pp-map-card__main">',
                    '<div class="pp-map-card__top">',
                        '<span class="pp-map-card__mark pp-map-card__mark--ai" aria-hidden="true">IA</span>',
                        '<div class="pp-map-card__title">',
                            '<span class="pp-map-card__type">Sugerencia · ' + escapeHtml(priorityLabel(priority)) + '</span>',
                            '<h3>' + escapeHtml(title) + '</h3>',
                        '</div>',
                        '<span class="pp-badge pp-badge--ghost">Faltante</span>',
                    '</div>',
                    (slug ? '<div class="pp-map-card__meta"><code>' + escapeHtml(slug) + '</code></div>' : ''),
                    (p.reason || p.goal ? '<p class="pp-map-card__ghost-reason">' + escapeHtml(p.reason || p.goal) + '</p>' : ''),
                    '<div class="pp-map-card__actions">',
                        '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-create-suggested="' + escapeHtml(payload) + '">Crear con IA</button>',
                    '</div>',
                '</div>',
            '</article>'
        ].join('');
        return li;
    }

    function createSuggested(item, button) {
        setButtonBusy(button, true, 'Creando');
        postForm('/admin/pages/ai-create', {
            title: item.title || 'Nueva página',
            page_type: item.page_type || 'landing',
            parent_id: item.parent_id || '',
            ai_page_goal: item.goal || item.reason || 'Crear una página útil para esta arquitectura.',
            ai_target_audience: item.audience || '',
            ai_extra_context: item.reason || '',
            architecture_context: item.architecture_context || item.reason || ''
        }, 180000).then(function (body) {
            window.location.href = body.edit_url;
        }).catch(function (err) {
            showToast(err.message || 'No se pudo crear la página.', 'error');
        }).finally(function () {
            setButtonBusy(button, false, button && button.dataset.createChild ? 'Crear hija con IA' : 'Crear con IA');
        });
    }

    function openChildComposer(button) {
        var parentTitle = button.getAttribute('data-parent-title') || 'esta página';
        var parentId = Number(button.getAttribute('data-create-child') || 0);
        var existing = document.getElementById('pp-child-composer');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.className = 'pp-map-modal';
        modal.id = 'pp-child-composer';
        modal.innerHTML = [
            '<div class="pp-map-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-child-composer-title">',
                '<button type="button" class="pp-map-modal__close" data-close-child-composer aria-label="Cerrar">&times;</button>',
                '<span>Nueva página hija</span>',
                '<h3 id="pp-child-composer-title">Bajo ' + escapeHtml(parentTitle) + '</h3>',
                '<label><span>Título o intención</span><input type="text" id="pp-child-composer-input" autocomplete="off" placeholder="Ej. Servicio de auditoría SEO"></label>',
                '<div class="pp-map-modal__actions">',
                    '<button type="button" class="pp-btn pp-btn--secondary" data-close-child-composer>Cancelar</button>',
                    '<button type="button" class="pp-btn pp-btn--primary" id="pp-child-composer-create">Crear con IA</button>',
                '</div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);

        var input = document.getElementById('pp-child-composer-input');
        var create = document.getElementById('pp-child-composer-create');
        if (input) input.focus();

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-close-child-composer]')) {
                modal.remove();
            }
        });
        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') modal.remove();
            if (event.key === 'Enter' && event.target === input) {
                event.preventDefault();
                if (create) create.click();
            }
        });
        if (create) {
            create.addEventListener('click', function () {
                var title = input ? input.value.trim() : '';
                if (!title) {
                    showToast('Añade un título o intención para la página.', 'error');
                    return;
                }
                modal.remove();
                createSuggested({
                    title: title,
                    page_type: 'landing',
                    parent_id: parentId,
                    goal: 'Crear una página hija dentro de la arquitectura del sitio.',
                    architecture_context: 'Página hija creada desde el mapa del sitio bajo "' + parentTitle + '".'
                }, button);
            });
        }
    }

    function renderInspector(card) {
        if (!inspector) return;
        var id = Number(card.closest('[data-page-id]').getAttribute('data-page-id') || 0);
        var data = card.dataset;
        inspector.innerHTML = [
            '<div class="pp-map-inspector__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-map-inspector-title">',
                '<button type="button" class="pp-map-inspector__close" data-close-inspector aria-label="Cerrar">&times;</button>',
                '<div class="pp-map-inspector__head">',
                    '<span>' + escapeHtml(data.pageType || 'Página') + '</span>',
                    '<strong id="pp-map-inspector-title">' + escapeHtml(data.pageLabel || data.pageTitle || 'Página') + '</strong>',
                    '<code>/' + escapeHtml(data.pageSlug || '') + '</code>',
                '</div>',
                breadcrumbHtml(id),
                '<div class="pp-map-inspector__actions">',
                    '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + escapeHtml(data.pageEdit || '#') + '">Editar</a>',
                    '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + escapeHtml(data.pagePreview || '#') + '">Preview</a>',
                    '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-create-child="' + id + '" data-parent-title="' + escapeHtml(data.pageLabel || data.pageTitle || 'esta página') + '">Crear hija con IA</button>',
                '</div>',
                '<form class="pp-map-inspector-form" action="' + escapeHtml(data.pageStructure || '') + '" method="POST">',
                    '<label><span>Etiqueta navegación</span><input type="text" name="nav_label" value="' + escapeHtml(data.pageNav || '') + '" placeholder="' + escapeHtml(data.pageTitle || '') + '"></label>',
                    '<label><span>Padre</span><select name="parent_id">' + parentOptionsHtml(id, data.pageParent || '') + '</select></label>',
                    '<label><span>Orden</span><input type="number" name="tree_sort_order" min="0" value="' + escapeHtml(data.pageOrder || '0') + '"></label>',
                    '<button type="submit" class="pp-btn pp-btn--primary">Guardar estructura</button>',
                '</form>',
            '</div>'
        ].join('');
        inspector.hidden = false;
        inspector.focus();

        var form = inspector.querySelector('.pp-map-inspector-form');
        form && form.addEventListener('submit', function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"]');
            setButtonBusy(button, true, 'Guardando');
            postForm(form.getAttribute('action'), formDataObject(form), 30000)
                .then(function () {
                    window.location.reload();
                })
                .catch(function (err) {
                    showToast(err.message || 'No se pudo guardar la estructura.', 'error');
                })
                .finally(function () {
                    setButtonBusy(button, false, 'Guardar estructura');
                });
        });
    }

    function applyBranchFocus(card) {
        root.querySelectorAll('.pp-map-card.is-in-branch, .pp-map-card.is-dimmed').forEach(function (item) {
            item.classList.remove('is-in-branch', 'is-dimmed');
        });
        var selectedNode = card.closest('.pp-map-node');
        if (!selectedNode) return;
        var branch = new Set();
        var node = selectedNode;
        while (node && node.classList && node.classList.contains('pp-map-node')) {
            var nodeCard = node.querySelector(':scope > .pp-map-card');
            if (nodeCard) branch.add(nodeCard);
            var parentList = node.parentElement;
            node = parentList ? parentList.closest('.pp-map-node') : null;
        }
        selectedNode.querySelectorAll('.pp-map-card').forEach(function (childCard) {
            branch.add(childCard);
        });
        root.querySelectorAll('.pp-map-card').forEach(function (item) {
            if (branch.has(item)) {
                item.classList.add('is-in-branch');
            } else {
                item.classList.add('is-dimmed');
            }
        });
    }

    function breadcrumbHtml(pageId) {
        var chain = pageChain(pageId);
        if (!chain.length) return '';
        return [
            '<div class="pp-map-inspector__route">',
                '<span>Ruta en el sitio</span>',
                '<ol>',
                    chain.map(function (page) {
                        return '<li><button type="button" data-focus-page="' + escapeHtml(page.id) + '">' + escapeHtml(page.label || page.title || '') + '</button></li>';
                    }).join(''),
                '</ol>',
            '</div>'
        ].join('');
    }

    function pageChain(pageId) {
        var byId = {};
        pageData.forEach(function (page) { byId[Number(page.id)] = page; });
        var out = [];
        var seen = {};
        var current = byId[Number(pageId)];
        while (current && !seen[Number(current.id)]) {
            seen[Number(current.id)] = true;
            out.unshift(current);
            current = current.parent_id ? byId[Number(current.parent_id)] : null;
        }
        return out;
    }

    function parentOptionsHtml(currentId, selectedId) {
        var html = '<option value="">Raíz</option>';
        pageData.forEach(function (page) {
            if (Number(page.id) === Number(currentId)) return;
            var selected = String(page.id) === String(selectedId || '') ? ' selected' : '';
            html += '<option value="' + escapeHtml(page.id) + '"' + selected + '>' + escapeHtml(page.label || page.title || '') + ' · /' + escapeHtml(page.slug || '') + '</option>';
        });
        return html;
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
            if (err && err.name === 'AbortError') throw new Error('La operación ha tardado demasiado. Prueba de nuevo.');
            throw err;
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    function formDataObject(form) {
        var out = {};
        new FormData(form).forEach(function (value, key) { out[key] = value; });
        return out;
    }

    function readPageData() {
        var el = document.getElementById('pp-map-pages-data');
        if (!el) return [];
        try {
            var parsed = JSON.parse(el.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function renderArchitectError(message) {
        if (!architectBody) return;
        architectBody.innerHTML = '<div class="pp-alert pp-alert--error">' + escapeHtml(message) + '</div>';
        architectBody.dataset.errored = '1';
    }

    function showToast(message, type) {
        var el = document.createElement('div');
        el.className = 'pp-toast pp-toast--' + (type || 'success');
        el.textContent = message;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('is-visible'); });
        setTimeout(function () {
            el.classList.remove('is-visible');
            setTimeout(function () { el.remove(); }, 220);
        }, 3600);
    }

    function skeletonHtml() {
        return '<div class="pp-map-skeleton"><span></span><span></span><span></span></div>';
    }

    function diagnosticHtml(item) {
        return '<div class="pp-architect-diagnostic pp-architect-diagnostic--' + escapeHtml(item.severity || 'info') + '"><strong>' + escapeHtml(item.label || '') + '</strong><span>' + escapeHtml(item.detail || '') + '</span></div>';
    }

    function priorityLabel(priority) {
        return { high: 'Prioritaria', medium: 'Recomendada', low: 'Opcional' }[priority] || 'Recomendada';
    }

    function setButtonBusy(button, busy, label) {
        if (!button) return;
        if (label !== null && label !== undefined) button.textContent = label;
        button.disabled = !!busy;
        button.classList.toggle('is-busy', !!busy);
    }

    function formatAiMeta(body) {
        if (!body || !body.model) return 'Análisis nuevo';
        var cost = typeof body.estimated_cost === 'number' ? ' · $' + body.estimated_cost.toFixed(6) : '';
        return body.model + ' · ' + Number(body.tokens_in || 0) + ' -> ' + Number(body.tokens_out || 0) + ' tokens' + cost;
    }

    function formatDate(value) {
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }
        return String(value).replace(/"/g, '\\"');
    }
})();
