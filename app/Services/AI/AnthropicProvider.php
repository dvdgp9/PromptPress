<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Anthropic (Claude) — endpoint /v1/messages.
 *
 * Diferencias con OpenAI:
 *   - Header: `x-api-key` + `anthropic-version` (en lugar de Authorization).
 *   - El `system` se pasa como campo top-level, no como message role.
 *   - `max_tokens` es OBLIGATORIO.
 *   - Respuesta: content es una lista de bloques, tomamos el primer text block.
 */
final class AnthropicProvider extends BaseProvider
{
    private const BASE_URL = 'https://api.anthropic.com/v1';
    private const DEFAULT_MAX_TOKENS = 1024;
    private const API_VERSION = '2023-06-01';

    public function getName(): string { return 'anthropic'; }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $clean = $this->sanitizeMessages($messages);

        // Separar 'system' (top-level en Anthropic)
        $system = '';
        $convo  = [];
        foreach ($clean as $m) {
            if ($m['role'] === 'system') {
                $system .= ($system !== '' ? "\n\n" : '') . $m['content'];
            } else {
                $convo[] = $m;
            }
        }
        if ($convo === []) {
            throw new AIException('Anthropic requiere al menos un mensaje user/assistant');
        }

        $payload = [
            'model'      => $this->model,
            'messages'   => $convo,
            'max_tokens' => (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        ];
        if ($system !== '')                 $payload['system']      = $system;
        if (isset($options['temperature'])) $payload['temperature'] = (float) $options['temperature'];

        $timeout = (int) ($options['timeout'] ?? 60);
        [$status, $body,, $latency] = $this->httpPostJson(
            self::BASE_URL . '/messages',
            [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            $payload,
            $timeout
        );
        $this->assertOk($status, $body);

        // Anthropic: content = [{type:'text', text:'...'}, ...]
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            throw new AIException('Anthropic devolvió una respuesta sin texto');
        }

        return new AIResponse(
            content:      $text,
            model:        (string) ($body['model'] ?? $this->model),
            provider:     $this->getName(),
            tokensIn:     (int) ($body['usage']['input_tokens']  ?? 0),
            tokensOut:    (int) ($body['usage']['output_tokens'] ?? 0),
            finishReason: $body['stop_reason'] ?? null,
            latencyMs:    $latency,
            raw:          $body,
        );
    }

    protected function extractError(array $decoded): string
    {
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }
        return '';
    }
}
