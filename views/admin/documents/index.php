<?php
/**
 * @var array $documents   filas de documents
 * @var string $csrf
 * @var int $maxSize
 * @var array $allowedExt
 */
\Core\View::extend('admin/layout');
$maxMb = round($maxSize / 1024 / 1024);
$accept = '.' . implode(',.', $allowedExt);
?>

<?php \Core\View::start('title'); ?>Documentos base<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>window.PP_DOC_MAX_SIZE = <?= (int) $maxSize ?>;</script>
<script src="<?= e(base_url('admin/assets/js/document-upload.js')) ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Documentos base</h2>
</div>

<p class="pp-page-intro">
    Sube PDFs, DOCX o TXT con información de tu empresa (catálogos, presentaciones, propuestas). El texto se extraerá automáticamente
    y estará disponible como contexto para las llamadas a IA.
</p>

<?php
$flashSuccess = \Core\Session::flash('success');
$flashError   = \Core\Session::flash('error');
?>
<?php if ($flashSuccess): ?>
<div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="pp-alert pp-alert--error"><?= e($flashError) ?></div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/documents/upload')) ?>"
      enctype="multipart/form-data" class="pp-form" id="pp-doc-upload-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <label class="pp-dropzone" id="pp-dropzone" for="pp-file-input">
        <div class="pp-dropzone__icon">📄</div>
        <div class="pp-dropzone__title">Arrastra un archivo aquí o haz clic para seleccionar</div>
        <div class="pp-dropzone__hint">
            PDF, DOCX o TXT · máximo <?= (int) $maxMb ?> MB
        </div>
        <input type="file" id="pp-file-input" name="file"
               accept="<?= e($accept) ?>" hidden>

        <div class="pp-dropzone__selected" id="pp-dropzone-selected" hidden>
            <div class="pp-dropzone__selected-name" id="pp-dropzone-name"></div>
            <div class="pp-dropzone__selected-meta" id="pp-dropzone-meta"></div>
        </div>
    </label>

    <div class="pp-dropzone-progress" id="pp-dropzone-progress" hidden>
        <div class="pp-dropzone-progress__bar">
            <div class="pp-dropzone-progress__fill" id="pp-dropzone-progress-fill"></div>
        </div>
        <div class="pp-dropzone-progress__label" id="pp-dropzone-progress-label">Subiendo 0%…</div>
    </div>

    <div class="pp-form-row pp-dropzone-extras" id="pp-dropzone-extras" hidden>
        <div class="pp-form-group">
            <label for="title">Título (opcional)</label>
            <input type="text" id="title" name="title" maxlength="255"
                   placeholder="Si lo dejas vacío, se usará el nombre del archivo.">
        </div>
        <div class="pp-form-group pp-dropzone-actions-group">
            <label>&nbsp;</label>
            <div class="pp-dropzone-actions">
                <button type="button" class="pp-btn pp-btn--secondary" id="pp-dropzone-clear">Cambiar archivo</button>
                <button type="submit" class="pp-btn pp-btn--primary" id="pp-doc-submit">Subir y procesar</button>
            </div>
        </div>
    </div>
</form>

<h3 class="pp-docs-section-title">Documentos subidos <span class="pp-docs-count">(<?= count($documents) ?>)</span></h3>

<?php if (empty($documents)): ?>
<div class="pp-empty">
    <div class="pp-empty__title">No hay documentos aún</div>
    <div class="pp-empty__text">Sube tu primer documento usando el formulario de arriba.</div>
</div>
<?php else: ?>
<table class="pp-table">
    <thead>
        <tr>
            <th>Título</th>
            <th>Tipo</th>
            <th>Estado</th>
            <th>Texto extraído</th>
            <th>Resumen</th>
            <th>Subido</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($documents as $d): ?>
        <?php
        $statusBadge = match ($d['status']) {
            'ready'      => 'pp-badge--success',
            'processing' => 'pp-badge--warning',
            'error'      => 'pp-badge--danger',
            default      => 'pp-badge--muted',
        };
        $statusLabel = match ($d['status']) {
            'ready'      => 'Listo',
            'processing' => 'Procesando',
            'error'      => 'Error',
            default      => $d['status'],
        };
        $detailUrl = base_url('admin/documents/' . $d['id']);
        ?>
        <tr class="pp-doc-row">
            <td>
                <a href="<?= e($detailUrl) ?>" class="pp-doc-title-link">
                    <strong><?= e($d['title']) ?></strong>
                </a>
                <div class="pp-doc-filename"><?= e($d['original_filename']) ?></div>
            </td>
            <td><code><?= e(strtoupper($d['file_type'])) ?></code></td>
            <td><span class="pp-badge <?= $statusBadge ?>"><?= e($statusLabel) ?></span></td>
            <td>
                <?php if ((int) $d['text_length'] > 0): ?>
                <span title="Caracteres extraídos"><?= number_format((int) $d['text_length'], 0, ',', '.') ?> car.</span>
                <?php else: ?>
                <span class="pp-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="pp-doc-summary">
                <?php if (!empty($d['summary'])): ?>
                <?= e(mb_strimwidth($d['summary'], 0, 120, '…')) ?>
                <?php else: ?>
                <span class="pp-muted">—</span>
                <?php endif; ?>
            </td>
            <td><small><?= e(substr((string) $d['created_at'], 0, 16)) ?></small></td>
            <td class="pp-doc-row-actions">
                <a href="<?= e($detailUrl) ?>" class="pp-btn pp-btn--sm pp-btn--secondary">Ver</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
