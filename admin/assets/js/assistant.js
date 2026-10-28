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
    const composerBox = document.getElementById('ppa-composer');
    const attachmentList = document.getElementById('ppa-attachments');
    const mediaPicker = document.getElementById('ppa-media-picker');
    const mediaPickerClose = document.getElementById('ppa-media-picker-close');
    const mediaPickerStatus = document.getElementById('ppa-media-picker-status');
    const mediaPickerGrid = document.getElementById('ppa-media-picker-grid');

    /**
     * Documentos pendientes de enviar (ASST-CONV AC1: pueden ser varios).
     * Cada uno: {id, filename, kind:'doc'|'pdf', status:'working'|'ready', chars, truncated, text, pages}
     */
    let attachedDocs = [];
    let nextDocId = 1;
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
    // Adjuntos: documentos (texto) e imágenes (al composer)
    // ------------------------------------------------------------------
    function readyDocs() {
        return attachedDocs.filter((doc) => doc.status === 'ready');
    }

    function docMeta(doc) {
        if (doc.status === 'working') return doc.progress || pp.t('js.as.doc_extracting');
        const parts = [];
        if (doc.pages > 0 && doc.totalPages > doc.pages) {
            parts.push(pp.t('js.as.pdf_pages_partial', { n: doc.pages, total: doc.totalPages }));
        } else if (doc.pages > 0) {
            parts.push(pp.t(doc.pages === 1 ? 'js.as.pdf_pages_one' : 'js.as.pdf_pages_other', { n: doc.pages }));
        }
        if (doc.renderFailed) parts.push(pp.t('js.as.pdf_render_failed'));
        if (doc.chars > 0) {
            parts.push(pp.t('js.as.chars_extracted', { n: doc.chars.toLocaleString() })
                + (doc.truncated ? ' ' + pp.t('js.as.doc_truncated') : ''));
        }
        return parts.join(' · ');
    }

    function renderAttachments() {
        attachmentList.replaceChildren();
        attachedDocs.forEach((doc) => {
            const chip = document.createElement('div');
            chip.className = 'ppa-attachment' + (doc.status === 'working' ? ' is-working' : '');
            const icon = document.createElement('span');
            icon.className = 'ppa-attachment__icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = doc.kind === 'pdf' ? 'PDF' : 'DOC';
            chip.appendChild(icon);
            const name = document.createElement('span');
            name.className = 'ppa-attachment__name';
            name.textContent = doc.filename;
            chip.appendChild(name);
            const meta = document.createElement('span');
            meta.className = 'ppa-attachment__meta';
            meta.textContent = docMeta(doc);
            chip.appendChild(meta);

            const preview = document.createElement('pre');
            preview.className = 'ppa-attachment-preview';
            preview.hidden = true;
            if (doc.status === 'ready' && doc.text) {
                preview.textContent = doc.text;
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'ppa-attachment__toggle';
                toggle.textContent = pp.t('js.as.see_text');
                toggle.addEventListener('click', () => {
                    preview.hidden = !preview.hidden;
                    toggle.textContent = pp.t(preview.hidden ? 'js.as.see_text' : 'js.as.hide_text');
                });
                chip.appendChild(toggle);
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ppa-attachment__remove';
            remove.title = pp.t('js.as.remove_doc');
            remove.setAttribute('aria-label', pp.t('js.as.remove_doc'));
            remove.textContent = '×';
            remove.addEventListener('click', () => removeDoc(doc.id));
            chip.appendChild(remove);

            attachmentList.appendChild(chip);
            attachmentList.appendChild(preview);
        });
        attachmentList.hidden = attachedDocs.length === 0;
        refreshSendState();
    }

    function updateDoc(id, changes) {
        const doc = attachedDocs.find((item) => item.id === id);
        if (doc) Object.assign(doc, changes);
        renderAttachments();
    }

    function removeDoc(id) {
        attachedDocs = attachedDocs.filter((doc) => doc.id !== id);
        renderAttachments();
    }

    function clearDocs() {
        attachedDocs = [];
        fileInput.value = '';
        renderAttachments();
    }

    function refreshSendState() {
        const richState = richComposer ? richComposer.state() : null;
        const hasContent = richState ? !richState.empty : input.value.trim() !== '';
        const valid = richState ? richState.valid : true;
        extracting = attachedDocs.some((doc) => doc.status === 'working');
        attachBtn.classList.toggle('is-busy', extracting);
        sendBtn.disabled = (!hasContent && readyDocs().length === 0) || !valid || extracting || planning;
        sendBtn.classList.toggle('is-loading', planning);
        sendBtn.setAttribute('aria-busy', planning ? 'true' : 'false');
    }

    /** Extrae el texto en el servidor. Devuelve {ok, data} o {ok:false, error, empty}. */
    async function requestExtract(file) {
        const form = new FormData();
        form.append('_csrf', cfg.csrf);
        form.append('file', file);
        try {
            const res = await fetch(cfg.baseUrl + '/extract', { method: 'POST', body: form });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                return { ok: false, error: data.error || pp.t('js.as.doc_failed'), empty: data.code === 'empty_doc' };
            }
            return { ok: true, data: data };
        } catch (err) {
            return { ok: false, error: pp.t('js.as.upload_network'), empty: false };
        }
    }

    async function extractFile(file, kind) {
        const doc = { id: nextDocId++, filename: file.name, kind: kind || 'doc', status: 'working', chars: 0, truncated: false, text: '', pages: 0 };
        attachedDocs.push(doc);
        renderAttachments();
        const result = await requestExtract(file);
        if (!result.ok) {
            removeDoc(doc.id);
            addMessage('assistant', file.name + ': ' + result.error, 'ppa-msg--error');
            return;
        }
        updateDoc(doc.id, {
            status: 'ready',
            chars: result.data.chars,
            truncated: !!result.data.truncated,
            text: result.data.text
        });
    }

    /**
     * ASST-CONV AC3 — un PDF aporta su texto (servidor) y sus páginas como
     * imagen (navegador, pdf.js). Un PDF de diseño sin texto ya no es un error:
     * lo que cuenta son sus páginas. Los PDF se procesan de uno en uno para que
     * cada uno sepa cuánto cupo de imágenes queda.
     */
    let pdfQueue = Promise.resolve();

    function attachPdf(file) {
        const doc = { id: nextDocId++, filename: file.name, kind: 'pdf', status: 'working', chars: 0, truncated: false, text: '', pages: 0, totalPages: 0, renderFailed: false };
        attachedDocs.push(doc);
        renderAttachments();
        pdfQueue = pdfQueue.then(() => processPdf(file, doc)).catch(() => {});
    }

    async function processPdf(file, doc) {
        const budget = richComposer ? richComposer.remainingImages() : 0;
        const canRender = budget > 0 && window.PPAPdfPages && cfg.pdfLibUrl;
        const rendering = canRender
            ? window.PPAPdfPages.renderPdf(file, {
                libUrl: cfg.pdfLibUrl,
                workerUrl: cfg.pdfWorkerUrl,
                budget: budget,
                onProgress: (n, total) => updateDoc(doc.id, { progress: pp.t('js.as.pdf_rendering', { n: n, total: total }) })
            }).catch((error) => {
                console.warn('[assistant] PDF render failed', error);
                return null;
            })
            : Promise.resolve(null);
        const [text, pages] = await Promise.all([requestExtract(file), rendering]);
        if (!attachedDocs.some((item) => item.id === doc.id)) return; // quitado mientras se procesaba

        let pageCount = 0;
        if (pages && pages.files.length > 0 && richComposer) {
            richComposer.addFiles(pages.files, (image) => {
                const where = pages.pageOf.get(image.name) || { page: 1, part: 1, parts: 1 };
                return where.parts > 1
                    ? pp.t('js.as.pdf_page_part_alt', { pagina: where.page, parte: where.part, partes: where.parts, archivo: file.name })
                    : pp.t('js.as.pdf_page_alt', { pagina: where.page, archivo: file.name });
            });
            pageCount = pages.renderedPages;
        }
        if (!text.ok && pageCount === 0) {
            removeDoc(doc.id);
            addMessage('assistant', file.name + ': ' + text.error, 'ppa-msg--error');
            return;
        }
        updateDoc(doc.id, {
            status: 'ready',
            chars: text.ok ? text.data.chars : 0,
            truncated: text.ok ? !!text.data.truncated : false,
            text: text.ok ? text.data.text : '',
            pages: pageCount,
            totalPages: pages ? pages.totalPages : 0,
            renderFailed: !!canRender && pages === null
        });
    }

    /** Reparte lo que llega por el clip o arrastrando (ASST-CONV AC1). */
    function handleFiles(fileList) {
        const files = Array.from(fileList || []);
        if (files.length === 0) return;
        const groups = window.PPARichComposer.classifyAttachments(files, {
            docExtensions: cfg.allowedExt,
            maxDocBytes: cfg.maxSize
        });
        if (groups.rejected.length > 0) {
            const names = groups.rejected.map((entry) => entry.file.name).join(', ');
            addMessage('assistant', pp.t('js.as.files_rejected', {
                archivos: names,
                mb: Math.round(cfg.maxSize / 1048576)
            }), 'ppa-msg--error');
        }
        if (groups.images.length > 0) {
            if (richComposer) {
                richComposer.addFiles(groups.images);
            } else {
                addMessage('assistant', pp.t('js.as.images_need_editor'), 'ppa-msg--error');
            }
        }
        groups.pdfs.forEach((file) => attachPdf(file));
        groups.docs.forEach((file) => extractFile(file, 'doc'));
        fileInput.value = '';
    }

    function setupDropZone() {
        if (!composerBox) return;
        let depth = 0;
        const hasFiles = (event) => Array.from((event.dataTransfer && event.dataTransfer.types) || []).includes('Files');
        composerBox.addEventListener('dragenter', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            depth += 1;
            composerBox.classList.add('is-dragover');
        });
        composerBox.addEventListener('dragover', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });
        composerBox.addEventListener('dragleave', () => {
            depth = Math.max(0, depth - 1);
            if (depth === 0) composerBox.classList.remove('is-dragover');
        });
        // Se intercepta también la caída sobre el editor: si no, el navegador
        // inserta la imagen tal cual (blob sin subir) o abre el PDF.
        composerBox.addEventListener('drop', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            depth = 0;
            composerBox.classList.remove('is-dragover');
            if (planning) return;
            handleFiles(event.dataTransfer.files);
        });
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

    // ------------------------------------------------------------------
    // ASST-CONV AC7 — memoria de la conversación (vive mientras la pestaña
    // está abierta). Cada mensaje manda los turnos anteriores, el plan aún sin
    // aplicar y el token del material ya adjuntado.
    // ------------------------------------------------------------------
    const MAX_TURNS = 10;
    const conversation = { turns: [], plan: null, sourceToken: '', thumbs: new Map(), id: null, log: [] };

    // ------------------------------------------------------------------
    // ASST-V2 F1 — la conversación se guarda en el servidor tras cada paso
    // para poder recargar o retomarla otro día.
    // ------------------------------------------------------------------
    function logEntry(entry) {
        conversation.log.push(entry);
        conversation.log = conversation.log.slice(-80);
    }

    let persistChain = Promise.resolve();
    function persistConversation() {
        if (conversation.log.length === 0) return;
        const snapshot = JSON.stringify({
            state: { turns: conversation.turns, plan: conversation.plan, source_token: conversation.sourceToken },
            log: conversation.log
        }, (key, value) => (key === '_thumbs' || key === '_button' ? undefined : value));
        // En serie: dos guardados seguidos no pueden crear dos conversaciones.
        persistChain = persistChain.then(async () => {
            try {
                const res = await fetch(cfg.baseUrl + '/conversations/save', {
                    method: 'POST',
                    body: new URLSearchParams({ _csrf: cfg.csrf, id: conversation.id || '', snapshot })
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok && data.id) {
                    conversation.id = data.id;
                    if (cfg.mode !== 'drawer' && window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', cfg.baseUrl + '?c=' + data.id);
                    }
                }
            } catch (err) {
                // Sin guardar se sigue pudiendo trabajar; solo se pierde al recargar.
            }
        });
    }

    function markPlanApplied(plan) {
        plan._applied = true;
        const msg = plan._button;
        if (msg) {
            msg.disabled = true;
            msg.textContent = pp.t('js.as.plan_applied');
        }
    }

    function restoreConversation(saved) {
        if (!saved || !Array.isArray(saved.log) || saved.log.length === 0) return;
        const state = saved.state || {};
        conversation.id = saved.id;
        conversation.turns = Array.isArray(state.turns) ? state.turns : [];
        conversation.sourceToken = typeof state.source_token === 'string' ? state.source_token : '';
        let pending = null;
        saved.log.forEach((entry) => {
            if (!entry || typeof entry !== 'object') return;
            if (entry.type === 'plan' && entry.plan) {
                const plan = entry.plan;
                plan._thumbs = new Map();
                renderPlan(plan);
                conversation.log.push({ type: 'plan', plan });
                if (plan._applied) {
                    markPlanApplied(plan);
                    if (lastPlanContext && lastPlanContext.plan === plan) lastPlanContext = null;
                    pending = null;
                } else {
                    pending = (plan.items || []).length > 0 ? plan : null;
                }
                return;
            }
            const text = String(entry.text || '');
            if (text === '') return;
            if (entry.type === 'user') addMessage('user', text);
            else addMessage('assistant', text, entry.type === 'error' ? 'ppa-msg--error' : '');
            conversation.log.push({ type: entry.type, text });
        });
        conversation.plan = pending;
        const note = addMessage('assistant', pp.t('js.as.conv_resumed'));
        note.closest('.ppa-msg').classList.add('ppa-msg--resumed');
    }
    const newChatBtn = document.getElementById('ppa-new-chat');

    function rememberTurn(role, text) {
        const clean = String(text || '').trim();
        if (clean === '') return;
        conversation.turns.push({ role: role, text: clean.slice(0, 1500) });
        conversation.turns = conversation.turns.slice(-MAX_TURNS);
        if (newChatBtn) newChatBtn.hidden = false;
    }

    function condensePlan(plan) {
        if (!plan || !Array.isArray(plan.items)) return [];
        return plan.items.map((it) => ({
            page_id: it.page_id,
            page_title: it.page_title,
            form_id: it.form_id || 0,
            form_title: it.form_title || '',
            section: it.section,
            capability_id: it.capability_id,
            category: it.category,
            instruction: it.instruction,
            media_ids: it.media_ids || [],
            source_block_ids: it.source_block_ids || []
        }));
    }

    /** Un plan nuevo sustituye al anterior: su botón deja de aplicar. */
    function supersedePreviousPlans() {
        thread.querySelectorAll('.ppa-plan__apply:not([disabled])').forEach((button) => {
            button.disabled = true;
            button.textContent = pp.t('js.as.plan_superseded');
        });
    }

    function resetConversation() {
        if (planning || applying) return;
        conversation.turns = [];
        conversation.plan = null;
        conversation.sourceToken = '';
        conversation.thumbs = new Map();
        conversation.id = null;
        conversation.log = [];
        if (cfg.mode !== 'drawer' && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', cfg.baseUrl + '?nueva=1');
        }
        lastPlanContext = null;
        Array.from(thread.children).slice(1).forEach((node) => node.remove());
        if (newChatBtn) newChatBtn.hidden = true;
        if (richComposer) richComposer.reset();
        else input.value = '';
        clearDocs();
    }

    function itemCategory(it) {
        if (CATEGORY_META[it.category]) return it.category;
        if (it.status === 'aplicar') return 'automatable_now';
        if (it.status === 'ambiguo') return 'needs_input';
        return 'requires_development';
    }

    /** Rol de cada imagen según el plan: media_id => 'reference' | 'asset'. */
    function mediaRoleMap(plan) {
        const roles = new Map();
        (plan.media_roles || []).forEach((entry) => {
            if (entry && entry.media_id) roles.set(Number(entry.media_id), entry.role === 'asset' ? 'asset' : 'reference');
        });
        return roles;
    }

    /** Miniaturas de las imágenes en que se basa un punto del plan. */
    function renderItemMedia(it, plan) {
        const ids = Array.isArray(it.media_ids) ? it.media_ids.map(Number) : [];
        const thumbs = plan._thumbs instanceof Map ? plan._thumbs : new Map();
        const known = ids.filter((id) => thumbs.has(id));
        if (known.length === 0) return null;
        const roles = mediaRoleMap(plan);
        const wrap = document.createElement('div');
        wrap.className = 'ppa-item__media';
        const label = document.createElement('span');
        label.className = 'ppa-item__media-label';
        label.textContent = known.every((id) => roles.get(id) === 'asset')
            ? pp.t('js.as.media_asset_label')
            : pp.t('js.as.media_reference_label');
        wrap.appendChild(label);
        known.forEach((id) => {
            const thumb = thumbs.get(id);
            const image = document.createElement('img');
            image.src = thumb.url;
            image.alt = thumb.alt || '';
            image.title = thumb.alt || '';
            image.loading = 'lazy';
            wrap.appendChild(image);
        });
        return wrap;
    }

    function renderPlan(plan) {
        supersedePreviousPlans();
        const msg = document.createElement('div');
        msg.className = 'ppa-msg ppa-msg--assistant';
        const bubble = document.createElement('div');
        bubble.className = 'ppa-msg__bubble ppa-msg__bubble--plan';
        msg.appendChild(bubble);

        // ASST-CONV AC5 — primero la respuesta hablada; el resumen técnico
        // solo si el modelo no la dio.
        const replyText = plan.reply || plan.summary;
        if (replyText) {
            const p = document.createElement('p');
            p.className = 'ppa-plan__summary';
            p.textContent = replyText;
            bubble.appendChild(p);
        }

        if (Array.isArray(plan.understanding) && plan.understanding.length > 0) {
            const box = document.createElement('div');
            box.className = 'ppa-plan__understanding';
            const title = document.createElement('p');
            title.className = 'ppa-plan__understanding-title';
            title.textContent = pp.t('js.as.understood_title');
            box.appendChild(title);
            const list = document.createElement('ul');
            plan.understanding.forEach((line) => {
                const li = document.createElement('li');
                li.textContent = line;
                list.appendChild(li);
            });
            box.appendChild(list);
            bubble.appendChild(box);
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
                title.textContent = it.form_id
                    ? pp.t('js.as.form_x', { formulario: it.form_title })
                    : it.page_title
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
                // En lo aplicable el siguiente paso es siempre «confirmar»: no aporta.
                if (it.next_action && category !== 'automatable_now') {
                    const next = document.createElement('div');
                    next.className = 'ppa-plan__note';
                    next.textContent = pp.t('js.as.next_action', { accion: it.next_action });
                    card.appendChild(next);
                }
                // ASST-V2 F2 — lo que el asistente aún no hace, a un clic.
                if (it.link && it.link.url && category !== 'automatable_now') {
                    const go = document.createElement('a');
                    go.className = 'pp-btn pp-btn--secondary pp-btn--sm ppa-item__go';
                    go.href = it.link.url;
                    go.textContent = it.link.label + ' →';
                    card.appendChild(go);
                }
                const media = renderItemMedia(it, plan);
                if (media) card.appendChild(media);

                bubble.appendChild(card);
            });
        });

        if (items.length === 0 && !plan.reply) {
            const p = document.createElement('p');
            p.textContent = pp.t('js.as.no_changes');
            bubble.appendChild(p);
        }

        // Qué imágenes ha mirado la IA: dato útil, pero secundario.
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
            // ASST-FORMS — los formularios no tienen borrador: avisar antes.
            if (actionable.some((it) => it.form_id)) {
                const live = document.createElement('span');
                live.className = 'ppa-plan__note';
                live.textContent = pp.t('js.as.form_live_note');
                foot.appendChild(live);
            }
            bubble.appendChild(foot);
            refresh();
            plan._button = btn;
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
            if (it.form_id) {
                const link = document.createElement('a');
                link.className = 'ppa-item__link';
                link.href = cfg.formsUrl + it.form_id;
                link.target = '_blank';
                link.textContent = pp.t('js.as.view_form');
                head.appendChild(link);
            } else if (!seenPages.has(it.page_id)) {
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
            body.textContent = (it.error || pp.t('js.as.unknown_error')) + ' ' + pp.t(it.form_id ? 'js.as.form_unchanged' : 'js.as.page_unchanged');
            card.appendChild(body);
            bubble.appendChild(card);
        });

        if (doneItems.some((it) => it.form_id)) {
            const formNote = document.createElement('p');
            formNote.className = 'ppa-plan__note';
            formNote.textContent = pp.t('js.as.form_live_done');
            bubble.appendChild(formNote);
        }

        // Publicar solo tiene sentido para páginas: el formulario ya está en vivo.
        if (doneItems.some((it) => !it.form_id)) {
            const note = document.createElement('p');
            note.className = 'ppa-plan__note';
            note.textContent = pp.t('js.as.saved_note');
            bubble.appendChild(note);

            // ASST-CONV AC8 — aprobar de un clic lo que ya se ha revisado.
            const foot = document.createElement('div');
            foot.className = 'ppa-plan__foot';
            const publishBtn = document.createElement('button');
            publishBtn.type = 'button';
            publishBtn.className = 'pp-btn pp-btn--primary ppa-plan__publish';
            publishBtn.textContent = pp.t('js.as.publish_changes');
            publishBtn.addEventListener('click', () => publishJob(job, publishBtn, foot, note));
            foot.appendChild(publishBtn);
            bubble.appendChild(foot);
        }

        // ASST-V2 F2 — si lo cambiado es lo que hay en pantalla, ofrecer verlo.
        const ctx = cfg.mode === 'drawer' ? cfg.context : null;
        if (ctx && doneItems.some((it) => (ctx.type === 'form' && Number(it.form_id) === Number(ctx.id))
            || (ctx.type === 'page' && Number(it.page_id) === Number(ctx.id)))) {
            const reload = document.createElement('button');
            reload.type = 'button';
            reload.className = 'pp-btn pp-btn--primary pp-btn--sm ppa-plan__reload';
            reload.textContent = pp.t('js.as.reload_screen');
            reload.addEventListener('click', () => window.location.reload());
            bubble.appendChild(reload);
        }

        // ASST-V2 F1 — todo lo aplicado se puede revisar y deshacer en Actividad.
        if (doneItems.length > 0 && cfg.activityUrl) {
            const activity = document.createElement('a');
            activity.className = 'ppa-item__link';
            activity.href = cfg.activityUrl + '?f=assistant';
            activity.textContent = pp.t('js.as.view_activity');
            bubble.appendChild(activity);
        }

        thread.appendChild(msg);
        thread.scrollTop = thread.scrollHeight;
    }

    async function publishJob(job, button, container, note) {
        if (button.disabled) return;
        button.disabled = true;
        button.textContent = pp.t('js.as.publishing');
        try {
            const res = await fetch(cfg.baseUrl + '/jobs/' + job.id + '/publish', {
                method: 'POST',
                body: new URLSearchParams({ _csrf: cfg.csrf })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.error || pp.t('js.as.publish_failed'));
            const pages = data.pages || [];
            const live = pages.filter((page) => page.published);
            button.textContent = live.length > 0 ? pp.t('js.as.published_btn') : pp.t('js.as.publish_changes');
            button.disabled = live.length > 0;
            if (live.length > 0 && note) note.textContent = pp.t('js.as.published_note');
            const list = document.createElement('ul');
            list.className = 'ppa-plan__publish-result';
            pages.forEach((page) => {
                const li = document.createElement('li');
                li.textContent = page.published
                    ? pp.t('js.as.page_published', { pagina: page.title })
                    : pp.t('js.as.page_not_published', { pagina: page.title, motivo: page.reason });
                list.appendChild(li);
            });
            container.after(list);
            const publishedNote = live.length > 0
                ? pp.t('js.as.turn_published', { paginas: live.map((page) => page.title).join(', ') })
                : pp.t('js.as.turn_not_published');
            rememberTurn('assistant', publishedNote);
            logEntry({ type: 'note', text: publishedNote });
            persistConversation();
        } catch (error) {
            button.disabled = false;
            button.textContent = pp.t('js.as.publish_changes');
            addMessage('assistant', error.message || pp.t('js.as.publish_failed'), 'ppa-msg--error');
        }
    }

    let applying = false;

    async function applyPlan(plan, applicable, btn) {
        if (applying) return;
        applying = true;
        setBusy(true);
        btn.disabled = true;
        btn.classList.add('is-loading');
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
                btn.textContent = pp.t('js.as.plan_applied');
                renderReport(job);
                // La IA debe saber en el siguiente mensaje que esto ya está hecho.
                const done = job.items.filter((it) => it.status === 'done').map(itemLabel);
                const failed = job.items.filter((it) => it.status === 'failed').map(itemLabel);
                const appliedNote = pp.t('js.as.turn_applied', { hechos: done.join(', ') || '-', fallidos: failed.join(', ') || '-' });
                rememberTurn('assistant', appliedNote);
                if (conversation.plan === plan) conversation.plan = null;
                markPlanApplied(plan);
                logEntry({ type: 'note', text: appliedNote });
                persistConversation();
            }
        } catch (err) {
            addMessage('assistant', pp.t('js.as.exec_network'), 'ppa-msg--error');
        } finally {
            applying = false;
            btn.classList.remove('is-loading');
            setBusy(false);
            emit('ppa:news');
        }
    }

    // ------------------------------------------------------------------
    // Envío: POST /admin/assistant/plan
    // ------------------------------------------------------------------
    let planning = false;
    let planAbort = null;

    // ASST-V2 F2 — el panel lateral escucha esto para avisar con el botón.
    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }
    function setBusy(busy) {
        emit('ppa:busy', { busy });
    }

    /**
     * Mensaje de espera que dice en qué está el asistente: tres fases que
     * avanzan solas, segundos a partir de 10 y un botón para cancelar.
     */
    function showThinking(onCancel) {
        const bubble = addMessage('assistant', '', 'ppa-msg--thinking');
        bubble.textContent = '';
        const row = document.createElement('div');
        row.className = 'ppa-thinking';
        const dots = document.createElement('span');
        dots.className = 'ppa-thinking__dots';
        dots.setAttribute('aria-hidden', 'true');
        for (let i = 0; i < 3; i += 1) dots.appendChild(document.createElement('i'));
        const label = document.createElement('span');
        label.className = 'ppa-thinking__label';
        label.setAttribute('role', 'status');
        const time = document.createElement('span');
        time.className = 'ppa-thinking__time';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'ppa-thinking__cancel';
        cancel.textContent = pp.t('js.as.cancel');
        cancel.addEventListener('click', onCancel);
        row.append(dots, label, time, cancel);
        bubble.appendChild(row);

        const stages = ['js.as.stage_read', 'js.as.stage_check', 'js.as.stage_write'];
        const started = Date.now();
        let stage = 0;
        label.textContent = pp.t(stages[0]);
        const timer = window.setInterval(() => {
            const seconds = Math.round((Date.now() - started) / 1000);
            const next = Math.min(stages.length - 1, Math.floor(seconds / 4));
            if (next !== stage) {
                stage = next;
                label.textContent = pp.t(stages[stage]);
            }
            time.textContent = seconds >= 10 ? pp.t('js.as.seconds', { n: seconds }) : '';
        }, 500);
        return {
            remove() {
                window.clearInterval(timer);
                const msg = bubble.closest('.ppa-msg');
                if (msg) msg.remove();
            }
        };
    }

    // Irse a mitad de un plan o, sobre todo, de una aplicación la corta.
    window.addEventListener('beforeunload', (event) => {
        if (!planning && !applying) return;
        event.preventDefault();
        event.returnValue = '';
    });

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
        const docs = readyDocs();
        if ((text === '' && (!richState || richState.images === 0) && docs.length === 0) || extracting || planning) return;
        if (richState && !richState.valid) return;

        // Continuación natural del plan mostrado: una confirmación breve
        // aplica el último plan en vez de pedir a la IA que planifique la frase
        // aislada "sí, procede" (que lógicamente no contiene cambios).
        if (docs.length === 0 && (!richState || richState.images === 0) && lastPlanContext && isAffirmativeReply(text)) {
            addMessage('user', text);
            logEntry({ type: 'user', text });
            if (richComposer) richComposer.reset();
            else input.value = '';
            const context = lastPlanContext;
            const applicable = context.plan.items.filter((it) => it.status === 'aplicar' && itemCategory(it) === 'automatable_now');
            await applyPlan(context.plan, applicable, context.button);
            refreshSendState();
            return;
        }

        let userLabel = text !== '' ? text : pp.t('js.as.default_request');
        docs.forEach((doc) => {
            userLabel += '\n[' + (doc.kind === 'pdf' ? 'PDF' : pp.t('js.as.doc_label')) + '] ' + doc.filename;
        });
        if (richState && richState.images > 0) {
            userLabel += '\n' + pp.t(richState.images === 1 ? 'js.as.images_attached_one' : 'js.as.images_attached_other', { n: richState.images });
        }
        addMessage('user', userLabel);

        const docText = docs.length === 1
            ? docs[0].text
            : docs.filter((doc) => doc.text).map((doc) => '[' + doc.filename + ']\n' + doc.text).join('\n\n');
        (richComposer ? richComposer.storedMedia() : []).forEach((media) => conversation.thumbs.set(Number(media.mediaId), media));
        if (richComposer) richComposer.reset();
        else input.value = '';
        clearDocs();

        planning = true;
        refreshSendState();
        setBusy(true);
        planAbort = typeof AbortController === 'function' ? new AbortController() : null;
        const thinking = showThinking(() => { if (planAbort) planAbort.abort(); });
        let cancelled = false;

        const body = new URLSearchParams({
            _csrf: cfg.csrf,
            instruction: text,
            doc_text: docText,
            rich_html: richHtml,
            history: JSON.stringify(conversation.turns),
            current_plan: JSON.stringify(condensePlan(conversation.plan)),
            source_token: conversation.sourceToken,
            context_type: cfg.context ? cfg.context.type : '',
            context_id: cfg.context ? String(cfg.context.id) : ''
        });
        try {
            const res = await fetch(cfg.baseUrl + '/plan', { method: 'POST', body, signal: planAbort ? planAbort.signal : undefined });
            const data = await res.json().catch(() => ({}));
            thinking.remove();
            if (!res.ok || !data.ok) {
                addMessage('assistant', data.error || pp.t('js.as.plan_failed'), 'ppa-msg--error');
                return;
            }
            data.plan._request = text !== '' ? text : userLabel;
            data.plan._thumbs = conversation.thumbs;
            renderPlan(data.plan);
            rememberTurn('user', userLabel);
            rememberTurn('assistant', data.plan.reply || data.plan.summary || '');
            conversation.plan = (data.plan.items || []).length > 0 ? data.plan : null;
            if (data.plan.source_token) conversation.sourceToken = data.plan.source_token;
            logEntry({ type: 'user', text: userLabel });
            logEntry({ type: 'plan', plan: data.plan });
            persistConversation();
        } catch (err) {
            thinking.remove();
            if (err && err.name === 'AbortError') {
                cancelled = true;
                addMessage('assistant', pp.t('js.as.plan_cancelled'), 'ppa-msg--muted');
            } else {
                addMessage('assistant', pp.t('js.as.plan_network'), 'ppa-msg--error');
            }
        } finally {
            planning = false;
            planAbort = null;
            setBusy(false);
            refreshSendState();
            // Respuesta o error: con el panel cerrado, el botón avisa.
            if (!cancelled) emit('ppa:news');
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
            purpose: 'assistant',
            uploadUrl: cfg.mediaUploadUrl,
            remoteImportUrl: cfg.remoteImportUrl,
            t: (key, vars) => pp.t(key, vars),
            onChooseMedia: openMediaPicker,
            onChange: refreshSendState
        }).enhance();
        richInput.addEventListener('ppa:send', sendRequest);
        if (pastePlainBtn) pastePlainBtn.addEventListener('click', () => richComposer.armPlainPaste());
        if (newChatBtn) newChatBtn.addEventListener('click', resetConversation);
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
    fileInput.addEventListener('change', () => handleFiles(fileInput.files));
    setupDropZone();
    input.addEventListener('input', refreshSendState);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            sendRequest();
        }
    });
    sendBtn.addEventListener('click', sendRequest);

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
    // ASST-V2 F2 — API mínima para el panel lateral.
    window.PPAssistant = {
        focus() {
            const target = richComposer && richInput && !richInput.hidden ? richInput : input;
            if (target) target.focus();
        },
        prefill(text) {
            const value = String(text || '');
            if (value === '') return;
            if (richComposer && richInput && !richInput.hidden) {
                richInput.textContent = value;
                richInput.dispatchEvent(new Event('input', { bubbles: true }));
            } else {
                input.value = value;
            }
            refreshSendState();
        },
        reset() {
            if (planning || applying) return;
            resetConversation();
            thread.classList.remove('is-fresh');
            void thread.offsetWidth; // reinicia la animación de «conversación nueva»
            thread.classList.add('is-fresh');
            window.PPAssistant.focus();
        }
    };

    function boot(saved) {
        // En el panel lateral el saludo es corto y, si sabe dónde estás, lo dice.
        if (cfg.mode === 'drawer') {
            const first = thread.querySelector('.ppa-msg__bubble');
            if (first) {
                first.textContent = cfg.context
                    ? pp.t('js.as.drawer_greeting_ctx', { pantalla: cfg.context.label })
                    : pp.t('js.as.drawer_greeting');
            }
        }
        restoreConversation(saved);
        refreshSendState();
        emit('ppa:ready');
    }
    if (cfg.lazyConversation) {
        fetch(cfg.baseUrl + '/conversations/current', { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => boot(data && data.ok ? data.conversation : null))
            .catch(() => boot(null));
    } else {
        boot(cfg.conversation);
    }
})();
