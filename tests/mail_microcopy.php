<?php

declare(strict_types=1);

/**
 * I18N-FULL T0.3 — Emails transaccionales en el idioma del cliente.
 *
 * Dos reglas que gobiernan esta tarea:
 *
 * 1. **Los emails al CLIENTE van en el idioma del sitio** (y, cuando la fase 1
 *    añada `language` a pedidos y reservas, en el idioma con el que compró).
 * 2. **Los avisos al ADMINISTRADOR siguen en castellano.** Los recibe el dueño
 *    del sitio, que gestiona un panel en castellano; traducirlos no ayuda a
 *    nadie y multiplica la superficie de error.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\DateFormat;
use App\Services\Microcopy;

$failed = 0;
function checkMail(string $name, bool $ok, string $detail = ''): void
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
// 1. Diccionario de emails
// ---------------------------------------------------------------------------

$gaps = [];
foreach (Microcopy::MODULE_LANGUAGES as $code) {
    $missing = array_values(array_filter(
        Microcopy::missing($code),
        static fn (string $k): bool => str_starts_with($k, 'mail.')
    ));
    if ($missing !== []) {
        $gaps[] = $code . ': ' . implode(', ', array_slice($missing, 0, 8));
    }
}
checkMail('mail_keys_complete_in_declared_languages', $gaps === [], implode("\n", $gaps));

checkMail(
    'mail_subjects_are_translated',
    Microcopy::t('mail.shop.created_subject', 'fr', ['number' => 'PC-1']) === 'Commande reçue : PC-1'
        && str_contains(Microcopy::t('mail.booking.confirmed_subject', 'fr', ['service' => 'X', 'when' => 'Y']), 'confirmée'),
    Microcopy::t('mail.shop.created_subject', 'fr', ['number' => 'PC-1'])
);

checkMail(
    'greeting_interpolates_customer_name',
    Microcopy::t('mail.greeting', 'fr', ['name' => 'Marie']) === 'Bonjour Marie,'
        && Microcopy::t('mail.greeting', 'es', ['name' => 'Marta']) === 'Hola Marta,',
    Microcopy::t('mail.greeting', 'fr', ['name' => 'Marie'])
);

// ---------------------------------------------------------------------------
// 2. Fechas: el patrón castellano no vale para los demás idiomas
// ---------------------------------------------------------------------------

$when = new DateTimeImmutable('2026-07-29 10:00:00', new DateTimeZone('Europe/Madrid'));

// Regresión dura: en castellano el texto debe seguir siendo EXACTAMENTE el de
// antes («miércoles 29 de julio de 2026, 10:00»), patrón incluido.
checkMail(
    'spanish_date_format_is_unchanged',
    DateFormat::humanDateTime($when, 'es', 'Europe/Madrid') === 'miércoles 29 de julio de 2026, 10:00',
    DateFormat::humanDateTime($when, 'es', 'Europe/Madrid')
);

$fr = DateFormat::humanDateTime($when, 'fr', 'Europe/Madrid');
checkMail(
    'french_date_is_localised',
    str_contains($fr, 'mercredi') && str_contains($fr, 'juillet') && !str_contains($fr, ' de '),
    $fr
);

// El catalán y el gallego apostrofan («29 d'abril»): ahí NO inventamos patrón,
// se deja el de ICU, que lo resuelve bien por construcción.
$ca = DateFormat::humanDateTime(new DateTimeImmutable('2026-04-29 10:00:00'), 'ca', 'Europe/Madrid');
checkMail(
    'catalan_date_uses_icu_defaults',
    str_contains($ca, "d'abril") || str_contains($ca, '’abril'),
    $ca
);

// ---------------------------------------------------------------------------
// 3. Nada de castellano fijo en los emails al cliente
// ---------------------------------------------------------------------------

$customerStrings = [
    'app/Modules/Commerce/CommerceMailer.php' => [
        "'Pedido recibido: '", 'Te avisaremos por email cuando el pedido avance.',
        'Hemos recibido el pago de tu pedido. Lo estamos preparando.',
        'Tu pedido está en camino.',
    ],
    'app/Modules/Booking/BookingMailer.php' => [
        "'Reserva confirmada: '", 'Tu reserva está confirmada. Aquí tienes los detalles:',
        'Si necesitas cancelarla, puedes hacerlo aquí:',
        'Reserva a nombre de ',
    ],
];
$leftovers = [];
foreach ($customerStrings as $file => $needles) {
    $src = (string) file_get_contents(PP_ROOT . '/' . $file);
    foreach ($needles as $needle) {
        if (str_contains($src, $needle)) {
            $leftovers[] = $file . ' → ' . $needle;
        }
    }
}
checkMail('customer_emails_have_no_hardcoded_spanish', $leftovers === [], implode("\n", $leftovers));

// ---------------------------------------------------------------------------
// 4. Los avisos al ADMIN se quedan en castellano (decisión, no olvido)
// ---------------------------------------------------------------------------

$commerceSrc = (string) file_get_contents(PP_ROOT . '/app/Modules/Commerce/CommerceMailer.php');
$bookingSrc  = (string) file_get_contents(PP_ROOT . '/app/Modules/Booking/BookingMailer.php');
// ADMIN-I18N (11/08/2026): esto era justo al revés. Los avisos al admin
// estaban fijos en castellano porque el panel lo estaba; ahora el panel se
// traduce, así que el aviso sale en el idioma de quien gestiona el sitio.
checkMail(
    'admin_notices_use_the_panel_language',
    str_contains($commerceSrc, "__('order.mail.admin_new_body'")
        && str_contains($bookingSrc, "__('bk.mail.admin_new_body'"),
    'Los avisos al administrador salen por el catálogo del panel, no fijos'
);

// Lo que sigue valiendo: el aviso va entero en UN idioma, también la fecha.
checkMail(
    'admin_notice_date_matches_the_notice',
    str_contains($bookingSrc, 'self::humanWhen($booking, $tz, \App\Services\AdminI18n::locale())'),
    'Un aviso en un idioma con la fecha en otro queda a medio camino'
);

// El idioma tiene que resolverse por una única función, preparada para que la
// fase 1 (T1.2) lo lea del pedido/reserva sin tocar los mailers otra vez.
checkMail(
    'language_resolution_is_forward_compatible',
    str_contains($commerceSrc, "\$order['language']") && str_contains($bookingSrc, "\$booking['language']"),
    'El mailer debe preferir el idioma guardado en el pedido/reserva si existe'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
