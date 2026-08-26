-- FEAT-RESOURCES R8 — un recurso puede estar disponible en varios idiomas o
-- en todos (incluidos los que se activen en el futuro).

ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS language_scope ENUM('selected','all') NOT NULL DEFAULT 'selected' AFTER language;

CREATE TABLE IF NOT EXISTS resource_languages (
    resource_id INT UNSIGNED NOT NULL,
    language    VARCHAR(5) NOT NULL,
    PRIMARY KEY (resource_id, language),
    INDEX idx_resource_languages_language (language, resource_id),
    CONSTRAINT fk_resource_languages_resource
        FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidad: cada recurso existente conserva exactamente su visibilidad
-- anterior hasta que el gestor elija más idiomas o "todos".
INSERT IGNORE INTO resource_languages (resource_id, language)
SELECT id, language FROM resources;
