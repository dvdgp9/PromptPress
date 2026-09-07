<?php

declare(strict_types=1);

namespace Core;

/**
 * SESION-RECUERDA SR-2 — «Mantener la sesión iniciada».
 *
 * Por qué no basta con alargar la sesión de PHP: el recolector de basura mira
 * el `save_path`, que en hosting compartido es un directorio común, así que el
 * GC de otro sitio borra nuestros ficheros mucho antes de los 30 días. Y un
 * `PPRESSSESSID` robado valdría todo ese tiempo sin forma de revocarlo.
 *
 * Así que la sesión se queda corta y esta cookie solo sirve para REABRIRLA:
 * cuando el usuario vuelve y la sesión ya no está, `resume()` la levanta y
 * regenera el id de sesión.
 *
 * Formato de la cookie: `selector:validador`.
 *  - El **selector** localiza la fila (está indexado). No es secreto.
 *  - El **validador** es el secreto y en la tabla vive solo hasheado, así que
 *    quien lea la base de datos no puede fabricar una cookie válida.
 *
 * La comparación es con `hash_equals` (tiempo constante): comparar con `===`
 * filtra por cuánto tarda en fallar.
 */
final class RememberToken
{
    private const COOKIE = 'PPRESS_REMEMBER';

    /** 30 días, deslizantes: cada uso los vuelve a empujar. */
    private const LIFETIME_DAYS = 30;

    /**
     * El validador se renueva en cada uso, pero el navegador dispara varias
     * peticiones a la vez: sin esta ventana, la segunda llegaría con el
     * validador ya rotado y echaría al usuario de su propia sesión.
     */
    private const GRACE_SECONDS = 120;

    /** Emite un token nuevo y deja la cookie puesta. */
    public static function issue(int $userId): void
    {
        $selector  = bin2hex(random_bytes(16));   // 32 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars

        $ok = self::guard(static function () use ($userId, $selector, $validator): bool {
            Database::execute(
                'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at)
                 VALUES (?, ?, ?, ?)',
                [$userId, $selector, self::hash($validator), self::expiryDate()]
            );
            return true;
        });
        if ($ok !== true) return;   // sin tabla (instalación sin migrar): ni cookie ni ruido

        self::putCookie($selector . ':' . $validator);
        self::prune();
    }

    /**
     * Intenta reabrir la sesión desde la cookie. Devuelve el id de usuario si
     * el token era bueno, y null en cualquier otro caso.
     */
    public static function resume(): ?int
    {
        $parsed = self::readCookie();
        if ($parsed === null) return null;
        [$selector, $validator] = $parsed;

        $row = self::guard(static fn(): ?array => Database::selectOne(
            'SELECT id, user_id, validator_hash, prev_validator_hash, prev_valid_until
               FROM auth_tokens
              WHERE selector = ? AND expires_at > NOW() LIMIT 1',
            [$selector]
        ));
        if (!is_array($row)) {
            // Caducado, inexistente o sin tabla: la cookie ya no sirve para nada.
            self::clearCookie();
            return null;
        }

        if (!self::matches($validator, $row)) {
            // El selector existe pero el secreto no encaja: o la cookie es de
            // un token ya rotado hace rato, o alguien la está probando. Fuera.
            self::deleteById((int) $row['id']);
            self::clearCookie();
            return null;
        }

        self::rotate((int) $row['id'], $selector);
        return (int) $row['user_id'];
    }

    /** Cierre de sesión: el token de ESTE navegador deja de valer. */
    public static function revokeCurrent(): void
    {
        $parsed = self::readCookie();
        if ($parsed !== null) {
            self::guard(static function () use ($parsed): bool {
                Database::execute('DELETE FROM auth_tokens WHERE selector = ?', [$parsed[0]]);
                return true;
            });
        }
        self::clearCookie();
    }

