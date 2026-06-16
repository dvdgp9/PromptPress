/**
 * F21.T21.4 — Widget modal reutilizable para buscar e importar imágenes de Unsplash.
 *
 * Uso desde cualquier parte del admin:
 *
 *   window.PPUnsplashPicker.open({
 *     query: 'café restaurante',       // query inicial (opcional)
 *     orientation: 'landscape',         // 'landscape'|'portrait'|'squarish'
 *     onSelect: (media) => {            // se llama tras importar
 *       // media = { id, name, url, path, alt_text, attribution_name, ... }
 *     }
 *   });
 *
 * Reutiliza los endpoints de T18.4:
 *   - GET  /admin/media/bank/search?q=...&orientation=...
 *   - POST /admin/media/bank/import   (csrf + result_id + query + alt)
 *
 * El widget es vanilla JS y solo se inyecta cuando se usa por primera vez.
 * Sin dependencias externas.
 */
(function (global) {
    'use strict';

    let modal = null;
    let currentOptions = null;
    let lastQuery = '';
    let lastOrientation = 'landscape';

    function ensureMount() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'pp-unsplash-modal';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="pp-unsplash-modal__backdrop" data-close></div>
            <div class="pp-unsplash-modal__panel" role="dialog" aria-modal="true" aria-labelledby="pp-unsplash-title">
                <header class="pp-unsplash-modal__head">
                    <div>
                        <span class="pp-unsplash-modal__eyebrow">Banco de imágenes</span>
                        <h3 id="pp-unsplash-title">Buscar en Unsplash</h3>
                    </div>
                    <button type="button" class="pp-unsplash-modal__close" data-close aria-label="Cerrar">×</button>
                </header>
                <form class="pp-unsplash-modal__form" data-search-form autocomplete="off">
                    <input type="search" class="pp-unsplash-modal__input" data-query placeholder="Describe lo que buscas (ej. equipo trabajando, café, oficina moderna)" required minlength="2">
                    <select class="pp-unsplash-modal__orient" data-orientation>
                        <option value="landscape">Horizontal</option>
                        <option value="portrait">Vertical</option>
                        <option value="squarish">Cuadrada</option>
                    </select>
                    <button type="submit" class="pp-btn pp-btn--primary">Buscar</button>
                </form>
                <div class="pp-unsplash-modal__status" data-status aria-live="polite"></div>
                <ul class="pp-unsplash-modal__grid" data-results></ul>
                <p class="pp-unsplash-modal__hint">Las imágenes se descargan a tu sitio con atribución al fotógrafo, conforme a los términos de Unsplash.</p>
            </div>
        `;
        document.body.appendChild(modal);

        // Eventos
        modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
        modal.querySelector('[data-search-form]').addEventListener('submit', (e) => {
            e.preventDefault();
            runSearch();
        });
        modal.querySelector('[data-results]').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-import]');
            if (btn) importImage(btn);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.hidden) close();
        });

        return modal;
    }

    function open(options) {
        options = options || {};
        ensureMount();
        currentOptions = options;

        const qInput = modal.querySelector('[data-query]');
        const oSel   = modal.querySelector('[data-orientation]');
        qInput.value = options.query || lastQuery || '';
        oSel.value = options.orientation || lastOrientation;
        modal.querySelector('[data-results]').innerHTML = '';
        modal.querySelector('[data-status]').textContent = '';

        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        setTimeout(() => qInput.focus(), 30);

        // Si llega con query inicial, búsqueda automática.
        if (qInput.value.trim().length >= 2) runSearch();
    }

    function close() {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
        currentOptions = null;
    }

    function runSearch() {
        const qInput = modal.querySelector('[data-query]');
        const oSel   = modal.querySelector('[data-orientation]');
        const status = modal.querySelector('[data-status]');
        const results = modal.querySelector('[data-results]');
        const q = qInput.value.trim();
        const o = oSel.value;
        if (q.length < 2) return;
        lastQuery = q;
        lastOrientation = o;
        results.innerHTML = '';
        status.textContent = 'Buscando…';
        status.className = 'pp-unsplash-modal__status is-loading';

        const url = baseUrl() + '/admin/media/bank/search?q=' + encodeURIComponent(q) + '&orientation=' + encodeURIComponent(o);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    status.textContent = data.error || 'Error en la búsqueda.';
                    status.className = 'pp-unsplash-modal__status is-error';
                    return;
                }
                if (!data.items || !data.items.length) {
                    status.textContent = 'Sin resultados para "' + q + '". Prueba con otros términos.';
                    status.className = 'pp-unsplash-modal__status';
                    return;
                }
                status.textContent = data.items.length + ' resultados';
                status.className = 'pp-unsplash-modal__status';
                renderResults(data.items);
            })
            .catch(err => {
                status.textContent = 'Error de conexión: ' + err.message;
                status.className = 'pp-unsplash-modal__status is-error';
            });
    }

    function renderResults(items) {
        const results = modal.querySelector('[data-results]');
        results.innerHTML = '';
        items.forEach(item => {
            const li = document.createElement('li');
            li.className = 'pp-unsplash-item';
            li.innerHTML = ''
                + '<figure class="pp-unsplash-item__figure">'
                + '<img loading="lazy" src="' + escAttr(item.preview) + '" alt="' + escAttr(item.alt || item.description) + '">'
                + '<figcaption>'
                +   '<span class="pp-unsplash-item__author">por <a href="' + escAttr(item.profile_url || '') + '?utm_source=promptpress&utm_medium=referral" target="_blank" rel="noopener" onclick="event.stopPropagation()">' + escHtml(item.photographer || 'fotógrafo') + '</a></span>'
                +   '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-import="' + escAttr(item.id) + '" data-alt="' + escAttr(item.alt || item.description || '') + '">Usar esta</button>'
                + '</figcaption>'
                + '</figure>';
            results.appendChild(li);
        });
    }

    function importImage(btn) {
        const id = btn.getAttribute('data-import');
        const alt = btn.getAttribute('data-alt') || '';
        const csrf = getCsrf();
        if (!csrf) { alert('Falta CSRF token. Recarga la página.'); return; }

        btn.disabled = true;
        btn.textContent = 'Importando…';

        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('result_id', id);
        fd.append('query', lastQuery);
        fd.append('orientation', lastOrientation);
        fd.append('alt', alt);

        fetch(baseUrl() + '/admin/media/bank/import', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    btn.disabled = false; btn.textContent = 'Usar esta';
                    alert(data.error || 'No se pudo importar.');
                    return;
                }
                btn.textContent = '✓ Importada';
                // Callback al consumidor
                if (currentOptions && typeof currentOptions.onSelect === 'function') {
                    try { currentOptions.onSelect(data.media); } catch (e) { console.error(e); }
                }
                setTimeout(close, 300);
            })
            .catch(err => {
                btn.disabled = false; btn.textContent = 'Usar esta';
                alert('Error: ' + err.message);
            });
    }

    // Helpers
    function baseUrl() {
        // Buscamos un dataset razonable; si no, asumimos raíz.
        const dataEl = document.querySelector('[data-base-url]');
        if (dataEl && dataEl.dataset.baseUrl) return dataEl.dataset.baseUrl.replace(/\/$/, '');
        return '';
    }
    function getCsrf() {
        const dataEl = document.querySelector('[data-csrf]');
        if (dataEl && dataEl.dataset.csrf) return dataEl.dataset.csrf;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
        const input = document.querySelector('input[name="_csrf"]');
        if (input) return input.value || '';
        return '';
    }
    function escAttr(s) { return String(s == null ? '' : s).replace(/[&"<>]/g, c => ({'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c])); }
    function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

    global.PPUnsplashPicker = { open: open, close: close };
})(window);
