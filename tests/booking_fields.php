<?php

declare(strict_types=1);

/**
 * MODULOS M8 — Campos configurables del formulario de reserva.
 *
 * Lo que se comprueba, por orden de importancia:
 *   1. Que la VALIDACIÓN DEL SERVIDOR mande. El widget pinta lo que le dicen,
 *      pero quien decide es el servidor contra la definición guardada: sin esto,
 *      cualquiera puede saltarse un campo obligatorio con un POST a mano.
 *   2. Que nombre y email sigan siendo intocables.
 *   3. Que una definición rara (campo sin etiqueta, desplegable sin opciones,
 *      clave repetida, tipo inventado) no rompa nada.
 *   4. Que las reservas ya guardadas se sigan leyendo si el campo desaparece.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingFields;
use App\Modules\Booking\ServiceStore;
use Core\Database;

$failed = 0;
function check_bf(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_bf('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

// ---------------------------------------------------------------------------
// 1. Valores por defecto: el formulario de siempre
// ---------------------------------------------------------------------------

$def = BookingFields::forService([]);
check_bf('sin configurar: teléfono y notas se piden y no son obligatorios',
    $def['phone']['enabled'] && !$def['phone']['required']
    && $def['notes']['enabled'] && !$def['notes']['required'] && $def['custom'] === []);

$widget = BookingFields::forWidget([], 'es');
$keys = array_column($widget, 'key');
check_bf('sin configurar: el widget pide los cuatro de siempre',
    $keys === ['name', 'email', 'phone', 'notes'], json_encode($keys));
check_bf('nombre y email van marcados como fijos',
    ($widget[0]['core'] ?? false) && ($widget[1]['core'] ?? false));

// ---------------------------------------------------------------------------
// 2. Normalización de definiciones sucias
// ---------------------------------------------------------------------------

$dirty = BookingFields::normalize([
    'phone' => ['enabled' => '0', 'required' => '1'],   // oculto Y obligatorio: imposible
    'notes' => ['enabled' => '1', 'required' => '1', 'label' => '  Cuéntanos  '],
    'custom' => [
        ['label' => '', 'type' => 'text'],                                  // sin etiqueta: fuera
        ['label' => 'Matrícula', 'type' => 'inventado'],                    // tipo inválido → texto
        ['label' => 'Personas', 'type' => 'select', 'options' => []],       // sin opciones → texto
        ['label' => 'Turno', 'type' => 'select', 'options' => ['Mañana', 'Tarde', 'Mañana']],
        ['label' => 'Matrícula', 'type' => 'text'],                         // etiqueta repetida
        ['label' => 'Email', 'type' => 'email'],                            // clave reservada
    ],
]);
check_bf('un campo oculto no puede ser obligatorio', !$dirty['phone']['required']);
check_bf('la etiqueta se recorta', $dirty['notes']['label'] === 'Cuéntanos', $dirty['notes']['label']);
check_bf('el campo sin etiqueta se descarta', count($dirty['custom']) === 5, json_encode(array_column($dirty['custom'], 'label')));
check_bf('el tipo inventado cae a texto', $dirty['custom'][0]['type'] === 'text');
check_bf('el desplegable sin opciones cae a texto', $dirty['custom'][1]['type'] === 'text');
check_bf('las opciones se deduplican', $dirty['custom'][2]['options'] === ['Mañana', 'Tarde'], json_encode($dirty['custom'][2]['options'] ?? null));
$claves = array_column($dirty['custom'], 'key');
check_bf('las claves no se repiten', count($claves) === count(array_unique($claves)), json_encode($claves));
check_bf('una clave reservada no pisa a los campos fijos',
    !in_array('email', $claves, true) && !in_array('name', $claves, true), json_encode($claves));

$muchos = BookingFields::normalize(['custom' => array_map(
    static fn (int $i): array => ['label' => 'Campo ' . $i, 'type' => 'text'],
    range(1, BookingFields::MAX_CUSTOM + 5)
)]);
check_bf('se acota el número de campos', count($muchos['custom']) === BookingFields::MAX_CUSTOM);

// ---------------------------------------------------------------------------
// 3. Validación (la que cuenta: servidor)
// ---------------------------------------------------------------------------

$service = ['fields_json' => json_encode([
    'phone' => ['enabled' => true, 'required' => true, 'label' => ''],
    'notes' => ['enabled' => false, 'required' => false, 'label' => ''],
    'custom' => [
        ['key' => 'matricula', 'label' => 'Matrícula', 'type' => 'text', 'required' => true],
        ['key' => 'personas', 'label' => 'Personas', 'type' => 'select', 'required' => true, 'options' => ['1', '2', '3']],
        ['key' => 'condiciones', 'label' => 'Acepto las condiciones', 'type' => 'checkbox', 'required' => true],
        ['key' => 'edad', 'label' => 'Edad', 'type' => 'number', 'required' => false],
        ['key' => 'cuando', 'label' => 'Fecha de la ITV', 'type' => 'date', 'required' => false],
    ],
], JSON_UNESCAPED_UNICODE)];

$r = BookingFields::validate($service, [], 'es');
check_bf('faltan los obligatorios → error en cada uno',
    isset($r['errors']['phone'], $r['errors']['matricula'], $r['errors']['personas'], $r['errors']['condiciones']),
    json_encode($r['errors']));

$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => '1234ABC', 'personas' => '9', 'condiciones' => '1',
], 'es');
check_bf('una opción fuera de la lista se rechaza', isset($r['errors']['personas']), json_encode($r['errors']));

$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => '1234ABC', 'personas' => '2', 'condiciones' => '',
], 'es');
check_bf('la casilla obligatoria sin marcar se rechaza', isset($r['errors']['condiciones']));

$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => '1234ABC', 'personas' => '2', 'condiciones' => '1',
    'edad' => 'treinta',
], 'es');
check_bf('un número que no lo es se rechaza', isset($r['errors']['edad']), json_encode($r['errors']));

$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => '1234ABC', 'personas' => '2', 'condiciones' => '1',
    'cuando' => '2026-02-31',
], 'es');
check_bf('una fecha imposible se rechaza', isset($r['errors']['cuando']), json_encode($r['errors']));

$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => '1234ABC', 'personas' => '2', 'condiciones' => 'on',
    'edad' => '41', 'cuando' => '2026-09-01',
], 'es');
check_bf('todo correcto → sin errores', $r['errors'] === [], json_encode($r['errors']));
check_bf('se guarda la etiqueta junto al valor',
    ($r['values']['matricula']['label'] ?? '') === 'Matrícula' && ($r['values']['matricula']['value'] ?? '') === '1234ABC',
    json_encode($r['values']));
check_bf('la casilla se guarda como Sí/No', ($r['values']['condiciones']['value'] ?? '') === 'Sí', json_encode($r['values']['condiciones'] ?? null));
check_bf('un campo opcional vacío no ensucia la reserva',
    !array_key_exists('vacio', $r['values']));

// Campos que el gestor NO ha definido no se cuelan aunque los mande el cliente.
$r = BookingFields::validate($service, [
    'phone' => '600', 'matricula' => 'X', 'personas' => '1', 'condiciones' => '1',
    'campo_pirata' => 'lo que sea',
], 'es');
check_bf('un campo no definido se ignora', !array_key_exists('campo_pirata', $r['values']), json_encode(array_keys($r['values'])));

// El idioma de los errores es el de la página donde se reserva.
$fr = BookingFields::validate($service, [], 'fr');
check_bf('los errores salen en el idioma de la reserva',
    str_contains($fr['errors']['matricula'] ?? '', 'obligatoire'), json_encode($fr['errors']['matricula'] ?? null));

// ---------------------------------------------------------------------------
// 4. Respuestas guardadas
// ---------------------------------------------------------------------------

$answers = BookingFields::answers(['extra_json' => json_encode([
    ['label' => 'Matrícula', 'value' => '1234ABC'],
    ['label' => '', 'value' => 'sin etiqueta'],
    ['label' => 'Vacío', 'value' => ''],
], JSON_UNESCAPED_UNICODE)]);
check_bf('las respuestas se leen con su etiqueta', count($answers) === 1 && $answers[0]['label'] === 'Matrícula', json_encode($answers));
check_bf('un extra_json corrupto no rompe nada', BookingFields::answers(['extra_json' => '{roto']) === []);
check_bf('una reserva antigua sin extras devuelve lista vacía', BookingFields::answers([]) === []);

// ---------------------------------------------------------------------------
// 5. Ida y vuelta por la BD
// ---------------------------------------------------------------------------

$svcId = ServiceStore::create($siteId, ['name' => 'zzz Test campos']);
ServiceStore::update($siteId, $svcId, [
    'name' => 'zzz Test campos',
    'fields' => [
        'phone' => ['enabled' => '0'],
        'notes' => ['enabled' => '1', 'required' => '1'],
        'custom' => [['label' => 'Alergias', 'type' => 'textarea', 'required' => '1']],
    ],
], [], []);
$saved = ServiceStore::find($siteId, $svcId);
$savedDef = BookingFields::forService($saved);
check_bf('la configuración sobrevive al guardado',
    !$savedDef['phone']['enabled'] && $savedDef['notes']['required']
    && ($savedDef['custom'][0]['label'] ?? '') === 'Alergias',
    (string) ($saved['fields_json'] ?? ''));

$widget = array_column(BookingFields::forWidget($saved, 'es'), 'key');
check_bf('el widget ya no pide el teléfono', !in_array('phone', $widget, true), json_encode($widget));
check_bf('y sí el campo nuevo', in_array($savedDef['custom'][0]['key'], $widget, true), json_encode($widget));

ServiceStore::delete($siteId, $svcId);
check_bf('limpieza: servicio de prueba borrado', ServiceStore::find($siteId, $svcId) === null);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
