<?php

declare(strict_types=1);

// ASST-V2 F4b — el asistente cambia el menú, el botón del header y los textos
// del pie. La parte con BD copia la config de header y pie del sitio 1, la
// cambia con una propuesta firmada (sin IA), la deshace y la deja como estaba.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ActivityService;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\ChromeAssistantEditor;
use App\Services\ChromeService;
use App\Services\HeaderMenuService;
use App\Services\LanguageService;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4b(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Registro y permisos --------------------------------------------------------
$catalog = AssistantCapabilityRegistry::catalogForState();
$byId = AssistantCapabilityRegistry::byId($catalog);
checkV2F4b('chrome_edit_is_automatic', ($byId['chrome.edit']['mode'] ?? '') === 'automatic' && ($byId['chrome.edit']['handler'] ?? '') === 'chrome_edit');
checkV2F4b('chrome_edit_live_and_undoable', ($byId['chrome.edit']['risk'] ?? '') === 'live' && ($byId['chrome.edit']['undo'] ?? false) === true);
checkV2F4b('chrome_design_stays_manual', ($byId['chrome.manage']['mode'] ?? '') === 'manual');
checkV2F4b('only_admin_runs_chrome', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_ADMIN, 'chrome.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_EDITOR, 'chrome.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'chrome.edit'));

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [[
    'capability_id' => 'chrome.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 7, 'instruction' => 'Añadir «Blog» al menú',
]], [7 => ['id' => 7, 'title' => 'X', 'slug' => 'x', 'editable' => true, 'sections' => []]], $catalog, null, []);
checkV2F4b('chrome_item_is_site_wide', ($items[0]['category'] ?? '') === 'automatable_now' && ($items[0]['page_id'] ?? -1) === 0, json_encode($items[0] ?? null, JSON_UNESCAPED_UNICODE));

// --- Diff puro ----------------------------------------------------------------------
$base = ['menu' => [
    ['key' => 'p1', 'label' => 'Inicio', 'type' => 'page', 'visible' => true, 'children' => []],
    ['key' => 'p2', 'label' => 'Servicios', 'type' => 'page', 'visible' => true, 'children' => []],
    ['key' => 'p3', 'label' => 'Contacto', 'type' => 'page', 'visible' => true, 'children' => []],
], 'cta' => ['mode' => 'auto', 'label' => '', 'url' => ''],
'footer' => ['tagline' => '', 'nav' => [], 'contact' => [], 'social' => [], 'copyright' => '']];
$next = $base;
$next['menu'] = [
    $base['menu'][0],
    ['key' => 'dtratamientos', 'label' => 'Tratamientos', 'type' => 'dropdown', 'visible' => true, 'children' => [
        ['key' => 'p2', 'label' => 'Servicios', 'type' => 'page', 'visible' => true, 'children' => []],
    ]],
    ['key' => 'l/blog', 'label' => 'Blog', 'type' => 'link', 'visible' => true, 'children' => []],
];
$next['footer']['contact'] = ['phone' => '600 000 000'];
$diff = ChromeAssistantEditor::diff($base, $next);
$byLabel = [];
foreach ($diff as $c) $byLabel[$c['label']] = $c;
checkV2F4b('diff_added_link', ($byLabel['Blog']['kind'] ?? '') === 'added', json_encode($diff, JSON_UNESCAPED_UNICODE));
checkV2F4b('diff_added_dropdown', ($byLabel['Tratamientos']['kind'] ?? '') === 'added');
checkV2F4b('diff_moved_into_dropdown', ($byLabel['Servicios']['kind'] ?? '') === 'changed' && str_contains($byLabel['Servicios']['detail'], 'Tratamientos'));
checkV2F4b('diff_removed_page', ($byLabel['Contacto']['kind'] ?? '') === 'removed');
checkV2F4b('diff_footer_contact', ($byLabel[__('asst.chrome.contact')]['detail'] ?? '') === '600 000 000');
checkV2F4b('diff_empty_when_equal', ChromeAssistantEditor::diff($base, $base) === []);

