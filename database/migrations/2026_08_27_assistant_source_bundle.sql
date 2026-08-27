-- ASSISTANT-RICH AR5 — material fuente confirmado y referencias por item.

ALTER TABLE assistant_jobs
    ADD COLUMN IF NOT EXISTS source_bundle_json MEDIUMTEXT NULL AFTER summary;

ALTER TABLE assistant_job_items
    ADD COLUMN IF NOT EXISTS source_block_ids_json TEXT NULL AFTER instruction,
    ADD COLUMN IF NOT EXISTS media_ids_json TEXT NULL AFTER source_block_ids_json;
