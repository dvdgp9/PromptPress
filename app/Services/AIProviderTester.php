<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * AIProviderTester — verifica una API key contra el proveedor de IA.
 *
 * Estrategia: hacer un GET barato al endpoint /models del proveedor.
 * Si la key es válida, el endpoint responde 200 con el listado de modelos.
 * Si la key es inválida, el endpoint responde 401/403.
 *
 * Diseño: respuesta uniforme [ok, error?, info?] independientemente del proveedor.
 */
final class AIProviderTester
{
    /** Lista de proveedores soportados. */
    public const PROVIDERS = [
        'openrouter' => 'OpenRouter',
        'openai'    => 'OpenAI (GPT)',
        'anthropic' => 'Anthropic (Claude)',
        'mistral'   => 'Mistral AI',
    ];

    /** Modelos sugeridos por proveedor (texto libre, el usuario puede personalizar). */
    public const SUGGESTED_MODELS = [
        'openrouter' => [
            'google/gemini-3-flash-preview',
            'google/gemini-3.1-flash-lite',
            'google/gemini-3.5-flash',
            'openai/gpt-4o-mini',
            'anthropic/claude-3.5-haiku',
            'meta-llama/llama-3.3-70b-instruct',
            'mistralai/mistral-small-24b-instruct-2501:free',
        ],
        'openai'    => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'o1-mini'],
        'anthropic' => ['claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'claude-3-opus-latest'],
        'mistral'   => ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest'],
    ];

    /**
     * Verifica una API key contra el proveedor.
     *
     * @return array{ok: bool, error?: string, model_found?: bool}
     */
    public static function test(string $provider, string $model, string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'La API key está vacía.'];
        }

        return match ($provider) {
            'openrouter' => self::testOpenRouter($apiKey, $model),
            'openai'    => self::testOpenAI($apiKey, $model),
            'anthropic' => self::testAnthropic($apiKey, $model),
            'mistral'   => self::testMistral($apiKey, $model),
            default     => ['ok' => false, 'error' => 'Proveedor no soportado: ' . $provider],
        };
    }

    private static function testOpenAI(string $apiKey, string $model): array
    {
        return self::checkKey(
            url: 'https://api.openai.com/v1/models',
            headers: ['Authorization: Bearer ' . $apiKey],
            providerLabel: 'OpenAI',
            expectedModel: $model,
        );
    }

    private static function testOpenRouter(string $apiKey, string $model): array
    {
        return self::checkKey(
            url: 'https://openrouter.ai/api/v1/models',
            headers: [
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://promptpress.local',
                'X-Title: PromptPress',
            ],
            providerLabel: 'OpenRouter',
            expectedModel: $model,
        );
    }

    private static function testAnthropic(string $apiKey, string $model): array
    {
        return self::checkKey(
            url: 'https://api.anthropic.com/v1/models',
            headers: [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            providerLabel: 'Anthropic',
            expectedModel: $model,
        );
    }

    private static function testMistral(string $apiKey, string $model): array
    {
        return self::checkKey(
            url: 'https://api.mistral.ai/v1/models',
            headers: ['Authorization: Bearer ' . $apiKey],
            providerLabel: 'Mistral',
            expectedModel: $model,
        );
    }

    /**
     * Hace GET a la URL y evalúa la respuesta.
     *
     * @param string[] $headers
     */
    private static function checkKey(string $url, array $headers, string $providerLabel, string $expectedModel): array
    {
        try {
            [$status, $body, $err] = self::httpGet($url, $headers);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => 'Error de red al contactar con ' . $providerLabel . ': ' . $e->getMessage()];
        }

        if ($err !== null) {
            return ['ok' => false, 'error' => 'No se pudo conectar con ' . $providerLabel . ': ' . $err];
        }

        if ($status === 200) {
            // Comprobar si el modelo está disponible (best-effort)
            $modelFound = false;
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $models = $decoded['data'] ?? $decoded['models'] ?? [];
                foreach ($models as $m) {
                    $id = is_array($m) ? ($m['id'] ?? $m['name'] ?? '') : '';
                    if (is_string($id) && $id === $expectedModel) {
                        $modelFound = true;
                        break;
                    }
                }
            }
            return ['ok' => true, 'model_found' => $modelFound];
        }

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'API key rechazada por ' . $providerLabel . ' (HTTP ' . $status . '). Verifica que sea correcta y esté activa.'];
        }

        if ($status === 429) {
            return ['ok' => false, 'error' => $providerLabel . ' ha respondido con rate-limit (HTTP 429). Espera unos segundos y vuelve a intentarlo.'];
        }

        return ['ok' => false, 'error' => $providerLabel . ' respondió HTTP ' . $status . '. Detalles: ' . substr($body, 0, 200)];
    }

    /**
     * GET HTTP simple con cURL.
     *
     * @param string[] $headers
     * @return array{0: int, 1: string, 2: ?string} [statusCode, body, errorString|null]
     */
    private static function httpGet(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL no está disponible en este servidor');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PromptPress/0.1 (+install)',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [0, '', $err !== '' ? $err : 'unknown cURL error'];
        }
        return [$code, (string) $body, null];
    }
}
