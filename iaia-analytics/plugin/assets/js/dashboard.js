/**
 * dashboard.js — dashboard de IAIA Analytics.
 *
 * Portado de PromptPress (admin/assets/js/analytics-dashboard.js). Pinta la
 * gráfica diaria (SVG generado a mano, sin librerías), los KPIs con deltas
 * vs. el periodo anterior y los desgloses con barras de proporción. El
 * selector de rango pide JSON a la REST API (con nonce) y re-renderiza sin
 * recargar. Tooltip interactivo al pasar por la gráfica.
 */
(function () {
  'use strict';

  var root = document.getElementById('pp-analytics');
  if (!root) return;

  var endpoint = root.getAttribute('data-endpoint');
  var nonce = root.getAttribute('data-nonce');
  var chartWrap = root.querySelector('[data-chart]');
  var tooltip = root.querySelector('[data-tooltip]');
  var emptyBox = root.querySelector('[data-empty]');
  var breakdowns = root.querySelector('[data-breakdowns]');

  var LABELS = {
    referrer: { '': 'Directo' },
    device: { desktop: 'Ordenador', mobile: 'Móvil', tablet: 'Tablet' },
    browser: { chrome: 'Chrome', safari: 'Safari', firefox: 'Firefox', edge: 'Edge', opera: 'Opera', other: 'Otros' },
    event: { form_submit: 'Envío de formulario', booking_created: 'Reserva', purchase: 'Compra' }
  };
  // Dashicons (los carga siempre wp-admin): iconos nativos, sin emojis.
  var DEVICE_ICONS = { desktop: 'desktop', mobile: 'smartphone', tablet: 'tablet' };

  var fmt = function (n) { return Number(n).toLocaleString('es-ES'); };

  function labelFor(kind, key) {
    var map = LABELS[kind] || {};
    return map.hasOwnProperty(key) ? map[key] : key;
  }

  // ------------------------------------------------------------------ KPIs
  function renderKpis(stats) {
    var t = stats.totals, p = stats.prev;
    setKpi('visitors', t.visitors, p.visitors);
    setKpi('pageviews', t.pageviews, p.pageviews);
    setKpi('avg', t.avgPerDay, null);
    setKpi('events', t.events, null);
  }

  function setKpi(name, value, prevValue) {
    var card = root.querySelector('[data-kpi="' + name + '"]');
    if (!card) return;
    card.querySelector('.pp-analytics-kpi__value').textContent = fmt(value);
    var delta = card.querySelector('.pp-analytics-kpi__delta');
    if (!delta) return;
    if (prevValue === null || (prevValue === 0 && value === 0)) {
      delta.hidden = true;
      return;
    }
    if (prevValue === 0) {
      delta.hidden = true; // sin base de comparación: no inventar porcentajes
      return;
    }
    var pct = Math.round(((value - prevValue) / prevValue) * 100);
    delta.hidden = false;
    delta.textContent = (pct >= 0 ? '↑ +' : '↓ ') + pct + '%';
    delta.className = 'pp-analytics-kpi__delta ' +
      (pct >= 0 ? 'pp-analytics-kpi__delta--up' : 'pp-analytics-kpi__delta--down');
    delta.title = 'Respecto a los ' + stats.range + ' días anteriores';
  }

  // ----------------------------------------------------------------- Chart
  var W = 920, H = 260, PAD_L = 44, PAD_B = 26, PAD_T = 14, PAD_R = 10;
  var stats = null; // estado actual (para el tooltip)

  function renderChart(s) {
    var series = s.series;
    var n = series.length;
    var old = chartWrap.querySelector('svg');
    if (old) old.remove();

    var maxPv = 1;
    series.forEach(function (pt) { if (pt.pv > maxPv) maxPv = pt.pv; });
    // Techo "bonito": redondear a un múltiplo limpio.
    var step = Math.pow(10, Math.max(0, String(maxPv).length - 1));
    var top = Math.ceil(maxPv / step) * step;
    if (top < 4) top = 4;

    var plotW = W - PAD_L - PAD_R, plotH = H - PAD_T - PAD_B;
    var slot = plotW / n;
    var barW = Math.max(2, Math.min(26, slot * 0.62));

    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'pp-analytics-svg');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Gráfica de páginas vistas y visitantes por día');

    var x = function (i) { return PAD_L + slot * i + slot / 2; };
    var y = function (v) { return PAD_T + plotH * (1 - v / top); };

    // Rejilla horizontal + etiquetas del eje Y (4 líneas).
    for (var g = 0; g <= 4; g++) {
      var gv = Math.round(top * g / 4);
      var gy = y(gv);
      svg.appendChild(el('line', { x1: PAD_L, x2: W - PAD_R, y1: gy, y2: gy, class: 'pp-analytics-grid-line' }));
      svg.appendChild(el('text', { x: PAD_L - 8, y: gy + 4, class: 'pp-analytics-axis', 'text-anchor': 'end' }, fmt(gv)));
    }

    // Etiquetas del eje X (~6, según ancho).
    var every = Math.max(1, Math.ceil(n / 6));
    series.forEach(function (pt, i) {
      if (i % every !== 0 && i !== n - 1) return;
      var d = new Date(pt.d + 'T00:00:00');
      var lbl = d.getDate() + ' ' + d.toLocaleDateString('es-ES', { month: 'short' }).replace('.', '');
      svg.appendChild(el('text', { x: x(i), y: H - 6, class: 'pp-analytics-axis', 'text-anchor': 'middle' }, lbl));
    });

    // Barras de pageviews (con animación de crecimiento vía CSS).
    series.forEach(function (pt, i) {
      var by = y(pt.pv);
      var bar = el('rect', {
        x: x(i) - barW / 2, y: by, width: barW, height: Math.max(0, H - PAD_B - by),
        rx: Math.min(4, barW / 2), class: 'pp-analytics-bar', 'data-i': i
      });
      bar.style.transformOrigin = 'center ' + (H - PAD_B) + 'px';
      bar.style.animationDelay = (i * (360 / n)) + 'ms';
      svg.appendChild(bar);
    });

    // Línea + área de visitantes por encima de las barras.
    if (n > 1) {
      var pts = series.map(function (pt, i) { return x(i) + ',' + y(pt.vis); }).join(' ');
      var area = series.map(function (pt, i) { return x(i) + ',' + y(pt.vis); });
      area.push(x(n - 1) + ',' + (H - PAD_B), x(0) + ',' + (H - PAD_B));
      svg.appendChild(el('polygon', { points: area.join(' '), class: 'pp-analytics-area' }));
      svg.appendChild(el('polyline', { points: pts, class: 'pp-analytics-line' }));
      series.forEach(function (pt, i) {
        svg.appendChild(el('circle', { cx: x(i), cy: y(pt.vis), r: 2.5, class: 'pp-analytics-dot', 'data-i': i }));
      });
    }

    // Guía vertical para el hover (oculta por defecto).
    svg.appendChild(el('line', { x1: 0, x2: 0, y1: PAD_T, y2: H - PAD_B, class: 'pp-analytics-guide', 'data-guide': '', opacity: 0 }));

    // Zona de captura del ratón.
    var capture = el('rect', { x: PAD_L, y: PAD_T, width: plotW, height: plotH, fill: 'transparent' });
    svg.appendChild(capture);

    svg.addEventListener('mousemove', function (evt) {
      var rect = svg.getBoundingClientRect();
      var mx = (evt.clientX - rect.left) * (W / rect.width);
      var i = Math.round((mx - PAD_L - slot / 2) / slot);
      i = Math.max(0, Math.min(n - 1, i));
      showTooltip(i, x(i), rect, svg);
    });
    svg.addEventListener('mouseleave', hideTooltip);

    chartWrap.appendChild(svg);

    function el(tag, attrs, text) {
      var node = document.createElementNS('http://www.w3.org/2000/svg', tag);
      Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
      if (text !== undefined) node.textContent = text;
      return node;
    }
  }

  function showTooltip(i, svgX, rect, svg) {
    var pt = stats.series[i];
    if (!pt) return;
    var guide = svg.querySelector('[data-guide]');
    guide.setAttribute('x1', svgX); guide.setAttribute('x2', svgX);
    guide.setAttribute('opacity', 1);
    svg.querySelectorAll('.pp-analytics-bar').forEach(function (b) {
      b.classList.toggle('is-hover', Number(b.getAttribute('data-i')) === i);
    });

    var d = new Date(pt.d + 'T00:00:00');
    tooltip.innerHTML =
      '<strong>' + d.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' }) + '</strong>' +
      '<span><i class="pp-analytics-swatch pp-analytics-swatch--pv"></i>' + fmt(pt.pv) + ' vistas</span>' +
      '<span><i class="pp-analytics-swatch pp-analytics-swatch--vis"></i>' + fmt(pt.vis) + ' visitantes</span>';
    tooltip.hidden = false;

    var px = (svgX / W) * rect.width;
    var flip = px > rect.width - 150;
    tooltip.style.left = (px + (flip ? -tooltip.offsetWidth - 12 : 12)) + 'px';
    tooltip.style.top = '18px';
  }

  function hideTooltip() {
    tooltip.hidden = true;
    var svg = chartWrap.querySelector('svg');
    if (!svg) return;
    var guide = svg.querySelector('[data-guide]');
    if (guide) guide.setAttribute('opacity', 0);
    svg.querySelectorAll('.pp-analytics-bar.is-hover').forEach(function (b) { b.classList.remove('is-hover'); });
  }

  // ------------------------------------------------------------ Breakdowns
  function renderList(name, kind, rows, unitSingular, unitPlural) {
    var ol = root.querySelector('[data-list="' + name + '"]');
    if (!ol) return;
    ol.innerHTML = '';
    if (!rows.length) {
      var li = document.createElement('li');
      li.className = 'pp-analytics-list__empty';
      li.textContent = 'Sin datos en este periodo.';
      ol.appendChild(li);
      return;
    }
    var max = rows[0].pv || 1;
    rows.forEach(function (row, idx) {
      var li = document.createElement('li');
      li.className = 'pp-analytics-list__item';
      li.style.animationDelay = (idx * 40) + 'ms';
      var pct = Math.max(2, Math.round((row.pv / max) * 100));
      var units = row.pv === 1 ? unitSingular : unitPlural;
      li.innerHTML =
        '<span class="pp-analytics-list__bar" style="width:' + pct + '%"></span>' +
        '<span class="pp-analytics-list__label" title="' + escapeHtml(row.k) + '">' + escapeHtml(labelFor(kind, row.k)) + '</span>' +
        '<span class="pp-analytics-list__count" title="' + fmt(row.vis) + ' visitantes">' + fmt(row.pv) + ' <small>' + units + '</small></span>';
      ol.appendChild(li);
    });
  }

  function renderDevices(rows) {
    var box = root.querySelector('[data-devices]');
    if (!box) return;
    box.innerHTML = '';
    var total = rows.reduce(function (acc, r) { return acc + r.pv; }, 0);
    if (!total) {
      box.innerHTML = '<p class="pp-analytics-list__empty">Sin datos en este periodo.</p>';
      return;
    }
    rows.forEach(function (row) {
      var pct = Math.round((row.pv / total) * 100);
      var div = document.createElement('div');
      div.className = 'pp-analytics-device';
      div.innerHTML =
        '<span class="pp-analytics-device__icon dashicons dashicons-' + (DEVICE_ICONS[row.k] || 'laptop') + '"></span>' +
        '<span class="pp-analytics-device__name">' + escapeHtml(labelFor('device', row.k)) + '</span>' +
        '<span class="pp-analytics-device__track"><span class="pp-analytics-device__fill" style="width:' + pct + '%"></span></span>' +
        '<span class="pp-analytics-device__pct">' + pct + '%</span>';
      box.appendChild(div);
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ---------------------------------------------------------------- Render
  function render(s) {
    stats = s;
    // Guards: las vistas de detalle (F1) no tienen desgloses.
    var hasData = s.totals.pageviews > 0 || s.totals.events > 0;
    if (emptyBox) emptyBox.hidden = hasData;
    if (breakdowns) breakdowns.style.display = hasData ? '' : 'none';
    if (chartWrap && chartWrap.parentElement) chartWrap.parentElement.style.display = hasData ? '' : 'none';

    renderKpis(s);
    if (hasData) {
      renderChart(s);
      renderList('pages', 'page', s.pages, 'vista', 'vistas');
      renderList('referrers', 'referrer', s.referrers, 'vista', 'vistas');
      renderList('browsers', 'browser', s.browsers, 'vista', 'vistas');
      renderList('events', 'event', s.events, 'vez', 'veces');
      renderDevices(s.devices);
    }
  }

  // ------------------------------------------------------- Range switching
  var loading = false;
  // Solo los BOTONES de rango (dashboard, fetch); en list/detail son enlaces.
  document.querySelectorAll('button.pp-analytics-range').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (loading || btn.classList.contains('is-active')) return;
      loading = true;
      document.querySelectorAll('.pp-analytics-range').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
        b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
      });
      root.classList.add('is-loading');
      var url = endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + 'range=' + btn.getAttribute('data-range');
      fetch(url, { headers: { 'Accept': 'application/json', 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) { if (json && json.ok) render(json.stats); })
        .catch(function () { /* mantener la vista actual */ })
        .finally(function () { loading = false; root.classList.remove('is-loading'); });
    });
  });

  // ------------------------------------------------------------------ Init
  var seed = document.getElementById('pp-analytics-data');
  if (seed) {
    try { render(JSON.parse(seed.textContent)); } catch (e) { /* noop */ }
  }
})();
