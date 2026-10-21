<?php

declare(strict_types=1);

/**
 * ASST-CONV AC6 — quien aplica un cambio ve la referencia visual de su punto:
 * solo las imágenes con rol `reference` que el plan asignó a ese item, y con
 * una instrucción que impide pegarlas en la web o copiar su paleta.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\Canvas\CanvasChatService;
use App\Services\SiteAssistantJobs;
use Core\Database;

$failed = 0;
function checkRefExec(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$site = Database::selectOne("SELECT site_id FROM pages WHERE render_mode = 'canvas' ORDER BY id LIMIT 1");
if (!$site) {
    echo "SKIP no canvas pages\n";
    exit(0);
}
$siteId = (int) $site['site_id'];
$dir = PP_ROOT . '/storage/uploads/' . $siteId;
if (!is_dir($dir)) mkdir($dir, 0775, true);

$created = [];
$files = [];
$makeMedia = static function (string $color) use ($siteId, $dir, &$created, &$files): int {
    $name = 'ac6-' . bin2hex(random_bytes(5)) . '.png';
    $img = imagecreatetruecolor(400, 300);
    imagefill($img, 0, 0, (int) hexdec($color));
    imagepng($img, $dir . '/' . $name);
    imagedestroy($img);
    $files[] = $dir . '/' . $name;
    Database::execute(
        'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$siteId, $name, $name, 'image/png', filesize($dir . '/' . $name), 'storage/uploads/' . $siteId . '/' . $name, 'Captura', 400, 300, 'assistant_ref']
    );
    $id = (int) Database::lastInsertId();
    $created[] = $id;
    return $id;
};

try {
    $refA = $makeMedia('1f2937');
    $refB = $makeMedia('d6c7b0');
    $asset = $makeMedia('7c2d12');
    $bundle = [
        'status' => 'ready',
        'blocks' => [['id' => 'B1', 'type' => 'paragraph', 'text' => 'Como esta web']],
        'media' => [
            ['ref' => 'IMG-1', 'media_id' => $refA, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'A', 'role' => 'reference'],
            ['ref' => 'IMG-2', 'media_id' => $refB, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'B', 'role' => 'reference'],
            ['ref' => 'IMG-3', 'media_id' => $asset, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'Foto', 'role' => 'asset'],
        ],
    ];
    $job = ['source_bundle_json' => json_encode($bundle)];

    $images = SiteAssistantJobs::referenceImagesFor($job, ['media_ids_json' => json_encode([$refB, $asset])], $siteId);
    checkRefExec('only_reference_media_of_the_item_are_sent', count($images) === 1 && (int) $images[0]['media_id'] === $refB, json_encode(array_map(static fn ($i) => $i['media_id'] ?? null, $images)));
    checkRefExec('reference_is_prepared_for_vision', isset($images[0]['mime'], $images[0]['data']) && base64_decode((string) $images[0]['data'], true) !== false);

    $none = SiteAssistantJobs::referenceImagesFor($job, ['media_ids_json' => json_encode([$asset])], $siteId);
    checkRefExec('asset_only_item_sends_no_reference', $none === []);

    $empty = SiteAssistantJobs::referenceImagesFor(['source_bundle_json' => ''], ['media_ids_json' => json_encode([$refA])], $siteId);
    checkRefExec('job_without_material_sends_nothing', $empty === []);

    $many = $bundle;
    for ($i = 0; $i < 3; $i++) {
        $id = $makeMedia('22c55e');
        $many['media'][] = ['ref' => 'IMG-' . (4 + $i), 'media_id' => $id, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'x', 'role' => 'reference'];
    }
    $allIds = array_map(static fn ($m) => (int) $m['media_id'], $many['media']);
    $capped = SiteAssistantJobs::referenceImagesFor(['source_bundle_json' => json_encode($many)], ['media_ids_json' => json_encode($allIds)], $siteId);
    checkRefExec('at_most_three_references_per_item', count($capped) === 3, (string) count($capped));

    $directive = CanvasChatService::referenceDirective(2);
    checkRefExec('directive_forbids_inserting_or_copying', str_contains($directive, 'NO insertes') && str_contains($directive, 'NO copies'), $directive);
    checkRefExec('directive_keeps_brand_tokens', str_contains($directive, 'var(--pp-'), $directive);
    checkRefExec('directive_counts_images', str_contains($directive, '2 imágenes'), $directive);
} finally {
    foreach ($created as $id) Database::execute('DELETE FROM media WHERE id = ?', [$id]);
    foreach ($files as $file) @unlink($file);
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
