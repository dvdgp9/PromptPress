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
    'ready'      => 'Listo',
    'processing' => 'Procesando',
    'error'      => 'Error',
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
        <a href="<?= e(base_url('admin/documents')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm">← Volver</a>
    </div>
    <h2>
        <span id="pp-doc-title-display"><?= e($doc['title']) ?></span>
        <button type="button" class="pp-btn pp-btn--sm pp-btn--ghost" id="pp-edit-title-btn" title="Editar título">✏️</button>
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
    <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm">Guardar</button>
    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-cancel-rename">Cancelar</button>
</form>

<div class="pp-doc-detail">
    <!-- Sidebar metadata -->
    <aside class="pp-doc-detail__sidebar">
        <div class="pp-doc-meta-card">
            <h4>Información</h4>
            <dl>
                <dt>Archivo</dt>
                <dd class="pp-doc-filename"><?= e($doc['original_filename']) ?></dd>

                <dt>Tipo</dt>
                <dd><code><?= e(strtoupper($doc['file_type'])) ?></code></dd>

                <dt>Tamaño</dt>
                <dd><?= e(fmtSize($sizeBytes)) ?></dd>

                <dt>Estado</dt>
                <dd><span class="pp-badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span></dd>

                <dt>Texto extraído</dt>
                <dd><?= number_format($textLength, 0, ',', '.') ?> caracteres</dd>

                <dt>Subido</dt>
                <dd><?= e(substr((string) $doc['created_at'], 0, 16)) ?></dd>

                <?php if (!empty($doc['uploaded_by_username'])): ?>
                <dt>Por</dt>
                <dd><?= e($doc['uploaded_by_username']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="pp-doc-actions-card">
            <?php if ($doc['status'] === 'error'): ?>
            <form method="POST" action="<?= e(base_url('admin/documents/' . $doc['id'] . '/retry')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="pp-btn pp-btn--primary pp-btn--block">
                    🔄 Reintentar extracción
                </button>
            </form>
            <?php endif; ?>

            <button type="button" class="pp-btn pp-btn--danger pp-btn--block" id="pp-delete-btn">
                🗑 Eliminar documento
            </button>
        </div>
    </aside>

    <!-- Main content -->
    <main class="pp-doc-detail__main">
        <?php if (!empty($doc['summary'])): ?>
        <section class="pp-doc-summary-card">
            <div class="pp-doc-card-header">
                <h4>Resumen</h4>
                <span class="pp-doc-card-hint">Heurístico — la IA generará uno mejor cuando esté configurada (T6.3).</span>
            </div>
            <p><?= e($doc['summary']) ?></p>
        </section>
        <?php endif; ?>

        <section class="pp-doc-text-card">
            <div class="pp-doc-card-header">
                <h4>Texto extraído</h4>
                <div class="pp-doc-text-tools">
                    <input type="search" id="pp-doc-search" placeholder="Buscar en el texto…"
                           <?= $textLength === 0 ? 'disabled' : '' ?>>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-doc-copy-btn"
                            <?= $textLength === 0 ? 'disabled' : '' ?>>
                        📋 Copiar
                    </button>
                </div>
            </div>

            <?php if ($doc['status'] === 'error'): ?>
            <div class="pp-alert pp-alert--error">
                La extracción falló para este documento. Usa "Reintentar" o revisa los logs del servidor.
            </div>
            <?php endif; ?>

            <?php if ($textLength > 0): ?>
            <div class="pp-doc-text-container">
                <pre class="pp-doc-text" id="pp-doc-text"><?= e($doc['extracted_text']) ?></pre>
                <div class="pp-doc-search-info" id="pp-doc-search-info" hidden></div>
            </div>
            <?php else: ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__text">No hay texto extraído.</div>
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
            <h3 id="pp-delete-title">Eliminar documento</h3>
            <button type="button" class="pp-modal__close" data-close-modal aria-label="Cerrar">×</button>
        </header>
        <div class="pp-modal__body">
            <p>¿Seguro que quieres eliminar <strong><?= e($doc['title']) ?></strong>?</p>
            <p class="pp-muted">Se borrará el archivo físico y el texto extraído. Esta acción no se puede deshacer.</p>
        </div>
        <footer class="pp-modal__footer">
            <button type="button" class="pp-btn pp-btn--secondary" data-close-modal>Cancelar</button>
            <form method="POST" action="<?= e(base_url('admin/documents/' . $doc['id'] . '/delete')) ?>"
                  style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="pp-btn pp-btn--danger">Sí, eliminar</button>
            </form>
        </footer>
    </div>
</div>
