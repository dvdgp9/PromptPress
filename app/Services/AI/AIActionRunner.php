<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\SectionSchemas;

/**
 * Orquesta la ejecución de una acción IA end-to-end (T6.3):
 *
 *   Actions::get()  →  PromptBuilder::forAction()  →  Provider::chat()  →  parse/validate
 *
 * La salida está siempre normalizada:
 *   [
 *     'ok'       => true,
 *     'action'   => 'generate_section',
 *     'output'   => 'json'|'text',
 *     'data'     => mixed,     // objeto/array si json, string si text
 *     'warnings' => string[],  // avisos no fatales (ej. campos extra ignorados)
 *     'provider' => 'openai',
 *     'model'    => 'gpt-4o-mini',
 *     'tokens_in' => 754, 'tokens_out' => 312,
 *     'latency_ms' => 1843,
 *     'meta'     => [ memory_fields_used, documents_used ],
 *   ]
 */
final class AIActionRunner
{
    /**
     * @param string              $action
     * @param array<string,mixed> $input
     * @param int                 $siteId
     * @return array<string,mixed>
     *
     * @throws AIException si no hay provider configurado, si la acción es inválida,
     *                     si la llamada falla, o si el output no valida.
     */
    public static function run(string $action, array $input, int $siteId): array
    {
        $def = Actions::get($action);
        if ($def === null) {
            throw new AIException('Acción de IA desconocida: ' . $action);
        }

        // Contexto extra por tipo de acción
        $extras = [];
        if ($action === Actions::GENERATE_SECTION) {
            $sectionType = (string) ($input['section_type'] ?? '');
            $extras['section_schema']   = self::renderSectionSchemaHint($sectionType);
            $extras['available_icons']  = implode(', ', \App\Services\Renderer\Icons::names());
        }
        if ($action === Actions::GENERATE_PAGE_STRUCTURE || $action === Actions::RECREATE_FROM_REFERENCE) {
            $all = SectionSchemas::all();
            $extras['available_section_types'] = implode(', ', array_keys($all));
            $map = [];
            foreach ($all as $type => $schema) {
                $variants = array_keys((array) ($schema['variants'] ?? ['default' => 'Por defecto']));
                if (!in_array('default', $variants, true)) {
                    array_unshift($variants, 'default');
                }
                $map[] = $type . ': ' . implode(', ', $variants);
            }
            $extras['variants_by_type'] = implode("\n", $map);
        }
        if ($action === Actions::PROPOSE_LAYOUT_VARIATIONS) {
            $all = SectionSchemas::all();
            $extras['available_section_types'] = implode(', ', array_keys($all));
            $map = [];
            foreach ($all as $type => $schema) {
                $variants = array_keys((array) ($schema['variants'] ?? ['default' => 'Por defecto']));
                if (!in_array('default', $variants, true)) {
                    array_unshift($variants, 'default');
                }
                $map[] = $type . ': ' . implode(', ', $variants);
            }
            $extras['variants_by_type'] = implode("\n", $map);
        }

        if ($action === Actions::DESIGN_FORM || $action === Actions::DRAFT_FORM_AUTORESPONDER) {
            // Tokens literales que el modelo debe emitir tal cual en la autorrespuesta.
            // Se pasan como variables para que sobrevivan a la plantilla {key} de PromptBuilder
            // (si los pusiéramos como {{nombre}} en la instrucción, el motor los vaciaría).
            $extras['name_token'] = '{{nombre}}';
            $extras['site_token'] = '{{sitio}}';
        }

        $built = PromptBuilder::forAction($action, $input, $siteId, $extras);

        // Visión (MBv1): si el input trae imágenes, se hilan a las options para que
        // el provider (OpenRouter/OpenAI) las adjunte en formato multimodal.
        if (!empty($input['_images']) && is_array($input['_images'])) {
            $built['options']['images'] = $input['_images'];
        }

        $provider = AIProviderFactory::currentForAction($siteId, $action);
        if ($provider === null) {
            throw new AIException('No hay proveedor de IA configurado para este sitio.');
        }

        try {
            $resp = $provider->chat($built['messages'], $built['options']);
        } catch (AIException $e) {
            AILogger::logError(
                $siteId,
                $provider->getName(),
                $provider->getModel(),
                $action,
                $e->getMessage(),
                null,
                ['input' => $input]
            );
            throw $e;
        }

        $outputType = (string) ($def['output'] ?? 'text');
        $warnings = [];

        try {
            if ($outputType === 'json') {
                $data = self::parseJsonStrict($resp->content);
                $warnings = self::validateActionOutput($action, $input, $data);
            } else {
                $data = trim($resp->content);
            }
        } catch (AIException $e) {
            AILogger::logError(
                $siteId,
                $resp->provider,
                $resp->model,
                $action,
                'Parse/validate: ' . $e->getMessage(),
                $resp->latencyMs,
                ['input' => $input, 'raw_content' => $resp->content]
            );
            throw $e;
        }

        AILogger::logSuccess(
            $siteId,
            $resp->provider,
            $resp->model,
            $action,
            $resp->tokensIn,
            $resp->tokensOut,
            $resp->latencyMs,
            ['input' => $input, 'meta' => $built['meta']],
            ['data' => $data, 'warnings' => $warnings],
        );

        return [
            'ok'         => true,
            'action'     => $action,
            'output'     => $outputType,
            'data'       => $data,
            'warnings'   => $warnings,
            'provider'   => $resp->provider,
            'model'      => $resp->model,
            'tokens_in'  => $resp->tokensIn,
            'tokens_out' => $resp->tokensOut,
            'estimated_cost' => AIPricing::costFor($resp->model, $resp->tokensIn, $resp->tokensOut),
            'latency_ms' => $resp->latencyMs,
            'meta'       => $built['meta'],
        ];
    }

