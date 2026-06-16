<?php

namespace App\Services\Renderer;

/**
 * Sanitiza fragmentos PromptPress-friendly HTML (`custom_block`).
 *
 * Es deliberadamente estricto: la IA puede equivocarse, pero el renderer
 * publico solo debe recibir HTML con etiquetas, atributos, URLs y clases
 * conocidas por PromptPress.
 */
final class CustomBlockSanitizer
{
    private const ALLOWED_TAGS = [
        'div', 'header', 'footer', 'article', 'figure', 'figcaption',
        'h1', 'h2', 'h3', 'h4', 'p', 'span', 'strong', 'em', 'br',
        'ul', 'ol', 'li', 'a', 'img', 'blockquote', 'cite',
    ];

    private const REJECT_TAGS = [
        'script', 'noscript', 'style', 'link', 'meta', 'iframe', 'embed',
        'object', 'form', 'input', 'textarea', 'select', 'button', 'label',
    ];

    private const UNWRAP_TAGS = ['section', 'main', 'nav', 'aside', 'html', 'head', 'body'];
    private const DROP_TAGS = ['svg', 'canvas', 'video', 'audio', 'source', 'picture', 'table', 'thead', 'tbody', 'tr', 'td', 'th'];

    private const FIELD_TYPES = ['text', 'richtext', 'image', 'link', 'cta', 'list', 'group'];

    private const ALLOWED_CLASSES = [
        'pp-btn', 'pp-btn--primary', 'pp-btn--ghost', 'pp-btn--lg',
        'ppb-container', 'ppb-section', 'ppb-header', 'ppb-body', 'ppb-footer',
        'ppb-stack', 'ppb-stack--tight', 'ppb-stack--loose', 'ppb-cluster',
        'ppb-split', 'ppb-split--media-left', 'ppb-split--media-right',
        'ppb-split--text-heavy', 'ppb-split--media-heavy',
        'ppb-grid', 'ppb-grid--2', 'ppb-grid--3', 'ppb-grid--4', 'ppb-mosaic',
        'ppb-align-start', 'ppb-align-center', 'ppb-align-end', 'ppb-text-center',
        'ppb-measure-sm', 'ppb-measure-md', 'ppb-measure-lg',
        'ppb-eyebrow', 'ppb-heading-xl', 'ppb-heading-lg', 'ppb-heading-md',
        'ppb-lead', 'ppb-copy', 'ppb-small', 'ppb-kicker',
        'ppb-card', 'ppb-card--flat', 'ppb-card--raised', 'ppb-card--accent',
        'ppb-panel', 'ppb-panel--inverted', 'ppb-strip',
        'ppb-media', 'ppb-media--frame', 'ppb-media--bleed', 'ppb-media--portrait',
        'ppb-media--landscape', 'ppb-media--square', 'ppb-caption',
        'ppb-actions', 'ppb-actions--center', 'ppb-actions--stack-mobile',
        'ppb-list', 'ppb-list--check', 'ppb-list--numbered',
        'ppb-item', 'ppb-item__icon', 'ppb-item__title', 'ppb-item__text',
        'ppb-badge', 'ppb-badge--accent',
        'ppb-stat', 'ppb-stat__value', 'ppb-stat__label',
        'ppb-quote', 'ppb-quote__text', 'ppb-quote__cite',
        'ppb-gap-sm', 'ppb-gap-md', 'ppb-gap-lg',
        'ppb-pad-sm', 'ppb-pad-md', 'ppb-pad-lg',
        'ppb-cover', 'ppb-cover__bg', 'ppb-cover__content',
    ];

    /** D-MB2 R2 — valores válidos de dirección de arte en el elemento raíz. */
    private const ART_THEMES = ['surface', 'tint', 'primary', 'dark', 'image'];
    private const ART_PADS = ['sm', 'md', 'lg', 'xl'];

