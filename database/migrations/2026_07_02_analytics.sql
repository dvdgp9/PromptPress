-- FEAT-3 Fase A (A2) — Tablas del módulo Analytics.
-- Diseño: cursor/analytics-design.md (aprobado 2026-07-02).
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen estas tablas en `install/schema.sql`.
--
-- Privacidad: analytics_events NO guarda IP ni User-Agent; el visitante se
-- identifica con un hash truncado calculado con un salt diario aleatorio
-- (analytics_salts) que se purga a los 2 días.

-- Salt diario para el hash de visitante. Se purgan filas con day < hoy-2.
CREATE TABLE IF NOT EXISTS analytics_salts (
    day  DATE NOT NULL PRIMARY KEY,
    salt BINARY(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos crudos. Retención 90 días (purga en el job de rollup perezoso).
CREATE TABLE IF NOT EXISTS analytics_events (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id       INT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL,
    event_type    VARCHAR(50) NOT NULL DEFAULT 'pageview',
    path          VARCHAR(255) NOT NULL DEFAULT '/',
    referrer_host VARCHAR(120) DEFAULT NULL,
    device        VARCHAR(10) NOT NULL DEFAULT 'desktop',
    browser       VARCHAR(24) DEFAULT NULL,
    visitor_hash  BINARY(16) NOT NULL,
    INDEX idx_ae_site_time (site_id, created_at),
    CONSTRAINT fk_ae_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollup diario genérico: una fila por (día, dimensión, valor).
-- dimension: 'total' (dim_key=''), 'page', 'referrer', 'device', 'browser', 'event'.
-- Los agregados se conservan indefinidamente (pesan poco).
CREATE TABLE IF NOT EXISTS analytics_daily (
    site_id   INT UNSIGNED NOT NULL,
    day       DATE NOT NULL,
    dimension VARCHAR(12) NOT NULL,
    dim_key   VARCHAR(255) NOT NULL DEFAULT '',
    pageviews INT UNSIGNED NOT NULL DEFAULT 0,
    visitors  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (site_id, day, dimension, dim_key),
    CONSTRAINT fk_ad_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
