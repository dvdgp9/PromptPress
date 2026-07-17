<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use Core\Database;

/**
 * Resuelve referencias escritas a secciones de otra página canvas del mismo
 * sitio. La página origen se usa únicamente como contexto visual para la IA.
 */
final class CanvasCrossPageReference
{
    private const HTML_BUDGET = 16000;
    private const CSS_BUDGET = 12000;

    /**
     * @param array<string,mixed> $currentPage
     * @return array{page_id:int,page_title:string,section_id:string,html:string,css:string,copy_content:bool}|null
     */
    public static function resolve(int $siteId, array $currentPage, string $instruction): ?array
    {
        if (!self::hasReferenceIntent($instruction)) {
            return null;
        }

        $pages = Database::select(
            "SELECT p.id, p.title, p.slug, pc.html, pc.css
             FROM pages p
             INNER JOIN page_canvas pc ON pc.page_id = p.id
             WHERE p.site_id = ? AND p.id <> ? AND p.render_mode = 'canvas'",
            [$siteId, (int) ($currentPage['id'] ?? 0)]
        );
        if ($pages === []) {
            return null;
        }

        $normalizedInstruction = self::normalize($instruction);
        $pageMatch = self::matchPage($pages, $normalizedInstruction);
        if ($pageMatch === null) {
            return null;
        }

        $sections = CanvasService::listSections((string) $pageMatch['html']);
        $section = self::matchSection(
            $sections,
            (string) $pageMatch['html'],
            $normalizedInstruction,
            (int) $pageMatch['_match_position']
        );
        if ($section === null) {
            return null;
        }

        $sectionHtml = CanvasService::extractSection((string) $pageMatch['html'], (string) $section['id']);
        if ($sectionHtml === null) {
            return null;
        }

        return [
            'page_id' => (int) $pageMatch['id'],
            'page_title' => (string) $pageMatch['title'],
            'section_id' => (string) $section['id'],
            'html' => mb_substr($sectionHtml, 0, self::HTML_BUDGET),
            'css' => mb_substr((string) $pageMatch['css'], 0, self::CSS_BUDGET),
            'copy_content' => self::requestsContentCopy($instruction),
        ];
    }

    /** @param array{page_title?:string,section_id?:string,html?:string,css?:string,copy_content?:bool} $reference */
    public static function promptBlock(array $reference): string
    {
        $copyContent = (bool) ($reference['copy_content'] ?? false);
        $contentRule = $copyContent
            ? 'El usuario ha pedido copiar también el contenido: puedes reutilizar los textos de la referencia y adaptarlos a la página actual.'
            : 'Usa la referencia solo para la estructura, composición y tratamiento visual; conserva el contenido y la intención de la sección actual. No copies sus textos.';

        return "\n\nREFERENCIA VISUAL DE OTRA PÁGINA (solo lectura):\n"
            . 'Página: ' . (string) ($reference['page_title'] ?? '') . "\n"
            . 'Sección: data-pp-section="' . (string) ($reference['section_id'] ?? '') . "\"\n"
            . $contentRule . "\n"
            . "Recrea el patrón con clases compatibles con la página actual. NO modifiques la página de referencia; solo devuelve cambios para la página actual.\n"
            . "HTML de referencia:\n<pp-reference-html>\n" . (string) ($reference['html'] ?? '') . "\n</pp-reference-html>\n"
            . "CSS de referencia:\n<pp-reference-css>\n" . (string) ($reference['css'] ?? '') . "\n</pp-reference-css>";
    }

    private static function hasReferenceIntent(string $instruction): bool
    {
        return (bool) preg_match(
            '/\b(?:igual\s+que|como\s+(?:la|el)|copi\w*|basad\w*\s+en|inspirad\w*\s+en|misma?\s+(?:estructura|dise[nñ]o|estilo))\b/iu',
            $instruction
        );
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>|null
     */
    private static function matchPage(array $pages, string $instruction): ?array
    {
        $matches = [];
        foreach ($pages as $page) {
            foreach ([(string) ($page['title'] ?? ''), (string) ($page['slug'] ?? '')] as $candidate) {
                $needle = self::normalize($candidate);
                $position = self::phrasePosition($instruction, $needle);
                if ($needle === '' || $position === null) {
                    continue;
                }
                $page['_match_position'] = $position;
                $page['_match_length'] = mb_strlen($needle);
                $matches[] = $page;
                break;
            }
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn(array $a, array $b): int => (int) $b['_match_length'] <=> (int) $a['_match_length']);
        return $matches[0];
    }

    /**
     * @param array<int,array{id:string,label:string}> $sections
     * @return array{id:string,label:string}|null
     */
    private static function matchSection(array $sections, string $pageHtml, string $instruction, int $pagePosition): ?array
    {
        $matches = [];
        foreach ($sections as $section) {
            $bestDistance = null;
            $bestLength = 0;
            $sectionHtml = CanvasService::extractSection($pageHtml, (string) $section['id']) ?? '';
            $candidates = [(string) $section['id'], (string) $section['label']];
            if (preg_match('/<h[1-3]\b[^>]*>(.*?)<\/h[1-3]>/isu', $sectionHtml, $heading)) {
                $candidates[] = html_entity_decode(strip_tags((string) $heading[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            foreach ($candidates as $candidate) {
                $needle = self::normalize($candidate);
                $position = self::phrasePosition($instruction, $needle);
                if ($needle === '' || $position === null) {
                    continue;
                }
                $distance = abs($pagePosition - ($position + mb_strlen($needle)));
                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestLength = mb_strlen($needle);
                }
            }
            if ($bestDistance !== null) {
                $matches[] = ['section' => $section, 'distance' => $bestDistance, 'length' => $bestLength];
            }
        }
        if ($matches === []) {
            return null;
        }
        usort($matches, static function (array $a, array $b): int {
            return $a['distance'] === $b['distance']
                ? $b['length'] <=> $a['length']
                : $a['distance'] <=> $b['distance'];
        });
        return $matches[0]['section'];
    }

    private static function requestsContentCopy(string $instruction): bool
    {
        return (bool) preg_match(
            '/\b(?:copia\w*|trae\w*)\b[^.]{0,45}\b(?:texto|textos|contenido)\b|\b(?:tambien|también|ademas|además)\b[^.]{0,25}\b(?:texto|textos|contenido)\b/iu',
            $instruction
        );
    }

    private static function phrasePosition(string $haystack, string $needle): ?int
    {
        if ($needle === '') return null;
        $position = mb_strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
        return $position === false ? null : max(0, $position - 1);
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