    // ======================================================================
    // Output validators
    // ======================================================================

    /** @return string[] warnings */
    private static function validateActionOutput(string $action, array $input, mixed $data): array
    {
        $warnings = [];
        if (!is_array($data)) {
            throw new AIException("El modelo no devolvió un objeto JSON para la acción '$action'");
        }

        return match ($action) {
            Actions::GENERATE_SECTION        => self::validateSection((string) ($input['section_type'] ?? ''), $data),
            Actions::IMPROVE_SEO             => self::validateSeo($data),
            Actions::GENERATE_PAGE_STRUCTURE => self::validatePageStructure($data),
            Actions::DISCOVER_PAGE_OPPORTUNITIES => self::validatePageOpportunities($data),
            Actions::GENERATE_PAGE_BRIEF     => self::validatePageBrief($data),
            Actions::ANALYZE_SITE_ARCHITECTURE => self::validateArchitecture($data),
            Actions::EXTRACT_BUSINESS_PROFILE => self::validateBusinessProfile($data),
            Actions::PROPOSE_LAYOUT_VARIATIONS => self::validateLayoutVariations($input, $data),
            Actions::GENERATE_CUSTOM_BLOCK_FROM_REFERENCE => self::validateCustomBlockDraft($data),
            Actions::COMPOSE_CUSTOM_PAGE_FROM_REFERENCE => self::validateCustomPageDraft($data),
            Actions::COMPOSE_CANVAS_PAGE => self::validateCanvasDraft($data),
            Actions::EDIT_CANVAS_SECTION, Actions::EDIT_CANVAS_PAGE => self::validateCanvasEdit($data),
            default                          => $warnings,
        };
    }

    /**
     * FH3 — shape mínimo de una edición conversacional canvas.
     *
     * Una edición es válida si trae HTML reescrito O bien CSS (cambio solo de
     * estilo). Forzar HTML siempre obligaba al modelo a reescribir secciones
     * enteras —caro, propenso a truncado y a destrozar ilustraciones SVG—.
     * Permitir "html" vacío con css/css_append deja que un cambio puramente de
     * estilo se exprese solo en CSS, conservando el HTML original intacto.
     */
    private static function validateCanvasEdit(array $data): array
    {
        $hasHtml = trim((string) ($data['html'] ?? '')) !== '';
        $hasCss  = trim((string) ($data['css_append'] ?? '')) !== ''
                || trim((string) ($data['css'] ?? '')) !== '';
        if (!$hasHtml && !$hasCss) {
            throw new AIException('La edición no contiene ni "html" ni "css".');
        }
        return [];
    }

