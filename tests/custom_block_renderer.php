<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\DesignSystem;
use App\Services\Renderer\SectionRenderer;

$html = '<div class="ppb-container ppb-stack">'
    . '<h1 class="ppb-heading-xl" data-pp-field="header.heading" data-pp-type="text">Hero PPB</h1>'
    . '<p class="ppb-lead" data-pp-field="header.lead" data-pp-type="richtext">Lead</p>'
    . '<a class="pp-btn pp-btn--primary" href="/contacto" data-pp-field="cta.primary" data-pp-type="cta">Contacto</a>'
    . '</div>';

$valid = SectionRenderer::render([
    'id' => 9001,
    'sort_order' => 0,
    'section_type' => 'custom_block',
    'content' => json_encode(['version' => 'ppb:1', 'html' => $html], JSON_UNESCAPED_UNICODE),
    'style' => null,
]);

$invalid = SectionRenderer::render([
    'id' => 9002,
    'sort_order' => 1,
    'section_type' => 'custom_block',
    'content' => json_encode(['version' => 'ppb:1', 'html' => '<script>alert(1)</script>'], JSON_UNESCAPED_UNICODE),
    'style' => null,
]);

$css = DesignSystem::renderSectionBaseCss();

$checks = [
    'valid renders wrapper' => str_contains($valid, 'pp-section--custom_block'),
    'valid renders inner' => str_contains($valid, 'Hero PPB') && str_contains($valid, 'ppb-container'),
    'invalid rejected' => str_contains($invalid, 'custom_block invalid') && !str_contains($invalid, '<script>'),
    'css has ppb container' => str_contains($css, '.ppb-container'),
    'css has responsive split' => str_contains($css, '.ppb-split'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

exit($failed > 0 ? 1 : 0);
