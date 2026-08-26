<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\Canvas\CanvasService;
use Core\Database;

/**
 * PromptPress — Traducción de páginas (I18N-FULL T5.2).
 *
 * Motor HÍBRIDO, decidido en el plan de la fase 5:
 *
 *   - **Literal** para lo que no admite creatividad: legales, artículos y
 *     contacto. Que la IA «mejore» un aviso legal es justo el riesgo que no
 *     queremos correr.
 *   - **Reescritura** nativa para lo que vende: home, servicios, landings,
 *     páginas de producto. Un titular traducido palabra por palabra suena plano
 *     en el idioma destino.
 *
 * La elección se refuerza sola en la validación: en literal se puede exigir que
 * la estructura sea idéntica (mismo número de secciones y de campos), y en
 * reescritura no, porque el copy cambia a propósito.
 *
 * Este servicio NO guarda nada: devuelve la traducción para que quien la pidió
 * decida (T5.3 la guarda como borrador).
 */
final class PageTranslator
{
    public const MODE_LITERAL = 'literal';
    public const MODE_REWRITE = 'rewrite';

    /**
     * Tipos de página que SÍ se reescriben. La lista es explícita a propósito:
     * la reescritura es el modo con más libertad —y por tanto más riesgo—, así
     * que se opta a él tipo por tipo. Cualquier otro tipo, incluido uno que no
     * conozcamos, se traduce con fidelidad.
     */
    private const REWRITE_TYPES = ['home', 'service', 'landing', 'product'];

    public static function modeFor(string $pageType): string
    {
        return in_array(strtolower(trim($pageType)), self::REWRITE_TYPES, true)
            ? self::MODE_REWRITE
            : self::MODE_LITERAL;
    }

    /**
     * Traduce una página canvas (HTML libre).
     *
     * @param array<string,mixed> $page Fila de `pages`
     * @return array{ok:bool, html?:string, meta_title?:string, meta_description?:string, message?:string}
     */
    public static function translateCanvas(int $siteId, array $page, string $targetLang): array
    {
        $canvas = CanvasService::get((int) $page['id']);
        $html = trim((string) ($canvas['html'] ?? ''));
        if ($html === '') {
            return ['ok' => false, 'message' => __('tr.err.empty_page')];
        }

        $mode = self::modeFor((string) ($page['page_type'] ?? ''));

        $result = AIActionRunner::run(Actions::TRANSLATE_PAGE_CANVAS, [
            'language'         => LanguageService::promptLabel($targetLang),
            'translation_mode' => self::modeDirective($mode),
            'page_title'       => (string) ($page['title'] ?? ''),
            'page_html'        => $html,
            'meta_title'       => (string) ($page['meta_title'] ?? ''),
            'meta_description' => (string) ($page['meta_description'] ?? ''),
        ], $siteId);

        $parsed = self::parseEnvelope((string) ($result['data'] ?? ''));
        if ($parsed['html'] === '') {
            return ['ok' => false, 'message' => self::friendlyFailure()];
        }

        $check = self::validateCanvas($html, $parsed['html'], $mode);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message']];
        }

