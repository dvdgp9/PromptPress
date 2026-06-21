-- FORMS-R T2 — Soft-delete de formularios.
-- Añade el valor 'deleted' al enum de estado de page_sections para poder
-- ocultar un formulario sin borrar la fila (y así conservar sus respuestas,
-- que tienen FK ON DELETE CASCADE a page_sections).
ALTER TABLE page_sections
    MODIFY status ENUM('editable','locked','deleted') NOT NULL DEFAULT 'editable';
