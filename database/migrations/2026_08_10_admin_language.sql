-- ADMIN-I18N T0.4 — El idioma en el que se le habla al gestor.
--
-- No es lo mismo que `sites.language`: ese es el idioma de la WEB (lo que lee el
-- visitante), y este es el del PANEL (lo que lee quien la mantiene). Coinciden
-- casi siempre, y por eso el valor por defecto es NULL = "heredar del sitio":
-- así una web francesa da panel francés sin que nadie configure nada, y un
-- gestor español que lleva webs de medio mundo puede fijarse el suyo.
--
-- NULL en vez de 'es' a propósito: si el defecto fuera 'es', cambiar el idioma
-- del sitio no cambiaría nunca el del panel, que es justo lo que se busca.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS language VARCHAR(5) DEFAULT NULL AFTER role;
