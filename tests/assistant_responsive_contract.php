<?php

declare(strict_types=1);

$css = (string) file_get_contents(__DIR__ . '/../admin/assets/css/admin.css');
$failed = 0;
function checkAssistantResponsive(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

checkAssistantResponsive('composer_children_can_shrink', str_contains($css, '.ppa-composer__editor { flex: 1; min-width: 0;'));
checkAssistantResponsive('mobile_breakpoint_exists', str_contains($css, '@media (max-width: 700px)'));
checkAssistantResponsive('mobile_composer_wraps', str_contains($css, '.ppa-composer__row { flex-wrap: wrap; }'));
checkAssistantResponsive('mobile_editor_uses_full_width',
    str_contains($css, '.ppa-composer__editor, .ppa-composer__input { flex-basis: 100%; width: 100%; }')
);
checkAssistantResponsive('mobile_send_uses_full_width', str_contains($css, '.ppa-composer__send { width: 100%; }'));
checkAssistantResponsive('mobile_media_picker_has_two_columns',
    str_contains($css, '.ppa-media-picker__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }')
);
checkAssistantResponsive('pasted_media_never_exceeds_composer',
    str_contains($css, '.ppa-pasted-image { max-width: 100%; grid-template-columns: 96px 1fr; }')
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
