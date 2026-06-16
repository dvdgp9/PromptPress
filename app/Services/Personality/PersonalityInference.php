<?php

declare(strict_types=1);

namespace App\Services\Personality;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use Core\Database;

/**
 * D-Slice 1 (S1.6) — Orquestador del vector de personalidad de un sitio.
 *
 * Lee las señales del onboarding, aplica extractores ponderados, compone
 * el vector final de 8 ejes (4 skin + 4 layout) y lo materializa en
 * `sites.personality` + `sites.skin_json` vía SkinComposer.
 *
 * En este slice solo activamos 2 extractores (los demás vienen en Slice 4):
 *   - `tone_of_voice` (peso 0.30): mapeo determinista por valor del select.
 *   - `INFER_BRAND_PERSONALITY` (peso 0.25): llamada IA al texto del negocio.
 *
 * Los 4 ejes de layout (density/hierarchy/alignment_bias/compositional_balance)
 * se rellenan a 0.5 (neutro) — Slice 2 activa su uso real.
 */
final class PersonalityInference
{
    /** Mapeo determinista tono → ejes skin (D0f). */
    private const TONE_MAP = [
        'profesional'   => ['warmth' => 0.35, 'formality' => 0.75, 'modernity' => 0.45, 'energy' => 0.45],
        'cercano'       => ['warmth' => 0.85, 'formality' => 0.30, 'modernity' => 0.55, 'energy' => 0.55],
        'tecnico'       => ['warmth' => 0.25, 'formality' => 0.70, 'modernity' => 0.60, 'energy' => 0.40],
        'casual'        => ['warmth' => 0.70, 'formality' => 0.20, 'modernity' => 0.60, 'energy' => 0.65],
        'inspiracional' => ['warmth' => 0.65, 'formality' => 0.45, 'modernity' => 0.65, 'energy' => 0.80],
        'directo'       => ['warmth' => 0.40, 'formality' => 0.50, 'modernity' => 0.55, 'energy' => 0.70],
    ];

    /** Pesos por extractor. */
    private const WEIGHTS = [
        'tone'              => 0.30,
        'text_ai'           => 0.25,
        'logo'              => 0.20,
        'sector_prior'      => 0.15,
        'color_manual'      => 0.15,
        'typography_manual' => 0.10,
        'intent'            => 0.10,
        'radius_manual'     => 0.05,
    ];

    /** Sesgos de intent (D0f). Solo afecta ejes layout salvo energy. */
    private const INTENT_MAP = [
        'presence'  => [],
        'services'  => ['formality' => 0.55, 'density' => 0.60],
        'seo'       => ['hierarchy' => 0.60],
        'portfolio' => ['compositional_balance' => 0.65, 'alignment_bias' => 0.60],
        'product'   => ['energy' => 0.60, 'hierarchy' => 0.60],
    ];

    /** Mapeo determinista de los 13 pares de tipografía (D0f). */
    private const TYPOGRAPHY_MAP = [
        'Inter / Inter'                  => ['warmth' => 0.40, 'formality' => 0.50, 'modernity' => 0.55, 'energy' => 0.50],
        'Plus Jakarta Sans / Inter'      => ['warmth' => 0.45, 'formality' => 0.45, 'modernity' => 0.65, 'energy' => 0.60],
        'Geist / Geist'                  => ['warmth' => 0.40, 'formality' => 0.55, 'modernity' => 0.75, 'energy' => 0.55],
        'Outfit / Inter'                 => ['warmth' => 0.45, 'formality' => 0.40, 'modernity' => 0.65, 'energy' => 0.65],
        'Space Grotesk / DM Sans'        => ['warmth' => 0.30, 'formality' => 0.45, 'modernity' => 0.80, 'energy' => 0.60],
        'Playfair Display / Source Sans 3' => ['warmth' => 0.65, 'formality' => 0.85, 'modernity' => 0.30, 'energy' => 0.40],
        'Manrope / Manrope'              => ['warmth' => 0.40, 'formality' => 0.55, 'modernity' => 0.65, 'energy' => 0.50],
        'Space Grotesk / Inter'          => ['warmth' => 0.35, 'formality' => 0.50, 'modernity' => 0.80, 'energy' => 0.65],
        'DM Sans / DM Sans'              => ['warmth' => 0.65, 'formality' => 0.35, 'modernity' => 0.55, 'energy' => 0.50],
        'Lora / Inter'                   => ['warmth' => 0.70, 'formality' => 0.65, 'modernity' => 0.40, 'energy' => 0.35],
        'Fraunces / Inter'               => ['warmth' => 0.80, 'formality' => 0.65, 'modernity' => 0.55, 'energy' => 0.45],
        'Montserrat / Open Sans'         => ['warmth' => 0.55, 'formality' => 0.50, 'modernity' => 0.50, 'energy' => 0.55],
        'IBM Plex Sans / IBM Plex Sans'  => ['warmth' => 0.30, 'formality' => 0.65, 'modernity' => 0.55, 'energy' => 0.45],
    ];

