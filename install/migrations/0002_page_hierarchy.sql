ALTER TABLE pages
    ADD COLUMN parent_id INT UNSIGNED DEFAULT NULL AFTER page_type,
    ADD COLUMN nav_label VARCHAR(255) DEFAULT NULL AFTER parent_id,
    ADD COLUMN tree_sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER sort_order,
    ADD INDEX idx_pages_parent_order (site_id, parent_id, tree_sort_order),
    ADD CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL;
