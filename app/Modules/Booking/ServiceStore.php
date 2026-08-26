<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use Core\Database;

/**
 * ServiceStore — persistencia de servicios reservables y su horario (B2).
 *
 * Un servicio guarda su configuración en `booking_services` y sus reglas de
 * horario en `booking_hours` (recurrentes por weekday + excepciones por fecha;
 * ver cursor/booking-design.md §3). Las horas se interpretan SIEMPRE en la
 * zona horaria del sitio; aquí solo se persisten tal cual (TIME locales).
 *
 * Al actualizar, las reglas del servicio se reescriben (delete+insert): el
 * horario completo viaja en cada guardado, igual que los campos de un
 * formulario en FormStore.
 */
final class ServiceStore
{
    /** Campos editables con sus defaults (coinciden con el DDL). */
    private const DEFAULTS = [
        'name'             => '',
        'description'      => '',
        'duration_min'     => 60,
        'buffer_min'       => 0,
        'capacity'         => 1,
        'min_notice_hours' => 12,
        'max_advance_days' => 60,
        'auto_confirm'     => 0,
        'price_label'      => '',
        'active'           => 1,
        // MODULOS M8 — definición de los campos que se le piden al cliente.
        // NULL = los de siempre (nombre, email, teléfono y notas).
        'fields_json'      => null,
        // MODULOS M9 — plantillas de email reescritas. NULL = las de siempre.
        'emails_json'      => null,
    ];

    /** @return array<int, array<string,mixed>> servicios del sitio (con nº de reservas futuras activas) */
    public static function all(int $siteId): array
    {
        return Database::select(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM booking_bookings b
                      WHERE b.service_id = s.id
                        AND b.status IN (\'pending\', \'confirmed\')
                        AND b.starts_at_utc >= UTC_TIMESTAMP()) AS upcoming_count
               FROM booking_services s
              WHERE s.site_id = ?
              ORDER BY s.active DESC, s.name ASC',
            [$siteId]
        );
    }

