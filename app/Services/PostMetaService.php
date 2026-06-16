<?php

namespace App\Services;

use Core\Database;

/**
 * F21.T21.1 — Metadatos específicos de entradas (blog).
 *
 * Cada entrada vive en `pages` con `page_type='article'`. Esta clase gestiona
 * la tabla auxiliar `post_meta` para los campos que NO encajan en `pages`:
 *   - excerpt (resumen para listado y SEO)
 *   - featured_image_path / featured_image_alt
 *   - reading_minutes (calculado al guardar contenido)
 *   - author_name (texto libre, no FK a users — permite invitados)
 *
 * Self-healing: `ensureSchema()` crea la tabla en runtime si falta — así no
 * obligamos a correr migraciones manuales en instalaciones existentes.
 */
final class PostMetaService
{
    /** @var bool|null cache estática del estado del schema */
    private static ?bool $schemaReady = null;

    /**
     * Crea la tabla `post_meta` si no existe. Idempotente y cacheado por request.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady === true) return;

        // Comprobamos vía information_schema (más fiable que SHOW TABLES en hostings raros).
        $row = Database::selectOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_meta' LIMIT 1"
        );
        if ($row) {
            self::$schemaReady = true;
            return;
        }

        Database::execute(
            "CREATE TABLE IF NOT EXISTS post_meta (
                page_id INT UNSIGNED NOT NULL PRIMARY KEY,
                excerpt VARCHAR(500) DEFAULT NULL,
                featured_image_path VARCHAR(500) DEFAULT NULL,
                featured_image_alt VARCHAR(255) DEFAULT NULL,
                reading_minutes SMALLINT UNSIGNED DEFAULT NULL,
                author_name VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_post_meta_page
                    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaReady = true;
    }

    /**
     * Carga los metadatos de una entrada. Devuelve un array con los defaults
     * si la fila no existe (la entrada puede existir en `pages` sin meta).
     *
     * @return array{
     *   excerpt:string, featured_image_path:string, featured_image_alt:string,
     *   reading_minutes:?int, author_name:string
     * }
     */
    public static function load(int $pageId): array
    {
        self::ensureSchema();
        $row = Database::selectOne(
            'SELECT excerpt, featured_image_path, featured_image_alt, reading_minutes, author_name
             FROM post_meta WHERE page_id = ? LIMIT 1',
            [$pageId]
        );
        return [
            'excerpt'             => (string) ($row['excerpt'] ?? ''),
            'featured_image_path' => (string) ($row['featured_image_path'] ?? ''),
            'featured_image_alt'  => (string) ($row['featured_image_alt'] ?? ''),
            'reading_minutes'     => isset($row['reading_minutes']) && $row['reading_minutes'] !== null
                ? (int) $row['reading_minutes'] : null,
            'author_name'         => (string) ($row['author_name'] ?? ''),
        ];
    }

    /**
     * UPSERT de los metadatos. Acepta solo las claves conocidas; ignora extras.
     * Acepta `null` o ausencia para limpiar campos opcionales.
     */
    public static function save(int $pageId, array $meta): void
    {
        self::ensureSchema();

        $excerpt    = self::nullableStr($meta['excerpt'] ?? null, 500);
        $imgPath    = self::nullableStr($meta['featured_image_path'] ?? null, 500);
        $imgAlt     = self::nullableStr($meta['featured_image_alt'] ?? null, 255);
        $readMin    = isset($meta['reading_minutes']) && is_numeric($meta['reading_minutes'])
            ? max(0, min(65535, (int) $meta['reading_minutes'])) : null;
        $authorName = self::nullableStr($meta['author_name'] ?? null, 120);

        Database::execute(
            'INSERT INTO post_meta
                (page_id, excerpt, featured_image_path, featured_image_alt, reading_minutes, author_name)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                excerpt = VALUES(excerpt),
                featured_image_path = VALUES(featured_image_path),
                featured_image_alt = VALUES(featured_image_alt),
                reading_minutes = VALUES(reading_minutes),
                author_name = VALUES(author_name)',
            [$pageId, $excerpt, $imgPath, $imgAlt, $readMin, $authorName]
        );
    }

    /**
     * Estima tiempo de lectura en minutos a partir del contenido textual.
     * Asume 220 palabras/min (lectura cómoda en pantalla). Mínimo 1 min.
     */
    public static function estimateReadingMinutes(string $text): int
    {
        $text = trim(strip_tags($text));
        if ($text === '') return 1;
        $words = preg_split('/\s+/u', $text) ?: [];
        $count = count($words);
        return max(1, (int) ceil($count / 220));
    }

    /**
     * Extrae texto plano del contenido de secciones (JSON) para estimar lectura
     * o generar excerpt automático.
     *
     * @param array<int,array> $sections rows de page_sections con `content` JSON.
     */
    public static function plainTextFromSections(array $sections): string
    {
        $parts = [];
        $keys = ['heading', 'subheading', 'body', 'description', 'quote', 'answer', 'title'];
        foreach ($sections as $s) {
            $content = $s['content'] ?? null;
            if (is_string($content)) {
                $content = json_decode($content, true);
            }
            if (!is_array($content)) continue;
            self::walkContent($content, $keys, $parts);
        }
        return trim(implode("\n", $parts));
    }

    /** Recorre recursivamente extrayendo strings asociadas a las claves conocidas. */
    private static function walkContent(array $content, array $keys, array &$out): void
    {
        foreach ($content as $k => $v) {
            if (is_array($v)) {
                self::walkContent($v, $keys, $out);
                continue;
            }
            if (!is_string($v) || trim($v) === '') continue;
            if (in_array($k, $keys, true) || is_int($k)) {
                $out[] = trim($v);
            }
        }
    }

    private static function nullableStr($value, int $maxLen): ?string
    {
        if ($value === null) return null;
        $s = is_string($value) ? trim($value) : '';
        if ($s === '') return null;
        return mb_substr($s, 0, $maxLen);
    }
}
