<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\AssistantSourceEnvelope;

$failed = 0;
function checkAssistantEnvelope(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$bundle = [
    'status' => 'ready',
    'blocks' => [
        ['id' => 'B1', 'type' => 'heading', 'text' => 'Présentation', 'level' => 2],
        ['id' => 'B2', 'type' => 'paragraph', 'text' => 'Texte exact — sans paraphrase.'],
        ['id' => 'B3', 'type' => 'image', 'text' => 'Portrait', 'media_ref' => 'IMG-1'],
    ],
    'media' => [[
        'ref' => 'IMG-1', 'media_id' => 302, 'status' => 'stored',
        'source_kind' => 'media', 'source' => '/storage/uploads/1/should-not-be-trusted.jpg',
        'alt' => 'Portrait',
    ]],
    'prompt_text' => 'duplicated prompt text that must not be persisted',
];
$item = [
    'status' => 'aplicar', 'category' => 'automatable_now',
    'capability_id' => 'pages.canvas.edit', 'page_id' => 12, 'section' => 'main',
    'instruction' => 'Crear la página con el material.',
    'source_block_ids' => ['B1', 'B2', 'B3'], 'media_ids' => [302],
];

$token = AssistantSourceEnvelope::issue(7, $bundle, [$item], 1_800_000_000);
$opened = AssistantSourceEnvelope::open($token, 7, 1_800_000_100);
checkAssistantEnvelope('roundtrip_preserves_literal_blocks',
    ($opened['bundle']['blocks'][1]['text'] ?? '') === 'Texte exact — sans paraphrase.'
);
checkAssistantEnvelope('bundle_drops_derived_prompt_and_paths',
    !isset($opened['bundle']['prompt_text'])
    && !isset($opened['bundle']['media'][0]['source'])
);
checkAssistantEnvelope('plan_item_is_authorized',
    in_array(AssistantSourceEnvelope::itemFingerprint($item), $opened['authorized_item_hashes'], true)
);

$parts = explode('.', $token);
$parts[1][10] = $parts[1][10] === 'A' ? 'B' : 'A';
$tamperedRejected = false;
try {
    AssistantSourceEnvelope::open(implode('.', $parts), 7, 1_800_000_100);
} catch (InvalidArgumentException) {
    $tamperedRejected = true;
}
checkAssistantEnvelope('tampered_payload_is_rejected', $tamperedRejected);

$wrongSiteRejected = false;
try {
    AssistantSourceEnvelope::open($token, 8, 1_800_000_100);
} catch (InvalidArgumentException) {
    $wrongSiteRejected = true;
}
checkAssistantEnvelope('site_scope_is_enforced', $wrongSiteRejected);

$expiredRejected = false;
try {
    AssistantSourceEnvelope::open($token, 7, 1_800_010_000);
} catch (InvalidArgumentException) {
    $expiredRejected = true;
}
checkAssistantEnvelope('expired_token_is_rejected', $expiredRejected);

$changed = $item;
$changed['source_block_ids'] = ['B1'];
checkAssistantEnvelope('changed_references_have_different_fingerprint',
    AssistantSourceEnvelope::itemFingerprint($changed) !== AssistantSourceEnvelope::itemFingerprint($item)
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
