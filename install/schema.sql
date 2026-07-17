-- ============================================================
-- PromptPress (PPress) — Database Schema
-- Versión: 0.1.0
-- Engine: InnoDB · Charset: utf8mb4 · Collation: utf8mb4_unicode_ci
-- ============================================================
-- IMPORTANTE: este archivo NO crea la base de datos. Se asume
-- que el usuario ya ha creado el schema (BD) y la conexión activa
-- la usa por defecto. El instalador se encarga del USE.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Tabla: migrations
-- Tracking de migraciones aplicadas (para futuras versiones)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','editor') NOT NULL DEFAULT 'admin',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: sites
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'es',
    timezone VARCHAR(50) NOT NULL DEFAULT 'Europe/Madrid',
    -- D-Slice 1 — Sistema componible (vector + skin + layout preferences).
    personality JSON NULL,
    skin_json JSON NULL,
    layout_preferences JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: site_memory
-- Pares clave/valor de la "memoria del sitio" (qué hace la empresa,
-- público objetivo, tono, servicios, palabras clave, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_memory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_site_field (site_id, field_key),
    CONSTRAINT fk_site_memory_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: pages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL,
    page_type ENUM('home','service','product','landing','article','contact','legal') NOT NULL DEFAULT 'landing',
    parent_id INT UNSIGNED DEFAULT NULL,
    nav_label VARCHAR(255) DEFAULT NULL,
    meta_title VARCHAR(255) DEFAULT NULL,
    meta_description VARCHAR(500) DEFAULT NULL,
    seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
    seo_exclude_sitemap TINYINT(1) NOT NULL DEFAULT 0,
    canonical_url VARCHAR(500) DEFAULT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    tree_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_site_slug (site_id, slug),
    INDEX idx_pages_parent_order (site_id, parent_id, tree_sort_order),
    INDEX idx_pages_status (site_id, status),
    INDEX idx_pages_type (site_id, page_type),
    CONSTRAINT fk_pages_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_pages_parent
        FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_pages_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: page_sections
-- Secciones tipadas de cada página. La IA SOLO modifica esta tabla,
-- nunca HTML directo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS page_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    section_type VARCHAR(50) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    content JSON NOT NULL,
    style JSON DEFAULT NULL,
    status ENUM('editable','locked','deleted') NOT NULL DEFAULT 'editable',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_page_order (page_id, sort_order),
    CONSTRAINT fk_sections_page
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: post_meta (F21 — Entradas / Blog)
-- Metadatos específicos de entradas de blog. Cada entrada es una página
-- con page_type='article'; aquí guardamos los campos que NO comparte con
-- páginas normales (excerpt, imagen destacada, tiempo de lectura, autor).
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabla: form_submissions
-- Leads recibidos desde secciones públicas de tipo formulario.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    page_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    page_title VARCHAR(500) NOT NULL,
    section_heading VARCHAR(255) DEFAULT NULL,
    sender_name VARCHAR(255) DEFAULT NULL,
    sender_email VARCHAR(255) DEFAULT NULL,
    sender_phone VARCHAR(100) DEFAULT NULL,
    payload JSON NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    bot_check VARCHAR(16) NOT NULL DEFAULT 'none',
    status ENUM('unread','read') NOT NULL DEFAULT 'unread',
    email_status ENUM('skipped','sent','failed') NOT NULL DEFAULT 'skipped',
    email_error VARCHAR(500) DEFAULT NULL,
    autoresponder_status ENUM('unknown','disabled','skipped','sent','failed') NOT NULL DEFAULT 'unknown',
    autoresponder_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    INDEX idx_form_submissions_site_date (site_id, created_at),
    INDEX idx_form_submissions_page_date (page_id, created_at),
    INDEX idx_form_submissions_section_date (section_id, created_at),
    INDEX idx_form_submissions_status (site_id, status),
    INDEX idx_form_submissions_rate (section_id, ip_hash, created_at),
    CONSTRAINT fk_form_submissions_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_submissions_page
        FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_submissions_section
        FOREIGN KEY (section_id) REFERENCES page_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: form_placements
-- Usos normalizados de formularios en paginas Canvas.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabla: media
-- Imágenes y otros archivos accesibles públicamente
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    path VARCHAR(500) NOT NULL,
    alt_text VARCHAR(500) DEFAULT NULL,
    width INT UNSIGNED DEFAULT NULL,
    height INT UNSIGNED DEFAULT NULL,
    uploaded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- T18.4 — origen de la imagen para atribución (Unsplash u otros bancos).
    source VARCHAR(20) NOT NULL DEFAULT 'upload',
    source_id VARCHAR(120) DEFAULT NULL,
    source_url VARCHAR(500) DEFAULT NULL,
    attribution_name VARCHAR(160) DEFAULT NULL,
    attribution_url VARCHAR(500) DEFAULT NULL,
    INDEX idx_media_site (site_id, created_at),
    INDEX idx_media_source (source, source_id),
    CONSTRAINT fk_media_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_media_user
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: documents
-- Documentos base (PDF/DOCX/TXT) usados como contexto IA
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_type ENUM('pdf','docx','txt') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    extracted_text LONGTEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    status ENUM('processing','ready','error') NOT NULL DEFAULT 'processing',
    uploaded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_docs_site (site_id, status),
    CONSTRAINT fk_docs_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_docs_user
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: design_system
-- Tokens del design system (colors, typography, buttons, spacing,
-- components) almacenados como JSON por categoría.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS design_system (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    tokens JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_site_category (site_id, category),
    CONSTRAINT fk_design_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: ai_logs
-- Tracking de cada llamada a IA: tokens, coste, modelo, acción
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(100) NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    tokens_input INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_output INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost DECIMAL(10,6) NOT NULL DEFAULT 0,
    request_data JSON DEFAULT NULL,
    response_data JSON DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    status ENUM('success','error') NOT NULL DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_site_date (site_id, created_at),
    INDEX idx_logs_action (action_type),
    INDEX idx_logs_provider (provider, model),
    CONSTRAINT fk_logs_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_logs_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: settings
-- Configuración por sitio (clave/valor). API keys con is_encrypted=1
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_site_setting (site_id, setting_key),
    CONSTRAINT fk_settings_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: versions
-- Snapshots de entidades (page_section, page) antes de ediciones
-- importantes (especialmente antes de ediciones IA)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    version_data JSON NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_versions_entity (entity_type, entity_id, created_at),
    CONSTRAINT fk_versions_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabla: site_compliance
-- E-GDPR G1 — Manifest de privacidad por sitio (datos del responsable,
-- features, tracking activo, processors, referencias a páginas legales
-- y textos del banner de cookies).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_compliance (
    site_id INT UNSIGNED NOT NULL PRIMARY KEY,
    manifest JSON NOT NULL,
    manifest_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_compliance_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tablas SEO (FH13 SEO-1)
-- Redirecciones y monitor 404 para el panel SEO nativo.
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabla: email_log (EMAIL E3)
-- Registro de envíos de correo (notificaciones de formulario,
-- autorespuestas, correos de prueba). Diagnóstico legible en `error`.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    transport VARCHAR(40) NOT NULL DEFAULT 'smtp',
    context VARCHAR(40) NOT NULL DEFAULT 'other',
    status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
    error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_log_site_date (site_id, created_at),
    INDEX idx_email_log_status (site_id, status),
    CONSTRAINT fk_email_log_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Módulo Analytics (FEAT-3 A2) — ver cursor/analytics-design.md.
-- Sin IP ni User-Agent persistidos: visitante = hash con salt diario rotativo.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analytics_salts (
    day  DATE NOT NULL PRIMARY KEY,
    salt BINARY(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ------------------------------------------------------------
-- Módulo Booking (FEAT-3 Fase B) — ver cursor/booking-design.md
-- booking_hours.start/end_time en zona del sitio; booking_bookings en UTC.
-- ------------------------------------------------------------

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

-- ------------------------------------------------------------
-- Módulo Commerce / PromptCommerce (FEAT-3 Fase C) — ver cursor/commerce-design.md
-- Importes en céntimos; EUR fijo v1; líneas de pedido con snapshot.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS commerce_products (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id      INT UNSIGNED NOT NULL,
    name         VARCHAR(160) NOT NULL,
    slug         VARCHAR(180) NOT NULL,
    description  TEXT NULL,
    price_cents  INT UNSIGNED NOT NULL DEFAULT 0,
    tax_rate     DECIMAL(4,2) NOT NULL DEFAULT 21.00,
    stock        INT UNSIGNED DEFAULT NULL,
    media_id     INT UNSIGNED DEFAULT NULL,
    active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL,
    updated_at   DATETIME NOT NULL,
    UNIQUE KEY uq_cp_slug (site_id, slug),
    INDEX idx_cp_site (site_id, active),
    CONSTRAINT fk_cp_site  FOREIGN KEY (site_id)  REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NOT NULL,
    order_number    VARCHAR(20) NOT NULL,
    status          ENUM('pending_payment','paid','shipped','cancelled') NOT NULL DEFAULT 'pending_payment',
    payment_method  VARCHAR(20) NOT NULL,
    payment_ref     VARCHAR(120) DEFAULT NULL,
    access_key      CHAR(32) NOT NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'EUR',
    subtotal_cents  INT UNSIGNED NOT NULL,
    shipping_cents  INT UNSIGNED NOT NULL DEFAULT 0,
    tax_cents       INT UNSIGNED NOT NULL,
    total_cents     INT UNSIGNED NOT NULL,
    customer_name   VARCHAR(120) NOT NULL,
    customer_email  VARCHAR(190) NOT NULL,
    customer_phone  VARCHAR(40) DEFAULT NULL,
    ship_address    VARCHAR(200) DEFAULT NULL,
    ship_city       VARCHAR(80) DEFAULT NULL,
    ship_postcode   VARCHAR(12) DEFAULT NULL,
    ship_province   VARCHAR(80) DEFAULT NULL,
    notes           TEXT NULL,
    admin_notes     TEXT NULL,
    ip_hash         CHAR(64) DEFAULT NULL,
    email_status    VARCHAR(12) NOT NULL DEFAULT 'unknown',
    email_error     VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    UNIQUE KEY uq_co_number (site_id, order_number),
    INDEX idx_co_status (site_id, status, created_at),
    CONSTRAINT fk_co_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_order_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id         INT UNSIGNED NOT NULL,
    product_id       INT UNSIGNED DEFAULT NULL,
    product_name     VARCHAR(160) NOT NULL,
    unit_price_cents INT UNSIGNED NOT NULL,
    tax_rate         DECIMAL(4,2) NOT NULL,
    quantity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    line_total_cents INT UNSIGNED NOT NULL,
    INDEX idx_ci_order (order_id),
    CONSTRAINT fk_ci_order   FOREIGN KEY (order_id)   REFERENCES commerce_orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ci_product FOREIGN KEY (product_id) REFERENCES commerce_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FEAT-4 — BotGuard: anti-replay del proof-of-work (2026_07_07_botguard)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS botguard_solved (
    challenge_hash CHAR(64) NOT NULL PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_bgs_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FEAT-5 — Asistente central: jobs de cambios multi-página (2026_07_17_assistant_jobs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assistant_jobs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id      INT UNSIGNED NOT NULL,
    request_text TEXT NOT NULL,
    summary      TEXT NULL,
    status       ENUM('pending','running','done') NOT NULL DEFAULT 'pending',
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aj_site (site_id, created_at),
    CONSTRAINT fk_aj_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assistant_job_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id       INT UNSIGNED NOT NULL,
    page_id      INT UNSIGNED NOT NULL,
    page_title   VARCHAR(255) NOT NULL DEFAULT '',
    section      VARCHAR(120) NOT NULL DEFAULT '',
    instruction  TEXT NOT NULL,
    status       ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    reply        TEXT NULL,
    error        TEXT NULL,
    sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aji_job (job_id, sort_order),
    CONSTRAINT fk_aji_job
        FOREIGN KEY (job_id) REFERENCES assistant_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Migración inicial registrada
-- ------------------------------------------------------------
INSERT INTO migrations (name) VALUES ('0001_initial_schema')
    ON DUPLICATE KEY UPDATE applied_at = applied_at;
INSERT INTO migrations (name) VALUES ('0002_page_hierarchy')
    ON DUPLICATE KEY UPDATE applied_at = applied_at;
