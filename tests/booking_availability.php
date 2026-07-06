<?php

declare(strict_types=1);

/**
 * FEAT-3 B3 — Tests del motor de disponibilidad (AvailabilityEngine).
 *
 * Todo sobre la función pura `slots()`/`findSlot()`: sin BD, con "ahora"
 * inyectado → determinista. Fechas de referencia en 2027 (futuro lejano)
 * para que min_notice/max_advance no interfieran salvo donde se prueban.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\AvailabilityEngine;

$failed = 0;
function check_av(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$TZ = 'Europe/Madrid';
// "Ahora": lunes 2027-06-07 08:00 Madrid (06:00 UTC, verano +02:00).
$NOW = new DateTimeImmutable('2027-06-07 06:00:00', new DateTimeZone('UTC'));

function svc(array $over = []): array
{
    return array_merge([
        'duration_min'     => 60,
        'buffer_min'       => 0,
        'capacity'         => 1,
        'min_notice_hours' => 0,
        'max_advance_days' => 365,
    ], $over);
}

/** Regla recurrente. */
function rec(int $weekday, string $start, string $end): array
{
    return ['service_id' => 7, 'weekday' => $weekday, 'date' => null, 'start_time' => $start, 'end_time' => $end, 'closed' => 0];
}

/** Excepción ($serviceId null = global). */
function exc(?int $serviceId, string $date, bool $closed, ?string $start = null, ?string $end = null): array
{
    return ['service_id' => $serviceId, 'weekday' => null, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'closed' => $closed ? 1 : 0];
}

/** Reserva activa en UTC. */
function bk(string $startUtc, string $endUtc, string $status = 'confirmed'): array
{
    return ['starts_at_utc' => $startUtc, 'ends_at_utc' => $endUtc, 'status' => $status];
}

$starts = fn (array $days) => array_map(
    fn (array $s) => substr($s['start'], 11, 5),
    $days[0]['slots'] ?? []
);

// ---------------------------------------------------------------------------
// 1. Generación básica: lunes 9-14, 60 min → 5 huecos.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('básico: 5 huecos de 60 min en 9-14', $starts($days) === ['09:00', '10:00', '11:00', '12:00', '13:00'], json_encode($starts($days)));
check_av('básico: ISO con offset de verano (+02:00)', str_ends_with($days[0]['slots'][0]['start'], '+02:00'), $days[0]['slots'][0]['start']);

