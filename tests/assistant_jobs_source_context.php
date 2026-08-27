<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\AssistantSourceEnvelope;
use App\Services\SiteAssistantJobs;
use Core\Database;

$failed = 0;
$createdJobs = [];
function checkAssistantJobSource(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$fixture = Database::selectOne(
    "SELECT p.id AS page_id, p.site_id, m.id AS media_id, m.path
     FROM pages p JOIN media m ON m.site_id = p.site_id
     WHERE p.render_mode = 'canvas' ORDER BY p.id, m.id LIMIT 1"
);
if (!$fixture) {
    fwrite(STDERR, "SKIP assistant_jobs_source_context: no canvas page with owned media\n");
    exit(0);
}

$siteId = (int) $fixture['site_id'];
$pageId = (int) $fixture['page_id'];
$mediaId = (int) $fixture['media_id'];
$exact = "Bienvenue chez Margaux — l'équilibre, sans résumé ni invention.";
$bundle = [
    'status' => 'ready',
    'blocks' => [
        ['id' => 'B1', 'type' => 'heading', 'text' => 'À propos', 'level' => 2],
        ['id' => 'B2', 'type' => 'paragraph', 'text' => $exact],
        ['id' => 'B3', 'type' => 'image', 'text' => 'Portrait', 'media_ref' => 'IMG-1'],
    ],
    'media' => [[
        'ref' => 'IMG-1', 'media_id' => $mediaId, 'status' => 'stored',
        'source_kind' => 'media', 'source' => '/storage/uploads/999/forged.jpg', 'alt' => 'Portrait',
    ]],
];
$item = [
    'status' => 'aplicar', 'category' => 'automatable_now',
    'capability_id' => 'pages.canvas.edit', 'page_id' => $pageId, 'section' => '',
    'instruction' => 'Añade la presentación y su retrato.',
    'source_block_ids' => ['B3', 'B2', 'B1'], 'media_ids' => [$mediaId, 99999999],
];
$source = AssistantSourceEnvelope::open(
    AssistantSourceEnvelope::issue($siteId, $bundle, [$item]),
    $siteId
);
$result = SiteAssistantJobs::createJob($siteId, 'Prueba AR5', 'Contexto exacto', [$item], null, $source);
checkAssistantJobSource('job_with_source_is_created', ($result['ok'] ?? false) === true, json_encode($result));

if (($result['ok'] ?? false) === true) {
    $jobId = (int) $result['job']['id'];
    $createdJobs[] = $jobId;
    $job = Database::selectOne('SELECT * FROM assistant_jobs WHERE id = ?', [$jobId]);
    $stored = Database::selectOne('SELECT * FROM assistant_job_items WHERE job_id = ? LIMIT 1', [$jobId]);
    $refs = json_decode((string) $stored['source_block_ids_json'], true);
    $media = json_decode((string) $stored['media_ids_json'], true);
    checkAssistantJobSource('block_references_are_reordered_to_source_order', $refs === ['B1', 'B2', 'B3'], json_encode($refs));
    checkAssistantJobSource('unknown_media_is_removed', $media === [$mediaId], json_encode($media));
    checkAssistantJobSource('raw_or_forged_paths_are_not_persisted',
        !str_contains((string) $job['source_bundle_json'], 'forged.jpg')
        && !str_contains((string) $job['source_bundle_json'], 'prompt_text')
    );
    $context = SiteAssistantJobs::buildSourceContext($job, $stored, $siteId);
    checkAssistantJobSource('literal_text_reaches_executor_context', str_contains($context, $exact), $context);
    checkAssistantJobSource('only_current_owned_media_path_reaches_context',
        str_contains($context, '/' . ltrim((string) $fixture['path'], '/'))
        && !str_contains($context, 'forged.jpg'),
        $context
    );
    checkAssistantJobSource('source_order_is_preserved_in_context',
        strpos($context, '[B1 ') < strpos($context, '[B2 ')
        && strpos($context, '[B2 ') < strpos($context, '[B3 ')
    );
}

$changed = $item;
$changed['instruction'] = 'Una instrucción manipulada.';
$rejected = SiteAssistantJobs::createJob($siteId, 'Prueba', 'Manipulado', [$changed], null, $source);
checkAssistantJobSource('changed_plan_item_is_rejected', ($rejected['ok'] ?? true) === false);

$longBundle = [
    'status' => 'ready',
    'blocks' => [
        ['id' => 'B1', 'type' => 'paragraph', 'text' => str_repeat('A', 13000)],
        ['id' => 'B2', 'type' => 'paragraph', 'text' => str_repeat('B', 13000)],
    ],
    'media' => [],
];
$longItem = $item;
$longItem['instruction'] = 'Distribuye el contenido completo.';
$longItem['source_block_ids'] = ['B1', 'B2'];
$longItem['media_ids'] = [];
$longSource = AssistantSourceEnvelope::open(
    AssistantSourceEnvelope::issue($siteId, $longBundle, [$longItem]),
    $siteId
);
$longResult = SiteAssistantJobs::createJob($siteId, 'Prueba larga', 'Partes', [$longItem], null, $longSource);
checkAssistantJobSource('long_source_is_split_on_block_boundaries',
    ($longResult['ok'] ?? false) === true && (int) ($longResult['job']['total'] ?? 0) === 2,
    json_encode($longResult)
);
if (($longResult['ok'] ?? false) === true) $createdJobs[] = (int) $longResult['job']['id'];

foreach ($createdJobs as $jobId) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$jobId]);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
