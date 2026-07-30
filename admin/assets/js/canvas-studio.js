/**
 * FH3 — Studio Live: chat conversacional sobre la página en vivo.
 *
 * Principios UX (usuario NO técnico):
 *  - Una sola acción primaria: escribir y "Aplicar cambio".
 *  - El estado siempre visible: pensando / aplicado / error, en el propio chat.
 *  - Deshacer a un clic tras cada cambio. Historial completo a un clic.
 *  - La selección de sección es opcional y se explica sola.
 */
(function () {
  'use strict';

  var body = document.body;
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var iframe = document.getElementById('studio-iframe');
  var frameWrap = document.getElementById('studio-frame-wrap');
  var messages = document.getElementById('chat-messages');
  var form = document.getElementById('chat-form');
  var input = document.getElementById('chat-input');
  var sendBtn = document.getElementById('chat-send');
  var ctxBox = document.getElementById('chat-context');
  var ctxLabel = document.getElementById('chat-context-label');
  var ctxClear = document.getElementById('chat-context-clear');
  var modelSelect = document.getElementById('chat-model');

  var selectedSection = null;
  var selectedElementContext = '';
  var selectedElementPath = '';
  var busy = false;
  var lastScrollY = 0;
  // STUDIO-2 B1 — últimos turnos de la conversación (se envían como contexto).
  var chatHistory = [];
  // STUDIO-2 B3 — sección que acaba de cambiar, para señalarla tras recargar.
  var pendingFlash = '';

  // ----------------------------------------------------------------
  // STUDIO-2 A3 — Chat flotante: pastilla plegada / panel desplegado.
  // La barra lateral queda entera para la edición manual; el chat se pliega
  // cuando no se usa y devuelve el ancho al lienzo.
  // ----------------------------------------------------------------
  var dock = document.getElementById('chat-dock');
  var chatPill = document.getElementById('chat-pill');
  var chatPillLabel = document.getElementById('chat-pill-label');
  var chatPillDot = document.getElementById('chat-pill-dot');
  var chatMinimize = document.getElementById('chat-minimize');
  var DOCK_KEY = 'pp-studio-chat-open';

  function pillText() {
    if (busy) return 'Aplicando el cambio…';
    if (selectedSection) return 'Cambiar «' + (ctxLabel.textContent || 'esta parte') + '»';
    return 'Pídeme un cambio';
  }

  function refreshPill() {
    chatPillLabel.textContent = pillText();
    chatPill.title = 'Abrir la conversación';
  }

  function setDock(open, remember) {
    dock.classList.toggle('is-open', open);
    chatPill.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      chatPillDot.hidden = true;
      messages.scrollTop = messages.scrollHeight;
    }
    if (remember !== false) {
      try { localStorage.setItem(DOCK_KEY, open ? '1' : '0'); } catch (e) { /* modo privado */ }
    }
    refreshPill();
  }

  function dockIsOpen() { return dock.classList.contains('is-open'); }

  // Abierto la primera vez (así se ve de qué va); después, lo que eligió el usuario.
  var dockPref = '1';
  try { dockPref = localStorage.getItem(DOCK_KEY) || '1'; } catch (e) { /* modo privado */ }
  setDock(dockPref !== '0', false);

  chatPill.addEventListener('click', function () { setDock(true); input.focus(); });
  chatMinimize.addEventListener('click', function () { setDock(false); });

  // ----------------------------------------------------------------
  // Mensajes del chat
  // ----------------------------------------------------------------
  function addMsg(kind, html) {
    var div = document.createElement('div');
    div.className = 'pp-chat-msg pp-chat-msg--' + kind;
    div.innerHTML = html;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    // Con el chat plegado, un punto avisa de que hay respuesta esperando.
    if (kind.indexOf('assistant') === 0 && dock && !dockIsOpen()) chatPillDot.hidden = false;
    return div;
  }
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // Tiempo relativo legible ("hace 2 min", "hace 1 h"). El backend manda
  // datetime en hora del servidor; lo parseamos como local.
  function relTime(sqlDate) {
    var t = Date.parse((sqlDate || '').replace(' ', 'T'));
    if (isNaN(t)) return sqlDate || '';
    var diff = Math.max(0, (Date.now() - t) / 1000);
    if (diff < 60) return 'hace un momento';
    if (diff < 3600) return 'hace ' + Math.floor(diff / 60) + ' min';
    if (diff < 86400) return 'hace ' + Math.floor(diff / 3600) + ' h';
    if (diff < 604800) return 'hace ' + Math.floor(diff / 86400) + ' días';
    return new Date(t).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  // ----------------------------------------------------------------
  // Selección de sección (mensajes desde el iframe)
  // ----------------------------------------------------------------
  window.addEventListener('message', function (e) {
    var d = e.data || {};
    if (d.source !== 'pp-studio') return;
    if (d.type === 'section-selected') {
      selectedSection = d.id;
      ctxLabel.textContent = d.label;
      ctxBox.hidden = false;
      input.placeholder = 'Ej.: cambia el titular de esta parte';
      markCurrentSection();
      refreshPill();
      // Si el usuario está editando texto EN la página, el foco es suyo:
      // robárselo aquí era el bug que impedía escribir inline. Y con el chat
      // plegado no hay dónde escribir: tampoco se toca el foco.
      if (!d.editing && dockIsOpen()) input.focus();
    }
    if (d.type === 'section-deselected') { clearSelection(false); closePanel(); }
    if (d.type === 'section-changed') saveSectionInline(d.id, d.html);
    if (d.type === 'image-clicked') openMediaModal();
    if (d.type === 'element-selected') {
      selectedElementContext = (d.kind || 'elemento') + (d.props && d.props.text ? ' con texto "' + d.props.text.slice(0, 180) + '"' : '');
      selectedElementPath = d.elementPath || '';
      openPanel(d);
    }
    if (d.type === 'element-deselected') { selectedElementContext = ''; selectedElementPath = ''; closePanel(); }
    if (d.type === 'ready') {
      if (d.palette) brandPalette = d.palette;
      renderSectionList(d.sections || []);
      if (lastScrollY > 0) {
        iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'scroll-to', y: lastScrollY }, '*');
        if (selectedSection) {
          iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'select', id: selectedSection }, '*');
        }
        lastScrollY = 0;
      }
      // El destello va después del scroll restaurado: manda la vista a lo que
      // ha cambiado, que es lo que el usuario quiere ver.
      if (pendingFlash) {
        tellIframe({ type: 'flash', id: pendingFlash });
        pendingFlash = '';
      }
    }
  });

  // ----------------------------------------------------------------
  // STUDIO-2 A1 — Barra lateral: panel de controles cuando hay algo
  // seleccionado; si no, ayuda + las partes de la página para llegar a cada una
  // sin tener que acertar con el ratón.
  // ----------------------------------------------------------------
  var sideEmpty = document.getElementById('side-empty');
  var sectionList = document.getElementById('side-sections');
  var pageSections = [];

  // "cta-final" → "Cta final" (misma regla que el overlay del iframe).
  function sectionLabel(id) {
    var s = String(id || '').replace(/[-_]+/g, ' ').trim();
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Sección';
  }

  function tellIframe(msg) {
    if (iframe.contentWindow) {
      msg.source = 'pp-studio-parent';
      iframe.contentWindow.postMessage(msg, '*');
    }
  }

  function renderSectionList(ids) {
    pageSections = Array.isArray(ids) ? ids : [];
    if (!sectionList) return;
    if (!pageSections.length) {
      sectionList.innerHTML = '<li class="cvstudio-side__hint">Esta página todavía no tiene partes editables.</li>';
      return;
    }
    sectionList.innerHTML = '';
    pageSections.forEach(function (id, i) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.section = id;
      btn.innerHTML = '<span class="cvstudio-seclist__num">' + (i + 1) + '</span><span>' + esc(sectionLabel(id)) + '</span>';
      btn.addEventListener('click', function () { tellIframe({ type: 'select', id: id, panel: true }); });
      btn.addEventListener('mouseenter', function () { tellIframe({ type: 'highlight', id: id, on: true }); });
      btn.addEventListener('mouseleave', function () { tellIframe({ type: 'highlight', on: false }); });
      li.appendChild(btn);
      sectionList.appendChild(li);
    });
    markCurrentSection();
  }

  function markCurrentSection() {
    if (!sectionList) return;
    sectionList.querySelectorAll('button[data-section]').forEach(function (b) {
      b.classList.toggle('is-current', b.dataset.section === selectedSection);
    });
  }

  // La barra enseña una cosa u otra, nunca las dos ni ninguna.
  function showSide(which) {
    panel.hidden = which !== 'panel';
    if (sideEmpty) sideEmpty.hidden = which === 'panel';
  }

  // ----------------------------------------------------------------
  // FH7 — Panel contextual de edición directa
  // ----------------------------------------------------------------
  var panel = document.getElementById('edit-panel');
  var brandPalette = {};
  var LINKS = Array.isArray(window.PP_LINK_TARGETS) ? window.PP_LINK_TARGETS : [];

  function applyOp(op, value, preview) {
    if (!iframe.contentWindow) return;
    iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'apply', op: op, value: value, preview: !!preview }, '*');
  }

  function closePanel() { panel.innerHTML = ''; showSide('empty'); }

  // ---- Utilidades de color (comparar el color actual con la paleta) ----
  function toHex(c) {
    if (!c) return '';
    if (c.charAt(0) === '#') return c.length === 4 ? '#' + c[1] + c[1] + c[2] + c[2] + c[3] + c[3] : c.toLowerCase();
    var m = c.match(/rgba?\(([^)]+)\)/);
    if (!m) return '';
    var p = m[1].split(',').map(function (x) { return parseFloat(x); });
    if (p.length >= 4 && p[3] === 0) return ''; // transparente → sin color
    var h = function (n) { n = Math.max(0, Math.min(255, Math.round(n))).toString(16); return n.length === 1 ? '0' + n : n; };
    return '#' + h(p[0]) + h(p[1]) + h(p[2]);
  }
  function isTransparent(c) {
    if (!c) return true;
    var m = c.match(/rgba?\(([^)]+)\)/);
    if (m) { var p = m[1].split(','); return p.length >= 4 && parseFloat(p[3]) === 0; }
    return c === 'transparent';
  }
  function sameColor(a, b) { var ha = toHex(a), hb = toHex(b); return !!ha && ha === hb; }
  function paletteMatch(cur) {
    var keys = ['primary', 'text', 'text-muted', 'on-primary'];
    for (var i = 0; i < keys.length; i++) if (sameColor(cur, brandPalette[keys[i]])) return keys[i];
    return null;
  }

  // Campo de color reutilizable: swatches de paleta + picker libre + (opcional
  // "sin relleno") + reset. Marca activo lo que coincide con el color actual.
  function colorField(labelTxt, op, opts) {
    opts = opts || {};
    var cur = opts.current || '';
    var match = paletteMatch(cur);
    var transp = isTransparent(cur);
    var names = [['primary', 'Principal'], ['text', 'Texto'], ['text-muted', 'Apagado'], ['on-primary', 'Claro']];
    var sw = names.map(function (n) {
      var c = brandPalette[n[0]] || '#ccc';
      return '<button type="button" class="cvstudio-swatch' + (match === n[0] ? ' is-on' : '') + '" data-cop="' + op + '" data-cval="' + n[0] + '" title="' + n[1] + '" style="background:' + c + '"></button>';
    }).join('');
    // El picker muestra el color actual si es "a medida" (no coincide con paleta).
    var hex = toHex(cur);
    var custom = hex && !match && !transp;
    var pickerStyle = custom ? ' style="background:' + hex + '"' : '';
    var picker = '<label class="cvstudio-colorpick' + (custom ? ' is-on' : '') + '"' + pickerStyle + ' title="Color personalizado">'
      + '<input type="color" data-cinput="' + op + '" value="' + (hex || '#000000') + '"></label>';
    var none = opts.none ? '<button type="button" class="cvstudio-swatch cvstudio-swatch--reset' + (transp ? ' is-on' : '') + '" data-cop="' + op + '" data-cval="none" title="Sin relleno">∅</button>' : '';
    var reset = '<button type="button" class="cvstudio-swatch cvstudio-swatch--reset" data-cop="' + op + '" data-cval="reset" title="Quitar">×</button>';
    return '<div class="cvstudio-field"><label>' + esc(labelTxt) + '</label><div class="cvstudio-swatches">' + sw + picker + none + reset + '</div></div>';
  }

  function sizeField() {
    return '<div class="cvstudio-field"><label>Tamaño</label><div class="cvstudio-btnrow">'
      + '<button type="button" data-op="size" data-val="down" title="Más pequeño">A−</button>'
      + '<button type="button" data-op="size" data-val="reset" title="Tamaño normal">A</button>'
      + '<button type="button" data-op="size" data-val="up" title="Más grande">A+</button>'
    + '</div></div>';
  }

  function textControls(props) {
    return ''
      + sizeField()
      + '<div class="cvstudio-field"><label>Estilo</label><div class="cvstudio-btnrow">'
        + '<button type="button" data-toggle="bold" class="' + (props.bold ? 'is-on' : '') + '" title="Negrita"><b>B</b></button>'
        + '<button type="button" data-toggle="italic" class="' + (props.italic ? 'is-on' : '') + '" title="Cursiva"><i>I</i></button>'
      + '</div></div>'
      + '<div class="cvstudio-field"><label>Alineación</label><div class="cvstudio-btnrow">'
        + '<button type="button" data-op="align" data-val="left" title="Izquierda">⬅</button>'
        + '<button type="button" data-op="align" data-val="center" title="Centro">↔</button>'
        + '<button type="button" data-op="align" data-val="right" title="Derecha">➡</button>'
        + '<button type="button" data-op="align" data-val="justify" title="Justificado">☰</button>'
      + '</div></div>'
      + colorField('Color del texto', 'color', { current: props.color });
  }

  function radiusField() {
    return '<div class="cvstudio-field"><label>Esquinas</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
      + '<button type="button" data-op="radius" data-val="sharp">Recto</button>'
      + '<button type="button" data-op="radius" data-val="soft">Suave</button>'
      + '<button type="button" data-op="radius" data-val="round">Redondo</button>'
      + '<button type="button" data-op="radius" data-val="pill">Píldora</button>'
    + '</div></div>';
  }

  function cornerFields(props) {
    var fields = [
      ['top-left', 'Superior izquierda', props.radiusTopLeft],
      ['top-right', 'Superior derecha', props.radiusTopRight],
      ['bottom-right', 'Inferior derecha', props.radiusBottomRight],
      ['bottom-left', 'Inferior izquierda', props.radiusBottomLeft]
    ];
    return '<div class="cvstudio-field"><label>Radio de cada esquina (px)</label><div class="cvstudio-corners">'
      + fields.map(function (f) {
        return '<label><span>' + f[1] + '</span><input type="number" min="0" max="200" step="1" value="' + (f[2] || 0) + '" data-corner="' + f[0] + '"></label>';
      }).join('') + '</div></div>';
  }

  function boxControls(props) {
    return textControls(props)
      + colorField('Relleno', 'fill', { none: true, current: props.fill })
      + radiusField()
      + cornerFields(props);
  }

  function linkControls(props) {
    var opts = '<option value="">— Elige una página —</option>'
      + LINKS.map(function (l) {
        return '<option value="' + esc(l.url) + '"' + (l.url === props.href ? ' selected' : '') + '>' + esc(l.title) + '</option>';
      }).join('');
    var styleControls = props.isButton
      ? colorField('Relleno', 'fill', { none: true, current: props.fill }) + colorField('Color del texto', 'color', { current: props.color }) + radiusField() + sizeField()
      : colorField('Color', 'color', { current: props.color }) + sizeField();
    return ''
      + '<div class="cvstudio-field"><label>Texto</label>'
        + '<input type="text" id="ep-text" value="' + esc(props.text || '') + '"></div>'
      + '<div class="cvstudio-field"><label>Enlace a una página</label>'
        + '<select id="ep-page">' + opts + '</select></div>'
      + '<div class="cvstudio-field"><label>…o una dirección</label>'
        + '<input type="text" id="ep-url" placeholder="https://…" value="' + esc(props.href || '') + '"></div>'
      + '<label class="cvstudio-check"><input type="checkbox" id="ep-newtab"' + (props.newTab ? ' checked' : '') + '> Abrir en una pestaña nueva</label>'
      + '<hr class="cvstudio-sep">'
      + styleControls;
  }

  function imageControls(props) {
    return ''
      + '<div class="cvstudio-field"><label>Texto alternativo (accesibilidad/SEO)</label>'
        + '<input type="text" id="ep-alt" value="' + esc(props.alt || '') + '"></div>'
      + '<button type="button" class="cvstudio-primary-btn" id="ep-replace" style="width:100%">Reemplazar imagen</button>';
  }

  function sectionControls(props) {
    var seg = function (op, val, cur, txt) {
      return '<button type="button" data-op="' + op + '" data-val="' + val + '" class="' + (cur === val ? 'is-on' : '') + '">' + txt + '</button>';
    };
    var bgImageBlock = props.hasBgImage
      ? '<div class="cvstudio-field"><label>Imagen de fondo</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + '<button type="button" id="ep-bg-change">Cambiar</button>'
          + '<button type="button" id="ep-bg-remove">Quitar</button>'
        + '</div></div>'
        + '<div class="cvstudio-field"><label>Atenuar fondo</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + seg('bgdim', 'none', '', 'No') + seg('bgdim', 'soft', '', 'Suave')
          + seg('bgdim', 'medium', '', 'Medio') + seg('bgdim', 'strong', '', 'Mucho')
        + '</div></div>'
      : '<div class="cvstudio-field"><label>Imagen de fondo</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + '<button type="button" id="ep-bg-add">Poner imagen de fondo</button>'
        + '</div></div>';
    // Galería/carrusel: disposición y fotos. Solo aparece si la sección lleva uno.
    var galleryBlock = props.slider
      ? '<div class="cvstudio-field"><label>Galería · cómo se ven las fotos</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + seg('sliderlayout', 'strip', props.slider, 'En fila')
          + seg('sliderlayout', 'single', props.slider, 'Una a una')
          + seg('sliderlayout', 'vertical', props.slider, 'En vertical')
        + '</div></div>'
        + '<div class="cvstudio-field"><label>Fotos de la galería</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + '<button type="button" id="ep-gallery-pick">Elegir fotos' + (props.sliderPhotos ? ' (' + props.sliderPhotos + ')' : '') + '</button>'
        + '</div><small class="cvstudio-hint">Elige varias de tu biblioteca y sustituirán a las actuales. Para cambiar solo una, haz clic sobre ella.</small></div>'
      : '';

    return ''
      + colorField('Color de fondo', 'bgcolor', { current: props.bgcolor })
      + galleryBlock
      + bgImageBlock
      + '<div class="cvstudio-field"><label>Espaciado vertical</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
        + seg('pad', 'compact', props.pad, 'Compacto') + seg('pad', 'normal', props.pad, 'Normal')
        + seg('pad', 'roomy', props.pad, 'Amplio') + seg('pad', 'default', props.pad, 'Auto')
      + '</div></div>'
      + '<label class="cvstudio-check"><input type="checkbox" id="ep-reveal"' + (props.reveal ? ' checked' : '') + '> Aparecer suavemente al bajar</label>';
  }

  // Migas de ámbito: Página ▸ Sección ▸ Bloque ▸ elemento. Sin ellas, cuando la
  // IA envuelve el contenido en una caja (un velo blanco sobre la foto de
  // fondo, por ejemplo) el clic cae en esa caja y los controles de la sección
  // —los únicos que cambian la imagen de fondo— se vuelven inalcanzables.
  var CRUMB_LABELS = { text: 'Texto', box: 'Bloque', link: 'Botón', image: 'Imagen', section: 'Sección' };
  var panelState = { chain: [], index: -1, sectionLabel: '' };

  function renderCrumbs(chain, active, sectionLabel) {
    var parts = ['<button type="button" class="cvstudio-crumb" data-scope="-1" title="Quitar la selección: el cambio afectará a toda la página">Página</button>'];
    chain.forEach(function (c, i) {
      var label = c.kind === 'section' ? (sectionLabel || 'Sección') : (CRUMB_LABELS[c.kind] || 'Elemento');
      parts.push(i === active
        ? '<strong class="cvstudio-crumb is-active">' + esc(label) + '</strong>'
        : '<button type="button" class="cvstudio-crumb" data-scope="' + i + '">' + esc(label) + '</button>');
    });
    return '<nav class="cvstudio-crumbs" aria-label="Ámbito de edición">'
      + parts.join('<i aria-hidden="true">›</i>') + '</nav>';
  }

  function openPanel(d) {
    var p = d.props || {};
    var titles = { text: 'Texto', box: 'Bloque', link: 'Botón / enlace', image: 'Imagen', section: 'Sección' };
    var bodyHtml = d.kind === 'text' ? textControls(p)
      : d.kind === 'box' ? boxControls(p)
      : d.kind === 'link' ? linkControls(p)
      : d.kind === 'image' ? imageControls(p)
      : sectionControls(p);

    var chain = Array.isArray(d.chain) ? d.chain : [];
    panelState = { chain: chain, index: typeof d.chainIndex === 'number' ? d.chainIndex : -1, sectionLabel: d.sectionLabel || '' };

    panel.innerHTML = ''
      + '<div class="cvstudio-panel__head">'
        + (chain.length
            ? renderCrumbs(chain, panelState.index, d.sectionLabel)
            : '<strong>' + esc(titles[d.kind] || 'Elemento') + '</strong><small>' + esc(d.sectionLabel || '') + '</small>')
        + '<button type="button" id="ep-close" title="Cerrar">✕</button>'
      + '</div>'
      + '<div class="cvstudio-panel__body">' + bodyHtml + '</div>'
      + '<p class="pp-chat-hint">¿Algo más complejo? Pídemelo en la conversación de abajo a la derecha.</p>';
    showSide('panel');
    wirePanel(d.kind);
  }

  // Sube un nivel de ámbito (Esc). Desde la sección, cierra el panel.
  function climbScope() {
    if (panel.hidden) return false;
    if (panelState.index > 0) { selectScope(panelState.index - 1); return true; }
    closePanel();
    clearSelection(true);
    return true;
  }

  function selectScope(index) {
    if (index < 0) { closePanel(); clearSelection(true); return; }
    if (iframe.contentWindow) {
      iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'select-scope', index: index }, '*');
    }
  }

  // Operaciones segmentadas (toggle visual de "activo" entre hermanas).
  var SEGMENTED = { pad: 1, bgdim: 1, radius: 1, sliderlayout: 1 };

  function wirePanel(kind) {
    panel.querySelectorAll('[data-op]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyOp(btn.dataset.op, btn.dataset.val);
        if (SEGMENTED[btn.dataset.op]) {
          var sibs = btn.parentNode.querySelectorAll('[data-op="' + btn.dataset.op + '"]');
          sibs.forEach(function (b) { b.classList.remove('is-on'); });
          btn.classList.add('is-on');
        }
        showSaved('Guardado');
      });
    });
    panel.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var on = !btn.classList.contains('is-on');
        btn.classList.toggle('is-on', on);
        applyOp(btn.dataset.toggle, on);
        showSaved('Guardado');
      });
    });
    panel.querySelectorAll('[data-corner]').forEach(function (field) {
      field.addEventListener('change', function () {
        applyOp('corner-radius', { corner: field.dataset.corner, px: field.value });
        showSaved('Guardado');
      });
    });
    // Marca activo el control de color elegido (swatch o picker) dentro de su grupo.
    function markColor(op, activeEl) {
      panel.querySelectorAll('.cvstudio-swatch[data-cop="' + op + '"]').forEach(function (b) { b.classList.remove('is-on'); });
      var pickerLabel = panel.querySelector('[data-cinput="' + op + '"]');
      if (pickerLabel && pickerLabel.parentNode) pickerLabel.parentNode.classList.remove('is-on');
      if (activeEl) activeEl.classList.add('is-on');
    }
    // Swatches de color/relleno/fondo (paleta, "sin relleno", reset).
    panel.querySelectorAll('[data-cop]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyOp(btn.dataset.cop, btn.dataset.cval);
        if (btn.dataset.cval !== 'reset') markColor(btn.dataset.cop, btn); else markColor(btn.dataset.cop, null);
        showSaved('Guardado');
      });
    });
    // Picker de color libre.
    panel.querySelectorAll('[data-cinput]').forEach(function (inp) {
      var lbl = inp.parentNode;
      inp.addEventListener('input', function () { applyOp(inp.dataset.cinput, inp.value, true); lbl.style.background = inp.value; }); // preview
      inp.addEventListener('change', function () { applyOp(inp.dataset.cinput, inp.value); markColor(inp.dataset.cinput, lbl); lbl.style.background = inp.value; showSaved('Guardado'); });
    });

    panel.querySelectorAll('[data-scope]').forEach(function (btn) {
      btn.addEventListener('click', function () { selectScope(parseInt(btn.dataset.scope, 10)); });
    });

    var close = panel.querySelector('#ep-close');
    if (close) close.addEventListener('click', function () { closePanel(); clearSelection(true); });

    if (kind === 'link') {
      var pageSel = panel.querySelector('#ep-page');
      var urlIn = panel.querySelector('#ep-url');
      var textIn = panel.querySelector('#ep-text');
      var newtab = panel.querySelector('#ep-newtab');
      if (pageSel) pageSel.addEventListener('change', function () {
        if (pageSel.value) { urlIn.value = pageSel.value; applyOp('link', pageSel.value); showSaved('Guardado'); }
      });
      if (urlIn) urlIn.addEventListener('change', function () { applyOp('link', urlIn.value.trim()); showSaved('Guardado'); });
      if (textIn) textIn.addEventListener('change', function () { applyOp('settext', textIn.value); showSaved('Guardado'); });
      if (newtab) newtab.addEventListener('change', function () { applyOp('newtab', newtab.checked); showSaved('Guardado'); });
    }
    if (kind === 'image') {
      var altIn = panel.querySelector('#ep-alt');
      var repl = panel.querySelector('#ep-replace');
      if (altIn) altIn.addEventListener('change', function () { applyOp('alt', altIn.value); showSaved('Guardado'); });
      if (repl) repl.addEventListener('click', function () { openMediaModal(); });
    }
    if (kind === 'section') {
      var reveal = panel.querySelector('#ep-reveal');
      if (reveal) reveal.addEventListener('change', function () { applyOp('reveal', reveal.checked); showSaved('Guardado'); });
      var bgChange = panel.querySelector('#ep-bg-change');
      var bgRemove = panel.querySelector('#ep-bg-remove');
      var bgAdd = panel.querySelector('#ep-bg-add');
      // "Cambiar"/"Poner" marca el destino del fondo y abre la biblioteca (replace-image guarda).
      if (bgChange) bgChange.addEventListener('click', function () { applyOp('bgimg', 'mark'); openMediaModal(); });
      if (bgAdd) bgAdd.addEventListener('click', function () { applyOp('bgimg', 'mark'); openMediaModal(); });
      if (bgRemove) bgRemove.addEventListener('click', function () { applyOp('bgimg', 'remove'); showSaved('Guardado'); });
      var galleryPick = panel.querySelector('#ep-gallery-pick');
      if (galleryPick) galleryPick.addEventListener('click', function () { openMediaModal(true); });
    }
  }

  function clearSelection(notifyIframe) {
    selectedSection = null;
    selectedElementContext = '';
    ctxBox.hidden = true;
    input.placeholder = 'Ej.: pon el titular más grande y el botón en otro color';
    if (notifyIframe !== false && iframe.contentWindow) {
      iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'deselect' }, '*');
    }
    markCurrentSection();
    refreshPill();
  }
  ctxClear.addEventListener('click', function () { clearSelection(true); });

  // ----------------------------------------------------------------
  // Enviar petición
  // ----------------------------------------------------------------
  // CANCEL — "Parar" no basta con abortar el fetch: el servidor seguiría
  // trabajando y guardaría el cambio. Se manda también un aviso con el mismo
  // request_id y el pipeline lo comprueba antes de tocar la página.
  var cancelBtn = document.getElementById('chat-cancel');
  var currentRequest = null;   // AbortController de la generación en curso
  var currentRequestId = '';

  // STUDIO-2 B4 — Espera honesta: segundos transcurridos y, cuando se alarga,
  // por qué. Antes eran tres puntitos durante 40 s sin decir nada.
  function startWaitTimer(msgEl, scoped) {
    var t0 = Date.now();
    var slot = msgEl.querySelector('[data-wait]');
    var iv = setInterval(function () {
      // Si el mensaje ya no está en pantalla (se paró o terminó), fuera.
      if (!slot || !msgEl.isConnected) { clearInterval(iv); return; }
      var s = Math.round((Date.now() - t0) / 1000);
      var note = '';
      if (s >= 45) note = scoped ? ' · está tardando; puedes pulsar «Parar»' : ' · una página entera tarda más; puedes pulsar «Parar»';
      else if (s >= 15) note = scoped ? ' · suele tardar unos segundos' : ' · cambiar la página entera tarda algo más';
      slot.textContent = 'Aplicando el cambio… ' + s + ' s' + note;
    }, 1000);
    return iv;
  }

  function newRequestId() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID().replace(/-/g, '');
    return 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    if (cancelBtn) cancelBtn.hidden = !on;
    refreshPill();   // plegado, la pastilla dice si se está aplicando algo
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (text === '' || busy) return;

    setBusy(true);
    input.value = '';

    var scopeNote = selectedSection
      ? ' <span class="pp-chat-scope">en “' + esc(ctxLabel.textContent) + '”</span>'
      : '';
    addMsg('user', esc(text) + scopeNote);
    var thinking = addMsg('assistant', '<span class="pp-chat-dots"><i></i><i></i><i></i></span> <span data-wait>Aplicando el cambio…</span>');
    var waitTimer = startWaitTimer(thinking, !!selectedSection);

    var scopeLabel = selectedSection ? (ctxLabel.textContent || '') : '';

    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('instruction', text);
    if (selectedSection) fd.append('section', selectedSection);
    if (selectedElementContext) fd.append('element_context', selectedElementContext);
    if (selectedElementPath) fd.append('element_path', selectedElementPath);
    if (chatHistory.length) fd.append('history', JSON.stringify(chatHistory.slice(-4)));
    if (modelSelect && modelSelect.value) fd.append('model', modelSelect.value);

    currentRequestId = newRequestId();
    currentRequest = ('AbortController' in window) ? new AbortController() : null;
    fd.append('request_id', currentRequestId);

    fetch(body.dataset.chatUrl, {
      method: 'POST',
      body: fd,
      signal: currentRequest ? currentRequest.signal : undefined
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        clearInterval(waitTimer);
        thinking.remove();
        if (!data.ok) {
          addMsg('assistant pp-chat-msg--error',
            esc(data.error || 'Algo no ha ido bien. Vuelve a intentarlo en un momento.')
            + ' <button type="button" class="pp-chat-retry">Reintentar</button>');
          var retry = messages.querySelector('.pp-chat-retry:last-of-type');
          if (retry) retry.addEventListener('click', function () {
            input.value = text;
            this.closest('.pp-chat-msg').remove();
            form.dispatchEvent(new Event('submit'));
          });
          return;
        }
        addMsg('assistant', esc(data.reply || 'Hecho.')
          + ' <button type="button" class="pp-chat-undo">Deshacer</button>');
        bindUndo();
        applyHistory(data.history);
        // B1 — el turno entra en la memoria de la conversación.
        chatHistory.push({ q: text, a: String(data.reply || ''), scope: scopeLabel });
        if (chatHistory.length > 8) chatHistory = chatHistory.slice(-8);
        // B3 — al recargar, señalar la parte tocada.
        pendingFlash = String(data.changed_section || '');
        reloadPreview();
      })
      .catch(function (err) {
        clearInterval(waitTimer);
        thinking.remove();
        if (err && err.name === 'AbortError') return; // ya lo hemos contado al parar
        addMsg('assistant pp-chat-msg--error', 'No hay conexión ahora mismo. Tu página no ha cambiado.');
      })
      .finally(function () {
        currentRequest = null;
        currentRequestId = '';
        setBusy(false);
        input.focus();
      });
  });

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      if (!busy || !currentRequestId) return;
      var id = currentRequestId;
      cancelBtn.disabled = true;

      // 1) Avisar al servidor ANTES de abortar: es lo que impide que se guarde.
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('request_id', id);
      fetch(body.dataset.cancelUrl, { method: 'POST', body: fd, keepalive: true })
        .catch(function () { /* si falla, el abort de abajo al menos libera la UI */ })
        .finally(function () {
          // 2) Y cortar la espera en el navegador.
          if (currentRequest) currentRequest.abort();
          cancelBtn.disabled = false;
          var t = messages.querySelector('.pp-chat-msg:last-child');
          if (t && t.textContent.indexOf('Aplicando el cambio') >= 0) t.remove();
          addMsg('assistant', 'Cambio cancelado. Tu página no se ha tocado.');
          setBusy(false);
        });
    });
  }

  // Enter envía, Shift+Enter hace salto de línea.
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.dispatchEvent(new Event('submit'));
    }
  });

  function bindUndo() {
    messages.querySelectorAll('.pp-chat-undo:not([data-bound])').forEach(function (btn) {
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        if (busy) return;
        doUndo(btn);
      });
    });
  }

  // ----------------------------------------------------------------
  // FH6 — Deshacer / Rehacer con puntero de versión
  // ----------------------------------------------------------------
  var undoBtn = document.getElementById('studio-undo-btn');
  var redoBtn = document.getElementById('studio-redo-btn');

  function applyHistory(state) {
    if (!state) return;
    undoBtn.disabled = !state.can_undo;
    redoBtn.disabled = !state.can_redo;
  }

  function historyStep(url, label, btn) {
    if (busy) return;
    busy = true;
    if (btn) btn.disabled = true;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fetch(url, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (data.ok) {
          applyHistory(data.history);
          reloadPreview();
        } else if (data.error) {
          showSaved(data.error, true);
        }
      })
      .catch(function () { showSaved('Sin conexión', true); })
      .finally(function () { setBusy(false); });
  }

  function doUndo(srcBtn) { historyStep(body.dataset.undoUrl, 'deshacer', srcBtn || undoBtn); }
  function doRedo() { historyStep(body.dataset.redoUrl, 'rehacer', redoBtn); }

  undoBtn.addEventListener('click', function () { if (!undoBtn.disabled) doUndo(undoBtn); });
  redoBtn.addEventListener('click', function () { if (!redoBtn.disabled) doRedo(); });

  // Estado inicial de los botones (lo pinta el servidor en data-can-*).
  applyHistory({ can_undo: body.dataset.canUndo === '1', can_redo: body.dataset.canRedo === '1' });

  // Esc sube un nivel de ámbito (elemento → bloque → sección → página).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || panel.hidden) return;
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName || '')) return;
    if (climbScope()) e.preventDefault();
  });

  // Atajos de teclado (cuando el foco NO está editando texto en el iframe).
  document.addEventListener('keydown', function (e) {
    var mod = e.metaKey || e.ctrlKey;
    if (!mod || e.key.toLowerCase() !== 'z') return;
    e.preventDefault();
    if (e.shiftKey) { if (!redoBtn.disabled) doRedo(); }
    else { if (!undoBtn.disabled) doUndo(undoBtn); }
  });

  // Restaura una versión concreta (desde el modal de historial). Mueve el
  // puntero (reversible con deshacer/rehacer hasta el siguiente cambio).
  function restoreVersion(versionId, btn) {
    busy = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Recuperando…'; }
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('version_id', versionId);
    fetch(body.dataset.restoreUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) { applyHistory(data.history); reloadPreview(); showSaved('Versión recuperada'); }
        else { showSaved(data.error || 'No se pudo recuperar', true); }
      })
      .catch(function () { showSaved('No se pudo recuperar ahora mismo', true); })
      .finally(function () { setBusy(false); });
  }

  function reloadPreview() {
    try { lastScrollY = iframe.contentWindow.scrollY || 0; } catch (e) { lastScrollY = 0; }
    iframe.src = body.dataset.previewUrl + '?t=' + Date.now();
  }

  // ----------------------------------------------------------------
  // FH4 — Edición directa: guardado de sección (sin IA, sin recarga)
  // ----------------------------------------------------------------
  var savedPill = document.getElementById('studio-saved');
  var savedTimer = null;
  function showSaved(text, isError) {
    savedPill.textContent = text;
    savedPill.classList.toggle('is-error', !!isError);
    savedPill.hidden = false;
    clearTimeout(savedTimer);
    savedTimer = setTimeout(function () { savedPill.hidden = true; }, isError ? 5000 : 2200);
  }

  function saveSectionInline(sectionId, html) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('section', sectionId);
    fd.append('html', html);
    fetch(body.dataset.sectionUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          showSaved('Guardado');
          applyHistory(data.history);
        } else {
          showSaved('No se pudo guardar', true);
          reloadPreview(); // volver al estado real persistido
        }
      })
      .catch(function () { showSaved('Sin conexión, no guardado', true); reloadPreview(); });
  }

  // ----------------------------------------------------------------
  // FH4 — Selector de imágenes de la biblioteca
  // ----------------------------------------------------------------
  var mediaModal = document.getElementById('media-modal');
  var mediaGrid = document.getElementById('media-grid');
  var mediaHint = document.getElementById('media-hint');
  var mediaSearchForm = document.getElementById('media-search-form');
  var mediaSearchInput = document.getElementById('media-search-input');
  var mediaUploadInput = document.getElementById('media-upload-input');
  var mediaTabs = mediaModal.querySelectorAll('[data-media-tab]');
  var mediaCache = null;

  // Ruta servible relativa: la biblioteca da `path`; subida/Unsplash dan `url`.
  function mediaPath(it) {
    if (it.path) return it.path;
    try { return new URL(it.url, location.href).pathname; } catch (e) { return it.url || ''; }
  }
  // Aplica la imagen elegida al destino marcado (imagen de contenido o fondo).
  // Selección múltiple: activa al elegir las fotos de una galería completa.
  var galleryMode = false;
  var gallerySelection = [];

  function useMedia(it) {
    if (galleryMode) { toggleGalleryPick(it); return; }
    mediaModal.hidden = true;
    iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'replace-image', src: mediaPath(it), alt: it.alt_text || '' }, '*');
  }

  function toggleGalleryPick(it) {
    var src = mediaPath(it);
    var i = gallerySelection.findIndex(function (p) { return p.src === src; });
    if (i >= 0) gallerySelection.splice(i, 1);
    else gallerySelection.push({ src: src, alt: it.alt_text || '' });
    syncGalleryPicks();
  }

  function syncGalleryPicks() {
    var bar = document.getElementById('cvstudio-gallery-bar');
    mediaGrid.querySelectorAll('.cvstudio-media-item').forEach(function (btn) {
      var pos = gallerySelection.findIndex(function (p) { return p.src === btn.dataset.mediaSrc; });
      btn.classList.toggle('is-picked', pos >= 0);
      var badge = btn.querySelector('.cvstudio-media-item__num');
      if (pos >= 0) {
        if (!badge) {
          badge = document.createElement('i');
          badge.className = 'cvstudio-media-item__num';
          btn.appendChild(badge);
        }
        badge.textContent = String(pos + 1);
      } else if (badge) {
        badge.remove();
      }
    });
    if (bar) {
      var n = gallerySelection.length;
      bar.querySelector('button').disabled = n === 0;
      bar.querySelector('button').textContent = n === 0 ? 'Elige al menos una foto' : 'Usar ' + n + (n === 1 ? ' foto' : ' fotos');
    }
  }

  function applyGallerySelection() {
    if (!gallerySelection.length) return;
    iframe.contentWindow.postMessage({
      source: 'pp-studio-parent', type: 'apply', op: 'gallery', value: gallerySelection.slice()
    }, '*');
    showSaved('Galería actualizada');
    closeGalleryMode();
    mediaModal.hidden = true;
  }

  function closeGalleryMode() {
    galleryMode = false;
    gallerySelection = [];
    var bar = document.getElementById('cvstudio-gallery-bar');
    if (bar) bar.remove();
  }

  function setMediaTab(tab) {
    mediaTabs.forEach(function (b) { b.classList.toggle('is-active', b.dataset.mediaTab === tab); });
    mediaSearchForm.hidden = tab !== 'unsplash';
    var filterBar = document.getElementById('media-filter');
    if (filterBar) filterBar.hidden = tab !== 'library';
    if (tab === 'unsplash') {
      mediaHint.textContent = 'Busca una foto en Unsplash; al elegirla se añade a tu biblioteca.';
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Escribe qué foto necesitas y pulsa «Buscar».</p>';
      if (mediaSearchInput) mediaSearchInput.focus();
    } else {
      mediaHint.textContent = galleryMode
        ? 'Elige las fotos de la galería: se colocarán en el orden en que las toques.'
        : 'Imágenes de tu biblioteca. La nueva imagen sustituirá a la que has tocado.';
      loadLibrary();
    }
  }

  function openMediaModal(forGallery) {
    galleryMode = !!forGallery;
    gallerySelection = [];
    mediaFilter = 'own';   // cada apertura vuelve a la prioridad: tus fotos
    mediaModal.hidden = false;
    if (galleryMode && !document.getElementById('cvstudio-gallery-bar')) {
      var bar = document.createElement('div');
      bar.id = 'cvstudio-gallery-bar';
      bar.className = 'cvstudio-gallery-bar';
      bar.innerHTML = '<span>Toca las fotos en el orden que quieras verlas.</span><button type="button" disabled>Elige al menos una foto</button>';
      bar.querySelector('button').addEventListener('click', applyGallerySelection);
      mediaGrid.parentNode.insertBefore(bar, mediaGrid.nextSibling);
    }
    if (!galleryMode) closeGalleryMode();
    setMediaTab('library');
  }

  // STUDIO-2 C5 — filtro "Tus fotos / De banco". Por defecto, tus fotos: son
  // la prioridad del producto. Sin fotos propias, se muestran todas.
  var mediaFilter = 'own';

  function loadLibrary() {
    if (mediaCache) { renderLibrary(mediaCache); return; }
    mediaGrid.innerHTML = '<p class="pp-chat-hint">Cargando tu biblioteca…</p>';
    fetch(body.dataset.mediaUrl)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        mediaCache = (data.items || []).filter(function (it) { return (it.mime_type || '').indexOf('image/') === 0; });
        renderLibrary(mediaCache);
      })
      .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">No se pudo cargar la biblioteca.</p>'; });
  }

  function isOwn(it) { return (it.source || 'upload') === 'upload'; }

  function renderFilterChips(hasOwn, hasBank) {
    var bar = document.getElementById('media-filter');
    if (!hasOwn || !hasBank) { if (bar) bar.remove(); return; }   // un solo origen: sin filtro
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'media-filter';
      bar.className = 'cvstudio-media-filter';
      mediaGrid.parentNode.insertBefore(bar, mediaGrid);
    }
    bar.innerHTML = '';
    [['own', 'Tus fotos'], ['bank', 'De banco'], ['all', 'Todas']].forEach(function (f) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = f[1];
      b.className = mediaFilter === f[0] ? 'is-active' : '';
      b.addEventListener('click', function () { mediaFilter = f[0]; renderLibrary(mediaCache || []); });
      bar.appendChild(b);
    });
  }

  function renderLibrary(items) {
    if (!items.length) {
      renderFilterChips(false, false);
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Tu biblioteca está vacía. Sube una imagen o busca en Unsplash.</p>';
      return;
    }
    var hasOwn = items.some(isOwn);
    var hasBank = items.some(function (it) { return !isOwn(it); });
    if (mediaFilter === 'own' && !hasOwn) mediaFilter = 'all';
    renderFilterChips(hasOwn, hasBank);

    var visible = items.filter(function (it) {
      if (mediaFilter === 'own') return isOwn(it);
      if (mediaFilter === 'bank') return !isOwn(it);
      return true;
    });
    mediaGrid.innerHTML = '';
    visible.forEach(function (it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cvstudio-media-item';
      btn.dataset.mediaSrc = mediaPath(it);
      var badge = isOwn(it) && hasBank && mediaFilter !== 'own' ? '<i class="cvstudio-media-item__own">Tu foto</i>' : '';
      btn.innerHTML = badge + '<img src="' + it.url + '" alt="" loading="lazy"><span>' + esc(it.alt_text || it.name || '') + '</span>';
      btn.addEventListener('click', function () { useMedia(it); });
      mediaGrid.appendChild(btn);
    });
    if (galleryMode) syncGalleryPicks();
  }

  // ---- Subir desde el equipo ----
  if (mediaUploadInput) {
    mediaUploadInput.addEventListener('change', function () {
      var file = mediaUploadInput.files && mediaUploadInput.files[0];
      if (!file) return;
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Subiendo «' + esc(file.name) + '»…</p>';
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('file', file);
      fetch(body.dataset.mediaUploadUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (data) {
          if (!data.ok || !data.item) { mediaGrid.innerHTML = '<p class="pp-chat-hint">' + esc(data.error || 'No se pudo subir la imagen.') + '</p>'; return; }
          mediaCache = null; // la biblioteca cambió
          useMedia(data.item);
        })
        .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">No se pudo subir la imagen.</p>'; })
        .finally(function () { mediaUploadInput.value = ''; });
    });
  }

  // ---- Buscar en Unsplash ----
  if (mediaSearchForm) {
    mediaSearchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = (mediaSearchInput.value || '').trim();
      if (!q) return;
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Buscando «' + esc(q) + '» en Unsplash…</p>';
      fetch(body.dataset.bankSearchUrl + '?q=' + encodeURIComponent(q) + '&orientation=landscape')
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (data) {
          if (!data.ok) { mediaGrid.innerHTML = '<p class="pp-chat-hint">' + esc(data.error || 'No se pudo buscar ahora mismo.') + '</p>'; return; }
          renderUnsplash(data.items || [], q);
        })
        .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">No se pudo buscar ahora mismo.</p>'; });
    });
  }

  function renderUnsplash(items, query) {
    if (!items.length) {
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Sin resultados para «' + esc(query) + '». Prueba otras palabras.</p>';
      return;
    }
    mediaGrid.innerHTML = '';
    items.forEach(function (it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cvstudio-media-item';
      var credit = it.photographer ? 'Foto: ' + it.photographer : (it.description || it.alt || '');
      btn.innerHTML = '<img src="' + it.thumb + '" alt="" loading="lazy"><span>' + esc(credit) + '</span>';
      btn.addEventListener('click', function () { importUnsplash(it, query, btn); });
      mediaGrid.appendChild(btn);
    });
  }

  function importUnsplash(it, query, btn) {
    if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('result_id', it.id);
    fd.append('query', query);
    fd.append('orientation', 'landscape');
    fd.append('alt', it.alt || it.description || '');
    fetch(body.dataset.bankImportUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok || !data.media) { showSaved(data.error || 'No se pudo añadir la imagen', true); if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); } return; }
        mediaCache = null;
        useMedia(data.media);
      })
      .catch(function () { showSaved('No se pudo añadir la imagen', true); if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); } });
  }

  mediaTabs.forEach(function (b) { b.addEventListener('click', function () { setMediaTab(b.dataset.mediaTab); }); });
  document.getElementById('media-close').addEventListener('click', function () { mediaModal.hidden = true; });
  mediaModal.addEventListener('click', function (e) { if (e.target === mediaModal) mediaModal.hidden = true; });

  // ----------------------------------------------------------------
  // Viewport desktop / móvil
  // ----------------------------------------------------------------
  document.querySelectorAll('.cvstudio-viewport button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.cvstudio-viewport button').forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      frameWrap.classList.toggle('is-mobile', btn.dataset.vp === 'mobile');
    });
  });

  // ----------------------------------------------------------------
  // Historial
  // ----------------------------------------------------------------
  var modal = document.getElementById('history-modal');
  var historyList = document.getElementById('history-list');
  document.getElementById('studio-history-btn').addEventListener('click', function () {
    modal.hidden = false;
    historyList.innerHTML = '<li class="pp-chat-hint">Cargando…</li>';
    fetch(body.dataset.versionsUrl)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok || !data.versions.length) {
          historyList.innerHTML = '<li class="pp-chat-hint">Todavía no hay versiones guardadas.</li>';
          return;
        }
        applyHistory(data.history);
        historyList.innerHTML = '';
        data.versions.forEach(function (v) {
          var li = document.createElement('li');
          if (v.is_current) li.className = 'is-current';
          li.innerHTML = '<div>'
              + '<strong>' + esc(v.label) + '</strong>'
              + '<span><i class="cvstudio-vkind">' + esc(v.kind) + '</i> · ' + esc(relTime(v.created_at)) + '</span>'
            + '</div>'
            + (v.is_current
              ? '<em>Aquí estás</em>'
              : '<button type="button" class="cvstudio-ghost-btn" data-version="' + v.id + '">Ver esta versión</button>');
          historyList.appendChild(li);
        });
        historyList.querySelectorAll('button[data-version]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            modal.hidden = true;
            restoreVersion(btn.dataset.version, null);
          });
        });
      });
  });
  document.getElementById('history-close').addEventListener('click', function () { modal.hidden = true; });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });

  // ----------------------------------------------------------------
  // Publicar / despublicar
  // ----------------------------------------------------------------
  var publishBtn = document.getElementById('studio-publish-btn');
  var statusEl = document.getElementById('studio-status');
  var moreWrap = document.getElementById('studio-more');
  var moreBtn = document.getElementById('studio-more-btn');
  var moreMenu = document.getElementById('studio-more-menu');
  var unpublishBtn = document.getElementById('studio-unpublish-btn');

  // Refleja el estado publicado/borrador en toda la barra (sin recargar).
  function reflectPublished(publishing) {
    body.dataset.published = publishing ? '1' : '0';
    // Borrador → botón primario "Publicar"; Publicada → menú discreto "⋯".
    publishBtn.hidden = publishing;
    moreWrap.hidden = !publishing;
    if (!publishing) closeMoreMenu();
    statusEl.textContent = publishing ? 'Publicada' : 'Borrador';
    statusEl.classList.toggle('is-live', publishing);
    // "Ver página": URL pública si está publicada; preview limpio si es borrador.
    var vlink = document.getElementById('studio-view-link');
    if (vlink) {
      vlink.href = publishing ? body.dataset.publicUrl : body.dataset.cleanPreviewUrl;
      var vt = publishing ? 'Ver página en el sitio' : 'Previsualizar borrador';
      vlink.title = vt; vlink.setAttribute('aria-label', vt);
    }
  }

  function setPublished(publishing, triggerBtn) {
    if (triggerBtn) triggerBtn.disabled = true;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('publish', publishing ? '1' : '0');
    fetch(body.dataset.publishUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) return;
        reflectPublished(publishing);
        addMsg('assistant', publishing
          ? 'Tu página ya está publicada. <a href="' + body.dataset.publicUrl + '" target="_blank" rel="noopener">Verla en el sitio</a>.'
          : 'La página vuelve a ser un borrador (ya no es visible para tus visitantes).');
      })
      .finally(function () { if (triggerBtn) triggerBtn.disabled = false; });
  }

  function closeMoreMenu() {
    moreMenu.hidden = true;
    moreBtn.setAttribute('aria-expanded', 'false');
  }
  moreBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = moreMenu.hidden;
    moreMenu.hidden = !open;
    moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', function (e) {
    if (!moreMenu.hidden && !moreWrap.contains(e.target)) closeMoreMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !moreMenu.hidden) closeMoreMenu();
  });

  publishBtn.addEventListener('click', function () { setPublished(true, publishBtn); });
  unpublishBtn.addEventListener('click', function () { closeMoreMenu(); setPublished(false, unpublishBtn); });

  // ----------------------------------------------------------------
  // FORMS-R T3 — Insertar existente o crear desde plantilla en el punto activo.
  // ----------------------------------------------------------------
  var insertWrap = document.getElementById('studio-insert-form');
  if (insertWrap) {
    var insertBtn = document.getElementById('studio-insert-btn');
    var insertMenu = document.getElementById('studio-insert-menu');
    function closeInsertMenu() { insertMenu.hidden = true; insertBtn.setAttribute('aria-expanded', 'false'); }
    insertBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = insertMenu.hidden;
      insertMenu.hidden = !open;
      insertBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!insertMenu.hidden && !insertWrap.contains(e.target)) closeInsertMenu();
    });
    function insertFormItem(item) {
      item.addEventListener('click', function () {
        var formId = item.dataset.formId;
        var template = item.dataset.formTemplate;
        closeInsertMenu();
        setDock(true);   // el progreso y el resultado se cuentan en la conversación
        var thinking = addMsg('assistant', '<span class="pp-chat-dots"><i></i><i></i><i></i></span> Insertando el formulario…');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        if (formId) fd.append('form_id', formId);
        if (template) fd.append('template', template);
        if (selectedSection) fd.append('section', selectedSection);
        var source = document.getElementById('studio-form-source');
        if (source && source.value.trim()) fd.append('source_label', source.value.trim());
        fetch(body.dataset.insertFormUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (data) {
            thinking.remove();
            if (!data.ok) { addMsg('assistant pp-chat-msg--error', esc(data.error || 'No se pudo insertar.')); return; }
            addMsg('assistant', esc(data.reply || 'Formulario insertado.'));
            applyHistory(data.history);
            reloadPreview();
            if (source) source.value = '';
          })
          .catch(function () { thinking.remove(); addMsg('assistant pp-chat-msg--error', 'No hay conexión ahora mismo.'); });
      });
    }
    insertMenu.querySelectorAll('button[data-form-id],button[data-form-template]').forEach(insertFormItem);
  }

  // ----------------------------------------------------------------
  // FH8 — Ajustes de la página (SEO): meta título, descripción, slug
  // ----------------------------------------------------------------
  var setModal = document.getElementById('settings-modal');
  if (setModal) {
    var setBtn = document.getElementById('studio-settings-btn');
    var setClose = document.getElementById('settings-close');
    var setSave = document.getElementById('settings-save-btn');
    var setStatus = document.getElementById('settings-status');
    var fTitle = document.getElementById('settings-meta-title');
    var fDesc = document.getElementById('settings-meta-desc');
    var fSlug = document.getElementById('settings-slug'); // ausente en el home
    var fNoindex = document.getElementById('settings-seo-noindex');
    var fExcludeSitemap = document.getElementById('settings-seo-exclude-sitemap');
    var fCanonical = document.getElementById('settings-canonical-url');
    var urlPreview = document.getElementById('settings-url-preview');
    var slugWarn = document.getElementById('settings-slug-warn');
    var slugInitial = fSlug ? fSlug.value.trim() : '';

    function setMsg(text, isError) {
      setStatus.textContent = text;
      setStatus.classList.toggle('is-error', !!isError);
      setStatus.hidden = false;
    }

    // Slugifica igual que el backend (slugify): minúsculas, sin acentos, guiones.
    function slugify(s) {
      return (s || '').toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function refreshCounts() {
      setModal.querySelectorAll('[data-count]').forEach(function (el) {
        var src = document.getElementById(el.getAttribute('data-count'));
        if (src) el.textContent = src.value.length;
      });
    }

    function refreshSlugPreview() {
      if (!fSlug) return;
      var clean = slugify(fSlug.value);
      urlPreview.textContent = (body.dataset.publicBase || '/') + clean;
      var changed = clean !== slugInitial && body.dataset.published === '1';
      slugWarn.hidden = !changed;
    }

    setBtn.addEventListener('click', function () {
      setStatus.hidden = true;
      setModal.hidden = false;
      refreshCounts();
      refreshSlugPreview();
    });
    setClose.addEventListener('click', function () { setModal.hidden = true; });
    setModal.addEventListener('click', function (e) { if (e.target === setModal) setModal.hidden = true; });
    fTitle.addEventListener('input', refreshCounts);
    fDesc.addEventListener('input', refreshCounts);
    if (fSlug) fSlug.addEventListener('input', function () { refreshCounts(); refreshSlugPreview(); });

    // Guardar
    setSave.addEventListener('click', function () {
      setSave.disabled = true;
      setMsg('Guardando…');
      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('meta_title', fTitle.value.trim());
      fd.append('meta_description', fDesc.value.trim());
      if (fSlug) fd.append('slug', fSlug.value.trim());
      if (fNoindex && fNoindex.checked) fd.append('seo_noindex', '1');
      if (fExcludeSitemap && fExcludeSitemap.checked) fd.append('seo_exclude_sitemap', '1');
      if (fCanonical) fd.append('canonical_url', fCanonical.value.trim());
      fetch(body.dataset.settingsUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { setMsg(data.error || 'No se pudo guardar', true); return; }
          if (fSlug) { fSlug.value = data.slug.replace(/^\/+/, ''); slugInitial = fSlug.value; refreshSlugPreview(); }
          // El enlace público y los avisos cambian con el nuevo slug. Solo
          // actualizamos "Ver página" a la URL pública si la página está
          // publicada; si es borrador conserva el preview limpio (la URL
          // pública daría 404).
          body.dataset.publicUrl = data.public_url;
          var viewLink = document.getElementById('studio-view-link');
          if (viewLink && body.dataset.published === '1') viewLink.href = data.public_url;
          setMsg('Ajustes guardados');
          showSaved('Ajustes guardados');
        })
        .catch(function () { setMsg('Sin conexión, no guardado', true); })
        .finally(function () { setSave.disabled = false; });
    });

    // Sugerir con IA por campo — reutiliza el endpoint genérico de acciones.
    // Cada chip pide la propuesta SEO completa pero aplica SOLO su campo, así
    // el usuario puede rehacer únicamente el título, la descripción o la URL.
    var AI_FIELDS = {
      meta_title:       { key: 'seo_title',        target: fTitle, label: 'el título' },
      meta_description: { key: 'meta_description', target: fDesc,  label: 'la descripción' },
      slug:             { key: 'slug',             target: fSlug,  label: 'la dirección' }
    };

    setModal.querySelectorAll('[data-ai-field]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var field = chip.getAttribute('data-ai-field');
        var spec = AI_FIELDS[field];
        if (!spec || !spec.target) return;
        chip.disabled = true;
        chip.classList.add('is-busy');
        setMsg('La IA está sugiriendo ' + spec.label + '…');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('action', 'improve_seo');
        fd.append('input_json', JSON.stringify({
          page_title: body.dataset.pageTitle || '',
          page_type: body.dataset.pageType || '',
          current_slug: fSlug ? fSlug.value.trim() : '',
          current_meta_title: fTitle.value.trim(),
          current_meta_description: fDesc.value.trim(),
          page_content: body.dataset.pageTitle || ''
        }));
        fetch(body.dataset.aiUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (resp) {
            var value = String((resp.data || {})[spec.key] || '').trim();
            if (!value) { setMsg('La IA no devolvió ' + spec.label + '. Inténtalo otra vez.', true); return; }
            spec.target.value = value;
            refreshCounts();
            refreshSlugPreview();
            setMsg('Sugerencia aplicada a ' + spec.label + '. Revisa y pulsa «Guardar ajustes».');
          })
          .catch(function () { setMsg('No se pudo generar la sugerencia ahora mismo.', true); })
          .finally(function () { chip.disabled = false; chip.classList.remove('is-busy'); });
      });
    });
  }
})();
