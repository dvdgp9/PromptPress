<?php

declare(strict_types=1);

/**
 * FEAT-3 — Test del párrafo de analítica propia en LegalPageGenerator.
 *
 * `generate()` llama a IA (coste real), así que no se invoca aquí. Se prueba
 * el método privado `formatOwnAnalytics()` vía reflexión, alternando el
 * módulo Analytics on/off para el sitio de dev y restaurando su estado al
 * terminar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Services\Compliance\LegalPageGenerator;
use Core\Database;

$failed = 0;
function check_lp(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL; }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_lp('hay site para probar', $siteId > 0);
if ($siteId <= 0) { exit(1); }

$originalEnabled = ModuleRegistry::isEnabled($siteId, 'analytics');

$call = function (int $siteId): string {
    $method = new ReflectionMethod(LegalPageGenerator::class, 'formatOwnAnalytics');
    $method->setAccessible(true);
    return (string) $method->invoke(null, $siteId);
};

ModuleRegistry::setEnabled($siteId, 'analytics', false);
$off = $call($siteId);
check_lp('módulo apagado → "No activa"', str_contains($off, 'No activa'), $off);
check_lp('módulo apagado → no menciona cookies ni hash (nada que aclarar)', !str_contains($off, 'cookies'), $off);

ModuleRegistry::setEnabled($siteId, 'analytics', true);
$on = $call($siteId);
check_lp('módulo activo → menciona que NO usa cookies', str_contains($on, 'No usa cookies'), $on);
check_lp('módulo activo → menciona que no persiste IP', str_contains($on, 'IP'), $on);
check_lp('módulo activo → menciona la retención de 90 días', str_contains($on, '90 días'), $on);
check_lp('módulo activo → NO inventa un nombre de tercero (no dice Google/Meta)', !preg_match('/google|meta|facebook/i', $on), $on);

// Restaurar estado original.
ModuleRegistry::setEnabled($siteId, 'analytics', $originalEnabled);
check_lp('estado del módulo restaurado', ModuleRegistry::isEnabled($siteId, 'analytics') === $originalEnabled);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
