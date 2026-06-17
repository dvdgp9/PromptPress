-- EMAIL E3 — Tabla de registro de envíos de correo.
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen esta tabla en `install/schema.sql`.
--
-- `App\Services\Mail\MailService::ensureSchema()` aplica esto automáticamente
-- al primer uso (self-healing). Este archivo queda como referencia canónica.

CREATE TABLE IF NOT EXISTS email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    transport VARCHAR(40) NOT NULL DEFAULT 'smtp',
    context VARCHAR(40) NOT NULL DEFAULT 'other',
    status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
    error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_log_site_date (site_id, created_at),
    INDEX idx_email_log_status (site_id, status),
    CONSTRAINT fk_email_log_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
