<?php
/**
 * F21.T21.2 — Editor dedicado de entrada.
 * Gestiona `article_body.blocks` con interacción JS en `post-editor.js`.
 *
 * @var array  $page       row de pages
 * @var array  $meta       row de post_meta
 * @var array  $blocks     bloques actuales del article_body
 * @var int    $sectionId
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$flashSuccess = \Core\Session::flash('success');
$isPublished  = ($page['status'] ?? '') === 'published';
$publicUrl    = base_url('/' . ltrim((string) $page['slug'], '/'));
$isLegalPage  = ($page['page_type'] ?? '') === 'legal';
$backUrl      = $isLegalPage ? base_url('admin/privacy?tab=pages') : base_url('admin/posts');
$backLabel    = $isLegalPage ? 'Volver a Privacidad' : 'Volver al listado';
?>

<?php \Core\View::start('title'); ?>Editar entrada · <?= e((string) $page['title']) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-editor-mode pp-post-editor-mode<?php \Core\View::end(); ?>

<!-- Barra de editor (sticky) — acciones globales del post -->
<header class="pp-post-editor__bar">
    <div class="pp-post-editor__bar-left">
        <a href="<?= e($backUrl) ?>" class="pp-post-editor__back" aria-label="<?= e($backLabel) ?>" title="<?= e($backLabel) ?>">←</a>
        <div class="pp-post-editor__title-wrap">
            <span class="pp-post-editor__status pp-post-editor__status--<?= $isPublished ? 'published' : 'draft' ?>" data-status-pill>
                <?= $isPublished ? 'Publicada' : 'Borrador' ?>
            </span>
            <h1 class="pp-post-editor__title-display" data-title-display><?= e((string) $page['title']) ?></h1>
        </div>
    </div>
    <div class="pp-post-editor__bar-right">
        <span class="pp-post-editor__save-state" data-save-state aria-live="polite"></span>
        <?php if ($isPublished): ?>
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="pp-btn pp-btn--secondary">Ver en sitio ↗</a>
            <button type="button" class="pp-btn pp-btn--secondary" data-action="unpublish">Despublicar</button>
        <?php else: ?>
            <button type="button" class="pp-btn pp-btn--primary" data-action="publish">Publicar</button>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess): ?><div class="pp-alert pp-alert--success" style="margin-top:18px;"><?= e($flashSuccess) ?></div><?php endif; ?>

<div class="pp-post-editor"
     data-post-id="<?= (int) $page['id'] ?>"
     data-csrf="<?= e($csrf) ?>"
     data-base-url="<?= e(base_url('')) ?>"
     data-section-id="<?= (int) $sectionId ?>">

    <!-- COLUMNA PRINCIPAL: cuerpo editorial -->
    <main class="pp-post-editor__body">
        <!-- Título editable inline -->
        <div class="pp-post-editor__title-block">
            <label class="pp-vh" for="pp-post-title">Título de la entrada</label>
            <input id="pp-post-title" type="text"
                   class="pp-post-editor__title-input"
                   data-field="title"
                   value="<?= e((string) $page['title']) ?>"
                   placeholder="Título de la entrada"
                   maxlength="300"
                   autocomplete="off">
        </div>

        <!-- Lista de bloques editoriales -->
        <div class="pp-post-blocks" data-blocks data-initial='<?= e(json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
            <?php if (empty($blocks)): ?>
                <div class="pp-post-blocks__empty" data-empty>
                    <p>Empieza escribiendo tu primer párrafo.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Toolbar para añadir bloques (sticky abajo) -->
        <div class="pp-post-toolbar" data-toolbar>
            <span class="pp-post-toolbar__label">Añadir bloque:</span>
            <button type="button" data-add="paragraph">¶ Párrafo</button>
            <button type="button" data-add="heading-2">H2</button>
            <button type="button" data-add="heading-3">H3</button>
            <button type="button" data-add="image">Imagen</button>
            <button type="button" data-add="list">Lista</button>
            <button type="button" data-add="quote">Cita</button>
            <button type="button" data-add="divider">— Divisor</button>
        </div>
    </main>

    <!-- COLUMNA LATERAL: metadatos -->
    <aside class="pp-post-editor__sidebar" id="pp-post-meta"
           data-post-id="<?= (int) $page['id'] ?>"
           data-csrf="<?= e($csrf) ?>"
           data-base-url="<?= e(base_url('')) ?>">
        <header class="pp-post-editor__sidebar-head">
            <span class="pp-posts-header__eyebrow">Metadatos</span>
            <h3>Sobre la entrada</h3>
        </header>

        <div class="pp-post-meta__featured">
            <span class="pp-post-meta__label">Imagen destacada</span>
            <?php $img = (string) ($meta['featured_image_path'] ?? ''); ?>
            <div class="pp-post-meta__featured-slot <?= $img ? 'has-image' : '' ?>" data-image-slot>
                <?php if ($img): ?>
                    <img data-image-preview src="<?= e(base_url(ltrim($img, '/'))) ?>" alt="<?= e((string) ($meta['featured_image_alt'] ?? '')) ?>">
                <?php else: ?>
                    <img data-image-preview hidden alt="">
                <?php endif; ?>
                <div class="pp-post-meta__featured-empty" data-image-empty <?= $img ? 'hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span>Aún sin imagen</span>
                </div>
            </div>
            <div class="pp-post-meta__featured-actions">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="pick">
                    <?= $img ? 'Cambiar…' : 'Elegir…' ?>
                </button>
                <?php if (\App\Services\ImageBankService::isAvailable()): ?>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="search-bank" title="Buscar en Unsplash">
                    Unsplash
                </button>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="suggest-auto" title="Buscar automáticamente según el contenido">
                    ✨ Auto
                </button>
                <?php endif; ?>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="clear" <?= $img ? '' : 'hidden' ?>>Quitar</button>
            </div>
            <input type="hidden" data-image-path value="<?= e($img) ?>">
            <input type="hidden" data-image-alt-store value="<?= e((string) ($meta['featured_image_alt'] ?? '')) ?>">
        </div>

        <div class="pp-form-group">
            <label class="pp-form-label" for="pp-post-meta-excerpt">Resumen</label>
            <textarea id="pp-post-meta-excerpt" class="pp-input" rows="3" maxlength="500" placeholder="Frase o dos que enganchen." data-field="excerpt"><?= e((string) ($meta['excerpt'] ?? '')) ?></textarea>
            <small class="pp-form-hint"><span data-excerpt-count>0</span> / 500 · aparece en la entrada y en los listados del blog</small>
        </div>

        <div class="pp-post-seo-box" id="pp-post-seo">
            <div class="pp-post-seo-box__head">
                <span>SEO</span>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="copy-excerpt-to-meta">Usar resumen</button>
            </div>
            <div class="pp-form-group">
                <label class="pp-form-label" for="pp-post-meta-description">Meta descripción</label>
                <textarea id="pp-post-meta-description" class="pp-input" rows="3" maxlength="500" placeholder="Texto que aparecerá como descripción en Google." data-field="meta_description"><?= e((string) ($page['meta_description'] ?? '')) ?></textarea>
                <small class="pp-form-hint"><span data-meta-description-count>0</span> / 500 · recomendado 120-160. Si la dejas vacía, se usará el resumen.</small>
            </div>
            <details class="pp-seo-advanced pp-seo-advanced--compact">
                <summary>Indexación avanzada</summary>
                <div class="pp-seo-advanced__body">
                    <label class="pp-checkline">
                        <input type="checkbox" data-field="seo_noindex" value="1" <?= (int) ($page['seo_noindex'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span>No mostrar esta entrada en buscadores</span>
                    </label>
                    <small>Útil para borradores publicados, contenido duplicado o artículos temporales.</small>
                    <label class="pp-checkline">
                        <input type="checkbox" data-field="seo_exclude_sitemap" value="1" <?= (int) ($page['seo_exclude_sitemap'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span>Excluir del sitemap</span>
                    </label>
                    <small>Normalmente conviene dejarlo desactivado.</small>
                    <div class="pp-form-group">
                        <label class="pp-form-label" for="pp-post-canonical-url">Canonical personalizada</label>
                        <input id="pp-post-canonical-url" type="url" class="pp-input" maxlength="500" placeholder="https://tudominio.com/articulo-principal" data-field="canonical_url" value="<?= e((string) ($page['canonical_url'] ?? '')) ?>">
                        <small class="pp-form-hint">Solo si esta entrada duplica otra URL principal.</small>
                    </div>
                </div>
            </details>
        </div>

        <div class="pp-form-group">
            <label class="pp-form-label" for="pp-post-meta-alt">Alt de la imagen</label>
            <input id="pp-post-meta-alt" type="text" class="pp-input" maxlength="255" placeholder="Para accesibilidad y SEO" data-field="featured_image_alt" value="<?= e((string) ($meta['featured_image_alt'] ?? '')) ?>">
        </div>

        <div class="pp-form-group">
            <label class="pp-form-label" for="pp-post-meta-author">Autor</label>
            <input id="pp-post-meta-author" type="text" class="pp-input" maxlength="120" placeholder="Tu nombre" data-field="author_name" value="<?= e((string) ($meta['author_name'] ?? '')) ?>">
        </div>

        <div class="pp-post-editor__meta-footer">
            <small data-reading-display>
                <?php $rm = (int) ($meta['reading_minutes'] ?? 0); ?>
                <?= $rm > 0 ? $rm . ' min de lectura' : 'Tiempo de lectura: aún sin calcular' ?>
            </small>
            <div class="pp-post-editor__meta-actions">
                <span data-status class="pp-post-meta__status" aria-live="polite"></span>
                <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-action="save">Guardar</button>
            </div>
        </div>
    </aside>
</div>

<script src="<?= e(base_url('admin/assets/js/unsplash-picker.js')) ?>"></script>
<script src="<?= e(base_url('admin/assets/js/post-editor.js')) ?>"></script>
<script>
// Reutilizamos exactamente la lógica AJAX del bloque de metadatos.
(function () {
    const root = document.getElementById('pp-post-meta');
    if (!root) return;
    const postId  = root.dataset.postId;
    const csrf    = root.dataset.csrf;
    const baseUrl = root.dataset.baseUrl.replace(/\/$/, '');

    const excerpt  = root.querySelector('[data-field="excerpt"]');
    const metaDescription = root.querySelector('[data-field="meta_description"]');
    const seoNoindex = root.querySelector('[data-field="seo_noindex"]');
    const seoExcludeSitemap = root.querySelector('[data-field="seo_exclude_sitemap"]');
    const canonicalUrl = root.querySelector('[data-field="canonical_url"]');
    const altInput = root.querySelector('[data-field="featured_image_alt"]');
    const author   = root.querySelector('[data-field="author_name"]');
    const imgPath  = root.querySelector('[data-image-path]');
    const imgAlt   = root.querySelector('[data-image-alt-store]');
    const preview  = root.querySelector('[data-image-preview]');
    const empty    = root.querySelector('[data-image-empty]');
    const slot     = root.querySelector('[data-image-slot]');
    const clearBtn = root.querySelector('[data-action="clear"]');
    const pickBtn  = root.querySelector('[data-action="pick"]');
    const saveBtn  = root.querySelector('[data-action="save"]');
    const status   = root.querySelector('[data-status]');
    const counter  = root.querySelector('[data-excerpt-count]');
    const metaCounter = root.querySelector('[data-meta-description-count]');
    const copyExcerptBtn = root.querySelector('[data-action="copy-excerpt-to-meta"]');
    const readingDisplay = root.querySelector('[data-reading-display]');

    function updateCount() { if (counter) counter.textContent = String((excerpt.value || '').length); }
    function updateMetaCount() { if (metaCounter) metaCounter.textContent = String((metaDescription.value || '').length); }
    excerpt.addEventListener('input', updateCount); updateCount();
    metaDescription.addEventListener('input', updateMetaCount); updateMetaCount();
    if (copyExcerptBtn) {
        copyExcerptBtn.addEventListener('click', () => {
            metaDescription.value = (excerpt.value || '').slice(0, 500);
            updateMetaCount();
            metaDescription.focus();
        });
    }
    altInput.addEventListener('input', () => { imgAlt.value = altInput.value; if (preview && !preview.hidden) preview.alt = altInput.value; });

    function setImage(path, alt) {
        imgPath.value = path || '';
        imgAlt.value = alt || imgAlt.value;
        if (path) {
            preview.src = baseUrl + '/' + path.replace(/^\//, '');
            preview.alt = imgAlt.value; preview.hidden = false;
            slot.classList.add('has-image'); empty.hidden = true; clearBtn.hidden = false;
            if (alt && !altInput.value) altInput.value = alt;
        } else {
            preview.removeAttribute('src'); preview.hidden = true;
            slot.classList.remove('has-image'); empty.hidden = false; clearBtn.hidden = true;
        }
    }
    clearBtn.addEventListener('click', () => setImage('', ''));
    pickBtn.addEventListener('click', () => {
        if (window.PPMediaPicker && typeof window.PPMediaPicker.open === 'function') {
            window.PPMediaPicker.open({ onSelect: (media) => setImage(media.path || media.url, media.alt_text || '') });
        } else {
            const url = prompt('Pega la URL/ruta de la imagen (puedes subirla antes en /admin/media):');
            if (url) setImage(url.trim(), '');
        }
    });

    // T21.4 — Buscar manualmente en Unsplash
    const bankBtn = root.querySelector('[data-action="search-bank"]');
    if (bankBtn) {
        bankBtn.addEventListener('click', () => {
            if (!window.PPUnsplashPicker) { alert('Widget Unsplash no cargado.'); return; }
            const titleField = document.querySelector('[data-field="title"]');
            const initial = (titleField ? titleField.value : '').trim() || (excerpt.value || '').trim();
            window.PPUnsplashPicker.open({
                query: initial.slice(0, 80),
                orientation: 'landscape',
                onSelect: (media) => {
                    setImage(media.path || media.url, media.alt_text || '');
                    status.textContent = '✓ Imagen importada';
                    status.className = 'pp-post-meta__status is-ok';
                    setTimeout(() => { status.textContent = ''; status.className = 'pp-post-meta__status'; }, 2200);
                },
            });
        });
    }

    // T21.4 — Sugerir automática (servidor compone la query)
    // Contador de intentos: cada click pide la siguiente imagen del search,
    // así pulsar Auto repetidamente cicla por los mejores resultados sin
    // devolver siempre la misma. Se resetea si el usuario elige imagen manual.
    const autoBtn = root.querySelector('[data-action="suggest-auto"]');
    let autoAttempt = 0;
    // Resetear contador cuando el usuario cambia/quita imagen manualmente.
    if (autoBtn) {
        const resetAttempt = () => { autoAttempt = 0; if (autoBtn) autoBtn.textContent = '✨ Auto'; };
        pickBtn.addEventListener('click', resetAttempt);
        clearBtn.addEventListener('click', resetAttempt);
        const bankBtnRef = root.querySelector('[data-action="search-bank"]');
        if (bankBtnRef) bankBtnRef.addEventListener('click', resetAttempt);

        autoBtn.addEventListener('click', () => {
            autoBtn.disabled = true;
            const prevText = autoBtn.textContent;
            autoBtn.textContent = 'Buscando…';
            status.textContent = autoAttempt === 0 ? 'Buscando imagen…' : 'Buscando otra opción…';
            status.className = 'pp-post-meta__status is-loading';
            const fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('attempt', String(autoAttempt));
            fetch(baseUrl + '/admin/posts/' + postId + '/featured/auto', {
                method: 'POST', credentials: 'same-origin', body: fd,
            })
                .then(r => r.json())
                .then(data => {
                    autoBtn.disabled = false;
                    if (!data.ok) {
                        autoBtn.textContent = prevText;
                        status.textContent = data.error || 'No encontrada'; status.className = 'pp-post-meta__status is-error';
                        return;
                    }
                    setImage(data.media.path, data.media.alt_text || '');
                    autoAttempt++;
                    autoBtn.textContent = '🔁 Otra';
                    status.textContent = '✓ Imagen ' + autoAttempt;
                    status.className = 'pp-post-meta__status is-ok';
                    setTimeout(() => { status.textContent = ''; status.className = 'pp-post-meta__status'; }, 2400);
                })
                .catch(err => {
                    autoBtn.disabled = false; autoBtn.textContent = prevText;
                    status.textContent = 'Error: ' + err.message; status.className = 'pp-post-meta__status is-error';
                });
        });
    }
    saveBtn.addEventListener('click', () => {
        status.textContent = 'Guardando…'; status.className = 'pp-post-meta__status is-loading'; saveBtn.disabled = true;
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('excerpt', excerpt.value || '');
        fd.append('meta_description', metaDescription.value || '');
        if (seoNoindex && seoNoindex.checked) fd.append('seo_noindex', '1');
        if (seoExcludeSitemap && seoExcludeSitemap.checked) fd.append('seo_exclude_sitemap', '1');
        if (canonicalUrl) fd.append('canonical_url', canonicalUrl.value || '');
        fd.append('featured_image_path', imgPath.value || '');
        fd.append('featured_image_alt', altInput.value || '');
        fd.append('author_name', author.value || '');
        fetch(baseUrl + '/admin/posts/' + postId + '/meta', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r => r.json())
            .then(data => {
                saveBtn.disabled = false;
                if (!data.ok) { status.textContent = data.error || 'No se pudo guardar.'; status.className = 'pp-post-meta__status is-error'; return; }
                status.textContent = '✓'; status.className = 'pp-post-meta__status is-ok';
                if (typeof data.reading_minutes === 'number' && readingDisplay) {
                    readingDisplay.textContent = data.reading_minutes + ' min de lectura';
                }
                setTimeout(() => { status.textContent = ''; status.className = 'pp-post-meta__status'; }, 2200);
            })
            .catch(err => { saveBtn.disabled = false; status.textContent = 'Error: ' + err.message; status.className = 'pp-post-meta__status is-error'; });
    });
})();
</script>
