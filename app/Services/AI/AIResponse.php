<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Respuesta uniforme de un proveedor de IA.
 *
 * Independiente del proveedor concreto: cualquier implementación de
 * AIProviderInterface devuelve esta estructura tras una llamada exitosa.
 */
final class AIResponse
{
    public function __construct(
        /** Texto generado por el modelo. */
        public readonly string $content,
        /** Modelo exacto usado (lo devuelve el API, puede diferir del solicitado). */
        public readonly string $model,
        /** Nombre del proveedor ("openai", "anthropic", etc.). */
        public readonly string $provider,
        /** Tokens del prompt. */
        public readonly int $tokensIn = 0,
        /** Tokens de la respuesta. */
        public readonly int $tokensOut = 0,
        /** Razón por la que terminó la generación (stop, length, tool_call, ...). */
        public readonly ?string $finishReason = null,
        /** Latencia aproximada en milisegundos. */
        public readonly int $latencyMs = 0,
        /** Respuesta cruda del proveedor (debug). */
        public readonly ?array $raw = null,
    ) {}

    public function toArray(): array
    {
        return [
            'content'       => $this->content,
            'model'         => $this->model,
            'provider'      => $this->provider,
            'tokens_in'     => $this->tokensIn,
            'tokens_out'    => $this->tokensOut,
            'finish_reason' => $this->finishReason,
            'latency_ms'    => $this->latencyMs,
        ];
    }
}
