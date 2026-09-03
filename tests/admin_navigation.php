<?php

declare(strict_types=1);

// ADMIN-NAV N1 — contrato puro de composición de la barra lateral.
// No toca BD ni sesión: fija grupos, módulos, orden y ruta activa.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AdminNavigation;

$failed = 0;
function navCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 800) . PHP_EOL;
    }
}

/** @param array<int, array<string, mixed>> $navigation */
function navKeys(array $navigation): array
{
    return array_column($navigation, 'key');
}

/** @param array<int, array<string, mixed>> $navigation */
function navActiveKeys(array $navigation): array
{
    return array_values(array_column(array_filter(
        AdminNavigation::flatten($navigation),
        static fn(array $item): bool => !empty($item['active'])
    ), 'key'));
}

$none = AdminNavigation::build('/admin/pages', []);
$all = AdminNavigation::build('/admin/pages', [
    'analytics' => true,
    'booking' => true,
    'commerce' => true,
    'resources' => true,
]);

navCheck(
    'top_level_information_architecture',
    navKeys($all) === ['home', 'assistant', 'content', 'clients', 'appearance', 'visibility', 'configuration'],
    json_encode(navKeys($all)) ?: ''
);
navCheck('base_destinations_count', count(AdminNavigation::flatten($none)) === 17);
navCheck('all_modules_destinations_count', count(AdminNavigation::flatten($all)) === 21);

$allFlat = AdminNavigation::flatten($all);
$allKeys = array_column($allFlat, 'key');
navCheck('destinations_are_unique', count($allKeys) === count(array_unique($allKeys)), json_encode($allKeys) ?: '');
navCheck('all_items_have_route_icon_and_label', array_reduce(
    $allFlat,
    static fn(bool $ok, array $item): bool => $ok
        && ($item['type'] ?? '') === 'link'
        && is_string($item['url'] ?? null) && $item['url'] !== ''
        && is_string($item['match'] ?? null) && str_starts_with($item['match'], '/admin')
        && is_string($item['icon'] ?? null) && $item['icon'] !== ''
        && is_string($item['label_key'] ?? null) && str_starts_with($item['label_key'], 'nav.'),
    true
));

$groupsByKey = array_column($all, null, 'key');
navCheck(
    'assistant_group_order',
    navKeys($groupsByKey['assistant']['items']) === ['assistant_home', 'knowledge', 'documents', 'ai_usage']
);
navCheck(
    'content_group_order',
    navKeys($groupsByKey['content']['items']) === ['pages', 'posts', 'media', 'forms', 'resources']
);
navCheck(
    'clients_group_order',
    navKeys($groupsByKey['clients']['items']) === ['messages', 'bookings', 'shop']
);
navCheck(
    'appearance_group_order',
    navKeys($groupsByKey['appearance']['items']) === ['design', 'chrome']
);
navCheck(
    'visibility_group_order',
    navKeys($groupsByKey['visibility']['items']) === ['analytics', 'seo', 'marketing']
);
navCheck(
    'configuration_group_order',
    navKeys($groupsByKey['configuration']['items']) === ['privacy', 'modules', 'settings']
);

$resourceOnly = AdminNavigation::build('/admin/resources', ['resources']);
$resourceGroups = array_column($resourceOnly, null, 'key');
navCheck('resource_module_lives_in_content', in_array('resources', navKeys($resourceGroups['content']['items']), true));
navCheck('inactive_business_modules_absent', navKeys($resourceGroups['clients']['items']) === ['messages']);
navCheck('inactive_analytics_absent', navKeys($resourceGroups['visibility']['items']) === ['seo', 'marketing']);
navCheck('list_and_boolean_module_inputs_match', navKeys(AdminNavigation::flatten($resourceOnly)) === navKeys(AdminNavigation::flatten(
    AdminNavigation::build('/admin/resources', ['resources' => true, 'booking' => false])
)));

navCheck('pages_route_active', navActiveKeys(AdminNavigation::build('/admin/pages/42/edit')) === ['pages']);
navCheck('forms_route_does_not_match_messages', navActiveKeys(AdminNavigation::build('/admin/formularios/3')) === ['forms']);
navCheck('messages_route_does_not_match_forms', navActiveKeys(AdminNavigation::build('/admin/forms/3')) === ['messages']);
navCheck('ai_settings_route_matches_settings', navActiveKeys(AdminNavigation::build('/admin/settings/ai')) === ['settings']);
navCheck('ai_usage_route_matches_usage', navActiveKeys(AdminNavigation::build('/admin/ai/usage')) === ['ai_usage']);
navCheck('dashboard_exact_route', navActiveKeys(AdminNavigation::build('/admin/')) === ['home']);
navCheck('unknown_route_has_no_false_active', navActiveKeys(AdminNavigation::build('/admin/onboarding')) === []);

$activePages = AdminNavigation::build('/admin/pages/42/edit');
$activeGroups = array_values(array_column(array_filter(
    $activePages,
    static fn(array $item): bool => ($item['type'] ?? '') === 'group' && !empty($item['active'])
), 'key'));
navCheck('exactly_active_parent_group', $activeGroups === ['content'], json_encode($activeGroups) ?: '');

$legacyKeys = array_column(AdminNavigation::flatForCurrentLayout($all), 'key');
navCheck(
    'legacy_flat_order_stays_stable_until_n2',
    $legacyKeys === [
        'home', 'assistant_home', 'pages', 'posts', 'media', 'forms', 'messages',
        'knowledge', 'documents', 'design', 'chrome', 'seo', 'marketing',
        'analytics', 'bookings', 'shop', 'resources', 'ai_usage', 'privacy',
        'modules', 'settings',
    ],
    json_encode($legacyKeys) ?: ''
);

$layoutSource = (string) file_get_contents(PP_ROOT . '/views/admin/layout.php');
navCheck('layout_consumes_navigation_builder', str_contains($layoutSource, 'AdminNavigation::build'));
navCheck('layout_renders_grouped_navigation', str_contains($layoutSource, 'foreach ($navigation as $navEntry)'));
navCheck('layout_no_longer_defines_destinations', !str_contains($layoutSource, "['url' => 'admin/"));

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
