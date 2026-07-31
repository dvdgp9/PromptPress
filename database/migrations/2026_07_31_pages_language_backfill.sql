-- PAGES-LANG L3 — Repaso del idioma y el grupo de traducción de `pages`.
--
-- La migración de multi-idioma (2026_07_27) rellenó estas columnas una vez,
-- pero ONCE caminos de creación de páginas siguieron insertando filas sin
-- ellas: el onboarding, las entradas del blog, la creación rápida y la
-- creación con IA de páginas, las páginas legales y la página interna de
-- formularios. Esos caminos ya rellenan idioma y grupo; esto arregla lo que
-- quedó creado por el camino viejo.
--
-- Una página sin `translation_group` NO se puede traducir: el flujo de
-- traducción hermana las versiones por ese campo.
--
-- Es idempotente: aplicarlo dos veces no cambia nada. En el panel se aplica
-- solo al abrir /admin/pages (PageController::repairMissingLanguageGroups());
-- este archivo queda como referencia canónica y para aplicación manual.

-- Idioma real del sitio, no un 'es' a lo bruto.
UPDATE pages p
    JOIN sites s ON s.id = p.site_id
    SET p.language = s.language
    WHERE (p.language IS NULL OR p.language = '')
      AND s.language IS NOT NULL AND s.language <> '';

-- UUID() se evalúa POR FILA: cada página, su propio grupo. Un grupo compartido
-- las convertiría en traducciones unas de otras.
UPDATE pages SET translation_group = UUID()
    WHERE translation_group IS NULL OR translation_group = '';
