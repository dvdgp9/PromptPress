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
        $errors[] = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
    }

    $provider = (string) $defaults['provider'];
    $model    = trim((string) $defaults['model']);
    $modelLight = trim((string) $defaults['model_light']);
    $apiKey   = (string) (Request::post('api_key') ?? '');
    $unsplashKey = trim((string) (Request::post('unsplash_key') ?? ''));

    if (empty($errors)) {
        if (!array_key_exists($provider, $providers)) {
            $errors[] = 'Proveedor no válido.';
        }
        if ($model === '' || strlen($model) > 100) {
            $errors[] = 'El modelo principal es obligatorio (máx. 100 caracteres).';
        }
        if ($modelLight !== '' && strlen($modelLight) > 100) {
            $errors[] = 'El modelo pequeño no puede superar 100 caracteres.';
        }
        if (trim($apiKey) === '') {
            $errors[] = 'La API key es obligatoria.';
        }
    }

    // Test real contra la API
    if (empty($errors)) {
        $result = AIProviderTester::test($provider, $model, $apiKey);
        if (!$result['ok']) {
            $errors[] = $result['error'] ?? 'Error desconocido al probar la API key.';
        } elseif (empty($result['model_found'])) {
            $warning = 'La API key es válida, pero el modelo "' . $model . '" no aparece en el listado de '
                     . $providers[$provider] . '. Continuamos igualmente — verifica el nombre del modelo más tarde.';
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
                throw new RuntimeException('No hay site_id en sesión. Vuelve atrás y recrea el sitio.');
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
                        $warning = trim($warning . ' No hemos podido verificar la clave de Unsplash ('
                            . ($check['error'] ?? 'motivo desconocido')
                            . '). La hemos guardado igualmente; si no aparecen imágenes, revísala.');
                    }
                } else {
                    $warning = trim($warning . ' No se pudo escribir config/image_bank.php (¿permisos de la carpeta config?). '
                        . 'Las imágenes de Unsplash quedarán desactivadas hasta crear ese archivo a mano.');
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
            $errors[] = 'Error guardando la configuración: ' . $e->getMessage();
        }
    }
}

// Render
$csrfToken = CSRF::token();
ob_start();
?>
<h1 class="pp-step-title">Conexión con la IA y las imágenes</h1>
<p class="pp-step-intro">
    PromptPress usa una API de IA para generar el contenido y, opcionalmente, Unsplash para las fotos.
    Configura tu proveedor de IA (verificaremos la API key) y, si quieres, añade tu clave de Unsplash.
</p>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--fail">
        <strong>No se ha podido continuar:</strong>
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
        <label for="provider">Proveedor</label>
        <select id="provider" name="provider" required>
            <?php foreach ($providers as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $code === $defaults['provider'] ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="pp-field">
        <label for="model">Modelo principal</label>
        <input type="text" id="model" name="model" value="<?= e($defaults['model']) ?>" required maxlength="100" list="model-suggestions">
        <datalist id="model-suggestions">
            <?php foreach ($suggestedModels as $providerCode => $models): ?>
                <?php foreach ($models as $m): ?>
                    <option value="<?= e($m) ?>" data-provider="<?= e($providerCode) ?>"></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </datalist>
        <small id="model-help">Para generación de páginas, secciones y artículos completos.</small>
    </div>

    <div class="pp-field">
        <label for="model_light">Modelo pequeño</label>
        <input type="text" id="model_light" name="model_light" value="<?= e($defaults['model_light']) ?>" maxlength="100" list="model-suggestions">
        <small id="model-light-help">Para reescrituras, SEO, resúmenes y tareas rápidas. Puedes dejarlo vacío para usar siempre el principal.</small>
    </div>

    <div class="pp-field">
        <label for="api_key">API Key</label>
        <input type="password" id="api_key" name="api_key" required autocomplete="off" placeholder="sk-or-v1-...">
        <small>
            Se almacenará <strong>encriptada</strong> en la base de datos (AES-256-GCM con la clave única de tu instalación).
            Solo el servidor podrá descifrarla.
        </small>
    </div>

    <hr class="pp-sep">

    <div class="pp-field">
        <label for="unsplash_key">Unsplash Access Key <span style="font-weight:normal;opacity:.7;">(opcional)</span></label>
        <input type="password" id="unsplash_key" name="unsplash_key" autocomplete="off"
               value="<?= e((string) (Request::post('unsplash_key') ?? '')) ?>" placeholder="Tu Access Key de Unsplash">
        <small>
            Permite que las páginas se generen con fotos reales. Consigue una gratis en
            <a href="https://unsplash.com/developers" target="_blank" rel="noopener">unsplash.com/developers</a>
            (crea una app → copia la <em>Access Key</em>; 50 imágenes/hora en modo demo).
            Si la dejas vacía, podrás añadirla más tarde y, mientras, las páginas se generarán sin imágenes.
        </small>
    </div>

    <div class="pp-form__actions">
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">
            Probar conexión y finalizar →
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
    function update() {
        var p = providerSel.value;
        var arr = suggestions[p] || [];
        modelInput.placeholder = arr[0] || '';
        if (modelLightInput) modelLightInput.placeholder = arr[1] || arr[0] || '';
        if (help) help.textContent = 'Principal sugerido: ' + (arr[0] || 'escribe un ID compatible');
        if (lightHelp) lightHelp.textContent = 'Pequeño sugerido: ' + (arr[1] || arr[0] || 'opcional');
    }
    providerSel.addEventListener('change', update);
    update();
})();
</script>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('ai_provider', 'Proveedor IA', $content);
