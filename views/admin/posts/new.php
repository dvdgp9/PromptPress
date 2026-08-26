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
      <span class="pp-posts-header__eyebrow"><?= e(__('post_new.eyebrow')) ?></span>
      <h2 class="pp-posts-header__title"><?= e(__('post_new.title')) ?></h2>
      <p class="pp-posts-header__desc"><?= e(__('post_new.intro')) ?></p>
    </div>
    <div class="pp-posts-header__actions">
      <a href="<?= e(base_url('admin/posts')) ?>" class="pp-btn pp-btn--secondary">← <?= e(__('common.back')) ?></a>
    </div>
  </header>

  <?php if ($flashError): ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>

  <!-- ============ FASE 1 · IDEA ============ -->
  <section class="pp-ps-phase" data-phase="idea">
    <div class="pp-ps-hero">
      <label class="pp-ps-hero__label" for="ps-focus"><?= e(__('post_new.about_what')) ?></label>
      <textarea id="ps-focus" class="pp-ps-hero__input" rows="2" maxlength="240"
        placeholder="<?= e(__('post_new.focus_placeholder')) ?>"></textarea>
      <p class="pp-ps-hero__hint"><?= e(__('post_new.focus_hint')) ?></p>

      <div class="pp-ps-hero__row">
        <div class="pp-ps-field">
          <label for="ps-count"><?= e(__('post_new.how_many')) ?></label>
          <select id="ps-count" class="pp-input">
            <option value="3"><?= e(__('post_new.n_ideas', ['n' => 3])) ?></option>
            <option value="5" selected><?= e(__('post_new.n_ideas', ['n' => 5])) ?></option>
            <option value="6"><?= e(__('post_new.n_ideas', ['n' => 6])) ?></option>
            <option value="8"><?= e(__('post_new.n_ideas', ['n' => 8])) ?></option>
          </select>
        </div>
        <button type="button" class="pp-btn pp-btn--primary pp-ps-hero__cta" id="ps-suggest-btn">✨ <?= e(__('posts.suggest_ideas')) ?></button>
      </div>
      <div class="pp-ps-status" id="ps-idea-status" aria-live="polite" hidden></div>
    </div>

    <div class="pp-ps-secondary">
      <span class="pp-ps-secondary__label"><?= e(__('post_new.other_way')) ?></span>
      <button type="button" class="pp-ps-link" data-toggle-secondary="blank"><?= e(__('post_new.blank')) ?></button>
      <button type="button" class="pp-ps-link" data-toggle-secondary="doc"><?= e(__('post_new.from_docs')) ?></button>
    </div>

    <!-- Secundario: en blanco -->
    <form class="pp-ps-secform" id="ps-form-blank" method="POST" action="<?= e(base_url('admin/posts')) ?>" hidden>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="pp-form-group">
        <label for="ps-blank-title" class="pp-form-label"><?= e(__('post_edit.post_title')) ?> *</label>
        <input id="ps-blank-title" type="text" name="title" required maxlength="200" class="pp-input pp-input--xl" placeholder="<?= e(__('post_new.title_placeholder')) ?>" autocomplete="off">
      </div>
      <div class="pp-form-group">
        <label for="ps-blank-excerpt" class="pp-form-label"><?= e(__('post_new.excerpt_optional')) ?></label>
        <textarea id="ps-blank-excerpt" name="excerpt" rows="2" maxlength="155" class="pp-input" placeholder="<?= e(__('post_new.excerpt_placeholder')) ?>"></textarea>
      </div>
      <div class="pp-ps-secform__actions">
        <button type="button" class="pp-btn pp-btn--secondary" data-cancel-secondary><?= e(__('common.cancel')) ?></button>
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('post_new.create_and_write')) ?> →</button>
      </div>
    </form>

    <!-- Secundario: desde documentos -->
    <form class="pp-ps-secform" id="ps-form-doc" method="POST" action="<?= e(base_url('admin/posts/ai-create-from-document')) ?>" enctype="multipart/form-data" hidden>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="pp-form-group">
        <label for="ps-doc-files" class="pp-form-label"><?= e(__('post_new.ref_docs')) ?> *</label>
        <input id="ps-doc-files" type="file" name="documents[]" class="pp-input pp-input--xl" accept=".pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" multiple required>
        <small class="pp-form-help"><?= e(__('post_new.docs_help')) ?></small>
      </div>
      <div class="pp-form-group">
        <label for="ps-doc-angle" class="pp-form-label"><?= e(__('post_new.angle')) ?></label>
        <input id="ps-doc-angle" type="text" name="angle" maxlength="240" class="pp-input" placeholder="<?= e(__('post_new.angle_placeholder')) ?>">
      </div>
      <div class="pp-ps-status" data-doc-status aria-live="polite" hidden></div>
      <div class="pp-ps-secform__actions">
        <button type="button" class="pp-btn pp-btn--secondary" data-cancel-secondary><?= e(__('common.cancel')) ?></button>
        <button type="submit" class="pp-btn pp-btn--primary" data-doc-submit><?= e(__('post_new.from_docs')) ?></button>
      </div>
    </form>
  </section>

  <!-- ============ FASE 2 · SUGERENCIAS ============ -->
  <section class="pp-ps-phase" data-phase="suggest" hidden>
    <div class="pp-ps-subhead">
      <button type="button" class="pp-ps-link" id="ps-back-to-idea">← <?= e(__('post_new.change_topic')) ?></button>
      <h3 class="pp-ps-subhead__title"><?= e(__('post_new.pick_ideas')) ?></h3>
      <button type="button" class="pp-ps-link" id="ps-refresh-suggest">↻ <?= e(__('posts.other_ideas')) ?></button>
    </div>

    <div class="pp-ps-suggest-grid" id="ps-suggest-grid"></div>

    <div class="pp-ps-actionbar" id="ps-suggest-actionbar" hidden>
      <div class="pp-ps-actionbar__opts">
        <div class="pp-ps-field">
          <label for="ps-tone"><?= e(__('post_new.tone')) ?></label>
          <input id="ps-tone" type="text" class="pp-input" value="<?= e(__('post_new.tone_default')) ?>" maxlength="120">
        </div>
        <div class="pp-ps-field">
          <label for="ps-length"><?= e(__('post_new.length')) ?></label>
          <select id="ps-length" class="pp-input">
            <option value="corto"><?= e(__('post_new.short')) ?></option>
            <option value="medio" selected><?= e(__('post_new.medium')) ?></option>
            <option value="largo"><?= e(__('post_new.long')) ?></option>
          </select>
        </div>
      </div>
      <button type="button" class="pp-btn pp-btn--primary" id="ps-generate-btn" disabled><?= e(__('post_new.generate')) ?> <span data-sel-count>0</span> <?= e(__('nav.posts')) ?></button>
    </div>
  </section>

  <!-- ============ FASE 3/4 · GENERACIÓN + REVISIÓN ============ -->
  <section class="pp-ps-phase" data-phase="results" hidden>
    <div class="pp-ps-subhead">
      <h3 class="pp-ps-subhead__title" id="ps-results-title"><?= e(__('post_new.generating')) ?></h3>
      <div class="pp-ps-results-bar" id="ps-results-bar" hidden>
        <button type="button" class="pp-btn pp-btn--secondary" id="ps-publish-selected"><?= e(__('post_new.publish_selected')) ?></button>
        <button type="button" class="pp-ps-link" id="ps-generate-more"><?= e(__('post_new.more_ideas')) ?></button>
        <a href="<?= e(base_url('admin/posts')) ?>" class="pp-btn pp-btn--primary"><?= e(__('post_new.go_to_posts')) ?> →</a>
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
        status.hidden = false; status.className = 'pp-ps-status is-error'; status.textContent = pp.t('js.post_new.max_docs');
        return;
      }
      submit.disabled = true; submit.textContent = pp.t('js.post_new.generating');
      status.hidden = false; status.className = 'pp-ps-status is-loading'; status.textContent = pp.t('js.post_new.uploading_reading');
      const fd = new FormData(docForm);
      fetch(docForm.action, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) { submit.disabled = false; submit.textContent = pp.t('js.post_new.from_docs'); status.className = 'pp-ps-status is-error'; status.textContent = pp.t('js.bank.error', { error: d.error || pp.t('js.form_edit.generate_failed') }); return; }
          status.className = 'pp-ps-status is-ok'; status.textContent = pp.t('js.post_new.created_opening');
          setTimeout(() => { window.location = d.edit_url; }, 700);
        })
        .catch(err => { submit.disabled = false; submit.textContent = pp.t('js.post_new.from_docs'); status.className = 'pp-ps-status is-error'; status.textContent = pp.t('js.bank.connection_error', { error: err.message }); });
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
    ideaStatus.textContent = pp.t('js.post_new.thinking_ideas');
    post(base + '/admin/posts/ai-suggest-related', { count, focus })
      .then(d => {
        suggestBtn.disabled = false;
        if (!d.ok || !(d.suggestions || []).length) { ideaStatus.className = 'pp-ps-status is-error'; ideaStatus.textContent = pp.t('js.bank.error', { error: d.error || pp.t('js.posts.no_suggestions') }); return; }
        ideaStatus.hidden = true;
        suggestions = d.suggestions.map(s => ({ ...s, selected: false }));
        renderSuggestions();
        showPhase('suggest');
      })
      .catch(err => { suggestBtn.disabled = false; ideaStatus.className = 'pp-ps-status is-error'; ideaStatus.textContent = pp.t('js.bank.connection_error', { error: err.message }); });
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
        '<span class="pp-ps-review__state">' + pp.t('js.post_new.queued') + '</span></div>';
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
      '<div class="pp-ps-review__actions"><button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-retry>' + pp.t('js.media.retry') + '</button></div>';
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
        '<span class="pp-ps-review__badge" data-badge>' + pp.t('js.post_new.draft') + '</span></div>' +
        (p.excerpt ? '<p class="pp-ps-review__excerpt">' + esc(p.excerpt) + '</p>' : '') +
        '<p class="pp-ps-review__metaline">' + (p.reading_minutes ? p.reading_minutes + ' min · ' : '') + p.block_count + ' bloques</p>' +
        '<div class="pp-ps-review__actions">' +
          '<button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-read>' + pp.t('js.post_new.read') + '</button>' +
          '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-publish>' + pp.t('js.post_new.publish') + '</button>' +
          '<a class="pp-btn pp-btn--secondary pp-btn--sm" href="' + esc(p.edit_url) + '" target="_blank" rel="noopener">' + pp.t('js.post_new.edit') + '</a>' +
          '<button type="button" class="pp-btn pp-btn--ghost pp-btn--sm pp-ps-review__discard" data-discard>' + pp.t('js.post_new.discard') + '</button>' +
        '</div>' +
        '<div class="pp-ps-review__reader" hidden></div>' +
      '</div>';

    // Leer (iframe lazy)
    const readBtn = it.el.querySelector('[data-read]');
    const reader = it.el.querySelector('.pp-ps-review__reader');
    readBtn.addEventListener('click', () => {
      if (reader.hidden) {
        if (!reader.dataset.loaded) {
          reader.innerHTML = '<iframe src="' + esc(p.preview_url) + '" title="' + pp.t('js.post_new.preview') + '" loading="lazy"></iframe>';
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
      if (!confirm(pp.t('js.post_new.confirm_discard'))) return;
      post(base + '/admin/posts/' + p.page_id + '/delete', { ajax: '1' })
        .then(d => { if (d.ok) { it.discarded = true; it.el.remove(); } });
    });
  }

  function finishBatch(items) {
    const ok = items.filter(it => it.post).length;
    const fail = items.length - ok;
    resultsTitle.textContent = pp.t(ok === 1 ? 'js.post_new.generated_one' : 'js.post_new.generated_other', { n: ok })
      + (fail ? ' · ' + pp.t('js.post_new.with_errors', { n: fail }) : '') + '. ' + pp.t('js.post_new.review_hint');
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
