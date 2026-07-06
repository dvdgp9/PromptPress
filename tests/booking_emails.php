<?php

declare(strict_types=1);

/**
 * FEAT-3 B5 — Tests de emails y gestión de reservas.
 *
 * En dev normalmente NO hay SMTP configurado: el objetivo es verificar que
 * (a) el ICS es correcto, (b) los estados email_status se registran bien
 * ('skipped' sin SMTP; la reserva JAMÁS se pierde por el correo), y (c) el
 * flujo público de cancelación (GET página + POST) funciona con y sin token.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingMailer;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\ServiceStore;
use App\Modules\ModuleRegistry;
use Core\Database;

$failed = 0;
function check_em(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_em('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$wasEnabled = ModuleRegistry::isEnabled($siteId, 'booking');
ModuleRegistry::setEnabled($siteId, 'booking', true);
$mailConfigured = \App\Services\Mail\MailService::isConfigured($siteId);

// Servicio de prueba con hueco mañana.
$tz = BookingService::siteTimezone($siteId);
$svcId = ServiceStore::create($siteId, ['name' => 'Test B5 · Emails']);
$allDays = [];
for ($wd = 0; $wd <= 6; $wd++) $allDays[$wd] = [['start' => '09:00', 'end' => '11:00']];
ServiceStore::update($siteId, $svcId, [
    'name' => 'Test B5 · Emails', 'duration_min' => 60, 'capacity' => 2,
    'min_notice_hours' => 0, 'max_advance_days' => 60, 'auto_confirm' => '0', 'active' => '1',
], $allDays, []);
$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone($tz)))->format('Y-m-d');
$slotIso = (new DateTimeImmutable($tomorrow . ' 09:00', new DateTimeZone($tz)))->format('c');

// --- ICS ---------------------------------------------------------------------
$r = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Eva López', 'email' => 'eva@example.com']);
check_em('reserva creada', $r['ok'] === true, json_encode($r));
$bid = (int) $r['booking']['id'];
$booking = Database::selectOne('SELECT * FROM booking_bookings WHERE id = ?', [$bid]);
$service = ['name' => 'Test B5 · Emails'];

$ics = BookingMailer::buildIcs($booking, $service, 'Mi Sitio');
$startUtc = (new DateTimeImmutable($slotIso))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
check_em('ICS: estructura VCALENDAR/VEVENT', str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_contains($ics, 'BEGIN:VEVENT') && str_contains($ics, "END:VCALENDAR\r\n"));
check_em('ICS: DTSTART en UTC correcto', str_contains($ics, 'DTSTART:' . $startUtc), $ics);
check_em('ICS: UID estable por reserva', str_contains($ics, 'UID:booking-' . $bid . '@'));
check_em('ICS: SUMMARY escapado con servicio y sitio', str_contains($ics, 'SUMMARY:Test B5 · Emails — Mi Sitio'));
check_em('ICS: METHOD REQUEST', str_contains($ics, 'METHOD:REQUEST'));

// --- email_status ---------------------------------------------------------------
BookingMailer::sendCreated($siteId, $bid);
$es = (string) Database::selectOne('SELECT email_status FROM booking_bookings WHERE id = ?', [$bid])['email_status'];
if ($mailConfigured) {
    check_em('email_status refleja el envío (sent|failed)', in_array($es, ['sent', 'failed'], true), $es);
} else {
    check_em('sin SMTP → email_status skipped (reserva intacta)', $es === 'skipped', $es);
}
check_em('la reserva sobrevive al flujo de email', Database::selectOne('SELECT id FROM booking_bookings WHERE id = ?', [$bid]) !== null);

// sendStatusChange tampoco rompe nada.
Database::execute("UPDATE booking_bookings SET status = 'confirmed' WHERE id = ?", [$bid]);
BookingMailer::sendStatusChange($siteId, $bid, 'confirmed');
check_em('sendStatusChange no rompe la reserva', (string) Database::selectOne('SELECT status FROM booking_bookings WHERE id = ?', [$bid])['status'] === 'confirmed');

// --- Página pública de cancelación (HTTP) ----------------------------------------
$port = 8798;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;
$token = (string) $booking['cancel_token'];

function fetch_page(string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10]);
    if ($post !== []) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}

[$st, $body] = fetch_page('GET', $base . '/_booking/cancel/' . $bid . '?token=' . $token);
check_em('GET página de cancelación → 200 con confirmación', $st === 200 && str_contains($body, 'Cancelar reserva') && str_contains($body, 'Test B5'), 'status=' . $st);
[$st] = fetch_page('GET', $base . '/_booking/cancel/' . $bid . '?token=malo');
check_em('GET con token malo → 404', $st === 404);
[$st, $body] = fetch_page('POST', $base . '/_booking/cancel/' . $bid, ['token' => $token]);
check_em('POST cancelación → 200 cancelada', $st === 200 && str_contains($body, 'cancelada'), 'status=' . $st);
check_em('estado en BD = cancelled', (string) Database::selectOne('SELECT status FROM booking_bookings WHERE id = ?', [$bid])['status'] === 'cancelled');
[$st, $body] = fetch_page('GET', $base . '/_booking/cancel/' . $bid . '?token=' . $token);
check_em('GET tras cancelar → "ya cancelada"', $st === 200 && str_contains($body, 'ya estaba cancelada'));

// --- Admin: cambio de estado con email (vía método, no HTTP: requiere sesión) ----
$r2 = BookingService::create($siteId, ['service_id' => $svcId, 'start' => $slotIso, 'name' => 'Otro', 'email' => 'otro@example.com']);
$bid2 = (int) $r2['booking']['id'];
Database::execute("UPDATE booking_bookings SET status = 'confirmed' WHERE id = ?", [$bid2]);
BookingMailer::sendStatusChange($siteId, $bid2, 'cancelled'); // solo comprueba que no explota sin SMTP
check_em('flujo admin de email tolerante a fallos', true);

// --- Limpieza ---------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
ServiceStore::delete($siteId, $svcId);
ModuleRegistry::setEnabled($siteId, 'booking', $wasEnabled);
check_em('limpieza completa', Database::selectOne('SELECT id FROM booking_bookings WHERE service_id = ?', [$svcId]) === null);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
