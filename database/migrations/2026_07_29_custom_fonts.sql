-- FONTS F1 — Tipografías propias del cliente (brandbook).
--
-- Dos tablas y no una: el ROL (títulos / textos / ambas) y el fallback son
-- propiedades de la FAMILIA, no de cada archivo. Con una sola tabla habría que
-- repetir nombre+rol+fallback en cada peso subido y el borrado de una familia
-- pasaría a depender de que todas las filas estuvieran de acuerdo.
--
-- `slug` es la clave que viaja a los tokens del design system como
-- `custom:{slug}` (ver DesignSystem::fontCssValue), por eso es único por site.

CREATE TABLE IF NOT EXISTS site_font_families (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id        INT UNSIGNED NOT NULL,
    name           VARCHAR(120) NOT NULL,
    slug           VARCHAR(120) NOT NULL,
    role           ENUM('heading','body','both','none') NOT NULL DEFAULT 'none',
    fallback_stack VARCHAR(255) NOT NULL DEFAULT '',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sff_site_slug (site_id, slug),
    INDEX idx_sff_site_role (site_id, role),
    CONSTRAINT fk_sff_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un archivo = un @font-face con su peso y estilo reales. La unique
-- (family_id, weight, style) evita que dos archivos compitan por el mismo
-- corte, que es justo el caso en el que el navegador elige "el que sea".
CREATE TABLE IF NOT EXISTS site_font_files (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    site_id       INT UNSIGNED NOT NULL,
    weight        SMALLINT UNSIGNED NOT NULL DEFAULT 400,
    style         ENUM('normal','italic') NOT NULL DEFAULT 'normal',
    format        ENUM('woff2','woff','ttf','otf') NOT NULL,
    path          VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    file_size     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sfl_family_cut (family_id, weight, style),
    INDEX idx_sfl_site (site_id),
    CONSTRAINT fk_sfl_family
        FOREIGN KEY (family_id) REFERENCES site_font_families(id) ON DELETE CASCADE,
    CONSTRAINT fk_sfl_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
