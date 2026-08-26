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
    // PAGES-OPS — operaciones sobre páginas: las mismas en el mapa y en la lista.
    bindPageActions();
    bindListToolbar();
    bindBulkActions();
    bindMapLanguageFilter();
    bindDragAndDrop();

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
            architectToggle.setAttribute('aria-label', pp.t('js.map.hide_diagnosis'));
        }
        if (!configured && architectBody && !architectBody.dataset.errored) {
            renderArchitectError(pp.t('js.map.configure_ai'));
        }
    }

    function collapseArchitect() {
        if (!architectPanel) return;
        architectPanel.classList.add('is-collapsed');
        if (architectBody) architectBody.hidden = true;
        if (refreshBtn) refreshBtn.hidden = true;
        if (architectToggle) {
            architectToggle.setAttribute('aria-expanded', 'false');
            architectToggle.setAttribute('aria-label', pp.t('js.map.show_diagnosis'));
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

    // ======================================================================
    // PAGES-OPS — acciones por página
    //
    // El marcado de la fila (lista) y el de la tarjeta (mapa) es distinto, pero
    // los dos llevan los mismos `data-page-*`. Todo lo de aquí trabaja sobre el
    // contenedor más cercano con `data-page-id`, así que una acción nueva vale
    // para las dos vistas sin escribirla dos veces.
    // ======================================================================

    var openMenu = null;

    function bindPageActions() {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-page-menu]');
            if (trigger) {
                event.preventDefault();
                event.stopPropagation();
                togglePageMenu(trigger);
                return;
            }
            var item = event.target.closest('[data-page-do]');
            if (item) {
                event.preventDefault();
                runPageAction(item.getAttribute('data-page-do'), item.dataset.pageRef, item);
                closePageMenu();
                return;
            }
            closePageMenu();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closePageMenu();
        });
        window.addEventListener('resize', closePageMenu);
        window.addEventListener('scroll', closePageMenu, true);
    }

    function togglePageMenu(trigger) {
        var holder = trigger.closest('[data-page-id]');
        if (!holder) return;
        if (openMenu && openMenu.trigger === trigger) {
            closePageMenu();
            return;
        }
        closePageMenu();

        var data = holder.dataset;
        var id = data.pageId;
        var published = data.pageStatus === 'published';
        var isHome = data.pageType === 'home';
        var items = [];

        items.push(menuItem(id, 'status', published ? 'Volver a borrador' : 'Publicar'));
        items.push(menuItem(id, 'duplicate', 'Duplicar'));
        // Una entrada del blog no puede ser portada, y la home ya lo es.
        if (!isHome && data.pageType !== 'article') {
            items.push(menuItem(id, 'set-home', 'Marcar como inicio'));
        }
        items.push('<hr>');
        items.push(menuItem(id, 'delete', 'Eliminar', 'is-danger'));

        var menu = document.createElement('div');
        menu.className = 'pp-page-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = items.join('');
        document.body.appendChild(menu);

        var rect = trigger.getBoundingClientRect();
        menu.style.top = (window.scrollY + rect.bottom + 6) + 'px';
        // Anclado a la derecha del botón: cerca del borde, un menú anclado a la
        // izquierda se sale de la pantalla.
        menu.style.left = (window.scrollX + Math.max(8, rect.right - menu.offsetWidth)) + 'px';

        trigger.setAttribute('aria-expanded', 'true');
        openMenu = { el: menu, trigger: trigger };
    }

    function menuItem(id, action, label, extraClass) {
        return '<button type="button" role="menuitem" class="pp-page-menu__item ' + (extraClass || '') + '"'
            + ' data-page-do="' + escapeHtml(action) + '" data-page-ref="' + escapeHtml(id) + '">'
            + escapeHtml(label) + '</button>';
    }

    function closePageMenu() {
        if (!openMenu) return;
        openMenu.el.remove();
        openMenu.trigger.setAttribute('aria-expanded', 'false');
        openMenu = null;
    }

    /** Todos los contenedores (fila y tarjeta) de una misma página. */
    function holdersFor(id) {
        return Array.prototype.slice.call(document.querySelectorAll('[data-page-id="' + id + '"]'));
    }

    function runPageAction(action, id, sourceEl) {
        // En el mapa hay DOS elementos con el mismo `data-page-id`: el <li> del
        // árbol (que solo lo usa el drag & drop) y la tarjeta, que es la que
        // lleva los datos. Coger el primero a secas daba "esta página".
        var holder = holdersFor(id).filter(function (el) { return !!el.dataset.pageTitle; })[0]
            || holdersFor(id)[0];
        if (!holder) return;
        var title = holder.dataset.pageTitle || pp.t('js.map.this_page');

        if (action === 'status') {
            var next = holder.dataset.pageStatus === 'published' ? 'draft' : 'published';
            postForm('/admin/pages/' + id + '/status', { status: next }, 20000)
                .then(function (body) {
                    applyStatusToUi(id, next);
                    showToast(body.message || 'Estado actualizado.', 'success');
                    if (body.warning) showToast(body.warning, 'error');
                })
                .catch(function (err) { showToast(err.message || 'No se pudo cambiar el estado.', 'error'); });
            return;
        }

        if (action === 'duplicate') {
            postForm('/admin/pages/' + id + '/duplicate', {}, 30000)
                .then(function (body) {
                    showToast(body.message || 'Copia creada.', 'success');
                    // La copia tiene que aparecer en el árbol y en la tabla: se
                    // recarga en vez de inventarse el marcado de una página nueva.
                    window.location.reload();
                })
                .catch(function (err) { showToast(err.message || 'No se pudo duplicar.', 'error'); });
            return;
        }

        if (action === 'set-home') {
            if (!window.confirm(pp.t('js.map.confirm_home', { titulo: title }) + '\n\n' + pp.t('js.map.confirm_home_note'))) return;
            postForm('/admin/pages/' + id + '/set-home', {}, 20000)
                .then(function (body) {
                    showToast(body.message || 'Inicio actualizado.', 'success');
                    if (body.warning) showToast(body.warning, 'error');
                    window.location.reload();
                })
                .catch(function (err) { showToast(err.message || 'No se pudo marcar como inicio.', 'error'); });
            return;
        }

        if (action === 'delete') {
            openDeleteDialog(id, title, sourceEl);
        }
    }

    function applyStatusToUi(id, status) {
        holdersFor(id).forEach(function (holder) {
            holder.dataset.pageStatus = status;
            var card = holder.classList.contains('pp-map-card') ? holder : null;
            if (card) {
                card.classList.remove('pp-map-card--published', 'pp-map-card--draft');
                card.classList.add('pp-map-card--' + status);
            }
            var badge = holder.querySelector('.pp-badge--success, .pp-badge--muted');
            if (badge) {
                badge.className = 'pp-badge ' + (status === 'published' ? 'pp-badge--success' : 'pp-badge--muted');
                badge.textContent = status === 'published' ? 'Publicada' : 'Borrador';
            }
        });
    }

    // ----------------------------------------------------------------------
    // G5 — Borrado informado: primero se pregunta al servidor QUÉ se lleva por
    // delante (hijas que suben a raíz, traducciones huérfanas, enlaces que
    // quedarán rotos) y solo entonces se enseña la confirmación.
    // ----------------------------------------------------------------------
    function openDeleteDialog(id, title, sourceEl) {
        setButtonBusy(sourceEl, true, null);
        fetch(baseUrl + '/admin/pages/' + id + '/delete-info', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(readJson).then(function (info) {
            renderDeleteDialog(id, title, info);
        }).catch(function (err) {
            showToast(err.message || pp.t('js.map.delete_check_failed'), err.gone ? 'success' : 'error');
            // La tarjeta es un fantasma: la página ya no está en la base de
            // datos. Recargar es lo único que deja la pantalla en su sitio.
            if (err.gone) setTimeout(function () { window.location.reload(); }, 1200);
        }).finally(function () {
            setButtonBusy(sourceEl, false, null);
        });
    }

    function renderDeleteDialog(id, title, info) {
        var warnings = [];
        if (info.is_home) {
            warnings.push('<li class="is-danger">' + pp.t('js.map.warn_is_home.html') + '</li>');
        }
        if ((info.children || []).length) {
            warnings.push('<li>' + pp.t('js.map.warn_children.html', { n: info.children.length })
                + ' ' + info.children.map(function (c) { return escapeHtml(c.title); }).join(', ') + '.</li>');
        }
        if ((info.translations || []).length) {
            warnings.push('<li>' + pp.t('js.map.warn_translations.html', { n: info.translations.length })
                + ' ' + info.translations.map(function (t) { return escapeHtml(t.title) + (t.language ? ' (' + escapeHtml(t.language) + ')' : ''); }).join(', ') + '.</li>');
        }
        if ((info.inbound || []).length) {
            warnings.push('<li>' + pp.t('js.map.warn_inbound.html', { n: info.inbound.length })
                + ' ' + info.inbound.map(function (p) { return escapeHtml(p.title); }).join(', ') + '.</li>');
        }

        var redirectBlock = '';
        if (info.published) {
            var options = (info.redirect_targets || []).map(function (t) {
                return '<option value="' + escapeHtml(t.slug) + '">' + escapeHtml(t.title) + ' · ' + escapeHtml(t.slug) + '</option>';
            }).join('');
            redirectBlock = [
                '<div class="pp-del-redirect">',
                    '<label><input type="checkbox" data-del-redirect' + ((info.inbound || []).length ? ' checked' : '') + '> ',
                    pp.t('js.map.redirect_label.html', { slug: '<code>/' + escapeHtml(String(info.slug || '').replace(/^\//, '')) + '</code>' }) + '</label>',
                    '<select data-del-target>' + options + '</select>',
                    '<small>' + pp.t('js.map.redirect_help') + '</small>',
                '</div>'
            ].join('');
        }

        var overlay = document.createElement('div');
        overlay.className = 'pp-del-overlay';
        overlay.innerHTML = [
            '<div class="pp-del-dialog" role="dialog" aria-modal="true" aria-labelledby="pp-del-title">',
                '<h3 id="pp-del-title">' + pp.t('js.map.delete_title', { titulo: escapeHtml(title) }) + '</h3>',
                warnings.length
                    ? '<ul class="pp-del-warnings">' + warnings.join('') + '</ul>'
                    : '<p class="pp-del-clean">' + pp.t('js.map.delete_clean') + '</p>',
                redirectBlock,
                '<p class="pp-del-final">' + pp.t('js.map.cannot_undo') + '</p>',
                '<div class="pp-del-actions">',
                    '<button type="button" class="pp-btn pp-btn--secondary" data-del-cancel>' + pp.t('js.common.cancel') + '</button>',
                    '<button type="button" class="pp-btn pp-btn--danger" data-del-confirm>' + pp.t('js.map.delete_page') + '</button>',
                '</div>',
            '</div>'
        ].join('');
        document.body.appendChild(overlay);

        var close = function () { overlay.remove(); };
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay || event.target.closest('[data-del-cancel]')) close();
        });
        document.addEventListener('keydown', function onKey(event) {
            if (event.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
        });

        overlay.querySelector('[data-del-confirm]').addEventListener('click', function (event) {
            var button = event.currentTarget;
            var wantsRedirect = overlay.querySelector('[data-del-redirect]');
            var target = overlay.querySelector('[data-del-target]');
            var payload = {};
            if (wantsRedirect && wantsRedirect.checked && target && target.value) {
                payload.redirect_to = target.value;
            }
            setButtonBusy(button, true, 'Eliminando…');
            postForm('/admin/pages/' + id + '/delete', payload, 25000)
                .then(function (body) {
                    showToast(body.message || pp.t('js.map.page_deleted'), 'success');
                    window.location.reload();
                })
                .catch(function (err) {
                    showToast(err.message || 'No se pudo eliminar.', 'error');
                    setButtonBusy(button, false, pp.t('js.map.delete_page'));
                });
        });
    }

    // ----------------------------------------------------------------------
    // G6 — Buscador, filtros y acciones en lote de la lista
    // ----------------------------------------------------------------------
    function bindListToolbar() {
        var toolbar = root.querySelector('[data-pages-toolbar]');
        if (!toolbar) return;
        var search = toolbar.querySelector('[data-pages-search]');
        var counter = toolbar.querySelector('[data-pages-count]');
        var filters = Array.prototype.slice.call(toolbar.querySelectorAll('[data-pages-filter]'));
        var rows = Array.prototype.slice.call(root.querySelectorAll('[data-pages-row]'));

        var apply = function () {
            var term = (search ? search.value : '').trim().toLowerCase();
            var wanted = {};
            filters.forEach(function (select) { wanted[select.getAttribute('data-pages-filter')] = select.value; });

            var visible = 0;
            rows.forEach(function (row) {
                var ok = (term === '' || (row.dataset.search || '').indexOf(term) !== -1)
                    && (!wanted.status || row.dataset.pageStatus === wanted.status)
                    && (!wanted.type || row.dataset.pageType === wanted.type);
                row.hidden = !ok;
                if (ok) visible++;
                // Una fila oculta no puede quedarse seleccionada: el lote actuaría
                // sobre páginas que el usuario ya no está viendo.
                if (!ok) {
                    var box = row.querySelector('[data-bulk-item]');
                    if (box) box.checked = false;
                }
            });
            if (counter) counter.textContent = pp.t(visible === 1 ? 'js.onb.pages_one' : 'js.onb.pages_other', { n: visible });
            refreshBulkBar();
        };

        search && search.addEventListener('input', apply);
        filters.forEach(function (select) { select.addEventListener('change', apply); });
    }

    function bindBulkActions() {
        var bar = root.querySelector('[data-pages-bulk]');
        if (!bar) return;

        root.addEventListener('change', function (event) {
            if (event.target.matches('[data-bulk-all]')) {
                root.querySelectorAll('[data-pages-row]').forEach(function (row) {
                    if (row.hidden) return;
                    var box = row.querySelector('[data-bulk-item]');
                    if (box) box.checked = event.target.checked;
                });
            }
            if (event.target.matches('[data-bulk-item], [data-bulk-all]')) refreshBulkBar();
        });

        bar.querySelector('[data-bulk-clear]').addEventListener('click', function () {
            root.querySelectorAll('[data-bulk-item], [data-bulk-all]').forEach(function (box) { box.checked = false; });
            refreshBulkBar();
        });

        bar.querySelectorAll('[data-bulk-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.getAttribute('data-bulk-action');
                var ids = selectedIds();
                if (!ids.length) return;
                if (action === 'delete' && !window.confirm(pp.t('js.map.confirm_bulk_delete', { n: ids.length }) + '\n\n' + pp.t('js.map.confirm_bulk_note'))) return;

                var params = new URLSearchParams();
                params.set('_csrf', csrf);
                params.set('action', action);
                ids.forEach(function (id) { params.append('ids[]', id); });

                setButtonBusy(button, true, null);
                fetch(baseUrl + '/admin/pages/bulk', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString(),
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json().then(function (body) {
                        if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
                        return body;
                    });
                }).then(function (body) {
                    if ((body.skipped || []).length) showToast('Sin tocar: ' + body.skipped.join(', '), 'error');
                    showToast(body.message || 'Hecho.', 'success');
                    window.location.reload();
                }).catch(function (err) {
                    showToast(err.message || pp.t('js.map.action_failed'), 'error');
                    setButtonBusy(button, false, null);
                });
            });
        });
    }

    function selectedIds() {
        return Array.prototype.slice.call(root.querySelectorAll('[data-bulk-item]'))
            .filter(function (box) { return box.checked; })
            .map(function (box) { return box.value; });
    }

    function refreshBulkBar() {
        var bar = root.querySelector('[data-pages-bulk]');
        if (!bar) return;
        var count = selectedIds().length;
        bar.hidden = count === 0;
        var label = bar.querySelector('[data-bulk-count]');
        if (label) label.textContent = String(count);
    }

    // ----------------------------------------------------------------------
    // G7 — El mapa, de un idioma cada vez
    // ----------------------------------------------------------------------
    function bindMapLanguageFilter() {
        var select = root.querySelector('[data-map-lang]');
        if (!select) return;
        var apply = function () {
            root.querySelectorAll('.pp-map-node[data-page-lang]').forEach(function (node) {
                var lang = node.getAttribute('data-page-lang') || '';
                // Las filas anteriores a la migración de idiomas no tienen idioma:
                // se enseñan siempre en vez de desaparecer del mapa.
                node.hidden = lang !== '' && lang !== select.value;
            });
        };
        select.addEventListener('change', apply);
        apply();
    }

    // ----------------------------------------------------------------------
    // G7 — Arrastrar para reordenar. Soltar SOBRE una tarjeta la convierte en
    // hija; soltar en la línea entre dos tarjetas la coloca ahí como hermana.
    // La renumeración la hace el servidor de una vez.
    // ----------------------------------------------------------------------
    function bindDragAndDrop() {
        var tree = root.querySelector('.pp-map-tree');
        if (!tree) return;
        var draggedId = null;

        tree.addEventListener('dragstart', function (event) {
            var card = event.target.closest('.pp-map-card');
            if (!card) return;
            draggedId = card.dataset.pageId;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox no inicia el arrastre sin datos en el dataTransfer.
            event.dataTransfer.setData('text/plain', draggedId);
        });

        tree.addEventListener('dragend', function () {
            draggedId = null;
            tree.querySelectorAll('.is-dragging, .is-drop-into, .is-drop-before').forEach(function (el) {
                el.classList.remove('is-dragging', 'is-drop-into', 'is-drop-before');
            });
        });

        tree.addEventListener('dragover', function (event) {
            if (!draggedId) return;
            var card = event.target.closest('.pp-map-card');
            if (!card || card.dataset.pageId === draggedId) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            var rect = card.getBoundingClientRect();
            var before = (event.clientY - rect.top) < rect.height * 0.35;
            card.classList.toggle('is-drop-before', before);
            card.classList.toggle('is-drop-into', !before);
        });

        tree.addEventListener('dragleave', function (event) {
            var card = event.target.closest('.pp-map-card');
            if (card) card.classList.remove('is-drop-into', 'is-drop-before');
        });

        tree.addEventListener('drop', function (event) {
            if (!draggedId) return;
            var card = event.target.closest('.pp-map-card');
            if (!card || card.dataset.pageId === draggedId) return;
            event.preventDefault();

            var asSibling = card.classList.contains('is-drop-before');
            var targetNode = card.closest('.pp-map-node');
            var payload;

            if (asSibling) {
                var parentNode = targetNode.parentElement.closest('.pp-map-node');
                var siblings = Array.prototype.slice.call(targetNode.parentElement.children)
                    .filter(function (li) { return li.dataset.pageId !== draggedId; });
                payload = {
                    parent_id: parentNode ? parentNode.dataset.pageId : '',
                    position: siblings.indexOf(targetNode)
                };
            } else {
                var childList = targetNode.querySelector(':scope > .pp-map-children');
                payload = {
                    parent_id: card.dataset.pageId,
                    position: childList ? childList.children.length : 0
                };
            }

            postForm('/admin/pages/' + draggedId + '/move', payload, 20000)
                .then(function () { window.location.reload(); })
                .catch(function (err) { showToast(err.message || pp.t('js.map.move_failed'), 'error'); });
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
            renderArchitectError(pp.t('js.map.configure_ai'));
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
            '<p class="pp-studio-note">' + (body.cached ? pp.t('js.studio.saved_analysis') + (body.cached_at ? ' · ' + escapeHtml(formatDate(body.cached_at)) : '') : formatAiMeta(body)) + '</p>',
            diagnostics.length ? '<div class="pp-architect-diagnostics">' + diagnostics.map(diagnosticHtml).join('') + '</div>' : ''
        ].join('');

        renderSuggestions(missing, groups);
    }

    function renderSuggestions(missing, groups) {
        clearGhostNodes();
        if (suggestions) suggestions.innerHTML = '';

        if (!groups.length && !missing.length) {
            if (suggestions) {
                suggestions.innerHTML = '<section class="pp-map-ai-lane pp-map-ai-lane--quiet"><div class="pp-map-ai-lane__head"><strong>' + pp.t('js.map.no_gaps') + '</strong><span>' + pp.t('js.map.no_gaps_help') + '</span></div></section>';
            }
            return;
        }

        injectGhostMissing(missing);

        if (groups.length && suggestions) {
            var html = '<section class="pp-map-ai-lane"><div class="pp-map-ai-lane__head"><strong>' + pp.t('js.map.suggested_branches') + '</strong><span>' + pp.t('js.map.branches_help') + '</span></div><div class="pp-map-ai-branches">';
            html += groups.map(function (g) {
                var payload = {
                    title: g.label || 'Nueva rama',
                    page_type: 'landing',
                    parent_id: '',
                    // i18n-ignore: objetivo que viaja a la IA, no se pinta.
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
        var title = p.title || pp.t('js.map.suggested_page');
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
            title: item.title || pp.t('js.studio.new_page'),
            page_type: item.page_type || 'landing',
            parent_id: item.parent_id || '',
            // i18n-ignore: objetivo que viaja a la IA, no se pinta.
            ai_page_goal: item.goal || item.reason || 'Crear una página útil para esta arquitectura.',
            ai_target_audience: item.audience || '',
            ai_extra_context: item.reason || '',
            architecture_context: item.architecture_context || item.reason || ''
        }, 180000).then(function (body) {
            window.location.href = body.edit_url;
        }).catch(function (err) {
            showToast(err.message || pp.t('js.studio.create_failed'), 'error');
        }).finally(function () {
            setButtonBusy(button, false, button && button.dataset.createChild ? 'Crear hija con IA' : 'Crear con IA');
        });
    }

    function openChildComposer(button) {
        var parentTitle = button.getAttribute('data-parent-title') || pp.t('js.map.this_page');
        var parentId = Number(button.getAttribute('data-create-child') || 0);
        var existing = document.getElementById('pp-child-composer');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.className = 'pp-map-modal';
        modal.id = 'pp-child-composer';
        modal.innerHTML = [
            '<div class="pp-map-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-child-composer-title">',
                '<button type="button" class="pp-map-modal__close" data-close-child-composer aria-label="Cerrar">&times;</button>',
                '<span>' + pp.t('js.map.new_child_page') + '</span>',
                '<h3 id="pp-child-composer-title">' + pp.t('js.map.under', { padre: escapeHtml(parentTitle) }) + '</h3>',
                '<label><span>' + pp.t('js.map.title_or_intent') + '</span><input type="text" id="pp-child-composer-input" autocomplete="off" placeholder="' + pp.t('js.map.child_placeholder') + '"></label>',
                '<div class="pp-map-modal__actions">',
                    '<button type="button" class="pp-btn pp-btn--secondary" data-close-child-composer>' + pp.t('js.common.cancel') + '</button>',
                    '<button type="button" class="pp-btn pp-btn--primary" id="pp-child-composer-create">' + pp.t('js.map.create_with_ai') + '</button>',
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
                    showToast(pp.t('js.map.need_title_or_intent'), 'error');
                    return;
                }
                modal.remove();
                createSuggested({
                    title: title,
                    page_type: 'landing',
                    parent_id: parentId,
                    // i18n-ignore: objetivo y contexto que viajan a la IA.
                    goal: 'Crear una página hija dentro de la arquitectura del sitio.',
                    architecture_context: 'Página hija creada desde el mapa del sitio bajo "' + parentTitle + '".' // i18n-ignore: contexto para la IA.
                }, button);
            });
        }
    }

    function renderInspector(card) {
        if (!inspector) return;
        var id = Number(card.closest('[data-page-id]').getAttribute('data-page-id') || 0);
        var data = card.dataset;
        inspector.innerHTML = [
            // PAGES-OPS G2 — el inspector lleva los mismos `data-page-*` que la
            // tarjeta y la fila: así el menú de acciones funciona también aquí.
            '<div class="pp-map-inspector__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-map-inspector-title"'
                + ' data-page-id="' + escapeHtml(String(id)) + '"'
                + ' data-page-title="' + escapeHtml(data.pageTitle || '') + '"'
                + ' data-page-status="' + escapeHtml(data.pageStatus || 'draft') + '"'
                + ' data-page-type="' + escapeHtml(data.pageType || '') + '">',
                '<button type="button" class="pp-map-inspector__close" data-close-inspector aria-label="Cerrar">&times;</button>',
                '<div class="pp-map-inspector__head">',
                    '<span>' + escapeHtml(data.pageType || pp.t('js.onb.page')) + '</span>',
                    '<strong id="pp-map-inspector-title">' + escapeHtml(data.pageLabel || data.pageTitle || pp.t('js.onb.page')) + '</strong>',
                    '<code>/' + escapeHtml(data.pageSlug || '') + '</code>',
                '</div>',
                breadcrumbHtml(id),
                '<div class="pp-map-inspector__actions">',
                    '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + escapeHtml(data.pageEdit || '#') + '">' + pp.t('js.post_new.edit') + '</a>',
                    '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + escapeHtml(data.pagePreview || '#') + '">Preview</a>',
                    '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-create-child="' + id + '" data-parent-title="' + escapeHtml(data.pageLabel || data.pageTitle || pp.t('js.map.this_page')) + '">' + pp.t('js.map.create_child_ai') + '</button>',
                    '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm pp-page-menu-btn" data-page-menu aria-haspopup="true" aria-expanded="false" aria-label="' + pp.t('js.map.more_actions') + '">⋯</button>',
                '</div>',
                '<form class="pp-map-inspector-form" action="' + escapeHtml(data.pageStructure || '') + '" method="POST">',
                    '<label><span>' + pp.t('js.map.nav_label') + '</span><input type="text" name="nav_label" value="' + escapeHtml(data.pageNav || '') + '" placeholder="' + escapeHtml(data.pageTitle || '') + '"></label>',
                    '<label><span>' + pp.t('js.map.parent') + '</span><select name="parent_id">' + parentOptionsHtml(id, data.pageParent || '') + '</select></label>',
                    '<label><span>' + pp.t('js.map.order') + '</span><input type="number" name="tree_sort_order" min="0" value="' + escapeHtml(data.pageOrder || '0') + '"></label>',
                    '<button type="submit" class="pp-btn pp-btn--primary">' + pp.t('js.map.save_structure') + '</button>',
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
                '<span>' + pp.t('js.map.site_path') + '</span>',
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
        var html = '<option value="">' + pp.t('js.map.root') + '</option>';
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
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                // Sin esta cabecera, los endpoints que comparten camino con el
                // formulario clásico (p. ej. `destroy()`) contestan con un
                // redirect a /admin/pages en vez de JSON: la operación se hace
                // en el servidor, pero aquí revienta el parseo y la pantalla no
                // se refresca. El GET de delete-info ya la mandaba.
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: params.toString(),
            credentials: 'same-origin'
        }, timeoutMs).then(readJson);
    }

    /**
     * Lee la respuesta como JSON y, si no lo es (un HTML de 404, de login
     * caducado o un redirect seguido), lanza un mensaje legible en vez del
     * «Unexpected token '<'» del parser.
     */
    function readJson(res) {
        return res.text().then(function (text) {
            var body;
            try {
                body = JSON.parse(text);
            } catch (e) {
                if (res.status === 404) {
                    var err404 = new Error(pp.t('js.map.page_gone'));
                    err404.gone = true;
                    throw err404;
                }
                throw new Error(pp.t('js.map.not_json', { code: res.status }));
            }
            if (!res.ok || !body.ok) throw new Error(body.error || ('HTTP ' + res.status));
            return body;
        });
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
        options = options || {};
        options.signal = controller.signal;
        return fetch(url, options).catch(function (err) {
            if (err && err.name === 'AbortError') throw new Error(pp.t('js.onb.timeout'));
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
        if (!body || !body.model) return pp.t('js.map.new_analysis');
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
