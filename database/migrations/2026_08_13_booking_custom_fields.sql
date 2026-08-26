-- Campos configurables del formulario de reserva (MODULOS M8).
--
-- Hasta ahora el formulario que ve el cliente era fijo en el JS del widget:
-- nombre, email, teléfono y notas. Cada negocio necesita lo suyo (matrícula del
-- coche, nº de personas, alergias, "acepto las condiciones"…), así que la
-- definición pasa a vivir en el servicio y las respuestas en la reserva.
--
--   booking_services.fields_json → QUÉ se pide (definiciones).
--   booking_bookings.extra_json  → QUÉ contestó el cliente en esos campos.
--
-- Las dos columnas son NULL-ables y todo el código trata NULL como "los campos
-- de siempre": las instalaciones que ya existen siguen igual sin tocar nada.
-- Nombre y email NO viven aquí: son fijos y tienen su propia columna, porque
-- sin email no hay confirmación ni enlace de cancelación.

ALTER TABLE booking_services
    ADD COLUMN IF NOT EXISTS fields_json LONGTEXT DEFAULT NULL AFTER price_label;

ALTER TABLE booking_bookings
    ADD COLUMN IF NOT EXISTS extra_json LONGTEXT DEFAULT NULL AFTER notes;
