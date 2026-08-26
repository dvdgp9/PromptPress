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
    public const WEEKDAYS = ['bk.day.mon', 'bk.day.tue', 'bk.day.wed', 'bk.day.thu', 'bk.day.fri', 'bk.day.sat', 'bk.day.sun'];

    /** Etiquetas de los días ya traducidas al idioma del panel. */
    private static function weekdayLabels(): array
    {
        return array_map(static fn (string $k): string => __($k), self::WEEKDAYS);
    }

    /** GET /admin/booking — listado de servicios + integración externa. */
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $services = ServiceStore::all($siteId);
        View::send('admin/booking/index', [
            'services'       => $services,
            'apiKey'         => $this->currentApiKey($siteId),
            'allowedOrigins' => $this->setting($siteId, 'booking_allowed_origins'),
            // Lo que de verdad importa de esta pantalla es la gestión: cuántas
            // reservas esperan respuesta se dice aquí, no solo en su listado.
            // Sin servicios no hay reservas posibles: se ahorra la consulta.
            'pendingCount'   => $services === [] ? 0 : $this->pendingCount($siteId),
            'mailReady'      => \App\Services\Mail\MailService::isConfigured($siteId),
            'notice'         => Session::flash('notice'),
            'error'          => Session::flash('error'),
            'csrf'           => CSRF::token(),
        ]);
    }

    /** Reservas futuras pendientes de confirmar. */
    private function pendingCount(int $siteId): int
    {
        return (int) (Database::selectOne(
            "SELECT COUNT(*) AS n FROM booking_bookings
              WHERE site_id = ? AND status = 'pending' AND starts_at_utc >= UTC_TIMESTAMP()",
            [$siteId]
        )['n'] ?? 0);
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
                Session::flash('error', __('bk.err.origin', ['origen' => $o]));
                Response::redirect(base_url('admin/booking'));
            }
            $clean[] = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        $this->saveSetting($siteId, 'booking_allowed_origins', implode("\n", array_unique($clean)), false);

        if ((string) Request::post('regenerate_key', '0') === '1' || $this->currentApiKey($siteId) === null) {
            $appKey = (string) \Core\App::config()['app_key'];
            $newKey = 'ppbk_' . bin2hex(random_bytes(20));
            $this->saveSetting($siteId, 'booking_api_key', \Core\Crypto::encrypt($newKey, $appKey), true);
            Session::flash('notice', __('bk.ok.key_generated'));
        } else {
            Session::flash('notice', __('bk.ok.integration_saved'));
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
            Session::flash('error', __('bk.err.name_required'));
            Response::redirect(base_url('admin/booking'));
        }
        $id = ServiceStore::create($siteId, ['name' => $name]);
        Session::flash('notice', __('bk.ok.service_created'));
        Response::redirect(base_url('admin/booking/services/' . $id));
    }

    /** GET /admin/booking/services/{id} — editor. */
    public function edit(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $service = ServiceStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($service === null) {
            Session::flash('error', __('bk.err.service_not_found'));
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
            Session::flash('error', __('bk.err.service_not_found'));
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
            // MODULOS M8 — qué se le pide al cliente. `BookingFields::normalize()`
            // sanea; aquí solo se pasan las opciones del desplegable de "a, b, c"
            // a lista, que es como se escriben en el editor.
            'fields'           => self::collectFields(),
            'emails'           => is_array(Request::post('emails', [])) ? Request::post('emails', []) : [],
        ];

        [$hours, $exceptions, $errors] = $this->collectSchedule();
        if (trim((string) $fields['name']) === '') {
            $errors[] = __('bk.err.name_empty');
        }

        if ($errors !== []) {
            // Repintar con lo enviado (sin persistir) para no perder la edición.
            $draft = array_merge($existing, $fields, ['id' => $id, 'hours' => $hours, 'exceptions' => $exceptions]);
            $this->renderEditor($draft, $errors);
            return;
        }

        ServiceStore::update($siteId, $id, $fields, $hours, $exceptions);
        Session::flash('notice', __('bk.ok.service_saved'));
        Response::redirect(base_url('admin/booking/services/' . $id));
    }

    /** POST /admin/booking/services/{id}/delete */
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        if (ServiceStore::delete($siteId, (int) ($params['id'] ?? 0))) {
            Session::flash('notice', __('bk.ok.service_deleted'));
        } else {
            Session::flash('error', __('bk.err.service_delete'));
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

        View::send('admin/booking/bookings', [
            'bookings'     => $rows,
            'services'     => ServiceStore::all($siteId),
            'timezone'     => BookingService::siteTimezone($siteId),
            'filters'      => ['status' => $status, 'service' => $service, 'scope' => $scope],
            'pendingCount' => $this->pendingCount($siteId),
            'mailReady'    => \App\Services\Mail\MailService::isConfigured($siteId),
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
            Session::flash('error', __('bk.err.bad_status'));
            Response::redirect($this->bookingsUrl());
        }
        $booking = Database::selectOne(
            'SELECT id, status FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        );
        if ($booking === null) {
            Session::flash('error', __('bk.err.booking_not_found'));
            Response::redirect($this->bookingsUrl());
        }
        if ((string) $booking['status'] === $to) {
            Session::flash('notice', __('bk.ok.already_status'));
            Response::redirect($this->bookingsUrl());
        }

        Database::execute(
            'UPDATE booking_bookings SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$to, $id]
        );
        $mail = 'failed';
        try {
            $mail = BookingMailer::sendStatusChange($siteId, $id, $to);
        } catch (\Throwable) {
            // el email nunca revierte el cambio de estado
        }
        // El aviso cuenta lo que ha pasado de verdad: prometer un email que no
        // se ha enviado (sitio sin SMTP) hace creer que el cliente ya lo sabe.
        $key = $to === 'confirmed' ? 'bk.ok.confirmed' : 'bk.ok.cancelled';
        if ($mail !== 'sent') {
            $key .= $mail === 'skipped' ? '_no_mail' : '_mail_failed';
        }
        Session::flash('notice', __($key));
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
                        $errors[] = __('bk.err.range_invalid', ['dia' => mb_strtolower(__(self::WEEKDAYS[$weekday]))]);
                        continue;
                    }
                    $clean[] = ['start' => $start, 'end' => $end];
                }
                usort($clean, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
                foreach ($clean as $i => $r) {
                    if ($i > 0 && $r['start'] < $clean[$i - 1]['end']) {
                        $errors[] = __('bk.err.range_overlap', ['dia' => mb_strtolower(__(self::WEEKDAYS[$weekday]))]);
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
                    $errors[] = __('bk.err.exc_date', ['fecha' => $date]);
                    continue;
                }
                $closed = (string) ($ex['closed'] ?? '0') === '1';
                $start = $closed ? null : $this->cleanTime((string) ($ex['start'] ?? ''));
                $end   = $closed ? null : $this->cleanTime((string) ($ex['end'] ?? ''));
                if (!$closed && ($start === null || $end === null || $start >= $end)) {
                    $errors[] = __('bk.err.exc_range', ['fecha' => $date]);
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
            'weekdays' => self::weekdayLabels(),
            // Definición efectiva de los campos del formulario de reserva: si el
            // servicio no tiene nada guardado, los de siempre.
            'fieldsDef' => BookingFields::forService($service),
            // Plantillas de email: lo reescrito por el gestor y, al lado, la de
            // por defecto para poder enseñarla como punto de partida.
            'emailsDef' => BookingEmails::forService($service),
            'emailDefaults' => array_combine(
                BookingEmails::TYPES,
                array_map(
                    static fn (string $t): array => BookingEmails::defaultTemplate(
                        $t,
                        BookingService::serviceLanguage(Auth::siteId() ?? 0, $service)
                    ),
                    BookingEmails::TYPES
                )
            ),
            // Si el sitio no puede enviar correo, todo esto no sirve de nada:
            // el editor lo dice y lleva a configurarlo.
            'mailReady' => \App\Services\Mail\MailService::isConfigured(Auth::siteId() ?? 0),
            'errors'   => $errors,
            'notice'   => Session::flash('notice'),
            'csrf'     => CSRF::token(),
        ]);
    }

    /**
     * Definición de los campos del formulario tal y como llega del editor.
     *
     * @return array<string,mixed>
     */
    private static function collectFields(): array
    {
        $raw = Request::post('fields', []);
        if (!is_array($raw)) {
            return BookingFields::defaults();
        }
        $custom = [];
        foreach ((array) ($raw['custom'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            // El editor pide las opciones en una línea separadas por comas: es
            // mucho más rápido de escribir que un repetidor de opciones.
            if (isset($field['options_raw'])) {
                $field['options'] = preg_split('/\s*,\s*/u', trim((string) $field['options_raw']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                unset($field['options_raw']);
            }
            $custom[] = $field;
        }
        $raw['custom'] = $custom;
        return $raw;
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('bk.err.no_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
