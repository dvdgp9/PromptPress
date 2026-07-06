<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BookingService — creación y cancelación de reservas (B4).
 *
 * `create()` es el único camino de escritura y garantiza el anti-doble-reserva:
 * valida el hueco recalculándolo con AvailabilityEngine (nunca se fía del
 * cliente) y dentro de una transacción cuenta las reservas activas del slot
 * con SELECT … FOR UPDATE antes de insertar. Dos peticiones concurrentes al
 * último hueco → el lock serializa y solo una gana (la otra recibe
 * 'slot_unavailable').
 */
final class BookingService
{
    public const RATE_LIMIT_MAX = 5;       // reservas por IP…
    public const RATE_LIMIT_WINDOW = 10;   // …cada N minutos

    /**
     * Crea una reserva si el hueco es legal y queda plaza.
     *
     * @param array<string,mixed> $input  {service_id:int, start:string ISO-8601,
     *                                     name:string, email:string, phone?:string, notes?:string}
     * @return array{ok:bool, status?:string, booking?:array<string,mixed>, error?:string, fields?:array<string,string>}
     */
    public static function create(int $siteId, array $input, ?string $ipHash = null, ?DateTimeImmutable $nowUtc = null): array
    {
        $serviceId = (int) ($input['service_id'] ?? 0);
        $start     = trim((string) ($input['start'] ?? ''));

        // --- Validación de datos del cliente (campos fijos v1) --------------
        $fields = [];
        $name  = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
        $email = mb_substr(trim((string) ($input['email'] ?? '')), 0, 190);
        $phone = mb_substr(trim((string) ($input['phone'] ?? '')), 0, 40);
        $notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000);
        if ($name === '') {
            $fields['name'] = 'El nombre es obligatorio.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Necesitamos un email válido para confirmar la reserva.';
        }
        if ($serviceId <= 0 || $start === '') {
            $fields['start'] = 'Falta el servicio o la hora de inicio.';
        }
        if ($fields !== []) {
            return ['ok' => false, 'error' => 'validation', 'fields' => $fields];
        }

        // --- Servicio y contexto --------------------------------------------
        $service = Database::selectOne(
            'SELECT * FROM booking_services WHERE site_id = ? AND id = ? AND active = 1 LIMIT 1',
            [$siteId, $serviceId]
        );
        if ($service === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $timezone = self::siteTimezone($siteId);
        $now = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // --- Rate limit por IP (mismo patrón que formularios) ---------------
        if ($ipHash !== null && self::isRateLimited($siteId, $ipHash)) {
            return ['ok' => false, 'error' => 'rate_limited'];
        }

        // --- Validación del hueco (fuera de la transacción: solo lectura) ----
        $rules = Database::select(
            'SELECT service_id, weekday, date, start_time, end_time, closed
               FROM booking_hours WHERE site_id = ? AND (service_id = ? OR service_id IS NULL)',
            [$siteId, $serviceId]
        );
        $slot = AvailabilityEngine::findSlot(
            $service,
            $rules,
            self::activeBookingsAround($serviceId, $start),
            $start,
            $timezone,
            $now
        );
        if ($slot === null) {
            return ['ok' => false, 'error' => 'slot_unavailable'];
        }

        // --- Transacción con lock: recuento definitivo + insert -------------
        // Se bloquea la fila del servicio (no el rango de reservas): serializa
        // las escrituras por servicio de forma determinista y sin deadlocks de
        // gap-locks; la concurrente espera el lock, re-cuenta y ve el hueco lleno.
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM booking_services WHERE id = ? FOR UPDATE');
            $lock->execute([$serviceId]);

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS n FROM booking_bookings
                  WHERE service_id = ? AND status IN ('pending','confirmed')
                    AND starts_at_utc < ? AND ends_at_utc > ?"
            );
            $stmt->execute([$serviceId, $slot['end_utc'], $slot['start_utc']]);
            $used = (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['n'] ?? 0);
            if ($used >= (int) $service['capacity']) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'slot_unavailable'];
            }

            $status = (int) $service['auto_confirm'] === 1 ? 'confirmed' : 'pending';
            $token  = bin2hex(random_bytes(16));
            $ins = $pdo->prepare(
                'INSERT INTO booking_bookings
                    (site_id, service_id, starts_at_utc, ends_at_utc, status,
                     customer_name, customer_email, customer_phone, notes,
                     cancel_token, ip_hash, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $ins->execute([
                $siteId, $serviceId, $slot['start_utc'], $slot['end_utc'], $status,
                $name, $email, $phone !== '' ? $phone : null, $notes !== '' ? $notes : null,
                $token, $ipHash,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'ok'      => true,
            'booking' => [
                'id'           => $id,
                'status'       => $status,
                'service'      => (string) $service['name'],
                'start'        => self::toLocalIso($slot['start_utc'], $timezone),
                'end'          => self::toLocalIso($slot['end_utc'], $timezone),
                'cancel_token' => $token,
            ],
        ];
    }

    /**
     * Cancela una reserva con su token (link del email). Idempotente: cancelar
     * una reserva ya cancelada devuelve ok.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function cancelWithToken(int $siteId, int $bookingId, string $token): array
    {
        if ($token === '' || strlen($token) > 64) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $row = Database::selectOne(
            'SELECT id, status, cancel_token FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $bookingId]
        );
        if ($row === null || !hash_equals((string) $row['cancel_token'], $token)) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((string) $row['status'] !== 'cancelled') {
            Database::execute(
                "UPDATE booking_bookings SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$bookingId]
            );
        }
        return ['ok' => true];
    }

    /** Zona horaria del sitio (columna sites.timezone, fallback Madrid). */
    public static function siteTimezone(int $siteId): string
    {
        $row = Database::selectOne('SELECT timezone FROM sites WHERE id = ? LIMIT 1', [$siteId]);
        $tz = (string) ($row['timezone'] ?? '');
        return $tz !== '' ? $tz : 'Europe/Madrid';
    }

    public static function isRateLimited(int $siteId, string $ipHash): bool
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n FROM booking_bookings
              WHERE site_id = ? AND ip_hash = ?
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::RATE_LIMIT_WINDOW . ' MINUTE)',
            [$siteId, $ipHash]
        );
        return (int) ($row['n'] ?? 0) >= self::RATE_LIMIT_MAX;
    }

    /** Reservas activas alrededor de la fecha pedida (±1 día por el desfase UTC). */
    private static function activeBookingsAround(int $serviceId, string $startIso): array
    {
        try {
            $day = (new DateTimeImmutable($startIso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Exception) {
            return [];
        }
        return Database::select(
            "SELECT starts_at_utc, ends_at_utc, status FROM booking_bookings
              WHERE service_id = ? AND status IN ('pending','confirmed')
                AND starts_at_utc >= DATE_SUB(?, INTERVAL 1 DAY)
                AND starts_at_utc <  DATE_ADD(?, INTERVAL 2 DAY)",
            [$serviceId, $day . ' 00:00:00', $day . ' 00:00:00']
        );
    }

    private static function toLocalIso(string $utcDateTime, string $timezone): string
    {
        return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('c');
    }
}
