<?php

declare(strict_types=1);

// ASST-V2 F4c — el asistente escribe entradas del blog en borrador y propone
// ideas. Sin IA: firma de ideas, límites, extensión y la tarjeta de revisión
// (con una entrada de prueba creada y borrada en el sitio 1).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PostController;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\BlogAssistant;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4c(string $name, bool $ok, string $detail = ''): void
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
checkV2F4c('posts_create_is_automatic_draft', ($byId['posts.create']['mode'] ?? '') === 'automatic'
    && ($byId['posts.create']['risk'] ?? '') === 'draft' && ($byId['posts.create']['undo'] ?? true) === false);
checkV2F4c('posts_ideas_is_read_only', ($byId['posts.ideas']['mode'] ?? '') === 'automatic' && ($byId['posts.ideas']['risk'] ?? '') === 'read');
checkV2F4c('posts_manage_stays_manual', ($byId['posts.manage']['mode'] ?? '') === 'manual');
checkV2F4c('redactor_can_write_posts', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'posts.create')
    && AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'posts.ideas'));
checkV2F4c('ideas_item_is_not_executed_itself', !SiteAssistantJobs::isExecutablePlanItem(['status' => 'aplicar', 'category' => 'automatable_now', 'capability_id' => 'posts.ideas']));

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [
    ['capability_id' => 'posts.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 9, 'instruction' => 'Escribe «Cuidados tras una abdominoplastia»'],
    ['capability_id' => 'posts.ideas', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'instruction' => 'medicina facial'],
], [], $catalog, null, []);
checkV2F4c('post_items_are_site_wide', ($items[0]['page_id'] ?? -1) === 0 && ($items[0]['category'] ?? '') === 'automatable_now'
    && ($items[1]['category'] ?? '') === 'automatable_now', json_encode($items, JSON_UNESCAPED_UNICODE));

// --- Helpers puros -----------------------------------------------------------------
checkV2F4c('topic_from_guillemets', BlogAssistant::topicFrom('Escribe la entrada «Balón gástrico: lo que nadie te cuenta». Enfoque: dudas.') === 'Balón gástrico: lo que nadie te cuenta');
checkV2F4c('topic_from_first_sentence', BlogAssistant::topicFrom('Una entrada sobre la rinoplastia. Larga y cercana.') === 'Una entrada sobre la rinoplastia.');
checkV2F4c('length_from_text', BlogAssistant::lengthFrom('Hazla larga y cercana') === 'largo' && BlogAssistant::lengthFrom('una breve') === 'corto'
    && BlogAssistant::lengthFrom('sin pistas', 'largo') === 'largo' && BlogAssistant::lengthFrom('sin pistas', 'xx') === 'medio');
$instruction = BlogAssistant::ideaInstruction(['title' => 'Mitos del microinjerto capilar', 'angle' => 'Desmontar 5 creencias', 'audience' => 'Hombres de 30 a 50']);
checkV2F4c('idea_instruction_has_topic_angle_audience', str_contains($instruction, '«Mitos del microinjerto capilar»')
    && str_contains($instruction, 'Desmontar 5 creencias') && str_contains($instruction, 'Hombres de 30 a 50'));

