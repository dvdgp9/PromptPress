<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use RuntimeException;

/**
 * REF-HTML — Una maqueta HTML como referencia del onboarding.
 *
 * Los clientes traen su web hecha con una IA en un único .html «autocontenido»
 * y piden «lo más parecido a esto». Leer su CÓDIGO es más fiel que una captura:
 * el orden de las secciones, cuántos elementos tiene cada una y las columnas
 * vienen escritos, no hay que adivinarlos de una imagen.
 *
 * Dos reglas que no se negocian:
 *   - A la IA le llega una versión LIMPIA: sin scripts, sin imágenes base64 (en
 *     una maqueta autocontenida son el ~97 % del peso y no dicen nada de la
 *     estructura), sin comentarios ni atributos on*.
 *   - El .html subido NUNCA se guarda tal cual ni en `storage/uploads`, que es
 *     público: sería HTML del cliente servido desde el dominio del panel. Solo
 *     se guarda la versión limpia, como .txt, en `storage/documents` (cerrado).
 */
final class ReferenceHtml
{
    /** Tope de lo que se manda a la IA (~10k tokens). */
    public const MAX_CHARS = 40000;

    /** Tope del archivo subido: una maqueta autocontenida con fotos pesa ~1 MB. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Por debajo de esto no es una maqueta: no hay texto que estructurar. */
    private const MIN_TEXT = 120;

    private const EXTENSIONS = ['html', 'htm'];
    private const MIMES = ['text/html', 'text/plain', 'application/xhtml+xml'];

    /** ¿Es una subida HTML? Hacen falta las DOS cosas: extensión y tipo real. */
    public static function isHtmlUpload(string $name, string $mime): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::EXTENSIONS, true) && in_array(strtolower($mime), self::MIMES, true);
    }

    /** ¿Este item de la lista de referencias es una maqueta HTML? */
    public static function isHtmlItem(array $item): bool
    {
        return ($item['kind'] ?? '') === 'html' || ($item['mime'] ?? '') === 'text/html';
    }

    /** Versión para la IA: estructura, clases, textos y CSS; nada más. */
    public static function clean(string $raw): string
    {
        $html = self::toUtf8($raw);

        // Primero las imágenes incrustadas: son casi todo el peso.
        $html = preg_replace('#data:[a-z0-9.+/-]+;base64,[a-z0-9+/=\s]+#i', 'data:…', $html) ?? $html;

        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        $html = preg_replace('#<(script|noscript|iframe|object|embed|template)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|iframe|object|embed|meta|link|base)\b[^>]*>#i', '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#\ssrcset\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html) ?? $html;
        // Un icono SVG importa por estar ahí, no por su trazo.
        $html = preg_replace('#<svg\b([^>]*)>.*?</svg\s*>#is', '<svg$1></svg>', $html) ?? $html;

        $html = preg_replace('#[ \t]+#', ' ', $html) ?? $html;
        $html = preg_replace('#\s*\n\s*#', "\n", $html) ?? $html;
        $html = trim($html);

        if (mb_strlen($html) > self::MAX_CHARS) {
            $cut = mb_substr($html, 0, self::MAX_CHARS);
            $lastTag = mb_strrpos($cut, '>');
            $html = ($lastTag !== false && $lastTag > self::MAX_CHARS * 0.8)
                ? mb_substr($cut, 0, $lastTag + 1)
                : $cut;
        }
        return $html;
    }

    /**
     * Guarda la versión limpia y devuelve el item para la lista de referencias
     * del onboarding (`onboarding_visual_references`).
     *
     * @return array{path:string,original_name:string,mime:string,kind:string,size:int,created_at:string}
     */
    public static function store(int $siteId, string $raw, string $originalName): array
    {
        $clean = self::clean($raw);
        if (mb_strlen(self::visibleText($clean)) < self::MIN_TEXT) {
            throw new RuntimeException(__('onboarding.error.ref_html_empty', [
                'archivo' => mb_substr($originalName, 0, 80),
            ]));
        }

        $relDir = 'storage/documents/' . $siteId . '/references';
        $dir = PP_ROOT . '/' . $relDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(__('onboarding.error.ref_html_save'));
        }
        $filename = 'reference-' . bin2hex(random_bytes(10)) . '.txt';
        if (@file_put_contents($dir . '/' . $filename, $clean) === false) {
            throw new RuntimeException(__('onboarding.error.ref_html_save'));
        }

        return [
            'path' => $relDir . '/' . $filename,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime' => 'text/html',
            'kind' => 'html',
            'size' => strlen($clean),
            'created_at' => date('c'),
        ];
    }

    /**
     * La maqueta (limpia) de una lista de referencias, o '' si no hay.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function load(array $items): string
    {
        foreach ($items as $item) {
            if (!is_array($item) || !self::isHtmlItem($item)) continue;
            $path = PP_ROOT . '/' . ltrim((string) ($item['path'] ?? ''), '/');
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }
        return '';
    }

    private static function visibleText(string $html): string
    {
        $html = preg_replace('#<style\b[^>]*>.*?</style\s*>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
