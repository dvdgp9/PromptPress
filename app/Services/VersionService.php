<?php

namespace App\Services;

use Core\Database;

/**
 * Snapshots básicos de entidades versionables (T9.2).
 */
final class VersionService
{
    public const ENTITY_SECTION = 'page_section';

    public static function snapshotSection(array $section, ?int $userId, string $reason): int
    {
        $data = [
            'id'           => (int) $section['id'],
            'page_id'      => (int) $section['page_id'],
            'section_type' => (string) $section['section_type'],
            'sort_order'   => (int) $section['sort_order'],
            'content'      => (string) ($section['content'] ?? '{}'),
            'style'        => $section['style'] !== null ? (string) $section['style'] : null,
            'status'       => (string) $section['status'],
            'created_at'   => (string) ($section['created_at'] ?? ''),
            'updated_at'   => (string) ($section['updated_at'] ?? ''),
        ];

        Database::execute(
            'INSERT INTO versions (entity_type, entity_id, version_data, created_by, reason)
             VALUES (?, ?, ?, ?, ?)',
            [
                self::ENTITY_SECTION,
                (int) $section['id'],
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $userId,
                mb_substr($reason, 0, 255),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public static function sectionVersions(int $sectionId): array
    {
        return Database::select(
            'SELECT v.id, v.reason, v.created_at, v.created_by, u.username
             FROM versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.entity_type = ? AND v.entity_id = ?
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT 30',
            [self::ENTITY_SECTION, $sectionId]
        );
    }

    public static function loadSectionVersion(int $sectionId, int $versionId): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM versions
             WHERE id = ? AND entity_type = ? AND entity_id = ?
             LIMIT 1',
            [$versionId, self::ENTITY_SECTION, $sectionId]
        );
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['version_data'], true);
        if (!is_array($data)) {
            return null;
        }
        $row['data'] = $data;
        return $row;
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'before_manual_update' => 'Antes de edición manual',
            'before_delete'        => 'Antes de eliminar',
            'before_restore'       => 'Antes de restaurar',
            'before_ai_edit'       => 'Antes de edición IA',
            default                => $reason,
        };
    }
}
