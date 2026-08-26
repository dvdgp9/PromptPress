<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AssistantContentNormalizer;

$failed = 0;
function checkAssistantContent(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 1000) . PHP_EOL;
    }
}

function richFixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/assistant-rich/' . $name . '.html');
}

function blockTexts(array $result): array
{
    return array_values(array_filter(array_map(
        static fn (array $b): string => ($b['type'] ?? '') === 'image' ? '' : (string) ($b['text'] ?? ''),
        $result['blocks'] ?? []
    ), static fn (string $text): bool => $text !== ''));
}

$gmail = AssistantContentNormalizer::normalize(richFixture('gmail'));
$gmailTypes = array_column($gmail['blocks'], 'type');
$gmailJson = json_encode($gmail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
checkAssistantContent(
    'gmail_preserves_paragraphs_and_nested_lists',
    $gmailTypes === ['paragraph', 'paragraph', 'list_item', 'list_item', 'list_item', 'paragraph', 'image'],
    json_encode([$gmailTypes, $gmail['blocks']], JSON_UNESCAPED_UNICODE)
);
checkAssistantContent(
    'gmail_list_depth_is_semantic',
    ($gmail['blocks'][2]['depth'] ?? -1) === 0
        && ($gmail['blocks'][3]['depth'] ?? -1) === 1
        && ($gmail['blocks'][4]['depth'] ?? -1) === 1,
    json_encode($gmail['blocks'], JSON_UNESCAPED_UNICODE)
);
checkAssistantContent(
    'gmail_keeps_strong_and_safe_link_marks',
    in_array('strong', array_column($gmail['blocks'][0]['marks'] ?? [], 'type'), true)
        && in_array('link', array_column($gmail['blocks'][3]['marks'] ?? [], 'type'), true)
        && ($gmail['blocks'][3]['marks'][1]['href'] ?? '') === 'https://example.com/reference?x=1',
    json_encode($gmail['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
checkAssistantContent(
    'gmail_remote_image_is_reference_not_download',
    count($gmail['media']) === 1
        && ($gmail['media'][0]['ref'] ?? '') === 'IMG-1'
        && ($gmail['media'][0]['source_kind'] ?? '') === 'remote_url'
        && ($gmail['media'][0]['status'] ?? '') === 'needs_review'
        && ($gmail['blocks'][6]['media_ref'] ?? '') === 'IMG-1',
    json_encode($gmail['media'], JSON_UNESCAPED_SLASHES)
);
checkAssistantContent(
    'active_content_and_origin_styling_do_not_survive',
    !str_contains(strtolower((string) $gmailJson), '<script')
        && !str_contains(strtolower((string) $gmailJson), 'onclick')
        && !str_contains(strtolower((string) $gmailJson), 'onerror')
        && !str_contains(strtolower((string) $gmailJson), 'font-family')
        && !str_contains(strtolower((string) $gmailJson), '#f09'),
    (string) $gmailJson
);

$word = AssistantContentNormalizer::normalize(richFixture('word'));
$wordTypes = array_column($word['blocks'], 'type');
checkAssistantContent(
    'word_detects_heading_mso_bullets_and_inline_image_order',
    $wordTypes === ['heading', 'paragraph', 'list_item', 'list_item', 'paragraph', 'image', 'paragraph']
        && ($word['blocks'][0]['level'] ?? 0) === 2
        && ($word['blocks'][2]['text'] ?? '') === 'Ebooks: 30€?'
        && ($word['blocks'][4]['text'] ?? '') === 'Before image'
        && ($word['blocks'][6]['text'] ?? '') === 'after image',
    json_encode($word['blocks'], JSON_UNESCAPED_UNICODE)
);
checkAssistantContent(
    'word_data_image_is_captured_for_later_upload',
    ($word['media'][0]['source_kind'] ?? '') === 'data'
        && ($word['media'][0]['status'] ?? '') === 'captured'
        && ($word['media'][0]['mime'] ?? '') === 'image/png'
        && ($word['media'][0]['bytes'] ?? 0) > 0,
    json_encode($word['media'])
);
checkAssistantContent(
    'inline_mark_offsets_are_valid_unicode_offsets',
    array_reduce($word['blocks'], static function (bool $valid, array $block): bool {
        $length = mb_strlen((string) ($block['text'] ?? ''));
        foreach (($block['marks'] ?? []) as $mark) {
            $valid = $valid && $mark['start'] >= 0 && $mark['end'] <= $length && $mark['start'] < $mark['end'];
        }
        return $valid;
    }, true),
    json_encode($word['blocks'], JSON_UNESCAPED_UNICODE)
);

$docs = AssistantContentNormalizer::normalize(richFixture('google-docs'));
checkAssistantContent(
    'docs_preserves_heading_order_and_table_rows',
    array_column($docs['blocks'], 'type') === ['heading', 'paragraph', 'list_item', 'list_item', 'table_row', 'table_row', 'paragraph', 'image']
        && ($docs['blocks'][4]['cells'] ?? []) === ['Service', 'Duration']
        && ($docs['blocks'][5]['cells'] ?? []) === ['Consultation', '90 min'],
    json_encode($docs['blocks'], JSON_UNESCAPED_UNICODE)
);
checkAssistantContent(
    'unsafe_link_loses_href_but_keeps_text',
    in_array('Unsafe destination', blockTexts($docs), true)
        && ($docs['blocks'][6]['marks'] ?? []) === []
        && !str_contains(json_encode($docs), 'javascript:'),
    json_encode($docs['blocks'])
);
checkAssistantContent(
    'blob_image_is_explicitly_unresolved',
    ($docs['media'][0]['source_kind'] ?? '') === 'unresolved'
        && ($docs['media'][0]['status'] ?? '') === 'needs_review'
        && in_array('unresolved_image', array_column($docs['warnings'], 'code'), true),
    json_encode([$docs['media'], $docs['warnings']])
);

$inertImageSource = AssistantContentNormalizer::normalize(
    '<p>Before</p><img data-ppa-source="https://cdn.example.test/reference.png" alt="Reference"><p>After</p>'
);
checkAssistantContent(
    'composer_inert_image_source_preserves_order_without_src',
    array_column($inertImageSource['blocks'], 'type') === ['paragraph', 'image', 'paragraph']
        && ($inertImageSource['media'][0]['source_kind'] ?? '') === 'remote_url'
        && ($inertImageSource['media'][0]['source'] ?? '') === 'https://cdn.example.test/reference.png',
    json_encode($inertImageSource, JSON_UNESCAPED_SLASHES)
);

$plain = AssistantContentNormalizer::normalize('', "Title\n\n• First item\n2. Second item\n\nFinal paragraph");
checkAssistantContent(
    'plain_text_fallback_recognizes_blocks_without_html',
    array_column($plain['blocks'], 'type') === ['paragraph', 'list_item', 'list_item', 'paragraph']
        && ($plain['blocks'][1]['list_kind'] ?? '') === 'unordered'
        && ($plain['blocks'][2]['list_kind'] ?? '') === 'ordered',
    json_encode($plain['blocks'])
);

$malicious = AssistantContentNormalizer::normalize(
    '<form><input value="secret"><button>Send</button></form>'
    . '<svg onload="x()"><script>x()</script></svg>'
    . '<iframe src="https://evil.test"></iframe>'
    . '<p><a href="data:text/html;base64,WA==">Visible safe text</a></p>'
    . '<img src="file:///private/photo.png" alt="Private image">'
);
$maliciousJson = strtolower((string) json_encode($malicious));
checkAssistantContent(
    'malicious_html_becomes_inert_data_only',
    blockTexts($malicious) === ['Visible safe text']
        && !str_contains($maliciousJson, '<form')
        && !str_contains($maliciousJson, '<svg')
        && !str_contains($maliciousJson, '<iframe')
        && !str_contains($maliciousJson, 'data:text/html')
        && ($malicious['media'][0]['source_kind'] ?? '') === 'unresolved',
    $maliciousJson
);

$limited = AssistantContentNormalizer::normalize(
    '<p>First complete block</p><p>Second block is too long</p><img src="https://a.test/1.png"><img src="https://a.test/2.png">',
    '',
    ['max_chars' => 21, 'max_images' => 1]
);
checkAssistantContent(
    'limits_drop_whole_blocks_and_extra_images',
    blockTexts($limited) === ['First complete block']
        && count($limited['media']) === 1
        && $limited['truncated'] === true
        && in_array('text_limit', array_column($limited['warnings'], 'code'), true)
        && in_array('image_limit', array_column($limited['warnings'], 'code'), true),
    json_encode($limited, JSON_UNESCAPED_SLASHES)
);
checkAssistantContent(
    'ids_and_prompt_text_are_stable',
    array_column($gmail['blocks'], 'id') === ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7']
        && str_contains($gmail['prompt_text'], '[B1 paragraph]')
        && str_contains($gmail['prompt_text'], '[B7 image IMG-1 status=needs_review]'),
    $gmail['prompt_text']
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
