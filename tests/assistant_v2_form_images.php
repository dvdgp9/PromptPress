<?php

declare(strict_types=1);

// ASST-V2 F6b — imagen de referencia para formularios en el panel. Sin IA: la
// entrada que recibiría DESIGN_FORM, que el trabajo conserva las imágenes del
// plan firmado en los items de formulario y que la vista previa solo usa las
// imágenes del material firmado (sitio 1; lo creado se borra al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantMediaReferences;
use App\Services\AssistantSourceEnvelope;
use App\Services\FormAssistantEditor;
use App\Services\FormStore;
use App\Services\SiteAssistantJobs;
use Core\Database;

$failed = 0;
function checkV2F6b(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Entrada del diseñador ---------------------------------------------------
$plain = FormAssistantEditor::designInput('Crea «Encuesta»', 'Idioma: español', []);
checkV2F6b('no_images_no_vision', !isset($plain['_images']) && $plain['description'] === 'Crea «Encuesta»');
$img = ['mime' => 'image/png', 'data' => 'AAAA', 'media_id' => 5];
$withImages = FormAssistantEditor::designInput('Copia este formulario', 'Idioma: español', [$img, $img, $img, $img]);
checkV2F6b('images_go_to_vision', count($withImages['_images'] ?? []) === 3 && $withImages['_images'][0]['mime'] === 'image/png');
checkV2F6b('context_says_image_is_reference', str_contains($withImages['extra_context'], 'imagen') && !str_contains($plain['extra_context'], 'imagen'),
    $withImages['extra_context']);

// --- Vista previa: solo material firmado --------------------------------------
$site = 1;
checkV2F6b('preview_without_token_has_no_images', SiteAssistantJobs::previewReferenceImages($site, '', [5]) === []);
checkV2F6b('preview_with_bad_token_has_no_images', SiteAssistantJobs::previewReferenceImages($site, 'v1.roto.firma', [5]) === []);
// Una imagen real del sitio (solo se lee) y una que no existe.
$real = Database::selectOne("SELECT id FROM media WHERE site_id = ? AND mime_type IN ('image/png','image/jpeg','image/webp') ORDER BY id LIMIT 1", [$site]);
$realId = (int) ($real['id'] ?? 0);
$bundle = AssistantMediaReferences::resolve(AssistantSourceEnvelope::sanitizeBundle(['blocks' => [], 'media' => [
    ['ref' => 'IMG-1', 'media_id' => $realId, 'role' => 'reference'],
    ['ref' => 'IMG-2', 'media_id' => 777777, 'role' => 'reference'],
]]), $site);
$token = AssistantSourceEnvelope::issue($site, $bundle, []);
if ($realId > 0) {
    $images = SiteAssistantJobs::previewReferenceImages($site, $token, [$realId, 777777]);
    checkV2F6b('preview_uses_signed_reference', count($images) === 1 && (int) $images[0]['media_id'] === $realId && $images[0]['data'] !== '',
        json_encode(array_map(static fn ($i) => array_diff_key($i, ['data' => 1]), $images)));
} else {
    echo 'SKIP preview_uses_signed_reference (sin imágenes en el sitio 1)' . PHP_EOL;
}
checkV2F6b('preview_ignores_unlisted_media', SiteAssistantJobs::previewReferenceImages($site, $token, [123]) === []);
$assetToken = AssistantSourceEnvelope::issue($site, array_replace($bundle, ['media' => array_map(static fn ($m) => array_replace($m, ['role' => 'publishable_asset']), $bundle['media'])]), []);
checkV2F6b('preview_skips_assets', SiteAssistantJobs::previewReferenceImages($site, $assetToken, [$realId]) === []);

// --- Trabajo: los items de formulario conservan sus imágenes ------------------
$jobIds = [];
$formIds = [];
try {
    $formId = FormStore::create($site, FormStore::defaultContact($site));
    $formIds[] = $formId;
    $source = ['bundle' => $bundle, 'authorized_item_hashes' => []];
    $create = ['capability_id' => 'forms.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'form_id' => 0,
        'section' => '', 'instruction' => 'Crea «Encuesta» como el de la foto', 'source_block_ids' => [], 'media_ids' => [$realId, 777777, 999]];
    $edit = ['capability_id' => 'forms.edit', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'form_id' => $formId,
        'section' => '', 'instruction' => 'Deja las preguntas como en la foto', 'source_block_ids' => [], 'media_ids' => [$realId]];
    $source['authorized_item_hashes'] = [AssistantSourceEnvelope::itemFingerprint($create), AssistantSourceEnvelope::itemFingerprint($edit)];
    $created = SiteAssistantJobs::createJob($site, 'Prueba F6b', 'Con foto', [$create, $edit], null, $source);
    checkV2F6b('job_created', $created['ok'] === true, json_encode($created, JSON_UNESCAPED_UNICODE));
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    $rows = Database::select('SELECT capability_id, media_ids_json FROM assistant_job_items WHERE job_id = ? ORDER BY sort_order', [$jobId]);
    $media = array_column($rows, 'media_ids_json', 'capability_id');
    checkV2F6b('create_keeps_signed_media_only', json_decode((string) ($media['forms.create'] ?? 'null'), true) === [$realId], json_encode($rows));
    checkV2F6b('edit_keeps_signed_media', json_decode((string) ($media['forms.edit'] ?? 'null'), true) === [$realId], json_encode($rows));

    // Sin material firmado, los ids que mande el navegador no cuentan.
    $bare = SiteAssistantJobs::createJob($site, 'Prueba F6b', 'Sin material', [array_merge($create, ['media_ids' => []])], null);
    $jobIds[] = (int) ($bare['job']['id'] ?? 0);
    $row = Database::selectOne('SELECT media_ids_json FROM assistant_job_items WHERE job_id = ? LIMIT 1', [(int) ($bare['job']['id'] ?? 0)]);
    checkV2F6b('no_source_no_media', $bare['ok'] === true && json_decode((string) ($row['media_ids_json'] ?? 'null'), true) === []);
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    foreach ($formIds as $id) Database::execute("DELETE FROM page_sections WHERE id = ? AND section_type = 'form'", [$id]);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
