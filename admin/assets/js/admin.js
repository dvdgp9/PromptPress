/**
 * PromptPress Admin — JS principal
 * Sidebar toggle, overlays, flash messages auto-dismiss.
 */

/**
 * ADMIN-I18N — `pp.t` vive en `pp-i18n.js`, que este layout carga ANTES que
 * este fichero: lo necesitan también las pantallas standalone (Canvas Studio),
 * que no cargan admin.js.
 */

(function () {
    'use strict';

    var sidebar = document.getElementById('pp-sidebar');
    var toggle  = document.getElementById('pp-sidebar-toggle');
    var overlay = document.getElementById('pp-overlay');
    var collapseControl = document.getElementById('pp-sidebar-collapse');

    if (!sidebar || !toggle) return;

    var mobileSidebarMedia = window.matchMedia('(max-width: 768px)');
    var setMobileSidebarOpen = function (open, returnFocus) {
        var shouldOpen = open && mobileSidebarMedia.matches;
        sidebar.classList.toggle('is-open', shouldOpen);
        if (overlay) overlay.classList.toggle('is-visible', shouldOpen);
        document.body.classList.toggle('pp-sidebar-open', shouldOpen);
        toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

        if (shouldOpen) {
            window.requestAnimationFrame(function () {
                var mobileFocusTarget = sidebar.querySelector('[aria-current="page"]')
                    || sidebar.querySelector('[data-pp-nav-group-active="1"]')
                    || sidebar.querySelector('a, button');
                if (mobileFocusTarget) mobileFocusTarget.focus();
            });
        } else if (returnFocus) {
            toggle.focus();
        }
    };

    // Drawer móvil: un único estado sincroniza panel, overlay, body y ARIA.
    toggle.addEventListener('click', function () {
        setMobileSidebarOpen(!sidebar.classList.contains('is-open'), false);
    });

    if (overlay) {
        overlay.addEventListener('click', function () {
            setMobileSidebarOpen(false, true);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
            setMobileSidebarOpen(false, true);
        }
    });

    sidebar.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (mobileSidebarMedia.matches) setMobileSidebarOpen(false, false);
        });
    });

    if (typeof mobileSidebarMedia.addEventListener === 'function') {
        mobileSidebarMedia.addEventListener('change', function (event) {
            if (!event.matches) setMobileSidebarOpen(false, false);
        });
    }

    // ADMIN-NAV N2 — acordeón progresivo. El servidor abre el grupo activo;
    // JS permite explorar otro, mantiene solo uno abierto y recuerda la última
    // elección cuando la ruta actual es Inicio o no pertenece a la navegación.
    var navGroupKey = 'pp_sidebar_group';
    var navGroupToggles = Array.from(document.querySelectorAll('[data-pp-nav-group-toggle]'));
    var setNavGroupOpen = function (groupToggle, open) {
        var panelId = groupToggle.getAttribute('aria-controls');
        var panel = panelId ? document.getElementById(panelId) : null;
        groupToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (panel) panel.hidden = !open;
    };
    var closeOtherNavGroups = function (currentToggle) {
        navGroupToggles.forEach(function (groupToggle) {
            if (groupToggle !== currentToggle) setNavGroupOpen(groupToggle, false);
        });
    };
    var readPreferredNavGroup = function () {
        try { return localStorage.getItem(navGroupKey) || ''; }
        catch (error) { return ''; }
    };
    var savePreferredNavGroup = function (groupKey) {
        try {
            if (groupKey) localStorage.setItem(navGroupKey, groupKey);
            else localStorage.removeItem(navGroupKey);
        } catch (error) {
            // El acordeón sigue funcionando aunque el navegador bloquee storage.
        }
    };

    if (navGroupToggles.length > 0) {
        var activeNavGroup = document.querySelector('[data-pp-nav-group-active="1"]');
        if (activeNavGroup) {
            closeOtherNavGroups(activeNavGroup);
            setNavGroupOpen(activeNavGroup, true);
        } else {
            var preferredNavGroup = readPreferredNavGroup();
            var preferredToggle = navGroupToggles.find(function (groupToggle) {
                var group = groupToggle.closest('[data-pp-nav-group]');
                return group && group.getAttribute('data-pp-nav-group') === preferredNavGroup;
            });
            if (preferredToggle) {
                closeOtherNavGroups(preferredToggle);
                setNavGroupOpen(preferredToggle, true);
            }
        }

        navGroupToggles.forEach(function (groupToggle) {
            groupToggle.addEventListener('click', function () {
                var willOpen = groupToggle.getAttribute('aria-expanded') !== 'true';
                closeOtherNavGroups(groupToggle);
                setNavGroupOpen(groupToggle, willOpen);
                var group = groupToggle.closest('[data-pp-nav-group]');
                savePreferredNavGroup(willOpen && group ? group.getAttribute('data-pp-nav-group') : '');
            });
        });
    }

    // Auto-dismiss flash alerts after 5s.
    //
    // Solo los mensajes de "ya está hecho": un aviso PERMANENTE (una condición
    // del sitio que sigue ahí, como no tener el correo configurado) se marca con
    // `data-pp-persist` y no se toca. Sin esa marca, el aviso se pintaba y a los
    // cinco segundos desaparecía, que es peor que no ponerlo.
    var alerts = document.querySelectorAll('.pp-alert:not([data-pp-persist])');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function () { alert.remove(); }, 300);
        }, 5000);
    });

    // Modo compacto de escritorio: control explícito, estado persistente y
    // etiqueta que describe siempre la acción disponible.
    var collapseKey = 'pp_sidebar_collapsed';
    var readSidebarCollapsed = function () {
        try { return localStorage.getItem(collapseKey) === '1'; }
        catch (error) { return false; }
    };
    var saveSidebarCollapsed = function (collapsed) {
        try { localStorage.setItem(collapseKey, collapsed ? '1' : '0'); }
        catch (error) {
            // El control sigue funcionando durante la visita sin persistencia.
        }
    };
    var setSidebarCollapsed = function (collapsed, persist) {
        document.body.classList.toggle('pp-sidebar-collapsed', collapsed);
        if (!collapseControl) return;

        var nextLabel = collapseControl.getAttribute(collapsed ? 'data-label-expand' : 'data-label-collapse') || '';
        collapseControl.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        collapseControl.setAttribute('aria-label', nextLabel);
        var visibleLabel = collapseControl.querySelector('.pp-sidebar__collapse-label');
        var visualTooltip = collapseControl.querySelector('.pp-nav-visual-tooltip');
        if (visibleLabel) visibleLabel.textContent = nextLabel;
        if (visualTooltip) visualTooltip.textContent = nextLabel;
        if (persist) saveSidebarCollapsed(collapsed);
    };

    setSidebarCollapsed(readSidebarCollapsed(), false);
    if (collapseControl) {
        collapseControl.addEventListener('click', function () {
            setSidebarCollapsed(!document.body.classList.contains('pp-sidebar-collapsed'), true);
        });
    }

    // Mensajes: filtros AJAX con URL compartible y fallback GET sin JavaScript.
    var inboxRequest = null;
    var loadInbox = function (url, pushState) {
        var inbox = document.querySelector('.pp-inbox');
        if (!inbox) return;
        if (inboxRequest) inboxRequest.abort();
        inboxRequest = new AbortController();
        inbox.classList.add('is-loading');

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: inboxRequest.signal
        }).then(function (response) {
            if (!response.ok) throw new Error('No se pudieron cargar los mensajes.');
            return response.text();
        }).then(function (html) {
            var page = new DOMParser().parseFromString(html, 'text/html');
            var nextInbox = page.querySelector('.pp-inbox');
            // i18n-ignore: se captura abajo y nunca se pinta — si la respuesta
            // no trae bandeja, se recarga la página entera.
            if (!nextInbox) throw new Error('Respuesta de mensajes no válida.');
            inbox.replaceWith(nextInbox);
            if (pushState) window.history.pushState({ inbox: true }, '', url);
        }).catch(function (error) {
            if (error.name !== 'AbortError') window.location.assign(url);
        }).finally(function () {
            inboxRequest = null;
        });
    };

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-inbox-filters]');
        if (!form) return;
        event.preventDefault();
        var query = new URLSearchParams(new FormData(form));
        query.delete('page');
        Array.from(query.entries()).forEach(function (entry) {
            if (entry[1] === '' || entry[1] === '0') query.delete(entry[0]);
        });
        loadInbox(form.action + (query.toString() ? '?' + query.toString() : ''), true);
    });

    document.addEventListener('change', function (event) {
        var form = event.target.closest('[data-inbox-filters]');
        if (!form || (!event.target.matches('select') && !event.target.matches('input[type="date"]'))) return;
        form.requestSubmit();
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.pp-inbox-status a, .pp-inbox-clear, .pp-inbox .pp-pagination a, .pp-inbox-empty a');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        loadInbox(link.href, true);
    });

    window.addEventListener('popstate', function () {
        if (document.querySelector('.pp-inbox')) loadInbox(window.location.href, false);
    });
})();
