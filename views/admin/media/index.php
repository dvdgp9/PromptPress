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
      enctype="multipart/form-data" class="pp-form" style="margin-bottom:24px">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-row" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1 1 280px">
            <label class="pp-label" for="pp-media-file">Archivo</label>
            <input type="file" id="pp-media-file" name="file" accept="<?= e($accept) ?>" required>
        </div>
        <div style="flex:2 1 320px">
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
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
        <?php foreach ($items as $m):
            $url = base_url(ltrim((string) $m['path'], '/'));
            $sizeKb = max(1, (int) round(((int) $m['file_size']) / 1024));
            $dims = ($m['width'] && $m['height']) ? ((int) $m['width']) . '×' . ((int) $m['height']) : '—';
        ?>
        <div class="pp-card" style="border:1px solid var(--pp-border);border-radius:8px;overflow:hidden;background:var(--pp-surface);display:flex;flex-direction:column">
            <a href="<?= e($url) ?>" target="_blank" rel="noopener" style="display:block;background:#f1f5f9;aspect-ratio:4/3;overflow:hidden">
                <img src="<?= e($url) ?>" alt="<?= e((string) ($m['alt_text'] ?? '')) ?>"
                     style="width:100%;height:100%;object-fit:cover;display:block">
            </a>
            <div style="padding:10px 12px;display:flex;flex-direction:column;gap:6px;flex:1">
                <div style="font-size:.8rem;color:var(--pp-text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string) $m['original_name']) ?>">
                    <?= e((string) $m['original_name']) ?>
                </div>
                <div style="font-size:.75rem;color:var(--pp-text-muted)">
                    <?= e($dims) ?> · <?= e($sizeKb) ?> KB · <?= e(strtoupper((string) explode('/', (string) $m['mime_type'])[1] ?? '')) ?>
                </div>
                <form method="POST" action="<?= e(base_url('admin/media/' . (int) $m['id'] . '/alt')) ?>" class="pp-form" style="margin-top:auto">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="text" name="alt_text" value="<?= e((string) ($m['alt_text'] ?? '')) ?>"
                           maxlength="500" placeholder="Texto alternativo"
                           style="width:100%;font-size:.85rem;padding:6px 8px;border:1px solid var(--pp-border);border-radius:4px;margin-bottom:6px">
                    <button type="submit" class="pp-btn pp-btn--ghost" style="font-size:.8rem;padding:6px 10px;width:100%">Guardar alt</button>
                </form>
                <form method="POST" action="<?= e(base_url('admin/media/' . (int) $m['id'] . '/delete')) ?>"
                      style="margin:6px 0 0" onsubmit="return confirm('¿Borrar esta imagen?');">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="pp-btn pp-btn--ghost" style="font-size:.8rem;padding:6px 10px;width:100%;color:#b91c1c">Borrar</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
