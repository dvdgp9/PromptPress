-- Repara instalaciones donde un intento de soft-delete guardó status=''
-- porque el ENUM de page_sections todavía no incluía el valor 'deleted'.
UPDATE page_sections
SET status = 'editable'
WHERE section_type = 'form' AND status = '';

ALTER TABLE page_sections
    MODIFY status ENUM('editable','locked','deleted') NOT NULL DEFAULT 'editable';
