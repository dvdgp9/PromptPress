<?php
/**
 * FH11 — Estudio de entradas IA-first.
 * 4 fases: idea → sugerencias (multi) → generación en lote → revisión en línea.
 * Manual y desde-documentos quedan como opciones secundarias.
 *
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
$flashError = \Core\Session::flash('error');
?>

<?php \Core\View::start('title'); ?>Crear entradas<?php \Core\View::end(); ?>

<div class="pp-ps"
     data-base="<?= e(rtrim(base_url(''), '/')) ?>"
     data-csrf="<?= e($csrf) ?>"
     data-posts-url="<?= e(base_url('admin/posts')) ?>">

  <header class="pp-posts-header">
    <div class="pp-posts-header__intro">
      <span class="pp-posts-header__eyebrow">Crear entradas</span>
      <h2 class="pp-posts-header__title">Estudio de entradas</h2>
      <p class="pp-posts-header__desc">Cuéntale a la IA sobre qué quieres escribir y te propone varias entradas. Eliges las que te gusten y las genera todas de una vez.</p>
    </div>
    <div class="pp-posts-header__actions">
      <a href="<?= e(base_url('admin/posts')) ?>" class="pp-btn pp-btn--secondary">← Volver</a>
    </div>
  </header>

  <?php if ($flashError): ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>

  <!-- ============ FASE 1 · IDEA ============ -->
  <section class="pp-ps-phase" data-phase="idea">
    <div class="pp-ps-hero">
      <label class="pp-ps-hero__label" for="ps-focus">¿Sobre qué quieres escribir?</label>
      <textarea id="ps-focus" class="pp-ps-hero__input" rows="2" maxlength="240"
        placeholder="Ej. preparación de oposiciones de magisterio, errores comunes, novedades LOMLOE… (opcional)"></textarea>
      <p class="pp-ps-hero__hint">Déjalo vacío y la IA propondrá ideas a partir de la memoria de tu negocio y tus entradas anteriores.</p>

      <div class="pp-ps-hero__row">
        <div class="pp-ps-field">
          <label for="ps-count">Cuántas ideas</label>
          <select id="ps-count" class="pp-input">
            <option value="3">3 ideas</option>
            <option value="5" selected>5 ideas</option>
            <option value="6">6 ideas</option>
            <option value="8">8 ideas</option>
          </select>
        </div>
        <button type="button" class="pp-btn pp-btn--primary pp-ps-hero__cta" id="ps-suggest-btn">✨ Proponer ideas</button>
      </div>
      <div class="pp-ps-status" id="ps-idea-status" aria-live="polite" hidden></div>
    </div>

    <div class="pp-ps-secondary">
      <span class="pp-ps-secondary__label">¿Prefieres otra vía?</span>
      <button type="button" class="pp-ps-link" data-toggle-secondary="blank">Crear en blanco</button>
      <button type="button" class="pp-ps-link" data-toggle-secondary="doc">Generar desde documentos</button>
    </div>

    <!-- Secundario: en blanco -->
    <form class="pp-ps-secform" id="ps-form-blank" method="POST" action="<?= e(base_url('admin/posts')) ?>" hidden>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="pp-form-group">
        <label for="ps-blank-title" class="pp-form-label">Título de la entrada *</label>
        <input id="ps-blank-title" type="text" name="title" required maxlength="200" class="pp-input pp-input--xl" placeholder="Ej. Cómo digitalizar un restaurante familiar en 2026" autocomplete="off">
      </div>
      <div class="pp-form-group">
        <label for="ps-blank-excerpt" class="pp-form-label">Resumen (opcional)</label>
        <textarea id="ps-blank-excerpt" name="excerpt" rows="2" maxlength="155" class="pp-input" placeholder="2 frases que describan el ángulo. Si lo dejas vacío, lo generamos del contenido."></textarea>
      </div>
      <div class="pp-ps-secform__actions">
        <button type="button" class="pp-btn pp-btn--secondary" data-cancel-secondary>Cancelar</button>
        <button type="submit" class="pp-btn pp-btn--primary">Crear y empezar a escribir →</button>
      </div>
    </form>

    <!-- Secundario: desde documentos -->
    <form class="pp-ps-secform" id="ps-form-doc" method="POST" action="<?= e(base_url('admin/posts/ai-create-from-document')) ?>" enctype="multipart/form-data" hidden>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="pp-form-group">
        <label for="ps-doc-files" class="pp-form-label">Documentos de referencia *</label>
        <input id="ps-doc-files" type="file" name="documents[]" class="pp-input pp-input--xl" accept=".pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" multiple required>
        <small class="pp-form-help">Puedes subir hasta 5 archivos PDF, DOCX o TXT. Se guardarán en Documentos y se usarán como referencia para redactar la entrada.</small>
      </div>
      <div class="pp-form-group">
        <label for="ps-doc-angle" class="pp-form-label">Ángulo o enfoque (opcional)</label>
        <input id="ps-doc-angle" type="text" name="angle" maxlength="240" class="pp-input" placeholder="Ej. enfócate solo en las recomendaciones prácticas">
      </div>
      <div class="pp-ps-status" data-doc-status aria-live="polite" hidden></div>
      <div class="pp-ps-secform__actions">
        <button type="button" class="pp-btn pp-btn--secondary" data-cancel-secondary>Cancelar</button>
        <button type="submit" class="pp-btn pp-btn--primary" data-doc-submit>Generar desde documentos</button>
      </div>
    </form>
  </section>

  <!-- ============ FASE 2 · SUGERENCIAS ============ -->
  <section class="pp-ps-phase" data-phase="suggest" hidden>
    <div class="pp-ps-subhead">
      <button type="button" class="pp-ps-link" id="ps-back-to-idea">← Cambiar tema</button>
      <h3 class="pp-ps-subhead__title">Elige las que quieras crear</h3>
      <button type="button" class="pp-ps-link" id="ps-refresh-suggest">↻ Otras ideas</button>
    </div>

    <div class="pp-ps-suggest-grid" id="ps-suggest-grid"></div>

    <div class="pp-ps-actionbar" id="ps-suggest-actionbar" hidden>
      <div class="pp-ps-actionbar__opts">
        <div class="pp-ps-field">
          <label for="ps-tone">Tono</label>
          <input id="ps-tone" type="text" class="pp-input" value="profesional y cercano" maxlength="120">
        </div>
        <div class="pp-ps-field">
          <label for="ps-length">Longitud</label>
          <select id="ps-length" class="pp-input">
            <option value="corto">Corto</option>
            <option value="medio" selected>Medio</option>
            <option value="largo">Largo</option>
          </select>
        </div>
      </div>
      <button type="button" class="pp-btn pp-btn--primary" id="ps-generate-btn" disabled>Generar <span data-sel-count>0</span> entradas</button>
    </div>
  </section>

  <!-- ============ FASE 3/4 · GENERACIÓN + REVISIÓN ============ -->
  <section class="pp-ps-phase" data-phase="results" hidden>
    <div class="pp-ps-subhead">
      <h3 class="pp-ps-subhead__title" id="ps-results-title">Generando entradas…</h3>
      <div class="pp-ps-results-bar" id="ps-results-bar" hidden>
        <button type="button" class="pp-btn pp-btn--secondary" id="ps-publish-selected">Publicar seleccionadas</button>
        <button type="button" class="pp-ps-link" id="ps-generate-more">Generar más ideas</button>
        <a href="<?= e(base_url('admin/posts')) ?>" class="pp-btn pp-btn--primary">Ir a Entradas →</a>
      </div>
    </div>
    <div class="pp-ps-results" id="ps-results"></div>
  </section>
</div>

<script>
(function () {
  const root = document.querySelector('.pp-ps');
  const base = root.dataset.base;
  const csrf = root.dataset.csrf;

  const phases = {};
  document.querySelectorAll('.pp-ps-phase').forEach(p => phases[p.dataset.phase] = p);
  function showPhase(name) {
    Object.values(phases).forEach(p => p.hidden = true);
    phases[name].hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

  function post(url, data) {
    const fd = new FormData();
    fd.append('_csrf', csrf);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd }).then(r => r.json());
  }

  // ---------- Secundarios (manual / documento) ----------
  document.querySelectorAll('[data-toggle-secondary]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = 'ps-form-' + btn.dataset.toggleSecondary;
      document.querySelectorAll('.pp-ps-secform').forEach(f => { f.hidden = (f.id !== id) ? true : !f.hidden; });
    });
  });
  document.querySelectorAll('[data-cancel-secondary]').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.pp-ps-secform').hidden = true);
  });
  // Documentos → AJAX (redirige al editor al terminar)
  const docForm = document.getElementById('ps-form-doc');
  if (docForm) {
    docForm.addEventListener('submit', (e) => {
      const submit = docForm.querySelector('[data-doc-submit]');
      e.preventDefault();
      const status = docForm.querySelector('[data-doc-status]');
      const files = docForm.querySelector('#ps-doc-files')?.files || [];
      if (!files.length) {
        status.hidden = false; status.className = 'pp-ps-status is-error'; status.textContent = 'Sube al menos un documento de referencia.';
        return;
      }
      if (files.length > 5) {
        status.hidden = false; status.className = 'pp-ps-status is-error'; status.textContent = 'Puedes subir un máximo de 5 documentos.';
        return;
      }
      submit.disabled = true; submit.textContent = 'Generando...';
      status.hidden = false; status.className = 'pp-ps-status is-loading'; status.textContent = 'Subiendo, leyendo documentos y redactando. 30-120s aprox.';
      const fd = new FormData(docForm);
      fetch(docForm.action, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) { submit.disabled = false; submit.textContent = 'Generar desde documentos'; status.className = 'pp-ps-status is-error'; status.textContent = 'Error: ' + (d.error || 'No se pudo generar.'); return; }
          status.className = 'pp-ps-status is-ok'; status.textContent = 'Creada. Abriendo el editor...';
          setTimeout(() => { window.location = d.edit_url; }, 700);
        })
        .catch(err => { submit.disabled = false; submit.textContent = 'Generar desde documentos'; status.className = 'pp-ps-status is-error'; status.textContent = 'Error de conexión: ' + err.message; });
    });
  }

  // ---------- Fase 1 → 2: proponer ideas ----------
  let suggestions = []; // {title, angle, audience, why_now, selected}
  const suggestBtn = document.getElementById('ps-suggest-btn');
  const ideaStatus = document.getElementById('ps-idea-status');

  function fetchSuggestions() {
    const focus = document.getElementById('ps-focus').value.trim();
    const count = document.getElementById('ps-count').value;
    suggestBtn.disabled = true;
    ideaStatus.hidden = false; ideaStatus.className = 'pp-ps-status is-loading';
    ideaStatus.textContent = 'Pensando ideas para tu blog…';
    post(base + '/admin/posts/ai-suggest-related', { count, focus })
      .then(d => {
        suggestBtn.disabled = false;
        if (!d.ok || !(d.suggestions || []).length) { ideaStatus.className = 'pp-ps-status is-error'; ideaStatus.textContent = 'Error: ' + (d.error || 'No hubo sugerencias.'); return; }
        ideaStatus.hidden = true;
        suggestions = d.suggestions.map(s => ({ ...s, selected: false }));
        renderSuggestions();
        showPhase('suggest');
      })
      .catch(err => { suggestBtn.disabled = false; ideaStatus.className = 'pp-ps-status is-error'; ideaStatus.textContent = 'Error de conexión: ' + err.message; });
  }
  suggestBtn.addEventListener('click', fetchSuggestions);
  document.getElementById('ps-refresh-suggest').addEventListener('click', fetchSuggestions);
  document.getElementById('ps-back-to-idea').addEventListener('click', () => showPhase('idea'));

  const grid = document.getElementById('ps-suggest-grid');
  const selCountEls = document.querySelectorAll('[data-sel-count]');
  const generateBtn = document.getElementById('ps-generate-btn');
  const suggestActionbar = document.getElementById('ps-suggest-actionbar');

  function updateSelCount() {
    const n = suggestions.filter(s => s.selected).length;
    selCountEls.forEach(el => el.textContent = n);
    generateBtn.disabled = n === 0;
    suggestActionbar.hidden = suggestions.length === 0;
  }

  function renderSuggestions() {
    grid.innerHTML = '';
    suggestions.forEach((s, i) => {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'pp-ps-card';
      card.dataset.idx = i;
      card.innerHTML =
        '<span class="pp-ps-card__check" aria-hidden="true"></span>' +
        '<span class="pp-ps-card__title">' + esc(s.title) + '</span>' +
        (s.angle ? '<span class="pp-ps-card__angle">' + esc(s.angle) + '</span>' : '') +
        '<span class="pp-ps-card__meta">' +
          (s.audience ? '<span>👤 ' + esc(s.audience) + '</span>' : '') +
          (s.why_now ? '<span>💡 ' + esc(s.why_now) + '</span>' : '') +
        '</span>';
      card.addEventListener('click', () => {
        suggestions[i].selected = !suggestions[i].selected;
        card.classList.toggle('is-selected', suggestions[i].selected);
        updateSelCount();
      });
      grid.appendChild(card);
    });
    updateSelCount();
  }

  // ---------- Fase 3/4: generar en lote + revisión ----------
  const resultsEl = document.getElementById('ps-results');
  const resultsTitle = document.getElementById('ps-results-title');
  const resultsBar = document.getElementById('ps-results-bar');

  generateBtn.addEventListener('click', () => {
    const chosen = suggestions.filter(s => s.selected);
    if (!chosen.length) return;
    const tone = document.getElementById('ps-tone').value.trim() || 'profesional y cercano';
    const length = document.getElementById('ps-length').value;
    showPhase('results');
    resultsBar.hidden = true;
    resultsEl.innerHTML = '';
    const items = chosen.map((s, i) => {
      const el = document.createElement('div');
      el.className = 'pp-ps-review is-pending';
      el.innerHTML = '<div class="pp-ps-review__head"><span class="pp-ps-review__spin" aria-hidden="true"></span>' +
        '<strong class="pp-ps-review__title">' + esc(s.title) + '</strong>' +
        '<span class="pp-ps-review__state">En cola…</span></div>';
      resultsEl.appendChild(el);
      return { s, el, tone, length, done: false };
    });
    runQueue(items, 0);
  });

  function runQueue(items, i) {
    if (i >= items.length) { finishBatch(items); return; }
    const it = items[i];
    it.el.className = 'pp-ps-review is-generating';
    it.el.querySelector('.pp-ps-review__state').textContent = 'Generando…';
    resultsTitle.textContent = 'Generando entradas… (' + (i + 1) + '/' + items.length + ')';
    post(base + '/admin/posts/ai-create', {
      topic: it.s.title, audience: it.s.audience || '', tone: it.tone, length: it.length, details: it.s.angle || ''
    }).then(d => {
      if (!d.ok) { renderError(it, d.error || 'No se pudo generar.'); }
      else { it.post = d; renderReview(it); }
    }).catch(err => renderError(it, err.message))
      .finally(() => runQueue(items, i + 1));
  }

  function renderError(it, msg) {
    it.el.className = 'pp-ps-review is-error';
    it.el.innerHTML = '<div class="pp-ps-review__head"><strong class="pp-ps-review__title">' + esc(it.s.title) + '</strong>' +
      '<span class="pp-ps-review__state">⚠ ' + esc(msg) + '</span></div>' +
      '<div class="pp-ps-review__actions"><button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-retry>Reintentar</button></div>';
    it.el.querySelector('[data-retry]').addEventListener('click', () => {
      it.el.className = 'pp-ps-review is-generating';
      it.el.innerHTML = '<div class="pp-ps-review__head"><span class="pp-ps-review__spin"></span><strong class="pp-ps-review__title">' + esc(it.s.title) + '</strong><span class="pp-ps-review__state">Generando…</span></div>';
      post(base + '/admin/posts/ai-create', { topic: it.s.title, audience: it.s.audience || '', tone: it.tone, length: it.length, details: it.s.angle || '' })
        .then(d => { if (!d.ok) renderError(it, d.error || 'No se pudo generar.'); else { it.post = d; renderReview(it); } })
        .catch(err => renderError(it, err.message));
    });
  }

  function renderReview(it) {
    const p = it.post;
    it.published = false;
    it.el.className = 'pp-ps-review is-done';
    const cover = p.featured_image_path
      ? '<img class="pp-ps-review__cover" src="' + esc(p.featured_image_path) + '" alt="" loading="lazy">'
      : '<span class="pp-ps-review__cover pp-ps-review__cover--empty" aria-hidden="true"></span>';
    it.el.innerHTML =
      '<label class="pp-ps-review__pick"><input type="checkbox" checked data-pick></label>' +
      cover +
      '<div class="pp-ps-review__body">' +
        '<div class="pp-ps-review__head"><strong class="pp-ps-review__title">' + esc(p.title) + '</strong>' +
        '<span class="pp-ps-review__badge" data-badge>Borrador</span></div>' +
        (p.excerpt ? '<p class="pp-ps-review__excerpt">' + esc(p.excerpt) + '</p>' : '') +
        '<p class="pp-ps-review__metaline">' + (p.reading_minutes ? p.reading_minutes + ' min · ' : '') + p.block_count + ' bloques</p>' +
        '<div class="pp-ps-review__actions">' +
          '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-read>Leer</button>' +
          '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-publish>Publicar</button>' +
          '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + esc(p.edit_url) + '" target="_blank" rel="noopener">Editar</a>' +
          '<button type="button" class="pp-btn pp-btn--ghost pp-btn--sm pp-ps-review__discard" data-discard>Descartar</button>' +
        '</div>' +
        '<div class="pp-ps-review__reader" hidden></div>' +
      '</div>';

    // Leer (iframe lazy)
    const readBtn = it.el.querySelector('[data-read]');
    const reader = it.el.querySelector('.pp-ps-review__reader');
    readBtn.addEventListener('click', () => {
      if (reader.hidden) {
        if (!reader.dataset.loaded) {
          reader.innerHTML = '<iframe src="' + esc(p.preview_url) + '" title="Vista previa" loading="lazy"></iframe>';
          reader.dataset.loaded = '1';
        }
        reader.hidden = false; readBtn.textContent = 'Ocultar';
      } else { reader.hidden = true; readBtn.textContent = 'Leer'; }
    });

    // Publicar / despublicar
    const pubBtn = it.el.querySelector('[data-publish]');
    const badge = it.el.querySelector('[data-badge]');
    pubBtn.addEventListener('click', () => {
      const next = it.published ? 'draft' : 'published';
      pubBtn.disabled = true;
      post(base + '/admin/posts/' + p.page_id + '/status', { status: next })
        .then(d => { if (!d.ok) { alert(d.error || 'No se pudo cambiar el estado.'); return; }
          it.published = (next === 'published');
          badge.textContent = it.published ? 'Publicada' : 'Borrador';
          badge.classList.toggle('is-live', it.published);
          pubBtn.textContent = it.published ? 'Despublicar' : 'Publicar';
        })
        .finally(() => pubBtn.disabled = false);
    });

    // Descartar
    it.el.querySelector('[data-discard]').addEventListener('click', () => {
      if (!confirm('¿Descartar esta entrada? Se elimina el borrador.')) return;
      post(base + '/admin/posts/' + p.page_id + '/delete', { ajax: '1' })
        .then(d => { if (d.ok) { it.discarded = true; it.el.remove(); } });
    });
  }

  function finishBatch(items) {
    const ok = items.filter(it => it.post).length;
    const fail = items.length - ok;
    resultsTitle.textContent = ok + ' entrada' + (ok === 1 ? '' : 's') + ' generada' + (ok === 1 ? '' : 's')
      + (fail ? ' · ' + fail + ' con error' : '') + '. Revísalas y publica las que valgan.';
    resultsBar.hidden = false;
  }

  document.getElementById('ps-generate-more').addEventListener('click', () => showPhase('idea'));

  // Publicar seleccionadas (en lote)
  document.getElementById('ps-publish-selected').addEventListener('click', function () {
    const cards = [...resultsEl.querySelectorAll('.pp-ps-review.is-done')]
      .filter(el => el.querySelector('[data-pick]')?.checked && el.querySelector('[data-badge]')?.textContent === 'Borrador');
    if (!cards.length) { alert('No hay borradores seleccionados para publicar.'); return; }
    this.disabled = true; const orig = this.textContent; this.textContent = 'Publicando…';
    let i = 0;
    const next = () => {
      if (i >= cards.length) { this.disabled = false; this.textContent = orig; return; }
      const el = cards[i++]; el.querySelector('[data-publish]')?.click();
      setTimeout(next, 400);
    };
    next();
  });
})();
</script>
