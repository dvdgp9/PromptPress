-- FEAT-3 Fase C (C2) — Tablas del módulo Commerce (PromptCommerce).
-- Diseño: cursor/commerce-design.md (aprobado 2026-07-02).
--
-- Aplicar UNA vez en instalaciones existentes. Las nuevas instalaciones
-- ya tienen estas tablas en `install/schema.sql`.
--
-- Importes SIEMPRE en céntimos (enteros); moneda EUR fija en v1. Las líneas
-- del pedido guardan snapshot de nombre/precio para que editar o borrar un
-- producto no altere pedidos históricos.

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