    /**
     * Todos los tokens de un usuario. Pensado para el día que haya pantalla de
     * cambio de contraseña: cambiarla tiene que echar al ladrón de todos los
     * dispositivos donde hubiera dejado una cookie.
     */
    public static function revokeAllFor(int $userId): void
    {
        self::guard(static function () use ($userId): bool {
            Database::execute('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
            return true;
        });
    }

    /** Limpieza oportunista: se llama al emitir, que es raro y barato. */
    public static function prune(): void
    {
        self::guard(static function (): bool {
            Database::execute('DELETE FROM auth_tokens WHERE expires_at < NOW()');
            return true;
        });
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private static function matches(string $validator, array $row): bool
    {
        $hash = self::hash($validator);
        if (hash_equals((string) $row['validator_hash'], $hash)) return true;

        $prev = $row['prev_validator_hash'] ?? null;
        $until = $row['prev_valid_until'] ?? null;
        if (!is_string($prev) || $prev === '' || !is_string($until) || $until === '') return false;
        if (strtotime($until) < time()) return false;

        return hash_equals($prev, $hash);
    }

    /**
     * Validador nuevo en cada uso (si roban la cookie, deja de valer en cuanto
     * el dueño vuelve) y caducidad empujada otros 30 días.
     */
    private static function rotate(int $id, string $selector): void
    {
        $validator = bin2hex(random_bytes(32));
        $ok = self::guard(static function () use ($id, $validator): bool {
            Database::execute(
                'UPDATE auth_tokens
                    SET prev_validator_hash = validator_hash,
                        prev_valid_until = DATE_ADD(NOW(), INTERVAL ' . self::GRACE_SECONDS . ' SECOND),
                        validator_hash = ?,
                        expires_at = ?,
                        last_used_at = NOW()
                  WHERE id = ?',
                [self::hash($validator), self::expiryDate(), $id]
            );
            return true;
        });
        if ($ok !== true) return;

        self::putCookie($selector . ':' . $validator);
    }

    private static function deleteById(int $id): void
    {
        self::guard(static function () use ($id): bool {
            Database::execute('DELETE FROM auth_tokens WHERE id = ?', [$id]);
            return true;
        });
    }

    /** @return array{0:string,1:string}|null */
    private static function readCookie(): ?array
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($raw) || !str_contains($raw, ':')) return null;

        [$selector, $validator] = explode(':', $raw, 2);
        // Formato exacto: descarta basura antes de tocar la base de datos.
        if (!preg_match('/^[a-f0-9]{32}$/', $selector)) return null;
        if (!preg_match('/^[a-f0-9]{64}$/', $validator)) return null;

        return [$selector, $validator];
    }

    private static function putCookie(string $value): void
    {
        // También en `$_COOKIE`: dentro de ESTA petición el valor bueno es el
        // que acabamos de emitir, no el que llegó del navegador.
        $_COOKIE[self::COOKIE] = $value;
        if (headers_sent()) return;
        setcookie(self::COOKIE, $value, self::cookieOptions(time() + self::lifetimeSeconds()));
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (headers_sent()) return;
        setcookie(self::COOKIE, '', self::cookieOptions(time() - 42000));
    }

    /** @return array<string,mixed> */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            // Lax y no Strict: con Strict, llegar al panel desde un enlace de
            // fuera (el correo de un formulario, por ejemplo) no mandaría la
            // cookie y el usuario vería el login igualmente.
            'samesite' => 'Lax',
        ];
    }

    private static function hash(string $validator): string
    {
        return hash('sha256', $validator);
    }

    private static function lifetimeSeconds(): int
    {
        return self::LIFETIME_DAYS * 86400;
    }

    private static function expiryDate(): string
    {
        return date('Y-m-d H:i:s', time() + self::lifetimeSeconds());
    }

    /**
     * Nada de esto puede tumbar el panel: si la tabla todavía no existe
     * (instalación sin migrar) o la base de datos se queja, simplemente no hay
     * «recuérdame».
     *
     * @template T
     * @param callable():T $fn
     * @return T|null
     */
    private static function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
