<?php
/**
 * Formulario create/edit de una página.
 * @var string $mode     'create' | 'edit'
 * @var array  $page     datos actuales (o defaults)
 * @var array  $errors   errores por campo
 * @var array  $pageTypes
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$isEdit = ($mode === 'edit');
$actionUrl = $isEdit
    ? base_url('admin/pages/' . (int) $page['id'])
    : base_url('admin/pages');
$pageTitle = $isEdit ? __('page_form.title_edit') : __('page_form.title_new');
?>

<?php \Core\View::start('title'); ?><?= e($pageTitle) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-editor-mode<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/pages-form.js')) ?>"></script>
<?php if ($isEdit): ?>
<script>
window.PP_SECTION_SCHEMAS = <?= json_encode(
    // Copia traducida: el editor pinta estas etiquetas. `all()` se queda en
    // castellano porque es la fuente del prompt que describe los schemas a la IA.
    \App\Services\SectionSchemas::allForView(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
window.PP_PAGES = <?= json_encode(
    $pagesForLinks ?? [],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
</script>
<script src="<?= e(base_url('admin/assets/js/sections-editor.js')) ?>"></script>
<?php endif; ?>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e($pageTitle) ?></h2>
    <div class="pp-page-header__actions">
        <?php
        // E-GDPR G6 — pill contextual de privacidad si hay gaps.
        $complianceLevel = $compliance['level'] ?? 'green';
        if ($complianceLevel !== 'green'):
            $pillLabel = match ($complianceLevel) {
                'red'    => __('page_form.privacy_red'),
                'orange' => __('page_form.privacy_orange'),
                default  => __('page_form.privacy_yellow'),
            };
        ?>
        <a href="<?= e(base_url('admin/privacy')) ?>" class="pp-privacy-pill pp-privacy-pill--<?= e($complianceLevel) ?>" title="<?= e(__('page_form.privacy_title')) ?>">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="M12 8v4"/>
                <circle cx="12" cy="16" r="0.9" fill="currentColor"/>
            </svg>
            <span><?= e($pillLabel) ?></span>
        </a>
        <?php endif; ?>
        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-btn pp-btn--secondary">← <?= e(__('common.back')) ?></a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <strong><?= e(__('settings.errors_intro')) ?></strong>
    <ul style="margin: 8px 0 0 20px;">
        <?php foreach ($errors as $msg): ?>
        <li><?= e($msg) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="<?= e($actionUrl) ?>" class="pp-form" id="pp-page-form"
      data-csrf="<?= e($csrf) ?>"
      data-base-url="<?= e(base_url('')) ?>"
      novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <?php if (!$isEdit): ?>
    <div class="pp-ai-page-builder">
        <div class="pp-ai-page-builder__head">
            <div>
                <span><?= e(__('page_form.assisted')) ?></span>
                <h3><?= e(__('page_form.generate_title')) ?></h3>
                <p><?= e(__('page_form.generate_help')) ?></p>
            </div>
            <button type="button" class="pp-btn pp-btn--primary" id="pp-ai-create-page-btn">
                <?= e(__('page_form.generate_draft')) ?>
            </button>
        </div>

        <div class="pp-form-group">
            <label for="ai_page_goal"><?= e(__('page_form.goal')) ?></label>
            <textarea id="ai_page_goal" name="ai_page_goal" rows="3"
                      placeholder="<?= e(__('page_form.goal_placeholder')) ?>"></textarea>
            <small><?= e(__('page_form.goal_help')) ?></small>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group">
                <label for="ai_target_audience"><?= e(__('memory.field.target_audience.label')) ?></label>
                <input type="text" id="ai_target_audience" name="ai_target_audience"
                       placeholder="<?= e(__('page_form.audience_placeholder')) ?>">
            </div>
            <div class="pp-form-group">
                <label for="ai_extra_context"><?= e(__('page_form.details')) ?></label>
                <input type="text" id="ai_extra_context" name="ai_extra_context"
                       placeholder="<?= e(__('page_form.details_placeholder')) ?>">
            </div>
        </div>

        <?php
            $aiMain  = trim((string) ($aiMeta['model'] ?? ''));
            $aiLight = trim((string) ($aiMeta['model_light'] ?? ''));
        ?>
        <div class="pp-form-group">
            <label for="ai_model_choice"><?= e(__('page_form.ai_model')) ?></label>
            <select id="ai_model_choice" name="ai_model_choice">
                <option value="" selected><?= e(__('page_form.model_main')) ?><?= $aiMain !== '' ? ' · ' . e($aiMain) : '' ?> <?= e(__('page_form.model_recommended')) ?></option>
                <?php if ($aiLight !== '' && $aiLight !== $aiMain): ?>
                    <option value="<?= e($aiLight) ?>"><?= e(__('page_form.model_aux')) ?> · <?= e($aiLight) ?> <?= e(__('page_form.model_faster')) ?></option>
                <?php endif; ?>
                <option value="__custom__"><?= e(__('page_form.model_other')) ?></option>
            </select>
            <input type="text" id="ai_model_custom" name="ai_model_custom" hidden
                   placeholder="<?= e(__('page_form.model_placeholder')) ?>" maxlength="100" autocomplete="off">
            <small><?= e(__('page_form.model_help')) ?></small>
        </div>

        <div class="pp-ai-page-builder__status" id="pp-ai-create-page-status" hidden></div>
    </div>
    <?php endif; ?>

    <div class="pp-form-card">
        <h3><?= e(__('page_form.main_content')) ?></h3>

        <div class="pp-form-group <?= isset($errors['title']) ? 'has-error' : '' ?>">
            <label for="title"><?= e(__('table.title')) ?> <span class="pp-req">*</span></label>
            <input type="text" id="title" name="title"
                   value="<?= e($page['title'] ?? '') ?>"
                   maxlength="500" required autofocus>
            <?php if (isset($errors['title'])): ?>
            <small class="pp-err"><?= e($errors['title']) ?></small>
            <?php endif; ?>
        </div>

        <div class="pp-form-group <?= isset($errors['slug']) ? 'has-error' : '' ?>">
            <label for="slug"><?= e(__('page_form.slug')) ?> <span class="pp-req">*</span></label>
            <div class="pp-slug-input">
                <span class="pp-slug-input__prefix">/</span>
                <input type="text" id="slug" name="slug"
                       value="<?= e($page['slug'] ?? '') ?>"
                       pattern="[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*"
                       maxlength="500">
            </div>
            <small><?= e(__('page_form.slug_help')) ?></small>
            <?php if (isset($errors['slug'])): ?>
            <small class="pp-err"><?= e($errors['slug']) ?></small>
            <?php endif; ?>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($errors['page_type']) ? 'has-error' : '' ?>">
                <label for="page_type"><?= e(__('page_form.page_type')) ?></label>
                <select id="page_type" name="page_type">
                    <?php foreach ($pageTypes as $value => $label): ?>
                    <option value="<?= e($value) ?>"
                        <?= ($page['page_type'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['page_type'])): ?>
                <small class="pp-err"><?= e($errors['page_type']) ?></small>
                <?php endif; ?>
            </div>

            <?php if (!empty($isMultilingual)): ?>
            <div class="pp-form-group">
                <label for="language"><?= e(__('page_form.language')) ?></label>
                <?php $curLang = (string) ($page['language'] ?? $primaryLang); ?>
                <select id="language" name="language"<?= !empty($page['id']) ? ' disabled' : '' ?>>
                    <?php foreach ($siteLanguages as $code): ?>
                        <option value="<?= e($code) ?>" <?= $curLang === $code ? 'selected' : '' ?>>
                            <?= e($languageLabels[$code] ?? $code) ?><?= $code === $primaryLang ? ' ' . e(__('page_form.lang_primary')) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($page['id'])): ?>
                    <small><?= e(__('page_form.lang_locked')) ?></small>
                <?php else: ?>
                    <small><?= __('page_form.lang_prefix.html') ?></small>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="pp-form-group <?= isset($errors['status']) ? 'has-error' : '' ?>">
                <label for="status"><?= e(__('table.status')) ?></label>
                <select id="status" name="status">
                    <option value="draft"     <?= ($page['status'] ?? 'draft') === 'draft'     ? 'selected' : '' ?>><?= e(__('status.draft')) ?></option>
                    <option value="published" <?= ($page['status'] ?? '')      === 'published' ? 'selected' : '' ?>><?= e(__('status.published')) ?></option>
                </select>
                <?php if (isset($errors['status'])): ?>
                <small class="pp-err"><?= e($errors['status']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pp-form-card pp-seo-card">
        <div class="pp-form-card__head pp-seo-card__head">
            <div>
                <h3>SEO</h3>
                <p><?= e(__('page_form.seo_help')) ?></p>
            </div>
            <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-ai-seo-btn">
                Mejorar con IA
            </button>
        </div>

        <div class="pp-form-group <?= isset($errors['meta_title']) ? 'has-error' : '' ?>">
            <label for="meta_title"><?= e(__('page_form.meta_title')) ?></label>
            <input type="text" id="meta_title" name="meta_title"
                   value="<?= e($page['meta_title'] ?? '') ?>"
                   maxlength="255"
                   placeholder="<?= e(__('page_form.meta_title_placeholder')) ?>">
            <small><?= e(__('page_form.meta_title_help')) ?></small>
            <?php if (isset($errors['meta_title'])): ?>
            <small class="pp-err"><?= e($errors['meta_title']) ?></small>
            <?php endif; ?>
        </div>

        <div class="pp-form-group <?= isset($errors['meta_description']) ? 'has-error' : '' ?>">
            <label for="meta_description"><?= e(__('page_form.meta_desc')) ?></label>
            <textarea id="meta_description" name="meta_description"
                      maxlength="500" rows="3"
                      placeholder="<?= e(__('page_form.meta_desc_placeholder')) ?>"><?= e($page['meta_description'] ?? '') ?></textarea>
            <small><?= e(__('page_form.meta_desc_help')) ?></small>
            <?php if (isset($errors['meta_description'])): ?>
            <small class="pp-err"><?= e($errors['meta_description']) ?></small>
            <?php endif; ?>
        </div>

        <details class="pp-seo-advanced">
            <summary><?= e(__('page_form.advanced_index')) ?></summary>
            <div class="pp-seo-advanced__body">
                <label class="pp-checkline">
                    <input type="checkbox" name="seo_noindex" value="1" <?= (int) ($page['seo_noindex'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span><?= e(__('page_form.noindex')) ?></span>
                </label>
                <small><?= e(__('page_form.noindex_help')) ?></small>

                <label class="pp-checkline">
                    <input type="checkbox" name="seo_exclude_sitemap" value="1" <?= (int) ($page['seo_exclude_sitemap'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span><?= e(__('page_form.no_sitemap')) ?></span>
                </label>
                <small><?= e(__('page_form.no_sitemap_help')) ?></small>

                <div class="pp-form-group <?= isset($errors['canonical_url']) ? 'has-error' : '' ?>">
                    <label for="canonical_url"><?= e(__('page_form.canonical')) ?></label>
                    <input type="url" id="canonical_url" name="canonical_url"
                           value="<?= e((string) ($page['canonical_url'] ?? '')) ?>"
                           maxlength="500"
                           placeholder="https://tudominio.com/pagina-principal">
                    <small><?= e(__('page_form.canonical_help')) ?></small>
                    <?php if (isset($errors['canonical_url'])): ?>
                    <small class="pp-err"><?= e($errors['canonical_url']) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <div class="pp-ai-seo-panel" id="pp-ai-seo-panel" hidden aria-live="polite"></div>
    </div>

    <div class="pp-form-actions">
        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-btn pp-btn--secondary"><?= e(__('common.cancel')) ?></a>
        <button type="submit" class="pp-btn pp-btn--primary">
            <?= e($isEdit ? __('common.save_changes') : __('page_form.create_page')) ?>
        </button>
    </div>
</form>

<?php if ($isEdit && !empty($isArticle)): ?>
<!-- ============================================================
     F21.T21.1 — Metadatos de entrada (excerpt, featured image, autor).
     Solo visible cuando page_type='article'.
     ============================================================ -->
<section class="pp-post-meta" id="pp-post-meta"
         data-post-id="<?= (int) $page['id'] ?>"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>">
    <header class="pp-post-meta__head">
        <div>
            <span class="pp-post-meta__eyebrow"><?= e(__('js.onb.post')) ?></span>
            <h3 class="pp-post-meta__title"><?= e(__('post_meta.title')) ?></h3>
            <p class="pp-post-meta__desc"><?= e(__('post_meta.help')) ?></p>
        </div>
        <div class="pp-post-meta__status" data-status aria-live="polite"></div>
    </header>

    <div class="pp-post-meta__grid">
        <!-- Featured image -->
        <div class="pp-post-meta__featured">
            <span class="pp-post-meta__label"><?= e(__('post_meta.featured_image')) ?></span>
            <?php $img = (string) ($postMeta['featured_image_path'] ?? ''); ?>
            <div class="pp-post-meta__featured-slot <?= $img ? 'has-image' : '' ?>" data-image-slot>
                <?php if ($img): ?>
                    <img data-image-preview src="<?= e(base_url(ltrim($img, '/'))) ?>" alt="<?= e((string) ($postMeta['featured_image_alt'] ?? '')) ?>">
                <?php else: ?>
                    <img data-image-preview hidden alt="">
                <?php endif; ?>
                <div class="pp-post-meta__featured-empty" data-image-empty <?= $img ? 'hidden' : '' ?>>
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <span><?= e(__('post_meta.no_image')) ?></span>
                </div>
            </div>
            <div class="pp-post-meta__featured-actions">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="pick">
                    <?= e($img ? __('post_meta.change_image') : __('post_meta.pick_image')) ?>
                </button>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-action="clear" <?= $img ? '' : 'hidden' ?>><?= e(__('post_meta.remove')) ?></button>
            </div>
            <input type="hidden" data-image-path value="<?= e($img) ?>">
            <input type="hidden" data-image-alt-store value="<?= e((string) ($postMeta['featured_image_alt'] ?? '')) ?>">
        </div>

        <!-- Resumen + autor -->
        <div class="pp-post-meta__fields">
            <div class="pp-form-group">
                <label class="pp-form-label" for="pp-post-meta-excerpt"><?= e(__('documents.summary')) ?></label>
                <textarea id="pp-post-meta-excerpt" class="pp-input" rows="3" maxlength="500" placeholder="<?= e(__('post_meta.excerpt_placeholder')) ?>" data-field="excerpt"><?= e((string) ($postMeta['excerpt'] ?? '')) ?></textarea>
                <small class="pp-form-hint"><span data-excerpt-count>0</span> <?= __('post_meta.excerpt_hint.html') ?></small>
            </div>
            <div class="pp-form-group">
                <label class="pp-form-label" for="pp-post-meta-alt"><?= e(__('post_meta.alt')) ?></label>
                <input id="pp-post-meta-alt" type="text" class="pp-input" maxlength="255" placeholder="<?= e(__('post_meta.alt_placeholder')) ?>" data-field="featured_image_alt" value="<?= e((string) ($postMeta['featured_image_alt'] ?? '')) ?>">
            </div>
            <div class="pp-form-group">
                <label class="pp-form-label" for="pp-post-meta-author"><?= e(__('post_meta.author')) ?></label>
                <input id="pp-post-meta-author" type="text" class="pp-input" maxlength="120" placeholder="<?= e(__('post_meta.author_placeholder')) ?>" data-field="author_name" value="<?= e((string) ($postMeta['author_name'] ?? '')) ?>">
            </div>
            <div class="pp-post-meta__footer">
                <small class="pp-post-meta__reading">
                    <?php $rm = (int) ($postMeta['reading_minutes'] ?? 0); ?>
                    <span data-reading-display><?= e($rm > 0 ? __('post_meta.reading_time', ['n' => $rm]) : __('post_meta.reading_pending')) ?></span>
                </small>
                <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-action="save"><?= e(__('post_meta.save')) ?></button>
            </div>
        </div>
    </div>
</section>
<script>
(function () {
    const root = document.getElementById('pp-post-meta');
    if (!root) return;
    const postId  = root.dataset.postId;
    const csrf    = root.dataset.csrf;
    const baseUrl = root.dataset.baseUrl.replace(/\/$/, '');

    const excerpt  = root.querySelector('[data-field="excerpt"]');
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
    const readingDisplay = root.querySelector('[data-reading-display]');

    function updateCount() { if (counter) counter.textContent = String((excerpt.value || '').length); }
    excerpt.addEventListener('input', updateCount);
    updateCount();

    altInput.addEventListener('input', () => { imgAlt.value = altInput.value; if (preview && !preview.hidden) preview.alt = altInput.value; });

    function setImage(path, alt) {
        imgPath.value = path || '';
        imgAlt.value = alt || imgAlt.value;
        if (path) {
            preview.src = baseUrl + '/' + path.replace(/^\//, '');
            preview.alt = imgAlt.value;
            preview.hidden = false;
            slot.classList.add('has-image');
            empty.hidden = true;
            clearBtn.hidden = false;
            if (alt && !altInput.value) altInput.value = alt;
        } else {
            preview.removeAttribute('src');
            preview.hidden = true;
            slot.classList.remove('has-image');
            empty.hidden = false;
            clearBtn.hidden = true;
        }
    }

    clearBtn.addEventListener('click', () => setImage('', ''));

    // Usar el media-picker existente si está disponible. Si no, pedimos URL como fallback.
    pickBtn.addEventListener('click', () => {
        if (window.PPMediaPicker && typeof window.PPMediaPicker.open === 'function') {
            window.PPMediaPicker.open({
                onSelect: (media) => setImage(media.path || media.url, media.alt_text || ''),
            });
        } else {
            const url = prompt(pp.t('js.post.image_url_prompt'));
            if (url) setImage(url.trim(), '');
        }
    });

    saveBtn.addEventListener('click', () => {
        status.textContent = pp.t('js.saving');
        status.className = 'pp-post-meta__status is-loading';
        saveBtn.disabled = true;
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('excerpt', excerpt.value || '');
        fd.append('featured_image_path', imgPath.value || '');
        fd.append('featured_image_alt', altInput.value || '');
        fd.append('author_name', author.value || '');
        fetch(baseUrl + '/admin/posts/' + postId + '/meta', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r => r.json())
            .then(data => {
                saveBtn.disabled = false;
                if (!data.ok) { status.textContent = data.error || pp.t('js.post_meta.save_failed'); status.className = 'pp-post-meta__status is-error'; return; }
                status.textContent = '✓ ' + pp.t('js.saved');
                status.className = 'pp-post-meta__status is-ok';
                if (typeof data.reading_minutes === 'number' && readingDisplay) {
                    readingDisplay.textContent = pp.t('js.post_meta.reading_time', { n: data.reading_minutes });
                }
                setTimeout(() => { status.textContent = ''; status.className = 'pp-post-meta__status'; }, 2200);
            })
            .catch(err => { saveBtn.disabled = false; status.textContent = pp.t('js.bank.error', { error: err.message }); status.className = 'pp-post-meta__status is-error'; });
    });
})();
</script>
<style>
.pp-post-meta { margin: 0 0 28px; padding: 22px 24px 20px; background: linear-gradient(180deg, #fff 0%, #fafbfc 100%); border: 1px solid #e2e8f0; border-radius: 14px; }
.pp-post-meta__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.pp-post-meta__eyebrow { display: block; font-size: .68rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #6366f1; margin-bottom: 4px; }
.pp-post-meta__title { margin: 0 0 4px; font-size: 1.05rem; letter-spacing: -.01em; }
.pp-post-meta__desc { margin: 0; color: #64748b; font-size: .85rem; max-width: 60ch; line-height: 1.45; }
.pp-post-meta__status { font-size: .82rem; color: #64748b; padding: 4px 10px; border-radius: 6px; min-height: 22px; min-width: 60px; text-align: right; }
.pp-post-meta__status.is-loading { color: #6366f1; }
.pp-post-meta__status.is-ok { color: #047857; background: #ecfdf5; }
.pp-post-meta__status.is-error { color: #b91c1c; background: #fef2f2; }

.pp-post-meta__grid { display: grid; grid-template-columns: 280px 1fr; gap: 24px; align-items: flex-start; }
@media (max-width: 720px) { .pp-post-meta__grid { grid-template-columns: 1fr; } }

.pp-post-meta__label { display: block; font-size: .82rem; font-weight: 600; color: #1e293b; margin-bottom: 8px; }
.pp-post-meta__featured-slot { position: relative; aspect-ratio: 16/10; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; overflow: hidden; display: flex; align-items: center; justify-content: center; }
.pp-post-meta__featured-slot.has-image { border-style: solid; border-color: transparent; background: #0f172a; }
.pp-post-meta__featured-slot img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pp-post-meta__featured-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; color: #94a3b8; font-size: .82rem; padding: 12px; text-align: center; }
.pp-post-meta__featured-actions { margin-top: 10px; display: flex; gap: 8px; }
.pp-post-meta__featured-actions .pp-btn { flex: 1; }
.pp-post-meta__featured-actions .pp-btn[hidden] { display: none !important; }

.pp-post-meta__fields { display: flex; flex-direction: column; gap: 14px; }
.pp-post-meta__fields .pp-form-group { display: flex; flex-direction: column; gap: 5px; }
.pp-post-meta__fields .pp-form-label { font-size: .82rem; font-weight: 600; color: #1e293b; }
.pp-post-meta__fields .pp-form-hint { color: #94a3b8; font-size: .76rem; }
.pp-post-meta__fields .pp-form-hint em { font-style: normal; color: #6b7280; }
.pp-post-meta__fields .pp-input {
    width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;
    font: inherit; color: #0f172a; background: #fff;
    transition: border-color .12s ease, box-shadow .12s ease;
}
.pp-post-meta__fields .pp-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.16); }
.pp-post-meta__footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding-top: 8px; flex-wrap: wrap; }
.pp-post-meta__reading { color: #64748b; font-size: .82rem; }

.pp-btn--sm { padding: 6px 12px; font-size: .82rem; }
</style>
<?php endif; ?>

<?php if ($isEdit): ?>
<!-- ============================================================
     Editor de secciones (T3.2)
     ============================================================ -->
<section class="pp-sections-editor"
         data-page-id="<?= (int) $page['id'] ?>"
         data-page-title="<?= e((string) ($page['title'] ?? '')) ?>"
         data-page-goal="<?= e((string) ($page['meta_description'] ?? '')) ?>"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>">

    <div class="pp-section-header">
        <h3><?= e(__('page_form.sections')) ?></h3>
        <div class="pp-section-header__actions">
            <button type="button" id="pp-layout-variations-btn" class="pp-btn pp-btn--secondary">
                <?= e(__('page_form.try_variations')) ?>
            </button>
            <button type="button" id="pp-add-section-btn" class="pp-btn pp-btn--primary">
                <?= e(__('page_form.add_section')) ?>
            </button>
        </div>
    </div>

    <div class="pp-layout-variations" id="pp-layout-variations-panel" hidden>
        <div class="pp-layout-variations__status" aria-live="polite"></div>
        <div class="pp-layout-variations__list"></div>
    </div>

    <?php if (empty($sections)): ?>
    <div class="pp-empty pp-empty--inline" id="pp-no-sections">
        <div class="pp-empty__title"><?= e(__('page_form.no_sections')) ?></div>
        <div class="pp-empty__text"><?= e(__('page_form.no_sections_text')) ?></div>
    </div>
    <?php endif; ?>

    <?php
    // Iconos por tipo (mismo set que TYPE_ICONS_SVG en sections-editor.js).
    $ppTypeIcons = [
        'hero'          => '<rect x="3" y="5" width="18" height="9" rx="1.5"/><rect x="6.5" y="16.5" width="11" height="2.5" rx="1.2"/>',
        'text_image'    => '<rect x="3" y="5" width="8" height="14" rx="1.2"/><rect x="13" y="6" width="8" height="2" rx="1"/><rect x="13" y="10" width="8" height="2" rx="1"/><rect x="13" y="14" width="6" height="2" rx="1"/>',
        'benefits'      => '<rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="9.25" y="3" width="5.5" height="5.5" rx="1"/><rect x="15.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="9.25" width="5.5" height="5.5" rx="1"/><rect x="9.25" y="9.25" width="5.5" height="5.5" rx="1"/><rect x="15.5" y="9.25" width="5.5" height="5.5" rx="1"/>',
        'faq'           => '<rect x="3" y="5.5" width="18" height="2" rx="1"/><rect x="3" y="11" width="18" height="2" rx="1"/><rect x="3" y="16.5" width="13" height="2" rx="1"/>',
        'cta'           => '<rect x="2.5" y="6" width="19" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="8.5" y="11" width="7" height="2" rx="1"/>',
        'testimonials'  => '<path d="M5.5 13.5c0-2.6 1.6-4.5 4-4.5v1.8c-1.1 0-1.8 0.7-2 1.7h2v3.5H5.5v-2.5zm8 0c0-2.6 1.6-4.5 4-4.5v1.8c-1.1 0-1.8 0.7-2 1.7h2v3.5h-4v-2.5z"/>',
        'stats'         => '<rect x="3.5" y="14" width="3" height="6" rx=".5"/><rect x="10.5" y="9" width="3" height="11" rx=".5"/><rect x="17.5" y="4" width="3" height="16" rx=".5"/>',
        'gallery'       => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
        'steps'         => '<circle cx="5.5" cy="12" r="2.8"/><circle cx="12" cy="12" r="2.8"/><circle cx="18.5" cy="12" r="2.8"/><rect x="8.3" y="11.3" width="1.4" height="1.4"/><rect x="14.3" y="11.3" width="1.4" height="1.4"/>',
        'logos_strip'   => '<rect x="2" y="9" width="5" height="6" rx="1"/><rect x="9.5" y="9" width="5" height="6" rx="1"/><rect x="17" y="9" width="5" height="6" rx="1"/>',
        'pricing'       => '<rect x="3" y="5" width="5" height="14" rx="1"/><rect x="9.5" y="3" width="5" height="18" rx="1"/><rect x="16" y="5" width="5" height="14" rx="1"/>',
        'form'          => '<rect x="3" y="4" width="18" height="3.5" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="10" width="18" height="3.5" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="16" width="9" height="3.5" rx="1.5"/>',
        'posts_listing' => '<rect x="3" y="4" width="6" height="6" rx="1"/><rect x="10.5" y="5" width="10.5" height="1.8" rx=".9"/><rect x="10.5" y="8.2" width="8" height="1.8" rx=".9"/><rect x="3" y="13" width="6" height="6" rx="1"/><rect x="10.5" y="14" width="10.5" height="1.8" rx=".9"/><rect x="10.5" y="17.2" width="8" height="1.8" rx=".9"/>',
        'article_body'  => '<rect x="3" y="5" width="18" height="2" rx="1"/><rect x="3" y="9" width="18" height="2" rx="1"/><rect x="3" y="13" width="18" height="2" rx="1"/><rect x="3" y="17" width="12" height="2" rx="1"/>',
        'generic'       => '<rect x="4" y="4" width="16" height="16" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/>',
    ];
    ?>
    <ol class="pp-sections-list" id="pp-sections-list">
        <?php foreach ($sections as $i => $s): ?>
        <?php
            $typeLabel = $sectionTypes[$s['section_type']] ?? $s['section_type'];
            // Inferencia de título visible en la cabecera (E1.1).
            $ppInferredTitle = '';
            $ppContentArr = null;
            $ppContentRaw = $s['content'] ?? '';
            if (is_string($ppContentRaw) && $ppContentRaw !== '') {
                $ppContentArr = json_decode($ppContentRaw, true);
            }
            if (is_array($ppContentArr)) {
                foreach (['heading', 'title', 'eyebrow'] as $k) {
                    if (!empty($ppContentArr[$k]) && is_string($ppContentArr[$k])) {
                        $ppInferredTitle = trim($ppContentArr[$k]);
                        break;
                    }
                }
                if ($ppInferredTitle === '' && !empty($ppContentArr['items']) && is_array($ppContentArr['items'])) {
                    $first = $ppContentArr['items'][0] ?? null;
                    if (is_array($first)) {
                        foreach (['title', 'question', 'plan_name', 'name', 'quote', 'label'] as $k) {
                            if (!empty($first[$k]) && is_string($first[$k])) {
                                $ppInferredTitle = trim($first[$k]);
                                break;
                            }
                        }
                    }
                }
            }
            $ppTypeSafe = preg_replace('/[^a-zA-Z0-9_-]/', '', $s['section_type']);
            $ppIconKey = isset($ppTypeIcons[$ppTypeSafe]) ? $ppTypeSafe : 'generic';
            $ppStatus = ($s['status'] ?? 'editable') === 'locked' ? 'locked' : 'editable';
            $ppStatusLabel = $ppStatus === 'locked' ? 'Bloqueada' : 'Editable';
            $ppOrder = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            // Truncado simple a 90 chars.
            if (mb_strlen($ppInferredTitle) > 90) {
                $ppInferredTitle = mb_substr($ppInferredTitle, 0, 89) . '…';
            }
        ?>
        <li class="pp-section-card"
            data-section-id="<?= (int) $s['id'] ?>"
            data-section-type="<?= e($s['section_type']) ?>"
            draggable="true">
            <header class="pp-section-card__header">
                <span class="pp-drag-handle" title="<?= e(__('page_form.drag_to_reorder')) ?>" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 16 16"><g fill="currentColor">
                        <circle cx="6" cy="3" r="1.2"/><circle cx="10" cy="3" r="1.2"/>
                        <circle cx="6" cy="8" r="1.2"/><circle cx="10" cy="8" r="1.2"/>
                        <circle cx="6" cy="13" r="1.2"/><circle cx="10" cy="13" r="1.2"/>
                    </g></svg>
                </span>
                <span class="pp-section-card__order"><?= e($ppOrder) ?></span>
                <span class="pp-section-card__thumb pp-section-card__thumb--<?= e($ppIconKey) ?>" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><?= $ppTypeIcons[$ppIconKey] ?></svg>
                </span>
                <span class="pp-section-card__title-block">
                    <span class="pp-section-card__eyebrow pp-section-card__type"><?= e($typeLabel) ?></span>
                    <?php if ($ppInferredTitle !== ''): ?>
                        <span class="pp-section-card__title"><?= e($ppInferredTitle) ?></span>
                    <?php else: ?>
                        <span class="pp-section-card__title pp-section-card__title--empty"><?= e(__('page_form.no_content')) ?></span>
                    <?php endif; ?>
                </span>
                <span class="pp-section-card__status-pill pp-section-card__status-pill--<?= e($ppStatus) ?>"><?= e($ppStatusLabel) ?></span>
                <button type="button" class="pp-section-card__toggle" aria-expanded="false" aria-label="<?= e(__('page_form.expand_section')) ?>">
                    <svg class="pp-section-card__chevron" width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="pp-section-card__menu">
                    <button type="button" class="pp-section-card__menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('pages.more_actions')) ?>">
                        <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><g fill="currentColor">
                            <circle cx="8" cy="3" r="1.4"/><circle cx="8" cy="8" r="1.4"/><circle cx="8" cy="13" r="1.4"/>
                        </g></svg>
                    </button>
                    <div class="pp-section-card__menu-list" role="menu" hidden>
                        <button type="button" role="menuitem" class="pp-section-card__menu-item pp-section-card__delete"><?= e(__('page_form.delete_section')) ?></button>
                    </div>
                </div>
            </header>
            <div class="pp-section-card__body" hidden></div>
            <!-- Datos iniciales leídos por sections-editor.js al abrir la card -->
            <div hidden>
                <input type="hidden" data-field="section_type" value="<?= e($s['section_type']) ?>">
                <input type="hidden" data-field="status"       value="<?= e($s['status']) ?>">
                <textarea data-field="content"><?= e($s['content'] ?? '{}') ?></textarea>
                <?php if (!empty($s['style'])): ?>
                <textarea data-field="style"><?= e($s['style']) ?></textarea>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
</section>

<!-- Modal para añadir sección -->
<div class="pp-modal" id="pp-add-section-modal" hidden aria-hidden="true">
    <div class="pp-modal__backdrop" data-close-modal></div>
    <div class="pp-modal__dialog" role="dialog" aria-labelledby="pp-add-section-title">
        <header class="pp-modal__header">
            <h3 id="pp-add-section-title"><?= e(__('page_form.add_section_title')) ?></h3>
            <button type="button" class="pp-modal__close" data-close-modal aria-label="<?= e(__('common.close')) ?>">×</button>
        </header>
        <div class="pp-modal__body">
            <div class="pp-form-group">
                <label for="pp-new-section-type"><?= e(__('page_form.section_type')) ?></label>
                <select id="pp-new-section-type">
                    <?php foreach ($sectionTypes as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(__('page_form.section_empty_hint')) ?></small>
            </div>
        </div>
        <footer class="pp-modal__footer">
            <button type="button" class="pp-btn pp-btn--secondary" data-close-modal><?= e(__('common.cancel')) ?></button>
            <button type="button" class="pp-btn pp-btn--primary" id="pp-create-section-btn">
                <?= e(__('page_form.create_section')) ?>
            </button>
        </footer>
    </div>
</div>
<?php endif; ?>
