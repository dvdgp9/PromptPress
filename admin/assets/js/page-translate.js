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
        titleEl.textContent = pp.t('js.tr.title_one');
        body.innerHTML = pp.t('js.tr.intro_one.html', {
            idioma: '<strong>' + escapeHtml(pending.label) + '</strong>',
            pagina: '«' + escapeHtml(pending.title) + '»'
        });
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
            pp.t('js.tr.step1'),
            pp.t('js.tr.step2'),
            pp.t('js.tr.step3'),
            pp.t('js.tr.step4')
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
            confirmBtn.textContent = pp.t('js.tr.open_translation');
            confirmBtn.onclick = function () { window.location.href = editUrl; };
            cancelBtn.textContent = pp.t('js.common.close');
        } else {
            confirmBtn.textContent = isError ? 'Volver a intentarlo' : 'Cerrar';
            confirmBtn.onclick = isError ? run : close;
            cancelBtn.textContent = pp.t('js.common.close');
        }
    }

    function run() {
        if (!pending) return;
        confirmBtn.disabled = true;
        confirmBtn.onclick = null;
        actions.hidden = true;
        progress.hidden = false;
        body.innerHTML = pp.t('js.tr.creating', { idioma: '<strong>' + escapeHtml(pending.label) + '</strong>' });
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
                finish('✅ ' + escapeHtml(d.message || pp.t('js.tr.created')), false, d.edit_url);
                if (pending && pending.button) {
                    pending.button.classList.remove('is-missing');
                    pending.button.classList.add('is-done');
                    pending.button.textContent = pending.label + ' · borrador';
                    pending.button.disabled = true;
                }
            } else {
                finish(escapeHtml(d.message || pp.t('js.tr.failed_one')), true, d.edit_url || null);
            }
        }).catch(function () {
            finish(pp.t('js.tr.no_connection'), true, null);
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
        titleEl.textContent = pp.t(job.missing === 1 ? 'js.tr.title_bulk_one' : 'js.tr.title_bulk_other', { n: job.missing });
        confirmBtn.textContent = pp.t('js.tr.translate_n', { n: job.missing });
        confirmBtn.onclick = startBulk;
        cancelBtn.textContent = pp.t('js.common.cancel');
        body.innerHTML = pp.t('js.tr.intro_bulk.html', {
            n: '<strong>' + job.missing + '</strong>',
            idioma: '<strong>' + escapeHtml(job.label) + '</strong>'
        });
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
            if (item.status === 'skipped') li.title = pp.t('js.tr.skipped');
            jobList.appendChild(li);
        });
        var c = state.counts || {};
        var doneish = (c.done || 0) + (c.failed || 0) + (c.skipped || 0);
        progressTxt.textContent = pp.t('js.tr.progress', { hechas: doneish, total: state.total || 0 });
    }

    function finishBulk(state) {
        if (timer) { clearInterval(timer); timer = null; }
        progress.hidden = true;
        actions.hidden = false;
        confirmBtn.disabled = false;
        var c = state.counts || {};
        var parts = [];
        if (c.done)    parts.push(pp.t('js.tr.n_translated', { n: '<strong>' + c.done + '</strong>' }));
        if (c.skipped) parts.push(pp.t('js.tr.n_already', { n: c.skipped }));
        if (c.failed)  parts.push(pp.t('js.tr.n_failed', { n: c.failed }));
        body.innerHTML = pp.t('js.tr.finished.html', { resumen: parts.join(' · ') })
            + (c.failed ? '<br><br>' + pp.t('js.tr.retry_hint') : '');
        confirmBtn.textContent = pp.t('js.tr.see_pages');
        confirmBtn.onclick = function () { window.location.reload(); };
        cancelBtn.textContent = pp.t('js.common.close');
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
            finish(pp.t('js.tr.lost_connection'), true, null);
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
            finish(pp.t('js.tr.cant_start'), true, null);
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
