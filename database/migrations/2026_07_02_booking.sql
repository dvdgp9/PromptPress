-- FEAT-3 Fase B (B2) — Tablas del módulo Booking (Reservas).
-- Diseño: cursor/booking-design.md (aprobado 2026-07-02).
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen estas tablas en `install/schema.sql`.
--
-- Zona horaria: booking_hours.start_time/end_time se interpretan en la zona
-- del sitio (sites.timezone); booking_bookings guarda SIEMPRE UTC.

-- Servicios reservables.
CREATE TABLE IF NOT EXISTS booking_services (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id          INT UNSIGNED NOT NULL,
    name             VARCHAR(120) NOT NULL,
    description      TEXT NULL,
    duration_min     SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    buffer_min       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    capacity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    min_notice_hours SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    max_advance_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    auto_confirm     TINYINT(1) NOT NULL DEFAULT 0,
    price_label      VARCHAR(60) DEFAULT NULL,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    INDEX idx_bs_site (site_id, active),
    CONSTRAINT fk_bs_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario semanal recurrente + excepciones de fecha, en una sola tabla.
--   Recurrente: weekday 0-6 (0=lunes), date NULL, franja start/end.
--   Excepción:  date concreta, weekday NULL. closed=1 → día cerrado;
--               closed=0 con franja → horario especial que sustituye al recurrente.
--   service_id NULL = aplica a todos los servicios del sitio (festivo global).
CREATE TABLE IF NOT EXISTS booking_hours (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id    INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED DEFAULT NULL,
    weekday    TINYINT UNSIGNED DEFAULT NULL,
    date       DATE DEFAULT NULL,
    start_time TIME DEFAULT NULL,
    end_time   TIME DEFAULT NULL,
    closed     TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_bh_service (service_id, weekday),
    INDEX idx_bh_date (site_id, date),
    CONSTRAINT fk_bh_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_bh_service
        FOREIGN KEY (service_id) REFERENCES booking_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservas. Fechas en UTC.
CREATE TABLE IF NOT EXISTS booking_bookings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id        INT UNSIGNED NOT NULL,
    service_id     INT UNSIGNED NOT NULL,
    starts_at_utc  DATETIME NOT NULL,
    ends_at_utc    DATETIME NOT NULL,
    status         ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    customer_name  VARCHAR(120) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,
    notes          TEXT NULL,
    admin_notes    TEXT NULL,
    cancel_token   CHAR(32) NOT NULL,
    ip_hash        CHAR(64) DEFAULT NULL,
    email_status   VARCHAR(12) NOT NULL DEFAULT 'unknown',
    email_error    VARCHAR(255) DEFAULT NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    INDEX idx_bb_slot (service_id, starts_at_utc, status),
    INDEX idx_bb_site_time (site_id, starts_at_utc),
    INDEX idx_bb_ip (site_id, ip_hash, created_at),
    CONSTRAINT fk_bb_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_bb_service
        FOREIGN KEY (service_id) REFERENCES booking_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