    /** FH2 — shape mínimo de la página canvas; el sanitizado vive en CanvasService. */
    private static function validateCanvasDraft(array $data): array
    {
        if (trim((string) ($data['html'] ?? '')) === '') {
            throw new AIException('La respuesta no contiene "html" para la página canvas.');
        }
        $warnings = [];
        if (trim((string) ($data['css'] ?? '')) === '') {
            $warnings[] = 'La página canvas no trae "css" (se renderizará solo con estilos base).';
        }
        return $warnings;
    }

    /** D-MB2 R3 — shape mínimo de la página compuesta; el sanitizado por sección vive fuera. */
    private static function validateCustomPageDraft(array $data): array
    {
        $sections = $data['sections'] ?? null;
        if (!is_array($sections) || $sections === []) {
            throw new AIException('La respuesta no contiene "sections" para la página compuesta.');
        }
        foreach (array_values($sections) as $i => $section) {
            if (!is_array($section) || trim((string) ($section['html'] ?? '')) === '') {
                throw new AIException('La sección ' . ($i + 1) . ' de la página compuesta no contiene "html".');
            }
        }
        return [];
    }

    /** Valida el shape mínimo del borrador PP-friendly; el sanitizer final vive fuera del runner. */
    private static function validateCustomBlockDraft(array $data): array
    {
        if (trim((string) ($data['html'] ?? '')) === '') {
            throw new AIException('La respuesta no contiene "html" para el bloque custom.');
        }
        if (!isset($data['rationale']) || !is_array($data['rationale'])) {
            throw new AIException('La respuesta no contiene "rationale" válido.');
        }

        $warnings = [];
        foreach (['summary', 'reference_takeaways', 'brand_application'] as $key) {
            if (!array_key_exists($key, $data['rationale'])) {
                $warnings[] = "rationale.$key no está presente.";
            }
        }
        if (str_contains(strtolower((string) $data['html']), '<section')) {
            $warnings[] = 'El HTML incluye <section>; el sanitizer lo intentará desenvolver.';
        }
        return $warnings;
    }

    /** Valida shape de variaciones de layout y coherencia con el layout actual. */
    private static function validateLayoutVariations(array $input, array $data): array
    {
        if (!isset($data['variations']) || !is_array($data['variations']) || count($data['variations']) < 1) {
            throw new AIException('La respuesta no contiene un array "variations" válido.');
        }

        $layout = (array) ($input['sections_layout_data'] ?? []);
        if ($layout === []) {
            return [];
        }

        $expectedCount = count($layout);
        $expectedTypes = array_count_values(array_map(
            static fn($s) => (string) ($s['type'] ?? ''),
            $layout
        ));

        foreach ($data['variations'] as $idx => $variation) {
            if (!is_array($variation)) {
                throw new AIException('Variación #' . ($idx + 1) . ' no es un objeto válido.');
            }
            $sections = (array) ($variation['sections'] ?? []);
            if (count($sections) !== $expectedCount) {
                throw new AIException('Variación #' . ($idx + 1) . ' no mantiene el número de secciones.');
            }

            $types = [];
            foreach ($sections as $sIdx => $s) {
                if (!is_array($s)) {
                    throw new AIException('Variación #' . ($idx + 1) . ', sección #' . ($sIdx + 1) . ' inválida.');
                }
                $type = (string) ($s['type'] ?? '');
                if (!isset(SectionSchemas::all()[$type])) {
                    throw new AIException('Variación #' . ($idx + 1) . ' contiene tipo no soportado: ' . $type);
                }
                $variant = SectionSchemas::normalizeVariant($type, (string) ($s['variant'] ?? 'default'));
                $data['variations'][$idx]['sections'][$sIdx]['variant'] = $variant;
                $types[] = $type;
            }

            if (array_count_values($types) !== $expectedTypes) {
                throw new AIException('Variación #' . ($idx + 1) . ' cambia el set de tipos permitido.');
            }
        }

        return [];
    }

