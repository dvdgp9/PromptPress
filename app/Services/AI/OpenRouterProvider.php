<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * OpenRouter — agregador de modelos, API compatible con OpenAI.
 * Requiere cabeceras extra `HTTP-Referer` y `X-Title` recomendadas para analítica.
 */
final class OpenRouterProvider extends OpenAIProvider
{
    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    public function getName(): string { return 'openrouter'; }

    protected function buildHeaders(): array
    {
        $headers = parent::buildHeaders();
        $headers['HTTP-Referer'] = function_exists('base_url') ? (string) base_url('') : 'https://promptpress.local';
        $headers['X-Title']      = 'PromptPress';
        return $headers;
    }

    /**
     * OpenRouter recomienda max_completion_tokens y expone el control unificado
     * de reasoning. Se aplica aquí para no enviar esos parámetros a Mistral ni
     * al endpoint OpenAI directo.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function applyProviderOptions(array $payload, array $options): array
    {
        if (isset($payload['max_tokens'])) {
            $payload['max_completion_tokens'] = (int) $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        $effort = strtolower(trim((string) ($options['reasoning_effort'] ?? '')));
        if (in_array($effort, ['max', 'xhigh', 'high', 'medium', 'low', 'minimal', 'none'], true)) {
            $payload['reasoning'] = ['effort' => $effort];
        }

        return $payload;
    }
}
