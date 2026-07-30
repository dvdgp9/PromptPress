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

<?php \Core\View::start('title'); ?>Medios<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Medios</h2>
    <?php if (\App\Services\ImageBankService::isAvailable()): ?>
        <a href="<?= e(base_url('admin/media/bank')) ?>" class="pp-btn pp-btn--secondary">Buscar en Unsplash</a>
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
            <?= $missingAlt === 1 ? 'Tienes 1 imagen sin descripción' : 'Tienes ' . (int) $missingAlt . ' imágenes sin descripción' ?>
        </strong>
        <span id="pp-describe-hint">Sin descripción, al crear páginas no sabemos qué muestran tus fotos y tiende a usarse el banco de imágenes. También es lo que oye quien usa un lector de pantalla.</span>
    </div>
    <button type="button" class="pp-btn pp-btn--primary" id="pp-describe-btn">Describir con IA</button>
</div>
<?php endif; ?>

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
      enctype="multipart/form-data" class="pp-form pp-media-upload" id="pp-media-form"
      data-upload-url="<?= e(base_url('admin/media')) ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-row pp-media-upload__row">
        <div class="pp-media-upload__field--file">
            <label class="pp-label" for="pp-media-file">Archivo</label>
            <label class="pp-file-input" id="pp-media-file-wrap">
                <input type="file" id="pp-media-file" name="file[]" accept="<?= e($accept) ?>" multiple required>
                <span class="pp-file-input__btn">Seleccionar imágenes</span>
                <span class="pp-file-input__name">Ningún archivo seleccionado</span>
            </label>
        </div>
        <div class="pp-media-upload__field--alt">
            <label class="pp-label" for="pp-media-alt">Texto alternativo (opcional)</label>
            <input type="text" id="pp-media-alt" name="alt_text" maxlength="500"
                   placeholder="Describe la imagen para accesibilidad">
            <small class="pp-media-upload__hint">Puedes elegir varias imágenes a la vez. Si subes varias, este texto se aplica a todas.</small>
        </div>
        <div>
            <button type="submit" class="pp-btn pp-btn--primary" id="pp-media-submit">Subir</button>
        </div>
    </div>
    <ol class="pp-media-queue" id="pp-media-queue" hidden></ol>
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
                    title.textContent = 'No he podido describir todas';
                    hint.textContent = data.error || 'Inténtalo de nuevo en un momento.';
                    btn.disabled = false;
                    btn.textContent = 'Volver a intentarlo';
                    return;
                }
                var remaining = data.remaining || 0;
                // Solo quedan imágenes cuyo archivo ya no está: no seguimos.
                // El número lo da el servidor (`remaining`): acumularlo por
                // lotes lo contaba varias veces, porque las que no se pueden
                // describir vuelven a salir en el lote siguiente.
                if (data.blocked) {
                    title.textContent = done > 0
                        ? (done + ' ' + plural(done, 'imagen descrita', 'imágenes descritas'))
                        : 'No he podido describir ninguna';
                    hint.textContent = remaining + ' ' + plural(remaining, 'imagen no se puede describir', 'imágenes no se pueden describir')
                        + ': su archivo ya no está en el servidor. '
                        + plural(remaining, 'Puedes borrarla o volver a subirla.', 'Puedes borrarlas o volver a subirlas.');
                    btn.remove();
                    return;
                }
                if (remaining === 0) {
                    title.textContent = done > 0
                        ? (done + ' ' + plural(done, 'imagen descrita', 'imágenes descritas'))
                        : 'Todas tus imágenes tienen descripción';
                    hint.textContent = 'Revísalas y ajusta a mano lo que no te cuadre: la descripción es una propuesta.';
                    btn.remove();
                    return;
                }
                title.textContent = 'Describiendo… ' + done + ' de ' + total;
                hint.textContent = 'Puedes seguir trabajando; lo descrito ya queda guardado.';
                nextBatch();
            })
            .catch(function () {
                title.textContent = 'Se ha cortado la conexión';
                hint.textContent = 'Lo descrito hasta ahora está guardado. Puedes continuar cuando quieras.';
                btn.disabled = false;
                btn.textContent = 'Continuar';
            });
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Describiendo…';
        title.textContent = 'Describiendo… 0 de ' + total;
        hint.textContent = 'Puedes seguir trabajando; lo descrito ya queda guardado.';
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
        else if (n > 1) name.textContent = n + ' imágenes seleccionadas';
        else name.textContent = 'Ningún archivo seleccionado';
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
            li.innerHTML = '<span class="pp-media-queue__name"></span><span class="pp-media-queue__state">En espera</span>';
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
                summary.textContent = okCount + (okCount === 1 ? ' imagen subida' : ' imágenes subidas') +
                    ' · ' + failCount + (failCount === 1 ? ' con problemas' : ' con problemas') +
                    '. Recarga la página para ver las que sí entraron.';
                queue.appendChild(summary);
                return;
            }

            var file = files[i];
            setState(rows[i], 'is-uploading', 'Subiendo…');

            var fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('file', file);
            fd.append('alt_text', altIn ? altIn.value : '');

            fetch(form.dataset.uploadUrl, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Respuesta inesperada del servidor.' }; }); })
                .then(function (data) {
                    if (data && data.ok) { okCount++; setState(rows[i], 'is-done', 'Subida'); }
                    else { failCount++; setState(rows[i], 'is-error', (data && data.error) || 'No se pudo subir'); }
                })
                .catch(function () { failCount++; setState(rows[i], 'is-error', 'Sin conexión'); })
                .finally(function () { uploadAt(i + 1); });
        }

        uploadAt(0);
    });
})();
</script>
<?php \Core\View::end(); ?>
