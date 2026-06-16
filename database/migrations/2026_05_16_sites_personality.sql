-- D-Slice 1 (S1.1) — Añadir columnas de personalidad/skin/layout a `sites`.
--
-- Estas 3 columnas JSON guardan el sistema componible derivado del onboarding:
--   - personality: vector 8 ejes (4 skin + 4 layout) + meta (source, inferred_at,
--     adjustment_log).
--   - skin_json: paleta + tipografía + radii + sombras + motion materializados
--     desde la interpolación 3-NN de los 8 anchors.
--   - layout_preferences: memoria inter-página por page_type (se rellena en Slice 2).
--
-- Idempotente: usa ADD COLUMN IF NOT EXISTS donde el motor lo permite y
-- self-heal en `SitesSchema::ensurePersonalityColumns()` para hosts viejos.

ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS personality JSON NULL,
    ADD COLUMN IF NOT EXISTS skin_json JSON NULL,
    ADD COLUMN IF NOT EXISTS layout_preferences JSON NULL;
