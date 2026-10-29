<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\Admin\PostController;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use Core\Database;

/**
 * ASST-V2 F4c — El blog, desde el asistente.
 *
 * Reutiliza lo que ya hace /admin/posts/new: las ideas salen de
 * SUGGEST_RELATED_ARTICLES (mirando las entradas que ya hay) y cada entrada se
 * escribe con GENERATE_ARTICLE y se guarda en BORRADOR con
 * PostController::createPostFromAiPayload (imagen destacada incluida si hay
 * banco de imágenes). Nada se publica solo.
 */
final class BlogAssistant
{
    public const LENGTHS = ['corto', 'medio', 'largo'];
    public const MAX_POSTS_PER_PLAN = 5;
    private const DEFAULT_TONE = 'profesional y cercano';
    private const DEFAULT_AUDIENCE = 'lector general';

    /**
     * Ideas de entradas nuevas. Mismo comportamiento que el botón «Sugerir
     * ideas» del blog: un reintento si el modelo no devuelve propuestas.
     *
     * @return array{ok:bool, suggestions?:array<int,array<string,string>>, result?:array<string,mixed>, error?:string, status?:int}
     */
    public static function ideas(int $siteId, string $focus = '', int $count = 5): array
    {
        $count = max(3, min(8, $count));
        $posts = Database::select(
            "SELECT p.title, pm.excerpt
             FROM pages p LEFT JOIN post_meta pm ON pm.page_id = p.id
             WHERE p.site_id = ? AND p.page_type = 'article'
             ORDER BY COALESCE(p.published_at, p.updated_at) DESC
             LIMIT 50",
            [$siteId]
        );

        $existingCount = count($posts);
        if ($existingCount === 0) {
            // i18n-ignore: contexto que viaja a la IA.
            $existingList = '(El blog está vacío. Propón entradas pilares fundamentales del tema del negocio.)';
        } else {
            $lines = [];
            foreach ($posts as $i => $p) {
                $title = trim((string) $p['title']);
                $excerpt = trim((string) ($p['excerpt'] ?? ''));
                $lines[] = ($i + 1) . '. ' . $title . ($excerpt !== '' ? ' — ' . $excerpt : '');
            }
            $existingList = implode("\n", $lines);
        }

        $focus = trim($focus);
        $focusLine = $focus !== ''
            // i18n-ignore: instrucción para el modelo.
            ? "Enfócate especialmente en este tema o ángulo: \"" . mb_substr($focus, 0, 240) . "\".\n\n"
            : '';

        $suggestions = [];
        $result = null;
        $lastError = __('post_ctrl.no_proposals');
        $lastStatus = 422;
        for ($attempt = 1; $attempt <= 2 && empty($suggestions); $attempt++) {
            try {
                $result = AIActionRunner::run(Actions::SUGGEST_RELATED_ARTICLES, [
                    'existing_count' => (string) $existingCount,
                    'existing_posts' => $existingList,
                    'count'          => (string) $count,
                    'focus_line'     => $focusLine,
                ], $siteId);
            } catch (AIException $e) {
                $lastError = $e->getMessage();
                $st = $e->getHttpStatus();
                $lastStatus = ($st >= 400 && $st < 600) ? $st : 422;
                // 4xx propio del proveedor (auth, límite): no insistir.
                if ($st >= 400 && $st < 500 && $st !== 422 && $st !== 429) break;
                continue;
            }

            $data = (array) ($result['data'] ?? []);
            $raw = is_array($data['suggestions'] ?? null) ? $data['suggestions'] : [];
            foreach ($raw as $s) {
                if (!is_array($s)) continue;
                $title = trim((string) ($s['title'] ?? ''));
                if ($title === '') continue;
                $suggestions[] = [
                    'title'    => mb_substr($title, 0, 200),
                    'angle'    => mb_substr(trim((string) ($s['angle'] ?? '')), 0, 400),
                    'audience' => mb_substr(trim((string) ($s['audience'] ?? '')), 0, 200),
                    'why_now'  => mb_substr(trim((string) ($s['why_now'] ?? '')), 0, 400),
                ];
            }
        }

        if (empty($suggestions)) {
            return ['ok' => false, 'error' => $lastError, 'status' => $lastStatus];
        }
        return ['ok' => true, 'suggestions' => $suggestions, 'result' => (array) $result];
    }

