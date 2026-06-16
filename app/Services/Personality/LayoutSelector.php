<?php

declare(strict_types=1);

namespace App\Services\Personality;

use Core\Database;

/**
 * D-Slice 2 — Selector de layout (variante visual) por sección.
 *
 * Dado el vector de personalidad del sitio y una lista de tipos de sección,
 * elige la mejor variante para cada uno según un score determinista:
 *
 *   score(layout) = 1.0 - euclidean_distance(site_layout_axes, layout_axes)
 *                 - hard_penalty (∞ si incompatible_skin o falta requires)
 *                 + inter_page_bonus(+0.20 si la combinación page_type+section_type
 *                                          ya eligió este layout antes)
 *
 * Bonuses adicionales (pairs_well, content_fit, intent_bonus) entran en Slice 5.
 *
 * Outputs:
 *   - selectForPage(siteId, sections, pageType): mapa [index → variant].
 *   - rememberPage(siteId, pageType, sectionTypeVariants): persiste preferences.
 */
final class LayoutSelector
{
    /** Bonus por mantener coherencia con la elección previa para el mismo page_type. */
    private const INTER_PAGE_BONUS = 0.20;

    /** Bonus si el layout aparece en `pairs_well` de otro ya elegido en esta página. */
    private const PAIRS_WELL_BONUS = 0.15;

    /** Bonus implícito por afinidad de axes con otros ya elegidos (familia visual). */
    private const FAMILY_BONUS_MAX = 0.08;

    /** Bonus por content_fit (heurísticas específicas por tipo). */
    private const CONTENT_FIT_BONUS = 0.10;

    /** Umbral: si std de un eje supera esto entre las elecciones de página, re-seleccionar. */
    private const COHERENCE_STD_THRESHOLD = 0.30;

    /** Confidence por debajo de la cual activamos A/B implícito. */
    private const LOW_CONFIDENCE_THRESHOLD = 0.40;

    /** Orden de importancia compositiva (define el orden de selección). */
    private const SELECTION_ORDER = [
        'hero', 'testimonials', 'benefits', 'steps', 'stats', 'gallery',
        'pricing', 'text_image', 'logos_strip', 'faq', 'form',
        'posts_listing', 'article_body', 'cta', 'generic',
    ];

    /**
     * Selecciona la variante óptima para cada sección de la página.
     *
     * Pipeline (D-Slice 5):
     *   1. Iterar secciones EN ORDEN DE IMPORTANCIA compositiva (no en orden DOM).
     *   2. Cada selección recibe las anteriores como contexto (pairs_well + familia).
     *   3. Calcular std de cada eje sobre las elecciones → si > umbral, re-seleccionar.
     *   4. A/B implícito si confidence del sitio < 0.40 → escoger el set más coherente.
     *
     * @param array<int,array{type:string,content?:array}> $sections sections con tipo (y opcional content para checks de requires)
     * @return array<int,array{type:string,variant:string,score:float,alternatives:array}> indexado por posición original
     */
    public static function selectForPage(int $siteId, array $sections, string $pageType = 'landing'): array
    {
        $vector = self::loadSiteVector($siteId);
        $skin   = self::loadSiteSkinVector($siteId);
        $preferences = self::loadPreferences($siteId, $pageType);
        $confidence = self::loadSiteConfidence($siteId);

        // Ordenar índices por importancia compositiva.
        $sortedIdx = self::sortByImportance($sections);

        $primary = self::selectInOrder($sections, $sortedIdx, $vector, $skin, $preferences);

        // Re-selección por coherencia: si algún eje tiene std > umbral, marcar outliers
        // y penalizar para reseleccionar. Máx 2 iteraciones.
        $primary = self::enforceCoherence($primary, $sections, $sortedIdx, $vector, $skin, $preferences);

        // A/B implícito si confidence baja.
        if ($confidence < self::LOW_CONFIDENCE_THRESHOLD) {
            $alt = self::selectInOrder(
                $sections, $sortedIdx, $vector, $skin, $preferences,
                /* avoidPairsWellBonus */ true
            );
            $alt = self::enforceCoherence($alt, $sections, $sortedIdx, $vector, $skin, $preferences);

            $stdPrimary = self::maxAxisStd(self::collectAxes($primary));
            $stdAlt     = self::maxAxisStd(self::collectAxes($alt));
            if ($stdAlt < $stdPrimary) $primary = $alt;
        }

        return $primary;
    }

