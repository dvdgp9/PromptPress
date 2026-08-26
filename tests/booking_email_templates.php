<?php

declare(strict_types=1);

/**
 * MODULOS M9 — Plantillas de email por servicio.
 *
 * Lo importante:
 *   1. Sin nada reescrito, el cliente recibe EXACTAMENTE lo de siempre, en su
 *      idioma. Un módulo de reservas que cambia sus emails al actualizar sería
 *      una sorpresa desagradable para quien ya lo tenía funcionando.
 *   2. Lo reescrito manda, y los tokens se sustituyen de verdad.
 *   3. Una plantilla a medida no deja huecos raros cuando un token viene vacío.
 *   4. Guardar sin tocar nada NO deja basura en la BD.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingEmails;
use App\Modules\Booking\ServiceStore;
use App\Services\Microcopy;
use Core\Database;

$failed = 0;
function check_bt(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_bt('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$vars = [
    'cliente'    => 'Ana Ruiz',
    'servicio'   => 'Consulta inicial',
    'fecha'      => 'lunes 17 de agosto, 10:00',
    'precio'     => '30 €',
    'sitio'      => 'Mi negocio',
    'detalles'   => "• Servicio: Consulta inicial\n• Fecha y hora: lunes 17 de agosto, 10:00",
    'cancelar'   => 'https://ejemplo.com/_booking/cancel/1?token=abc',
    'respuestas' => 'Matrícula: 1234ABC',
];

// ---------------------------------------------------------------------------
// 1. Sin reescribir: lo de siempre
// ---------------------------------------------------------------------------

$r = BookingEmails::render([], 'received', 'es', $vars);
check_bt('asunto por defecto', $r['subject'] === 'Hemos recibido tu reserva: Consulta inicial — lunes 17 de agosto, 10:00', $r['subject']);
check_bt('el cuerpo por defecto saluda, explica, detalla y ofrece cancelar',
    str_starts_with($r['body'], 'Hola Ana Ruiz,')
    && str_contains($r['body'], Microcopy::template('mail.booking.received_intro', 'es'))
    && str_contains($r['body'], '• Servicio: Consulta inicial')
    && str_contains($r['body'], 'https://ejemplo.com/_booking/cancel/1?token=abc')
    && str_ends_with($r['body'], 'Mi negocio'),
    $r['body']);
check_bt('no queda ningún token sin sustituir', !str_contains($r['body'], '{') && !str_contains($r['subject'], '{'), $r['body']);

$fr = BookingEmails::render([], 'confirmed', 'fr', $vars);
check_bt('el mensaje por defecto sigue traducido',
    str_starts_with($fr['body'], 'Bonjour Ana Ruiz,') && str_contains($fr['subject'], 'Réservation confirmée'),
    $fr['subject'] . ' / ' . mb_substr($fr['body'], 0, 60));

$cancel = BookingEmails::render([], 'cancelled', 'es', $vars);
check_bt('el de cancelación no ofrece un enlace de cancelar',
    !str_contains($cancel['body'], 'https://ejemplo.com/_booking/cancel'), $cancel['body']);

// La plantilla que se enseña en el editor lleva los tokens VISIBLES: si se
// limpiaran (como hace `Microcopy::t`), el gestor vería un texto mutilado.
$tpl = BookingEmails::defaultTemplate('received', 'es');
check_bt('la plantilla del editor conserva sus tokens',
    str_contains($tpl['body'], '{cliente}') && str_contains($tpl['body'], '{detalles}')
    && str_contains($tpl['body'], '{cancelar}') && str_contains($tpl['subject'], '{servicio}'),
    $tpl['body']);

// ---------------------------------------------------------------------------
// 2. Reescrito: manda lo escrito
// ---------------------------------------------------------------------------

$service = ['emails_json' => json_encode([
    'received' => [
        'subject' => 'Tu cita en el taller: {fecha}',
        'body'    => "Hola {cliente},\n\nTe esperamos para {servicio} el {fecha}.\n\n{respuestas}\n\nAnular: {cancelar}\n\n{sitio}",
    ],
], JSON_UNESCAPED_UNICODE)];

$r = BookingEmails::render($service, 'received', 'es', $vars);
check_bt('el asunto a medida manda', $r['subject'] === 'Tu cita en el taller: lunes 17 de agosto, 10:00', $r['subject']);
check_bt('el cuerpo a medida manda y sustituye tokens',
    str_contains($r['body'], 'Te esperamos para Consulta inicial el lunes 17 de agosto, 10:00')
    && str_contains($r['body'], 'Matrícula: 1234ABC')
    && str_contains($r['body'], 'Anular: https://ejemplo.com/_booking/cancel/1?token=abc'),
    $r['body']);

// Reescribir uno NO toca a los otros dos.
$otro = BookingEmails::render($service, 'confirmed', 'es', $vars);
check_bt('los tipos no reescritos siguen con su plantilla',
    str_contains($otro['subject'], 'Reserva confirmada'), $otro['subject']);

// Un token vacío no debe dejar un agujero en el texto.
$sinRespuestas = $vars;
$sinRespuestas['respuestas'] = '';
$r = BookingEmails::render($service, 'received', 'es', $sinRespuestas);
check_bt('un token vacío no deja líneas en blanco de más',
    !str_contains($r['body'], "\n\n\n"), json_encode($r['body']));

// ---------------------------------------------------------------------------
// 3. Saneado
// ---------------------------------------------------------------------------

$norm = BookingEmails::normalize([
    'received' => ['subject' => '  <b>Hola</b>  ', 'body' => "  texto  "],
    'inventado' => ['subject' => 'no existe'],
]);
check_bt('se limpia el HTML del asunto', $norm['received']['subject'] === 'Hola', $norm['received']['subject']);
check_bt('solo se guardan los tres tipos conocidos',
    array_keys($norm) === BookingEmails::TYPES, json_encode(array_keys($norm)));
check_bt('una configuración en blanco se detecta como vacía', BookingEmails::isEmpty(BookingEmails::normalize([])));
check_bt('una con contenido no', !BookingEmails::isEmpty($norm));
check_bt('un emails_json corrupto cae a las plantillas por defecto',
    BookingEmails::render(['emails_json' => '{roto'], 'received', 'es', $vars)['subject']
    === BookingEmails::render([], 'received', 'es', $vars)['subject']);

// ---------------------------------------------------------------------------
// 4. Ida y vuelta por la BD
// ---------------------------------------------------------------------------

$svcId = ServiceStore::create($siteId, ['name' => 'zzz Test emails']);
ServiceStore::update($siteId, $svcId, ['name' => 'zzz Test emails', 'emails' => [
    'received' => ['subject' => '', 'body' => ''],
    'confirmed' => ['subject' => '', 'body' => ''],
    'cancelled' => ['subject' => '', 'body' => ''],
]], [], []);
$row = Database::selectOne('SELECT emails_json FROM booking_services WHERE id = ?', [$svcId]);
check_bt('guardar sin escribir nada NO deja basura en la BD', $row['emails_json'] === null, json_encode($row));

ServiceStore::update($siteId, $svcId, ['name' => 'zzz Test emails', 'emails' => [
    'confirmed' => ['subject' => 'Nos vemos el {fecha}', 'body' => 'Hola {cliente}. {cancelar}'],
]], [], []);
$saved = ServiceStore::find($siteId, $svcId);
$r = BookingEmails::render($saved, 'confirmed', 'es', $vars);
check_bt('lo reescrito sobrevive al guardado', $r['subject'] === 'Nos vemos el lunes 17 de agosto, 10:00', $r['subject']);

ServiceStore::delete($siteId, $svcId);
check_bt('limpieza: servicio de prueba borrado', ServiceStore::find($siteId, $svcId) === null);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
