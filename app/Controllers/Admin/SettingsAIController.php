<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use Core\App;
use Core\Auth;
use Core\Crypto;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Ajustes del proveedor de IA (parte de T9.1, adelantado para soportar OpenRouter + cambios post-install).
 *
 * Permite al admin cambiar el provider, modelo y API key de IA sin tocar SQL.
 * La key se guarda encriptada en `settings.ai_api_key`.
 */
class SettingsAIController
{
    /** Sugerencias de modelo por proveedor. Texto libre, informativo. */
    private const SUGGESTED_MODELS = [
        'openai'     => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'o1-mini'],
        'anthropic'  => ['claude-3-5-haiku-latest', 'claude-3-5-sonnet-latest', 'claude-3-opus-latest'],
        'mistral'    => ['mistral-small-latest', 'mistral-large-latest', 'codestral-latest'],
        'openrouter' => [
            // Gemini en OpenRouter (IDs confirmados desde la plataforma).
            'google/gemini-3-flash-preview',
            'google/gemini-3.1-flash-lite',
            'google/gemini-3.5-flash',

            // Alternativas útiles desde OpenRouter.
            'openai/gpt-4o-mini',
            'anthropic/claude-3.5-haiku',
            'anthropic/claude-3.5-sonnet',
            'meta-llama/llama-3.3-70b-instruct',
            'meta-llama/llama-3.1-8b-instruct:free',
            'mistralai/mistral-small-24b-instruct-2501:free',
        ],
    ];

    /** Presets pensados para la UI: nombres humanos sobre IDs técnicos. */
    private const MODEL_PRESETS = [
        'openrouter' => [
            [
                'model' => 'google/gemini-3-flash-preview',
                'name' => 'Gemini 3 Flash',
                'badge' => 'Principal',
                'tone' => 'balanced',
                'summary' => 'Modelo recomendado para generación de páginas, secciones y contenido largo dentro de PromptPress.',
                'use_case' => 'Principal',
                'cost' => 'OpenRouter',
            ],
            [
                'model' => 'google/gemini-3.1-flash-lite',
                'name' => 'Gemini 3.1 Flash Lite',
                'badge' => 'Pequeño',
                'tone' => 'standard',
                'summary' => 'Opción ligera para reescrituras, SEO, resúmenes y tareas frecuentes de bajo coste.',
                'use_case' => 'Auxiliar',
                'cost' => 'OpenRouter',
            ],
            [
                'model' => 'google/gemini-3.5-flash',
                'name' => 'Gemini 3.5 Flash',
                'badge' => 'Avanzado',
                'tone' => 'premium',
                'summary' => 'Alternativa más capaz para contenido exigente manteniendo la familia Gemini en OpenRouter.',
                'use_case' => 'Calidad',
                'cost' => 'OpenRouter',
            ],
        ],
        'openai' => [
            [
                'model' => 'gpt-4o-mini',
                'name' => 'GPT-4o mini',
                'badge' => 'Rápido',
                'tone' => 'balanced',
                'summary' => 'Buena opción general para pruebas, reescritura y tareas cortas.',
                'use_case' => 'Día a día',
                'cost' => '$0.15 / $0.60',
            ],
            [
                'model' => 'gpt-4o',
                'name' => 'GPT-4o',
                'badge' => 'Calidad',
                'tone' => 'premium',
                'summary' => 'Más sólido para generación de contenido largo o decisiones editoriales.',
                'use_case' => 'Contenido',
                'cost' => '$2.50 / $10.00',
            ],
        ],
        'anthropic' => [
            [
                'model' => 'claude-3-5-haiku-latest',
                'name' => 'Claude Haiku',
                'badge' => 'Ligero',
                'tone' => 'balanced',
                'summary' => 'Respuesta ágil para copy breve, resúmenes y variaciones.',
                'use_case' => 'Rápido',
                'cost' => '$0.80 / $4.00',
            ],
            [
                'model' => 'claude-3-5-sonnet-latest',
                'name' => 'Claude Sonnet',
                'badge' => 'Editorial',
                'tone' => 'premium',
                'summary' => 'Mejor cuando el tono, la estructura y la redacción pesan bastante.',
                'use_case' => 'Contenido',
                'cost' => '$3.00 / $15.00',
            ],
        ],
        'mistral' => [
            [
                'model' => 'mistral-small-latest',
                'name' => 'Mistral Small',
                'badge' => 'Económico',
                'tone' => 'balanced',
                'summary' => 'Modelo rápido y barato para tareas auxiliares.',
                'use_case' => 'Auxiliar',
                'cost' => '$0.20 / $0.60',
            ],
            [
                'model' => 'mistral-large-latest',
                'name' => 'Mistral Large',
                'badge' => 'Avanzado',
                'tone' => 'premium',
                'summary' => 'Más capacidad para generación compleja manteniendo proveedor Mistral.',
                'use_case' => 'Contenido',
                'cost' => '$2.00 / $6.00',
            ],
        ],
    ];

    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $meta   = AIProviderFactory::currentMeta($siteId);
        $hasKey = $this->hasApiKey($siteId);

        View::send('admin/settings/ai', array_merge(
            DashboardController::getCommonData(),
            [
                'providers'        => AIProviderFactory::PROVIDERS,
                'suggested_models' => self::SUGGESTED_MODELS,
                'model_presets'    => self::MODEL_PRESETS,
                'current_provider'    => $meta['provider'] ?: 'openrouter',
                'current_model'       => $meta['model'],
                'current_model_light' => $meta['model_light'] ?? '',
                'has_api_key'         => $hasKey,
                'errors'              => [],
                'notice'              => Session::flash('notice'),
                'csrf'                => CSRF::token(),
            ]
        ));
    }

    public function update(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        $provider   = (string) (Request::post('provider') ?? '');
        $model      = trim((string) (Request::post('model') ?? ''));
        $modelLight = trim((string) (Request::post('model_light') ?? ''));
        $apiKey     = (string) (Request::post('api_key') ?? '');
        $test       = (string) (Request::post('test_connection') ?? '') === '1';

        $errors = [];
        if (!array_key_exists($provider, AIProviderFactory::PROVIDERS)) {
            $errors[] = 'Proveedor no válido.';
        }
        if ($model === '' || mb_strlen($model) > 100) {
            $errors[] = 'El modelo principal es obligatorio (máx. 100 caracteres).';
        }
        if ($modelLight !== '' && mb_strlen($modelLight) > 100) {
            $errors[] = 'El modelo auxiliar no puede superar 100 caracteres.';
        }

        $keyProvided = trim($apiKey) !== '';
        $hadKey      = $this->hasApiKey($siteId);
        if (!$keyProvided && !$hadKey) {
            $errors[] = 'La API key es obligatoria en la primera configuración.';
        }

        if ($errors !== []) {
            $this->renderWithErrors($siteId, $provider, $model, $errors, $modelLight);
            return;
        }

        // Test opcional: hace una llamada barata al modelo con la key nueva/actual
        if ($test) {
            $testKey = $keyProvided ? $apiKey : $this->loadDecryptedKey($siteId);
            if ($testKey === null) {
                $this->renderWithErrors($siteId, $provider, $model, ['No se pudo recuperar la API key actual para probarla.'], $modelLight);
                return;
            }
            try {
                $p = AIProviderFactory::make($provider, $testKey, $model);
                $p->chat(
                    [['role' => 'user', 'content' => 'ping']],
                    ['max_tokens' => 5, 'temperature' => 0, 'timeout' => 20]
                );
            } catch (AIException $e) {
                $this->renderWithErrors(
                    $siteId, $provider, $model,
                    ['Test fallido: ' . $e->getMessage()],
                    $modelLight
                );
                return;
            }
        }

        // Persistir
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $upsert = $pdo->prepare(
                'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)'
            );
            $upsert->execute([$siteId, 'ai_provider',    $provider,   0]);
            $upsert->execute([$siteId, 'ai_model',       $model,      0]);
            $upsert->execute([$siteId, 'ai_model_light', $modelLight, 0]);
            if ($keyProvided) {
                $appKey = (string) (App::config()['app_key'] ?? '');
                if ($appKey === '') {
                    throw new \RuntimeException('app_key no definida en config/config.php');
                }
                $encrypted = Crypto::encrypt($apiKey, $appKey);
                $upsert->execute([$siteId, 'ai_api_key', $encrypted, 1]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $this->renderWithErrors($siteId, $provider, $model, ['Error guardando: ' . $e->getMessage()], $modelLight);
            return;
        }

        Session::flash('notice', $test
            ? 'Configuración guardada y conexión verificada ✓'
            : 'Configuración guardada.');
        Response::redirect(base_url('admin/settings/ai'));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private function hasApiKey(int $siteId): bool
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?',
            [$siteId, 'ai_api_key']
        );
        return $row !== null && trim((string) $row['setting_value']) !== '';
    }

    private function loadDecryptedKey(int $siteId): ?string
    {
        $row = Database::selectOne(
            'SELECT setting_value, is_encrypted FROM settings WHERE site_id = ? AND setting_key = ?',
            [$siteId, 'ai_api_key']
        );
        if ($row === null) return null;
        $val = (string) $row['setting_value'];
        if ((int) $row['is_encrypted'] !== 1) return $val;
        $appKey = (string) (App::config()['app_key'] ?? '');
        try {
            return Crypto::decrypt($val, $appKey);
        } catch (\Throwable) {
            return null;
        }
    }

    private function renderWithErrors(int $siteId, string $provider, string $model, array $errors, string $modelLight = ''): void
    {
        View::send('admin/settings/ai', array_merge(
            DashboardController::getCommonData(),
            [
                'providers'           => AIProviderFactory::PROVIDERS,
                'suggested_models'    => self::SUGGESTED_MODELS,
                'model_presets'       => self::MODEL_PRESETS,
                'current_provider'    => $provider,
                'current_model'       => $model,
                'current_model_light' => $modelLight,
                'has_api_key'         => $this->hasApiKey($siteId),
                'errors'              => $errors,
                'notice'              => null,
                'csrf'                => CSRF::token(),
            ]
        ));
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
