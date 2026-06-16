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
    var blocksList = document.getElementById('blocks-list');
    var socialList = document.getElementById('social-list');
    var iframe = document.getElementById('chrome-preview');
    var hidden = document.getElementById('config_json');

    var BLOCK_LABELS = {
        brand: 'Marca y lema', nav: 'Navegación (Explora)', legal: 'Enlaces legales',
        contact: 'Contacto', social: 'Redes sociales', newsletter: 'Newsletter'
    };
    var ALL_BLOCKS = ['brand', 'nav', 'legal', 'contact', 'social', 'newsletter'];

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
            fields = el('div', { class: 'pp-chrome-row__fields' }, [el('span', { class: 'pp-chrome-row__tag', html: 'Página' }), pageSelect(item.page_id), label]);
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
        return { type: 'page', page_id: pid, label: row.querySelector('.pp-chrome-f-label').value.trim() };
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

    /* ---------- Bloques footer ---------- */
    function blockRow(key, enabled) {
        var row = el('div', { class: 'pp-chrome-row', 'data-block': key });
        var chk = el('input', { type: 'checkbox', class: 'pp-chrome-b-on' });
        chk.checked = !!enabled;
        var lab = el('label', { class: 'pp-chrome-row__chk' }, [chk, document.createTextNode(' ' + (BLOCK_LABELS[key] || key))]);
        var mv = moveBtns(row, blocksList);
        row.appendChild(el('div', { class: 'pp-chrome-row__fields' }, [lab]));
        row.appendChild(el('div', { class: 'pp-chrome-row__actions' }, [mv[0], mv[1]]));
        return row;
    }

    /* ---------- Redes ---------- */
    function socialRow(item) {
        item = item || {};
        var row = el('div', { class: 'pp-chrome-row' });
        var net = el('input', { type: 'text', class: 'pp-chrome-s-net', placeholder: 'Instagram', maxlength: '40' });
        net.value = item.network || '';
        var url = el('input', { type: 'text', class: 'pp-chrome-s-url', placeholder: 'https://…', maxlength: '300' });
        url.value = item.url || '';
        row.appendChild(el('div', { class: 'pp-chrome-row__fields' }, [net, url]));
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
        var conf = (cfg.footer && cfg.footer.blocks) || [];
        var def = ['brand', 'nav', 'legal'];
        var order = conf.length ? conf.slice() : def.slice();
        ALL_BLOCKS.forEach(function (b) { if (order.indexOf(b) === -1) order.push(b); });
        order.forEach(function (b) { blocksList.appendChild(blockRow(b, conf.length ? conf.indexOf(b) !== -1 : def.indexOf(b) !== -1)); });
        ((cfg.footer && cfg.footer.social) || []).forEach(function (s) { socialList.appendChild(socialRow(s)); });
    })();

    /* ---------- Construir config ---------- */
    function val(id) { var n = document.getElementById(id); return n ? n.value : ''; }
    function chk(id) { var n = document.getElementById(id); return !!(n && n.checked); }

    function buildConfig() {
        var blocks = [];
        Array.prototype.forEach.call(blocksList.children, function (row) {
            if (row.querySelector('.pp-chrome-b-on').checked) blocks.push(row.getAttribute('data-block'));
        });
        var social = [];
        Array.prototype.forEach.call(socialList.children, function (row) {
            var net = row.querySelector('.pp-chrome-s-net').value.trim();
            var url = row.querySelector('.pp-chrome-s-url').value.trim();
            if (net && url) social.push({ network: net, url: url });
        });
        return {
            header: {
                layout: { sticky: chk('h_sticky'), transparent_over_hero: chk('h_transparent'), density: val('h_density'), logo_position: val('h_logo') },
                menu: readItems(menuList),
                cta: { mode: val('cta_mode'), label: val('cta_label').trim(), url: val('cta_url').trim(), style: val('cta_style') }
            },
            footer: {
                style: { background: val('f_bg') },
                blocks: blocks,
                nav: readItems(footerNavList),
                tagline: val('f_tagline').trim(),
                copyright: val('f_copyright').trim(),
                contact: { address: val('c_address').trim(), phone: val('c_phone').trim(), email: val('c_email').trim(), hours: val('c_hours').trim() },
                social: social,
                newsletter: { enabled: chk('n_enabled'), heading: val('n_heading').trim(), form_ref: val('n_form').trim() }
            }
        };
    }

    /* ---------- Vista previa ---------- */
    var previewTimer = null;
    function preview() {
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

    document.getElementById('refresh-preview').addEventListener('click', preview);
    form.addEventListener('input', preview);
    form.addEventListener('change', preview);
    form.addEventListener('submit', function () { hidden.value = JSON.stringify(buildConfig()); });

    preview();
})();
