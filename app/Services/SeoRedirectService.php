<?php

namespace App\Services;

use Core\Database;

final class SeoRedirectService
{
    public const ALLOWED_STATUS = [301, 302, 410];

    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '/';

        $parts = parse_url($path);
        if (is_array($parts) && isset($parts['path'])) {
            $path = (string) $parts['path'];
        }

        $path = preg_replace('#/+#', '/', '/' . ltrim($path, '/')) ?? '/';
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public static function findActive(int $siteId, string $path): ?array
    {
        $source = self::normalizePath($path);
        return Database::selectOne(
            'SELECT * FROM seo_redirects
             WHERE site_id = ? AND source_path = ? AND is_active = 1
             LIMIT 1',
            [$siteId, $source]
        );
    }

    public static function createManual(
        int $siteId,
        string $sourcePath,
        ?string $targetPath,
        int $statusCode = 301,
        ?int $createdBy = null
    ): array {
        return self::upsert($siteId, $sourcePath, $targetPath, $statusCode, false, null, null, $createdBy);
    }

    public static function createAutomaticSlugRedirect(
        int $siteId,
        string $oldSlug,
        string $newSlug,
        int $pageId,
        ?int $createdBy = null
    ): ?array {
        $oldPath = self::normalizePath($oldSlug);
        $newPath = self::normalizePath($newSlug);
        if ($oldPath === $newPath) return null;

        return self::upsert($siteId, $oldPath, $newPath, 301, true, $pageId, $pageId, $createdBy);
    }

    public static function recordHit(int $id): void
    {
        Database::execute(
            'UPDATE seo_redirects SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public static function deactivate(int $siteId, int $id): void
    {
        Database::execute(
            'UPDATE seo_redirects SET is_active = 0 WHERE id = ? AND site_id = ?',
            [$id, $siteId]
        );
    }

    public static function activate(int $siteId, int $id): void
    {
        Database::execute(
            'UPDATE seo_redirects SET is_active = 1 WHERE id = ? AND site_id = ?',
            [$id, $siteId]
        );
    }

    public static function delete(int $siteId, int $id): void
    {
        Database::execute('DELETE FROM seo_redirects WHERE id = ? AND site_id = ?', [$id, $siteId]);
    }

    public static function list(int $siteId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return Database::select(
            "SELECT r.*, sp.title AS source_page_title, tp.title AS target_page_title
             FROM seo_redirects r
             LEFT JOIN pages sp ON sp.id = r.source_page_id
             LEFT JOIN pages tp ON tp.id = r.target_page_id
             WHERE r.site_id = ?
             ORDER BY r.is_active DESC, r.updated_at DESC, r.id DESC
             LIMIT {$limit}",
            [$siteId]
        );
    }

    private static function upsert(
        int $siteId,
        string $sourcePath,
        ?string $targetPath,
        int $statusCode,
        bool $autoCreated,
        ?int $sourcePageId,
        ?int $targetPageId,
        ?int $createdBy
    ): array {
        if (!in_array($statusCode, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException('Tipo de redirección no válido.');
        }

        $source = self::normalizePath($sourcePath);
        $target = $statusCode === 410 ? null : self::normalizePath((string) $targetPath);

        if ($source === '/' || $source === '') {
            throw new \InvalidArgumentException('No se puede redirigir la página de inicio desde aquí.');
        }
        if ($statusCode !== 410 && ($target === null || $target === '/')) {
            throw new \InvalidArgumentException('Indica una URL de destino.');
        }
        if ($statusCode !== 410 && $source === $target) {
            throw new \InvalidArgumentException('La URL antigua y la nueva no pueden ser iguales.');
        }
        if ($statusCode !== 410 && self::wouldLoop($siteId, $source, (string) $target)) {
            throw new \InvalidArgumentException('Esa redirección crearía un bucle.');
        }

        Database::execute(
            'INSERT INTO seo_redirects
                (site_id, source_path, target_path, status_code, is_active, auto_created,
                 source_page_id, target_page_id, created_by)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                target_path = VALUES(target_path),
                status_code = VALUES(status_code),
                is_active = 1,
                auto_created = GREATEST(auto_created, VALUES(auto_created)),
                source_page_id = COALESCE(VALUES(source_page_id), source_page_id),
                target_page_id = COALESCE(VALUES(target_page_id), target_page_id),
                updated_at = NOW()',
            [
                $siteId,
                $source,
                $target,
                $statusCode,
                $autoCreated ? 1 : 0,
                $sourcePageId,
                $targetPageId,
                $createdBy,
            ]
        );

        return Database::selectOne(
            'SELECT * FROM seo_redirects WHERE site_id = ? AND source_path = ? LIMIT 1',
            [$siteId, $source]
        ) ?? [];
    }

    private static function wouldLoop(int $siteId, string $source, string $target): bool
    {
        $next = self::findActive($siteId, $target);
        if (!$next) return false;
        if ((int) $next['status_code'] === 410) return false;
        return self::normalizePath((string) ($next['target_path'] ?? '')) === $source;
    }
}
