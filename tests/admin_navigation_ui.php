<?php

declare(strict_types=1);

// ADMIN-NAV N2 — contrato de presentación accesible del acordeón lateral.

require_once __DIR__ . '/../config/constants.php';

$failed = 0;
function navUiCheck(string $name, bool $ok, string $detail = ''): void
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

navUiCheck('layout_renders_grouped_model', str_contains($layout, 'foreach ($navigation as $navEntry)'));
navUiCheck('navigation_uses_semantic_lists', str_contains($layout, 'class="pp-nav-list"') && str_contains($layout, 'class="pp-nav-group__items"'));
navUiCheck('group_headers_are_buttons', str_contains($layout, 'data-pp-nav-group-toggle') && str_contains($layout, 'type="button"'));
navUiCheck('buttons_expose_expanded_state', str_contains($layout, 'aria-expanded=') && str_contains($layout, '$groupOpen'));
navUiCheck('buttons_control_named_regions', str_contains($layout, 'aria-controls=') && str_contains($layout, '$groupPanelId'));
navUiCheck('active_group_opens_on_server', str_contains($layout, '$groupOpen = !empty($navEntry[\'active\'])'));
navUiCheck('closed_groups_use_hidden', str_contains($layout, '$groupOpen ? \'\' : \' hidden\''));
navUiCheck('no_js_keeps_all_destinations_available',
    str_contains($layout, '<html class="no-js"')
    && str_contains($css, '.no-js .pp-nav-group__items[hidden]')
    && str_contains($css, 'display: grid !important')
);
navUiCheck('n1_flat_adapter_removed_from_layout', !str_contains($layout, 'flatForCurrentLayout'));

navUiCheck('js_uses_persistent_group_preference', str_contains($js, "'pp_sidebar_group'"));
navUiCheck('js_syncs_aria_expanded', str_contains($js, "setAttribute('aria-expanded'"));
navUiCheck('js_syncs_hidden_panels', str_contains($js, '.hidden = !open'));
navUiCheck('js_closes_other_groups', str_contains($js, 'closeOtherNavGroups'));
navUiCheck('active_group_wins_on_initial_load', str_contains($js, '[data-pp-nav-group-active="1"]'));

navUiCheck('css_styles_group_controls', str_contains($css, '.pp-nav-group__toggle'));
navUiCheck('css_indents_child_destinations', str_contains($css, '.pp-nav-group__items .pp-nav-item'));
navUiCheck('css_has_visible_group_focus', str_contains($css, '.pp-nav-group__toggle:focus-visible'));
navUiCheck('motion_uses_transform_and_opacity',
    str_contains($css, '@keyframes pp-nav-group-reveal')
    && str_contains($css, 'transform: translateY(')
    && str_contains($css, 'opacity:')
);
navUiCheck('navigation_respects_reduced_motion',
    str_contains($css, '@media (prefers-reduced-motion: reduce)')
    && str_contains($css, '.pp-nav-group__items')
);

foreach (['es', 'en', 'fr', 'pt'] as $locale) {
    $catalog = require PP_ROOT . '/lang/admin/' . $locale . '.php';
    foreach (['assistant', 'content', 'clients', 'appearance', 'visibility', 'configuration'] as $group) {
        navUiCheck(
            'translated_' . $locale . '_' . $group,
            isset($catalog['nav.group.' . $group]) && trim((string) $catalog['nav.group.' . $group]) !== ''
        );
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
