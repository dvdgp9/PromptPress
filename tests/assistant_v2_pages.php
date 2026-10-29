<?php

declare(strict_types=1);

// ASST-V2 F4d — el asistente crea páginas nuevas en borrador y propone las que
// faltan. Sin IA: firma del plan, límites y creación desde una precomposición
// (página de prueba en el sitio 1 que se borra al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\PageAssistant;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4d(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Registro ----------------------------------------------------------------------
$catalog = AssistantCapabilityRegistry::catalogForState();
$byId = AssistantCapabilityRegistry::byId($catalog);
checkV2F4d('pages_create_is_automatic_draft', ($byId['pages.create']['mode'] ?? '') === 'automatic'
    && ($byId['pages.create']['risk'] ?? '') === 'draft' && ($byId['pages.create']['handler'] ?? '') === 'page_create');
checkV2F4d('pages_ideas_is_read_only', ($byId['pages.ideas']['mode'] ?? '') === 'automatic' && ($byId['pages.ideas']['risk'] ?? '') === 'read');
checkV2F4d('content_role_can_create_pages', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'pages.create'));
checkV2F4d('page_ideas_not_executed_itself', !SiteAssistantJobs::isExecutablePlanItem(['status' => 'aplicar', 'category' => 'automatable_now', 'capability_id' => 'pages.ideas']));

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [
    ['capability_id' => 'pages.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 4, 'instruction' => 'Crea la página «Medicina facial»'],
], [4 => ['id' => 4, 'title' => 'X', 'slug' => 'x', 'editable' => true, 'sections' => []]], $catalog, null, []);
checkV2F4d('page_create_item_is_site_wide', ($items[0]['page_id'] ?? -1) === 0 && ($items[0]['category'] ?? '') === 'automatable_now', json_encode($items[0] ?? null, JSON_UNESCAPED_UNICODE));

// --- Puros ---------------------------------------------------------------------------
$idea = ['title' => 'Microinjerto capilar', 'angle' => 'Explicar el tratamiento y captar consultas', 'audience' => 'Hombres de 30 a 50'];
$ideaInstruction = PageAssistant::ideaInstruction($idea);
checkV2F4d('idea_instruction_names_page', str_contains($ideaInstruction, '«Microinjerto capilar»') && str_contains($ideaInstruction, 'captar consultas'));
$brief = [
    'title' => 'Prueba F4d · Microinjerto capilar', 'page_type' => 'service', 'goal' => 'Explicar el tratamiento y captar consultas.',
    'audience' => 'Hombres de 30 a 50', 'tone' => '', 'seo_intent' => '', 'primary_cta' => 'Pide tu valoración',
    'recommended_form' => ['needed' => true, 'purpose' => 'Consulta', 'fields' => [['label' => 'Nombre', 'name' => 'nombre', 'field_type' => 'text', 'required' => true, 'placeholder' => '']]],
    'sections' => [['type' => 'hero', 'heading' => 'Recupera tu pelo', 'purpose' => 'Presentar la técnica'], ['type' => 'faq', 'heading' => 'Dudas', 'purpose' => '']],
    'questions' => [], 'extra_context' => '',
];
$preview = PageAssistant::preview($brief, false);
checkV2F4d('preview_lists_sections_and_form', $preview['kind'] === 'page' && count($preview['sections']) === 2
    && $preview['sections'][0]['heading'] === 'Recupera tu pelo' && $preview['form'] === ['Nombre'] && $preview['type'] === __('page_type.service'),
    json_encode($preview, JSON_UNESCAPED_UNICODE));

