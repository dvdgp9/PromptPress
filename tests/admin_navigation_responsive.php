<?php

declare(strict_types=1);

// ADMIN-NAV N3 — contrato de compacto explícito, ayudas y drawer móvil.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function navResponsiveCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 700) . PHP_EOL;
    }
}

$layout = (string) file_get_contents(PP_ROOT . '/views/admin/layout.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/admin.js');
$css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');

navResponsiveCheck('visible_collapse_button', str_contains($layout, 'id="pp-sidebar-collapse"'));
navResponsiveCheck('collapse_button_controls_sidebar',
    str_contains($layout, 'id="pp-sidebar-collapse"')
    && str_contains($layout, 'aria-controls="pp-sidebar"')
    && str_contains($layout, 'data-label-expand=')
    && str_contains($layout, 'data-label-collapse=')
);
navResponsiveCheck('mobile_toggle_exposes_state',
    str_contains($layout, 'id="pp-sidebar-toggle"')
    && str_contains($layout, 'aria-controls="pp-sidebar"')
    && str_contains($layout, 'aria-expanded="false"')
);
navResponsiveCheck('all_compact_targets_have_labels',
    substr_count($layout, 'class="pp-nav-visual-tooltip" aria-hidden="true"') >= 4
    && str_contains($layout, '$navEntry[\'label_key\']')
    && str_contains($layout, '$item[\'label_key\']')
);

navResponsiveCheck('secret_logo_double_click_removed', !str_contains($js, "brand.addEventListener('dblclick'"));
navResponsiveCheck('collapse_uses_explicit_control',
    str_contains($js, "getElementById('pp-sidebar-collapse')")
    && str_contains($js, 'setSidebarCollapsed')
);
navResponsiveCheck('collapse_updates_accessible_state',
    str_contains($js, "setAttribute('aria-expanded'")
    && str_contains($js, "setAttribute('aria-label'")
);
navResponsiveCheck('mobile_drawer_has_single_state_function', str_contains($js, 'setMobileSidebarOpen'));
navResponsiveCheck('mobile_open_moves_focus_inside', str_contains($js, 'mobileFocusTarget.focus()'));
navResponsiveCheck('mobile_close_returns_focus', str_contains($js, 'toggle.focus()'));
navResponsiveCheck('mobile_destination_closes_drawer',
    str_contains($js, "sidebar.querySelectorAll('a[href]')")
    && str_contains($js, 'setMobileSidebarOpen(false')
);
navResponsiveCheck('collapse_control_is_styled', str_contains($css, '.pp-sidebar__collapse'));
navResponsiveCheck('compact_tooltip_is_styled',
    str_contains($css, '.pp-nav-visual-tooltip')
    && str_contains($css, '.pp-sidebar-collapsed .pp-nav-visual-tooltip')
);
navResponsiveCheck('compact_tooltip_supports_focus_and_hover',
    str_contains($css, ':hover .pp-nav-visual-tooltip')
    && str_contains($css, ':focus-visible .pp-nav-visual-tooltip')
);
navResponsiveCheck('compact_group_labels_are_hidden',
    str_contains($css, '.pp-sidebar-collapsed .pp-nav-group__label')
    && str_contains($css, '.pp-sidebar-collapsed .pp-nav-group__chevron')
);
navResponsiveCheck('mobile_targets_are_at_least_44px',
    preg_match('/@media \(max-width: 768px\).*?\.pp-nav-item,\s*\.pp-nav-group__toggle\s*\{[^}]*min-height:\s*44px/s', $css) === 1
);
navResponsiveCheck('mobile_sidebar_uses_safe_width', str_contains($css, 'width: min(304px, calc(100vw - 32px))'));
navResponsiveCheck('saved_desktop_compact_does_not_block_mobile_drawer',
    preg_match('/\.pp-sidebar-collapsed \.pp-sidebar\.is-open\s*\{[^}]*transform:\s*translateX\(0\)/s', $css) === 1
);
navResponsiveCheck('saved_desktop_compact_restores_mobile_chevrons',
    preg_match('/@media \(max-width: 768px\).*?\.pp-sidebar-collapsed \.pp-nav-group__chevron\s*\{[^}]*display:\s*block;[^}]*width:\s*7px;[^}]*height:\s*7px/s', $css) === 1
);
navResponsiveCheck('desktop_collapse_hidden_on_mobile',
    preg_match('/@media \(max-width: 768px\).*?\.pp-sidebar__collapse\s*\{[^}]*display:\s*none/s', $css) === 1
);
navResponsiveCheck('tooltip_motion_is_reduced',
    preg_match('/@media \(prefers-reduced-motion: reduce\).*?\.pp-nav-visual-tooltip/s', $css) === 1
);

foreach (['es', 'en', 'fr', 'pt'] as $locale) {
    $catalog = require PP_ROOT . '/lang/admin/' . $locale . '.php';
    navResponsiveCheck('translated_' . $locale . '_collapse', !empty($catalog['nav.collapse']));
    navResponsiveCheck('translated_' . $locale . '_expand', !empty($catalog['nav.expand']));
}

$spanishCatalog = require PP_ROOT . '/lang/admin/es.php';
navResponsiveCheck('spanish_chrome_uses_cabecera', ($spanishCatalog['nav.chrome'] ?? '') === 'Cabecera y pie');
navResponsiveCheck('release_version_is_1_1_2', defined('PP_VERSION') && PP_VERSION === '1.1.2');

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
