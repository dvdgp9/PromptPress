<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\ModuleRegistry;
use App\Services\FormSubmissionService;
use Core\App;
use Core\Crypto;
use Core\Database;
use Core\Request;
use Core\Response;
use DateTimeImmutable;

/**
 * BookingApiController — API pública JSON del módulo Booking (B4).
 *
 * Contrato en cursor/booking-design.md §6. Stateless: sin sesión ni CSRF
 * (mismo criterio que /_analytics/collect). El sitio es el público (primer
 * site), resuelto por el guard del módulo.
 *
 * Origen:
 *   - Same-origin (páginas del propio sitio / widget interno): sin clave.
 *   - Cross-origin (widget externo, B6): header X-Booking-Key con la API key
 *     del sitio (settings.booking_api_key, cifrada) y Origin dentro de
 *     settings.booking_allowed_origins → se emiten headers CORS al origin
 *     concreto. Sin clave válida, la petición cross-origin recibe 403.
 */
final class BookingApiController
{
    private const MAX_RANGE_DAYS = 31;

    /** GET /api/booking/v1/services */
    public function services(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            Response::json(['error' => 'origin_not_allowed'], 403);
        }
        $rows = Database::select(
            'SELECT id, name, description, duration_min, capacity, price_label
               FROM booking_services WHERE site_id = ? AND active = 1 ORDER BY name',
            [$siteId]
        );
        Response::json([
            'timezone' => BookingService::siteTimezone($siteId),
            'services' => array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['name'],
                'description'  => (string) ($r['description'] ?? ''),
                'duration_min' => (int) $r['duration_min'],
                'capacity'     => (int) $r['capacity'],
                'price_label'  => $r['price_label'] !== null ? (string) $r['price_label'] : null,
            ], $rows),
        ]);
    }

    /** GET /api/booking/v1/services/{id}/availability?from=Y-m-d&to=Y-m-d */
    public function availability(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            Response::json(['error' => 'origin_not_allowed'], 403);
        }
        $serviceId = (int) ($params['id'] ?? 0);
        $from = self::validDate((string) Request::get('from', ''));
        $to   = self::validDate((string) Request::get('to', ''));
        if ($from === null || $to === null || $from > $to) {
            Response::json(['error' => 'validation', 'detail' => 'from/to deben ser fechas Y-m-d con from <= to'], 422);
        }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS) {
            Response::json(['error' => 'validation', 'detail' => 'rango máximo ' . self::MAX_RANGE_DAYS . ' días'], 422);
        }

        $timezone = BookingService::siteTimezone($siteId);
        $result = AvailabilityEngine::forService($siteId, $serviceId, $from, $to, $timezone);
        if ($result['service'] === null) {
            Response::json(['error' => 'not_found'], 404);
        }
        Response::json([
            'service_id' => $serviceId,
            'timezone'   => $timezone,
            'days'       => $result['days'],
        ]);
    }

    /** POST /api/booking/v1/bookings */
    public function create(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            Response::json(['error' => 'origin_not_allowed'], 403);
        }
        $data = Request::isJson() ? Request::json() : Request::all();

        // Honeypot (mismo criterio que formularios): responder ok sin crear nada.
        if (trim((string) ($data['company_url'] ?? '')) !== '') {
            Response::json(['id' => 0, 'status' => 'pending', 'message' => 'Te hemos enviado un email con los detalles.'], 201);
        }

        $ipHash = FormSubmissionService::ipHash(Request::ip());
        $result = BookingService::create($siteId, is_array($data) ? $data : [], $ipHash);

        if (!$result['ok']) {
            switch ($result['error'] ?? '') {
                case 'validation':
                    Response::json(['error' => 'validation', 'fields' => $result['fields'] ?? []], 422);
                case 'rate_limited':
                    Response::json(['error' => 'rate_limited'], 429);
                case 'not_found':
                    Response::json(['error' => 'not_found'], 404);
                default:
                    Response::json(['error' => 'slot_unavailable'], 409);
            }
        }

        $booking = $result['booking'];

        // Emails de creación (cliente + aviso admin). Nunca rompen la reserva.
        try {
            BookingMailer::sendCreated($siteId, (int) $booking['id']);
        } catch (\Throwable) {
            // silencioso a propósito
        }

        // Conversión en Analytics (si el módulo está activo): jamás rompe la reserva.
        try {
            if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
                \App\Modules\Analytics\EventRecorder::record(
                    $siteId, 'booking_created', '/api/booking', null, Request::ip(), Request::userAgent()
                );
            }
        } catch (\Throwable) {
            // silencioso a propósito
        }

        $status = (string) $booking['status'];
        Response::json([
            'id'      => (int) $booking['id'],
            'status'  => $status,
            'service' => $booking['service'],
            'start'   => $booking['start'],
            'end'     => $booking['end'],
            'cancel_token' => $booking['cancel_token'],
            'message' => $status === 'confirmed'
                ? 'Reserva confirmada. Te hemos enviado un email con los detalles.'
                : 'Reserva recibida, pendiente de confirmación. Te avisaremos por email.',
        ], 201);
    }

    /** POST /api/booking/v1/bookings/{id}/cancel  { token } */
    public function cancel(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            Response::json(['error' => 'origin_not_allowed'], 403);
        }
        $data = Request::isJson() ? Request::json() : Request::all();
        $bookingId = (int) ($params['id'] ?? 0);
        $result = BookingService::cancelWithToken($siteId, $bookingId, trim((string) ($data['token'] ?? '')));
        if (!$result['ok']) {
            Response::json(['error' => 'not_found'], 404);
        }
        try {
            BookingMailer::notifyCustomerCancelled($siteId, $bookingId);
        } catch (\Throwable) {
            // silencioso a propósito
        }
        Response::json(['status' => 'cancelled']);
    }

    /** OPTIONS /api/booking/v1/* — preflight CORS del widget externo. */
    public function preflight(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId, true)) {
            Response::json(['error' => 'origin_not_allowed'], 403);
        }
        Response::noContent();
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function siteId(): int
    {
        $siteId = ModuleRegistry::resolveSiteId();
        if ($siteId === null) {
            Response::json(['error' => 'not_found'], 404);
        }
        return $siteId;
    }

    /**
     * Política de origen. Devuelve true si la petición puede continuar:
     *   - Sin header Origin (curl, server-to-server) o mismo host → permitida
     *     sin headers CORS.
     *   - Cross-origin → exige API key válida + origin en la allowlist, y
     *     entonces emite los headers CORS. Si no, false (→ 403).
     */
    private static function cors(int $siteId, bool $isPreflight = false): bool
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $originHost = (string) (parse_url($origin, PHP_URL_HOST) ?? '');
        $originPort = parse_url($origin, PHP_URL_PORT);
        if ($originHost !== '' && ($originHost . ($originPort !== null ? ':' . $originPort : '')) === $host) {
            return true; // same-origin con header Origin (fetch moderno lo manda)
        }

        // El preflight no lleva headers custom: valida solo la allowlist de
        // orígenes; la key se exige en la petición real.
        if (!self::originAllowed($siteId, $origin)) {
            return false;
        }
        if (!$isPreflight && !self::validApiKey($siteId)) {
            return false;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Booking-Key');
        header('Access-Control-Max-Age: 3600');
        return true;
    }

    private static function originAllowed(int $siteId, string $origin): bool
    {
        $row = Database::selectOne(
            "SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = 'booking_allowed_origins' LIMIT 1",
            [$siteId]
        );
        $raw = (string) ($row['setting_value'] ?? '');
        if ($raw === '') {
            return false;
        }
        $allowed = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return in_array(rtrim($origin, '/'), array_map(static fn (string $o): string => rtrim($o, '/'), $allowed), true);
    }

    private static function validApiKey(int $siteId): bool
    {
        $provided = trim((string) ($_SERVER['HTTP_X_BOOKING_KEY'] ?? Request::get('key', '')));
        if ($provided === '') {
            return false;
        }
        $row = Database::selectOne(
            "SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = 'booking_api_key' LIMIT 1",
            [$siteId]
        );
        $stored = (string) ($row['setting_value'] ?? '');
        if ($stored === '') {
            return false;
        }
        try {
            $appKey = (string) (App::config()['app_key'] ?? '');
            $real = $appKey !== '' ? Crypto::decrypt($stored, $appKey) : '';
        } catch (\Throwable) {
            return false;
        }
        return $real !== '' && hash_equals($real, $provided);
    }

    private static function validDate(string $raw): ?string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        return ($d !== false && $d->format('Y-m-d') === $raw) ? $raw : null;
    }
}
