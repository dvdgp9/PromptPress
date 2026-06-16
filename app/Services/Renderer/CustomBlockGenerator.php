<?php

declare(strict_types=1);

namespace App\Services\Renderer;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;

/**
 * Genera y normaliza bloques `custom_block` usando la accion IA PP-friendly.
 *
 * El servicio separa dos responsabilidades:
 * - AIActionRunner obtiene el borrador `{html,rationale}`.
 * - CustomBlockSanitizer decide si ese HTML es seguro y extrae `fields`.
 */
final class CustomBlockGenerator
{
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function generate(int $siteId, array $input, int $maxAttempts = 2): array
    {
        $attempts = [];
        $feedback = (string) ($input['validation_feedback'] ?? '');
        $maxAttempts = max(1, min(3, $maxAttempts));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $runInput = $input;
            $runInput['validation_feedback'] = $feedback;
            $runInput += [
                'section_role' => '',
                'language' => 'es',
                'available_images' => '',
            ];

            try {
                $result = AIActionRunner::run(Actions::GENERATE_CUSTOM_BLOCK_FROM_REFERENCE, $runInput, $siteId);
            } catch (AIException $e) {
                // Respuesta truncada / JSON inválido también merece reintento,
                // no solo los fallos del sanitizer.
                $attempts[] = [
                    'attempt' => $attempt,
                    'ok' => false,
                    'errors' => [['code' => 'ai_response_error', 'message' => $e->getMessage(), 'severity' => 'error']],
                    'warnings' => [],
                    'provider' => null,
                    'model' => null,
                    'latency_ms' => null,
                ];
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $feedback = '- ai_response_error: la respuesta anterior no fue JSON válido (posible truncado). '
                    . 'Devuelve el mismo bloque pero MÁS CONCISO: menos items, textos más cortos, sin perder la composición.';
                continue;
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $content = self::buildContentFromAiData($data, [
                'kind' => 'reference',
                'provider' => (string) ($result['provider'] ?? ''),
                'model' => (string) ($result['model'] ?? ''),
                'created_at' => gmdate('c'),
                'attempt' => $attempt,
            ], [
                'site_id' => $siteId,
                'is_first_section' => (bool) ($input['is_first_section'] ?? false),
            ]);

            $attempts[] = [
                'attempt' => $attempt,
                'ok' => (bool) ($content['validation']['sanitized'] ?? false),
                'errors' => $content['validation']['errors'] ?? [],
                'warnings' => $content['validation']['warnings'] ?? [],
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
            ];

            if (($content['validation']['sanitized'] ?? false) === true) {
                return [
                    'ok' => true,
                    'content' => $content,
                    'attempts' => $attempts,
                    'warnings' => $result['warnings'] ?? [],
                    'provider' => $result['provider'] ?? null,
                    'model' => $result['model'] ?? null,
                    'tokens_in' => $result['tokens_in'] ?? null,
                    'tokens_out' => $result['tokens_out'] ?? null,
                    'estimated_cost' => $result['estimated_cost'] ?? null,
                ];
            }

            $feedback = self::formatValidationFeedback($content['validation']['errors'] ?? []);
        }

        $last = end($attempts);
        $errors = is_array($last) ? (array) ($last['errors'] ?? []) : [];
        $summary = self::formatValidationFeedback($errors);
        throw new AIException(
            'No se pudo generar un bloque PP-friendly válido tras ' . $maxAttempts . ' intento(s).'
          . ($summary !== '' ? "\nErrores del último intento:\n" . $summary : '')
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $source
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function buildContentFromAiData(array $data, array $source = [], array $context = []): array
    {
        $rawHtml = trim((string) ($data['html'] ?? ''));
        $sanitized = CustomBlockSanitizer::sanitize($rawHtml, $context);

        return [
            'version' => 'ppb:1',
            'html' => $sanitized['ok'] ? $sanitized['html'] : $rawHtml,
            'fields' => $sanitized['ok'] ? $sanitized['fields'] : new \stdClass(),
            'art' => $sanitized['ok'] && is_array($sanitized['art'] ?? null)
                ? $sanitized['art']
                : ['theme' => '', 'pad' => ''],
            'source' => $source + ['kind' => 'reference'],
            'rationale' => self::normalizeRationale($data['rationale'] ?? []),
            'validation' => [
                'sanitized' => (bool) $sanitized['ok'],
                'warnings' => $sanitized['warnings'],
                'errors' => $sanitized['errors'],
                'removed' => $sanitized['removed'],
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return array{summary:string,reference_takeaways:array<int,string>,brand_application:array<int,string>}
     */
    private static function normalizeRationale(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        return [
            'summary' => trim((string) ($raw['summary'] ?? '')),
            'reference_takeaways' => self::stringList($raw['reference_takeaways'] ?? []),
            'brand_application' => self::stringList($raw['brand_application'] ?? []),
        ];
    }

    /** @return array<int,string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') $out[] = $text;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,string>> $errors
     */
    private static function formatValidationFeedback(array $errors): string
    {
        if ($errors === []) return '';
        $lines = [];
        foreach ($errors as $error) {
            $code = (string) ($error['code'] ?? 'validation_error');
            $message = (string) ($error['message'] ?? '');
            $lines[] = '- ' . $code . ($message !== '' ? ': ' . $message : '');
        }
        return implode("\n", $lines);
    }
}
