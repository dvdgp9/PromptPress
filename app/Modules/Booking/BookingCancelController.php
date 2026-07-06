<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\ModuleRegistry;
use Core\Database;
use Core\Request;
use Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BookingCancelController — página pública del link de cancelación (B5).
 *
 * El email lleva un link GET (un email no puede hacer POST):
 *   GET  /_booking/cancel/{id}?token=…  → página de confirmación
 *   POST /_booking/cancel/{id}          → cancela (token en el form) y avisa al admin
 *
 * HTML mínimo autónomo (sin el design system del sitio: debe funcionar aunque
 * el sitio no tenga páginas publicadas).
 */
final class BookingCancelController
{
    public function show(array $params = []): void
    {
        [$booking, $service, $tz] = $this->load($params);
        $when = $this->when($booking, $tz);

        if ((string) $booking['status'] === 'cancelled') {
            $this->page('Reserva ya cancelada', '<p>Esta reserva ya estaba cancelada. No tienes que hacer nada más.</p>');
        }

        $this->page('Cancelar reserva', sprintf(
            '<p>¿Seguro que quieres cancelar esta reserva?</p>
             <p class="detail"><strong>%s</strong><br>%s</p>
             <form method="post" action="%s">
                 <input type="hidden" name="token" value="%s">
                 <button type="submit">Sí, cancelar la reserva</button>
             </form>
             <p class="soft">Si has llegado aquí por error, simplemente cierra esta página.</p>',
            e((string) $service['name']),
            e($when),
            e(base_url('_booking/cancel/' . (int) $booking['id'])),
            e((string) Request::get('token', ''))
        ));
    }

    public function cancel(array $params = []): void
    {
        [$booking] = $this->load($params, (string) Request::post('token', ''));
        $siteId = (int) $booking['site_id'];
        $result = BookingService::cancelWithToken($siteId, (int) $booking['id'], (string) Request::post('token', ''));
        if (!$result['ok']) {
            Response::notFound();
        }
        try {
            BookingMailer::notifyCustomerCancelled($siteId, (int) $booking['id']);
        } catch (\Throwable) {
            // el aviso nunca rompe la cancelación
        }
        $this->page('Reserva cancelada', '<p>Tu reserva ha quedado cancelada. Gracias por avisar.</p><p class="soft">Si quieres buscar otro hueco, puedes reservar de nuevo cuando quieras.</p>');
    }

    /** Carga y valida reserva+token (404 si no casan). @return array{0:array,1:array,2:string} */
    private function load(array $params, ?string $tokenOverride = null): array
    {
        $siteId = ModuleRegistry::resolveSiteId();
        $id = (int) ($params['id'] ?? 0);
        $token = $tokenOverride ?? trim((string) Request::get('token', ''));
        if ($siteId === null || $id <= 0 || $token === '' || strlen($token) > 64) {
            Response::notFound();
        }
        $booking = Database::selectOne(
            'SELECT * FROM booking_bookings WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        );
        if ($booking === null || !hash_equals((string) $booking['cancel_token'], $token)) {
            Response::notFound();
        }
        $service = Database::selectOne('SELECT name FROM booking_services WHERE id = ? LIMIT 1', [(int) $booking['service_id']]) ?? ['name' => 'Reserva'];
        $tz = BookingService::siteTimezone($siteId);
        return [$booking, $service, $tz];
    }

    private function when(array $booking, string $tz): string
    {
        return (new DateTimeImmutable((string) $booking['starts_at_utc'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))->format('d/m/Y H:i');
    }

    private function page(string $title, string $bodyHtml): never
    {
        Response::html(
            '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . e($title) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f7f6f3;color:#1f2937;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}'
            . '.card{background:#fff;border:1px solid #e5e2dc;border-radius:14px;padding:32px;max-width:420px;width:100%;box-shadow:0 8px 24px -12px rgba(0,0,0,.15)}'
            . 'h1{font-size:1.25rem;margin:0 0 12px}p{line-height:1.55;margin:0 0 14px}.detail{background:#f7f6f3;border-radius:10px;padding:12px 14px}'
            . '.soft{color:#6b7280;font-size:.88rem}button{background:#c2410c;color:#fff;border:0;border-radius:10px;padding:11px 18px;font-size:1rem;cursor:pointer;width:100%}'
            . 'button:hover{background:#9a3412}</style></head>'
            . '<body><div class="card"><h1>' . e($title) . '</h1>' . $bodyHtml . '</div></body></html>'
        );
    }
}
