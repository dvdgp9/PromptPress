/**
 * ASSISTANT-RICH AR2 — captura progresiva de contenido enriquecido.
 *
 * El servidor vuelve a normalizar siempre el HTML. Este módulo solo construye
 * una preview segura y mantiene las referencias de imágenes en su lugar.
 */
(function (root, factory) {
    'use strict';
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PPARichComposer = api;
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const DROP_TAGS = new Set(['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'link', 'meta']);
    const BLOCK_TAGS = new Set(['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'blockquote', 'pre']);
    const KEEP_TAGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'strong', 'b', 'em', 'i', 'br']);

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[char]));
    }

    function safeHref(value) {
        try {
            const url = new URL(String(value || ''));
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function imageSourceKind(value) {
        const source = String(value || '').trim();
        if (/^data:image\/(?:jpeg|png|gif|webp);base64,/i.test(source)) return 'data';
        if (/^https?:\/\//i.test(source)) return 'remote_url';
        return 'unresolved';
    }

    function parseDataImage(value, maxBytes) {
        const match = String(value || '').match(/^data:(image\/(?:jpeg|png|gif|webp));base64,([a-z0-9+/=\s]+)$/i);
        if (!match) return null;
        const base64 = match[2].replace(/\s+/g, '');
        if (base64.length === 0 || base64.length % 4 !== 0) return null;
        const padding = (base64.match(/=*$/) || [''])[0].length;
        const bytes = (base64.length * 3 / 4) - padding;
        if (!Number.isFinite(bytes) || bytes <= 0 || bytes > maxBytes) return null;
        return { mime: match[1].toLowerCase(), bytes: bytes, base64: base64 };
    }

    function selectClipboardImages(files, limits) {
        const accepted = [];
        const rejected = [];
        const maxImages = Math.max(0, Number(limits.maxImages) || 0);
        const maxImageBytes = Math.max(1, Number(limits.maxImageBytes) || 1);
        Array.from(files || []).forEach((file) => {
            if (!String(file.type || '').startsWith('image/')) return;
            if (!ALLOWED_IMAGE_MIMES.includes(String(file.type || '').toLowerCase())) {
                rejected.push({ file: file, reason: 'mime' });
            } else if (Number(file.size || 0) > maxImageBytes) {
                rejected.push({ file: file, reason: 'size' });
            } else if (accepted.length >= maxImages) {
                rejected.push({ file: file, reason: 'count' });
            } else {
                accepted.push(file);
            }
        });
        return { accepted: accepted, rejected: rejected };
    }

    function normalizeMediaItem(value) {
        const item = value && typeof value === 'object' ? value : {};
        const id = Number(item.id || 0);
        const path = String(item.path || '');
        if (!Number.isInteger(id) || id <= 0 || path.includes('..') || path.includes('\\')) return null;
        if (!/^\/storage\/uploads\/\d+\/[a-zA-Z0-9._/-]+$/.test(path)) return null;
        return {
            id: id,
            path: path,
            url: String(item.url || path),
            name: String(item.name || ''),
            alt: String(item.alt_text || item.alt || ''),
            mime: String(item.mime_type || item.mime || ''),
            bytes: Number(item.file_size || item.bytes || 0)
        };
    }

    function summarizeImageStates(images) {
        const values = images instanceof Map ? Array.from(images.values()) : Array.from(images || []);
        const external = values.filter((image) => image && (image.kind === 'remote_url' || image.kind === 'unresolved'));
        return {
            total: values.length,
            stored: values.filter((image) => image && image.kind === 'stored').length,
            external: external.length,
            blocking: values.filter((image) => !image || image.kind !== 'stored').length,
            externalIds: external.map((image) => String(image.id || '')).filter(Boolean)
        };
    }

    function discardExternalImageReferences(images) {
        if (!(images instanceof Map)) return [];
        const ids = summarizeImageStates(images).externalIds;
        ids.forEach((id) => images.delete(id));
        return ids;
    }

    function plainTextToHtml(value) {
        const lines = String(value || '').replace(/\r\n?/g, '\n').split('\n');
        const parts = [];
        lines.forEach((raw) => {
            const line = raw.trim();
            if (line === '') return;
            let match = line.match(/^(?:[•●▪◦*+-])\s+(.+)$/u);
            if (match) {
                parts.push('<ul><li>' + escapeHtml(match[1]) + '</li></ul>');
                return;
            }
            match = line.match(/^\d+[.)]\s+(.+)$/u);
            if (match) {
                parts.push('<ol><li>' + escapeHtml(match[1]) + '</li></ol>');
                return;
            }
            parts.push('<p>' + escapeHtml(line) + '</p>');
        });
        return parts.join('');
    }

    function dataImageToFile(parsed, name) {
        const binary = atob(parsed.base64);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index);
        return new File([bytes], name, { type: parsed.mime });
    }

    class Composer {
        constructor(options) {
            this.editor = options.editor;
            this.fallback = options.fallback;
            this.status = options.status;
            this.maxChars = options.maxChars || 60000;
            this.maxImages = options.maxImages || 8;
            this.maxImageBytes = options.maxImageBytes || 10485760;
            this.t = options.t || ((key) => key);
            this.csrf = options.csrf || '';
            this.uploadUrl = options.uploadUrl || '';
            this.onChooseMedia = options.onChooseMedia || function () {};
            this.images = new Map();
            this.objectUrls = new Set();
            this.nextImageId = 1;
            this.plainPasteArmed = false;
            this.onChange = options.onChange || function () {};
        }

        enhance() {
            this.fallback.hidden = true;
            this.editor.hidden = false;
            this.editor.addEventListener('input', () => this.sync());
            this.editor.addEventListener('paste', (event) => this.handlePaste(event));
            this.editor.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    this.editor.dispatchEvent(new CustomEvent('ppa:send', { bubbles: true }));
                }
            });
            this.sync();
            return this;
        }

        armPlainPaste() {
            this.plainPasteArmed = true;
            this.editor.focus();
            this.setStatus(this.t('js.as.plain_paste_ready'), 'info');
        }

        handlePaste(event) {
            const clipboard = event.clipboardData;
            if (!clipboard) return;
            event.preventDefault();
            const forcePlain = this.plainPasteArmed || event.shiftKey;
            this.plainPasteArmed = false;
            const plain = clipboard.getData('text/plain') || '';
            if (forcePlain) {
                this.insertHtml(plainTextToHtml(plain));
                this.setStatus(this.t('js.as.plain_pasted'), 'success');
                this.sync();
                return;
            }

            const selected = selectClipboardImages(Array.from(clipboard.files || []), {
                maxImages: Math.max(0, this.maxImages - this.images.size),
                maxImageBytes: this.maxImageBytes
            });
            const availableFiles = selected.accepted.slice();
            const html = clipboard.getData('text/html');
            const fragment = html
                ? this.sanitizeHtml(html, availableFiles)
                : this.fragmentFromPlainText(plain);
            while (availableFiles.length > 0 && this.images.size < this.maxImages) {
                fragment.appendChild(this.createCapturedImage(availableFiles.shift()));
            }
            const imageCount = fragment.querySelectorAll ? fragment.querySelectorAll('[data-ppa-pasted-image]').length : 0;
            this.insertFragment(fragment);
            const rejectedCount = selected.rejected.length;
            if (rejectedCount > 0) {
                this.setStatus(this.t('js.as.some_images_rejected', { n: rejectedCount }), 'warning');
            } else {
                this.setStatus(this.t('js.as.rich_pasted', { n: imageCount }), 'success');
            }
            this.sync();
            if (this.state().blockingImages > 0) this.refreshMediaStatus();
        }

        sanitizeHtml(html, availableFiles) {
            const parsed = new DOMParser().parseFromString(String(html || ''), 'text/html');
            const fragment = document.createDocumentFragment();
            Array.from(parsed.body.childNodes).forEach((node) => {
                const safe = this.sanitizeNode(node, availableFiles);
                if (safe) fragment.appendChild(safe);
            });
            return fragment;
        }

        sanitizeNode(node, availableFiles) {
            if (node.nodeType === Node.TEXT_NODE) return document.createTextNode(node.nodeValue || '');
            if (node.nodeType !== Node.ELEMENT_NODE) return null;
            const tag = node.tagName.toLowerCase();
            if (DROP_TAGS.has(tag)) return null;
            if (tag === 'img') return this.imageFromSource(node.getAttribute('src') || '', node.getAttribute('alt') || '', availableFiles);

            let safe;
            if (tag === 'a') {
                const href = safeHref(node.getAttribute('href'));
                safe = href ? document.createElement('a') : document.createElement('span');
                if (href) safe.setAttribute('href', href);
            } else if (KEEP_TAGS.has(tag)) {
                safe = document.createElement(tag === 'b' ? 'strong' : (tag === 'i' ? 'em' : tag));
            } else if (BLOCK_TAGS.has(tag)) {
                safe = document.createElement(tag === 'blockquote' || tag === 'pre' ? 'blockquote' : 'p');
            } else {
                safe = document.createDocumentFragment();
            }
            Array.from(node.childNodes).forEach((child) => {
                const clean = this.sanitizeNode(child, availableFiles);
                if (clean) safe.appendChild(clean);
            });
            return safe;
        }

        imageFromSource(source, alt, availableFiles) {
            const kind = imageSourceKind(source);
            if (kind === 'data') {
                const parsed = parseDataImage(source, this.maxImageBytes);
                if (parsed && this.images.size < this.maxImages) {
                    return this.createCapturedImage(dataImageToFile(parsed, 'imagen-pegada.' + parsed.mime.split('/')[1]), alt);
                }
                return this.createImagePlaceholder('unresolved', '', alt);
            }
            if (kind === 'unresolved' && availableFiles.length > 0 && this.images.size < this.maxImages) {
                return this.createCapturedImage(availableFiles.shift(), alt);
            }
            if (this.images.size >= this.maxImages) return document.createTextNode('');
            return this.createImagePlaceholder(kind, source, alt);
        }

        createCapturedImage(file, alt) {
            const id = 'paste-' + this.nextImageId++;
            const objectUrl = URL.createObjectURL(file);
            this.objectUrls.add(objectUrl);
            this.images.set(id, { id: id, kind: 'captured', file: file, alt: alt || file.name || '' });
            if (this.uploadUrl) window.setTimeout(() => this.uploadImage(id), 0);
            return this.createFigure(id, 'captured', objectUrl, alt || file.name || '');
        }

        createImagePlaceholder(kind, source, alt) {
            const id = 'paste-' + this.nextImageId++;
            this.images.set(id, { id: id, kind: kind, source: source, alt: alt || '' });
            return this.createFigure(id, kind, '', alt || '');
        }

        createFigure(id, kind, previewUrl, alt) {
            const figure = document.createElement('figure');
            figure.className = 'ppa-pasted-image ppa-pasted-image--' + kind;
            figure.contentEditable = 'false';
            figure.dataset.ppaPastedImage = id;
            figure.dataset.sourceKind = kind;
            if (previewUrl) {
                const image = document.createElement('img');
                image.src = previewUrl;
                image.alt = alt;
                figure.appendChild(image);
            }
            const caption = document.createElement('figcaption');
            const label = document.createElement('span');
            label.className = 'ppa-pasted-image__label';
            label.textContent = kind === 'captured'
                ? this.t('js.as.image_pending')
                : this.t('js.as.image_external_marker');
            caption.appendChild(label);
            const actions = document.createElement('span');
            actions.className = 'ppa-pasted-image__actions';
            if (kind !== 'captured') actions.appendChild(this.createChooseButton(id));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ppa-pasted-image__remove';
            remove.setAttribute('aria-label', this.t('js.as.remove_image'));
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                this.removeImage(id);
                figure.remove();
                this.sync();
                this.refreshMediaStatus();
            });
            actions.appendChild(remove);
            caption.appendChild(actions);
            figure.appendChild(caption);
            return figure;
        }

        createChooseButton(id) {
            const choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'ppa-pasted-image__choose';
            choose.textContent = this.t('js.as.choose_media');
            choose.addEventListener('click', () => this.onChooseMedia(id));
            return choose;
        }

        async uploadImage(id) {
            const item = this.images.get(id);
            if (!item || !item.file || !this.uploadUrl) return;
            item.kind = 'uploading';
            this.updateFigure(id, 'uploading', this.t('js.as.image_uploading'));
            this.setStatus(this.t('js.as.image_uploading'), 'info');
            this.onChange(this.state());

            const form = new FormData();
            form.append('_csrf', this.csrf);
            form.append('file', item.file);
            if (item.alt && item.alt !== item.file.name && !/^imagen-pegada\./i.test(item.alt)) {
                form.append('alt_text', item.alt);
            }
            try {
                const response = await fetch(this.uploadUrl, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const body = await response.json().catch(() => ({}));
                const media = response.ok && body.ok ? normalizeMediaItem(body.item) : null;
                if (!media) throw new Error(body.error || this.t('js.as.image_upload_failed'));
                this.useMedia(id, media);
            } catch (error) {
                item.kind = 'upload_failed';
                item.error = error && error.message ? error.message : this.t('js.as.image_upload_failed');
                this.updateFigure(id, 'upload_failed', item.error, true);
                this.setStatus(this.t('js.as.images_blocking'), 'error');
                this.onChange(this.state());
            }
        }

        useMedia(id, value) {
            const media = normalizeMediaItem(value);
            const current = this.images.get(id);
            if (!media || !current) return false;
            const figure = this.editor.querySelector('[data-ppa-pasted-image="' + id + '"]');
            const preview = figure ? figure.querySelector('img') : null;
            if (preview && preview.src && preview.src.startsWith('blob:')) {
                URL.revokeObjectURL(preview.src);
                this.objectUrls.delete(preview.src);
            }
            this.images.set(id, {
                id: id,
                kind: 'stored',
                mediaId: media.id,
                path: media.path,
                url: media.url,
                alt: media.alt || current.alt || media.name,
                mime: media.mime,
                bytes: media.bytes
            });
            this.updateFigure(id, 'stored', this.t('js.as.image_stored'), false, media.url);
            this.refreshMediaStatus();
            this.onChange(this.state());
            return true;
        }

        updateFigure(id, kind, message, retry, previewUrl) {
            const figure = this.editor.querySelector('[data-ppa-pasted-image="' + id + '"]');
            if (!figure) return;
            figure.className = 'ppa-pasted-image ppa-pasted-image--' + kind;
            figure.dataset.sourceKind = kind;
            const label = figure.querySelector('.ppa-pasted-image__label');
            if (label) label.textContent = message;
            let image = figure.querySelector('img');
            if (previewUrl) {
                if (!image) {
                    image = document.createElement('img');
                    figure.prepend(image);
                }
                image.src = previewUrl;
                image.alt = (this.images.get(id) || {}).alt || '';
            }
            const actions = figure.querySelector('.ppa-pasted-image__actions');
            if (actions) {
                actions.querySelectorAll('.ppa-pasted-image__choose,.ppa-pasted-image__retry').forEach((button) => button.remove());
                const control = retry ? document.createElement('button') : this.createChooseButton(id);
                if (retry) {
                    control.type = 'button';
                    control.className = 'ppa-pasted-image__retry';
                    control.textContent = this.t('js.as.retry_upload');
                    control.addEventListener('click', () => this.uploadImage(id));
                }
                actions.prepend(control);
            }
        }

        refreshMediaStatus() {
            const state = this.state();
            if (state.externalImages > 0) {
                this.showExternalImagesSummary(state.externalImages);
            } else if (state.blockingImages > 0) {
                this.setStatus(this.t('js.as.images_blocking'), 'warning');
            } else if (state.images > 0) {
                this.setStatus(this.t('js.as.images_ready', { n: state.images }), 'success');
            } else if (this.status && this.status.dataset.kind === 'warning') {
                this.clearStatus();
            }
        }

        showExternalImagesSummary(count) {
            if (!this.status) return;
            this.status.replaceChildren();
            this.status.dataset.kind = 'warning';
            this.status.hidden = false;

            const message = document.createElement('span');
            message.className = 'ppa-composer__status-message';
            message.textContent = this.t('js.as.external_images_summary', { n: count });
            this.status.appendChild(message);

            const actions = document.createElement('span');
            actions.className = 'ppa-composer__status-actions';
            const continueButton = document.createElement('button');
            continueButton.type = 'button';
            continueButton.className = 'ppa-composer__status-action ppa-composer__status-action--primary';
            continueButton.textContent = this.t('js.as.continue_without_images');
            continueButton.addEventListener('click', () => this.continueWithoutExternalImages());
            actions.appendChild(continueButton);

            const reviewButton = document.createElement('button');
            reviewButton.type = 'button';
            reviewButton.className = 'ppa-composer__status-action';
            reviewButton.textContent = this.t('js.as.review_images');
            reviewButton.addEventListener('click', () => this.focusFirstExternalImage());
            actions.appendChild(reviewButton);
            this.status.appendChild(actions);
        }

        continueWithoutExternalImages() {
            const removedIds = discardExternalImageReferences(this.images);
            removedIds.forEach((id) => {
                const figure = this.editor.querySelector('[data-ppa-pasted-image="' + id + '"]');
                if (figure) figure.remove();
            });
            this.sync();
            const state = this.state();
            if (state.blockingImages > 0) {
                this.refreshMediaStatus();
            } else {
                this.setStatus(this.t('js.as.external_images_ignored', { n: removedIds.length }), 'info');
            }
        }

        focusFirstExternalImage() {
            const firstId = summarizeImageStates(this.images).externalIds[0];
            if (!firstId) return;
            const figure = this.editor.querySelector('[data-ppa-pasted-image="' + firstId + '"]');
            if (!figure) return;
            const choose = figure.querySelector('.ppa-pasted-image__choose');
            if (choose) choose.focus();
            if (typeof figure.scrollIntoView === 'function') figure.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        removeImage(id) {
            const image = this.images.get(id);
            if (image) {
                const figure = this.editor.querySelector('[data-ppa-pasted-image="' + id + '"] img');
                if (figure && figure.src && figure.src.startsWith('blob:')) {
                    URL.revokeObjectURL(figure.src);
                    this.objectUrls.delete(figure.src);
                }
            }
            this.images.delete(id);
        }

        fragmentFromPlainText(text) {
            const template = document.createElement('template');
            template.innerHTML = plainTextToHtml(text);
            return template.content;
        }

        insertHtml(html) {
            const template = document.createElement('template');
            template.innerHTML = html;
            this.insertFragment(template.content);
        }

        insertFragment(fragment) {
            const last = fragment.lastChild;
            const selection = window.getSelection();
            const range = selection && selection.rangeCount ? selection.getRangeAt(0) : null;
            if (range && this.editor.contains(range.commonAncestorContainer)) {
                range.deleteContents();
                range.insertNode(fragment);
                if (last) {
                    range.setStartAfter(last);
                    range.collapse(true);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            } else {
                this.editor.appendChild(fragment);
            }
        }

        sync() {
            this.fallback.value = this.getPlainText();
            const tooLong = this.fallback.value.length > this.maxChars;
            this.editor.setAttribute('aria-invalid', tooLong ? 'true' : 'false');
            if (tooLong) this.setStatus(this.t('js.as.rich_too_long', { n: this.maxChars.toLocaleString() }), 'error');
            else if (this.status && this.status.dataset.kind === 'error') this.clearStatus();
            this.onChange(this.state());
        }

        state() {
            const imageState = summarizeImageStates(this.images);
            return {
                empty: this.getPlainText() === '' && this.images.size === 0,
                valid: this.getPlainText().length <= this.maxChars && imageState.blocking === 0,
                chars: this.getPlainText().length,
                images: this.images.size,
                blockingImages: imageState.blocking,
                externalImages: imageState.external
            };
        }

        getPlainText() {
            const lineBlocks = new Set(['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'BLOCKQUOTE', 'TR', 'BR']);
            const startsLine = new Set(['UL', 'OL', 'TABLE']);
            let output = '';
            const visit = (node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    output += node.nodeValue || '';
                    return;
                }
                if (node.nodeType !== Node.ELEMENT_NODE || node.hasAttribute('data-ppa-pasted-image')) return;
                if (startsLine.has(node.tagName) && output !== '' && !output.endsWith('\n')) output += '\n';
                Array.from(node.childNodes).forEach(visit);
                if (node.tagName === 'TD' || node.tagName === 'TH') output += '\t';
                if (lineBlocks.has(node.tagName)) output += '\n';
            };
            Array.from(this.editor.childNodes).forEach(visit);
            return output.replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        }

        getRichHtml() {
            const clone = this.editor.cloneNode(true);
            Array.from(clone.querySelectorAll('[data-ppa-pasted-image]')).forEach((figure) => {
                const id = figure.dataset.ppaPastedImage;
                const item = this.images.get(id);
                if (!item) {
                    figure.remove();
                    return;
                }
                const image = document.createElement('img');
                image.alt = item.alt || '';
                if (item.kind === 'stored') {
                    image.dataset.ppaMediaId = String(item.mediaId);
                    image.dataset.ppaSource = item.path;
                } else {
                    image.dataset.ppaSource = item.kind === 'remote_url'
                        ? String(item.source || '')
                        : 'blob:ppa-unresolved/' + id;
                }
                figure.replaceWith(image);
            });
            clone.removeAttribute('contenteditable');
            clone.removeAttribute('aria-invalid');
            return clone.innerHTML;
        }

        reset() {
            this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.objectUrls.clear();
            this.images.clear();
            this.editor.replaceChildren();
            this.fallback.value = '';
            this.clearStatus();
            this.sync();
        }

        setStatus(message, kind) {
            if (!this.status) return;
            this.status.textContent = message;
            this.status.dataset.kind = kind || 'info';
            this.status.hidden = false;
        }

        clearStatus() {
            if (!this.status) return;
            this.status.textContent = '';
            this.status.hidden = true;
            delete this.status.dataset.kind;
        }
    }

    return {
        Composer: Composer,
        safeHref: safeHref,
        imageSourceKind: imageSourceKind,
        parseDataImage: parseDataImage,
        selectClipboardImages: selectClipboardImages,
        plainTextToHtml: plainTextToHtml,
        normalizeMediaItem: normalizeMediaItem,
        summarizeImageStates: summarizeImageStates,
        discardExternalImageReferences: discardExternalImageReferences
    };
}));
