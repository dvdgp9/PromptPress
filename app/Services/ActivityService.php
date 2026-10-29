<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Canvas\CanvasService;
use Core\Database;

/**
 * ASST-V2 F1 — «Actividad»: un solo historial de cambios con deshacer.
 *
 * No hay tabla nueva: se leen las versiones que ya existen.
 *   - Páginas Canvas (`page_versions`): cada fila es el estado DESPUÉS de un
 *     cambio. Deshacerlo = volver a la versión anterior de esa página. Es el
 *     borrador; lo publicado no cambia hasta publicar.
 *   - Formularios (`versions`, entity page_section): cada fila es el estado
 *     ANTES de un cambio. Deshacerlo = restaurar esa fila. Es en vivo.
 *
 * Cuando el asistente aprenda a tocar más cosas (F4), cada ejecutor añade aquí
 * su fuente y su forma de deshacer.
 */
final class ActivityService
{
    public const DAYS = 30;
    public const FILTERS = ['all', 'assistant', 'manual'];
    private const LIMIT = 80;

    /** Orígenes de page_versions escritos por una IA dentro del editor. */
    private const AI_ORIGINS = ['chat', 'generate', 'translate', 'compose', 'template'];

    /**
     * @return array<int,array<string,mixed>> más reciente primero
     */
    public static function entries(int $siteId, ?string $role, string $filter = 'all'): array
    {
        $entries = [];
        if (Permissions::roleHas($role, Permissions::CAP_CONTENT)) {
            $entries = array_merge($entries, self::pageEntries($siteId));
        }
        if (Permissions::roleHas($role, Permissions::CAP_FORMS)) {
            $entries = array_merge($entries, self::formEntries($siteId));
        }
        if (Permissions::roleHas($role, Permissions::CAP_VISIBILITY)) {
            $entries = array_merge($entries, self::seoEntries($siteId));
        }
        // El header y el pie solo los edita un administrador.
        if ($role === Permissions::ROLE_ADMIN) {
            $entries = array_merge($entries, self::chromeEntries($siteId));
        }
        if (Permissions::roleHas($role, Permissions::CAP_APPEARANCE)) {
            $entries = array_merge($entries, self::designEntries($siteId));
        }
        return self::filterAndSort($entries, $filter);
    }

    /**
     * Pura: se prueba sin BD.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    public static function filterAndSort(array $entries, string $filter): array
    {
        if ($filter === 'assistant') {
            $entries = array_filter($entries, static fn (array $e): bool => $e['actor'] === 'assistant');
        } elseif ($filter === 'manual') {
            $entries = array_filter($entries, static fn (array $e): bool => $e['actor'] !== 'assistant');
        }
        usort($entries, static fn (array $a, array $b): int => [$b['at'], $b['version_id']] <=> [$a['at'], $a['version_id']]);
        return array_slice(array_values($entries), 0, self::LIMIT);
    }

    /** Quién hizo un cambio de página según su origen. */
    public static function pageActor(string $origin): string
    {
        return match (true) {
            $origin === 'assistant' => 'assistant',
            $origin === 'restore' => 'restore',
            in_array($origin, self::AI_ORIGINS, true) => 'ai',
            default => 'manual',
        };
    }

    /** Quién hizo un cambio de formulario según el motivo de su versión. */
    public static function formActor(string $reason): ?string
    {
        return match ($reason) {
            'before_ai_edit' => 'assistant',
            'before_manual_update' => 'manual',
            'before_restore' => 'restore',
            default => null,
        };
    }

