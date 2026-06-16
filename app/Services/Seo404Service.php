<?php

namespace App\Services;

use Core\Database;
use Core\Request;

final class Seo404Service
{
    public static function shouldIgnore(string $path): bool
    {
        $path = SeoRedirectService::normalizePath($path);
        if (preg_match('#^/(admin|storage|public|assets|install)(/|$)#i', $path)) return true;
        if (preg_match('/\.(css|js|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|pdf|xml|txt)$/i', $path)) return true;
        return false;
    }

    public static function record(int $siteId, string $path, ?string $queryString = null): void
    {
        $path = SeoRedirectService::normalizePath($path);
        if (self::shouldIgnore($path)) return;

        $query = trim((string) ($queryString ?? ($_SERVER['QUERY_STRING'] ?? '')));
        $referrer = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000);
        $agent = mb_substr(Request::userAgent(), 0, 500);
        $ip = Request::ip();
        $ipHash = $ip !== '' ? hash('sha256', $ip . '|' . (string) config('app_key', '')) : null;
        $hash = hash('sha256', $path . '?' . $query);

        Database::execute(
            'INSERT INTO seo_404_logs
                (site_id, request_hash, requested_path, query_string, referrer, user_agent, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                hit_count = hit_count + 1,
                last_seen_at = NOW(),
                referrer = VALUES(referrer),
                user_agent = VALUES(user_agent),
                ip_hash = VALUES(ip_hash),
                status = IF(status = "resolved", "resolved", status)',
            [$siteId, $hash, $path, $query !== '' ? $query : null, $referrer ?: null, $agent ?: null, $ipHash]
        );
    }

    public static function list(int $siteId, string $status = 'open', int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        $allowed = ['open', 'ignored', 'resolved', 'all'];
        if (!in_array($status, $allowed, true)) $status = 'open';

        $where = 'site_id = ?';
        $params = [$siteId];
        if ($status !== 'all') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        return Database::select(
            "SELECT * FROM seo_404_logs
             WHERE {$where}
             ORDER BY last_seen_at DESC, hit_count DESC
             LIMIT {$limit}",
            $params
        );
    }

    public static function mark(int $siteId, int $id, string $status, ?int $redirectId = null): void
    {
        if (!in_array($status, ['open', 'ignored', 'resolved'], true)) {
            throw new \InvalidArgumentException('Estado de 404 no válido.');
        }
        Database::execute(
            'UPDATE seo_404_logs SET status = ?, redirect_id = ? WHERE id = ? AND site_id = ?',
            [$status, $redirectId, $id, $siteId]
        );
    }

    public static function countOpen(int $siteId): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n FROM seo_404_logs WHERE site_id = ? AND status = "open"',
            [$siteId]
        );
        return (int) ($row['n'] ?? 0);
    }
}
