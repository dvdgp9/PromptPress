<?php
/**
 * Paso 2: Configuración de base de datos.
 *
 * Flujo:
 *   GET  → muestra formulario (host, port, name, user, pass)
 *   POST → valida CSRF, prueba conexión, escribe config.php, ejecuta migración.
 *          Si todo OK → desbloquea paso siguiente y redirige.
 */

use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;

require_once dirname(__DIR__) . '/migrate.php';

/** Valores por defecto del formulario (recordamos lo enviado en caso de error) */
$defaults = [
    'host' => Request::post('host') ?? '127.0.0.1',
    'port' => Request::post('port') ?? '3306',
    'name' => Request::post('name') ?? '',
    'user' => Request::post('user') ?? '',
    'pass' => Request::post('pass') ?? '',
];

$errors  = [];
$success = '';
/** Si /config no es escribible, aquí guardamos el contenido de config.php para que el usuario lo suba a mano. */
$manualConfig = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CSRF
    if (!CSRF::validate((string) (Request::post('_csrf') ?? ''))) {
        $errors[] = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
    }

    // 2. Validación básica
    $host = trim((string) $defaults['host']);
    $port = (int) $defaults['port'];
    $name = trim((string) $defaults['name']);
    $user = trim((string) $defaults['user']);
    $pass = (string) $defaults['pass'];

    if (empty($errors)) {
        if ($host === '')          { $errors[] = 'El host no puede estar vacío.'; }
        if ($port < 1 || $port > 65535) { $errors[] = 'El puerto debe ser un número entre 1 y 65535.'; }
        if ($name === '')          { $errors[] = 'El nombre de la base de datos es obligatorio.'; }
        if ($user === '')          { $errors[] = 'El usuario es obligatorio.'; }
    }

    // 3. Test de conexión
    $pdo = null;
    if (empty($errors)) {
        try {
            $pdo = \Core\Database::testConnection($host, $port, $name, $user, $pass);
        } catch (PDOException $e) {
            $errors[] = 'No se puede conectar a la base de datos: ' . $e->getMessage();
        }
    }

    // 4. Verificar privilegios mínimos (CREATE TABLE)
    if (empty($errors) && $pdo instanceof PDO) {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS pp_install_check (id INT) ENGINE=InnoDB');
            $pdo->exec('DROP TABLE IF EXISTS pp_install_check');
        } catch (PDOException $e) {
            $errors[] = 'El usuario no tiene permisos suficientes (CREATE/DROP TABLE): ' . $e->getMessage();
        }
    }

    // 5. Escribir config.php (o preparar el fallback manual si /config no es escribible)
    if (empty($errors)) {
        $dbConf = [
            'host'    => $host,
            'port'    => $port,
            'name'    => $name,
            'user'    => $user,
            'pass'    => $pass,
            'charset' => 'utf8mb4',
        ];
        if (is_file(PP_CONFIG_FILE)) {
            // El usuario ya subió config.php a mano (fallback): lo usamos tal cual.
        } else {
            $appKey  = bin2hex(random_bytes(32));
            $written = InstallerApp::writeConfigFile($dbConf, $appKey);
            if (!$written) {
                // No podemos escribir el archivo: en vez de cortar, mostramos el
                // contenido para que el usuario lo cree con el gestor de archivos.
                $manualConfig = InstallerApp::buildConfigContent($dbConf, $appKey);
            }
        }
    }

    // 6. Ejecutar migración (solo si config.php ya está en su sitio)
    if (empty($errors) && $manualConfig === '' && $pdo instanceof PDO) {
        $result = pp_run_migrations($pdo);
        if (!empty($result['errors'])) {
            // Limpiar config.php para que se pueda reintentar
            @unlink(PP_CONFIG_FILE);
            $errors[] = 'Error aplicando el schema: ' . $result['errors'][0]['error'];
            foreach ($result['errors'] as $err) {
                error_log('[PromptPress install] ' . $err['statement'] . ' → ' . $err['error']);
            }
        }
    }

    // 7. Marcar paso completado y avanzar
    if (empty($errors) && $manualConfig === '') {
        InstallerApp::unlockNextStep('database');
        Session::set('install_db_ready', true);
        Response::redirect(InstallerApp::stepUrl('admin'));
    }
}

