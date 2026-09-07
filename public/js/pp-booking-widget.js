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
 * - data-width: card (por defecto, tarjeta de 420px), wide o full (100%).
 *
 * RSV-UI — La agenda se enseña como un CALENDARIO MENSUAL, no como la tira
 * horizontal con scroll de antes: con 14 o 31 días de ventana, arrastrar una
 * fila de pastillas obligaba a buscar a ciegas y escondía en qué semana caía
 * cada hueco. Ahora hay tres pasos visibles —día, hora, datos—, las horas van
 * agrupadas por franja (mañana / tarde / noche) y en los anchos grandes el
 * calendario y las horas se reparten en dos columnas.
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

    /** Anchos que entiende el contenedor. `card` es el de siempre. */
    var WIDTHS = { card: 1, wide: 1, full: 1 };

    function injectCss() {
        if (document.getElementById('ppbk-css')) return;
        var css = document.createElement('style');
        css.id = 'ppbk-css';
        // Cada color pasa por una variable del design system con el valor
        // histórico como respaldo: en tu propia web el calendario sale con tu
        // paleta; en una web ajena (sin variables) queda igual que siempre.
        //
        // Mapa de la hoja, por orden de aparición:
        //   .ppbk--w-*      anchos que elige el gestor (tarjeta / ancho / completo)
        //   .ppbk-body      una columna en la tarjeta, dos cuando hay sitio
        //   .ppbk-step      los rotulitos numerados de los tres pasos
        //   .ppbk-cal*      el calendario mensual: cabecera, rejilla y celdas
        //   .ppbk-when/-group/-slots  las horas del día elegido, por franjas
        //   .ppbk-chosen    el resumen de la cita, encima del formulario
        //
        // Los comentarios no bajan al interior de la concatenación a propósito:
        // el guardián de castellano de `tests/booking_microcopy.php` empareja
        // comillas en secuencia y leería el texto de en medio como una cadena.
        css.textContent =
            '.ppbk{font-family:var(--pp-font-body,system-ui,-apple-system,sans-serif);max-width:420px;border:1px solid var(--pp-border,#e2e0da);border-radius:var(--pp-radius-card,14px);padding:18px;background:var(--pp-surface,#fff);color:var(--pp-text,#1f2937);box-sizing:border-box}' +
            '.ppbk *{box-sizing:border-box}' +
            '.ppbk.ppbk--w-wide{max-width:760px;padding:clamp(18px,2vw,26px)}' +
            '.ppbk.ppbk--w-full{max-width:none;width:100%;padding:clamp(18px,2vw,28px)}' +
            '.ppbk h3{margin:0 0 4px;font-size:1.05rem;font-family:var(--pp-font-heading,inherit)}' +
            '.ppbk .ppbk-sub{margin:0 0 14px;font-size:.85rem;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-body{display:grid;gap:18px;align-items:start}' +
            '.ppbk--w-wide .ppbk-body,.ppbk--w-full .ppbk-body{grid-template-columns:minmax(250px,300px) 1fr;gap:clamp(20px,3vw,36px)}' +
            '@media (max-width:760px){.ppbk--w-wide .ppbk-body,.ppbk--w-full .ppbk-body{grid-template-columns:1fr}}' +
            '.ppbk-step{margin:0 0 8px;font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-step b{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;margin-right:6px;border-radius:50%;background:color-mix(in srgb,var(--pp-primary,#c2410c) 14%,transparent);color:var(--pp-primary,#c2410c);font-size:.68rem;vertical-align:-2px}' +
            '.ppbk-cal__head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}' +
            '.ppbk-cal__title{font-weight:600;font-size:.95rem}' +
            '.ppbk-cal__title::first-letter{text-transform:uppercase}' +
            '.ppbk-nav{width:30px;height:30px;flex:0 0 auto;border:1px solid var(--pp-border,#e2e0da);background:var(--pp-surface,#fff);color:inherit;border-radius:8px;font:inherit;font-size:1rem;line-height:1;cursor:pointer;padding:0}' +
            '.ppbk-nav:disabled{opacity:.35;cursor:default}' +
            '.ppbk-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}' +
            '.ppbk-wd{padding:2px 0 6px;text-align:center;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-day{position:relative;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;border:1px solid transparent;background:transparent;color:inherit;border-radius:9px;padding:0;font:inherit;font-size:.85rem;cursor:pointer}' +
            '.ppbk-day[disabled]{color:color-mix(in srgb,currentColor 38%,transparent);cursor:default}' +
            '.ppbk-day.free{background:var(--pp-bg,#faf9f7);border-color:var(--pp-border,#e2e0da);font-weight:600}' +
            '.ppbk-day.free:hover{border-color:var(--pp-primary,#c2410c)}' +
            '.ppbk-day.today{box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--pp-primary,#c2410c) 45%,transparent)}' +
            '.ppbk-day.on{background:var(--pp-primary,#c2410c);border-color:var(--pp-primary,#c2410c);color:var(--pp-on-primary,#fff)}' +
            '.ppbk-day.free::after{content:"";position:absolute;bottom:5px;width:4px;height:4px;border-radius:50%;background:var(--pp-primary,#c2410c)}' +
            '.ppbk-day.on::after{background:var(--pp-on-primary,#fff)}' +
            '.ppbk-when{margin:0 0 10px;font-size:.9rem;font-weight:600}' +
            '.ppbk-when::first-letter{text-transform:uppercase}' +
            '.ppbk-group{margin-bottom:12px}' +
            '.ppbk-group__label{margin:0 0 6px;font-size:.74rem;font-weight:600;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(74px,1fr));gap:6px}' +
            '.ppbk-slot{border:1px solid var(--pp-border,#e2e0da);background:var(--pp-surface,#fff);color:inherit;border-radius:9px;padding:8px 6px;font:inherit;font-size:.85rem;text-align:center;cursor:pointer}' +
            '.ppbk-slot:hover{border-color:var(--pp-primary,#c2410c)}' +
            '.ppbk-slot.on{border-color:var(--pp-primary,#c2410c);background:var(--pp-primary,#c2410c);color:var(--pp-on-primary,#fff)}' +
            '.ppbk-hint{margin:0;font-size:.85rem;color:var(--pp-text-muted,#6b7280)}' +
            '.ppbk-chosen{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px;padding:9px 12px;border-radius:10px;background:color-mix(in srgb,var(--pp-primary,#c2410c) 9%,transparent);font-size:.88rem;font-weight:600}' +
            '.ppbk-chosen span::first-letter{text-transform:uppercase}' +
            '.ppbk-chosen button{border:0;background:none;color:var(--pp-primary,#c2410c);font:inherit;font-size:.82rem;text-decoration:underline;cursor:pointer;padding:0;flex:0 0 auto}' +
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
            '.ppbk-soft{font-size:.78rem;color:var(--pp-text-muted,#9ca3af);margin:12px 0 0;text-align:center}';
        document.head.appendChild(css);
    }

    function h(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text) el.textContent = text;
        return el;
    }

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
    function parseYmd(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function monthStart(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
    function addMonths(d, n) { return new Date(d.getFullYear(), d.getMonth() + n, 1); }
    function sameMonth(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth(); }

    /**
     * Monta UN calendario dentro de `root`.
     * Todo el estado vive aquí dentro, así que puede haber varios en la
     * misma página (dos servicios, dos secciones) sin pisarse.
     */
    function mount(root, serviceId, apiKey, days, lang) {
        var state = {
            service: null, days: [], selDay: null, selSlot: null,
            tzLabel: '', texts: {}, lang: '', botTs: '',
            month: monthStart(new Date())
        };

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

        /** Locale para las fechas: el del sitio si llegó, y si no el del navegador. */
        function loc() { return state.lang || undefined; }

        function fmtParts(dateStr, opts) {
            var d = parseYmd(dateStr);
            try { return d.toLocaleDateString(loc(), opts); }
            catch (e) { return d.toLocaleDateString(undefined, opts); }
        }

        /** "viernes, 12 de septiembre" — el encabezado de las horas del día. */
        function fmtLongDay(dateStr) {
            return fmtParts(dateStr, { weekday: 'long', day: 'numeric', month: 'long' });
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

        // ---------------- Calendario ----------------

        /** Agenda por fecha: solo llegan los días QUE TIENEN huecos. */
        function dayOf(dateStr) {
            for (var i = 0; i < state.days.length; i++) {
                if (state.days[i].date === dateStr) return state.days[i];
            }
            return null;
        }

        /** Ventana visible: de hoy a hoy+días-1, que es lo que pide `load()`. */
        function windowFrom() { var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
        function windowTo() { var f = windowFrom(); return new Date(f.getFullYear(), f.getMonth(), f.getDate() + days - 1); }

        /**
         * Primer día de la semana según el idioma: en castellano el lunes, en
         * inglés el domingo. `getWeekInfo` no está en todos los navegadores, así
         * que el lunes hace de respaldo (es lo correcto en 5 de los 6 idiomas).
         */
        function firstWeekday() {
            try {
                var l = new Intl.Locale(state.lang || navigator.language || 'es');
                var wi = typeof l.getWeekInfo === 'function' ? l.getWeekInfo() : l.weekInfo;
                if (wi && wi.firstDay) return wi.firstDay % 7;   // 7 (domingo) → 0
            } catch (e) { /* sin Intl.Locale: lunes */ }
            return 1;
        }

        function weekdayLabels(first) {
            var out = [];
            // 2024-01-01 fue lunes: sirve de patrón para sacar los nombres.
            for (var i = 0; i < 7; i++) {
                var d = new Date(2023, 11, 31 + first + i);      // 31/12/2023 = domingo
                var txt;
                try { txt = d.toLocaleDateString(loc(), { weekday: 'short' }); }
                catch (e) { txt = d.toLocaleDateString(undefined, { weekday: 'short' }); }
                out.push(txt.replace('.', '').slice(0, 3));
            }
            return out;
        }

        function calendarNode() {
            var box = h('div', 'ppbk-cal');
            var from = windowFrom(), to = windowTo();
            var minM = monthStart(from), maxM = monthStart(to);
            if (state.month < minM) state.month = minM;
            if (state.month > maxM) state.month = maxM;

            var head = h('div', 'ppbk-cal__head');
            var prev = h('button', 'ppbk-nav', '‹');
            prev.type = 'button';
            prev.title = T('month_prev'); prev.setAttribute('aria-label', T('month_prev'));
            prev.disabled = !(state.month > minM);
            prev.addEventListener('click', function () { state.month = addMonths(state.month, -1); render(); });

            var next = h('button', 'ppbk-nav', '›');
            next.type = 'button';
            next.title = T('month_next'); next.setAttribute('aria-label', T('month_next'));
            next.disabled = !(state.month < maxM);
            next.addEventListener('click', function () { state.month = addMonths(state.month, 1); render(); });

            var title;
            try { title = state.month.toLocaleDateString(loc(), { month: 'long', year: 'numeric' }); }
            catch (e) { title = state.month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }); }
            head.appendChild(prev);
            head.appendChild(h('div', 'ppbk-cal__title', title));
            head.appendChild(next);
            box.appendChild(head);

            var grid = h('div', 'ppbk-grid');
            var first = firstWeekday();
            weekdayLabels(first).forEach(function (w) {
                var cell = h('div', 'ppbk-wd', w);
                cell.setAttribute('aria-hidden', 'true');
                grid.appendChild(cell);
            });

            // Huecos hasta el primer día del mes.
            var lead = (state.month.getDay() - first + 7) % 7;
            for (var i = 0; i < lead; i++) grid.appendChild(h('div', 'ppbk-cell'));

            var todayStr = ymd(new Date());
            var last = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 0).getDate();
            for (var n = 1; n <= last; n++) {
                var date = new Date(state.month.getFullYear(), state.month.getMonth(), n);
                var key = ymd(date);
                var day = dayOf(key);
                var cls = 'ppbk-day';
                if (day) cls += ' free';
                if (key === todayStr) cls += ' today';
                if (key === state.selDay) cls += ' on';
                var b = h('button', cls, String(n));
                b.type = 'button';
                if (!day) {
                    b.disabled = true;
                } else {
                    // El día lleva su fecha completa y cuántos huecos quedan: en la
                    // rejilla solo cabe el número, pero un lector de pantalla (y el
                    // ratón, por el title) sí pueden contarlo.
                    var count = Tv(day.slots.length === 1 ? 'slots_one' : 'slots_many', '', { n: day.slots.length });
                    b.title = fmtLongDay(key) + (count ? ' · ' + count : '');
                    b.setAttribute('aria-label', b.title);
                    b.setAttribute('aria-pressed', key === state.selDay ? 'true' : 'false');
                    (function (k) {
                        b.addEventListener('click', function () {
                            state.selDay = k; state.selSlot = null; render();
                        });
                    })(key);
                }
                grid.appendChild(b);
            }
            box.appendChild(grid);
            return box;
        }

        // ---------------- Horas ----------------

        /**
         * Las horas se agrupan por franja: una lista de 20 botones seguidos no
         * dice si las 8:30 son de mañana o si queda algo por la tarde.
         */
        function groupSlots(slots) {
            var groups = [
                { key: 'part_morning', items: [] },
                { key: 'part_afternoon', items: [] },
                { key: 'part_evening', items: [] }
            ];
            slots.forEach(function (s) {
                var hh = parseInt(s.start.substring(11, 13), 10);
                groups[hh < 14 ? 0 : (hh < 20 ? 1 : 2)].items.push(s);
            });
            return groups.filter(function (g) { return g.items.length > 0; });
        }

        function slotsNode() {
            var box = h('div', 'ppbk-times');
            var day = dayOf(state.selDay);
            if (!day) {
                box.appendChild(h('p', 'ppbk-hint', T('pick_day_first')));
                return box;
            }
            box.appendChild(h('p', 'ppbk-when', fmtLongDay(day.date)));
            if (!day.slots.length) {
                box.appendChild(h('p', 'ppbk-hint', T('no_day_slots')));
                return box;
            }
            var groups = groupSlots(day.slots);
            groups.forEach(function (g) {
                var wrap = h('div', 'ppbk-group');
                // Con una sola franja el rótulo sobra: no separa nada.
                if (groups.length > 1) wrap.appendChild(h('p', 'ppbk-group__label', T(g.key)));
                var list = h('div', 'ppbk-slots');
                g.items.forEach(function (s) {
                    var b = h('button', 'ppbk-slot' + (state.selSlot === s.start ? ' on' : ''), fmtTime(s.start));
                    b.type = 'button';
                    b.setAttribute('aria-pressed', state.selSlot === s.start ? 'true' : 'false');
                    b.addEventListener('click', function () { state.selSlot = s.start; render(); });
                    list.appendChild(b);
                });
                wrap.appendChild(list);
                box.appendChild(wrap);
            });
            return box;
        }

        // ---------------- Formulario ----------------

        function formNode() {
            var form = h('form');
            var msg = h('div');

            // Qué se ha elegido, con opción de volver: el botón de enviar dice la
            // hora, pero no el día, y hasta ahora no había forma de saber en qué
            // fecha se estaba reservando sin mirar arriba.
            var chosen = h('div', 'ppbk-chosen');
            chosen.appendChild(h('span', null, Tv('chosen', '', {
                date: fmtLongDay(state.selDay), time: fmtTime(state.selSlot)
            })));
            var back = h('button', null, T('change_choice'));
            back.type = 'button';
            back.addEventListener('click', function () { state.selSlot = null; render(); });
            chosen.appendChild(back);
            form.appendChild(chosen);
            form.appendChild(msg);

            // Los campos los decide el SERVICIO (MODULOS M8): la API manda su
            // definición y aquí solo se pintan. Si un embed antiguo no la
            // recibe, se usan los cuatro de siempre.
            var defs = (state.service && state.service.fields) || [
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
            return form;
        }

        /** Rótulo de paso: "1 Elige el día". */
        function step(n, key) {
            var p = h('p', 'ppbk-step');
            p.appendChild(h('b', null, String(n)));
            p.appendChild(document.createTextNode(T(key)));
            return p;
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

            // Dos columnas cuando el contenedor es ancho (lo decide el CSS): el
            // calendario a la izquierda, la hora y los datos a la derecha.
            var body = h('div', 'ppbk-body');
            var colCal = h('div', 'ppbk-col');
            colCal.appendChild(step(1, 'pick_day'));
            colCal.appendChild(calendarNode());
            body.appendChild(colCal);

            var colTime = h('div', 'ppbk-col');
            colTime.appendChild(step(2, 'pick_time'));
            colTime.appendChild(slotsNode());
            if (state.selSlot) {
                colTime.appendChild(step(3, 'pick_data'));
                colTime.appendChild(formNode());
            }
            body.appendChild(colTime);
            root.appendChild(body);

            if (state.tzLabel) root.appendChild(h('p', 'ppbk-soft', Tv('local_time', '', { tz: state.tzLabel })));
        }

        function load() {
            var from = windowFrom();
            var to = windowTo();
            req('GET', api + '/services/' + serviceId + '/availability?from=' + ymd(from) + '&to=' + ymd(to))
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
                    // El calendario se abre por el mes del primer día libre: si el
                    // hueco más cercano cae en octubre, empezar en septiembre sería
                    // enseñar una rejilla entera en gris.
                    if (state.selDay) {
                        var m = monthStart(parseYmd(state.selDay));
                        if (!sameMonth(m, state.month)) state.month = m;
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

    /** Ancho pedido por el contenedor; cualquier cosa rara vuelve a la tarjeta. */
    function applyWidth(root, el) {
        var w = (el.getAttribute('data-width') || 'card').toLowerCase();
        if (!WIDTHS[w]) w = 'card';
        root.classList.remove('ppbk--w-card', 'ppbk--w-wide', 'ppbk--w-full');
        root.classList.add('ppbk--w-' + w);
    }

    /**
     * Monta (o vuelve a montar) un contenedor del modo B. Se expone porque el
     * Canvas Studio cambia los ajustes del calendario —ancho, días de agenda— en
     * caliente y necesita que el widget se rehaga sin recargar la página.
     */
    function mountBox(box) {
        var sid = parseInt(box.getAttribute('data-service') || '0', 10);
        box.setAttribute('data-pp-booking-ready', '1');
        if (!sid) return;
        injectCss();
        box.classList.add('ppbk');
        applyWidth(box, box);
        box.innerHTML = '';
        mount(box, sid, box.getAttribute('data-key') || '', readDays(box),
              box.getAttribute('data-lang') || '');
    }
    window.ppBookingMount = mountBox;

    /**
     * RSV-TABS — Pestañas: un solo calendario que cambia de servicio.
     *
     * No hay N calendarios escondidos: la pestaña reescribe `data-service` del
     * único contenedor y lo remonta, así que la página solo pide la
     * disponibilidad del servicio que se está mirando.
     */
    function tabsWrapOf(el) {
        for (var n = el; n; n = n.parentElement) {
            if (n.hasAttribute && n.hasAttribute('data-pp-booking-tabs')) return n;
        }
        return null;
    }

    function tabsOf(wrap) {
        return Array.prototype.slice.call(wrap.querySelectorAll('[role="tab"]'));
    }

    function activateTab(tab) {
        var wrap = tabsWrapOf(tab);
        if (!wrap) return;
        var box = wrap.querySelector('[data-pp-booking]');
        var sid = tab.getAttribute('data-service');
        if (!box || !sid) return;

        tabsOf(wrap).forEach(function (t) {
            var on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
            t.classList.toggle('is-on', on);
        });
        if (tab.id) box.setAttribute('aria-labelledby', tab.id);

        // Ya se está enseñando: no hay por qué tirar el formulario a medias de
        // quien solo ha vuelto a pulsar la pestaña en la que estaba.
        if (box.getAttribute('data-service') === sid) return;

        box.setAttribute('data-service', sid);
        box.removeAttribute('data-pp-booking-ready');
        mountBox(box);
    }

    // El script se incluye una vez por embed, así que puede ejecutarse dos
    // veces en la misma página: sin esta bandera, cada clic se atendería dos
    // veces (y el calendario se montaría dos veces seguidas).
    if (!window.__ppBookingTabsWired) {
        window.__ppBookingTabsWired = 1;

        document.addEventListener('click', function (e) {
            var tab = e.target && e.target.closest ? e.target.closest('[role="tab"][data-service]') : null;
            if (tab && tabsWrapOf(tab)) activateTab(tab);
        });

        // Patrón de pestañas de la WAI: las flechas MUEVEN el foco y es Enter o
        // Espacio quien cambia de calendario. Activar al enfocar dispararía una
        // consulta de disponibilidad por cada tecla.
        document.addEventListener('keydown', function (e) {
            var tab = e.target && e.target.closest ? e.target.closest('[role="tab"][data-service]') : null;
            if (!tab) return;
            var wrap = tabsWrapOf(tab);
            if (!wrap) return;

            var tabs = tabsOf(wrap);
            var i = tabs.indexOf(tab);
            var next = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (i + 1) % tabs.length;
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (i - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') next = 0;
            else if (e.key === 'End') next = tabs.length - 1;
            else if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { activateTab(tab); e.preventDefault(); return; }
            if (next < 0) return;

            e.preventDefault();
            tabs[next].focus();
        });
    }

    var scriptService = parseInt(script.getAttribute('data-service') || '0', 10);

    if (scriptService) {
        // Modo A: el snippet clásico de las webs externas.
        injectCss();
        var root = document.createElement('div');
        root.className = 'ppbk';
        applyWidth(root, script);
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
    Array.prototype.forEach.call(boxes, mountBox);
})();
