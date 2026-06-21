<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/** Registro normalizado de las paginas Canvas que usan cada formulario. */
final class FormPlacementStore
{
    public static function syncPage(int $pageId, string $html): void
    {
        preg_match_all('/\{\{form:(\d+)\}\}/', $html, $matches);
        $formIds = array_values(array_unique(array_map('intval', $matches[1] ?? [])));

        if ($formIds === []) {
            Database::execute('DELETE FROM form_placements WHERE page_id = ?', [$pageId]);
            return;
        }
        $marks = implode(',', array_fill(0, count($formIds), '?'));
        Database::execute(
            'DELETE FROM form_placements WHERE page_id = ? AND form_id NOT IN (' . $marks . ')',
            array_merge([$pageId], $formIds)
        );
        foreach ($formIds as $formId) self::record($formId, $pageId);
    }

    public static function record(int $formId, int $pageId, ?string $sourceLabel = null): void
    {
        $label = trim((string) $sourceLabel);
        Database::execute(
            'INSERT INTO form_placements (form_id, page_id, source_label, created_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE source_label = COALESCE(VALUES(source_label), source_label)',
            [$formId, $pageId, $label !== '' ? mb_substr($label, 0, 160) : null, date('Y-m-d H:i:s')]
        );
    }

    /** @return array<int,int> formId => numero de paginas publicadas */
    public static function usageMap(int $siteId): array
    {
        $rows = Database::select(
            "SELECT fp.form_id, COUNT(DISTINCT fp.page_id) AS uses_count
             FROM form_placements fp
             JOIN pages p ON p.id = fp.page_id
             JOIN page_sections ps ON ps.id = fp.form_id
             JOIN pages container ON container.id = ps.page_id
             WHERE p.site_id = ? AND p.status = 'published'
               AND container.site_id = ? AND ps.section_type = 'form' AND ps.status != 'deleted'
             GROUP BY fp.form_id",
            [$siteId, $siteId]
        );
        $out = [];
        foreach ($rows as $row) $out[(int) $row['form_id']] = (int) $row['uses_count'];
        return $out;
    }
}
