<?php

declare(strict_types=1);

// ASST-V2 F4a — el asistente cambia el título SEO y la meta descripción.
// La parte con BD usa una página real del sitio 1: guarda su SEO, lo cambia
// con una propuesta firmada (sin IA), lo deshace y lo deja como estaba.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ActivityService;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\PageSeoAssistantEditor;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4a(string $name, bool $ok, string $detail = ''): void
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
$seo = $byId['seo.page.edit'] ?? [];
checkV2F4a('seo_page_edit_is_automatic', ($seo['mode'] ?? '') === 'automatic' && ($seo['handler'] ?? '') === 'seo_page_edit');
checkV2F4a('seo_page_edit_live_and_undoable', ($seo['risk'] ?? '') === 'live' && ($seo['undo'] ?? false) === true);
checkV2F4a('seo_manage_stays_manual', ($byId['seo.manage']['mode'] ?? '') === 'manual');
checkV2F4a('editor_can_run_seo', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_EDITOR, 'seo.page.edit'));
checkV2F4a('redactor_cannot_run_seo_or_forms', !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'seo.page.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'forms.edit')
    && AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'pages.canvas.edit'));
checkV2F4a('unknown_capability_never_runs', !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_ADMIN, 'chrome.manage'));
checkV2F4a('seo_item_is_executable', SiteAssistantJobs::isExecutablePlanItem(['status' => 'aplicar', 'category' => 'automatable_now', 'capability_id' => 'seo.page.edit']));

// --- Planner: SEO vale también en páginas clásicas y es de la página entera -------
$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$pages = [5 => ['id' => 5, 'title' => 'Servicios', 'slug' => 'servicios', 'editable' => false, 'sections' => ['Hero']]];
$items = $normalizer->invoke(null, [[
    'capability_id' => 'seo.page.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 5, 'section' => 'Hero', 'instruction' => 'Enfocar a cirugía estética en Madrid',
]], $pages, $catalog, null, []);
checkV2F4a('seo_on_classic_page_stays_automatic', ($items[0]['category'] ?? '') === 'automatable_now' && ($items[0]['status'] ?? '') === 'aplicar', json_encode($items[0] ?? null, JSON_UNESCAPED_UNICODE));
checkV2F4a('seo_item_has_no_section', ($items[0]['section'] ?? 'x') === '');
$canvasOnly = $normalizer->invoke(null, [[
    'capability_id' => 'pages.canvas.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 5, 'instruction' => 'Cambiar el titular',
]], $pages, $catalog, null, []);
checkV2F4a('canvas_edit_still_needs_canvas', ($canvasOnly[0]['category'] ?? '') === 'manual_in_platform');

// --- Validación y firma -------------------------------------------------------------
checkV2F4a('empty_title_rejected', PageSeoAssistantEditor::errors(['meta_title' => '', 'meta_description' => 'x']) !== []);
checkV2F4a('valid_seo_accepted', PageSeoAssistantEditor::errors(['meta_title' => 'Clínica', 'meta_description' => '']) === []);
$formToken = AssistantProposal::seal('form', 1, 5, 'x', ['a' => 1], 'h');
checkV2F4a('form_token_is_not_seo_token', AssistantProposal::open($formToken, 'seo', 1, 5, 'x') === null
    && AssistantProposal::open($formToken, 'form', 1, 5, 'x') !== null);

