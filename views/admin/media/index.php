<?php
/**
 * @var array $items     filas de media (con uploader)
 * @var string $csrf
 * @var int $maxSize
 * @var array $allowedExt
 */
\Core\View::extend('admin/layout');
$maxMb = round($maxSize / 1024 / 1024);
$accept = 'image/jpeg,image/png,image/webp,image/gif';
?>

<?php \Core\View::start('title'); ?>Medios<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Medios</h2>
    <?php if (\App\Services\ImageBankService::isAvailable()): ?>
        <a href="<?= e(base_url('admin/media/bank')) ?>" class="pp-btn pp-btn--secondary">Buscar en Unsplash</a>
    <?php endif; ?>
</div>

<p class="pp-page-intro">
    Sube imágenes para usar en las secciones de tus páginas.
    Formatos: JPG, PNG, WebP, GIF · máximo <?= (int) $maxMb ?> MB.
    Las imágenes mayores de <?= (int) \App\Services\MediaService::MAX_WIDTH ?>px se redimensionan automáticamente.
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

<form method="POST" action="<?= e(base_url('admin/media')) ?>"
      enctype="multipart/form-data" class="pp-form pp-media-upload">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-row pp-media-upload__row">
        <div class="pp-media-upload__field--file">
            <label class="pp-label" for="pp-media-file">Archivo</label>
            <label class="pp-file-input" id="pp-media-file-wrap">
                <input type="file" id="pp-media-file" name="file" accept="<?= e($accept) ?>" required>
                <span class="pp-file-input__btn">Seleccionar imagen</span>
                <span class="pp-file-input__name">Ningún archivo seleccionado</span>
            </label>
        </div>
        <div class="pp-media-upload__field--alt">
            <label class="pp-label" for="pp-media-alt">Texto alternativo (opcional)</label>
            <input type="text" id="pp-media-alt" name="alt_text" maxlength="500"
                   placeholder="Describe la imagen para accesibilidad">
        </div>
        <div>
            <button type="submit" class="pp-btn pp-btn--primary">Subir</button>
        </div>
    </div>
</form>

<?php if (empty($items)): ?>
    <div class="pp-empty pp-empty--inline">
        <div class="pp-empty__title">Tu galería está vacía</div>
        <div class="pp-empty__text">
            Sube tus propias imágenes aquí o usa <strong>imágenes de relleno</strong> directamente desde el editor de secciones — placeholders profesionales mientras preparas tus fotos.
        </div>
    </div>
<?php else: ?>
    <div class="pp-media-grid">
        <?php foreach ($items as $m):
            $url = base_url(ltrim((string) $m['path'], '/'));
            $sizeKb = max(1, (int) round(((int) $m['file_size']) / 1024));
            $dims = ($m['width'] && $m['height']) ? ((int) $m['width']) . '×' . ((int) $m['height']) : '—';
        ?>
        <div class="pp-media-card">
            <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="pp-media-card__thumb">
                <img src="<?= e($url) ?>" alt="<?= e((string) ($m['alt_text'] ?? '')) ?>">
            </a>
            <div class="pp-media-card__body">
                <div class="pp-media-card__name" title="<?= e((string) $m['original_name']) ?>">
                    <?= e((string) $m['original_name']) ?>
                </div>
                <div class="pp-media-card__meta">
                    <?= e($dims) ?> · <?= e($sizeKb) ?> KB · <?= e(strtoupper((string) explode('/', (string) $m['mime_type'])[1] ?? '')) ?>
                </div>
                <form method="POST" action="<?= e(base_url('admin/media/' . (int) $m['id'] . '/alt')) ?>" class="pp-media-card__alt-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="text" name="alt_text" value="<?= e((string) ($m['alt_text'] ?? '')) ?>"
                           maxlength="500" placeholder="Texto alternativo" class="pp-media-card__alt-input">
                    <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Guardar</button>
                </form>
                <form method="POST" action="<?= e(base_url('admin/media/' . (int) $m['id'] . '/delete')) ?>"
                      class="pp-media-card__delete-form" onsubmit="return confirm('¿Borrar esta imagen?');">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="pp-btn pp-btn--ghost pp-btn--danger-text">Borrar</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    var input = document.getElementById('pp-media-file');
    if (!input) return;
    var wrap = document.getElementById('pp-media-file-wrap');
    var name = wrap.querySelector('.pp-file-input__name');
    input.addEventListener('change', function () {
        if (input.files && input.files.length) {
            name.textContent = input.files[0].name;
            wrap.classList.add('has-file');
        } else {
            name.textContent = 'Ningún archivo seleccionado';
            wrap.classList.remove('has-file');
        }
    });
})();
</script>
<?php \Core\View::end(); ?>
