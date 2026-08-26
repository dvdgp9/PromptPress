<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Convierte HTML de portapapeles en datos semánticos inertes.
 *
 * No devuelve HTML. El navegador podrá hacer una preview, pero este resultado
 * server-side es la única representación autoritativa que llegará al planner.
 */
final class AssistantContentNormalizer
{
    public const DEFAULT_MAX_CHARS = 60000;
    public const DEFAULT_MAX_IMAGES = 8;
    public const DEFAULT_MAX_IMAGE_BYTES = 10485760;

    /** @var array<int,array<string,mixed>> */
    private array $blocks = [];
    /** @var array<int,array<string,mixed>> */
    private array $media = [];
    /** @var array<int,array<string,mixed>> */
    private array $warnings = [];
    private int $chars = 0;
    private int $maxChars;
    private int $maxImages;
    private int $maxImageBytes;
    private bool $truncated = false;
    private bool $textClosed = false;

    /**
     * @param array{max_chars?:int,max_images?:int,max_image_bytes?:int} $limits
     * @return array<string,mixed>
     */
    public static function normalize(string $html, string $plainText = '', array $limits = []): array
    {
        $run = new self($limits);
        $html = trim($html);
        if ($html !== '') {
            $run->fromHtml($html);
        }
        if ($run->blocks === [] && trim($plainText) !== '') {
            if ($html !== '') {
                $run->warn('html_fallback_plain');
            }
            $run->fromPlainText($plainText);
        }
        return $run->result();
    }

    /** @param array{max_chars?:int,max_images?:int,max_image_bytes?:int} $limits */
    private function __construct(array $limits)
    {
        $this->maxChars = self::boundedLimit($limits['max_chars'] ?? self::DEFAULT_MAX_CHARS, 1, self::DEFAULT_MAX_CHARS);
        $this->maxImages = self::boundedLimit($limits['max_images'] ?? self::DEFAULT_MAX_IMAGES, 0, self::DEFAULT_MAX_IMAGES);
        $this->maxImageBytes = self::boundedLimit(
            $limits['max_image_bytes'] ?? self::DEFAULT_MAX_IMAGE_BYTES,
            1,
            self::DEFAULT_MAX_IMAGE_BYTES
        );
    }

    private static function boundedLimit(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function fromHtml(string $html): void
    {
        if (!class_exists(\DOMDocument::class)) {
            $this->warn('dom_unavailable');
            return;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $this->warn('invalid_html');
            return;
        }

        $this->removeActiveContent($doc);
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement) {
            $this->walkContainer($body);
        }
    }

    private function removeActiveContent(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query(
            '//script|//style|//noscript|//iframe|//object|//embed|//form|//svg|//math|//template|//link|//meta'
        );
        if ($nodes === false) {
            return;
        }
        $remove = [];
        foreach ($nodes as $node) {
            $remove[] = $node;
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** Walk a structural container without duplicating nested block text. */
    private function walkContainer(\DOMNode $container, int $listDepth = 0): void
    {
        $pending = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                continue;
            }
            if ($child instanceof \DOMElement && self::isBlockTag(strtolower($child->tagName))) {
                $this->emitTokens($pending, 'paragraph');
                $pending = [];
                $this->processBlock($child, $listDepth);
                continue;
            }
            $this->collectInlineTokens($child, [], $pending);
        }
        $this->emitTokens($pending, 'paragraph');
    }

    private static function isBlockTag(string $tag): bool
    {
        return in_array($tag, [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'section',
            'article', 'main', 'header', 'footer', 'ul', 'ol', 'li',
            'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'pre', 'hr',
        ], true);
    }

