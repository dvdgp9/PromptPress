-- Retirada del "Módulo de prueba" (hello).
--
-- Existía solo para validar el sistema de módulos (FEAT-3 F0.1): una tarjeta
-- on/off y dos rutas que devolvían 404 al apagarlo. El sistema ya está probado
-- por los tres módulos reales (Analytics, Booking, Commerce), así que la tarjeta
-- solo confundía a quien abre "Módulos" en un sitio en producción.
--
-- El código (`app/Modules/Hello/`) y su entrada en el catálogo se han borrado.
-- Aquí solo queda barrer el flag que se hubiera guardado en `settings`: sin la
-- entrada en el catálogo `isEnabled()` ya devuelve false, pero dejar la fila
-- sería basura que nadie sabría interpretar más adelante.

DELETE FROM settings WHERE setting_key = 'module_hello_enabled';
