<?php

declare(strict_types=1);

/**
 * FEAT-RESOURCES R3 — contrato del panel de administración.
 *
 * Comprueba el registro activable, los bloqueos de publicación que alimentan
 * la UX del editor y que las piezas de interfaz/rutas esenciales existen sin
 * dejar el módulo visible cuando está apagado. No crea binarios ni datos.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceAdminController;
use App\Services\AdminNavigation;
use Core\Database;

$failed = 0;
function check_ra(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_ra('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$settingKey = ModuleRegistry::settingKey('resources');
$original = Database::selectOne(
    'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, $settingKey]
);

check_ra('resources existe en ModuleRegistry', ModuleRegistry::exists('resources'));
check_ra('resources está disponible', ModuleRegistry::isAvailable('resources'));
check_ra('setting estable module_resources_enabled', $settingKey === 'module_resources_enabled', $settingKey);

ModuleRegistry::setEnabled($siteId, 'resources', false);
check_ra('resources apagado no está activo', !ModuleRegistry::isEnabled($siteId, 'resources'));
ModuleRegistry::setEnabled($siteId, 'resources', true);
check_ra('resources se puede activar', ModuleRegistry::isEnabled($siteId, 'resources'));

$empty = [
    'file_path' => null, 'original_filename' => null, 'file_mime' => null,
    'file_size' => null, 'access_mode' => 'direct', 'form_id' => null,
];
check_ra(
    'sin archivo explica el único bloqueo de publicación',
    ResourceAdminController::publicationIssues($empty) === ['resource.publish_issue.file']
);

$withFile = array_merge($empty, [
    'file_path' => 'storage/resources/' . $siteId . '/book.pdf',
    'original_filename' => 'guia.pdf', 'file_mime' => 'application/pdf', 'file_size' => 2048,
]);
check_ra('descarga directa con archivo se puede publicar', ResourceAdminController::publicationIssues($withFile) === []);

$formMissing = array_merge($withFile, ['access_mode' => 'form', 'form_id' => null]);
check_ra(
    'modo formulario sin formulario explica el bloqueo',
    ResourceAdminController::publicationIssues($formMissing) === ['resource.publish_issue.form']
);

$routes = (string) file_get_contents(PP_ROOT . '/app/Modules/Resources/routes.php');
check_ra('rutas admin protegidas dentro del módulo',
    str_contains($routes, "group('/admin'")
    && str_contains($routes, "'/resources'")
    && str_contains($routes, 'ResourceAdminController')
);

$index = PP_ROOT . '/views/admin/resources/index.php';
$edit = PP_ROOT . '/views/admin/resources/edit.php';
$js = PP_ROOT . '/admin/assets/js/resources-editor.js';
check_ra('existen listado y editor', is_file($index) && is_file($edit));
check_ra('editor tiene JS aislado para interacción progresiva', is_file($js));

$layout = (string) file_get_contents(PP_ROOT . '/views/admin/layout.php');
$navKeysOff = array_column(AdminNavigation::flatten(AdminNavigation::build('/admin/resources')), 'key');
$navKeysOn = array_column(AdminNavigation::flatten(AdminNavigation::build('/admin/resources', ['resources'])), 'key');
check_ra('layout resuelve módulos activos antes de construir navegación',
    str_contains($layout, 'ModuleRegistry::isEnabled')
    && str_contains($layout, 'AdminNavigation::build')
);
check_ra('navegación de Recursos depende del módulo activo',
    !in_array('resources', $navKeysOff, true)
    && in_array('resources', $navKeysOn, true)
);

if ($original === null) {
    Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $settingKey]);
} else {
    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
        [$siteId, $settingKey, (string) $original['setting_value']]
    );
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
