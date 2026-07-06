<?php

declare(strict_types=1);

/**
 * FEAT-3 C6 — Tests de la gestión de pedidos en el admin.
 *
 * Parte 1 (CLI): OrderStore::listForAdmin (filtros status/method/q),
 * countByStatus, saveAdminNotes, y la máquina de estados ADMIN_TRANSITIONS.
 * Parte 2 (HTTP): panel admin con sesión + CSRF — listado, detalle,
 * transición pending→paid (stock + email), transición inválida rechazada,
 * y notas internas.
 *
 * Restaura el estado (borra productos/pedidos de prueba) al terminar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\CartService;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\ProductStore;
use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_o(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_o('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$wasEnabled = ModuleRegistry::isEnabled($siteId, 'commerce');
ModuleRegistry::setEnabled($siteId, 'commerce', true);

// ---------------------------------------------------------------------------
// Máquina de estados
// ---------------------------------------------------------------------------
check_o('desde pending_payment → paid/cancelled', OrderStore::ADMIN_TRANSITIONS['pending_payment'] === ['paid', 'cancelled']);
check_o('desde paid → shipped/cancelled', OrderStore::ADMIN_TRANSITIONS['paid'] === ['shipped', 'cancelled']);
check_o('desde cancelled → sin salidas', OrderStore::ADMIN_TRANSITIONS['cancelled'] === []);

// ---------------------------------------------------------------------------
// Datos de prueba: 3 pedidos con estados/métodos distintos
// ---------------------------------------------------------------------------
$pid = ProductStore::create($siteId, ['name' => 'Test C6 · Libreta', 'price_cents' => 800, 'tax_rate' => 21, 'stock' => 10, 'active' => '1']);
function mk_order(int $siteId, int $pid, string $method, string $email, string $name): array {
    CartService::clear($siteId);
    CartService::add($siteId, $pid, 1);
    $r = OrderStore::createFromCart($siteId, ['name' => $name, 'email' => $email], $method, null);
    CartService::clear($siteId);
    return $r['order'];
}
$oA = mk_order($siteId, $pid, 'manual', 'a-c6@example.com', 'Ana Compradora');
$oB = mk_order($siteId, $pid, 'stripe', 'b-c6@example.com', 'Bruno Cliente');
$oC = mk_order($siteId, $pid, 'manual', 'c-c6@example.com', 'Carla Test');

// ---------------------------------------------------------------------------
// listForAdmin + countByStatus
// ---------------------------------------------------------------------------
$all = OrderStore::listForAdmin($siteId);
$ids = array_column($all, 'order_number');
check_o('listForAdmin trae los 3 pedidos', count(array_intersect([$oA['order_number'], $oB['order_number'], $oC['order_number']], $ids)) === 3);
check_o('listForAdmin incluye item_count', (int) $all[0]['item_count'] >= 1);

$manual = OrderStore::listForAdmin($siteId, ['method' => 'manual']);
check_o('filtro method=manual excluye el de stripe', !in_array($oB['order_number'], array_column($manual, 'order_number'), true)
    && in_array($oA['order_number'], array_column($manual, 'order_number'), true));

$byEmail = OrderStore::listForAdmin($siteId, ['q' => 'b-c6@example']);
check_o('búsqueda por email acota a 1', count($byEmail) === 1 && $byEmail[0]['order_number'] === $oB['order_number']);

$byNum = OrderStore::listForAdmin($siteId, ['q' => $oC['order_number']]);
check_o('búsqueda por nº de pedido', count($byNum) === 1 && $byNum[0]['customer_name'] === 'Carla Test');

$byName = OrderStore::listForAdmin($siteId, ['q' => 'Bruno']);
check_o('búsqueda por nombre', count($byName) === 1 && $byName[0]['order_number'] === $oB['order_number']);

$counts = OrderStore::countByStatus($siteId);
check_o('countByStatus tiene las 4 claves', array_keys($counts) === ['pending_payment', 'paid', 'shipped', 'cancelled']);
check_o('countByStatus: al menos 3 pendientes', $counts['pending_payment'] >= 3, json_encode($counts));

$pendingFilter = OrderStore::listForAdmin($siteId, ['status' => 'paid']);
check_o('filtro status=paid excluye los pendientes', !in_array($oA['order_number'], array_column($pendingFilter, 'order_number'), true));

// ---------------------------------------------------------------------------
// saveAdminNotes
// ---------------------------------------------------------------------------
OrderStore::saveAdminNotes($siteId, (int) $oA['id'], 'Llamar antes de enviar.');
check_o('saveAdminNotes persiste', (string) OrderStore::find($siteId, (int) $oA['id'])['admin_notes'] === 'Llamar antes de enviar.');

// ---------------------------------------------------------------------------
// Parte 2 — HTTP (sesión admin + CSRF)
// ---------------------------------------------------------------------------
$port = 8792;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;
$jar = tempnam(sys_get_temp_dir(), 'c6jar');

function adm(string $method, string $url, array $post = []): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (test C6)',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$status, $body, $finalUrl];
}
function csrf_of_c6(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

// Login admin.
[$st, $html] = adm('GET', $base . '/admin/login');
adm('POST', $base . '/admin/login', ['identifier' => 'admin', 'password' => 'supersecret123', '_csrf' => csrf_of_c6($html)]);

// Listado.
[$st, $html] = adm('GET', $base . '/admin/commerce/pedidos');
check_o('HTTP listado 200 con pedidos', $st === 200 && str_contains($html, $oA['order_number']) && str_contains($html, 'Ana Compradora'), (string) $st);

// Filtro por método vía querystring.
[$st, $html] = adm('GET', $base . '/admin/commerce/pedidos?method=stripe');
check_o('HTTP filtro stripe muestra solo tarjeta', str_contains($html, $oB['order_number']) && !str_contains($html, $oA['order_number']));

// Detalle.
[$st, $html] = adm('GET', $base . '/admin/commerce/pedidos/' . (int) $oA['id']);
check_o('HTTP detalle 200 con cliente y acciones', $st === 200
    && str_contains($html, 'Ana Compradora') && str_contains($html, 'Marcar como pagado')
    && str_contains($html, 'Llamar antes de enviar'), (string) $st);
$csrf = csrf_of_c6($html);

// Transición inválida (pending → shipped) rechazada.
[$st, $html, $fin] = adm('POST', $base . '/admin/commerce/pedidos/' . (int) $oA['id'] . '/status', ['_csrf' => $csrf, 'status' => 'shipped']);
check_o('transición inválida rechazada', str_contains($html, 'no es válido') && (string) OrderStore::find($siteId, (int) $oA['id'])['status'] === 'pending_payment');

// Transición válida pending → paid: stock 10→9 + estado.
$csrf = csrf_of_c6($html);
[$st, $html] = adm('POST', $base . '/admin/commerce/pedidos/' . (int) $oA['id'] . '/status', ['_csrf' => $csrf, 'status' => 'paid']);
check_o('pending→paid marca pagado', (string) OrderStore::find($siteId, (int) $oA['id'])['status'] === 'paid');
check_o('stock descontado al pagar (10→9)', (int) ProductStore::find($siteId, $pid)['stock'] === 9);
check_o('mensaje de éxito menciona stock', str_contains($html, 'descontado el stock'));

// Ahora paid → shipped disponible.
$csrf = csrf_of_c6($html);
[$st, $html] = adm('POST', $base . '/admin/commerce/pedidos/' . (int) $oA['id'] . '/status', ['_csrf' => $csrf, 'status' => 'shipped']);
check_o('paid→shipped marca enviado', (string) OrderStore::find($siteId, (int) $oA['id'])['status'] === 'shipped');

// Notas internas por HTTP.
[$st, $html] = adm('GET', $base . '/admin/commerce/pedidos/' . (int) $oB['id']);
$csrf = csrf_of_c6($html);
adm('POST', $base . '/admin/commerce/pedidos/' . (int) $oB['id'] . '/notes', ['_csrf' => $csrf, 'admin_notes' => 'Pago con tarjeta pendiente de confirmar.']);
check_o('notas internas guardadas por HTTP', (string) OrderStore::find($siteId, (int) $oB['id'])['admin_notes'] === 'Pago con tarjeta pendiente de confirmar.');

// Pedido inexistente → redirect al listado con error.
[$st, $html, $fin] = adm('GET', $base . '/admin/commerce/pedidos/99999999');
check_o('detalle inexistente → vuelve al listado', str_ends_with($fin, '/admin/commerce/pedidos') && str_contains($html, 'no encontrado'));

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
@unlink($jar);
Database::execute("DELETE FROM commerce_orders WHERE site_id = ? AND customer_email LIKE '%-c6@example.com'", [$siteId]);
ProductStore::delete($siteId, $pid);
ModuleRegistry::setEnabled($siteId, 'commerce', $wasEnabled);
$left = (int) Database::selectOne("SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ? AND name LIKE 'Test C6%'", [$siteId])['n'];
check_o('limpieza completa', $left === 0);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
