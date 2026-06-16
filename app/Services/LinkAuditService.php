<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * Auditoría de enlaces internos (T-Links). Recorre el contenido de todas las
 * secciones buscando enlaces internos ("/algo") y los contrasta con las páginas
 * existentes para detectar destinos rotos:
 *   - 'missing': no existe ninguna página con ese slug.
 *   - 'draft':   la página existe pero está sin publicar (404 para visitantes).
 */
final class LinkAuditService
{
    /**
     * @return list<array<string,mixed>> issues encontrados
     */
    public static function audit(int $siteId): array
    {
        // Índice de páginas del sitio: slug => status, y si hay home publicada.
        $pages = Database::select(
            'SELECT slug, page_type, status FROM pages WHERE site_id = ?',
            [$siteId]
        );
        $bySlug = [];
        $homePublished = false;
        foreach ($pages as $p) {
            $bySlug[(string) $p['slug']] = (string) $p['status'];
            if (($p['page_type'] ?? '') === 'home' && $p['status'] === 'published') {
                $homePublished = true;
            }
        }

        $sections = Database::select(
            'SELECT s.id, s.section_type, s.content, p.id AS page_id, p.title AS page_title, p.slug AS page_slug
             FROM page_sections s
             JOIN pages p ON p.id = s.page_id
             WHERE p.site_id = ?
             ORDER BY p.title ASC, s.sort_order ASC',
            [$siteId]
        );

        $issues = [];
        foreach ($sections as $s) {
            $content = json_decode((string) $s['content'], true);
            if (!is_array($content)) {
                continue;
            }
            $links = [];
            self::collectLinks($content, $links);
            foreach (array_unique($links) as $link) {
                $problem = self::classify($link, $bySlug, $homePublished);
                if ($problem !== null) {
                    $issues[] = [
                        'page_id'      => (int) $s['page_id'],
                        'page_title'   => (string) $s['page_title'],
                        'page_slug'    => (string) $s['page_slug'],
                        'section_id'   => (int) $s['id'],
                        'section_type' => (string) $s['section_type'],
                        'link'         => $link,
                        'problem'      => $problem,
                    ];
                }
            }
        }
        return $issues;
    }

    /** Recoge recursivamente todos los strings que parecen enlaces internos. */
    private static function collectLinks(mixed $node, array &$out): void
    {
        if (is_array($node)) {
            foreach ($node as $v) {
                self::collectLinks($v, $out);
            }
            return;
        }
        if (is_string($node) && self::isNavLink($node)) {
            $out[] = $node;
        }
    }

    /** ¿Es un enlace de navegación interno (y no una ruta de asset como /storage/x.jpg)? */
    private static function isNavLink(string $s): bool
    {
        if (!str_starts_with($s, '/') || str_starts_with($s, '//')) {
            return false;
        }
        // Excluir rutas de assets/sistema y archivos con extensión.
        if (preg_match('#^/(storage|admin|assets)/#', $s)) {
            return false;
        }
        $path = preg_replace('/[?#].*$/', '', $s) ?? $s;
        if (preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
            return false; // termina en .jpg/.png/.pdf… → es un archivo, no navegación
        }
        return true;
    }

    /** Devuelve el tipo de problema ('missing'|'draft') o null si el enlace es válido. */
    private static function classify(string $link, array $bySlug, bool $homePublished): ?string
    {
        // Normaliza: quita query/hash y barra final.
        $path = preg_replace('/[?#].*$/', '', $link) ?? $link;
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return $homePublished ? null : 'missing';
        }
        $slug = ltrim($path, '/');
        if (!array_key_exists($slug, $bySlug)) {
            return 'missing';
        }
        return $bySlug[$slug] === 'published' ? null : 'draft';
    }
}
