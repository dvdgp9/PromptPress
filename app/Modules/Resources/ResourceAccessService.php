<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\ModuleRegistry;
use App\Services\LanguageService;
use Core\App;
use Core\Database;

/** Contextos y enlaces firmados para la entrega condicionada (R5). */
final class ResourceAccessService
{
    public const FORM_CONTEXT_TTL = 21600; // 6 horas, igual que el formulario.
    public const DOWNLOAD_TTL = 86400;     // 24 horas.

    public static function issueFormContext(
        int $siteId,
        array $resource,
        int $ttl = self::FORM_CONTEXT_TTL,
        ?int $now = null
    ): string {
        return self::encode([
            'v' => 1,
            'k' => 'form',
            's' => $siteId,
            'r' => (int) ($resource['id'] ?? 0),
            'f' => (int) ($resource['form_id'] ?? 0),
            'e' => ($now ?? time()) + max(1, $ttl),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function validateFormContext(
        string $token,
        int $siteId,
        int $formId,
        ?int $now = null
    ): ?array {
        $p = self::decode($token);
        if ($p === null || ($p['k'] ?? '') !== 'form' || (int) ($p['v'] ?? 0) !== 1
            || (int) ($p['s'] ?? 0) !== $siteId || (int) ($p['f'] ?? 0) !== $formId
            || (int) ($p['e'] ?? 0) < ($now ?? time())
            || !ModuleRegistry::isEnabled($siteId, 'resources')) {
            return null;
        }
        $resource = ResourceStore::find($siteId, (int) ($p['r'] ?? 0));
        return self::isConditionedResource($resource, $formId) ? $resource : null;
    }

    public static function issueDownloadToken(
        int $siteId,
        int $resourceId,
        int $submissionId,
        int $ttl = self::DOWNLOAD_TTL,
        ?int $now = null
    ): string {
        return self::encode([
            'v' => 1,
            'k' => 'download',
            's' => $siteId,
            'r' => $resourceId,
            'u' => $submissionId,
            'e' => ($now ?? time()) + max(1, $ttl),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function validateDownloadToken(
        string $token,
        int $siteId,
        int $resourceId,
        ?int $now = null
    ): ?array {
        $p = self::decode($token);
        if ($p === null || ($p['k'] ?? '') !== 'download' || (int) ($p['v'] ?? 0) !== 1
            || (int) ($p['s'] ?? 0) !== $siteId || (int) ($p['r'] ?? 0) !== $resourceId
            || (int) ($p['u'] ?? 0) <= 0 || (int) ($p['e'] ?? 0) < ($now ?? time())
            || !ModuleRegistry::isEnabled($siteId, 'resources')) {
            return null;
        }

        $resource = ResourceStore::find($siteId, $resourceId);
        if (!self::isConditionedResource($resource, (int) ($resource['form_id'] ?? 0))) return null;

        $submission = Database::selectOne(
            'SELECT id FROM form_submissions WHERE id = ? AND site_id = ? AND section_id = ? LIMIT 1',
            [(int) $p['u'], $siteId, (int) $resource['form_id']]
        );
        return $submission !== null ? $resource : null;
    }

    /** Devuelve el enlace solo si el contexto corresponde al envío guardado. */
    public static function downloadUrlForSubmission(
        string $context,
        int $siteId,
        int $formId,
        int $submissionId,
        string $lang
    ): ?string {
        $resource = self::validateFormContext($context, $siteId, $formId);
        if ($resource === null || $submissionId <= 0) return null;
        $token = self::issueDownloadToken($siteId, (int) $resource['id'], $submissionId);
        $prefix = LanguageService::prefixFor($siteId, $lang);
        $path = ($prefix !== '' ? $prefix . '/' : '')
            . 'recursos/' . rawurlencode((string) $resource['slug']) . '/descargar';
        return base_url($path) . '?token=' . rawurlencode($token);
    }

    private static function isConditionedResource(?array $resource, int $formId): bool
    {
        return $resource !== null
            && (string) ($resource['status'] ?? '') === 'published'
            && (string) ($resource['access_mode'] ?? '') === 'form'
            && (int) ($resource['form_id'] ?? 0) > 0
            && (int) ($resource['form_id'] ?? 0) === $formId
            && (string) ($resource['form_status'] ?? '') !== ''
            && (string) ($resource['form_status'] ?? '') !== 'deleted';
    }

    private static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new \RuntimeException('No se pudo firmar el acceso al recurso.');
        $body = self::base64UrlEncode($json);
        $signature = hash_hmac('sha256', $body, self::key(), true);
        return $body . '.' . self::base64UrlEncode($signature);
    }

    private static function decode(string $token): ?array
    {
        if (strlen($token) > 1000 || preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m) !== 1) return null;
        $expected = hash_hmac('sha256', $m[1], self::key(), true);
        $provided = self::base64UrlDecode($m[2]);
        if ($provided === null || !hash_equals($expected, $provided)) return null;
        $json = self::base64UrlDecode($m[1]);
        if ($json === null) return null;
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    private static function key(): string
    {
        $appKey = (string) (App::config()['app_key'] ?? '');
        if ($appKey === '') throw new \RuntimeException('Resources requiere app_key en config.php');
        return hash_hmac('sha256', 'resource-download-v1', $appKey, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $pad = strlen($value) % 4;
        if ($pad > 0) $value .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
