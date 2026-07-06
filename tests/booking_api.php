<?php

declare(strict_types=1);

/**
 * FEAT-3 B4 — Tests de la API pública de reservas.
 *
 * Parte 1 (CLI): BookingService::create/cancelWithToken contra la BD dev
 * (validación, anti-doble-reserva secuencial, cancelación, rate limit).
 * Parte 2 (HTTP): levanta un `php -S` propio con PHP_CLI_SERVER_WORKERS y
 * lanza DOS POST CONCURRENTES al mismo último hueco → solo uno gana (criterio
 * de éxito de B4). También contrato JSON, validaciones y política CORS.
 *
 * Limpia todo al final (servicio de prueba en cascada + eventos analytics).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingService;
use App\Modules\Booking\ServiceStore;
use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_api(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_api('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$wasEnabled = ModuleRegistry::isEnabled($siteId, 'booking');
ModuleRegistry::setEnabled($siteId, 'booking', true);

// Preservar la configuración real de integración (el test la sobreescribe).
$savedIntegration = Database::select(
    "SELECT setting_key, setting_value, is_encrypted FROM settings
      WHERE site_id = ? AND setting_key IN ('booking_api_key','booking_allowed_origins')",
    [$siteId]
);

$tz = BookingService::siteTimezone($siteId);

// Servicio de prueba: todos los días 09:00-11:00, 60 min, capacity 1, sin antelación.
$svcId = ServiceStore::create($siteId, ['name' => 'Test B4 · API']);
$allDays = [];
for ($wd = 0; $wd <= 6; $wd++) $allDays[$wd] = [['start' => '09:00', 'end' => '11:00']];
ServiceStore::update($siteId, $svcId, [
    'name' => 'Test B4 · API', 'duration_min' => 60, 'buffer_min' => 0, 'capacity' => 1,
    'min_notice_hours' => 0, 'max_advance_days' => 60, 'auto_confirm' => '0', 'active' => '1',
], $allDays, []);

$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone($tz)))->format('Y-m-d');
$slotIso = (new DateTimeImmutable($tomorrow . ' 09:00', new DateTimeZone($tz)))->format('c');

// ---------------------------------------------------------------------------
// Parte 1 — servicio de dominio (CLI)
// ---------------------------------------------------------------------------

// Validación de campos.
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => '', 'email' => 'no-es-email']);
check_api('validación: nombre y email', !$r['ok'] && $r['error'] === 'validation' && isset($r['fields']['name'], $r['fields']['email']), json_encode($r));

// Reserva OK (pendiente por auto_confirm=0).
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '600111222', 'notes' => 'nota']);
check_api('crear reserva → ok pending', $r['ok'] && $r['booking']['status'] === 'pending', json_encode($r));
$bookingId = (int) ($r['booking']['id'] ?? 0);
$token = (string) ($r['booking']['cancel_token'] ?? '');
check_api('reserva devuelve start local ISO', str_starts_with((string) $r['booking']['start'], $tomorrow . 'T09:00:00'), (string) $r['booking']['start']);

// Mismo hueco → slot_unavailable (capacity 1).
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Luis', 'email' => 'luis@example.com']);
check_api('mismo hueco lleno → slot_unavailable', !$r['ok'] && $r['error'] === 'slot_unavailable', json_encode($r));

// Hora fuera de parrilla → slot_unavailable.
$badIso = (new DateTimeImmutable($tomorrow . ' 09:17', new DateTimeZone($tz)))->format('c');
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $badIso, 'name' => 'Luis', 'email' => 'luis@example.com']);
check_api('hora fuera de parrilla → slot_unavailable', !$r['ok'] && $r['error'] === 'slot_unavailable');

// Servicio inexistente/inactivo → not_found.
$r = BookingService::create($siteId, ['service_id' => 99999999, 'start' => $slotIso, 'name' => 'Luis', 'email' => 'luis@example.com']);
check_api('servicio inexistente → not_found', !$r['ok'] && $r['error'] === 'not_found');

// Cancelar con token equivocado → not_found; con el bueno → ok e idempotente.
check_api('cancel token malo → not_found', BookingService::cancelWithToken($siteId, $bookingId, 'x')['ok'] === false);
check_api('cancel token bueno → ok', BookingService::cancelWithToken($siteId, $bookingId, $token)['ok'] === true);
check_api('cancel idempotente', BookingService::cancelWithToken($siteId, $bookingId, $token)['ok'] === true);

// Tras cancelar, el hueco vuelve a estar libre.
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Luis', 'email' => 'luis@example.com']);
check_api('hueco libre tras cancelación', $r['ok'] === true, json_encode($r));

// Rate limit: 5 reservas recientes con la misma IP → bloqueada la sexta.
$ipHash = str_repeat('ab', 32);
for ($i = 0; $i < 5; $i++) {
    Database::execute(
        "INSERT INTO booking_bookings (site_id, service_id, starts_at_utc, ends_at_utc, status,
            customer_name, customer_email, cancel_token, ip_hash, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'cancelled', 'RL', 'rl@example.com', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        [$siteId, $svcId, '2030-01-01 0' . $i . ':00:00', '2030-01-01 0' . $i . ':30:00', bin2hex(random_bytes(16)), $ipHash]
    );
}
check_api('isRateLimited con 5 recientes', BookingService::isRateLimited($siteId, $ipHash) === true);
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Bot', 'email' => 'bot@example.com'], $ipHash);
check_api('create con IP limitada → rate_limited', !$r['ok'] && $r['error'] === 'rate_limited');

// ---------------------------------------------------------------------------
// Parte 2 — HTTP real con concurrencia
// ---------------------------------------------------------------------------

$port = 8799;
$root = PP_ROOT;
$spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    $spec,
    $pipes,
    $root,
    array_merge($_ENV, ['PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string) getenv('PATH')])
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;

function http(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (test B4)',
    ]);
    $h = $headers;
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        $h[] = 'Content-Type: application/json';
    }
    if ($h !== []) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => json_decode(substr($raw, $headerSize), true)];
}

// Contrato: listado de servicios.
$r = http('GET', $base . '/api/booking/v1/services');
$found = array_filter($r['body']['services'] ?? [], fn ($s) => (int) $s['id'] === $svcId);
check_api('GET services 200 + timezone + servicio de prueba', $r['status'] === 200 && ($r['body']['timezone'] ?? '') === $tz && $found !== [], json_encode($r));

// Disponibilidad: validación de rango y contrato de días.
$r = http('GET', $base . "/api/booking/v1/services/$svcId/availability?from=$tomorrow&to=" . substr($tomorrow, 0, 8) . '99');
check_api('availability fechas inválidas → 422', $r['status'] === 422);
$r = http('GET', $base . "/api/booking/v1/services/$svcId/availability?from=$tomorrow&to=$tomorrow");
$slots = $r['body']['days'][0]['slots'] ?? [];
check_api('availability 200: 1 hueco libre (09 ocupado de la parte 1)', $r['status'] === 200 && count($slots) === 1 && str_contains($slots[0]['start'], 'T10:00:00'), json_encode($r['body']));
$r = http('GET', $base . '/api/booking/v1/services/99999/availability?from=' . $tomorrow . '&to=' . $tomorrow);
check_api('availability servicio inexistente → 404', $r['status'] === 404);

// CORS: cross-origin sin clave → 403; same-origin (sin Origin) ya probado arriba.
$r = http('GET', $base . '/api/booking/v1/services', null, ['Origin: https://externa.example.com']);
check_api('cross-origin sin clave → 403', $r['status'] === 403);

// CORS con clave y allowlist → 200 + headers.
$appKey = (string) (\Core\App::config()['app_key'] ?? '');
$apiKey = 'testkey-' . bin2hex(random_bytes(8));
Database::execute(
    "INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, 'booking_api_key', ?, 1)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    [$siteId, \Core\Crypto::encrypt($apiKey, $appKey)]
);
Database::execute(
    "INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, 'booking_allowed_origins', 'https://externa.example.com', 0)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    [$siteId]
);
$r = http('GET', $base . '/api/booking/v1/services', null, ['Origin: https://externa.example.com', 'X-Booking-Key: ' . $apiKey]);
check_api('cross-origin con clave+allowlist → 200 + ACAO', $r['status'] === 200 && str_contains($r['headers'], 'Access-Control-Allow-Origin: https://externa.example.com'), $r['headers']);
$r = http('OPTIONS', $base . '/api/booking/v1/bookings', null, ['Origin: https://externa.example.com', 'Access-Control-Request-Method: POST']);
check_api('preflight OPTIONS → 204 + ACAO', $r['status'] === 204 && str_contains($r['headers'], 'Access-Control-Allow-Origin:'), 'status=' . $r['status'] . ' ' . $r['headers']);
$r = http('GET', $base . '/api/booking/v1/services', null, ['Origin: https://otra.example.com', 'X-Booking-Key: ' . $apiKey]);
check_api('origin fuera de allowlist → 403 aunque la clave sea buena', $r['status'] === 403);

// POST validación → 422; honeypot → 201 sin crear.
$r = http('POST', $base . '/api/booking/v1/bookings', ['service_id' => $svcId, 'start' => '', 'name' => '', 'email' => 'x']);
check_api('POST inválido → 422 con fields', $r['status'] === 422 && isset($r['body']['fields']));
$before = (int) Database::selectOne('SELECT COUNT(*) AS n FROM booking_bookings WHERE service_id = ?', [$svcId])['n'];
$r = http('POST', $base . '/api/booking/v1/bookings', ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Spam', 'email' => 'spam@example.com', 'company_url' => 'http://spam']);
$after = (int) Database::selectOne('SELECT COUNT(*) AS n FROM booking_bookings WHERE service_id = ?', [$svcId])['n'];
check_api('honeypot → 201 sin crear nada', $r['status'] === 201 && $before === $after);

// LA CARRERA: dos POST concurrentes al último hueco (mañana 10:00).
$slot10 = (new DateTimeImmutable($tomorrow . ' 10:00', new DateTimeZone($tz)))->format('c');
$mh = curl_multi_init();
$handles = [];
foreach (['carrera-a@example.com', 'carrera-b@example.com'] as $i => $mail) {
    $ch = curl_init($base . '/api/booking/v1/bookings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['service_id' => $svcId, 'start' => $slot10, 'name' => 'Corredor ' . $i, 'email' => $mail]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Forwarded-For: 10.0.0.' . ($i + 1)],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (race)',
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
} while ($running > 0);
$statuses = [];
foreach ($handles as $ch) {
    $statuses[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
sort($statuses);
check_api('CARRERA: 2 POST concurrentes → un 201 y un 409', $statuses === [201, 409], json_encode($statuses));
$winners = (int) Database::selectOne(
    "SELECT COUNT(*) AS n FROM booking_bookings WHERE service_id = ? AND status IN ('pending','confirmed') AND starts_at_utc = ?",
    [$svcId, (new DateTimeImmutable($slot10))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
)['n'];
check_api('CARRERA: exactamente 1 reserva en BD', $winners === 1, (string) $winners);

// Cancelación vía API con token.
$row = Database::selectOne("SELECT id, cancel_token FROM booking_bookings WHERE service_id = ? AND customer_email LIKE 'carrera-%' LIMIT 1", [$svcId]);
$r = http('POST', $base . '/api/booking/v1/bookings/' . $row['id'] . '/cancel', ['token' => $row['cancel_token']]);
check_api('POST cancel con token → 200 cancelled', $r['status'] === 200 && ($r['body']['status'] ?? '') === 'cancelled', json_encode($r));

// Evento de conversión en Analytics (módulo activo en dev).
if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
    $n = (int) Database::selectOne(
        "SELECT COUNT(*) AS n FROM analytics_events WHERE site_id = ? AND event_type = 'booking_created'",
        [$siteId]
    )['n'];
    check_api('conversión booking_created registrada en analytics', $n >= 1, (string) $n);
    Database::execute("DELETE FROM analytics_events WHERE site_id = ? AND event_type = 'booking_created'", [$siteId]);
}

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
ServiceStore::delete($siteId, $svcId); // cascada borra reservas
// Restaurar la configuración de integración previa (o borrarla si no existía).
Database::execute("DELETE FROM settings WHERE site_id = ? AND setting_key IN ('booking_api_key','booking_allowed_origins')", [$siteId]);
foreach ($savedIntegration as $s) {
    Database::execute(
        'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, ?)',
        [$siteId, $s['setting_key'], $s['setting_value'], (int) $s['is_encrypted']]
    );
}
ModuleRegistry::setEnabled($siteId, 'booking', $wasEnabled);
$left = (int) Database::selectOne('SELECT COUNT(*) AS n FROM booking_bookings WHERE service_id = ?', [$svcId])['n'];
check_api('limpieza completa', $left === 0);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
