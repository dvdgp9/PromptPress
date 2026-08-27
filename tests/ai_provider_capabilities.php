<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\AI\AIProviderCapabilities;

$failed = 0;
function checkAiCapabilities(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

$visionRecord = [
    'data' => [
        'id' => 'google/gemini-3.7-flash',
        'architecture' => ['input_modalities' => ['text', 'image']],
    ],
];
$textRecord = [
    'id' => 'example/text-model',
    'architecture' => ['input_modalities' => ['text']],
];

$vision = AIProviderCapabilities::forModel(
    'openrouter',
    'google/gemini-3.7-flash',
    static fn (string $model): ?array => $visionRecord
);
$textOnly = AIProviderCapabilities::forModel(
    'openrouter',
    'example/text-model',
    static fn (string $model): ?array => $textRecord
);
$unknown = AIProviderCapabilities::forModel(
    'openrouter',
    'missing/model',
    static fn (string $model): ?array => null
);
$anthropic = AIProviderCapabilities::forModel('anthropic', 'claude-anything');

checkAiCapabilities('openrouter_vision_comes_from_input_modalities',
    $vision['supports_vision'] === true && $vision['status'] === 'verified',
    json_encode($vision)
);
checkAiCapabilities('openrouter_text_model_is_verified_not_visual',
    $textOnly['supports_vision'] === false && $textOnly['status'] === 'verified',
    json_encode($textOnly)
);
checkAiCapabilities('unknown_model_fails_closed',
    $unknown['supports_vision'] === false && $unknown['status'] === 'unknown',
    json_encode($unknown)
);
checkAiCapabilities('anthropic_is_off_until_transport_exists',
    $anthropic['supports_vision'] === false && $anthropic['reason'] === 'provider_transport_unsupported',
    json_encode($anthropic)
);
checkAiCapabilities('model_and_provider_are_reported',
    $vision['provider'] === 'openrouter' && $vision['model'] === 'google/gemini-3.7-flash',
    json_encode($vision)
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
