<?php

declare(strict_types=1);

/**
 * FEAT-3 F0.1 — Tests del sistema de módulos (ModuleRegistry).
 *
 * Verifica activación por sitio, que un módulo no disponible nunca se considera
 * activo, y que statusFor refleja el estado. Limpia el flag de prueba al final
 * para no dejar residuos en el sitio dev.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_mod(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_mod('hay site para probar', $siteId > 0);
if ($siteId <= 0) {
    exit(1);
}

// Estado inicial: guardar para restaurar y arrancar limpio.
$settingKey = ModuleRegistry::settingKey('hello');
$original = Database::selectOne(
    'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, $settingKey]
);

// settingKey usa el prefijo esperado.
check_mod('settingKey compone module_<key>_enabled', $settingKey === 'module_hello_enabled', $settingKey);

// exists / isAvailable.
check_mod('hello existe', ModuleRegistry::exists('hello'));
check_mod('hello disponible', ModuleRegistry::isAvailable('hello'));
check_mod('inexistente no existe', !ModuleRegistry::exists('no_such_module'));
// Con la Fase C, TODO el catálogo está construido (available:true en los 4).
check_mod('todo el catálogo disponible', ModuleRegistry::isAvailable('analytics') && ModuleRegistry::isAvailable('booking') && ModuleRegistry::isAvailable('commerce'));

// Módulo inexistente/no disponible nunca cuenta como activo, aunque se fuerce el flag.
Database::execute(
    'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
    [$siteId, 'module_no_such_module_enabled', '1']
);
check_mod('módulo inexistente no se considera activo aunque el flag esté a 1', !ModuleRegistry::isEnabled($siteId, 'no_such_module'));
Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'module_no_such_module_enabled']);

// Activar / desactivar hello.
ModuleRegistry::setEnabled($siteId, 'hello', false);
check_mod('hello arranca desactivado', !ModuleRegistry::isEnabled($siteId, 'hello'));

ModuleRegistry::setEnabled($siteId, 'hello', true);
check_mod('hello activado tras setEnabled(true)', ModuleRegistry::isEnabled($siteId, 'hello'));

ModuleRegistry::setEnabled($siteId, 'hello', false);
check_mod('hello desactivado tras setEnabled(false)', !ModuleRegistry::isEnabled($siteId, 'hello'));

// statusFor refleja todos los módulos del catálogo.
$status = ModuleRegistry::statusFor($siteId);
$keys = array_column($status, 'key');
check_mod('statusFor lista los 4 módulos del catálogo', count($status) === count(ModuleRegistry::MODULES));
check_mod('statusFor incluye hello y commerce', in_array('hello', $keys, true) && in_array('commerce', $keys, true));
$helloRow = null;
foreach ($status as $row) { if ($row['key'] === 'hello') { $helloRow = $row; } }
check_mod('statusFor marca hello available=true enabled=false', $helloRow !== null && $helloRow['available'] === true && $helloRow['enabled'] === false);

// requireEnabled devuelve un callable (el guard de ruta).
check_mod('requireEnabled devuelve callable', is_callable(ModuleRegistry::requireEnabled('hello')));

// Restaurar el flag original tal cual estaba.
if ($original === null) {
    Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $settingKey]);
} else {
    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$siteId, $settingKey, (string) $original['setting_value']]
    );
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
