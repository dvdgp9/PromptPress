<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Controllers\Admin\MemoryController;
use Core\Database;

/**
 * Motor de prompt engineering de PromptPress (T6.2).
 *
 * Combina los insumos del sitio:
 *   - Memoria (T4.1): qué hace la empresa, tono, público, USPs, keywords…
 *   - Documentos (T4.2/T4.3): top N resúmenes relevantes como referencia
 *   - Acción (definida en Actions): instrucción + plantilla de user message
 *   - Input concreto de la acción (section_type, page_title, original_text...)
 *
 * Produce `[messages, options]` listos para `AIProviderInterface::chat()`.
 *
 * Principios:
 *   - Determinista: dado el mismo state de BD + input, genera el mismo prompt.
 *   - Defensivo: campos de memoria vacíos se omiten, no se emite "Tono: N/A".
 *   - Barato: documentos truncados a N chars para no disparar tokens.
 */
final class PromptBuilder
{
    /** Máximo de chars de resumen de documento incluidos por doc. */
    private const DOC_EXCERPT_MAX = 600;

    /** Documentos máximos por prompt (por coste). */
    private const DOC_MAX_COUNT = 3;

    /**
     * Construye los mensajes + opciones para una acción.
     *
     * @param string              $action   Clave de Actions (ej. Actions::GENERATE_SECTION).
     * @param array<string,mixed> $input    Datos de la acción (ver `required` por acción).
     * @param int                 $siteId   Sitio activo.
     * @param array<string,mixed> $extras   Placeholders extra (p.ej. section_schema).
     *
     * @return array{messages: array<int,array{role:string,content:string}>, options: array<string,mixed>, meta: array<string,mixed>}
     *
     * @throws AIException si la acción no existe o faltan campos obligatorios.
     */
    public static function forAction(string $action, array $input, int $siteId, array $extras = []): array
    {
        $def = Actions::get($action);
        if ($def === null) {
            throw new AIException('Acción de IA desconocida: ' . $action);
        }

        // Validar required
        foreach ((array) ($def['required'] ?? []) as $req) {
            $val = $input[$req] ?? null;
            if (!is_string($val) || trim($val) === '') {
                throw new AIException("Falta el campo obligatorio '$req' para la acción '$action'");
            }
        }

        $memory    = self::loadMemory($siteId);
        $documents = self::loadDocuments($siteId, $input);

        $placeholders = array_merge(
            $memory,
            $extras,
            $input,
            ['extra_context' => self::formatExtraContext($input['extra_context'] ?? '')],
        );

        $systemPrompt = self::buildSystemPrompt($def, $memory, $documents, $placeholders);
        $userPrompt   = self::expandTemplate((string) ($def['user_template'] ?? ''), $placeholders);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => trim($userPrompt)],
        ];

        return [
            'messages' => $messages,
            'options'  => (array) ($def['options'] ?? []),
            'meta'     => [
                'action'             => $action,
                'output'             => $def['output'] ?? 'text',
                'memory_fields_used' => array_keys(array_filter($memory, fn($v) => $v !== '')),
                'documents_used'     => array_map(fn($d) => $d['title'], $documents),
            ],
        ];
    }

    // ======================================================================
    // Internals
    // ======================================================================

    /** @return array<string,string> */
    private static function loadMemory(int $siteId): array
    {
        $values = [];
        foreach (array_keys(MemoryController::FIELDS) as $k) {
            $values[$k] = '';
        }

        $rows = Database::select(
            'SELECT field_key, field_value FROM site_memory WHERE site_id = ?',
            [$siteId]
        );
        foreach ($rows as $r) {
            $values[$r['field_key']] = (string) $r['field_value'];
        }
        return $values;
    }

    /**
     * Documentos relevantes (top N ready, más recientes). Un día será semántico.
     *
     * @param array<string,mixed> $input
     * @return array<int,array{title:string,excerpt:string}>
     */
    private static function loadDocuments(int $siteId, array $input): array
    {
        $rows = Database::select(
            'SELECT title, summary, extracted_text
             FROM documents
             WHERE site_id = ? AND status = ?
             ORDER BY updated_at DESC
             LIMIT ' . (int) self::DOC_MAX_COUNT,
            [$siteId, 'ready']
        );

        $out = [];
        foreach ($rows as $r) {
            $excerpt = (string) ($r['summary'] ?? '');
            if ($excerpt === '') {
                $excerpt = (string) ($r['extracted_text'] ?? '');
            }
            $excerpt = self::truncate($excerpt, self::DOC_EXCERPT_MAX);
            $out[] = [
                'title'   => (string) $r['title'],
                'excerpt' => $excerpt,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,string>                               $memory
     * @param array<int,array{title:string,excerpt:string}>      $documents
     * @param array<string,mixed>                                $placeholders
     */
    private static function buildSystemPrompt(array $def, array $memory, array $documents, array $placeholders): string
    {
        $parts = [];
        $parts[] = 'Eres un redactor experto de contenidos web. Tu trabajo es producir textos '
                 . 'alineados con la identidad y el tono de la marca descritos a continuación.';

        // Contexto de empresa
        $context = self::formatMemoryBlock($memory);
        if ($context !== '') {
            $parts[] = "CONTEXTO DE LA EMPRESA:\n" . $context;
        }

        // Documentos de referencia
        if ($documents !== []) {
            $docBlock = "DOCUMENTOS DE REFERENCIA (usa esta información como fuente si es relevante):\n";
            foreach ($documents as $i => $d) {
                $n = $i + 1;
                $docBlock .= "\n[Doc {$n} — \"{$d['title']}\"]\n{$d['excerpt']}\n";
            }
            $parts[] = rtrim($docBlock);
        }

        // Instrucción específica de la acción
        $instruction = self::expandTemplate((string) ($def['instruction'] ?? ''), $placeholders);
        $parts[] = "INSTRUCCIÓN:\n" . trim($instruction);

        // Reglas globales de estilo
        $parts[] = "REGLAS:\n"
                 . "- No inventes datos de contacto ni cifras que no estén en el contexto.\n"
                 . "- No incluyas meta-comentarios (\"Aquí tienes…\", \"Claro, te ayudo…\").\n"
                 . "- Cuando se pida JSON, devuelve JSON válido sin bloques markdown.";

        return implode("\n\n", $parts);
    }

    /** Bloque legible con los campos de memoria rellenos. */
    private static function formatMemoryBlock(array $memory): string
    {
        $labels = [
            'business_description'  => 'Qué hace la empresa',
            'target_audience'       => 'Público objetivo',
            'tone_of_voice'         => 'Tono',
            'services'              => 'Servicios / productos',
            'value_proposition'     => 'Propuesta de valor',
            'unique_selling_points' => 'Diferenciadores',
            'keywords'              => 'Keywords SEO',
            'contact_info'          => 'Contacto (no inventes, usa solo estos datos)',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $val = trim((string) ($memory[$key] ?? ''));
            if ($val === '') continue;
            // Para multi-línea, sangramos
            $val = preg_replace('/\r\n?/', "\n", $val);
            if (str_contains($val, "\n")) {
                $indented = '  ' . str_replace("\n", "\n  ", $val);
                $lines[] = "- $label:\n$indented";
            } else {
                $lines[] = "- $label: $val";
            }
        }
        return implode("\n", $lines);
    }

    /** Sustituye `{key}` por su valor. Keys no resueltas → "". */
    private static function expandTemplate(string $tpl, array $vars): string
    {
        if ($tpl === '') return '';
        return preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/i', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';
            return is_scalar($v) ? (string) $v : '';
        }, $tpl) ?? '';
    }

    private static function formatExtraContext(mixed $raw): string
    {
        $s = is_string($raw) ? trim($raw) : '';
        return $s === '' ? '' : "Contexto adicional:\n" . $s;
    }

    private static function truncate(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        if (mb_strlen($s) <= $max) return $s;
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
