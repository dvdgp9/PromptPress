<?php
/**
 * ASST-V2 F2 — Configuración del chat del asistente para el navegador.
 *
 * Entrada:
 *   $assistantMode     'page' (/admin/assistant) o 'drawer' (panel lateral)
 *   $conversation      (page) conversación a retomar, ya cargada
 *   $assistantContext  (drawer) pantalla que se está viendo, o null
 * Salida:
 *   $assistantAssets   scripts del chat, en orden (el panel los carga al abrirse)
 */
$assistantMode = $assistantMode ?? 'page';
$assetUrl = static function (string $relative): string {
    $path = PP_ROOT . '/' . $relative;
    return base_url($relative) . '?v=' . (file_exists($path) ? filemtime($path) : PP_VERSION);
};
$assistantAssets = [
    $assetUrl('admin/assets/js/assistant-rich-composer.js'),
    $assetUrl('admin/assets/js/assistant-pdf-pages.js'),
    $assetUrl('admin/assets/js/assistant.js'),
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<script>
window.PPA = {
    mode: <?= json_encode($assistantMode) ?>,
    csrf: <?= json_encode(\Core\CSRF::token()) ?>,
    baseUrl: <?= json_encode(base_url('admin/assistant')) ?>,
    studioUrl: <?= json_encode(base_url('admin/canvas/')) ?>,
    formsUrl: <?= json_encode(base_url('admin/formularios/')) ?>,
    activityUrl: <?= json_encode(base_url('admin/actividad')) ?>,
    <?php if ($assistantMode === 'page'): ?>
    conversation: <?= json_encode($conversation ?? null, $jsonFlags) ?>,
    <?php else: ?>
    // En el panel la conversación se pide al abrirlo: no viaja en cada página.
    lazyConversation: true,
    context: <?= json_encode($assistantContext ?? null, $jsonFlags) ?>,
    <?php endif; ?>
    maxSize: <?= (int) \App\Controllers\Admin\DocumentController::MAX_SIZE ?>,
    allowedExt: <?= json_encode(\App\Controllers\Admin\DocumentController::ALLOWED_EXT) ?>,
    richMaxChars: <?= (int) \App\Services\AssistantContentNormalizer::DEFAULT_MAX_CHARS ?>,
    richMaxImages: <?= (int) \App\Services\AssistantContentNormalizer::DEFAULT_MAX_IMAGES ?>,
    richMaxImageBytes: <?= (int) \App\Services\AssistantContentNormalizer::DEFAULT_MAX_IMAGE_BYTES ?>,
    mediaUploadUrl: <?= json_encode(base_url('admin/media')) ?>,
    remoteImportUrl: <?= json_encode(base_url('admin/media/import-remote')) ?>,
    mediaLibraryUrl: <?= json_encode(base_url('admin/media/library')) ?>,
    // ASST-CONV AC3 — pdf.js vendorizado (versión fija; ver vendor/pdfjs/VERSION.txt).
    pdfLibUrl: <?= json_encode(base_url('admin/assets/vendor/pdfjs/pdf.min.js') . '?v=6.4.299') ?>,
    pdfWorkerUrl: <?= json_encode(base_url('admin/assets/vendor/pdfjs/pdf.worker.min.js') . '?v=6.4.299') ?>
};
</script>
