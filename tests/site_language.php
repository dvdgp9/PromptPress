<?php

declare(strict_types=1);

/**
 * Idioma del sitio (paso 1): `sites.language` manda en la generación IA.
 *
 * Cubre el contrato de LanguageService y, sobre todo, blinda la regresión que
 * originó el trabajo: el pipeline de generación pasaba `'language' => 'es'`
 * literal, así que un sitio en francés se generaba en castellano.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\LanguageService;

$failed = 0;
function checkSiteLanguage(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
        }
    }
}

// ---------------------------------------------------------------------------
// 1. Contrato de LanguageService
// ---------------------------------------------------------------------------

checkSiteLanguage(
    'supported_languages_include_fr',
    LanguageService::isSupported('fr') && LanguageService::isSupported('FR'),
    'fr debe estar admitido y la comprobación ser case-insensitive'
);

checkSiteLanguage(
    'unsupported_language_falls_back_to_default',
    LanguageService::normalize('klingon') === 'es'
        && LanguageService::normalize(null) === 'es'
        && LanguageService::normalize('  FR ') === 'fr',
    'normalize: ' . LanguageService::normalize('klingon') . ' / ' . LanguageService::normalize('  FR ')
);

checkSiteLanguage(
    'prompt_label_uses_endonym',
    LanguageService::promptLabel('fr') === 'français'
        && LanguageService::promptLabel('es') === 'español'
        && LanguageService::promptLabel('pt') === 'português',
    LanguageService::promptLabel('fr')
);

checkSiteLanguage(
    'ui_label_is_capitalised_endonym',
    LanguageService::label('fr') === 'Français' && LanguageService::label('eu') === 'Euskara',
    LanguageService::label('fr')
);

// Todo idioma admitido tiene endónimo propio para prompts (nada cae al default
// silenciosamente: eso generaría contenido en castellano sin avisar).
$missing = [];
foreach (array_keys(LanguageService::LANGUAGES) as $code) {
    if ($code !== 'es' && LanguageService::promptLabel($code) === 'español') {
        $missing[] = $code;
    }
}
checkSiteLanguage(
    'every_supported_language_has_prompt_label',
    $missing === [],
    'sin endónimo propio: ' . implode(', ', $missing)
);

// ---------------------------------------------------------------------------
// 2. Guardarraíl: ningún hardcode 'es' en el pipeline de generación
// ---------------------------------------------------------------------------

$pipelineFiles = [
    PP_ROOT . '/app/Controllers/Admin/OnboardingController.php',
    PP_ROOT . '/app/Services/Canvas/CanvasChatService.php',
    PP_ROOT . '/app/Services/Canvas/CanvasGenerator.php',
    PP_ROOT . '/app/Services/Renderer/CustomBlockGenerator.php',
];

$offenders = [];
foreach ($pipelineFiles as $file) {
    $src = (string) file_get_contents($file);
    foreach (explode("\n", $src) as $i => $line) {
        if (preg_match("/'language'\s*=>\s*'[a-z]{2}'/", $line)) {
            $offenders[] = basename($file) . ':' . ($i + 1) . ' ' . trim($line);
        }
    }
}
checkSiteLanguage(
    'generation_pipeline_has_no_hardcoded_language',
    $offenders === [],
    implode("\n", $offenders)
);

// El idioma debe llegar a los prompts como endónimo, no como código ISO.
$callers = 0;
foreach ($pipelineFiles as $file) {
    $src = (string) file_get_contents($file);
    $callers += preg_match_all('/LanguageService::promptLabelFor\(/', $src);
}
checkSiteLanguage(
    'generation_pipeline_resolves_language_from_site',
    $callers >= 5,
    'llamadas a promptLabelFor encontradas: ' . $callers
);

// ---------------------------------------------------------------------------
// 2b. La regla de idioma tiene que viajar en el system prompt
// ---------------------------------------------------------------------------

// Verificado con IA real: pasar "Idioma: {language}" en el user_template NO
// basta (la memoria del sitio en castellano gana). La orden dura va en la
// instruction de toda acción que escriba texto visible.
$writingActions = [
    \App\Services\AI\Actions::EDIT_CANVAS_SECTION,
    \App\Services\AI\Actions::EDIT_CANVAS_PAGE,
    \App\Services\AI\Actions::COMPOSE_CANVAS_PAGE,
    \App\Services\AI\Actions::COMPOSE_CUSTOM_PAGE_FROM_REFERENCE,
    \App\Services\AI\Actions::GENERATE_CUSTOM_BLOCK_FROM_REFERENCE,
];
$withoutRule = [];
foreach ($writingActions as $action) {
    $instruction = (string) (\App\Services\AI\Actions::get($action)['instruction'] ?? '');
    if (!str_contains($instruction, 'IDIOMA DE SALIDA (REGLA DURA') || !str_contains($instruction, '{language}')) {
        $withoutRule[] = $action;
    }
}
checkSiteLanguage(
    'writing_actions_carry_hard_language_rule',
    $withoutRule === [],
    'sin regla de idioma en el system prompt: ' . implode(', ', $withoutRule)
);

checkSiteLanguage(
    'language_rule_keeps_admin_reply_in_spanish',
    str_contains(\App\Services\AI\Actions::languageRule(), '`<pp-reply>`')
        && str_contains(\App\Services\AI\Actions::languageRule(), 'siempre en español'),
    'El mensaje del chat al administrador debe seguir en español'
);

// ---------------------------------------------------------------------------
// 3. Al cambiar el idioma en Ajustes hay que invalidar la caché por request
// ---------------------------------------------------------------------------

$settingsSrc = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/SettingsController.php');
checkSiteLanguage(
    'settings_invalidate_language_cache',
    str_contains($settingsSrc, 'LanguageService::forget('),
    'SettingsController debe llamar a LanguageService::forget() tras guardar'
);
checkSiteLanguage(
    'settings_reuse_language_catalog',
    str_contains($settingsSrc, 'LanguageService::LANGUAGES'),
    'La lista de idiomas de Ajustes debe salir de LanguageService, no duplicarse'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
