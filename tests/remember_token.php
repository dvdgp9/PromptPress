<?php

declare(strict_types=1);

/**
 * SESION-RECUERDA SR-2 — Contrato del token de «mantener la sesión iniciada».
 *
 * Lo que se fija aquí es lo que hace que esto sea seguro y no solo cómodo: que
 * el secreto no viva en claro en la tabla, que se renueve en cada uso, que dos
 * pestañas a la vez no se echen la una a la otra, y que una cookie que no
 * encaja se lleve por delante la fila en lugar de dar otra oportunidad.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use Core\Database;
use Core\RememberToken;

$failed = 0;
function rememberCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$user = Database::selectOne('SELECT id FROM users ORDER BY id LIMIT 1');
if (!$user) {
    echo "SKIP: no hay usuarios en la base de datos de desarrollo.\n";
    exit(0);
}
$userId = (int) $user['id'];

/** @return array<string,mixed>|null */
function tokenRow(string $selector): ?array
{
    return Database::selectOne('SELECT * FROM auth_tokens WHERE selector = ? LIMIT 1', [$selector]);
}

function cookie(): string
{
    return (string) ($_COOKIE['PPRESS_REMEMBER'] ?? '');
}

function selectorOf(string $cookie): string
{
    return explode(':', $cookie, 2)[0] ?? '';
}

function validatorOf(string $cookie): string
{
    return explode(':', $cookie, 2)[1] ?? '';
}

// Punto de partida limpio.
Database::execute('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
$_COOKIE = [];

// ---------------------------------------------------------------- emitir
RememberToken::issue($userId);
$first = cookie();
rememberCheck('emitir deja una cookie con formato selector:validador',
    (bool) preg_match('/^[a-f0-9]{32}:[a-f0-9]{64}$/', $first), $first);

$row = tokenRow(selectorOf($first));
rememberCheck('emitir guarda una fila para el usuario', is_array($row) && (int) $row['user_id'] === $userId);

// Que el secreto NO esté en claro es el punto entero del diseño: quien lea la
// tabla (un volcado, una inyección de solo lectura) no puede fabricar cookies.
rememberCheck('el validador no se guarda en claro',
    is_array($row) && $row['validator_hash'] !== validatorOf($first)
    && hash_equals((string) $row['validator_hash'], hash('sha256', validatorOf($first))));

$daysLeft = is_array($row) ? (strtotime((string) $row['expires_at']) - time()) / 86400 : 0;
rememberCheck('caduca a 30 días', $daysLeft > 29.5 && $daysLeft < 30.5, (string) $daysLeft);

// ---------------------------------------------------------------- reabrir
rememberCheck('la cookie buena reabre la sesión', RememberToken::resume() === $userId);

$second = cookie();
rememberCheck('cada uso renueva el validador', $second !== $first && selectorOf($second) === selectorOf($first));

$row = tokenRow(selectorOf($second));
rememberCheck('el validador anterior queda guardado con su ventana',
    is_array($row)
    && hash_equals((string) $row['prev_validator_hash'], hash('sha256', validatorOf($first)))
    && strtotime((string) $row['prev_valid_until']) > time());

// Dos pestañas a la vez: la segunda llega con el validador que la primera acaba
// de rotar. Sin la ventana de gracia, esto echaba al usuario de su sesión.
$_COOKIE['PPRESS_REMEMBER'] = $first;
rememberCheck('el validador recién rotado sigue valiendo dentro de la gracia',
    RememberToken::resume() === $userId);

// ---------------------------------------------------------- fuera de plazo
$selector = selectorOf(cookie());
$stale = $first;
Database::execute(
    'UPDATE auth_tokens SET prev_valid_until = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE selector = ?',
    [$selector]
);
$_COOKIE['PPRESS_REMEMBER'] = $stale;
rememberCheck('pasada la gracia, el validador viejo ya no vale', RememberToken::resume() === null);
rememberCheck('un validador que no encaja se lleva la fila por delante', tokenRow($selector) === null);

// ------------------------------------------------------------------ basura
$_COOKIE['PPRESS_REMEMBER'] = 'no-es-un-token';
rememberCheck('una cookie con formato inválido no reabre nada', RememberToken::resume() === null);

$_COOKIE = [];
rememberCheck('sin cookie no reabre nada', RememberToken::resume() === null);

// --------------------------------------------------------------- caducado
RememberToken::issue($userId);
$selector = selectorOf(cookie());
Database::execute(
    'UPDATE auth_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE selector = ?',
    [$selector]
);
rememberCheck('un token caducado no reabre nada', RememberToken::resume() === null);

// ---------------------------------------------------------------- revocar
$_COOKIE = [];
RememberToken::issue($userId);
$selector = selectorOf(cookie());
RememberToken::revokeCurrent();
rememberCheck('cerrar sesión borra el token de este navegador', tokenRow($selector) === null);
rememberCheck('cerrar sesión limpia la cookie', cookie() === '');

$_COOKIE = [];
RememberToken::issue($userId);
$_COOKIE = [];
RememberToken::issue($userId);
$before = (int) (Database::selectOne(
    'SELECT COUNT(*) c FROM auth_tokens WHERE user_id = ?', [$userId]
)['c'] ?? 0);
RememberToken::revokeAllFor($userId);
$after = (int) (Database::selectOne(
    'SELECT COUNT(*) c FROM auth_tokens WHERE user_id = ?', [$userId]
)['c'] ?? 0);
rememberCheck('revocar todos deja al usuario sin tokens', $before >= 2 && $after === 0, "antes={$before} después={$after}");

// La limpieza no se puede llevar por delante tokens vivos.
$_COOKIE = [];
RememberToken::issue($userId);
$aliveSelector = selectorOf(cookie());
Database::execute(
    'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at)
     VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))',
    [$userId, str_repeat('a', 32), str_repeat('b', 64)]
);
RememberToken::prune();
rememberCheck('la limpieza borra lo caducado y respeta lo vivo',
    tokenRow(str_repeat('a', 32)) === null && tokenRow($aliveSelector) !== null);

// Sin restos en la base de datos de desarrollo.
Database::execute('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