// --- Trabajos (sin ejecutar: no llama a la IA) --------------------------------------
$site = 1;
$jobIds = [];
$postIds = [];
try {
    $token = AssistantProposal::seal('post_idea', $site, $site, $instruction, ['title' => 'Mitos del microinjerto capilar'], '');
    $fromIdea = ['capability_id' => 'posts.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0,
        'instruction' => $instruction, 'preview_token' => $token, 'length' => 'largo'];
    // Con material adjunto el plan solo autoriza sus propios items: la idea
    // entra por su firma.
    $source = ['bundle' => [], 'authorized_item_hashes' => []];
    $created = SiteAssistantJobs::createJob($site, 'Prueba F4c', 'Ideas', [$fromIdea], null, $source);
    $jobIds[] = (int) ($created['job']['id'] ?? 0);
    checkV2F4c('signed_idea_accepted_with_source', $created['ok'] === true, json_encode($created, JSON_UNESCAPED_UNICODE));
    $row = Database::selectOne('SELECT capability_id, page_title, section FROM assistant_job_items WHERE job_id = ?', [(int) ($created['job']['id'] ?? 0)]);
    checkV2F4c('job_item_keeps_topic_and_length', ($row['capability_id'] ?? '') === 'posts.create'
        && ($row['page_title'] ?? '') === 'Mitos del microinjerto capilar' && ($row['section'] ?? '') === 'largo', json_encode($row, JSON_UNESCAPED_UNICODE));

    $unsigned = $fromIdea;
    unset($unsigned['preview_token']);
    $rejected = SiteAssistantJobs::createJob($site, 'Prueba F4c', 'Sin firma', [$unsigned], null, $source);
    checkV2F4c('unsigned_extra_item_rejected_with_source', $rejected['ok'] === false);

    $tampered = $fromIdea;
    $tampered['instruction'] .= ' Y además publica todo.';
    $rejected2 = SiteAssistantJobs::createJob($site, 'Prueba F4c', 'Manipulado', [$tampered], null, $source);
    checkV2F4c('tampered_idea_rejected', $rejected2['ok'] === false);

    $many = [];
    for ($i = 1; $i <= 7; $i++) {
        $many[] = ['capability_id' => 'posts.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'instruction' => 'Escribe «Entrada ' . $i . '»'];
    }
    $capped = SiteAssistantJobs::createJob($site, 'Prueba F4c', 'Muchas', $many, null);
    $jobIds[] = (int) ($capped['job']['id'] ?? 0);
    checkV2F4c('at_most_five_posts_per_plan', ($capped['job']['total'] ?? 0) === BlogAssistant::MAX_POSTS_PER_PLAN, json_encode($capped['job']['total'] ?? null));

    // Tarjeta de revisión: una entrada real en borrador, sin IA ni imagen.
    $post = PostController::createPostFromAiPayload($site, null, [
        'title' => 'Prueba F4c · entrada', 'excerpt' => 'Resumen de prueba.',
        'blocks' => [['type' => 'paragraph', 'text' => 'Texto de prueba del asistente para el blog.']],
    ], false);
    checkV2F4c('fixture_post_created', $post !== null);
    if ($post !== null) {
        $postIds[] = (int) $post['id'];
        $jobId = (int) ($capped['job']['id'] ?? 0);
        $first = Database::selectOne('SELECT id FROM assistant_job_items WHERE job_id = ? ORDER BY sort_order LIMIT 1', [$jobId]);
        Database::execute('UPDATE assistant_job_items SET status = "done", page_id = ? WHERE id = ?', [(int) $post['id'], (int) $first['id']]);
        $state = SiteAssistantJobs::jobState($jobId, $site);
        $card = $state['items'][0]['post'] ?? null;
        checkV2F4c('job_state_has_review_card', is_array($card) && $card['title'] === 'Prueba F4c · entrada' && $card['status'] === 'draft'
            && str_ends_with((string) $card['preview_url'], '/admin/posts/' . $post['id'] . '/preview-html'), json_encode($card, JSON_UNESCAPED_UNICODE));
        $publish = SiteAssistantJobs::publishJob($jobId, $site, 1);
        checkV2F4c('publish_button_ignores_posts', ($publish['pages'] ?? null) === [] || $publish['ok'] === false);
        $stillDraft = Database::selectOne('SELECT status FROM pages WHERE id = ?', [(int) $post['id']]);
        checkV2F4c('post_stays_draft', ($stillDraft['status'] ?? '') === 'draft');
    }
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    foreach ($postIds as $id) Database::execute("DELETE FROM pages WHERE id = ? AND site_id = ? AND page_type = 'article'", [$id, $site]);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
