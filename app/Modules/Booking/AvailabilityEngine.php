<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * AvailabilityEngine — cálculo de huecos reservables (B3).
 *
 * Servicio PURO: `slots()` no toca BD ni reloj; recibe la configuración del
 * servicio, las reglas de horario (filas de booking_hours), las reservas
 * existentes, el rango de fechas, la zona del sitio y el "ahora" en UTC.
 * Así la batería de tests es determinista (cursor/booking-design.md §4).
 *
 * Convenciones:
 *   - weekday 0=lunes … 6=domingo (formato PHP 'N' − 1).
 *   - Las horas de las reglas son locales del sitio; las reservas van en UTC.
 *   - Precedencia por día: excepción del servicio > excepción global
 *     (service_id NULL) > franjas recurrentes del weekday.
 *   - Un hueco dura duration_min y la parrilla avanza duration+buffer desde
 *     el inicio de cada franja; el hueco debe caber entero en la franja.
 */
final class AvailabilityEngine
{
    /**
     * Huecos por día para un servicio, con plazas restantes.
     *
     * @param array<string,mixed> $service  fila de booking_services (al menos
     *        duration_min, buffer_min, capacity, min_notice_hours, max_advance_days)
     * @param array<int,array<string,mixed>> $rules  filas de booking_hours que
     *        afectan al servicio (propias y globales), con service_id para la precedencia
     * @param array<int,array<string,mixed>> $bookings  reservas activas
     *        (pending/confirmed) con starts_at_utc y ends_at_utc ('Y-m-d H:i:s' UTC)
     * @param string $fromDate  'Y-m-d' local (inclusive)
     * @param string $toDate    'Y-m-d' local (inclusive)
     * @param string $timezone  zona del sitio (p. ej. 'Europe/Madrid')
     * @param DateTimeImmutable $nowUtc  "ahora" en UTC (inyectado)
     *
     * @return array<int, array{date:string, slots: array<int, array{start:string, end:string, remaining:int}>}>
     *         start/end en ISO-8601 con offset local; solo huecos con remaining > 0.
     */
    public static function slots(
        array $service,
        array $rules,
        array $bookings,
        string $fromDate,
        string $toDate,
        string $timezone,
        DateTimeImmutable $nowUtc
    ): array {
        $tz  = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');

        $duration = max(1, (int) ($service['duration_min'] ?? 60));
        $step     = $duration + max(0, (int) ($service['buffer_min'] ?? 0));
        $capacity = max(1, (int) ($service['capacity'] ?? 1));

        // Umbral de antelación mínima y última fecha reservable (en local).
        $minStartUtc = $nowUtc->modify('+' . max(0, (int) ($service['min_notice_hours'] ?? 0)) . ' hours');
        $todayLocal  = $nowUtc->setTimezone($tz)->format('Y-m-d');
        $lastDate    = (new DateTimeImmutable($todayLocal, $tz))
            ->modify('+' . max(1, (int) ($service['max_advance_days'] ?? 60)) . ' days')
            ->format('Y-m-d');

        $index = self::indexRules($rules);
        $activeBookings = self::bookingIntervals($bookings, $utc);

        $out = [];
        $day = new DateTimeImmutable($fromDate, $tz);
        $end = new DateTimeImmutable($toDate, $tz);
        for (; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($date < $todayLocal || $date > $lastDate) {
                continue;
            }

            $ranges = self::rangesForDate($index, $date, (int) $day->format('N') - 1);
            if ($ranges === []) {
                continue;
            }

            $slots = [];
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                $cursor   = new DateTimeImmutable($date . ' ' . $rangeStart, $tz);
                $rangeCap = new DateTimeImmutable($date . ' ' . $rangeEnd, $tz);
                while (true) {
                    $slotEnd = $cursor->modify('+' . $duration . ' minutes');
                    if ($slotEnd > $rangeCap) {
                        break;
                    }
                    $startUtc = $cursor->setTimezone($utc);
                    if ($startUtc >= $minStartUtc) {
                        $endUtc = $slotEnd->setTimezone($utc);
                        $used = 0;
                        foreach ($activeBookings as [$bStart, $bEnd]) {
                            if ($bStart < $endUtc && $bEnd > $startUtc) {
                                $used++;
                            }
                        }
                        if ($used < $capacity) {
                            $slots[] = [
                                'start'     => $cursor->format('c'),
                                'end'       => $slotEnd->format('c'),
                                'remaining' => $capacity - $used,
                            ];
                        }
                    }
                    $cursor = $cursor->modify('+' . $step . ' minutes');
                }
            }

            if ($slots !== []) {
                usort($slots, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
                $out[] = ['date' => $date, 'slots' => $slots];
            }
        }

        return $out;
    }

