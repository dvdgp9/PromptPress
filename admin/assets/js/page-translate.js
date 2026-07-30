/**
 * I18N-FULL T5.3 — Traducir una página desde el listado.
 *
 * Prioridad: que alguien no técnico entienda en todo momento qué va a pasar,
 * qué está pasando y qué ha pasado.
 *
 *  - ANTES: se dice explícitamente que se crea una copia en borrador y que la
 *    página original no se toca. Nadie debería pulsar sin saberlo.
 *  - DURANTE: la traducción es una llamada a IA y puede tardar bastante. Se
 *    avisa de que puede tardar y el botón queda deshabilitado, para que nadie
 *    piense que se ha colgado ni lo pulse dos veces.
 *  - DESPUÉS: se ofrece abrir la traducción directamente. Si algo falla, el
 *    mensaje viene del servidor ya escrito en cristiano.
 */
(function () {
    'use strict';

    var overlay = document.getElementById('pp-tr-overlay');
    if (!overlay) return;

    var body        = document.getElementById('pp-tr-body');
    var progress    = document.getElementById('pp-tr-progress');
    var progressTxt = document.getElementById('pp-tr-progress-text');
    var actions     = document.getElementById('pp-tr-actions');
    var confirmBtn  = overlay.querySelector('[data-pp-tr-confirm]');
    var cancelBtn   = overlay.querySelector('[data-pp-tr-cancel]');
    var titleEl     = document.getElementById('pp-tr-title');
    var jobList     = document.getElementById('pp-tr-joblist');
    var pending     = null;
    var timer       = null;
    var job         = null;   // trabajo de traducción masiva en curso

    function csrf() {
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function open(btn) {
        pending = {
            pageId: btn.getAttribute('data-pp-translate'),
            lang:   btn.getAttribute('data-pp-lang'),
            label:  btn.getAttribute('data-pp-lang-label'),
            title:  btn.getAttribute('data-pp-page-title'),
            button: btn
        };
        titleEl.textContent = 'Traducir página';
        body.innerHTML = 'Vamos a crear la versión en <strong>' + escapeHtml(pending.label) + '</strong> de '
            + '«' + escapeHtml(pending.title) + '».<br><br>'
            + 'Se guardará como <strong>borrador</strong> para que la revises antes de publicarla, y '
            + '<strong>tu página actual no cambia</strong>.';
        progress.hidden = true;
        actions.hidden = false;
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Traducir';
        overlay.hidden = false;
        confirmBtn.focus();
    }

    function close() {
        overlay.hidden = true;
        if (timer) { clearInterval(timer); timer = null; }
        pending = null;
        job = null;
        if (jobList) { jobList.hidden = true; jobList.innerHTML = ''; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Mensajes que van cambiando: una espera larga en silencio parece un cuelgue. */
    function startProgress() {
        var steps = [
            'Traduciendo la página…',
            'Adaptando los textos al idioma…',
            'Revisando enlaces y estructura…',
            'Casi está, un momento más…'
        ];
        var i = 0;
        progressTxt.textContent = steps[0];
        timer = setInterval(function () {
            i = Math.min(i + 1, steps.length - 1);
            progressTxt.textContent = steps[i];
        }, 7000);
    }

    function finish(html, isError, editUrl) {
        if (timer) { clearInterval(timer); timer = null; }
        progress.hidden = true;
        actions.hidden = false;
        body.innerHTML = html;
        confirmBtn.disabled = false;

        if (editUrl) {
            confirmBtn.textContent = 'Abrir la traducción';
            confirmBtn.onclick = function () { window.location.href = editUrl; };
            cancelBtn.textContent = 'Cerrar';
        } else {
            confirmBtn.textContent = isError ? 'Volver a intentarlo' : 'Cerrar';
            confirmBtn.onclick = isError ? run : close;
            cancelBtn.textContent = 'Cerrar';
        }
    }

    function run() {
        if (!pending) return;
        confirmBtn.disabled = true;
        confirmBtn.onclick = null;
        actions.hidden = true;
        progress.hidden = false;
        body.innerHTML = 'Estamos creando la versión en <strong>' + escapeHtml(pending.label) + '</strong>. '
            + 'Puede tardar hasta un minuto: no cierres esta ventana.';
        startProgress();

        var form = new FormData();
        form.append('_csrf', csrf());
        form.append('language', pending.lang);

        var base = overlay.getAttribute('data-pp-pages-url') || '';
        fetch(base + '/' + pending.pageId + '/translate', {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (data) { return { status: r.status, data: data }; });
        }).then(function (res) {
            var d = res.data || {};
            if (d.ok) {
                finish('✅ ' + escapeHtml(d.message || 'Traducción creada.'), false, d.edit_url);
                if (pending && pending.button) {
                    pending.button.classList.remove('is-missing');
                    pending.button.classList.add('is-done');
                    pending.button.textContent = pending.label + ' · borrador';
                    pending.button.disabled = true;
                }
            } else {
                finish(escapeHtml(d.message || 'No hemos podido traducir esta página.'), true, d.edit_url || null);
            }
        }).catch(function () {
            finish('No hemos podido conectar para traducir la página. No se ha guardado nada; '
                 + 'comprueba tu conexión y vuelve a intentarlo.', true, null);
        });
    }

    /* ---------------------------------------------------------------
       Traducción de varias páginas (T5.5)

       El navegador pide UNA página por petición. Así ninguna petición se
       eterniza, se ve el avance real y un fallo suelto no tira el resto.
       --------------------------------------------------------------- */

    function openBulk(btn) {
        job = {
            lang:    btn.getAttribute('data-pp-translate-all'),
            label:   btn.getAttribute('data-pp-lang-label'),
            missing: parseInt(btn.getAttribute('data-pp-missing'), 10) || 0,
            id:      null,
            done:    0
        };
        pending = null;
        jobList.hidden = true;
        jobList.innerHTML = '';
        progress.hidden = true;
        actions.hidden = false;
        confirmBtn.disabled = false;
        titleEl.textContent = 'Traducir ' + job.missing + (job.missing === 1 ? ' página' : ' páginas');
        confirmBtn.textContent = 'Traducir las ' + job.missing;
        confirmBtn.onclick = startBulk;
        cancelBtn.textContent = 'Cancelar';
        body.innerHTML = 'Vamos a traducir <strong>' + job.missing + ' páginas</strong> a '
            + '<strong>' + escapeHtml(job.label) + '</strong>.<br><br>'
            + 'Cada una se guardará como <strong>borrador</strong> para que la revises, y '
            + '<strong>tus páginas actuales no cambian</strong>.<br><br>'
            + 'Tardará unos minutos. <strong>No cierres esta ventana</strong> mientras avanza: '
            + 'si la cierras, se quedará donde iba (lo ya traducido no se pierde).';
        overlay.hidden = false;
        confirmBtn.focus();
    }

    function renderJob(state) {
        jobList.hidden = false;
        jobList.innerHTML = '';
        (state.items || []).forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'pp-tr-jobitem is-' + item.status;
            var icon = { done: '✅', failed: '⚠️', skipped: '↷', running: '⏳', pending: '·' }[item.status] || '·';
            li.textContent = icon + ' ' + item.title;
            if (item.status === 'failed' && item.error) li.title = item.error;
            if (item.status === 'skipped') li.title = 'Ya tenía versión en este idioma: no la hemos tocado.';
            jobList.appendChild(li);
        });
        var c = state.counts || {};
        var doneish = (c.done || 0) + (c.failed || 0) + (c.skipped || 0);
        progressTxt.textContent = 'Traduciendo… ' + doneish + ' de ' + (state.total || 0);
    }

    function finishBulk(state) {
        if (timer) { clearInterval(timer); timer = null; }
        progress.hidden = true;
        actions.hidden = false;
        confirmBtn.disabled = false;
        var c = state.counts || {};
        var parts = [];
        if (c.done)    parts.push('<strong>' + c.done + '</strong> traducidas');
        if (c.skipped) parts.push(c.skipped + ' ya estaban');
        if (c.failed)  parts.push(c.failed + ' no se han podido traducir');
        body.innerHTML = 'Trabajo terminado: ' + parts.join(' · ') + '.<br><br>'
            + 'Están todas guardadas como <strong>borrador</strong>: revísalas y publícalas cuando quieras.'
            + (c.failed ? '<br><br>Las que han fallado puedes traducirlas de una en una desde su fila.' : '');
        confirmBtn.textContent = 'Ver las páginas';
        confirmBtn.onclick = function () { window.location.reload(); };
        cancelBtn.textContent = 'Cerrar';
    }

    function stepBulk() {
        var base = overlay.getAttribute('data-pp-pages-url') || '';
        var form = new FormData();
        form.append('_csrf', csrf());

        fetch(base + '/translate-job/' + job.id + '/step', {
            method: 'POST', body: form, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok || !d.job) {
                finish(escapeHtml((d && d.message) || 'El trabajo se ha interrumpido.'), true, null);
                return;
            }
            renderJob(d.job);
            if (d.job.status === 'done') { finishBulk(d.job); return; }
            stepBulk();
        }).catch(function () {
            finish('Se ha perdido la conexión a mitad del trabajo. Lo ya traducido está guardado; '
                 + 'puedes volver a lanzarlo para continuar con el resto.', true, null);
        });
    }

    function startBulk() {
        confirmBtn.disabled = true;
        actions.hidden = true;
        progress.hidden = false;
        progressTxt.textContent = 'Preparando…';
        body.innerHTML = 'Traduciendo tu web a <strong>' + escapeHtml(job.label) + '</strong>. '
            + 'No cierres esta ventana.';

        var base = overlay.getAttribute('data-pp-pages-url') || '';
        var form = new FormData();
        form.append('_csrf', csrf());
        form.append('language', job.lang);

        fetch(base + '/translate-all', {
            method: 'POST', body: form, credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { finish(escapeHtml(d.message || 'No hemos podido iniciar el trabajo.'), true, null); return; }
            job.id = d.job_id;
            renderJob(d.job);
            stepBulk();
        }).catch(function () {
            finish('No hemos podido iniciar la traducción. No se ha creado nada.', true, null);
        });
    }

    document.addEventListener('click', function (ev) {
        var bulk = ev.target.closest ? ev.target.closest('[data-pp-translate-all]') : null;
        if (bulk) { ev.preventDefault(); openBulk(bulk); return; }
        var btn = ev.target.closest ? ev.target.closest('[data-pp-translate]') : null;
        if (btn) { ev.preventDefault(); open(btn); return; }
        if (ev.target === overlay) close();
    });

    cancelBtn.addEventListener('click', close);
    confirmBtn.addEventListener('click', function () { if (pending && !job) run(); });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !overlay.hidden) close();
    });
})();
