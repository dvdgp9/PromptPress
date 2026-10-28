<?php

declare(strict_types=1);

/**
 * ASST-V2 F1 — Conversaciones del asistente que sobreviven a recargar.
 *
 * Hasta ahora la conversación vivía solo en la pestaña: recargar perdía lo
 * hablado y el plan pendiente. Una fila por conversación y persona, con lo que
 * el navegador necesita para retomarla tal cual:
 *   - state_json: turnos, plan vigente y token firmado del material (lo que se
 *     reenvía al planner; el token caduca solo a las 2 h, ver AssistantSourceEnvelope)
 *   - log_json: lo que se pinta en el hilo (mensajes, planes, notas de aplicado)
 *
 * Se borran a los 30 días (AssistantConversationStore::prune): son peticiones
 * de clientes y no hace falta guardarlas más.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assistant_conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            title VARCHAR(160) NOT NULL DEFAULT '',
            state_json MEDIUMTEXT NULL,
            log_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ac_owner (site_id, user_id, updated_at),
            CONSTRAINT fk_ac_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
            CONSTRAINT fk_ac_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