    /** @return array<int,array<string,mixed>> */
    private static function pageEntries(int $siteId): array
    {
        $rows = Database::select(
            "SELECT v.id, v.page_id, v.origin, v.summary, v.created_at, p.title, u.username,
                    pc.current_version_id,
                    (SELECT MAX(pv.id) FROM page_versions pv WHERE pv.page_id = v.page_id AND pv.id < v.id) AS prev_id
             FROM page_versions v
             JOIN pages p ON p.id = v.page_id
             LEFT JOIN page_canvas pc ON pc.page_id = v.page_id
             LEFT JOIN users u ON u.id = v.created_by
             WHERE p.site_id = ? AND p.render_mode = 'canvas'
               AND v.created_at >= NOW() - INTERVAL " . self::DAYS . " DAY
             ORDER BY v.id DESC LIMIT " . self::LIMIT,
            [$siteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $current = (int) ($r['current_version_id'] ?? 0);
            $undone = $current > 0 && (int) $r['id'] > $current;
            $out[] = [
                'type' => 'page',
                'entity_id' => (int) $r['page_id'],
                'title' => (string) $r['title'],
                'actor' => self::pageActor((string) $r['origin']),
                'username' => (string) ($r['username'] ?? ''),
                'detail' => trim((string) ($r['summary'] ?? '')),
                'at' => (string) $r['created_at'],
                'version_id' => (int) $r['id'],
                'undone' => $undone,
                'undoable' => !$undone && (int) ($r['prev_id'] ?? 0) > 0,
                'edit_url' => base_url('admin/canvas/' . (int) $r['page_id']),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function formEntries(int $siteId): array
    {
        $rows = Database::select(
            "SELECT vr.id, vr.entity_id, vr.reason, vr.created_at, ps.content, u.username
             FROM versions vr
             JOIN page_sections ps ON ps.id = vr.entity_id AND ps.section_type = 'form' AND ps.status != 'deleted'
             JOIN pages c ON c.id = ps.page_id AND c.slug = '__forms'
             LEFT JOIN users u ON u.id = vr.created_by
             WHERE vr.entity_type = ? AND c.site_id = ?
               AND vr.reason IN ('before_ai_edit', 'before_manual_update', 'before_restore')
               AND vr.created_at >= NOW() - INTERVAL " . self::DAYS . " DAY
             ORDER BY vr.id DESC LIMIT " . self::LIMIT,
            [VersionService::ENTITY_SECTION, $siteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $actor = self::formActor((string) $r['reason']);
            if ($actor === null) continue;
            $content = json_decode((string) $r['content'], true);
            $out[] = [
                'type' => 'form',
                'entity_id' => (int) $r['entity_id'],
                'title' => trim((string) (is_array($content) ? ($content['heading'] ?? '') : '')) ?: __('forms.untitled'),
                'actor' => $actor,
                'username' => (string) ($r['username'] ?? ''),
                'detail' => '',
                'at' => (string) $r['created_at'],
                'version_id' => (int) $r['id'],
                'undone' => false,
                'undoable' => true,
                'edit_url' => base_url('admin/formularios/' . (int) $r['entity_id']),
            ];
        }
        return $out;
    }

    /**
     * ASST-V2 F4a — SEO de página: cada fila es el estado ANTES del cambio
     * (solo los cambios del asistente y las restauraciones lo guardan).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function seoEntries(int $siteId): array
    {
        $rows = Database::select(
            "SELECT vr.id, vr.entity_id, vr.reason, vr.created_at, vr.version_data, p.title, u.username
             FROM versions vr
             JOIN pages p ON p.id = vr.entity_id AND p.site_id = ?
             LEFT JOIN users u ON u.id = vr.created_by
             WHERE vr.entity_type = ? AND vr.reason IN ('before_ai_edit', 'before_restore')
               AND vr.created_at >= NOW() - INTERVAL " . self::DAYS . " DAY
             ORDER BY vr.id DESC LIMIT " . self::LIMIT,
            [$siteId, PageSeoAssistantEditor::ENTITY]
        );
        $out = [];
        foreach ($rows as $r) {
            $actor = self::formActor((string) $r['reason']);
            if ($actor === null) continue;
            $before = json_decode((string) $r['version_data'], true);
            $oldTitle = is_array($before) ? trim((string) ($before['meta_title'] ?? '')) : '';
            $link = AssistantScreenContext::deepLink(['page_id' => (int) $r['entity_id']], $siteId);
            $out[] = [
                'type' => 'seo',
                'entity_id' => (int) $r['entity_id'],
                'title' => (string) $r['title'],
                'actor' => $actor,
                'username' => (string) ($r['username'] ?? ''),
                'detail' => __('activity.seo_before', ['titulo' => $oldTitle !== '' ? $oldTitle : __('asst.diff.empty')]),
                'at' => (string) $r['created_at'],
                'version_id' => (int) $r['id'],
                'undone' => false,
                'undoable' => true,
                'edit_url' => $link['url'] ?? base_url('admin/seo'),
            ];
        }
        return $out;
    }

    /**
     * ASST-V2 F4b — header y pie: cada fila es la config ANTES del cambio.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function chromeEntries(int $siteId): array
    {
        $rows = Database::select(
            "SELECT vr.id, vr.reason, vr.created_at, u.username
             FROM versions vr
             LEFT JOIN users u ON u.id = vr.created_by
             WHERE vr.entity_type = ? AND vr.entity_id = ? AND vr.reason IN ('before_ai_edit', 'before_restore')
               AND vr.created_at >= NOW() - INTERVAL " . self::DAYS . " DAY
             ORDER BY vr.id DESC LIMIT " . self::LIMIT,
            [ChromeAssistantEditor::ENTITY, $siteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $actor = self::formActor((string) $r['reason']);
            if ($actor === null) continue;
            $out[] = [
                'type' => 'chrome',
                'entity_id' => $siteId,
                'title' => __('asst.chrome.label'),
                'actor' => $actor,
                'username' => (string) ($r['username'] ?? ''),
                'detail' => '',
                'at' => (string) $r['created_at'],
                'version_id' => (int) $r['id'],
                'undone' => false,
                'undoable' => true,
                'edit_url' => base_url('admin/chrome'),
            ];
        }
        return $out;
    }

    /**
     * ASST-V2 F4e — diseño global: cada fila es la paleta y los overrides
     * ANTES del cambio.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function designEntries(int $siteId): array
    {
        $rows = Database::select(
            "SELECT vr.id, vr.reason, vr.created_at, u.username
             FROM versions vr
             LEFT JOIN users u ON u.id = vr.created_by
             WHERE vr.entity_type = ? AND vr.entity_id = ? AND vr.reason IN ('before_ai_edit', 'before_restore')
               AND vr.created_at >= NOW() - INTERVAL " . self::DAYS . " DAY
             ORDER BY vr.id DESC LIMIT " . self::LIMIT,
            [DesignAssistantEditor::ENTITY, $siteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $actor = self::formActor((string) $r['reason']);
            if ($actor === null) continue;
            $out[] = [
                'type' => 'design',
                'entity_id' => $siteId,
                'title' => __('asst.design.label'),
                'actor' => $actor,
                'username' => (string) ($r['username'] ?? ''),
                'detail' => '',
                'at' => (string) $r['created_at'],
                'version_id' => (int) $r['id'],
                'undone' => false,
                'undoable' => true,
                'edit_url' => base_url('admin/design'),
            ];
        }
        return $out;
    }

    /**
     * Deshace un cambio. Devuelve la clave de traducción del resultado.
     *
     * @return array{ok:bool,message:string,vars?:array<string,string>}
     */
    public static function undo(int $siteId, ?string $role, ?int $userId, string $type, int $entityId, int $versionId): array
    {
        $fail = ['ok' => false, 'message' => 'activity.undo_failed'];
        if ($type === 'form') {
            if (!Permissions::roleHas($role, Permissions::CAP_FORMS)) return $fail;
            return FormStore::restoreVersion($siteId, $entityId, $versionId, $userId)
                ? ['ok' => true, 'message' => 'activity.undo_form_done']
                : $fail;
        }
        if ($type === 'chrome') {
            if ($role !== Permissions::ROLE_ADMIN || $entityId !== $siteId) return $fail;
            return ChromeAssistantEditor::restoreVersion($siteId, $versionId, $userId)
                ? ['ok' => true, 'message' => 'activity.undo_chrome_done']
                : $fail;
        }
        if ($type === 'design') {
            if (!Permissions::roleHas($role, Permissions::CAP_APPEARANCE) || $entityId !== $siteId) return $fail;
            return DesignAssistantEditor::restoreVersion($siteId, $versionId, $userId)
                ? ['ok' => true, 'message' => 'activity.undo_design_done']
                : $fail;
        }
        if ($type === 'seo') {
            if (!Permissions::roleHas($role, Permissions::CAP_VISIBILITY)) return $fail;
            return PageSeoAssistantEditor::restoreVersion($siteId, $entityId, $versionId, $userId)
                ? ['ok' => true, 'message' => 'activity.undo_seo_done']
                : $fail;
        }
        if ($type !== 'page' || !Permissions::roleHas($role, Permissions::CAP_CONTENT)) {
            return $fail;
        }
        $page = Database::selectOne(
            "SELECT id FROM pages WHERE id = ? AND site_id = ? AND render_mode = 'canvas' LIMIT 1",
            [$entityId, $siteId]
        );
        if ($page === null) return $fail;
        $lock = EditLock::status(EditLock::ENTITY_PAGE, $entityId);
        if (!empty($lock['held']) && (int) ($lock['user_id'] ?? 0) !== (int) $userId) {
            return ['ok' => false, 'message' => 'activity.undo_locked', 'vars' => ['nombre' => (string) ($lock['username'] ?? '')]];
        }
        $prev = Database::selectOne(
            'SELECT MAX(id) AS id FROM page_versions WHERE page_id = ? AND id < ?
               AND EXISTS (SELECT 1 FROM page_versions WHERE id = ? AND page_id = ?)',
            [$entityId, $versionId, $versionId, $entityId]
        );
        $target = (int) ($prev['id'] ?? 0);
        if ($target <= 0 || CanvasService::restore($entityId, $target) === null) {
            return $fail;
        }
        return ['ok' => true, 'message' => 'activity.undo_page_done'];
    }
}
