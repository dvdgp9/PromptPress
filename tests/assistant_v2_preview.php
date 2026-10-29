<?php

declare(strict_types=1);

// ASST-V2 F3 — riesgo de cada capacidad y vista previa de formularios.
// La parte con BD crea un formulario y un trabajo de prueba en el sitio 1 y
// los borra al terminar. No llama a la IA: aplica una propuesta ya firmada.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantCapabilityRegistry;
use App\Services\FormAssistantEditor;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F3(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Riesgo en el registro ----------------------------------------------------
$catalog = AssistantCapabilityRegistry::catalogForState(['commerce' => ['available' => true, 'enabled' => true]]);
$byId = AssistantCapabilityRegistry::byId($catalog);
$validRisk = array_filter($catalog, static fn (array $c): bool => in_array($c['risk'], ['read', 'draft', 'live', 'sensitive'], true));
checkV2F3('every_capability_has_known_risk', count($validRisk) === count($catalog));
checkV2F3('canvas_edit_is_draft_and_undoable', $byId['pages.canvas.edit']['risk'] === 'draft' && $byId['pages.canvas.edit']['undo'] === true);
checkV2F3('forms_edit_is_live_and_undoable', $byId['forms.edit']['risk'] === 'live' && $byId['forms.edit']['undo'] === true);
checkV2F3('legal_and_commerce_are_sensitive', $byId['legal.content']['risk'] === 'sensitive' && $byId['commerce.manage']['risk'] === 'sensitive');
checkV2F3('manual_capability_not_undoable', $byId['chrome.manage']['undo'] === false);
checkV2F3('prompt_lists_risk', str_contains(AssistantCapabilityRegistry::renderForPrompt($catalog), 'forms.edit | ') && str_contains(AssistantCapabilityRegistry::renderForPrompt($catalog), 'riesgo=live'));

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [[
    'capability_id' => 'forms.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'form_id' => 7, 'page_id' => 0, 'instruction' => 'Un desplegable de tratamientos',
]], [], $catalog, null, [7 => ['id' => 7, 'heading' => 'Pide información', 'fields' => [], 'pages' => [], 'translated' => []]]);
checkV2F3('plan_item_carries_risk', ($items[0]['risk'] ?? '') === 'live' && ($items[0]['undo'] ?? null) === true, json_encode($items[0]));

// --- Cambios pregunta a pregunta ----------------------------------------------
$before = [
    'heading' => 'Pide información', 'description' => '', 'submit_text' => 'Enviar',
    'fields' => [
        ['label' => 'Nombre', 'name' => 'nombre', 'field_type' => 'text', 'required' => '1'],
        ['label' => 'Cirugía pecho', 'name' => 'cirugia_pecho', 'field_type' => 'checkbox', 'required' => '0'],
        ['label' => 'Teléfono', 'name' => 'telefono', 'field_type' => 'tel', 'required' => '0'],
    ],
];
$after = [
    'heading' => 'Pide información', 'description' => '', 'submit_text' => 'Quiero información',
    'fields' => [
        ['label' => 'Nombre', 'name' => 'nombre', 'field_type' => 'text', 'required' => '1'],
        ['label' => 'Teléfono', 'name' => 'telefono', 'field_type' => 'tel', 'required' => '1'],
        ['label' => 'Tratamiento de interés', 'name' => 'tratamiento', 'field_type' => 'select', 'required' => '1',
         'options' => ['Cirugía pecho', 'Abdominoplastia', 'Balón gástrico']],
    ],
];
$diff = FormAssistantEditor::diff($before, $after);
$kinds = array_column($diff, 'kind', 'label');
checkV2F3('diff_detects_added', ($kinds['Tratamiento de interés'] ?? '') === 'added', json_encode($diff, JSON_UNESCAPED_UNICODE));
checkV2F3('diff_detects_removed', ($kinds['Cirugía pecho'] ?? '') === 'removed');
checkV2F3('diff_detects_now_required', ($kinds['Teléfono'] ?? '') === 'changed');
checkV2F3('diff_detects_submit_text', ($kinds[__('asst.diff.submit')] ?? '') === 'text');
checkV2F3('diff_ignores_untouched', !isset($kinds['Nombre']));
$many = $before;
foreach (['A', 'B', 'C', 'D'] as $x) $many['fields'][] = ['label' => 'Casilla ' . $x, 'name' => 'c' . $x, 'field_type' => 'checkbox', 'required' => '0'];
$grouped = array_values(array_filter(FormAssistantEditor::diff($many, $after), static fn (array $c): bool => $c['kind'] === 'removed'));
checkV2F3('diff_groups_many_removed', count($grouped) === 1 && str_contains($grouped[0]['detail'], 'Casilla D')
    && $grouped[0]['label'] === __('asst.diff.removed_n', ['n' => '5']), json_encode($grouped, JSON_UNESCAPED_UNICODE));
checkV2F3('diff_empty_when_equal', FormAssistantEditor::diff($before, $before) === []);
$outline = FormAssistantEditor::outline(['fields' => [['label' => 'X', 'name' => 'x', 'field_type' => 'select', 'required' => '1', 'options' => "Uno\nDos\n"]]]);
checkV2F3('outline_reads_stored_shape', $outline['fields'][0]['required'] === true && $outline['fields'][0]['options'] === ['Uno', 'Dos']
    && $outline['fields'][0]['type'] === 'select', json_encode($outline));
