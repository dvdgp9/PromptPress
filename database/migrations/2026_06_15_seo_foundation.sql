-- FH13 SEO-1 — Base del panel SEO nativo.
--
-- `seo_redirects`: gestor de redirecciones manuales y 301 automáticas al
-- cambiar slugs publicados.
-- `seo_404_logs`: monitor agregado de URLs públicas que terminan en 404.

CREATE TABLE IF NOT EXISTS seo_redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    target_path VARCHAR(500) DEFAULT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    match_type ENUM('exact') NOT NULL DEFAULT 'exact',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    auto_created TINYINT(1) NOT NULL DEFAULT 0,
    source_page_id INT UNSIGNED DEFAULT NULL,
    target_page_id INT UNSIGNED DEFAULT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_seo_redirect_source (site_id, source_path),
    INDEX idx_seo_redirect_lookup (site_id, is_active, source_path),
    INDEX idx_seo_redirect_target_page (target_page_id),
    CONSTRAINT fk_seo_redirect_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_redirect_source_page
        FOREIGN KEY (source_page_id) REFERENCES pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_seo_redirect_target_page
        FOREIGN KEY (target_page_id) REFERENCES pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_seo_redirect_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_404_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    request_hash CHAR(64) NOT NULL,
    requested_path VARCHAR(500) NOT NULL,
    query_string VARCHAR(1000) DEFAULT NULL,
    referrer VARCHAR(1000) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('open','ignored','resolved') NOT NULL DEFAULT 'open',
    redirect_id INT UNSIGNED DEFAULT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_seo_404_request (site_id, request_hash),
    INDEX idx_seo_404_status (site_id, status, last_seen_at),
    INDEX idx_seo_404_path (site_id, requested_path),
    CONSTRAINT fk_seo_404_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_seo_404_redirect
        FOREIGN KEY (redirect_id) REFERENCES seo_redirects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
