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

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
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
                    'extra_context' => (string) ($input['extra_context'] ?? ''),
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

            $rationale = is_array($data['rationale'] ?? null) ? $data['rationale'] : [];
            return [
                'html' => $html,
                'css' => $css,
                'rationale' => $rationale,
                'warnings' => $probe['warnings'],
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
}
