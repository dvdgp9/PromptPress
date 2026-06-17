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

  var selectedSection = null;
  var busy = false;
  var lastScrollY = 0;

  // ----------------------------------------------------------------
  // Mensajes del chat
  // ----------------------------------------------------------------
  function addMsg(kind, html) {
    var div = document.createElement('div');
    div.className = 'pp-chat-msg pp-chat-msg--' + kind;
    div.innerHTML = html;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
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
      // Si el usuario está editando texto EN la página, el foco es suyo:
      // robárselo aquí era el bug que impedía escribir inline.
      if (!d.editing) input.focus();
    }
    if (d.type === 'section-deselected') { clearSelection(false); closePanel(); }
    if (d.type === 'section-changed') saveSectionInline(d.id, d.html);
    if (d.type === 'image-clicked') openMediaModal();
    if (d.type === 'element-selected') openPanel(d);
    if (d.type === 'element-deselected') closePanel();
    if (d.type === 'ready') {
      if (d.palette) brandPalette = d.palette;
      if (lastScrollY > 0) {
        iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'scroll-to', y: lastScrollY }, '*');
        if (selectedSection) {
          iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'select', id: selectedSection }, '*');
        }
        lastScrollY = 0;
      }
    }
  });

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

  function closePanel() { panel.hidden = true; panel.innerHTML = ''; }

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
        + '<div class="cvstudio-field"><label>Oscurecer fondo</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + seg('bgdim', 'none', '', 'No') + seg('bgdim', 'soft', '', 'Suave')
          + seg('bgdim', 'medium', '', 'Medio') + seg('bgdim', 'strong', '', 'Mucho')
        + '</div></div>'
      : '';
    return ''
      + colorField('Color de fondo', 'bgcolor', { current: props.bgcolor })
      + bgImageBlock
      + '<div class="cvstudio-field"><label>Espaciado vertical</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
        + seg('pad', 'compact', props.pad, 'Compacto') + seg('pad', 'normal', props.pad, 'Normal')
        + seg('pad', 'roomy', props.pad, 'Amplio') + seg('pad', 'default', props.pad, 'Auto')
      + '</div></div>'
      + '<label class="cvstudio-check"><input type="checkbox" id="ep-reveal"' + (props.reveal ? ' checked' : '') + '> Aparecer suavemente al bajar</label>';
  }

  function openPanel(d) {
    var p = d.props || {};
    var titles = { text: 'Texto', link: 'Botón / enlace', image: 'Imagen', section: 'Sección' };
    var bodyHtml = d.kind === 'text' ? textControls(p)
      : d.kind === 'link' ? linkControls(p)
      : d.kind === 'image' ? imageControls(p)
      : sectionControls(p);

    panel.innerHTML = ''
      + '<div class="cvstudio-panel__head">'
        + '<strong>' + esc(titles[d.kind] || 'Elemento') + '</strong>'
        + '<small>' + esc(d.sectionLabel || '') + '</small>'
        + '<button type="button" id="ep-close" title="Cerrar">✕</button>'
      + '</div>'
      + '<div class="cvstudio-panel__body">' + bodyHtml + '</div>'
      + '<p class="pp-chat-hint">¿Algo más complejo? Descríbelo en el chat de abajo.</p>';
    panel.hidden = false;
    wirePanel(d.kind);
  }

  // Operaciones segmentadas (toggle visual de "activo" entre hermanas).
  var SEGMENTED = { pad: 1, bgdim: 1, radius: 1 };

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
      // "Cambiar" marca la imagen de fondo y abre la biblioteca (replace-image guarda).
      if (bgChange) bgChange.addEventListener('click', function () { applyOp('bgimg', 'mark'); openMediaModal(); });
      if (bgRemove) bgRemove.addEventListener('click', function () { applyOp('bgimg', 'remove'); showSaved('Guardado'); });
    }
  }

  function clearSelection(notifyIframe) {
    selectedSection = null;
    ctxBox.hidden = true;
    input.placeholder = 'Ej.: pon el titular más grande y el botón en otro color';
    if (notifyIframe !== false && iframe.contentWindow) {
      iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'deselect' }, '*');
    }
  }
  ctxClear.addEventListener('click', function () { clearSelection(true); });

  // ----------------------------------------------------------------
  // Enviar petición
  // ----------------------------------------------------------------
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (text === '' || busy) return;

    busy = true;
    sendBtn.disabled = true;
    input.value = '';

    var scopeNote = selectedSection
      ? ' <span class="pp-chat-scope">en “' + esc(ctxLabel.textContent) + '”</span>'
      : '';
    addMsg('user', esc(text) + scopeNote);
    var thinking = addMsg('assistant', '<span class="pp-chat-dots"><i></i><i></i><i></i></span> Aplicando el cambio…');

    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('instruction', text);
    if (selectedSection) fd.append('section', selectedSection);

    fetch(body.dataset.chatUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
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
        reloadPreview();
      })
      .catch(function () {
        thinking.remove();
        addMsg('assistant pp-chat-msg--error', 'No hay conexión ahora mismo. Tu página no ha cambiado.');
      })
      .finally(function () {
        busy = false;
        sendBtn.disabled = false;
        input.focus();
      });
  });

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
      .finally(function () { busy = false; });
  }

  function doUndo(srcBtn) { historyStep(body.dataset.undoUrl, 'deshacer', srcBtn || undoBtn); }
  function doRedo() { historyStep(body.dataset.redoUrl, 'rehacer', redoBtn); }

  undoBtn.addEventListener('click', function () { if (!undoBtn.disabled) doUndo(undoBtn); });
  redoBtn.addEventListener('click', function () { if (!redoBtn.disabled) doRedo(); });

  // Estado inicial de los botones (lo pinta el servidor en data-can-*).
  applyHistory({ can_undo: body.dataset.canUndo === '1', can_redo: body.dataset.canRedo === '1' });

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
      .finally(function () { busy = false; });
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
  var mediaCache = null;

  function openMediaModal() {
    mediaModal.hidden = false;
    if (mediaCache) { renderMedia(mediaCache); return; }
    mediaGrid.innerHTML = '<p class="pp-chat-hint">Cargando tu biblioteca…</p>';
    fetch(body.dataset.mediaUrl)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        mediaCache = (data.items || []).filter(function (it) { return (it.mime_type || '').indexOf('image/') === 0; });
        renderMedia(mediaCache);
      })
      .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">No se pudo cargar la biblioteca.</p>'; });
  }

  function renderMedia(items) {
    if (!items.length) {
      mediaGrid.innerHTML = '<p class="pp-chat-hint">Tu biblioteca está vacía. Sube imágenes desde Medios.</p>';
      return;
    }
    mediaGrid.innerHTML = '';
    items.forEach(function (it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cvstudio-media-item';
      btn.innerHTML = '<img src="' + it.url + '" alt="" loading="lazy"><span>' + esc(it.alt_text || it.name || '') + '</span>';
      btn.addEventListener('click', function () {
        mediaModal.hidden = true;
        iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'replace-image', src: it.path, alt: it.alt_text || '' }, '*');
      });
      mediaGrid.appendChild(btn);
    });
  }

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
