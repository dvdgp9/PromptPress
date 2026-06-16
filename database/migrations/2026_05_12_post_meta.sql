-- F21.T21.1 — Tabla auxiliar para metadatos de entradas (blog).
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen esta tabla en `install/schema.sql`.
--
-- `PostMetaService::ensureSchema()` aplica esto automáticamente al primer
-- uso (self-healing). Este archivo queda como referencia canónica.

CREATE TABLE IF NOT EXISTS post_meta (
    page_id INT UNSIGNED NOT NULL PRIMARY KEY,
    excerpt VARCHAR(500) DEFAULT NULL,
    featured_image_path VARCHAR(500) DEFAULT NULL,
    featured_image_alt VARCHAR(255) DEFAULT NULL,
    reading_minutes SMALLINT UNSIGNED DEFAULT NULL,
    author_name VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_post_meta_page
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
