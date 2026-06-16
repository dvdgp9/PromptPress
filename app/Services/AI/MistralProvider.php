<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Mistral AI — API compatible con OpenAI. Solo cambia base URL y nombre.
 */
final class MistralProvider extends OpenAIProvider
{
    protected string $baseUrl = 'https://api.mistral.ai/v1';

    public function getName(): string { return 'mistral'; }
}
