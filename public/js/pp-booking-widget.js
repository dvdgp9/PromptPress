/**
 * PromptPress Booking Widget (FEAT-3 B6).
 *
 * Uso (en cualquier web, también externa a PromptPress):
 *   <script src="https://TU-SITIO/public/js/pp-booking-widget.js"
 *           data-service="3" data-key="API_KEY" defer></script>
 *
 * - data-service: id del servicio reservable (obligatorio).
 * - data-key: API key del sitio; solo necesaria fuera del propio sitio
 *   (el origen externo debe estar además en la lista de orígenes permitidos).
 * - data-days: días de agenda a mostrar (por defecto 14, máx 31).
 *
 * El widget se pinta donde esté el <script>. Sin dependencias; los estilos
 * van con prefijo .ppbk- y no tocan la página anfitriona.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var serviceId = parseInt(script.getAttribute('data-service') || '0', 10);
    var apiKey = script.getAttribute('data-key') || '';
    var days = Math.min(31, Math.max(1, parseInt(script.getAttribute('data-days') || '14', 10)));
    if (!serviceId) return;

    // Base de la API: el origen del propio script (…/public/js/x.js → origen).
    var origin;
    try { origin = new URL(script.src).origin; } catch (e) { return; }
    var api = origin + '/api/booking/v1';

    var root = document.createElement('div');
    root.className = 'ppbk';
    script.parentNode.insertBefore(root, script.nextSibling);

    if (!document.getElementById('ppbk-css')) {
        var css = document.createElement('style');
        css.id = 'ppbk-css';
        css.textContent =
            '.ppbk{font-family:system-ui,-apple-system,sans-serif;max-width:420px;border:1px solid #e2e0da;border-radius:14px;padding:18px;background:#fff;color:#1f2937;box-sizing:border-box}' +
            '.ppbk *{box-sizing:border-box}' +
            '.ppbk h3{margin:0 0 4px;font-size:1.05rem}' +
            '.ppbk .ppbk-sub{margin:0 0 14px;font-size:.85rem;color:#6b7280}' +
            '.ppbk-days{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px;margin-bottom:10px}' +
            '.ppbk-day{flex:0 0 auto;border:1px solid #e2e0da;background:#faf9f7;border-radius:10px;padding:7px 10px;font-size:.82rem;cursor:pointer;text-align:center;min-width:64px}' +
            '.ppbk-day.on{border-color:#c2410c;background:#fff3ec;font-weight:600}' +
            '.ppbk-day span{display:block;font-size:.72rem;color:#6b7280}' +
            '.ppbk-slots{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}' +
            '.ppbk-slot{border:1px solid #e2e0da;background:#fff;border-radius:8px;padding:6px 10px;font-size:.85rem;cursor:pointer}' +
            '.ppbk-slot.on{border-color:#c2410c;background:#c2410c;color:#fff}' +
            '.ppbk input,.ppbk textarea{width:100%;border:1px solid #d9d6cf;border-radius:8px;padding:9px 10px;font:inherit;font-size:.9rem;margin-bottom:8px}' +
            '.ppbk button.ppbk-submit{width:100%;border:0;border-radius:10px;background:#c2410c;color:#fff;padding:11px;font:inherit;font-size:.95rem;cursor:pointer}' +
            '.ppbk button.ppbk-submit:disabled{opacity:.55;cursor:default}' +
            '.ppbk-msg{padding:10px 12px;border-radius:10px;font-size:.88rem;margin-bottom:10px}' +
            '.ppbk-msg.ok{background:#ecfdf3;color:#166534}' +
            '.ppbk-msg.err{background:#fef2f2;color:#b91c1c}' +
            '.ppbk-hp{position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden}' +
            '.ppbk-soft{font-size:.78rem;color:#9ca3af;margin:8px 0 0;text-align:center}';
        document.head.appendChild(css);
    }

    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text) el.textContent = text;
        return el;
    }

    function req(method, url, body) {
        var headers = { 'Content-Type': 'application/json' };
        if (apiKey) headers['X-Booking-Key'] = apiKey;
        return fetch(url, {
            method: method,
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().then(function (data) { return { status: r.status, data: data }; });
        });
    }

    function fmtDay(dateStr) {
        var d = new Date(dateStr + 'T12:00:00');
        var wd = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][d.getDay()];
        return { top: wd, bottom: d.getDate() + '/' + (d.getMonth() + 1) };
    }

    function fmtTime(iso) {
        // La hora local del sitio viaja en el propio ISO (offset incluido): se
        // muestra tal cual llega, sin convertir a la zona del visitante.
        return iso.substring(11, 16);
    }

    var state = { service: null, days: [], selDay: null, selSlot: null, tzLabel: '' };

    function render() {
        root.innerHTML = '';
        if (!state.service) { root.appendChild(h('p', 'ppbk-sub', 'Cargando disponibilidad…')); return; }

        root.appendChild(h('h3', null, state.service.name));
        var sub = state.service.duration_min + ' min';
        if (state.service.price_label) sub += ' · ' + state.service.price_label;
        root.appendChild(h('p', 'ppbk-sub', sub));

        if (!state.days.length) {
            root.appendChild(h('p', 'ppbk-sub', 'Ahora mismo no hay huecos disponibles. Vuelve a intentarlo más adelante.'));
            return;
        }

        var daysBar = h('div', 'ppbk-days');
        state.days.forEach(function (d) {
            var f = fmtDay(d.date);
            var b = h('button', 'ppbk-day' + (state.selDay === d.date ? ' on' : ''));
            b.type = 'button';
            b.appendChild(h('strong', null, f.top + ' ' + f.bottom));
            b.appendChild(h('span', null, d.slots.length + (d.slots.length === 1 ? ' hueco' : ' huecos')));
            b.addEventListener('click', function () { state.selDay = d.date; state.selSlot = null; render(); });
            daysBar.appendChild(b);
        });
        root.appendChild(daysBar);

        var day = state.days.find(function (d) { return d.date === state.selDay; });
        if (day) {
            var slotsBox = h('div', 'ppbk-slots');
            day.slots.forEach(function (s) {
                var b = h('button', 'ppbk-slot' + (state.selSlot === s.start ? ' on' : ''), fmtTime(s.start));
                b.type = 'button';
                b.addEventListener('click', function () { state.selSlot = s.start; render(); });
                slotsBox.appendChild(b);
            });
            root.appendChild(slotsBox);
        }

        if (state.selSlot) {
            var form = h('form');
            var msg = h('div');
            form.appendChild(msg);
            var name = h('input'); name.placeholder = 'Tu nombre *'; name.required = true; name.maxLength = 120;
            var email = h('input'); email.type = 'email'; email.placeholder = 'Tu email *'; email.required = true; email.maxLength = 190;
            var phone = h('input'); phone.type = 'tel'; phone.placeholder = 'Teléfono (opcional)'; phone.maxLength = 40;
            var notes = h('textarea'); notes.placeholder = 'Notas (opcional)'; notes.rows = 2; notes.maxLength = 2000;
            var hp = h('input', 'ppbk-hp'); hp.name = 'company_url'; hp.tabIndex = -1; hp.autocomplete = 'off';
            var submit = h('button', 'ppbk-submit', 'Reservar ' + fmtTime(state.selSlot));
            submit.type = 'submit';
            [name, email, phone, notes, hp, submit].forEach(function (el) { form.appendChild(el); });

            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                submit.disabled = true;
                msg.className = ''; msg.textContent = '';
                req('POST', api + '/bookings', {
                    service_id: serviceId,
                    start: state.selSlot,
                    name: name.value, email: email.value, phone: phone.value, notes: notes.value,
                    company_url: hp.value,
                    _pp_ts: state.botTs || ''
                }).then(function (r) {
                    if (r.status === 201) {
                        root.innerHTML = '';
                        root.appendChild(h('h3', null, '¡Reserva enviada!'));
                        var ok = h('div', 'ppbk-msg ok', r.data.message || 'Reserva registrada.');
                        root.appendChild(ok);
                    } else if (r.status === 409) {
                        msg.className = 'ppbk-msg err';
                        msg.textContent = 'Ese hueco se acaba de ocupar. Elige otro, por favor.';
                        submit.disabled = false;
                        load(); // refresca la agenda
                    } else if (r.status === 429) {
                        msg.className = 'ppbk-msg err';
                        msg.textContent = 'Demasiados intentos seguidos. Espera unos minutos.';
                        submit.disabled = false;
                    } else {
                        var fields = (r.data && r.data.fields) || {};
                        var first = Object.keys(fields)[0];
                        msg.className = 'ppbk-msg err';
                        msg.textContent = first ? fields[first] : 'No se pudo completar la reserva. Revisa los datos.';
                        submit.disabled = false;
                    }
                }).catch(function () {
                    msg.className = 'ppbk-msg err';
                    msg.textContent = 'Error de conexión. Inténtalo de nuevo.';
                    submit.disabled = false;
                });
            });
            root.appendChild(form);
        }

        if (state.tzLabel) root.appendChild(h('p', 'ppbk-soft', 'Horario local: ' + state.tzLabel));
    }

    function load() {
        var from = new Date();
        var to = new Date(from.getTime() + (days - 1) * 86400000);
        var iso = function (d) { return d.toISOString().substring(0, 10); };
        req('GET', api + '/services/' + serviceId + '/availability?from=' + iso(from) + '&to=' + iso(to))
            .then(function (r) {
                if (r.status !== 200) throw new Error('availability ' + r.status);
                state.days = r.data.days || [];
                state.tzLabel = r.data.timezone || '';
                // FEAT-4 AB5 — ancla firmada del time-trap anti-bot.
                if (r.data.bot_ts) state.botTs = r.data.bot_ts;
                if (state.days.length && !state.days.some(function (d) { return d.date === state.selDay; })) {
                    state.selDay = state.days[0].date;
                    state.selSlot = null;
                }
                render();
            })
            .catch(function () {
                root.innerHTML = '';
                root.appendChild(h('p', 'ppbk-sub', 'No se pudo cargar la disponibilidad.'));
            });
    }

    render();
    req('GET', api + '/services').then(function (r) {
        if (r.status !== 200) throw new Error('services ' + r.status);
        state.service = (r.data.services || []).find(function (s) { return s.id === serviceId; }) || null;
        if (!state.service) {
            root.innerHTML = '';
            root.appendChild(h('p', 'ppbk-sub', 'Este servicio no está disponible.'));
            return;
        }
        render();
        load();
    }).catch(function () {
        root.innerHTML = '';
        root.appendChild(h('p', 'ppbk-sub', 'No se pudo conectar con el sistema de reservas.'));
    });
})();