        return [
            'ok'               => true,
            // El título de la página es lo que se ve en el menú del sitio: si no
            // se traduce, la web francesa muestra un menú en castellano.
            'title'            => $parsed['title'] !== '' ? $parsed['title'] : (string) ($page['title'] ?? ''),
            'html'             => self::rewriteInternalLinks($siteId, $parsed['html'], $targetLang),
            'meta_title'       => $parsed['meta_title'],
            'meta_description' => $parsed['meta_description'],
        ];
    }

    /**
     * Traduce las secciones tipadas de una página (JSON por sección).
     *
     * @param array<string,mixed> $page
     * @return array{ok:bool, sections?:array<int,array{id:int,content:string}>, meta_title?:string, meta_description?:string, message?:string}
     */
    public static function translateSections(int $siteId, array $page, string $targetLang): array
    {
        $rows = Database::select(
            'SELECT id, section_type, content FROM page_sections WHERE page_id = ? ORDER BY sort_order ASC, id ASC',
            [(int) $page['id']]
        );
        if ($rows === []) {
            return ['ok' => false, 'message' => __('tr.err.empty_page')];
        }

        $mode = self::modeFor((string) ($page['page_type'] ?? ''));

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'id'      => (int) $row['id'],
                'type'    => (string) $row['section_type'],
                'content' => json_decode((string) $row['content'], true) ?: [],
            ];
        }

        $result = AIActionRunner::run(Actions::TRANSLATE_PAGE_SECTIONS, [
            'language'         => LanguageService::promptLabel($targetLang),
            'translation_mode' => self::modeDirective($mode),
            'page_title'       => (string) ($page['title'] ?? ''),
            'sections_json'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta_title'       => (string) ($page['meta_title'] ?? ''),
            'meta_description' => (string) ($page['meta_description'] ?? ''),
        ], $siteId);

        $data = $result['data'] ?? null;
        if (!is_array($data) || !isset($data['sections']) || !is_array($data['sections'])) {
            return ['ok' => false, 'message' => self::friendlyFailure()];
        }

        // Solo se aceptan secciones que existían: la traducción no inventa
        // bloques nuevos ni cambia el orden.
        $byId = [];
        foreach ($payload as $original) {
            $byId[$original['id']] = $original;
        }

        $out = [];
        foreach ($data['sections'] as $translated) {
            $id = (int) ($translated['id'] ?? 0);
            if (!isset($byId[$id]) || !is_array($translated['content'] ?? null)) {
                continue;
            }
            // Las claves son el esquema de la sección: se conservan las del
            // original y solo se sustituyen los valores de texto.
            $merged = self::mergeTranslatedContent($byId[$id]['content'], $translated['content']);
            $out[] = [
                'id'      => $id,
                'content' => json_encode(
                    self::rewriteLinksInArray($siteId, $merged, $targetLang),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }

        if (count($out) < count($payload)) {
            return ['ok' => false, 'message' => self::partialFailure(count($out), count($payload))];
        }

        return [
            'ok'               => true,
            'title'            => trim((string) ($data['title'] ?? '')) !== ''
                ? trim((string) $data['title'])
                : (string) ($page['title'] ?? ''),
            'sections'         => $out,
            'meta_title'       => trim((string) ($data['meta_title'] ?? '')),
            'meta_description' => trim((string) ($data['meta_description'] ?? '')),
        ];
    }

    // =====================================================================
    // Validación
    // =====================================================================

    /**
     * Comprueba que la traducción no ha destrozado la página.
     *
     * En LITERAL se exige la misma estructura: mismo número de secciones y de
     * campos editables. En REESCRITURA el copy cambia a propósito, así que solo
     * se exige que no desaparezcan secciones enteras.
     *
     * @return array{ok:bool, message?:string}
     */
    public static function validateCanvas(string $source, string $translated, string $mode): array
    {
        $sectionsBefore = self::countMatches('/<section\b/i', $source);
        $sectionsAfter  = self::countMatches('/<section\b/i', $translated);
        $fieldsBefore   = self::countMatches('/data-pp-field=/i', $source);
        $fieldsAfter    = self::countMatches('/data-pp-field=/i', $translated);

        if ($sectionsAfter < $sectionsBefore) {
            return [
                'ok' => false,
                'message' => __('tr.err.lost_sections'),
            ];
        }

        if ($mode === self::MODE_LITERAL && $fieldsAfter !== $fieldsBefore) {
            return [
                'ok' => false,
                'message' => __('tr.err.changed_content'),
            ];
        }

        // Incluso reescribiendo, una página que se queda en nada es un fallo.
        if ($sectionsBefore > 0 && $sectionsAfter === 0) {
            return [
                'ok' => false,
                'message' => __('tr.err.empty_result'),
            ];
        }
        if ($sectionsBefore === 0 && mb_strlen(strip_tags($translated)) < mb_strlen(strip_tags($source)) / 4) {
            return [
                'ok' => false,
                'message' => __('tr.err.too_short'),
            ];
        }

        return ['ok' => true];
    }

    // =====================================================================
    // Enlaces internos
    // =====================================================================

    /**
     * Reescribe los enlaces internos al idioma destino.
     *
     * Una página traducida que enlaza a `/contacto` mandaría al visitante
     * francés de vuelta al castellano. Si la página destino NO está traducida,
     * se deja el enlace original: mejor uno que funciona en otro idioma que uno
     * roto. Los enlaces externos y las anclas no se tocan.
     */
    public static function rewriteInternalLinks(int $siteId, string $html, string $targetLang): string
    {
        return (string) preg_replace_callback(
            '/href="(\/[^"#][^"]*)"/i',
            static function (array $m) use ($siteId, $targetLang): string {
                $slug = trim($m[1], '/');
                $target = self::translatedSlug($siteId, $slug, $targetLang);
                return 'href="/' . ($target ?? $slug) . '"';
            },
            $html
        );
    }

    /** Slug de la versión en `$targetLang` de la página con slug `$slug`, o null. */
    private static function translatedSlug(int $siteId, string $slug, string $targetLang): ?string
    {
        if ($slug === '') {
            return null;
        }
        try {
            $row = Database::selectOne(
                "SELECT t.slug FROM pages p
                 JOIN pages t ON t.site_id = p.site_id AND t.translation_group = p.translation_group
                 WHERE p.site_id = ? AND p.slug = ? AND t.language = ? AND t.status = 'published'
                 LIMIT 1",
                [$siteId, $slug, LanguageService::normalize($targetLang)]
            );
        } catch (\Throwable $e) {
            return null;
        }
        $found = trim((string) ($row['slug'] ?? ''));
        return $found !== '' ? $found : null;
    }

    /**
     * Reescribe enlaces dentro de un array de contenido de sección (recursivo).
     *
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private static function rewriteLinksInArray(int $siteId, array $content, string $targetLang): array
    {
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $content[$key] = self::rewriteLinksInArray($siteId, $value, $targetLang);
                continue;
            }
            if (!is_string($value) || !str_starts_with($value, '/')) {
                continue;
            }
            $slug = trim($value, '/');
            $target = self::translatedSlug($siteId, $slug, $targetLang);
            if ($target !== null) {
                $content[$key] = '/' . $target;
            }
        }
        return $content;
    }

    // =====================================================================
    // Internos
    // =====================================================================

    /** Directiva de modo que se inyecta en el prompt. */
    // i18n-ignore-start: no es interfaz, es la instrucción que viaja a la IA.
    private static function modeDirective(string $mode): string
    {
        if ($mode === self::MODE_LITERAL) {
            return 'TRADUCCIÓN FIEL. Traduce el texto respetando su significado exacto, sin reformular, '
                 . 'sin acortar y sin cambiar el tono. Es contenido donde la precisión importa más que el estilo '
                 . '(textos legales, artículos, datos de contacto). Mantén TODOS los datos: cifras, fechas, '
                 . 'nombres propios, direcciones, importes y referencias legales, tal cual.';
        }

        return 'ADAPTACIÓN NATIVA. No traduzcas palabra por palabra: reescribe el texto como lo escribiría '
             . 'alguien nativo del idioma destino, conservando el MISMO mensaje, los mismos argumentos y la '
             . 'misma estructura de la página. Los titulares deben sonar naturales en el idioma destino, no a '
             . 'traducción. PROHIBIDO añadir hechos, cifras, servicios o promesas que no estén en el original.';
    }

    /**
     * Sustituye solo los valores de texto, conservando las claves del esquema
     * original. Si la IA se inventa claves nuevas, se ignoran.
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $translated
     * @return array<string,mixed>
     */
    private static function mergeTranslatedContent(array $original, array $translated): array
    {
        foreach ($original as $key => $value) {
            if (!array_key_exists($key, $translated)) {
                continue;
            }
            if (is_array($value) && is_array($translated[$key])) {
                $original[$key] = self::mergeTranslatedContent($value, $translated[$key]);
            } elseif (is_string($value) && is_string($translated[$key])) {
                $original[$key] = $translated[$key];
            }
        }
        return $original;
    }
    // i18n-ignore-end

    /** @return array{title:string, html:string, meta_title:string, meta_description:string} */
    private static function parseEnvelope(string $raw): array
    {
        $grab = static function (string $tag) use ($raw): string {
            if (preg_match('#<pp-' . $tag . '>(.*?)</pp-' . $tag . '>#s', $raw, $m) === 1) {
                return trim($m[1]);
            }
            return '';
        };

        return [
            'title'            => $grab('title'),
            'html'             => $grab('html'),
            'meta_title'       => $grab('meta-title'),
            'meta_description' => $grab('meta-description'),
        ];
    }

    private static function countMatches(string $pattern, string $subject): int
    {
        return (int) preg_match_all($pattern, $subject);
    }

    /** Mensajes de error pensados para alguien que no sabe qué es un `<section>`. */
    private static function friendlyFailure(): string
    {
        return __('tr.err.friendly');
    }

    private static function partialFailure(int $done, int $total): string
    {
        return __('tr.err.partial', ['hechos' => (string) $done, 'total' => (string) $total]);
    }
}
