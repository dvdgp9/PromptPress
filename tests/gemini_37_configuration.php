<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\SettingsAIController;
use App\Services\AI\AIPricing;
use App\Services\AI\AIProviderFactory;
use App\Services\AIProviderTester;
use Core\Database;

$failed = 0;
function checkGemini37(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$model = 'google/gemini-3.7-flash';
$oldModel = 'google/gemini-3-flash-preview';
$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
$meta = AIProviderFactory::currentMeta($siteId);

checkGemini37('site_uses_openrouter', ($meta['provider'] ?? '') === 'openrouter', json_encode($meta));
checkGemini37('main_model_is_gemini_37', ($meta['model'] ?? '') === $model, json_encode($meta));
checkGemini37(
    'settings_suggest_gemini_37_not_preview_3',
    in_array($model, SettingsAIController::suggestedModelsFor('openrouter'), true)
        && !in_array($oldModel, SettingsAIController::suggestedModelsFor('openrouter'), true)
);
$settingsController = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/SettingsAIController.php');
checkGemini37(
    'settings_connection_test_allows_reasoning_tokens',
    str_contains($settingsController, "'max_tokens' => 128")
        && !str_contains($settingsController, "'max_tokens' => 5")
);
checkGemini37(
    'installer_tester_suggests_gemini_37',
    in_array($model, AIProviderTester::SUGGESTED_MODELS['openrouter'], true)
        && !in_array($oldModel, AIProviderTester::SUGGESTED_MODELS['openrouter'], true)
);
checkGemini37(
    'pricing_uses_current_openrouter_rate',
    AIPricing::rateFor($model) === [0.375, 1.875],
    json_encode(AIPricing::rateFor($model))
);

$onboarding = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/OnboardingController.php');
$onboardingView = (string) file_get_contents(PP_ROOT . '/views/admin/onboarding/index.php');
checkGemini37(
    'new_onboarding_defaults_to_gemini_37',
    str_contains($onboarding, $model)
        && str_contains($onboardingView, $model)
        && !str_contains($onboarding, $oldModel)
        && !str_contains($onboardingView, $oldModel)
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