    /**
     * ¿Es `$startIso` el inicio de un hueco reservable? Devuelve el hueco
     * (con sus límites UTC) o null. Lo usa B4 para validar el POST de reserva
     * recalculando en servidor, sin fiarse del cliente.
     *
     * @param array<string,mixed> $service
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,array<string,mixed>> $bookings
     * @return array{start_utc:string, end_utc:string, remaining:int}|null
     */
    public static function findSlot(
        array $service,
        array $rules,
        array $bookings,
        string $startIso,
        string $timezone,
        DateTimeImmutable $nowUtc
    ): ?array {
        try {
            $start = new DateTimeImmutable($startIso);
        } catch (\Exception) {
            return null;
        }
        $tz = new DateTimeZone($timezone);
        $date = $start->setTimezone($tz)->format('Y-m-d');
        $days = self::slots($service, $rules, $bookings, $date, $date, $timezone, $nowUtc);
        $wantedUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        foreach ($days[0]['slots'] ?? [] as $slot) {
            $slotStartUtc = (new DateTimeImmutable($slot['start']))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            if ($slotStartUtc === $wantedUtc) {
                $slotEndUtc = (new DateTimeImmutable($slot['end']))
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                return ['start_utc' => $slotStartUtc, 'end_utc' => $slotEndUtc, 'remaining' => $slot['remaining']];
            }
        }
        return null;
    }

    /**
     * Loader con BD: carga servicio, reglas (propias + globales) y reservas
     * activas del rango, y delega en la función pura.
     *
     * @return array{service: ?array<string,mixed>, days: array<int,mixed>}
     */
    public static function forService(
        int $siteId,
        int $serviceId,
        string $fromDate,
        string $toDate,
        string $timezone,
        ?DateTimeImmutable $nowUtc = null
    ): array {
        $service = Database::selectOne(
            'SELECT * FROM booking_services WHERE site_id = ? AND id = ? AND active = 1 LIMIT 1',
            [$siteId, $serviceId]
        );
        if ($service === null) {
            return ['service' => null, 'days' => []];
        }
        $rules = Database::select(
            'SELECT service_id, weekday, date, start_time, end_time, closed
               FROM booking_hours
              WHERE site_id = ? AND (service_id = ? OR service_id IS NULL)',
            [$siteId, $serviceId]
        );
        // Margen de 1 día a cada lado por el desfase local↔UTC de las reservas.
        $bookings = Database::select(
            "SELECT starts_at_utc, ends_at_utc FROM booking_bookings
              WHERE service_id = ? AND status IN ('pending','confirmed')
                AND starts_at_utc >= DATE_SUB(?, INTERVAL 1 DAY)
                AND starts_at_utc <  DATE_ADD(?, INTERVAL 2 DAY)",
            [$serviceId, $fromDate . ' 00:00:00', $toDate . ' 00:00:00']
        );
        $now = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [
            'service' => $service,
            'days'    => self::slots($service, $rules, $bookings, $fromDate, $toDate, $timezone, $now),
        ];
    }

    // ======================================================================
    // Internos
    // ======================================================================

    /**
     * Separa las reglas crudas en recurrentes y excepciones (por fecha),
     * distinguiendo excepciones del servicio de las globales.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array{recurring: array<int, array<int, array{0:string,1:string}>>,
     *               exService: array<string, array<int, array{0:string,1:string}>|null>,
     *               exGlobal: array<string, array<int, array{0:string,1:string}>|null>}
     */
    private static function indexRules(array $rules): array
    {
        $recurring = [];
        $exService = [];
        $exGlobal  = [];
        foreach ($rules as $r) {
            $closed = (int) ($r['closed'] ?? 0) === 1;
            $range = null;
            if (!$closed && isset($r['start_time'], $r['end_time'])) {
                $range = [substr((string) $r['start_time'], 0, 5), substr((string) $r['end_time'], 0, 5)];
                if ($range[0] >= $range[1]) {
                    continue; // regla corrupta: se ignora
                }
            }
            if (isset($r['date']) && $r['date'] !== null && $r['date'] !== '') {
                $date = (string) $r['date'];
                $bucket = ($r['service_id'] ?? null) === null ? 'exGlobal' : 'exService';
                // null = día cerrado; array = franjas especiales acumulables.
                if ($closed) {
                    ${$bucket}[$date] = null;
                } elseif ($range !== null && (${$bucket}[$date] ?? []) !== null) {
                    ${$bucket}[$date][] = $range;
                }
            } elseif (isset($r['weekday']) && $r['weekday'] !== null && $range !== null) {
                $recurring[(int) $r['weekday']][] = $range;
            }
        }
        return ['recurring' => $recurring, 'exService' => $exService, 'exGlobal' => $exGlobal];
    }

    /**
     * Franjas efectivas de una fecha aplicando la precedencia
     * servicio > global > recurrente. Devuelve [] si el día está cerrado.
     *
     * @param array{recurring:array, exService:array, exGlobal:array} $index
     * @return array<int, array{0:string,1:string}>
     */
    private static function rangesForDate(array $index, string $date, int $weekday): array
    {
        if (array_key_exists($date, $index['exService'])) {
            return $index['exService'][$date] ?? [];
        }
        if (array_key_exists($date, $index['exGlobal'])) {
            return $index['exGlobal'][$date] ?? [];
        }
        return $index['recurring'][$weekday] ?? [];
    }

    /**
     * @param array<int,array<string,mixed>> $bookings
     * @return array<int, array{0:DateTimeImmutable, 1:DateTimeImmutable}>
     */
    private static function bookingIntervals(array $bookings, DateTimeZone $utc): array
    {
        $out = [];
        foreach ($bookings as $b) {
            $status = (string) ($b['status'] ?? 'pending');
            if (!in_array($status, ['pending', 'confirmed'], true)) {
                continue;
            }
            $out[] = [
                new DateTimeImmutable((string) $b['starts_at_utc'], $utc),
                new DateTimeImmutable((string) $b['ends_at_utc'], $utc),
            ];
        }
        return $out;
    }
}
