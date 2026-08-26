/**
 * PromptPress Booking Widget (FEAT-3 B6).
 *
 * Dos modos, el mismo calendario:
 *
 * A) Web externa — el <script> lleva el servicio y la clave, y el widget se
 *    pinta justo donde está el <script>:
 *
 *      <script src="https://TU-SITIO/public/js/pp-booking-widget.js"
 *              data-service="3" data-key="API_KEY" defer></script>
 *
 * B) Tu propia web (MODULOS M2) — el HTML de la página trae contenedores y el
 *    script los rellena. Así lo emiten la sección "Calendario de reservas" y el
 *    placeholder {{booking:N}} de las páginas canvas, sin necesidad de clave
 *    (mismo origen) y sin que el gestor vea nunca un snippet:
 *
 *      <div data-pp-booking data-service="3" data-days="14"></div>
 *      <script src="/public/js/pp-booking-widget.js" defer></script>
 *
 * Atributos (en el <script> del modo A, en el contenedor del modo B):
 * - data-service: id del servicio reservable (obligatorio).
 * - data-key: API key del sitio; solo necesaria fuera del propio sitio
 *   (el origen externo debe estar además en la lista de orígenes permitidos).
 * - data-days: días de agenda a mostrar (por defecto 14, máx 31).
 *
 * Sin dependencias. Los estilos van con prefijo .ppbk- y no tocan la página
 * anfitriona; los colores salen de las variables del design system cuando
 * existen (tu propia web) y de los valores por defecto cuando no (web ajena).
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    // Base de la API: el origen del propio script (…/public/js/x.js → origen).
    var origin;
    try { origin = new URL(script.src).origin; } catch (e) { return; }
    var api = origin + '/api/booking/v1';

    function injectCss() {
        if (document.getElementById('ppbk-css')) return;
        var css = document.createElement('style');
        css.id = 'ppbk-css';
        // Cada color pasa por una variable del design system con el valor
        // histórico como respaldo: en tu propia web el calendario sale con tu
        // paleta; en una web ajena (sin variables) queda igual que siempre.
        css.textContent =
            '.ppbk{font-family:var(--pp-font-body,system-ui,-apple-system,sans-serif);max-width:420px;border:1px solid var(--pp-border,#e2e0da);border-radius:var(--pp-radius-card,14px);padding:18px;background:var(--pp-surface,#fff);color:var(--pp-text,#1f2937);box-sizing:border-box}' +
            '.ppbk *{box-sizing:border-box}' +
            '.ppbk h3{margin:0 0 4px;font-size:1.05rem;font-family:var(--pp-font-heading,inherit)}' +
            '.ppbk .ppbk-sub{margin:0 0 14px;font-size:.85rem;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-days{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px;margin-bottom:10px}' +
            '.ppbk-day{flex:0 0 auto;border:1px solid var(--pp-border,#e2e0da);background:var(--pp-bg,#faf9f7);color:inherit;border-radius:10px;padding:7px 10px;font:inherit;font-size:.82rem;cursor:pointer;text-align:center;min-width:64px}' +
            '.ppbk-day.on{border-color:var(--pp-primary,#c2410c);background:color-mix(in srgb,var(--pp-primary,#c2410c) 10%,#fff);font-weight:600}' +
            '.ppbk-day span{display:block;font-size:.72rem;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-slots{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}' +
            '.ppbk-slot{border:1px solid var(--pp-border,#e2e0da);background:var(--pp-surface,#fff);color:inherit;border-radius:8px;padding:6px 10px;font:inherit;font-size:.85rem;cursor:pointer}' +
            '.ppbk-slot.on{border-color:var(--pp-primary,#c2410c);background:var(--pp-primary,#c2410c);color:var(--pp-on-primary,#fff)}' +
            '.ppbk select{width:100%;border:1px solid var(--pp-border,#d9d6cf);border-radius:8px;padding:9px 10px;font:inherit;font-size:.9rem;margin-bottom:8px;background:var(--pp-surface,#fff);color:var(--pp-text,#1f2937)}' +
            '.ppbk .ppbk-bad{border-color:#b91c1c}' +
            '.ppbk input,.ppbk textarea{width:100%;border:1px solid var(--pp-border,#d9d6cf);border-radius:8px;padding:9px 10px;font:inherit;font-size:.9rem;margin-bottom:8px;background:var(--pp-surface,#fff);color:var(--pp-text,#1f2937)}' +
            '.ppbk-check{display:flex;align-items:flex-start;gap:8px;font-size:.88rem;margin-bottom:8px;cursor:pointer}' +
            '.ppbk-check input{width:auto;margin:2px 0 0;flex:0 0 auto}' +
            '.ppbk button.ppbk-submit{width:100%;border:0;border-radius:var(--pp-btn-radius,10px);background:var(--pp-primary,#c2410c);color:var(--pp-on-primary,#fff);padding:11px;font:inherit;font-size:.95rem;cursor:pointer}' +
            '.ppbk button.ppbk-submit:disabled{opacity:.55;cursor:default}' +
            '.ppbk-msg{padding:10px 12px;border-radius:10px;font-size:.88rem;margin-bottom:10px}' +
            '.ppbk-msg.ok{background:#ecfdf3;color:#166534}' +
            '.ppbk-msg.err{background:#fef2f2;color:#b91c1c}' +
            '.ppbk-hp{position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden}' +
            '.ppbk-soft{font-size:.78rem;color:var(--pp-text-muted,#9ca3af);margin:8px 0 0;text-align:center}';
        document.head.appendChild(css);
    }

    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text) el.textContent = text;
        return el;
    }

    /**
     * Monta UN calendario dentro de `root`.
     * Todo el estado vive aquí dentro, así que puede haber varios en la
     * misma página (dos servicios, dos secciones) sin pisarse.
     */
    function mount(root, serviceId, apiKey, days, lang) {
        var state = { service: null, days: [], selDay: null, selSlot: null, tzLabel: '', texts: {}, lang: '', botTs: '' };

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
            // El día de la semana lo pone el navegador con el locale del sitio:
            // antes era un array fijo en castellano.
            var wd;
            try {
                wd = d.toLocaleDateString(state.lang || undefined, { weekday: 'short' });
            } catch (e) {
                wd = d.toLocaleDateString(undefined, { weekday: 'short' });
            }
            return { top: wd, bottom: d.getDate() + '/' + (d.getMonth() + 1) };
        }

        function fmtTime(iso) {
            // La hora local del sitio viaja en el propio ISO (offset incluido): se
            // muestra tal cual llega, sin convertir a la zona del visitante.
            return iso.substring(11, 16);
        }

        /**
         * Texto del widget. Los sirve la API en el idioma del sitio (el widget es
         * estático y puede vivir en una web ajena, así que no puede deducirlo).
         * El fallback solo entra en juego antes de la primera respuesta.
         */
        function T(key, fallback) {
            var v = state.texts[key];
            return (typeof v === 'string' && v !== '') ? v : (fallback || '');
        }

        /** Sustituye {token} por su valor, igual que Microcopy en PHP. */
        function Tv(key, fallback, vars) {
            var out = T(key, fallback);
            Object.keys(vars).forEach(function (k) {
                out = out.split('{' + k + '}').join(vars[k]);
            });
            return out;
        }

        function render() {
            root.innerHTML = '';
            if (!state.service) { root.appendChild(h('p', 'ppbk-sub', T('loading', 'Cargando disponibilidad…'))); return; }

            root.appendChild(h('h3', null, state.service.name));
            var sub = state.service.duration_min + ' min';
            if (state.service.price_label) sub += ' · ' + state.service.price_label;
            root.appendChild(h('p', 'ppbk-sub', sub));

            if (!state.days.length) {
                root.appendChild(h('p', 'ppbk-sub', T('no_slots')));
                return;
            }

            var daysBar = h('div', 'ppbk-days');
            state.days.forEach(function (d) {
                var f = fmtDay(d.date);
                var b = h('button', 'ppbk-day' + (state.selDay === d.date ? ' on' : ''));
                b.type = 'button';
                b.appendChild(h('strong', null, f.top + ' ' + f.bottom));
                b.appendChild(h('span', null, Tv(d.slots.length === 1 ? 'slots_one' : 'slots_many', '', { n: d.slots.length })));
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

                // Los campos los decide el SERVICIO (MODULOS M8): la API manda su
                // definición y aquí solo se pintan. Si un embed antiguo no la
                // recibe, se usan los cuatro de siempre.
                var defs = (state.service && state.service.fields) || [
                    // Respaldo: los cuatro de siempre, por si node embed habla con
                    // una versión de la API anterior a los campos configurables.
                    { key: 'name',  type: 'text',     required: true,  label: T('ph_name') },
                    { key: 'email', type: 'email',    required: true,  label: T('ph_email') },
                    { key: 'phone', type: 'tel',      required: false, label: T('ph_phone') },
                    { key: 'notes', type: 'textarea', required: false, label: T('ph_notes') }
                ];
                var inputs = {};
                defs.forEach(function (f) {
                    var node;
                    if (f.type === 'textarea') {
                        node = h('textarea'); node.rows = 2; node.maxLength = 2000;
                    } else if (f.type === 'select') {
                        node = h('select');
                        var empty = h('option', null, f.label);
                        empty.value = '';
                        node.appendChild(empty);
                        (f.options || []).forEach(function (o) {
                            var op = h('option', null, o); op.value = o; node.appendChild(op);
                        });
                    } else if (f.type === 'checkbox') {
                        node = h('input'); node.type = 'checkbox';
                    } else {
                        node = h('input');
                        node.type = (f.type === 'email' || f.type === 'tel' || f.type === 'number' || f.type === 'date') ? f.type : 'text';
                        node.maxLength = f.key === 'name' ? 120 : (f.key === 'email' ? 190 : 255);
                    }
                    if (node.tagName !== 'SELECT') {
                        // La etiqueta va de placeholder salvo en la casilla, que
                        // necesita texto al lado para saber qué se está marcando.
                        if (f.type !== 'checkbox') node.placeholder = f.placeholder || f.label;
                    }
                    if (f.required) node.required = true;
                    inputs[f.key] = node;

                    if (f.type === 'checkbox') {
                        var wrap = h('label', 'ppbk-check');
                        wrap.appendChild(node);
                        wrap.appendChild(h('span', null, f.label + (f.required ? ' *' : '')));
                        form.appendChild(wrap);
                    } else {
                        form.appendChild(node);
                    }
                });

                var hp = h('input', 'ppbk-hp'); hp.name = 'company_url'; hp.tabIndex = -1; hp.autocomplete = 'off';
                var submit = h('button', 'ppbk-submit', Tv('book_at', '', { time: fmtTime(state.selSlot) }));
                submit.type = 'submit';
                form.appendChild(hp);
                form.appendChild(submit);

                form.addEventListener('submit', function (ev) {
                    ev.preventDefault();
                    submit.disabled = true;
                    msg.className = ''; msg.textContent = '';
                    var payload = {
                        service_id: serviceId,
                        start: state.selSlot,
                        company_url: hp.value,
                        // Idioma en el que el cliente ha reservado: sus emails y su
                        // página de cancelación saldrán en este idioma.
                        lang: lang || state.lang || '',
                        _pp_ts: state.botTs || ''
                    };
                    Object.keys(inputs).forEach(function (k) {
                        var node = inputs[k];
                        payload[k] = node.type === 'checkbox' ? (node.checked ? '1' : '') : node.value;
                    });
                    req('POST', api + '/bookings', payload).then(function (r) {
                        if (r.status === 201) {
                            root.innerHTML = '';
                            root.appendChild(h('h3', null, T('sent_title')));
                            var ok = h('div', 'ppbk-msg ok', r.data.message || T('registered'));
                            root.appendChild(ok);
                        } else if (r.status === 409) {
                            msg.className = 'ppbk-msg err';
                            msg.textContent = T('slot_taken');
                            submit.disabled = false;
                            load(); // refresca la agenda
                        } else if (r.status === 429) {
                            msg.className = 'ppbk-msg err';
                            msg.textContent = T('too_many');
                            submit.disabled = false;
                        } else {
                            var fields = (r.data && r.data.fields) || {};
                            var first = Object.keys(fields)[0];
                            msg.className = 'ppbk-msg err';
                            msg.textContent = first ? fields[first] : T('failed');
                            // Se marca el campo que falla: con un formulario a
                            // medida, "algo está mal" no basta para encontrarlo.
                            Object.keys(inputs).forEach(function (k) {
                                inputs[k].classList.toggle('ppbk-bad', !!fields[k]);
                            });
                            if (first && inputs[first] && inputs[first].focus) inputs[first].focus();
                            submit.disabled = false;
                        }
                    }).catch(function () {
                        msg.className = 'ppbk-msg err';
                        msg.textContent = T('network');
                        submit.disabled = false;
                    });
                });
                root.appendChild(form);
            }

            if (state.tzLabel) root.appendChild(h('p', 'ppbk-soft', Tv('local_time', '', { tz: state.tzLabel })));
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
                    root.appendChild(h('p', 'ppbk-sub', T('load_failed')));
                });
        }

        render();
        // Se indica el servicio para recibir los textos en SU idioma (en una web
        // multi-idioma, cada idioma tiene su propio servicio). Cuando el
        // calendario va dentro de una página de PromptPress, esa página SÍ sabe
        // en qué idioma se está leyendo y lo manda: entonces manda el idioma de
        // la página, no el del servicio.
        var url = api + '/services?service=' + serviceId + (lang ? '&lang=' + encodeURIComponent(lang) : '');
        req('GET', url).then(function (r) {
            if (r.status !== 200) throw new Error('services ' + r.status);
            // Idioma y textos del sitio, servidos por la API.
            state.texts = r.data.texts || {};
            state.lang = r.data.lang || '';
            state.service = (r.data.services || []).find(function (s) { return s.id === serviceId; }) || null;
            if (!state.service) {
                root.innerHTML = '';
                root.appendChild(h('p', 'ppbk-sub', T('service_unavailable')));
                return;
            }
            render();
            load();
        }).catch(function () {
            root.innerHTML = '';
            root.appendChild(h('p', 'ppbk-sub', 'No se pudo conectar con el sistema de reservas.'));
        });
    }

    function readDays(el) {
        return Math.min(31, Math.max(1, parseInt(el.getAttribute('data-days') || '14', 10)));
    }

    var scriptService = parseInt(script.getAttribute('data-service') || '0', 10);

    if (scriptService) {
        // Modo A: el snippet clásico de las webs externas.
        injectCss();
        var root = document.createElement('div');
        root.className = 'ppbk';
        script.parentNode.insertBefore(root, script.nextSibling);
        mount(root, scriptService, script.getAttribute('data-key') || '', readDays(script),
              script.getAttribute('data-lang') || '');
        return;
    }

    // Modo B: contenedores ya presentes en la página. El script puede aparecer
    // dos veces (dos calendarios en la misma página): el atributo -ready evita
    // montar dos veces sobre el mismo contenedor.
    var boxes = document.querySelectorAll('[data-pp-booking]:not([data-pp-booking-ready])');
    if (!boxes.length) return;
    injectCss();
    Array.prototype.forEach.call(boxes, function (box) {
        var sid = parseInt(box.getAttribute('data-service') || '0', 10);
        box.setAttribute('data-pp-booking-ready', '1');
        if (!sid) return;
        box.classList.add('ppbk');
        mount(box, sid, box.getAttribute('data-key') || '', readDays(box),
              box.getAttribute('data-lang') || '');
    });
})();