// --- Trabajos y creación -------------------------------------------------------------
$site = 1;
$jobIds = [];
$pageIds = [];
$composeKeys = ['studio_compose_meta', 'studio_compose_html', 'studio_compose_css'];
$composeBackup = Database::select(
    'SELECT setting_key, setting_value FROM settings WHERE site_id = ? AND setting_key IN (?, ?, ?)',
    array_merge([$site], $composeKeys)
);
try {
    $instruction = 'Crea la página «Microinjerto capilar». Objetivo: captar consultas.';
    $token = AssistantProposal::seal('page', $site, $site, $instruction, $brief, '');
    $item = ['capability_id' => 'pages.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0,
        'instruction' => $instruction, 'preview_token' => $token];
    $created = SiteAssistantJobs::createJob($site, 'Prueba F4d', 'Página', [$item], null);
    $jobIds[] = (int) ($created['job']['id'] ?? 0);
    $row = Database::selectOne('SELECT capability_id, page_title, proposal_token FROM assistant_job_items WHERE job_id = ?', [(int) ($created['job']['id'] ?? 0)]);
    checkV2F4d('job_keeps_previewed_plan', ($row['capability_id'] ?? '') === 'pages.create' && ($row['page_title'] ?? '') === $brief['title']
        && ($row['proposal_token'] ?? '') === $token, json_encode($row, JSON_UNESCAPED_UNICODE));

    $bad = $item;
    $bad['preview_token'] = 'ap1.nope.nope';
    $created2 = SiteAssistantJobs::createJob($site, 'Prueba F4d', 'Token malo', [$bad], null);
    $jobIds[] = (int) ($created2['job']['id'] ?? 0);
    $row2 = Database::selectOne('SELECT page_title, proposal_token FROM assistant_job_items WHERE job_id = ?', [(int) ($created2['job']['id'] ?? 0)]);
    checkV2F4d('bad_plan_token_dropped', is_array($row2) && $row2['proposal_token'] === null && $row2['page_title'] === 'Microinjerto capilar', json_encode($row2, JSON_UNESCAPED_UNICODE));

    $source = ['bundle' => [], 'authorized_item_hashes' => []];
    $fromIdea = ['capability_id' => 'pages.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0,
        'instruction' => $ideaInstruction, 'preview_token' => AssistantProposal::seal('page_idea', $site, $site, $ideaInstruction, $idea, '')];
    $created3 = SiteAssistantJobs::createJob($site, 'Prueba F4d', 'Idea', [$fromIdea], null, $source);
    $jobIds[] = (int) ($created3['job']['id'] ?? 0);
    checkV2F4d('signed_page_idea_accepted_with_source', $created3['ok'] === true, json_encode($created3, JSON_UNESCAPED_UNICODE));
    $postToken = AssistantProposal::seal('post_idea', $site, $site, $ideaInstruction, $idea, '');
    $wrongKind = $fromIdea;
    $wrongKind['preview_token'] = $postToken;
    checkV2F4d('post_idea_token_does_not_open_pages', SiteAssistantJobs::createJob($site, 'Prueba F4d', 'Tipo cruzado', [$wrongKind], null, $source)['ok'] === false);

    $many = [];
    for ($i = 1; $i <= 5; $i++) {
        $many[] = ['capability_id' => 'pages.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'instruction' => 'Crea la página «P' . $i . '»'];
    }
    $capped = SiteAssistantJobs::createJob($site, 'Prueba F4d', 'Muchas', $many, null);
    $jobIds[] = (int) ($capped['job']['id'] ?? 0);
    checkV2F4d('at_most_three_pages_per_plan', ($capped['job']['total'] ?? 0) === PageAssistant::MAX_PAGES_PER_PLAN);

    // Con la página ya precompuesta (lo que hace la tarjeta), crear no llama a la IA.
    $normalised = PageController::decodePostedBrief((string) json_encode($brief, JSON_UNESCAPED_UNICODE));
    PageController::storeComposition($site, PageController::compositionSignature($normalised, ''),
        '<section data-pp-section="hero"><h1>Recupera tu pelo</h1></section>', '', [
            'title' => $brief['title'], 'page_type' => 'service', 'goal' => $brief['goal'],
        ]);
    $started = microtime(true);
    $made = PageAssistant::create($site, $brief);
    $pageIds[] = (int) ($made['page_id'] ?? 0);
    checkV2F4d('create_uses_precomposition_without_ai', $made['ok'] === true && ($made['prepared'] ?? false) === true
        && microtime(true) - $started < 5, json_encode($made, JSON_UNESCAPED_UNICODE));
    $page = Database::selectOne('SELECT title, status, render_mode, page_type FROM pages WHERE id = ?', [(int) ($made['page_id'] ?? 0)]);
    checkV2F4d('new_page_is_canvas_draft', ($page['status'] ?? '') === 'draft' && ($page['render_mode'] ?? '') === 'canvas'
        && ($page['page_type'] ?? '') === 'service', json_encode($page, JSON_UNESCAPED_UNICODE));
    checkV2F4d('precomposition_is_single_use', PageController::readComposition($site, PageController::compositionSignature($normalised, '')) === null);
    $card = PageAssistant::card($site, (int) ($made['page_id'] ?? 0));
    checkV2F4d('card_links_to_studio', is_array($card) && str_ends_with((string) $card['edit_url'], '/admin/canvas/' . (int) $made['page_id']));
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    foreach ($pageIds as $id) if ($id > 0) Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$id, $site]);
    PageController::clearComposition($site);
    foreach ($composeBackup as $r) {
        Database::execute('INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)',
            [$site, $r['setting_key'], $r['setting_value']]);
    }
    \App\Services\CacheService::flush($site);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
