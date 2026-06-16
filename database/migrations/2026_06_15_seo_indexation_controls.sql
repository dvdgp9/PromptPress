-- FH13 SEO-3 — Controles avanzados de indexación por página.

ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER meta_description,
    ADD COLUMN IF NOT EXISTS seo_exclude_sitemap TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_noindex,
    ADD COLUMN IF NOT EXISTS canonical_url VARCHAR(500) DEFAULT NULL AFTER seo_exclude_sitemap;

