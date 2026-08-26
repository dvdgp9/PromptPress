<?php
/**
 * Paso 4: Configuración del proveedor de IA.
 *
 * Flujo:
 *   GET  → form con provider + model + api_key
 *   POST → valida CSRF, prueba la API key real, encripta y guarda en `settings`,
 *          avanza a `complete`.
 */

use App\Services\AIProviderTester;
use Core\Crypto;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

$providers       = AIProviderTester::PROVIDERS;
$suggestedModels = AIProviderTester::SUGGESTED_MODELS;

$defaults = [
    'provider'    => Request::post('provider') ?? 'openrouter',
    'model'       => Request::post('model') ?? 'google/gemini-3-flash-preview',
    'model_light' => Request::post('model_light') ?? 'google/gemini-3.1-flash-lite',
];

$errors  = [];
$warning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate((string) (Request::post('_csrf') ?? ''))) {
        $errors[] = __('inst.err.csrf');
    }

    $provider = (string) $defaults['provider'];
    $model    = trim((string) $defaults['model']);
    $modelLight = trim((string) $defaults['model_light']);
    $apiKey   = (string) (Request::post('api_key') ?? '');
    $unsplashKey = trim((string) (Request::post('unsplash_key') ?? ''));

    if (empty($errors)) {
        if (!array_key_exists($provider, $providers)) {
            $errors[] = __('inst.ai.err.provider');
        }
        if ($model === '' || strlen($model) > 100) {
            $errors[] = __('inst.ai.err.model');
        }
        if ($modelLight !== '' && strlen($modelLight) > 100) {
            $errors[] = __('inst.ai.err.model_light');
        }
        if (trim($apiKey) === '') {
            $errors[] = __('inst.ai.err.api_key');
        }
    }

    // Test real contra la API
    if (empty($errors)) {
        $result = AIProviderTester::test($provider, $model, $apiKey);
        if (!$result['ok']) {
            $errors[] = $result['error'] ?? __('inst.ai.err.unknown');
        } elseif (empty($result['model_found'])) {
            $warning = __('inst.ai.warn.model_unknown', [
                'modelo'    => $model,
                'proveedor' => (string) $providers[$provider],
            ]);
        }
    }

    // Persistir en `settings`
    if (empty($errors)) {
        try {
            $cfg    = \Core\App::config();
            $appKey = (string) ($cfg['app_key'] ?? '');
            $encryptedKey = Crypto::encrypt($apiKey, $appKey);

            $siteId = (int) (Session::get('site_id') ?? 0);
            if ($siteId <= 0) {
                throw new RuntimeException(__('inst.ai.err.no_site'));
            }

            $pdo = Database::connection();
            $pdo->beginTransaction();

            $upsert = $pdo->prepare(
                'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)'
            );
            $upsert->execute([$siteId, 'ai_provider', $provider, 0]);
            $upsert->execute([$siteId, 'ai_model', $model, 0]);
            $upsert->execute([$siteId, 'ai_model_light', $modelLight, 0]);
            $upsert->execute([$siteId, 'ai_api_key', $encryptedKey, 1]);

            $pdo->commit();

            // Banco de imágenes (Unsplash) — opcional. Se guarda en
            // config/image_bank.php (gitignored, fuera de config.php). Si la
            // clave no valida o no se puede escribir, avisamos pero NO
            // bloqueamos: el sitio puede configurarla/funcionar sin imágenes.
            if ($unsplashKey !== '') {
                $check = \App\Services\ImageBankService::validateKey($unsplashKey);
                if (InstallerApp::writeImageBankFile($unsplashKey)) {
                    if (!$check['ok']) {
                        $warning = trim($warning . ' ' . __('inst.ai.warn.unsplash_unverified', [
                            'motivo' => (string) ($check['error'] ?? __('inst.ai.unknown_reason')),
                        ]));
                    }
                } else {
                    $warning = trim($warning . ' ' . __('inst.ai.warn.unsplash_unwritable'));
                }
            }

            // Marcar la instalación como completa: crear flag .installed
            $flagOk = @file_put_contents(PP_INSTALLED_FLAG, "Installed at " . date('c') . "\n", LOCK_EX);
            if ($flagOk === false) {
                error_log('[PromptPress install] no se pudo crear ' . PP_INSTALLED_FLAG);
            }

            InstallerApp::unlockNextStep('ai_provider');
            if ($warning !== '') {
                Session::set('install_warning', $warning);
            }
            Response::redirect(InstallerApp::stepUrl('complete'));
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = __('inst.ai.err.save', ['detalle' => $e->getMessage()]);
        }
    }
}