    /**
     * Itera las secciones en el orden dado, acumulando lo elegido para pasarlo como
     * contexto a las siguientes.
     *
     * @param array<int,array> $sections
     * @param int[]            $sortedIdx
     * @return array<int,array{type:string,variant:string,score:float,alternatives:array}> indexado por posición original
     */
    private static function selectInOrder(
        array $sections,
        array $sortedIdx,
        array $vector,
        array $skin,
        array $preferences,
        bool $avoidPairsWellBonus = false
    ): array {
        $selections = [];
        $chosenSoFar = [];
        foreach ($sortedIdx as $origIdx) {
            $s = $sections[$origIdx];
            $type = (string) ($s['type'] ?? '');
            $content = (array) ($s['content'] ?? []);
            $pick = self::selectForSection(
                $type, $content, $vector, $skin, $preferences,
                $chosenSoFar,
                /* penalize */ [],
                $avoidPairsWellBonus
            );
            $selections[$origIdx] = [
                'type'         => $type,
                'variant'      => $pick['variant'],
                'score'        => $pick['score'],
                'alternatives' => $pick['alternatives'],
            ];
            $chosenSoFar[] = ['type' => $type, 'variant' => $pick['variant']];
        }
        ksort($selections);
        return $selections;
    }

    /**
     * Devuelve los índices de $sections ordenados por importancia compositiva.
     * El primer hero gana primero, los CTA salen al final.
     *
     * @return int[]
     */
    private static function sortByImportance(array $sections): array
    {
        $rank = array_flip(self::SELECTION_ORDER);
        $idx = array_keys($sections);
        usort($idx, static function (int $a, int $b) use ($sections, $rank) {
            $ta = (string) ($sections[$a]['type'] ?? 'generic');
            $tb = (string) ($sections[$b]['type'] ?? 'generic');
            return ($rank[$ta] ?? 99) <=> ($rank[$tb] ?? 99);
        });
        return $idx;
    }

