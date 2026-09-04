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
  // F7 — el selector vive en Ajustes; el chat sigue mandando lo que haya elegido.
  var modelSelect = document.getElementById('settings-ai-model');

  var selectedSection = null;
  var selectedElementContext = '';
  var selectedElementPath = '';
  var busy = false;
  var lastScrollY = 0;
  // STUDIO-2 B1 — últimos turnos de la conversación (se envían como contexto).
  var chatHistory = [];
  // STUDIO-2 B3 — sección que acaba de cambiar, para señalarla tras recargar.
  var pendingFlash = '';
  var suppressChatFocusOnce = false;

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
  // A3′ — aviso efímero en la píldora («Cambio aplicado»). Es una capa por
  // encima del texto normal: `refreshPill` lo respeta mientras dure, salvo que
  // el chat se abra o arranque otro cambio, que es cuando ya sobra.
  var pillNotice = '';
  var pillNoticeTimer = null;

  function pillText() {
    if (busy) return pp.t('js.cv.applying');
    if (selectedSection) return pp.t('js.cv.change_x', { parte: ctxLabel.textContent || pp.t('js.cv.this_part') });
    return pp.t('cv.ask_change_js');
  }

  function refreshPill() {
    // El aviso solo estorba si el chat está abierto (ahí ya se lee la respuesta).
    // Ojo con `busy`: la respuesta se pinta ANTES de que `setBusy(false)` llegue,
    // así que mirar `busy` aquí borraba el aviso justo al nacer.
    if (dockIsOpen()) pillNotice = '';
    chatPillLabel.textContent = pillNotice || pillText();
    chatPill.title = pp.t('js.cv.open_chat');
  }

  function showPillNotice(text) {
    pillNotice = text;
    clearTimeout(pillNoticeTimer);
    pillNoticeTimer = setTimeout(function () { pillNotice = ''; refreshPill(); }, 6000);
    refreshPill();
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

  // STUDIO-UX A3′ — Arranca PLEGADO. Antes se abría solo y se quedaba abierto
  // toda la sesión: 390×415 de panel sobre una página que es el producto. La
  // píldora dice de qué va y basta un clic (o la tecla) para abrirlo.
  var dockPref = '0';
  try { dockPref = localStorage.getItem(DOCK_KEY) || '0'; } catch (e) { /* modo privado */ }
  setDock(dockPref === '1', false);

  chatPill.addEventListener('click', function () { setDock(true); input.focus(); });
  chatMinimize.addEventListener('click', function () { setDock(false); });

  // ----------------------------------------------------------------
  // STUDIO-UX A2/A4 — Sitio para el lienzo.
  // A2: la barra lateral se pliega (botón o «B») y el estado se recuerda.
  // A4: modo "solo página" (botón o «.»): ni barra, ni chat, ni marcas de
  // edición dentro del iframe. Ninguno de los dos recarga el preview.
  // ----------------------------------------------------------------
  var SIDE_KEY = 'pp-studio-side-open';
  var sideToggle = document.getElementById('studio-side-toggle');
  var canvasOnlyBtn = document.getElementById('studio-canvas-only');

  function labelButton(btn, text) {
    if (!btn) return;
    btn.title = text;
    btn.setAttribute('aria-label', text);
  }

  function sideIsOpen() { return !document.body.classList.contains('is-side-hidden'); }

  function setSide(open, remember) {
    document.body.classList.toggle('is-side-hidden', !open);
    if (sideToggle) sideToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    labelButton(sideToggle, pp.t(open ? 'js.cv.hide_panel' : 'js.cv.show_panel'));
    if (remember !== false) {
      try { localStorage.setItem(SIDE_KEY, open ? '1' : '0'); } catch (e) { /* modo privado */ }
    }
  }

  var sidePref = '1';
  try { sidePref = localStorage.getItem(SIDE_KEY) || '1'; } catch (e) { /* modo privado */ }
  setSide(sidePref !== '0', false);
  if (sideToggle) sideToggle.addEventListener('click', function () { setSide(!sideIsOpen()); });

  function canvasOnlyIsOn() { return document.body.classList.contains('is-canvas-only'); }

  function setCanvasOnly(on) {
    document.body.classList.toggle('is-canvas-only', on);
    if (canvasOnlyBtn) canvasOnlyBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    labelButton(canvasOnlyBtn, pp.t(on ? 'js.cv.canvas_only_exit' : 'js.cv.canvas_only'));
    // El overlay sigue vivo dentro del iframe: se le pide que calle, no que se vaya.
    tellIframe({ type: 'chrome', on: !on });
  }

  labelButton(canvasOnlyBtn, pp.t('js.cv.canvas_only'));
  if (canvasOnlyBtn) canvasOnlyBtn.addEventListener('click', function () { setCanvasOnly(!canvasOnlyIsOn()); });

  // Un solo sitio para los atajos: los del padre y los que reenvía el overlay
  // desde dentro del lienzo (P5 — allí es donde está el foco casi siempre).
  function studioShortcut(key) {
    if (key === 'b' || key === 'B') { setSide(!sideIsOpen()); return true; }
    if (key === '.') { setCanvasOnly(!canvasOnlyIsOn()); return true; }
    if (key === 'Escape' && canvasOnlyIsOn()) { setCanvasOnly(false); return true; }
    return false;
  }

  function typingTarget(t) {
    if (!t) return false;
    return /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName || '') || t.isContentEditable === true;
  }

  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (typingTarget(e.target)) return;
    if (studioShortcut(e.key)) e.preventDefault();
  });

  // ----------------------------------------------------------------
  // Mensajes del chat
  // ----------------------------------------------------------------
  function addMsg(kind, html) {
    var div = document.createElement('div');
    div.className = 'pp-chat-msg pp-chat-msg--' + kind;
    div.innerHTML = html;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
    // A3′ — Con el chat plegado, la píldora es el único canal de vuelta: además
    // del punto, dice en palabras que ya hay respuesta. Así no hace falta dejar
    // el panel abierto solo para enterarse de que el cambio terminó.
    if (kind.indexOf('assistant') === 0 && dock && !dockIsOpen()) {
      chatPillDot.hidden = false;
      showPillNotice(pp.t('js.cv.change_ready'));
    }
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
    if (diff < 60) return pp.t('js.cv.just_now');
    if (diff < 3600) return pp.t('js.cv.ago_min', { n: Math.floor(diff / 60) });
    if (diff < 86400) return pp.t('js.cv.ago_h', { n: Math.floor(diff / 3600) });
    if (diff < 604800) return pp.t('js.cv.ago_days', { n: Math.floor(diff / 86400) });
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
      input.placeholder = pp.t('js.canvas.input_ph');
      markCurrentSection();
      refreshPill();
      // Si el usuario está editando texto EN la página, el foco es suyo:
      // robárselo aquí era el bug que impedía escribir inline. Y con el chat
      // plegado no hay dónde escribir: tampoco se toca el foco.
      if (!d.editing && dockIsOpen() && !suppressChatFocusOnce) input.focus();
      suppressChatFocusOnce = false;
    }
    // A2/A4 — tecla pulsada DENTRO del lienzo: el overlay la reenvía porque el
    // foco del usuario vive ahí y estos listeners están en el padre.
    if (d.type === 'key') { studioShortcut(d.key); return; }
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
      // STUDIO-UX F3 — el overlay vive en el iframe y no tiene catálogo de
      // idioma ni la lista de páginas: se los pasa el panel al arrancar.
      tellIframe({
        type: 'studio-config',
        labels: {
          bold: pp.t('js.cv.rt_bold'),
          italic: pp.t('js.cv.rt_italic'),
          link: pp.t('js.cv.rt_link'),
          unlink: pp.t('js.cv.rt_unlink'),
          link_url: pp.t('js.cv.rt_link_url'),
          link_apply: pp.t('js.cv.rt_link_apply'),
          link_page: pp.t('js.cv.rt_link_page')
        },
        linkTargets: LINKS
      });
      renderSectionList(d.sections || []);
      if (lastScrollY > 0) {
        iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'scroll-to', y: lastScrollY }, '*');
      }
      if (selectedSection) {
        suppressChatFocusOnce = true;
        iframe.contentWindow.postMessage({ source: 'pp-studio-parent', type: 'select', id: selectedSection }, '*');
      }
      lastScrollY = 0;
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
  var structureStatus = document.getElementById('structure-status');
  var insertPlacementHint = document.getElementById('studio-insert-placement');
  var addBlock = document.getElementById('studio-add-block');
  var pageSections = [];
  var insertPlacement = null;
  var structureBusy = false;
  var pendingStructureFocus = '';

  // "cta-final" → "Cta final" (misma regla que el overlay del iframe).
  function sectionLabel(id) {
    var s = String(id || '').replace(/[-_]+/g, ' ').trim();
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : pp.t('js.cv.section');
  }

  function tellIframe(msg) {
    if (iframe.contentWindow) {
      msg.source = 'pp-studio-parent';
      iframe.contentWindow.postMessage(msg, '*');
    }
  }

  function structureIcon(name) {
    var paths = {
      plus: '<path d="M12 5v14M5 12h14"/>',
      up: '<path d="M6 15l6-6 6 6"/>',
      down: '<path d="M6 9l6 6 6-6"/>',
      duplicate: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
      trash: '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13"/>'
    };
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + (paths[name] || '') + '</svg>';
  }

  function insertionLabel(anchor, position) {
    if (!pageSections.length) return pp.t('js.cv.insert_at_start');
    var part = pageSections.find(function (p) { return p.id === anchor; });
    if (!part) return position === 'before' ? pp.t('js.cv.insert_at_start') : pp.t('js.cv.insert_at_end');
    var index = pageSections.indexOf(part);
    if (position === 'before' && index === 0) return pp.t('js.cv.insert_at_start');
    if (position === 'after' && index === pageSections.length - 1) return pp.t('js.cv.insert_at_end');
    return pp.t(position === 'before' ? 'js.cv.insert_before_x' : 'js.cv.insert_after_x', { parte: part.label });
  }

  function updateInsertionHint() {
    if (!insertPlacementHint) return;
    if (!insertPlacement) {
      insertPlacementHint.hidden = true;
      insertPlacementHint.textContent = '';
      return;
    }
    insertPlacementHint.textContent = insertionLabel(insertPlacement.anchor, insertPlacement.position);
    insertPlacementHint.hidden = false;
  }

  function showStructureStatus(text, kind, undoSection) {
    if (!structureStatus) return;
    structureStatus.innerHTML = '';
    structureStatus.className = 'cvstudio-structure-status' + (kind ? ' is-' + kind : '');
    var message = document.createElement('span');
    message.textContent = text;
    structureStatus.appendChild(message);
    if (undoSection) {
      var undo = document.createElement('button');
      undo.type = 'button';
      undo.textContent = pp.t('js.cv.undo_action');
      undo.addEventListener('click', function () {
        selectedSection = undoSection;
        pendingFlash = undoSection;
        pendingStructureFocus = undoSection;
        doUndo(undo);
      });
      structureStatus.appendChild(undo);
    }
    structureStatus.hidden = false;
  }

  function hideStructureStatus() {
    if (!structureStatus) return;
    structureStatus.hidden = true;
    structureStatus.innerHTML = '';
    structureStatus.className = 'cvstudio-structure-status';
  }

  function chooseInsertPoint(anchor, position) {
    insertPlacement = { anchor: anchor || '', position: position };
    closePanel();
    updateInsertionHint();
    renderSectionList(pageSections);
    if (addBlock) addBlock.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    var first = addBlock && addBlock.querySelector('.cvstudio-insert__btn:not(:disabled)');
    if (first) setTimeout(function () { first.focus(); }, 180);
  }

  function createInsertPoint(anchor, position) {
    var li = document.createElement('li');
    li.className = 'cvstudio-insertpoint';
    if (insertPlacement && insertPlacement.anchor === anchor && insertPlacement.position === position) li.classList.add('is-current');
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'cvstudio-insertpoint__btn';
    button.dataset.insertAnchor = anchor;
    button.dataset.insertPosition = position;
    button.setAttribute('aria-label', insertionLabel(anchor, position));
    button.innerHTML = structureIcon('plus') + '<span>' + esc(pp.t('js.cv.add_here')) + '</span>';
    button.addEventListener('click', function () { chooseInsertPoint(anchor, position); });
    li.appendChild(button);
    return li;
  }

  function structureActionButton(action, id, label, disabled) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'cvstudio-seclist__action' + (action === 'delete' ? ' is-danger' : '');
    button.dataset.structureAction = action;
    button.disabled = !!disabled;
    var labels = {
      up: 'js.cv.move_up',
      down: 'js.cv.move_down',
      duplicate: 'js.cv.duplicate_section',
      delete: 'js.cv.delete_section'
    };
    var text = pp.t(labels[action] || labels.delete);
    button.title = text;
    button.setAttribute('aria-label', text + ': ' + label);
    button.innerHTML = structureIcon(action === 'delete' ? 'trash' : action);
    button.addEventListener('click', function (e) {
      e.stopPropagation();
      var isMove = action === 'up' || action === 'down';
      updateCanvasStructure(isMove ? 'move' : action, id, isMove ? action : '', button);
    });
    return button;
  }

  // ----------------------------------------------------------------
  // STUDIO-UX F8 — Duplicar una parte de ESTA página desde "Añadir": es la
  // forma natural de crecer una página que ya funciona, y respeta el punto de
  // inserción que el usuario acaba de elegir.
  // ----------------------------------------------------------------
  (function () {
    var wrap = document.getElementById('studio-duplicate-part');
    if (!wrap) return;
    var trigger = document.getElementById('studio-duplicate-part-btn');
    var menu = document.getElementById('studio-duplicate-part-menu');

    function closeMenu() {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
    }

    function render() {
      if (!pageSections.length) {
        menu.innerHTML = '<p class="cvstudio-insert__empty">' + esc(pp.t('js.cv.duplicate_part_empty')) + '</p>';
        return;
      }
      menu.innerHTML = '<strong class="cvstudio-insert__title">' + esc(pp.t('js.cv.duplicate_part_pick')) + '</strong>'
        + pageSections.map(function (part) {
            return '<button type="button" class="cvstudio-menu__item" data-duplicate-part="' + esc(part.id) + '">'
              + esc(part.label) + '</button>';
          }).join('');
      menu.querySelectorAll('[data-duplicate-part]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          closeMenu();
          closeBlockPicker();
          duplicateWithPlacement(btn.dataset.duplicatePart, btn);
        });
      });
    }

    function duplicateWithPlacement(sectionId, btn) {
      if (structureBusy || busy) return;
      var pending = flushSectionSave();
      if (pending) { pending.then(function () { duplicateWithPlacement(sectionId, btn); }); return; }

      structureBusy = true;
      if (btn) btn.disabled = true;
      showStructureStatus(pp.t('js.cv.duplicating_section'), 'loading');

      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('action', 'duplicate');
      fd.append('section', sectionId);
      // El ancla viaja aparte de `section`: `section` es QUÉ se duplica y
      // `anchor` DÓNDE va la copia.
      if (insertPlacement && insertPlacement.anchor) {
        fd.append('anchor', insertPlacement.anchor);
        fd.append('position', insertPlacement.position);
      }

      fetch(body.dataset.structureUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (data) {
          if (!data.ok) {
            showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error');
            return;
          }
          applyHistory(data.history);
          pendingStructureFocus = String(data.focus_section || '');
          pendingFlash = String(data.changed_section || '');
          selectedSection = String(data.focus_section || '');
          insertPlacement = null;
          updateInsertionHint();
          showStructureStatus(pp.t('js.cv.section_duplicated'), 'success');
          renderSectionList(data.sections || []);
          reloadPreview();
        })
        .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
        .finally(function () {
          structureBusy = false;
          if (btn && btn.isConnected) btn.disabled = false;
        });
    }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = menu.hidden;
      menu.hidden = !open;
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) render();
    });
    document.addEventListener('click', function (e) {
      if (!menu.hidden && !wrap.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hidden) closeMenu();
    });
  })();

  // ----------------------------------------------------------------
  // STUDIO-UX F6 — Traer una parte de otra página, literal y sin IA.
  // ----------------------------------------------------------------
  (function () {
    var copyWrap = document.getElementById('studio-copy-section');
    if (!copyWrap) return;
    var copyBtn = document.getElementById('studio-copy-btn');
    var copyMenu = document.getElementById('studio-copy-menu');
    var loaded = false;

    function closeCopyMenu() {
      copyMenu.hidden = true;
      copyBtn.setAttribute('aria-expanded', 'false');
    }

    function renderPages(pages) {
      if (!pages.length) {
        copyMenu.innerHTML = '<p class="cvstudio-insert__empty">' + esc(pp.t('js.cv.copy_no_pages')) + '</p>';
        return;
      }
      var html = '<strong class="cvstudio-insert__title">' + esc(pp.t('js.cv.copy_pick_page')) + '</strong>';
      pages.forEach(function (page) {
        html += '<strong class="cvstudio-insert__title">' + esc(page.title) + '</strong>';
        page.sections.forEach(function (sec) {
          html += '<button type="button" class="cvstudio-menu__item" data-copy-page="' + page.id
            + '" data-copy-section="' + esc(sec.id) + '">' + esc(sec.label) + '</button>';
        });
      });
      copyMenu.innerHTML = html;
      copyMenu.querySelectorAll('[data-copy-section]').forEach(function (btn) {
        btn.addEventListener('click', function () { copySection(btn.dataset.copyPage, btn.dataset.copySection, btn); });
      });
    }

    function loadPages() {
      if (loaded) return;
      copyMenu.innerHTML = '<p class="cvstudio-insert__empty">' + esc(pp.t('js.cv.loading')) + '</p>';
      fetch(body.dataset.copySourcesUrl, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) throw new Error('nope');
          loaded = true;
          renderPages(data.pages || []);
        })
        .catch(function () {
          copyMenu.innerHTML = '<p class="cvstudio-insert__empty">' + esc(pp.t('js.cv.structure_error')) + '</p>';
        });
    }

    function copySection(pageId, sectionId, btn) {
      if (structureBusy || busy) return;
      var pendingCopy = flushSectionSave();
      if (pendingCopy) { pendingCopy.then(function () { copySection(pageId, sectionId, btn); }); return; }

      structureBusy = true;
      if (btn) btn.disabled = true;
      closeCopyMenu();
      closeBlockPicker();
      showStructureStatus(pp.t('js.cv.copying_section'), 'loading');

      var fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('source_page', pageId);
      fd.append('source_section', sectionId);
      appendRequestedPlacement(fd);

      fetch(body.dataset.copySectionUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (data) {
          if (!data.ok) {
            showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error');
            return;
          }
          applyHistory(data.history);
          pendingStructureFocus = String(data.focus_section || '');
          pendingFlash = String(data.changed_section || '');
          selectedSection = String(data.focus_section || '');
          insertPlacement = null;
          updateInsertionHint();
          showStructureStatus(pp.t('js.cv.section_copied'), 'success');
          renderSectionList(data.sections || []);
          reloadPreview();
        })
        .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
        .finally(function () {
          structureBusy = false;
          if (btn && btn.isConnected) btn.disabled = false;
        });
    }

    copyBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = copyMenu.hidden;
      copyMenu.hidden = !open;
      copyBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) loadPages();
    });
    document.addEventListener('click', function (e) {
      if (!copyMenu.hidden && !copyWrap.contains(e.target)) closeCopyMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !copyMenu.hidden) closeCopyMenu();
    });
  })();

  // ----------------------------------------------------------------
  // STUDIO-UX F5 — Reordenar arrastrando: el DOM de la lista se mueve en vivo
  // y se guarda UNA sola vez al soltar, con el orden final completo.
  // ----------------------------------------------------------------
  var dragId = '';

  function currentOrderFromList() {
    return Array.prototype.map.call(
      sectionList.querySelectorAll('[data-section-item]'),
      function (li) { return li.dataset.sectionItem; }
    );
  }

  function clearDropMarks() {
    sectionList.querySelectorAll('.is-drop-target').forEach(function (n) { n.classList.remove('is-drop-target'); });
  }

  function wireRowDrag(li, id) {
    li.addEventListener('dragstart', function (e) {
      if (structureBusy || busy) { e.preventDefault(); return; }
      dragId = id;
      li.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      // Firefox no arranca el arrastre sin datos.
      try { e.dataTransfer.setData('text/plain', id); } catch (err) { /* da igual */ }
    });
    li.addEventListener('dragend', function () {
      dragId = '';
      li.classList.remove('is-dragging');
      clearDropMarks();
    });
    li.addEventListener('dragover', function (e) {
      if (!dragId || dragId === id) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      clearDropMarks();
      li.classList.add('is-drop-target');
    });
    li.addEventListener('dragleave', function () { li.classList.remove('is-drop-target'); });
    li.addEventListener('drop', function (e) {
      if (!dragId || dragId === id) return;
      e.preventDefault();
      var before = currentOrderFromList();
      var moving = sectionList.querySelector('[data-section-item="' + CSS.escape(dragId) + '"]');
      if (!moving) return;
      // Cae antes o después según de dónde venga, para que el gesto sea el que
      // uno espera al soltar sobre una fila.
      var movingIndex = before.indexOf(dragId);
      var targetIndex = before.indexOf(id);
      li.parentNode.insertBefore(moving, movingIndex < targetIndex ? li.nextSibling : li);
      clearDropMarks();
      commitReorder(before);
    });
  }

  function commitReorder(previousOrder) {
    var order = currentOrderFromList();
    if (order.join('|') === previousOrder.join('|')) return;   // no se movió
    if (structureBusy || busy) return;

    var pendingReorder = flushSectionSave();
    if (pendingReorder) { pendingReorder.then(function () { commitReorder(previousOrder); }); return; }

    structureBusy = true;
    showStructureStatus(pp.t('js.cv.moving_section'), 'loading');
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('action', 'reorder');
    order.forEach(function (id) { fd.append('order[]', id); });

    fetch(body.dataset.structureUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok) {
          showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error');
          renderSectionList(pageSections);   // devolver la lista a lo que hay
          return;
        }
        applyHistory(data.history);
        showStructureStatus(pp.t('js.cv.sections_reordered'), 'success');
        renderSectionList(data.sections || []);
        reloadPreview();
      })
      .catch(function () {
        showStructureStatus(pp.t('js.cv.structure_error'), 'error');
        renderSectionList(pageSections);
      })
      .finally(function () { structureBusy = false; });
  }

  // El iframe manda las partes como {id, label}; se acepta también un array de
  // ids a secas por si queda una preview vieja en caché.
  function renderSectionList(parts) {
    pageSections = (Array.isArray(parts) ? parts : []).map(function (p) {
      return typeof p === 'string' ? { id: p, label: sectionLabel(p) } : { id: p.id, label: p.label || sectionLabel(p.id) };
    });
    if (!sectionList) return;
    sectionList.innerHTML = '';
    if (!pageSections.length) {
      sectionList.appendChild(createInsertPoint('', 'after'));
      markCurrentSection();
      return;
    }
    sectionList.appendChild(createInsertPoint(pageSections[0].id, 'before'));
    pageSections.forEach(function (part, i) {
      var id = part.id;
      var li = document.createElement('li');
      li.className = 'cvstudio-seclist__item';
      li.dataset.sectionItem = id;
      // STUDIO-UX F5 — arrastrar para reordenar. Los botones ↑↓ se quedan:
      // son la vía accesible y la de las pantallas táctiles.
      li.draggable = true;
      li.title = pp.t('js.cv.reorder_hint');
      wireRowDrag(li, id);
      var row = document.createElement('div');
      row.className = 'cvstudio-seclist__row';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cvstudio-seclist__select';
      btn.dataset.section = id;
      btn.innerHTML = '<span class="cvstudio-seclist__num">' + (i + 1) + '</span><span class="cvstudio-seclist__label">' + esc(part.label) + '</span>';
      btn.addEventListener('click', function () {
        suppressChatFocusOnce = true;
        tellIframe({ type: 'select', id: id, panel: true });
      });
      btn.addEventListener('mouseenter', function () { tellIframe({ type: 'highlight', id: id, on: true }); });
      btn.addEventListener('mouseleave', function () { tellIframe({ type: 'highlight', on: false }); });
      var actions = document.createElement('div');
      actions.className = 'cvstudio-seclist__actions';
      actions.appendChild(structureActionButton('up', id, part.label, i === 0));
      actions.appendChild(structureActionButton('down', id, part.label, i === pageSections.length - 1));
      actions.appendChild(structureActionButton('duplicate', id, part.label, false));
      actions.appendChild(structureActionButton('delete', id, part.label, false));
      row.appendChild(btn);
      row.appendChild(actions);
      li.appendChild(row);
      sectionList.appendChild(li);
      sectionList.appendChild(createInsertPoint(id, 'after'));
    });
    markCurrentSection();
    if (pendingStructureFocus) {
      var focusId = pendingStructureFocus;
      pendingStructureFocus = '';
      setTimeout(function () {
        var target = sectionList.querySelector('button[data-section="' + CSS.escape(focusId) + '"]');
        if (target) target.focus();
      }, 0);
    }
  }

  function markCurrentSection() {
    if (!sectionList) return;
    sectionList.querySelectorAll('button[data-section]').forEach(function (b) {
      b.classList.toggle('is-current', b.dataset.section === selectedSection);
      var item = b.closest('.cvstudio-seclist__item');
      if (item) item.classList.toggle('is-current', b.dataset.section === selectedSection);
    });
  }

  function updateCanvasStructure(action, sectionId, direction, trigger) {
    if (structureBusy || busy) return;
    var pendingStructure = flushSectionSave();
    if (pendingStructure) {
      pendingStructure.then(function () { updateCanvasStructure(action, sectionId, direction, trigger); });
      return;
    }
    structureBusy = true;
    if (trigger) trigger.disabled = true;
    var loadingKeys = { delete: 'js.cv.deleting_section', duplicate: 'js.cv.duplicating_section' };
    var loadingText = pp.t(loadingKeys[action] || 'js.cv.moving_section');
    showStructureStatus(loadingText, 'loading');

    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('action', action);
    fd.append('section', sectionId);
    if (direction) fd.append('direction', direction);
    fetch(body.dataset.structureUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data.ok) {
          showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error');
          return;
        }
        applyHistory(data.history);
        if (!data.changed) {
          showStructureStatus(pp.t('js.cv.section_at_limit'));
          return;
        }

        var focusId = String(data.focus_section || '');
        selectedSection = focusId;
        suppressChatFocusOnce = true;
        pendingStructureFocus = focusId;
        pendingFlash = String(data.changed_section || focusId);
        // Una posición espacial deja de ser fiable en cuanto cambia el orden.
        insertPlacement = null;
        updateInsertionHint();
        if (action === 'delete') {
          ctxBox.hidden = true;
          selectedElementContext = '';
          selectedElementPath = '';
          closePanel();
          showStructureStatus(pp.t('js.cv.section_deleted'), 'success', sectionId);
        } else {
          showStructureStatus(pp.t(action === 'duplicate' ? 'js.cv.section_duplicated' : 'js.cv.section_moved'), 'success');
        }
        renderSectionList(data.sections || []);
        reloadPreview();
      })
      .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
      .finally(function () {
        structureBusy = false;
        if (trigger && trigger.isConnected) trigger.disabled = false;
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
    var reset = '<button type="button" class="cvstudio-swatch cvstudio-swatch--reset" data-cop="' + op + '" data-cval="reset" title="' + pp.t('js.cv.remove') + '">×</button>';
    return '<div class="cvstudio-field"><label>' + esc(labelTxt) + '</label><div class="cvstudio-swatches">' + sw + picker + none + reset + '</div></div>';
  }

  function sizeField() {
    return '<div class="cvstudio-field"><label>' + pp.t('js.cv.size') + '</label><div class="cvstudio-btnrow">'
      + '<button type="button" data-op="size" data-val="down" title="' + pp.t('js.cv.smaller') + '">A−</button>'
      + '<button type="button" data-op="size" data-val="reset" title="' + pp.t('js.cv.normal_size') + '">A</button>'
      + '<button type="button" data-op="size" data-val="up" title="' + pp.t('js.cv.bigger') + '">A+</button>'
    + '</div></div>';
  }

  function textControls(props) {
    return ''
      + sizeField()
      + '<div class="cvstudio-field"><label>' + pp.t('chrome.style_js') + '</label><div class="cvstudio-btnrow">'
        + '<button type="button" data-toggle="bold" class="' + (props.bold ? 'is-on' : '') + '" title="' + pp.t('js.cv.bold') + '"><b>B</b></button>'
        + '<button type="button" data-toggle="italic" class="' + (props.italic ? 'is-on' : '') + '" title="' + pp.t('design.italic_js') + '"><i>I</i></button>'
      + '</div></div>'
      + '<div class="cvstudio-field"><label>' + pp.t('js.cv.align') + '</label><div class="cvstudio-btnrow">'
        + '<button type="button" data-op="align" data-val="left" title="' + pp.t('chrome.side.left_js') + '">⬅</button>'
        + '<button type="button" data-op="align" data-val="center" title="' + pp.t('js.cv.center') + '">↔</button>'
        + '<button type="button" data-op="align" data-val="right" title="' + pp.t('chrome.side.right_js') + '">➡</button>'
        + '<button type="button" data-op="align" data-val="justify" title="' + pp.t('js.cv.justify') + '">☰</button>'
      + '</div></div>'
      + colorField(pp.t('js.cv.text_color'), 'color', { current: props.color });
  }

  function radiusField() {
    return '<div class="cvstudio-field"><label>' + pp.t('js.cv.corners') + '</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
      + '<button type="button" data-op="radius" data-val="sharp">' + pp.t('js.cv.sharp') + '</button>'
      + '<button type="button" data-op="radius" data-val="soft">' + pp.t('js.cv.soft') + '</button>'
      + '<button type="button" data-op="radius" data-val="round">' + pp.t('js.cv.round') + '</button>'
      + '<button type="button" data-op="radius" data-val="pill">' + pp.t('js.cv.pill') + '</button>'
    + '</div></div>';
  }

  function cornerFields(props) {
    var fields = [
      ['top-left', pp.t('js.cv.top_left'), props.radiusTopLeft],
      ['top-right', pp.t('js.cv.top_right'), props.radiusTopRight],
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
      + colorField(pp.t('js.cv.fill'), 'fill', { none: true, current: props.fill })
      + radiusField()
      + cornerFields(props);
  }

  function linkControls(props) {
    var opts = '<option value="">— ' + pp.t('js.cv.pick_page') + ' —</option>'
      + LINKS.map(function (l) {
        return '<option value="' + esc(l.url) + '"' + (l.url === props.href ? ' selected' : '') + '>' + esc(l.title) + '</option>';
      }).join('');
    var styleControls = props.isButton
      ? colorField(pp.t('js.cv.fill'), 'fill', { none: true, current: props.fill }) + colorField(pp.t('js.cv.text_color'), 'color', { current: props.color }) + radiusField() + sizeField()
      : colorField(pp.t('js.cv.color'), 'color', { current: props.color }) + sizeField();
    return ''
      + '<div class="cvstudio-field"><label>' + pp.t('js.chrome.text') + '</label>'
        + '<input type="text" id="ep-text" value="' + esc(props.text || '') + '"></div>'
      + '<div class="cvstudio-field"><label>' + pp.t('js.cv.link_to_page') + '</label>'
        + '<select id="ep-page">' + opts + '</select></div>'
      + '<div class="cvstudio-field"><label>' + pp.t('js.cv.or_url') + '</label>'
        + '<input type="text" id="ep-url" placeholder="https://…" value="' + esc(props.href || '') + '"></div>'
      + '<label class="cvstudio-check"><input type="checkbox" id="ep-newtab"' + (props.newTab ? ' checked' : '') + '> ' + pp.t('js.cv.open_new_tab') + '</label>'
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
      ? '<div class="cvstudio-field"><label>' + pp.t('js.cv.gallery_layout') + '</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + seg('sliderlayout', 'strip', props.slider, pp.t('js.cv.in_row'))
          + seg('sliderlayout', 'single', props.slider, pp.t('js.cv.one_by_one'))
          + seg('sliderlayout', 'vertical', props.slider, pp.t('js.cv.vertical'))
        + '</div></div>'
        + '<div class="cvstudio-field"><label>' + pp.t('js.cv.gallery_photos') + '</label><div class="cvstudio-btnrow cvstudio-btnrow--wrap">'
          + '<button type="button" id="ep-gallery-pick">' + pp.t('js.cv.pick_photos') + (props.sliderPhotos ? ' (' + props.sliderPhotos + ')' : '') + '</button>'
        + '</div><small class="cvstudio-hint">' + pp.t('js.cv.gallery_hint') + '</small></div>'
      : '';

    return ''
      + colorField(pp.t('chrome.bg_color_js'), 'bgcolor', { current: props.bgcolor })
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
  // Las claves son tipos internos; solo la etiqueta se traduce.
  var CRUMB_LABELS = { text: pp.t('js.chrome.text'), box: pp.t('js.cv.box'), link: pp.t('chrome.button_js'), image: pp.t('js.post_editor.image'), section: pp.t('js.cv.section') };
  var panelState = { chain: [], index: -1, sectionLabel: '' };

  function renderCrumbs(chain, active, sectionLabel) {
    var parts = ['<button type="button" class="cvstudio-crumb" data-scope="-1" title="' + pp.t('js.cv.clear_scope') + '">' + pp.t('js.onb.page') + '</button>'];
    chain.forEach(function (c, i) {
      var label = c.kind === 'section' ? (sectionLabel || pp.t('js.cv.section')) : (CRUMB_LABELS[c.kind] || pp.t('js.cv.element'));
      parts.push(i === active
        ? '<strong class="cvstudio-crumb is-active">' + esc(label) + '</strong>'
        : '<button type="button" class="cvstudio-crumb" data-scope="' + i + '">' + esc(label) + '</button>');
    });
    return '<nav class="cvstudio-crumbs" aria-label="' + pp.t('js.cv.scope_aria') + '">'
      + parts.join('<i aria-hidden="true">›</i>') + '</nav>';
  }

  // STUDIO-UX F2 — Acciones estructurales del elemento seleccionado. Antes,
  // añadir una tarjeta a una rejilla o quitar un botón obligaba a pedirle al
  // modelo que reescribiera la sección entera.
  function elActionButton(op, val, key, icon, disabled) {
    var text = pp.t(key);
    return '<button type="button" class="cvstudio-elaction' + (op === 'el-delete' ? ' is-danger' : '') + '"'
      + ' data-elop="' + op + '" data-elval="' + val + '"' + (disabled ? ' disabled' : '')
      + ' title="' + esc(text) + '" aria-label="' + esc(text) + '">' + structureIcon(icon) + '</button>';
  }

  function elActionsRow(structure) {
    if (!structure) return '';
    return '<div class="cvstudio-panel__structure" role="group" aria-label="' + pp.t('js.cv.element_actions') + '">'
      + elActionButton('el-duplicate', '', 'js.cv.duplicate_element', 'duplicate', false)
      + elActionButton('el-move', 'prev', 'js.cv.move_element_prev', 'up', !structure.canPrev)
      + elActionButton('el-move', 'next', 'js.cv.move_element_next', 'down', !structure.canNext)
      + elActionButton('el-delete', '', 'js.cv.delete_element', 'trash', !structure.canDelete)
      + '</div>';
  }

  function openPanel(d) {
    var p = d.props || {};
    var titles = { text: pp.t('js.chrome.text'), box: pp.t('js.cv.box'), link: pp.t('js.cv.button_link'), image: pp.t('js.post_editor.image'), section: pp.t('js.cv.section') };
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
            : '<strong>' + esc(titles[d.kind] || pp.t('js.cv.element')) + '</strong><small>' + esc(d.sectionLabel || '') + '</small>')
        + '<button type="button" id="ep-close" title="' + pp.t('js.common.close') + '">✕</button>'
      + '</div>'
      + elActionsRow(d.structure)
      + '<div class="cvstudio-panel__body">' + bodyHtml + '</div>'
      + '<p class="pp-chat-hint">' + pp.t('js.cv.complex_hint') + '</p>';
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
    // Estas no pintan "Guardado" a ciegas: el overlay serializa la sección y el
    // aviso lo da `saveSectionInline` cuando el servidor confirma.
    panel.querySelectorAll('[data-elop]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.disabled) return;
        applyOp(btn.dataset.elop, btn.dataset.elval || '');
      });
    });
    panel.querySelectorAll('[data-op]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyOp(btn.dataset.op, btn.dataset.val);
        if (SEGMENTED[btn.dataset.op]) {
          var sibs = btn.parentNode.querySelectorAll('[data-op="' + btn.dataset.op + '"]');
          sibs.forEach(function (b) { b.classList.remove('is-on'); });
          btn.classList.add('is-on');
        }
      });
    });
    panel.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var on = !btn.classList.contains('is-on');
        btn.classList.toggle('is-on', on);
        applyOp(btn.dataset.toggle, on);
      });
    });
    panel.querySelectorAll('[data-corner]').forEach(function (field) {
      field.addEventListener('change', function () {
        applyOp('corner-radius', { corner: field.dataset.corner, px: field.value });
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
      });
    });
    // Picker de color libre.
    panel.querySelectorAll('[data-cinput]').forEach(function (inp) {
      var lbl = inp.parentNode;
      inp.addEventListener('input', function () { applyOp(inp.dataset.cinput, inp.value, true); lbl.style.background = inp.value; }); // preview
      inp.addEventListener('change', function () { applyOp(inp.dataset.cinput, inp.value); markColor(inp.dataset.cinput, lbl); lbl.style.background = inp.value; });
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
        if (pageSel.value) { urlIn.value = pageSel.value; applyOp('link', pageSel.value); }
      });
      if (urlIn) urlIn.addEventListener('change', function () { applyOp('link', urlIn.value.trim()); });
      if (textIn) textIn.addEventListener('change', function () { applyOp('settext', textIn.value); });
      if (newtab) newtab.addEventListener('change', function () { applyOp('newtab', newtab.checked); });
    }
    if (kind === 'image') {
      var altIn = panel.querySelector('#ep-alt');
      var repl = panel.querySelector('#ep-replace');
      if (altIn) altIn.addEventListener('change', function () { applyOp('alt', altIn.value); });
      if (repl) repl.addEventListener('click', function () { openMediaModal(); });
    }
    if (kind === 'section') {
      var reveal = panel.querySelector('#ep-reveal');
      if (reveal) reveal.addEventListener('change', function () { applyOp('reveal', reveal.checked); });
      var bgChange = panel.querySelector('#ep-bg-change');
      var bgRemove = panel.querySelector('#ep-bg-remove');
      var bgAdd = panel.querySelector('#ep-bg-add');
      // "Cambiar"/"Poner" marca el destino del fondo y abre la biblioteca (replace-image guarda).
      if (bgChange) bgChange.addEventListener('click', function () { applyOp('bgimg', 'mark'); openMediaModal(); });
      if (bgAdd) bgAdd.addEventListener('click', function () { applyOp('bgimg', 'mark'); openMediaModal(); });
      if (bgRemove) bgRemove.addEventListener('click', function () { applyOp('bgimg', 'remove'); });
      var galleryPick = panel.querySelector('#ep-gallery-pick');
      if (galleryPick) galleryPick.addEventListener('click', function () { openMediaModal(true); });
    }
  }

  function clearSelection(notifyIframe) {
    selectedSection = null;
    selectedElementContext = '';
    ctxBox.hidden = true;
    input.placeholder = pp.t('js.cv.chat_scoped_placeholder');
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
      if (s >= 45) note = ' · ' + pp.t(scoped ? 'js.cv.slow_scoped' : 'js.cv.slow_page');
      else if (s >= 15) note = ' · ' + pp.t(scoped ? 'js.cv.wait_scoped' : 'js.cv.wait_page');
      slot.textContent = 'Aplicando el cambio… ' + s + ' s' + note;
    }, 1000);
    return iv;
  }

  function newRequestId() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID().replace(/-/g, '');
    return 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  function setBusy(on) {
    // Empieza otro cambio: el aviso del anterior ya no interesa.
    if (on) { pillNotice = ''; clearTimeout(pillNoticeTimer); }
    busy = on;
    sendBtn.disabled = on;
    if (cancelBtn) cancelBtn.hidden = !on;
    refreshPill();   // plegado, la pastilla dice si se está aplicando algo
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (text === '' || busy) return;

    // F4 — la IA parte del HTML guardado: sin vaciar la cola, trabajaría sobre
    // una versión anterior a lo que el usuario acaba de tocar a mano.
    var pendingChat = flushSectionSave();
    if (pendingChat) { pendingChat.then(function () { form.dispatchEvent(new Event('submit')); }); return; }

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
        addMsg('assistant pp-chat-msg--error', pp.t('js.cv.no_connection_page'));
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
          addMsg('assistant', pp.t('js.cv.cancelled'));
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
    // C4 — cada guardado dice si la página se ha separado de lo publicado.
    if (typeof state.has_unpublished === 'boolean' && state.has_unpublished !== hasUnpublished) {
      hasUnpublished = state.has_unpublished;
      reflectPublished(body.dataset.published === '1');
    }
  }

  function historyStep(url, label, btn) {
    if (busy) return;
    // F4 — lo pendiente sale ANTES: si el guardado llegara después del undo,
    // reescribiría con el estado viejo lo que el undo acaba de restaurar.
    var pending = flushSectionSave();
    if (pending) { pending.then(function () { historyStep(url, label, btn); }); return; }
    busy = true;
    if (btn) btn.disabled = true;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fetch(url, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (data.ok) {
          applyHistory(data.history);
          hideStructureStatus();
          reloadPreview();
        } else if (data.error) {
          showSaved(data.error, true);
        }
      })
      .catch(function () { showSaved(pp.t('js.media.no_connection'), true); })
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
    var pendingRestore = flushSectionSave();
    if (pendingRestore) { pendingRestore.then(function () { restoreVersion(versionId, btn); }); return; }
    busy = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Recuperando…'; }
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('version_id', versionId);
    fetch(body.dataset.restoreUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) { applyHistory(data.history); reloadPreview(); showSaved(pp.t('js.cv.version_restored')); }
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
  // `pending` = todavía guardando: se queda en pantalla hasta que haya
  // respuesta. Sin esto, "Guardado" aparecía antes de que el servidor supiera
  // nada del cambio.
  function showSaved(text, isError, pending) {
    savedPill.textContent = text;
    savedPill.classList.toggle('is-error', !!isError);
    savedPill.classList.toggle('is-pending', !!pending);
    savedPill.hidden = false;
    clearTimeout(savedTimer);
    if (pending) return;
    savedTimer = setTimeout(function () { savedPill.hidden = true; }, isError ? 5000 : 2200);
  }

  // STUDIO-UX F4 — Los retoques seguidos no salen a la red uno a uno: se
  // agrupan y se manda el último estado. El servidor, además, los funde en una
  // sola versión (CanvasService::coalescableVersionId), así que una ráfaga de
  // clics deja UN paso de "deshacer" en vez de cinco.
  var SAVE_DEBOUNCE_MS = 700;
  var pendingSave = null;      // { sectionId, html }
  var pendingTimer = null;
  var saveInFlight = 0;

  function queueSectionSave(sectionId, html) {
    // Si cambia la sección, lo pendiente sale YA: mezclar dos secciones en un
    // envío perdería la primera.
    if (pendingSave && pendingSave.sectionId !== sectionId) flushSectionSave();
    pendingSave = { sectionId: sectionId, html: html };
    showSaved(pp.t('js.cv.saving'), false, true);
    clearTimeout(pendingTimer);
    pendingTimer = setTimeout(flushSectionSave, SAVE_DEBOUNCE_MS);
  }

  function flushSectionSave(useKeepalive) {
    clearTimeout(pendingTimer);
    pendingTimer = null;
    if (!pendingSave) return null;
    var job = pendingSave;
    pendingSave = null;
    return sendSectionSave(job.sectionId, job.html, useKeepalive);
  }

  function sendSectionSave(sectionId, html, useKeepalive) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('section', sectionId);
    fd.append('html', html);
    saveInFlight++;
    var opts = { method: 'POST', body: fd };
    if (useKeepalive) opts.keepalive = true;   // la pestaña se está cerrando
    return fetch(body.dataset.sectionUrl, opts)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          showSaved(pp.t('js.cv.saved'));
          applyHistory(data.history);
        } else {
          showSaved(pp.t('js.cv.save_failed'), true);
          reloadPreview(); // volver al estado real persistido
        }
      })
      .catch(function () { showSaved(pp.t('js.cv.offline_not_saved'), true); reloadPreview(); })
      .finally(function () { saveInFlight--; });
  }

  // Compatibilidad: el iframe sigue mandando 'section-changed' por cambio.
  function saveSectionInline(sectionId, html) { queueSectionSave(sectionId, html); }

  // Cerrar la pestaña con algo pendiente no puede perderlo.
  window.addEventListener('beforeunload', function (e) {
    if (!pendingSave && saveInFlight === 0) return;
    flushSectionSave(true);
    if (saveInFlight > 0) { e.preventDefault(); e.returnValue = ''; }
  });

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
    showSaved(pp.t('js.cv.gallery_updated'));
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
      mediaHint.textContent = pp.t('js.cv.unsplash_hint');
      mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.cv.search_prompt') + '</p>';
      if (mediaSearchInput) mediaSearchInput.focus();
    } else {
      mediaHint.textContent = galleryMode
        ? pp.t('js.cv.gallery_pick_hint')
        : pp.t('cv.media_hint_js');
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
      bar.innerHTML = '<span>' + pp.t('js.canvas.gallery_hint') + '</span><button type="button" disabled>' + pp.t('js.canvas.gallery_pick') + '</button>';
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
      .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.media.load_error') + '</p>'; });
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
      mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.cv.library_empty') + '</p>';
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
        .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.canvas.upload_error') + '</p>'; })
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
        .catch(function () { mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.canvas.search_error') + '</p>'; });
    });
  }

  function renderUnsplash(items, query) {
    if (!items.length) {
      mediaGrid.innerHTML = '<p class="pp-chat-hint">' + pp.t('js.canvas.no_results', { query: esc(query) }) + '</p>';
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
        if (!data.ok || !data.media) { showSaved(data.error || pp.t('js.cv.add_image_failed'), true); if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); } return; }
        mediaCache = null;
        useMedia(data.media);
      })
      .catch(function () { showSaved(pp.t('js.cv.add_image_failed'), true); if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); } });
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
          historyList.innerHTML = '<li class="pp-chat-hint">' + pp.t('js.cv.no_versions') + '</li>';
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
              ? '<em>' + pp.t('js.cv.you_are_here') + '</em>'
              : '<button type="button" class="cvstudio-ghost-btn" data-version="' + v.id + '">' + pp.t('js.cv.see_version') + '</button>');
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
  // STUDIO-UX C4 — Tres estados, no dos: borrador, publicada al día, y
  // publicada CON cambios sin publicar. El tercero es el que antes no existía:
  // se editaba en vivo y el chip verde decía que todo estaba bien.
  var hasUnpublished = body.dataset.hasUnpublished === '1';

  function reflectPublished(publishing) {
    body.dataset.published = publishing ? '1' : '0';
    var pending = publishing && hasUnpublished;
    // Borrador → "Publicar". Publicada con cambios → "Publicar cambios".
    // Publicada y al día → solo el menú discreto "⋯".
    publishBtn.hidden = publishing && !pending;
    publishBtn.textContent = pending ? pp.t('js.cv.publish_changes') : pp.t('js.cv.publish');
    moreWrap.hidden = !publishing;
    if (!publishing) closeMoreMenu();
    statusEl.textContent = pending
      ? pp.t('js.cv.unpublished_changes')
      : pp.t(publishing ? 'js.cv.status_published' : 'js.cv.status_draft');
    statusEl.classList.toggle('is-live', publishing && !pending);
    statusEl.classList.toggle('is-pending', pending);
    // "Ver página": URL pública si está publicada; preview limpio si es borrador.
    var vlink = document.getElementById('studio-view-link');
    if (vlink) {
      vlink.href = publishing ? body.dataset.publicUrl : body.dataset.cleanPreviewUrl;
      var vt = pp.t(publishing ? 'cv.view_on_site_js' : 'cv.preview_draft_js');
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
        hasUnpublished = !!(data.history && data.history.has_unpublished);
        reflectPublished(publishing);
        addMsg('assistant', publishing
          ? pp.t('js.cv.now_published.html', {
              enlace: '<a href="' + body.dataset.publicUrl + '" target="_blank" rel="noopener">' + pp.t('js.cv.see_it') + '</a>'
            })
          : pp.t('js.cv.now_draft'));
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

  // C4 — el estado inicial lo pinta el JS, no la vista: así los tres estados
  // (borrador / publicada / publicada con cambios) viven en un solo sitio.
  reflectPublished(body.dataset.published === '1');

  function appendRequestedPlacement(fd) {
    if (insertPlacement) {
      if (insertPlacement.anchor) fd.append('section', insertPlacement.anchor);
      fd.append('position', insertPlacement.position);
      return;
    }
    if (selectedSection) {
      fd.append('section', selectedSection);
      fd.append('position', 'after');
    }
  }

  // ----------------------------------------------------------------
  // STUDIO-STRUCTURE S4 — un selector único para contenido y bloques reales.
  // Los subselectores permanecen dentro del mismo panel para no perder la
  // posición espacial que el usuario acaba de elegir.
  // ----------------------------------------------------------------
  var blockPicker = document.getElementById('studio-block-picker');
  var blockPickerBtn = document.getElementById('studio-block-picker-btn');
  var blockPickerMenu = document.getElementById('studio-block-picker-menu');

  function closeBlockPicker() {
    if (!blockPickerMenu || !blockPickerBtn) return;
    blockPickerMenu.hidden = true;
    blockPickerBtn.setAttribute('aria-expanded', 'false');
    blockPickerMenu.querySelectorAll('.cvstudio-insert__pop').forEach(function (menu) { menu.hidden = true; });
    blockPickerMenu.querySelectorAll('.cvstudio-insert__btn[aria-expanded]').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }

  if (blockPicker && blockPickerBtn && blockPickerMenu) {
    blockPickerBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = blockPickerMenu.hidden;
      if (!open) {
        closeBlockPicker();
        return;
      }
      blockPickerMenu.hidden = false;
      blockPickerBtn.setAttribute('aria-expanded', 'true');
      var first = blockPickerMenu.querySelector('[data-section-template], .cvstudio-insert__btn:not(:disabled)');
      if (first) setTimeout(function () { first.focus(); }, 0);
    });
    blockPickerMenu.addEventListener('click', function (e) {
      var trigger = e.target.closest('.cvstudio-insert__btn');
      if (!trigger) return;
      blockPickerMenu.querySelectorAll('.cvstudio-insert').forEach(function (wrap) {
        if (wrap.contains(trigger)) return;
        var menu = wrap.querySelector('.cvstudio-insert__pop');
        var button = wrap.querySelector('.cvstudio-insert__btn');
        if (menu) menu.hidden = true;
        if (button) button.setAttribute('aria-expanded', 'false');
      });
    }, true);
    document.addEventListener('click', function (e) {
      if (!blockPickerMenu.hidden && !blockPicker.contains(e.target)) closeBlockPicker();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || blockPickerMenu.hidden) return;
      closeBlockPicker();
      blockPickerBtn.focus();
    });

    blockPickerMenu.querySelectorAll('button[data-section-template]').forEach(function (item) {
      item.addEventListener('click', function () {
        if (structureBusy || busy) return;
        structureBusy = true;
        item.disabled = true;
        showStructureStatus(pp.t('js.cv.inserting_template'), 'loading');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('action', 'insert_template');
        fd.append('template', item.dataset.sectionTemplate || '');
        appendRequestedPlacement(fd);
        // Una página vacía no tiene ancla, pero sí un extremo inequívoco.
        if (!fd.has('position')) fd.append('position', 'after');
        fetch(body.dataset.structureUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (data) {
            if (!data.ok) { showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error'); return; }
            finishFunctionalInsert(data);
          })
          .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
          .finally(function () {
            structureBusy = false;
            if (item.isConnected) item.disabled = false;
          });
      });
    });
  }

  function finishFunctionalInsert(data) {
    applyHistory(data.history);
    selectedSection = String(data.changed_section || '');
    pendingStructureFocus = selectedSection;
    pendingFlash = selectedSection;
    insertPlacement = null;
    updateInsertionHint();
    showStructureStatus(data.reply || pp.t('js.cv.block_inserted'), 'success');
    renderSectionList(data.sections || []);
    closeBlockPicker();
    reloadPreview();
  }

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
        if (structureBusy || busy) return;
        var formId = item.dataset.formId;
        var template = item.dataset.formTemplate;
        closeInsertMenu();
        structureBusy = true;
        item.disabled = true;
        showStructureStatus(pp.t('js.cv.inserting_form'), 'loading');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        if (formId) fd.append('form_id', formId);
        if (template) fd.append('template', template);
        appendRequestedPlacement(fd);
        var source = document.getElementById('studio-form-source');
        if (source && source.value.trim()) fd.append('source_label', source.value.trim());
        fetch(body.dataset.insertFormUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (data) {
            if (!data.ok) { showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error'); return; }
            finishFunctionalInsert(data);
            if (source) source.value = '';
          })
          .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
          .finally(function () { structureBusy = false; if (item.isConnected) item.disabled = false; });
      });
    }
    insertMenu.querySelectorAll('button[data-form-id],button[data-form-template]').forEach(insertFormItem);
  }

  // ----------------------------------------------------------------
  // MODULOS M2 — Insertar el calendario de reservas en el punto activo.
  // Mismo gesto que el formulario: un botón, elegir servicio por su nombre, y
  // el resultado se cuenta en la conversación.
  // ----------------------------------------------------------------
  var bkWrap = document.getElementById('studio-insert-booking');
  if (bkWrap) {
    var bkBtn = document.getElementById('studio-insert-booking-btn');
    var bkMenu = document.getElementById('studio-insert-booking-menu');
    function closeBkMenu() { bkMenu.hidden = true; bkBtn.setAttribute('aria-expanded', 'false'); }
    bkBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = bkMenu.hidden;
      bkMenu.hidden = !open;
      bkBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!bkMenu.hidden && !bkWrap.contains(e.target)) closeBkMenu();
    });
    bkMenu.querySelectorAll('button[data-booking-service]').forEach(function (item) {
      item.addEventListener('click', function () {
        if (structureBusy || busy) return;
        closeBkMenu();
        structureBusy = true;
        item.disabled = true;
        showStructureStatus(pp.t('js.cv.inserting_booking'), 'loading');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('service_id', item.dataset.bookingService || 'auto');
        appendRequestedPlacement(fd);
        fetch(body.dataset.insertBookingUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (data) {
            if (!data.ok) { showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error'); return; }
            finishFunctionalInsert(data);
          })
          .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
          .finally(function () { structureBusy = false; if (item.isConnected) item.disabled = false; });
      });
    });
  }

  // R6 — inserta recursos reales, no tarjetas copiadas que puedan quedar obsoletas.
  var rsWrap = document.getElementById('studio-insert-resources');
  if (rsWrap) {
    var rsBtn = document.getElementById('studio-insert-resources-btn');
    var rsMenu = document.getElementById('studio-insert-resources-menu');
    function closeRsMenu() { rsMenu.hidden = true; rsBtn.setAttribute('aria-expanded', 'false'); }
    rsBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = rsMenu.hidden;
      rsMenu.hidden = !open;
      rsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!rsMenu.hidden && !rsWrap.contains(e.target)) closeRsMenu();
    });
    rsMenu.querySelectorAll('button[data-resources-limit]').forEach(function (item) {
      item.addEventListener('click', function () {
        if (structureBusy || busy) return;
        closeRsMenu();
        structureBusy = true;
        item.disabled = true;
        showStructureStatus(pp.t('js.cv.inserting_resources'), 'loading');
        var fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('limit', item.dataset.resourcesLimit || '3');
        appendRequestedPlacement(fd);
        fetch(body.dataset.insertResourcesUrl, { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (data) {
            if (!data.ok) { showStructureStatus(data.error || pp.t('js.cv.structure_error'), 'error'); return; }
            finishFunctionalInsert(data);
          })
          .catch(function () { showStructureStatus(pp.t('js.cv.structure_error'), 'error'); })
          .finally(function () { structureBusy = false; if (item.isConnected) item.disabled = false; });
      });
    });
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
        .catch(function () { setMsg(pp.t('js.cv.offline_not_saved'), true); })
        .finally(function () { setSave.disabled = false; });
    });

    // Sugerir con IA por campo — reutiliza el endpoint genérico de acciones.
    // Cada chip pide la propuesta SEO completa pero aplica SOLO su campo, así
    // el usuario puede rehacer únicamente el título, la descripción o la URL.
    var AI_FIELDS = {
      meta_title:       { key: 'seo_title',        target: fTitle, label: pp.t('js.cv.the_title') },
      meta_description: { key: 'meta_description', target: fDesc,  label: pp.t('js.cv.the_desc') },
      slug:             { key: 'slug',             target: fSlug,  label: pp.t('js.cv.the_url') }
    };

    setModal.querySelectorAll('[data-ai-field]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var field = chip.getAttribute('data-ai-field');
        var spec = AI_FIELDS[field];
        if (!spec || !spec.target) return;
        chip.disabled = true;
        chip.classList.add('is-busy');
        setMsg(pp.t('js.cv.ai_suggesting', { campo: spec.label }));
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
            if (!value) { setMsg(pp.t('js.cv.ai_no_result', { campo: spec.label }), true); return; }
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
