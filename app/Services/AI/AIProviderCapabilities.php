<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Gate conservador de capacidades por provider + modelo.
 *
 * OpenRouter expone la señal autoritativa en
 * `architecture.input_modalities`. Si el descubrimiento no responde, visión
 * queda desactivada: nunca se deduce por contener "vision" o "gemini" en el id.
 */
final class AIProviderCapabilities
{
    private const CACHE_SECONDS = 21600;

    /**
     * @param null|callable(string):?array<string,mixed> $openRouterLookup
     * @return array{provider:string,model:string,supports_vision:bool,status:string,reason:string,source:string}
     */
    public static function forModel(string $provider, string $model, ?callable $openRouterLookup = null): array
    {
        $provider = strtolower(trim($provider));
        $model = trim($model);
        $base = [
            'provider' => $provider,
            'model' => $model,
            'supports_vision' => false,
            'status' => 'unknown',
            'reason' => 'capability_not_verified',
            'source' => 'local_gate',
        ];

        if ($provider === 'anthropic') {
            return array_merge($base, [
                'status' => 'unsupported',
                'reason' => 'provider_transport_unsupported',
            ]);
        }

        if ($provider === 'openrouter') {
            $lookup = $openRouterLookup ?? [self::class, 'lookupOpenRouterModel'];
            $record = $lookup($model);
            if (!is_array($record)) return $base;
            $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
            $modalities = $data['architecture']['input_modalities'] ?? null;
            if (!is_array($modalities)) return $base;
            $modalities = array_map(static fn (mixed $item): string => strtolower((string) $item), $modalities);
            return array_merge($base, [
                'supports_vision' => in_array('image', $modalities, true),
                'status' => 'verified',
                'reason' => in_array('image', $modalities, true) ? 'input_modality_image' : 'input_modality_missing',
                'source' => 'openrouter_models_api',
            ]);
        }

        // Estos providers no publican una señal uniforme en el contrato que usa
        // PromptPress. Se mantienen apagados hasta incorporar su descubrimiento
        // autoritativo, evitando promesas visuales basadas en nombres.
        return $base;
    }

    /** @return array{provider:string,model:string,supports_vision:bool,status:string,reason:string,source:string} */
    public static function forProvider(AIProviderInterface $provider): array
    {
        return self::forModel($provider->getName(), $provider->getModel());
    }

    /** @return array<string,mixed>|null */
    public static function lookupOpenRouterModel(string $model): ?array
    {
        if (!preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $model)) return null;
        $cacheDir = defined('PP_STORAGE') ? PP_STORAGE . '/cache/ai-model-capabilities' : '';
        $cacheFile = $cacheDir !== '' ? $cacheDir . '/' . sha1($model) . '.json' : '';
        if ($cacheFile !== '' && is_file($cacheFile) && filemtime($cacheFile) >= time() - self::CACHE_SECONDS) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        if (!function_exists('curl_init')) return self::staleCache($cacheFile);
        [$author, $slug] = explode('/', $model, 2);
        $url = 'https://openrouter.ai/api/v1/models/' . rawurlencode($author) . '/' . rawurlencode($slug) . '/endpoints';
        $ch = curl_init($url);
        if ($ch === false) return self::staleCache($cacheFile);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PromptPress/' . (defined('PP_VERSION') ? PP_VERSION : 'dev'),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return self::staleCache($cacheFile);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return self::staleCache($cacheFile);
        if ($cacheFile !== '') {
            @mkdir(dirname($cacheFile), 0775, true);
            @file_put_contents($cacheFile, json_encode($decoded, JSON_UNESCAPED_SLASHES));
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private static function staleCache(string $cacheFile): ?array
    {
        if ($cacheFile === '' || !is_file($cacheFile)) return null;
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : null;
    }
}