// --- Con BD -------------------------------------------------------------------------
$site = 1;
$row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$site, ChromeService::SETTING_KEY]);
$backup = $row !== null ? (string) $row['setting_value'] : null;
$maxVersion = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS id FROM versions')['id'] ?? 0);
$jobIds = [];
$writeRaw = static function (?string $raw) use ($site): void {
    if ($raw === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$site, ChromeService::SETTING_KEY]);
    } else {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$site, ChromeService::SETTING_KEY, $raw]
        );
    }
};
try {
    // Partimos de «nunca configurado»: menú automático.
    $writeRaw(null);
    $config = ChromeService::load($site);
    $primary = LanguageService::primaryFor($site);
    $auto = HeaderMenuService::editableMenu($config, $primary, $primary, HeaderMenuService::autoByLang($site));
    checkV2F4b('fixture_has_auto_menu', $auto !== [], 'El sitio 1 no tiene páginas publicadas en el menú automático.');

    $same = ChromeAssistantEditor::prepare($site, ['header' => ['menu' => $auto]]);
    checkV2F4b('same_as_auto_stays_automatic', $same['ok'] === true && $same['content']['header']['menu'] === [], json_encode($same['content']['header']['menu'] ?? null));

    $bad = ChromeAssistantEditor::prepare($site, ['header' => ['menu' => array_merge($auto, [['type' => 'page', 'page_id' => 999999999, 'label' => 'Fantasma']])]]);
    checkV2F4b('unknown_page_rejected', $bad['ok'] === false && str_contains((string) $bad['error'], '999999999'));

    $draft = ['header' => ['menu' => array_merge($auto, [['type' => 'link', 'label' => 'Blog F4b', 'url' => '/blog', 'visible' => true]]),
        'cta' => ['mode' => 'custom', 'label' => 'Pide cita', 'url' => '/contacto', 'style' => 'primary']],
        'footer' => ['contact' => ['phone' => '600 000 000']]];
    $prepared = ChromeAssistantEditor::prepare($site, $draft);
    checkV2F4b('prepare_materialises_menu', $prepared['ok'] === true && count($prepared['content']['header']['menu']) === count($auto) + 1, json_encode($prepared, JSON_UNESCAPED_UNICODE));
    $preview = ChromeAssistantEditor::preview($site, $prepared['before'], $prepared['content']);
    $labels = array_column($preview['changes'], 'label');
    checkV2F4b('preview_lists_changes', $preview['kind'] === 'chrome' && in_array('Blog F4b', $labels, true)
        && in_array(__('asst.chrome.cta'), $labels, true), json_encode($preview['changes'], JSON_UNESCAPED_UNICODE));
    checkV2F4b('preview_resolves_page_labels', ($preview['after']['menu'][0]['label'] ?? '') !== '' && !str_starts_with((string) $preview['after']['menu'][0]['label'], '#'));

    $instruction = 'Añade Blog al menú y un botón «Pide cita»';
    $token = AssistantProposal::seal('chrome', $site, $site, $instruction, $prepared['content'], $prepared['base_hash']);
    $created = SiteAssistantJobs::createJob($site, 'Prueba F4b', 'Menú', [[
        'capability_id' => 'chrome.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
        'page_id' => 0, 'section' => '', 'instruction' => $instruction, 'preview_token' => $token,
    ]], null);
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    $step = SiteAssistantJobs::stepJob($jobId, $site);
    $done = $step['job']['items'][0] ?? [];
    checkV2F4b('step_saves_previewed_chrome', ($done['status'] ?? '') === 'done' && ($done['capability_id'] ?? '') === 'chrome.edit', json_encode($done, JSON_UNESCAPED_UNICODE));
    $saved = ChromeService::load($site);
    checkV2F4b('menu_and_cta_saved', end($saved['header']['menu'])['label'] === 'Blog F4b' && $saved['header']['cta']['label'] === 'Pide cita'
        && $saved['footer']['contact']['phone'] === '600 000 000');

    $publish = SiteAssistantJobs::publishJob($jobId, $site, 1);
    checkV2F4b('publish_ignores_chrome', ($publish['pages'] ?? null) === []);
    $stale = ChromeAssistantEditor::commit($site, $prepared['content'], $prepared['base_hash'], null);
    checkV2F4b('stale_chrome_proposal_not_saved', $stale['ok'] === false && $stale['error'] === __('asst.err.chrome_changed_since_preview'));

    $entries = array_values(array_filter(ActivityService::entries($site, Permissions::ROLE_ADMIN, 'assistant'), static fn (array $e): bool => $e['type'] === 'chrome'));
    checkV2F4b('activity_lists_chrome_for_admin', $entries !== [] && $entries[0]['actor'] === 'assistant');
    checkV2F4b('editor_does_not_see_chrome', array_filter(ActivityService::entries($site, Permissions::ROLE_EDITOR, 'all'), static fn (array $e): bool => $e['type'] === 'chrome') === []);
    $denied = ActivityService::undo($site, Permissions::ROLE_EDITOR, null, 'chrome', $site, (int) ($entries[0]['version_id'] ?? 0));
    checkV2F4b('editor_cannot_undo_chrome', $denied['ok'] === false);

    $undo = ActivityService::undo($site, Permissions::ROLE_ADMIN, null, 'chrome', $site, (int) ($entries[0]['version_id'] ?? 0));
    checkV2F4b('undo_back_to_automatic', $undo['ok'] === true && ChromeService::load($site)['header']['menu'] === []
        && !ChromeService::isConfigured($site), json_encode($undo));
} finally {
    $writeRaw($backup);
    Database::execute('DELETE FROM versions WHERE id > ? AND entity_type = ? AND entity_id = ?', [$maxVersion, ChromeAssistantEditor::ENTITY, $site]);
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    \App\Services\CacheService::flush($site);
}
$after = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$site, ChromeService::SETTING_KEY]);
checkV2F4b('original_config_restored', ($after === null ? null : (string) $after['setting_value']) === $backup);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
