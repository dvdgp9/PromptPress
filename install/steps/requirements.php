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
        'label'    => __('inst.req.php'),
        'critical' => true,
        'check'    => fn() => [
            version_compare(PHP_VERSION, '8.0.0', '>='),
            __('inst.req.detected', ['version' => PHP_VERSION]),
        ],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'PDO']),
        'critical' => true,
        'check'    => fn() => [extension_loaded('pdo'), extension_loaded('pdo') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'pdo_mysql']),
        'critical' => true,
        'check'    => fn() => [extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'JSON']),
        'critical' => true,
        'check'    => fn() => [extension_loaded('json'), extension_loaded('json') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'mbstring']),
        'critical' => true,
        'check'    => fn() => [extension_loaded('mbstring'), extension_loaded('mbstring') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'OpenSSL']) . ' (' . __('inst.req.openssl_why') . ')',
        'critical' => true,
        'check'    => fn() => [extension_loaded('openssl'), extension_loaded('openssl') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'cURL']) . ' (' . __('inst.req.curl_why') . ')',
        'critical' => true,
        'check'    => fn() => [extension_loaded('curl'), extension_loaded('curl') ? __('inst.req.loaded') : __('inst.req.missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'fileinfo']) . ' (' . __('inst.req.fileinfo_why') . ')',
        'critical' => false,
        'check'    => fn() => [extension_loaded('fileinfo'), extension_loaded('fileinfo') ? __('inst.req.loaded') : __('inst.req.fileinfo_missing')],
    ],
    [
        'label'    => __('inst.req.ext', ['ext' => 'zip']) . ' (DOCX)',
        'critical' => false,
        'check'    => fn() => [extension_loaded('zip'), extension_loaded('zip') ? __('inst.req.loaded') : __('inst.req.zip_missing')],
    ],
    [
        'label'    => __('inst.req.writable', ['ruta' => '/config']),
        'critical' => false,
        'check'    => fn() => [is_writable(PP_CONFIG), is_writable(PP_CONFIG) ? 'OK' : __('inst.req.config_not_writable')],
    ],
    [
        'label'    => __('inst.req.writable', ['ruta' => '/storage']),
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE), is_writable(PP_STORAGE) ? 'OK' : __('inst.req.storage_not_writable')],
    ],
    [
        'label'    => __('inst.req.writable', ['ruta' => '/storage/uploads']),
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE . '/uploads'), is_writable(PP_STORAGE . '/uploads') ? 'OK' : __('inst.req.dir_not_writable')],
    ],
    [
        'label'    => __('inst.req.writable', ['ruta' => '/storage/logs']),
        'critical' => true,
        'check'    => fn() => [is_writable(PP_STORAGE . '/logs'), is_writable(PP_STORAGE . '/logs') ? 'OK' : __('inst.req.dir_not_writable')],
    ],
    [
        'label'    => __('inst.req.composer'),
        'critical' => false,
        'check'    => fn() => [
            is_file(PP_ROOT . '/vendor/autoload.php'),
            is_file(PP_ROOT . '/vendor/autoload.php')
                ? __('inst.req.composer_ok')
                : __('inst.req.composer_missing'),
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
<h1 class="pp-step-title"><?= e(__('inst.req.title')) ?></h1>
<p class="pp-step-intro">
    <?= e(__('inst.req.intro')) ?>
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
                    <span class="pp-tag"><?= e(__('inst.req.recommended')) ?></span>
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
            ✓ <?= e(__('inst.req.all_ok')) ?>
        </div>
        <button type="submit" class="pp-btn pp-btn--primary pp-btn--lg"><?= e(__('inst.continue')) ?> →</button>
    <?php else: ?>
        <div class="pp-alert pp-alert--fail">
            ✗ <?= e(__('inst.req.missing_critical')) ?>
        </div>
        <button type="button" class="pp-btn pp-btn--lg" onclick="window.location.reload()"><?= e(__('inst.retry')) ?></button>
    <?php endif; ?>
</form>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('requirements', __('inst.req.title'), $content);
