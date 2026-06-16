<?php

namespace App\Controllers\Admin;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\BrandService;
use App\Services\CacheService;
use App\Services\DesignSystem;
use App\Services\DocumentSummarizer;
use App\Services\ImageBankService;
use App\Services\PostMetaService;
use App\Services\Renderer\SectionRenderer;
use App\Services\SeoIndexingService;
use App\Services\TextExtractor;
use App\Services\VisualStyleService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * F21.T21.1 — Gestión de entradas (blog).
 *
 * Decisión arquitectónica: una "entrada" es una `pages` row con
 * `page_type='article'`. Aquí no creamos otra tabla; en su lugar añadimos
 * UX cronológica adaptada y persistimos los metadatos blog-específicos
 * (excerpt, featured image, autor, reading time) en `post_meta`.
 *
 * Las entradas tienen editor dedicado (`/admin/posts/{id}/edit`) con bloques
 * editoriales (`article_body`). El editor genérico de páginas redirige aquí
 * para no mezclar artículos con Canvas ni con secciones de marketing.
 */
class PostController
{
    // ----------------------------------------------------------------------
    // GET /admin/posts — listado cronológico de entradas
    // ----------------------------------------------------------------------
    public function index(array $params = []): void
    {
        $siteId = self::requireSiteId();
        PostMetaService::ensureSchema();

        $filter = (string) Request::get('status', '');
        $whereStatus = '';
        $params = [$siteId];
        if ($filter === 'draft' || $filter === 'published') {
            $whereStatus = ' AND p.status = ?';
            $params[] = $filter;
        }

        $rows = Database::select(
            "SELECT p.id, p.title, p.slug, p.status, p.published_at, p.updated_at, p.created_at,
                    pm.excerpt, pm.featured_image_path, pm.featured_image_alt, pm.reading_minutes, pm.author_name
             FROM pages p
             LEFT JOIN post_meta pm ON pm.page_id = p.id
             WHERE p.site_id = ? AND p.page_type = 'article'" . $whereStatus . "
             ORDER BY
                CASE WHEN p.published_at IS NOT NULL THEN p.published_at ELSE p.updated_at END DESC,
                p.id DESC",
            $params
        );

        $countAll       = (int) (Database::selectOne("SELECT COUNT(*) AS c FROM pages WHERE site_id = ? AND page_type = 'article'", [$siteId])['c'] ?? 0);
        $countPublished = (int) (Database::selectOne("SELECT COUNT(*) AS c FROM pages WHERE site_id = ? AND page_type = 'article' AND status = 'published'", [$siteId])['c'] ?? 0);
        $countDraft     = $countAll - $countPublished;

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'posts'          => $rows,
            'filter'         => $filter,
            'countAll'       => $countAll,
            'countPublished' => $countPublished,
            'countDraft'     => $countDraft,
            'csrf'           => CSRF::token(),
        ]);
        View::send('admin/posts/index', $data);
    }

    // ----------------------------------------------------------------------
    // GET /admin/posts/new — pantalla "crear entrada" con 3 modos
    // ----------------------------------------------------------------------
    public function create(array $params = []): void
    {
        self::requireSiteId();
        $data = DashboardController::getCommonData();
        $data['csrf'] = CSRF::token();
        View::send('admin/posts/new', $data);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts — crear entrada en blanco (modo manual)
    //   body: title, excerpt?, author?
    // Crea page_type='article' + meta inicial, redirige al editor.
    // ----------------------------------------------------------------------
    public function store(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        PostMetaService::ensureSchema();

        $title = trim((string) Request::post('title', ''));
        if ($title === '') {
            Session::flash('error', 'Necesitamos un título para empezar.');
            Response::redirect(base_url('admin/posts/new'));
        }
        $excerpt = trim((string) Request::post('excerpt', ''));
        $author  = trim((string) Request::post('author_name', ''));

        $slug = PageController::uniqueSlug($siteId, slugify($title));
        $now = date('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                "INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, 'article', NULL, NULL, NULL, ?,
                    'draft', 0, ?, ?, ?, ?, NULL)",
                [
                    $siteId, $title, $slug,
                    $excerpt !== '' ? mb_substr($excerpt, 0, 155) : null,
                    PageController::nextTreeOrder($siteId, null),
                    Auth::id(), $now, $now,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            // T21.2.a — Sección article_body vacía. UNA por entrada, no editable
            // desde el editor genérico de secciones.
            Database::execute(
                "INSERT INTO page_sections
                    (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                 VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                [$pageId, json_encode(['blocks' => []], JSON_UNESCAPED_UNICODE), $now, $now]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'No se pudo crear la entrada: ' . $e->getMessage());
            Response::redirect(base_url('admin/posts/new'));
        }

        PostMetaService::save($pageId, [
            'excerpt'     => $excerpt,
            'author_name' => $author,
        ]);

        CacheService::invalidatePage($siteId, [
            'site_id' => $siteId, 'slug' => $slug, 'page_type' => 'article',
        ]);

        Session::flash('success', 'Entrada creada. Empieza a escribir cuando quieras.');
        Response::redirect(base_url('admin/posts/' . $pageId . '/edit'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/ai-create — generar entrada desde una idea con IA (T21.5)
    //   body: topic, audience, tone, length (corto|medio|largo), details
    //
    // Flujo:
    //   1. Llamar a Actions::GENERATE_ARTICLE → JSON con {title, excerpt, blocks}
    //   2. Crear page (article) + section article_body + post_meta en transacción
    //   3. Llamar a ImageBankService::pickFeaturedForPost para featured image auto
    //   4. Devolver page_id + edit_url
    // ----------------------------------------------------------------------
    public function aiCreate(array $params = []): void
    {
        @set_time_limit(180);
        CSRF::check();
        $siteId = self::requireSiteId();
        PostMetaService::ensureSchema();

        $topic    = trim((string) Request::post('topic', ''));
        $audience = trim((string) Request::post('audience', ''));
        $tone     = trim((string) Request::post('tone', ''));
        $length   = (string) Request::post('length', 'medio');
        $details  = trim((string) Request::post('details', ''));

        if ($topic === '') {
            Response::json(['ok' => false, 'error' => 'Cuéntanos sobre qué quieres escribir.'], 422);
        }
        if (!in_array($length, ['corto', 'medio', 'largo'], true)) $length = 'medio';

        $lengthLabels = ['corto' => 'corto', 'medio' => 'medio', 'largo' => 'largo'];

        try {
            $result = AIActionRunner::run(Actions::GENERATE_ARTICLE, [
                'topic'         => $topic,
                'audience'      => $audience !== '' ? $audience : 'lector general',
                'tone'          => $tone !== '' ? $tone : 'profesional y cercano',
                'length_label'  => $lengthLabels[$length],
                'details'       => $details !== '' ? $details : '(sin detalles adicionales)',
            ], $siteId);
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Error generando el artículo: ' . $e->getMessage()], 500);
        }

        $data = (array) ($result['data'] ?? []);
        $title   = trim((string) ($data['title'] ?? '')) ?: mb_substr($topic, 0, 160);
        $excerpt = trim((string) ($data['excerpt'] ?? ''));
        $blocks  = self::sanitizeBlocks(is_array($data['blocks'] ?? null) ? $data['blocks'] : []);

        if (empty($blocks)) {
            Response::json(['ok' => false, 'error' => 'La IA no devolvió bloques aplicables. Inténtalo de nuevo con más contexto.'], 422);
        }

        // Slug único basado en el título devuelto por la IA
        $slug = PageController::uniqueSlug($siteId, slugify($title));
        $now = date('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                "INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, 'article', NULL, NULL, NULL, ?,
                    'draft', 0, ?, ?, ?, ?, NULL)",
                [
                    $siteId, $title, $slug,
                    $excerpt !== '' ? mb_substr($excerpt, 0, 155) : null,
                    PageController::nextTreeOrder($siteId, null),
                    Auth::id(), $now, $now,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            Database::execute(
                "INSERT INTO page_sections
                    (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                 VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                [$pageId, json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok' => false, 'error' => 'Error guardando el artículo: ' . $e->getMessage()], 500);
        }

        // Reading time + meta inicial
        $text = self::plainTextFromBlocks($blocks);
        $readingMin = PostMetaService::estimateReadingMinutes($text);
        PostMetaService::save($pageId, [
            'excerpt'         => $excerpt,
            'reading_minutes' => $readingMin,
        ]);

        // Featured image automática (best-effort, no bloquea si falla).
        $imageApplied = false;
        $featuredPath = null;
        if (ImageBankService::isAvailable()) {
            try {
                $media = ImageBankService::pickFeaturedForPost($siteId, Auth::id(), $title, $excerpt, $text, 'landscape', 0);
                if ($media) {
                    $featuredPath = '/' . ltrim((string) $media['path'], '/');
                    PostMetaService::save($pageId, [
                        'excerpt'             => $excerpt,
                        'reading_minutes'     => $readingMin,
                        'featured_image_path' => $featuredPath,
                        'featured_image_alt'  => (string) ($media['alt_text'] ?? $title),
                    ]);
                    $imageApplied = true;
                }
            } catch (\Throwable $e) {
                error_log('[aiCreate] image pick error: ' . $e->getMessage());
            }
        }

        CacheService::invalidatePage($siteId, [
            'site_id' => $siteId, 'slug' => $slug, 'page_type' => 'article',
        ]);

        Session::flash('success', 'Entrada generada con IA. Revisa el resultado antes de publicar.');
        Response::json([
            'ok'            => true,
            'page_id'       => $pageId,
            'edit_url'      => base_url('admin/posts/' . $pageId . '/edit'),
            'preview_url'   => base_url('admin/posts/' . $pageId . '/preview-html'),
            'block_count'   => count($blocks),
            'image_applied' => $imageApplied,
            // FH11 — datos para la tarjeta de revisión sin recargar.
            'title'         => $title,
            'excerpt'       => $excerpt,
            'reading_minutes' => $readingMin,
            'featured_image_path' => $featuredPath !== null ? base_url(ltrim($featuredPath, '/')) : null,
            'ai_usage'      => [
                'tokens_in'      => (int) ($result['tokens_in'] ?? 0),
                'tokens_out'     => (int) ($result['tokens_out'] ?? 0),
                'estimated_cost' => (float) ($result['estimated_cost'] ?? 0),
                'model'          => (string) ($result['model'] ?? ''),
            ],
        ]);
    }

    /**
     * F22.T22.3 — Helper público para crear una entrada a partir del payload
     * JSON ya devuelto por `Actions::GENERATE_ARTICLE`. Usado por el onboarding
     * cuando el intent es "seo" y se generan entradas iniciales.
     *
     * @param array $payload  Debe contener al menos `title`, `excerpt`, `blocks`.
     * @return array|null     Row de pages insertada, o null si payload inválido.
     */
    public static function createPostFromAiPayload(
        int $siteId,
        ?int $userId,
        array $payload,
        bool $withFeaturedImage = true
    ): ?array {
        PostMetaService::ensureSchema();

        $title   = trim((string) ($payload['title'] ?? '')) ?: 'Entrada sin título';
        $excerpt = trim((string) ($payload['excerpt'] ?? ''));
        $blocks  = self::sanitizeBlocks(is_array($payload['blocks'] ?? null) ? $payload['blocks'] : []);
        if (empty($blocks)) return null;

        $slug = PageController::uniqueSlug($siteId, slugify($title));
        $now  = date('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                "INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, 'article', NULL, NULL, NULL, ?,
                    'draft', 0, ?, ?, ?, ?, NULL)",
                [
                    $siteId, $title, $slug,
                    $excerpt !== '' ? mb_substr($excerpt, 0, 155) : null,
                    PageController::nextTreeOrder($siteId, null),
                    $userId, $now, $now,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            Database::execute(
                "INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                 VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                [$pageId, json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[createPostFromAiPayload] DB error: ' . $e->getMessage());
            return null;
        }

        $text = self::plainTextFromBlocks($blocks);
        $readingMin = PostMetaService::estimateReadingMinutes($text);
        $meta = ['excerpt' => $excerpt, 'reading_minutes' => $readingMin];

        if ($withFeaturedImage && ImageBankService::isAvailable()) {
            try {
                $media = ImageBankService::pickFeaturedForPost($siteId, $userId, $title, $excerpt, $text, 'landscape', 0);
                if ($media) {
                    $meta['featured_image_path'] = '/' . ltrim((string) $media['path'], '/');
                    $meta['featured_image_alt']  = (string) ($media['alt_text'] ?? $title);
                }
            } catch (\Throwable $e) {
                error_log('[createPostFromAiPayload] image error: ' . $e->getMessage());
            }
        }
        PostMetaService::save($pageId, $meta);

        return [
            'id'    => $pageId,
            'title' => $title,
            'slug'  => $slug,
        ];
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/ai-suggest-related — sugerir entradas nuevas (T21.7)
    //   Mira las entradas publicadas + memoria del sitio, pide a la IA N propuestas
    //   y devuelve { ok, suggestions: [{title, angle, audience, why_now}, ...] }.
    //   No crea nada; el usuario elige luego una y la genera con aiCreate.
    // ----------------------------------------------------------------------
    public function aiSuggestRelated(array $params = []): void
    {
        @set_time_limit(60);
        CSRF::check();
        $siteId = self::requireSiteId();

        $count = max(3, min(8, (int) Request::post('count', 5)));

        // Snapshot de entradas existentes (todas, no solo published, para coherencia)
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

        // FH11 — enfoque/tema opcional para acotar las ideas.
        $focus = trim((string) Request::post('focus', ''));
        $focusLine = $focus !== ''
            ? "Enfócate especialmente en este tema o ángulo: \"" . mb_substr($focus, 0, 240) . "\".\n\n"
            : '';

        // FH11 — el modelo devuelve ocasionalmente un JSON sin propuestas
        // parseables (→ 422 transitorio). Reintentamos una vez antes de fallar.
        $suggestions = [];
        $result = null;
        $lastError = 'La IA no devolvió propuestas. Inténtalo de nuevo.';
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
            } catch (\Throwable $e) {
                Response::json(['ok' => false, 'error' => 'Error pidiendo sugerencias: ' . $e->getMessage()], 500);
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
            Response::json(['ok' => false, 'error' => $lastError], $lastStatus);
        }

        Response::json([
            'ok' => true,
            'suggestions' => $suggestions,
            'ai_usage' => [
                'tokens_in'      => (int) ($result['tokens_in'] ?? 0),
                'tokens_out'     => (int) ($result['tokens_out'] ?? 0),
                'estimated_cost' => (float) ($result['estimated_cost'] ?? 0),
                'model'          => (string) ($result['model'] ?? ''),
            ],
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/ai-create-from-document — generar entrada desde docs (T21.6/FH12)
    //   body: documents[] uploads o document_id legacy, angle, audience, tone, length
    //
    // Con uploads, guarda cada documento en la biblioteca, extrae texto síncrono
    // y combina las referencias. Sin uploads mantiene el flujo legacy por id.
    // ----------------------------------------------------------------------
    public function aiCreateFromDocument(array $params = []): void
    {
        @set_time_limit(180);
        CSRF::check();
        $siteId = self::requireSiteId();
        PostMetaService::ensureSchema();

        $documentId = (int) Request::post('document_id', 0);
        $angle      = trim((string) Request::post('angle', ''));
        $audience   = trim((string) Request::post('audience', ''));
        $tone       = trim((string) Request::post('tone', ''));
        $length     = (string) Request::post('length', 'medio');

        if (!in_array($length, ['corto', 'medio', 'largo'], true)) $length = 'medio';

        $referenceDocs = [];
        try {
            $uploaded = self::normalizeUploadedFiles($_FILES['documents'] ?? null);
            if (!empty($uploaded)) {
                if (count($uploaded) > 5) {
                    Response::json(['ok' => false, 'error' => 'Puedes subir un máximo de 5 documentos de referencia.'], 422);
                }
                foreach ($uploaded as $file) {
                    $referenceDocs[] = self::storeUploadedReferenceDocument($siteId, $file);
                }
            }
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo procesar el documento: ' . $e->getMessage()], 422);
        }

        if (empty($referenceDocs)) {
            if ($documentId <= 0) {
                Response::json(['ok' => false, 'error' => 'Sube al menos un documento de referencia.'], 422);
            }

            $doc = Database::selectOne(
                'SELECT id, title, extracted_text, status FROM documents WHERE id = ? AND site_id = ? LIMIT 1',
                [$documentId, $siteId]
            );
            if (!$doc) {
                Response::json(['ok' => false, 'error' => 'Documento no encontrado.'], 404);
            }
            if ($doc['status'] !== 'ready') {
                Response::json(['ok' => false, 'error' => 'El documento aún se está procesando o tuvo un error en la extracción. Vuelve a /admin/documents para revisarlo.'], 422);
            }
            $text = trim((string) ($doc['extracted_text'] ?? ''));
            if ($text === '') {
                Response::json(['ok' => false, 'error' => 'El documento no tiene texto extraído utilizable.'], 422);
            }
            $referenceDocs[] = [
                'id'    => (int) $doc['id'],
                'title' => (string) $doc['title'],
                'text'  => $text,
            ];
        }

        $extracted = self::composeReferenceDocumentText($referenceDocs);
        $sourceTitles = array_map(static fn(array $d): string => (string) $d['title'], $referenceDocs);
        $sourceLabel = implode(', ', array_slice($sourceTitles, 0, 3));
        if (count($sourceTitles) > 3) $sourceLabel .= ' +' . (count($sourceTitles) - 3);

        try {
            $result = AIActionRunner::run(Actions::GENERATE_ARTICLE_FROM_DOCUMENT, [
                'document_text' => $extracted,
                'angle'         => $angle !== '' ? $angle : '(sin ángulo específico — destila los puntos clave del documento)',
                'audience'      => $audience !== '' ? $audience : 'lector general',
                'tone'          => $tone !== '' ? $tone : 'profesional y cercano',
                'length_label'  => $length,
            ], $siteId);
        } catch (AIException $e) {
            Response::json([
                'ok' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Error generando el artículo: ' . $e->getMessage()], 500);
        }

        $data = (array) ($result['data'] ?? []);
        $title   = trim((string) ($data['title'] ?? '')) ?: mb_substr((string) ($sourceTitles[0] ?? 'Artículo desde documento'), 0, 160);
        $excerpt = trim((string) ($data['excerpt'] ?? ''));
        $blocks  = self::sanitizeBlocks(is_array($data['blocks'] ?? null) ? $data['blocks'] : []);

        if (empty($blocks)) {
            Response::json(['ok' => false, 'error' => 'La IA no devolvió bloques aplicables. El documento puede ser muy breve.'], 422);
        }

        $slug = PageController::uniqueSlug($siteId, slugify($title));
        $now  = date('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                "INSERT INTO pages
                    (site_id, title, slug, page_type, parent_id, nav_label, meta_title, meta_description,
                     status, sort_order, tree_sort_order, created_by, created_at, updated_at, published_at)
                 VALUES (?, ?, ?, 'article', NULL, NULL, NULL, ?,
                    'draft', 0, ?, ?, ?, ?, NULL)",
                [
                    $siteId, $title, $slug,
                    $excerpt !== '' ? mb_substr($excerpt, 0, 155) : null,
                    PageController::nextTreeOrder($siteId, null),
                    Auth::id(), $now, $now,
                ]
            );
            $pageId = (int) Database::lastInsertId();

            Database::execute(
                "INSERT INTO page_sections
                    (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                 VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                [$pageId, json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Response::json(['ok' => false, 'error' => 'Error guardando el artículo: ' . $e->getMessage()], 500);
        }

        $text = self::plainTextFromBlocks($blocks);
        $readingMin = PostMetaService::estimateReadingMinutes($text);
        PostMetaService::save($pageId, [
            'excerpt'         => $excerpt,
            'reading_minutes' => $readingMin,
        ]);

        // Featured image auto (best-effort).
        $imageApplied = false;
        if (ImageBankService::isAvailable()) {
            try {
                $media = ImageBankService::pickFeaturedForPost($siteId, Auth::id(), $title, $excerpt, $text, 'landscape', 0);
                if ($media) {
                    PostMetaService::save($pageId, [
                        'excerpt'             => $excerpt,
                        'reading_minutes'     => $readingMin,
                        'featured_image_path' => '/' . ltrim((string) $media['path'], '/'),
                        'featured_image_alt'  => (string) ($media['alt_text'] ?? $title),
                    ]);
                    $imageApplied = true;
                }
            } catch (\Throwable $e) {
                error_log('[aiCreateFromDocument] image pick error: ' . $e->getMessage());
            }
        }

        CacheService::invalidatePage($siteId, [
            'site_id' => $siteId, 'slug' => $slug, 'page_type' => 'article',
        ]);

        Session::flash('success', 'Entrada generada desde documentos de referencia. Revisa el resultado antes de publicar.');
        Response::json([
            'ok'            => true,
            'page_id'       => $pageId,
            'edit_url'      => base_url('admin/posts/' . $pageId . '/edit'),
            'block_count'   => count($blocks),
            'image_applied' => $imageApplied,
            'source_document' => $sourceLabel,
            'source_documents' => $sourceTitles,
            'ai_usage'      => [
                'tokens_in'      => (int) ($result['tokens_in'] ?? 0),
                'tokens_out'     => (int) ($result['tokens_out'] ?? 0),
                'estimated_cost' => (float) ($result['estimated_cost'] ?? 0),
                'model'          => (string) ($result['model'] ?? ''),
            ],
        ]);
    }

    /**
     * Normaliza un input `$_FILES` simple o múltiple a una lista de archivos.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeUploadedFiles($files): array
    {
        if (!is_array($files) || !isset($files['error'])) return [];

        if (is_array($files['error'])) {
            $out = [];
            foreach ($files['error'] as $i => $error) {
                if ((int) $error === UPLOAD_ERR_NO_FILE) continue;
                $out[] = [
                    'name'     => $files['name'][$i] ?? '',
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $error,
                    'size'     => $files['size'][$i] ?? 0,
                ];
            }
            return $out;
        }

        return ((int) $files['error'] === UPLOAD_ERR_NO_FILE) ? [] : [$files];
    }

    /**
     * Guarda un documento subido desde el flujo de artículo y devuelve su texto.
     *
     * @return array{id:int,title:string,text:string}
     */
    private static function storeUploadedReferenceDocument(int $siteId, array $file): array
    {
        $error = self::validateReferenceUpload($file);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        $type = self::detectReferenceType($file);
        if ($type === null) {
            throw new \RuntimeException('Tipo de archivo no soportado. Sube PDF, DOCX o TXT.');
        }

        $dir = DocumentController::documentsDir($siteId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de documentos.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $type;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('No se pudo guardar el archivo subido.');
        }

        $rel = 'storage/documents/' . $siteId . '/' . $name;
        $title = mb_substr(trim(pathinfo((string) $file['name'], PATHINFO_FILENAME)), 0, 255);
        if ($title === '') $title = 'Documento de referencia';

        Database::execute(
            'INSERT INTO documents (site_id, title, original_filename, file_type, file_path, status, uploaded_by)
             VALUES (?, ?, ?, ?, ?, "processing", ?)',
            [$siteId, $title, (string) $file['name'], $type, $rel, Auth::id()]
        );
        $docId = (int) Database::lastInsertId();

        try {
            $text = TextExtractor::extract($dest, $type);
            if (trim($text) === '') {
                throw new \RuntimeException('No hemos encontrado texto legible en el documento.');
            }
            $summary = DocumentSummarizer::summarize($text);
            Database::execute(
                'UPDATE documents SET extracted_text = ?, summary = ?, status = "ready" WHERE id = ?',
                [$text, $summary, $docId]
            );
        } catch (\Throwable $e) {
            Database::execute('UPDATE documents SET status = "error" WHERE id = ?', [$docId]);
            error_log('[aiCreateFromDocument] extraction failed doc ' . $docId . ': ' . $e->getMessage());
            throw $e;
        }

        return ['id' => $docId, 'title' => $title, 'text' => $text];
    }

    private static function validateReferenceUpload(array $file): ?string
    {
        $name = (string) ($file['name'] ?? 'archivo');
        switch ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $name . ': el archivo excede el tamaño máximo permitido.';
            case UPLOAD_ERR_PARTIAL:
                return $name . ': la subida se interrumpió. Inténtalo de nuevo.';
            case UPLOAD_ERR_NO_FILE:
                return $name . ': no se recibió el archivo.';
            default:
                return $name . ': error al subir el archivo.';
        }
        if ((int) ($file['size'] ?? 0) <= 0) {
            return $name . ': el archivo está vacío.';
        }
        if ((int) ($file['size'] ?? 0) > DocumentController::MAX_SIZE) {
            return $name . ': supera los ' . (DocumentController::MAX_SIZE / 1024 / 1024) . ' MB permitidos.';
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return $name . ': archivo subido no válido.';
        }
        if (self::detectReferenceType($file) === null) {
            return $name . ': tipo no soportado. Sube PDF, DOCX o TXT.';
        }
        return null;
    }

    private static function detectReferenceType(array $file): ?string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, (string) $file['tmp_name']) ?: null;
                finfo_close($finfo);
            }
        }
        if ($mime && isset(DocumentController::ALLOWED_MIME[$mime])) {
            return DocumentController::ALLOWED_MIME[$mime];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        return in_array($ext, DocumentController::ALLOWED_EXT, true) ? $ext : null;
    }

    private static function composeReferenceDocumentText(array $docs): string
    {
        $maxChars = 24000;
        $perDoc = max(4000, (int) floor($maxChars / max(1, count($docs))));
        $parts = [];
        $used = 0;

        foreach ($docs as $doc) {
            $title = trim((string) ($doc['title'] ?? 'Documento'));
            $text = trim((string) ($doc['text'] ?? ''));
            if ($text === '') continue;

            $remaining = $maxChars - $used;
            if ($remaining <= 0) break;
            $take = min($perDoc, $remaining, mb_strlen($text));
            $chunk = mb_substr($text, 0, $take);
            if (mb_strlen($text) > $take) {
                $chunk .= "\n\n[...documento truncado...]";
            }

            $part = "### Documento: " . $title . "\n" . $chunk;
            $parts[] = $part;
            $used += mb_strlen($part);
        }

        $combined = trim(implode("\n\n", $parts));
        if ($combined === '') {
            Response::json(['ok' => false, 'error' => 'Los documentos no tienen texto extraído utilizable.'], 422);
        }
        return $combined;
    }

    // ----------------------------------------------------------------------
    // GET /admin/posts/{id}/edit — editor dedicado de entrada (T21.2)
    // Carga la vista editorial dedicada y los bloques `article_body`.
    // ----------------------------------------------------------------------
    public function edit(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        // Cargar la sección article_body de esta entrada (debería ser 1).
        $section = Database::selectOne(
            "SELECT id, content FROM page_sections
             WHERE page_id = ? AND section_type = 'article_body' LIMIT 1",
            [$pageId]
        );
        $blocks = [];
        if ($section) {
            $decoded = json_decode((string) $section['content'], true);
            if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
                $blocks = $decoded['blocks'];
            }
        }

        $meta = PostMetaService::load($pageId);

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'page'     => $page,
            'meta'     => $meta,
            'blocks'   => $blocks,
            'sectionId' => $section ? (int) $section['id'] : 0,
            'csrf'     => CSRF::token(),
        ]);
        View::send('admin/posts/edit', $data);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/{id}/body — guardar el array de bloques del article_body
    //   body: blocks (JSON string)
    // Devuelve: { ok, reading_minutes }
    // ----------------------------------------------------------------------
    public function updateBody(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        $raw = (string) Request::post('blocks', '');
        $blocks = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($blocks)) {
            Response::json(['ok' => false, 'error' => 'Formato de bloques inválido.'], 422);
        }

        $blocks = self::sanitizeBlocks($blocks);
        $contentJson = json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Upsert: si por alguna razón no existe la sección article_body, la creamos.
        $section = Database::selectOne(
            "SELECT id FROM page_sections WHERE page_id = ? AND section_type = 'article_body' LIMIT 1",
            [$pageId]
        );
        $now = date('Y-m-d H:i:s');
        if ($section) {
            Database::execute(
                'UPDATE page_sections SET content = ?, updated_at = ? WHERE id = ?',
                [$contentJson, $now, (int) $section['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO page_sections
                    (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                 VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                [$pageId, $contentJson, $now, $now]
            );
        }

        // Opcional: actualizar título si el editor lo envía (autosave inline).
        $title = trim((string) Request::post('title', ''));
        if ($title !== '' && $title !== (string) $page['title']) {
            Database::execute(
                'UPDATE pages SET title = ?, updated_at = ? WHERE id = ?',
                [mb_substr($title, 0, 500), $now, $pageId]
            );
        } else {
            // Refrescar solo timestamp.
            Database::execute('UPDATE pages SET updated_at = ? WHERE id = ?', [$now, $pageId]);
        }
        $text = self::plainTextFromBlocks($blocks);
        $readingMin = PostMetaService::estimateReadingMinutes($text);
        PostMetaService::save($pageId, ['reading_minutes' => $readingMin]
            // Mantener el resto de meta intacto.
            + PostMetaService::load($pageId));

        CacheService::invalidatePage($siteId, $page);

        Response::json([
            'ok' => true,
            'reading_minutes' => $readingMin,
            'block_count' => count($blocks),
            'saved_at' => $now,
        ]);
    }

    /**
     * Sanea el array de bloques antes de persistir. Filtra tipos desconocidos
     * y limita longitudes razonables. La validación profunda (markdown, HTML)
     * NO se hace aquí — el editor JS asume control de la estructura.
     *
     * @param array<int,array> $blocks
     * @return array<int,array>
     */
    private static function sanitizeBlocks(array $blocks): array
    {
        $out = [];
        $allowed = ['paragraph', 'heading', 'image', 'list', 'quote', 'divider'];
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            $type = (string) ($b['type'] ?? '');
            if (!in_array($type, $allowed, true)) continue;
            $clean = ['type' => $type];
            switch ($type) {
                case 'paragraph':
                    $clean['text'] = mb_substr((string) ($b['text'] ?? ''), 0, 5000);
                    break;
                case 'heading':
                    $level = (int) ($b['level'] ?? 2);
                    $clean['level'] = ($level === 3) ? 3 : 2;
                    $clean['text'] = mb_substr((string) ($b['text'] ?? ''), 0, 300);
                    break;
                case 'image':
                    $clean['src']     = mb_substr((string) ($b['src'] ?? ''), 0, 500);
                    $clean['alt']     = mb_substr((string) ($b['alt'] ?? ''), 0, 255);
                    $clean['caption'] = mb_substr((string) ($b['caption'] ?? ''), 0, 500);
                    break;
                case 'list':
                    $style = (string) ($b['style'] ?? 'unordered');
                    $clean['style'] = ($style === 'ordered') ? 'ordered' : 'unordered';
                    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
                    $clean['items'] = array_values(array_filter(array_map(
                        fn($i) => mb_substr((string) $i, 0, 500),
                        $items
                    ), fn($i) => $i !== ''));
                    break;
                case 'quote':
                    $clean['text']        = mb_substr((string) ($b['text'] ?? ''), 0, 1000);
                    $clean['attribution'] = mb_substr((string) ($b['attribution'] ?? ''), 0, 200);
                    break;
                case 'divider':
                    // sin payload
                    break;
            }
            $out[] = $clean;
        }
        return $out;
    }

    /** Extrae texto plano del array de bloques (para reading time). */
    private static function plainTextFromBlocks(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            switch ($b['type'] ?? '') {
                case 'paragraph':
                case 'heading':
                case 'quote':
                    if (!empty($b['text'])) $parts[] = (string) $b['text'];
                    if (!empty($b['attribution'])) $parts[] = (string) $b['attribution'];
                    break;
                case 'list':
                    foreach ((array) ($b['items'] ?? []) as $it) {
                        if (is_string($it) && trim($it) !== '') $parts[] = $it;
                    }
                    break;
                case 'image':
                    if (!empty($b['caption'])) $parts[] = (string) $b['caption'];
                    break;
            }
        }
        return trim(implode("\n", $parts));
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/{id}/status — toggle borrador/publicado (T21.2.e)
    // ----------------------------------------------------------------------
    public function updateStatus(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        $next = (string) Request::post('status', '');
        if (!in_array($next, ['draft', 'published'], true)) {
            Response::json(['ok' => false, 'error' => 'Estado inválido.'], 422);
        }

        $publishedAt = $page['published_at'];
        if ($next === 'published' && empty($publishedAt)) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        Database::execute(
            'UPDATE pages SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
            [$next, $publishedAt, date('Y-m-d H:i:s'), $pageId]
        );

        CacheService::invalidatePage($siteId, $page);

        Response::json([
            'ok' => true,
            'status' => $next,
            'published_at' => $publishedAt,
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/{id}/meta — guardar metadatos desde el editor
    //   body: excerpt, meta_description, featured_image_path, featured_image_alt, author_name
    // ----------------------------------------------------------------------
    public function updateMeta(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        $meta = [
            'excerpt'             => trim((string) Request::post('excerpt', '')),
            'featured_image_path' => trim((string) Request::post('featured_image_path', '')),
            'featured_image_alt'  => trim((string) Request::post('featured_image_alt', '')),
            'author_name'         => trim((string) Request::post('author_name', '')),
        ];
        $metaDescription = mb_substr(trim((string) Request::post('meta_description', '')), 0, 500);
        $seoNoindex = Request::post('seo_noindex', '') === '1' ? 1 : 0;
        $seoExcludeSitemap = Request::post('seo_exclude_sitemap', '') === '1' ? 1 : 0;
        $canonicalUrl = SeoIndexingService::normalizeCanonical((string) Request::post('canonical_url', ''));
        if (trim((string) Request::post('canonical_url', '')) !== '' && $canonicalUrl === null) {
            Response::json(['ok' => false, 'error' => 'La canonical debe empezar por http:// o https://.'], 422);
        }

        PostMetaService::save($pageId, $meta);
        Database::execute(
            'UPDATE pages SET meta_description = ?, seo_noindex = ?, seo_exclude_sitemap = ?, canonical_url = ?, updated_at = NOW() WHERE id = ? AND site_id = ?',
            [$metaDescription !== '' ? $metaDescription : null, $seoNoindex, $seoExcludeSitemap, $canonicalUrl, $pageId, $siteId]
        );

        // Recalcular reading time desde las secciones actuales.
        $sections = Database::select(
            'SELECT content FROM page_sections WHERE page_id = ? ORDER BY sort_order',
            [$pageId]
        );
        $text = PostMetaService::plainTextFromSections($sections);
        $readingMin = PostMetaService::estimateReadingMinutes($text);
        PostMetaService::save($pageId, array_merge($meta, ['reading_minutes' => $readingMin]));

        CacheService::invalidatePage($siteId, $page);

        Response::json([
            'ok' => true,
            'reading_minutes' => $readingMin,
            'meta_description' => $metaDescription,
            'meta_description_effective' => $metaDescription !== '' ? $metaDescription : $meta['excerpt'],
            'seo_noindex' => $seoNoindex,
            'seo_exclude_sitemap' => $seoExcludeSitemap,
            'canonical_url' => $canonicalUrl,
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/{id}/featured/auto — sugiere featured image automática
    //   Compone query a partir de título + excerpt + texto del body.
    //   Busca y descarga 1 imagen del banco. Actualiza post_meta.featured_image_path.
    //   Devuelve { ok, media: {path, url, alt_text, attribution_name, ...} }.
    // ----------------------------------------------------------------------
    public function autoFeatured(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        if (!ImageBankService::isAvailable()) {
            Response::json(['ok' => false, 'error' => 'Banco de imágenes no configurado. Revisa la Access Key en config/config.php.'], 503);
        }

        // Recolectar contexto para componer la query
        $meta = PostMetaService::load($pageId);
        $title = (string) $page['title'];
        $excerpt = (string) ($meta['excerpt'] ?? '');

        // Sacar un poco de texto del body para enriquecer la query
        $sections = Database::select(
            "SELECT content FROM page_sections WHERE page_id = ? AND section_type = 'article_body' LIMIT 1",
            [$pageId]
        );
        $bodyText = '';
        if (!empty($sections)) {
            $decoded = json_decode((string) $sections[0]['content'], true);
            if (is_array($decoded) && isset($decoded['blocks'])) {
                $bodyText = self::plainTextFromBlocks($decoded['blocks']);
                $bodyText = mb_substr($bodyText, 0, 400);
            }
        }

        $attempt = max(0, (int) Request::post('attempt', 0));
        $media = ImageBankService::pickFeaturedForPost($siteId, Auth::id(), $title, $excerpt, $bodyText, 'landscape', $attempt);
        if (!$media) {
            Response::json(['ok' => false, 'error' => 'No hemos encontrado una imagen relevante. Prueba con la búsqueda manual.'], 404);
        }

        // Actualizar post_meta con la nueva featured image (y su alt).
        $newMeta = array_merge($meta, [
            'featured_image_path' => '/' . ltrim((string) $media['path'], '/'),
            'featured_image_alt'  => $meta['featured_image_alt'] !== ''
                ? $meta['featured_image_alt']
                : (string) ($media['alt_text'] ?? $title),
        ]);
        PostMetaService::save($pageId, $newMeta);

        CacheService::invalidatePage($siteId, $page);

        $path = '/' . ltrim((string) $media['path'], '/');
        Response::json([
            'ok' => true,
            'media' => [
                'id'               => (int) $media['id'],
                'path'             => $path,
                'url'              => base_url(ltrim($path, '/')),
                'alt_text'         => (string) ($media['alt_text'] ?? ''),
                'attribution_name' => (string) ($media['attribution_name'] ?? ''),
                'attribution_url'  => (string) ($media['attribution_url'] ?? ''),
            ],
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/posts/{id}/delete — borrar entrada (CASCADE limpia secciones y meta)
    // ----------------------------------------------------------------------
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$pageId, $siteId]);
        CacheService::invalidatePage($siteId, $page);

        // FH11 — descartar desde la tarjeta de revisión (sin recargar).
        if (Request::post('ajax', '') === '1') {
            Response::json(['ok' => true, 'id' => $pageId]);
        }

        Session::flash('success', 'Entrada eliminada.');
        Response::redirect(base_url('admin/posts'));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    // ----------------------------------------------------------------------
    // GET /admin/posts/{id}/preview-html — FH11
    // Render del artículo (aunque sea borrador) para la lectura en línea de la
    // revisión por lotes. Documento HTML completo (estilos de marca) pensado
    // para embeberse en un iframe aislado.
    // ----------------------------------------------------------------------
    public function previewHtml(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $pageId = (int) ($params['id'] ?? 0);
        $page = self::findArticleOrFail($pageId, $siteId);

        $sections = Database::select(
            "SELECT id, section_type, content, style, status FROM page_sections
             WHERE page_id = ? ORDER BY sort_order ASC, id ASC",
            [$pageId]
        );
        $meta = PostMetaService::load($pageId);
        $styleSlug = VisualStyleService::selectedForSite($siteId);
        SectionRenderer::setSiteContext($siteId);

        $title   = (string) $page['title'];
        $reading = (int) ($meta['reading_minutes'] ?? 0);
        $img     = trim((string) ($meta['featured_image_path'] ?? ''));
        $imgUrl  = $img !== '' ? (preg_match('#^https?://#i', $img) ? $img : base_url(ltrim($img, '/'))) : '';

        $hero  = '<header class="pp-section pp-article-hero" style="text-align:center;padding-top:2rem">';
        if ($imgUrl !== '') {
            $hero .= '<img src="' . e($imgUrl) . '" alt="" style="width:100%;max-height:340px;object-fit:cover;border-radius:12px;margin-bottom:1.5rem">';
        }
        $hero .= '<h1>' . e($title) . '</h1>';
        if ($reading > 0) {
            $hero .= '<p style="opacity:.7">' . (int) $reading . ' min de lectura</p>';
        }
        $hero .= '</header>';

        $body = SectionRenderer::renderMany($sections);

        $h  = '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="robots" content="noindex">';
        $h .= '<title>' . e($title) . ' — vista previa</title>';
        $h .= DesignSystem::renderHead($siteId, $styleSlug);
        $h .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $h .= '<main class="pp-article-page">' . $hero . $body . '</main>';
        $h .= '</body></html>';

        Response::html($h);
    }

    private static function findArticleOrFail(int $pageId, int $siteId): array
    {
        // Aceptamos tanto entradas de blog (article) como páginas legales (legal),
        // ya que ambas usan la misma estructura article_body de bloques editoriales.
        $row = Database::selectOne(
            "SELECT * FROM pages WHERE id = ? AND site_id = ? AND page_type IN ('article','legal') LIMIT 1",
            [$pageId, $siteId]
        );
        if (!$row) {
            Session::flash('error', 'Página no encontrada.');
            Response::redirect(base_url('admin/posts'));
        }
        return $row;
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
