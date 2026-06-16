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
})();
