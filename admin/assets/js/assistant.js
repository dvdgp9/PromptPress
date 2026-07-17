/**
 * FEAT-5 F5-T1 — Asistente central del sitio: composer del chat + adjunto.
 *
 * En este hito: extraer texto del documento adjunto (POST /admin/assistant/extract)
 * y montar los mensajes en el hilo. La generación del PLAN (F5-T2/T3) sustituirá
 * el stub de respuesta de sendRequest().
 */
(function () {
    'use strict';

    const cfg = window.PPA || {};
    const thread = document.getElementById('ppa-thread');
    const input = document.getElementById('ppa-input');
    const sendBtn = document.getElementById('ppa-send');
    const attachBtn = document.getElementById('ppa-attach-btn');
    const fileInput = document.getElementById('ppa-file-input');
    const attachment = document.getElementById('ppa-attachment');
    const attachmentName = document.getElementById('ppa-attachment-name');
    const attachmentMeta = document.getElementById('ppa-attachment-meta');
    const attachmentToggle = document.getElementById('ppa-attachment-toggle');
    const attachmentRemove = document.getElementById('ppa-attachment-remove');
    const attachmentPreview = document.getElementById('ppa-attachment-preview');

    /** Documento extraído pendiente de enviar: {filename, chars, truncated, text} | null */
    let attachedDoc = null;
    let extracting = false;

    // ------------------------------------------------------------------
    // Hilo de mensajes
    // ------------------------------------------------------------------
    function addMessage(role, text, extraClass) {
        const msg = document.createElement('div');
        msg.className = 'ppa-msg ppa-msg--' + role + (extraClass ? ' ' + extraClass : '');
        const bubble = document.createElement('div');
        bubble.className = 'ppa-msg__bubble';
        bubble.textContent = text;
        msg.appendChild(bubble);
        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
        return bubble;
    }

    // ------------------------------------------------------------------
    // Adjunto: subir + extraer texto
    // ------------------------------------------------------------------
    function setAttachment(doc) {
        attachedDoc = doc;
        if (doc) {
            attachmentName.textContent = doc.filename;
            attachmentMeta.textContent = doc.chars.toLocaleString('es-ES') + ' caracteres extraídos'
                + (doc.truncated ? ' (recortado)' : '');
            attachment.hidden = false;
        } else {
            attachment.hidden = true;
            attachmentPreview.hidden = true;
            attachmentToggle.textContent = 'Ver texto';
            fileInput.value = '';
        }
        refreshSendState();
    }

    function refreshSendState() {
        const hasContent = input.value.trim() !== '' || attachedDoc !== null;
        sendBtn.disabled = !hasContent || extracting || planning;
    }

    async function extractFile(file) {
        if (file.size > cfg.maxSize) {
            addMessage('assistant', 'Ese archivo supera los ' + Math.round(cfg.maxSize / 1048576) + ' MB permitidos.', 'ppa-msg--error');
            fileInput.value = '';
            return;
        }
        extracting = true;
        refreshSendState();
        attachBtn.classList.add('is-busy');
        attachmentName.textContent = file.name;
        attachmentMeta.textContent = 'Extrayendo texto…';
        attachment.hidden = false;

        const form = new FormData();
        form.append('_csrf', cfg.csrf);
        form.append('file', file);

        try {
            const res = await fetch(cfg.baseUrl + '/extract', { method: 'POST', body: form });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                setAttachment(null);
                addMessage('assistant', data.error || 'No se pudo procesar el documento. Inténtalo de nuevo.', 'ppa-msg--error');
                return;
            }
            setAttachment({
                filename: data.filename,
                chars: data.chars,
                truncated: !!data.truncated,
                text: data.text
            });
            attachmentPreview.textContent = data.text;
        } catch (err) {
            setAttachment(null);
            addMessage('assistant', 'Error de red al subir el documento. Inténtalo de nuevo.', 'ppa-msg--error');
        } finally {
            extracting = false;
            attachBtn.classList.remove('is-busy');
            refreshSendState();
        }
    }

    // ------------------------------------------------------------------
    // F5-T3 — Render del plan propuesto
    // ------------------------------------------------------------------
    const STATUS_META = {
        aplicar:   { label: 'Se aplicará',      cls: 'ppa-item--ok' },
        ambiguo:   { label: 'Necesito aclarar', cls: 'ppa-item--ask' },
        no_viable: { label: 'No viable',        cls: 'ppa-item--no' }
    };

    function renderPlan(plan) {
        const msg = document.createElement('div');
        msg.className = 'ppa-msg ppa-msg--assistant';
        const bubble = document.createElement('div');
        bubble.className = 'ppa-msg__bubble ppa-msg__bubble--plan';
        msg.appendChild(bubble);

        if (plan.summary) {
            const p = document.createElement('p');
            p.className = 'ppa-plan__summary';
            p.textContent = plan.summary;
            bubble.appendChild(p);
        }

        const items = plan.items || [];
        const order = ['aplicar', 'ambiguo', 'no_viable'];
        order.forEach((status) => {
            items.filter((it) => it.status === status).forEach((it) => {
                const meta = STATUS_META[status];
                const card = document.createElement('div');
                card.className = 'ppa-item ' + meta.cls;

                const head = document.createElement('div');
                head.className = 'ppa-item__head';
                const badge = document.createElement('span');
                badge.className = 'ppa-item__badge';
                badge.textContent = meta.label;
                head.appendChild(badge);
                const title = document.createElement('span');
                title.className = 'ppa-item__page';
                title.textContent = it.page_title
                    ? it.page_title + (it.section ? ' · sección «' + it.section + '»' : '')
                    : 'Fuera de las páginas';
                head.appendChild(title);
                card.appendChild(head);

                const body = document.createElement('div');
                body.className = 'ppa-item__body';
                body.textContent = status === 'aplicar' ? it.instruction : (it.reason || it.instruction);
                card.appendChild(body);

                bubble.appendChild(card);
            });
        });

        if (items.length === 0) {
            const p = document.createElement('p');
            p.textContent = 'No he identificado ningún cambio concreto que aplicar.';
            bubble.appendChild(p);
        }

        const applicable = items.filter((it) => it.status === 'aplicar');
        if (applicable.length > 0) {
            const foot = document.createElement('div');
            foot.className = 'ppa-plan__foot';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pp-btn pp-btn--primary ppa-plan__apply';
            btn.textContent = 'Aplicar ' + applicable.length + (applicable.length === 1 ? ' cambio' : ' cambios');
            btn.addEventListener('click', () => applyPlan(plan, applicable, btn));
            foot.appendChild(btn);
            const note = document.createElement('span');
            note.className = 'ppa-plan__note';
            note.textContent = 'Los cambios quedan como borrador; nada se publica solo.';
            foot.appendChild(note);
            bubble.appendChild(foot);
        }

        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
    }

    // ------------------------------------------------------------------
    // F5-T4/T5 — Ejecución del plan confirmado, con progreso e informe
    // ------------------------------------------------------------------
    const ITEM_STATUS = {
        pending: { icon: '·', label: 'En cola' },
        running: { icon: '⏳', label: 'Aplicando…' },
        done:    { icon: '✓', label: 'Hecho' },
        failed:  { icon: '✗', label: 'Falló' }
    };

    function itemLabel(it) {
        return it.page_title + (it.section ? ' · «' + it.section + '»' : '');
    }

    function renderProgress(job, container) {
        container.textContent = '';
        const title = document.createElement('div');
        title.className = 'ppa-progress__title';
        title.textContent = job.status === 'done'
            ? 'Cambios aplicados (' + job.completed + '/' + job.total + ')'
            : 'Aplicando cambios… (' + job.completed + '/' + job.total + ')';
        container.appendChild(title);

        job.items.forEach((it) => {
            const row = document.createElement('div');
            row.className = 'ppa-progress__row ppa-progress__row--' + it.status;
            const icon = document.createElement('span');
            icon.className = 'ppa-progress__icon';
            icon.textContent = ITEM_STATUS[it.status].icon;
            row.appendChild(icon);
            const label = document.createElement('span');
            label.className = 'ppa-progress__label';
            label.textContent = itemLabel(it);
            row.appendChild(label);
            const state = document.createElement('span');
            state.className = 'ppa-progress__state';
            state.textContent = ITEM_STATUS[it.status].label;
            row.appendChild(state);
            container.appendChild(row);
        });
    }

    function renderReport(job) {
        const msg = document.createElement('div');
        msg.className = 'ppa-msg ppa-msg--assistant';
        const bubble = document.createElement('div');
        bubble.className = 'ppa-msg__bubble ppa-msg__bubble--plan';
        msg.appendChild(bubble);

        const doneItems = job.items.filter((it) => it.status === 'done');
        const failedItems = job.items.filter((it) => it.status === 'failed');

        const p = document.createElement('p');
        p.className = 'ppa-plan__summary';
        p.textContent = failedItems.length === 0
            ? 'Listo: he aplicado ' + doneItems.length + (doneItems.length === 1 ? ' cambio.' : ' cambios.')
            : 'He aplicado ' + doneItems.length + ' de ' + job.items.length + ' cambios; ' + failedItems.length + ' no se ' + (failedItems.length === 1 ? 'pudo aplicar.' : 'pudieron aplicar.');
        bubble.appendChild(p);

        const seenPages = new Set();
        doneItems.forEach((it) => {
            const card = document.createElement('div');
            card.className = 'ppa-item ppa-item--ok';
            const head = document.createElement('div');
            head.className = 'ppa-item__head';
            const badge = document.createElement('span');
            badge.className = 'ppa-item__badge';
            badge.textContent = 'Hecho';
            head.appendChild(badge);
            const t = document.createElement('span');
            t.className = 'ppa-item__page';
            t.textContent = itemLabel(it);
            head.appendChild(t);
            if (!seenPages.has(it.page_id)) {
                seenPages.add(it.page_id);
                const link = document.createElement('a');
                link.className = 'ppa-item__link';
                link.href = cfg.studioUrl + it.page_id;
                link.target = '_blank';
                link.textContent = 'Revisar y publicar →';
                head.appendChild(link);
            }
            card.appendChild(head);
            if (it.reply) {
                const body = document.createElement('div');
                body.className = 'ppa-item__body';
                body.textContent = it.reply;
                card.appendChild(body);
            }
            bubble.appendChild(card);
        });

        failedItems.forEach((it) => {
            const card = document.createElement('div');
            card.className = 'ppa-item ppa-item--no';
            const head = document.createElement('div');
            head.className = 'ppa-item__head';
            const badge = document.createElement('span');
            badge.className = 'ppa-item__badge';
            badge.textContent = 'Falló';
            head.appendChild(badge);
            const t = document.createElement('span');
            t.className = 'ppa-item__page';
            t.textContent = itemLabel(it);
            head.appendChild(t);
            card.appendChild(head);
            const body = document.createElement('div');
            body.className = 'ppa-item__body';
            body.textContent = (it.error || 'Error desconocido.') + ' Tu página no ha cambiado; puedes volver a pedirlo.';
            card.appendChild(body);
            bubble.appendChild(card);
        });

        if (doneItems.length > 0) {
            const note = document.createElement('p');
            note.className = 'ppa-plan__note';
            note.textContent = 'Los cambios están guardados como borrador en cada página, con su historial (puedes deshacer). Nada se ha publicado.';
            bubble.appendChild(note);
        }

        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
    }

    let applying = false;

    async function applyPlan(plan, applicable, btn) {
        if (applying) return;
        applying = true;
        btn.disabled = true;

        const progressMsg = document.createElement('div');
        progressMsg.className = 'ppa-msg ppa-msg--assistant';
        const progressBubble = document.createElement('div');
        progressBubble.className = 'ppa-msg__bubble ppa-msg__bubble--plan ppa-progress';
        progressMsg.appendChild(progressBubble);
        thread.appendChild(progressMsg);
        thread.scrollTop = thread.scrollHeight;

        try {
            const body = new URLSearchParams({
                _csrf: cfg.csrf,
                items: JSON.stringify(applicable),
                request_text: plan._request || '',
                summary: plan.summary || ''
            });
            const res = await fetch(cfg.baseUrl + '/apply', { method: 'POST', body });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                progressMsg.remove();
                addMessage('assistant', data.error || 'No se pudo iniciar la aplicación de cambios.', 'ppa-msg--error');
                btn.disabled = false;
                return;
            }

            let job = data.job;
            renderProgress(job, progressBubble);

            while (job.status !== 'done') {
                const stepRes = await fetch(cfg.baseUrl + '/jobs/' + job.id + '/step', {
                    method: 'POST',
                    body: new URLSearchParams({ _csrf: cfg.csrf })
                });
                const stepData = await stepRes.json().catch(() => ({}));
                if (!stepRes.ok || !stepData.ok) {
                    addMessage('assistant', stepData.error || 'La ejecución se interrumpió. Los cambios ya aplicados están guardados.', 'ppa-msg--error');
                    break;
                }
                job = stepData.job;
                renderProgress(job, progressBubble);
            }

            if (job.status === 'done') {
                renderReport(job);
            }
        } catch (err) {
            addMessage('assistant', 'Error de red durante la ejecución. Los cambios ya aplicados están guardados; recarga y revisa las páginas.', 'ppa-msg--error');
        } finally {
            applying = false;
        }
    }

    // ------------------------------------------------------------------
    // Envío: POST /admin/assistant/plan
    // ------------------------------------------------------------------
    let planning = false;

    async function sendRequest() {
        const text = input.value.trim();
        if ((text === '' && !attachedDoc) || extracting || planning) return;

        let userLabel = text !== '' ? text : 'Aplica los cambios descritos en el documento adjunto.';
        if (attachedDoc) {
            userLabel += '\n📄 ' + attachedDoc.filename;
        }
        addMessage('user', userLabel);

        const docText = attachedDoc ? attachedDoc.text : '';
        input.value = '';
        setAttachment(null);

        planning = true;
        refreshSendState();
        const thinking = addMessage('assistant', 'Analizando la petición y el mapa del sitio…', 'ppa-msg--thinking');

        const body = new URLSearchParams({ _csrf: cfg.csrf, instruction: text, doc_text: docText });
        try {
            const res = await fetch(cfg.baseUrl + '/plan', { method: 'POST', body });
            const data = await res.json().catch(() => ({}));
            thinking.closest('.ppa-msg').remove();
            if (!res.ok || !data.ok) {
                addMessage('assistant', data.error || 'No he podido generar el plan. Inténtalo de nuevo.', 'ppa-msg--error');
                return;
            }
            data.plan._request = text !== '' ? text : userLabel;
            renderPlan(data.plan);
        } catch (err) {
            thinking.closest('.ppa-msg').remove();
            addMessage('assistant', 'Error de red al generar el plan. Inténtalo de nuevo.', 'ppa-msg--error');
        } finally {
            planning = false;
            refreshSendState();
        }
    }

    // ------------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------------
    attachBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files[0]) extractFile(fileInput.files[0]);
    });
    attachmentRemove.addEventListener('click', () => setAttachment(null));
    attachmentToggle.addEventListener('click', () => {
        const show = attachmentPreview.hidden;
        attachmentPreview.hidden = !show;
        attachmentToggle.textContent = show ? 'Ocultar texto' : 'Ver texto';
    });
    input.addEventListener('input', refreshSendState);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            sendRequest();
        }
    });
    sendBtn.addEventListener('click', sendRequest);
})();