$preview = FormAssistantEditor::preview($before, $after);
checkV2F3('preview_has_both_sides', count($preview['before']['fields']) === 3 && count($preview['after']['fields']) === 3 && $preview['changes'] !== []);

// --- Propuesta firmada ----------------------------------------------------------
$token = FormAssistantEditor::sealProposal(1, 7, 'Un desplegable', $after, 'abc');
$opened = FormAssistantEditor::openProposal($token, 1, 7, ' Un desplegable ');
checkV2F3('proposal_roundtrip', ($opened['content']['fields'][2]['name'] ?? '') === 'tratamiento' && ($opened['base_hash'] ?? '') === 'abc');
checkV2F3('proposal_rejects_other_instruction', FormAssistantEditor::openProposal($token, 1, 7, 'Otra cosa') === null);
checkV2F3('proposal_rejects_other_form', FormAssistantEditor::openProposal($token, 1, 8, 'Un desplegable') === null);
checkV2F3('proposal_rejects_other_site', FormAssistantEditor::openProposal($token, 2, 7, 'Un desplegable') === null);
$parts = explode('.', $token);
$tampered = $parts[0] . '.' . $parts[1] . 'A.' . $parts[2];
checkV2F3('proposal_rejects_tampering', FormAssistantEditor::openProposal($tampered, 1, 7, 'Un desplegable') === null);
$old = FormAssistantEditor::sealProposal(1, 7, 'Un desplegable', $after, 'abc', time() - FormAssistantEditor::PROPOSAL_TTL - 10);
checkV2F3('proposal_expires', FormAssistantEditor::openProposal($old, 1, 7, 'Un desplegable') === null);

// --- Con BD: aplicar la propuesta enseñada, sin IA ------------------------------
$site = 1;
$content = FormTemplates::content('contact', 'es');
$content['heading'] = 'Prueba vista previa F3';
$formId = FormStore::create($site, $content);
$jobIds = [];
try {
    $form = FormStore::find($site, $formId);
    $proposed = $form;
    unset($proposed['id']);
    $proposed['fields'][] = ['label' => 'Tratamiento de interés', 'name' => 'tratamiento', 'field_type' => 'select',
        'required' => '1', 'placeholder' => '', 'options' => ['Cirugía pecho', 'Abdominoplastia']];
    $instruction = 'Añade un desplegable de tratamientos';
    $baseHash = FormAssistantEditor::contentHash($form);
    $sealed = FormAssistantEditor::sealProposal($site, $formId, $instruction, $proposed, $baseHash);

    $item = ['capability_id' => 'forms.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
        'form_id' => $formId, 'page_id' => 0, 'instruction' => $instruction, 'preview_token' => $sealed];
    $created = SiteAssistantJobs::createJob($site, 'Prueba F3', 'Vista previa', [$item], null);
    checkV2F3('job_created_with_preview', $created['ok'] === true, json_encode($created));
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    $stored = Database::selectOne('SELECT proposal_token FROM assistant_job_items WHERE job_id = ?', [$jobId]);
    checkV2F3('job_item_keeps_signed_proposal', ($stored['proposal_token'] ?? '') === $sealed);

    $started = microtime(true);
    $step = SiteAssistantJobs::stepJob($jobId, $site);
    $ms = (int) ((microtime(true) - $started) * 1000);
    $done = $step['job']['items'][0] ?? [];
    checkV2F3('step_applies_proposal_without_ai', ($done['status'] ?? '') === 'done' && $ms < 5000, json_encode($done, JSON_UNESCAPED_UNICODE) . ' ms=' . $ms);
    $now = FormStore::find($site, $formId);
    $labels = array_column((array) ($now['fields'] ?? []), 'label');
    checkV2F3('form_matches_preview', in_array('Tratamiento de interés', $labels, true), json_encode($labels, JSON_UNESCAPED_UNICODE));
    $version = Database::selectOne("SELECT reason FROM versions WHERE entity_type = 'page_section' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$formId]);
    checkV2F3('version_saved_before_apply', ($version['reason'] ?? '') === 'before_ai_edit');

    // La misma propuesta ya no vale: el formulario ha cambiado desde entonces.
    $stale = FormAssistantEditor::commit($site, $formId, $proposed, $baseHash, null);
    checkV2F3('stale_proposal_not_saved', $stale['ok'] === false && $stale['error'] === __('asst.err.form_changed_since_preview'), json_encode($stale, JSON_UNESCAPED_UNICODE));

    // Un token que no corresponde se descarta (el paso pediría el cambio a la IA).
    $bad = $item;
    $bad['preview_token'] = $sealed . 'x';
    $created2 = SiteAssistantJobs::createJob($site, 'Prueba F3', 'Token malo', [$bad], null);
    $jobIds[] = (int) ($created2['job']['id'] ?? 0);
    $stored2 = Database::selectOne('SELECT proposal_token FROM assistant_job_items WHERE job_id = ?', [(int) ($created2['job']['id'] ?? 0)]);
    checkV2F3('bad_token_dropped', $created2['ok'] === true && ($stored2['proposal_token'] ?? null) === null);
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    Database::execute("DELETE FROM versions WHERE entity_type = 'page_section' AND entity_id = ?", [$formId]);
    Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
