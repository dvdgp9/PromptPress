<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\AssistantCapabilityRegistry;
use App\Services\SiteAssistantPlanner;

$failed = 0;
function checkAssistantMultimodal(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$pages = [
    10 => [
        'id' => 10, 'title' => 'Inicio', 'slug' => 'inicio', 'status' => 'draft',
        'editable' => true, 'sections' => ['hero'],
    ],
];
$bundle = [
    'blocks' => [
        ['id' => 'B1', 'type' => 'heading', 'text' => 'Servicios'],
        ['id' => 'B2', 'type' => 'image', 'media_ref' => 'IMG-1', 'text' => 'Referencia'],
    ],
    'media' => [
        ['ref' => 'IMG-1', 'media_id' => 42, 'status' => 'stored', 'source_kind' => 'media'],
        ['ref' => 'IMG-2', 'media_id' => 99, 'status' => 'needs_review', 'source_kind' => 'unresolved'],
    ],
];
$raw = [[
    'capability_id' => 'pages.canvas.edit',
    'category' => 'automatable_now',
    'page_id' => 10,
    'section' => 'hero',
    'instruction' => 'Adaptar la sección a la referencia.',
    'status' => 'aplicar',
    'source_block_ids' => ['B2', 'B404', 'B1', 'B2'],
    'media_ids' => [42, 99, 9999, 42],
]];

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$normalized = $normalizer->invoke(null, $raw, $pages, AssistantCapabilityRegistry::catalogForState(), $bundle);
$item = $normalized[0] ?? [];

checkAssistantMultimodal('source_block_ids_are_scoped_and_deduplicated',
    ($item['source_block_ids'] ?? null) === ['B2', 'B1'],
    json_encode($item)
);
checkAssistantMultimodal('media_ids_accept_only_stored_bundle_media',
    ($item['media_ids'] ?? null) === [42],
    json_encode($item)
);

$definition = Actions::get(Actions::PLAN_SITE_CHANGES);
$instruction = (string) ($definition['instruction'] ?? '');
checkAssistantMultimodal('planner_contract_requests_typed_references',
    str_contains($instruction, 'source_block_ids') && str_contains($instruction, 'media_ids'),
    $instruction
);
checkAssistantMultimodal('planner_forbids_claiming_unseen_images',
    str_contains($instruction, 'NO afirmes') && str_contains($instruction, 'inspeccionado'),
    $instruction
);

$validate = new ReflectionMethod(AIActionRunner::class, 'validateSitePlan');
$invalidRejected = false;
try {
    $validate->invoke(null, [
        'summary' => 'Plan',
        'items' => [[
            'status' => 'aplicar',
            'capability_id' => 'pages.canvas.edit',
            'category' => 'automatable_now',
            'source_block_ids' => 'B1',
            'media_ids' => [42],
        ]],
    ]);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $cause = $e instanceof ReflectionException ? $e : ($e->getPrevious() ?? $e);
    $invalidRejected = $cause instanceof AIException;
}
checkAssistantMultimodal('runner_rejects_untyped_reference_arrays', $invalidRejected);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
