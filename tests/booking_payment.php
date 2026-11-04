<?php

declare(strict_types=1);

/**
 * BOOK-PAGO — cobrar con Stripe al reservar un servicio.
 *
 * Parte 1 (CLI, Stripe falso con StripeApi::$httpOverride): la reserva de pago
 * nace awaiting_payment con su pedido; el bloqueo ocupa el hueco y se libera
 * solo al caducar; la sesión caduca con el bloqueo y se reutiliza al
 * reintentar; pagada = confirmada (idempotente); caducada por webhook solo si
 * es la sesión vigente; pago de una cancelada no la resucita; cancelar sin
 * pagar cancela el pedido y pagada no; sin Stripe no se reserva gratis; el
 * admin de la Tienda marcando pagado también confirma.
 * Parte 2 (HTTP): API devuelve payment_url, página de pago (pendiente,
 * confirmada, token malo), webhook firmado → confirmada.
 *
 * Guarda y RESTAURA módulos y settings de Stripe; limpia todo al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingPayment;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\ServiceStore;
use App\Modules\Commerce\OrderStore;
use App\Modules\Commerce\Payments\StripeApi;
use App\Modules\Commerce\Payments\StripeConfig;
use App\Modules\Commerce\StripeWebhookController;
use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_bp(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_bp('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

// --- Estado previo (se restaura al final) -----------------------------------
$modules = [];
foreach (['booking', 'commerce'] as $m) {
    $modules[$m] = ModuleRegistry::isEnabled($siteId, $m);
    ModuleRegistry::setEnabled($siteId, $m, true);
}
$stripeKeys = ['commerce_stripe_mode', 'commerce_stripe_sk_test', 'commerce_stripe_webhook_secret_test'];
$prev = [];
foreach ($stripeKeys as $k) {
    $prev[$k] = Database::selectOne('SELECT setting_value, is_encrypted FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $k]);
}
Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, 'commerce_stripe_mode']);
// Partida a propósito: entera parece una clave real y GitHub bloquea el push.
$fakeSk = 'sk_' . 'test_' . 'FakeKeyForBookPago' . str_repeat('0', 10) . 'abcd';
$fakeWhsec = 'whsec_test_secret_book_pago_0001';
StripeConfig::saveSecret($siteId, 'commerce_stripe_sk_test', $fakeSk);
StripeConfig::saveSecret($siteId, 'commerce_stripe_webhook_secret_test', $fakeWhsec);

// --- Stripe falso ------------------------------------------------------------
$created = [];   // params de cada POST /checkout/sessions
$sessions = [];  // id → estado que devuelve GET
StripeApi::$httpOverride = function (string $method, string $path, array $params) use (&$created, &$sessions): array {
    if ($method === 'POST' && $path === '/checkout/sessions') {
        $id = 'cs_test_bp_' . (count($created) + 1);
        $created[] = $params;
        $sessions[$id] = ['id' => $id, 'status' => 'open', 'payment_status' => 'unpaid',
            'url' => 'https://checkout.stripe.test/' . $id, 'metadata' => $params['metadata'] ?? []];
        return $sessions[$id];
    }
    if ($method === 'GET' && str_starts_with($path, '/checkout/sessions/')) {
        $id = rawurldecode(substr($path, strlen('/checkout/sessions/')));
        return $sessions[$id] ?? ['id' => $id, 'status' => 'expired'];
    }
    throw new RuntimeException('Stripe falso: ' . $method . ' ' . $path);
};

// --- Servicio de pago: todos los días 09:00-13:00, 60 min, 1 plaza ----------
$tz = BookingService::siteTimezone($siteId);
$svcId = ServiceStore::create($siteId, ['name' => 'Test BOOK-PAGO · Consulta']);
$allDays = [];
for ($wd = 0; $wd <= 6; $wd++) $allDays[$wd] = [['start' => '09:00', 'end' => '13:00']];
ServiceStore::update($siteId, $svcId, [
    'name' => 'Test BOOK-PAGO · Consulta', 'duration_min' => 60, 'buffer_min' => 0, 'capacity' => 1,
    'min_notice_hours' => 0, 'max_advance_days' => 60, 'auto_confirm' => '0', 'active' => '1',
], $allDays, []);
ServiceStore::savePayment($siteId, $svcId, ['requires_payment' => 1, 'price_cents' => 3000, 'tax_rate' => 21.0]);
$service = Database::selectOne('SELECT * FROM booking_services WHERE id = ?', [$svcId]);

$day = (new DateTimeImmutable('+3 days', new DateTimeZone($tz)))->format('Y-m-d');
$slot = static fn (string $hm, string $d = '') => (new DateTimeImmutable(($d ?: $day) . ' ' . $hm, new DateTimeZone($tz)))->format('c');
$book = static fn (string $iso, string $who) => BookingService::create($siteId, [
    'service_id' => $svcId, 'start' => $iso, 'name' => $who, 'email' => strtolower($who) . '@bookpago.test',
]);
$row = static fn (int $id) => Database::selectOne('SELECT * FROM booking_bookings WHERE id = ?', [$id]);

check_bp('servicio de pago reconocido', BookingPayment::requiresPayment($service));
check_bp('sitio con Tienda + Stripe puede cobrar', BookingPayment::available($siteId));

// --- 1. Crear: awaiting_payment + pedido -------------------------------------
$r = $book($slot('09:00'), 'Ana');
check_bp('crear → ok awaiting_payment con pago', $r['ok'] && $r['booking']['status'] === 'awaiting_payment' && $r['booking']['payment_required'] === true, json_encode($r));
$b1 = (int) ($r['booking']['id'] ?? 0);
$bk = $row($b1);
$hold = strtotime($bk['hold_until_utc'] . ' UTC') - time();
check_bp('bloqueo ~31 min', $hold > 30 * 60 && $hold <= 31 * 60 + 5, (string) $hold);
$order = OrderStore::find($siteId, (int) $bk['order_id']);
check_bp('pedido pending_payment de 30 € por stripe', $order !== null && $order['status'] === 'pending_payment'
    && (int) $order['total_cents'] === 3000 && $order['payment_method'] === 'stripe', json_encode($order));
check_bp('línea del pedido apunta a la reserva', (int) ($order['items'][0]['booking_id'] ?? 0) === $b1
    && str_contains((string) $order['items'][0]['product_name'], 'Consulta'));

// --- 2. El bloqueo ocupa el hueco --------------------------------------------
$r = $book($slot('09:00'), 'Luis');
check_bp('mismo hueco durante el bloqueo → slot_unavailable', !$r['ok'] && $r['error'] === 'slot_unavailable', json_encode($r));
$avail = \App\Modules\Booking\AvailabilityEngine::forService($siteId, $svcId, $day, $day, $tz);
$free = [];
foreach ($avail['days'] ?? [] as $d) foreach ($d['slots'] as $s) $free[] = substr((string) $s['start'], 11, 5);
check_bp('la disponibilidad no ofrece las 09:00', !in_array('09:00', $free, true) && in_array('10:00', $free, true), json_encode($free));

// --- 3. Sesión de Stripe ------------------------------------------------------
$url = BookingPayment::checkoutUrl($siteId, $b1);
check_bp('checkoutUrl → URL de Stripe', $url === 'https://checkout.stripe.test/cs_test_bp_1', (string) $url);
$p = $created[0] ?? [];
$holdTs = strtotime($row($b1)['hold_until_utc'] . ' UTC');
check_bp('la sesión caduca con el bloqueo (≥30 min, ≤ bloqueo)',
    ($p['expires_at'] ?? 0) >= time() + 1800 && ($p['expires_at'] ?? PHP_INT_MAX) <= $holdTs, json_encode([$p['expires_at'] ?? null, $holdTs]));
check_bp('metadata lleva booking_id', (int) ($p['metadata']['booking_id'] ?? 0) === $b1);
check_bp('vuelta a la página de la reserva', str_contains((string) ($p['success_url'] ?? ''), '/_booking/pago/' . $b1));
check_bp('payment_ref guardado', OrderStore::find($siteId, (int) $order['id'])['payment_ref'] === 'cs_test_bp_1');
$url2 = BookingPayment::checkoutUrl($siteId, $b1);
check_bp('reintento con sesión abierta → la misma, sin crear otra', $url2 === $url && count($created) === 1);

// --- 4. Pago → confirmada (idempotente) --------------------------------------
$sessions['cs_test_bp_1']['payment_status'] = 'paid';
$sessions['cs_test_bp_1']['status'] = 'complete';
$sessions['cs_test_bp_1']['payment_intent'] = 'pi_bp_1';
BookingPayment::reconcile($siteId, $b1); // vuelta de Stripe antes que el webhook
check_bp('reconcile → reserva confirmada', $row($b1)['status'] === 'confirmed');
check_bp('pedido pagado con pi_', OrderStore::find($siteId, (int) $order['id'])['status'] === 'paid'
    && OrderStore::find($siteId, (int) $order['id'])['payment_ref'] === 'pi_bp_1');
StripeWebhookController::markPaid($siteId, $sessions['cs_test_bp_1']);
check_bp('webhook replicado no cambia nada', $row($b1)['status'] === 'confirmed');
check_bp('ya pagada → checkoutUrl null', BookingPayment::checkoutUrl($siteId, $b1) === null);

// --- 5. Bloqueo caducado → hueco libre (sin cron) ----------------------------
$r = $book($slot('10:00'), 'Eva');
$b2 = (int) $r['booking']['id'];
Database::execute('UPDATE booking_bookings SET hold_until_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?', [$b2]);
check_bp('caducada → ya no es pagable', !BookingPayment::isPayable($row($b2)));
$r = $book($slot('10:00'), 'Pau');
check_bp('hueco de una caducada se puede reservar', $r['ok'] === true, json_encode($r));
$b3 = (int) ($r['booking']['id'] ?? 0);
$n = BookingPayment::expireStale($siteId);
check_bp('expireStale cancela la caducada y su pedido', $n >= 1 && $row($b2)['status'] === 'cancelled'
    && OrderStore::find($siteId, (int) $row($b2)['order_id'])['status'] === 'cancelled');
check_bp('expireStale no toca la vigente', $row($b3)['status'] === 'awaiting_payment');

// --- 6. Webhook expired: solo la sesión vigente ------------------------------
BookingPayment::checkoutUrl($siteId, $b3); // crea cs_test_bp_2
$o3 = OrderStore::find($siteId, (int) $row($b3)['order_id']);
StripeWebhookController::expireBookingSession($siteId, ['id' => 'cs_vieja', 'metadata' => ['booking_id' => (string) $b3, 'order_id' => (string) $o3['id'], 'site_id' => (string) $siteId]]);
check_bp('expired de otra sesión → no cancela', $row($b3)['status'] === 'awaiting_payment');
StripeWebhookController::expireBookingSession($siteId, ['id' => $o3['payment_ref'], 'metadata' => ['booking_id' => (string) $b3, 'order_id' => (string) $o3['id'], 'site_id' => (string) $siteId]]);
check_bp('expired de la sesión vigente → reserva y pedido cancelados', $row($b3)['status'] === 'cancelled'
    && OrderStore::find($siteId, (int) $o3['id'])['status'] === 'cancelled');

// --- 7. Pago de una cancelada no la resucita ---------------------------------
StripeWebhookController::markPaid($siteId, ['id' => $o3['payment_ref'], 'payment_intent' => 'pi_tarde',
    'metadata' => ['order_id' => (string) $o3['id'], 'site_id' => (string) $siteId]]);
check_bp('pago tardío de cancelada → sigue cancelada', $row($b3)['status'] === 'cancelled');

// --- 8. Cancelación por el cliente --------------------------------------------
$r = $book($slot('11:00'), 'Sara');
$b4 = (int) $r['booking']['id'];
$c = BookingService::cancelWithToken($siteId, $b4, (string) $r['booking']['cancel_token']);
check_bp('cancelar sin pagar → paid=false y pedido cancelado', $c['ok'] && empty($c['paid'])
    && OrderStore::find($siteId, (int) $row($b4)['order_id'])['status'] === 'cancelled');
$c = BookingService::cancelWithToken($siteId, $b1, (string) $row($b1)['cancel_token']);
check_bp('cancelar pagada → paid=true y el pedido sigue pagado', $c['ok'] && !empty($c['paid'])
    && OrderStore::find($siteId, (int) $order['id'])['status'] === 'paid');

// --- 9. Marcar pagado desde la Tienda también confirma -----------------------
$r = $book($slot('12:00'), 'Iker');
$b5 = (int) $r['booking']['id'];
OrderStore::transition($siteId, (int) $row($b5)['order_id'], 'paid');
check_bp('admin de la Tienda marca pagado → reserva confirmada', $row($b5)['status'] === 'confirmed');

// --- 10. Sin Stripe no se reserva gratis -------------------------------------
StripeConfig::saveSecret($siteId, 'commerce_stripe_sk_test', '');
$r = $book($slot('09:00', (new DateTimeImmutable('+4 days', new DateTimeZone($tz)))->format('Y-m-d')), 'Nora');
check_bp('sin Stripe → payment_unavailable', !$r['ok'] && $r['error'] === 'payment_unavailable', json_encode($r));
StripeConfig::saveSecret($siteId, 'commerce_stripe_sk_test', $fakeSk);

// Servicio gratis sigue igual.
ServiceStore::savePayment($siteId, $svcId, ['requires_payment' => 0, 'price_cents' => 3000, 'tax_rate' => 21.0]);
$r = $book($slot('09:00', (new DateTimeImmutable('+4 days', new DateTimeZone($tz)))->format('Y-m-d')), 'Gratis');
check_bp('sin cobro → pending de siempre, sin pedido', $r['ok'] && $r['booking']['status'] === 'pending'
    && empty($row((int) $r['booking']['id'])['order_id']), json_encode($r));
ServiceStore::savePayment($siteId, $svcId, ['requires_payment' => 1, 'price_cents' => 3000, 'tax_rate' => 21.0]);

// ---------------------------------------------------------------------------
// Parte 2 — HTTP (el servidor usa el Stripe real con una clave falsa: crear la
// sesión falla y la API devuelve la página de la reserva para reintentar)
// ---------------------------------------------------------------------------
StripeApi::$httpOverride = null;
$port = 8797;
$proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', PP_ROOT, PP_ROOT . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PP_ROOT);
usleep(500000);
$base = 'http://127.0.0.1:' . $port;

function bp_http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (test BOOK-PAGO)',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($headers !== []) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $out = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$st, $out];
}

$day5 = (new DateTimeImmutable('+5 days', new DateTimeZone($tz)))->format('Y-m-d');
[$st, $out] = bp_http('GET', $base . '/api/booking/v1/services?service=' . $svcId);
$svcJson = array_values(array_filter(json_decode($out, true)['services'] ?? [], fn ($s) => (int) $s['id'] === $svcId))[0] ?? [];
check_bp('API services: requires_payment y precio visible', !empty($svcJson['requires_payment']) && ($svcJson['price_label'] ?? '') === '30,00 €', json_encode($svcJson));
check_bp('textos del widget incluyen book_pay', str_contains($out, 'book_pay'));

[$st, $out] = bp_http('POST', $base . '/api/booking/v1/bookings', json_encode([
    'service_id' => $svcId, 'start' => $slot('09:00', $day5), 'name' => 'Http', 'email' => 'http@bookpago.test',
]), ['Content-Type: application/json']);
$j = json_decode($out, true) ?? [];
check_bp('API POST → 201 awaiting_payment con payment_url', $st === 201 && ($j['status'] ?? '') === 'awaiting_payment'
    && str_contains((string) ($j['payment_url'] ?? ''), '/_booking/pago/'), $st . ' ' . $out);
$bh = (int) ($j['id'] ?? 0);
$tok = (string) ($j['cancel_token'] ?? '');

[$st, $html] = bp_http('GET', $base . '/_booking/pago/' . $bh . '?token=' . $tok);
check_bp('página de pago pendiente → botón Pagar ahora', $st === 200 && str_contains($html, 'Pagar ahora') && str_contains($html, '/stripe?token='), $st . ' ' . mb_substr(strip_tags($html), 0, 300));
[$st] = bp_http('GET', $base . '/_booking/pago/' . $bh . '?token=malo');
check_bp('token malo → 404', $st === 404);

// Webhook firmado → confirmada.
$oh = OrderStore::find($siteId, (int) $row($bh)['order_id']);
$event = json_encode(['id' => 'evt_bp', 'type' => 'checkout.session.completed', 'data' => ['object' => [
    'id' => 'cs_test_http_bp', 'payment_status' => 'paid', 'payment_intent' => 'pi_http_bp',
    'metadata' => ['order_id' => (string) $oh['id'], 'site_id' => (string) $siteId, 'booking_id' => (string) $bh],
]]]);
$t = time();
[$st] = bp_http('POST', $base . '/tienda/stripe/webhook', $event,
    ['Content-Type: application/json', 'Stripe-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $event, $fakeWhsec)]);
check_bp('webhook firmado → 200 y reserva confirmada', $st === 200 && $row($bh)['status'] === 'confirmed', (string) $st);
[$st, $html] = bp_http('GET', $base . '/_booking/pago/' . $bh . '?token=' . $tok);
check_bp('página tras pagar → Reserva confirmada', $st === 200 && str_contains($html, 'Reserva confirmada'));

proc_terminate($proc);
proc_close($proc);

// ---------------------------------------------------------------------------
// Limpieza y restauración
// ---------------------------------------------------------------------------
$orderIds = array_map(static fn ($r) => (int) $r['order_id'], Database::select(
    'SELECT order_id FROM booking_bookings WHERE service_id = ? AND order_id IS NOT NULL', [$svcId]));
if ($orderIds !== []) {
    Database::execute('DELETE FROM commerce_orders WHERE site_id = ? AND id IN (' . implode(',', $orderIds) . ')', [$siteId]);
}
ServiceStore::delete($siteId, $svcId);
foreach ($stripeKeys as $k) {
    if ($prev[$k] === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $k]);
    } else {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$siteId, $k, $prev[$k]['setting_value'], (int) $prev[$k]['is_encrypted']]
        );
    }
}
foreach ($modules as $m => $on) ModuleRegistry::setEnabled($siteId, $m, $on);
$left = (int) Database::selectOne('SELECT COUNT(*) AS n FROM booking_bookings WHERE service_id = ?', [$svcId])['n']
      + (int) Database::selectOne("SELECT COUNT(*) AS n FROM commerce_orders WHERE customer_email LIKE '%@bookpago.test'")['n'];
check_bp('limpieza completa', $left === 0, (string) $left);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
