<?php

declare(strict_types=1);

/**
 * FEAT-4 AB2 — Tests del proof-of-work de BotGuard.
 *
 * Resuelve retos reales por fuerza bruta en PHP (POW_BITS=15 → ~33k hashes,
 * milisegundos) y verifica: solución correcta (una sola vez), replay con el
 * mismo nonce y con nonce alternativo, firma manipulada, nonce incorrecto,
 * reto caducado, malformados y purga perezosa de consumidos caducados.
 * Limpia sus filas de botguard_solved al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\Security\BotGuard;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    if ($ok) {
        echo "PASS  $name\n";
    } else {
        $failed++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

/** Fuerza bruta: nonce cuyo sha256(salt.nonce) empiece por $bits ceros. */
function solve(string $salt, int $bits, int $from = 0): int
{
    for ($n = $from; ; $n++) {
        $h = hash('sha256', $salt . '.' . $n, true);
        $full = intdiv($bits, 8);
        $ok = true;
        for ($i = 0; $i < $full; $i++) {
            if ($h[$i] !== "\x00") { $ok = false; break; }
        }
        $rest = $bits % 8;
        if ($ok && ($rest === 0 || (ord($h[$full]) >> (8 - $rest)) === 0)) return $n;
    }
}

BotGuard::ensureSchema();
$before = (int) (Database::selectOne('SELECT COUNT(*) n FROM botguard_solved')['n'] ?? 0);

// --- Emisión ---
$c = BotGuard::issueChallenge();
check('challenge_formato', (bool) preg_match('/^1\.\d+\.[0-9a-f]{32}\.\d{1,2}\.[0-9a-f]{64}$/', $c['challenge']), $c['challenge']);
check('challenge_bits', $c['bits'] === BotGuard::POW_BITS);
check('challenge_expira_futuro', $c['expires'] > time());
$c2 = BotGuard::issueChallenge();
check('salts_distintas', $c['salt'] !== $c2['salt']);

// --- Solución correcta, una sola vez ---
$t0 = microtime(true);
$nonce = solve($c['salt'], $c['bits']);
$solveMs = (int) round((microtime(true) - $t0) * 1000);
echo "      (nonce=$nonce resuelto en {$solveMs}ms)\n";
$solution = $c['challenge'] . '.' . $nonce;
check('solucion_valida_ok', BotGuard::verifySolution($solution) === BotGuard::POW_OK);
check('replay_mismo_nonce', BotGuard::verifySolution($solution) === BotGuard::POW_REPLAY);
$nonce2 = solve($c['salt'], $c['bits'], $nonce + 1);
check('replay_otro_nonce', BotGuard::verifySolution($c['challenge'] . '.' . $nonce2) === BotGuard::POW_REPLAY);

// --- Manipulaciones ---
$n2 = solve($c2['salt'], $c2['bits']);
$parts = explode('.', $c2['challenge']); // [1, expires, salt, bits, sig]
$tamperedSig = implode('.', [$parts[0], $parts[1], $parts[2], $parts[3], strrev($parts[4])]) . '.' . $n2;
check('firma_manipulada', BotGuard::verifySolution($tamperedSig) === BotGuard::POW_INVALID);
$lowBits = implode('.', [$parts[0], $parts[1], $parts[2], '1', $parts[4]]) . '.' . $n2;
check('bits_rebajados', BotGuard::verifySolution($lowBits) === BotGuard::POW_INVALID);
check('nonce_incorrecto', BotGuard::verifySolution($c2['challenge'] . '.' . ($n2 + 1)) === BotGuard::POW_INVALID
    || $n2 + 1 === solve($c2['salt'], $c2['bits'], $n2 + 1)); // (colisión legítima improbable)
check('malformado', BotGuard::verifySolution('garbage') === BotGuard::POW_INVALID);
check('vacio_null', BotGuard::verifySolution(null) === BotGuard::POW_INVALID);
check('sin_nonce', BotGuard::verifySolution($c2['challenge']) === BotGuard::POW_INVALID);

// --- Caducidad: reto emitido en el pasado (expira hace 10 s) ---
$old = BotGuard::issueChallenge(time() - BotGuard::POW_TTL - 10);
$nOld = solve($old['salt'], $old['bits']);
check('reto_caducado', BotGuard::verifySolution($old['challenge'] . '.' . $nOld) === BotGuard::POW_EXPIRED);

// --- Purga perezosa: un consumido ya caducado desaparece al verificar ---
Database::execute(
    'INSERT IGNORE INTO botguard_solved (challenge_hash, expires_at) VALUES (?, DATE_SUB(NOW(), INTERVAL 1 HOUR))',
    [str_repeat('f', 64)]
);
$n2ok = BotGuard::verifySolution($c2['challenge'] . '.' . $n2); // dispara purga + consume c2
check('solucion_c2_ok', $n2ok === BotGuard::POW_OK);
$purged = Database::selectOne('SELECT COUNT(*) n FROM botguard_solved WHERE challenge_hash = ?', [str_repeat('f', 64)]);
check('purga_perezosa', (int) $purged['n'] === 0);

// --- Limpieza: borrar los retos consumidos por este test ---
foreach ([$c, $c2] as $ch) {
    [, $exp, $salt, $bits, $sig] = explode('.', $ch['challenge']);
    Database::execute('DELETE FROM botguard_solved WHERE challenge_hash = ?', [hash('sha256', "$exp.$salt.$bits.$sig")]);
}
$after = (int) (Database::selectOne('SELECT COUNT(*) n FROM botguard_solved')['n'] ?? 0);
check('tabla_como_estaba', $after <= $before, "before=$before after=$after");

echo $failed === 0 ? "\nALL PASS (16)\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
