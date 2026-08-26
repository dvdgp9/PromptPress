<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use App\Services\ImageBankService;
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
            'google/gemini-3.7-flash',
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

    /** Modelos sugeridos para un proveedor (lista curada). Reutilizable por
     *  otras pantallas (p. ej. el selector de modelo del Studio). */
    public static function suggestedModelsFor(string $provider): array
    {
        return self::SUGGESTED_MODELS[$provider] ?? [];
    }

    /**
     * Presets pensados para la UI: nombres humanos sobre IDs técnicos.
     *
     * `badge`, `summary` y `use_case` son CLAVES de traducción, no texto: la
     * constante se evalúa antes de saber el idioma del gestor. Se resuelven en
     * `modelPresetsForView()`.
     */
    private const MODEL_PRESETS = [
        'openrouter' => [
            [
                'model' => 'google/gemini-3.7-flash',
                'name' => 'Gemini 3.7 Flash',
                'badge' => 'model.badge.main',
                'tone' => 'balanced',
                'summary' => 'model.summary.gemini_flash',
                'use_case' => 'model.use.main',
                'cost' => 'OpenRouter',
            ],
            [
                'model' => 'google/gemini-3.1-flash-lite',
                'name' => 'Gemini 3.1 Flash Lite',
                'badge' => 'model.badge.small',
                'tone' => 'standard',
                'summary' => 'model.summary.gemini_lite',
                'use_case' => 'model.use.aux',
                'cost' => 'OpenRouter',
            ],
            [
                'model' => 'google/gemini-3.5-flash',
                'name' => 'Gemini 3.5 Flash',
                'badge' => 'model.badge.advanced',
                'tone' => 'premium',
                'summary' => 'model.summary.gemini_35',
                'use_case' => 'model.use.quality',
                'cost' => 'OpenRouter',
            ],
        ],
        'openai' => [
            [
                'model' => 'gpt-4o-mini',
                'name' => 'GPT-4o mini',
                'badge' => 'model.badge.fast',
                'tone' => 'balanced',
                'summary' => 'model.summary.gpt4o_mini',
                'use_case' => 'model.use.daily',
                'cost' => '$0.15 / $0.60',
            ],
            [
                'model' => 'gpt-4o',
                'name' => 'GPT-4o',
                'badge' => 'model.badge.quality',
                'tone' => 'premium',
                'summary' => 'model.summary.gpt4o',
                'use_case' => 'model.use.content',
                'cost' => '$2.50 / $10.00',
            ],
        ],
        'anthropic' => [
            [
                'model' => 'claude-3-5-haiku-latest',
                'name' => 'Claude Haiku',
                'badge' => 'model.badge.light',
                'tone' => 'balanced',
                'summary' => 'model.summary.haiku',
                'use_case' => 'model.use.fast',
                'cost' => '$0.80 / $4.00',
            ],
            [
                'model' => 'claude-3-5-sonnet-latest',
                'name' => 'Claude Sonnet',
                'badge' => 'model.badge.editorial',
                'tone' => 'premium',
                'summary' => 'model.summary.sonnet',
                'use_case' => 'model.use.content',
                'cost' => '$3.00 / $15.00',
            ],
        ],
        'mistral' => [
            [
                'model' => 'mistral-small-latest',
                'name' => 'Mistral Small',
                'badge' => 'model.badge.cheap',
                'tone' => 'balanced',
                'summary' => 'model.summary.mistral_small',
                'use_case' => 'model.use.aux',
                'cost' => '$0.20 / $0.60',
            ],
            [
                'model' => 'mistral-large-latest',
                'name' => 'Mistral Large',
                'badge' => 'model.badge.advanced',
                'tone' => 'premium',
                'summary' => 'model.summary.mistral_large',
                'use_case' => 'model.use.content',
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
                'model_presets'    => self::modelPresetsForView(),
                'current_provider'    => $meta['provider'] ?: 'openrouter',
                'current_model'       => $meta['model'],
                'current_model_light' => $meta['model_light'] ?? '',
                'has_api_key'         => $hasKey,
                'errors'              => [],
                'notice'              => Session::flash('notice'),
                'csrf'                => CSRF::token(),
                'unsplash_configured' => ImageBankService::isAvailable(),
                'unsplash_masked'     => ImageBankService::maskedKey(),
                'image_notice'        => Session::flash('image_notice'),
                'image_error'         => Session::flash('image_error'),
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
            $errors[] = __('settings_ai.error.provider');
        }
        if ($model === '' || mb_strlen($model) > 100) {
            $errors[] = __('settings_ai.error.model_required');
        }
        if ($modelLight !== '' && mb_strlen($modelLight) > 100) {
            $errors[] = 'El modelo auxiliar no puede superar 100 caracteres.';
        }

        $keyProvided = trim($apiKey) !== '';
        $hadKey      = $this->hasApiKey($siteId);
        if (!$keyProvided && !$hadKey) {
            $errors[] = __('settings_ai.error.key_required');
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
                    // Los Gemini recientes pueden consumir primero tokens de
                    // razonamiento; 5 dejaba una respuesta válida sin texto.
                    ['max_tokens' => 128, 'temperature' => 0, 'timeout' => 30]
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
            ? __('settings_ai.saved_verified')
            : __('settings_ai.saved'));
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
                'model_presets'       => self::modelPresetsForView(),
                'current_provider'    => $provider,
                'current_model'       => $model,
                'current_model_light' => $modelLight,
                'has_api_key'         => $this->hasApiKey($siteId),
                'errors'              => $errors,
                'notice'              => null,
                'csrf'                => CSRF::token(),
                'unsplash_configured' => ImageBankService::isAvailable(),
                'unsplash_masked'     => ImageBankService::maskedKey(),
                'image_notice'        => null,
                'image_error'         => null,
            ]
        ));
    }

    /**
     * Guarda/cambia/elimina la Access Key de Unsplash (banco de imágenes).
     * La key es universal: se escribe en config/image_bank.php (gitignored),
     * no en settings. Solo admin.
     */
    public function updateImages(): void
    {
        CSRF::check();
        if (Auth::role() !== 'admin') {
            Response::forbidden(__('settings_ai.error.images_admin_only'));
        }
        $this->requireSiteId();

        $key    = trim((string) (Request::post('unsplash_key') ?? ''));
        $remove = (string) (Request::post('remove_unsplash') ?? '') === '1';

        if ($remove) {
            if (ImageBankService::writeConfig('')) {
                Session::flash('image_notice', __('settings_ai.unsplash_removed'));
            } else {
                Session::flash('image_error', 'No se pudo escribir config/image_bank.php (revisa los permisos de la carpeta config/).');
            }
            Response::redirect(base_url('admin/settings/ai'));
        }

        if ($key === '') {
            Session::flash('image_error', 'Pega una Access Key de Unsplash, o marca "Quitar la clave" para borrarla.');
            Response::redirect(base_url('admin/settings/ai'));
        }

        $check = ImageBankService::validateKey($key);
        if (!$check['ok']) {
            Session::flash('image_error', __('settings_ai.unsplash_rejected', ['motivo' => $check['error'] ?? __('settings_ai.unknown_reason')]));
            Response::redirect(base_url('admin/settings/ai'));
        }

        if (!ImageBankService::writeConfig($key)) {
            Session::flash('image_error', __('settings_ai.unsplash_write_failed'));
            Response::redirect(base_url('admin/settings/ai'));
        }

        Session::flash('image_notice', __('settings_ai.unsplash_saved'));
        Response::redirect(base_url('admin/settings/ai'));
    }

    /**
     * Presets con `badge`, `summary` y `use_case` ya traducidos. El ID del
     * modelo, su nombre comercial y el coste no se tocan.
     *
     * @return array<string, array<int, array<string,string>>>
     */
    private static function modelPresetsForView(): array
    {
        $out = [];
        foreach (self::MODEL_PRESETS as $provider => $presets) {
            foreach ($presets as $preset) {
                $preset['badge']    = __($preset['badge']);
                $preset['summary']  = __($preset['summary']);
                $preset['use_case'] = __($preset['use_case']);
                $out[$provider][] = $preset;
            }
        }
        return $out;
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('common.no_active_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