    /** Instrucción del item posts.create que sale de una idea. @param array<string,string> $idea */
    public static function ideaInstruction(array $idea): string
    {
        // i18n-ignore-start: instrucción que se guarda en el trabajo y llega a la IA.
        $parts = ['Escribe la entrada «' . trim($idea['title'] ?? '') . '».'];
        if (trim($idea['angle'] ?? '') !== '') $parts[] = 'Enfoque: ' . trim($idea['angle']) . '.';
        if (trim($idea['audience'] ?? '') !== '') $parts[] = 'Público: ' . trim($idea['audience']) . '.';
        // i18n-ignore-end
        return implode(' ', $parts);
    }

    /** Extensión pedida en un texto libre, o «medio». */
    public static function lengthFrom(string $text, string $fallback = 'medio'): string
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b(corta|corto|breve|short)\b/u', $t) === 1) return 'corto';
        if (preg_match('/\b(larga|largo|extensa|extenso|long|completa)\b/u', $t) === 1) return 'largo';
        return in_array($fallback, self::LENGTHS, true) ? $fallback : 'medio';
    }

    /** Tema corto de una instrucción, para el título provisional. */
    public static function topicFrom(string $instruction): string
    {
        if (preg_match('/«([^»]{3,200})»/u', $instruction, $m) === 1) return trim($m[1]);
        $first = preg_split('/(?<=[.!?])\s/u', trim($instruction))[0] ?? $instruction;
        return mb_substr(trim($first), 0, 160);
    }

    /**
     * Escribe una entrada y la guarda en borrador.
     *
     * @return array{ok:bool, page_id?:int, reply?:string, error?:string}
     * @throws AIException si falla la llamada al modelo
     */
    public static function create(int $siteId, ?int $userId, string $topic, string $instruction, string $length): array
    {
        $length = in_array($length, self::LENGTHS, true) ? $length : 'medio';
        $result = AIActionRunner::run(Actions::GENERATE_ARTICLE, [
            'topic'        => $topic,
            'audience'     => self::DEFAULT_AUDIENCE,
            'tone'         => self::DEFAULT_TONE,
            'length_label' => $length,
            'details'      => $instruction,
        ], $siteId);

        $post = PostController::createPostFromAiPayload($siteId, $userId, (array) ($result['data'] ?? []));
        if ($post === null) {
            return ['ok' => false, 'error' => __('post_ctrl.no_blocks')];
        }
        return [
            'ok'      => true,
            'page_id' => (int) $post['id'],
            'reply'   => __('asst.post.created', ['titulo' => (string) $post['title']]),
        ];
    }

    /**
     * Lo que enseña la tarjeta de revisión del informe (mismos datos que la
     * de /admin/posts/new).
     *
     * @return array<string,mixed>|null
     */
    public static function card(int $siteId, int $pageId): ?array
    {
        $page = Database::selectOne(
            "SELECT id, title, status FROM pages WHERE id = ? AND site_id = ? AND page_type = 'article' LIMIT 1",
            [$pageId, $siteId]
        );
        if ($page === null) return null;
        $meta = PostMetaService::load($pageId);
        $img = trim((string) ($meta['featured_image_path'] ?? ''));
        return [
            'page_id'             => $pageId,
            'title'               => (string) $page['title'],
            'status'              => (string) $page['status'],
            'excerpt'             => (string) ($meta['excerpt'] ?? ''),
            'reading_minutes'     => (int) ($meta['reading_minutes'] ?? 0),
            'featured_image_path' => $img !== '' ? (preg_match('#^https?://#i', $img) ? $img : base_url(ltrim($img, '/'))) : null,
            'edit_url'            => base_url('admin/posts/' . $pageId . '/edit'),
            'preview_url'         => base_url('admin/posts/' . $pageId . '/preview-html'),
        ];
    }
}
