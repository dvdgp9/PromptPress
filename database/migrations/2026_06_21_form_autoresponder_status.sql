-- FORMS-R — separar el aviso al administrador de la autorrespuesta al visitante.
ALTER TABLE form_submissions
    ADD COLUMN IF NOT EXISTS autoresponder_status ENUM('unknown','disabled','skipped','sent','failed') NOT NULL DEFAULT 'unknown' AFTER email_error,
    ADD COLUMN IF NOT EXISTS autoresponder_error VARCHAR(500) NULL AFTER autoresponder_status;
