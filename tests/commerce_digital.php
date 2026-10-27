<?php

declare(strict_types=1);

/**
 * EBOOK-PAGO — Vender un ebook: producto digital de la Tienda que entrega un
 * recurso solo a quien lo ha pagado.
 *
 * Parte 1 (CLI): normalización del producto, carrito (1 unidad, sin envío),
 * consentimiento obligatorio, snapshot del recurso en la línea, enlace firmado
 * contra el estado real del pedido (pendiente, pagado, caducado, manipulado,
 * cancelado) y que el modo `sale` nunca deja descargar gratis.
 * Parte 2 (HTTP): ficha con «Comprar», checkout sin dirección y con casilla,
 * pedido por transferencia → admin marca pagado → descarga del PDF; reenvío,
 * bloqueo de borrado, crear producto desde el recurso y avisos del editor.
 *
 * Limpia todo al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\CartService;
use App\Modules\Commerce\CommerceMailer;
use App\Modules\Commerce\DigitalDelivery;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\ProductStore;
use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceAccessService;
use App\Modules\Resources\ResourceFileService;
use App\Modules\Resources\ResourceStore;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_dg(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_dg('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$lang = LanguageService::primaryFor($siteId);
$moduleState = [];
foreach (['commerce', 'resources'] as $m) {
    $moduleState[$m] = Database::selectOne(
        'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
        [$siteId, ModuleRegistry::settingKey($m)]
    );
}
$maxEventBefore = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) AS m FROM analytics_events')['m'] ?? 0);
$emails = ['dg-cli@example.com', 'dg-http@example.com'];
$files = [];
$resourceIds = [];
$productIds = [];
$proc = null;
$jar = '';

/** Recurso con un PDF real en el almacenamiento protegido. */
function dg_resource(int $siteId, string $lang, string $title, string $mode, array &$files): int
{
    $dir = PP_ROOT . '/storage/resources/' . $siteId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = $dir . '/' . bin2hex(random_bytes(32)) . '.pdf';
    file_put_contents($file, "%PDF-1.4\n" . $title . "\n%%EOF\n");
    $files[] = $file;
    return ResourceStore::create($siteId, [
        'title' => $title, 'description' => 'Ebook de prueba EBOOK-PAGO.',
        'file_path' => 'storage/resources/' . $siteId . '/' . basename($file),
        'original_filename' => $title . '.pdf', 'file_mime' => 'application/pdf',
        'file_size' => filesize($file), 'access_mode' => $mode,
        'language' => $lang, 'status' => 'published',
    ]);
}