    /**
     * Si algún eje tiene std > umbral entre las elecciones, re-selecciona penalizando
     * los layouts más outliers.
     */
    private static function enforceCoherence(
        array $selections,
        array $sections,
        array $sortedIdx,
        array $vector,
        array $skin,
        array $preferences,
        int $maxIterations = 2
    ): array {
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $axesList = self::collectAxes($selections);
            $maxStd = self::maxAxisStd($axesList);
            if ($maxStd <= self::COHERENCE_STD_THRESHOLD) {
                return $selections;
            }
            // Identificar los layouts outliers: aquellos cuyo axes está más alejado
            // del centroide. Penalizamos esa elección concreta y reseleccionamos.
            $centroid = self::axesCentroid($axesList);
            $outliers = [];
            foreach ($selections as $idx => $sel) {
                $meta = LayoutCatalog::get((string) $sel['type'], (string) $sel['variant']);
                if (!$meta) continue;
                $d = self::axesDistance((array) ($meta['axes'] ?? []), $centroid);
                if ($d > self::COHERENCE_STD_THRESHOLD) {
                    $outliers[$idx] = (string) $sel['variant'];
                }
            }
            if ($outliers === []) return $selections;

            // Re-seleccionar penalizando los outliers de la pasada anterior.
            $newSelections = [];
            $chosenSoFar = [];
            foreach ($sortedIdx as $origIdx) {
                $s = $sections[$origIdx];
                $type = (string) ($s['type'] ?? '');
                $content = (array) ($s['content'] ?? []);
                $penalize = [];
                if (isset($outliers[$origIdx])) {
                    $penalize[] = $outliers[$origIdx];
                }
                $pick = self::selectForSection(
                    $type, $content, $vector, $skin, $preferences,
                    $chosenSoFar, $penalize, false
                );
                $newSelections[$origIdx] = [
                    'type'         => $type,
                    'variant'      => $pick['variant'],
                    'score'        => $pick['score'],
                    'alternatives' => $pick['alternatives'],
                ];
                $chosenSoFar[] = ['type' => $type, 'variant' => $pick['variant']];
            }
            ksort($newSelections);
            $selections = $newSelections;
        }
        return $selections;
    }

    // -----------------------------------------------------------------
    // Helpers numéricos
    // -----------------------------------------------------------------

    /** @param array<int,array> $selections */
    private static function collectAxes(array $selections): array
    {
        $axes = [];
        foreach ($selections as $sel) {
            $meta = LayoutCatalog::get((string) $sel['type'], (string) $sel['variant']);
            if ($meta && isset($meta['axes'])) $axes[] = (array) $meta['axes'];
        }
        return $axes;
    }

    /** @param array<int,array<string,float>> $axesList */
    private static function maxAxisStd(array $axesList): float
    {
        $keys = ['density', 'hierarchy', 'alignment_bias', 'compositional_balance'];
        if (count($axesList) < 2) return 0.0;
        $maxStd = 0.0;
        foreach ($keys as $k) {
            $vals = [];
            foreach ($axesList as $a) $vals[] = (float) ($a[$k] ?? 0.5);
            $mean = array_sum($vals) / count($vals);
            $sumSq = 0.0;
            foreach ($vals as $v) $sumSq += ($v - $mean) ** 2;
            $std = sqrt($sumSq / count($vals));
            if ($std > $maxStd) $maxStd = $std;
        }
        return $maxStd;
    }

    /** @param array<int,array<string,float>> $axesList */
    private static function axesCentroid(array $axesList): array
    {
        $keys = ['density', 'hierarchy', 'alignment_bias', 'compositional_balance'];
        $sum = array_fill_keys($keys, 0.0);
        $n = max(1, count($axesList));
        foreach ($axesList as $a) {
            foreach ($keys as $k) $sum[$k] += (float) ($a[$k] ?? 0.5);
        }
        foreach ($keys as $k) $sum[$k] /= $n;
        return $sum;
    }

    /** Distancia euclídea entre dos axes (4D). */
    private static function axesDistance(array $a, array $b): float
    {
        $keys = ['density', 'hierarchy', 'alignment_bias', 'compositional_balance'];
        $sq = 0.0;
        foreach ($keys as $k) {
            $sq += (((float) ($a[$k] ?? 0.5)) - ((float) ($b[$k] ?? 0.5))) ** 2;
        }
        return sqrt($sq);
    }

    private static function loadSiteConfidence(int $siteId): float
    {
        try {
            $row = Database::selectOne('SELECT personality FROM sites WHERE id = ?', [$siteId]);
            if (!$row || $row['personality'] === null) return 0.0;
            $p = json_decode((string) $row['personality'], true);
            return is_array($p) ? (float) ($p['confidence'] ?? 0.0) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Selecciona la mejor variante para UNA sección concreta.
     *
     * Score = 1.0 − distance
     *        + inter_page_bonus  (si matchea preference)
     *        + pairs_well_bonus  (si está en pairs_well de algún chosenSoFar)
     *        + family_bonus      (afinidad implícita por axes con chosenSoFar)
     *        + content_fit_bonus (heurísticas por tipo/contenido)
     *        − penalty_per_outlier (0.15 cada vez que aparece en $penalize)
     *
     * @param array<int,array{type:string,variant:string}> $chosenSoFar elecciones previas en esta página
     * @param string[]                                     $penalize    variantes a penalizar (re-selección)
     * @return array{variant:string,score:float,alternatives:array<int,array{variant:string,score:float}>}
     */
    public static function selectForSection(
        string $type,
        array $content,
        array $layoutVector,
        array $skinVector,
        array $preferences = [],
        array $chosenSoFar = [],
        array $penalize = [],
        bool $avoidPairsWellBonus = false
    ): array {
        $candidates = LayoutCatalog::variantsFor($type);
        $scored = [];
        $preferredVariant = $preferences[$type] ?? null;

        // Pre-cálculo: lista de slugs ya elegidos con formato `type/variant` para pairs_well.
        $chosenSlugs = array_map(
            static fn ($c) => ((string) $c['type']) . '/' . ((string) $c['variant']),
            $chosenSoFar
        );

        foreach ($candidates as $variant => $meta) {
            // Hard penalty: requires.
            $requires = (array) ($meta['requires'] ?? []);
            $missing = false;
            foreach ($requires as $field) {
                $v = $content[$field] ?? '';
                if (!is_string($v) || trim($v) === '') {
                    $missing = true;
                    break;
                }
            }
            if ($missing) continue;

            // Hard penalty: incompatible skin.
            $rules = (array) ($meta['incompatible_skin'] ?? []);
            if (!empty($rules) && LayoutCatalog::isIncompatibleWithSkin($rules, $skinVector)) {
                continue;
            }

            // Distancia euclídea sobre los 4 ejes de layout.
            $axes = (array) ($meta['axes'] ?? []);
            $distance = sqrt(
                (((float) ($axes['density'] ?? 0.5))               - ((float) ($layoutVector['density'] ?? 0.5)))               ** 2
              + (((float) ($axes['hierarchy'] ?? 0.5))             - ((float) ($layoutVector['hierarchy'] ?? 0.5)))             ** 2
              + (((float) ($axes['alignment_bias'] ?? 0.5))        - ((float) ($layoutVector['alignment_bias'] ?? 0.5)))        ** 2
              + (((float) ($axes['compositional_balance'] ?? 0.5)) - ((float) ($layoutVector['compositional_balance'] ?? 0.5))) ** 2
            );

            $score = 1.0 - $distance;

            // Inter-page bonus: misma elección que páginas anteriores del mismo page_type.
            if ($preferredVariant !== null && $preferredVariant === $variant) {
                $score += self::INTER_PAGE_BONUS;
            }

            // pairs_well_bonus: si el meta declara compatibilidad con algún slug ya elegido.
            if (!$avoidPairsWellBonus && !empty($meta['pairs_well']) && is_array($meta['pairs_well'])) {
                foreach ($meta['pairs_well'] as $pair) {
                    if (in_array((string) $pair, $chosenSlugs, true)) {
                        $score += self::PAIRS_WELL_BONUS;
                        break;
                    }
                }
            }

            // family_bonus: afinidad implícita por axes con elecciones previas (familia visual).
            if ($chosenSoFar !== []) {
                $family = self::computeFamilyBonus($axes, $chosenSoFar);
                $score += $family;
            }

            // content_fit_bonus: heurísticas específicas por tipo.
            $score += self::computeContentFitBonus($type, $variant, $content);

            // Penalty por outlier (re-selección).
            if (in_array($variant, $penalize, true)) {
                $score -= 0.30;
            }

            $scored[] = [
                'variant' => $variant,
                'score'   => round($score, 4),
            ];
        }

        // Si por hard penalty no queda ninguno → forzar 'default' (siempre existe).
        if ($scored === []) {
            return [
                'variant' => 'default',
                'score'   => 0.0,
                'alternatives' => [],
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_shift($scored);
        return [
            'variant'      => $top['variant'],
            'score'        => $top['score'],
            'alternatives' => array_slice($scored, 0, 2),
        ];
    }

    /**
     * Calcula un bonus implícito proporcional a lo cerca que está el axes del
     * layout candidato del axes promedio de los ya elegidos.
     *
     * @param array<string,float>                         $axes
     * @param array<int,array{type:string,variant:string}> $chosenSoFar
     */
    private static function computeFamilyBonus(array $axes, array $chosenSoFar): float
    {
        $chosenAxes = [];
        foreach ($chosenSoFar as $c) {
            $meta = LayoutCatalog::get((string) $c['type'], (string) $c['variant']);
            if ($meta && isset($meta['axes'])) $chosenAxes[] = (array) $meta['axes'];
        }
        if ($chosenAxes === []) return 0.0;
        $centroid = self::axesCentroid($chosenAxes);
        $d = self::axesDistance($axes, $centroid);
        // d ≈ 0 → bonus máximo; d ≥ 0.5 → bonus 0.
        $bonus = self::FAMILY_BONUS_MAX * max(0.0, 1.0 - ($d / 0.5));
        return $bonus;
    }

    /**
     * Heurísticas content_fit (D0i): premiar layouts que aprovechan especialmente
     * bien el contenido disponible. Ej. testimonials/quote-wall si hay muchos items.
     */
    private static function computeContentFitBonus(string $type, string $variant, array $content): float
    {
        $itemsCount = is_array($content['items'] ?? null) ? count($content['items']) : 0;

        if ($type === 'testimonials' && $variant === 'quote-wall' && $itemsCount >= 6) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'gallery' && $variant === 'mosaic' && $itemsCount >= 8) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'pricing' && $variant === 'comparison' && $itemsCount >= 3) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'posts_listing' && $variant === 'featured-first') {
            $limit = (int) ($content['limit'] ?? 6);
            if ($limit >= 4) return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'benefits' && $variant === 'proof-strip' && $itemsCount >= 5) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'logos_strip' && $variant === 'marquee' && $itemsCount >= 6) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'steps' && $variant === 'horizontal' && $itemsCount >= 3 && $itemsCount <= 5) {
            return self::CONTENT_FIT_BONUS;
        }
        if ($type === 'faq' && $variant === 'two-columns' && $itemsCount >= 8) {
            return self::CONTENT_FIT_BONUS;
        }
        return 0.0;
    }

    /**
     * Persiste las variantes elegidas en `sites.layout_preferences` agrupadas
     * por `page_type` para que páginas futuras del mismo tipo "respiren igual".
     *
     * @param array<int,array{type:string,variant:string}> $chosen secuencia de selecciones de la página
     */
    public static function rememberPage(int $siteId, string $pageType, array $chosen): void
    {
        try {
            $row = Database::selectOne('SELECT layout_preferences FROM sites WHERE id = ?', [$siteId]);
            $prefs = $row && $row['layout_preferences'] !== null
                ? (json_decode((string) $row['layout_preferences'], true) ?: [])
                : [];

            if (!isset($prefs[$pageType]) || !is_array($prefs[$pageType])) {
                $prefs[$pageType] = [];
            }

            foreach ($chosen as $sel) {
                $type = (string) ($sel['type'] ?? '');
                $variant = (string) ($sel['variant'] ?? '');
                if ($type === '' || $variant === '') continue;
                // Política "first-write wins": la primera página de un page_type fija
                // la preferencia; las siguientes la respetan (vía bonus inter-page).
                // Solo se actualiza si NO existía aún para evitar volatilidad.
                if (!isset($prefs[$pageType][$type])) {
                    $prefs[$pageType][$type] = $variant;
                }
            }

            Database::execute(
                'UPDATE sites SET layout_preferences = ?, updated_at = NOW() WHERE id = ?',
                [json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $siteId]
            );
        } catch (\Throwable $e) {
            // No bloqueante.
            error_log('LayoutSelector::rememberPage failed: ' . $e->getMessage());
        }
    }

    /**
     * Lee las preferencias previas para un `page_type` dado.
     * @return array<string,string> mapa section_type → variant_slug
     */
    public static function loadPreferences(int $siteId, string $pageType): array
    {
        try {
            $row = Database::selectOne('SELECT layout_preferences FROM sites WHERE id = ?', [$siteId]);
            if (!$row || $row['layout_preferences'] === null) return [];
            $prefs = json_decode((string) $row['layout_preferences'], true);
            if (!is_array($prefs)) return [];
            return (array) ($prefs[$pageType] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -----------------------------------------------------------------
    // Vectores del sitio
    // -----------------------------------------------------------------

    /**
     * Lee los 4 ejes layout del `sites.personality.vector`.
     * Fallback: 0.5 si no hay vector.
     */
    private static function loadSiteVector(int $siteId): array
    {
        try {
            $row = Database::selectOne('SELECT personality FROM sites WHERE id = ?', [$siteId]);
            $p = is_array(json_decode((string) ($row['personality'] ?? ''), true))
                ? json_decode((string) $row['personality'], true)
                : null;
            $v = is_array($p) ? (array) ($p['vector'] ?? []) : [];
            return [
                'density'               => (float) ($v['density']               ?? 0.5),
                'hierarchy'             => (float) ($v['hierarchy']             ?? 0.5),
                'alignment_bias'        => (float) ($v['alignment_bias']        ?? 0.5),
                'compositional_balance' => (float) ($v['compositional_balance'] ?? 0.5),
            ];
        } catch (\Throwable $e) {
            return ['density' => 0.5, 'hierarchy' => 0.5, 'alignment_bias' => 0.5, 'compositional_balance' => 0.5];
        }
    }

    /**
     * Lee los 4 ejes skin del `sites.personality.vector` (para hard penalties
     * de `incompatible_skin`).
     */
    private static function loadSiteSkinVector(int $siteId): array
    {
        try {
            $row = Database::selectOne('SELECT personality FROM sites WHERE id = ?', [$siteId]);
            $p = is_array(json_decode((string) ($row['personality'] ?? ''), true))
                ? json_decode((string) $row['personality'], true)
                : null;
            $v = is_array($p) ? (array) ($p['vector'] ?? []) : [];
            return [
                'warmth'    => (float) ($v['warmth']    ?? 0.5),
                'formality' => (float) ($v['formality'] ?? 0.5),
                'modernity' => (float) ($v['modernity'] ?? 0.5),
                'energy'    => (float) ($v['energy']    ?? 0.5),
            ];
        } catch (\Throwable $e) {
            return ['warmth' => 0.5, 'formality' => 0.5, 'modernity' => 0.5, 'energy' => 0.5];
        }
    }
}
