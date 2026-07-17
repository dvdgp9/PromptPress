<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\Canvas\CanvasService;
use Core\Database;

/**
 * FEAT-5 F5-T2 — Planificador del asistente central.
 *
 * Convierte una petición en texto libre (y/o el texto de un documento adjunto)
 * en un plan de cambios por página, clasificado:
 *   - aplicar   → ejecutable por el pipeline de edición canvas (F5-T4)
 *   - ambiguo   → falta información; reason contiene la pregunta a hacer
 *   - no_viable → fuera del alcance del editor de páginas; reason explica dónde/por qué
 *
 * Este servicio SOLO planifica; no toca ninguna página.
 */
final class SiteAssistantPlanner
{
    /** Estados válidos de un item del plan. */
    public const STATUSES = ['aplicar', 'ambiguo', 'no_viable'];

    /** Máximo de texto de documento que viaja al prompt (caracteres). */
    private const DOC_PROMPT_MAX = 30000;

    /**
     * @return array{
     *   summary: string,
     *   items: array<int,array{page_id:int,page_title:string,page_slug:string,section:string,instruction:string,status:string,reason:string}>,
     *   model: string,
     *   estimated_cost: float|null,
     * }
     * @throws \App\Services\AI\AIException
     */
    public static function plan(int $siteId, string $requestText, string $docText = ''): array
    {
        $pages = self::sitePages($siteId);

        $docBlock = '';
        if (trim($docText) !== '') {
            $doc = mb_substr(trim($docText), 0, self::DOC_PROMPT_MAX);
            $docBlock = "\nDOCUMENTO ADJUNTO DEL USUARIO:\n---\n" . $doc . "\n---";
        }

        $result = AIActionRunner::run(Actions::PLAN_SITE_CHANGES, [
            'request_text'   => $requestText,
            'site_map'       => self::renderSiteMap($pages),
            'document_block' => $docBlock,
        ], $siteId);

        $data = (array) $result['data'];
        $items = self::normalizeItems((array) ($data['items'] ?? []), $pages);

        return [
            'summary'        => trim((string) ($data['summary'] ?? '')),
            'items'          => $items,
            'model'          => (string) ($result['model'] ?? ''),
            'estimated_cost' => $result['estimated_cost'] ?? null,
        ];
    }

    // ======================================================================
    // Mapa del sitio
    // ======================================================================

    /**
     * Páginas del sitio relevantes para el asistente, con sus secciones canvas.
     * Excluye entradas de blog (article) y páginas de sistema (slug __*).
     *
     * @return array<int,array{id:int,title:string,slug:string,status:string,editable:bool,sections:array<int,string>}>
     */
    public static function sitePages(int $siteId): array
    {
        $rows = Database::select(
            "SELECT p.id, p.title, p.slug, p.status, p.render_mode, pc.html AS canvas_html
             FROM pages p
             LEFT JOIN page_canvas pc ON pc.page_id = p.id
             WHERE p.site_id = ?
               AND p.page_type <> 'article'
               AND p.slug NOT LIKE '\\_\\_%'
             ORDER BY p.sort_order ASC, p.id ASC",
            [$siteId]
        );

        $out = [];
        foreach ($rows as $r) {
            $editable = ($r['render_mode'] === 'canvas') && $r['canvas_html'] !== null;
            $sections = [];
            if ($editable) {
                foreach (CanvasService::listSections((string) $r['canvas_html']) as $s) {
                    $sections[] = (string) $s['id'];
                }
            }
            $out[(int) $r['id']] = [
                'id'       => (int) $r['id'],
                'title'    => (string) $r['title'],
                'slug'     => (string) $r['slug'],
                'status'   => (string) $r['status'],
                'editable' => $editable,
                'sections' => $sections,
            ];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $pages */
    private static function renderSiteMap(array $pages): string
    {
        $editable = [];
        $rest = [];
        foreach ($pages as $p) {
            $line = 'id=' . $p['id'] . ' «' . $p['title'] . '» (/' . $p['slug'] . ', ' . ($p['status'] === 'published' ? 'publicada' : 'borrador') . ')';
            if ($p['editable']) {
                $line .= $p['sections'] !== []
                    ? ' — secciones: ' . implode(', ', $p['sections'])
                    : ' — sin secciones etiquetadas';
                $editable[] = '- ' . $line;
            } else {
                $rest[] = '- ' . $line;
            }
        }

        $map = "PÁGINAS EDITABLES:\n" . ($editable !== [] ? implode("\n", $editable) : '(ninguna)');
        if ($rest !== []) {
            $map .= "\n\nPÁGINAS SIN EDITOR (no editables por el asistente):\n" . implode("\n", $rest);
        }
        return $map;
    }

    // ======================================================================
    // Normalización del plan
    // ======================================================================

    /**
     * Aplica las invariantes que el modelo puede violar: page_id real, sección
     * existente, status del vocabulario, y "aplicar" solo sobre páginas editables.
     *
     * @param array<int,mixed> $rawItems
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array{page_id:int,page_title:string,page_slug:string,section:string,instruction:string,status:string,reason:string}>
     */
    private static function normalizeItems(array $rawItems, array $pages): array
    {
        $items = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $pageId      = (int) ($raw['page_id'] ?? 0);
            $section     = trim((string) ($raw['section'] ?? ''));
            $instruction = trim((string) ($raw['instruction'] ?? ''));
            $status      = strtolower(trim((string) ($raw['status'] ?? '')));
            $reason      = trim((string) ($raw['reason'] ?? ''));

            if (!in_array($status, self::STATUSES, true)) {
                $status = 'ambiguo';
                $reason = $reason !== '' ? $reason : 'No he podido clasificar este cambio con seguridad.';
            }

            $page = $pages[$pageId] ?? null;

            if ($status === 'aplicar') {
                if ($page === null) {
                    $status = 'ambiguo';
                    $reason = 'No he encontrado la página a la que se refiere este cambio. ¿En qué página va?';
                } elseif (!$page['editable']) {
                    $status = 'no_viable';
                    $reason = 'La página «' . $page['title'] . '» no tiene editor canvas, así que no puedo modificarla desde aquí.';
                } elseif ($instruction === '') {
                    $status = 'ambiguo';
                    $reason = 'El cambio no incluye una instrucción concreta. ¿Qué hay que hacer exactamente?';
                }
            }

            // Sección: solo si existe en la página; si no, cae a página completa.
            if ($page === null || !in_array($section, (array) $page['sections'], true)) {
                $section = '';
            }

            $items[] = [
                'page_id'     => $page !== null ? $pageId : 0,
                'page_title'  => $page !== null ? (string) $page['title'] : '',
                'page_slug'   => $page !== null ? (string) $page['slug'] : '',
                'section'     => $section,
                'instruction' => $instruction,
                'status'      => $status,
                'reason'      => $reason,
            ];
        }
        return $items;
    }
}
