<?php

declare(strict_types=1);

/**
 * FEAT-3 C4 — Tests del carrito y checkout.
 *
 * Parte 1 (CLI): matemática de líneas en ambos modos de IVA, OrderStore
 * (secuencia de números, snapshot, stock, transiciones con decremento único,
 * rate limit) y visibilidad condicional del tipo legal purchase_conditions.
 * Parte 2 (HTTP): flujo real con sesión y CSRF — añadir al carrito, checkout
 * manual completo, página de gracias con access_key, y 404 con clave mala.
 *
 * Limpia todo al final (productos y pedidos de prueba).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\CartService;
use App\Modules\Commerce\CommerceSettings;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\ProductStore;
use App\Modules\ModuleRegistry;
use App\Services\Compliance\LegalPageGenerator;
use Core\Database;

$failed = 0;
function check_ck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_ck('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$wasEnabled = ModuleRegistry::isEnabled($siteId, 'commerce');
ModuleRegistry::setEnabled($siteId, 'commerce', true);

// C7: los checkouts HTTP de este test registran eventos `purchase` si el
// módulo Analytics está activo en dev → capturar el máximo id previo para
// borrar en la limpieza SOLO los eventos creados por este test.
$maxEventBefore = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) AS m FROM analytics_events')['m'] ?? 0);

// ---------------------------------------------------------------------------
// Matemática de líneas (pura)
// ---------------------------------------------------------------------------
// IVA incluido: 19,95 € ×2 al 21% → total 3990; IVA = 3990 − 3990/1,21 = 692.
[$total, $tax] = CartService::lineAmounts(1995, 21.0, 2, true);
check_ck('línea IVA incluido: total 3990, IVA 692', $total === 3990 && $tax === 692, "$total/$tax");
// IVA excluido: 1000 ×3 al 21% → neto 3000, IVA 630, total 3630.
[$total, $tax] = CartService::lineAmounts(1000, 21.0, 3, false);
check_ck('línea IVA excluido: total 3630, IVA 630', $total === 3630 && $tax === 630, "$total/$tax");
// Tipo 0%: sin IVA en ambos modos.
[$total, $tax] = CartService::lineAmounts(500, 0.0, 1, true);
check_ck('línea 0%: IVA 0', $total === 500 && $tax === 0);

// ---------------------------------------------------------------------------
// OrderStore (CLI)
// ---------------------------------------------------------------------------
$pid1 = ProductStore::create($siteId, ['name' => 'Test C4 · Taza', 'price_cents' => 1200, 'tax_rate' => 21, 'stock' => 3, 'active' => '1']);
$pid2 = ProductStore::create($siteId, ['name' => 'Test C4 · Ebook', 'price_cents' => 900, 'tax_rate' => 4, 'stock' => '', 'active' => '1']);

// El carrito vive en sesión: en CLI $_SESSION existe tras App::boot (session_start).
CartService::clear($siteId);
CartService::add($siteId, $pid1, 2);
CartService::add($siteId, $pid2, 1);
$t = CartService::totals($siteId);
check_ck('totals: 2 líneas, 3 items', count($t['lines']) === 2 && $t['item_count'] === 3, json_encode($t));
check_ck('totals: subtotal 2400+900=3300', $t['subtotal_cents'] === 3300);

// Cantidad acotada al stock.
$warn = CartService::put($siteId, $pid1, 99);
check_ck('put acota a stock y avisa', $warn !== null && CartService::items($siteId)[$pid1] === 3, (string) $warn);
CartService::put($siteId, $pid1, 2);

// Crear pedido.
$customer = ['name' => 'Cliente C4', 'email' => 'c4@example.com'];
$r = OrderStore::createFromCart($siteId, $customer, 'manual', null);
check_ck('createFromCart ok', $r['ok'] === true, json_encode($r['error'] ?? ''));
$order1 = $r['order'];
check_ck('número PC-<año>-NNNN', preg_match('/^PC-\d{4}-\d{4}$/', (string) $order1['order_number']) === 1, (string) $order1['order_number']);
check_ck('estado pending_payment', (string) $order1['status'] === 'pending_payment');
check_ck('access_key de 32 chars', strlen((string) $order1['access_key']) === 32);
check_ck('2 líneas snapshot', count($order1['items']) === 2);
check_ck('stock NO decrementado al crear', (int) ProductStore::find($siteId, $pid1)['stock'] === 3);

// Secuencia: segundo pedido → número consecutivo.
CartService::add($siteId, $pid2, 1);
$r2 = OrderStore::createFromCart($siteId, $customer, 'manual', null);
$n1 = (int) substr((string) $order1['order_number'], -4);
$n2 = (int) substr((string) $r2['order']['order_number'], -4);
check_ck('números consecutivos', $r2['ok'] && $n2 === $n1 + 1, $order1['order_number'] . ' → ' . $r2['order']['order_number']);

// Carrito vacío → error.
CartService::clear($siteId);
$r3 = OrderStore::createFromCart($siteId, $customer, 'manual', null);
check_ck('carrito vacío → empty_cart', !$r3['ok'] && $r3['error'] === 'empty_cart');

// Stock insuficiente detectado dentro del lock.
CartService::add($siteId, $pid1, 3);
Database::execute('UPDATE commerce_products SET stock = 1 WHERE id = ?', [$pid1]); // alguien compró mientras tanto
$r4 = OrderStore::createFromCart($siteId, $customer, 'manual', null);
check_ck('stock insuficiente → out_of_stock', !$r4['ok'] && $r4['error'] === 'out_of_stock', json_encode($r4));
Database::execute('UPDATE commerce_products SET stock = 3 WHERE id = ?', [$pid1]);
CartService::clear($siteId);

// Transición a paid decrementa stock UNA vez.
$oid = (int) $order1['id'];
check_ck('transition a paid', OrderStore::transition($siteId, $oid, 'paid') === true);
check_ck('stock decrementado (3−2=1)', (int) ProductStore::find($siteId, $pid1)['stock'] === 1);
check_ck('transition repetida → false', OrderStore::transition($siteId, $oid, 'paid') === false);
check_ck('stock sin doble decremento', (int) ProductStore::find($siteId, $pid1)['stock'] === 1);
check_ck('paid → shipped ok', OrderStore::transition($siteId, $oid, 'shipped') === true);
check_ck('ebook (stock NULL) sigue ilimitado', ProductStore::find($siteId, $pid2)['stock'] === null);

// findByNumberAndKey.
$found = OrderStore::findByNumberAndKey($siteId, (string) $order1['order_number'], (string) $order1['access_key']);
check_ck('findByNumberAndKey con clave buena', $found !== null && (int) $found['id'] === $oid);
check_ck('findByNumberAndKey con clave mala → null', OrderStore::findByNumberAndKey($siteId, (string) $order1['order_number'], 'x') === null);

// Rate limit.
$ipHash = str_repeat('cd', 32);
Database::execute('UPDATE commerce_orders SET ip_hash = ? WHERE id IN (?, ?)', [$ipHash, $oid, (int) $r2['order']['id']]);
for ($i = 0; $i < 3; $i++) {
    CartService::add($siteId, $pid2, 1);
    $rr = OrderStore::createFromCart($siteId, $customer, 'manual', $ipHash);
    if (!$rr['ok']) break;
}
CartService::add($siteId, $pid2, 1);
$rr = OrderStore::createFromCart($siteId, $customer, 'manual', $ipHash);
check_ck('rate limit a partir del 5º pedido', !$rr['ok'] && $rr['error'] === 'rate_limited', json_encode($rr));
CartService::clear($siteId);

// ---------------------------------------------------------------------------
// Tipo legal purchase_conditions condicional al módulo
// ---------------------------------------------------------------------------
check_ck('typesFor CON commerce incluye purchase_conditions', isset(LegalPageGenerator::typesFor($siteId)['purchase_conditions']));
ModuleRegistry::setEnabled($siteId, 'commerce', false);
check_ck('typesFor SIN commerce lo oculta', !isset(LegalPageGenerator::typesFor($siteId)['purchase_conditions']));
ModuleRegistry::setEnabled($siteId, 'commerce', true);

// ---------------------------------------------------------------------------
// Parte 2 — HTTP real (sesión + CSRF + redirects)
// ---------------------------------------------------------------------------
$port = 8796;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;
$jar = tempnam(sys_get_temp_dir(), 'ckjar');

function shop(string $method, string $url, array $post = [], bool $follow = true): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (test C4)',
    ]);
    // OJO: no usar CUSTOMREQUEST — forzaría POST también al seguir el
    // redirect (el 302 debe re-pedirse como GET, como hace el navegador).
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
function csrf_of(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

$slug = (string) ProductStore::find($siteId, $pid1)['slug'];

// Catálogo y ficha.
[$st, $html] = shop('GET', $base . '/tienda');
check_ck('HTTP catálogo 200 con producto', $st === 200 && str_contains($html, 'Test C4 · Taza'));
[$st, $html] = shop('GET', $base . '/tienda/p/' . $slug);
check_ck('HTTP ficha 200 con precio', $st === 200 && str_contains($html, '12,00'));
$csrf = csrf_of($html);

// Añadir al carrito (misma sesión vía cookie jar).
[$st, $html, $finalUrl] = shop('POST', $base . '/tienda/carrito', ['_csrf' => $csrf, 'product_id' => $pid1, 'quantity' => 1]);
check_ck('HTTP añadir → redirect a carrito con línea', str_ends_with($finalUrl, '/tienda/carrito') && str_contains($html, 'Test C4 · Taza'), $finalUrl);

// Checkout con datos y pago manual.
[$st, $html] = shop('GET', $base . '/tienda/checkout');
check_ck('HTTP checkout 200 con método manual', $st === 200 && str_contains($html, 'Transferencia'));
$csrf = csrf_of($html);
[$st, $html, $finalUrl] = shop('POST', $base . '/tienda/checkout', [
    '_csrf' => $csrf, 'name' => 'HTTP C4', 'email' => 'http-c4@example.com',
    'payment_method' => 'manual', 'company_url' => '',
]);
check_ck('HTTP pedido creado → gracias con instrucciones', $st === 200
    && str_contains($finalUrl, '/tienda/gracias/PC-') && str_contains($html, 'pendiente de pago'), $finalUrl);
preg_match('#/tienda/gracias/(PC-[\d-]+)\?k=([0-9a-f]+)#', $finalUrl, $m);
check_ck('URL de gracias con número y clave', isset($m[1], $m[2]));

// Clave mala → 404; validación de checkout sin email → error sin crear pedido.
[$st] = shop('GET', $base . '/tienda/gracias/' . ($m[1] ?? 'PC-0') . '?k=malo');
check_ck('gracias con clave mala → 404', $st === 404);
[$st, $html] = shop('GET', $base . '/tienda/p/' . $slug);
$csrf = csrf_of($html);
shop('POST', $base . '/tienda/carrito', ['_csrf' => $csrf, 'product_id' => $pid1, 'quantity' => 1]);
[$st, $html] = shop('GET', $base . '/tienda/checkout');
$before = (int) Database::selectOne('SELECT COUNT(*) AS n FROM commerce_orders WHERE site_id = ?', [$siteId])['n'];
[$st, $html] = shop('POST', $base . '/tienda/checkout', ['_csrf' => csrf_of($html), 'name' => '', 'email' => 'no-email', 'payment_method' => 'manual', 'company_url' => '']);
$after = (int) Database::selectOne('SELECT COUNT(*) AS n FROM commerce_orders WHERE site_id = ?', [$siteId])['n'];
check_ck('checkout inválido → errores sin crear pedido', str_contains($html, 'email válido') && $before === $after);

// Honeypot → redirect a tienda sin crear pedido.
[$st, $html] = shop('GET', $base . '/tienda/checkout');
[$st, , $finalUrl] = shop('POST', $base . '/tienda/checkout', ['_csrf' => csrf_of($html), 'name' => 'Bot', 'email' => 'bot@example.com', 'payment_method' => 'manual', 'company_url' => 'http://spam']);
$after2 = (int) Database::selectOne('SELECT COUNT(*) AS n FROM commerce_orders WHERE site_id = ?', [$siteId])['n'];
check_ck('honeypot → sin pedido nuevo', $after2 === $after && str_ends_with($finalUrl, '/tienda'), $finalUrl);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
@unlink($jar);
Database::execute("DELETE FROM commerce_orders WHERE site_id = ? AND customer_email IN ('c4@example.com','http-c4@example.com')", [$siteId]);
Database::execute(
    "DELETE FROM analytics_events WHERE site_id = ? AND event_type = 'purchase' AND id > ?",
    [$siteId, $maxEventBefore]
);
ProductStore::delete($siteId, $pid1);
ProductStore::delete($siteId, $pid2);
ModuleRegistry::setEnabled($siteId, 'commerce', $wasEnabled);
$left = (int) Database::selectOne("SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ? AND name LIKE 'Test C4%'", [$siteId])['n'];
check_ck('limpieza completa', $left === 0);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
