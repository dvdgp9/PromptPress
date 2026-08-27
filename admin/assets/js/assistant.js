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
    const richInput = document.getElementById('ppa-rich-input');
    const richStatus = document.getElementById('ppa-rich-status');
    const pastePlainBtn = document.getElementById('ppa-paste-plain');
    const sendBtn = document.getElementById('ppa-send');
    const attachBtn = document.getElementById('ppa-attach-btn');
    const fileInput = document.getElementById('ppa-file-input');
    const attachment = document.getElementById('ppa-attachment');
    const attachmentName = document.getElementById('ppa-attachment-name');
    const attachmentMeta = document.getElementById('ppa-attachment-meta');
    const attachmentToggle = document.getElementById('ppa-attachment-toggle');
    const attachmentRemove = document.getElementById('ppa-attachment-remove');
    const attachmentPreview = document.getElementById('ppa-attachment-preview');
    const mediaPicker = document.getElementById('ppa-media-picker');
    const mediaPickerClose = document.getElementById('ppa-media-picker-close');
    const mediaPickerStatus = document.getElementById('ppa-media-picker-status');
    const mediaPickerGrid = document.getElementById('ppa-media-picker-grid');

    /** Documento extraído pendiente de enviar: {filename, chars, truncated, text} | null */
    let attachedDoc = null;
    let extracting = false;
    let richComposer = null;
    let mediaTargetId = null;
    let mediaPickerOpener = null;

    function closeMediaPicker() {
        if (!mediaPicker) return;
        mediaPicker.hidden = true;
        mediaTargetId = null;
        if (mediaPickerOpener) mediaPickerOpener.focus();
        mediaPickerOpener = null;
    }

    async function openMediaPicker(imageId) {
        if (!mediaPicker || !mediaPickerGrid || !mediaPickerStatus) return;
        mediaTargetId = imageId;
        mediaPickerOpener = document.activeElement;
        mediaPicker.hidden = false;
        mediaPickerGrid.replaceChildren();
        mediaPickerStatus.textContent = pp.t('js.as.media_loading');
        mediaPicker.setAttribute('aria-busy', 'true');
        mediaPickerClose.focus();
        try {
            const response = await fetch(cfg.mediaLibraryUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || !body.ok) throw new Error(body.error || pp.t('js.as.media_load_failed'));
            const items = (body.items || [])
                .map((item) => window.PPARichComposer.normalizeMediaItem(item))
                .filter(Boolean);
            mediaPickerStatus.textContent = items.length > 0
                ? pp.t('js.as.media_choose_one')
                : pp.t('js.as.media_empty');
            items.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'ppa-media-picker__item';
                button.setAttribute('aria-label', item.alt || item.name || pp.t('js.as.media_image'));
                const image = document.createElement('img');
                image.src = item.url;
                image.alt = '';
                image.loading = 'lazy';
                button.appendChild(image);
                const label = document.createElement('span');
                label.textContent = item.alt || item.name || pp.t('js.as.media_image');
                button.appendChild(label);
                button.addEventListener('click', () => {
                    if (richComposer && mediaTargetId && richComposer.useMedia(mediaTargetId, item)) {
                        closeMediaPicker();
                    }
                });
                mediaPickerGrid.appendChild(button);
            });
        } catch (error) {
            mediaPickerStatus.textContent = error && error.message ? error.message : pp.t('js.as.media_load_failed');
        } finally {
            mediaPicker.removeAttribute('aria-busy');
        }
    }

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
            attachmentMeta.textContent = pp.t('js.as.chars_extracted', { n: doc.chars.toLocaleString() })
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
        const richState = richComposer ? richComposer.state() : null;
        const hasContent = richState ? !richState.empty : input.value.trim() !== '';
        const valid = richState ? richState.valid : true;
        sendBtn.disabled = (!hasContent && attachedDoc === null) || !valid || extracting || planning;
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
                addMessage('assistant', data.error || pp.t('js.as.doc_failed'), 'ppa-msg--error');
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
            addMessage('assistant', pp.t('js.as.upload_network'), 'ppa-msg--error');
        } finally {
            extracting = false;
            attachBtn.classList.remove('is-busy');
            refreshSendState();
        }
    }

    // ------------------------------------------------------------------
    // F5-T3 — Render del plan propuesto
    // ------------------------------------------------------------------
    const CATEGORY_META = {
        automatable_now:      { label: pp.t('js.as.cat.automatic'), cls: 'ppa-item--ok' },
        manual_in_platform:   { label: pp.t('js.as.cat.manual'), cls: 'ppa-item--ask' },
        needs_input:          { label: pp.t('js.as.cat.input'), cls: 'ppa-item--ask' },
        requires_development: { label: pp.t('js.as.cat.development'), cls: 'ppa-item--no' },
        sensitive_review:     { label: pp.t('js.as.cat.sensitive'), cls: 'ppa-item--no' }
    };
    let lastPlanContext = null;

    function itemCategory(it) {
        if (CATEGORY_META[it.category]) return it.category;
        if (it.status === 'aplicar') return 'automatable_now';
        if (it.status === 'ambiguo') return 'needs_input';
        return 'requires_development';
    }

    function renderPlan(plan) {
        const msg = document.createElement('div');
        msg.className = 'ppa-msg ppa-msg--assistant';
        const bubble = document.createElement('div');
        bubble.className = 'ppa-msg__bubble ppa-msg__bubble--plan';
        msg.appendChild(bubble);

        if (plan.vision && plan.vision.status && plan.vision.status !== 'not_needed') {
            const vision = document.createElement('p');
            vision.className = 'ppa-plan__vision ppa-plan__vision--' + plan.vision.status;
            const visionCount = plan.vision.status === 'used'
                ? Number(plan.vision.sent_images || 0)
                : Number(plan.vision.ready_images || 0);
            vision.textContent = plan.vision.status === 'used'
                ? pp.t(visionCount === 1 ? 'js.as.vision_used_one' : 'js.as.vision_used_other', { n: visionCount, modelo: plan.vision.model || '' })
                : pp.t(visionCount === 1 ? 'js.as.vision_unavailable_one' : 'js.as.vision_unavailable_other', { n: visionCount, modelo: plan.vision.model || '' });
            bubble.appendChild(vision);
        }

        if (plan.summary) {
            const p = document.createElement('p');
            p.className = 'ppa-plan__summary';
            p.textContent = plan.summary;
            bubble.appendChild(p);
        }

        const items = plan.items || [];
        const order = ['automatable_now', 'manual_in_platform', 'needs_input', 'requires_development', 'sensitive_review'];
        order.forEach((category) => {
            items.filter((it) => itemCategory(it) === category).forEach((it) => {
                const meta = CATEGORY_META[category];
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
                    ? it.page_title + (it.section ? ' · ' + pp.t('js.as.section_x', { seccion: it.section }) : '')
                    : pp.t('js.as.outside_pages');
                head.appendChild(title);
                card.appendChild(head);

                const body = document.createElement('div');
                body.className = 'ppa-item__body';
                body.textContent = category === 'automatable_now' ? it.instruction : (it.reason || it.instruction);
                card.appendChild(body);

                if (Array.isArray(it.required_inputs) && it.required_inputs.length > 0) {
                    const missing = document.createElement('div');
                    missing.className = 'ppa-item__body';
                    missing.textContent = pp.t('js.as.missing_data', { datos: it.required_inputs.join(' · ') });
                    card.appendChild(missing);
                }
                if (it.next_action) {
                    const next = document.createElement('div');
                    next.className = 'ppa-plan__note';
                    next.textContent = pp.t('js.as.next_action', { accion: it.next_action });
                    card.appendChild(next);
                }

                bubble.appendChild(card);
            });
        });

        if (items.length === 0) {
            const p = document.createElement('p');
            p.textContent = pp.t('js.as.no_changes');
            bubble.appendChild(p);
        }

        const actionable = items.filter((it) => itemCategory(it) === 'automatable_now');
        if (actionable.length > 0) {
            const foot = document.createElement('div');
            foot.className = 'ppa-plan__foot';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pp-btn pp-btn--primary ppa-plan__apply';
            const refresh = () => {
                const applicable = items.filter((it) => it.status === 'aplicar' && itemCategory(it) === 'automatable_now');
                btn.disabled = applicable.length === 0;
                btn.textContent = applicable.length > 0
                    ? pp.t(applicable.length === 1 ? 'js.as.apply_one' : 'js.as.apply_n', { n: applicable.length })
                    : pp.t('js.as.confirm_above');
            };
            btn.addEventListener('click', () => {
                const applicable = items.filter((it) => it.status === 'aplicar' && itemCategory(it) === 'automatable_now');
                if (applicable.length > 0) applyPlan(plan, applicable, btn);
            });
            foot.appendChild(btn);
            const note = document.createElement('span');
            note.className = 'ppa-plan__note';
            note.textContent = pp.t('js.asst.draft_note');
            foot.appendChild(note);
            bubble.appendChild(foot);
            refresh();
            lastPlanContext = {
                plan,
                button: btn,
                refresh
            };
        } else {
            lastPlanContext = null;
        }

        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
    }

    // ------------------------------------------------------------------
    // F5-T4/T5 — Ejecución del plan confirmado, con progreso e informe
    // ------------------------------------------------------------------
    const ITEM_STATUS = {
        pending: { icon: '·', label: pp.t('js.as.queued') },
        running: { icon: '…', label: pp.t('js.as.applying') },
        done:    { icon: '✓', label: pp.t('js.as.done') },
        failed:  { icon: '✗', label: pp.t('js.as.failed') }
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
            badge.textContent = pp.t('js.as.failed');
            head.appendChild(badge);
            const t = document.createElement('span');
            t.className = 'ppa-item__page';
            t.textContent = itemLabel(it);
            head.appendChild(t);
            card.appendChild(head);
            const body = document.createElement('div');
            body.className = 'ppa-item__body';
            body.textContent = (it.error || pp.t('js.as.unknown_error')) + ' ' + pp.t('js.as.page_unchanged');
            card.appendChild(body);
            bubble.appendChild(card);
        });

        if (doneItems.length > 0) {
            const note = document.createElement('p');
            note.className = 'ppa-plan__note';
            note.textContent = pp.t('js.as.saved_note');
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
        if (lastPlanContext && lastPlanContext.plan === plan) {
            lastPlanContext = null;
        }

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
                summary: plan.summary || '',
                source_token: plan.source_token || ''
            });
            const res = await fetch(cfg.baseUrl + '/apply', { method: 'POST', body });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                progressMsg.remove();
                addMessage('assistant', data.error || pp.t('js.as.cant_start'), 'ppa-msg--error');
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
                    addMessage('assistant', stepData.error || pp.t('js.as.interrupted'), 'ppa-msg--error');
                    break;
                }
                job = stepData.job;
                renderProgress(job, progressBubble);
            }

            if (job.status === 'done') {
                renderReport(job);
            }
        } catch (err) {
            addMessage('assistant', pp.t('js.as.exec_network'), 'ppa-msg--error');
        } finally {
            applying = false;
        }
    }

    // ------------------------------------------------------------------
    // Envío: POST /admin/assistant/plan
    // ------------------------------------------------------------------
    let planning = false;

    function isAffirmativeReply(text) {
        const normalized = text.toLocaleLowerCase('es-ES')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            // i18n-ignore: clase de caracteres de una expresión regular, no es texto.
        .replace(/[.,;:!?¿¡]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return /^(?:si|vale|ok|de acuerdo|adelante|procede|hazlo|aplica|si procede|si adelante|si hazlo)$/.test(normalized);
    }

    async function sendRequest() {
        const text = richComposer ? richComposer.getPlainText() : input.value.trim();
        const richHtml = richComposer ? richComposer.getRichHtml() : '';
        const richState = richComposer ? richComposer.state() : null;
        if ((text === '' && (!richState || richState.images === 0) && !attachedDoc) || extracting || planning) return;
        if (richState && !richState.valid) return;

        // Continuación natural del plan mostrado: una confirmación breve
        // aplica el último plan en vez de pedir a la IA que planifique la frase
        // aislada "sí, procede" (que lógicamente no contiene cambios).
        if (!attachedDoc && (!richState || richState.images === 0) && lastPlanContext && isAffirmativeReply(text)) {
            addMessage('user', text);
            if (richComposer) richComposer.reset();
            else input.value = '';
            const context = lastPlanContext;
            const applicable = context.plan.items.filter((it) => it.status === 'aplicar' && itemCategory(it) === 'automatable_now');
            await applyPlan(context.plan, applicable, context.button);
            refreshSendState();
            return;
        }

        let userLabel = text !== '' ? text : 'Aplica los cambios descritos en el documento adjunto.';
        if (attachedDoc) {
            userLabel += '\n[Documento] ' + attachedDoc.filename;
        }
        addMessage('user', userLabel);
        lastPlanContext = null;

        const docText = attachedDoc ? attachedDoc.text : '';
        if (richComposer) richComposer.reset();
        else input.value = '';
        setAttachment(null);

        planning = true;
        refreshSendState();
        const thinking = addMessage('assistant', pp.t('js.as.analyzing'), 'ppa-msg--thinking');

        const body = new URLSearchParams({
            _csrf: cfg.csrf,
            instruction: text,
            doc_text: docText,
            rich_html: richHtml
        });
        try {
            const res = await fetch(cfg.baseUrl + '/plan', { method: 'POST', body });
            const data = await res.json().catch(() => ({}));
            thinking.closest('.ppa-msg').remove();
            if (!res.ok || !data.ok) {
                addMessage('assistant', data.error || pp.t('js.as.plan_failed'), 'ppa-msg--error');
                return;
            }
            data.plan._request = text !== '' ? text : userLabel;
            renderPlan(data.plan);
        } catch (err) {
            thinking.closest('.ppa-msg').remove();
            addMessage('assistant', pp.t('js.as.plan_network'), 'ppa-msg--error');
        } finally {
            planning = false;
            refreshSendState();
        }
    }

    // ------------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------------
    if (window.PPARichComposer && richInput && richStatus) {
        richComposer = new window.PPARichComposer.Composer({
            editor: richInput,
            fallback: input,
            status: richStatus,
            maxChars: cfg.richMaxChars,
            maxImages: cfg.richMaxImages,
            maxImageBytes: cfg.richMaxImageBytes,
            csrf: cfg.csrf,
            uploadUrl: cfg.mediaUploadUrl,
            remoteImportUrl: cfg.remoteImportUrl,
            t: (key, vars) => pp.t(key, vars),
            onChooseMedia: openMediaPicker,
            onChange: refreshSendState
        }).enhance();
        richInput.addEventListener('ppa:send', sendRequest);
        if (pastePlainBtn) pastePlainBtn.addEventListener('click', () => richComposer.armPlainPaste());
    } else if (pastePlainBtn) {
        pastePlainBtn.hidden = true;
    }
    if (mediaPickerClose) mediaPickerClose.addEventListener('click', closeMediaPicker);
    if (mediaPicker) {
        mediaPicker.addEventListener('click', (event) => {
            if (event.target === mediaPicker) closeMediaPicker();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !mediaPicker.hidden) closeMediaPicker();
        });
    }
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