// Render
$csrfToken = CSRF::token();
ob_start();
?>
<h1 class="pp-step-title"><?= e(__('inst.ai.title')) ?></h1>
<p class="pp-step-intro">
    <?= e(__('inst.ai.intro')) ?>
</p>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--fail">
        <strong><?= e(__('inst.err.cannot_continue')) ?></strong>
        <ul style="margin: 0.5rem 0 0 1.25rem;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="pp-form" autocomplete="off" id="ai-form">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div class="pp-field">
        <label for="provider"><?= e(__('inst.ai.provider')) ?></label>
        <select id="provider" name="provider" required>
            <?php foreach ($providers as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $code === $defaults['provider'] ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="pp-field">
        <label for="model"><?= e(__('inst.ai.model')) ?></label>
        <input type="text" id="model" name="model" value="<?= e($defaults['model']) ?>" required maxlength="100" list="model-suggestions">
        <datalist id="model-suggestions">
            <?php foreach ($suggestedModels as $providerCode => $models): ?>
                <?php foreach ($models as $m): ?>
                    <option value="<?= e($m) ?>" data-provider="<?= e($providerCode) ?>"></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </datalist>
        <small id="model-help"><?= e(__('inst.ai.model_help')) ?></small>
    </div>

    <div class="pp-field">
        <label for="model_light"><?= e(__('inst.ai.model_light')) ?></label>
        <input type="text" id="model_light" name="model_light" value="<?= e($defaults['model_light']) ?>" maxlength="100" list="model-suggestions">
        <small id="model-light-help"><?= e(__('inst.ai.model_light_help')) ?></small>
    </div>

    <div class="pp-field">
        <label for="api_key">API Key</label>
        <input type="password" id="api_key" name="api_key" required autocomplete="off" placeholder="sk-or-v1-...">
        <small>
            <?= __('inst.ai.api_key_help.html') ?>
        </small>
    </div>

    <hr class="pp-sep">

    <div class="pp-field">
        <label for="unsplash_key">Unsplash Access Key <span style="font-weight:normal;opacity:.7;">(<?= e(__('common.optional')) ?>)</span></label>
        <input type="password" id="unsplash_key" name="unsplash_key" autocomplete="off"
               value="<?= e((string) (Request::post('unsplash_key') ?? '')) ?>" placeholder="<?= e(__('inst.ai.unsplash_ph')) ?>">
        <small>
            <?= __('inst.ai.unsplash_help.html') ?>
        </small>
    </div>

    <div class="pp-form__actions">
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">
            <?= e(__('inst.ai.submit')) ?> →
        </button>
    </div>
</form>

<script>
// Sugerencias contextuales: actualizar el placeholder del modelo según el proveedor
(function () {
    var providerSel = document.getElementById('provider');
    var modelInput  = document.getElementById('model');
    var modelLightInput = document.getElementById('model_light');
    var help        = document.getElementById('model-help');
    var lightHelp   = document.getElementById('model-light-help');
    var suggestions = <?= json_encode($suggestedModels, JSON_UNESCAPED_SLASHES) ?>;
    var i18n = <?= json_encode([
        'main'      => __('inst.ai.js.main_suggested'),
        'light'     => __('inst.ai.js.light_suggested'),
        'write_id'  => __('inst.ai.js.write_id'),
        'optional'  => __('common.optional'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    function update() {
        var p = providerSel.value;
        var arr = suggestions[p] || [];
        modelInput.placeholder = arr[0] || '';
        if (modelLightInput) modelLightInput.placeholder = arr[1] || arr[0] || '';
        if (help) help.textContent = i18n.main + ': ' + (arr[0] || i18n.write_id);
        if (lightHelp) lightHelp.textContent = i18n.light + ': ' + (arr[1] || arr[0] || i18n.optional);
    }
    providerSel.addEventListener('change', update);
    update();
})();
</script>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('ai_provider', __('inst.ai.step_title'), $content);
