<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\AI\Actions;
use App\Services\Renderer\CustomBlockGenerator;
use App\Services\Renderer\SectionRenderer;

$validAiData = [
    'html' => '<div class="ppb-container ppb-stack">'
        . '<p class="ppb-eyebrow" data-pp-field="header.eyebrow" data-pp-type="text">Referencia visual</p>'
        . '<h2 class="ppb-heading-lg" data-pp-field="header.heading" data-pp-type="text">Bloque generado con criterio</h2>'
        . '<p class="ppb-lead" data-pp-field="header.lead" data-pp-type="richtext">Texto nuevo para el negocio.</p>'
        . '</div>',
    'rationale' => [
        'summary' => 'Bloque editorial con jerarquia clara.',
        'reference_takeaways' => ['Se toma el ritmo visual, no la marca.'],
        'brand_application' => ['Los tokens del sitio controlan color y tipo.'],
    ],
];

$content = CustomBlockGenerator::buildContentFromAiData($validAiData, ['kind' => 'test'], ['is_first_section' => true]);
$rendered = SectionRenderer::render([
    'id' => 9401,
    'section_type' => 'custom_block',
    'content_json' => $content,
    'style_json' => null,
]);

$invalid = CustomBlockGenerator::buildContentFromAiData([
    'html' => '<div class="ppb-container"><script>alert(1)</script><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div>',
    'rationale' => [],
]);

$checks = [
    'action registered' => Actions::get(Actions::GENERATE_CUSTOM_BLOCK_FROM_REFERENCE) !== null,
    'content version' => ($content['version'] ?? '') === 'ppb:1',
    'content sanitized' => ($content['validation']['sanitized'] ?? false) === true,
    'fields extracted' => isset($content['fields']['header.heading']),
    'rationale normalized' => ($content['rationale']['summary'] ?? '') !== '',
    'renders valid block' => str_contains($rendered, 'pp-section--custom_block') && str_contains($rendered, 'Bloque generado'),
    'invalid marked unsafe' => ($invalid['validation']['sanitized'] ?? true) === false,
    'invalid fields empty' => $invalid['fields'] instanceof stdClass,
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
