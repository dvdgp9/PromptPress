<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\Canvas\CanvasService;
use Core\Database;

/**
 * ASST-V2 F4a — Ejecutor del asistente para `seo.page.edit`.
 *
 * Solo cambia el título SEO y la meta descripción de una página. El slug, la
 * indexación y la canonical no se tocan: cambiar la URL rompe enlaces y eso
 * sigue siendo manual (seo.manage).
 *
 * Los metadatos no tienen borrador: guardar es publicarlos. Antes de escribir
 * se guarda el estado anterior en `versions` (entity page_seo) para poder
 * deshacerlo desde Actividad.
 */
final class PageSeoAssistantEditor
{
    public const ENTITY = 'page_seo';
    /** Lo que Google suele enseñar antes de cortar. */
    public const TITLE_IDEAL = 60;
    public const DESCRIPTION_IDEAL = 155;
    /** Límites del formulario manual de la página. */
    private const TITLE_MAX = 255;
    private const DESCRIPTION_MAX = 500;
    private const CONTENT_CHARS = 6000;

    /**
     * Prepara el título y la descripción nuevos, sin guardar nada.
     *
     * @return array{ok:bool, error?:string, before?:array<string,string>, content?:array<string,string>, base_hash?:string}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function propose(int $siteId, int $pageId, string $instruction): array
    {
        $page = self::page($siteId, $pageId);
        if ($page === null) {
            return ['ok' => false, 'error' => __('asst.err.page_gone')];
        }

        // i18n-ignore-start: contexto que viaja al prompt.
        $result = AIActionRunner::run(Actions::IMPROVE_SEO, [
            'page_title'               => (string) $page['title'],
            'page_type'                => (string) $page['page_type'],
            'current_slug'             => (string) $page['slug'],
            'current_meta_title'       => (string) ($page['meta_title'] ?? ''),
            'current_meta_description' => (string) ($page['meta_description'] ?? ''),
            'page_content'             => self::pageText($pageId) ?: (string) $page['title'],
            'extra_context'            => "Petición del operador: " . $instruction
                . "\nIdioma de la página: " . LanguageService::promptLabel((string) ($page['language'] ?? 'es'))
                . "\nEl slug NO se va a cambiar: devuelve el actual.",
        ], $siteId);
        // i18n-ignore-end

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $after = [
            'meta_title'       => self::clean((string) ($data['seo_title'] ?? '')),
            'meta_description' => self::clean((string) ($data['meta_description'] ?? '')),
        ];
        $errors = self::errors($after);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        return ['ok' => true, 'before' => self::state($page), 'content' => $after, 'base_hash' => AssistantProposal::hash(self::state($page))];
    }

    /**
     * Guarda una propuesta ya preparada si la página no ha cambiado su SEO
     * entre medias.
     *
     * @param array<string,mixed> $content
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function commit(int $siteId, int $pageId, array $content, string $baseHash, ?int $userId): array
    {
        $page = self::page($siteId, $pageId);
        if ($page === null) {
            return ['ok' => false, 'error' => __('asst.err.page_gone')];
        }
        if (!hash_equals(AssistantProposal::hash(self::state($page)), $baseHash)) {
            return ['ok' => false, 'error' => __('asst.err.seo_changed_since_preview')];
        }
        $after = [
            'meta_title'       => self::clean((string) ($content['meta_title'] ?? '')),
            'meta_description' => self::clean((string) ($content['meta_description'] ?? '')),
        ];
        $errors = self::errors($after);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        self::snapshot($page, $userId, 'before_ai_edit');
        self::write($pageId, $after);
        CacheService::flush($siteId);
        return ['ok' => true, 'reply' => __('asst.seo.updated', [
            'pagina'      => (string) $page['title'],
            'titulo'      => $after['meta_title'],
            'descripcion' => $after['meta_description'],
        ])];
    }

    /**
     * Sin vista previa: pide y guarda en el mismo paso.
     *
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function apply(int $siteId, int $pageId, string $instruction, ?int $userId): array
    {
        $proposal = self::propose($siteId, $pageId, $instruction);
        if (!$proposal['ok']) {
            return $proposal;
        }
        return self::commit($siteId, $pageId, $proposal['content'], $proposal['base_hash'], $userId);
    }

    /**
     * Lo que enseña la tarjeta: el resultado de Google antes y después.
     *
     * @param array<string,string> $before
     * @param array<string,string> $after
     * @return array<string,mixed>
     */
    public static function preview(int $siteId, int $pageId, array $before, array $after): array
    {
        $page = self::page($siteId, $pageId);
        $url = $page !== null ? self::publicUrl($page) : '';
        $title = $page !== null ? (string) $page['title'] : '';
        $snippet = static fn (array $s): array => [
            'title'       => $s['meta_title'] !== '' ? $s['meta_title'] : $title,
            'description' => $s['meta_description'],
            'url'         => $url,
            'fallback'    => $s['meta_title'] === '',
        ];
        // El texto nuevo ya se ve en el resultado de Google: aquí, solo qué cambia.
        $changes = [];
        if ($before['meta_title'] !== $after['meta_title']) {
            $changes[] = ['kind' => 'text', 'label' => __('asst.seo.title'), 'detail' => ''];
        }
        if ($before['meta_description'] !== $after['meta_description']) {
            $changes[] = ['kind' => 'text', 'label' => __('asst.seo.description'), 'detail' => ''];
        }
        return [
            'kind'    => 'seo',
            'before'  => $snippet($before),
            'after'   => $snippet($after),
            'changes' => $changes,
            'limits'  => ['title' => self::TITLE_IDEAL, 'description' => self::DESCRIPTION_IDEAL],
        ];
    }

