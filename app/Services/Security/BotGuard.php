<?php

declare(strict_types=1);

namespace App\Services\Security;

use Core\App;
use Core\Database;

/**
 * FEAT-4 — Escudo anti-bot propio (sin terceros, sin cookies, RGPD-limpio).
 *
 * AB1: trampa de tiempo. El render del formulario emite un campo oculto
 * `_pp_ts` con `timestamp.firma` (HMAC-SHA256, clave derivada de app_key).
 * Al recibir el envío, un timestamp firmado hace de suelo mínimo: si el POST
 * llega antes de MIN_SECONDS es un bot (los humanos tardan en rellenar), y si
 * la firma no cuadra, el campo falta o fue manipulado. En ambos casos el
 * controlador responde el mismo "ok" falso del honeypot, sin crear nada.
 *
 * La caducidad (MAX_SECONDS) cubre formularios pre-cocinados; a un humano con
 * la pestaña abierta demasiado tiempo se le pide reintentar (error amable, no
 * "ok" falso). Las páginas con formulario nunca se cachean (PageController),
 * así que el timestamp refleja la visita real.
 */
final class BotGuard
{
    public const OK = 'ok';
    public const TOO_FAST = 'too_fast';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    /** Umbrales del time-trap (decisión Planner 2026-07-07, ajustables). */
    public const MIN_SECONDS = 3;
    public const MAX_SECONDS = 21600; // 6 h

    /** Valor para el hidden `_pp_ts`: "<unix_ts>.<hmac>". */
    public static function issueTimestamp(?int $now = null): string
    {
        $ts = (string) ($now ?? time());
        return $ts . '.' . self::sign($ts);
    }

    /**
     * Verifica el `_pp_ts` recibido. Devuelve una de las constantes:
     * OK, TOO_FAST (bot casi seguro), EXPIRED (humano con pestaña vieja),
     * INVALID (ausente, malformado o firma manipulada → bot).
     */
    public static function verifyTimestamp(
        ?string $value,
        int $minSeconds = self::MIN_SECONDS,
        int $maxSeconds = self::MAX_SECONDS,
        ?int $now = null
    ): string {
        if (!is_string($value) || !preg_match('/^(\d{1,20})\.([0-9a-f]{64})$/', $value, $m)) {
            return self::INVALID;
        }
        if (!hash_equals(self::sign($m[1]), $m[2])) {
            return self::INVALID;
        }
        $age = ($now ?? time()) - (int) $m[1];
        if ($age < 0) return self::INVALID;   // timestamp del futuro: manipulado
        if ($age < $minSeconds) return self::TOO_FAST;
        if ($age > $maxSeconds) return self::EXPIRED;
        return self::OK;
    }

    // ------------------------------------------------------------------
    // AB2 — Proof-of-work (estilo ALTCHA, sin terceros).
    //
    // El servidor emite un reto firmado y stateless; el navegador busca un
    // nonce `n` tal que sha256(salt + '.' + n) empiece por POW_BITS bits a
    // cero (~2^POW_BITS hashes de media, coste real de CPU para spam masivo,
    // invisible para el humano). La verificación es UN hash + comprobación
    // de firma; el anti-replay persiste el reto consumido en botguard_solved
    // hasta su caducidad, con purga perezosa en cada verificación.
    // ------------------------------------------------------------------

    public const POW_OK = 'pow_ok';
    public const POW_INVALID = 'pow_invalid';
    public const POW_EXPIRED = 'pow_expired';
    public const POW_REPLAY = 'pow_replay';

    /** Dificultad en bits a cero (~2^15 ≈ 33k hashes de media, <1 s de CPU). */
    public const POW_BITS = 15;
    /** Vida del reto; si caduca con el formulario abierto, el cliente re-pide. */
    public const POW_TTL = 7200; // 2 h

    /**
     * Emite un reto PoW. `challenge` es la cadena opaca para el cliente:
     * "1.<expira>.<salt 32 hex>.<bits>.<firma>"; el cliente devuelve en
     * `_pp_pow` esa misma cadena + ".<nonce decimal>".
     *
     * @return array{challenge:string,salt:string,bits:int,expires:int}
     */
    public static function issueChallenge(?int $now = null): array
    {
        $expires = ($now ?? time()) + self::POW_TTL;
        $salt = bin2hex(random_bytes(16));
        $bits = self::POW_BITS;
        $sig = self::sign('pow|' . $expires . '|' . $salt . '|' . $bits);
        return [
            'challenge' => '1.' . $expires . '.' . $salt . '.' . $bits . '.' . $sig,
            'salt' => $salt,
            'bits' => $bits,
            'expires' => $expires,
        ];
    }

    /**
     * Verifica un `_pp_pow`. Devuelve POW_OK / POW_INVALID (malformado,
     * firma o nonce incorrectos → bot) / POW_EXPIRED (reto caducado, el
     * cliente debería haber re-pedido) / POW_REPLAY (solución ya consumida).
     */
    public static function verifySolution(?string $value, ?int $now = null): string
    {
        if (!is_string($value)
            || !preg_match('/^1\.(\d{1,20})\.([0-9a-f]{32})\.(\d{1,2})\.([0-9a-f]{64})\.(\d{1,15})$/', $value, $m)
        ) {
            return self::POW_INVALID;
        }
        [, $expires, $salt, $bits, $sig, $nonce] = $m;
        if (!hash_equals(self::sign('pow|' . $expires . '|' . $salt . '|' . $bits), $sig)) {
            return self::POW_INVALID;
        }
        if ((int) $bits < self::POW_BITS) {
            return self::POW_INVALID; // reto degradado: nunca lo emitimos así
        }
        if (($now ?? time()) > (int) $expires) {
            return self::POW_EXPIRED;
        }
        if (!self::hashMeetsDifficulty(hash('sha256', $salt . '.' . $nonce, true), (int) $bits)) {
            return self::POW_INVALID;
        }

        // Anti-replay: cada reto se consume UNA vez. La clave es el hash del
        // reto (sin nonce): re-resolver el mismo reto con otro nonce tampoco
        // cuela. INSERT IGNORE → 0 filas afectadas = ya estaba consumido.
        self::ensureSchema();
        Database::execute('DELETE FROM botguard_solved WHERE expires_at < NOW()');
        $inserted = Database::execute(
            'INSERT IGNORE INTO botguard_solved (challenge_hash, expires_at) VALUES (?, FROM_UNIXTIME(?))',
            [hash('sha256', $expires . '.' . $salt . '.' . $bits . '.' . $sig), (int) $expires]
        );
        return $inserted === 0 ? self::POW_REPLAY : self::POW_OK;
    }

    /** ¿Los primeros $bits bits del hash binario son cero? */
    private static function hashMeetsDifficulty(string $binHash, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($binHash[$i] !== "\x00") return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) return true;
        return (ord($binHash[$fullBytes]) >> (8 - $rest)) === 0;
    }

    public static function ensureSchema(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS botguard_solved (
                challenge_hash CHAR(64) NOT NULL PRIMARY KEY,
                expires_at DATETIME NOT NULL,
                INDEX idx_bgs_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::key());
    }

    /**
     * Clave de propósito derivada de app_key (no se usa la maestra en crudo:
     * el escudo queda desacoplado del cifrado de secretos de Core\Crypto).
     */
    private static function key(): string
    {
        $appKey = (string) (App::config()['app_key'] ?? '');
        if ($appKey === '') {
            throw new \RuntimeException('BotGuard requiere app_key en config.php');
        }
        return hash_hmac('sha256', 'botguard-v1', $appKey);
    }
}
