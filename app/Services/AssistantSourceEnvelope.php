<?php

declare(strict_types=1);

namespace App\Services;

use Core\App;
use InvalidArgumentException;

/**
 * Sobre firmado y efímero que une la propuesta visible con su material fuente.
 *
 * El navegador lo transporta, pero no puede cambiar bloques, referencias ni los
 * items autorizados sin invalidar el HMAC. Nunca contiene HTML ni rutas de disco.
 */
final class AssistantSourceEnvelope
{
    public const TTL_SECONDS = 7200;
    private const VERSION = 'as1';
    private const MAX_TOKEN_BYTES = 180000;
    private const MAX_BLOCKS = 600;
    private const MAX_CHARS = 60000;
    private const MAX_MEDIA = 8;

    /** @param array<string,mixed> $bundle @param array<int,array<string,mixed>> $planItems */
    public static function issue(int $siteId, array $bundle, array $planItems, ?int $now = null): string
    {
        $now ??= time();
        $hashes = [];
        foreach ($planItems as $item) {
            if (is_array($item)) $hashes[] = self::itemFingerprint($item);
        }
        $payload = [
            'v' => 1,
            'site_id' => $siteId,
            'issued_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
            'bundle' => self::sanitizeBundle($bundle),
            'authorized_item_hashes' => array_values(array_unique($hashes)),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $compressed = gzdeflate($json, 6);
        if ($compressed === false) throw new \RuntimeException('No se pudo preparar el material fuente.');
        $body = self::base64UrlEncode($compressed);
        $signature = self::base64UrlEncode(hash_hmac('sha256', self::VERSION . '.' . $body, self::key(), true));
        $token = self::VERSION . '.' . $body . '.' . $signature;
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new \RuntimeException('El material fuente supera el tamaño permitido.');
        }
        return $token;
    }

    /** @return array{bundle:array<string,mixed>,authorized_item_hashes:array<int,string>} */
    public static function open(string $token, int $siteId, ?int $now = null): array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new InvalidArgumentException('El material fuente confirmado no es válido.');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new InvalidArgumentException('El material fuente confirmado no es válido.');
        }
        [, $body, $provided] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', self::VERSION . '.' . $body, self::key(), true));
        if (!hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('El material fuente confirmado ha cambiado. Vuelve a proponer el plan.');
        }
        $compressed = self::base64UrlDecode($body);
        $json = $compressed === null ? false : gzinflate($compressed, 250000);
        if (!is_string($json)) {
            throw new InvalidArgumentException('El material fuente confirmado no se puede leer.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('El material fuente confirmado no se puede leer.');
        }
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 1
            || (int) ($payload['site_id'] ?? 0) !== $siteId
            || (int) ($payload['issued_at'] ?? 0) > $now + 60
            || (int) ($payload['expires_at'] ?? 0) < $now) {
            throw new InvalidArgumentException('El material fuente confirmado ha caducado o pertenece a otro sitio.');
        }
        $hashes = array_values(array_filter(
            is_array($payload['authorized_item_hashes'] ?? null) ? $payload['authorized_item_hashes'] : [],
            static fn (mixed $hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1
        ));
        return [
            'bundle' => self::sanitizeBundle(is_array($payload['bundle'] ?? null) ? $payload['bundle'] : []),
            'authorized_item_hashes' => $hashes,
        ];
    }

    /** @param array<string,mixed> $item */
    public static function itemFingerprint(array $item): string
    {
        $sourceIds = self::stringIds($item['source_block_ids'] ?? [], '/^B[1-9][0-9]*$/', 600);
        $mediaIds = self::integerIds($item['media_ids'] ?? [], self::MAX_MEDIA);
        $canonical = [
            'capability_id' => (string) ($item['capability_id'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'page_id' => (int) ($item['page_id'] ?? 0),
            'section' => trim((string) ($item['section'] ?? '')),
            'instruction' => trim((string) ($item['instruction'] ?? '')),
            'source_block_ids' => $sourceIds,
            'media_ids' => $mediaIds,
        ];
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $bundle @return array<string,mixed> */
    public static function sanitizeBundle(array $bundle): array
    {
        $blocks = [];
        $seen = [];
        $chars = 0;
        $allowedTypes = ['heading', 'paragraph', 'list_item', 'quote', 'table_row', 'image'];
        foreach (is_array($bundle['blocks'] ?? null) ? $bundle['blocks'] : [] as $raw) {
            if (!is_array($raw) || count($blocks) >= self::MAX_BLOCKS) break;
            $id = (string) ($raw['id'] ?? '');
            $type = (string) ($raw['type'] ?? '');
            if (preg_match('/^B[1-9][0-9]*$/', $id) !== 1 || isset($seen[$id]) || !in_array($type, $allowedTypes, true)) continue;
            $text = (string) ($raw['text'] ?? '');
            if ($chars + mb_strlen($text) > self::MAX_CHARS) break;
            $block = ['id' => $id, 'type' => $type, 'text' => $text];
            if ($type === 'heading') $block['level'] = max(1, min(6, (int) ($raw['level'] ?? 2)));
            if ($type === 'list_item') {
                $block['depth'] = max(0, min(8, (int) ($raw['depth'] ?? 0)));
                $block['list_kind'] = ($raw['list_kind'] ?? '') === 'ordered' ? 'ordered' : 'unordered';
            }
            if ($type === 'image' && preg_match('/^IMG-[1-9][0-9]*$/', (string) ($raw['media_ref'] ?? '')) === 1) {
                $block['media_ref'] = (string) $raw['media_ref'];
            }
            $blocks[] = $block;
            $seen[$id] = true;
            $chars += mb_strlen($text);
        }

        $media = [];
        $seenRefs = [];
        foreach (is_array($bundle['media'] ?? null) ? $bundle['media'] : [] as $raw) {
            if (!is_array($raw) || count($media) >= self::MAX_MEDIA) break;
            $ref = (string) ($raw['ref'] ?? '');
            if (preg_match('/^IMG-[1-9][0-9]*$/', $ref) !== 1 || isset($seenRefs[$ref])) continue;
            $entry = [
                'ref' => $ref,
                'status' => (string) ($raw['status'] ?? 'needs_review'),
                'source_kind' => (string) ($raw['source_kind'] ?? 'unresolved'),
                'alt' => mb_substr(trim((string) ($raw['alt'] ?? '')), 0, 500),
            ];
            $mediaId = (int) ($raw['media_id'] ?? 0);
            if ($mediaId > 0) $entry['media_id'] = $mediaId;
            $media[] = $entry;
            $seenRefs[$ref] = true;
        }
        return [
            'status' => in_array(($bundle['status'] ?? ''), ['ready', 'partial'], true) ? $bundle['status'] : 'partial',
            'blocks' => $blocks,
            'media' => $media,
        ];
    }

    private static function key(): string
    {
        $appKey = (string) (App::config()['app_key'] ?? '');
        if ($appKey === '') throw new \RuntimeException('Assistant requiere app_key en config.php.');
        return hash_hmac('sha256', 'assistant-source-envelope-v1', $appKey, true);
    }

    /** @return array<int,string> */
    private static function stringIds(mixed $value, string $pattern, int $limit): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $id) {
            $id = (string) $id;
            if (preg_match($pattern, $id) === 1 && !in_array($id, $out, true)) $out[] = $id;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** @return array<int,int> */
    private static function integerIds(mixed $value, int $limit): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $out, true)) $out[] = $id;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return null;
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