// Render
$csrfToken = CSRF::token();
ob_start();
?>
<h1 class="pp-step-title">Configuración de la base de datos</h1>
<p class="pp-step-intro">
    Introduce los datos de conexión a tu base de datos MySQL/MariaDB.
    <strong>La base de datos debe existir y estar vacía</strong> — créala desde tu panel de hosting (cPanel/Plesk) antes de continuar.
</p>

<?php if ($manualConfig !== ''): ?>
    <div class="pp-alert pp-alert--warn">
        <strong>Un último paso manual</strong>
        <p style="margin:.5rem 0;">
            La conexión funciona, pero el instalador no puede crear el archivo de configuración
            automáticamente (la carpeta <code>/config</code> no tiene permisos de escritura).
            No te preocupes, se soluciona en 1 minuto y no necesitas la terminal:
        </p>
        <ol style="margin:.5rem 0 .75rem 1.25rem;">
            <li>Abre el <strong>Gestor de archivos</strong> de tu hosting (cPanel/Plesk).</li>
            <li>Entra en la carpeta <code>config/</code> de la instalación.</li>
            <li>Crea un archivo llamado <code>config.php</code> con exactamente este contenido:</li>
        </ol>
        <textarea readonly rows="18" onclick="this.select()"
            style="width:100%;font-family:monospace;font-size:.8rem;white-space:pre;"><?= e($manualConfig) ?></textarea>
        <p style="margin:.75rem 0 0;">Cuando lo hayas subido, pulsa el botón para continuar.</p>
        <form method="post" class="pp-form" style="margin-top:.5rem;">
            <input type="hidden" name="_csrf" value="<?= e(CSRF::token()) ?>">
            <input type="hidden" name="host" value="<?= e($defaults['host']) ?>">
            <input type="hidden" name="port" value="<?= e((string) $defaults['port']) ?>">
            <input type="hidden" name="name" value="<?= e($defaults['name']) ?>">
            <input type="hidden" name="user" value="<?= e($defaults['user']) ?>">
            <input type="hidden" name="pass" value="<?= e($defaults['pass']) ?>">
            <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">Ya he subido el archivo, continuar →</button>
        </form>
    </div>
<?php endif; ?>

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

<form method="post" class="pp-form pp-form--db" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <div class="pp-field-row">
        <div class="pp-field" style="flex: 2;">
            <label for="host">Host</label>
            <input type="text" id="host" name="host" value="<?= e($defaults['host']) ?>" required>
            <small>Normalmente <code>localhost</code> o <code>127.0.0.1</code></small>
        </div>
        <div class="pp-field" style="flex: 1;">
            <label for="port">Puerto</label>
            <input type="number" id="port" name="port" value="<?= e((string) $defaults['port']) ?>" min="1" max="65535" required>
        </div>
    </div>

    <div class="pp-field">
        <label for="name">Nombre de la base de datos</label>
        <input type="text" id="name" name="name" value="<?= e($defaults['name']) ?>" required>
        <small>Ya debe existir y estar vacía</small>
    </div>

    <div class="pp-field-row">
        <div class="pp-field">
            <label for="user">Usuario</label>
            <input type="text" id="user" name="user" value="<?= e($defaults['user']) ?>" required>
        </div>
        <div class="pp-field">
            <label for="pass">Contraseña</label>
            <input type="password" id="pass" name="pass" value="<?= e($defaults['pass']) ?>">
        </div>
    </div>

    <div class="pp-form__actions">
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">
            Conectar y crear tablas →
        </button>
    </div>
</form>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('database', 'Base de datos', $content);
