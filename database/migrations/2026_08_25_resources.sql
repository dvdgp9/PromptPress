-- FEAT-RESOURCES R1 — entidad de recursos descargables.
--
-- El archivo vive fuera del acceso público y sus metadatos son NULL mientras
-- el recurso está incompleto. Publicar exige el contrato que valida
-- ResourceStore. La subida/descarga física llega en R2.

CREATE TABLE IF NOT EXISTS resources (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id           INT UNSIGNED NOT NULL,
    title             VARCHAR(180) NOT NULL,
    slug              VARCHAR(180) NOT NULL,
    description       TEXT NULL,
    category          VARCHAR(100) NULL,
    cover_media_id    INT UNSIGNED DEFAULT NULL,
    file_path         VARCHAR(500) DEFAULT NULL,
    original_filename VARCHAR(255) DEFAULT NULL,
    file_mime         VARCHAR(100) DEFAULT NULL,
    file_size         INT UNSIGNED DEFAULT NULL,
    access_mode       ENUM('direct','form') NOT NULL DEFAULT 'direct',
    form_id           INT UNSIGNED DEFAULT NULL,
    language          VARCHAR(5) NOT NULL,
    translation_group CHAR(36) NOT NULL,
    status            ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at      DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    UNIQUE KEY uq_resources_slug (site_id, language, slug),
    INDEX idx_resources_public (site_id, language, status, published_at),
    INDEX idx_resources_translation (translation_group),
    INDEX idx_resources_form (form_id),
    CONSTRAINT fk_resources_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_resources_cover
        FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
    CONSTRAINT fk_resources_form
        FOREIGN KEY (form_id) REFERENCES page_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

