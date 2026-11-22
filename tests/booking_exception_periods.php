<?php

declare(strict_types=1);

/**
 * BOOK-VAC — Excepciones por periodo y copiar el horario a otros calendarios.
 *
 * Parte 1: motor puro (sin BD). Parte 2: ServiceStore contra la BD local,
 * con servicios de prueba que se borran al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\AvailabilityEngine;
use App\Modules\Booking\ServiceStore;
use Core\Database;

$failed = 0;
function check_vac(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// 1. Motor
// ---------------------------------------------------------------------------
$TZ  = 'Europe/Madrid';
$NOW = new DateTimeImmutable('2027-06-07 06:00:00', new DateTimeZone('UTC'));
$svc = ['duration_min' => 60, 'buffer_min' => 0, 'capacity' => 1, 'min_notice_hours' => 0, 'max_advance_days' => 365];
$rec = static function (int $wd): array {
    return ['service_id' => 7, 'weekday' => $wd, 'date' => null, 'date_end' => null, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'closed' => 0];
};
$every = array_map($rec, range(0, 6));
$dates = static fn (array $days): array => array_map(static fn ($d) => $d['date'], $days);

// Periodo cerrado del miércoles 16 al viernes 18 (ambos incluidos).
$rules = array_merge($every, [[
    'service_id' => 7, 'weekday' => null, 'date' => '2027-06-16', 'date_end' => '2027-06-18',
    'start_time' => null, 'end_time' => null, 'closed' => 1,
]]);
$days = AvailabilityEngine::slots($svc, $rules, [], '2027-06-14', '2027-06-20', $TZ, $NOW);
check_vac('periodo cerrado quita sus 3 días (incluidos los extremos)',
    $dates($days) === ['2027-06-14', '2027-06-15', '2027-06-19', '2027-06-20'], json_encode($dates($days)));

// Periodo con horario especial: 12-13 en vez de 9-11.
$rules = array_merge($every, [[
    'service_id' => 7, 'weekday' => null, 'date' => '2027-06-15', 'date_end' => '2027-06-16',
    'start_time' => '12:00:00', 'end_time' => '13:00:00', 'closed' => 0,
]]);
$days = AvailabilityEngine::slots($svc, $rules, [], '2027-06-14', '2027-06-17', $TZ, $NOW);
$byDate = [];
foreach ($days as $d) $byDate[$d['date']] = array_map(static fn ($s) => substr((string) $s['start'], 11, 5), $d['slots']);
check_vac('periodo con horario especial lo aplica a cada día',
    ($byDate['2027-06-15'] ?? null) === ['12:00'] && ($byDate['2027-06-16'] ?? null) === ['12:00']
    && ($byDate['2027-06-14'] ?? null) === ['09:00', '10:00'], json_encode($byDate));

// Sin date_end (como las de siempre) sigue siendo un día.
check_vac('sin fin = un solo día', AvailabilityEngine::exceptionDates('2027-06-16', null) === ['2027-06-16']);
check_vac('fin anterior al inicio = un solo día', AvailabilityEngine::exceptionDates('2027-06-16', '2027-06-10') === ['2027-06-16']);
check_vac('periodo cruza de mes', AvailabilityEngine::exceptionDates('2026-11-29', '2026-12-02') === ['2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02']);
check_vac('periodo larguísimo se corta en 366 días', count(AvailabilityEngine::exceptionDates('2027-01-01', '2035-01-01')) === AvailabilityEngine::MAX_PERIOD_DAYS);

// ---------------------------------------------------------------------------
// 2. ServiceStore: guardar periodos y copiar
// ---------------------------------------------------------------------------
$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_vac('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$a = ServiceStore::create($siteId, ['name' => 'Test VAC A']);
$b = ServiceStore::create($siteId, ['name' => 'Test VAC B']);
$c = ServiceStore::create($siteId, ['name' => 'Test VAC C']);
$created = [$a, $b, $c];

try {
    ServiceStore::update($siteId, $a, ['name' => 'Test VAC A', 'active' => '1', 'price_label' => 'A'],
        [0 => [['start' => '09:00', 'end' => '13:00']]],
        [
            ['date' => '2026-12-21', 'date_end' => '2027-01-06', 'closed' => true, 'start' => null, 'end' => null],
            ['date' => '2026-11-26', 'date_end' => null, 'closed' => false, 'start' => '12:00', 'end' => '15:00'],
        ]);
    $sa = ServiceStore::find($siteId, $a);
    $periods = array_values(array_filter($sa['exceptions'], static fn ($e) => $e['date_end'] !== null));
    check_vac('el periodo se guarda y se lee con su fin',
        count($periods) === 1 && $periods[0]['date'] === '2026-12-21' && $periods[0]['date_end'] === '2027-01-06', json_encode($sa['exceptions']));
    check_vac('la excepción de un día se lee con date_end null',
        count($sa['exceptions']) === 2 && $sa['exceptions'][0]['date'] === '2026-11-26' && $sa['exceptions'][0]['date_end'] === null);

    // B ya tiene horario y una excepción propia (y una igual a la de A).
    ServiceStore::update($siteId, $b, ['name' => 'Test VAC B', 'active' => '1', 'price_label' => 'B'],
        [4 => [['start' => '16:00', 'end' => '20:00']]],
        [
            ['date' => '2026-12-08', 'closed' => true, 'start' => null, 'end' => null],
            ['date' => '2026-12-21', 'date_end' => '2027-01-06', 'closed' => true, 'start' => null, 'end' => null],
        ]);

    // Solo excepciones → B y C.
    $n = ServiceStore::copySchedule($siteId, $a, [$b, $c, $a, 99999999], false, true);
    check_vac('copia a 2 calendarios (ignora el origen y los ajenos)', $n === 2, (string) $n);
    $sb = ServiceStore::find($siteId, $b);
    check_vac('solo excepciones: el horario semanal de B no cambia', array_keys($sb['hours']) === [4], json_encode($sb['hours']));
    check_vac('excepciones se añaden sin duplicar (B: 3)', count($sb['exceptions']) === 3, json_encode($sb['exceptions']));
    check_vac('copiar no toca los demás campos', (string) $sb['price_label'] === 'B' && (string) $sb['name'] === 'Test VAC B');
    $sc = ServiceStore::find($siteId, $c);
    check_vac('C recibe las 2 excepciones', count($sc['exceptions']) === 2);

    // Repetir no duplica.
    ServiceStore::copySchedule($siteId, $a, [$b], false, true);
    check_vac('copiar dos veces no duplica', count(ServiceStore::find($siteId, $b)['exceptions']) === 3);

    // Solo horario → sustituye y deja las excepciones.
    ServiceStore::copySchedule($siteId, $a, [$b], true, false);
    $sb = ServiceStore::find($siteId, $b);
    check_vac('solo horario: lo sustituye', array_keys($sb['hours']) === [0] && $sb['hours'][0][0]['start'] === '09:00', json_encode($sb['hours']));
    check_vac('solo horario: las excepciones siguen', count($sb['exceptions']) === 3);

    // Otro sitio no puede ser destino.
    check_vac('nada que copiar = 0', ServiceStore::copySchedule($siteId, $a, [$b], false, false) === 0);
    check_vac('origen de otro sitio = 0', ServiceStore::copySchedule($siteId + 999999, $a, [$b], true, true) === 0);
} finally {
    foreach ($created as $id) ServiceStore::delete($siteId, $id);
}

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
