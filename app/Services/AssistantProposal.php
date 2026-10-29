<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ASST-V2 F3/F4 — Propuesta firmada de un cambio del asistente.
 *
 * La vista previa prepara el cambio con la IA y lo devuelve firmado; «Aplicar»
 * lo trae de vuelta y se guarda exactamente eso, sin otra llamada a la IA. La
 * firma ata la propuesta al tipo de cambio, al sitio, al elemento y a la
 * instrucción, y caduca a las dos horas.
 */
final class AssistantProposal
{
    public const TTL = 7200;
    private const VERSION = 'ap1';
    private const MAX_BYTES = 150000;

    /** @param array<string,mixed> $content */
    public static function seal(string $kind, int $siteId, int $targetId, string $instruction, array $content, string $baseHash, ?int $now = null): string
    {
        $now ??= time();
        $payload = [
            'kind'        => $kind,
            'site_id'     => $siteId,
            'target_id'   => $targetId,
            'instruction' => hash('sha256', trim($instruction)),
            'base_hash'   => $baseHash,
            'expires_at'  => $now + self::TTL,
            'content'     => $content,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = self::b64(gzdeflate($json, 6) ?: '');
        $token = self::VERSION . '.' . $body . '.'
            . self::b64(hash_hmac('sha256', self::VERSION . '.' . $body, self::key(), true));
        if (strlen($token) > self::MAX_BYTES) {
            throw new \RuntimeException('La propuesta es demasiado grande.');
        }
        return $token;
    }

    /**
     * Null si no es válida, ha caducado o no corresponde a este tipo, sitio,
     * elemento e instrucción.
     *
     * @return array{content:array<string,mixed>, base_hash:string}|null
     */
    public static function open(string $token, string $kind, int $siteId, int $targetId, string $instruction, ?int $now = null): ?array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > self::MAX_BYTES) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) return null;
        $expected = self::b64(hash_hmac('sha256', self::VERSION . '.' . $parts[1], self::key(), true));
        if (!hash_equals($expected, $parts[2])) return null;
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $json = $raw === false ? false : @gzinflate($raw, 400000);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)
            || ($payload['kind'] ?? '') !== $kind
            || (int) ($payload['site_id'] ?? 0) !== $siteId
            || (int) ($payload['target_id'] ?? 0) !== $targetId
            || !hash_equals((string) ($payload['instruction'] ?? ''), hash('sha256', trim($instruction)))
            || (int) ($payload['expires_at'] ?? 0) < $now
            || !is_array($payload['content'] ?? null)) {
            return null;
        }
        return ['content' => $payload['content'], 'base_hash' => (string) ($payload['base_hash'] ?? '')];
    }

    /** Huella estable de un estado guardado, para saber si cambió entre medias. @param array<string,mixed> $state */
    public static function hash(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private static function key(): string
    {
        $appKey = (string) (\Core\App::config()['app_key'] ?? '');
        if ($appKey === '') throw new \RuntimeException('Assistant requiere app_key en config.php.');
        return hash_hmac('sha256', 'assistant-proposal-v1', $appKey, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
