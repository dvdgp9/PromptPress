-- T18.4 — Pipeline de imágenes con banco externo (Unsplash).
--
-- Añade trazabilidad de origen y atribución a la tabla `media`. Aplicar UNA vez
-- en instalaciones existentes. Las nuevas instalaciones ya tienen estas columnas
-- en `install/schema.sql`.
--
-- La self-healing migration de `App\Services\ImageBankService::ensureSchema()`
-- aplica esto automáticamente al primer uso. Este archivo queda como referencia
-- canónica y para aplicación manual si se prefiere.

ALTER TABLE media
    ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'upload' AFTER created_at,
    ADD COLUMN IF NOT EXISTS source_id VARCHAR(120) DEFAULT NULL AFTER source,
    ADD COLUMN IF NOT EXISTS source_url VARCHAR(500) DEFAULT NULL AFTER source_id,
    ADD COLUMN IF NOT EXISTS attribution_name VARCHAR(160) DEFAULT NULL AFTER source_url,
    ADD COLUMN IF NOT EXISTS attribution_url VARCHAR(500) DEFAULT NULL AFTER attribution_name;

-- Índice para lookups rápidos por origen externo (idempotencia, cache).
ALTER TABLE media
    ADD INDEX IF NOT EXISTS idx_media_source (source, source_id);