// 2. Buffer: 60+30 → 9:00, 10:30, 12:00 (13:30+60 > 14:00 fuera).
$days = AvailabilityEngine::slots(svc(['buffer_min' => 30]), [rec(0, '09:00', '14:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('buffer 30: parrilla 9:00/10:30/12:00', $starts($days) === ['09:00', '10:30', '12:00'], json_encode($starts($days)));

// 3. Hueco que no cabe entero se descarta: 90 min en 9-13:40 → 9:00, 10:30 (12:00+90=13:30<=13:40 sí) → 3.
$days = AvailabilityEngine::slots(svc(['duration_min' => 90]), [rec(0, '09:00', '13:40')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('duración 90: solo huecos completos', $starts($days) === ['09:00', '10:30', '12:00'], json_encode($starts($days)));

// 4. Varias franjas el mismo día (partido).
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '11:00'), rec(0, '16:00', '18:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('horario partido: franjas de mañana y tarde', $starts($days) === ['09:00', '10:00', '16:00', '17:00'], json_encode($starts($days)));

// 5. Día sin franjas → sin entrada en el resultado.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '11:00')], [], '2027-06-15', '2027-06-15', $TZ, $NOW); // martes
check_av('día sin horario recurrente → vacío', $days === []);

// 6. Excepción cerrado anula el día.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00'), exc(7, '2027-06-14', true)], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('excepción cerrado del servicio → sin huecos', $days === []);

// 7. Excepción con horario especial sustituye al recurrente.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00'), exc(7, '2027-06-14', false, '17:00', '19:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('excepción especial sustituye al recurrente', $starts($days) === ['17:00', '18:00'], json_encode($starts($days)));

// 8. Excepción global (festivo) cierra; la del servicio tiene precedencia sobre la global.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00'), exc(null, '2027-06-14', true)], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('festivo global cierra el día', $days === []);
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00'), exc(null, '2027-06-14', true), exc(7, '2027-06-14', false, '10:00', '12:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('excepción del servicio gana a la global', $starts($days) === ['10:00', '11:00'], json_encode($starts($days)));

// 9. Antelación mínima: hoy lunes 08:00 con min_notice 2h → desde las 10:00.
$days = AvailabilityEngine::slots(svc(['min_notice_hours' => 2]), [rec(0, '09:00', '14:00')], [], '2027-06-07', '2027-06-07', $TZ, $NOW);
check_av('min_notice 2h: hoy empieza a las 10:00', $starts($days) === ['10:00', '11:00', '12:00', '13:00'], json_encode($starts($days)));

// 10. Ventana máxima: max_advance 7 días → el lunes en 14 días queda fuera.
$days = AvailabilityEngine::slots(svc(['max_advance_days' => 7]), [rec(0, '09:00', '14:00')], [], '2027-06-21', '2027-06-21', $TZ, $NOW);
check_av('max_advance 7d excluye fechas lejanas', $days === []);

// 11. Días pasados excluidos aunque el rango los pida.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00')], [], '2027-05-31', '2027-06-01', $TZ, $NOW);
check_av('días pasados excluidos', $days === []);

// 12. Ocupación: capacity 1 + reserva 10:00-11:00 local (08:00-09:00 UTC) → hueco de las 10 desaparece.
$booked = [bk('2027-06-14 08:00:00', '2027-06-14 09:00:00')];
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00')], $booked, '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('reserva llena el hueco (capacity 1)', $starts($days) === ['09:00', '11:00', '12:00', '13:00'], json_encode($starts($days)));

// 13. Capacity 3: dos reservas en el mismo hueco → remaining 1; tercera lo elimina.
$two = [bk('2027-06-14 08:00:00', '2027-06-14 09:00:00'), bk('2027-06-14 08:00:00', '2027-06-14 09:00:00')];
$days = AvailabilityEngine::slots(svc(['capacity' => 3]), [rec(0, '09:00', '14:00')], $two, '2027-06-14', '2027-06-14', $TZ, $NOW);
$slot10 = array_values(array_filter($days[0]['slots'], fn ($s) => substr($s['start'], 11, 5) === '10:00'))[0] ?? null;
check_av('capacity 3 con 2 reservas → remaining 1', ($slot10['remaining'] ?? 0) === 1, json_encode($slot10));

// 14. Reserva cancelada no cuenta.
$cancelled = [bk('2027-06-14 08:00:00', '2027-06-14 09:00:00', 'cancelled')];
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00')], $cancelled, '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('reserva cancelada no ocupa', count($days[0]['slots']) === 5);

// 15. Solape parcial bloquea: reserva 10:30-11:30 local pisa huecos de 10 y 11.
$partial = [bk('2027-06-14 08:30:00', '2027-06-14 09:30:00')];
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '14:00')], $partial, '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('solape parcial bloquea ambos huecos', $starts($days) === ['09:00', '12:00', '13:00'], json_encode($starts($days)));

// 16. DST: último domingo de marzo de 2027 es el 28. Antes (+01:00), después (+02:00);
//     el hueco de las 9:00 local del 29-03 debe ser 07:00 UTC, y el del 22-03, 08:00 UTC.
$winter = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '10:00')], [], '2027-03-22', '2027-03-22', $TZ, $NOW->modify('-3 months'));
$summer = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '10:00')], [], '2027-03-29', '2027-03-29', $TZ, $NOW->modify('-3 months'));
check_av('DST: invierno +01:00', str_ends_with($winter[0]['slots'][0]['start'], '+01:00'), $winter[0]['slots'][0]['start'] ?? 'sin slots');
check_av('DST: verano +02:00', str_ends_with($summer[0]['slots'][0]['start'], '+02:00'), $summer[0]['slots'][0]['start'] ?? 'sin slots');
$w = new DateTimeImmutable($winter[0]['slots'][0]['start']);
$s = new DateTimeImmutable($summer[0]['slots'][0]['start']);
check_av('DST: mismos 9:00 locales, distinto UTC (08:00/07:00)',
    $w->setTimezone(new DateTimeZone('UTC'))->format('H:i') === '08:00'
    && $s->setTimezone(new DateTimeZone('UTC'))->format('H:i') === '07:00');

// 17. Rango multi-día: semana completa con lunes y miércoles configurados.
$days = AvailabilityEngine::slots(svc(), [rec(0, '09:00', '11:00'), rec(2, '09:00', '11:00')], [], '2027-06-14', '2027-06-20', $TZ, $NOW);
check_av('rango semanal: solo lunes y miércoles', array_map(fn ($d) => $d['date'], $days) === ['2027-06-14', '2027-06-16']);

// 18. findSlot: hueco válido, hueco inexistente, hueco lleno.
$rules = [rec(0, '09:00', '14:00')];
$hit = AvailabilityEngine::findSlot(svc(), $rules, [], '2027-06-14T10:00:00+02:00', $TZ, $NOW);
check_av('findSlot encuentra hueco válido', $hit !== null && $hit['start_utc'] === '2027-06-14 08:00:00' && $hit['end_utc'] === '2027-06-14 09:00:00', json_encode($hit));
check_av('findSlot rechaza hora fuera de parrilla', AvailabilityEngine::findSlot(svc(), $rules, [], '2027-06-14T10:17:00+02:00', $TZ, $NOW) === null);
check_av('findSlot rechaza hueco lleno', AvailabilityEngine::findSlot(svc(), $rules, [bk('2027-06-14 08:00:00', '2027-06-14 09:00:00')], '2027-06-14T10:00:00+02:00', $TZ, $NOW) === null);
check_av('findSlot rechaza fecha ilegible', AvailabilityEngine::findSlot(svc(), $rules, [], 'no-es-fecha', $TZ, $NOW) === null);
// El mismo instante expresado en otra zona también debe casar (contrato ISO-8601).
$hitUtc = AvailabilityEngine::findSlot(svc(), $rules, [], '2027-06-14T08:00:00+00:00', $TZ, $NOW);
check_av('findSlot acepta el instante en otra zona', $hitUtc !== null && $hitUtc['start_utc'] === '2027-06-14 08:00:00', json_encode($hitUtc));

// 19. Reglas corruptas (start >= end) se ignoran sin romper.
$days = AvailabilityEngine::slots(svc(), [rec(0, '14:00', '09:00'), rec(0, '10:00', '12:00')], [], '2027-06-14', '2027-06-14', $TZ, $NOW);
check_av('regla invertida ignorada', $starts($days) === ['10:00', '11:00'], json_encode($starts($days)));

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
