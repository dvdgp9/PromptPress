/* CHROME-EDITOR — editor del header y el pie con vista previa en vivo. */
(function () {
    'use strict';
    var cfg = window.PP_CHROME || {};
    var pages = window.PP_PAGES || [];
    var baseUrl = window.PP_BASEURL || '';
    var csrf = window.PP_CSRF || '';

    var form = document.getElementById('chrome-form');
    if (!form) return;
    var menuList = document.getElementById('menu-list');
    var footerNavList = document.getElementById('footernav-list');
    var footerBlocks = document.getElementById('footer-blocks');
    var socialList = document.getElementById('social-list');
    var iframe = document.getElementById('chrome-preview');
    var previewFrame = document.getElementById('chrome-preview-frame');
    var saveBtn = document.getElementById('chrome-save');
    var dirtyBadge = document.getElementById('chrome-dirty');
    var initialized = false;
    var dirty = false;
    function setDirty(on) { dirty = on; if (dirtyBadge) dirtyBadge.hidden = !on; }
    function markDirty() { if (!dirty) setDirty(true); }
    function showToast(message, type) {
        var elx = document.createElement('div');
        elx.className = 'pp-toast pp-toast--' + (type || 'success');
        elx.textContent = message;
        document.body.appendChild(elx);
        requestAnimationFrame(function () { elx.classList.add('is-visible'); });
        setTimeout(function () { elx.classList.remove('is-visible'); setTimeout(function () { elx.remove(); }, 240); }, 3200);
    }
    function saveConfig() {
        if (saveBtn) saveBtn.disabled = true;
        var body = new URLSearchParams();
        body.set('_csrf', csrf);
        body.set('config_json', JSON.stringify(buildConfig()));
        fetch(baseUrl + '/admin/chrome', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: body.toString(), credentials: 'same-origin'
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json().catch(function () { return {}; });
        }).then(function (data) {
            setDirty(false);
            showToast((data && data.message) || 'Header y pie actualizados.', 'success');
        }).catch(function () {
            showToast('No se pudieron guardar los cambios. Inténtalo de nuevo.', 'error');
        }).then(function () { if (saveBtn) saveBtn.disabled = false; });
    }

    /* Vista previa: renderiza a ancho real de dispositivo y escala para encajar. */
    var DEVICES = { desktop: { w: 1280, h: 880 }, mobile: { w: 390, h: 760 } };
    var device = 'desktop';
    function fitPreview() {
        if (!previewFrame) return;
        var dim = DEVICES[device] || DEVICES.desktop;
        var avail = previewFrame.clientWidth || dim.w;
        var scale = avail / dim.w;
        iframe.style.width = dim.w + 'px';
        iframe.style.height = dim.h + 'px';
        iframe.style.transform = 'scale(' + scale + ')';
        previewFrame.style.height = (dim.h * scale) + 'px';
    }

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'class') n.className = attrs[k];
            else if (k === 'html') n.innerHTML = attrs[k];
            else n.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) n.appendChild(c); });
        return n;
    }
    function moveBtns(row, list) {
        var up = el('button', { type: 'button', class: 'pp-chrome-row__btn', title: 'Subir', html: '↑' });
        var dn = el('button', { type: 'button', class: 'pp-chrome-row__btn', title: 'Bajar', html: '↓' });
        up.addEventListener('click', function () { if (row.previousElementSibling) list.insertBefore(row, row.previousElementSibling); preview(); });
        dn.addEventListener('click', function () { if (row.nextElementSibling) list.insertBefore(row.nextElementSibling, row); preview(); });
        return [up, dn];
    }
    function delBtn(row) {
        var b = el('button', { type: 'button', class: 'pp-chrome-row__btn pp-chrome-row__btn--del', title: 'Quitar', html: '×' });
        b.addEventListener('click', function () { row.remove(); preview(); });
        return b;
    }
    function pageSelect(selectedId) {
        var s = el('select', { class: 'pp-chrome-page' });
        pages.forEach(function (p) {
            var o = el('option', { value: String(p.id) });
            o.textContent = p.title + (p.status === 'published' ? '' : ' (borrador)');
            if (String(p.id) === String(selectedId)) o.selected = true;
            s.appendChild(o);
        });
        return s;
    }

    /* ---------- Constructor genérico de ítems (página / enlace / submenú) ---------- */
    function itemRow(item, list, allowDropdown) {
        item = item || {};
        var type = item.type;
        if (type !== 'link' && !(allowDropdown && type === 'dropdown')) type = (type === 'link') ? 'link' : 'page';
        if (item.type === 'dropdown' && allowDropdown) type = 'dropdown';

        var row = el('div', { class: 'pp-chrome-row', 'data-type': type });
        var mv = moveBtns(row, list);
        var fields;

        if (type === 'dropdown') {
            var dlabel = el('input', { type: 'text', class: 'pp-chrome-f-label', placeholder: 'Nombre del submenú', maxlength: '120' });
            dlabel.value = item.label || '';
            var childList = el('div', { class: 'pp-chrome-childlist' });
            (item.children || []).forEach(function (c) {
                if (c && (c.type === 'page' || c.type === 'link')) childList.appendChild(itemRow(c, childList, false));
            });
            var addPage = el('button', { type: 'button', class: 'pp-btn pp-btn--secondary pp-btn--sm', html: '+ Página' });
            var addLink = el('button', { type: 'button', class: 'pp-btn pp-btn--secondary pp-btn--sm', html: '+ Enlace' });
            addPage.addEventListener('click', function () { childList.appendChild(itemRow({ type: 'page' }, childList, false)); preview(); });
            addLink.addEventListener('click', function () { childList.appendChild(itemRow({ type: 'link' }, childList, false)); preview(); });
            fields = el('div', { class: 'pp-chrome-row__fields pp-chrome-row__fields--col' }, [
                el('div', { class: 'pp-chrome-row__line' }, [el('span', { class: 'pp-chrome-row__tag', html: 'Submenú' }), dlabel]),
                childList,
                el('div', { class: 'pp-chrome-addrow' }, [addPage, addLink])
            ]);
        } else if (type === 'link') {
            var lbl = el('input', { type: 'text', class: 'pp-chrome-f-label', placeholder: 'Texto', maxlength: '120' });
            lbl.value = item.label || '';
            var url = el('input', { type: 'text', class: 'pp-chrome-f-url', placeholder: 'https://… o /pagina', maxlength: '300' });
            url.value = item.url || '';
            var tgt = el('input', { type: 'checkbox', class: 'pp-chrome-f-blank' });
            tgt.checked = item.target === '_blank';
            var tgtL = el('label', { class: 'pp-chrome-row__chk' }, [tgt, document.createTextNode(' nueva pestaña')]);
            fields = el('div', { class: 'pp-chrome-row__fields' }, [el('span', { class: 'pp-chrome-row__tag', html: 'Enlace' }), lbl, url, tgtL]);
        } else {
            var label = el('input', { type: 'text', class: 'pp-chrome-f-label', placeholder: 'Texto (opcional)', maxlength: '120' });
            label.value = item.label || '';
            var pageTgt = el('input', { type: 'checkbox', class: 'pp-chrome-f-blank' });
            pageTgt.checked = item.target === '_blank';
            var pageTgtL = el('label', { class: 'pp-chrome-row__chk' }, [pageTgt, document.createTextNode(' nueva pestaña')]);
            fields = el('div', { class: 'pp-chrome-row__fields' }, [el('span', { class: 'pp-chrome-row__tag', html: 'Página' }), pageSelect(item.page_id), label, pageTgtL]);
        }

        row.appendChild(fields);
        row.appendChild(el('div', { class: 'pp-chrome-row__actions' }, [mv[0], mv[1], delBtn(row)]));
        return row;
    }

    function readItem(row) {
        var type = row.getAttribute('data-type');
        if (type === 'dropdown') {
            var label = row.querySelector('.pp-chrome-f-label').value.trim();
            var children = readItems(row.querySelector('.pp-chrome-childlist'));
            if (!label || !children.length) return null;
            return { type: 'dropdown', label: label, children: children };
        }
        if (type === 'link') {
            var url = row.querySelector('.pp-chrome-f-url').value.trim();
            var lbl = row.querySelector('.pp-chrome-f-label').value.trim();
            if (!url || !lbl) return null;
            return { type: 'link', label: lbl, url: url, target: row.querySelector('.pp-chrome-f-blank').checked ? '_blank' : '_self' };
        }
        var pid = parseInt(row.querySelector('.pp-chrome-page').value, 10) || 0;
        if (pid <= 0) return null;
        return {
            type: 'page',
            page_id: pid,
            label: row.querySelector('.pp-chrome-f-label').value.trim(),
            target: row.querySelector('.pp-chrome-f-blank').checked ? '_blank' : '_self'
        };
    }
    function readItems(list) {
        var out = [];
        // Solo filas directas (no nietos): los hijos de un submenú se leen en readItem.
        Array.prototype.forEach.call(list.children, function (row) {
            if (!row.classList || !row.classList.contains('pp-chrome-row')) return;
            var it = readItem(row);
            if (it) out.push(it);
        });
        return out;
    }

    /* ---------- Redes ---------- */
    var SOCIAL_NETS = [
        ['instagram', 'Instagram'], ['facebook', 'Facebook'], ['x', 'X'], ['linkedin', 'LinkedIn'],
        ['youtube', 'YouTube'], ['tiktok', 'TikTok'], ['whatsapp', 'WhatsApp'], ['pinterest', 'Pinterest']
    ];
    function socialRow(item) {
        item = item || {};
        var known = SOCIAL_NETS.some(function (n) { return n[0] === String(item.network || '').toLowerCase(); });
        var sel = el('select', { class: 'pp-chrome-s-net' });
        SOCIAL_NETS.forEach(function (n) {
            var o = el('option', { value: n[0] });
            o.textContent = n[1];
            if (n[0] === String(item.network || '').toLowerCase()) o.selected = true;
            sel.appendChild(o);
        });
        var other = el('option', { value: '__other__' });
        other.textContent = 'Otro…';
        if (item.network && !known) other.selected = true;
        sel.appendChild(other);

        var custom = el('input', { type: 'text', class: 'pp-chrome-s-custom', placeholder: 'Nombre de la red', maxlength: '40' });
        custom.value = (item.network && !known) ? item.network : '';
        custom.hidden = !(item.network && !known);

        var url = el('input', { type: 'text', class: 'pp-chrome-s-url', placeholder: 'https://…', maxlength: '300' });
        url.value = item.url || '';

        sel.addEventListener('change', function () { custom.hidden = sel.value !== '__other__'; if (!custom.hidden) custom.focus(); preview(); });

        var row = el('div', { class: 'pp-chrome-row' });
        row.appendChild(el('div', { class: 'pp-chrome-row__fields' }, [sel, custom, url]));
        row.appendChild(el('div', { class: 'pp-chrome-row__actions' }, [delBtn(row)]));
        return row;
    }

    /* ---------- Hidratar ---------- */
    (function hydrate() {
        ((cfg.header && cfg.header.menu) || []).forEach(function (it) {
            if (it) menuList.appendChild(itemRow(it, menuList, true));
        });
        ((cfg.footer && cfg.footer.nav) || []).forEach(function (it) {
            if (it && (it.type === 'page' || it.type === 'link')) footerNavList.appendChild(itemRow(it, footerNavList, false));
        });
        ((cfg.footer && cfg.footer.social) || []).forEach(function (s) { socialList.appendChild(socialRow(s)); });
    })();

    /* ---------- Construir config ---------- */
    function val(id) { var n = document.getElementById(id); return n ? n.value : ''; }
    function chk(id) { var n = document.getElementById(id); return !!(n && n.checked); }
    function borderPart(prefix, side) {
        return {
            width: val(prefix + '_border_' + side + '_width'),
            color: val(prefix + '_border_' + side + '_color')
        };
    }
    function readBorder(prefix) {
        return {
            mode: val(prefix + '_border_mode') === 'sides' ? 'sides' : 'all',
            all: borderPart(prefix, 'all'),
            top: borderPart(prefix, 'top'),
            right: borderPart(prefix, 'right'),
            bottom: borderPart(prefix, 'bottom'),
            left: borderPart(prefix, 'left')
        };
    }

    function buildConfig() {
        var blocks = [];
        Array.prototype.forEach.call(footerBlocks.querySelectorAll('.pp-fblock'), function (block) {
            var on = block.querySelector('.pp-fblock-on');
            if (on && on.checked) blocks.push(block.getAttribute('data-fblock'));
        });
        var social = [];
        Array.prototype.forEach.call(socialList.children, function (row) {
            var sel = row.querySelector('.pp-chrome-s-net');
            var net = sel.value === '__other__' ? row.querySelector('.pp-chrome-s-custom').value.trim() : sel.value;
            var url = row.querySelector('.pp-chrome-s-url').value.trim();
            if (net && url) social.push({ network: net, url: url });
        });
        return {
            header: {
                layout: {
                    sticky: chk('h_sticky'),
                    transparent_over_hero: chk('h_transparent'),
                    density: val('h_density'),
                    logo_position: val('h_logo'),
                    width: val('h_width'),
                    nav_alignment: val('h_nav_alignment'),
                    mobile_cta: val('h_mobile_cta')
                },
                style: { background: val('h_bg'), border: readBorder('h') },
                brand: { url: val('h_brand_url').trim() },
                menu: readItems(menuList),
                cta: { mode: val('cta_mode'), label: val('cta_label').trim(), url: val('cta_url').trim(), style: val('cta_style') }
            },
            footer: {
                style: { background: val('f_bg'), columns: parseInt(val('f_columns'), 10) || 0, border: readBorder('f') },
                blocks: blocks,
                brand: { name: val('f_brand_name').trim() },
                labels: {
                    nav: val('f_label_nav').trim(),
                    legal: val('f_label_legal').trim(),
                    contact: val('f_label_contact').trim(),
                    social: val('f_label_social').trim(),
                    newsletter: val('f_label_newsletter').trim()
                },
                nav: readItems(footerNavList),
                tagline: val('f_tagline').trim(),
                copyright: val('f_copyright').trim(),
                contact: { address: val('c_address').trim(), phone: val('c_phone').trim(), email: val('c_email').trim(), hours: val('c_hours').trim() },
                social: social,
                newsletter: { enabled: blocks.indexOf('newsletter') !== -1, heading: val('n_heading').trim(), form_ref: val('n_form').trim(), cta_label: val('n_cta_label').trim() }
            }
        };
    }

    /* ---------- Vista previa ---------- */
    var previewTimer = null;
    function preview() {
        if (initialized) markDirty();
        clearTimeout(previewTimer);
        previewTimer = setTimeout(function () {
            var body = new URLSearchParams();
            body.set('_csrf', csrf);
            body.set('config_json', JSON.stringify(buildConfig()));
            fetch(baseUrl + '/admin/chrome/preview', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(), credentials: 'same-origin'
            }).then(function (r) { return r.text(); }).then(function (html) {
                iframe.srcdoc = html;
            }).catch(function () { /* silencioso */ });
        }, 250);
    }

    /* ---------- Eventos ---------- */
    document.querySelectorAll('[data-add-menu]').forEach(function (b) {
        b.addEventListener('click', function () { menuList.appendChild(itemRow({ type: b.getAttribute('data-add-menu') }, menuList, true)); preview(); });
    });
    document.querySelectorAll('[data-add-footernav]').forEach(function (b) {
        b.addEventListener('click', function () { footerNavList.appendChild(itemRow({ type: b.getAttribute('data-add-footernav') }, footerNavList, false)); preview(); });
    });
    var addSocial = document.getElementById('add-social');
    if (addSocial) addSocial.addEventListener('click', function () { socialList.appendChild(socialRow({})); preview(); });

    form.addEventListener('input', preview);
    form.addEventListener('change', preview);
    form.addEventListener('submit', function (e) { e.preventDefault(); saveConfig(); });

    function syncBorderEditor(prefix) {
        var mode = val(prefix + '_border_mode') === 'sides' ? 'sides' : 'all';
        var all = document.querySelector('[data-border-all="' + prefix + '"]');
        var sides = document.querySelector('[data-border-sides="' + prefix + '"]');
        if (all) all.hidden = mode !== 'all';
        if (sides) sides.hidden = mode !== 'sides';
    }
    ['h', 'f'].forEach(function (prefix) {
        syncBorderEditor(prefix);
        var mode = document.getElementById(prefix + '_border_mode');
        if (mode) mode.addEventListener('change', function () { syncBorderEditor(prefix); preview(); });
    });

    // Pestañas Header / Pie
    var chromeTabs = document.querySelectorAll('.pp-chrome-tabs [data-chrome-tab]');
    var chromePanels = document.querySelectorAll('[data-chrome-panel]');
    chromeTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-chrome-tab');
            chromeTabs.forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            chromePanels.forEach(function (p) {
                var on = p.getAttribute('data-chrome-panel') === target;
                p.classList.toggle('is-active', on);
                p.hidden = !on;
            });
        });
    });

    // CTA: los campos personalizados solo aparecen en modo "Personalizado"
    var ctaModeSel = document.getElementById('cta_mode');
    var ctaCustom = document.querySelector('[data-cta-custom]');
    function syncCtaCustom() { if (ctaCustom) ctaCustom.hidden = !(ctaModeSel && ctaModeSel.value === 'custom'); }
    if (ctaModeSel) ctaModeSel.addEventListener('change', syncCtaCustom);
    syncCtaCustom();

    // Bloques del pie: plegar/desplegar, reordenar e interruptor
    function setBlockOpen(block, open) {
        block.classList.toggle('is-open', open);
        var body = block.querySelector('.pp-fblock__body');
        if (body) body.hidden = !open;
        var toggle = block.querySelector('.pp-fblock__toggle');
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (footerBlocks) {
        Array.prototype.forEach.call(footerBlocks.querySelectorAll('.pp-fblock'), function (block) {
            var toggle = block.querySelector('.pp-fblock__toggle');
            var sw = block.querySelector('.pp-fblock-on');
            var up = block.querySelector('[data-fblock-up]');
            var down = block.querySelector('[data-fblock-down]');
            if (toggle) toggle.addEventListener('click', function () { setBlockOpen(block, !block.classList.contains('is-open')); });
            if (sw) sw.addEventListener('change', function () {
                block.classList.toggle('is-off', !sw.checked);
                if (sw.checked) setBlockOpen(block, true);
            });
            if (up) up.addEventListener('click', function () { if (block.previousElementSibling) footerBlocks.insertBefore(block, block.previousElementSibling); preview(); });
            if (down) down.addEventListener('click', function () { if (block.nextElementSibling) footerBlocks.insertBefore(block.nextElementSibling, block); preview(); });
        });
    }

    // Toggle de dispositivo (escritorio / móvil)
    document.querySelectorAll('.pp-chrome-devtoggle button').forEach(function (b) {
        b.addEventListener('click', function () {
            device = b.getAttribute('data-device') === 'mobile' ? 'mobile' : 'desktop';
            document.querySelectorAll('.pp-chrome-devtoggle button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
            fitPreview();
        });
    });
    var resizeTimer = null;
    window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(fitPreview, 150); });

    fitPreview();
    preview();
    initialized = true;
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
