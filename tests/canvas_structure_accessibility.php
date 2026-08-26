<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S5 — contrato de accesibilidad y responsive del editor.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function structureA11yCheck(string $name, bool $ok, string $detail = ''): void
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
$pickerStart = strpos($view, 'id="studio-block-picker"');
$pickerEnd = strpos($view, '<div class="cvstudio-modal" id="history-modal"', $pickerStart);
$picker = $pickerStart === false ? '' : substr($view, $pickerStart, max(0, $pickerEnd - $pickerStart));

structureA11yCheck('selector anuncia relación trigger-panel',
    str_contains($picker, 'id="studio-block-picker-btn"')
    && str_contains($picker, 'aria-controls="studio-block-picker-menu"')
    && str_contains($picker, 'id="studio-block-picker-menu"')
    && str_contains($picker, 'role="group"')
);
structureA11yCheck('selector no usa menuitem para controles y campo',
    !str_contains($picker, 'role="menuitem"')
    && !str_contains($picker, 'role="menu"')
    && str_contains($picker, 'id="studio-form-source"')
);
structureA11yCheck('subselectores exponen su control asociado',
    str_contains($picker, 'aria-controls="studio-insert-menu"')
    && str_contains($picker, 'aria-controls="studio-insert-booking-menu"')
    && str_contains($picker, 'aria-controls="studio-insert-resources-menu"')
);
structureA11yCheck('Escape cierra y restaura foco del selector',
    str_contains($js, "e.key !== 'Escape'")
    && str_contains($js, 'blockPickerBtn.focus()')
);
structureA11yCheck('foco visible cubre trigger y opciones',
    str_contains($css, '.cvstudio-block-picker__trigger:focus-visible')
    && str_contains($css, '.cvstudio-block-option:focus-visible')
);
structureA11yCheck('táctil amplía acciones e inserciones',
    str_contains($css, '@media (hover:none)')
    && str_contains($css, '.cvstudio-seclist__action{width:40px;height:40px}')
    && str_contains($css, '.cvstudio-insertpoint__btn{height:40px}')
);
structureA11yCheck('móvil preserva acceso al chat',
    str_contains($css, '@media (max-width:640px){.cvstudio-side')
    && str_contains($css, '.cvstudio-dock__panel{width:min(360px,calc(100vw - 32px))}')
);
structureA11yCheck('reduced motion también cubre selector',
    str_contains($css, '@media (prefers-reduced-motion:reduce){.cvstudio-block-picker__trigger')
);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
