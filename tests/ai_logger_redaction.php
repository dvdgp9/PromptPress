<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\AI\AILogger;

$failed = 0;
function checkAiLogRedaction(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

$binary = "\x89PNG\r\n\x1a\n" . random_bytes(48);
$base64 = base64_encode($binary);
$secretUrl = 'https://private.example/image.png?token=never-log-this';
$payload = [
    'input' => [
        'request_text' => 'Analiza estas referencias',
        '_images' => [
            ['mime' => 'image/png', 'data' => $base64, 'width' => 20, 'height' => 10, 'media_id' => 17],
            ['mime' => 'image/jpeg', 'url' => $secretUrl],
        ],
    ],
    'nested' => ['preview' => 'data:image/png;base64,' . $base64],
];

$clean = AILogger::sanitizeForStorage($payload);
$json = json_encode($clean, JSON_UNESCAPED_SLASHES);
$images = $clean['input']['_images'] ?? [];

checkAiLogRedaction('keeps_non_binary_context', ($clean['input']['request_text'] ?? '') === 'Analiza estas referencias');
checkAiLogRedaction('images_become_metadata_manifest', is_array($images) && ($images['count'] ?? 0) === 2, $json ?: '');
checkAiLogRedaction('records_decoded_bytes', ($images['items'][0]['bytes'] ?? 0) === strlen($binary), $json ?: '');
checkAiLogRedaction('records_sha256_not_binary', ($images['items'][0]['sha256'] ?? '') === hash('sha256', $binary), $json ?: '');
checkAiLogRedaction('keeps_safe_dimensions_and_media_id',
    ($images['items'][0]['width'] ?? 0) === 20
    && ($images['items'][0]['height'] ?? 0) === 10
    && ($images['items'][0]['media_id'] ?? 0) === 17,
    $json ?: ''
);
checkAiLogRedaction('never_keeps_base64_or_private_url',
    is_string($json)
    && !str_contains($json, $base64)
    && !str_contains($json, $secretUrl)
    && !str_contains($json, 'data:image'),
    $json ?: ''
);
checkAiLogRedaction('nested_data_urls_are_redacted', ($clean['nested']['preview']['redacted'] ?? false) === true, $json ?: '');

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
