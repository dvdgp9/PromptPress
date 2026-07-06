<?php

declare(strict_types=1);

/**
 * FEAT-3 B2 — Tests de ServiceStore (servicios reservables + horario).
 *
 * Crea un servicio de prueba, guarda horario semanal y excepciones, verifica
 * la hidratación y los límites de normalización, y borra todo al final
 * (incluida la cascada de booking_hours por FK).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\ServiceStore;
use Core\Database;

$failed = 0;
function check_bk(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_bk('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

// --- create con defaults ---------------------------------------------------
$id = ServiceStore::create($siteId, ['name' => '  Test B2 · Consulta  ']);
check_bk('create devuelve id', $id > 0);

$svc = ServiceStore::find($siteId, $id);
check_bk('find hidrata el servicio', $svc !== null);
check_bk('name normalizado (trim)', ($svc['name'] ?? '') === 'Test B2 · Consulta', (string) ($svc['name'] ?? ''));
check_bk('defaults: duration 60', (int) ($svc['duration_min'] ?? 0) === 60);
check_bk('defaults: capacity 1', (int) ($svc['capacity'] ?? 0) === 1);
check_bk('defaults: auto_confirm 0', (int) ($svc['auto_confirm'] ?? 1) === 0);
check_bk('sin horario al crear', ($svc['hours'] ?? null) === [] && ($svc['exceptions'] ?? null) === []);

// find con site equivocado no debe devolver nada.
check_bk('find aislado por site', ServiceStore::find($siteId + 999999, $id) === null);

// --- update: campos + horario + excepciones ---------------------------------
$hours = [
    0 => [['start' => '09:00', 'end' => '14:00'], ['start' => '16:00', 'end' => '20:00']], // lunes partido
    5 => [['start' => '10:00', 'end' => '13:30']],                                          // sábado
];
$exceptions = [
    ['date' => '2026-12-25', 'closed' => true,  'start' => null,    'end' => null],
    ['date' => '2026-12-24', 'closed' => false, 'start' => '09:00', 'end' => '12:00'],
];
$ok = ServiceStore::update($siteId, $id, [
    'name'             => 'Test B2 · Consulta v2',
    'description'      => 'desc',
    'duration_min'     => 30,
    'buffer_min'       => 10,
    'capacity'         => 8,
    'min_notice_hours' => 24,
    'max_advance_days' => 90,
    'auto_confirm'     => '1',
    'price_label'      => '25 €',
    'active'           => '1',
], $hours, $exceptions);
check_bk('update devuelve true', $ok);

$svc = ServiceStore::find($siteId, $id);
check_bk('update persiste campos', (int) $svc['duration_min'] === 30 && (int) $svc['capacity'] === 8
    && (int) $svc['auto_confirm'] === 1 && (string) $svc['price_label'] === '25 €');
check_bk('horario: lunes con 2 franjas ordenadas',
    count($svc['hours'][0] ?? []) === 2
    && ($svc['hours'][0][0]['start'] ?? '') === '09:00' && ($svc['hours'][0][1]['end'] ?? '') === '20:00');
check_bk('horario: sábado con 1 franja', count($svc['hours'][5] ?? []) === 1);
check_bk('horario: martes sin franjas', !isset($svc['hours'][1]));
check_bk('excepciones: 2 ordenadas por fecha',
    count($svc['exceptions']) === 2 && $svc['exceptions'][0]['date'] === '2026-12-24');
check_bk('excepción cerrado sin franja',
    $svc['exceptions'][1]['closed'] === true && $svc['exceptions'][1]['start'] === null);
check_bk('excepción especial con franja',
    $svc['exceptions'][0]['closed'] === false && $svc['exceptions'][0]['start'] === '09:00');

// --- update reescribe (no acumula) -------------------------------------------
$ok = ServiceStore::update($siteId, $id, ['name' => 'Test B2 · Consulta v2', 'active' => '1'], [2 => [['start' => '08:00', 'end' => '10:00']]], []);
$svc = ServiceStore::find($siteId, $id);
check_bk('update reescribe horario', $ok && count($svc['hours']) === 1 && isset($svc['hours'][2]) && $svc['exceptions'] === []);

// --- normalización acota límites ---------------------------------------------
ServiceStore::update($siteId, $id, ['name' => 'x', 'duration_min' => 9999, 'capacity' => 0, 'buffer_min' => -5, 'active' => '1'], [], []);
$svc = ServiceStore::find($siteId, $id);
check_bk('normalize acota duration<=480 capacity>=1 buffer>=0',
    (int) $svc['duration_min'] === 480 && (int) $svc['capacity'] === 1 && (int) $svc['buffer_min'] === 0);

// update de un id inexistente → false.
check_bk('update de id inexistente devuelve false', ServiceStore::update($siteId, 99999999, ['name' => 'x', 'active' => '1'], [], []) === false);

// --- all + delete (cascada) ----------------------------------------------------
$all = ServiceStore::all($siteId);
$found = array_filter($all, static fn (array $s): bool => (int) $s['id'] === $id);
check_bk('all incluye el servicio con upcoming_count', $found !== [] && (int) (reset($found)['upcoming_count']) === 0);

check_bk('delete devuelve true', ServiceStore::delete($siteId, $id));
$orphans = (int) (Database::selectOne('SELECT COUNT(*) AS c FROM booking_hours WHERE service_id = ?', [$id])['c'] ?? -1);
check_bk('delete cascada borra booking_hours', $orphans === 0);
check_bk('find tras delete devuelve null', ServiceStore::find($siteId, $id) === null);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