try {
    ModuleRegistry::setEnabled($siteId, 'commerce', true);
    ModuleRegistry::setEnabled($siteId, 'resources', true);

    // -----------------------------------------------------------------------
    // Producto: normalización
    // -----------------------------------------------------------------------
    $n = ProductStore::normalize(['name' => 'x', 'product_type' => 'digital', 'resource_id' => '7', 'stock' => '5']);
    check_dg('digital: stock siempre ilimitado y conserva recurso', $n['stock'] === null && $n['resource_id'] === 7 && $n['product_type'] === 'digital');
    $n = ProductStore::normalize(['name' => 'x', 'product_type' => 'physical', 'resource_id' => '7', 'stock' => '5']);
    check_dg('físico: ignora el recurso y conserva stock', $n['resource_id'] === null && $n['stock'] === 5);
    $n = ProductStore::normalize(['name' => 'x', 'product_type' => 'otro']);
    check_dg('tipo desconocido → físico', $n['product_type'] === 'physical');

    // -----------------------------------------------------------------------
    // Recurso a la venta + producto
    // -----------------------------------------------------------------------
    $rid = dg_resource($siteId, $lang, 'Ebook EBOOK-PAGO de venta', 'sale', $files);
    $resourceIds[] = $rid;
    $resource = ResourceStore::find($siteId, $rid);
    check_dg('recurso acepta modo sale', ($resource['access_mode'] ?? '') === 'sale');
    check_dg('modo sale nunca sirve la descarga directa',
        ResourceFileService::prepareDirectDownload($siteId, $lang, (string) $resource['slug']) === null);

    $pid = ProductStore::create($siteId, [
        'name' => 'Ebook EBOOK-PAGO', 'price_cents' => 1990, 'tax_rate' => 4, 'active' => '1',
        'product_type' => 'digital', 'resource_id' => $rid,
    ]);
    $productIds[] = $pid;
    $product = ProductStore::find($siteId, $pid);
    check_dg('producto digital guardado con su recurso', ($product['product_type'] ?? '') === 'digital' && (int) $product['resource_id'] === $rid && $product['stock'] === null);
    check_dg('forResource encuentra el producto activo', (int) (ProductStore::forResource($siteId, $rid, true)['id'] ?? 0) === $pid);
    check_dg('el recurso es entregable', ProductStore::isDeliverable($siteId, $rid));
    check_dg('recurso inexistente no es entregable', !ProductStore::isDeliverable($siteId, 999999999));

    // -----------------------------------------------------------------------
    // Carrito y pedido (CLI)
    // -----------------------------------------------------------------------
    CartService::clear($siteId);
    CartService::add($siteId, $pid, 3);
    $t = CartService::totals($siteId);
    check_dg('digital: una sola unidad aunque pidan 3', ($t['lines'][0]['quantity'] ?? 0) === 1 && $t['total_cents'] === 1990, json_encode($t['lines']));
    check_dg('carrito solo digital: sin envío y con casilla', $t['has_digital'] && !$t['needs_shipping'] && $t['shipping_cents'] === 0);

    $customer = ['name' => 'DG CLI', 'email' => 'dg-cli@example.com'];
    $r = OrderStore::createFromCart($siteId, $customer, 'manual', null, false);
    check_dg('sin consentimiento no hay pedido', !$r['ok'] && ($r['error'] ?? '') === 'digital_consent');
    $r = OrderStore::createFromCart($siteId, $customer, 'manual', null, true);
    check_dg('con consentimiento se crea', $r['ok']);
    $order = $r['order'];
    $oid = (int) $order['id'];
    check_dg('pedido guarda cuándo se consintió', !empty($order['digital_consent_at']));
    check_dg('la línea guarda el recurso (snapshot)', (int) ($order['items'][0]['resource_id'] ?? 0) === $rid);
    CartService::clear($siteId);

    // -----------------------------------------------------------------------
    // Enlace firmado contra el estado del pedido
    // -----------------------------------------------------------------------
    $itemId = (int) $order['items'][0]['id'];
    check_dg('pendiente de pago: sin enlaces', DigitalDelivery::links($siteId, $order) === []);
    $tok = ResourceAccessService::issuePurchaseToken($siteId, $oid, $itemId, $rid, DigitalDelivery::LINK_TTL);
    check_dg('pendiente de pago: un token firmado tampoco vale', DigitalDelivery::authorize($siteId, $tok)['status'] === 'invalid');

    OrderStore::transition($siteId, $oid, 'paid');
    $order = OrderStore::find($siteId, $oid);
    $links = DigitalDelivery::links($siteId, $order);
    check_dg('pagado: un enlace por línea digital', count($links) === 1 && str_contains($links[0]['url'], '/tienda/descargar?token='));
    parse_str((string) parse_url($links[0]['url'], PHP_URL_QUERY), $q);
    $token = (string) ($q['token'] ?? '');
    $auth = DigitalDelivery::authorize($siteId, $token);
    check_dg('pagado: el enlace autoriza ese recurso', $auth['status'] === 'ok' && $auth['resource_id'] === $rid);
    check_dg('token manipulado → inválido', DigitalDelivery::authorize($siteId, $token . 'x')['status'] === 'invalid');
    check_dg('token de otro sitio → inválido', DigitalDelivery::authorize($siteId + 1000, $token)['status'] === 'invalid');
    check_dg('a los 8 días → caducado',
        DigitalDelivery::authorize($siteId, $token, time() + 8 * 86400)['status'] === 'expired');
    $forged = ResourceAccessService::issuePurchaseToken($siteId, $oid, $itemId, $rid + 1, DigitalDelivery::LINK_TTL);
    check_dg('token de otro recurso en la misma línea → inválido', DigitalDelivery::authorize($siteId, $forged)['status'] === 'invalid');
    check_dg('el formulario no acepta un token de compra',
        ResourceAccessService::validateDownloadToken($token, $siteId, $rid) === null);

    $block = CommerceMailer::downloadsBlock($siteId, $order, $lang);
    check_dg('email de pagado lleva el enlace y la caducidad', str_contains($block, '/tienda/descargar?token=') && str_contains($block, '7'), $block);

    // Borrar el producto no quita la descarga a quien pagó.
    ProductStore::delete($siteId, $pid);
    $productIds = [];
    check_dg('borrar el producto no quita la descarga', DigitalDelivery::authorize($siteId, $token)['status'] === 'ok');

    OrderStore::transition($siteId, $oid, 'cancelled');
    $order = OrderStore::find($siteId, $oid);
    check_dg('cancelado: el enlace ya enviado deja de valer', DigitalDelivery::authorize($siteId, $token)['status'] === 'invalid');
    check_dg('cancelado: sin bloque de descargas en el email', CommerceMailer::downloadsBlock($siteId, $order, $lang) === '');

    // -----------------------------------------------------------------------
    // Parte 2 — HTTP real
    // -----------------------------------------------------------------------
    $pid = ProductStore::create($siteId, [
        'name' => 'Ebook EBOOK-PAGO HTTP', 'price_cents' => 1990, 'tax_rate' => 4, 'active' => '1',
        'product_type' => 'digital', 'resource_id' => $rid,
    ]);
    $productIds[] = $pid;
    $freeRid = dg_resource($siteId, $lang, 'Ebook EBOOK-PAGO gratis', 'direct', $files);
    $resourceIds[] = $freeRid;

    $port = 8841;
    $proc = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', PP_ROOT, PP_ROOT . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        PP_ROOT
    );
    usleep(500000);
    $base = 'http://127.0.0.1:' . $port;
    $jar = (string) tempnam(sys_get_temp_dir(), 'dgjar');

    $http = static function (string $method, string $url, array $post = [], bool $follow = true) use (&$jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_HEADER         => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (test EBOOK-PAGO)',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $out = [
            (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            $body,
            (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        ];
        curl_close($ch);
        return $out;
    };
    $csrf = static fn (string $html): string => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    $prefix = LanguageService::prefixFor($siteId, $lang);
    $resPath = $base . '/' . ($prefix !== '' ? $prefix . '/' : '') . 'recursos/' . rawurlencode((string) $resource['slug']);

    [$st, $html] = $http('GET', $resPath);
    check_dg('ficha a la venta: «Comprar» con precio', $st === 200 && str_contains($html, '19,90') && str_contains($html, 'name="product_id" value="' . $pid . '"'), (string) $st);
    check_dg('ficha a la venta: sin enlace de descarga', !str_contains($html, '/descargar'));
    [$st] = $http('GET', $resPath . '/descargar');
    check_dg('descarga directa de un recurso a la venta → 404', $st === 404, (string) $st);

    [$st, $html, $url] = $http('POST', $base . '/tienda/carrito', ['_csrf' => $csrf($html), 'product_id' => $pid, 'quantity' => 1]);
    check_dg('«Comprar» lo mete en el carrito', str_ends_with($url, '/tienda/carrito') && str_contains($html, 'Ebook EBOOK-PAGO HTTP'), $url);
    [$st, $html] = $http('GET', $base . '/tienda/checkout');
    check_dg('checkout digital: casilla de entrega inmediata', str_contains($html, 'name="digital_consent"'));
    check_dg('checkout solo digital: no pide dirección', !str_contains($html, 'name="address"'));

    $agedTs = \App\Services\Security\BotGuard::issueTimestamp(time() - 60);
    $countOrders = static fn (): int => (int) Database::selectOne('SELECT COUNT(*) AS n FROM commerce_orders WHERE site_id = ?', [$siteId])['n'];
    $before = $countOrders();
    [$st, $html] = $http('POST', $base . '/tienda/checkout', [
        '_csrf' => $csrf($html), 'name' => 'DG HTTP', 'email' => 'dg-http@example.com',
        'payment_method' => 'manual', 'company_url' => '', '_pp_ts' => $agedTs,
    ]);
    check_dg('sin casilla: error y ningún pedido', $before === $countOrders() && str_contains($html, 'entrega inmediata'));
    [$st, $html, $thanksUrl] = $http('POST', $base . '/tienda/checkout', [
        '_csrf' => $csrf($html), 'name' => 'DG HTTP', 'email' => 'dg-http@example.com',
        'payment_method' => 'manual', 'company_url' => '', '_pp_ts' => $agedTs, 'digital_consent' => '1',
    ]);
    check_dg('con casilla: pedido y aviso de que el enlace llegará al pagar',
        str_contains($thanksUrl, '/tienda/gracias/') && str_contains($html, 'enlace de descarga'), $thanksUrl);
    $httpOrder = Database::selectOne("SELECT id FROM commerce_orders WHERE site_id = ? AND customer_email = 'dg-http@example.com' ORDER BY id DESC LIMIT 1", [$siteId]);
    $httpOid = (int) ($httpOrder['id'] ?? 0);

    // Admin: marcar pagado (transferencia recibida).
    [$st, $html] = $http('GET', $base . '/admin/login');
    $http('POST', $base . '/admin/login', ['identifier' => 'admin', 'password' => 'supersecret123', '_csrf' => $csrf($html)]);
    [$st, $html] = $http('GET', $base . '/admin/commerce/pedidos/' . $httpOid);
    check_dg('admin: el pedido marca la línea como descarga', $st === 200 && str_contains($html, 'pp-order-digital-tag') && str_contains($html, 'pérdida del desistimiento'));
    $http('POST', $base . '/admin/commerce/pedidos/' . $httpOid . '/status', ['_csrf' => $csrf($html), 'status' => 'paid']);

    [$st, $html] = $http('GET', $thanksUrl);
    check_dg('gracias tras pagar: «Tus descargas» con enlace', str_contains($html, 'Tus descargas') && str_contains($html, '/tienda/descargar?token='));
    preg_match('#href="([^"]*/tienda/descargar\?token=[^"]+)"#', $html, $m);
    $downloadUrl = html_entity_decode($m[1] ?? '');
    [$st, $body, , $type] = $http('GET', $downloadUrl);
    check_dg('el enlace descarga el PDF', $st === 200 && str_starts_with($type, 'application/pdf') && str_contains($body, 'Ebook EBOOK-PAGO de venta'), $st . ' ' . $type);
    [$st, $html] = $http('GET', $base . '/tienda/descargar?token=basura.basura');
    check_dg('token basura → 404', $st === 404);
    $expired = ResourceAccessService::issuePurchaseToken(
        $siteId, $httpOid, (int) Database::selectOne('SELECT id FROM commerce_order_items WHERE order_id = ?', [$httpOid])['id'],
        $rid, DigitalDelivery::LINK_TTL, time() - 8 * 86400
    );
    [$st, $html] = $http('GET', $base . '/tienda/descargar?token=' . rawurlencode($expired));
    check_dg('enlace caducado → página que lo explica (410)', $st === 410 && str_contains($html, 'caducado'), (string) $st);

    // Reenvío desde el pedido (con o sin email configurado, nunca rompe).
    [$st, $html] = $http('GET', $base . '/admin/commerce/pedidos/' . $httpOid);
    check_dg('admin: botón de reenviar enlaces', str_contains($html, '/downloads'));
    [$st, $html] = $http('POST', $base . '/admin/commerce/pedidos/' . $httpOid . '/downloads', ['_csrf' => $csrf($html)]);
    check_dg('admin: reenviar vuelve al pedido con un aviso',
        $st === 200 && (str_contains($html, 'reenviados') || str_contains($html, 'email configurado') || str_contains($html, 'no ha salido')));

    // Borrar un recurso con compras pagadas se bloquea.
    [$st, $html] = $http('GET', $base . '/admin/resources/' . $rid);
    check_dg('admin recurso: opción «Vender en la tienda» elegida', str_contains($html, 'value="sale" checked'));
    $http('POST', $base . '/admin/resources/' . $rid . '/delete', ['_csrf' => $csrf($html)]);
    check_dg('no se puede borrar un recurso ya vendido', ResourceStore::find($siteId, $rid) !== null);

    // Crear el producto desde el recurso (gratis aún) y aviso en el producto.
    [$st, $html] = $http('GET', $base . '/admin/resources/' . $freeRid);
    $c = $csrf($html);
    [$st, $json] = $http('POST', $base . '/admin/resources/' . $freeRid . '/product', ['_csrf' => $c]);
    $data = json_decode($json, true);
    $newPid = (int) ($data['product']['id'] ?? 0);
    if ($newPid > 0) $productIds[] = $newPid;
    $created = ProductStore::find($siteId, $newPid);
    check_dg('crear producto desde el recurso: digital, en borrador, enlazado',
        ($data['ok'] ?? false) === true && $created !== null && $created['product_type'] === 'digital'
        && (int) $created['resource_id'] === $freeRid && (int) $created['active'] === 0, $json);
    [$st, $json2] = $http('POST', $base . '/admin/resources/' . $freeRid . '/product', ['_csrf' => $c]);
    check_dg('crearlo otra vez devuelve el mismo', (int) (json_decode($json2, true)['product']['id'] ?? 0) === $newPid);
    [$st, $html] = $http('GET', $base . '/admin/commerce/products/' . $newPid);
    check_dg('editor de producto avisa de que el recurso sigue gratis', str_contains($html, 'se sigue descargando gratis'));
    [$st, $html] = $http('POST', $base . '/admin/commerce/products/' . $newPid, [
        '_csrf' => $csrf($html), 'name' => 'Sin archivo', 'price' => '9,90', 'tax_rate' => '4.00',
        'active' => '1', 'product_type' => 'digital', 'resource_id' => '',
    ]);
    check_dg('digital a la venta sin archivo → error', str_contains($html, 'Elige el archivo'));

    // Cancelar el pedido corta el enlace ya descargado.
    [$st, $html] = $http('GET', $base . '/admin/commerce/pedidos/' . $httpOid);
    $http('POST', $base . '/admin/commerce/pedidos/' . $httpOid . '/status', ['_csrf' => $csrf($html), 'status' => 'cancelled']);
    [$st] = $http('GET', $downloadUrl);
    check_dg('pedido cancelado → el enlace da 404', $st === 404, (string) $st);
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    if ($jar !== '' && is_file($jar)) unlink($jar);
    CartService::clear($siteId);
    $in = implode(',', array_fill(0, count($emails), '?'));
    Database::execute("DELETE FROM commerce_orders WHERE site_id = ? AND customer_email IN ($in)", array_merge([$siteId], $emails));
    foreach ($productIds as $id) ProductStore::delete($siteId, $id);
    Database::execute("DELETE FROM commerce_products WHERE site_id = ? AND name LIKE 'Ebook EBOOK-PAGO%'", [$siteId]);
    foreach ($resourceIds as $id) ResourceStore::delete($siteId, $id);
    foreach ($files as $f) if (is_file($f)) unlink($f);
    Database::execute(
        "DELETE FROM analytics_events WHERE site_id = ? AND event_type IN ('purchase','resource_download') AND id > ?",
        [$siteId, $maxEventBefore]
    );
    foreach ($moduleState as $m => $row) {
        if ($row === null) {
            Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, ModuleRegistry::settingKey($m)]);
        } else {
            ModuleRegistry::setEnabled($siteId, $m, (string) $row['setting_value'] === '1');
        }
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
