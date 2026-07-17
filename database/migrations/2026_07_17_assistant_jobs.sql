-- FEAT-5 F5-T4 — Asistente central: trabajos de aplicación de cambios.
--
-- Un job = una tanda confirmada por el usuario desde /admin/assistant.
-- Sus items se ejecutan UNO A UNO (endpoint "step" llamado en bucle por el
-- navegador); cada item aplicado crea una versión draft en la página vía
-- CanvasChatService (origin 'assistant').
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen estas tablas en `install/schema.sql`.

CREATE TABLE IF NOT EXISTS assistant_jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id      INT UNSIGNED NOT NULL,
    request_text TEXT NOT NULL,
    summary      TEXT NULL,
    status       ENUM('pending','running','done') NOT NULL DEFAULT 'pending',
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aj_site (site_id, created_at),
    CONSTRAINT fk_aj_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistant_job_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id       INT UNSIGNED NOT NULL,
    page_id      INT UNSIGNED NOT NULL,
    page_title   VARCHAR(255) NOT NULL DEFAULT '',
    section      VARCHAR(120) NOT NULL DEFAULT '',
    instruction  TEXT NOT NULL,
    status       ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    reply        TEXT NULL,
    error        TEXT NULL,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aji_job (job_id, sort_order),
    CONSTRAINT fk_aji_job
        FOREIGN KEY (job_id) REFERENCES assistant_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
