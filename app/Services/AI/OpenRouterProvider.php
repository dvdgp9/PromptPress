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
}
