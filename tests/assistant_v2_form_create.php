<?php

declare(strict_types=1);

// ASST-V2 F6a — formularios nuevos desde el panel del asistente. Sin IA: el
// borrador que devolvería DESIGN_FORM se da hecho. Registro, planner, contenido
// del formulario nuevo, vista previa, firma y el trabajo completo contra BD
// (sitio 1; lo creado se borra al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\FormAssistantEditor;
use App\Services\FormStore;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F6a(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Registro -----------------------------------------------------------------
$catalog = AssistantCapabilityRegistry::catalogForState();
$byId = array_column($catalog, null, 'id');
checkV2F6a('capability_is_automatic', ($byId['forms.create']['mode'] ?? '') === 'automatic', json_encode($byId['forms.create'] ?? null, JSON_UNESCAPED_UNICODE));
checkV2F6a('capability_is_draft_not_undo', ($byId['forms.create']['risk'] ?? '') === 'draft' && ($byId['forms.create']['undo'] ?? true) === false);
checkV2F6a('manage_no_longer_creates', !str_contains(mb_strtolower((string) ($byId['forms.manage']['label'] ?? '')), 'crear'));
checkV2F6a('permission_is_forms', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_ADMIN, 'forms.create')
    && AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'forms.create') === Permissions::roleHas(Permissions::ROLE_REDACTOR, Permissions::CAP_FORMS));
checkV2F6a('executable_item', SiteAssistantJobs::isExecutablePlanItem(['status' => 'aplicar', 'category' => 'automatable_now', 'capability_id' => 'forms.create']));

// --- Planner: no es de ninguna página ni de un formulario existente ----------
$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$forms = [3 => ['heading' => 'Contacto']];
$planned = $normalizer->invoke(null, [
    ['capability_id' => 'forms.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 5, 'form_id' => 3, 'instruction' => 'Crea «Pide presupuesto» con nombre, email y metros'],
    ['capability_id' => 'forms.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'form_id' => 0, 'instruction' => ''],
], [], $catalog, null, $forms);
checkV2F6a('planner_site_wide', ($planned[0]['category'] ?? '') === 'automatable_now' && ($planned[0]['page_id'] ?? -1) === 0
    && ($planned[0]['form_id'] ?? -1) === 0, json_encode($planned[0] ?? null, JSON_UNESCAPED_UNICODE));
checkV2F6a('planner_needs_instruction', ($planned[1]['category'] ?? '') === 'needs_input');

// --- Contenido del formulario nuevo ------------------------------------------
$site = 1;
$aiDraft = [
    'heading' => 'Pide presupuesto', 'description' => 'Te respondemos en 24 h', 'submit_text' => 'Enviar', 'success_message' => 'Gracias',
    'autoresponder_subject' => 'Recibido', 'autoresponder_body' => 'Hola',
    'fields' => [
        ['label' => 'Nombre', 'field_type' => 'text', 'required' => '1'],
        ['label' => 'Email', 'field_type' => 'email', 'required' => '1'],
        ['label' => 'Metros', 'field_type' => 'select', 'required' => '0', 'options' => ['<50', '50-100', '>100']],
    ],
];
$new = FormAssistantEditor::newContent($site, $aiDraft);
checkV2F6a('new_content_ok', $new['ok'] === true && $new['content']['heading'] === 'Pide presupuesto' && count($new['content']['fields']) === 3,
    json_encode($new, JSON_UNESCAPED_UNICODE));
checkV2F6a('new_content_is_custom_without_autoresponder', ($new['content']['form_type'] ?? '') === 'custom' && ($new['content']['autoresponder_enabled'] ?? '') === '0');
checkV2F6a('new_content_keeps_template_legal', array_key_exists('lawful_basis', $new['content']) && array_key_exists('notify_email', $new['content']), json_encode(array_keys($new['content'])));
$bad = FormAssistantEditor::newContent($site, ['heading' => '', 'fields' => []]);
checkV2F6a('new_content_rejects_empty', $bad['ok'] === false && ($bad['error'] ?? '') !== '');

$preview = FormAssistantEditor::previewNew($new['content']);
checkV2F6a('preview_is_new_form', $preview['kind'] === 'form_new' && $preview['after']['heading'] === 'Pide presupuesto'
    && count($preview['after']['fields']) === 3 && $preview['after']['fields'][2]['options'] === ['<50', '50-100', '>100'], json_encode($preview, JSON_UNESCAPED_UNICODE));

// --- Firma y trabajo -----------------------------------------------------------
$instruction = 'Crea «Pide presupuesto» con nombre, email y metros';
$token = AssistantProposal::seal('form_new', $site, $site, $instruction, $new['content'], '');
checkV2F6a('token_opens_for_site', AssistantProposal::open($token, 'form_new', $site, $site, $instruction) !== null);
checkV2F6a('token_bound_to_instruction', AssistantProposal::open($token, 'form_new', $site, $site, 'Otra cosa') === null);

$jobIds = [];
$formIds = [];
try {
    $item = ['capability_id' => 'forms.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'form_id' => 0,
        'instruction' => $instruction, 'preview_token' => $token];
    $created = SiteAssistantJobs::createJob($site, 'Prueba F6a', 'Formulario nuevo', [$item], null);
    checkV2F6a('job_created', $created['ok'] === true, json_encode($created, JSON_UNESCAPED_UNICODE));
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    $before = (int) (Database::selectOne("SELECT COUNT(*) AS n FROM page_sections WHERE section_type = 'form'")['n'] ?? 0);
    $step = SiteAssistantJobs::stepJob($jobId, $site);
    $done = $step['job']['items'][0] ?? [];
    $formId = (int) ($done['form_id'] ?? 0);
    if ($formId > 0) $formIds[] = $formId;
    checkV2F6a('step_creates_form', ($done['status'] ?? '') === 'done' && $formId > 0
        && (int) (Database::selectOne("SELECT COUNT(*) AS n FROM page_sections WHERE section_type = 'form'")['n'] ?? 0) === $before + 1,
        json_encode($done, JSON_UNESCAPED_UNICODE));
    $saved = $formId > 0 ? FormStore::find($site, $formId) : null;
    checkV2F6a('saved_is_what_was_previewed', $saved !== null && $saved['heading'] === 'Pide presupuesto'
        && array_column($saved['fields'], 'label') === ['Nombre', 'Email', 'Metros']);
    checkV2F6a('label_names_the_form', str_contains((string) ($done['page_title'] ?? ''), 'Pide presupuesto'));
    checkV2F6a('not_placed_anywhere', $formId > 0 && Database::selectOne('SELECT 1 AS x FROM form_placements WHERE form_id = ? LIMIT 1', [$formId]) === null);

    // Sin vista previa se acepta igual (se pide a la IA al aplicar); un token ajeno se descarta.
    $foreign = AssistantProposal::seal('form_new', $site, $site, 'Otra instrucción', $new['content'], '');
    $noPreview = SiteAssistantJobs::createJob($site, 'Prueba F6a', 'Sin vista previa', [array_merge($item, ['preview_token' => $foreign])], null);
    $jobIds[] = (int) ($noPreview['job']['id'] ?? 0);
    $row = Database::selectOne('SELECT proposal_token FROM assistant_job_items WHERE job_id = ? LIMIT 1', [(int) ($noPreview['job']['id'] ?? 0)]);
    checkV2F6a('foreign_token_dropped', $noPreview['ok'] === true && $row !== null && $row['proposal_token'] === null, json_encode($row));
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    foreach ($formIds as $id) Database::execute("DELETE FROM page_sections WHERE id = ? AND section_type = 'form'", [$id]);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
