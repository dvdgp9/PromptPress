-- E-GDPR G1 — Manifest de cumplimiento (RGPD + LSSI) por sitio.
--
-- Una fila por sitio con los datos legales del responsable, features
-- relevantes (ecommerce, newsletter, …), tracking activo, processors,
-- referencias a páginas legales generadas y textos del banner.
--
-- `ComplianceService::ensureSchema()` aplica esto en runtime para
-- instalaciones existentes (self-healing). Este archivo queda como
-- referencia canónica.

CREATE TABLE IF NOT EXISTS site_compliance (
    site_id INT UNSIGNED NOT NULL PRIMARY KEY,
    manifest JSON NOT NULL,
    manifest_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_site_compliance_site
        FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
