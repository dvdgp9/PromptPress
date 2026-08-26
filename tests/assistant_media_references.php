<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantMediaReferences;
use Core\Database;

$failed = 0;
function checkAssistantMedia(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 1000) . PHP_EOL;
    }
}

$sites = Database::select('SELECT id FROM sites ORDER BY id ASC LIMIT 2');
if ($sites === []) {
    echo "SKIP no sites\n";
    exit(0);
}
$siteId = (int) $sites[0]['id'];
$otherSiteId = isset($sites[1]) ? (int) $sites[1]['id'] : null;
$createdIds = [];

try {
    Database::execute(
        'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$siteId, 'ar3-owned.png', 'AR3 owned.png', 'image/png', 68, 'storage/uploads/' . $siteId . '/ar3-owned.png', 'Owned reference', 1, 1, 'upload']
    );
    $ownedId = (int) Database::lastInsertId();
    $createdIds[] = $ownedId;

    $foreignId = 0;
    if ($otherSiteId !== null) {
        Database::execute(
            'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$otherSiteId, 'ar3-foreign.png', 'AR3 foreign.png', 'image/png', 68, 'storage/uploads/' . $otherSiteId . '/ar3-foreign.png', 'Foreign reference', 1, 1, 'upload']
        );
        $foreignId = (int) Database::lastInsertId();
        $createdIds[] = $foreignId;
    } else {
        $foreignId = $ownedId + 999999;
    }

    $bundle = [
        'status' => 'partial',
        'blocks' => [
            ['id' => 'B1', 'type' => 'paragraph', 'text' => 'Before', 'marks' => []],
            ['id' => 'B2', 'type' => 'image', 'media_ref' => 'IMG-1', 'text' => 'Owned', 'marks' => []],
            ['id' => 'B3', 'type' => 'image', 'media_ref' => 'IMG-2', 'text' => 'Foreign', 'marks' => []],
        ],
        'media' => [
            ['ref' => 'IMG-1', 'media_id' => $ownedId, 'status' => 'needs_review', 'source_kind' => 'media_candidate', 'source' => '/claimed/path.png', 'alt' => 'Owned'],
            ['ref' => 'IMG-2', 'media_id' => $foreignId, 'status' => 'needs_review', 'source_kind' => 'media_candidate', 'source' => '/foreign/path.png', 'alt' => 'Foreign'],
        ],
        'warnings' => [],
        'prompt_text' => '',
    ];

    $resolved = AssistantMediaReferences::resolve($bundle, $siteId);
    checkAssistantMedia(
        'owned_media_id_resolves_to_database_path',
        ($resolved['media'][0]['status'] ?? '') === 'stored'
            && ($resolved['media'][0]['source_kind'] ?? '') === 'media'
            && ($resolved['media'][0]['media_id'] ?? 0) === $ownedId
            && ($resolved['media'][0]['source'] ?? '') === '/storage/uploads/' . $siteId . '/ar3-owned.png'
            && ($resolved['media'][0]['alt'] ?? '') === 'Owned reference',
        json_encode($resolved['media'], JSON_UNESCAPED_SLASHES)
    );
    checkAssistantMedia(
        'foreign_or_missing_media_id_is_rejected',
        ($resolved['media'][1]['status'] ?? '') === 'needs_review'
            && ($resolved['media'][1]['source_kind'] ?? '') === 'unresolved'
            && ($resolved['media'][1]['source'] ?? 'not-empty') === ''
            && !isset($resolved['media'][1]['media_id']),
        json_encode($resolved['media'], JSON_UNESCAPED_SLASHES)
    );
    checkAssistantMedia(
        'invalid_reference_adds_diagnostic_warning',
        in_array('invalid_media_reference', array_column($resolved['warnings'], 'code'), true),
        json_encode($resolved['warnings'])
    );
    checkAssistantMedia(
        'prompt_text_exposes_only_verified_media',
        str_contains($resolved['prompt_text'], 'IMG-1 status=stored media_id=' . $ownedId)
            && !str_contains($resolved['prompt_text'], 'media_id=' . $foreignId),
        (string) $resolved['prompt_text']
    );
} finally {
    foreach ($createdIds as $id) {
        Database::execute('DELETE FROM media WHERE id = ?', [$id]);
    }
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
