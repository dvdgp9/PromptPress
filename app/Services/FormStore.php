<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * FormStore (FORMS F1) — gestión de formularios como entidades propias.
 *
 * Decisión de arquitectura: un formulario sigue siendo una sección
 * `page_sections` de tipo `form` (para no tocar el placeholder {{form:id}},
 * el envío de leads ni el RGPD). La novedad es que viven en una **página
 * contenedora oculta** por sitio (no publicada, fuera de nav/sitemap), de modo
 * que se gestionan de forma centralizada e independiente del árbol de páginas.
 *
 * La autorrespuesta y el destino del aviso se guardan en el JSON del propio
 * formulario (claves autoresponder_* y notify_email).
 */
final class FormStore
{
    /** Slug centinela de la página contenedora (único por sitio). */
    private const CONTAINER_SLUG = '__forms';

    /**
     * Devuelve (creándola si hace falta) el id de la página contenedora oculta
     * donde viven los formularios del sitio.
     */
    public static function containerPageId(int $siteId): int
    {
        $row = Database::selectOne(
            'SELECT id FROM pages WHERE site_id = ? AND slug = ? LIMIT 1',
            [$siteId, self::CONTAINER_SLUG]
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO pages
                (site_id, title, slug, page_type, status, seo_noindex, seo_exclude_sitemap, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$siteId, 'Formularios (sistema)', self::CONTAINER_SLUG, 'landing', 'draft', 1, 1, $now, $now]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Lista los formularios del sitio (resumen para el listado del panel).
     *
     * @return array<int,array{id:int,heading:string,field_count:int,updated_at:string}>
     */
    public static function all(int $siteId): array
    {
        $pageId = self::containerPageId($siteId);
        $rows = Database::select(
            "SELECT id, content, updated_at FROM page_sections
             WHERE page_id = ? AND section_type = 'form' AND status != 'deleted'
             ORDER BY sort_order ASC, id ASC",
            [$pageId]
        );
        $out = [];
        foreach ($rows as $row) {
            $content = json_decode((string) $row['content'], true);
            $content = is_array($content) ? $content : [];
            $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
            $out[] = [
                'id'          => (int) $row['id'],
                'heading'     => trim((string) ($content['heading'] ?? 'Formulario sin título')),
                'field_count' => count($fields),
                'updated_at'  => (string) $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Devuelve un formulario por id, verificando que pertenece a este sitio.
     *
     * @return array<string,mixed>|null  el content decodificado + id, o null
     */
    public static function find(int $siteId, int $formId): ?array
    {
        $pageId = self::containerPageId($siteId);
        $row = Database::selectOne(
            "SELECT id, content FROM page_sections
             WHERE id = ? AND page_id = ? AND section_type = 'form' AND status != 'deleted' LIMIT 1",
            [$formId, $pageId]
        );
        if ($row === null) {
            return null;
        }
        $content = json_decode((string) $row['content'], true);
        $content = is_array($content) ? $content : [];
        $content['id'] = (int) $row['id'];
        return $content;
    }

    /**
     * Crea un formulario desde una plantilla tipada del catálogo
     * (`FormTemplates`). Devuelve su id. Si la clave no existe, cae a contacto.
     */
    public static function createFromTemplate(int $siteId, string $templateKey): int
    {
        return self::create($siteId, FormTemplates::content($templateKey));
    }

    /**
     * Crea un formulario nuevo (por defecto, uno de contacto) y devuelve su id.
     *
     * @param array<string,mixed>|null $content content inicial; null = plantilla de contacto
     */
    public static function create(int $siteId, ?array $content = null): int
    {
        $pageId = self::containerPageId($siteId);
        $content ??= self::defaultContact();

        $last = Database::selectOne(
            'SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM page_sections WHERE page_id = ?',
            [$pageId]
        );
        $sortOrder = ((int) ($last['max_order'] ?? -1)) + 1;

        $now = date('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO page_sections
                (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pageId, 'form', $sortOrder,
                json_encode($content, JSON_UNESCAPED_UNICODE),
                null, 'editable', $now, $now,
            ]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Actualiza el content de un formulario (verifica pertenencia al sitio).
     *
     * @param array<string,mixed> $content
     */
    public static function update(int $siteId, int $formId, array $content): bool
    {
        $pageId = self::containerPageId($siteId);
        unset($content['id']);
        $affected = Database::execute(
            "UPDATE page_sections SET content = ?, updated_at = ?
             WHERE id = ? AND page_id = ? AND section_type = 'form'",
            [json_encode($content, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $formId, $pageId]
        );
        return $affected >= 0;
    }

    /**
     * Borrado SUAVE (FORMS-R T2): marca el formulario como `deleted` en vez de
     * eliminar la fila. Así sus respuestas (`form_submissions`, con FK
     * ON DELETE CASCADE a `page_sections`) se conservan. Desaparece del panel y
     * deja de renderizar/aceptar envíos en la web pública.
     */
    public static function delete(int $siteId, int $formId): bool
    {
        $pageId = self::containerPageId($siteId);
        $affected = Database::execute(
            "UPDATE page_sections SET status = 'deleted', updated_at = ?
             WHERE id = ? AND page_id = ? AND section_type = 'form' AND status != 'deleted'",
            [date('Y-m-d H:i:s'), $formId, $pageId]
        );
        return $affected > 0;
    }

    /**
     * Plantilla por defecto: un formulario de contacto sencillo y correcto en RGPD.
     * Delega en el catálogo tipado (`FormTemplates`) para no duplicar el schema.
     *
     * @return array<string,mixed>
     */
    public static function defaultContact(): array
    {
        return FormTemplates::content('contact');
    }
}
