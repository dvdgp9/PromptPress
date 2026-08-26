<?php
/**
 * @var array $doc        fila de documents (+ uploaded_by_username)
 * @var int $sizeBytes    tamaño físico del archivo
 * @var string $csrf
 */

\Core\View::extend('admin/layout');

function fmtSize(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024,    0, ',', '.') . ' KB';
    return $bytes . ' B';
}

$statusBadge = match ($doc['status']) {
    'ready'      => 'pp-badge--success',
    'processing' => 'pp-badge--warning',
    'error'      => 'pp-badge--danger',
    default      => 'pp-badge--muted',
};
$statusLabel = match ($doc['status']) {
    'ready'      => __('documents.status.ready'),
    'processing' => __('documents.status.processing'),
    'error'      => __('status.error'),
    default      => $doc['status'],
};
$textLength = mb_strlen((string) $doc['extracted_text']);
?>

<?php \Core\View::start('title'); ?><?= e($doc['title']) ?><?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/document-detail.js')) ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div class="pp-page-header__back">
        <a href="<?= e(base_url('admin/documents')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm">← <?= e(__('common.back')) ?></a>
    </div>
    <h2>
        <span id="pp-doc-title-display"><?= e($doc['title']) ?></span>
        <button type="button" class="pp-btn pp-btn--sm pp-btn--ghost" id="pp-edit-title-btn" title="<?= e(__('documents.edit_title')) ?>">✏️</button>
    </h2>
</div>

<?php $flashSuccess = \Core\Session::flash('success'); $flashError = \Core\Session::flash('error'); ?>
<?php if ($flashSuccess): ?><div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>

<!-- Form para renombrar (oculto por defecto, toggled por JS) -->
<form method="POST" action="<?= e(base_url('admin/documents/' . $doc['id'] . '/rename')) ?>"
      class="pp-doc-rename-form" id="pp-rename-form" hidden>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="text" name="title" value="<?= e($doc['title']) ?>" maxlength="255" required>
    <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm"><?= e(__('common.save')) ?></button>
    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-cancel-rename"><?= e(__('common.cancel')) ?></button>
</form>

<div class="pp-doc-detail">
    <!-- Sidebar metadata -->
    <aside class="pp-doc-detail__sidebar">
        <div class="pp-doc-meta-card">
            <h4><?= e(__('documents.info')) ?></h4>
            <dl>
                <dt><?= e(__('documents.file')) ?></dt>
                <dd class="pp-doc-filename"><?= e($doc['original_filename']) ?></dd>

                <dt><?= e(__('table.type')) ?></dt>
                <dd><code><?= e(strtoupper($doc['file_type'])) ?></code></dd>

                <dt><?= e(__('documents.size')) ?></dt>
                <dd><?= e(fmtSize($sizeBytes)) ?></dd>

                <dt><?= e(__('table.status')) ?></dt>
                <dd><span class="pp-badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span></dd>

                <dt><?= e(__('documents.extracted_text')) ?></dt>
                <dd><?= e(__('documents.characters', ['n' => number_format($textLength, 0, ',', '.')])) ?></dd>

                <dt><?= e(__('documents.uploaded_at')) ?></dt>
                <dd><?= e(substr((string) $doc['created_at'], 0, 16)) ?></dd>

                <?php if (!empty($doc['uploaded_by_username'])): ?>
                <dt><?= e(__('documents.by')) ?></dt>
                <dd><?= e($doc['uploaded_by_username']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="pp-doc-actions-card">
            <?php if ($doc['status'] === 'error'): ?>
            <form method="POST" action="<?= e(base_url('admin/documents/' . $doc['id'] . '/retry')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="pp-btn pp-btn--primary pp-btn--block">
                    🔄 <?= e(__('documents.retry')) ?>
                </button>
            </form>
            <?php endif; ?>

            <button type="button" class="pp-btn pp-btn--danger pp-btn--block" id="pp-delete-btn">
                🗑 <?= e(__('documents.delete')) ?>
            </button>
        </div>
    </aside>

    <!-- Main content -->
    <main class="pp-doc-detail__main">
        <?php if (!empty($doc['summary'])): ?>
        <section class="pp-doc-summary-card">
            <div class="pp-doc-card-header">
                <h4><?= e(__('documents.summary')) ?></h4>
                <span class="pp-doc-card-hint"><?= e(__('documents.summary_hint')) ?></span>
            </div>
            <p><?= e($doc['summary']) ?></p>
        </section>
        <?php endif; ?>

        <section class="pp-doc-text-card">
            <div class="pp-doc-card-header">
                <h4><?= e(__('documents.extracted_text')) ?></h4>
                <div class="pp-doc-text-tools">
                    <input type="search" id="pp-doc-search" placeholder="<?= e(__('documents.search_placeholder')) ?>"
                           <?= $textLength === 0 ? 'disabled' : '' ?>>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-doc-copy-btn"
                            <?= $textLength === 0 ? 'disabled' : '' ?>>
                        📋 <?= e(__('documents.copy')) ?>
                    </button>
                </div>
            </div>

            <?php if ($doc['status'] === 'error'): ?>
            <div class="pp-alert pp-alert--error">
                <?= e(__('documents.extraction_failed')) ?>
            </div>
            <?php endif; ?>

            <?php if ($textLength > 0): ?>
            <div class="pp-doc-text-container">
                <pre class="pp-doc-text" id="pp-doc-text"><?= e($doc['extracted_text']) ?></pre>
                <div class="pp-doc-search-info" id="pp-doc-search-info" hidden></div>
            </div>
            <?php else: ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__text"><?= e(__('documents.no_text')) ?></div>
            </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- Modal de confirmación de borrado -->
<div class="pp-modal" id="pp-delete-modal" hidden aria-hidden="true">
    <div class="pp-modal__backdrop" data-close-modal></div>
    <div class="pp-modal__dialog" role="dialog" aria-labelledby="pp-delete-title">
        <header class="pp-modal__header">
            <h3 id="pp-delete-title"><?= e(__('documents.delete')) ?></h3>
            <button type="button" class="pp-modal__close" data-close-modal aria-label="<?= e(__('common.close')) ?>">×</button>
        </header>
        <div class="pp-modal__body">
            <p><?= __('documents.confirm_delete', ['titulo' => '<strong>' . e($doc['title']) . '</strong>']) ?></p>
            <p class="pp-muted"><?= e(__('documents.delete_warning')) ?></p>
        </div>
        <footer class="pp-modal__footer">
            <button type="button" class="pp-btn pp-btn--secondary" data-close-modal><?= e(__('common.cancel')) ?></button>
            <form method="POST" action="<?= e(base_url('admin/documents/' . $doc['id'] . '/delete')) ?>"
                  style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="pp-btn pp-btn--danger"><?= e(__('common.yes_delete')) ?></button>
            </form>
        </footer>
    </div>
</div>
