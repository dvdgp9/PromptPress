<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use Core\Database;

/**
 * FH2 — Genera páginas Canvas (HTML+CSS libres) con IA.
 *
 * Entrada típica: título/objetivo + design_language + outline (derivados de la
 * referencia visual por el flujo D-MB2) + imágenes reales + capturas (_images).
 * Salida: html/css ya saneados y listos para CanvasService::save().
 */
final class CanvasGenerator
{
    /**
     * @param array{
     *   title:string, goal:string, language?:string, design_language?:string,
     *   sections_outline?:string, extra_context?:string,
     *   reference_images?:array<int,array{mime:string,data:string}>
     * } $input
     * @return array{html:string,css:string,rationale:array<string,mixed>,warnings:array<int,string>,model:?string,provider:?string}
     */
    public static function generate(int $siteId, array $input, int $maxAttempts = 2): array
    {
        $maxAttempts = max(1, min(3, $maxAttempts));
        $lastError = null;
        $validationFeedback = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $extraContext = (string) ($input['extra_context'] ?? '');
                if ($validationFeedback !== '') {
                    $extraContext = trim($extraContext . "\n\nREINTENTO OBLIGATORIO POR VALIDACIÓN INTERNA:\n" . $validationFeedback);
                }
                $result = AIActionRunner::run(Actions::COMPOSE_CANVAS_PAGE, [
                    'page_title' => $input['title'],
                    'page_goal' => $input['goal'],
                    'language' => $input['language'] ?? 'es',
                    'design_language' => trim((string) ($input['design_language'] ?? '')) !== ''
                        ? (string) $input['design_language']
                        : '(sin referencia: diseña con un aire sobrio, contemporáneo y profesional)',
                    'sections_outline' => trim((string) ($input['sections_outline'] ?? '')) !== ''
                        ? (string) $input['sections_outline']
                        : '(sin outline: decide tú la estructura óptima, 5-7 secciones)',
                    'available_forms' => self::availableForms($siteId),
                    'available_pages' => self::availablePages($siteId),
                    'extra_context' => $extraContext,
                    '_images' => $input['reference_images'] ?? [],
                ], $siteId);
            } catch (AIException $e) {
                $lastError = $e;
                continue;
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $html = trim((string) ($data['html'] ?? ''));
            $css = trim((string) ($data['css'] ?? ''));

            // Saneado de prueba: si tras sanear no quedan secciones con texto,
            // el intento no vale.
            $probe = CanvasSanitizer::sanitizeHtml($html);
            if ($probe['html'] === '' || !str_contains($probe['html'], 'data-pp-section')) {
                $lastError = new AIException('La página canvas quedó vacía tras el saneado.');
                continue;
            }

            $referenceWarnings = self::referenceDriftWarnings(
                $probe['html'],
                (string) ($input['sections_outline'] ?? ''),
                !empty($input['reference_images'])
            );
            if ($referenceWarnings !== [] && $attempt < $maxAttempts) {
                $validationFeedback = "- La generación anterior parecía una plantilla genérica y no una adaptación de las capturas.\n"
                    . "- Problemas detectados: " . implode('; ', $referenceWarnings) . ".\n"
                    . "- Rediseña desde la arquitectura visible en las capturas. Elimina secciones no justificadas por la referencia o por datos reales.";
                $lastError = new AIException('La página canvas derivó hacia una plantilla genérica: ' . implode('; ', $referenceWarnings));
                continue;
            }
            if ($referenceWarnings !== []) {
                $stripped = self::stripDriftSections($html, $referenceWarnings);
                if ($stripped !== $html && str_contains($stripped, 'data-pp-section')) {
                    $html = $stripped;
                    $probe = CanvasSanitizer::sanitizeHtml($html);
                    $referenceWarnings[] = 'secciones comodín eliminadas automáticamente';
                }
            }

            // Red de seguridad: ancla los enlaces internos a páginas reales.
            // Un CTA hacia una página inexistente (p. ej. "/profesorado" sin esa
            // página) se redirige a /contacto para evitar 404; los #anclas,
            // externos, mailto/tel y {{form}} se respetan.
            $html = self::groundInternalLinks($siteId, $html);

            $rationale = is_array($data['rationale'] ?? null) ? $data['rationale'] : [];
            return [
                'html' => $html,
                'css' => $css,
                'rationale' => $rationale,
                'warnings' => array_merge($probe['warnings'], $referenceWarnings),
                'model' => $result['model'] ?? null,
                'provider' => $result['provider'] ?? null,
            ];
        }

