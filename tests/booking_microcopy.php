<?php

declare(strict_types=1);

/**
 * I18N-FULL T0.2 — El flujo público de reservas habla el idioma del sitio.
 *
 * Particularidad frente a la tienda: el widget es un JS ESTÁTICO que también
 * se embebe en webs externas, así que no puede leer `sites.language`. El
 * idioma y los textos viajan desde la API (`GET /api/booking/v1/services`) y
 * el JS solo conserva un fallback castellano para lo que pinta ANTES de que
 * llegue esa respuesta.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Modules\Booking\BookingApiController;
use App\Services\Microcopy;

$failed = 0;
function checkBookingCopy(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
        }
    }
}

// ---------------------------------------------------------------------------
// 1. Diccionario
// ---------------------------------------------------------------------------

$gaps = [];
foreach (Microcopy::MODULE_LANGUAGES as $code) {
    $missing = array_values(array_filter(
        Microcopy::missing($code),
        static fn (string $k): bool => str_starts_with($k, 'booking.')
    ));
    if ($missing !== []) {
        $gaps[] = $code . ': ' . implode(', ', array_slice($missing, 0, 8));
    }
}
checkBookingCopy('booking_keys_complete_in_declared_languages', $gaps === [], implode("\n", $gaps));

checkBookingCopy(
    'booking_translations_are_real',
    Microcopy::t('booking.ph_name', 'fr') === 'Votre nom *'
        && Microcopy::t('booking.slot_taken', 'fr') !== Microcopy::t('booking.slot_taken', 'es'),
    Microcopy::t('booking.ph_name', 'fr')
);

checkBookingCopy(
    'booking_placeholders_interpolate',
    Microcopy::t('booking.book_at', 'fr', ['time' => '10:30']) === 'Réserver à 10:30'
        && Microcopy::t('booking.slots_many', 'es', ['n' => 4]) === '4 huecos',
    Microcopy::t('booking.book_at', 'fr', ['time' => '10:30'])
);

// ---------------------------------------------------------------------------
// 2. La API entrega idioma + textos al widget
// ---------------------------------------------------------------------------

$texts = BookingApiController::widgetTexts('fr');
checkBookingCopy(
    'api_exposes_widget_texts',
    is_array($texts) && ($texts['ph_name'] ?? '') === 'Votre nom *' && ($texts['no_slots'] ?? '') !== '',
    json_encode(array_slice($texts, 0, 4), JSON_UNESCAPED_UNICODE)
);

// Los textos del widget se interpolan EN EL NAVEGADOR: los {token} tienen que
// llegar intactos. Con Microcopy::t() llegaban ya limpios y «{n} créneaux» se
// servía como «créneaux» (bug real detectado al probar la API de verdad).
checkBookingCopy(
    'widget_texts_keep_their_placeholders',
    str_contains($texts['slots_many'] ?? '', '{n}')
        && str_contains($texts['book_at'] ?? '', '{time}')
        && str_contains($texts['local_time'] ?? '', '{tz}'),
    'slots_many=' . ($texts['slots_many'] ?? '') . ' · book_at=' . ($texts['book_at'] ?? '')
);

// Todas las claves que el JS pide tienen que existir en el paquete: si falta
// una, el widget se queda con el fallback castellano sin avisar.
$js = (string) file_get_contents(PP_ROOT . '/public/js/pp-booking-widget.js');
preg_match_all("/T\('([a-z_]+)'/", $js, $m);
$requested = array_unique($m[1] ?? []);
$notServed = array_values(array_diff($requested, array_keys($texts)));
checkBookingCopy(
    'every_text_the_widget_asks_for_is_served',
    $requested !== [] && $notServed === [],
    'pedidas: ' . count($requested) . ' · sin servir: ' . implode(', ', $notServed)
);

// ---------------------------------------------------------------------------
// 3. Nada de castellano fijo en el flujo público
// ---------------------------------------------------------------------------

$hardcoded = [
    'app/Modules/Booking/BookingService.php' => [
        'El nombre es obligatorio.', 'Necesitamos un email válido para confirmar la reserva.',
    ],
    'app/Modules/Booking/BookingApiController.php' => [
        'Reserva confirmada. Te hemos enviado un email con los detalles.',
        'Reserva recibida, pendiente de confirmación. Te avisaremos por email.',
    ],
    'app/Modules/Booking/BookingCancelController.php' => [
        'Reserva ya cancelada', '¿Seguro que quieres cancelar esta reserva?',
        'Sí, cancelar la reserva', 'Tu reserva ha quedado cancelada. Gracias por avisar.',
        '<html lang="es">',
    ],
];
$leftovers = [];
foreach ($hardcoded as $file => $needles) {
    $src = (string) file_get_contents(PP_ROOT . '/' . $file);
    foreach ($needles as $needle) {
        if (str_contains($src, $needle)) {
            $leftovers[] = $file . ' → ' . $needle;
        }
    }
}
checkBookingCopy('booking_flow_has_no_hardcoded_spanish', $leftovers === [], implode("\n", $leftovers));

// ---------------------------------------------------------------------------
// 4. El widget: fallback mínimo y fechas por locale
// ---------------------------------------------------------------------------

// Los días de la semana estaban cableados en castellano ('mié', 'sáb'): eso lo
// resuelve el navegador con el locale que le pasemos.
checkBookingCopy(
    'weekdays_come_from_locale_not_hardcoded',
    !str_contains($js, "'mié'") && !str_contains($js, "'sáb'")
        && str_contains($js, 'toLocaleDateString'),
    'fmtDay debe usar toLocaleDateString con el idioma del sitio'
);

// Solo pueden quedar en castellano los textos anteriores a la primera
// respuesta de la API (no hay forma de conocer el idioma todavía).
$preflightOnly = ['Cargando disponibilidad…', 'No se pudo conectar con el sistema de reservas.'];
preg_match_all("/'([^'\\\\]{12,90})'/", $js, $sm);
$spanishish = array_values(array_filter(
    array_unique($sm[1] ?? []),
    static fn (string $s): bool => (bool) preg_match('/[áéíóúñ¿¡]|\b(de|la|el|no|se|tu|los)\b/iu', $s)
        && !in_array($s, $preflightOnly, true)
        && !str_contains($s, 'font-family')
));
checkBookingCopy(
    'widget_keeps_only_preflight_spanish',
    $spanishish === [],
    'castellano fijo fuera del arranque: ' . implode(' | ', $spanishish)
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