// --- Con BD -----------------------------------------------------------------------
$site = 1;
$page = Database::selectOne(
    "SELECT id, title, meta_title, meta_description FROM pages WHERE site_id = ? AND slug NOT LIKE '\\_\\_%' ORDER BY id LIMIT 1",
    [$site]
);
if ($page === null) {
    checkV2F4a('fixture_page_exists', false, 'El sitio 1 no tiene páginas.');
} else {
    $pageId = (int) $page['id'];
    $original = ['meta_title' => $page['meta_title'], 'meta_description' => $page['meta_description']];
    $maxVersion = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS id FROM versions')['id'] ?? 0);
    $jobIds = [];
    try {
        $state = PageSeoAssistantEditor::state($page);
        $baseHash = AssistantProposal::hash($state);
        $new = ['meta_title' => 'Prueba F4a · Clínica en Madrid', 'meta_description' => 'Descripción de prueba del asistente para Google.'];
        $instruction = 'Enfocar a clínica en Madrid';

        $preview = PageSeoAssistantEditor::preview($site, $pageId, $state, $new);
        checkV2F4a('preview_is_seo_snippet', $preview['kind'] === 'seo' && $preview['after']['title'] === $new['meta_title']
            && $preview['limits']['title'] === 60 && count($preview['changes']) >= 1, json_encode($preview, JSON_UNESCAPED_UNICODE));

        $token = AssistantProposal::seal('seo', $site, $pageId, $instruction, $new, $baseHash);
        $item = ['capability_id' => 'seo.page.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
            'page_id' => $pageId, 'section' => '', 'instruction' => $instruction, 'preview_token' => $token];
        $created = SiteAssistantJobs::createJob($site, 'Prueba F4a', 'SEO', [$item], null);
        $jobId = (int) ($created['job']['id'] ?? 0);
        $jobIds[] = $jobId;
        $stored = Database::selectOne('SELECT capability_id, proposal_token FROM assistant_job_items WHERE job_id = ?', [$jobId]);
        checkV2F4a('job_item_is_seo_with_token', ($stored['capability_id'] ?? '') === 'seo.page.edit' && ($stored['proposal_token'] ?? '') === $token, json_encode($created));
        checkV2F4a('job_state_reports_capability', ($created['job']['items'][0]['capability_id'] ?? '') === 'seo.page.edit');

        $started = microtime(true);
        $step = SiteAssistantJobs::stepJob($jobId, $site);
        $done = $step['job']['items'][0] ?? [];
        checkV2F4a('step_saves_previewed_seo_without_ai', ($done['status'] ?? '') === 'done' && microtime(true) - $started < 5, json_encode($done, JSON_UNESCAPED_UNICODE));
        $now = Database::selectOne('SELECT meta_title, meta_description FROM pages WHERE id = ?', [$pageId]);
        checkV2F4a('page_has_new_seo', ($now['meta_title'] ?? '') === $new['meta_title'] && ($now['meta_description'] ?? '') === $new['meta_description']);

        $publish = SiteAssistantJobs::publishJob($jobId, $site, 1);
        checkV2F4a('publish_ignores_seo_items', ($publish['ok'] ?? false) === true && ($publish['pages'] ?? null) === [], json_encode($publish, JSON_UNESCAPED_UNICODE));

        $stale = PageSeoAssistantEditor::commit($site, $pageId, $new, $baseHash, null);
        checkV2F4a('stale_seo_proposal_not_saved', $stale['ok'] === false && $stale['error'] === __('asst.err.seo_changed_since_preview'));

        $entries = array_values(array_filter(
            ActivityService::entries($site, Permissions::ROLE_ADMIN, 'assistant'),
            static fn (array $e): bool => $e['type'] === 'seo' && $e['entity_id'] === $pageId
        ));
        checkV2F4a('activity_lists_seo_change', $entries !== [] && $entries[0]['actor'] === 'assistant', json_encode($entries[0] ?? null, JSON_UNESCAPED_UNICODE));
        checkV2F4a('redactor_does_not_see_seo', array_filter(
            ActivityService::entries($site, Permissions::ROLE_REDACTOR, 'all'),
            static fn (array $e): bool => $e['type'] === 'seo'
        ) === []);

        $undo = ActivityService::undo($site, Permissions::ROLE_ADMIN, null, 'seo', $pageId, (int) ($entries[0]['version_id'] ?? 0));
        $back = Database::selectOne('SELECT meta_title, meta_description FROM pages WHERE id = ?', [$pageId]);
        checkV2F4a('undo_restores_seo', $undo['ok'] === true && $back['meta_title'] === $original['meta_title']
            && $back['meta_description'] === $original['meta_description'], json_encode([$undo, $back, $original], JSON_UNESCAPED_UNICODE));
        $denied = ActivityService::undo($site, Permissions::ROLE_REDACTOR, null, 'seo', $pageId, (int) ($entries[0]['version_id'] ?? 0));
        checkV2F4a('redactor_cannot_undo_seo', $denied['ok'] === false);
    } finally {
        Database::execute('UPDATE pages SET meta_title = ?, meta_description = ? WHERE id = ?', [$original['meta_title'], $original['meta_description'], $pageId]);
        Database::execute('DELETE FROM versions WHERE id > ? AND entity_type = ? AND entity_id = ?', [$maxVersion, PageSeoAssistantEditor::ENTITY, $pageId]);
        foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    }
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