    /** Valida un section content contra el schema de su tipo. */
    private static function validateSection(string $type, array $data): array
    {
        $warnings = [];
        $schemas  = SectionSchemas::all();
        $schema   = $schemas[$type] ?? null;
        if ($schema === null) {
            throw new AIException("Tipo de sección desconocido: $type");
        }

        $allowedKeys = [];
        foreach ($schema['fields'] as $f) {
            $allowedKeys[] = $f['key'];
        }

        // Detectar al menos una clave válida (sanity check)
        $overlap = array_intersect(array_keys($data), $allowedKeys);
        if ($overlap === []) {
            throw new AIException(
                "El JSON generado no contiene ningún campo del schema '$type'. "
              . "Claves devueltas: " . implode(', ', array_keys($data))
            );
        }

        // Claves extra: solo warning, no fatal
        $extra = array_diff(array_keys($data), $allowedKeys);
        foreach ($extra as $k) {
            $warnings[] = "Campo '$k' no existe en el schema '$type' y será ignorado al guardar.";
        }

        return $warnings;
    }

    /** Valida shape de improve_seo. */
    private static function validateSeo(array $data): array
    {
        $required = ['seo_title', 'meta_description', 'slug'];
        $missing  = array_diff($required, array_keys($data));
        if ($missing !== []) {
            throw new AIException('Faltan campos en la respuesta SEO: ' . implode(', ', $missing));
        }
        $warnings = [];
        if (mb_strlen((string) $data['seo_title']) > 60) {
            $warnings[] = 'seo_title excede 60 chars (' . mb_strlen((string) $data['seo_title']) . ').';
        }
        if (mb_strlen((string) $data['meta_description']) > 160) {
            $warnings[] = 'meta_description excede 160 chars.';
        }
        if (!preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#', (string) $data['slug'])) {
            $warnings[] = "slug contiene caracteres no permitidos (solo minúsculas, números, guiones y barras internas).";
        }
        return $warnings;
    }

    /** Valida shape de generate_page_structure. */
    private static function validatePageStructure(array $data): array
    {
        if (!isset($data['sections']) || !is_array($data['sections']) || $data['sections'] === []) {
            throw new AIException('La respuesta no contiene un array "sections" no vacío.');
        }
        $valid = array_keys(SectionSchemas::all());
        $warnings = [];
        foreach ($data['sections'] as $i => $s) {
            if (!is_array($s) || !isset($s['type']) || !in_array($s['type'], $valid, true)) {
                throw new AIException("Sección #$i tiene un 'type' inválido o faltante.");
            }
            $data['sections'][$i]['variant'] = SectionSchemas::normalizeVariant(
                (string) $s['type'],
                (string) ($s['variant'] ?? 'default')
            );
        }
        return $warnings;
    }

    /** Valida shape de discover_page_opportunities sin bloquear por detalles menores. */
    private static function validatePageOpportunities(array $data): array
    {
        if (!isset($data['opportunities']) || !is_array($data['opportunities'])) {
            throw new AIException('La respuesta no contiene un array "opportunities".');
        }

        $warnings = [];
        foreach ($data['opportunities'] as $i => $item) {
            if (!is_array($item)) {
                throw new AIException("Oportunidad #$i no es un objeto válido.");
            }
            foreach (['title', 'goal', 'reason'] as $key) {
                if (trim((string) ($item[$key] ?? '')) === '') {
                    throw new AIException("Oportunidad #$i no contiene '$key'.");
                }
            }
        }
        return $warnings;
    }

