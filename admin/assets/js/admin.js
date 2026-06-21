/**
 * PromptPress Admin — JS principal
 * Sidebar toggle, overlays, flash messages auto-dismiss.
 */
(function () {
    'use strict';

    var sidebar = document.getElementById('pp-sidebar');
    var toggle  = document.getElementById('pp-sidebar-toggle');
    var overlay = document.getElementById('pp-overlay');

    if (!sidebar || !toggle) return;

    // Toggle sidebar (mobile)
    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('is-open');
        overlay.classList.toggle('is-visible');
        document.body.classList.toggle('pp-sidebar-open');
    });

    // Close sidebar on overlay click
    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            document.body.classList.remove('pp-sidebar-open');
        });
    }

    // Close sidebar on ESC
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-visible');
            document.body.classList.remove('pp-sidebar-open');
        }
    });

    // Auto-dismiss flash alerts after 5s
    var alerts = document.querySelectorAll('.pp-alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function () { alert.remove(); }, 300);
        }, 5000);
    });

    // Sidebar collapse toggle for desktop (optional: persists in localStorage)
    var collapseKey = 'pp_sidebar_collapsed';
    if (localStorage.getItem(collapseKey) === '1') {
        document.body.classList.add('pp-sidebar-collapsed');
    }

    // Double-click logo to toggle collapse
    var brand = document.querySelector('.pp-sidebar__brand');
    if (brand) {
        brand.addEventListener('dblclick', function () {
            document.body.classList.toggle('pp-sidebar-collapsed');
            localStorage.setItem(collapseKey,
                document.body.classList.contains('pp-sidebar-collapsed') ? '1' : '0');
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
