<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * BookingAdminController — CRUD de servicios reservables (B2).
 *
 * La gestión de reservas (listado/confirmar/cancelar) llega en B5; este
 * controller cubre solo la configuración de servicios y su horario.
 */
final class BookingAdminController
{
    /** Etiquetas de weekday, índice 0=lunes (convención booking_hours). */
    public const WEEKDAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /** GET /admin/booking — listado de servicios + integración externa. */
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        View::send('admin/booking/index', [
            'services'       => ServiceStore::all($siteId),
            'apiKey'         => $this->currentApiKey($siteId),
            'allowedOrigins' => $this->setting($siteId, 'booking_allowed_origins'),
            'notice'         => Session::flash('notice'),
            'error'          => Session::flash('error'),
            'csrf'           => CSRF::token(),
        ]);
    }

    /** POST /admin/booking/integration — genera/regenera la API key y guarda orígenes. */
    public function integration(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        // Orígenes permitidos: uno por línea, solo esquema+host[:puerto].
        $rawOrigins = (string) Request::post('allowed_origins', '');
        $clean = [];
        foreach (preg_split('/[\s,]+/', $rawOrigins, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $o) {
            $o = rtrim(trim($o), '/');
            $p = parse_url($o);
            if (!isset($p['scheme'], $p['host']) || !in_array($p['scheme'], ['http', 'https'], true)) {
                Session::flash('error', 'Origen no válido: «' . $o . '». Usa el formato https://www.ejemplo.com');
                Response::redirect(base_url('admin/booking'));
            }
            $clean[] = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        $this->saveSetting($siteId, 'booking_allowed_origins', implode("\n", array_unique($clean)), false);

        if ((string) Request::post('regenerate_key', '0') === '1' || $this->currentApiKey($siteId) === null) {
            $appKey = (string) \Core\App::config()['app_key'];
            $newKey = 'ppbk_' . bin2hex(random_bytes(20));
            $this->saveSetting($siteId, 'booking_api_key', \Core\Crypto::encrypt($newKey, $appKey), true);
            Session::flash('notice', 'Clave de API generada. Actualiza el snippet en las webs externas donde lo uses.');
        } else {
            Session::flash('notice', 'Configuración de integración guardada.');
        }
        Response::redirect(base_url('admin/booking'));
    }

    /** API key en claro (o null si no existe) para mostrar el snippet. */
    private function currentApiKey(int $siteId): ?string
    {
        $stored = $this->setting($siteId, 'booking_api_key');
        if ($stored === '') {
            return null;
        }
        try {
            $key = \Core\Crypto::decrypt($stored, (string) \Core\App::config()['app_key']);
            return $key !== '' ? $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function setting(int $siteId, string $key): string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $key]
        );
        return (string) ($row['setting_value'] ?? '');
    }

    private function saveSetting(int $siteId, string $key, string $value, bool $encrypted): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$siteId, $key, $value, $encrypted ? 1 : 0]
        );
    }

    /** POST /admin/booking/services — crea un servicio con defaults y va al editor. */
    public function create(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $name = trim((string) Request::post('name', ''));
        if ($name === '') {
            Session::flash('error', 'Ponle un nombre al servicio.');
            Response::redirect(base_url('admin/booking'));
        }
        $id = ServiceStore::create($siteId, ['name' => $name]);
        Session::flash('notice', 'Servicio creado. Configura su duración y horario.');
        Response::redirect(base_url('admin/booking/services/' . $id));
    }

    /** GET /admin/booking/services/{id} — editor. */
    public function edit(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $service = ServiceStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($service === null) {
            Session::flash('error', 'Servicio no encontrado.');
            Response::redirect(base_url('admin/booking'));
        }
        $this->renderEditor($service, []);
    }

    /** POST /admin/booking/services/{id} — guarda configuración + horario. */
    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $existing = ServiceStore::find($siteId, $id);
        if ($existing === null) {
            Session::flash('error', 'Servicio no encontrado.');
            Response::redirect(base_url('admin/booking'));
        }

        $fields = [
            'name'             => Request::post('name', ''),
            'description'      => Request::post('description', ''),
            'duration_min'     => Request::post('duration_min', 60),
            'buffer_min'       => Request::post('buffer_min', 0),
            'capacity'         => Request::post('capacity', 1),
            'min_notice_hours' => Request::post('min_notice_hours', 12),
            'max_advance_days' => Request::post('max_advance_days', 60),
            'auto_confirm'     => Request::post('auto_confirm', '0'),
            'price_label'      => Request::post('price_label', ''),
            'active'           => Request::post('active', '0'),
        ];

        [$hours, $exceptions, $errors] = $this->collectSchedule();
        if (trim((string) $fields['name']) === '') {
            $errors[] = 'El nombre no puede estar vacío.';
        }

        if ($errors !== []) {
            // Repintar con lo enviado (sin persistir) para no perder la edición.
            $draft = array_merge($existing, $fields, ['id' => $id, 'hours' => $hours, 'exceptions' => $exceptions]);
            $this->renderEditor($draft, $errors);
            return;
        }

        ServiceStore::update($siteId, $id, $fields, $hours, $exceptions);
        Session::flash('notice', 'Servicio guardado.');
        Response::redirect(base_url('admin/booking/services/' . $id));
    }

    /** POST /admin/booking/services/{id}/delete */
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        if (ServiceStore::delete($siteId, (int) ($params['id'] ?? 0))) {
            Session::flash('notice', 'Servicio eliminado (junto con sus reservas).');
        } else {
            Session::flash('error', 'No se pudo eliminar el servicio.');
        }
        Response::redirect(base_url('admin/booking'));
    }

    // ======================================================================
    // Reservas (B5)
    // ======================================================================

    /** GET /admin/booking/reservas — listado con filtros, próximas primero. */
    public function bookings(): void
    {
        $siteId = $this->requireSiteId();

        $status  = (string) Request::get('status', '');
        $service = (int) Request::get('service', 0);
        $scope   = (string) Request::get('scope', 'upcoming'); // upcoming | past | all

        $where = ['b.site_id = ?'];
        $args  = [$siteId];
        if (in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
            $where[] = 'b.status = ?';
            $args[]  = $status;
        }
        if ($service > 0) {
            $where[] = 'b.service_id = ?';
            $args[]  = $service;
        }
        if ($scope === 'past') {
            $where[] = 'b.starts_at_utc < UTC_TIMESTAMP()';
        } elseif ($scope !== 'all') {
            $scope = 'upcoming';
            $where[] = 'b.starts_at_utc >= UTC_TIMESTAMP()';
        }

        $rows = Database::select(
            'SELECT b.*, s.name AS service_name
               FROM booking_bookings b
               JOIN booking_services s ON s.id = b.service_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY b.starts_at_utc ' . ($scope === 'past' ? 'DESC' : 'ASC') . '
              LIMIT 200',
            $args
        );

        $pendingCount = (int) (Database::selectOne(
            "SELECT COUNT(*) AS n FROM booking_bookings
              WHERE site_id = ? AND status = 'pending' AND starts_at_utc >= UTC_TIMESTAMP()",
            [$siteId]
        )['n'] ?? 0);

        View::send('admin/booking/bookings', [
            'bookings'     => $rows,
            'services'     => ServiceStore::all($siteId),
            'timezone'     => BookingService::siteTimezone($siteId),
            'filters'      => ['status' => $status, 'service' => $service, 'scope' => $scope],
            'pendingCount' => $pendingCount,
            'notice'       => Session::flash('notice'),
            'error'        => Session::flash('error'),
            'csrf'         => CSRF::token(),
        ]);
    }

    /** POST /admin/booking/reservas/{id}/status — confirmar o cancelar. */
    public function bookingStatus(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $to = (string) Request::post('status', '');
        if (!in_array($to, ['confirmed', 'cancelled'], true)) {
            Session::flash('error', 'Estado no válido.');
            Response::redirect($this->bookingsUrl());
        }
        $booking = Database::selectOne(
            'SELECT id, status FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        );
        if ($booking === null) {
            Session::flash('error', 'Reserva no encontrada.');
            Response::redirect($this->bookingsUrl());
        }
        if ((string) $booking['status'] === $to) {
            Session::flash('notice', 'La reserva ya estaba en ese estado.');
            Response::redirect($this->bookingsUrl());
        }

        Database::execute(
            'UPDATE booking_bookings SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$to, $id]
        );
        try {
            BookingMailer::sendStatusChange($siteId, $id, $to);
        } catch (\Throwable) {
            // el email nunca revierte el cambio de estado
        }
        Session::flash('notice', $to === 'confirmed'
            ? 'Reserva confirmada. Hemos avisado al cliente por email.'
            : 'Reserva cancelada. Hemos avisado al cliente por email.');
        Response::redirect($this->bookingsUrl());
    }

    /** Vuelve al listado conservando los filtros con los que se llegó. */
    private function bookingsUrl(): string
    {
        $qs = http_build_query(array_filter([
            'status'  => (string) Request::post('f_status', ''),
            'service' => (string) Request::post('f_service', ''),
            'scope'   => (string) Request::post('f_scope', ''),
        ], static fn (string $v): bool => $v !== '' && $v !== '0'));
        return base_url('admin/booking/reservas') . ($qs !== '' ? '?' . $qs : '');
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Recoge y valida horario semanal + excepciones del POST.
     *
     * Formato esperado:
     *   hours[<weekday>][<i>][start|end]  (HH:MM)
     *   exceptions[<i>][date|closed|start|end]
     *
     * @return array{0: array<int, array<int, array{start:string,end:string}>>,
     *               1: array<int, array{date:string,closed:bool,start:?string,end:?string}>,
     *               2: string[]}
     */
    private function collectSchedule(): array
    {
        $errors = [];

        $hours = [];
        $hoursRaw = Request::post('hours', []);
        if (is_array($hoursRaw)) {
            foreach ($hoursRaw as $weekday => $ranges) {
                $weekday = (int) $weekday;
                if ($weekday < 0 || $weekday > 6 || !is_array($ranges)) {
                    continue;
                }
                $clean = [];
                foreach ($ranges as $r) {
                    if (!is_array($r)) continue;
                    $start = $this->cleanTime((string) ($r['start'] ?? ''));
                    $end   = $this->cleanTime((string) ($r['end'] ?? ''));
                    if ($start === null && $end === null) continue; // fila vacía: se ignora
                    if ($start === null || $end === null || $start >= $end) {
                        $errors[] = 'Franja inválida el ' . mb_strtolower(self::WEEKDAYS[$weekday]) . ': la hora de inicio debe ser anterior a la de fin.';
                        continue;
                    }
                    $clean[] = ['start' => $start, 'end' => $end];
                }
                usort($clean, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
                foreach ($clean as $i => $r) {
                    if ($i > 0 && $r['start'] < $clean[$i - 1]['end']) {
                        $errors[] = 'Las franjas del ' . mb_strtolower(self::WEEKDAYS[$weekday]) . ' se solapan.';
                        break;
                    }
                }
                if ($clean !== []) {
                    $hours[$weekday] = $clean;
                }
            }
        }

        $exceptions = [];
        $seen = [];
        $exRaw = Request::post('exceptions', []);
        if (is_array($exRaw)) {
            foreach ($exRaw as $ex) {
                if (!is_array($ex)) continue;
                $date = trim((string) ($ex['date'] ?? ''));
                if ($date === '') continue; // fila vacía
                $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
                if ($d === false || $d->format('Y-m-d') !== $date) {
                    $errors[] = 'Excepción con fecha inválida: «' . $date . '».';
                    continue;
                }
                $closed = (string) ($ex['closed'] ?? '0') === '1';
                $start = $closed ? null : $this->cleanTime((string) ($ex['start'] ?? ''));
                $end   = $closed ? null : $this->cleanTime((string) ($ex['end'] ?? ''));
                if (!$closed && ($start === null || $end === null || $start >= $end)) {
                    $errors[] = 'La excepción del ' . $date . ' necesita una franja válida (o márcala como cerrado).';
                    continue;
                }
                $key = $date . '|' . ($start ?? 'closed');
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $exceptions[] = ['date' => $date, 'closed' => $closed, 'start' => $start, 'end' => $end];
            }
        }

        return [$hours, $exceptions, $errors];
    }

    /** Normaliza "H:MM"/"HH:MM" → "HH:MM"; null si vacío o inválido. */
    private function cleanTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    /** @param array<string,mixed> $service @param string[] $errors */
    private function renderEditor(array $service, array $errors): void
    {
        View::send('admin/booking/edit', [
            'service'  => $service,
            'weekdays' => self::WEEKDAYS,
            'errors'   => $errors,
            'notice'   => Session::flash('notice'),
            'csrf'     => CSRF::token(),
        ]);
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