        throw $lastError ?? new AIException('No se pudo generar la página canvas.');
    }

    /** Lista de formularios reales del sitio para el placeholder {{form:REF}}. */
    private static function availableForms(int $siteId): string
    {
        $rows = Database::select(
            "SELECT ps.id, ps.content, p.slug
             FROM page_sections ps JOIN pages p ON p.id = ps.page_id
             WHERE p.site_id = ? AND ps.section_type = 'form'
             ORDER BY ps.id ASC LIMIT 6",
            [$siteId]
        );
        if ($rows === []) {
            return '(ninguno — NO pongas formulario; usa un CTA a /contacto)';
        }
        $lines = [];
        foreach ($rows as $row) {
            $content = json_decode((string) $row['content'], true) ?: [];
            $lines[] = '- {{form:' . (int) $row['id'] . '}} — "' . trim((string) ($content['heading'] ?? 'Formulario'))
                . '" (página /' . (string) $row['slug'] . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * Lista de páginas REALES del sitio para anclar CTAs/enlaces y evitar que
     * el modelo invente destinos. La home se enlaza como "/".
     */
    private static function availablePages(int $siteId): string
    {
        $rows = Database::select(
            "SELECT slug, title, page_type, status FROM pages
             WHERE site_id = ?
             ORDER BY (page_type = 'home') DESC, tree_sort_order ASC, id ASC
             LIMIT 40",
            [$siteId]
        );
        if ($rows === []) {
            return '(aún no hay otras páginas: enlaza SOLO a #anclas internas de esta misma página o a /contacto; NO inventes páginas ni CTAs hacia contenido inexistente)';
        }
        $lines = [];
        $seen = [];
        foreach ($rows as $row) {
            $type = (string) ($row['page_type'] ?? '');
            $path = $type === 'home' ? '/' : '/' . ltrim((string) $row['slug'], '/');
            if (isset($seen[$path])) continue;   // una entrada por ruta (evita ruido de borradores repetidos)
            $seen[$path] = true;
            $state = ((string) ($row['status'] ?? '')) === 'published' ? 'publicada' : 'borrador';
            $lines[] = '- ' . $path . ' — "' . trim((string) ($row['title'] ?? '')) . '" (' . $type . ', ' . $state . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * Red de seguridad: reescribe los `href` internos que no apunten a una
     * página real del sitio (ni #ancla, ni externo, ni mailto/tel, ni {{form}})
     * hacia /contacto, evitando enlaces rotos a páginas inventadas por la IA.
     */
    private static function groundInternalLinks(int $siteId, string $html): string
    {
        if ($html === '' || stripos($html, 'href') === false) return $html;

        $valid = ['/', '/contacto'];
        foreach (Database::select('SELECT slug, page_type FROM pages WHERE site_id = ?', [$siteId]) as $r) {
            $valid[] = ((string) ($r['page_type'] ?? '')) === 'home'
                ? '/'
                : '/' . rtrim(ltrim((string) $r['slug'], '/'), '/');
        }
        $valid = array_unique($valid);

        $out = preg_replace_callback('/href\s*=\s*"([^"]*)"/i', static function (array $m) use ($valid): string {
            $href = trim($m[1]);
            if ($href === '' || $href[0] === '#') return $m[0];                       // ancla interna o vacío
            if (preg_match('#^(https?:)?//#i', $href)) return $m[0];                  // externo
            if (preg_match('#^(mailto:|tel:)#i', $href)) return $m[0];                // contacto directo
            if (str_contains($href, '{{form')) return $m[0];                          // placeholder de formulario
            if ($href[0] !== '/') return $m[0];                                       // no es ruta interna absoluta

            $path = (string) (parse_url($href, PHP_URL_PATH) ?: $href);
            $path = $path === '/' ? '/' : '/' . rtrim(ltrim($path, '/'), '/');
            if (in_array($path, $valid, true)) return $m[0];                          // página real

            return 'href="/contacto"';                                               // destino inexistente → contacto
        }, $html);

        return $out ?? $html;
    }

    /**
     * Detecta señales típicas de la antigua receta de landing cuando el usuario
     * sí ha aportado referencias visuales. No pretende juzgar diseño; solo evita
     * que el modelo ignore capturas y vuelva a secciones comodín.
     *
     * @return string[]
     */
    private static function referenceDriftWarnings(string $html, string $outline, bool $hasReferences): array
    {
        if (!$hasReferences) return [];

        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''));
        $markup = mb_strtolower($html);
        $outlineText = mb_strtolower($outline);
        $warnings = [];

        $checks = [
            'testimonios/prueba social' => [
                'needles' => ['testimon', 'lo que dicen', 'clientes dicen', 'prueba-social', 'social-proof'],
                'allow' => ['testimon', 'reseña', 'review', 'prueba social', 'social proof'],
            ],
            'cita inspiracional inventada' => [
                'needles' => ['data-pp-section="inspiracional"', 'frase-inspiracional', 'class="lx-quote', '<blockquote'],
                'allow' => ['cita', 'quote', 'blockquote', 'manifiesto', 'frase destacada'],
            ],
            'proceso por fases' => [
                'needles' => ['data-pp-section="proceso"', 'fase 1', 'fase 2', 'fase 3', 'paso 1', 'paso 2', 'paso 3'],
                'allow' => ['timeline visual explícito en referencia'],
            ],
            'métricas/logos de confianza' => [
                'needles' => ['data-pp-section="metric', 'data-pp-section="logos', 'logos de clientes', 'más de ', '/5'],
                'allow' => ['métrica', 'metric', 'logos', 'clientes', 'partners', 'cifras'],
            ],
        ];

        foreach ($checks as $label => $check) {
            $needles = (array) ($check['needles'] ?? []);
            if (self::outlineAllowsGenericPattern($outlineText, (array) ($check['allow'] ?? []))) {
                continue;
            }
            foreach ($needles as $needle) {
                if (($text !== '' && str_contains($text, $needle)) || str_contains($markup, $needle)) {
                    $warnings[] = $label . ' no justificado por la referencia';
                    break;
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /** @param string[] $needles */
    private static function outlineAllowsGenericPattern(string $outlineText, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($outlineText, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $warnings */
    private static function stripDriftSections(string $html, array $warnings): string
    {
        $removeKinds = [];
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'testimonios')) $removeKinds[] = 'testimonials';
            if (str_contains($warning, 'cita inspiracional')) $removeKinds[] = 'quote';
            if (str_contains($warning, 'proceso por fases')) $removeKinds[] = 'process';
            if (str_contains($warning, 'métricas/logos')) $removeKinds[] = 'metrics';
        }
        $removeKinds = array_values(array_unique($removeKinds));
        if ($removeKinds === []) return $html;

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<!doctype html><meta charset="utf-8"><div id="pp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('pp-root');
        if (!$root) return $html;

        $toRemove = [];
        $seen = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement || !$node->hasAttribute('data-pp-section')) continue;
            $haystack = mb_strtolower(
                $node->getAttribute('data-pp-section') . ' '
                . $node->getAttribute('class') . ' '
                . trim($node->textContent ?? '')
            );
            foreach ($removeKinds as $kind) {
                $matches = false;
                if ($kind === 'testimonials' && preg_match('/testimon|clientes dicen|lo que dicen|prueba-social|social-proof/u', $haystack)) $matches = true;
                if ($kind === 'quote' && preg_match('/quote|cita|inspiracional|blockquote|frase destacada/u', $haystack)) $matches = true;
                if ($kind === 'process' && preg_match('/proceso|process|fase\s*[0-9]|paso\s*[0-9]|step/u', $haystack)) $matches = true;
                if ($kind === 'metrics' && preg_match('/metric|métrica|logos|partners|\/5|más de/u', $haystack)) $matches = true;
                if ($matches) {
                    $hash = spl_object_hash($node);
                    if (!isset($seen[$hash])) {
                        $seen[$hash] = true;
                        $toRemove[] = $node;
                    }
                }
            }
        }

        foreach ($toRemove as $node) {
            if ($node->parentNode) $node->parentNode->removeChild($node);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out) !== '' ? trim($out) : $html;
    }
}
