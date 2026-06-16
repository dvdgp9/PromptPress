/**
 * F21.T21.2.c — Block editor para entradas (blog).
 *
 * Trabaja sobre el array `blocks` de la sección article_body. Cada bloque
 * tiene un tipo (paragraph, heading, image, list, quote, divider) y campos
 * propios. El JS pinta cada bloque con un mini-editor in-place y persiste
 * cambios con autosave debounced.
 *
 * Persistencia: POST /admin/posts/{id}/body (campos: blocks JSON, title opcional).
 * Estado: POST /admin/posts/{id}/status (campo: status=draft|published).
 *
 * Sin frameworks. Vanilla JS + delegación de eventos donde se puede.
 */
(function () {
    'use strict';

    const root = document.querySelector('.pp-post-editor');
    if (!root) return;

    const postId  = root.dataset.postId;
    const csrf    = root.dataset.csrf;
    const baseUrl = root.dataset.baseUrl.replace(/\/$/, '');

    const titleInput   = root.querySelector('[data-field="title"]');
    const titleDisplay = document.querySelector('[data-title-display]');
    const blocksWrap   = root.querySelector('[data-blocks]');
    const toolbar      = root.querySelector('[data-toolbar]');
    const saveState    = document.querySelector('[data-save-state]');
    const statusPill   = document.querySelector('[data-status-pill]');

    // Estado en memoria
    let state = { title: titleInput.value, blocks: [] };
    try {
        const initial = JSON.parse(blocksWrap.dataset.initial || '{"blocks":[]}');
        if (initial && Array.isArray(initial.blocks)) state.blocks = initial.blocks;
    } catch (e) { /* ignore */ }

    // ========================================================================
    // Render
    // ========================================================================

    function uid() { return 'b' + Math.random().toString(36).slice(2, 10); }

    /** Re-pinta toda la lista. Conserva foco si se proporciona. */
    function render(focusBlockId, focusField) {
        const empty = blocksWrap.querySelector('[data-empty]');
        // Limpia todos los nodos excepto el placeholder
        Array.from(blocksWrap.querySelectorAll('[data-block]')).forEach(n => n.remove());

        if (state.blocks.length === 0) {
            if (empty) empty.hidden = false;
            return;
        }
        if (empty) empty.hidden = true;

        state.blocks.forEach((block, idx) => {
            if (!block._id) block._id = uid();
            const el = renderBlock(block, idx);
            blocksWrap.appendChild(el);
        });

        if (focusBlockId) {
            const target = blocksWrap.querySelector('[data-block="' + focusBlockId + '"]');
            if (target) {
                const sel = focusField ? '[data-field="' + focusField + '"]' : 'textarea, input[type="text"]';
                const input = target.querySelector(sel);
                if (input) {
                    input.focus();
                    if (input.setSelectionRange && typeof input.value === 'string') {
                        const v = input.value.length;
                        try { input.setSelectionRange(v, v); } catch (e) {}
                    }
                }
            }
        }
    }

    function renderBlock(block, idx) {
        const wrap = document.createElement('div');
        wrap.className = 'pp-block pp-block--' + block.type;
        if (block.type === 'list' && block.style === 'ordered') wrap.classList.add('is-ordered');
        wrap.setAttribute('data-block', block._id);
        wrap.setAttribute('data-type', block.type);
        wrap.setAttribute('draggable', 'false'); // se activa al usar el handle

        // Sidebar de controles (drag, eliminar)
        const controls = document.createElement('div');
        controls.className = 'pp-block__controls';
        controls.innerHTML = ''
            + '<button type="button" class="pp-block__handle" data-handle aria-label="Arrastrar para reordenar" title="Arrastrar para reordenar">⋮⋮</button>'
            + '<button type="button" class="pp-block__up" data-move="-1" aria-label="Subir" title="Subir">↑</button>'
            + '<button type="button" class="pp-block__down" data-move="1" aria-label="Bajar" title="Bajar">↓</button>'
            + '<button type="button" class="pp-block__delete" data-delete aria-label="Eliminar" title="Eliminar">×</button>';
        wrap.appendChild(controls);

        const body = document.createElement('div');
        body.className = 'pp-block__body';
        body.appendChild(renderBlockBody(block));
        wrap.appendChild(body);

        return wrap;
    }

    function renderBlockBody(block) {
        switch (block.type) {
            case 'paragraph': return renderParagraph(block);
            case 'heading':   return renderHeading(block);
            case 'image':     return renderImage(block);
            case 'list':      return renderList(block);
            case 'quote':     return renderQuote(block);
            case 'divider':   return renderDivider(block);
            default: {
                const div = document.createElement('div');
                div.textContent = 'Tipo desconocido: ' + block.type;
                return div;
            }
        }
    }

    function autosizeTextarea(ta) {
        ta.style.height = 'auto';
        ta.style.height = Math.max(ta.scrollHeight, 32) + 'px';
    }

    function makeTextarea(placeholder, value, klass) {
        const ta = document.createElement('textarea');
        ta.className = 'pp-block__input ' + (klass || '');
        ta.placeholder = placeholder;
        ta.value = value || '';
        ta.setAttribute('data-field', 'text');
        ta.rows = 1;
        setTimeout(() => autosizeTextarea(ta), 0);
        ta.addEventListener('input', () => autosizeTextarea(ta));
        // Enter en último párrafo crea un nuevo bloque párrafo
        ta.addEventListener('keydown', handleEnterKey);
        return ta;
    }

    function renderParagraph(block) {
        return makeTextarea('Escribe un párrafo… Usa la barra inferior para añadir subtítulos, imágenes, listas o citas.', block.text, 'pp-block__input--paragraph');
    }

    function renderHeading(block) {
        const wrap = document.createElement('div');
        const lvl = block.level === 3 ? 3 : 2;
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pp-block__input pp-block__input--h' + lvl;
        input.placeholder = lvl === 2 ? 'Subtítulo importante (H2)' : 'Subtítulo de detalle (H3)';
        input.value = block.text || '';
        input.maxLength = 300;
        input.setAttribute('data-field', 'text');
        input.addEventListener('keydown', handleEnterKey);
        wrap.appendChild(input);
        // Selector H2/H3
        const toggle = document.createElement('div');
        toggle.className = 'pp-block__heading-toggle';
        toggle.innerHTML = ''
            + '<button type="button" data-set-level="2" class="' + (lvl === 2 ? 'is-active' : '') + '">H2</button>'
            + '<button type="button" data-set-level="3" class="' + (lvl === 3 ? 'is-active' : '') + '">H3</button>';
        wrap.appendChild(toggle);
        return wrap;
    }

    function renderImage(block) {
        const wrap = document.createElement('div');
        wrap.className = 'pp-block__image';
        const hasImg = !!block.src;
        wrap.innerHTML = ''
            + '<div class="pp-block__image-slot' + (hasImg ? ' has-image' : '') + '">'
            +   (hasImg
                ? '<img src="' + escAttr(absUrl(block.src)) + '" alt="' + escAttr(block.alt || '') + '">'
                : '<div class="pp-block__image-empty">'
                  + '<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
                  + '<span>Aún sin imagen</span>'
                + '</div>'
                )
            + '</div>'
            + '<div class="pp-block__image-actions">'
            +   '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-pick-image>' + (hasImg ? 'Cambiar…' : 'Elegir imagen…') + '</button>'
            +   (hasImg ? '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-clear-image>Quitar</button>' : '')
            + '</div>'
            + '<input type="text" class="pp-block__input pp-block__input--alt" data-field="alt" placeholder="Texto alternativo (accesibilidad y SEO)" value="' + escAttr(block.alt || '') + '" maxlength="255">'
            + '<input type="text" class="pp-block__input pp-block__input--caption" data-field="caption" placeholder="Pie de imagen (opcional)" value="' + escAttr(block.caption || '') + '" maxlength="500">';
        return wrap;
    }

    function renderList(block) {
        const wrap = document.createElement('div');
        const styleSel = document.createElement('div');
        styleSel.className = 'pp-block__list-toggle';
        const isOrdered = block.style === 'ordered';
        styleSel.innerHTML = ''
            + '<button type="button" data-set-list="unordered" class="' + (!isOrdered ? 'is-active' : '') + '">• Lista</button>'
            + '<button type="button" data-set-list="ordered" class="' + (isOrdered ? 'is-active' : '') + '">1. Lista numerada</button>';
        wrap.appendChild(styleSel);

        const ta = document.createElement('textarea');
        ta.className = 'pp-block__input pp-block__input--list';
        ta.placeholder = 'Un elemento por línea';
        ta.value = (block.items || []).join('\n');
        ta.setAttribute('data-field', 'items');
        ta.rows = Math.max(3, (block.items || []).length || 1);
        setTimeout(() => autosizeTextarea(ta), 0);
        ta.addEventListener('input', () => autosizeTextarea(ta));
        wrap.appendChild(ta);
        return wrap;
    }

    function renderQuote(block) {
        const wrap = document.createElement('div');
        const ta = document.createElement('textarea');
        ta.className = 'pp-block__input pp-block__input--quote';
        ta.placeholder = 'Cita memorable…';
        ta.value = block.text || '';
        ta.setAttribute('data-field', 'text');
        ta.rows = 2;
        setTimeout(() => autosizeTextarea(ta), 0);
        ta.addEventListener('input', () => autosizeTextarea(ta));
        wrap.appendChild(ta);

        const attr = document.createElement('input');
        attr.type = 'text';
        attr.className = 'pp-block__input pp-block__input--attribution';
        attr.placeholder = 'Atribución (opcional)';
        attr.value = block.attribution || '';
        attr.maxLength = 200;
        attr.setAttribute('data-field', 'attribution');
        wrap.appendChild(attr);
        return wrap;
    }

    function renderDivider() {
        const wrap = document.createElement('div');
        wrap.className = 'pp-block__divider-preview';
        wrap.innerHTML = '<hr><span>Divisor visual</span><hr>';
        return wrap;
    }

    // ========================================================================
    // Sync DOM → state (al perder foco o al pulsar guardar)
    // ========================================================================

    /** Lee los valores actuales del DOM y los aplica al state.blocks. */
    function syncStateFromDom() {
        const nodes = blocksWrap.querySelectorAll('[data-block]');
        nodes.forEach(node => {
            const id = node.getAttribute('data-block');
            const block = state.blocks.find(b => b._id === id);
            if (!block) return;
            switch (block.type) {
                case 'paragraph':
                case 'heading':
                    block.text = node.querySelector('[data-field="text"]').value;
                    break;
                case 'quote':
                    block.text = node.querySelector('[data-field="text"]').value;
                    block.attribution = node.querySelector('[data-field="attribution"]').value;
                    break;
                case 'list':
                    block.items = node.querySelector('[data-field="items"]').value
                        .split(/\r?\n/).map(s => s.trim()).filter(s => s !== '');
                    break;
                case 'image':
                    block.alt = node.querySelector('[data-field="alt"]').value;
                    block.caption = node.querySelector('[data-field="caption"]').value;
                    // src se actualiza directamente al elegir/quitar imagen (no input visible).
                    break;
            }
        });
    }

    // ========================================================================
    // Eventos
    // ========================================================================

    // Delegación: input/blur en cualquier campo del bloque
    blocksWrap.addEventListener('input', (e) => {
        if (e.target.closest('[data-block]')) scheduleSave();
    });

    // Toolbar: añadir bloque
    toolbar.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-add]');
        if (!btn) return;
        e.preventDefault();
        const kind = btn.getAttribute('data-add');
        addBlock(kind);
    });

    // Acciones de bloque (controles): mover, eliminar
    blocksWrap.addEventListener('click', (e) => {
        const blockEl = e.target.closest('[data-block]');
        if (!blockEl) return;
        const id = blockEl.getAttribute('data-block');
        const idx = state.blocks.findIndex(b => b._id === id);
        if (idx === -1) return;

        if (e.target.closest('[data-move]')) {
            const dir = parseInt(e.target.closest('[data-move]').getAttribute('data-move'), 10);
            const newIdx = idx + dir;
            if (newIdx < 0 || newIdx >= state.blocks.length) return;
            syncStateFromDom();
            const tmp = state.blocks[idx];
            state.blocks[idx] = state.blocks[newIdx];
            state.blocks[newIdx] = tmp;
            render(id);
            scheduleSave();
            return;
        }
        if (e.target.closest('[data-delete]')) {
            syncStateFromDom();
            state.blocks.splice(idx, 1);
            render();
            scheduleSave();
            return;
        }
        // Heading level toggle
        const setLevelBtn = e.target.closest('[data-set-level]');
        if (setLevelBtn && state.blocks[idx].type === 'heading') {
            syncStateFromDom();
            state.blocks[idx].level = parseInt(setLevelBtn.getAttribute('data-set-level'), 10);
            render(id, 'text');
            scheduleSave();
            return;
        }
        // List style toggle
        const setListBtn = e.target.closest('[data-set-list]');
        if (setListBtn && state.blocks[idx].type === 'list') {
            syncStateFromDom();
            state.blocks[idx].style = setListBtn.getAttribute('data-set-list');
            render(id, 'items');
            scheduleSave();
            return;
        }
        // Image: pick / clear
        if (e.target.closest('[data-pick-image]')) {
            pickImageForBlock(idx);
            return;
        }
        if (e.target.closest('[data-clear-image]')) {
            syncStateFromDom();
            state.blocks[idx].src = '';
            render(id);
            scheduleSave();
            return;
        }
    });

    function pickImageForBlock(idx) {
        const apply = (path, alt) => {
            syncStateFromDom();
            state.blocks[idx].src = path || '';
            if (alt && !state.blocks[idx].alt) state.blocks[idx].alt = alt;
            render(state.blocks[idx]._id);
            scheduleSave();
        };
        if (window.PPMediaPicker && typeof window.PPMediaPicker.open === 'function') {
            window.PPMediaPicker.open({ onSelect: (m) => apply(m.path || m.url, m.alt_text || '') });
        } else {
            const url = prompt('Pega la URL/ruta de la imagen (puedes subirla antes en /admin/media):');
            if (url) apply(url.trim(), '');
        }
    }

    // Enter al final de un párrafo → crear nuevo párrafo debajo
    function handleEnterKey(e) {
        if (e.key !== 'Enter') return;
        const target = e.target;
        // Si es heading (input) o lista, no creamos otro bloque al hacer enter
        if (target.tagName === 'INPUT' && target.classList.contains('pp-block__input--alt')) return;
        if (target.tagName === 'INPUT' && target.classList.contains('pp-block__input--caption')) return;
        if (target.classList.contains('pp-block__input--list')) return; // newline = nuevo item
        if (target.classList.contains('pp-block__input--quote')) return;
        if (target.classList.contains('pp-block__input--paragraph') && !e.shiftKey) {
            // Solo al final del párrafo, sin shift
            const v = target.value;
            const pos = target.selectionStart;
            if (pos === v.length) {
                e.preventDefault();
                const blockEl = target.closest('[data-block]');
                const id = blockEl.getAttribute('data-block');
                const idx = state.blocks.findIndex(b => b._id === id);
                if (idx === -1) return;
                syncStateFromDom();
                const newBlock = { _id: uid(), type: 'paragraph', text: '' };
                state.blocks.splice(idx + 1, 0, newBlock);
                render(newBlock._id);
                scheduleSave();
            }
            return;
        }
        // En heading (input), Enter pasa al siguiente bloque o crea uno
        if (target.tagName === 'INPUT' && target.classList.contains('pp-block__input--h2') || target.classList.contains('pp-block__input--h3')) {
            e.preventDefault();
            const blockEl = target.closest('[data-block]');
            const id = blockEl.getAttribute('data-block');
            const idx = state.blocks.findIndex(b => b._id === id);
            if (idx === -1) return;
            syncStateFromDom();
            // Si hay un bloque debajo de tipo paragraph vacío, ir ahí; si no, crear uno.
            const next = state.blocks[idx + 1];
            if (next && next.type === 'paragraph' && !next.text) {
                render(next._id);
            } else {
                const newBlock = { _id: uid(), type: 'paragraph', text: '' };
                state.blocks.splice(idx + 1, 0, newBlock);
                render(newBlock._id);
            }
            scheduleSave();
        }
    }

    // Drag-drop simple via mouse en el handle
    let dragSource = null;
    blocksWrap.addEventListener('mousedown', (e) => {
        const handle = e.target.closest('[data-handle]');
        if (!handle) return;
        const blockEl = handle.closest('[data-block]');
        if (!blockEl) return;
        blockEl.setAttribute('draggable', 'true');
    });
    blocksWrap.addEventListener('dragstart', (e) => {
        const blockEl = e.target.closest('[data-block]');
        if (!blockEl) return;
        dragSource = blockEl.getAttribute('data-block');
        e.dataTransfer.effectAllowed = 'move';
        // Hint visual
        blockEl.classList.add('is-dragging');
    });
    blocksWrap.addEventListener('dragend', (e) => {
        const blockEl = e.target.closest('[data-block]');
        if (blockEl) {
            blockEl.classList.remove('is-dragging');
            blockEl.setAttribute('draggable', 'false');
        }
        dragSource = null;
        blocksWrap.querySelectorAll('.is-drag-over').forEach(el => el.classList.remove('is-drag-over'));
    });
    blocksWrap.addEventListener('dragover', (e) => {
        const blockEl = e.target.closest('[data-block]');
        if (!blockEl || !dragSource) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        blocksWrap.querySelectorAll('.is-drag-over').forEach(el => el.classList.remove('is-drag-over'));
        blockEl.classList.add('is-drag-over');
    });
    blocksWrap.addEventListener('drop', (e) => {
        const blockEl = e.target.closest('[data-block]');
        if (!blockEl || !dragSource) return;
        e.preventDefault();
        const targetId = blockEl.getAttribute('data-block');
        if (targetId === dragSource) return;
        const fromIdx = state.blocks.findIndex(b => b._id === dragSource);
        const toIdx = state.blocks.findIndex(b => b._id === targetId);
        if (fromIdx === -1 || toIdx === -1) return;
        syncStateFromDom();
        const [moved] = state.blocks.splice(fromIdx, 1);
        state.blocks.splice(toIdx, 0, moved);
        render(moved._id);
        scheduleSave();
    });

    // Título: sync al state y autosave
    titleInput.addEventListener('input', () => {
        state.title = titleInput.value;
        if (titleDisplay) titleDisplay.textContent = state.title || 'Sin título';
        scheduleSave();
    });

    // Status: publicar/despublicar
    const barRight = document.querySelector('.pp-post-editor__bar-right');
    if (barRight) {
        barRight.addEventListener('click', (e) => {
            const action = e.target.closest('[data-action]');
            if (!action) return;
            const kind = action.getAttribute('data-action');
            if (kind === 'publish') changeStatus('published');
            if (kind === 'unpublish') changeStatus('draft');
        });
    }

    function changeStatus(next) {
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('status', next);
        saveState.textContent = next === 'published' ? 'Publicando…' : 'Despublicando…';
        fetch(baseUrl + '/admin/posts/' + postId + '/status', {
            method: 'POST', credentials: 'same-origin', body: fd,
        })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { saveState.textContent = 'Error: ' + (data.error || 'No se pudo'); return; }
                saveState.textContent = next === 'published' ? '✓ Publicada' : '✓ Despublicada';
                setTimeout(() => location.reload(), 600);
            })
            .catch(err => { saveState.textContent = 'Error: ' + err.message; });
    }

    // ========================================================================
    // Añadir bloques desde toolbar
    // ========================================================================

    function addBlock(kind) {
        syncStateFromDom();
        let block;
        switch (kind) {
            case 'paragraph': block = { _id: uid(), type: 'paragraph', text: '' }; break;
            case 'heading-2': block = { _id: uid(), type: 'heading', level: 2, text: '' }; break;
            case 'heading-3': block = { _id: uid(), type: 'heading', level: 3, text: '' }; break;
            case 'image':     block = { _id: uid(), type: 'image', src: '', alt: '', caption: '' }; break;
            case 'list':      block = { _id: uid(), type: 'list', style: 'unordered', items: [] }; break;
            case 'quote':     block = { _id: uid(), type: 'quote', text: '', attribution: '' }; break;
            case 'divider':   block = { _id: uid(), type: 'divider' }; break;
            default: return;
        }
        state.blocks.push(block);
        render(block._id);
        scheduleSave();
        // Si es imagen, abrir picker automáticamente.
        if (kind === 'image') {
            setTimeout(() => pickImageForBlock(state.blocks.length - 1), 50);
        }
    }

    // ========================================================================
    // Autosave (debounced)
    // ========================================================================

    let saveTimer = null;
    let savingNow = false;
    let pendingSave = false;

    function scheduleSave() {
        clearTimeout(saveTimer);
        setSaveState('Sin guardar', 'pending');
        saveTimer = setTimeout(commitSave, 900);
    }

    function commitSave() {
        if (savingNow) { pendingSave = true; return; }
        savingNow = true;
        syncStateFromDom();
        setSaveState('Guardando…', 'saving');

        // Strip _id (interno) antes de enviar.
        const clean = state.blocks.map(b => {
            const o = Object.assign({}, b);
            delete o._id;
            return o;
        });

        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('blocks', JSON.stringify(clean));
        fd.append('title', state.title);

        fetch(baseUrl + '/admin/posts/' + postId + '/body', {
            method: 'POST', credentials: 'same-origin', body: fd,
        })
            .then(r => r.json())
            .then(data => {
                savingNow = false;
                if (!data.ok) {
                    setSaveState('Error: ' + (data.error || 'No se pudo guardar'), 'error');
                    return;
                }
                setSaveState('Guardado · ' + new Date().toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }), 'ok');
                if (pendingSave) { pendingSave = false; scheduleSave(); }
            })
            .catch(err => {
                savingNow = false;
                setSaveState('Error: ' + err.message, 'error');
            });
    }

    function setSaveState(text, kind) {
        if (!saveState) return;
        saveState.textContent = text;
        saveState.className = 'pp-post-editor__save-state' + (kind ? ' is-' + kind : '');
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    function escAttr(s) { return String(s == null ? '' : s).replace(/[&"<>]/g, c => ({'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c])); }
    function absUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return baseUrl + '/' + String(path).replace(/^\//, '');
    }

    // ========================================================================
    // Init
    // ========================================================================
    render();
})();