    private function processBlock(\DOMElement $element, int $listDepth): void
    {
        $tag = strtolower($element->tagName);
        if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
            $tokens = [];
            $this->collectInlineChildren($element, $tokens);
            $this->emitTokens($tokens, 'heading', ['level' => (int) $m[1]]);
            return;
        }
        if (in_array($tag, ['p', 'pre'], true)) {
            $tokens = [];
            $this->collectInlineChildren($element, $tokens);
            $this->emitTokens($tokens, $tag === 'pre' ? 'quote' : 'paragraph');
            return;
        }
        if ($tag === 'blockquote') {
            $tokens = [];
            $this->collectInlineChildren($element, $tokens);
            $this->emitTokens($tokens, 'quote');
            return;
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $kind = $tag === 'ol' ? 'ordered' : 'unordered';
            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                    $this->processListItem($child, $listDepth, $kind);
                }
            }
            return;
        }
        if ($tag === 'li') {
            $this->processListItem($element, $listDepth, 'unordered');
            return;
        }
        if ($tag === 'table' || in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $childTag = strtolower($child->tagName);
                if ($childTag === 'tr') {
                    $this->processTableRow($child);
                } elseif (in_array($childTag, ['thead', 'tbody', 'tfoot'], true)) {
                    $this->processBlock($child, $listDepth);
                }
            }
            return;
        }
        if ($tag === 'tr') {
            $this->processTableRow($element);
            return;
        }
        if ($tag === 'hr') {
            return;
        }
        $this->walkContainer($element, $listDepth);
    }

    private function processListItem(\DOMElement $element, int $depth, string $kind): void
    {
        $tokens = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                continue;
            }
            $this->collectInlineTokens($child, [], $tokens);
        }
        $this->emitTokens($tokens, 'list_item', ['depth' => $depth, 'list_kind' => $kind]);

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                $this->processBlock($child, $depth + 1);
            }
        }
    }

    private function processTableRow(\DOMElement $row): void
    {
        $cells = [];
        foreach ($row->childNodes as $child) {
            if (!$child instanceof \DOMElement || !in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                continue;
            }
            $text = self::cleanText($child->textContent);
            if ($text !== '') {
                $cells[] = $text;
            }
        }
        if ($cells !== []) {
            $this->addTextBlock('table_row', implode(' | ', $cells), [], ['cells' => $cells]);
        }
    }

    /** @param array<int,array<string,mixed>> $tokens */
    private function collectInlineChildren(\DOMElement $element, array &$tokens): void
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && self::isBlockTag(strtolower($child->tagName))) {
                continue;
            }
            $this->collectInlineTokens($child, [], $tokens);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $activeMarks
     * @param array<int,array<string,mixed>> $tokens
     */
    private function collectInlineTokens(\DOMNode $node, array $activeMarks, array &$tokens): void
    {
        if ($node instanceof \DOMText) {
            $tokens[] = ['kind' => 'text', 'text' => $node->nodeValue ?? '', 'marks' => $activeMarks];
            return;
        }
        if (!$node instanceof \DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'img') {
            $tokens[] = ['kind' => 'image', 'element' => $node];
            return;
        }
        if ($tag === 'br') {
            $tokens[] = ['kind' => 'text', 'text' => ' ', 'marks' => $activeMarks];
            return;
        }
        if (self::isBlockTag($tag)) {
            return;
        }

        if ($tag === 'strong' || $tag === 'b') {
            $activeMarks[] = ['type' => 'strong'];
        } elseif ($tag === 'em' || $tag === 'i') {
            $activeMarks[] = ['type' => 'em'];
        } elseif ($tag === 'a') {
            $href = self::safeHttpUrl($node->getAttribute('href'));
            if ($href !== '') {
                $activeMarks[] = ['type' => 'link', 'href' => $href];
            }
        }

        foreach ($node->childNodes as $child) {
            $this->collectInlineTokens($child, $activeMarks, $tokens);
        }
    }

    /**
     * Splits a semantic block around inline images so source order remains exact.
     *
     * @param array<int,array<string,mixed>> $tokens
     * @param array<string,mixed> $meta
     */
    private function emitTokens(array $tokens, string $type, array $meta = []): void
    {
        $text = '';
        $marks = [];
        $flush = function () use (&$text, &$marks, $type, $meta): void {
            $final = rtrim($text);
            if ($final !== '') {
                $max = mb_strlen($final);
                $marks = array_values(array_filter(array_map(static function (array $mark) use ($max): array {
                    $mark['start'] = max(0, min($max, (int) $mark['start']));
                    $mark['end'] = max(0, min($max, (int) $mark['end']));
                    return $mark;
                }, $marks), static fn (array $mark): bool => $mark['end'] > $mark['start']));
                [$finalType, $finalText, $finalMarks, $finalMeta] = $this->detectPastedList($type, $final, $marks, $meta);
                $this->addTextBlock($finalType, $finalText, self::mergeMarks($finalMarks), $finalMeta);
            }
            $text = '';
            $marks = [];
        };

        foreach ($tokens as $token) {
            if (($token['kind'] ?? '') === 'image' && ($token['element'] ?? null) instanceof \DOMElement) {
                $flush();
                $this->addImage($token['element']);
                continue;
            }
            if (($token['kind'] ?? '') !== 'text') {
                continue;
            }
            $this->appendText($text, $marks, (string) ($token['text'] ?? ''), (array) ($token['marks'] ?? []));
        }
        $flush();
    }

    /** @param array<int,array<string,mixed>> $marks @param array<int,array<string,mixed>> $activeMarks */
    private function appendText(string &$text, array &$marks, string $raw, array $activeMarks): void
    {
        $clean = preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        if ($text === '') {
            $clean = ltrim($clean);
        } elseif (str_ends_with($text, ' ') && str_starts_with($clean, ' ')) {
            $clean = ltrim($clean);
        }
        if ($clean === '') {
            return;
        }
        $start = mb_strlen($text);
        $text .= $clean;
        $end = mb_strlen($text);
        foreach ($activeMarks as $mark) {
            $out = ['type' => (string) ($mark['type'] ?? ''), 'start' => $start, 'end' => $end];
            if ($out['type'] === 'link') {
                $out['href'] = (string) ($mark['href'] ?? '');
            }
            if ($out['type'] !== '') {
                $marks[] = $out;
            }
        }
    }

    /**
     * Word often serializes bullets as styled paragraphs instead of `<li>`.
     *
     * @param array<int,array<string,mixed>> $marks
     * @param array<string,mixed> $meta
     * @return array{0:string,1:string,2:array<int,array<string,mixed>>,3:array<string,mixed>}
     */
    private function detectPastedList(string $type, string $text, array $marks, array $meta): array
    {
        if ($type !== 'paragraph') {
            return [$type, $text, $marks, $meta];
        }
        $kind = null;
        $prefix = '';
        if (preg_match('/^[•·▪◦]\s*/u', $text, $m) === 1) {
            $kind = 'unordered';
            $prefix = $m[0];
        } elseif (preg_match('/^\d+[.)]\s*/u', $text, $m) === 1) {
            $kind = 'ordered';
            $prefix = $m[0];
        }
        if ($kind === null) {
            return [$type, $text, $marks, $meta];
        }
        $shift = mb_strlen($prefix);
        $text = ltrim(mb_substr($text, $shift));
        foreach ($marks as &$mark) {
            $mark['start'] = max(0, (int) $mark['start'] - $shift);
            $mark['end'] = max(0, (int) $mark['end'] - $shift);
        }
        unset($mark);
        return ['list_item', $text, $marks, ['depth' => 0, 'list_kind' => $kind]];
    }

    /** @param array<int,array<string,mixed>> $marks @param array<string,mixed> $meta */
    private function addTextBlock(string $type, string $text, array $marks, array $meta = []): void
    {
        $text = self::cleanText($text);
        if ($text === '' || $this->textClosed) {
            return;
        }
        $length = mb_strlen($text);
        if ($this->chars + $length > $this->maxChars) {
            $this->truncated = true;
            $this->textClosed = true;
            $this->warn('text_limit');
            return;
        }
        $this->blocks[] = array_merge([
            'id' => 'B' . (count($this->blocks) + 1),
            'type' => $type,
            'text' => $text,
            'marks' => $marks,
        ], $meta);
        $this->chars += $length;
    }

    private function addImage(\DOMElement $image): void
    {
        if (count($this->media) >= $this->maxImages) {
            $this->truncated = true;
            $this->warn('image_limit');
            return;
        }

        $ref = 'IMG-' . (count($this->media) + 1);
        $rawSource = $image->getAttribute('src');
        if ($rawSource === '') {
            // El composer usa un atributo inerte para que serializar una URL
            // remota no provoque una descarga accidental en el navegador.
            $rawSource = $image->getAttribute('data-ppa-source');
        }
        $src = trim(html_entity_decode($rawSource, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $alt = self::cleanText($image->getAttribute('alt'));
        $media = [
            'ref' => $ref,
            'status' => 'needs_review',
            'role' => 'unknown',
            'source_kind' => 'unresolved',
            'source' => $src,
            'mime' => null,
            'bytes' => null,
            'alt' => $alt,
        ];

        $candidateMediaId = (int) $image->getAttribute('data-ppa-media-id');
        if ($candidateMediaId > 0) {
            $media['media_id'] = $candidateMediaId;
            $media['source_kind'] = 'media_candidate';
            $this->media[] = $media;
            $this->blocks[] = [
                'id' => 'B' . (count($this->blocks) + 1),
                'type' => 'image',
                'media_ref' => $ref,
                'text' => $alt,
                'marks' => [],
            ];
            return;
        }

        if (preg_match('#^data:image/(png|jpe?g|webp|gif);base64,([a-z0-9+/=\s]+)$#i', $src, $m) === 1) {
            $payload = preg_replace('/\s+/', '', $m[2]) ?? '';
            $decoded = base64_decode($payload, true);
            if ($decoded === false) {
                $media['status'] = 'rejected';
                $media['source_kind'] = 'invalid';
                $media['source'] = '';
                $this->warn('invalid_data_image', $ref);
            } elseif (strlen($decoded) > $this->maxImageBytes) {
                $media['status'] = 'rejected';
                $media['source_kind'] = 'invalid';
                $media['source'] = '';
                $media['bytes'] = strlen($decoded);
                $this->warn('image_too_large', $ref);
            } else {
                $subtype = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
                $media['status'] = 'captured';
                $media['source_kind'] = 'data';
                $media['mime'] = 'image/' . $subtype;
                $media['bytes'] = strlen($decoded);
            }
        } elseif (self::safeHttpUrl($src) !== '') {
            $media['source_kind'] = 'remote_url';
            $media['source'] = self::safeHttpUrl($src);
            $this->warn('remote_image', $ref);
        } else {
            $media['source_kind'] = 'unresolved';
            // Never retain an executable data/javascript URL in the bundle.
            if (preg_match('#^(?:javascript:|vbscript:|data:)#i', $src) === 1) {
                $media['source'] = '';
            }
            $this->warn('unresolved_image', $ref);
        }

        $this->media[] = $media;
        $this->blocks[] = [
            'id' => 'B' . (count($this->blocks) + 1),
            'type' => 'image',
            'media_ref' => $ref,
            'text' => $alt,
            'marks' => [],
        ];
    }

    private function fromPlainText(string $plainText): void
    {
        $plainText = str_replace(["\r\n", "\r"], "\n", $plainText);
        $paragraph = [];
        $flush = function () use (&$paragraph): void {
            if ($paragraph !== []) {
                $this->addTextBlock('paragraph', implode(' ', $paragraph), []);
                $paragraph = [];
            }
        };
        foreach (explode("\n", $plainText) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            if (preg_match('/^[•·▪◦*\-]\s*(.+)$/u', $line, $m) === 1) {
                $flush();
                $this->addTextBlock('list_item', $m[1], [], ['depth' => 0, 'list_kind' => 'unordered']);
                continue;
            }
            if (preg_match('/^\d+[.)]\s*(.+)$/u', $line, $m) === 1) {
                $flush();
                $this->addTextBlock('list_item', $m[1], [], ['depth' => 0, 'list_kind' => 'ordered']);
                continue;
            }
            $paragraph[] = $line;
        }
        $flush();
    }

    /** @param array<int,array<string,mixed>> $marks @return array<int,array<string,mixed>> */
    private static function mergeMarks(array $marks): array
    {
        usort($marks, static fn (array $a, array $b): int => [$a['type'], $a['href'] ?? '', $a['start'], $a['end']]
            <=> [$b['type'], $b['href'] ?? '', $b['start'], $b['end']]);
        $out = [];
        foreach ($marks as $mark) {
            $last = count($out) - 1;
            if (
                $last >= 0
                && $out[$last]['type'] === $mark['type']
                && ($out[$last]['href'] ?? '') === ($mark['href'] ?? '')
                && $out[$last]['end'] === $mark['start']
            ) {
                $out[$last]['end'] = $mark['end'];
            } else {
                $out[] = $mark;
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['start'], $a['end'], $a['type']] <=> [$b['start'], $b['end'], $b['type']]);
        return $out;
    }

    private static function safeHttpUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true) ? $url : '';
    }

    private static function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    private function warn(string $code, ?string $mediaRef = null): void
    {
        foreach ($this->warnings as $warning) {
            if (($warning['code'] ?? '') === $code && ($warning['media_ref'] ?? null) === $mediaRef) {
                return;
            }
        }
        $warning = ['code' => $code];
        if ($mediaRef !== null) {
            $warning['media_ref'] = $mediaRef;
        }
        $this->warnings[] = $warning;
    }

    /** @return array<string,mixed> */
    private function result(): array
    {
        $status = $this->blocks === []
            ? 'rejected'
            : ($this->warnings === [] ? 'ready' : 'partial');
        return [
            'status' => $status,
            'blocks' => $this->blocks,
            'media' => $this->media,
            'warnings' => $this->warnings,
            'chars' => $this->chars,
            'truncated' => $this->truncated,
            'prompt_text' => $this->renderPromptText(),
        ];
    }

    private function renderPromptText(): string
    {
        $lines = [];
        foreach ($this->blocks as $block) {
            $meta = $block['type'];
            if ($block['type'] === 'heading') {
                $meta .= ' level=' . $block['level'];
            } elseif ($block['type'] === 'list_item') {
                $meta .= ' depth=' . $block['depth'] . ' list=' . $block['list_kind'];
            } elseif ($block['type'] === 'image') {
                $ref = (string) $block['media_ref'];
                $media = null;
                foreach ($this->media as $candidate) {
                    if ($candidate['ref'] === $ref) {
                        $media = $candidate;
                        break;
                    }
                }
                $meta .= ' ' . $ref . ' status=' . ($media['status'] ?? 'rejected');
            }
            $text = (string) ($block['text'] ?? '');
            $lines[] = '[' . $block['id'] . ' ' . $meta . ']' . ($text !== '' ? ' ' . $text : '');
        }
        return implode("\n", $lines);
    }
}
