-- FEAT-4 AB2 — Anti-replay del proof-of-work de BotGuard.
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen esta tabla en `install/schema.sql`. (BotGuard::ensureSchema
-- también la crea perezosamente si falta.)
--
-- Cada reto PoW resuelto se consume una sola vez: se guarda el hash del
-- reto hasta su caducidad (2 h) y se purga perezosamente en cada verificación.

-- AB4 — auditoría del escudo por envío: 'pow' (resolvió el proof-of-work),
-- 'timetrap' (aceptado solo con las capas base, p.ej. sin JS), 'none' (filas
-- anteriores a FEAT-4).
ALTER TABLE form_submissions
    ADD COLUMN bot_check VARCHAR(16) NOT NULL DEFAULT 'none' AFTER user_agent;

CREATE TABLE IF NOT EXISTS botguard_solved (
    challenge_hash CHAR(64) NOT NULL PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_bgs_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
