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
 * en un plan operativo contrastado con AssistantCapabilityRegistry. El campo
 * `category` explica la decisión; `status` se conserva como gate compatible con
 * el job actual (solo `aplicar` llega al ejecutor Canvas).
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
     *   items: array<int,array<string,mixed>>,
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

        $capabilities = AssistantCapabilityRegistry::forSite($siteId);

        $result = AIActionRunner::run(Actions::PLAN_SITE_CHANGES, [
            'request_text'   => $requestText,
            'site_map'       => self::renderSiteMap($pages),
            'capability_map' => AssistantCapabilityRegistry::renderForPrompt($capabilities),
            'document_block' => $docBlock,
        ], $siteId);

        $data = (array) $result['data'];
        $items = self::normalizeItems((array) ($data['items'] ?? []), $pages, $capabilities);

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

        // i18n-ignore-start: mapa del sitio que viaja al prompt.
        $map = "PÁGINAS EDITABLES:\n" . ($editable !== [] ? implode("\n", $editable) : '(ninguna)');
        if ($rest !== []) {
            $map .= "\n\nPÁGINAS SIN EDITOR (no editables por el asistente):\n" . implode("\n", $rest);
        }
        // i18n-ignore-end
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
     * @param array<int,array<string,mixed>>|null $capabilities
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeItems(array $rawItems, array $pages, ?array $capabilities = null): array
    {
        $capabilities ??= AssistantCapabilityRegistry::catalogForState();
        $capabilityMap = AssistantCapabilityRegistry::byId($capabilities);
        $items = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $categoryGiven = trim((string) ($raw['category'] ?? '')) !== '';
            $capabilityId = trim((string) ($raw['capability_id'] ?? ''));
            $pageId      = (int) ($raw['page_id'] ?? 0);
            $section     = trim((string) ($raw['section'] ?? ''));
            $instruction = trim((string) ($raw['instruction'] ?? ''));
            $status      = strtolower(trim((string) ($raw['status'] ?? '')));
            $reason      = trim((string) ($raw['reason'] ?? ''));
            $category    = strtolower(trim((string) ($raw['category'] ?? '')));
            $evidence    = trim((string) ($raw['evidence'] ?? ''));
            $nextAction  = trim((string) ($raw['next_action'] ?? ''));
            $requiredInputs = self::normalizeStringList($raw['required_inputs'] ?? []);

            // Los modelos usan ocasionalmente sinónimos pese al vocabulario
            // cerrado del prompt. Normalizarlos evita convertir un "aplicable"
            // perfectamente claro en una falsa ambigüedad.
            $status = match ($status) {
                'aplicable', 'viable', 'aplicar directamente', 'se puede aplicar' => 'aplicar',
                'aclarar', 'necesita aclarar', 'necesito aclarar', 'ambiguous' => 'ambiguo',
                'no viable', 'no-viable', 'inviable', 'no aplicable' => 'no_viable',
                default => $status,
            };

            // Compatibilidad con planes antiguos que todavía no devolvían
            // capability_id/category.
            if ($capabilityId === '') {
                $capabilityId = $pageId > 0 ? 'pages.canvas.edit' : 'custom.development';
            }
            if (!isset($capabilityMap[$capabilityId])) {
                $capabilityId = 'custom.development';
            }
            $capability = $capabilityMap[$capabilityId]
                ?? AssistantCapabilityRegistry::byId(AssistantCapabilityRegistry::catalogForState())['custom.development'];

            if (!in_array($category, AssistantCapabilityRegistry::CATEGORIES, true)) {
                $category = match ($status) {
                    'aplicar' => 'automatable_now',
                    'ambiguo' => 'needs_input',
                    default => match ((string) $capability['mode']) {
                        'manual' => 'manual_in_platform',
                        'review' => 'sensitive_review',
                        default => 'requires_development',
                    },
                };
            }

            // El registro manda sobre la afirmación del modelo.
            $mode = (string) $capability['mode'];
            if ($mode === 'manual' && $category !== 'needs_input') {
                $category = 'manual_in_platform';
            } elseif ($mode === 'review') {
                $category = 'sensitive_review';
            } elseif ($mode === 'none' || !(bool) $capability['platform_available']) {
                $category = 'requires_development';
                $capabilityId = 'custom.development';
                $capability = $capabilityMap[$capabilityId] ?? $capability;
            } elseif ($mode !== 'automatic' && $category === 'automatable_now') {
                $category = 'manual_in_platform';
            }

            $page = $pages[$pageId] ?? null;

            // Si el modelo marca "ambiguo" pero aporta una instrucción concreta
            // y su motivo no contiene pregunta ni dato faltante, el propio plan
            // demuestra que el cambio es ejecutable. Es el caso observado en
            // producción: "Se puede aplicar directamente..." aparecía amarillo.
            if (
                !$categoryGiven
                && $category === 'needs_input'
                && $page !== null
                && $page['editable']
                && $instruction !== ''
                && !self::reasonRequiresClarification($reason)
            ) {
                $category = 'automatable_now';
            }

            if ($category === 'automatable_now') {
                if ($page === null) {
                    $category = 'needs_input';
                    $reason = __('asst.plan.no_page');
                } elseif (!$page['editable']) {
                    $category = 'manual_in_platform';
                    $reason = __('asst.plan.not_canvas', ['pagina' => (string) $page['title']]);
                } elseif ($instruction === '') {
                    $category = 'needs_input';
                    $reason = __('asst.plan.no_instruction');
                }
            }

            $status = match ($category) {
                'automatable_now' => 'aplicar',
                'needs_input' => 'ambiguo',
                default => 'no_viable',
            };

            if ($reason === '') {
                $reason = match ($category) {
                    'automatable_now' => 'Capacidad automática verificada para esta página.',
                    'manual_in_platform' => 'La plataforma lo permite, pero el Assistant no tiene un ejecutor automático.',
                    'needs_input' => 'Falta información necesaria para continuar con seguridad.',
                    'sensitive_review' => 'Requiere validación humana especializada antes de aplicar cambios.',
                    default => 'No existe una capacidad registrada para ejecutar esta funcionalidad.',
                };
            }
            if ($nextAction === '') {
                $nextAction = match ($category) {
                    'automatable_now' => 'Revisar y confirmar el borrador propuesto.',
                    'manual_in_platform' => 'Gestionarlo desde ' . (string) ($capability['admin_path'] ?: 'el panel correspondiente') . '.',
                    'needs_input' => 'Aportar los datos indicados y volver a analizar.',
                    'sensitive_review' => 'Validar el contenido con la persona responsable antes de implementarlo.',
                    default => 'Definir y estimar una tarea de desarrollo.',
                };
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
                'capability_id' => $capabilityId,
                'category'      => $category,
                'evidence'      => $evidence,
                'next_action'   => $nextAction,
                'required_inputs' => $requiredInputs,
                'admin_path'    => (string) ($capability['admin_path'] ?? ''),
            ];
        }
        return $items;
    }

    /** @return string[] */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = mb_substr($item, 0, 300);
            }
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /** Un ambiguo real debe explicar qué dato falta o formular una pregunta. */
    private static function reasonRequiresClarification(string $reason): bool
    {
        $reason = mb_strtolower(trim($reason));
        // i18n-ignore: detecta signos de pregunta en lo que devuelve el modelo.
        if ($reason === '' || str_contains($reason, '?') || str_contains($reason, '¿')) {
            return true;
        }
        return preg_match(
            // i18n-ignore: patrón que lee castellano, no texto que se pinte.
        '/\b(?:falta|faltan|necesit\w*|aclar\w*|especific\w*|indic\w* cu[aá]l|confirm\w*|elige\w*|no (?:se )?indica|sin informaci[oó]n)\b/u',
            $reason
        ) === 1;
    }
}
