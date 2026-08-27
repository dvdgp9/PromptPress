<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\SiteAssistantPlanner;
use Core\Database;

if (!in_array('--live', $argv, true)) {
    echo "SKIP usa --live para llamar al provider configurado\n";
    exit(0);
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
$page = Database::selectOne(
    "SELECT p.id, p.title FROM pages p JOIN page_canvas pc ON pc.page_id = p.id
     WHERE p.site_id = ? AND p.render_mode = 'canvas' ORDER BY p.id ASC LIMIT 1",
    [$siteId]
);
$media = Database::selectOne(
    "SELECT id, path, mime_type, file_size, width, height, alt_text FROM media
     WHERE site_id = ? AND mime_type IN ('image/jpeg','image/png','image/webp')
     ORDER BY id DESC LIMIT 1",
    [$siteId]
);
if ($siteId <= 0 || $page === null || $media === null || !is_file(PP_ROOT . '/' . ltrim((string) $media['path'], '/'))) {
    echo "SKIP faltan página Canvas o medio local\n";
    exit(0);
}

$pageId = (int) $page['id'];
$mediaId = (int) $media['id'];
$sourceText = 'En la página ' . (string) $page['title']
    . ', adapta una sección existente tomando esta imagen como referencia visual. No publiques nada.';
$bundle = [
    'status' => 'ready',
    'blocks' => [
        ['id' => 'B1', 'type' => 'paragraph', 'text' => $sourceText, 'marks' => []],
        ['id' => 'B2', 'type' => 'image', 'media_ref' => 'IMG-1', 'text' => (string) $media['alt_text'], 'marks' => []],
    ],
    'media' => [[
        'ref' => 'IMG-1', 'media_id' => $mediaId, 'status' => 'stored', 'source_kind' => 'media',
        'source' => '/' . ltrim((string) $media['path'], '/'), 'mime' => (string) $media['mime_type'],
        'bytes' => (int) $media['file_size'], 'width' => (int) $media['width'], 'height' => (int) $media['height'],
        'alt' => (string) $media['alt_text'], 'role' => 'reference',
    ]],
    'warnings' => [],
    'prompt_text' => '[B1 paragraph] ' . $sourceText . "\n[B2 image IMG-1 status=stored media_id=" . $mediaId . '] ' . (string) $media['alt_text'],
];

$beforeLogId = (int) (Database::selectOne('SELECT MAX(id) AS id FROM ai_logs')['id'] ?? 0);
$plan = SiteAssistantPlanner::plan(
    $siteId,
    'Analiza y clasifica este material de prueba. Solo propón el plan; no ejecutes cambios.',
    (string) $bundle['prompt_text'],
    $bundle
);
$latestLog = Database::selectOne(
    "SELECT id, request_data FROM ai_logs WHERE site_id = ? AND action_type = 'plan_site_changes' AND id > ? ORDER BY id DESC LIMIT 1",
    [$siteId, $beforeLogId]
);
$log = (string) ($latestLog['request_data'] ?? '');
$logData = json_decode($log, true);
$items = (array) ($plan['items'] ?? []);
$hasSourceRef = false;
$hasMediaRef = false;
foreach ($items as $item) {
    $hasSourceRef = $hasSourceRef || in_array('B1', (array) ($item['source_block_ids'] ?? []), true);
    $hasMediaRef = $hasMediaRef || in_array($mediaId, (array) ($item['media_ids'] ?? []), true);
}

$checks = [
    'vision_used' => ($plan['vision']['status'] ?? '') === 'used' && (int) ($plan['vision']['sent_images'] ?? 0) === 1,
    'source_reference_returned' => $hasSourceRef,
    'media_reference_returned' => $hasMediaRef,
    'log_has_redacted_manifest' => is_array($logData) && (int) ($logData['input']['_images']['count'] ?? 0) === 1,
    'log_has_no_base64_payload' => !str_contains($log, 'base64,') && !preg_match('/[A-Za-z0-9+\\/]{1000,}={0,2}/', $log),
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo json_encode([
    'vision' => $plan['vision'] ?? null,
    'items' => array_map(static fn (array $item): array => [
        'page_id' => $item['page_id'] ?? 0,
        'category' => $item['category'] ?? '',
        'source_block_ids' => $item['source_block_ids'] ?? [],
        'media_ids' => $item['media_ids'] ?? [],
    ], $items),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ((int) ($latestLog['id'] ?? 0) > 0) {
    Database::execute(
        "DELETE FROM ai_logs WHERE id = ? AND site_id = ? AND action_type = 'plan_site_changes'",
        [(int) $latestLog['id'], $siteId]
    );
}
exit($failed === 0 ? 0 : 1);
