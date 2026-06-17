<?php

namespace App\Services;

use Core\Database;

final class SiteResetService
{
    /** @return array<string,int> */
    public static function counts(int $siteId): array
    {
        $pageIds = self::ids('pages', 'site_id', $siteId);
        $sectionIds = $pageIds === []
            ? []
            : self::idsIn('page_sections', 'page_id', $pageIds);

        return [
            'pages' => self::countWhere('pages', 'site_id', $siteId),
            'documents' => self::countWhere('documents', 'site_id', $siteId),
            'messages' => self::countWhere('form_submissions', 'site_id', $siteId),
            'memory' => self::countWhere('site_memory', 'site_id', $siteId),
            'media' => self::countWhere('media', 'site_id', $siteId),
            'ai_logs' => self::countWhere('ai_logs', 'site_id', $siteId),
            'versions' => $sectionIds === [] ? 0 : self::countVersions($sectionIds),
        ];
    }

    /** @return array<string,mixed> */
    public static function reset(int $siteId): array
    {
        $counts = self::counts($siteId);
        $pageIds = self::ids('pages', 'site_id', $siteId);
        $sectionIds = $pageIds === []
            ? []
            : self::idsIn('page_sections', 'page_id', $pageIds);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($sectionIds !== []) {
                self::deleteVersions($sectionIds);
            }
            Database::execute('DELETE FROM form_submissions WHERE site_id = ?', [$siteId]);
            if ($pageIds !== []) {
                self::deleteIn('page_sections', 'page_id', $pageIds);
            }
            Database::execute('DELETE FROM pages WHERE site_id = ?', [$siteId]);
            Database::execute('DELETE FROM media WHERE site_id = ?', [$siteId]);
            Database::execute('DELETE FROM documents WHERE site_id = ?', [$siteId]);
            Database::execute('DELETE FROM site_memory WHERE site_id = ?', [$siteId]);
            Database::execute('DELETE FROM design_system WHERE site_id = ?', [$siteId]);
            Database::execute('DELETE FROM ai_logs WHERE site_id = ?', [$siteId]);
            Database::execute(
                'DELETE FROM settings
                 WHERE site_id = ?
                   AND (
                        setting_key LIKE "onboarding_%"
                     OR setting_key IN (
                        "page_studio_opportunities_cache",
                        "site_architecture_cache",
                        "page_hierarchy_inferred"
                     )
                   )',
                [$siteId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::emptyDir(PP_ROOT . '/storage/uploads/' . $siteId);
        self::emptyDir(PP_ROOT . '/storage/form_uploads/' . $siteId);
        self::emptyDir(PP_ROOT . '/storage/documents/' . $siteId);
        self::emptyDir(PP_ROOT . '/storage/cache/' . $siteId);

        return [
            'site_id' => $siteId,
            'deleted' => $counts,
        ];
    }

    private static function countWhere(string $table, string $column, int $value): int
    {
        $row = Database::selectOne("SELECT COUNT(*) AS n FROM {$table} WHERE {$column} = ?", [$value]);
        return (int) ($row['n'] ?? 0);
    }

    /** @return int[] */
    private static function ids(string $table, string $column, int $value): array
    {
        return array_map(
            static fn($r) => (int) $r['id'],
            Database::select("SELECT id FROM {$table} WHERE {$column} = ?", [$value])
        );
    }

    /** @param int[] $ids @return int[] */
    private static function idsIn(string $table, string $column, array $ids): array
    {
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return array_map(
            static fn($r) => (int) $r['id'],
            Database::select("SELECT id FROM {$table} WHERE {$column} IN ({$placeholders})", $ids)
        );
    }

    /** @param int[] $sectionIds */
    private static function countVersions(array $sectionIds): int
    {
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        $row = Database::selectOne(
            "SELECT COUNT(*) AS n FROM versions WHERE entity_type = 'page_section' AND entity_id IN ({$placeholders})",
            $sectionIds
        );
        return (int) ($row['n'] ?? 0);
    }

    /** @param int[] $sectionIds */
    private static function deleteVersions(array $sectionIds): void
    {
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        Database::execute(
            "DELETE FROM versions WHERE entity_type = 'page_section' AND entity_id IN ({$placeholders})",
            $sectionIds
        );
    }

    /** @param int[] $ids */
    private static function deleteIn(string $table, string $column, array $ids): void
    {
        if ($ids === []) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::execute("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $ids);
    }

    private static function emptyDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::emptyDir($path);
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
