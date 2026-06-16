<?php

declare(strict_types=1);

namespace App\Services\Canvas;

/**
 * FH1 — Sanitizador del modo "Canvas" (HTML libre por página).
 *
 * Filosofía OPUESTA al CustomBlockSanitizer (whitelist estricta): aquí la IA
 * diseña libre y solo se bloquea lo peligroso o lo que rompe el contrato:
 *  - Nada ejecutable: script, iframes, handlers on*, URLs javascript:/data:.
 *  - Nada de formularios crudos (los reales se insertan vía {{form:...}}).
 *  - CSS por página permitido pero saneado y SIEMPRE scoped a la página.
 *  - Estructura mínima: el nivel superior son <section data-pp-section="...">
 *    (ancla del chat, de la edición por sección y del undo parcial).
 */
final class CanvasSanitizer
{
    /** Etiquetas que se eliminan con todo su contenido. */
    private const DROP_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 'frame', 'frameset',
        'link', 'meta', 'base', 'form', 'input', 'select', 'textarea', 'option',
        'audio', 'video', 'source', 'track', 'template', 'slot', 'portal',
        'foreignobject',
    ];

    /** Etiquetas que se eliminan conservando sus hijos. */
    private const UNWRAP_TAGS = ['html', 'head', 'body', 'noscript'];

    /** Emojis/pictogramas: prohibidos en la generación (política de producto). */
    private const EMOJI_REGEX = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{200D}\x{20E3}]/u';

    /**
     * @return array{html:string,css:string,warnings:array<int,string>}
     *         `css` es el CSS extraído de <style> embebidos (sin scope aún).
     */
    public static function sanitizeHtml(string $html): array
    {
        $warnings = [];
        $extractedCss = '';

        $html = trim(self::stripControlChars($html));
        if ($html === '') {
            return ['html' => '', 'css' => '', 'warnings' => ['empty_html']];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div data-pp-canvas-root="1">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return ['html' => '', 'css' => '', 'warnings' => ['parse_error']];
        }

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-pp-canvas-root') === '1') { $root = $div; break; }
        }
        if (!$root) {
            return ['html' => '', 'css' => '', 'warnings' => ['parse_error']];
        }

        self::walk($root, $warnings, $extractedCss);
        self::ensureSectionStructure($doc, $root, $warnings);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ['html' => trim($out), 'css' => trim($extractedCss), 'warnings' => $warnings];
    }

    private static function walk(\DOMNode $parent, array &$warnings, string &$extractedCss): void
    {
        $children = [];
        foreach ($parent->childNodes as $c) $children[] = $c;

        foreach ($children as $node) {
            if ($node instanceof \DOMComment) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            if ($node instanceof \DOMText) {
                $clean = preg_replace(self::EMOJI_REGEX, '', $node->textContent) ?? $node->textContent;
                if ($clean !== $node->textContent) $warnings[] = 'emoji_removed';
                // Anti-slop: la raya larga (—/–) es el tell nº1 de texto-IA.
                // Se normaliza a guion con espacios. Determinista, no depende
                // de que el modelo obedezca el prompt.
                $dashed = preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ' - ', $clean) ?? $clean;
                if ($dashed !== $clean) $warnings[] = 'emdash_normalized';
                if ($dashed !== $node->textContent) $node->nodeValue = $dashed;
                continue;
            }
            if (!$node instanceof \DOMElement) continue;

            $tag = strtolower($node->tagName);

            if ($tag === 'style') {
                // El CSS embebido no se pierde: se extrae al canal CSS de la
                // página (que luego se sanea y se scopea).
                $extractedCss .= "\n" . $node->textContent;
                $node->parentNode?->removeChild($node);
                continue;
            }
            if (in_array($tag, self::DROP_TAGS, true)) {
                $warnings[] = 'dropped:' . $tag;
                $node->parentNode?->removeChild($node);
                continue;
            }
            if (in_array($tag, self::UNWRAP_TAGS, true)) {
                while ($node->firstChild) {
                    $node->parentNode?->insertBefore($node->firstChild, $node);
                }
                $node->parentNode?->removeChild($node);
                // Los hijos recolocados se procesan en la pasada del padre:
                // re-lanzamos sobre el padre para no saltarlos.
                self::walk($parent, $warnings, $extractedCss);
                return;
            }

            self::sanitizeAttributes($node, $warnings);
            self::walk($node, $warnings, $extractedCss);
        }
    }

    private static function sanitizeAttributes(\DOMElement $node, array &$warnings): void
    {
        $names = [];
        foreach ($node->attributes as $attr) $names[] = $attr->name;

        foreach ($names as $name) {
            $low = strtolower($name);
            $value = (string) $node->getAttribute($name);

            if (str_starts_with($low, 'on') || in_array($low, ['srcdoc', 'formaction', 'xlink:href', 'contenteditable'], true)) {
                $node->removeAttribute($name);
                $warnings[] = 'attr:' . $low;
                continue;
            }

            if (in_array($low, ['href', 'src', 'poster', 'cite', 'action', 'data'], true)) {
                if (!self::isSafeUrl($value)) {
                    $node->removeAttribute($name);
                    $warnings[] = 'url:' . $low;
                }
                continue;
            }

            if ($low === 'style') {
                $clean = self::scrubInlineStyle($value);
                if ($clean === '') {
                    $node->removeAttribute($name);
                } elseif ($clean !== $value) {
                    $node->setAttribute($name, $clean);
                }
                continue;
            }
        }
    }

    /**
     * Garantiza el contrato estructural: los hijos de primer nivel son
     * <section data-pp-section="..."> con ids únicos. Nodos sueltos de primer
     * nivel se envuelven en una sección propia.
     */
    private static function ensureSectionStructure(\DOMDocument $doc, \DOMElement $root, array &$warnings): void
    {
        $children = [];
        foreach ($root->childNodes as $c) $children[] = $c;

        $pending = null; // sección-envoltorio para nodos sueltos consecutivos
        foreach ($children as $node) {
            $isSection = $node instanceof \DOMElement && strtolower($node->tagName) === 'section';
            if ($isSection) {
                $pending = null;
                continue;
            }
            if ($node instanceof \DOMText && trim($node->textContent) === '') continue;

            if ($pending === null) {
                $pending = $doc->createElement('section');
                $root->insertBefore($pending, $node);
                $warnings[] = 'wrapped_loose_node';
            }
            $pending->appendChild($node);
        }

        // Ids únicos y presentes.
        $seen = [];
        $i = 0;
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement || strtolower($node->tagName) !== 'section') continue;
            $i++;
            $id = trim($node->getAttribute('data-pp-section'));
            $id = preg_match('/^[a-z0-9][a-z0-9\-]{0,60}$/', $id) ? $id : '';
            if ($id === '' || isset($seen[$id])) {
                $id = 'sec-' . $i;
                while (isset($seen[$id])) $id .= 'b';
                $node->setAttribute('data-pp-section', $id);
            }
            $seen[$id] = true;
        }
    }

    // ==================================================================
    // CSS
    // ==================================================================

    /**
     * Sanea el CSS de página y lo scopea bajo $scope (p. ej. `#pp-canvas-12`).
     * Soporta @media/@supports (recursivo); @keyframes se conserva tal cual;
     * @import/@charset/@font-face se eliminan.
     */
    public static function sanitizeCss(string $css, string $scope): string
    {
        return trim(self::scopeBlock(self::cleanCss($css), $scope));
    }

    /** Limpieza sin scoping (lo que se persiste; el scope se aplica en render). */
    public static function cleanCss(string $css): string
    {
        $css = self::stripControlChars($css);
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/@(import|charset)[^;]*;/i', '', $css) ?? $css;
        return self::compactCss(trim(self::scrubCssDeclarations($css)));
    }

    /**
     * FH6 — Compactación segura del CSS acumulado por el chat (css_append):
     * elimina bloques EXACTAMENTE duplicados (mismo selector y mismo cuerpo,
     * normalizados) conservando la ÚLTIMA aparición. Conservar la última y
     * borrar las anteriores no puede alterar la cascada; fusiones más
     * agresivas sí podrían, así que no se hacen.
     */
    public static function compactCss(string $css): string
    {
        $blocks = [];
        $len = strlen($css);
        $pos = 0;
        while ($pos < $len) {
            $brace = strpos($css, '{', $pos);
            if ($brace === false) break;
            $selector = trim(substr($css, $pos, $brace - $pos));
            $end = self::findBlockEnd($css, $brace);
            if ($end === -1) break;
            $body = substr($css, $brace + 1, $end - $brace - 1);
            $pos = $end + 1;
            if ($selector === '') continue;
            $blocks[] = ['sel' => $selector, 'body' => $body];
        }
        if ($blocks === []) return $css;

        // Clave normalizada (espaciado) por bloque; las @-rules anidadas
        // (media/supports) se comparan con su contenido entero.
        $keys = [];
        foreach ($blocks as $i => $b) {
            $keys[$i] = preg_replace('/\s+/', ' ', trim($b['sel'])) . '{' . preg_replace('/\s+/', ' ', trim($b['body'])) . '}';
        }
        $lastIndex = [];
        foreach ($keys as $i => $k) $lastIndex[$k] = $i;

        $out = '';
        foreach ($blocks as $i => $b) {
            if ($lastIndex[$keys[$i]] !== $i) continue; // duplicado anterior → fuera
            $out .= $b['sel'] . '{' . $b['body'] . '}' . "\n";
        }
        return trim($out);
    }

    private static function scrubCssDeclarations(string $css): string
    {
        // Vectores clásicos + fixed (un overlay fijo de la IA puede tapar la web).
        $css = preg_replace('/expression\s*\(/i', 'void(', $css) ?? $css;
        $css = preg_replace('/-moz-binding[^;}{]*/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:[^;}{]*/i', '', $css) ?? $css;
        $css = preg_replace('/position\s*:\s*fixed/i', 'position:absolute', $css) ?? $css;
        // url(): solo rutas locales o https de imagen.
        $css = preg_replace_callback('/url\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i', static function (array $m): string {
            $url = trim($m[2]);
            $low = strtolower($url);
            if (str_starts_with($url, '/') || str_starts_with($low, 'https://')) {
                return 'url("' . str_replace('"', '', $url) . '")';
            }
            return 'none';
        }, $css) ?? $css;
        return $css;
    }

    private static function scopeBlock(string $css, string $scope): string
    {
        $out = '';
        $len = strlen($css);
        $pos = 0;

        while ($pos < $len) {
            $brace = strpos($css, '{', $pos);
            if ($brace === false) break;
            $selector = trim(substr($css, $pos, $brace - $pos));
            $end = self::findBlockEnd($css, $brace);
            if ($end === -1) break;
            $body = substr($css, $brace + 1, $end - $brace - 1);
            $pos = $end + 1;

            if ($selector === '') continue;

            if (preg_match('/^@(media|supports|container)\b/i', $selector)) {
                $out .= $selector . '{' . self::scopeBlock($body, $scope) . '}';
                continue;
            }
            if (str_starts_with($selector, '@')) {
                if (preg_match('/^@(-webkit-)?keyframes\b/i', $selector)) {
                    $out .= $selector . '{' . $body . '}';
                }
                // resto de @-rules (font-face, page…): se descartan
                continue;
            }

            $scoped = [];
            foreach (explode(',', $selector) as $sel) {
                $sel = trim($sel);
                if ($sel === '') continue;
                if (preg_match('/^(:root|html|body)$/i', $sel)) {
                    $scoped[] = $scope;
                } else {
                    $sel = preg_replace('/^(html|body)\s+/i', '', $sel) ?? $sel;
                    $scoped[] = $scope . ' ' . $sel;
                }
            }
            if ($scoped !== []) {
                $out .= implode(',', array_unique($scoped)) . '{' . $body . '}';
            }
        }

        return $out;
    }

    /** Devuelve la posición de la llave de cierre que equilibra la de $open. */
    private static function findBlockEnd(string $css, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') $depth++;
            elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) return $i;
            }
        }
        return -1;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private static function scrubInlineStyle(string $style): string
    {
        $style = self::stripControlChars($style);
        if (preg_match('/expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:/i', $style)) return '';
        $style = preg_replace('/position\s*:\s*fixed/i', 'position:absolute', $style) ?? $style;
        $style = preg_replace_callback('/url\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i', static function (array $m): string {
            $url = trim($m[2]);
            return (str_starts_with($url, '/') || str_starts_with(strtolower($url), 'https://'))
                ? 'url("' . str_replace('"', '', $url) . '")'
                : 'none';
        }, $style) ?? $style;
        return trim($style);
    }

    private static function isSafeUrl(string $url): bool
    {
        $u = trim(self::stripControlChars($url));
        if ($u === '') return false;
        $low = strtolower($u);
        foreach (['javascript:', 'vbscript:', 'data:', 'file:', 'blob:'] as $bad) {
            if (str_starts_with($low, $bad)) return false;
        }
        return true;
    }

    private static function stripControlChars(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    }
}
