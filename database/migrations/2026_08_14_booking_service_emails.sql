-- Emails de reserva editables por servicio (MODULOS M9).
--
-- Los tres mensajes que recibe el CLIENTE (reserva recibida, confirmada y
-- cancelada) estaban fijos en el catálogo de textos. Cada servicio quiere decir
-- lo suyo: dónde aparcar, qué traer, a qué hora llegar…
--
-- NULL = las plantillas por defecto de siempre, traducidas a los seis idiomas
-- del widget. Solo se guarda aquí lo que el gestor haya reescrito.

ALTER TABLE booking_services
    ADD COLUMN IF NOT EXISTS emails_json LONGTEXT DEFAULT NULL AFTER fields_json;
