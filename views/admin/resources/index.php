<?php
/** @var array $resources @var ?string $notice @var ?string $error @var string $csrf */
\Core\View::extend('admin/layout');
use App\Services\LanguageService;

$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) return '';
    return $bytes >= 1024 * 1024
        ? number_format($bytes / (1024 * 1024), 1, ',', '') . ' MB'
        : ($bytes < 1024 ? '< 1 KB' : number_format($bytes / 1024, 0, ',', '') . ' KB');
};
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.resources')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header pp-resources-header">
    <div>
        <span class="pp-resources-eyebrow"><?= e(__('resource.admin.eyebrow')) ?></span>
        <h2><?= e(__('nav.resources')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('resource.admin.intro')) ?></p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<section class="pp-resource-create<?= $resources === [] ? ' is-first' : '' ?>" aria-labelledby="pp-resource-create-title">
    <div class="pp-resource-create__copy">
        <h3 id="pp-resource-create-title"><?= e($resources === [] ? __('resource.admin.first_title') : __('resource.admin.new_title')) ?></h3>
        <p><?= e($resources === [] ? __('resource.admin.first_help') : __('resource.admin.new_help')) ?></p>
    </div>
    <form method="post" action="<?= e(base_url('admin/resources')) ?>" class="pp-resource-create__form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="pp-sr-only" for="pp-resource-new-title"><?= e(__('resource.admin.title')) ?></label>
        <input type="text" id="pp-resource-new-title" name="title" maxlength="180" required
               placeholder="<?= e(__('resource.admin.new_placeholder')) ?>" autocomplete="off">
        <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('resource.admin.create')) ?></button>
    </form>
</section>

<?php if ($resources !== []): ?>
<div class="pp-resource-list" role="list">
    <?php foreach ($resources as $resource):
        $published = (string) $resource['status'] === 'published';
        $hasFile = !empty($resource['file_path']);
        $mime = (string) ($resource['file_mime'] ?? '');
        $format = $mime === 'application/epub+zip' ? 'EPUB' : ($mime === 'application/pdf' ? 'PDF' : __('resource.admin.no_file_short'));
    ?>
    <article class="pp-resource-row" role="listitem">
        <a class="pp-resource-row__cover<?= empty($resource['cover_path']) ? ' is-empty' : '' ?>"
           href="<?= e(base_url('admin/resources/' . (int) $resource['id'])) ?>" aria-hidden="true" tabindex="-1">
            <?php if (!empty($resource['cover_path'])): ?>
                <img src="<?= e(base_url(ltrim((string) $resource['cover_path'], '/'))) ?>" alt="">
            <?php else: ?>
                <span><?= e($format === __('resource.admin.no_file_short') ? 'R' : $format) ?></span>
            <?php endif; ?>
        </a>
        <div class="pp-resource-row__main">
            <div class="pp-resource-row__titleline">
                <a href="<?= e(base_url('admin/resources/' . (int) $resource['id'])) ?>"><strong><?= e((string) $resource['title']) ?></strong></a>
                <span class="pp-status-pill<?= $published ? ' pp-status-pill--green' : '' ?>"><?= e($published ? __('status.published') : __('status.draft')) ?></span>
            </div>
            <div class="pp-resource-row__meta">
                <span><?= e($format) ?><?= $hasFile ? ' · ' . e($formatBytes((int) $resource['file_size'])) : '' ?></span>
                <?php if (!empty($resource['category'])): ?><span><?= e((string) $resource['category']) ?></span><?php endif; ?>
                <span><?= e((string) $resource['access_mode'] === 'form' ? __('resource.admin.access.form_short') : __('resource.admin.access.direct_short')) ?></span>
                <span><?= e((string) ($resource['language_scope'] ?? 'selected') === 'all'
                    ? __('resource.admin.languages_all')
                    : implode(', ', array_map(static fn(string $code): string => LanguageService::label($code), (array) ($resource['languages'] ?? [])))) ?></span>
            </div>
        </div>
        <a class="pp-btn pp-btn--ghost pp-btn--sm pp-resource-row__edit" href="<?= e(base_url('admin/resources/' . (int) $resource['id'])) ?>"><?= e(__('common.edit')) ?></a>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
