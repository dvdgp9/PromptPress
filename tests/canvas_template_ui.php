<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S4 — contrato del selector único en Studio.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function templateUiCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');

templateUiCheck('hay un único disparador principal',
    substr_count($view, 'id="studio-block-picker-btn"') === 1
    && str_contains($view, 'id="studio-block-picker-menu"')
);
templateUiCheck('selector separa contenido y bloques funcionales',
    str_contains($view, "__('cv.block_content_category')")
    && str_contains($view, "__('cv.block_functional_category')")
);
templateUiCheck('tres plantillas básicas exactas',
    substr_count($view, 'data-section-template=') === 3
    && str_contains($view, 'data-section-template="text"')
    && str_contains($view, 'data-section-template="text_image"')
    && str_contains($view, 'data-section-template="cta"')
);
templateUiCheck('bloques funcionales viven dentro del selector',
    strpos($view, 'id="studio-insert-form"') > strpos($view, 'id="studio-block-picker-menu"')
    && strpos($view, 'id="studio-insert-booking"') > strpos($view, 'id="studio-block-picker-menu"')
    && strpos($view, 'id="studio-insert-resources"') > strpos($view, 'id="studio-block-picker-menu"')
);
templateUiCheck('JS inserta plantilla en endpoint estructural',
    str_contains($js, "fd.append('action', 'insert_template')")
    && str_contains($js, "fd.append('template'")
    && str_contains($js, 'body.dataset.structureUrl')
    && str_contains($js, 'appendRequestedPlacement(fd)')
);
templateUiCheck('resultado reutiliza foco, historial y preview sin chat',
    str_contains($js, 'finishFunctionalInsert(data)')
    && str_contains($js, 'pendingStructureFocus = selectedSection')
    && str_contains($js, 'reloadPreview()')
);
templateUiCheck('menú tiene estados táctiles y responsive',
    str_contains($css, '.cvstudio-block-picker__menu')
    && str_contains($css, '.cvstudio-block-option:active')
    && str_contains($css, '@media (max-width:640px)')
    && str_contains($css, 'prefers-reduced-motion')
);

$required = [
    'cv.block_picker_button', 'cv.block_content_category', 'cv.block_functional_category',
    'cv.block_text', 'cv.block_text_desc', 'cv.block_text_image', 'cv.block_text_image_desc',
    'cv.block_cta', 'cv.block_cta_desc', 'cv.block_form_desc', 'cv.block_booking_desc',
    'cv.block_resources_desc', 'cv.template_added', 'js.cv.inserting_template',
];
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter($required, static fn(string $key): bool => !isset($catalog[$key])));
    templateUiCheck('selector traducido en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
