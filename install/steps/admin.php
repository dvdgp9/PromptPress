<?php
/**
 * Paso 3: Creación del usuario admin + configuración inicial del sitio.
 *
 * Flujo:
 *   GET  → form con datos del admin (username, email, password) y del sitio (nombre, URL, idioma, zona horaria)
 *   POST → valida, hashea password, INSERT en users + sites, auto-login, redirect a ai_provider
 */

use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

// Catálogo compartido con Ajustes y con el pipeline de generación IA.
$languages = \App\Services\LanguageService::LANGUAGES;

// Mismo catálogo que Ajustes, para no tener dos listas que se separan.
$timezones = [];
foreach (\App\Controllers\Admin\SettingsController::TIMEZONES as $tz => $tzKey) {
    $timezones[$tz] = __($tzKey);
}

// Defaults: recordar lo enviado, o sugerir valores sensatos
$detectedUrl = (string) (parse_url(base_url(''), PHP_URL_SCHEME) ?: 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$defaults = [
    'username'  => Request::post('username') ?? '',
    'email'     => Request::post('email') ?? '',
    'site_name' => Request::post('site_name') ?? '',
    'site_url'  => Request::post('site_url') ?? $detectedUrl,
    'language'  => Request::post('language') ?? \App\Services\AdminI18n::locale(),
    'timezone'  => Request::post('timezone') ?? 'Europe/Madrid',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF
    if (!CSRF::validate((string) (Request::post('_csrf') ?? ''))) {
        $errors[] = __('inst.err.csrf');
    }

    // 2. Recoger
    $username = trim((string) $defaults['username']);
    $email    = trim((string) $defaults['email']);
    $password = (string) (Request::post('password') ?? '');
    $passwordConfirm = (string) (Request::post('password_confirm') ?? '');
    $siteName = trim((string) $defaults['site_name']);
    $siteUrl  = trim((string) $defaults['site_url']);
    $language = (string) $defaults['language'];
    $timezone = (string) $defaults['timezone'];

    // 3. Validación
    if (empty($errors)) {
        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = __('inst.adm.err.username_len');
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = __('inst.adm.err.username_chars');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('inst.adm.err.email');
        }
        if (strlen($password) < 8) {
            $errors[] = __('inst.adm.err.password_len');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = __('inst.adm.err.password_match');
        }
        if ($siteName === '' || strlen($siteName) > 255) {
            $errors[] = __('inst.adm.err.site_name');
        }
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            $errors[] = __('inst.adm.err.site_url');
        }
        if (!array_key_exists($language, $languages)) {
            $errors[] = __('inst.adm.err.language');
        }
        if (!array_key_exists($timezone, $timezones)) {
            $errors[] = __('inst.adm.err.timezone');
        }
    }

    // 4. Inserción en BD (transacción)
    if (empty($errors)) {
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            // Comprobar duplicados
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn()) {
                $errors[] = __('inst.adm.err.duplicate');
                $pdo->rollBack();
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);

                // Insertar usuario admin
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$username, $email, $hash, 'admin']);
                $userId = (int) $pdo->lastInsertId();

                // Insertar sitio
                $stmt = $pdo->prepare(
                    'INSERT INTO sites (name, url, language, timezone) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$siteName, $siteUrl, $language, $timezone]);
                $siteId = (int) $pdo->lastInsertId();

                // Idioma principal del sitio en el catálogo multi-idioma. La
                // migración lo rellena para los sitios que YA existen; una
                // instalación nueva crea el sitio después de migrar, así que su
                // fila hay que ponerla aquí.
                try {
                    $pdo->prepare(
                        'INSERT INTO site_languages (site_id, code, is_primary, sort_order) VALUES (?, ?, 1, 0)'
                    )->execute([$siteId, $language]);
                } catch (\Throwable $e) {
                    // Si la tabla no existiera, el sitio sigue funcionando como
                    // monolingüe (LanguageService cae al idioma de `sites`).
                }

                $pdo->commit();

                // Auto-login + recordar el sitio activo en sesión
                Session::regenerate();
                Session::set('user_id', $userId);
                Session::set('site_id', $siteId);

                InstallerApp::unlockNextStep('admin');
                Response::redirect(InstallerApp::stepUrl('ai_provider'));
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = __('inst.adm.err.save', ['detalle' => $e->getMessage()]);
        }
    }
}

// Render
$csrfToken = CSRF::token();
ob_start();
?>
<h1 class="pp-step-title"><?= e(__('inst.adm.title')) ?></h1>
<p class="pp-step-intro">
    <?= e(__('inst.adm.intro')) ?>
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

<form method="post" class="pp-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <fieldset class="pp-fieldset">
        <legend><?= e(__('inst.adm.account')) ?></legend>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="username"><?= e(__('inst.adm.username')) ?></label>
                <input type="text" id="username" name="username" value="<?= e($defaults['username']) ?>" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\-]+">
                <small><?= e(__('inst.adm.username_help')) ?></small>
            </div>
            <div class="pp-field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($defaults['email']) ?>" required>
            </div>
        </div>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="password"><?= e(__('inst.adm.password')) ?></label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                <small><?= e(__('inst.adm.password_help')) ?></small>
            </div>
            <div class="pp-field">
                <label for="password_confirm"><?= e(__('inst.adm.password_confirm')) ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
            </div>
        </div>
    </fieldset>

    <fieldset class="pp-fieldset">
        <legend><?= e(__('inst.adm.site')) ?></legend>

        <div class="pp-field">
            <label for="site_name"><?= e(__('inst.adm.site_name')) ?></label>
            <input type="text" id="site_name" name="site_name" value="<?= e($defaults['site_name']) ?>" required maxlength="255">
            <small><?= __('inst.adm.site_name_help.html') ?></small>
        </div>

        <div class="pp-field">
            <label for="site_url"><?= e(__('inst.adm.site_url')) ?></label>
            <input type="url" id="site_url" name="site_url" value="<?= e($defaults['site_url']) ?>" required>
            <small><?= e(__('inst.adm.site_url_help')) ?></small>
        </div>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="language"><?= e(__('inst.adm.language')) ?></label>
                <select id="language" name="language" required>
                    <?php foreach ($languages as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $code === $defaults['language'] ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pp-field">
                <label for="timezone"><?= e(__('inst.adm.timezone')) ?></label>
                <select id="timezone" name="timezone" required>
                    <?php foreach ($timezones as $tz => $label): ?>
                        <option value="<?= e($tz) ?>" <?= $tz === $defaults['timezone'] ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>

    <div class="pp-form__actions">
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">
            <?= e(__('inst.adm.submit')) ?> →
        </button>
    </div>
</form>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('admin', __('inst.adm.step_title'), $content);
