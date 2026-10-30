/**
 * ASST-V2 F2 — Panel lateral del asistente.
 *
 * - Se abre con el botón flotante, con Ctrl/⌘ K o con cualquier elemento
 *   [data-assistant-open] (opcional data-assistant-prompt para precargar texto
 *   y data-assistant-send para enviarlo directamente).
 * - La primera vez carga los scripts del chat y la conversación en curso; hasta
 *   entonces enseña un esqueleto del hilo.
 * - Recuerda si estaba abierto al cambiar de pantalla (sessionStorage).
 * - Con el panel cerrado, el botón avisa de que el asistente está trabajando o
 *   de que hay respuesta nueva.
 */
(function () {
    'use strict';

    const drawer = document.getElementById('ppa-drawer');
    const launcher = document.getElementById('ppa-launcher');
    if (!drawer || !launcher) return;

    const cfg = window.PPA || {};
    const badge = document.getElementById('ppa-launcher-badge');
    const launcherStatus = document.getElementById('ppa-launcher-status');
    const skeleton = document.getElementById('ppa-drawer-skeleton');
    const contextBox = document.getElementById('ppa-context');
    const contextValue = document.getElementById('ppa-context-value');
    const OPEN_KEY = 'ppa-drawer-open';
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

    let loading = null;
    let ready = false;
    let pendingPrompt = '';
    let pendingSend = false;
    let lastFocus = null;

    // Dentro del panel (que se mueve con transform) el selector de medios
    // quedaría encerrado en sus 440px: se saca a <body> como una capa más.
    const picker = document.getElementById('ppa-media-picker');
    if (picker && drawer.contains(picker)) document.body.appendChild(picker);

    const kbd = launcher.querySelector('.ppa-launcher__kbd');
    if (kbd) kbd.textContent = isMac ? kbd.dataset.mac : kbd.dataset.other;

    function remember(open) {
        try { window.sessionStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch (e) { /* modo privado */ }
    }
    function wasOpen() {
        try { return window.sessionStorage.getItem(OPEN_KEY) === '1'; } catch (e) { return false; }
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.onload = resolve;
            script.onerror = () => reject(new Error(src));
            document.body.appendChild(script);
        });
    }

    function ensureLoaded() {
        if (loading) return loading;
        let assets = [];
        try { assets = JSON.parse(drawer.dataset.assets || '[]'); } catch (e) { assets = []; }
        loading = assets.reduce((chain, src) => chain.then(() => loadScript(src)), Promise.resolve())
            .catch(() => {
                if (skeleton) {
                    skeleton.classList.add('is-error');
                    skeleton.textContent = pp.t('js.as.drawer_load_failed');
                }
            });
        return loading;
    }

    function renderContext() {
        if (!contextBox) return;
        const ctx = cfg.context;
        contextBox.hidden = !ctx;
        if (ctx && contextValue) contextValue.textContent = ctx.label;
    }

    function focusComposer() {
        if (window.PPAssistant) window.PPAssistant.focus();
    }

    function open(options) {
        const opts = options || {};
        if (opts.prompt) {
            pendingPrompt = opts.prompt;
            pendingSend = !!opts.send;
        }
        if (!drawer.hidden && drawer.classList.contains('is-open')) {
            applyPendingPrompt();
            focusComposer();
            return;
        }
        lastFocus = document.activeElement;
        drawer.hidden = false;
        // Un frame para que la transición arranque desde fuera de la pantalla.
        window.requestAnimationFrame(() => {
            drawer.classList.add('is-open');
            document.body.classList.add('has-ppa-drawer');
        });
        launcher.setAttribute('aria-expanded', 'true');
        clearBadge();
        remember(true);
        ensureLoaded().then(() => {
            if (ready) {
                applyPendingPrompt();
                focusComposer();
            }
        });
    }

    function close() {
        drawer.classList.remove('is-open');
        document.body.classList.remove('has-ppa-drawer');
        launcher.setAttribute('aria-expanded', 'false');
        remember(false);
        const finish = () => { if (!drawer.classList.contains('is-open')) drawer.hidden = true; };
        if (reduceMotion) finish();
        else window.setTimeout(finish, 260);
        const target = lastFocus && document.contains(lastFocus) ? lastFocus : launcher;
        if (target && typeof target.focus === 'function') target.focus();
    }

    function toggle() {
        if (drawer.classList.contains('is-open')) close();
        else open();
    }

    function applyPendingPrompt() {
        if (!pendingPrompt || !window.PPAssistant) return;
        if (pendingSend && typeof window.PPAssistant.send === 'function') window.PPAssistant.send(pendingPrompt);
        else window.PPAssistant.prefill(pendingPrompt);
        pendingPrompt = '';
        pendingSend = false;
    }

    function clearBadge() {
        if (badge) badge.hidden = true;
        launcher.classList.remove('has-news');
    }

    // --- Eventos del chat (assistant.js) ----------------------------------
    document.addEventListener('ppa:ready', () => {
        ready = true;
        if (skeleton) skeleton.remove();
        drawer.classList.add('is-ready');
        // `hidden` y no `is-open`: esa clase llega un frame después y, con la
        // pestaña en segundo plano, ese frame puede no llegar nunca; la
        // petición de «Arreglar» se perdería.
        if (!drawer.hidden) {
            applyPendingPrompt();
            focusComposer();
        }
    });
    document.addEventListener('ppa:busy', (event) => {
        const busy = !!(event.detail && event.detail.busy);
        launcher.classList.toggle('is-busy', busy);
        if (launcherStatus) launcherStatus.textContent = busy ? pp.t('js.as.drawer_working') : '';
    });
    document.addEventListener('ppa:news', () => {
        if (drawer.classList.contains('is-open')) return;
        launcher.classList.add('has-news');
        if (badge) badge.hidden = false;
        if (launcherStatus) launcherStatus.textContent = pp.t('js.as.drawer_news');
    });

    // --- Controles ---------------------------------------------------------
    launcher.addEventListener('click', toggle);
    const closeBtn = document.getElementById('ppa-drawer-close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    const newBtn = document.getElementById('ppa-drawer-new');
    if (newBtn) {
        newBtn.addEventListener('click', () => {
            if (window.PPAssistant) window.PPAssistant.reset();
        });
    }
    const clearCtx = document.getElementById('ppa-context-clear');
    if (clearCtx) {
        clearCtx.addEventListener('click', () => {
            cfg.context = null;
            renderContext();
            focusComposer();
        });
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-assistant-open]');
        if (!trigger) return;
        event.preventDefault();
        open({ prompt: trigger.dataset.assistantPrompt || '', send: trigger.hasAttribute('data-assistant-send') });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            toggle();
            return;
        }
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
            const picker = document.getElementById('ppa-media-picker');
            if (picker && !picker.hidden) return; // lo cierra el propio selector
            if (drawer.contains(document.activeElement) || document.activeElement === document.body) {
                event.preventDefault();
                close();
            }
        }
    });

    renderContext();
    if (wasOpen()) open();
})();
