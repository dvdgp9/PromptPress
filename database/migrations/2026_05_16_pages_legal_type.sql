-- E-GDPR G3 — Añadir 'legal' al ENUM page_type para clasificar las páginas
-- legales generadas (privacidad, cookies, aviso legal).
--
-- ALTER MODIFY es idempotente: si la columna ya tiene 'legal' nada cambia.
-- El servicio `ComplianceService::ensurePageTypeLegal()` aplica esto en
-- runtime para instalaciones existentes (self-healing).

ALTER TABLE pages
    MODIFY COLUMN page_type
    ENUM('home','service','product','landing','article','contact','legal')
    NOT NULL DEFAULT 'landing';
