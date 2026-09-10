<?php

declare(strict_types=1);

/**
 * EDIT-LOCK L1 — Quién está editando qué, ahora mismo.
 *
 * Una fila por entidad editable (hoy: una página). El índice único sobre
 * (entity_type, entity_id) es lo que convierte «coger el lock» en una carrera
 * que gana uno solo, sin transacción explícita: el segundo INSERT choca.
 *
 * `token` identifica la PESTAÑA, no a la persona. Un lock por `user_id` no ve
 * el caso más común de todos —la misma persona con la página abierta en dos
 * sitios— y ese es justo el que se cuela por debajo de cualquier aviso.
 *
 * `heartbeat_at` es lo que mantiene vivo el lock. Sin él no habría forma de
 * distinguir «está editando» de «cerró el portátil con la pestaña abierta», y
 * la página quedaría bloqueada para siempre.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS edit_locks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(20) NOT NULL DEFAULT 'page',
            entity_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            token CHAR(32) NOT NULL,
            acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_edit_locks_entity (entity_type, entity_id),
            KEY idx_edit_locks_heartbeat (heartbeat_at),
            CONSTRAINT fk_edit_locks_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
