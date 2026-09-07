<?php

declare(strict_types=1);

/**
 * SESION-RECUERDA SR-1 — «Mantener la sesión iniciada» en el login.
 *
 * La sesión de PHP se queda como está (corta, y muere al cerrar el navegador).
 * Esta tabla guarda un token aparte cuya única misión es REABRIRLA sola.
 *
 * Patrón selector/validador: la cookie lleva `selector:validador`; el selector
 * busca la fila (por eso está indexado) y el validador se guarda solo hasheado,
 * de modo que quien lea esta tabla no puede fabricar una cookie válida.
 *
 * `prev_validator_hash` no es paranoia: el validador se renueva en cada uso, y
 * si el navegador dispara dos peticiones a la vez, la segunda llegaría con el
 * validador ya rotado y echaría al usuario. Durante `prev_valid_until` valen
 * los dos.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selector CHAR(32) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            prev_validator_hash CHAR(64) DEFAULT NULL,
            prev_valid_until DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE KEY uk_auth_tokens_selector (selector),
            KEY idx_auth_tokens_user (user_id),
            KEY idx_auth_tokens_expires (expires_at),
            CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id)
                REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
