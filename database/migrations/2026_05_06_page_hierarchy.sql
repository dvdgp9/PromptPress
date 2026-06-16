-- F15/T23 baseline — Jerarquía persistente de páginas.
-- Idempotente: el runner convierte ADD COLUMN/INDEX IF NOT EXISTS en checks
-- compatibles con MySQL/MariaDB aunque el motor no soporte esa sintaxis.

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED DEFAULT NULL AFTER page_type,
    ADD COLUMN IF NOT EXISTS nav_label VARCHAR(255) DEFAULT NULL AFTER parent_id,
    ADD COLUMN IF NOT EXISTS tree_sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER sort_order;

ALTER TABLE pages
    ADD INDEX IF NOT EXISTS idx_pages_parent_order (site_id, parent_id, tree_sort_order);
