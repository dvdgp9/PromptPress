<?php
/**
 * @var array $items     filas de media (con uploader)
 * @var string $csrf
 * @var int $maxSize
 * @var array $allowedExt
 * @var int $missingAlt  imágenes sin texto alternativo (STUDIO-2 C4b)
 */
\Core\View::extend('admin/layout');
$maxMb = round($maxSize / 1024 / 1024);
$accept = 'image/jpeg,image/png,image/webp,image/gif';
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.media')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('nav.media')) ?></h2>
    <?php if (\App\Services\ImageBankService::isAvailable()): ?>
        <a href="<?= e(base_url('admin/media/bank')) ?>" class="pp-btn pp-btn--secondary"><?= e(__('media.search_unsplash')) ?></a>
    <?php endif; ?>
</div>

<?php
// STUDIO-2 C4b — Las imágenes sin descripción no se pueden aprovechar bien: la
// IA no sabe qué son al elegir fotos para una página, y quien navega con lector
// de pantalla no las oye. Este aviso solo aparece si de verdad faltan.
$missingAlt = (int) ($missingAlt ?? 0);
?>
<?php if ($missingAlt > 0): ?>
<div class="pp-media-describe" id="pp-media-describe"
     data-url="<?= e(base_url('admin/media/describe-missing')) ?>"
     data-missing="<?= (int) $missingAlt ?>">
    <div class="pp-media-describe__text">
        <strong id="pp-describe-title">
            <?= e(__($missingAlt === 1 ? 'media.missing_alt_one' : 'media.missing_alt_other', ['n' => $missingAlt])) ?>
        </strong>
        <span id="pp-describe-hint"><?= e(__('media.describe_hint')) ?></span>
    </div>
    <button type="button" class="pp-btn pp-btn--primary" id="pp-describe-btn"><?= e(__('media.describe_button')) ?></button>
</div>
<?php endif; ?>

<p class="pp-page-intro">
    <?= e(__('media.intro', ['mb' => (int) $maxMb, 'ancho' => (int) \App\Services\MediaService::MAX_WIDTH])) ?>
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
      enctype="multipart/form-data" class="pp-form pp-media-upload" id="pp-media-form"
      data-upload-url="<?= e(base_url('admin/media')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-row pp-media-upload__row">
        <div class="pp-media-upload__field--file">
            <label class="pp-label" for="pp-media-file"><?= e(__('documents.file')) ?></label>
            <label class="pp-file-input" id="pp-media-file-wrap">
                <input type="file" id="pp-media-file" name="file[]" accept="<?= e($accept) ?>" multiple required>
                <span class="pp-file-input__btn"><?= e(__('media.select_images')) ?></span>
                <span class="pp-file-input__name"><?= e(__('onboarding.type.no_files')) ?></span>
            </label>
        </div>
        <div class="pp-media-upload__field--alt">
            <label class="pp-label" for="pp-media-alt"><?= e(__('media.alt_label')) ?></label>
            <input type="text" id="pp-media-alt" name="alt_text" maxlength="500"
                   placeholder="<?= e(__('media.alt_placeholder')) ?>">
            <small class="pp-media-upload__hint"><?= e(__('media.upload_hint')) ?></small>
        </div>
        <div>
            <button type="submit" class="pp-btn pp-btn--primary" id="pp-media-submit"><?= e(__('media.upload')) ?></button>
        </div>
    </div>
    <ol class="pp-media-queue" id="pp-media-queue" hidden></ol>
</form>

<?php if (empty($items)): ?>
    <div class="pp-empty pp-empty--inline">
        <div class="pp-empty__title"><?= e(__('media.empty_title')) ?></div>
        <div class="pp-empty__text">
            <?= __('media.empty_text.html') ?>
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
                           maxlength="500" placeholder="<?= e(__('media.alt_short')) ?>" class="pp-media-card__alt-input">
                    <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('common.save')) ?></button>
                </form>
                <form method="POST" action="<?= e(base_url('admin/media/' . (int) $m['id'] . '/delete')) ?>"
                      class="pp-media-card__delete-form" onsubmit="return confirm(<?= e(json_encode(__('media.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="pp-btn pp-btn--ghost pp-btn--danger-text"><?= e(__('common.delete_short')) ?></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php \Core\View::start('scripts'); ?>
