-- FORMS-R T3 — usos normalizados de formularios en paginas Canvas.
CREATE TABLE IF NOT EXISTS form_placements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id INT UNSIGNED NOT NULL,
    page_id INT UNSIGNED NOT NULL,
    source_label VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_placement_page (form_id, page_id),
    INDEX idx_form_placements_page (page_id),
    CONSTRAINT fk_form_placements_form FOREIGN KEY (form_id) REFERENCES page_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_placements_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