    /** @return array<string,mixed>|null servicio con 'hours' y 'exceptions' hidratados */
    public static function find(int $siteId, int $id): ?array
    {
        $service = Database::selectOne(
            'SELECT * FROM booking_services WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        );
        if ($service === null) {
            return null;
        }
        $service['hours']      = self::weeklyHours($id);
        $service['exceptions'] = self::exceptions($id);
        return $service;
    }

    /**
     * Franjas recurrentes agrupadas por weekday (0=lunes … 6=domingo).
     *
     * @return array<int, array<int, array{start:string, end:string}>>
     */
    public static function weeklyHours(int $serviceId): array
    {
        $rows = Database::select(
            'SELECT weekday, start_time, end_time FROM booking_hours
              WHERE service_id = ? AND weekday IS NOT NULL
              ORDER BY weekday, start_time',
            [$serviceId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['weekday']][] = [
                'start' => substr((string) $r['start_time'], 0, 5),
                'end'   => substr((string) $r['end_time'], 0, 5),
            ];
        }
        return $out;
    }

    /**
     * Excepciones de fecha del servicio, ordenadas por fecha.
     *
     * @return array<int, array{date:string, closed:bool, start:?string, end:?string}>
     */
    public static function exceptions(int $serviceId): array
    {
        $rows = Database::select(
            'SELECT date, closed, start_time, end_time FROM booking_hours
              WHERE service_id = ? AND date IS NOT NULL
              ORDER BY date, start_time',
            [$serviceId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'date'   => (string) $r['date'],
                'closed' => (bool) $r['closed'],
                'start'  => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : null,
                'end'    => $r['end_time'] !== null ? substr((string) $r['end_time'], 0, 5) : null,
            ];
        }
        return $out;
    }

    /**
     * Crea un servicio con defaults y devuelve su id.
     *
     * @param array<string,mixed> $fields
     */
    public static function create(int $siteId, array $fields): int
    {
        $f = self::normalize($fields);
        // El idioma se fija al crear: la columna tiene DEFAULT 'es', así que sin
        // esto TODO servicio nacía en castellano — también en una web francesa,
        // y sus emails y textos salían en el idioma equivocado.
        $lang = isset($fields['language']) && trim((string) $fields['language']) !== ''
            ? \App\Services\LanguageService::normalize((string) $fields['language'])
            : \App\Services\LanguageService::codeFor($siteId);
        Database::execute(
            'INSERT INTO booking_services
                (site_id, name, description, duration_min, buffer_min, capacity,
                 min_notice_hours, max_advance_days, auto_confirm, price_label, active,
                 language, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                $siteId, $f['name'], $f['description'], $f['duration_min'], $f['buffer_min'],
                $f['capacity'], $f['min_notice_hours'], $f['max_advance_days'],
                $f['auto_confirm'], $f['price_label'] !== '' ? $f['price_label'] : null, $f['active'],
                $lang,
            ]
        );
        self::flushPageCache($siteId);
        return (int) Database::lastInsertId();
    }

    /**
     * Actualiza configuración + horario completo (reglas reescritas).
     *
     * @param array<string,mixed> $fields
     * @param array<int, array<int, array{start:string, end:string}>> $hours
     * @param array<int, array{date:string, closed:bool, start:?string, end:?string}> $exceptions
     */
    public static function update(int $siteId, int $id, array $fields, array $hours, array $exceptions): bool
    {
        $f = self::normalize($fields);
        $updated = Database::execute(
            'UPDATE booking_services
                SET name = ?, description = ?, duration_min = ?, buffer_min = ?, capacity = ?,
                    min_notice_hours = ?, max_advance_days = ?, auto_confirm = ?, price_label = ?,
                    active = ?, fields_json = ?, emails_json = ?, updated_at = UTC_TIMESTAMP()
              WHERE site_id = ? AND id = ?',
            [
                $f['name'], $f['description'], $f['duration_min'], $f['buffer_min'], $f['capacity'],
                $f['min_notice_hours'], $f['max_advance_days'], $f['auto_confirm'],
                $f['price_label'] !== '' ? $f['price_label'] : null, $f['active'],
                $f['fields_json'], $f['emails_json'],
                $siteId, $id,
            ]
        );
        // Puede devolver 0 filas si no cambió nada; comprobar existencia real.
        if ($updated === 0 && Database::selectOne(
            'SELECT id FROM booking_services WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        ) === null) {
            return false;
        }

        Database::execute('DELETE FROM booking_hours WHERE service_id = ?', [$id]);
        foreach ($hours as $weekday => $ranges) {
            foreach ($ranges as $range) {
                Database::execute(
                    'INSERT INTO booking_hours (site_id, service_id, weekday, start_time, end_time, closed)
                     VALUES (?, ?, ?, ?, ?, 0)',
                    [$siteId, $id, $weekday, $range['start'] . ':00', $range['end'] . ':00']
                );
            }
        }
        foreach ($exceptions as $ex) {
            Database::execute(
                'INSERT INTO booking_hours (site_id, service_id, date, start_time, end_time, closed)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $siteId, $id, $ex['date'],
                    $ex['closed'] ? null : ($ex['start'] . ':00'),
                    $ex['closed'] ? null : ($ex['end'] . ':00'),
                    $ex['closed'] ? 1 : 0,
                ]
            );
        }
        self::flushPageCache($siteId);
        return true;
    }

    /** Borra el servicio (las reglas y reservas caen por FK CASCADE). */
    public static function delete(int $siteId, int $id): bool
    {
        $deleted = Database::execute(
            'DELETE FROM booking_services WHERE site_id = ? AND id = ?',
            [$siteId, $id]
        ) > 0;
        if ($deleted) {
            self::flushPageCache($siteId);
        }
        return $deleted;
    }

    /**
     * MODULOS M2: las páginas (canvas y de secciones) pueden llevar el
     * calendario incrustado y se cachean como HTML estático, con el nombre y la
     * duración del servicio ya escritos dentro. Sin esto, renombrar un servicio
     * dejaba el nombre viejo en las páginas cacheadas, y desactivarlo dejaba un
     * calendario que luego avisaba "no disponible" en lugar de desaparecer.
     * Mismo criterio que `ProductStore` con {{products:featured}}.
     */
    private static function flushPageCache(int $siteId): void
    {
        try {
            \App\Services\CacheService::flush($siteId);
        } catch (\Throwable) {
            // la caché nunca rompe una operación sobre los servicios
        }
    }

    /**
     * Normaliza y acota los campos de configuración.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function normalize(array $fields): array
    {
        $f = array_merge(self::DEFAULTS, array_intersect_key($fields, self::DEFAULTS));
        $f['name']             = mb_substr(trim((string) $f['name']), 0, 120);
        $f['description']      = mb_substr(trim((string) $f['description']), 0, 4000);
        $f['price_label']      = mb_substr(trim((string) $f['price_label']), 0, 60);
        $f['duration_min']     = max(5, min(480, (int) $f['duration_min']));
        $f['buffer_min']       = max(0, min(240, (int) $f['buffer_min']));
        $f['capacity']         = max(1, min(500, (int) $f['capacity']));
        $f['min_notice_hours'] = max(0, min(720, (int) $f['min_notice_hours']));
        $f['max_advance_days'] = max(1, min(365, (int) $f['max_advance_days']));
        $f['auto_confirm']     = (int) ((string) $f['auto_confirm'] === '1');
        $f['active']           = (int) ((string) $f['active'] === '1');
        // Los campos llegan como array desde el editor; se guardan saneados y en
        // JSON. Si no vienen, se deja lo que hubiera (null) para no pisar la
        // configuración con un guardado que no los incluía.
        if (is_array($fields['fields'] ?? null)) {
            $f['fields_json'] = json_encode(
                BookingFields::normalize($fields['fields']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } elseif (is_string($f['fields_json']) && trim($f['fields_json']) === '') {
            $f['fields_json'] = null;
        }
        // Igual con las plantillas de email: si no se ha reescrito nada, se
        // guarda NULL y el servicio sigue usando las de siempre (traducidas).
        if (is_array($fields['emails'] ?? null)) {
            $emails = BookingEmails::normalize($fields['emails']);
            $f['emails_json'] = BookingEmails::isEmpty($emails)
                ? null
                : json_encode($emails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_string($f['emails_json']) && trim($f['emails_json']) === '') {
            $f['emails_json'] = null;
        }
        return $f;
    }
}
