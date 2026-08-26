<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\ModuleRegistry;
use App\Services\FormSubmissionService;
use App\Services\LanguageService;
use App\Services\Microcopy;
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

    /** Motivo del último rechazo de `cors()`, para poder decir cuál de los dos fue. */
    private static ?string $corsDenied = null;

    /** GET /api/booking/v1/services */
    public function services(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            self::denyCors();
        }
        $rows = Database::select(
            'SELECT id, name, description, duration_min, capacity, price_label, language, fields_json
               FROM booking_services WHERE site_id = ? AND active = 1 ORDER BY name',
            [$siteId]
        );
        // El widget es un JS estático (también embebible fuera de este sitio):
        // no puede conocer el idioma, así que idioma y textos viajan aquí.
        //
        // Precedencia, de más a menos específico:
        //   1. `?lang=` — lo manda el calendario incrustado en una página de
        //      PromptPress, que SÍ sabe en qué idioma se está leyendo. Es el que
        //      manda: un calendario en una página francesa habla francés aunque
        //      el servicio se creara en castellano.
        //   2. el idioma del servicio pedido (`?service=N`) — en una web
        //      multi-idioma cada idioma tiene SU servicio, y así lo aprovechan
        //      los embebidos en webs ajenas, que no pueden mandar `lang`.
        //   3. el idioma del sitio.
        $lang = LanguageService::codeFor($siteId);
        $wanted = (int) Request::get('service', 0);
        if ($wanted > 0) {
            foreach ($rows as $row) {
                if ((int) $row['id'] === $wanted) {
                    $lang = BookingService::serviceLanguage($siteId, $row);
                    break;
                }
            }
        }
        $asked = trim((string) Request::get('lang', ''));
        if ($asked !== '' && LanguageService::isSupported(strtolower($asked))) {
            $lang = LanguageService::normalize($asked);
        }
        Response::json([
            'timezone' => BookingService::siteTimezone($siteId),
            'lang'     => $lang,
            'texts'    => self::widgetTexts($lang),
            'services' => array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'name'         => (string) $r['name'],
                'description'  => (string) ($r['description'] ?? ''),
                'duration_min' => (int) $r['duration_min'],
                'capacity'     => (int) $r['capacity'],
                'price_label'  => $r['price_label'] !== null ? (string) $r['price_label'] : null,
                'language'     => BookingService::serviceLanguage($siteId, $r),
                // MODULOS M8 — qué se le pide al cliente en ESTE servicio. El
                // widget lo pinta tal cual; la validación de verdad es la del
                // servidor al crear la reserva.
                'fields'       => BookingFields::forWidget($r, $lang),
            ], $rows),
        ]);
    }

    /** GET /api/booking/v1/services/{id}/availability?from=Y-m-d&to=Y-m-d */
    public function availability(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            self::denyCors();
        }
        $serviceId = (int) ($params['id'] ?? 0);
        $from = self::validDate((string) Request::get('from', ''));
        $to   = self::validDate((string) Request::get('to', ''));
        if ($from === null || $to === null || $from > $to) {
            Response::json(['error' => 'validation', 'detail' => 'from/to deben ser fechas Y-m-d con from <= to'], 422);
        }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS) {
            // i18n-ignore: detalle técnico de la API pública del widget, no panel.
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
            // FEAT-4 AB5 — ancla del time-trap: el widget lo reenvía como
            // `_pp_ts` al crear la reserva.
            'bot_ts'     => \App\Services\Security\BotGuard::issueTimestamp(),
        ]);
    }

    /** POST /api/booking/v1/bookings */
    public function create(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            self::denyCors();
        }
        $data = Request::isJson() ? Request::json() : Request::all();

        // Honeypot (mismo criterio que formularios): responder ok sin crear nada.
        if (trim((string) ($data['company_url'] ?? '')) !== '') {
            Response::json(['id' => 0, 'status' => 'pending', 'message' => Microcopy::site($siteId, 'booking.email_sent')], 201);
        }

        // FEAT-4 AB5 — time-trap. Ausente o caducado → se acepta (el API
        // público lo usan integraciones directas que no pasan por el widget;
        // misma degradación confirmada que en formularios). Presente pero
        // demasiado rápido o manipulado → bot: 201 falso, sin crear nada.
        $ppTs = trim((string) ($data['_pp_ts'] ?? ''));
        if ($ppTs !== '') {
            $tsCheck = \App\Services\Security\BotGuard::verifyTimestamp($ppTs);
            if ($tsCheck === \App\Services\Security\BotGuard::TOO_FAST
                || $tsCheck === \App\Services\Security\BotGuard::INVALID) {
                Response::json(['id' => 0, 'status' => 'pending', 'message' => Microcopy::site($siteId, 'booking.email_sent')], 201);
            }
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
            'message' => Microcopy::t(
                $status === 'confirmed' ? 'booking.created_confirmed' : 'booking.created_pending',
                (string) ($booking['language'] ?? LanguageService::codeFor($siteId))
            ),
        ], 201);
    }

    /** POST /api/booking/v1/bookings/{id}/cancel  { token } */
    public function cancel(array $params = []): void
    {
        $siteId = self::siteId();
        if (!self::cors($siteId)) {
            self::denyCors();
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
            self::denyCors();
        }
        Response::noContent();
    }

    /**
     * Textos que el widget necesita, en el idioma dado.
     *
     * Las claves son las del widget SIN el prefijo `booking.`: el JS pide
     * `T('ph_name')`. `tests/booking_microcopy.php` verifica que todo lo que
     * el JS pide está aquí — si falta algo, el widget se queda en castellano
     * sin avisar.
     *
     * @return array<string,string>
     */
    public static function widgetTexts(string $lang): array
    {
        $keys = [
            'loading', 'no_slots', 'slots_one', 'slots_many',
            'ph_name', 'ph_email', 'ph_phone', 'ph_notes', 'book_at',
            'sent_title', 'registered', 'slot_taken', 'too_many', 'failed',
            'network', 'load_failed', 'service_unavailable', 'local_time',
        ];
        $out = [];
        foreach ($keys as $key) {
            // template() y no t(): el widget interpola {n}, {time} y {tz} en
            // el navegador, así que los tokens tienen que llegar intactos.
            $out[$key] = Microcopy::template('booking.' . $key, $lang);
        }
        return $out;
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
        self::$corsDenied = null;
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
            self::$corsDenied = 'origin_not_allowed';
            return false;
        }
        if (!$isPreflight && !self::validApiKey($siteId)) {
            // Se distingue de origin_not_allowed a propósito: con un solo error
            // para los dos casos, depurar un embed en una web ajena es adivinar.
            // No filtra nada: la lista de orígenes no es secreta y el atacante
            // ya controla su propio origen.
            self::$corsDenied = 'invalid_api_key';
            return false;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Booking-Key');
        header('Access-Control-Max-Age: 3600');
        return true;
    }

    /** Corta la petición cross-origin explicando cuál de los dos filtros falló. */
    private static function denyCors(): never
    {
        Response::json(['error' => self::$corsDenied ?? 'origin_not_allowed'], 403);
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
