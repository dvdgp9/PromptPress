<?php
/**
 * FEAT-5 F5-T1 — Asistente central del sitio.
 *
 * @var string $csrf
 * @var int $maxSize
 * @var array $allowedExt
 */
\Core\View::extend('admin/layout');
$maxMb = round($maxSize / 1024 / 1024);
$accept = '.' . implode(',.', $allowedExt);
?>

<?php \Core\View::start('title'); ?>Asistente<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<?php $jsPath = PP_ROOT . '/admin/assets/js/assistant.js'; $jsVer = file_exists($jsPath) ? filemtime($jsPath) : PP_VERSION; ?>
<script>
window.PPA = {
    csrf: <?= json_encode($csrf) ?>,
    baseUrl: <?= json_encode(base_url('admin/assistant')) ?>,
    studioUrl: <?= json_encode(base_url('admin/canvas/')) ?>,
    maxSize: <?= (int) $maxSize ?>,
    allowedExt: <?= json_encode($allowedExt) ?>
};
</script>
<script src="<?= e(base_url('admin/assets/js/assistant.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Asistente del sitio</h2>
</div>

<p class="pp-page-intro">
    Pídeme cambios sobre cualquier página de la web, o adjunta un documento con las peticiones del cliente.
    Primero te propondré un plan con lo que voy a tocar (y lo que no sea viable), y solo aplicaré los cambios cuando confirmes.
    Nada se publica automáticamente: cada cambio queda como borrador con su historial.
</p>

<div class="ppa-chat" id="ppa-chat">
    <div class="ppa-thread" id="ppa-thread">
        <div class="ppa-msg ppa-msg--assistant">
            <div class="ppa-msg__bubble">
                ¡Hola! Cuéntame qué cambios necesita la web. Por ejemplo:
                «Cambia el teléfono en toda la web al 600 123 456» o
                «Adjunto el documento con los nuevos textos de Servicios».
            </div>
        </div>
    </div>

    <div class="ppa-composer">
        <div class="ppa-attachment" id="ppa-attachment" hidden>
            <span class="ppa-attachment__icon">📄</span>
            <span class="ppa-attachment__name" id="ppa-attachment-name"></span>
            <span class="ppa-attachment__meta" id="ppa-attachment-meta"></span>
            <button type="button" class="ppa-attachment__toggle" id="ppa-attachment-toggle">Ver texto</button>
            <button type="button" class="ppa-attachment__remove" id="ppa-attachment-remove" title="Quitar documento">&times;</button>
        </div>
        <pre class="ppa-attachment-preview" id="ppa-attachment-preview" hidden></pre>

        <div class="ppa-composer__row">
            <button type="button" class="ppa-composer__attach" id="ppa-attach-btn"
                    title="Adjuntar documento (PDF, DOCX o TXT · máx. <?= (int) $maxMb ?> MB)">📎</button>
            <input type="file" id="ppa-file-input" accept="<?= e($accept) ?>" hidden>
            <textarea class="ppa-composer__input" id="ppa-input" rows="2" maxlength="4000"
                      placeholder="Describe los cambios que quieres hacer en la web…"></textarea>
            <button type="button" class="pp-btn pp-btn--primary ppa-composer__send" id="ppa-send" disabled>Proponer plan</button>
        </div>
        <div class="ppa-composer__hint" id="ppa-hint">
            Puedes escribir la petición, adjuntar un documento, o ambas cosas.
        </div>
    </div>
</div>
