<?php

declare(strict_types=1);

// ASST-V2 F5 — sugerencias del asistente en Inicio. Sin IA: reglas a partir de
// hechos de prueba (puras), orden y límite, filtro por rol, «Ahora no» y la
// caché por sitio (sitio 1; los ajustes se restauran al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantSuggestions;
use App\Services\Permissions;
use Core\Database;

$failed = 0;
function checkV2F5(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$seoIssue = static fn (int $id, string $title, string $code, string $mode = 'canvas'): array =>
    ['page_id' => $id, 'page_title' => $title, 'slug' => 'p' . $id, 'page_type' => 'service', 'render_mode' => $mode, 'severity' => 'warning', 'code' => $code];
$facts = [
    'seo' => [
        $seoIssue(1, 'Inicio', 'og_description_missing'),
        $seoIssue(2, 'Servicios', 'og_description_missing'),
        $seoIssue(2, 'Servicios', 'image_alt_missing'),
        $seoIssue(3, 'Contacto', 'h1_missing'),
        $seoIssue(4, 'Antigua', 'h1_missing', 'sections'),
        ['page_id' => 6, 'page_title' => 'Aviso legal', 'slug' => 'aviso', 'page_type' => 'legal', 'render_mode' => 'canvas', 'severity' => 'info', 'code' => 'og_description_missing'],
    ],
    'links' => [
        ['page_id' => 5, 'page_title' => 'Blog', 'link' => '/precios', 'problem' => 'missing'],
        ['page_id' => 5, 'page_title' => 'Blog', 'link' => '/ofertas', 'problem' => 'draft'],
    ],
    'posts' => ['count' => 6, 'last_days' => 80],
    'pages' => ['published' => 2],
    'services_without_hours' => [['id' => 9, 'name' => 'Primera consulta']],
    'products_out_of_stock' => [['id' => 7, 'name' => 'Sérum']],
];
$all = AssistantSuggestions::fromFacts($facts);
$byRule = [];
foreach ($all as $s) $byRule[$s['rule']] = $s;

checkV2F5('every_rule_fires', array_diff(['seo_meta', 'seo_alt', 'seo_h1', 'links', 'blog_ideas', 'page_ideas', 'booking_hours', 'out_of_stock'], array_keys($byRule)) === [],
    json_encode(array_keys($byRule)));
checkV2F5('seo_meta_groups_pages', str_contains($byRule['seo_meta']['prompt'], '«Inicio»') && str_contains($byRule['seo_meta']['prompt'], '«Servicios»')
    && $byRule['seo_meta']['count'] === 2 && $byRule['seo_meta']['send'] === true && $byRule['seo_meta']['capability'] === 'seo.page.edit',
    json_encode($byRule['seo_meta'], JSON_UNESCAPED_UNICODE));
checkV2F5('legal_pages_left_out', !str_contains($byRule['seo_meta']['prompt'], 'Aviso legal'));
checkV2F5('h1_only_for_canvas_pages', $byRule['seo_h1']['count'] === 1 && str_contains($byRule['seo_h1']['prompt'], '«Contacto»') && !str_contains($byRule['seo_h1']['prompt'], 'Antigua'));
checkV2F5('broken_links_go_to_screen', $byRule['links']['action'] === 'go' && str_ends_with((string) $byRule['links']['url'], '/admin/seo?tab=links') && $byRule['links']['severity'] === 'high');
checkV2F5('hours_need_the_owner', $byRule['booking_hours']['send'] === false && str_contains($byRule['booking_hours']['prompt'], 'Primera consulta')
    && $byRule['booking_hours']['capability'] === 'booking.service.edit');
checkV2F5('ideas_are_sent_directly', $byRule['blog_ideas']['send'] === true && $byRule['blog_ideas']['capability'] === 'posts.ideas'
    && $byRule['page_ideas']['capability'] === 'pages.ideas');
checkV2F5('ids_stable_and_targeted', $byRule['seo_meta']['id'] === AssistantSuggestions::fromFacts($facts)[array_search('seo_meta', array_column($all, 'rule'), true)]['id']
    && $byRule['seo_meta']['id'] !== array_column(AssistantSuggestions::fromFacts(array_merge($facts, ['seo' => [$seoIssue(1, 'Inicio', 'og_description_missing')]])), 'id', 'rule')['seo_meta']);
checkV2F5('sorted_by_severity', array_search('links', array_column($all, 'rule'), true) < array_search('seo_alt', array_column($all, 'rule'), true)
    && array_search('booking_hours', array_column($all, 'rule'), true) < array_search('blog_ideas', array_column($all, 'rule'), true));

$quiet = AssistantSuggestions::fromFacts(['seo' => [], 'links' => [], 'posts' => ['count' => 6, 'last_days' => 10], 'pages' => ['published' => 8],
    'services_without_hours' => [], 'products_out_of_stock' => []]);
checkV2F5('nothing_when_all_is_fine', $quiet === [], json_encode($quiet, JSON_UNESCAPED_UNICODE));
$noBlog = AssistantSuggestions::fromFacts(['posts' => ['count' => 0, 'last_days' => null]]);
checkV2F5('no_blog_nudge_without_blog', !in_array('blog_ideas', array_column($noBlog, 'rule'), true));

// Rol: cada uno ve solo lo que puede hacer. El editor no toca reservas ni
// tienda; el redactor no tiene asistente ni SEO: no ve nada.
$rules = array_column(AssistantSuggestions::visibleFor($all, Permissions::ROLE_EDITOR), 'rule');
checkV2F5('editor_sees_no_clients_data', in_array('seo_meta', $rules, true) && in_array('seo_alt', $rules, true) && in_array('blog_ideas', $rules, true)
    && !in_array('booking_hours', $rules, true) && !in_array('out_of_stock', $rules, true), json_encode($rules));
$redactor = array_column(AssistantSuggestions::visibleFor($all, Permissions::ROLE_REDACTOR), 'rule');
checkV2F5('redactor_sees_nothing', $redactor === [], json_encode($redactor));
checkV2F5('editor_sees_links', in_array('links', $rules, true));
checkV2F5('admin_sees_all', count(AssistantSuggestions::visibleFor($all, Permissions::ROLE_ADMIN)) === count($all));

// --- Con BD -----------------------------------------------------------------
$site = 1;
$keys = [AssistantSuggestions::CACHE_KEY, AssistantSuggestions::DISMISSED_KEY];
$backup = [];
foreach ($keys as $k) {
    $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$site, $k]);
    $backup[$k] = $row !== null ? (string) $row['setting_value'] : null;
}
try {
    foreach ($keys as $k) Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$site, $k]);
    $started = microtime(true);
    $first = AssistantSuggestions::forDashboard($site, Permissions::ROLE_ADMIN, 4);
    $cold = microtime(true) - $started;
    checkV2F5('dashboard_list_is_capped', is_array($first) && count($first) <= 4);
    $started = microtime(true);
    $second = AssistantSuggestions::forDashboard($site, Permissions::ROLE_ADMIN, 4);
    checkV2F5('second_call_uses_cache', $second === $first && Database::selectOne('SELECT 1 AS x FROM settings WHERE site_id = ? AND setting_key = ?', [$site, AssistantSuggestions::CACHE_KEY]) !== null,
        sprintf('cold=%.3fs warm=%.3fs', $cold, microtime(true) - $started));

    // «Ahora no» sobre una sugerencia inventada en la caché.
    // La caché guarda los hechos, no los textos: cada usuario los ve en su idioma.
    $fakeFacts = ['services_without_hours' => [['id' => 999999, 'name' => 'Prueba F5']]];
    $fake = AssistantSuggestions::fromFacts($fakeFacts);
    Database::execute('INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$site, AssistantSuggestions::CACHE_KEY, json_encode(['at' => time(), 'facts' => $fakeFacts])]);
    $before = array_column(AssistantSuggestions::forDashboard($site, Permissions::ROLE_ADMIN, 4), 'id');
    checkV2F5('cached_fixture_shown', in_array($fake[0]['id'], $before, true));
    checkV2F5('dismiss_rejects_unknown', AssistantSuggestions::dismiss($site, 'nada:123') === false);
    checkV2F5('dismiss_known', AssistantSuggestions::dismiss($site, $fake[0]['id']) === true);
    $after = array_column(AssistantSuggestions::forDashboard($site, Permissions::ROLE_ADMIN, 4), 'id');
    checkV2F5('dismissed_hidden', !in_array($fake[0]['id'], $after, true));
} finally {
    foreach ($backup as $k => $v) {
        if ($v === null) Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$site, $k]);
        else Database::execute('INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$site, $k, $v]);
    }
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
