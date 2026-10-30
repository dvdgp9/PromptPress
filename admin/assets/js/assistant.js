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
        }, (key, value) => (['_thumbs', '_button', '_preview', '_ideas', '_length', '_reviewed'].includes(key) ? undefined : value));
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
                plan._restored = true;
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
            button.dataset.superseded = '1';
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

    // ------------------------------------------------------------------
    // ASST-V2 F3 — riesgo de cada cambio y vista previa de formularios
    // ------------------------------------------------------------------
    const RISK_META = {
        read:      { cls: 'is-read', label: pp.t('js.as.risk.read') },
        draft:     { cls: 'is-draft', label: pp.t('js.as.risk.draft') },
        live:      { cls: 'is-live', label: pp.t('js.as.risk.live') },
        sensitive: { cls: 'is-sensitive', label: pp.t('js.as.risk.sensitive') }
    };

    function renderRisk(it) {
        // Las ideas solo se leen, pero lo que se elija se escribe en borrador.
        const meta = it.capability_id === 'posts.ideas' || it.capability_id === 'pages.ideas'
            ? RISK_META.draft
            : RISK_META[it.risk] || (it.form_id ? RISK_META.live : RISK_META.draft);
        const line = document.createElement('div');
        line.className = 'ppa-risk ' + meta.cls;
        const dot = document.createElement('span');
        dot.className = 'ppa-risk__dot';
        dot.setAttribute('aria-hidden', 'true');
        line.appendChild(dot);
        const text = document.createElement('span');
        text.textContent = meta.label + (it.undo ? ' · ' + pp.t('js.as.risk.undo') : '');
        line.appendChild(text);
        return line;
    }

    // ASST-V2 F4f — servicios de reserva, productos y recursos.
    const CATALOG_EDIT = ['booking.service.edit', 'commerce.product.edit', 'resources.edit'];
    const CATALOG_CREATE = ['booking.service.create', 'commerce.product.create'];
    const CATALOG_KIND = {
        'booking.service.edit': 'service', 'booking.service.create': 'service',
        'commerce.product.edit': 'product', 'commerce.product.create': 'product', 'resources.edit': 'resource'
    };
    const isCatalogItem = (it) => Object.prototype.hasOwnProperty.call(CATALOG_KIND, it.capability_id);
    const CATALOG_TITLE = {
        'booking.service.edit': 'js.as.catalog_kind_service', 'commerce.product.edit': 'js.as.catalog_kind_product',
        'resources.edit': 'js.as.catalog_kind_resource', 'booking.service.create': 'js.as.catalog_new_service',
        'commerce.product.create': 'js.as.catalog_new_product'
    };

    function wantsPreview(it) {
        if (it.status !== 'aplicar') return false;
        return (it.capability_id === 'forms.edit' && Number(it.form_id) > 0)
            || (it.capability_id === 'seo.page.edit' && Number(it.page_id) > 0)
            || it.capability_id === 'chrome.edit'
            || it.capability_id === 'design.edit'
            || (CATALOG_EDIT.includes(it.capability_id) && Number(it.target_id) > 0)
            || CATALOG_CREATE.includes(it.capability_id)
            || it.capability_id === 'posts.ideas'
            || it.capability_id === 'pages.ideas'
            || it.capability_id === 'pages.create';
    }

    // ------------------------------------------------------------------
    // ASST-V2 F4d — plan de una página nueva (el mismo que /admin/pages/create).
    // Mientras se lee, la página se compone en segundo plano con el mismo
    // endpoint del creador de páginas: al aplicar, sale al instante.
    // ------------------------------------------------------------------
    function renderPageBrief(box, data, it, onSettled, plan) {
        const brief = data.brief || {};
        box.textContent = '';
        box.className = 'ppa-preview is-ready ppa-preview--page';

        const head = document.createElement('div');
        head.className = 'ppa-brief__head';
        const title = document.createElement('strong');
        title.className = 'ppa-brief__title';
        title.textContent = brief.title || '';
        head.appendChild(title);
        if (data.type) {
            const type = document.createElement('span');
            type.className = 'ppa-brief__type';
            type.textContent = data.type;
            head.appendChild(type);
        }
        box.appendChild(head);
        if (brief.goal) {
            const goal = document.createElement('p');
            goal.className = 'ppa-brief__goal';
            goal.textContent = brief.goal;
            box.appendChild(goal);
        }

        const sections = data.sections || [];
        if (sections.length > 0) {
            const label = document.createElement('p');
            label.className = 'ppa-brief__label';
            label.textContent = pp.t('js.as.page_sections', { n: sections.length });
            box.appendChild(label);
            const list = document.createElement('ol');
            list.className = 'ppa-brief__sections';
            sections.forEach((section) => {
                const li = document.createElement('li');
                const h = document.createElement('span');
                h.className = 'ppa-brief__section';
                h.textContent = section.heading || section.purpose;
                li.appendChild(h);
                if (section.heading && section.purpose) {
                    const purpose = document.createElement('span');
                    purpose.className = 'ppa-brief__purpose';
                    purpose.textContent = section.purpose;
                    li.appendChild(purpose);
                }
                list.appendChild(li);
            });
            box.appendChild(list);
        }

        const facts = document.createElement('div');
        facts.className = 'ppa-brief__facts';
        const fact = (labelKey, value) => {
            if (!value) return;
            const row = document.createElement('p');
            const k = document.createElement('span');
            k.textContent = pp.t(labelKey);
            row.appendChild(k);
            row.appendChild(document.createTextNode(value));
            facts.appendChild(row);
        };
        fact('js.as.page_cta', brief.primary_cta);
        fact('js.as.page_audience', brief.audience);
        if ((data.form || []).length > 0) fact('js.as.page_form', data.form.join(', '));
        if (facts.childNodes.length > 0) box.appendChild(facts);

        if (data.fallback) {
            const warn = document.createElement('p');
            warn.className = 'ppa-brief__warn';
            warn.textContent = pp.t('js.as.page_fallback');
            box.appendChild(warn);
        }

        const tools = document.createElement('div');
        tools.className = 'ppa-ideas__tools';
        const status = document.createElement('span');
        status.className = 'ppa-brief__status';
        status.setAttribute('role', 'status');
        tools.appendChild(status);
        const again = document.createElement('button');
        again.type = 'button';
        again.className = 'ppa-ideas__more';
        again.textContent = pp.t('js.as.page_again');
        again.addEventListener('click', () => loadPreview(it, box, onSettled));
        tools.appendChild(again);
        box.appendChild(tools);

        // Una sola página en el plan: hay un único hueco de precomposición.
        const pagesInPlan = (plan && plan.items ? plan.items : []).filter((x) => x.capability_id === 'pages.create').length;
        if (pagesInPlan === 1) {
            status.classList.add('is-working');
            status.textContent = pp.t('js.as.page_preparing');
            const body = new URLSearchParams({ _csrf: cfg.csrf, ai_brief_json: JSON.stringify(brief), ai_model: '' });
            fetch(adminUrl + '/pages/ai-prepare', { method: 'POST', credentials: 'same-origin', body })
                .then((res) => res.json().catch(() => ({})))
                .then((d) => {
                    status.classList.remove('is-working');
                    status.textContent = d && d.ready ? pp.t('js.as.page_ready') : pp.t('js.as.page_compose_on_apply');
                    status.classList.toggle('is-ready', !!(d && d.ready));
                })
                .catch(() => {
                    status.classList.remove('is-working');
                    status.textContent = pp.t('js.as.page_compose_on_apply');
                });
        }
    }

    // ------------------------------------------------------------------
    // ASST-V2 F4c — ideas para el blog: se eligen tocándolas y «Aplicar»
    // escribe las elegidas como borradores (como en /admin/posts/new).
    // ------------------------------------------------------------------
    const LENGTHS = ['corto', 'medio', 'largo'];

    function renderIdeas(box, data, it, onSettled) {
        box.textContent = '';
        box.className = 'ppa-preview is-ready ppa-preview--ideas';
        it._ideas = (data.ideas || []).map((idea) => Object.assign({ selected: false }, idea));
        it._length = it._length || 'medio';

        const hint = document.createElement('p');
        hint.className = 'ppa-ideas__hint';
        const forPages = it.capability_id === 'pages.ideas';
        hint.textContent = pp.t(forPages ? 'js.as.page_ideas_hint' : 'js.as.ideas_hint');
        box.appendChild(hint);

        const list = document.createElement('div');
        list.className = 'ppa-ideas';
        list.setAttribute('role', 'group');
        list.setAttribute('aria-label', pp.t(forPages ? 'js.as.page_ideas_title' : 'js.as.ideas_title'));
        it._ideas.forEach((idea) => {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'ppa-idea';
            tile.setAttribute('role', 'checkbox');
            tile.setAttribute('aria-checked', 'false');
            const check = document.createElement('span');
            check.className = 'ppa-idea__check';
            check.setAttribute('aria-hidden', 'true');
            tile.appendChild(check);
            const text = document.createElement('span');
            text.className = 'ppa-idea__text';
            const title = document.createElement('span');
            title.className = 'ppa-idea__title';
            title.textContent = idea.title;
            text.appendChild(title);
            if (idea.angle) {
                const angle = document.createElement('span');
                angle.className = 'ppa-idea__angle';
                angle.textContent = idea.angle;
                text.appendChild(angle);
            }
            if (idea.why_now) {
                const why = document.createElement('span');
                why.className = 'ppa-idea__why';
                why.textContent = idea.why_now;
                text.appendChild(why);
            }
            tile.appendChild(text);
            tile.addEventListener('click', () => {
                idea.selected = !idea.selected;
                tile.classList.toggle('is-selected', idea.selected);
                tile.setAttribute('aria-checked', idea.selected ? 'true' : 'false');
                onSettled();
            });
            list.appendChild(tile);
        });
        box.appendChild(list);

        const tools = document.createElement('div');
        tools.className = 'ppa-ideas__tools';
        if (forPages) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'ppa-ideas__more';
            more.textContent = pp.t('js.as.ideas_more');
            more.addEventListener('click', () => loadPreview(it, box, onSettled));
            tools.appendChild(more);
            box.appendChild(tools);
            return;
        }
        const lengthLabel = document.createElement('span');
        lengthLabel.className = 'ppa-ideas__label';
        lengthLabel.textContent = pp.t('js.as.ideas_length');
        tools.appendChild(lengthLabel);
        const seg = document.createElement('div');
        seg.className = 'ppa-seg ppa-seg--inline';
        seg.setAttribute('role', 'radiogroup');
        seg.setAttribute('aria-label', pp.t('js.as.ideas_length'));
        const names = { corto: pp.t('js.as.length_short'), medio: pp.t('js.as.length_medium'), largo: pp.t('js.as.length_long') };
        LENGTHS.forEach((value) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ppa-seg__btn';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', it._length === value ? 'true' : 'false');
            b.setAttribute('aria-selected', it._length === value ? 'true' : 'false');
            b.textContent = names[value];
            b.addEventListener('click', () => {
                it._length = value;
                seg.querySelectorAll('.ppa-seg__btn').forEach((other) => {
                    const on = other === b;
                    other.setAttribute('aria-checked', on ? 'true' : 'false');
                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            });
            seg.appendChild(b);
        });
        tools.appendChild(seg);
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'ppa-ideas__more';
        more.textContent = pp.t('js.as.ideas_more');
        more.addEventListener('click', () => loadPreview(it, box, onSettled));
        tools.appendChild(more);
        box.appendChild(tools);
    }

    /** Entradas a escribir que salen de las ideas elegidas. */
    function ideaItems(it) {
        const capability = it.capability_id === 'pages.ideas' ? 'pages.create' : 'posts.create';
        return (it._ideas || []).filter((idea) => idea.selected).map((idea) => ({
            capability_id: capability,
            category: 'automatable_now',
            status: 'aplicar',
            page_id: 0,
            form_id: 0,
            section: '',
            page_title: idea.title,
            instruction: idea.instruction,
            preview_token: idea.token,
            length: it._length || 'medio',
            risk: 'draft',
            undo: false
        }));
    }

    /** Lo que mandaría «Aplicar»: los cambios directos y las ideas elegidas. */
    function applicableItems(items) {
        const out = [];
        items.forEach((it) => {
            if (it.status !== 'aplicar' || itemCategory(it) !== 'automatable_now') return;
            if (it.capability_id === 'posts.ideas' || it.capability_id === 'pages.ideas') out.push(...ideaItems(it));
            // Una edición del catálogo solo se aplica tal como se vio.
            else if (CATALOG_EDIT.includes(it.capability_id) && !(it._preview && it._preview.state === 'ready')) return;
            else out.push(it);
        });
        return out;
    }

    function postTopic(it) {
        const m = /«([^»]{3,200})»/.exec(it.instruction || '');
        return m ? m[1] : (it.page_title || it.instruction || '');
    }

    /** ASST-V2 F4b — marcas por elemento del menú: key => added|changed|removed. */
    function chromeMarks(before, after) {
        const flat = (menu) => {
            const map = new Map();
            (menu || []).forEach((it) => {
                map.set(it.key, { label: it.label, visible: it.visible, parent: '' });
                (it.children || []).forEach((c) => map.set(c.key, { label: c.label, visible: c.visible, parent: it.label }));
            });
            return map;
        };
        const a = flat(before.menu);
        const b = flat(after.menu);
        const afterMarks = {};
        const beforeMarks = {};
        b.forEach((v, key) => {
            if (!a.has(key)) afterMarks[key] = 'added';
            else if (JSON.stringify(a.get(key)) !== JSON.stringify(v)) { afterMarks[key] = 'changed'; beforeMarks[key] = 'changed'; }
        });
        a.forEach((v, key) => { if (!b.has(key)) beforeMarks[key] = 'removed'; });
        return { before: beforeMarks, after: afterMarks };
    }

    function renderChromeMock(outline, marks) {
        const mock = document.createElement('div');
        mock.className = 'ppa-chrome';
        const head = document.createElement('div');
        head.className = 'ppa-chrome__header';
        const tag = document.createElement('span');
        tag.className = 'ppa-chrome__tag';
        tag.textContent = pp.t('js.as.chrome_header');
        head.appendChild(tag);
        const nav = document.createElement('ul');
        nav.className = 'ppa-chrome__menu';
        (outline.menu || []).forEach((it) => {
            const li = document.createElement('li');
            li.className = 'ppa-chrome__item' + (marks[it.key] ? ' is-' + marks[it.key] : '') + (it.visible ? '' : ' is-hidden');
            const label = document.createElement('span');
            label.className = 'ppa-chrome__label';
            label.textContent = it.label;
            li.appendChild(label);
            if ((it.children || []).length > 0) {
                li.classList.add('has-children');
                const sub = document.createElement('ul');
                sub.className = 'ppa-chrome__sub';
                it.children.forEach((c) => {
                    const cli = document.createElement('li');
                    cli.className = 'ppa-chrome__item' + (marks[c.key] ? ' is-' + marks[c.key] : '') + (c.visible ? '' : ' is-hidden');
                    cli.textContent = c.label;
                    sub.appendChild(cli);
                });
                li.appendChild(sub);
            }
            nav.appendChild(li);
        });
        head.appendChild(nav);
        const cta = outline.cta || {};
        if (cta.mode !== 'off') {
            const btn = document.createElement('span');
            btn.className = 'ppa-chrome__cta' + (cta.mode === 'auto' ? ' is-auto' : '');
            btn.textContent = cta.mode === 'custom' && cta.label ? cta.label : pp.t('js.as.chrome_cta_auto');
            head.appendChild(btn);
        }
        mock.appendChild(head);

        const f = outline.footer || {};
        const foot = document.createElement('div');
        foot.className = 'ppa-chrome__footer';
        const ftag = document.createElement('span');
        ftag.className = 'ppa-chrome__tag';
        ftag.textContent = pp.t('js.as.chrome_footer');
        foot.appendChild(ftag);
        const line = (text, cls) => {
            const p = document.createElement('p');
            p.className = 'ppa-chrome__line' + (cls ? ' ' + cls : '');
            p.textContent = text;
            foot.appendChild(p);
        };
        line(f.tagline || pp.t('js.as.chrome_auto_tagline'), f.tagline ? '' : 'is-auto');
        line((f.nav || []).length > 0 ? f.nav.map((n) => n.label).join(' · ') : pp.t('js.as.chrome_auto_nav'), (f.nav || []).length > 0 ? '' : 'is-auto');
        const contact = Object.values(f.contact || {}).filter(Boolean);
        if (contact.length > 0) line(contact.join(' · '));
        if ((f.social || []).length > 0) line(f.social.join(' · '));
        line(f.copyright || pp.t('js.as.chrome_auto_copyright'), f.copyright ? 'is-small' : 'is-small is-auto');
        mock.appendChild(foot);
        return mock;
    }

    // ------------------------------------------------------------------
    // ASST-V2 F4e — diseño global: una web en miniatura pintada con los
    // colores, fuentes y radios de cada versión.
    // ------------------------------------------------------------------
    function loadDesignFonts(data) {
        if (data.font_href && !document.querySelector('link[data-ppa-font="' + data.font_href + '"]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = data.font_href;
            link.dataset.ppaFont = data.font_href;
            document.head.appendChild(link);
        }
        // Fuentes propias del sitio: sus @font-face los genera el servidor.
        if (data.font_css && !document.getElementById('ppa-site-fonts')) {
            const holder = document.createElement('div');
            holder.innerHTML = data.font_css;
            const style = holder.querySelector('style');
            if (style) {
                style.id = 'ppa-site-fonts';
                document.head.appendChild(style);
            }
        }
    }

    function labelOn(hex) {
        const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
        if (!m) return '#ffffff';
        const lin = (v) => { v = parseInt(v, 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
        const l = 0.2126 * lin(m[1]) + 0.7152 * lin(m[2]) + 0.0722 * lin(m[3]);
        return (1.05 / (l + 0.05)) >= ((l + 0.05) / 0.05) ? '#ffffff' : '#111111';
    }

    function swatch(hex) {
        const s = document.createElement('span');
        s.className = 'ppa-swatch';
        s.style.setProperty('--sw', hex);
        s.setAttribute('aria-hidden', 'true');
        return s;
    }

    function renderDesignMock(tokens, data, changedKeys) {
        const c = tokens.colors || {};
        const t = tokens.typography || {};
        const fonts = data.fonts || {};
        const mock = document.createElement('div');
        mock.className = 'ppa-dmock';
        const vars = {
            '--m-primary': c.primary, '--m-primary-dark': c.primary_dark, '--m-on-primary': labelOn(c.primary),
            '--m-accent': c.accent, '--m-on-accent': labelOn(c.accent), '--m-bg': c.bg, '--m-surface': c.surface,
            '--m-text': c.text, '--m-muted': c.text_muted, '--m-border': c.border,
            '--m-heading': (fonts[t.font_heading] || {}).css || 'inherit', '--m-body': (fonts[t.font_body] || {}).css || 'inherit',
            '--m-size': String(Number(t.base_size) || 16),
            '--m-btn-radius': (Number((tokens.buttons || {}).radius) || 0) + 'px',
            '--m-card-radius': (Number((tokens.spacing || {}).radius_card) || 0) + 'px'
        };
        Object.keys(vars).forEach((k) => { if (vars[k]) mock.style.setProperty(k, vars[k]); });

        const bar = document.createElement('div');
        bar.className = 'ppa-dmock__bar';
        const brand = document.createElement('span');
        brand.className = 'ppa-dmock__brand';
        brand.textContent = pp.t('js.as.design_mock_brand');
        bar.appendChild(brand);
        const nav = document.createElement('span');
        nav.className = 'ppa-dmock__nav';
        nav.textContent = pp.t('js.as.design_mock_nav');
        bar.appendChild(nav);
        const cta = document.createElement('span');
        cta.className = 'ppa-dmock__btn is-small';
        cta.textContent = pp.t('js.as.design_mock_cta');
        bar.appendChild(cta);
        mock.appendChild(bar);

        const hero = document.createElement('div');
        hero.className = 'ppa-dmock__hero';
        const h = document.createElement('p');
        h.className = 'ppa-dmock__title';
        h.textContent = pp.t('js.as.design_mock_title');
        hero.appendChild(h);
        const p = document.createElement('p');
        p.className = 'ppa-dmock__text';
        p.textContent = pp.t('js.as.design_mock_text');
        hero.appendChild(p);
        const actions = document.createElement('div');
        actions.className = 'ppa-dmock__actions';
        const primary = document.createElement('span');
        primary.className = 'ppa-dmock__btn';
        primary.textContent = pp.t('js.as.design_mock_cta');
        const ghost = document.createElement('span');
        ghost.className = 'ppa-dmock__btn is-ghost';
        ghost.textContent = pp.t('js.as.design_mock_ghost');
        actions.append(primary, ghost);
        hero.appendChild(actions);
        mock.appendChild(hero);

        const card = document.createElement('div');
        card.className = 'ppa-dmock__card';
        const tag = document.createElement('span');
        tag.className = 'ppa-dmock__tag';
        tag.textContent = pp.t('js.as.design_mock_tag');
        const ct = document.createElement('p');
        ct.className = 'ppa-dmock__card-title';
        ct.textContent = pp.t('js.as.design_mock_card_title');
        const cx = document.createElement('p');
        cx.className = 'ppa-dmock__text';
        cx.textContent = pp.t('js.as.design_mock_card_text');
        card.append(tag, ct, cx);
        mock.appendChild(card);

        const strip = document.createElement('ul');
        strip.className = 'ppa-dmock__palette';
        strip.setAttribute('aria-label', pp.t('js.as.design_palette'));
        Object.keys(data.labels || {}).forEach((key) => {
            const li = document.createElement('li');
            if (changedKeys.has('colors.' + key)) li.className = 'is-changed';
            li.title = data.labels[key] + ' ' + c[key];
            li.appendChild(swatch(c[key]));
            const name = document.createElement('span');
            name.textContent = data.labels[key];
            li.appendChild(name);
            strip.appendChild(li);
        });
        mock.appendChild(strip);
        return mock;
    }

    function catalogDayChanged(data, d) {
        if (!data.before || !data.before.hours) return false;
        return JSON.stringify(data.before.hours[d] || []) !== JSON.stringify(data.after.hours[d] || []);
    }

    function renderCatalogCard(state, data, changedKeys, which) {
        const kind = data.item_kind;
        const card = document.createElement('div');
        card.className = 'ppa-cat ppa-cat--' + kind;
        const mark = (el, key) => {
            if (which === 'after' && changedKeys.has(key)) el.classList.add('is-changed');
            return el;
        };
        const line = (cls, text, key) => {
            const p = document.createElement('p');
            p.className = 'ppa-cat__' + cls;
            p.textContent = text;
            card.appendChild(key ? mark(p, key) : p);
            return p;
        };
        if (kind === 'resource' && state.category) line('tag', state.category, 'category');
        line('title', state.name || state.title || '', kind === 'resource' ? 'title' : 'name');
        if (kind === 'service') {
            const meta = document.createElement('div');
            meta.className = 'ppa-cat__meta';
            [['duration_min', state.duration_min + ' min'], ['price_label', state.price_label || pp.t('js.as.catalog_no_price')],
                ['capacity', pp.t(Number(state.capacity) === 1 ? 'js.as.catalog_places_one' : 'js.as.catalog_places', { n: state.capacity })]]
                .forEach(([key, text]) => {
                    const chip = document.createElement('span');
                    chip.textContent = text;
                    meta.appendChild(mark(chip, key));
                });
            card.appendChild(meta);
        }
        if (kind === 'product') {
            line('price', state.price || '', 'price_cents');
            line('small', state.stock === null || state.stock === undefined ? pp.t('js.as.catalog_stock_unlimited') : pp.t('js.as.catalog_stock', { n: state.stock }), 'stock');
        }
        if (state.description) line('desc', state.description, 'description');
        if (kind === 'service') {
            const grid = document.createElement('dl');
            grid.className = 'ppa-cat__hours';
            grid.setAttribute('aria-label', pp.t('js.as.catalog_hours'));
            (data.weekdays || []).forEach((day, d) => {
                const row = document.createElement('div');
                row.className = 'ppa-cat__day' + (which === 'after' && catalogDayChanged(data, d) ? ' is-changed' : '');
                const dt = document.createElement('dt');
                dt.textContent = day;
                const dd = document.createElement('dd');
                const ranges = (state.hours || [])[d] || [];
                dd.textContent = ranges.length ? ranges.map((r) => r.start + '-' + r.end).join(', ') : pp.t('js.as.catalog_closed');
                if (!ranges.length) dd.className = 'is-closed';
                row.append(dt, dd);
                grid.appendChild(row);
            });
            card.appendChild(grid);
            line('small', pp.t('js.as.catalog_rules', { margen: state.buffer_min, antelacion: state.min_notice_hours, dias: state.max_advance_days }),
                ['buffer_min', 'min_notice_hours', 'max_advance_days'].find((k) => changedKeys.has(k)));
        }
        return card;
    }

    /** «Lo he revisado»: sin marcarlo, «Aplicar» no manda un cambio sensible. */
    function renderCatalogReview(box, it, onSettled) {
        const label = document.createElement('label');
        label.className = 'ppa-review';
        const check = document.createElement('input');
        check.type = 'checkbox';
        check.checked = it._reviewed === true;
        check.addEventListener('change', () => { it._reviewed = check.checked; onSettled(); });
        const text = document.createElement('span');
        text.textContent = pp.t(it.capability_id === 'commerce.product.edit' ? 'js.as.catalog_review_product' : 'js.as.catalog_review_service');
        label.append(check, text);
        box.appendChild(label);
    }

    /** ASST-V2 F4a — cómo saldría la página en Google. */
    function renderSnippet(snippet, limits) {
        const box = document.createElement('div');
        box.className = 'ppa-snippet';
        const url = document.createElement('span');
        url.className = 'ppa-snippet__url';
        url.textContent = String(snippet.url || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
        box.appendChild(url);
        const title = document.createElement('span');
        title.className = 'ppa-snippet__title';
        title.textContent = snippet.title || '';
        box.appendChild(title);
        const desc = document.createElement('span');
        desc.className = 'ppa-snippet__desc' + (snippet.description ? '' : ' is-empty');
        desc.textContent = snippet.description || pp.t('js.as.seo_no_description');
        box.appendChild(desc);
        const meter = document.createElement('div');
        meter.className = 'ppa-snippet__meter';
        [['title', (snippet.fallback ? '' : snippet.title) || '', 'js.as.seo_title_chars'],
            ['description', snippet.description || '', 'js.as.seo_desc_chars']].forEach(([key, text, label]) => {
            const limit = Number((limits || {})[key] || 0);
            const span = document.createElement('span');
            const n = Array.from(text).length;
            span.className = limit > 0 && n > limit ? 'is-over' : '';
            span.textContent = pp.t(label, { n: n, max: limit });
            meter.appendChild(span);
        });
        box.appendChild(meter);
        return box;
    }

    /** Maqueta compacta del formulario. `marks` = name => 'added'|'changed'|'removed'. */
    function renderFormMock(outline, marks) {
        const mock = document.createElement('div');
        mock.className = 'ppa-mock';
        if (outline.heading) {
            const h = document.createElement('p');
            h.className = 'ppa-mock__heading';
            h.textContent = outline.heading;
            mock.appendChild(h);
        }
        (outline.fields || []).forEach((field) => {
            const row = document.createElement('div');
            row.className = 'ppa-mock__field' + (marks[field.name] ? ' is-' + marks[field.name] : '');
            const label = document.createElement('span');
            label.className = 'ppa-mock__label';
            label.textContent = field.label + (field.required ? ' *' : '');
            row.appendChild(label);
            const options = field.options || [];
            if ((field.type === 'radio' || field.type === 'checkbox_group') && options.length > 0) {
                const list = document.createElement('div');
                list.className = 'ppa-mock__choices';
                options.slice(0, 12).forEach((option) => {
                    const choice = document.createElement('span');
                    choice.className = 'ppa-mock__choice ppa-mock__choice--' + (field.type === 'radio' ? 'radio' : 'check');
                    choice.textContent = option;
                    list.appendChild(choice);
                });
                if (options.length > 12) list.appendChild(document.createTextNode(pp.t('js.as.preview_more', { n: options.length - 12 })));
                row.appendChild(list);
            } else if (field.type === 'checkbox') {
                row.classList.add('ppa-mock__field--check');
            } else {
                const control = document.createElement('span');
                control.className = 'ppa-mock__control ppa-mock__control--' + (field.type === 'textarea' ? 'area' : field.type === 'select' ? 'select' : field.type === 'file' ? 'file' : 'line');
                if (field.type === 'select' && options.length > 0) control.textContent = options[0];
                row.appendChild(control);
                if (field.type === 'select' && options.length > 1) {
                    const all = document.createElement('div');
                    all.className = 'ppa-mock__chips';
                    options.slice(0, 12).forEach((option) => {
                        const chip = document.createElement('span');
                        chip.textContent = option;
                        all.appendChild(chip);
                    });
                    if (options.length > 12) all.appendChild(document.createTextNode(pp.t('js.as.preview_more', { n: options.length - 12 })));
                    row.appendChild(all);
                }
            }
            mock.appendChild(row);
        });
        const submit = document.createElement('span');
        submit.className = 'ppa-mock__submit';
        submit.textContent = outline.submit_text || pp.t('js.as.preview_submit');
        mock.appendChild(submit);
        return mock;
    }

    function fieldMarks(before, after) {
        const old = new Map((before.fields || []).map((f) => [f.name, JSON.stringify(f)]));
        const now = new Set((after.fields || []).map((f) => f.name));
        const afterMarks = {};
        (after.fields || []).forEach((f) => {
            if (!old.has(f.name)) afterMarks[f.name] = 'added';
            else if (old.get(f.name) !== JSON.stringify(f)) afterMarks[f.name] = 'changed';
        });
        const beforeMarks = {};
        (before.fields || []).forEach((f) => {
            if (!now.has(f.name)) beforeMarks[f.name] = 'removed';
            else if (afterMarks[f.name]) beforeMarks[f.name] = 'changed';
        });
        return { before: beforeMarks, after: afterMarks };
    }

    function renderPreviewResult(box, data, it, onSettled) {
        if (data.kind === 'ideas') {
            renderIdeas(box, data, it, onSettled);
            return;
        }
        if (data.kind === 'page') {
            renderPageBrief(box, data, it, onSettled, it._plan);
            return;
        }
        box.textContent = '';
        box.className = 'ppa-preview is-ready';
        const changes = data.changes || [];
        const list = document.createElement('ul');
        list.className = 'ppa-preview__changes';
        if (changes.length === 0) {
            const li = document.createElement('li');
            li.className = 'is-none';
            li.textContent = pp.t('js.as.preview_no_changes');
            list.appendChild(li);
        }
        changes.forEach((change) => {
            const li = document.createElement('li');
            li.className = 'is-' + change.kind;
            const sign = document.createElement('span');
            sign.className = 'ppa-preview__sign';
            sign.setAttribute('aria-hidden', 'true');
            sign.textContent = { added: '+', removed: '−', changed: '~', text: '~' }[change.kind] || '·';
            li.appendChild(sign);
            const what = document.createElement('strong');
            what.textContent = change.label;
            li.appendChild(what);
            if (change.type === 'color') {
                li.appendChild(document.createTextNode(': '));
                li.append(swatch(change.from), document.createTextNode(change.from + ' → '), swatch(change.to), document.createTextNode(change.to));
            } else if (change.detail) li.appendChild(document.createTextNode(': ' + change.detail));
            if (change.sensitive) {
                const tag = document.createElement('span');
                tag.className = 'ppa-preview__flag';
                tag.textContent = pp.t('js.as.catalog_check');
                li.appendChild(tag);
            }
            const sr = document.createElement('span');
            sr.className = 'pp-sr-only';
            sr.textContent = {
                added: pp.t('js.as.preview_kind_added'),
                removed: pp.t('js.as.preview_kind_removed'),
                changed: pp.t('js.as.preview_kind_changed'),
                text: pp.t('js.as.preview_kind_text')
            }[change.kind] || '';
            li.prepend(sr);
            list.appendChild(li);
        });
        box.appendChild(list);
        (data.warnings || []).concat(data.notes || []).forEach((text, i) => {
            const p = document.createElement('p');
            p.className = i < (data.warnings || []).length ? 'ppa-preview__warn' : 'ppa-preview__note';
            p.textContent = i < (data.warnings || []).length ? pp.t('js.as.design_contrast', { pares: text }) : text;
            box.appendChild(p);
        });

        const isSeo = data.kind === 'seo';
        const isChrome = data.kind === 'chrome';
        const isDesign = data.kind === 'design';
        const isCatalog = data.kind === 'catalog';
        if (isDesign) loadDesignFonts(data);
        const changedKeys = new Set(changes.map((ch) => ch.key));
        if (isCatalog) {
            it._preview.sensitive = data.sensitive === true;
            if (data.is_new) {
                list.remove();
                const note = document.createElement('p');
                note.className = 'ppa-preview__note';
                note.textContent = pp.t('js.as.catalog_new_inactive');
                box.prepend(note);
                const stage = document.createElement('div');
                stage.className = 'ppa-preview__stage';
                stage.appendChild(renderCatalogCard(data.after, data, changedKeys, 'new'));
                box.appendChild(stage);
                return;
            }
        }
        const marks = isSeo || isDesign || isCatalog ? { before: {}, after: {} } : isChrome ? chromeMarks(data.before, data.after) : fieldMarks(data.before, data.after);
        const tabs = document.createElement('div');
        tabs.className = 'ppa-seg';
        tabs.setAttribute('role', 'tablist');
        const stage = document.createElement('div');
        stage.className = 'ppa-preview__stage';
        const views = isCatalog ? {
            after: () => renderCatalogCard(data.after, data, changedKeys, 'after'),
            before: () => renderCatalogCard(data.before, data, changedKeys, 'before')
        } : isDesign ? {
            after: () => renderDesignMock(data.after, data, changedKeys),
            before: () => renderDesignMock(data.before, data, changedKeys)
        } : isChrome ? {
            after: () => renderChromeMock(data.after, marks.after),
            before: () => renderChromeMock(data.before, marks.before)
        } : isSeo ? {
            after: () => renderSnippet(data.after, data.limits),
            before: () => renderSnippet(data.before, data.limits)
        } : {
            after: () => renderFormMock(data.after, marks.after),
            before: () => renderFormMock(data.before, marks.before)
        };
        const buttons = {};
        const show = (which) => {
            Object.keys(buttons).forEach((key) => {
                buttons[key].setAttribute('aria-selected', key === which ? 'true' : 'false');
                buttons[key].tabIndex = key === which ? 0 : -1;
            });
            stage.textContent = '';
            stage.dataset.view = which;
            stage.appendChild(views[which]());
        };
        ['after', 'before'].forEach((which) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ppa-seg__btn';
            b.setAttribute('role', 'tab');
            b.textContent = which === 'after' ? pp.t('js.as.preview_after') : pp.t('js.as.preview_before');
            b.addEventListener('click', () => show(which));
            b.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                const next = which === 'after' ? 'before' : 'after';
                show(next);
                buttons[next].focus();
            });
            buttons[which] = b;
            tabs.appendChild(b);
        });
        box.appendChild(tabs);
        box.appendChild(stage);
        show('after');
        if (isCatalog && data.sensitive) renderCatalogReview(box, it, onSettled);
    }

    async function loadPreview(it, box, onSettled) {
        it._preview = { state: 'loading' };
        box.textContent = '';
        box.className = 'ppa-preview is-loading';
        box.setAttribute('aria-busy', 'true');
        const label = document.createElement('span');
        label.className = 'ppa-preview__loading';
        label.textContent = pp.t(
            it.capability_id === 'posts.ideas' ? 'js.as.ideas_loading'
                : it.capability_id === 'pages.ideas' ? 'js.as.page_ideas_loading'
                    : it.capability_id === 'pages.create' ? 'js.as.page_brief_loading'
                        : 'js.as.preview_loading'
        );
        box.appendChild(label);
        const skel = document.createElement('div');
        skel.className = 'ppa-preview__skeleton';
        skel.setAttribute('aria-hidden', 'true');
        for (let i = 0; i < 3; i++) skel.appendChild(document.createElement('span'));
        box.appendChild(skel);
        onSettled();
        try {
            const res = await fetch(cfg.baseUrl + '/preview', {
                method: 'POST',
                body: new URLSearchParams({
                    _csrf: cfg.csrf,
                    item: JSON.stringify({ capability_id: it.capability_id, form_id: it.form_id, page_id: it.page_id, target_id: it.target_id, instruction: it.instruction })
                })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.error || pp.t('js.as.preview_failed'));
            it._preview = { state: 'ready', token: data.token };
            renderPreviewResult(box, data.preview, it, onSettled);
        } catch (error) {
            it._preview = { state: 'failed' };
            box.textContent = '';
            box.className = 'ppa-preview is-failed';
            const msg = document.createElement('span');
            msg.textContent = (error && error.message ? error.message : pp.t('js.as.preview_failed')) + ' ' + pp.t('js.as.preview_still_apply');
            box.appendChild(msg);
            box.appendChild(previewButton(it, box, onSettled, pp.t('js.as.preview_retry')));
        } finally {
            box.removeAttribute('aria-busy');
            onSettled();
        }
    }

    function previewButton(it, box, onSettled, text) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'pp-btn pp-btn--secondary pp-btn--sm ppa-preview__retry';
        b.textContent = text;
        b.addEventListener('click', () => loadPreview(it, box, onSettled));
        return b;
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
        const previewQueue = [];
        let previewSettled = () => {};
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
                    : it.capability_id === 'chrome.edit'
                        ? pp.t('js.as.chrome_title')
                    : it.capability_id === 'design.edit'
                        ? pp.t('js.as.design_title')
                    : CATALOG_CREATE.includes(it.capability_id) || (CATALOG_EDIT.includes(it.capability_id) && it.target_title)
                        ? pp.t(CATALOG_TITLE[it.capability_id], { nombre: it.target_title })
                    : it.capability_id === 'posts.ideas'
                        ? pp.t('js.as.ideas_title')
                    : it.capability_id === 'pages.ideas'
                        ? pp.t('js.as.page_ideas_title')
                    : it.capability_id === 'pages.create'
                        ? pp.t('js.as.page_new_x', { pagina: postTopic(it) })
                    : it.capability_id === 'posts.create'
                        ? pp.t('js.as.post_x', { titulo: postTopic(it) })
                    : it.capability_id === 'seo.page.edit' && it.page_title
                        ? pp.t('js.as.seo_x', { pagina: it.page_title })
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

                if (category === 'automatable_now') {
                    if (wantsPreview(it) && !plan._applied) {
                        Object.defineProperty(it, '_plan', { value: plan, enumerable: false, configurable: true });
                        const box = document.createElement('div');
                        box.className = 'ppa-preview';
                        card.appendChild(box);
                        // Un plan recuperado no vuelve a gastar IA al abrirse: a petición.
                        if (plan._restored) box.appendChild(previewButton(it, box, () => previewSettled(), pp.t(it.capability_id === 'posts.ideas' || it.capability_id === 'pages.ideas' ? 'js.as.ideas_show' : 'js.as.preview_show')));
                        else previewQueue.push(() => loadPreview(it, box, () => previewSettled()));
                    }
                    card.appendChild(renderRisk(it));
                }

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
            const hasIdeas = actionable.some((it) => it.capability_id === 'posts.ideas' || it.capability_id === 'pages.ideas');
            const refresh = () => {
                const applicable = applicableItems(items);
                const preparing = actionable.some((it) => it._preview && it._preview.state === 'loading');
                const needsReview = applicable.some((it) => it._preview && it._preview.sensitive && it._reviewed !== true);
                const onlyPosts = applicable.length > 0 && applicable.every((it) => it.capability_id === 'posts.create');
                const onlyPages = applicable.length > 0 && applicable.every((it) => it.capability_id === 'pages.create');
                btn.disabled = applicable.length === 0 || preparing || needsReview || !!plan._applied;
                btn.classList.toggle('is-waiting', preparing);
                if (plan._applied) btn.textContent = pp.t('js.as.plan_applied');
                else if (preparing) btn.textContent = pp.t('js.as.preview_wait');
                else if (needsReview) btn.textContent = pp.t('js.as.catalog_review_first');
                else if (applicable.length === 0) btn.textContent = pp.t(
                    actionable.some((it) => it.capability_id === 'pages.ideas') ? 'js.as.page_ideas_pick'
                        : hasIdeas ? 'js.as.ideas_pick' : 'js.as.confirm_above'
                );
                else if (onlyPages) btn.textContent = pp.t(applicable.length === 1 ? 'js.as.create_page_one' : 'js.as.create_page_n', { n: applicable.length });
                else if (onlyPosts) btn.textContent = pp.t(applicable.length === 1 ? 'js.as.write_one' : 'js.as.write_n', { n: applicable.length });
                else btn.textContent = pp.t(applicable.length === 1 ? 'js.as.apply_one' : 'js.as.apply_n', { n: applicable.length });
            };
            btn.addEventListener('click', () => {
                const applicable = applicableItems(items);
                if (applicable.length > 0) applyPlan(plan, applicable, btn);
            });
            foot.appendChild(btn);
            // Lo que no se ve en las tarjetas: qué pasa con las páginas.
            if (actionable.some((it) => isCanvasItem(it))) {
                const note = document.createElement('span');
                note.className = 'ppa-plan__note';
                note.textContent = pp.t('js.asst.draft_note');
                foot.appendChild(note);
            }
            bubble.appendChild(foot);
            refresh();
            // Una vista previa que llega tarde no reactiva un plan ya sustituido.
            previewSettled = () => { if (!applying && !btn.dataset.superseded) refresh(); };
            previewQueue.forEach((start) => start());
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

    function isCanvasItem(it) {
        return !it.form_id && (!it.capability_id || it.capability_id === 'pages.canvas.edit');
    }

    // ------------------------------------------------------------------
    // ASST-V2 F4c — tarjeta de revisión de una entrada nueva, con las mismas
    // acciones que /admin/posts/new: leer aquí, publicar, editar, descartar.
    // ------------------------------------------------------------------
    const adminUrl = String(cfg.baseUrl || '').replace(/\/assistant\/?$/, '');

    function postAction(path, data) {
        const body = new URLSearchParams(Object.assign({ _csrf: cfg.csrf }, data || {}));
        return fetch(adminUrl + path, { method: 'POST', credentials: 'same-origin', body })
            .then((res) => res.json().catch(() => ({})).then((d) => (res.ok && d.ok ? d : Promise.reject(new Error(d.error || pp.t('js.as.unknown_error'))))));
    }

    function renderPostReview(post) {
        const card = document.createElement('article');
        card.className = 'ppa-post';
        let published = post.status === 'published';
        if (post.featured_image_path) {
            const img = document.createElement('img');
            img.className = 'ppa-post__cover';
            img.src = post.featured_image_path;
            img.alt = '';
            img.loading = 'lazy';
            card.appendChild(img);
        }
        const body = document.createElement('div');
        body.className = 'ppa-post__body';
        const head = document.createElement('div');
        head.className = 'ppa-post__head';
        const title = document.createElement('h4');
        title.className = 'ppa-post__title';
        title.textContent = post.title;
        head.appendChild(title);
        const badge = document.createElement('span');
        badge.className = 'ppa-post__badge';
        head.appendChild(badge);
        body.appendChild(head);
        if (post.excerpt) {
            const ex = document.createElement('p');
            ex.className = 'ppa-post__excerpt';
            ex.textContent = post.excerpt;
            body.appendChild(ex);
        }
        if (post.reading_minutes) {
            const meta = document.createElement('p');
            meta.className = 'ppa-post__meta';
            meta.textContent = pp.t('js.as.post_minutes', { n: post.reading_minutes });
            body.appendChild(meta);
        }
        const actions = document.createElement('div');
        actions.className = 'ppa-post__actions';
        const button = (label, cls) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pp-btn pp-btn--sm ' + cls;
            b.textContent = label;
            actions.appendChild(b);
            return b;
        };
        const read = button(pp.t('js.as.post_read'), 'pp-btn--secondary');
        const publish = button('', 'pp-btn--primary');
        const edit = document.createElement('a');
        edit.className = 'pp-btn pp-btn--secondary pp-btn--sm';
        edit.href = post.edit_url;
        edit.textContent = pp.t('js.as.post_edit');
        actions.appendChild(edit);
        const discard = button(pp.t('js.as.post_discard'), 'pp-btn--ghost ppa-post__discard');
        body.appendChild(actions);
        const status = document.createElement('p');
        status.className = 'ppa-post__status';
        status.setAttribute('role', 'status');
        body.appendChild(status);
        const reader = document.createElement('div');
        reader.className = 'ppa-post__reader';
        reader.hidden = true;
        body.appendChild(reader);
        card.appendChild(body);

        const paint = () => {
            badge.textContent = published ? pp.t('js.as.post_live') : pp.t('js.as.post_draft');
            badge.classList.toggle('is-live', published);
            publish.textContent = published ? pp.t('js.as.post_unpublish') : pp.t('js.as.post_publish');
        };
        paint();

        read.addEventListener('click', () => {
            if (reader.hidden) {
                if (!reader.firstChild) {
                    const frame = document.createElement('iframe');
                    frame.src = post.preview_url;
                    frame.title = pp.t('js.as.post_read');
                    frame.loading = 'lazy';
                    reader.appendChild(frame);
                }
                reader.hidden = false;
                read.textContent = pp.t('js.as.post_hide');
            } else {
                reader.hidden = true;
                read.textContent = pp.t('js.as.post_read');
            }
        });
        publish.addEventListener('click', () => {
            const next = published ? 'draft' : 'published';
            publish.disabled = true;
            publish.classList.add('is-loading');
            status.textContent = '';
            postAction('/posts/' + post.page_id + '/status', { status: next })
                .then(() => {
                    published = next === 'published';
                    paint();
                    status.textContent = published ? pp.t('js.as.post_published_note') : pp.t('js.as.post_unpublished_note');
                })
                .catch((error) => { status.textContent = error.message; })
                .finally(() => { publish.disabled = false; publish.classList.remove('is-loading'); });
        });
        discard.addEventListener('click', () => {
            if (!window.confirm(pp.t('js.as.post_discard_confirm'))) return;
            discard.disabled = true;
            postAction('/posts/' + post.page_id + '/delete', { ajax: '1' })
                .then(() => {
                    card.classList.add('is-discarded');
                    card.textContent = '';
                    const gone = document.createElement('p');
                    gone.className = 'ppa-post__gone';
                    gone.textContent = pp.t('js.as.post_discarded', { titulo: post.title });
                    card.appendChild(gone);
                })
                .catch((error) => { discard.disabled = false; status.textContent = error.message; });
        });
        return card;
    }

    function itemLabel(it) {
        if (it.capability_id === 'posts.create') return pp.t('js.as.post_x', { titulo: it.page_title });
        if (it.capability_id === 'pages.create') return pp.t('js.as.page_new_x', { pagina: it.page_title });
        return it.page_title + (it.section ? ' · «' + it.section + '»' : '');
    }

    function renderProgress(job, container) {
        container.textContent = '';
        const title = document.createElement('div');
        title.className = 'ppa-progress__title';
        const onlyPosts = job.items.length > 0 && job.items.every((it) => it.capability_id === 'posts.create');
        const onlyPages = job.items.length > 0 && job.items.every((it) => it.capability_id === 'pages.create');
        title.textContent = onlyPages
            ? pp.t(job.status === 'done' ? 'js.as.pages_created_progress' : 'js.as.pages_creating_progress', { n: job.completed, total: job.total })
            : onlyPosts
            ? pp.t(job.status === 'done' ? 'js.as.posts_written_progress' : 'js.as.posts_writing_progress', { n: job.completed, total: job.total })
            : job.status === 'done'
                ? 'Cambios aplicados (' + job.completed + '/' + job.total + ')'
                : 'Aplicando cambios… (' + job.completed + '/' + job.total + ')';
        container.appendChild(title);

        if (job.status !== 'done' && job.items.some((it) => it.capability_id === 'pages.create' && it.status !== 'done' && it.status !== 'failed')) {
            const wait = document.createElement('p');
            wait.className = 'ppa-progress__hint';
            wait.textContent = pp.t('js.as.page_takes_time');
            container.appendChild(wait);
        }
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
        const onlyPosts = job.items.length > 0 && job.items.every((it) => it.capability_id === 'posts.create');
        const onlyPages = job.items.length > 0 && job.items.every((it) => it.capability_id === 'pages.create');
        p.textContent = onlyPages
            ? (failedItems.length === 0
                ? pp.t(doneItems.length === 1 ? 'js.as.pages_created_one' : 'js.as.pages_created_n', { n: doneItems.length })
                : pp.t('js.as.pages_created_partial', { n: doneItems.length, total: job.items.length }))
            : onlyPosts
            ? (failedItems.length === 0
                ? pp.t(doneItems.length === 1 ? 'js.as.posts_written_one' : 'js.as.posts_written_n', { n: doneItems.length })
                : pp.t('js.as.posts_written_partial', { n: doneItems.length, total: job.items.length }))
            : failedItems.length === 0
            ? 'Listo: he aplicado ' + doneItems.length + (doneItems.length === 1 ? ' cambio.' : ' cambios.')
            : 'He aplicado ' + doneItems.length + ' de ' + job.items.length + ' cambios; ' + failedItems.length + ' no se ' + (failedItems.length === 1 ? 'pudo aplicar.' : 'pudieron aplicar.');
        bubble.appendChild(p);

        const seenPages = new Set();
        doneItems.filter((it) => it.capability_id === 'posts.create' && it.post).forEach((it) => {
            bubble.appendChild(renderPostReview(it.post));
        });
        doneItems.filter((it) => !(it.capability_id === 'posts.create' && it.post)).forEach((it) => {
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
            if (isCatalogItem(it) && it.catalog) {
                const link = document.createElement('a');
                link.className = 'ppa-item__link';
                link.href = it.catalog.edit_url;
                link.textContent = pp.t('js.as.catalog_open');
                head.appendChild(link);
            } else if (it.capability_id === 'seo.page.edit' || it.capability_id === 'chrome.edit' || it.capability_id === 'design.edit') {
                // En vivo ya: no hay nada que revisar ni publicar.
            } else if (it.capability_id === 'pages.create' && it.new_page) {
                const link = document.createElement('a');
                link.className = 'ppa-item__link';
                link.href = it.new_page.edit_url;
                link.textContent = pp.t('js.as.page_open_studio');
                head.appendChild(link);
            } else if (it.form_id) {
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
            body.textContent = (it.error || pp.t('js.as.unknown_error')) + ' ' + pp.t(it.form_id ? 'js.as.form_unchanged' : it.capability_id === 'seo.page.edit' ? 'js.as.seo_unchanged' : it.capability_id === 'chrome.edit' ? 'js.as.chrome_unchanged' : it.capability_id === 'design.edit' ? 'js.as.design_unchanged' : isCatalogItem(it) ? 'js.as.catalog_unchanged' : it.capability_id === 'posts.create' ? 'js.as.post_not_created' : it.capability_id === 'pages.create' ? 'js.as.page_not_created' : 'js.as.page_unchanged');
            card.appendChild(body);
            bubble.appendChild(card);
        });

        if (doneItems.some((it) => it.capability_id === 'pages.create')) {
            const pageNote = document.createElement('p');
            pageNote.className = 'ppa-plan__note';
            pageNote.textContent = pp.t('js.as.pages_done_note');
            bubble.appendChild(pageNote);
        }
        if (doneItems.some((it) => it.capability_id === 'posts.create')) {
            const postNote = document.createElement('p');
            postNote.className = 'ppa-plan__note';
            postNote.textContent = pp.t('js.as.posts_done_note');
            bubble.appendChild(postNote);
        }
        if (doneItems.some((it) => it.capability_id === 'chrome.edit')) {
            const chromeNote = document.createElement('p');
            chromeNote.className = 'ppa-plan__note';
            chromeNote.textContent = pp.t('js.as.chrome_live_done');
            bubble.appendChild(chromeNote);
        }
        if (doneItems.some((it) => CATALOG_EDIT.includes(it.capability_id))) {
            const catNote = document.createElement('p');
            catNote.className = 'ppa-plan__note';
            catNote.textContent = pp.t('js.as.catalog_live_done');
            bubble.appendChild(catNote);
        }
        if (doneItems.some((it) => CATALOG_CREATE.includes(it.capability_id))) {
            const newNote = document.createElement('p');
            newNote.className = 'ppa-plan__note';
            newNote.textContent = pp.t('js.as.catalog_created_done');
            bubble.appendChild(newNote);
        }
        if (doneItems.some((it) => it.capability_id === 'design.edit')) {
            const designNote = document.createElement('p');
            designNote.className = 'ppa-plan__note';
            designNote.textContent = pp.t('js.as.design_live_done');
            bubble.appendChild(designNote);
        }
        if (doneItems.some((it) => it.capability_id === 'seo.page.edit')) {
            const seoNote = document.createElement('p');
            seoNote.className = 'ppa-plan__note';
            seoNote.textContent = pp.t('js.as.seo_live_done');
            bubble.appendChild(seoNote);
        }
        if (doneItems.some((it) => it.form_id)) {
            const formNote = document.createElement('p');
            formNote.className = 'ppa-plan__note';
            formNote.textContent = pp.t('js.as.form_live_done');
            bubble.appendChild(formNote);
        }

        // Publicar solo tiene sentido para páginas Canvas: formularios y SEO ya están en vivo.
        if (doneItems.some((it) => isCanvasItem(it))) {
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
        if (doneItems.some((it) => it.capability_id !== 'posts.create') && cfg.activityUrl) {
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
                items: JSON.stringify(applicable.map((it) => {
                    const copy = Object.assign({}, it);
                    delete copy._preview;
                    delete copy._reviewed;
                    if (it._preview && it._preview.state === 'ready') copy.preview_token = it._preview.token;
                    if (isCatalogItem(it)) copy.reviewed = it._reviewed === true;
                    return copy;
                })),
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
        // ASST-V2 F5 — «Arreglar» desde Inicio: escribe la petición y la envía.
        send(text) {
            if (planning || applying || extracting) {
                window.PPAssistant.prefill(text);
                return;
            }
            window.PPAssistant.prefill(text);
            sendRequest();
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
