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

$languages = [
    'es' => 'Español',
    'en' => 'English',
    'ca' => 'Català',
    'gl' => 'Galego',
    'eu' => 'Euskara',
    'fr' => 'Français',
    'pt' => 'Português',
];

$timezones = [
    'Europe/Madrid'    => 'Europa / Madrid',
    'Europe/London'    => 'Europa / Londres',
    'Europe/Paris'     => 'Europa / París',
    'Europe/Berlin'    => 'Europa / Berlín',
    'America/New_York' => 'América / Nueva York',
    'America/Mexico_City' => 'América / Ciudad de México',
    'America/Bogota'   => 'América / Bogotá',
    'America/Buenos_Aires' => 'América / Buenos Aires',
    'America/Santiago' => 'América / Santiago de Chile',
    'UTC'              => 'UTC',
];

// Defaults: recordar lo enviado, o sugerir valores sensatos
$detectedUrl = (string) (parse_url(base_url(''), PHP_URL_SCHEME) ?: 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$defaults = [
    'username'  => Request::post('username') ?? '',
    'email'     => Request::post('email') ?? '',
    'site_name' => Request::post('site_name') ?? '',
    'site_url'  => Request::post('site_url') ?? $detectedUrl,
    'language'  => Request::post('language') ?? 'es',
    'timezone'  => Request::post('timezone') ?? 'Europe/Madrid',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF
    if (!CSRF::validate((string) (Request::post('_csrf') ?? ''))) {
        $errors[] = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
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
            $errors[] = 'El nombre de usuario debe tener entre 3 y 50 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = 'El nombre de usuario solo puede contener letras, números, guiones, guiones bajos y puntos.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es válido.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Las contraseñas no coinciden.';
        }
        if ($siteName === '' || strlen($siteName) > 255) {
            $errors[] = 'El nombre del sitio es obligatorio (máx. 255 caracteres).';
        }
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'La URL del sitio no es válida (debe empezar por http:// o https://).';
        }
        if (!array_key_exists($language, $languages)) {
            $errors[] = 'Idioma no válido.';
        }
        if (!array_key_exists($timezone, $timezones)) {
            $errors[] = 'Zona horaria no válida.';
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
                $errors[] = 'Ya existe un usuario con ese nombre o email.';
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
            $errors[] = 'Error guardando los datos: ' . $e->getMessage();
        }
    }
}

// Render
$csrfToken = CSRF::token();
ob_start();
?>
<h1 class="pp-step-title">Crea tu cuenta y tu sitio</h1>
<p class="pp-step-intro">
    Vamos a crear el usuario administrador del panel y la configuración inicial del sitio.
    Podrás cambiar todo esto más tarde desde el panel de administración.
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

<form method="post" class="pp-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <fieldset class="pp-fieldset">
        <legend>Cuenta de administrador</legend>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="username">Nombre de usuario</label>
                <input type="text" id="username" name="username" value="<?= e($defaults['username']) ?>" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\-]+">
                <small>3-50 caracteres. Solo letras, números, _, . y -</small>
            </div>
            <div class="pp-field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($defaults['email']) ?>" required>
            </div>
        </div>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
                <small>Mínimo 8 caracteres</small>
            </div>
            <div class="pp-field">
                <label for="password_confirm">Confirmar contraseña</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
            </div>
        </div>
    </fieldset>

    <fieldset class="pp-fieldset">
        <legend>Configuración del sitio</legend>

        <div class="pp-field">
            <label for="site_name">Nombre del sitio</label>
            <input type="text" id="site_name" name="site_name" value="<?= e($defaults['site_name']) ?>" required maxlength="255">
            <small>Ejemplo: <em>Mi tienda online</em></small>
        </div>

        <div class="pp-field">
            <label for="site_url">URL del sitio</label>
            <input type="url" id="site_url" name="site_url" value="<?= e($defaults['site_url']) ?>" required>
            <small>URL pública donde se servirá el sitio (sin barra final)</small>
        </div>

        <div class="pp-field-row">
            <div class="pp-field">
                <label for="language">Idioma principal</label>
                <select id="language" name="language" required>
                    <?php foreach ($languages as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $code === $defaults['language'] ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pp-field">
                <label for="timezone">Zona horaria</label>
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
            Crear cuenta y sitio →
        </button>
    </div>
</form>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('admin', 'Administrador y sitio', $content);