    /**
     * Devuelve la página al SEO de una versión (guardando antes el actual).
     */
    public static function restoreVersion(int $siteId, int $pageId, int $versionId, ?int $userId): bool
    {
        $page = self::page($siteId, $pageId);
        if ($page === null) return false;
        $row = Database::selectOne(
            'SELECT version_data FROM versions WHERE id = ? AND entity_type = ? AND entity_id = ? LIMIT 1',
            [$versionId, self::ENTITY, $pageId]
        );
        $data = $row !== null ? json_decode((string) $row['version_data'], true) : null;
        if (!is_array($data)) return false;
        self::snapshot($page, $userId, 'before_restore');
        self::write($pageId, [
            'meta_title'       => (string) ($data['meta_title'] ?? ''),
            'meta_description' => (string) ($data['meta_description'] ?? ''),
        ]);
        CacheService::flush($siteId);
        return true;
    }

    /** @param array<string,string> $state @return string[] */
    public static function errors(array $state): array
    {
        $errors = [];
        if ($state['meta_title'] === '') {
            $errors[] = __('asst.err.seo_empty');
        }
        if (mb_strlen($state['meta_title']) > self::TITLE_MAX) {
            $errors[] = __('page_ctrl.meta_title_long');
        }
        if (mb_strlen($state['meta_description']) > self::DESCRIPTION_MAX) {
            $errors[] = __('page_ctrl.meta_desc_long');
        }
        return $errors;
    }

    /** @param array<string,mixed> $page @return array{meta_title:string, meta_description:string} */
    public static function state(array $page): array
    {
        return [
            'meta_title'       => trim((string) ($page['meta_title'] ?? '')),
            'meta_description' => trim((string) ($page['meta_description'] ?? '')),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function page(int $siteId, int $pageId): ?array
    {
        return Database::selectOne(
            "SELECT id, title, slug, page_type, language, meta_title, meta_description, status
             FROM pages WHERE id = ? AND site_id = ? AND slug NOT LIKE '\\_\\_%' LIMIT 1",
            [$pageId, $siteId]
        );
    }

    /** @param array<string,mixed> $page */
    private static function snapshot(array $page, ?int $userId, string $reason): void
    {
        Database::execute(
            'INSERT INTO versions (entity_type, entity_id, version_data, created_by, reason) VALUES (?, ?, ?, ?, ?)',
            [
                self::ENTITY,
                (int) $page['id'],
                json_encode(self::state($page) + ['title' => (string) $page['title']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $userId,
                $reason,
            ]
        );
    }

    /** @param array<string,string> $state */
    private static function write(int $pageId, array $state): void
    {
        Database::execute(
            'UPDATE pages SET meta_title = ?, meta_description = ?, updated_at = NOW() WHERE id = ?',
            [$state['meta_title'] !== '' ? $state['meta_title'] : null, $state['meta_description'] !== '' ? $state['meta_description'] : null, $pageId]
        );
    }

    /** Texto visible de la página, para que la IA sepa de qué trata. */
    private static function pageText(int $pageId): string
    {
        $canvas = CanvasService::get($pageId);
        if ($canvas !== null && trim($canvas['html']) !== '') {
            $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $canvas['html']) ?? '';
            $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = '';
            foreach (Database::select("SELECT content FROM page_sections WHERE page_id = ? AND status != 'deleted' ORDER BY sort_order", [$pageId]) as $row) {
                $decoded = json_decode((string) $row['content'], true);
                if (!is_array($decoded)) continue;
                array_walk_recursive($decoded, static function ($value) use (&$text): void {
                    if (is_string($value) && !str_starts_with($value, 'http')) $text .= ' ' . strip_tags($value);
                });
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, self::CONTENT_CHARS);
    }

    /** @param array<string,mixed> $page */
    private static function publicUrl(array $page): string
    {
        return ($page['page_type'] ?? '') === 'home' ? base_url('') : base_url(ltrim((string) $page['slug'], '/'));
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    }
}
