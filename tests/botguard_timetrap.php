<?php

declare(strict_types=1);

/**
 * FEAT-4 AB1 — Tests del time-trap de BotGuard.
 *
 * Unit (sin BD): emisión y verificación del timestamp firmado — válido,
 * demasiado rápido, caducado, firma manipulada, malformado, ausente y futuro.
 * La parte HTTP (ok falso vía POST real) se verifica manualmente con curl
 * contra el server de dev (ver scratchpad AB1).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\Security\BotGuard;

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

$now = time();
$token = BotGuard::issueTimestamp($now);

check('formato_token', (bool) preg_match('/^\d+\.[0-9a-f]{64}$/', $token), $token);

// Envío humano normal: emitido hace 60 s → OK.
$aged = BotGuard::issueTimestamp($now - 60);
check('humano_60s_ok', BotGuard::verifyTimestamp($aged, 3, 21600, $now) === BotGuard::OK);

// Justo en los límites.
check('limite_min_3s_ok', BotGuard::verifyTimestamp(BotGuard::issueTimestamp($now - 3), 3, 21600, $now) === BotGuard::OK);
check('limite_max_6h_ok', BotGuard::verifyTimestamp(BotGuard::issueTimestamp($now - 21600), 3, 21600, $now) === BotGuard::OK);

// Bot: envía en <3 s.
check('bot_2s_too_fast', BotGuard::verifyTimestamp(BotGuard::issueTimestamp($now - 2), 3, 21600, $now) === BotGuard::TOO_FAST);
check('bot_0s_too_fast', BotGuard::verifyTimestamp($token, 3, 21600, $now) === BotGuard::TOO_FAST);

// Humano con pestaña vieja: >6 h → EXPIRED (error amable, no ok falso).
check('pestana_vieja_expired', BotGuard::verifyTimestamp(BotGuard::issueTimestamp($now - 21601), 3, 21600, $now) === BotGuard::EXPIRED);

// Manipulaciones → INVALID.
[$ts, $sig] = explode('.', $aged);
check('firma_manipulada', BotGuard::verifyTimestamp($ts . '.' . strrev($sig), 3, 21600, $now) === BotGuard::INVALID);
check('ts_cambiado_firma_vieja', BotGuard::verifyTimestamp(($now - 7200) . '.' . $sig, 3, 21600, $now) === BotGuard::INVALID);
check('malformado', BotGuard::verifyTimestamp('loquesea', 3, 21600, $now) === BotGuard::INVALID);
check('vacio', BotGuard::verifyTimestamp('', 3, 21600, $now) === BotGuard::INVALID);
check('ausente_null', BotGuard::verifyTimestamp(null, 3, 21600, $now) === BotGuard::INVALID);
check('ts_futuro', BotGuard::verifyTimestamp(BotGuard::issueTimestamp($now + 120), 3, 21600, $now) === BotGuard::INVALID);

// Dos emisiones del mismo segundo comparten firma (determinista, sin estado).
check('determinista', BotGuard::issueTimestamp($now) === $token);

echo $failed === 0 ? "\nALL PASS (14)\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
