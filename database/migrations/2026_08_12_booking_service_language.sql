-- Los servicios reservables hablaban castellano en webs que no lo son.
--
-- `booking_services.language` tiene DEFAULT 'es' y nadie lo fijaba al crear el
-- servicio: en una web francesa TODOS los servicios nacían en castellano. Eso
-- no solo afectaba a los textos del calendario — que ya se corrigen mandando el
-- idioma de la página — sino a lo que se guarda en cada reserva, y con ello al
-- idioma de los EMAILS al cliente y de su página de cancelación.
--
-- El idioma del servicio no se puede elegir en el panel (no hay campo), así que
-- cualquier valor distinto del idioma del sitio es el defecto de la columna, no
-- una decisión de nadie: se alinean todos con su sitio.
--
-- A partir de ahora `ServiceStore::create()` lo fija al crear.

UPDATE booking_services bs
  JOIN sites s ON s.id = bs.site_id
   SET bs.language = s.language
 WHERE bs.language <> s.language;
