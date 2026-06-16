<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * OpenAI — endpoint /v1/chat/completions.
 *
 * También sirve de base para proveedores OpenAI-compatibles (Mistral, OpenRouter, etc.)
 * que sobreescriben `$baseUrl`, `getName()`, y `buildHeaders()`.
 */
class OpenAIProvider extends BaseProvider
{
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function getName(): string { return 'openai'; }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $clean = $this->sanitizeMessages($messages);

        // Visión (multimodal): si llegan imágenes, se adjuntan al último mensaje
        // de usuario en formato OpenAI-compatible. Solo se activa con imágenes,
        // así que las llamadas de solo texto no cambian.
        if (!empty($options['images']) && is_array($options['images'])) {
            $clean = $this->attachImagesToLastUserMessage($clean, $options['images']);
        }

        $payload = [
            'model'    => $this->model,
            'messages' => $clean,
        ];
        if (isset($options['temperature'])) $payload['temperature'] = (float) $options['temperature'];
        if (isset($options['max_tokens']))  $payload['max_tokens']  = (int) $options['max_tokens'];
        if (($options['response_format'] ?? null) === 'json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $timeout = (int) ($options['timeout'] ?? 60);
        [$status, $body,, $latency] = $this->httpPostJson(
            $this->baseUrl . '/chat/completions',
            $this->buildHeaders(),
            $payload,
            $timeout
        );
        $this->assertOk($status, $body);

        $choice  = $body['choices'][0] ?? null;
        $content = $choice['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new AIException($this->getName() . ' devolvió una respuesta sin contenido');
        }

        return new AIResponse(
            content:      $content,
            model:        (string) ($body['model'] ?? $this->model),
            provider:     $this->getName(),
            tokensIn:     (int) ($body['usage']['prompt_tokens']     ?? 0),
            tokensOut:    (int) ($body['usage']['completion_tokens'] ?? 0),
            finishReason: $choice['finish_reason'] ?? null,
            latencyMs:    $latency,
            raw:          $body,
        );
    }

    /**
     * Adjunta imágenes al último mensaje de usuario, convirtiendo su `content`
     * de string a array multimodal `[{type:text}, {type:image_url}...]`.
     * Si no hay mensaje de usuario o ninguna imagen es válida, no cambia nada.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<int,mixed> $images
     * @return array<int,array<string,mixed>>
     */
    protected function attachImagesToLastUserMessage(array $messages, array $images): array
    {
        $idx = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') { $idx = $i; break; }
        }
        if ($idx === null) {
            return $messages;
        }

        $parts = [];
        $text = (string) ($messages[$idx]['content'] ?? '');
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($images as $img) {
            $url = self::imageToDataUrl($img);
            if ($url !== null) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
        }

        // Sin imágenes válidas → mantener el mensaje de texto original intacto.
        if (count($parts) <= 1) {
            return $messages;
        }

        $messages[$idx]['content'] = $parts;
        return $messages;
    }

    /**
     * Normaliza una imagen a una URL usable por la API:
     *  - string → se asume URL o data-URI ya formado.
     *  - ['url' => ...] → esa URL.
     *  - ['data' => base64, 'mime' => 'image/png'] → data-URI.
     */
    private static function imageToDataUrl(mixed $img): ?string
    {
        if (is_string($img)) {
            $s = trim($img);
            return $s !== '' ? $s : null;
        }
        if (is_array($img)) {
            if (!empty($img['url']) && is_string($img['url'])) {
                return $img['url'];
            }
            if (!empty($img['data']) && is_string($img['data'])) {
                $mime = (string) ($img['mime'] ?? 'image/png');
                return 'data:' . $mime . ';base64,' . $img['data'];
            }
        }
        return null;
    }

    /** @return array<string,string> */
    protected function buildHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function extractError(array $decoded): string
    {
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }
        return '';
    }
}
