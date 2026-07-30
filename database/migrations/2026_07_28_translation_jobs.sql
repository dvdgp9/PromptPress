-- I18N-FULL T5.5 — Trabajos de traducción masiva.
--
-- Se reutiliza el DISEÑO probado de `assistant_jobs` (un item por petición, un
-- fallo no detiene los demás, el navegador llama a "step" en bucle), pero NO
-- sus tablas: aquellas están modeladas para "aplicar una instrucción a una
-- sección" y meter aquí la traducción obligaría a reaprovechar columnas para
-- otra cosa (`instruction` como idioma, `reply` como id de la página creada).
-- Eso es justo lo que se acaba pagando meses después.
--
-- Tablas propias, columnas explícitas y cero riesgo para el asistente central,
-- que ya está en producción.

CREATE TABLE IF NOT EXISTS translation_jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id      INT UNSIGNED NOT NULL,
    target_lang  VARCHAR(5) NOT NULL,
    status       ENUM('pending','running','done') NOT NULL DEFAULT 'pending',
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tj_site (site_id, created_at),
    CONSTRAINT fk_tj_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_job_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id       INT UNSIGNED NOT NULL,
    page_id      INT UNSIGNED NOT NULL,
    page_title   VARCHAR(255) NOT NULL DEFAULT '',
    status       ENUM('pending','running','done','failed','skipped') NOT NULL DEFAULT 'pending',
    new_page_id  INT UNSIGNED NULL,
    error        TEXT NULL,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tji_job (job_id, sort_order),
    CONSTRAINT fk_tji_job
        FOREIGN KEY (job_id) REFERENCES translation_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
