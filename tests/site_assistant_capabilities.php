<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AI\Actions;
use App\Services\AssistantCapabilityRegistry;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;

$failed = 0;
function checkAssistantCapability(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 1000) . PHP_EOL;
        }
    }
}

$catalog = AssistantCapabilityRegistry::catalogForState([
    'analytics' => ['available' => true, 'enabled' => true],
    'booking'   => ['available' => true, 'enabled' => true],
    'commerce'  => ['available' => true, 'enabled' => false],
    'resources' => ['available' => true, 'enabled' => true],
], [
    'pages' => 4,
    'forms' => 2,
    'media' => 7,
    'legal_pages' => 1,
    'booking_services' => 2,
    'resources' => 3,
    'commerce_products' => 0,
]);
$byId = array_column($catalog, null, 'id');

checkAssistantCapability(
    'taxonomy_has_exact_five_categories',
    AssistantCapabilityRegistry::CATEGORIES === [
        'automatable_now',
        'manual_in_platform',
        'needs_input',
        'requires_development',
        'sensitive_review',
    ],
    json_encode(AssistantCapabilityRegistry::CATEGORIES)
);
checkAssistantCapability(
    'canvas_edit_is_the_only_automatic_handler',
    ($byId['pages.canvas.edit']['mode'] ?? '') === 'automatic'
        && ($byId['pages.canvas.edit']['handler'] ?? '') === 'canvas_edit'
        && count(array_filter($catalog, static fn (array $c): bool => ($c['mode'] ?? '') === 'automatic')) === 1,
    json_encode($catalog)
);
checkAssistantCapability(
    'page_creation_exists_but_is_manual_for_assistant',
    ($byId['pages.create']['platform_available'] ?? false) === true
        && ($byId['pages.create']['mode'] ?? '') === 'manual'
        && ($byId['pages.create']['handler'] ?? null) === null
        && ($byId['pages.create']['admin_path'] ?? '') === '/admin/pages',
    json_encode($byId['pages.create'] ?? null)
);
checkAssistantCapability(
    'enabled_module_exposes_real_state_and_count',
    ($byId['booking.manage']['state'] ?? '') === 'enabled'
        && ($byId['booking.manage']['configured_items'] ?? -1) === 2
        && ($byId['booking.manage']['admin_path'] ?? '') === '/admin/booking',
    json_encode($byId['booking.manage'] ?? null)
);
checkAssistantCapability(
    'disabled_module_points_to_activation',
    ($byId['commerce.manage']['state'] ?? '') === 'disabled'
        && ($byId['commerce.manage']['admin_path'] ?? '') === '/admin/modules'
        && ($byId['commerce.manage']['configured_items'] ?? -1) === 0,
    json_encode($byId['commerce.manage'] ?? null)
);
checkAssistantCapability(
    'legal_content_is_never_automatic',
    ($byId['legal.content']['mode'] ?? '') === 'review'
        && ($byId['legal.content']['sensitive'] ?? false) === true
        && ($byId['legal.content']['handler'] ?? null) === null,
    json_encode($byId['legal.content'] ?? null)
);

$rendered = AssistantCapabilityRegistry::renderForPrompt($catalog);
checkAssistantCapability(
    'prompt_registry_contains_verified_state_and_gate',
    str_contains($rendered, 'booking.manage')
        && str_contains($rendered, 'estado=enabled')
        && str_contains($rendered, 'handler=canvas_edit')
        && str_contains($rendered, 'SIN_HANDLER_NO_EJECUTAR'),
    $rendered
);

$definition = Actions::get(Actions::PLAN_SITE_CHANGES);
$instruction = (string) ($definition['instruction'] ?? '');
checkAssistantCapability(
    'planner_requests_operational_taxonomy',
    str_contains($instruction, 'automatable_now|manual_in_platform|needs_input|requires_development|sensitive_review')
        && str_contains($instruction, 'capability_id')
        && str_contains($instruction, 'required_inputs')
        && str_contains($instruction, 'CAPACIDADES VERIFICADAS'),
    $instruction
);
checkAssistantCapability(
    'planner_template_receives_capability_registry',
    str_contains((string) ($definition['user_template'] ?? ''), '{capability_map}')
        && in_array('capability_map', (array) ($definition['required'] ?? []), true),
    json_encode($definition)
);
$shapeNormalizer = new ReflectionMethod(\App\Services\AI\AIActionRunner::class, 'normalizeActionData');
$wrappedPlan = $shapeNormalizer->invoke(null, Actions::PLAN_SITE_CHANGES, [[
    'summary' => 'Plan',
    'items' => [],
]]);
$multiplePlans = $shapeNormalizer->invoke(null, Actions::PLAN_SITE_CHANGES, [
    ['summary' => 'Uno', 'items' => []],
    ['summary' => 'Dos', 'items' => []],
]);
checkAssistantCapability(
    'single_gemini_plan_wrapper_is_unwrapped_strictly',
    ($wrappedPlan['summary'] ?? '') === 'Plan'
        && array_is_list($multiplePlans)
        && count($multiplePlans) === 2,
    json_encode([$wrappedPlan, $multiplePlans])
);

