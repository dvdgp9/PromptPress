<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\Admin\OnboardingController;
use App\Controllers\Admin\PageController;
use Core\Database;

/**
 * ASST-V2 F4d — Páginas nuevas desde el asistente.
 *
 * Reutiliza el creador de páginas del panel: el plan sale de
 * GENERATE_PAGE_BRIEF (PageController::briefFor), las ideas de
 * DISCOVER_PAGE_OPPORTUNITIES (con su caché) y la página se compone con el
 * mismo motor Canvas. Si la tarjeta dejó la página precompuesta
 * (/admin/pages/ai-prepare) con ese mismo plan, se crea al instante.
 *
 * Siempre en borrador: se revisa y se publica desde el Studio.
 */
final class PageAssistant
{
    public const MAX_PAGES_PER_PLAN = 3;

    /**
     * Plan de la página. Solo tipos Canvas: un artículo es posts.create y una
     * página legal, legal.content.
     *
     * @return array{ok:bool, brief?:array<string,mixed>, fallback?:bool, error?:string}
     */
    public static function brief(int $siteId, string $instruction): array
    {
        $found = PageController::briefFor($siteId, $instruction);
        if (!$found['ok']) {
            return ['ok' => false, 'error' => (string) ($found['error'] ?? __('asst.err.page_brief_failed'))];
        }
        $brief = (array) $found['data'];
        if (!PageController::isCanvasMarketingType((string) ($brief['page_type'] ?? ''))) {
            $brief['page_type'] = 'landing';
        }
        return ['ok' => true, 'brief' => $brief, 'fallback' => (bool) ($found['fallback'] ?? false)];
    }

    /**
     * Ideas de páginas que faltan, ya con la instrucción de cada una.
     *
     * @return array{ideas:array<int,array<string,string>>, fallback:bool}
     */
    public static function ideas(int $siteId, string $focus = ''): array
    {
        $found = PageController::opportunitiesFor($siteId, $focus);
        $ideas = [];
        foreach ((array) ($found['data']['opportunities'] ?? []) as $opp) {
            if (!is_array($opp) || !PageController::isCanvasMarketingType((string) ($opp['page_type'] ?? ''))) continue;
            $ideas[] = [
                'title'    => (string) $opp['title'],
                'angle'    => (string) ($opp['goal'] ?? ''),
                'audience' => (string) ($opp['audience'] ?? ''),
                'why_now'  => (string) ($opp['reason'] ?? ''),
                'page_type' => (string) $opp['page_type'],
                'priority' => (string) ($opp['priority'] ?? 'medium'),
            ];
        }
        return ['ideas' => $ideas, 'fallback' => (bool) $found['fallback']];
    }

    /** Instrucción del item pages.create que sale de una idea. @param array<string,string> $idea */
    public static function ideaInstruction(array $idea): string
    {
        // i18n-ignore-start: instrucción que se guarda en el trabajo y llega a la IA.
        $parts = ['Crea la página «' . trim($idea['title'] ?? '') . '».'];
        if (trim($idea['angle'] ?? '') !== '') $parts[] = 'Objetivo: ' . trim($idea['angle']) . '.';
        if (trim($idea['audience'] ?? '') !== '') $parts[] = 'Público: ' . trim($idea['audience']) . '.';
        // i18n-ignore-end
        return implode(' ', $parts);
    }

    /**
     * Crea la página en borrador a partir de un plan.
     *
     * @param array<string,mixed> $brief
     * @return array{ok:bool, page_id?:int, reply?:string, error?:string, prepared?:bool}
     */
    public static function create(int $siteId, array $brief): array
    {
        $brief = PageController::decodePostedBrief((string) json_encode($brief, JSON_UNESCAPED_UNICODE));
        $title = trim((string) ($brief['title'] ?? ''));
        $goal = trim((string) ($brief['goal'] ?? ''));
        $type = (string) ($brief['page_type'] ?? 'landing');
        if ($title === '' || $goal === '') {
            return ['ok' => false, 'error' => __('asst.err.page_brief_failed')];
        }
        if (!PageController::isCanvasMarketingType($type)) {
            $type = 'landing';
            $brief['page_type'] = $type;
        }

        // La tarjeta pudo dejarla compuesta mientras se leía el plan.
        $prepared = PageController::readComposition($siteId, PageController::compositionSignature($brief, ''));
        if ($prepared !== null) {
            $created = PageController::persistComposition($siteId, $prepared);
        } else {
            $audience = trim((string) ($brief['audience'] ?? ''));
            $context = trim(($audience !== '' ? 'Público objetivo: ' . $audience . "\n" : '') . PageController::briefToContext($brief));
            $created = OnboardingController::generateCanvasPageForPanel($siteId, $title, $type, $goal, $context, 0);
        }
        $pageId = (int) ($created['id'] ?? 0);
        if ($pageId <= 0) {
            return ['ok' => false, 'error' => __('asst.err.page_create_failed')];
        }
        return [
            'ok'       => true,
            'page_id'  => $pageId,
            'prepared' => $prepared !== null,
            'reply'    => __('asst.page.created', ['pagina' => $title]),
        ];
    }

    /**
     * Sin plan previo (item del planner sin vista previa): plan y página en el
     * mismo paso.
     *
     * @return array{ok:bool, page_id?:int, reply?:string, error?:string}
     */
    public static function apply(int $siteId, string $instruction): array
    {
        $brief = self::brief($siteId, $instruction);
        if (!$brief['ok']) {
            return $brief;
        }
        return self::create($siteId, $brief['brief']);
    }

    /**
     * Lo que enseña la tarjeta: el plan de la página, legible.
     *
     * @param array<string,mixed> $brief
     * @return array<string,mixed>
     */
    public static function preview(array $brief, bool $fallback): array
    {
        $form = (array) ($brief['recommended_form'] ?? []);
        return [
            'kind'     => 'page',
            'brief'    => $brief,
            'type'     => __('page_type.' . (string) ($brief['page_type'] ?? 'landing')),
            'sections' => array_values(array_map(static fn (array $s): array => [
                'heading' => (string) ($s['heading'] ?? ''),
                'purpose' => (string) ($s['purpose'] ?? ''),
            ], array_filter((array) ($brief['sections'] ?? []), 'is_array'))),
            'form'     => ($form['needed'] ?? false) === true
                ? array_values(array_map(static fn (array $f): string => (string) ($f['label'] ?? ''), (array) ($form['fields'] ?? [])))
                : [],
            'fallback' => $fallback,
            'changes'  => [],
        ];
    }

    /** Datos de la página creada para el informe. @return array<string,mixed>|null */
    public static function card(int $siteId, int $pageId): ?array
    {
        $page = Database::selectOne(
            "SELECT id, title, slug, status, render_mode FROM pages WHERE id = ? AND site_id = ? LIMIT 1",
            [$pageId, $siteId]
        );
        if ($page === null) return null;
        return [
            'page_id'  => $pageId,
            'title'    => (string) $page['title'],
            'status'   => (string) $page['status'],
            'edit_url' => base_url(($page['render_mode'] ?? '') === 'canvas' ? 'admin/canvas/' . $pageId : 'admin/pages/' . $pageId . '/edit'),
        ];
    }
}
