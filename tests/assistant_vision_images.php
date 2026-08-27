<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\AssistantVisionImages;

$failed = 0;
function checkAssistantVision(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 700) . PHP_EOL;
    }
}

if (!function_exists('imagecreatetruecolor')) {
    echo "SKIP GD unavailable\n";
    exit(0);
}

$siteId = 987654;
$dir = PP_ROOT . '/storage/uploads/' . $siteId;
$path = $dir . '/ar4-vision.png';
@mkdir($dir, 0775, true);
$image = imagecreatetruecolor(2000, 1000);
$color = imagecolorallocate($image, 30, 90, 160);
imagefill($image, 0, 0, $color);
imagepng($image, $path);
imagedestroy($image);

try {
    $bundle = [
        'media' => [
            [
                'ref' => 'IMG-1', 'media_id' => 101, 'status' => 'stored', 'source_kind' => 'media',
                'source' => '/storage/uploads/' . $siteId . '/ar4-vision.png', 'mime' => 'image/png',
                'width' => 2000, 'height' => 1000, 'bytes' => filesize($path), 'alt' => 'Captura de una ficha',
            ],
            [
                'ref' => 'IMG-2', 'status' => 'needs_review', 'source_kind' => 'remote_url',
                'source' => 'https://example.test/private.png', 'mime' => 'image/png', 'alt' => 'Remota',
            ],
            [
                'ref' => 'IMG-3', 'media_id' => 303, 'status' => 'stored', 'source_kind' => 'media',
                'source' => '/storage/uploads/44/foreign.png', 'mime' => 'image/png', 'alt' => 'Ajena',
            ],
        ],
    ];

    $prepared = AssistantVisionImages::prepare($bundle, $siteId);
    $sent = $prepared['images'] ?? [];
    $manifest = $prepared['manifest'] ?? [];
    $binary = isset($sent[0]['data']) ? base64_decode((string) $sent[0]['data'], true) : false;
    $size = is_string($binary) ? getimagesizefromstring($binary) : false;

    checkAssistantVision('only_owned_stored_media_is_sent', count($sent) === 1, json_encode($prepared));
    checkAssistantVision('manifest_keeps_exact_reference',
        ($manifest[0]['ref'] ?? '') === 'IMG-1'
        && ($manifest[0]['media_id'] ?? 0) === 101
        && ($manifest[0]['alt'] ?? '') === 'Captura de una ficha',
        json_encode($manifest)
    );
    checkAssistantVision('image_is_reencoded_and_bounded',
        is_array($size) && $size[0] === 1600 && $size[1] === 800,
        json_encode($size)
    );
    checkAssistantVision('provider_payload_contains_no_path',
        !array_key_exists('path', $sent[0] ?? []) && !str_contains(json_encode($sent) ?: '', '/storage/'),
        json_encode($sent)
    );
    checkAssistantVision('remote_and_foreign_references_are_diagnostic_only',
        in_array('IMG-2', $prepared['skipped_refs'] ?? [], true)
        && in_array('IMG-3', $prepared['skipped_refs'] ?? [], true),
        json_encode($prepared)
    );
} finally {
    @unlink($path);
    @rmdir($dir);
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
