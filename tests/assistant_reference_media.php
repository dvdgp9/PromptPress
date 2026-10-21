<?php

declare(strict_types=1);

/**
 * ASST-CONV AC2 — lo que se adjunta al Asistente es material de referencia:
 * no sale en Medios ni se ofrece al editor como foto hasta que un cambio
 * aplicado lo usa de verdad en una página.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\MediaLibraryService;
use App\Services\MediaService;
use Core\Database;

$failed = 0;
function checkRefMedia(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 800) . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
if ($site === null) {
    echo "SKIP no sites\n";
    exit(0);
}
$siteId = (int) $site['id'];
$created = [];

$insert = static function (string $name, string $source, ?string $alt) use ($siteId, &$created): int {
    Database::execute(
        'INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$siteId, $name, $name, 'image/png', 68, 'storage/uploads/' . $siteId . '/' . $name, $alt, 1, 1, $source]
    );
    $id = (int) Database::lastInsertId();
    $created[] = $id;
    return $id;
};

try {
    checkRefMedia('purpose_assistant_maps_to_reference_source', MediaService::sourceForPurpose('assistant') === MediaService::SOURCE_ASSISTANT);
    checkRefMedia('unknown_purpose_stays_upload', MediaService::sourceForPurpose('') === 'upload' && MediaService::sourceForPurpose('hack') === 'upload');

    $missingBefore = MediaLibraryService::countMissingAlt($siteId);
    $refId = $insert('ac2-ref-' . bin2hex(random_bytes(4)) . '.png', MediaService::SOURCE_ASSISTANT, null);
    $ownId = $insert('ac2-own-' . bin2hex(random_bytes(4)) . '.png', 'upload', 'Foto del local');

    $ids = array_map(static fn (array $r): int => (int) $r['id'], MediaLibraryService::images($siteId, 200));
    checkRefMedia('reference_is_not_offered_to_the_editor', !in_array($refId, $ids, true), 'ids=' . implode(',', $ids));
    checkRefMedia('own_photo_is_still_offered', in_array($ownId, $ids, true));
    checkRefMedia('reference_without_alt_does_not_count_as_missing', MediaLibraryService::countMissingAlt($siteId) === $missingBefore);
    checkRefMedia('reference_not_in_missing_alt_queue', !in_array($refId, MediaLibraryService::idsMissingAlt($siteId, 20), true));

    $refRow = Database::selectOne('SELECT path FROM media WHERE id = ?', [$refId]);
    $refPath = '/' . ltrim((string) $refRow['path'], '/');

    $untouched = MediaService::promoteAssistantMedia($siteId, [$refId], '<section><img src="/storage/uploads/' . $siteId . '/otra.png"></section>');
    $stillRef = Database::selectOne('SELECT source FROM media WHERE id = ?', [$refId]);
    checkRefMedia('reference_not_used_stays_hidden', $untouched === 0 && $stillRef['source'] === MediaService::SOURCE_ASSISTANT);

    $promoted = MediaService::promoteAssistantMedia($siteId, [$refId, $ownId], '<section style="background:url(' . $refPath . ')"></section>');
    $nowSource = Database::selectOne('SELECT source FROM media WHERE id = ?', [$refId]);
    checkRefMedia('reference_used_in_page_is_promoted', $promoted === 1 && $nowSource['source'] === 'upload', 'promoted=' . $promoted);

    $ids = array_map(static fn (array $r): int => (int) $r['id'], MediaLibraryService::images($siteId, 200));
    checkRefMedia('promoted_media_appears_in_library', in_array($refId, $ids, true));
} finally {
    foreach ($created as $id) {
        Database::execute('DELETE FROM media WHERE id = ?', [$id]);
    }
}

exit($failed > 0 ? 1 : 0);