$pages = [
    10 => [
        'id' => 10,
        'title' => 'Inicio',
        'slug' => 'inicio',
        'status' => 'published',
        'editable' => true,
        'sections' => ['hero'],
    ],
];
$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [
    [
        'capability_id' => 'pages.canvas.edit',
        'category' => 'automatable_now',
        'page_id' => 10,
        'section' => 'hero',
        'instruction' => 'Cambiar el titular.',
        'status' => 'aplicar',
        'reason' => 'La página es editable.',
        'evidence' => 'Inicio contiene la sección hero.',
        'next_action' => 'Preparar borrador.',
        'required_inputs' => [],
    ],
    [
        'capability_id' => 'forms.manage',
        'category' => 'automatable_now',
        'page_id' => 0,
        'instruction' => 'Crear formulario.',
        'status' => 'aplicar',
        'reason' => 'La plataforma tiene formularios.',
    ],
    [
        'capability_id' => 'booking.manage',
        'category' => 'needs_input',
        'page_id' => 0,
        'instruction' => 'Crear consulta de 90 minutos.',
        'status' => 'ambiguo',
        'reason' => 'Falta el precio definitivo.',
        'required_inputs' => ['Precio definitivo'],
    ],
    [
        'capability_id' => 'legal.content',
        'category' => 'automatable_now',
        'page_id' => 10,
        'instruction' => 'Añadir aceptación de CGV.',
        'status' => 'aplicar',
        'reason' => 'Es texto.',
    ],
    [
        'capability_id' => 'invented.magic',
        'category' => 'automatable_now',
        'page_id' => 0,
        'instruction' => 'Añadir una función mágica.',
        'status' => 'aplicar',
        'reason' => 'El modelo dice que puede.',
    ],
], $pages, $catalog);

checkAssistantCapability(
    'verified_canvas_item_remains_executable',
    ($items[0]['category'] ?? '') === 'automatable_now'
        && ($items[0]['status'] ?? '') === 'aplicar'
        && ($items[0]['capability_id'] ?? '') === 'pages.canvas.edit',
    json_encode($items[0] ?? null)
);
checkAssistantCapability(
    'manual_capability_cannot_be_promoted_by_model',
    ($items[1]['category'] ?? '') === 'manual_in_platform'
        && ($items[1]['status'] ?? '') === 'no_viable'
        && ($items[1]['admin_path'] ?? '') === '/admin/formularios',
    json_encode($items[1] ?? null)
);
checkAssistantCapability(
    'missing_input_stays_non_executable',
    ($items[2]['category'] ?? '') === 'needs_input'
        && ($items[2]['status'] ?? '') === 'ambiguo'
        && ($items[2]['required_inputs'] ?? []) === ['Precio definitivo'],
    json_encode($items[2] ?? null)
);
checkAssistantCapability(
    'sensitive_capability_overrides_model_claim',
    ($items[3]['category'] ?? '') === 'sensitive_review'
        && ($items[3]['status'] ?? '') === 'no_viable',
    json_encode($items[3] ?? null)
);
checkAssistantCapability(
    'unknown_capability_is_development_not_execution',
    ($items[4]['category'] ?? '') === 'requires_development'
        && ($items[4]['status'] ?? '') === 'no_viable'
        && ($items[4]['capability_id'] ?? '') === 'custom.development',
    json_encode($items[4] ?? null)
);
checkAssistantCapability(
    'job_accepts_only_verified_automatic_item',
    SiteAssistantJobs::isExecutablePlanItem($items[0])
        && !SiteAssistantJobs::isExecutablePlanItem($items[1])
        && !SiteAssistantJobs::isExecutablePlanItem($items[2])
        && !SiteAssistantJobs::isExecutablePlanItem($items[3])
        && !SiteAssistantJobs::isExecutablePlanItem([
            'status' => 'aplicar',
            'category' => 'automatable_now',
            'capability_id' => 'forms.manage',
        ])
);
$assistantJs = (string) file_get_contents(PP_ROOT . '/admin/assets/js/assistant.js');
checkAssistantCapability(
    'missing_information_cannot_be_promoted_by_confirmation',
    !str_contains($assistantJs, 'promoteAll')
        && !str_contains($assistantJs, "pp.t('js.as.apply_with_info')")
        && str_contains($assistantJs, "itemCategory(it) === 'automatable_now'")
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
