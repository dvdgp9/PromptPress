<?php

declare(strict_types=1);

namespace App\Services\AI;

use Core\Crypto;
use Core\Database;

/**
 * Fábrica de providers de IA.
 *
 * - make(): instancia un provider concreto a partir de (nombre, apiKey, model).
 * - current(): lee `ai_provider|ai_model|ai_api_key` de la tabla `settings` para
 *   el site dado, desencripta la API key y devuelve el provider listo para usar.
 */
final class AIProviderFactory
{
    /** Lista de proveedores soportados y su label humano. */
    public const PROVIDERS = [
        'openai'     => 'OpenAI (GPT)',
        'anthropic'  => 'Anthropic (Claude)',
        'mistral'    => 'Mistral AI',
        'openrouter' => 'OpenRouter',
    ];

    /**
     * Instancia un provider según su nombre.
     *
     * @throws AIException si el nombre no es válido.
     */
    public static function make(string $provider, string $apiKey, string $model): AIProviderInterface
    {
        return match ($provider) {
            'openai'     => new OpenAIProvider($apiKey, $model),
            'anthropic'  => new AnthropicProvider($apiKey, $model),
            'mistral'    => new MistralProvider($apiKey, $model),
            'openrouter' => new OpenRouterProvider($apiKey, $model),
            default      => throw new AIException('Proveedor de IA no soportado: ' . $provider),
        };
    }

    /**
     * Construye el provider a partir de la configuración activa en `settings`
     * para el site dado. Devuelve null si falta configuración.
     */
    public static function current(int $siteId): ?AIProviderInterface
    {
        return self::currentForTier($siteId, Actions::TIER_MAIN);
    }

    /**
     * Construye el provider para un tier dado (`main` | `light`). El modelo
     * auxiliar (`ai_model_light`) cae al principal si no está configurado, y
     * el provider/api_key son siempre los mismos para los dos tiers.
     */
    public static function currentForTier(int $siteId, string $tier): ?AIProviderInterface
    {
        $rows = Database::select(
            'SELECT setting_key, setting_value, is_encrypted
             FROM settings
             WHERE site_id = ? AND setting_key IN (?, ?, ?, ?)',
            [$siteId, 'ai_provider', 'ai_model', 'ai_model_light', 'ai_api_key']
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['setting_key']] = $r;
        }

        $provider   = (string) ($map['ai_provider']['setting_value']    ?? '');
        $modelMain  = (string) ($map['ai_model']['setting_value']       ?? '');
        $modelLight = (string) ($map['ai_model_light']['setting_value'] ?? '');
        $encKey     = (string) ($map['ai_api_key']['setting_value']     ?? '');

        $model = ($tier === Actions::TIER_LIGHT && $modelLight !== '') ? $modelLight : $modelMain;

        if ($provider === '' || $model === '' || $encKey === '') {
            return null;
        }

        $appKey = (string) (\Core\App::config()['app_key'] ?? '');
        if ($appKey === '') {
            throw new AIException('app_key no definida en config/config.php');
        }

        try {
            $apiKey = (int) ($map['ai_api_key']['is_encrypted'] ?? 0) === 1
                ? Crypto::decrypt($encKey, $appKey)
                : $encKey;
        } catch (\RuntimeException $e) {
            throw new AIException('No se pudo descifrar la API key: ' . $e->getMessage());
        }

        return self::make($provider, $apiKey, $model);
    }

    /** Devuelve el provider adecuado al tier de la acción dada. */
    public static function currentForAction(int $siteId, string $action): ?AIProviderInterface
    {
        return self::currentForTier($siteId, Actions::tierOf($action));
    }

    /**
     * Conveniencia: devuelve [provider, model] actualmente configurados (sin key).
     * @return array{provider: string, model: string, configured: bool}
     */
    public static function currentMeta(int $siteId): array
    {
        $rows = Database::select(
            'SELECT setting_key, setting_value FROM settings
             WHERE site_id = ? AND setting_key IN (?, ?, ?)',
            [$siteId, 'ai_provider', 'ai_model', 'ai_model_light']
        );
        $map = [];
        foreach ($rows as $r) { $map[$r['setting_key']] = $r['setting_value']; }
        $provider   = (string) ($map['ai_provider']     ?? '');
        $model      = (string) ($map['ai_model']        ?? '');
        $modelLight = (string) ($map['ai_model_light']  ?? '');
        return [
            'provider'    => $provider,
            'model'       => $model,
            'model_light' => $modelLight,
            'configured'  => $provider !== '' && $model !== '',
        ];
    }
}