    /** Valida shape del brief guiado. */
    private static function validatePageBrief(array $data): array
    {
        foreach (['title', 'page_type', 'goal', 'sections'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new AIException("Falta '$key' en el brief de página.");
            }
        }
        if (!is_array($data['sections']) || $data['sections'] === []) {
            throw new AIException('El brief no contiene secciones válidas.');
        }

        $validTypes = array_keys(SectionSchemas::all());
        $warnings = [];
        foreach ($data['sections'] as $i => $section) {
            if (!is_array($section) || trim((string) ($section['type'] ?? '')) === '') {
                throw new AIException("Sección #$i del brief no tiene tipo.");
            }
            if (!in_array((string) $section['type'], $validTypes, true)) {
                throw new AIException("Sección #$i del brief usa un tipo no soportado.");
            }
        }
        return $warnings;
    }

    /** Valida shape mínimo del análisis de arquitectura. */
    private static function validateArchitecture(array $data): array
    {
        foreach (['summary', 'missing_pages', 'diagnostics'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new AIException("Falta '$key' en el análisis de arquitectura.");
            }
        }
        if (!is_array($data['missing_pages']) || !is_array($data['diagnostics'])) {
            throw new AIException('El análisis de arquitectura no contiene arrays válidos.');
        }
        return [];
    }

    /** Valida shape mínimo de extracción de memoria desde dossier. */
    private static function validateBusinessProfile(array $data): array
    {
        if (!isset($data['fields']) || !is_array($data['fields'])) {
            throw new AIException('La respuesta no contiene un objeto "fields".');
        }
        $allowed = [
            'business_description',
            'target_audience',
            'tone_of_voice',
            'services',
            'value_proposition',
            'unique_selling_points',
            'keywords',
            'contact_info',
        ];
        $warnings = [];
        foreach (array_keys($data['fields']) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $warnings[] = "Campo '$key' no existe en la memoria del negocio.";
            }
        }
        return $warnings;
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Intenta parsear JSON limpiando bloques markdown que algunos modelos devuelven
     * a pesar de la instrucción.
     */
    private static function parseJsonStrict(string $raw): mixed
    {
        $raw = trim($raw);

        // Quitar fence ```json ... ``` o ``` ... ```
        if (preg_match('/^```(?:json)?\s*(.+)\s*```$/is', $raw, $m)) {
            $raw = $m[1];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // Último intento: buscar primer { o [ hasta el último correspondiente
            $first = min(
                (($p = strpos($raw, '{')) === false ? PHP_INT_MAX : $p),
                (($p = strpos($raw, '[')) === false ? PHP_INT_MAX : $p)
            );
            if ($first !== PHP_INT_MAX) {
                $last = max((int) strrpos($raw, '}'), (int) strrpos($raw, ']'));
                if ($last > $first) {
                    $decoded = json_decode(substr($raw, $first, $last - $first + 1), true);
                }
            }
        }

        if (!is_array($decoded)) {
            throw new AIException('No se pudo parsear JSON de la respuesta del modelo. Respuesta: ' . mb_substr($raw, 0, 300));
        }
        return $decoded;
    }

    /** Hint compacto del schema del tipo de sección para el system prompt. */
    private static function renderSectionSchemaHint(string $type): string
    {
        $schemas = SectionSchemas::all();
        $schema = $schemas[$type] ?? null;
        if ($schema === null) {
            return '(tipo de sección desconocido; devuelve un objeto plano con heading y body)';
        }
        $lines = [];
        foreach ($schema['fields'] as $f) {
            $k = $f['key'];
            $t = $f['type'];
            $hint = '';
            if ($t === 'repeater') {
                $subKeys = array_map(fn($sf) => $sf['key'] . ':' . $sf['type'], (array) ($f['fields'] ?? []));
                $hint = ' [array de objetos: ' . implode(', ', $subKeys) . ']';
            } elseif ($t === 'select') {
                $hint = ' (uno de: ' . implode(', ', array_keys((array) ($f['options'] ?? []))) . ')';
            }
            $lines[] = "- $k ($t)" . $hint;
        }
        return "{" . "\n" . implode("\n", $lines) . "\n}";
    }
}
