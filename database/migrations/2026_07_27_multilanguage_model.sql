-- I18N-FULL Fase 1 — Modelo de datos del multi-idioma.
--
-- Un sitio pasa de tener UN idioma (`sites.language`) a poder tener varios:
-- el principal, que conserva las URLs actuales sin prefijo, y los adicionales,
-- que se activan uno a uno desde Ajustes (opt-in).
--
-- Esta migración NO cambia el comportamiento de ninguna web existente: cada
-- sitio queda con su idioma actual como principal y único, y cada página con
-- ese idioma y un grupo de traducción propio.

-- ---------------------------------------------------------------------------
-- T1.1 · Páginas: idioma propio + grupo que hermana las traducciones
-- ---------------------------------------------------------------------------
ALTER TABLE pages
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'es' AFTER page_type,
    ADD COLUMN IF NOT EXISTS translation_group CHAR(36) DEFAULT NULL AFTER language;

ALTER TABLE pages
    ADD INDEX IF NOT EXISTS idx_pages_language (site_id, language, status),
    ADD INDEX IF NOT EXISTS idx_pages_translation_group (translation_group);

-- Backfill: el idioma real del sitio, no un 'es' a lo bruto.
UPDATE pages p
    JOIN sites s ON s.id = p.site_id
    SET p.language = s.language
    WHERE s.language IS NOT NULL AND s.language <> '';

-- Cada página existente arranca en su propio grupo (todavía no hay
-- traducciones). UUID() se evalúa por fila, que es justo lo que queremos.
UPDATE pages SET translation_group = UUID()
    WHERE translation_group IS NULL OR translation_group = '';

-- ---------------------------------------------------------------------------
-- T1.2 · El idioma viaja con el pedido y con la reserva
--
-- El idioma correcto de un email de pedido es el del CLIENTE (aquel con el que
-- compró), no el del sitio. Los mailers ya leen esta columna si existe, así que
-- en cuanto se rellene dejan de depender de `sites.language` sin tocar código.
-- ---------------------------------------------------------------------------
ALTER TABLE commerce_orders
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'es' AFTER currency;

ALTER TABLE booking_bookings
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'es' AFTER status;

UPDATE commerce_orders o
    JOIN sites s ON s.id = o.site_id
    SET o.language = s.language
    WHERE s.language IS NOT NULL AND s.language <> '';

UPDATE booking_bookings b
    JOIN sites s ON s.id = b.site_id
    SET b.language = s.language
    WHERE s.language IS NOT NULL AND s.language <> '';

-- ---------------------------------------------------------------------------
-- T1.3 · Idiomas activos por sitio (opt-in)
--
-- Un sitio sin tocar tiene UNA fila: su idioma actual como principal. Mientras
-- solo haya una, el sitio se comporta exactamente como hasta ahora.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_languages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    code VARCHAR(5) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_site_language (site_id, code),
    INDEX idx_site_languages_primary (site_id, is_primary),
    CONSTRAINT fk_site_languages_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_languages (site_id, code, is_primary, sort_order)
    SELECT s.id, s.language, 1, 0
    FROM sites s
    WHERE s.language IS NOT NULL AND s.language <> ''
      AND NOT EXISTS (SELECT 1 FROM site_languages sl WHERE sl.site_id = s.id AND sl.code = s.language);

-- ---------------------------------------------------------------------------
-- T1.5 · Catálogo preparado para traducciones
--
-- Solo el MODELO: el editor y la traducción con IA del catálogo son fase
-- posterior. Se hace ahora para no volver a migrar con pedidos reales en
-- producción.
-- ---------------------------------------------------------------------------
ALTER TABLE commerce_products
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'es' AFTER slug,
    ADD COLUMN IF NOT EXISTS translation_group CHAR(36) DEFAULT NULL AFTER language;

ALTER TABLE booking_services
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) NOT NULL DEFAULT 'es' AFTER name,
    ADD COLUMN IF NOT EXISTS translation_group CHAR(36) DEFAULT NULL AFTER language;

UPDATE commerce_products p
    JOIN sites s ON s.id = p.site_id
    SET p.language = s.language
    WHERE s.language IS NOT NULL AND s.language <> '';

UPDATE booking_services b
    JOIN sites s ON s.id = b.site_id
    SET b.language = s.language
    WHERE s.language IS NOT NULL AND s.language <> '';

UPDATE commerce_products SET translation_group = UUID()
    WHERE translation_group IS NULL OR translation_group = '';

UPDATE booking_services SET translation_group = UUID()
    WHERE translation_group IS NULL OR translation_group = '';

-- El UNIQUE de slug era (site_id, slug): impedía que existiera la variante
-- francesa del mismo producto. Pasa a incluir el idioma. Es seguro sobre datos
-- existentes: si (site_id, slug) era único, (site_id, language, slug) también.
ALTER TABLE commerce_products DROP INDEX IF EXISTS uq_cp_slug;
ALTER TABLE commerce_products ADD UNIQUE KEY uq_cp_slug (site_id, language, slug);

ALTER TABLE commerce_products
    ADD INDEX IF NOT EXISTS idx_cp_translation_group (translation_group);

ALTER TABLE booking_services
    ADD INDEX IF NOT EXISTS idx_bs_translation_group (translation_group);
