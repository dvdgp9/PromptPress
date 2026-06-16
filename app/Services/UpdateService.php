<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

final class UpdateService
{
    private const KEY_LAST_CHECK = 'updates_last_check';
    private const KEY_LAST_RESULT = 'updates_last_result';

    /**
     * @return array{
     *   current_version:string,
     *   latest_version:?string,
     *   has_update:bool,
     *   checked_at:?string,
     *   source:string,
     *   status:string,
     *   message:string,
     *   changelog_url:?string,
     *   download_url:?string,
     *   checksum_sha256:?string,
     *   signature:?string,
     *   signature_alg:?string
     * }
     */
    public static function status(int $siteId): array
    {
        $current = defined('PP_VERSION') ? (string) PP_VERSION : '0.0.0';
        $raw = self::getSetting($siteId, self::KEY_LAST_RESULT);
        $checkedAt = self::getSetting($siteId, self::KEY_LAST_CHECK);

        $base = [
            'current_version' => $current,
            'latest_version' => null,
            'has_update' => false,
            'checked_at' => $checkedAt !== '' ? $checkedAt : null,
            'source' => 'none',
            'status' => 'idle',
            'message' => 'Aún no se ha comprobado si hay nuevas versiones.',
            'changelog_url' => null,
            'download_url' => null,
            'checksum_sha256' => null,
            'signature' => null,
            'signature_alg' => null,
        ];

        if ($raw === '') {
            return $base;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $base['status'] = 'error';
            $base['message'] = 'El último resultado de actualización no es válido.';
            return $base;
        }

        return array_merge($base, [
            'latest_version' => self::strOrNull($decoded['latest_version'] ?? null),
            'has_update' => (bool) ($decoded['has_update'] ?? false),
            'source' => (string) ($decoded['source'] ?? 'remote'),
            'status' => (string) ($decoded['status'] ?? 'ok'),
            'message' => (string) ($decoded['message'] ?? 'Comprobación completada.'),
            'changelog_url' => self::strOrNull($decoded['changelog_url'] ?? null),
            'download_url' => self::strOrNull($decoded['download_url'] ?? null),
            'checksum_sha256' => self::strOrNull($decoded['checksum_sha256'] ?? null),
            'signature' => self::strOrNull($decoded['signature'] ?? null),
            'signature_alg' => self::strOrNull($decoded['signature_alg'] ?? null),
        ]);
    }

    public static function checkNow(int $siteId): array
    {
        $current = defined('PP_VERSION') ? (string) PP_VERSION : '0.0.0';
        $endpoint = trim((string) config('updates.version_check_url', ''));

        if ($endpoint === '') {
            $payload = [
                'source' => 'mock',
                'status' => 'ok',
                'latest_version' => $current,
                'has_update' => false,
                'message' => 'Canal de updates no configurado (`updates.version_check_url`).',
                'changelog_url' => null,
                'download_url' => null,
                'checksum_sha256' => null,
                'signature' => null,
                'signature_alg' => null,
            ];
            self::storeResult($siteId, $payload);
            return self::status($siteId);
        }

        $result = self::callRemote($endpoint, $siteId, $current);
        self::storeResult($siteId, $result);
        return self::status($siteId);
    }

    private static function callRemote(string $endpoint, int $siteId, string $current): array
    {
        $payload = [
            'product' => 'promptpress',
            'site_id' => $siteId,
            'current_version' => $current,
            'license_key' => trim((string) config('updates.license_key', '')),
            'channel' => trim((string) config('updates.channel', 'stable')),
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body) || $http < 200 || $http >= 300) {
            return [
                'source' => 'remote',
                'status' => 'error',
                'latest_version' => $current,
                'has_update' => false,
                'message' => $errno !== 0 ? ('No se pudo consultar updates: ' . $error) : ('Endpoint devolvió HTTP ' . $http),
                'changelog_url' => null,
                'download_url' => null,
                'checksum_sha256' => null,
                'signature' => null,
                'signature_alg' => null,
            ];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [
                'source' => 'remote',
                'status' => 'error',
                'latest_version' => $current,
                'has_update' => false,
                'message' => 'Respuesta inválida del servidor de updates.',
                'changelog_url' => null,
                'download_url' => null,
                'checksum_sha256' => null,
                'signature' => null,
                'signature_alg' => null,
            ];
        }

        $latest = trim((string) ($json['latest'] ?? $current));
        $hasUpdate = self::isNewer($latest, $current);

        return [
            'source' => 'remote',
            'status' => 'ok',
            'latest_version' => $latest,
            'has_update' => $hasUpdate,
            'message' => (string) ($json['message'] ?? ($hasUpdate ? 'Hay una nueva versión disponible.' : 'Tu instalación está al día.')),
            'changelog_url' => self::strOrNull($json['changelog_url'] ?? null),
            'download_url' => self::strOrNull($json['download_url'] ?? null),
            'checksum_sha256' => self::strOrNull($json['checksum_sha256'] ?? null),
            'signature' => self::strOrNull($json['signature'] ?? null),
            'signature_alg' => self::strOrNull($json['signature_alg'] ?? null),
        ];
    }

    private static function storeResult(int $siteId, array $payload): void
    {
        self::upsertSetting($siteId, self::KEY_LAST_RESULT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::upsertSetting($siteId, self::KEY_LAST_CHECK, date('c'));
    }

    private static function getSetting(int $siteId, string $key): string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $key]
        );
        return (string) ($row['setting_value'] ?? '');
    }

    private static function upsertSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$siteId, $key, $value]
        );
    }

    private static function isNewer(string $latest, string $current): bool
    {
        $a = preg_replace('/[^0-9.]/', '', $latest) ?: '0';
        $b = preg_replace('/[^0-9.]/', '', $current) ?: '0';
        return version_compare($a, $b, '>');
    }

    private static function strOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }
}
