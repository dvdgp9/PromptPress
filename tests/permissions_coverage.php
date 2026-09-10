<?php

declare(strict_types=1);

// EQUIPO T2 — La red de seguridad del «denegar por defecto».
//
// `Permissions` deniega toda ruta de /admin que no tenga área asignada, lo que
// protege de descuidos pero deja una trampa: una ruta nueva escrita dentro de
// seis meses se rompería EN SILENCIO para editores y redactores, y nadie se
// enteraría hasta que un cliente se queja.
//
// Este test recorre las rutas REALMENTE registradas (no el texto de
// `app/routes.php`) y falla si alguna cuelga de /admin sin área. El aviso llega
// al escribir la ruta.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\Permissions;
use Core\Router;

$failed = 0;
function covCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 2000) . PHP_EOL;
    }
}

// Registrar las rutas de verdad. Los handlers son closures o pares
// [Clase, 'método']: registrarlos no ejecuta nada ni toca la base de datos.
$router = new Router();
require PP_ROOT . '/app/routes.php';

$routes = $router->all();
covCheck('router_exposes_routes', count($routes) > 100, 'registradas: ' . count($routes));

$adminPaths = [];
foreach ($routes as $route) {
    $path = (string) $route['path'];
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $adminPaths[$path] = true;
    }
}
$adminPaths = array_keys($adminPaths);
covCheck('admin_routes_found', count($adminPaths) > 50, 'rutas admin: ' . count($adminPaths));

// 1. Toda ruta de /admin tiene área.
$unmapped = [];
foreach ($adminPaths as $path) {
    if (Permissions::capabilityForPath($path) === null) {
        $unmapped[] = $path;
    }
}
covCheck(
    'every_admin_route_has_an_area',
    $unmapped === [],
    "Estas rutas de /admin no tienen capacidad asignada en Permissions::SEGMENT_CAPABILITY.\n"
    . "Añade su primer segmento al mapa (o a OPEN_SEGMENTS si es de todos):\n  - "
    . implode("\n  - ", $unmapped)
);

// 2. Y al revés: ningún segmento del mapa sobra. Un segmento sin una sola ruta
//    suele ser una función retirada cuyo permiso se quedó ahí de adorno.
$segmentsInUse = [];
foreach ($adminPaths as $path) {
    $segment = Permissions::adminSegment($path);
    if (is_string($segment) && $segment !== '') {
        $segmentsInUse[$segment] = true;
    }
}
$reflection = new ReflectionClass(Permissions::class);
$mapped = array_keys($reflection->getConstant('SEGMENT_CAPABILITY'));
$orphans = array_values(array_diff($mapped, array_keys($segmentsInUse)));
covCheck(
    'no_orphan_segments_in_map',
    $orphans === [],
    'segmentos mapeados sin ninguna ruta: ' . implode(', ', $orphans)
);

// 3. El admin llega a todas; el redactor no llega a ninguna de configuración.
$reachableByAdmin = array_filter($adminPaths, static fn(string $p): bool => !Permissions::allows('admin', $p));
covCheck('admin_reaches_every_admin_route', $reachableByAdmin === [], implode(', ', $reachableByAdmin));

$redactorSettings = array_filter(
    $adminPaths,
    static fn(string $p): bool => Permissions::capabilityForPath($p) === 'settings'
        && Permissions::allows('redactor', $p)
);
covCheck('redactor_reaches_no_settings_route', $redactorSettings === [], implode(', ', $redactorSettings));

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
