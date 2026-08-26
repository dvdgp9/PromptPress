<?php
/**
 * @var array $resource @var array $forms @var string[] $languages
 * @var string[] $publicationIssues @var int $maxUploadBytes
 * @var string[] $errors @var ?string $notice @var string $csrf
 */
\Core\View::extend('admin/layout');
use App\Services\LanguageService;

$id = (int) $resource['id'];
$hasFile = !empty($resource['file_path']);
$coverId = (int) ($resource['cover_media_id'] ?? 0);
$coverPath = (string) ($resource['cover_path'] ?? '');
$accessMode = (string) ($resource['access_mode'] ?? 'direct');
$status = (string) ($resource['status'] ?? 'draft');
$allLanguages = (string) ($resource['language_scope'] ?? 'selected') === 'all';
$selectedLanguages = (array) ($resource['languages'] ?? [$resource['language']]);
$maxMb = rtrim(rtrim(number_format($maxUploadBytes / (1024 * 1024), 1, ',', ''), '0'), ',');
$fileSize = $hasFile ? ($resource['file_size'] >= 1024 * 1024
    ? number_format((int) $resource['file_size'] / (1024 * 1024), 1, ',', '') . ' MB'
    : ((int) $resource['file_size'] < 1024 ? '< 1 KB' : number_format((int) $resource['file_size'] / 1024, 0, ',', '') . ' KB')) : '';
?>

