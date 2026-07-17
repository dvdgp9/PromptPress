<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AI\Actions;
use App\Services\SiteAssistantPlanner;

$failed = 0;
function checkAssistantPlanning(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
        }
    }
}

$definition = Actions::get(Actions::PLAN_SITE_CHANGES);
$instruction = (string) ($definition['instruction'] ?? '');

checkAssistantPlanning(
    'planner_splits_same_page_by_section',
    str_contains($instruction, 'UN item por SECCIÓN afectada')
        && str_contains($instruction, 'puede haber varios items con el mismo page_id'),
    $instruction
);
checkAssistantPlanning(
    'planner_reserves_full_page_for_global_changes',
    str_contains($instruction, 'página completa')
        && str_contains($instruction, 'reordenar'),
    $instruction
);
checkAssistantPlanning(
    'planner_consolidates_missing_subpages',
    str_contains($instruction, 'MEJOR ALTERNATIVA VIABLE')
        && str_contains($instruction, 'secciones, tarjetas o acordeones')
        && str_contains($instruction, 'NO marques todo ese contenido como no_viable'),
    $instruction
);
checkAssistantPlanning(
    'planner_separates_content_from_navigation_limit',
    str_contains($instruction, 'URLs independientes')
        && str_contains($instruction, 'menú o jerarquía global'),
    $instruction
);
checkAssistantPlanning(
    'planner_must_not_invent_page_ids',
    str_contains($instruction, 'No inventes IDs'),
    $instruction
);

// La normalización y el job deben conservar items distintos que apuntan a
// secciones diferentes de la misma página; no deben deduplicarlos por page_id.
$pages = [
    10 => [
        'id' => 10,
        'title' => 'Inicio',
        'slug' => 'inicio',
        'status' => 'published',
        'editable' => true,
        'sections' => ['hero', 'servicios'],
    ],
];
$raw = [
    ['page_id' => 10, 'section' => 'hero', 'instruction' => 'Actualizar bienvenida.', 'status' => 'aplicar', 'reason' => ''],
    ['page_id' => 10, 'section' => 'servicios', 'instruction' => 'Crear cinco tarjetas.', 'status' => 'aplicar', 'reason' => ''],
];
$method = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$normalized = $method->invoke(null, $raw, $pages);

checkAssistantPlanning('same_page_items_are_preserved', count($normalized) === 2, json_encode($normalized));
checkAssistantPlanning(
    'same_page_sections_are_preserved',
    array_column($normalized, 'section') === ['hero', 'servicios'],
    json_encode($normalized)
);

$ambiguous = $method->invoke(null, [
    [
        'page_id' => 10,
        'section' => 'hero',
        'instruction' => 'Cambiar el texto de bienvenida por el literal indicado.',
        'status' => 'ambiguo',
        'reason' => 'Se puede aplicar directamente el nuevo texto de bienvenida.',
    ],
    [
        'page_id' => 10,
        'section' => 'servicios',
        'instruction' => 'Actualizar las tarjetas.',
        'status' => 'ambiguo',
        'reason' => '¿Qué descripción debe llevar la tarjeta de Idiomas?',
    ],
    [
        'page_id' => 10,
        'section' => 'hero',
        'instruction' => 'Actualizar el titular.',
        'status' => 'aplicable',
        'reason' => 'Cambio directo de contenido.',
    ],
], $pages);

checkAssistantPlanning(
    'false_ambiguity_is_promoted',
    ($ambiguous[0]['status'] ?? '') === 'aplicar',
    json_encode($ambiguous[0] ?? null)
);
checkAssistantPlanning(
    'real_question_stays_ambiguous',
    ($ambiguous[1]['status'] ?? '') === 'ambiguo',
    json_encode($ambiguous[1] ?? null)
);
checkAssistantPlanning(
    'applicable_alias_is_normalized',
    ($ambiguous[2]['status'] ?? '') === 'aplicar',
    json_encode($ambiguous[2] ?? null)
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