    /**
     * Pipeline completo: infiere vector, compone skin, persiste todo en `sites`.
     * Idempotente — siempre sobrescribe lo persistido.
     *
     * @return array{personality: array, skin_json: array, sources_used: array}
     */
    public static function compose(int $siteId): array
    {
        $inferred = self::infer($siteId);
        $anchors  = self::loadUserAnchors($siteId);
        $skin     = SkinComposer::compose($inferred['vector'], $anchors);
        self::persist($siteId, $inferred, $skin);
        return [
            'personality'  => self::buildPersonalityPayload($inferred),
            'skin_json'    => $skin,
            'sources_used' => $inferred['sources_used'],
        ];
    }

    /**
     * S1.15 — Anclas del usuario que llegan desde el step 2 del onboarding o
     * desde `/admin/design`. Si están, pisan los valores compuestos por el
     * SkinComposer (primary_color y typography_pair).
     */
    private static function loadUserAnchors(int $siteId): array
    {
        $anchors = [];
        try {
            // Colores y tipografía viven en `design_system` indexado por categoría.
            $rows = \Core\Database::select(
                "SELECT category, tokens FROM design_system WHERE site_id = ? AND category IN ('colors','typography')",
                [$siteId]
            );
            foreach ($rows as $r) {
                $tokens = json_decode((string) $r['tokens'], true);
                if (!is_array($tokens)) continue;
                if ($r['category'] === 'colors' && !empty($tokens['primary'])) {
                    $anchors['primary_color'] = (string) $tokens['primary'];
                } elseif ($r['category'] === 'typography') {
                    $heading = (string) ($tokens['font_heading'] ?? '');
                    $body    = (string) ($tokens['font_body']    ?? '');
                    if ($heading !== '' && $body !== '') {
                        $anchors['typography_pair'] = $heading . ' / ' . $body;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Sin design_system o esquema incompleto: degradamos a sin anclas.
        }
        return $anchors;
    }

    /**
     * Solo infiere. No toca BD. Útil para preview/showcase.
     *
     * @return array{vector: array<string,float>, sources_used: array, confidence: float, sector: string}
     */
    public static function infer(int $siteId): array
    {
        $signals = self::loadSignals($siteId);
        $contributions = [];
        $sourcesUsed = [];

        // Extractor 1 — tone_of_voice (determinista)
        $tone = trim((string) ($signals['tone_of_voice'] ?? ''));
        if ($tone !== '' && isset(self::TONE_MAP[$tone])) {
            $contributions[] = [
                'source' => 'tone:' . $tone,
                'weight' => self::WEIGHTS['tone'],
                'axes'   => self::TONE_MAP[$tone],
            ];
            $sourcesUsed[] = 'tone_of_voice';
        }

        // Extractor 2 — texto IA
        $sector = '';
        $textContext = self::buildBusinessContext($signals);
        if (mb_strlen($textContext) >= 60) {
            try {
                $resp = AIActionRunner::run(
                    Actions::INFER_BRAND_PERSONALITY,
                    ['business_context' => $textContext],
                    $siteId
                );
                $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
                $parsed = self::parseAiResponse($data);
                if ($parsed !== null) {
                    $contributions[] = [
                        'source'     => 'ai_text',
                        'weight'     => self::WEIGHTS['text_ai'],
                        'axes'       => $parsed['axes'],
                        'ai_rationale' => $parsed['rationale'],
                    ];
                    $sector = $parsed['sector'];
                    $sourcesUsed[] = 'ai_text';
                }
            } catch (AIException $e) {
                // Fallo silencioso — log para diagnosticar pero no rompe el pipeline.
                error_log('PersonalityInference AI fail: ' . $e->getMessage());
            } catch (\Throwable $e) {
                error_log('PersonalityInference unexpected: ' . $e->getMessage());
            }
        }

        // ----------- D-Slice 4 — Extractores adicionales -----------

        // Extractor 3 — sector prior (a partir de inferred_sector de la IA).
        // Restringido a ejes SKIN (warmth/formality/modernity/energy):
        // los ejes layout dependen mejor de intent + contenido + páginas
        // anteriores; el sector solo debería sesgar el "look & feel" estético.
        if ($sector !== '') {
            $prior = SectorPriors::priorFor($sector);
            if ($prior !== null) {
                $skinPart = array_intersect_key($prior, array_flip(['warmth','formality','modernity','energy']));
                if (!empty($skinPart)) {
                    $contributions[] = [
                        'source' => 'sector:' . $sector,
                        'weight' => self::WEIGHTS['sector_prior'],
                        'axes'   => $skinPart,
                    ];
                    $sourcesUsed[] = 'sector_prior';
                }
            }
        }

        // Extractor 4 — logo (paleta + saturación + temperatura).
        $logoPath = trim((string) ($signals['_logo_path'] ?? ''));
        if ($logoPath !== '') {
            $abs = self::resolveLogoAbsolutePath($logoPath);
            if ($abs !== null) {
                $logoAnalysis = LogoAnalyzer::analyze($abs);
                if ($logoAnalysis !== null && !empty($logoAnalysis['signals'])) {
                    $contributions[] = [
                        'source' => 'logo:' . basename($logoPath),
                        'weight' => self::WEIGHTS['logo'],
                        'axes'   => $logoAnalysis['signals'],
                    ];
                    $sourcesUsed[] = 'logo';
                }
            }
        }

        // Extractor 5 — intent (sesgo de layout principalmente).
        $intent = (string) ($signals['_intent'] ?? '');
        if ($intent !== '' && !empty(self::INTENT_MAP[$intent] ?? [])) {
            $contributions[] = [
                'source' => 'intent:' . $intent,
                'weight' => self::WEIGHTS['intent'],
                'axes'   => self::INTENT_MAP[$intent],
            ];
            $sourcesUsed[] = 'intent';
        }

        // Extractores 6/7/8 — sólo si el usuario tocó el diseño manualmente
        // (R1 híbrida). Si origin = 'manual' confiamos en sus elecciones
        // como señal fuerte de la personalidad de la marca.
        if (($signals['_design_origin'] ?? '') === 'manual') {
            $tokens = (array) ($signals['_design_tokens'] ?? []);

            // Color: primary + secondary aportan temperatura + saturación.
            $colorAxes = self::extractColorAxes(
                (string) ($tokens['colors']['primary']   ?? ''),
                (string) ($tokens['colors']['secondary'] ?? '')
            );
            if (!empty($colorAxes)) {
                $contributions[] = [
                    'source' => 'color_manual',
                    'weight' => self::WEIGHTS['color_manual'],
                    'axes'   => $colorAxes,
                ];
                $sourcesUsed[] = 'color_manual';
            }

            // Typography pair.
            $heading = (string) ($tokens['typography']['font_heading'] ?? '');
            $body    = (string) ($tokens['typography']['font_body']    ?? '');
            $typoKey = self::resolveTypographyKey($heading, $body);
            if ($typoKey !== null) {
                $contributions[] = [
                    'source' => 'typography:' . $typoKey,
                    'weight' => self::WEIGHTS['typography_manual'],
                    'axes'   => self::TYPOGRAPHY_MAP[$typoKey],
                ];
                $sourcesUsed[] = 'typography_manual';
            }

            // Border radius.
            $radius = (int) ($tokens['buttons']['radius'] ?? -1);
            if ($radius >= 0) {
                $radiusAxes = self::extractRadiusAxes($radius);
                if (!empty($radiusAxes)) {
                    $contributions[] = [
                        'source' => 'radius:' . $radius,
                        'weight' => self::WEIGHTS['radius_manual'],
                        'axes'   => $radiusAxes,
                    ];
                    $sourcesUsed[] = 'radius_manual';
                }
            }
        }

        // Agregador: media ponderada por eje
        $vector = self::aggregate($contributions);

        // Confianza global: suma de pesos usados / suma de pesos máximos
        $maxWeights = array_sum(self::WEIGHTS);
        $usedWeights = array_sum(array_column($contributions, 'weight'));
        $confidence = $maxWeights > 0 ? $usedWeights / $maxWeights : 0.0;

        return [
            'vector'       => $vector,
            'sources_used' => $sourcesUsed,
            'confidence'   => round($confidence, 3),
            'sector'       => $sector,
            'contributions'=> $contributions,
        ];
    }

    // -----------------------------------------------------------------
    // Señales
    // -----------------------------------------------------------------

    /**
     * Carga las señales relevantes del onboarding para los extractores.
     * En este slice solo necesitamos memoria + summary de documento ready.
     */
    private static function loadSignals(int $siteId): array
    {
        $memory = [];
        try {
            $rows = Database::select(
                'SELECT field_key, field_value FROM site_memory WHERE site_id = ?',
                [$siteId]
            );
            foreach ($rows as $r) {
                $memory[(string) $r['field_key']] = (string) $r['field_value'];
            }
        } catch (\Throwable $e) {}

        $documentSummary = '';
        try {
            $doc = Database::selectOne(
                "SELECT summary FROM documents
                 WHERE site_id = ? AND status = 'ready' AND summary IS NOT NULL AND summary <> ''
                 ORDER BY created_at DESC LIMIT 1",
                [$siteId]
            );
            if ($doc) $documentSummary = (string) $doc['summary'];
        } catch (\Throwable $e) {}

        // D-Slice 4 — Settings + design tokens + logo path.
        $settings = [];
        try {
            $rows = Database::select(
                "SELECT setting_key, setting_value FROM settings WHERE site_id = ?
                 AND setting_key IN ('onboarding_intent','design_choice_origin','site_logo_path','palette_preset')",
                [$siteId]
            );
            foreach ($rows as $r) {
                $settings[(string) $r['setting_key']] = (string) $r['setting_value'];
            }
        } catch (\Throwable $e) {}

        // Tokens del design system (para extractores manuales si origin=manual).
        $designTokens = [];
        try {
            $designTokens = \App\Services\DesignSystem::load($siteId);
        } catch (\Throwable $e) {}

        return $memory + [
            'document_summary'   => $documentSummary,
            '_intent'            => $settings['onboarding_intent']     ?? '',
            '_design_origin'     => $settings['design_choice_origin']  ?? '',
            '_logo_path'         => $settings['site_logo_path']        ?? '',
            '_palette_preset'    => $settings['palette_preset']        ?? '',
            '_design_tokens'     => $designTokens,
        ];
    }

    /**
     * Resuelve la ruta absoluta del logo a partir del path relativo guardado
     * en settings.site_logo_path. Devuelve null si no existe.
     */
    private static function resolveLogoAbsolutePath(string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');
        $abs = PP_ROOT . '/' . $rel;
        return is_file($abs) ? $abs : null;
    }

    /**
     * Extrae señales de ejes (warmth, energy, modernity) a partir del color
     * primary + secondary elegidos manualmente.
     *
     * Heurísticas simples:
     *   - HUE cálido del primary → warmth +
     *   - Saturación alta del primary → energy +
     *   - Negro/blanco contrastante → modernity neutra
     *
     * @return array<string,float>
     */
    private static function extractColorAxes(string $primary, string $secondary): array
    {
        $hex = ColorMath::normalizeHex($primary);
        if ($hex === null) return [];

        [$r, $g, $b] = ColorMath::hexToRgb($hex);
        // Calcular HSL del primary.
        $rn = $r / 255.0; $gn = $g / 255.0; $bn = $b / 255.0;
        $max = max($rn, $gn, $bn); $min = min($rn, $gn, $bn);
        $l = ($max + $min) / 2.0;
        $s = 0.0; $h = 0.0;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2.0 - $max - $min) : $d / ($max + $min);
            if ($max === $rn)     $h = (($gn - $bn) / $d) + ($gn < $bn ? 6.0 : 0.0);
            elseif ($max === $gn) $h = (($bn - $rn) / $d) + 2.0;
            else                  $h = (($rn - $gn) / $d) + 4.0;
            $h *= 60.0;
        }

        $axes = [];
        // Temperatura cromática del primary.
        if ($h <= 60 || $h >= 300) $axes['warmth'] = 0.50 + 0.20;
        elseif ($h >= 180 && $h <= 240) $axes['warmth'] = 0.50 - 0.20;

        // Saturación.
        if ($s > 0.70) $axes['energy'] = 0.50 + 0.15;
        elseif ($s < 0.20) $axes['energy'] = 0.50 - 0.10;

        // Clamp [0, 1].
        foreach ($axes as $k => $v) $axes[$k] = max(0.0, min(1.0, $v));
        return $axes;
    }

    /**
     * Resuelve la clave del par de tipografía manual a partir de las fuentes
     * elegidas. Devuelve null si el par no está en TYPOGRAPHY_MAP.
     */
    private static function resolveTypographyKey(string $heading, string $body): ?string
    {
        if ($heading === '' || $body === '') return null;
        $pair = $heading . ' / ' . $body;
        return isset(self::TYPOGRAPHY_MAP[$pair]) ? $pair : null;
    }

    /**
     * Mapea el border-radius (px) a contribuciones de ejes (D0f extractor 7).
     *
     * @return array<string,float>
     */
    private static function extractRadiusAxes(int $radius): array
    {
        if ($radius <= 4) {
            return ['formality' => 0.50 + 0.15];
        }
        if ($radius >= 21) {
            return ['formality' => 0.50 - 0.20, 'warmth' => 0.50 + 0.10, 'energy' => 0.50 + 0.05];
        }
        if ($radius >= 11) {
            return ['formality' => 0.50 - 0.05, 'warmth' => 0.50 + 0.05];
        }
        return []; // [5-10] neutro
    }

    /**
     * Construye el contexto del negocio que se pasa al prompt IA.
     * Cap a ~4000 chars (≈ 1000 tokens) para mantener el coste bajo control.
     */
    private static function buildBusinessContext(array $signals): string
    {
        $parts = [];
        $fields = [
            'business_description'  => 'Qué hace la empresa',
            'target_audience'       => 'Público objetivo',
            'value_proposition'     => 'Propuesta de valor',
            'services'              => 'Servicios',
            'unique_selling_points' => 'Diferenciadores',
            'document_summary'      => 'Resumen del documento adjunto',
        ];
        foreach ($fields as $key => $label) {
            $v = trim((string) ($signals[$key] ?? ''));
            if ($v === '') continue;
            $parts[] = "## $label\n" . $v;
        }
        $context = implode("\n\n", $parts);
        if (mb_strlen($context) > 4000) {
            $context = mb_substr($context, 0, 3997) . '...';
        }
        return $context;
    }

    // -----------------------------------------------------------------
    // Parsing IA
    // -----------------------------------------------------------------

    /**
     * Normaliza la respuesta del modelo. Valida que los 4 ejes sean floats
     * y los clamp a [0, 1]. Devuelve null si la respuesta no es utilizable.
     *
     * @return array{axes:array<string,float>,sector:string,rationale:string}|null
     */
    private static function parseAiResponse(array $data): ?array
    {
        $axes = [];
        foreach (['warmth', 'formality', 'modernity', 'energy'] as $k) {
            if (!isset($data[$k]) || !is_numeric($data[$k])) return null;
            $axes[$k] = max(0.0, min(1.0, (float) $data[$k]));
        }
        return [
            'axes'      => $axes,
            'sector'    => trim((string) ($data['inferred_sector'] ?? '')),
            'rationale' => trim((string) ($data['rationale'] ?? '')),
        ];
    }

    // -----------------------------------------------------------------
    // Agregador
    // -----------------------------------------------------------------

    /**
     * Media ponderada por eje. Si un eje no recibió contribuciones → 0.5 (neutro).
     * Procesa los 8 ejes (4 skin + 4 layout); cada extractor aporta sólo a los
     * ejes que le competen.
     *
     * @return array<string,float>
     */
    private static function aggregate(array $contributions): array
    {
        $allAxes = [
            'warmth', 'formality', 'modernity', 'energy',
            'density', 'hierarchy', 'alignment_bias', 'compositional_balance',
        ];
        $vector = [];
        foreach ($allAxes as $axis) {
            $num = 0.0;
            $den = 0.0;
            foreach ($contributions as $c) {
                if (!isset($c['axes'][$axis])) continue;
                $num += $c['axes'][$axis] * $c['weight'];
                $den += $c['weight'];
            }
            $vector[$axis] = $den > 0 ? round($num / $den, 4) : 0.5;
        }
        return $vector;
    }

    // -----------------------------------------------------------------
    // Persistencia
    // -----------------------------------------------------------------

    private static function buildPersonalityPayload(array $inferred): array
    {
        return [
            'vector'       => $inferred['vector'],
            'sector'       => $inferred['sector'],
            'confidence'   => $inferred['confidence'],
            'sources_used' => $inferred['sources_used'],
            'source'       => 'inferred',
            'inferred_at'  => date('c'),
            'last_adjusted_at' => null,
            'adjustment_log'   => [],
            // Slice 1 expone también las contribuciones brutas para auditoría/debug.
            'contributions'=> array_map(static fn ($c) => [
                'source' => $c['source'],
                'weight' => $c['weight'],
                'axes'   => $c['axes'],
            ], $inferred['contributions']),
        ];
    }

    /**
     * S1.14 — Aplica un nudge a un eje del vector y recompone el skin sin
     * volver a llamar a la IA. Coste: 0 tokens, ~50 ms.
     *
     * @param string $axis      'warmth' | 'modernity' | 'energy'
     * @param string $direction 'up' | 'down'
     * @param float  $step      magnitud del ajuste (default 0.2)
     *
     * @return array{vector: array, skin_json: array, axis: string, direction: string, value_before: float, value_after: float}
     * @throws \InvalidArgumentException si axis/direction no son válidos o el sitio no tiene personality
     */
    public static function applyNudge(int $siteId, string $axis, string $direction, float $step = 0.2): array
    {
        $allowedAxes = ['warmth', 'modernity', 'energy'];
        if (!in_array($axis, $allowedAxes, true)) {
            throw new \InvalidArgumentException('Eje no soportado: ' . $axis);
        }
        if ($direction !== 'up' && $direction !== 'down') {
            throw new \InvalidArgumentException('Dirección inválida: ' . $direction);
        }

        $row = Database::selectOne('SELECT personality FROM sites WHERE id = ? LIMIT 1', [$siteId]);
        $personality = $row && $row['personality'] !== null
            ? json_decode((string) $row['personality'], true)
            : null;
        if (!is_array($personality) || !isset($personality['vector'])) {
            throw new \InvalidArgumentException('El sitio no tiene personality inferida todavía. Llama a compose() primero.');
        }

        $vector = (array) $personality['vector'];
        $before = (float) ($vector[$axis] ?? 0.5);
        $delta  = $direction === 'up' ? $step : -$step;
        $after  = max(0.0, min(1.0, $before + $delta));
        $vector[$axis] = $after;

        // Recompose con anclas del usuario.
        $anchors = self::loadUserAnchors($siteId);
        $skin    = SkinComposer::compose($vector, $anchors);

        // Actualizar payload personality con el ajuste.
        $personality['vector'] = $vector;
        $personality['last_adjusted_at'] = date('c');
        $log = (array) ($personality['adjustment_log'] ?? []);
        $log[] = [
            'axis'         => $axis,
            'direction'    => $direction,
            'step'         => $step,
            'value_before' => $before,
            'value_after'  => $after,
            'at'           => date('c'),
        ];
        $personality['adjustment_log'] = $log;

        // Persistir.
        $personalityJson = json_encode($personality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $skinJson        = json_encode($skin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($personalityJson === false || $skinJson === false) {
            throw new \RuntimeException('Nudge: JSON encode failed: ' . json_last_error_msg());
        }
        Database::execute(
            'UPDATE sites SET personality = ?, skin_json = ?, updated_at = NOW() WHERE id = ?',
            [$personalityJson, $skinJson, $siteId]
        );

        return [
            'vector'       => $vector,
            'skin_json'    => $skin,
            'axis'         => $axis,
            'direction'    => $direction,
            'value_before' => $before,
            'value_after'  => $after,
        ];
    }

    private static function persist(int $siteId, array $inferred, array $skin): void
    {
        $personality = self::buildPersonalityPayload($inferred);
        $personalityJson = json_encode($personality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $skinJson        = json_encode($skin,        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($personalityJson === false || $skinJson === false) {
            throw new \RuntimeException('PersonalityInference: JSON encode failed: ' . json_last_error_msg());
        }
        Database::execute(
            'UPDATE sites SET personality = ?, skin_json = ?, updated_at = NOW() WHERE id = ?',
            [$personalityJson, $skinJson, $siteId]
        );
    }
}
