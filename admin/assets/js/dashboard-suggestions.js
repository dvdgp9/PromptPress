/**
 * ASST-V2 F5 — Sugerencias del asistente en Inicio.
 *
 * Pide la lista tras cargar la página (la auditoría no frena Inicio). Cada
 * sugerencia abre el panel lateral con la petición escrita: «Arreglar» la
 * envía, «Escribirlo» espera el dato que falta, «Ver enlaces» lleva a su
 * pantalla. «Ahora no» la oculta 30 días. Nada de esto gasta IA hasta que el
 * usuario pulsa.
 */
(function () {
    'use strict';

    const card = document.getElementById('pp-sugg');
    const list = document.getElementById('pp-sugg-list');
    const status = document.getElementById('pp-sugg-status');
    if (!card || !list) return;

    function el(tag, cls, text) {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function empty() {
        list.remove();
        card.appendChild(el('p', 'pp-sugg__empty', pp.t('js.dash.sugg_empty')));
    }

    function dismiss(item, row, button) {
        button.disabled = true;
        fetch(card.dataset.dismissUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({ _csrf: card.dataset.csrf, id: item.id })
        })
            .then((res) => res.json().catch(() => ({})).then((data) => (res.ok && data.ok ? data : Promise.reject(new Error('dismiss')))))
            .then(() => {
                row.classList.add('is-leaving');
                if (status) status.textContent = pp.t('js.dash.sugg_hidden');
                const finish = () => {
                    row.remove();
                    if (!list.querySelector('.pp-sugg__item')) empty();
                };
                const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (reduce) finish();
                else window.setTimeout(finish, 220);
            })
            .catch(() => { button.disabled = false; });
    }

    function render(items) {
        list.textContent = '';
        if (items.length === 0) {
            empty();
            return;
        }
        items.forEach((item) => {
            const row = el('li', 'pp-sugg__item pp-sugg__item--' + item.severity);
            row.appendChild(el('span', 'pp-sugg__dot'));
            const body = el('div', 'pp-sugg__body');
            body.appendChild(el('p', 'pp-sugg__item-title', item.title));
            if (item.detail) body.appendChild(el('p', 'pp-sugg__detail', item.detail));
            row.appendChild(body);

            const actions = el('div', 'pp-sugg__actions');
            let cta;
            if (item.action === 'go') {
                cta = el('a', 'pp-btn pp-btn--secondary pp-btn--sm', item.cta);
                cta.href = item.url;
            } else {
                cta = el('button', 'pp-btn pp-btn--sm ' + (item.send ? 'pp-btn--primary' : 'pp-btn--secondary'), item.cta);
                cta.type = 'button';
                cta.setAttribute('data-assistant-open', '');
                cta.dataset.assistantPrompt = item.prompt;
                if (item.send) cta.setAttribute('data-assistant-send', '');
            }
            actions.appendChild(cta);
            const hide = el('button', 'pp-sugg__dismiss');
            hide.type = 'button';
            hide.setAttribute('aria-label', pp.t('js.dash.sugg_dismiss_x') + ': ' + item.title);
            hide.title = pp.t('js.dash.sugg_dismiss');
            hide.appendChild(el('span', 'pp-sugg__dismiss-x'));
            hide.addEventListener('click', () => dismiss(item, row, hide));
            actions.appendChild(hide);
            row.appendChild(actions);
            list.appendChild(row);
        });
    }

    fetch(card.dataset.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((res) => res.json().catch(() => ({})).then((data) => (res.ok && data.ok ? data : Promise.reject(new Error('load')))))
        .then((data) => render(Array.isArray(data.items) ? data.items : []))
        // Sin sugerencias no pasa nada: la tarjeta se va y el resto de Inicio sigue igual.
        .catch(() => card.remove())
        .finally(() => card.removeAttribute('aria-busy'));
})();
