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
    /** Texto generado por el modelo. */
    public string $content;
    /** Modelo exacto usado (lo devuelve el API, puede diferir del solicitado). */
    public string $model;
    /** Nombre del proveedor ("openai", "anthropic", etc.). */
    public string $provider;
    /** Tokens del prompt. */
    public int $tokensIn;
    /** Tokens de la respuesta. */
    public int $tokensOut;
    /** Razón por la que terminó la generación (stop, length, tool_call, ...). */
    public ?string $finishReason;
    /** Latencia aproximada en milisegundos. */
    public int $latencyMs;
    /** Respuesta cruda del proveedor (debug). */
    public ?array $raw;

    public function __construct(
        string $content,
        string $model,
        string $provider,
        int $tokensIn = 0,
        int $tokensOut = 0,
        ?string $finishReason = null,
        int $latencyMs = 0,
        ?array $raw = null
    ) {
        $this->content = $content;
        $this->model = $model;
        $this->provider = $provider;
        $this->tokensIn = $tokensIn;
        $this->tokensOut = $tokensOut;
        $this->finishReason = $finishReason;
        $this->latencyMs = $latencyMs;
        $this->raw = $raw;
    }

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
