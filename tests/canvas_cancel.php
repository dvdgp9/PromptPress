<?php

declare(strict_types=1);

/**
 * CANCEL — Parar una generación tiene que dejar la página EXACTAMENTE igual.
 *
 * Lo que se prueba aquí es lo único que importa: que la marca de cancelación se
 * consulte antes de escribir. Abortar el fetch en el navegador no detiene a PHP;
 * si esta comprobación desapareciera, el usuario vería "cancelado" y la página
 * habría cambiado igualmente.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasCancelToken;
use Core\Database;

$failed = 0;
function check_cancel(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);

// ---------------------------------------------------------------
// La marca en sí
// ---------------------------------------------------------------
$id = 'test' . bin2hex(random_bytes(8));

check_cancel('sin cancelar, no hay marca', !CanvasCancelToken::isCancelled($siteId, $id));
check_cancel('cancel() deja la marca', CanvasCancelToken::cancel($siteId, $id));
check_cancel('isCancelled() la detecta', CanvasCancelToken::isCancelled($siteId, $id));
check_cancel('la marca se consume (una cancelación, una ejecución)', !CanvasCancelToken::isCancelled($siteId, $id));

// La marca es por sitio: cancelar en otro sitio no debe afectar a este.
CanvasCancelToken::cancel($siteId + 999, $id);
check_cancel('la marca no cruza entre sitios', !CanvasCancelToken::isCancelled($siteId, $id));
CanvasCancelToken::forget($siteId + 999, $id);

// Ids con formato raro se rechazan (llegan del navegador).
check_cancel('rechaza id vacío', !CanvasCancelToken::isValidId(''));
check_cancel('rechaza id corto', !CanvasCancelToken::isValidId('abc'));
check_cancel('rechaza id con separadores de ruta', !CanvasCancelToken::isValidId('../../etc/passwd'));
check_cancel('acepta un id normal', CanvasCancelToken::isValidId('a1b2c3d4e5f6a7b8'));

// ---------------------------------------------------------------
// El pipeline consulta la marca ANTES de guardar
// ---------------------------------------------------------------
$src = file_get_contents(PP_ROOT . '/app/Services/Canvas/CanvasChatService.php') ?: '';
$posCheck = strpos($src, 'CanvasCancelToken::isCancelled');
$posSave  = strpos($src, 'CanvasService::save(');
check_cancel(
    'la comprobación va antes del guardado',
    $posCheck !== false && $posSave !== false && $posCheck < $posSave,
    'check=' . var_export($posCheck, true) . ' save=' . var_export($posSave, true)
);

// Y el controlador suelta la sesión antes de la llamada larga: si no, la
// petición de cancelar se quedaría esperando a la generación que quiere parar.
$ctrl = file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php') ?: '';
$posClose = strpos($ctrl, 'Session::close();');
$posApply = strpos($ctrl, 'CanvasChatService::applyInstruction');
check_cancel(
    'el chat cierra la sesión antes de llamar a la IA',
    $posClose !== false && $posApply !== false && $posClose < $posApply
);

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : $failed . ' fallos') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