<script>
// STUDIO-2 C4b — "Describir con IA": va por lotes cortos y cuenta lo que queda.
// Un solo POST para 40 imágenes moriría por timeout; así el usuario ve avance
// desde la primera y puede irse cuando quiera (lo hecho queda guardado).
(function () {
    var box = document.getElementById('pp-media-describe');
    if (!box) return;
    var btn = document.getElementById('pp-describe-btn');
    var title = document.getElementById('pp-describe-title');
    var hint = document.getElementById('pp-describe-hint');
    var form = document.getElementById('pp-media-form');
    var csrf = form ? form.querySelector('[name="_csrf"]').value : '';
    var total = parseInt(box.dataset.missing, 10) || 0;
    var done = 0;
    var stop = false;

    function plural(n, singular, plural) { return n === 1 ? singular : plural; }

    // Refleja la descripción en la tarjeta correspondiente, sin recargar.
    function fillCard(item) {
        var input = document.querySelector('form[action$="/media/' + item.id + '/alt"] input[name="alt_text"]');
        if (input && !input.value) {
            input.value = item.alt_text;
            input.classList.add('is-filled');
        }
    }

    function nextBatch() {
        if (stop) return;
        var fd = new FormData();
        fd.append('_csrf', csrf);
        fetch(box.dataset.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (data) {
                (data.items || []).forEach(fillCard);
                done += (data.described || 0);
                if (!data.ok) {
                    title.textContent = pp.t('js.media.describe_partial');
                    hint.textContent = data.error || pp.t('js.media.try_again');
                    btn.disabled = false;
                    btn.textContent = pp.t('js.media.retry');
                    return;
                }
                var remaining = data.remaining || 0;
                // Solo quedan imágenes cuyo archivo ya no está: no seguimos.
                // El número lo da el servidor (`remaining`): acumularlo por
                // lotes lo contaba varias veces, porque las que no se pueden
                // describir vuelven a salir en el lote siguiente.
                if (data.blocked) {
                    title.textContent = done > 0
                        ? pp.t(done === 1 ? 'js.media.described_one' : 'js.media.described_other', { n: done })
                        : pp.t('js.media.described_none');
                    hint.textContent = pp.t(remaining === 1 ? 'js.media.missing_file_one' : 'js.media.missing_file_other', { n: remaining });
                    btn.remove();
                    return;
                }
                if (remaining === 0) {
                    title.textContent = done > 0
                        ? pp.t(done === 1 ? 'js.media.described_one' : 'js.media.described_other', { n: done })
                        : pp.t('js.media.all_described');
                    hint.textContent = pp.t('js.media.review_hint');
                    btn.remove();
                    return;
                }
                title.textContent = pp.t('js.media.describing_progress', { hechas: done, total: total });
                hint.textContent = pp.t('js.media.keep_working');
                nextBatch();
            })
            .catch(function () {
                title.textContent = pp.t('js.media.connection_lost');
                hint.textContent = pp.t('js.media.saved_so_far');
                btn.disabled = false;
                btn.textContent = pp.t('js.media.continue');
            });
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = pp.t('js.media.describing');
        title.textContent = pp.t('js.media.describing_progress', { hechas: 0, total: total });
        hint.textContent = pp.t('js.media.keep_working');
        stop = false;
        nextBatch();
    });
})();
</script>
<script>
(function () {
    var input = document.getElementById('pp-media-file');
    if (!input) return;
    var form   = document.getElementById('pp-media-form');
    var wrap   = document.getElementById('pp-media-file-wrap');
    var name   = wrap.querySelector('.pp-file-input__name');
    var queue  = document.getElementById('pp-media-queue');
    var submit = document.getElementById('pp-media-submit');
    var altIn  = document.getElementById('pp-media-alt');
    var csrf   = form.querySelector('[name="_csrf"]').value;

    input.addEventListener('change', function () {
        var n = input.files ? input.files.length : 0;
        if (n === 1) name.textContent = input.files[0].name;
        else if (n > 1) name.textContent = pp.t('js.media.n_selected', { n: n });
        else name.textContent = pp.t('js.onb.no_file');
        wrap.classList.toggle('has-file', n > 0);
    });

    // Subida de una en una: así un archivo rechazado no tumba la tanda y no
    // chocamos con el límite de tamaño por petición del hosting.
    form.addEventListener('submit', function (e) {
        var files = input.files ? Array.prototype.slice.call(input.files) : [];
        if (files.length === 0) return;           // el navegador ya avisa (required)
        if (files.length === 1 && !window.FormData) return; // sin JS moderno, envío clásico

        e.preventDefault();
        submit.disabled = true;
        queue.hidden = false;
        queue.innerHTML = '';

        var rows = files.map(function (file) {
            var li = document.createElement('li');
            li.className = 'pp-media-queue__item is-waiting';
            li.innerHTML = '<span class="pp-media-queue__name"></span><span class="pp-media-queue__state">' + pp.t('js.media.waiting') + '</span>';
            li.querySelector('.pp-media-queue__name').textContent = file.name;
            queue.appendChild(li);
            return li;
        });

        var okCount = 0, failCount = 0;

        function setState(li, cls, text) {
            li.className = 'pp-media-queue__item ' + cls;
            li.querySelector('.pp-media-queue__state').textContent = text;
        }

        function uploadAt(i) {
            if (i >= files.length) {
                submit.disabled = false;
                if (failCount === 0) {
                    // Todo bien: recargamos para ver la galería actualizada.
                    window.location.reload();
                    return;
                }
                var summary = document.createElement('li');
                summary.className = 'pp-media-queue__summary';
                summary.textContent = pp.t(okCount === 1 ? 'js.media.uploaded_one' : 'js.media.uploaded_other', { n: okCount })
                    + ' · ' + pp.t('js.media.with_problems', { n: failCount })
                    + '. ' + pp.t('js.media.reload_hint');
                queue.appendChild(summary);
                return;
            }

            var file = files[i];
            setState(rows[i], 'is-uploading', pp.t('js.doc.uploading_short'));

            var fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('file', file);
            fd.append('alt_text', altIn ? altIn.value : '');

            fetch(form.dataset.uploadUrl, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: pp.t('js.media.bad_response') }; }); })
                .then(function (data) {
                    if (data && data.ok) { okCount++; setState(rows[i], 'is-done', pp.t('js.media.done')); }
                    else { failCount++; setState(rows[i], 'is-error', (data && data.error) || pp.t('js.media.upload_failed')); }
                })
                .catch(function () { failCount++; setState(rows[i], 'is-error', pp.t('js.media.no_connection')); })
                .finally(function () { uploadAt(i + 1); });
        }

        uploadAt(0);
    });
})();
</script>
<?php \Core\View::end(); ?>