<?php \Core\View::start('title'); ?><?= e((string) $resource['title']) ?> · <?= e(__('nav.resources')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header pp-resource-editor-head">
    <div>
        <a class="pp-resource-back" href="<?= e(base_url('admin/resources')) ?>">← <?= e(__('resource.admin.back')) ?></a>
        <h2><?= e((string) $resource['title']) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('resource.admin.edit_intro')) ?></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="pp-alert pp-alert--error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" action="<?= e(base_url('admin/resources/' . $id)) ?>" enctype="multipart/form-data"
      class="pp-resource-editor" id="pp-resource-editor" autocomplete="off"
      data-has-file="<?= $hasFile ? '1' : '0' ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxUploadBytes ?>">

    <div class="pp-resource-editor__main">
        <section class="pp-resource-section" aria-labelledby="pp-resource-content-title">
            <header class="pp-resource-section__head">
                <span class="pp-resource-section__step">01</span>
                <div><h3 id="pp-resource-content-title"><?= e(__('resource.admin.content_title')) ?></h3><p><?= e(__('resource.admin.content_help')) ?></p></div>
            </header>
            <div class="pp-form-group">
                <label for="pp-resource-title"><?= e(__('resource.admin.title')) ?></label>
                <input type="text" id="pp-resource-title" name="title" maxlength="180" required value="<?= e((string) $resource['title']) ?>">
                <small><?= e(__('resource.admin.title_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-resource-description"><?= e(__('resource.admin.description')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
                <textarea id="pp-resource-description" name="description" rows="6" maxlength="8000"><?= e((string) ($resource['description'] ?? '')) ?></textarea>
                <small><?= e(__('resource.admin.description_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-resource-category"><?= e(__('resource.admin.category')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
                <input type="text" id="pp-resource-category" name="category" maxlength="100" value="<?= e((string) ($resource['category'] ?? '')) ?>" placeholder="<?= e(__('resource.admin.category_placeholder')) ?>">
            </div>

            <fieldset class="pp-resource-languages" data-resource-languages>
                <legend><?= e(__('resource.admin.languages')) ?></legend>
                <p><?= e(__('resource.admin.languages_help')) ?></p>
                <label class="pp-resource-language-all<?= $allLanguages ? ' is-selected' : '' ?>">
                    <input type="checkbox" name="language_scope" value="all" <?= $allLanguages ? 'checked' : '' ?> data-language-all>
                    <span><strong><?= e(__('resource.admin.languages_all')) ?></strong><small><?= e(__('resource.admin.languages_all_help')) ?></small></span>
                </label>
                <div class="pp-resource-language-list" data-language-list>
                    <?php foreach ($languages as $language): ?>
                    <label class="pp-resource-language-option<?= in_array($language, $selectedLanguages, true) ? ' is-selected' : '' ?>">
                        <input type="checkbox" name="languages[]" value="<?= e($language) ?>"
                               <?= in_array($language, $selectedLanguages, true) ? 'checked' : '' ?> <?= $allLanguages ? 'disabled' : '' ?>>
                        <span><?= e(LanguageService::label($language)) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="pp-resource-cover" data-media-picker>
                <div class="pp-resource-cover__copy">
                    <strong><?= e(__('resource.admin.cover')) ?></strong>
                    <span><?= e(__('resource.admin.cover_help')) ?></span>
                </div>
                <input type="hidden" name="cover_media_id" value="<?= $coverId > 0 ? $coverId : '' ?>" data-media-input>
                <div class="pp-resource-cover__preview<?= $coverPath !== '' ? '' : ' is-empty' ?>" data-media-preview>
                    <?php if ($coverPath !== ''): ?><img src="<?= e(base_url(ltrim($coverPath, '/'))) ?>" alt=""><?php else: ?><span aria-hidden="true">R</span><?php endif; ?>
                </div>
                <div class="pp-resource-cover__actions">
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-media-open><?= e(__('resource.admin.choose_cover')) ?></button>
                    <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm<?= $coverPath !== '' ? '' : ' is-hidden' ?>" data-media-clear><?= e(__('post_meta.remove')) ?></button>
                </div>
            </div>
        </section>

        <section class="pp-resource-section" aria-labelledby="pp-resource-file-title">
            <header class="pp-resource-section__head">
                <span class="pp-resource-section__step">02</span>
                <div><h3 id="pp-resource-file-title"><?= e(__('resource.admin.file_title')) ?></h3><p><?= e(__('resource.admin.file_help', ['mb' => $maxMb])) ?></p></div>
            </header>
            <?php if ($hasFile): ?>
            <div class="pp-resource-current-file" data-current-file>
                <span class="pp-resource-filemark"><?= e((string) $resource['file_mime'] === 'application/epub+zip' ? 'EPUB' : 'PDF') ?></span>
                <div><strong><?= e((string) $resource['original_filename']) ?></strong><small><?= e($fileSize) ?> · <?= e(__('resource.admin.file_protected')) ?></small></div>
                <span class="pp-status-pill pp-status-pill--green"><?= e(__('resource.admin.ready')) ?></span>
            </div>
            <?php endif; ?>
            <label class="pp-resource-file-picker" for="pp-resource-file">
                <input type="file" id="pp-resource-file" name="resource_file" accept=".pdf,.epub,application/pdf,application/epub+zip" data-file-input>
                <span class="pp-resource-file-picker__action"><?= e($hasFile ? __('resource.admin.replace_file') : __('resource.admin.choose_file')) ?></span>
                <span class="pp-resource-file-picker__name" data-file-name><?= e(__('resource.admin.no_file_selected')) ?></span>
            </label>
        </section>

        <section class="pp-resource-section" aria-labelledby="pp-resource-access-title">
            <header class="pp-resource-section__head">
                <span class="pp-resource-section__step">03</span>
                <div><h3 id="pp-resource-access-title"><?= e(__('resource.admin.access_title')) ?></h3><p><?= e(__('resource.admin.access_help')) ?></p></div>
            </header>
            <div class="pp-resource-choice-grid">
                <label class="pp-resource-choice<?= $accessMode === 'direct' ? ' is-selected' : '' ?>">
                    <input type="radio" name="access_mode" value="direct" <?= $accessMode === 'direct' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('resource.admin.access.direct')) ?></strong><small><?= e(__('resource.admin.access.direct_help')) ?></small></span>
                </label>
                <label class="pp-resource-choice<?= $accessMode === 'form' ? ' is-selected' : '' ?>">
                    <input type="radio" name="access_mode" value="form" <?= $accessMode === 'form' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('resource.admin.access.form')) ?></strong><small><?= e(__('resource.admin.access.form_help')) ?></small></span>
                </label>
            </div>
            <div class="pp-resource-form-link" data-form-settings <?= $accessMode === 'form' ? '' : 'hidden' ?>>
                <?php if ($forms === []): ?>
                    <p><strong><?= e(__('resource.admin.form_empty_title')) ?></strong><span><?= e(__('resource.admin.form_empty_help')) ?></span></p>
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/formularios')) ?>"><?= e(__('resource.admin.create_form')) ?></a>
                    <input type="hidden" name="form_id" value="">
                <?php else: ?>
                    <div class="pp-form-group">
                        <label for="pp-resource-form"><?= e(__('resource.admin.form_label')) ?></label>
                        <select id="pp-resource-form" name="form_id" data-form-select>
                            <option value=""><?= e(__('resource.admin.form_placeholder')) ?></option>
                            <?php foreach ($forms as $form): ?>
                                <option value="<?= (int) $form['id'] ?>" <?= (int) ($resource['form_id'] ?? 0) === (int) $form['id'] ? 'selected' : '' ?>><?= e((string) $form['heading']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small><?= e(__('resource.admin.form_help')) ?></small>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <aside class="pp-resource-editor__side">
        <section class="pp-resource-publish" aria-labelledby="pp-resource-publish-title">
            <div class="pp-resource-publish__head">
                <div><h3 id="pp-resource-publish-title"><?= e(__('resource.admin.publish_title')) ?></h3><p><?= e(__('resource.admin.publish_help')) ?></p></div>
                <span class="pp-status-pill<?= $status === 'published' ? ' pp-status-pill--green' : '' ?>" data-status-pill><?= e($status === 'published' ? __('status.published') : __('status.draft')) ?></span>
            </div>
            <div class="pp-resource-status-options">
                <label class="pp-resource-status-option<?= $status === 'draft' ? ' is-selected' : '' ?>">
                    <input type="radio" name="status" value="draft" <?= $status === 'draft' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('status.draft')) ?></strong><small><?= e(__('resource.admin.draft_help')) ?></small></span>
                </label>
                <label class="pp-resource-status-option<?= $status === 'published' ? ' is-selected' : '' ?>">
                    <input type="radio" name="status" value="published" <?= $status === 'published' ? 'checked' : '' ?>>
                    <span><strong><?= e(__('status.published')) ?></strong><small><?= e(__('resource.admin.published_help')) ?></small></span>
                </label>
            </div>
            <div class="pp-resource-readiness<?= $publicationIssues === [] ? ' is-ready' : '' ?>" data-readiness
                 data-ready-text="<?= e(__('resource.admin.ready_publish')) ?>"
                 data-file-text="<?= e(__('resource.publish_issue.file')) ?>"
                 data-form-text="<?= e(__('resource.publish_issue.form')) ?>">
                <strong data-readiness-title><?= e($publicationIssues === [] ? __('resource.admin.ready_publish') : __('resource.admin.before_publish')) ?></strong>
                <ul data-readiness-list>
                    <?php foreach ($publicationIssues as $issue): ?><li><?= e(__($issue)) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="pp-btn pp-btn--primary pp-resource-save" data-save><?= e(__('resource.admin.save')) ?></button>
            <p class="pp-resource-save-hint"><?= e(__('resource.admin.save_hint')) ?></p>
        </section>

        <section class="pp-resource-danger">
            <h3><?= e(__('resource.admin.delete_title')) ?></h3>
            <p><?= e(__('resource.admin.delete_help')) ?></p>
            <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text" data-delete-open><?= e(__('resource.admin.delete')) ?></button>
        </section>
    </aside>
</form>

<div class="pp-modal pp-commerce-media-modal" id="pp-resource-media-modal" role="dialog" aria-modal="true" aria-labelledby="pp-resource-media-title" hidden>
    <div class="pp-modal__backdrop" data-media-close></div>
    <div class="pp-modal__dialog">
        <header class="pp-modal__header">
            <h3 id="pp-resource-media-title"><?= e(__('resource.admin.choose_cover')) ?></h3>
            <button type="button" class="pp-modal__close" data-media-close aria-label="<?= e(__('common.close')) ?>">×</button>
        </header>
        <div class="pp-modal__body"><div class="pp-commerce-media-grid" data-media-grid><p class="pp-booking-soft"><?= e(__('cv.loading')) ?></p></div></div>
        <footer class="pp-modal__footer"><p class="pp-booking-soft"><?= e(__('resource.admin.cover_missing')) ?> <a href="<?= e(base_url('admin/media')) ?>" target="_blank" rel="noopener"><?= e(__('nav.media')) ?></a>.</p></footer>
    </div>
</div>

<div class="pp-del-overlay" data-delete-dialog hidden>
    <div class="pp-del-dialog" role="dialog" aria-modal="true" aria-labelledby="pp-resource-delete-title">
        <h3 id="pp-resource-delete-title"><?= e(__('resource.admin.delete_confirm_title')) ?></h3>
        <p class="pp-del-clean"><?= e(__('resource.admin.delete_confirm', ['title' => (string) $resource['title']])) ?></p>
        <p class="pp-del-final"><?= e(__('resource.admin.delete_irreversible')) ?></p>
        <div class="pp-del-actions">
            <button type="button" class="pp-btn pp-btn--ghost" data-delete-close><?= e(__('common.cancel')) ?></button>
            <form method="post" action="<?= e(base_url('admin/resources/' . $id . '/delete')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="pp-btn pp-btn--danger"><?= e(__('resource.admin.delete')) ?></button>
            </form>
        </div>
    </div>
</div>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/resources-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/resources-editor.js')) ?>?v=<?= e($jsVer) ?>" data-library="<?= e(base_url('admin/media/library')) ?>"></script>
<?php \Core\View::end(); ?>
