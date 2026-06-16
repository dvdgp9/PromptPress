<?php
/**
 * Paso 1: Comprobación de requisitos del servidor.
 *
 * Variables disponibles en este scope (cargado por InstallerApp::run):
 *   ninguna especial (utilizamos PP_* y funciones globales)
 */

use Core\Session;

/**
 * Lista de checks. Cada uno: ['key', 'label', 'critical', closure que devuelve [bool, string]]
 */
$checks = [
    [
        'label'    => 'Versión de PHP ≥ 8.0',
        'critical' => true,
        'check'    => fn() => [
            version_compare(PHP_VERSION, '8.0.0', '>='),
            'Detectado: PHP ' . PHP_VERSION,
        ],
    ],
    [
        'label'    => 'Extensión PDO',
        'critical' => true,
        'check'    => fn() => [extension_loaded('pdo'), extension_loaded('pdo') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión pdo_mysql',
        'critical' => true,
        'check'    => fn() => [extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión JSON',
        'critical' => true,
        'check'    => fn() => [extension_loaded('json'), extension_loaded('json') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión mbstring',
        'critical' => true,
        'check'    => fn() => [extension_loaded('mbstring'), extension_loaded('mbstring') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión OpenSSL (encriptación de API keys)',
        'critical' => true,
        'check'    => fn() => [extension_loaded('openssl'), extension_loaded('openssl') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión cURL (llamadas a IA)',
        'critical' => true,
        'check'    => fn() => [extension_loaded('curl'), extension_loaded('curl') ? 'Cargada' : 'No disponible'],
    ],
    [
        'label'    => 'Extensión fileinfo (uploads)',
        'critical' => false,
        'check'    => fn() => [extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'Cargada' : 'Recomendada para validar tipos de archivos'],
    ],
    [
        'label'    => 'Extensión zip (DOCX)',
        'critical' => false,
        'check'    => fn() => [extension_loaded('zip'), extension_loaded('zip') ? 'Cargada' : 'Necesaria solo si subirás documentos DOCX'],
    ],
    [
        'label'    => 'Permisos de escritura en /config',
        'critical' => false,
        'check'    => fn() => [is_writable(PP_CONFIG), is_writable(PP_CONFIG) ? 'OK' : 'No escribible. Puedes darle permisos desde el Gestor de archivos del hosting (clic derecho → Permisos → 775), o continuar igual: el instalador te dará el archivo listo para subir a mano.'],
    ],
    [
        'label'    => 'Permisos de escritura en /storage',
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE), is_writable(PP_STORAGE) ? 'OK' : 'No escribible. Dale permisos de escritura (775) desde el Gestor de archivos del hosting: aquí se guardan uploads, logs y caché.'],
    ],
    [
        'label'    => 'Permisos de escritura en /storage/uploads',
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE . '/uploads'), is_writable(PP_STORAGE . '/uploads') ? 'OK' : 'Carpeta no escribible'],
    ],
    [
        'label'    => 'Permisos de escritura en /storage/logs',
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE . '/logs'), is_writable(PP_STORAGE . '/logs') ? 'OK' : 'Carpeta no escribible'],
    ],
    [
        'label'    => 'Composer dependencies (vendor/autoload.php)',
        'critical' => false,
        'check'    => fn() => [
            is_file(PP_ROOT . '/vendor/autoload.php'),
            is_file(PP_ROOT . '/vendor/autoload.php')
                ? 'Detectadas — soporte completo PDF/DOCX'
                : 'No detectadas. Sin Composer solo podrás usar TXT en documentos. Ejecuta: composer install',
        ],
    ],
];

// Ejecutar todos los checks
$results = [];
$allCriticalOk = true;
foreach ($checks as $i => $c) {
    [$ok, $msg] = ($c['check'])();
    $results[] = [
        'label'    => $c['label'],
        'critical' => $c['critical'],
        'ok'       => $ok,
        'msg'      => $msg,
    ];
    if ($c['critical'] && !$ok) {
        $allCriticalOk = false;
    }
}

// Si todos los críticos están OK y se hace POST, avanzar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allCriticalOk) {
    InstallerApp::unlockNextStep('requirements');
    \Core\Response::redirect(InstallerApp::stepUrl('database'));
}

// Render
ob_start();
?>
<h1 class="pp-step-title">Comprobación de requisitos</h1>
<p class="pp-step-intro">
    Estamos verificando que tu servidor cumple los requisitos mínimos para ejecutar PromptPress.
</p>

<table class="pp-checks">
    <tbody>
    <?php foreach ($results as $r): ?>
        <tr class="pp-check pp-check--<?= $r['ok'] ? 'ok' : ($r['critical'] ? 'fail' : 'warn') ?>">
            <td class="pp-check__icon">
                <?= $r['ok'] ? '✓' : ($r['critical'] ? '✗' : '!') ?>
            </td>
            <td class="pp-check__label">
                <strong><?= e($r['label']) ?></strong>
                <?php if (!$r['critical']): ?>
                    <span class="pp-tag">Recomendado</span>
                <?php endif; ?>
                <div class="pp-check__msg"><?= e($r['msg']) ?></div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" class="pp-form">
    <?php if ($allCriticalOk): ?>
        <div class="pp-alert pp-alert--ok">
            ✓ Tu servidor cumple todos los requisitos críticos. Puedes continuar.
        </div>
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">Continuar →</button>
    <?php else: ?>
        <div class="pp-alert pp-alert--fail">
            ✗ Faltan requisitos críticos. Corrige los puntos marcados en rojo y recarga esta página.
        </div>
        <button type="button" class="pp-btn pp-btn--lg" onclick="window.location.reload()">Reintentar</button>
    <?php endif; ?>
</form>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('requirements', 'Requisitos del sistema', $content);
