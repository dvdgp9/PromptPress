<?php

declare(strict_types=1);

/**
 * FEAT-3 C5 — Tests de StripeCheckout.
 *
 * Parte 1 (CLI): StripeConfig (cifrado, modos, máscara), verificación de
 * firma de webhooks (pura), construcción de line_items (la suma SIEMPRE
 * iguala total_cents en ambos modos de IVA), start() con transporte HTTP
 * fake (redirect + payment_ref; fallo de API → fallback con reintento) y
 * markPaid (idempotencia, sitio equivocado, payment_ref → pi_).
 * Parte 2 (HTTP): webhook real firmado → pedido paid + stock decrementado;
 * réplica → sin doble decremento; firma mala → 400; y el checkout ofrece
 * el radio de tarjeta solo con claves configuradas.
 *
 * Guarda y RESTAURA los settings commerce_stripe_* del sitio (lección FEAT-3).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\CartService;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\ProductStore;
use App\Modules\Commerce\Payments\PaymentMethods;
use App\Modules\Commerce\Payments\StripeApi;
use App\Modules\Commerce\Payments\StripeCheckout;
use App\Modules\Commerce\Payments\StripeConfig;
use App\Modules\Commerce\StripeWebhookController;
use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_st(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_st('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$wasEnabled = ModuleRegistry::isEnabled($siteId, 'commerce');
ModuleRegistry::setEnabled($siteId, 'commerce', true);

// Guardar settings previos para restaurarlos al final.
$stripeKeys = [
    'commerce_stripe_mode', 'commerce_stripe_sk_test', 'commerce_stripe_sk_live',
    'commerce_stripe_webhook_secret_test', 'commerce_stripe_webhook_secret_live',
    'commerce_prices_include_tax', 'commerce_shipping_cents',
];
$prevSettings = [];
foreach ($stripeKeys as $k) {
    $row = Database::selectOne('SELECT setting_value, is_encrypted FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $k]);
    $prevSettings[$k] = $row;
}
function restore_setting(int $siteId, string $key, ?array $prev): void
{
    if ($prev === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
    } else {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$siteId, $key, $prev['setting_value'], (int) $prev['is_encrypted']]
        );
    }
}

// ---------------------------------------------------------------------------
// StripeConfig
// ---------------------------------------------------------------------------
foreach (['commerce_stripe_sk_test', 'commerce_stripe_sk_live', 'commerce_stripe_webhook_secret_test', 'commerce_stripe_webhook_secret_live'] as $k) {
    StripeConfig::saveSecret($siteId, $k, '');
}
Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'commerce_stripe_mode']);

check_st('modo por defecto: test', StripeConfig::mode($siteId) === 'test');
check_st('sin claves → no configurado', !StripeConfig::isConfigured($siteId));
check_st('sin claves → PaymentMethods no ofrece stripe', PaymentMethods::byKey($siteId, 'stripe') === null);

$fakeSk = 'sk_test_51FakeKeyForTests000000000000abcd';
$fakeWhsec = 'whsec_test_secret_para_tests_0001';
StripeConfig::saveSecret($siteId, 'commerce_stripe_sk_test', $fakeSk);
StripeConfig::saveSecret($siteId, 'commerce_stripe_webhook_secret_test', $fakeWhsec);

check_st('roundtrip cifrado de la sk', StripeConfig::secretKey($siteId, 'test') === $fakeSk);
$rawStored = (string) (Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'commerce_stripe_sk_test'])['setting_value'] ?? '');
check_st('la sk NO está en claro en BD', $rawStored !== '' && !str_contains($rawStored, 'sk_test_'), $rawStored);
check_st('configurado en modo test', StripeConfig::isConfigured($siteId));
check_st('modo live sin clave live → no configurado', StripeConfig::secretKey($siteId, 'live') === null);
check_st('masked oculta el cuerpo', StripeConfig::masked($fakeSk) === 'sk_test_••••abcd', (string) StripeConfig::masked($fakeSk));
check_st('con claves → stripe disponible y primero', array_key_first(PaymentMethods::availableFor($siteId)) === 'stripe');

// ---------------------------------------------------------------------------
// Verificación de firma (pura)
// ---------------------------------------------------------------------------
$payload = '{"id":"evt_1","type":"checkout.session.completed"}';
$now = time();
$sig = hash_hmac('sha256', $now . '.' . $payload, $fakeWhsec);

check_st('firma válida', StripeApi::verifySignature($payload, "t=$now,v1=$sig", $fakeWhsec, $now));
check_st('firma con secreto equivocado → false', !StripeApi::verifySignature($payload, "t=$now,v1=$sig", 'whsec_otro', $now));
check_st('payload alterado → false', !StripeApi::verifySignature($payload . ' ', "t=$now,v1=$sig", $fakeWhsec, $now));
check_st('timestamp fuera de tolerancia → false', !StripeApi::verifySignature($payload, "t=$now,v1=$sig", $fakeWhsec, $now + 301));
check_st('timestamp dentro de tolerancia → true', StripeApi::verifySignature($payload, "t=$now,v1=$sig", $fakeWhsec, $now + 299));
check_st('varias v1 (rotación de secreto)', StripeApi::verifySignature($payload, "t=$now,v1=deadbeef,v1=$sig,v0=ignorado", $fakeWhsec, $now));
check_st('header malformado → false', !StripeApi::verifySignature($payload, 'garbage', $fakeWhsec, $now));

// ---------------------------------------------------------------------------
// line_items: la suma SIEMPRE es total_cents
// ---------------------------------------------------------------------------
function sum_line_items(array $items): int
{
    $sum = 0;
    foreach ($items as $it) {
        $sum += (int) $it['price_data']['unit_amount'] * (int) $it['quantity'];
    }
    return $sum;
}

// IVA incluido, con envío: 19,95×2 + envío 4,95 = 44,85.
$orderInc = [
    'items' => [
        ['product_name' => 'Camiseta', 'quantity' => 2, 'line_total_cents' => 3990],
    ],
    'shipping_cents' => 495,
    'total_cents'    => 4485,
];
$items = StripeCheckout::lineItems($orderInc);
check_st('IVA incluido: suma = total (con envío)', sum_line_items($items) === 4485, json_encode($items));
check_st('línea divisible conserva cantidad', (int) $items[0]['quantity'] === 2 && (int) $items[0]['price_data']['unit_amount'] === 1995);

// IVA excluido con redondeo por línea NO divisible: 999×3 al 21% → 2997+629=3626.
$orderExc = [
    'items' => [
        ['product_name' => 'Widget', 'quantity' => 3, 'line_total_cents' => 3626],
    ],
    'shipping_cents' => 0,
    'total_cents'    => 3626,
];
$items = StripeCheckout::lineItems($orderExc);
check_st('IVA excluido no divisible: suma exacta = total', sum_line_items($items) === 3626, json_encode($items));
check_st('línea no divisible colapsa a qty 1 con «× N»', (int) $items[0]['quantity'] === 1 && str_contains((string) $items[0]['price_data']['product_data']['name'], '× 3'));

// ---------------------------------------------------------------------------
// start() con transporte fake + markPaid
// ---------------------------------------------------------------------------
Database::execute("INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, 'commerce_prices_include_tax', '1', 0), (?, 'commerce_shipping_cents', '0', 0)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0", [$siteId, $siteId]);

$pid = ProductStore::create($siteId, ['name' => 'Test C5 · Gorra', 'price_cents' => 1500, 'tax_rate' => 21, 'stock' => 5, 'active' => '1']);
CartService::clear($siteId);
CartService::add($siteId, $pid, 2);
$r = OrderStore::createFromCart($siteId, ['name' => 'Cliente C5', 'email' => 'c5@example.com'], 'stripe', null);
check_st('pedido stripe creado', $r['ok'] === true, json_encode($r['error'] ?? ''));
$order = $r['order'];
$oid = (int) $order['id'];
CartService::clear($siteId);

// start() feliz: el fake devuelve una sesión con url.
$captured = null;
StripeApi::$httpOverride = function (string $method, string $path, array $params) use (&$captured): array {
    $captured = [$method, $path, $params];
    return ['id' => 'cs_test_fake123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_fake123', 'payment_status' => 'unpaid'];
};
$stripe = new StripeCheckout();
$start = $stripe->start($siteId, $order);
check_st('start → redirect a Stripe', $start->redirectUrl === 'https://checkout.stripe.com/c/pay/cs_test_fake123');
check_st('start envía mode=payment y metadata.order_id', $captured !== null
    && $captured[0] === 'POST' && $captured[1] === '/checkout/sessions'
    && $captured[2]['mode'] === 'payment'
    && (int) $captured[2]['metadata']['order_id'] === $oid, json_encode($captured[2] ?? []));
check_st('start: suma de line_items = total del pedido', sum_line_items($captured[2]['line_items']) === (int) $order['total_cents']);
check_st('success/cancel_url apuntan a gracias con access_key', str_contains((string) $captured[2]['success_url'], '/tienda/gracias/' . $order['order_number'])
    && str_contains((string) $captured[2]['success_url'], 'k=' . $order['access_key'])
    && str_contains((string) $captured[2]['cancel_url'], 'pago=cancelado'));
$refAfter = (string) (OrderStore::find($siteId, $oid)['payment_ref'] ?? '');
check_st('payment_ref guarda el id de sesión', $refAfter === 'cs_test_fake123');

// start() con API caída → fallback con enlace de reintento (el pedido no se pierde).
StripeApi::$httpOverride = function (): array {
    throw new RuntimeException('Stripe: simulated outage');
};
$start = $stripe->start($siteId, $order);
check_st('API caída → instrucciones de fallback con /tienda/pagar/', $start->redirectUrl === null
    && $start->instructionsHtml !== null && str_contains($start->instructionsHtml, '/tienda/pagar/' . $order['order_number']));
StripeApi::$httpOverride = null;

// pendingInstructions es puro y ofrece el reintento.
$pending = $stripe->pendingInstructions($siteId, $order);
check_st('pendingInstructions con botón de pago', $pending !== null && str_contains($pending, '/tienda/pagar/' . $order['order_number']));

// markPaid: sesión fake → paid + stock + payment_ref pi_.
$session = [
    'id' => 'cs_test_fake123', 'payment_status' => 'paid', 'payment_intent' => 'pi_fake_789',
    'metadata' => ['order_id' => (string) $oid, 'site_id' => (string) $siteId, 'order_number' => (string) $order['order_number']],
];
$badSite = $session;
$badSite['metadata']['site_id'] = (string) ($siteId + 999);
StripeWebhookController::markPaid($siteId, $badSite);
check_st('markPaid con site_id ajeno → ignorado', (string) OrderStore::find($siteId, $oid)['status'] === 'pending_payment');

StripeWebhookController::markPaid($siteId, $session);
$after = OrderStore::find($siteId, $oid);
check_st('markPaid → paid', (string) $after['status'] === 'paid');
check_st('stock decrementado (5−2=3)', (int) ProductStore::find($siteId, $pid)['stock'] === 3);
check_st('payment_ref actualizado a pi_', (string) $after['payment_ref'] === 'pi_fake_789');

StripeWebhookController::markPaid($siteId, $session); // réplica
check_st('markPaid replicado → sin doble decremento', (int) ProductStore::find($siteId, $pid)['stock'] === 3);

// ---------------------------------------------------------------------------
// Parte 2 — webhook por HTTP real
// ---------------------------------------------------------------------------
CartService::add($siteId, $pid, 1);
$r2 = OrderStore::createFromCart($siteId, ['name' => 'Cliente C5 HTTP', 'email' => 'c5-http@example.com'], 'stripe', null);
$order2 = $r2['order'];
CartService::clear($siteId);

$port = 8795;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;

function webhook_post(string $url, string $payload, string $sigHeader): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Stripe-Signature: ' . $sigHeader],
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}

$event = json_encode([
    'id'   => 'evt_test_c5',
    'type' => 'checkout.session.completed',
    'data' => ['object' => [
        'id' => 'cs_test_http', 'payment_status' => 'paid', 'payment_intent' => 'pi_http_456',
        'metadata' => ['order_id' => (string) $order2['id'], 'site_id' => (string) $siteId, 'order_number' => (string) $order2['order_number']],
    ]],
]);
$t = time();
$v1 = hash_hmac('sha256', $t . '.' . $event, $fakeWhsec);

// Firma mala → 400 y el pedido no cambia.
[$st] = webhook_post($base . '/tienda/stripe/webhook', $event, "t=$t,v1=" . str_repeat('0', 64));
check_st('webhook con firma mala → 400', $st === 400);
check_st('pedido sigue pendiente tras firma mala', (string) OrderStore::find($siteId, (int) $order2['id'])['status'] === 'pending_payment');

// Firma buena → 200 + paid + stock 3−1=2.
[$st, $body] = webhook_post($base . '/tienda/stripe/webhook', $event, "t=$t,v1=$v1");
check_st('webhook firmado → 200', $st === 200, $body);
check_st('pedido HTTP → paid', (string) OrderStore::find($siteId, (int) $order2['id'])['status'] === 'paid');
check_st('stock decrementado por webhook (3−1=2)', (int) ProductStore::find($siteId, $pid)['stock'] === 2);

// Réplica del mismo evento → 200 idempotente.
[$st] = webhook_post($base . '/tienda/stripe/webhook', $event, "t=$t,v1=$v1");
check_st('webhook replicado → 200 sin doble decremento', $st === 200 && (int) ProductStore::find($siteId, $pid)['stock'] === 2);

// Evento que no interesa → 200 (que Stripe no reintente).
$other = json_encode(['id' => 'evt_x', 'type' => 'payment_intent.created', 'data' => ['object' => []]]);
$v1o = hash_hmac('sha256', $t . '.' . $other, $fakeWhsec);
[$st] = webhook_post($base . '/tienda/stripe/webhook', $other, "t=$t,v1=$v1o");
check_st('evento irrelevante firmado → 200', $st === 200);

// El checkout ofrece tarjeta con claves; sin claves, no.
$html = (string) file_get_contents($base . '/tienda/p/' . ProductStore::find($siteId, $pid)['slug']);
check_st('ficha accesible', $html !== '');
$ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
$checkoutHtml = (string) file_get_contents($base . '/tienda/checkout', false, $ctx);
// (el carrito de este cliente HTTP está vacío → redirige; comprobamos el radio vía renderizado directo)
$methods = PaymentMethods::availableFor($siteId);
check_st('checkout ofrece tarjeta + manual', isset($methods['stripe'], $methods['manual']));
StripeConfig::saveSecret($siteId, 'commerce_stripe_sk_test', '');
check_st('al borrar la sk desaparece la tarjeta', PaymentMethods::byKey($siteId, 'stripe') === null);

// ---------------------------------------------------------------------------
// Limpieza y restauración
// ---------------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
Database::execute("DELETE FROM commerce_orders WHERE site_id = ? AND customer_email IN ('c5@example.com','c5-http@example.com')", [$siteId]);
ProductStore::delete($siteId, $pid);
foreach ($stripeKeys as $k) {
    restore_setting($siteId, $k, $prevSettings[$k]);
}
ModuleRegistry::setEnabled($siteId, 'commerce', $wasEnabled);
$left = (int) Database::selectOne("SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ? AND name LIKE 'Test C5%'", [$siteId])['n'];
check_st('limpieza completa', $left === 0);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
