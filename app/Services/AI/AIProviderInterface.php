<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Contrato uniforme para cualquier proveedor de IA.
 *
 * Todos los proveedores se invocan con el mismo método `chat()` que recibe
 * una lista de mensajes estilo OpenAI ([role, content]). Los providers que
 * usan otro formato nativo (p.ej. Anthropic) hacen la traducción internamente.
 */
interface AIProviderInterface
{
    /**
     * Envía una conversación al modelo y devuelve la respuesta.
     *
     * @param array<int,array{role: string, content: string}> $messages
     *        Lista de mensajes. Roles soportados: 'system', 'user', 'assistant'.
     * @param array<string,mixed> $options
     *        Opcionales: 'temperature' (float), 'max_tokens' (int),
     *        'response_format' ('text'|'json'), 'timeout' (int segundos).
     *
     * @throws AIException si la llamada falla.
     */
    public function chat(array $messages, array $options = []): AIResponse;

    /** Identificador del proveedor ("openai", "anthropic", "mistral", "openrouter"). */
    public function getName(): string;

    /** Modelo configurado para este proveedor. */
    public function getModel(): string;
}
