<?php

declare(strict_types=1);

// ASST-FORMS — el asistente puede editar formularios existentes tras aprobación.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantSourceEnvelope;
use App\Services\FormAssistantEditor;
use App\Services\FormDefinition;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;

$failed = 0;
function checkAssistantFormEdit(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- T1 registro -----------------------------------------------------------
$catalog = AssistantCapabilityRegistry::byId(AssistantCapabilityRegistry::catalogForState([], ['forms' => 2]));
checkAssistantFormEdit('forms_edit_is_automatic_with_handler',
    ($catalog['forms.edit']['mode'] ?? '') === 'automatic' && ($catalog['forms.edit']['handler'] ?? null) === 'form_edit'
);
checkAssistantFormEdit('forms_manage_stays_manual', ($catalog['forms.manage']['mode'] ?? '') === 'manual');
checkAssistantFormEdit('prompt_lists_forms_edit',
    str_contains(AssistantCapabilityRegistry::renderForPrompt(AssistantCapabilityRegistry::catalogForState()), 'forms.edit | ')
);

// --- T2 planner ------------------------------------------------------------
$pages = [10 => ['id' => 10, 'title' => 'Inicio', 'slug' => 'inicio', 'status' => 'published', 'editable' => true, 'sections' => ['hero']]];
$forms = [7 => ['id' => 7, 'heading' => 'Pide información', 'fields' => [], 'pages' => ['Inicio'], 'translated' => []]];
$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$raw = [
    ['capability_id' => 'forms.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
     'form_id' => 7, 'page_id' => 10, 'section' => 'hero', 'instruction' => 'Una sola pregunta desplegable.'],
    ['capability_id' => 'forms.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
     'form_id' => 999, 'page_id' => 0, 'instruction' => 'Cambiar algo.'],
    ['capability_id' => 'forms.manage', 'category' => 'automatable_now', 'status' => 'aplicar',
     'form_id' => 7, 'page_id' => 0, 'instruction' => 'Cambiar el destinatario.'],
];
$items = $normalizer->invoke(null, $raw, $pages, AssistantCapabilityRegistry::catalogForState(), null, $forms);

checkAssistantFormEdit('form_item_is_applicable',
    ($items[0]['category'] ?? '') === 'automatable_now' && ($items[0]['status'] ?? '') === 'aplicar',
    json_encode($items[0])
);
checkAssistantFormEdit('form_item_has_no_page_or_section',
    ($items[0]['page_id'] ?? -1) === 0 && ($items[0]['section'] ?? 'x') === '' && ($items[0]['form_id'] ?? 0) === 7
    && ($items[0]['form_title'] ?? '') === 'Pide información',
    json_encode($items[0])
);
checkAssistantFormEdit('unknown_form_needs_input',
    ($items[1]['category'] ?? '') === 'needs_input' && ($items[1]['form_id'] ?? -1) === 0,
    json_encode($items[1])
);
checkAssistantFormEdit('recipient_change_stays_manual',
    ($items[2]['category'] ?? '') === 'manual_in_platform',
    json_encode($items[2])
);

// --- T3 jobs ---------------------------------------------------------------
checkAssistantFormEdit('job_gate_accepts_form_items', SiteAssistantJobs::isExecutablePlanItem($items[0]));
checkAssistantFormEdit('job_gate_rejects_manual_forms', !SiteAssistantJobs::isExecutablePlanItem($items[2]));

$pageItem = ['capability_id' => 'pages.canvas.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 10, 'section' => '', 'instruction' => 'x'];
$legacyHash = hash('sha256', json_encode([
    'capability_id' => 'pages.canvas.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 10, 'section' => '', 'instruction' => 'x', 'source_block_ids' => [], 'media_ids' => [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
checkAssistantFormEdit('page_fingerprint_unchanged', AssistantSourceEnvelope::itemFingerprint($pageItem) === $legacyHash);
$swapped = $items[0];
$swapped['form_id'] = 8;
checkAssistantFormEdit('form_fingerprint_binds_form_id',
    AssistantSourceEnvelope::itemFingerprint($items[0]) !== AssistantSourceEnvelope::itemFingerprint($swapped)
);

// --- T4 merge --------------------------------------------------------------
$form = [
    'id' => 7,
    'heading' => 'Pide información',
    'description' => 'Te llamamos',
    'submit_text' => 'Enviar',
    'success_message' => 'Gracias',
    'form_type' => 'contact',
    'language' => 'es',
    'notify_email' => 'clinica@example.com',
    'lawful_basis' => 'consent',
    'retention_period' => '1 año',
    'marketing_opt_in' => '1',
    'autoresponder_enabled' => '1',
    'autoresponder_subject' => 'Hola',
    'autoresponder_body' => 'Hola {{nombre}}, gracias por escribir a {{sitio}}.',
    'fields' => [
        ['label' => 'Nombre', 'name' => 'nombre_completo', 'field_type' => 'text', 'required' => '1', 'placeholder' => ''],
        ['label' => 'Email', 'name' => 'correo', 'field_type' => 'email', 'required' => '1', 'placeholder' => ''],
        ['label' => 'Cirugía pecho', 'name' => 'pecho', 'field_type' => 'checkbox', 'required' => '0', 'placeholder' => ''],
        ['label' => 'Cirugía facial', 'name' => 'facial', 'field_type' => 'checkbox', 'required' => '0', 'placeholder' => ''],
    ],
    'i18n' => ['en' => [
        'heading' => 'Ask for information',
        'description' => 'We call you',
        'fields' => [
            'nombre_completo' => ['label' => 'Name'],
            'pecho' => ['label' => 'Breast surgery'],
        ],
    ]],
];
$options = ['Cirugía pecho', 'Cirugía corporal', 'Cirugía facial', 'Abdominoplastia', 'Pérdida de peso',
    'Balón gástrico', 'Reducción de estómago', 'Microinjerto capilar', 'Medicina facial'];
$draft = FormDefinition::fromAI([
    'heading' => 'Pide información',
    'description' => 'Elige el tratamiento y te llamamos',
    'submit_text' => 'Enviar',
    'success_message' => 'Gracias',
    'fields' => [
        ['label' => 'Nombre', 'field_type' => 'text', 'required' => true],
        ['label' => 'Email', 'field_type' => 'email', 'required' => true],
        ['label' => '¿Qué tratamiento te interesa?', 'field_type' => 'select', 'required' => true, 'options' => $options],
    ],
]);
$merged = FormAssistantEditor::merge($form, $draft);

checkAssistantFormEdit('merge_preserves_protected_settings',
    $merged['notify_email'] === 'clinica@example.com' && $merged['lawful_basis'] === 'consent'
    && $merged['retention_period'] === '1 año' && $merged['marketing_opt_in'] === '1'
    && $merged['autoresponder_enabled'] === '1' && $merged['autoresponder_body'] === $form['autoresponder_body']
    && $merged['form_type'] === 'contact' && $merged['language'] === 'es' && !isset($merged['id']),
    json_encode($merged, JSON_UNESCAPED_UNICODE)
);
checkAssistantFormEdit('merge_keeps_names_of_unchanged_questions',
    array_column($merged['fields'], 'name') === ['nombre_completo', 'correo', 'que_tratamiento_te_interesa'],
    json_encode(array_column($merged['fields'], 'name'))
);
checkAssistantFormEdit('merge_builds_single_dropdown',
    $merged['fields'][2]['field_type'] === 'select' && $merged['fields'][2]['options'] === $options
);
checkAssistantFormEdit('merged_form_is_valid', FormDefinition::contentErrors($merged) === [],
    json_encode(FormDefinition::contentErrors($merged), JSON_UNESCAPED_UNICODE)
);
$en = $merged['i18n']['en'] ?? [];
checkAssistantFormEdit('merge_prunes_stale_translations',
    ($en['heading'] ?? '') === 'Ask for information' && !isset($en['description'])
    && isset($en['fields']['nombre_completo']) && !isset($en['fields']['pecho']),
    json_encode($en, JSON_UNESCAPED_UNICODE)
);

$noEmail = $merged;
$noEmail['fields'] = [$merged['fields'][2]];
checkAssistantFormEdit('autoresponder_without_email_is_rejected', FormDefinition::contentErrors($noEmail) !== []);

$clash = FormAssistantEditor::merge($form, FormDefinition::fromAI([
    'heading' => 'X', 'description' => '', 'submit_text' => '', 'success_message' => '',
    'fields' => [
        ['label' => 'Correo', 'field_type' => 'email'],          // nombre nuevo «correo»…
        ['label' => 'Email', 'field_type' => 'email'],           // …y el conservado también es «correo»
    ],
]));
$names = array_column($clash['fields'], 'name');
checkAssistantFormEdit('merge_never_duplicates_names', count($names) === count(array_unique($names)), json_encode($names));
checkAssistantFormEdit('merge_defaults_submit_text', $clash['submit_text'] === 'Enviar');

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
