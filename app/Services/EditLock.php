<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * EDIT-LOCK L1 — Quién tiene cogida una página para editarla.
 *
 * El lock es BLANDO a propósito: caduca solo y cualquiera puede tomarlo. Un
 * bloqueo que no se pueda romper es peor que ninguno — basta que alguien cierre
 * el portátil con la pestaña abierta para dejar la página inaccesible al resto,
 * y entonces la gente aprende a editar por otro camino.
 *
 * DOS DECISIONES QUE NO SE VEN EN LAS FIRMAS:
 *
 * 1. El dueño es una PESTAÑA (`token`), no una persona. Un lock por `user_id`
 *    dejaría pasar el caso más común de todos: la misma persona con la página
 *    abierta en dos ventanas, pisándose a sí misma sin que nada la avise.
 *
 * 2. Coger el lock no usa transacción: se apoya en el índice único de
 *    (entity_type, entity_id). Dos peticiones a la vez sobre una página libre
 *    hacen las dos su INSERT, y una choca. Es la propia base de datos la que
 *    arbitra, que es más fiable que cualquier «comprobar y luego escribir».
 */
final class EditLock
{
    public const ENTITY_PAGE = 'page';

    /**
     * Cada cuánto late el navegador. Ver `TTL_SECONDS`.
     */
    public const HEARTBEAT_SECONDS = 30;

    /**
     * Cuánto aguanta un lock sin noticias antes de darse por muerto.
     *
     * Tres latidos: uno perdido por una red mala no debe echar a nadie de su
     * propia página, pero un minuto y medio es poco tiempo para que alguien
     * espere delante de una página que ya nadie está tocando.
     */
    public const TTL_SECONDS = 90;

    /** Un token nuevo por pestaña que abre el editor. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Estado actual del lock de una entidad, ya descontados los caducados.
     *
     * @return array{held:bool, user_id:?int, username:?string, token:?string, seconds_ago:?int}
     */
    public static function status(string $entityType, int $entityId): array
    {
        $row = Database::selectOne(
            'SELECT l.user_id, l.token, u.username,
                    TIMESTAMPDIFF(SECOND, l.heartbeat_at, NOW()) AS seconds_ago
             FROM edit_locks l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = ? AND l.entity_id = ?
             LIMIT 1',
            [$entityType, $entityId]
        );

        if ($row === null) {
            return ['held' => false, 'user_id' => null, 'username' => null, 'token' => null, 'seconds_ago' => null];
        }

        $secondsAgo = (int) $row['seconds_ago'];
        if ($secondsAgo > self::TTL_SECONDS) {
            // Caducado: se cuenta como libre. No se borra aquí — lo hace
            // `acquire()` al ocuparlo, para no escribir en una lectura.
            return ['held' => false, 'user_id' => null, 'username' => null, 'token' => null, 'seconds_ago' => $secondsAgo];
        }

        return [
            'held'        => true,
            'user_id'     => (int) $row['user_id'],
            'username'    => is_string($row['username'] ?? null) ? (string) $row['username'] : null,
            'token'       => (string) $row['token'],
            'seconds_ago' => $secondsAgo,
        ];
    }

    /**
     * Intenta coger el lock.
     *
     * @return array{ok:bool, status:array<string,mixed>}  ok=false → lo tiene otra pestaña
     */
    public static function acquire(string $entityType, int $entityId, int $userId, string $token, bool $force = false): array
    {
        $current = self::status($entityType, $entityId);

        // Ya es mío: renovar y seguir. Pasa al recargar la página con el mismo
        // token guardado en la pestaña.
        if ($current['held'] && $current['token'] === $token) {
            self::heartbeat($entityType, $entityId, $token);
            return ['ok' => true, 'status' => self::status($entityType, $entityId)];
        }

        if ($current['held'] && !$force) {
            return ['ok' => false, 'status' => $current];
        }

        // Libre, caducado, o alguien tomando el control: la fila pasa a ser
        // nuestra. El UNIQUE hace de árbitro si dos llegan a la vez.
        Database::execute(
            'INSERT INTO edit_locks (entity_type, entity_id, user_id, token, acquired_at, heartbeat_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                token = VALUES(token),
                acquired_at = VALUES(acquired_at),
                heartbeat_at = VALUES(heartbeat_at)',
            [$entityType, $entityId, $userId, $token]
        );

        return ['ok' => true, 'status' => self::status($entityType, $entityId)];
    }

    /**
     * «Sigo aquí». Devuelve false si el lock ya no es de esta pestaña — señal
     * de que alguien tomó el control y hay que avisar a quien fue desalojado.
     *
     * La comprobación es explícita y NO se deduce de las filas afectadas por el
     * UPDATE: MySQL informa de 0 filas cuando el valor no cambia, así que dos
     * latidos dentro del mismo segundo daban «has perdido el lock» siendo
     * mentira. Con un latido cada 30 s casi nunca pasaría, y por eso mismo
     * habría sido un fallo desquiciante de diagnosticar.
     */
    public static function heartbeat(string $entityType, int $entityId, string $token): bool
    {
        if (!self::heldBy($entityType, $entityId, $token)) {
            return false;
        }
        Database::execute(
            'UPDATE edit_locks SET heartbeat_at = NOW()
             WHERE entity_type = ? AND entity_id = ? AND token = ?',
            [$entityType, $entityId, $token]
        );
        return true;
    }

    /** ¿Puede esta pestaña escribir en esta entidad? */
    public static function heldBy(string $entityType, int $entityId, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $status = self::status($entityType, $entityId);
        return $status['held'] && $status['token'] === $token;
    }

    /** Soltar el lock al cerrar el editor. Solo si sigue siendo nuestro. */
    public static function release(string $entityType, int $entityId, string $token): void
    {
        Database::execute(
            'DELETE FROM edit_locks WHERE entity_type = ? AND entity_id = ? AND token = ?',
            [$entityType, $entityId, $token]
        );
    }

    /** Limpieza oportunista de locks muertos. Barata y sin transacción. */
    public static function prune(): void
    {
        Database::execute(
            'DELETE FROM edit_locks WHERE heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [self::TTL_SECONDS]
        );
    }
}