    /**
     * D-MB2 — emojis y pictogramas: prohibidos en la generación. Cubre los
     * bloques Unicode de emoji/dingbats/símbolos + selectores de variación,
     * ZWJ, keycaps y banderas. NO incluye flechas tipográficas (→) ni
     * puntuación normal.
     */
    private const EMOJI_REGEX = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{200D}\x{20E3}\x{2139}\x{2049}\x{203C}\x{24C2}\x{3030}\x{303D}\x{3297}\x{3299}]/u';

    /**
     * @return array{ok:bool,html:string,fields:array<string,mixed>,warnings:array<int,array<string,string>>,errors:array<int,array<string,string>>,removed:array<int,array<string,string>>}
     */
    public static function sanitize(string $html, array $context = []): array
    {
        $state = [
            'warnings' => [],
            'errors' => [],
            'removed' => [],
            'fields' => [],
            'seen_fields' => [],
        ];

        $html = trim(self::stripControlChars($html));
        if ($html === '') {
            self::error($state, 'empty_block', 'Custom block HTML is empty.', 'error');
            return self::result(false, '', [], $state);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div data-pp-root="1">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            self::error($state, 'parse_error', 'HTML fragment could not be parsed.', 'error');
            return self::result(false, '', [], $state);
        }

        $root = self::findRoot($doc);
        if (!$root) {
            self::error($state, 'parse_error', 'HTML root wrapper was not found.', 'error');
            return self::result(false, '', [], $state);
        }

        self::sanitizeChildren($root, $state, (bool) ($context['is_first_section'] ?? false));
        if (!empty($state['errors'])) {
            return self::result(false, '', [], $state);
        }

        $state['art'] = self::extractArtDirection($root, $state);
        self::enforceThemeCombos($root, $state['art'], $state);
        self::demoteNestedCovers($root, $state);
        self::enforceGridUniformity($root, $state);
        self::rebalanceGridColumns($root, $state);

        $out = self::innerHTML($root);
        $out = trim($out);
        if ($out === '') {
            self::error($state, 'empty_block', 'Block is empty after sanitizing.', 'error');
            return self::result(false, '', [], $state);
        }

        if (empty($state['fields'])) {
            self::error($state, 'missing_required_field', 'Block must expose at least one editable field.', 'error');
            return self::result(false, '', [], $state);
        }

        if (!self::hasMeaningfulContent($root)) {
            self::error($state, 'empty_block', 'Block has no meaningful text content.', 'error');
            return self::result(false, '', [], $state);
        }

        return self::result(true, $out, $state['fields'], $state);
    }

    private static function sanitizeChildren(\DOMNode $parent, array &$state, bool $isFirstSection): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::sanitizeNode($child, $state, $isFirstSection);
        }
    }

    private static function sanitizeNode(\DOMNode $node, array &$state, bool $isFirstSection): void
    {
        if ($node instanceof \DOMComment) {
            self::removeNode($node);
            return;
        }
        if ($node instanceof \DOMText) {
            // Emojis prohibidos en la generación: se purgan del texto.
            $clean = preg_replace(self::EMOJI_REGEX, '', $node->textContent) ?? $node->textContent;
            if ($clean !== $node->textContent) {
                $node->nodeValue = trim($clean) === '' ? '' : $clean;
                self::warning($state, 'emoji_removed', 'Emoji/pictograma eliminado del texto.');
            }
            return;
        }
        if (!$node instanceof \DOMElement) {
            self::removeNode($node);
            return;
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::REJECT_TAGS, true)) {
            self::error($state, 'forbidden_tag', 'Tag <' . $tag . '> is not allowed.', 'critical');
            return;
        }
        if (in_array($tag, self::DROP_TAGS, true)) {
            self::removed($state, 'tag', $tag, 'dropped_tag');
            self::removeNode($node);
            return;
        }
        if (in_array($tag, self::UNWRAP_TAGS, true)) {
            self::removed($state, 'tag', $tag, 'unwrap_landmark');
            self::sanitizeChildren($node, $state, $isFirstSection);
            if (!empty($state['errors'])) return;
            self::unwrapNode($node);
            return;
        }
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            self::removed($state, 'tag', $tag, 'unknown_tag');
            self::removeNode($node);
            return;
        }

        if ($tag === 'h1' && !$isFirstSection) {
            $node = self::renameElement($node, 'h2');
            $tag = 'h2';
            self::warning($state, 'h1_downgraded', 'h1 downgraded to h2 outside first section.');
        }

        self::sanitizeAttributes($node, $tag, $state);
        if (!empty($state['errors'])) return;

        self::sanitizeChildren($node, $state, $isFirstSection);
        if (!empty($state['errors'])) return;

        self::extractField($node, $tag, $state);

        if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true) && trim($node->textContent) === '') {
            self::removed($state, 'tag', $tag, 'empty_heading');
            self::removeNode($node);
            return;
        }

        // D-MB2 — placeholders tipo "[Módulo interactivo de calendario]" (visto
        // en QA cuando la referencia tiene un widget): se elimina el nodo entero
        // si todo su contenido es un placeholder entre corchetes.
        if ($node->parentNode && !str_contains($node->textContent, '<')) {
            $text = trim($node->textContent);
            if ($text !== '' && preg_match('/^\[[^\[\]]{3,}\]$/u', $text) && $node->getElementsByTagName('img')->length === 0) {
                self::warning($state, 'placeholder_removed', 'Placeholder eliminado: ' . mb_substr($text, 0, 80));
                self::removeNode($node);
            }
        }
    }

    private static function sanitizeAttributes(\DOMElement $node, string $tag, array &$state): void
    {
        $attrs = [];
        foreach ($node->attributes as $attr) {
            $attrs[] = $attr->name;
        }

        foreach ($attrs as $name) {
            $value = (string) $node->getAttribute($name);
            $low = strtolower($name);

            if (str_starts_with($low, 'on')) {
                self::error($state, 'forbidden_event_handler', 'Event handler attribute is not allowed: ' . $name, 'critical');
                return;
            }

            if (in_array($low, ['style', 'srcset', 'sizes', 'autoplay', 'controls', 'contenteditable', 'draggable', 'tabindex', 'id', 'name', 'for', 'method', 'action', 'value', 'type'], true)) {
                self::removed($state, 'attribute', $name, $low === 'style' ? 'inline_style_removed' : 'forbidden_attribute');
                $node->removeAttribute($name);
                continue;
            }

            if ($low === 'class') {
                self::sanitizeClassAttribute($node, $state);
                continue;
            }

            if (str_starts_with($low, 'data-') && !str_starts_with($low, 'data-pp-') && !str_starts_with($low, 'data-ppb-')) {
                self::removed($state, 'attribute', $name, 'unknown_data_attribute');
                $node->removeAttribute($name);
                continue;
            }

            if (str_starts_with($low, 'aria-')) {
                if (!in_array($low, ['aria-label', 'aria-hidden'], true)) {
                    self::removed($state, 'attribute', $name, 'unknown_aria_attribute');
                    $node->removeAttribute($name);
                    continue;
                }
                if ($low === 'aria-hidden' && !in_array($value, ['true', 'false'], true)) {
                    $node->setAttribute('aria-hidden', 'false');
                }
                continue;
            }

            $allowed = ['data-pp-field', 'data-pp-type', 'data-pp-label', 'data-pp-repeat', 'data-ppb-theme', 'data-ppb-pad', 'data-ppb-icon'];
            if ($tag === 'a') $allowed = array_merge($allowed, ['href', 'target', 'rel']);
            if ($tag === 'img') $allowed = array_merge($allowed, ['src', 'alt', 'loading', 'decoding', 'width', 'height']);
            if ($tag === 'ol') $allowed[] = 'start';
            if ($tag === 'blockquote') $allowed[] = 'cite';

            if (!in_array($low, $allowed, true)) {
                self::removed($state, 'attribute', $name, 'unknown_attribute_removed');
                $node->removeAttribute($name);
            }
        }

        self::validateSpecialAttributes($node, $tag, $state);
    }

    private static function validateSpecialAttributes(\DOMElement $node, string $tag, array &$state): void
    {
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if ($href === '' || !self::isAllowedUrl($href, false)) {
                self::error($state, 'invalid_url', 'Invalid link href: ' . $href, 'error');
                return;
            }
            if ($node->getAttribute('target') !== '') {
                if ($node->getAttribute('target') !== '_blank') {
                    $node->removeAttribute('target');
                } else {
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        if ($tag === 'img') {
            $src = trim($node->getAttribute('src'));
            if ($src === '' || !self::isAllowedUrl($src, true)) {
                self::error($state, 'invalid_url', 'Invalid image src: ' . $src, 'error');
                return;
            }
            // D-MB2 — la misma foto repetida N veces en un bloque es un defecto
            // visual seguro (visto en QA: 6 tarjetas con la misma imagen). Se
            // conserva la primera aparición y se eliminan las repeticiones.
            if (isset($state['seen_image_srcs'][$src])) {
                self::warning($state, 'duplicate_image_removed', 'Imagen repetida eliminada: ' . $src);
                self::removeNode($node);
                return;
            }
            $state['seen_image_srcs'][$src] = true;
            if (!$node->hasAttribute('alt')) {
                self::error($state, 'missing_img_alt', 'Informative images must include alt.', 'error');
                return;
            }
            $loading = $node->getAttribute('loading');
            if ($loading !== '' && !in_array($loading, ['lazy', 'eager'], true)) {
                $node->setAttribute('loading', 'lazy');
            }
            $decoding = $node->getAttribute('decoding');
            if ($decoding !== '' && $decoding !== 'async') {
                $node->setAttribute('decoding', 'async');
            }
            foreach (['width', 'height'] as $dim) {
                $v = $node->getAttribute($dim);
                if ($v !== '' && !preg_match('/^[1-9][0-9]{0,4}$/', $v)) {
                    $node->removeAttribute($dim);
                }
            }
        }

        // D-MB2 — iconos de la librería de confianza: la IA solo declara el
        // nombre; el SVG lo inyecta SectionRenderer desde Icons. El span debe
        // quedar vacío (cualquier emoji/texto de relleno dentro se elimina).
        if ($node->hasAttribute('data-ppb-icon')) {
            $iconName = trim($node->getAttribute('data-ppb-icon'));
            if ($tag !== 'span' || !Icons::exists($iconName)) {
                $node->removeAttribute('data-ppb-icon');
                self::warning($state, 'icon_dropped', $tag !== 'span'
                    ? 'data-ppb-icon solo es válido en <span>; eliminado de <' . $tag . '>.'
                    : 'Icono desconocido eliminado: ' . $iconName);
            } else {
                $node->setAttribute('data-ppb-icon', Icons::canonicalName($iconName));
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
                $node->setAttribute('aria-hidden', 'true');
            }
        }

        if ($tag === 'blockquote' && $node->hasAttribute('cite') && !self::isAllowedUrl($node->getAttribute('cite'), false)) {
            $node->removeAttribute('cite');
        }

        if ($tag === 'ol' && $node->hasAttribute('start') && !preg_match('/^[1-9][0-9]{0,2}$/', $node->getAttribute('start'))) {
            $node->removeAttribute('start');
        }
    }

    private static function sanitizeClassAttribute(\DOMElement $node, array &$state): void
    {
        $raw = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        $valid = [];
        foreach ($raw as $class) {
            if ($class === '') continue;
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $class) || !in_array($class, self::ALLOWED_CLASSES, true)) {
                self::removed($state, 'class', $class, 'unknown_class_removed');
                continue;
            }
            if (!in_array($class, $valid, true)) $valid[] = $class;
        }
        if (empty($valid)) {
            $node->removeAttribute('class');
            return;
        }
        $node->setAttribute('class', implode(' ', $valid));
    }

    private static function extractField(\DOMElement $node, string $tag, array &$state): void
    {
        $repeat = trim($node->getAttribute('data-pp-repeat'));
        if ($repeat !== '') {
            $normalRepeat = self::normalizeFieldName($repeat);
            // El nombre del repeat es solo una clave de colección, no un riesgo de
            // seguridad: aceptamos cualquier identificador seguro. Si no encaja ni
            // tras normalizar, quitamos el atributo en vez de tirar el bloque.
            if (preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9]+)*$/', $normalRepeat)) {
                if ($normalRepeat !== $repeat) $node->setAttribute('data-pp-repeat', $normalRepeat);
            } else {
                $node->removeAttribute('data-pp-repeat');
                self::warning($state, 'repeat_dropped', 'data-pp-repeat inválido eliminado: ' . $repeat);
            }
        }

        // Nodo eliminado durante la pasada (p. ej. imagen duplicada): sin campo.
        if (!$node->parentNode) return;

        $field = trim($node->getAttribute('data-pp-field'));
        $type = trim($node->getAttribute('data-pp-type'));
        if ($field === '' && $type === '') return;

        // Tolerancia: el modelo a veces olvida uno de los dos atributos.
        // - Falta el tipo pero hay campo → inferir el tipo del tag (salvar bloque).
        // - Falta el campo pero hay tipo → el nodo no es editable; ignorar el tipo.
        if ($field !== '' && $type === '') {
            $type = self::inferFieldType($node, $tag);
            $node->setAttribute('data-pp-type', $type);
            self::warning($state, 'field_type_inferred', 'data-pp-type inferido (' . $type . ') para ' . $field . '.');
        }
        if ($field === '' && $type !== '') {
            $node->removeAttribute('data-pp-type');
            self::warning($state, 'orphan_field_type', 'data-pp-type sin data-pp-field; ignorado.');
            return;
        }

        // Normalizar nombres tipo snake_case/Mayúsculas a la convención con puntos.
        $normalField = self::normalizeFieldName($field);
        if ($normalField !== $field) {
            $field = $normalField;
            $node->setAttribute('data-pp-field', $field);
        }
        if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9]+)*$/', $field)) {
            self::error($state, 'invalid_field_name', 'Invalid field name: ' . $field, 'error');
            return;
        }
        if (!in_array($type, self::FIELD_TYPES, true)) {
            self::error($state, 'invalid_field_type', 'Invalid field type: ' . $type, 'error');
            return;
        }
        if (isset($state['seen_fields'][$field])) {
            self::error($state, 'duplicate_field', 'Duplicate field: ' . $field, 'error');
            return;
        }
        $state['seen_fields'][$field] = true;

        $state['fields'][$field] = [
            'type' => $type,
            'label' => trim($node->getAttribute('data-pp-label')),
            'value' => self::fieldValue($node, $tag, $type),
        ];
    }

    /** Infiere el data-pp-type a partir del tag cuando el modelo lo omite. */
    private static function inferFieldType(\DOMElement $node, string $tag): string
    {
        if ($tag === 'img') return 'image';
        if ($tag === 'a') {
            return str_contains($node->getAttribute('class'), 'pp-btn') ? 'cta' : 'link';
        }
        if (in_array($tag, ['ul', 'ol'], true)) return 'list';
        if ($tag === 'p') return 'richtext';
        return 'text';
    }

    /**
     * Normaliza nombres de campo/repeat al patrón con puntos: minúsculas y
     * guiones bajos/medios → puntos (p. ej. `cta_primary` → `cta.primary`,
     * `items_0_title` → `items.0.title`). No toca nombres ya válidos.
     */
    private static function normalizeFieldName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[_\-]+/', '.', $name) ?? $name;
        $name = preg_replace('/\.{2,}/', '.', $name) ?? $name;
        return trim($name, '.');
    }

    private static function fieldValue(\DOMElement $node, string $tag, string $type): mixed
    {
        if ($type === 'cta') {
            return [
                'label' => trim($node->textContent),
                'href' => $node->getAttribute('href'),
                'classes' => array_values(array_filter(preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [])),
            ];
        }
        if ($type === 'image') {
            return [
                'src' => $node->getAttribute('src'),
                'alt' => $node->getAttribute('alt'),
            ];
        }
        if ($type === 'group') {
            return ['field' => $node->getAttribute('data-pp-field')];
        }
        return trim($node->textContent);
    }

    /**
     * D-MB2 R2 — Lee `data-ppb-theme` / `data-ppb-pad` del PRIMER elemento
     * raíz (la dirección de arte es de la sección entera). Valores inválidos
     * se eliminan con warning; los atributos en nodos anidados se limpian.
     *
     * @return array{theme:string,pad:string}
     */
    private static function extractArtDirection(\DOMElement $root, array &$state): array
    {
        $art = ['theme' => '', 'pad' => ''];
        $first = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement) { $first = $child; break; }
        }

        foreach (self::elements($root) as $el) {
            foreach (['data-ppb-theme' => 'theme', 'data-ppb-pad' => 'pad'] as $attr => $key) {
                if (!$el->hasAttribute($attr)) continue;
                $value = strtolower(trim($el->getAttribute($attr)));
                $valid = $key === 'theme' ? self::ART_THEMES : self::ART_PADS;
                if ($el === $first && in_array($value, $valid, true)) {
                    $art[$key] = $value;
                    continue;
                }
                $el->removeAttribute($attr);
                if ($el !== $first) {
                    self::warning($state, 'art_attribute_moved', $attr . ' solo es válido en el elemento raíz; eliminado de un nodo anidado.');
                } else {
                    self::warning($state, 'invalid_art_value', 'Valor inválido en ' . $attr . ': ' . $value);
                }
            }
        }
        return $art;
    }

    /**
     * D-MB2 R2 — Combos visualmente rotos detectados en QA:
     *  - `ppb-panel--inverted` dentro de una `ppb-card` (panel oscuro flotante).
     *  - `ppb-panel--inverted` dentro de una sección ya oscura (dark/primary/image).
     * Se sanea quitando la clase en vez de rechazar el bloque.
     */
    private static function enforceThemeCombos(\DOMElement $root, array $art, array &$state): void
    {
        $darkSection = in_array($art['theme'] ?? '', ['dark', 'primary', 'image'], true);

        foreach (self::elements($root) as $el) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            if (!in_array('ppb-panel--inverted', $classes, true)) continue;

            $insideCard = false;
            for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if (str_contains(' ' . $p->getAttribute('class') . ' ', ' ppb-card')) { $insideCard = true; break; }
            }
            if (!$insideCard && !$darkSection) continue;

            $classes = array_values(array_diff($classes, ['ppb-panel--inverted']));
            if ($classes === []) {
                $el->removeAttribute('class');
            } else {
                $el->setAttribute('class', implode(' ', $classes));
            }
            self::warning(
                $state,
                'inverted_panel_stripped',
                $insideCard
                    ? 'ppb-panel--inverted dentro de ppb-card; clase eliminada.'
                    : 'ppb-panel--inverted dentro de una sección ya oscura (' . ($art['theme'] ?? '') . '); clase eliminada.'
            );
        }
    }

    /**
     * D-MB2 R2 — `ppb-cover` es un patrón de SECCIÓN (foto a sangre con
     * overlay): solo es válido como hijo directo del raíz. Anidado en
     * tarjetas/grids produce tiles oscuros gigantes (visto en QA). Se demota
     * quitando las clases cover de todo el subárbol; el contenido se conserva.
     */
    private static function demoteNestedCovers(\DOMElement $root, array &$state): void
    {
        $coverClasses = ['ppb-cover', 'ppb-cover__bg', 'ppb-cover__content'];

        foreach (self::elements($root) as $el) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            if (!in_array('ppb-cover', $classes, true)) continue;
            // Válido: cover como hijo del wrapper de saneado, o como hijo del
            // elemento raíz del bloque (que a su vez cuelga del wrapper).
            $parent = $el->parentNode;
            if ($parent === $root || ($parent instanceof \DOMElement && $parent->parentNode === $root)) continue;

            foreach (array_merge([$el], self::elements($el)) as $sub) {
                $subClasses = preg_split('/\s+/', trim($sub->getAttribute('class'))) ?: [];
                $kept = array_values(array_diff($subClasses, $coverClasses));
                if ($kept === $subClasses) continue;
                if ($kept === []) {
                    $sub->removeAttribute('class');
                } else {
                    $sub->setAttribute('class', implode(' ', $kept));
                }
            }
            self::warning($state, 'cover_demoted', 'ppb-cover solo es válido como hijo directo del raíz; clases cover eliminadas de un nodo anidado.');
        }
    }

    /**
     * D-MB2 — Uniformidad en grids/mosaicos (visto en QA: tarjetas con foto
     * junto a tarjetas sin foto → composición descuadrada). Si un grid mezcla
     * hijos con y sin <img>, se eliminan TODAS las imágenes de ese grid (y sus
     * <figure> vacías), dejando tarjetas homogéneas de texto/icono.
     */
    private static function enforceGridUniformity(\DOMElement $root, array &$state): void
    {
        foreach (self::elements($root) as $grid) {
            $classes = ' ' . $grid->getAttribute('class') . ' ';
            if (!str_contains($classes, ' ppb-grid') && !str_contains($classes, ' ppb-mosaic')) continue;

            $cards = [];
            foreach ($grid->childNodes as $child) {
                if ($child instanceof \DOMElement) $cards[] = $child;
            }
            if (count($cards) < 2) continue;

            $withImg = 0;
            foreach ($cards as $card) {
                if ($card->getElementsByTagName('img')->length > 0) $withImg++;
            }
            if ($withImg === 0 || $withImg === count($cards)) continue;

            foreach ($cards as $card) {
                $imgs = [];
                foreach ($card->getElementsByTagName('img') as $img) $imgs[] = $img;
                foreach ($imgs as $img) {
                    $field = trim($img->getAttribute('data-pp-field'));
                    if ($field !== '') {
                        unset($state['fields'][$field], $state['seen_fields'][$field]);
                    }
                    $figure = $img->parentNode;
                    self::removeNode($img);
                    if ($figure instanceof \DOMElement && strtolower($figure->tagName) === 'figure' && trim($figure->textContent) === '' && $figure->getElementsByTagName('img')->length === 0) {
                        self::removeNode($figure);
                    }
                }
            }
            self::warning($state, 'grid_images_stripped', 'Grid con tarjetas desiguales (' . $withImg . '/' . count($cards) . ' con imagen): imágenes eliminadas para uniformar.');
        }
    }

    /**
     * D-MB2 — Grid "cojo" (visto en QA): `ppb-grid--N` cuyo nº de hijos deja
     * exactamente 1 huérfano en la última fila (p. ej. 3 tarjetas en grid--2,
     * 4 en grid--3). Se cambia a un nº de columnas que cuadre (prefiere 3, 2,
     * 4); si ninguno divide, se quita el modificador y el grid pasa a auto-fit.
     */
    private static function rebalanceGridColumns(\DOMElement $root, array &$state): void
    {
        foreach (self::elements($root) as $grid) {
            $classes = preg_split('/\s+/', trim($grid->getAttribute('class'))) ?: [];
            $current = 0;
            foreach ($classes as $cls) {
                if (preg_match('/^ppb-grid--([234])$/', $cls, $m)) { $current = (int) $m[1]; break; }
            }
            if ($current === 0) continue;

            $count = 0;
            foreach ($grid->childNodes as $child) {
                if ($child instanceof \DOMElement) $count++;
            }
            if ($count < 2 || $count % $current !== 1) continue;

            $replacement = '';
            foreach ([3, 2, 4] as $n) {
                if ($n !== $current && $n <= $count && $count % $n === 0) { $replacement = 'ppb-grid--' . $n; break; }
            }

            $classes = array_values(array_filter($classes, fn($c) => $c !== 'ppb-grid--' . $current));
            if ($replacement !== '') $classes[] = $replacement;
            $grid->setAttribute('class', implode(' ', $classes));
            self::warning($state, 'grid_rebalanced', $count . ' items en grid--' . $current . ' dejaban 1 huérfano; '
                . ($replacement !== '' ? 'cambiado a ' . $replacement . '.' : 'modificador eliminado (auto-fit).'));
        }
    }

    /** @return array<int,\DOMElement> snapshot estable de todos los elementos descendientes. */
    private static function elements(\DOMElement $root): array
    {
        $out = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement) $out[] = $el;
        }
        return $out;
    }

    private static function isAllowedUrl(string $url, bool $image): bool
    {
        $u = trim(self::stripControlChars($url));
        if ($u === '' || $u === '#' || preg_match('/\s/u', $u)) return false;
        $low = strtolower($u);
        foreach (['javascript:', 'data:', 'vbscript:'] as $bad) {
            if (str_starts_with($low, $bad)) return false;
        }
        if (str_starts_with($low, 'http://')) return false;
        if (str_starts_with($low, 'https://')) return true;
        if (!$image && (str_starts_with($low, 'mailto:') || str_starts_with($low, 'tel:'))) return true;
        if (str_starts_with($u, '/')) return true;
        return false;
    }

    private static function hasMeaningfulContent(\DOMElement $root): bool
    {
        return trim($root->textContent) !== '';
    }

    private static function findRoot(\DOMDocument $doc): ?\DOMElement
    {
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-pp-root') === '1') return $div;
        }
        return null;
    }

    private static function innerHTML(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }

    private static function unwrapNode(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) return;
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function removeNode(\DOMNode $node): void
    {
        if ($node->parentNode) $node->parentNode->removeChild($node);
    }

    private static function renameElement(\DOMElement $node, string $newTag): \DOMElement
    {
        $doc = $node->ownerDocument;
        $new = $doc->createElement($newTag);
        foreach ($node->attributes as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        while ($node->firstChild) {
            $new->appendChild($node->firstChild);
        }
        $node->parentNode?->replaceChild($new, $node);
        return $new;
    }

    private static function stripControlChars(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    }

    private static function result(bool $ok, string $html, array $fields, array $state): array
    {
        return [
            'ok' => $ok,
            'html' => $html,
            'fields' => $fields,
            'art' => is_array($state['art'] ?? null) ? $state['art'] : ['theme' => '', 'pad' => ''],
            'warnings' => $state['warnings'],
            'errors' => $state['errors'],
            'removed' => $state['removed'],
        ];
    }

    private static function error(array &$state, string $code, string $message, string $severity): void
    {
        $state['errors'][] = ['code' => $code, 'message' => $message, 'severity' => $severity];
    }

    private static function warning(array &$state, string $code, string $message): void
    {
        $state['warnings'][] = ['code' => $code, 'message' => $message, 'severity' => 'warning'];
    }

    private static function removed(array &$state, string $kind, string $name, string $reason): void
    {
        $state['removed'][] = ['kind' => $kind, 'name' => $name, 'reason' => $reason];
    }
}
