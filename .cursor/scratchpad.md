# Background and Motivation

ONB-REV — Revisión del onboarding + SEO 20 páginas (2026-07-01/02, Planner/Executor).

El onboarding acumulaba cambios (canvas, intent picker F22, skin preview D-Slice1/FH6) y el paso 5 mostraba elementos duplicados. Además, se pidió que el intent "Aparecer en Google (SEO)" pudiera crear ~20 páginas de golpe: páginas corporativas + entradas de blog.

# Key Challenges and Analysis

**Bug del "paso duplicado" (verificado en navegador, paso 5 sin intent guardado):**
- El picker de intent (`[data-intent-picker]`, views/admin/onboarding/index.php:304) traía sus propios botones "Saltar" + "Ver mi arquitectura →", pero el footer global (`onboarding_footer`, index.php:352) se renderizaba a la vez con "Saltar" + "Continuar al estilo →" (botón muerto en ese momento). Resultado: dos pares de CTAs apilados.
- Secundario: con intent ya guardado, cada visita a `?step=5` relanzaba el análisis IA sin caché.

**Estado del intent SEO:**
- `intentDirective('seo')` pedía estructura base + Blog obligatorio (~5 páginas).
- Si intent=seo y se creó Blog, se generaban 3 entradas automáticas invisibles para el usuario hasta el flash final.

**Retos del "20 de golpe":**
- Tiempo/coste: 20 generaciones canvas serían prohibitivas. Mix correcto: ~7-8 páginas corporativas canvas + ~12 entradas de blog (más baratas, GENERATE_ARTICLE).
- Transparencia: el usuario debe ver y poder deseleccionar los títulos de blog propuestos.

# High-level Task Breakdown (ONB-REV)

- T1 — Arreglar el footer duplicado del paso 5: ocultar `onboarding_footer` mientras el picker de intent esté visible; mostrarlo al pintar `[data-arch-result]`.
- T2 — Cachear la propuesta de arquitectura por sitio (setting `onboarding_architecture_json` + botón "Volver a proponer"): recargar step 5 no dispara IA.
- T3 — Backend SEO: ampliar `intentDirective('seo')` a ~7-8 páginas corporativas y pedir ~12 títulos de blog vía `SUGGEST_RELATED_ARTICLES` como `blog_posts` en la respuesta de `/analyze`.
- T4 — UI paso 5: grupo "Entradas de blog" con checkboxes premarcados (solo intent seo); contador "X páginas + Y entradas".
- T5 — Generación: endpoint `create-post` (factorizado de `generateSeoStarterPosts`) + `runCreate` encola entradas tras páginas en la misma barra de progreso. Eliminar la generación automática a ciegas de 3 posts.

**Ampliación (2026-07-02) — hijas condicionales en services/portfolio:**
Subpáginas solo cuando los documentos/memoria tengan sustancia real (la plataforma no debe inventar contenido). `intentDirective('services')` y `intentDirective('portfolio')` ajustadas para exigir datos documentados reales antes de crear hijas; prompt ANALYZE_SITE_ARCHITECTURE con cap 6→8 páginas faltantes + instrucción de volcar datos reales; `proposalPages` cap 8→10.

# Project Status Board (ONB-REV)

- [x] T1 footer duplicado paso 5
- [x] T2 caché de arquitectura
- [x] T3 backend SEO (páginas + blog_posts)
- [x] T4 UI selección entradas de blog
- [x] T5 endpoint create-post + progreso unificado
- [x] Ampliación services/portfolio: hijas condicionales a datos reales

# Current Status / Progress Tracking

Todas las tareas implementadas y verificadas en navegador (2026-07-01/02):
- T1: footer nace `hidden`, JS lo muestra al pintar la propuesta. Fix colateral CSS: `[hidden]` necesita regla explícita cuando el elemento tiene `display:grid/flex`.
- T2: propuesta cacheada en setting `onboarding_architecture_json`; botón "Volver a proponer" fuerza recálculo. Verificado sin filas nuevas en `ai_logs` en recarga.
- T3: `intentDirective('seo')` ampliada (7-8 páginas) + `suggestSeoBlogPosts()` (count=12). Dedupe del hub de blog verificado offline.
- T4: grupo "Entradas de blog para posicionar" con contador vivo. Verificado: 8 páginas + 12 entradas renderizadas.
- T5: endpoint `POST /admin/onboarding/create-post` + `runCreate(pages, posts)`. Eliminada generación automática a ciegas de 3 posts. Endpoint probado (creó borrador, luego borrado).
- Ampliación services/portfolio: verificado con llamadas reales (sitio dev = academia con dossieres) — hijas solo de servicios documentados con datos reales; portfolio sin hijas ni hub inventados.

# Executor's Feedback or Assistance Requests

Pendiente de verificación manual del usuario: flujo completo de generación 8 páginas + 12 entradas de blog (no ejecutado end-to-end en dev por coste IA; el bucle reutiliza el mecanismo por-ítem ya probado + endpoint ya probado individualmente).

# Lessons

- `ai_logs` no tiene columna `action`: es `action_type`. Un `2>/dev/null` en mysql dentro de un until-loop puede convertir ese error en bucle infinito silencioso.
- El atributo `hidden` no basta si el elemento tiene `display:grid/flex` por CSS: añadir regla `[hidden]{display:none}` explícita.
- `preview_click` sobre tarjetas con overlay/posicionamiento especial puede no disparar el listener; usar `el.click()` vía eval para verificar.
- El mensaje "La IA no devolvió un cambio válido" en el Studio es genérico: cualquier AIException sin status 401/403/429/5xx cae ahí. No fiarse del mensaje para diagnosticar, mirar logs.
- Forzar al modelo a reescribir HTML completo para cambios de solo-estilo trunca en secciones grandes y puede destrozar SVGs; preferir ediciones parciales (html vacío + css_append) cuando el cambio es solo de estilo.
- Antes de editar `/admin/chrome`, revisar siempre `ChromeService`, `BrandService`, `views/admin/chrome/index.php`, `admin/assets/js/chrome-editor.js` y `DesignSystem`: la UI, saneado y render público van acoplados.
- Reforzar en prompts de generación: prohibir cifras/valoraciones/testimonios inventados; redirigir la "prueba social" a hechos comprobables cuando no hay datos reales.

---

# FEAT-3 — Analítica propia, Reservas y PromptCommerce (2026-07-02, Planner)

## Background and Motivation (FEAT-3)

El usuario quiere ampliar PromptPress con tres funcionalidades de producto: (1) analítica integrada propia que elimine la necesidad de Google Analytics (privacidad, dato propio, sin banner de cookies); (2) reservas y calendarios, consumible también desde webs externas; (3) eCommerce como módulo aparte estilo WooCommerce ("PromptCommerce"). Los pagos con Stripe quedan fuera de alcance por ahora — el eCommerce arranca con métodos de pago offline.

Decisión previa (conversación 2026-07-02): la analítica se desarrolla de 0 (no fork), usando el esquema de Umami (MIT) como referencia de diseño y el algoritmo de visitante único sin cookies de Plausible (hash diario con salt rotativo). Licencias AGPL/GPL (Plausible/Matomo) impiden copiar código si PromptPress es propietario.

## Key Challenges and Analysis (FEAT-3)

**Arquitectura común — no existe sistema de plugins:**
- PromptPress es un monolito PHP sin framework: un solo `app/routes.php`, autoloader propio (`Core\Autoloader`), multi-sitio vía `site_id`, configuración por sitio en `settings`. No hay hooks, ni feature flags, ni mecanismo de instalación de extensiones.
- Un plugin instalable estilo WooCommerce exigiría construir primero una API de plugins (hooks, registro de rutas dinámico, migraciones de terceros, empaquetado). Eso es un proyecto en sí mismo y de valor dudoso mientras solo exista un plugin.
- Propuesta: **monolito modular**. Cada funcionalidad grande vive en su directorio (`app/Modules/Analytics`, `app/Modules/Booking`, `app/Modules/Commerce`) con sus controllers/services/migrations/vistas, se registra en `routes.php` con un include condicionado, y se activa por sitio con un setting (`module_commerce_enabled`, etc.). Beneficios de la separación (código aislado, activable, extraíble a plugin real en el futuro) sin construir la infraestructura de plugins hoy.

**Analítica:**
- Riesgo principal: volumen de la tabla de eventos. Mitigación: tabla cruda con retención 30-90 días + rollups diarios agregados; suficiente en MySQL para tráfico pyme.
- Sin cookies: visitante único = hash(salt_diario_rotativo + site_id + IP + UA); el salt se destruye al rotar y la IP no se persiste → sin banner de consentimiento para analítica.
- El endpoint `/collect` debe ser stateless y barato (sin sesión, sin CSRF, respuesta 204); diseñarlo como si fuera un servicio externo facilita extraerlo si el volumen crece.
- Cuidado: `install/schema.sql` diverge de `database/migrations` (lección previa) — las tablas nuevas deben añadirse en ambos.

**Reservas:**
- La complejidad real está en el motor de disponibilidad: horarios recurrentes semanales + excepciones (festivos/vacaciones) + duración del servicio + buffer + solapes con reservas existentes. Debe ser un servicio puro y testeable sin HTTP.
- API-first: los endpoints públicos JSON (`/api/booking/*`) son el contrato; el widget embebible y el uso interno en páginas PromptPress consumen la misma API. Para uso externo: API key por sitio, CORS y rate limiting.
- Sin pagos: la reserva se confirma por email (reutiliza `Mail`/patrón autoresponder de formularios). Estado `pending/confirmed/cancelled` gestionable desde el admin.

**PromptCommerce:**
- Decisión (2026-07-02): Stripe SÍ entra en el alcance del módulo desde el principio; lo que se elimina del roadmap es la fase intermedia "solo pagos Stripe" como capa suelta. El checkout nace con dos métodos detrás de `PaymentMethodInterface`: `StripeCheckout` (Stripe Checkout hosted — Stripe aloja la página de pago, sin PCI en nuestro servidor, confirmación por webhook) y `ManualPayment` (transferencia con instrucciones / contra reembolso → pedido `pending_payment` que el admin marca pagado).
- Requisitos que trae Stripe: claves API por sitio en settings (cifradas), endpoint público de webhook con verificación de firma, y modo test/live. Antes de implementar C5, pedir al usuario búsqueda web de la documentación actual de Stripe Checkout + webhooks (regla de APIs externas) y documentar en `cursor/stripe-api.md`.
- Alcance contenido deliberadamente: productos simples (sin variantes en fase 1), stock simple, IVA por producto, sin cupones/envíos calculados/devoluciones. Cada una de esas piezas se añade después si hay demanda; meterlas ahora es el camino a no terminar nunca.
- Carrito en sesión/cookie propia del sitio público (no hay cuentas de cliente en fase 1: checkout como invitado).

**Orden recomendado:** Analítica → Reservas → Commerce. La analítica es la más autocontenida y valida el patrón de módulo; además aporta los eventos de conversión para medir las otras dos.

## High-level Task Breakdown (FEAT-3)

### Fase 0 — Patrón de módulo (pequeña, habilita todo lo demás)
- F0.1 Convención `app/Modules/<X>` en el autoloader + include condicional de rutas de módulo en `routes.php` + setting de activación por sitio + tarjeta on/off en el admin. Éxito: un módulo "hello" de prueba se activa/desactiva por sitio y sus rutas devuelven 404 cuando está apagado.

### Fase A — Analítica (módulo Analytics)
- A1 Documento de diseño breve: esquema de tablas (basado en Umami), definición del hash de visitante, lista de métricas del dashboard v1. Éxito: revisado y aprobado por el usuario antes de codificar.
- A2 Migración de tablas (eventos crudos + rollups diarios) en `database/migrations` **y** `install/schema.sql`. Éxito: instalación nueva y upgrade quedan idénticas.
- A3 Endpoint `POST /collect` + script público `pp-analytics.js` (~1 KB) inyectado en el render público cuando el módulo está activo. Éxito: navegar por el sitio dev genera eventos con visitor-hash correcto; sin cookies creadas.
- A4 Job de rollup diario (invocable por cron o al vuelo) + purga de crudos según retención. Éxito: rollups correctos verificados contra los crudos con datos sintéticos.
- A5 Dashboard en el admin: visitantes/páginas vistas por día, páginas top, referrers, países, dispositivos, rango de fechas. Éxito: cifras del dashboard coinciden con los datos sintéticos insertados.
- A6 Eventos personalizados (envío de formulario como primer evento integrado). Éxito: enviar un formulario público aparece como conversión en el dashboard.

### Fase B — Reservas (módulo Booking)
- B1 Documento de diseño: modelo de datos (servicios, horarios, excepciones, reservas), contrato de la API pública JSON, decisiones de zona horaria. Éxito: aprobado por el usuario.
- B2 Migraciones + CRUD admin de servicios reservables (nombre, duración, horario semanal, excepciones, aforo/plazas). Éxito: crear y editar un servicio desde el admin.
- B3 Motor de disponibilidad como servicio puro + tests exhaustivos (recurrencia, excepciones, buffers, solapes, aforo). Éxito: batería de tests de slots pasa; es el corazón del módulo.
- B4 API pública JSON: disponibilidad por servicio/rango + creación de reserva (con anti-doble-reserva transaccional). Éxito: dos peticiones concurrentes al mismo slot → solo una gana.
- B5 Emails de confirmación (cliente y admin, con ICS adjunto) + gestión de reservas en el admin (confirmar/cancelar/listar). Éxito: flujo completo reservar→email→gestión en dev.
- B6 Widget embebible: snippet `<script>` que pinta el calendario consumiendo la API con API key por sitio + CORS + rate limiting. Éxito: el widget funciona en una página HTML externa al proyecto.

### Fase C — PromptCommerce (módulo Commerce)
- C1 Documento de diseño: modelo de datos (productos, pedidos, líneas), flujo de checkout invitado, `PaymentMethodInterface` (StripeCheckout + ManualPayment), textos legales mínimos (condiciones de compra). Éxito: aprobado por el usuario.
- C2 Migraciones + CRUD admin de productos (nombre, descripción, precio, IVA, imagen de la biblioteca, stock, activo). Éxito: alta/edición de producto en dev.
- C3 Catálogo público: página de tienda + ficha de producto renderizadas con el design system del sitio. Éxito: tienda navegable en el sitio dev con productos de prueba.
- C4 Carrito (sesión pública) + checkout invitado con `ManualPayment` → pedido `pending_payment` con email al cliente y al admin. Éxito: compra completa end-to-end en dev sin pasarela (valida todo el flujo antes de meter Stripe).
- C5 `StripeCheckout`: settings de claves por sitio (modo test/live), creación de Checkout Session, webhook con verificación de firma → pedido `paid`. Precedido de búsqueda web de docs actuales de Stripe + `cursor/stripe-api.md`. Éxito: compra en modo test end-to-end con tarjeta de prueba.
- C6 Gestión de pedidos en el admin: listado, detalle, transiciones de estado (pagado/enviado/cancelado) con email de aviso al cliente; decremento de stock al confirmar. Éxito: ciclo de vida completo de un pedido por ambos métodos de pago.
- C7 Integración transversal: conversiones de compra en Analytics; sección/bloque "productos destacados" disponible en páginas canvas. Éxito: una compra aparece como evento de conversión.

## Project Status Board (FEAT-3)

- [x] F0.1 Patrón de módulo + activación por sitio (implementado y verificado en navegador; pendiente confirmación manual del usuario)
- [x] A1 Diseño analítica — `cursor/analytics-design.md` **aprobado por el usuario 2026-07-02** (retención crudos: 90 días; país/geo: fase 2)
- [x] A2 Migraciones — `database/migrations/2026_07_02_analytics.sql` + bloque idéntico en `install/schema.sql`; aplicada en dev (3 tablas: analytics_salts/events/daily); equivalencia migración↔schema verificada por diff normalizado
- [x] A3 Collect+script — endpoint `/_analytics/collect` (stateless, 204), `EventRecorder` (hash de visitante + salt diario + device/browser + anti-bot), script `public/js/pp-analytics.js` (sendBeacon, sin cookies), inyección condicional en el render público + flush de caché en el toggle. Verificado en navegador (ciclo on/off + pageview real registrado). Test `tests/analytics_events.php` ALL PASS.
- [x] A4 Rollups — `RollupService` (rollup perezoso con lock horario en settings, agregación por dimensión total/page/referrer/device/browser/event, retención 90d con purga de crudos + purga de salts >2d) + CLI opcional `scripts/analytics_rollup.php`. Test `tests/analytics_rollup.php` 22/22 PASS (correctitud por dimensión, idempotencia, día en curso excluido, retención, lock).
- [x] A5 Dashboard — `/admin/analytics` interactivo: KPIs con deltas vs periodo anterior, gráfica SVG diaria (barras pageviews + línea visitantes, tooltip hover con guía), selector 7/30/90 días vía JSON sin recarga, desgloses (páginas/fuentes/dispositivos/navegadores/conversiones) con barras de proporción animadas, estado vacío, nav condicional. Verificado en navegador con 95 días de datos sintéticos.
- [x] A6 Eventos — `form_submit` registrado server-side en `PublicFormController::submit` tras el INSERT del envío (condicionado a módulo activo, try/catch: la analítica jamás rompe un envío). Verificado end-to-end: envío real → evento en BD y visible en el dashboard; con módulo off → envío OK sin evento.

**FASE A COMPLETA (2026-07-02).** Pendiente de verificación manual del usuario: dashboard `/admin/analytics` con datos demo.

**Fleco de privacidad cerrado (2026-07-02):** `LegalPageGenerator` ahora informa a la IA de un nuevo dato objetivo `own_analytics` (no inventable — texto fijo generado por código a partir de `ModuleRegistry::isEnabled`, no por el modelo) con el estado real del módulo Analytics del sitio. Si está activo, el texto aclara explícitamente: sin cookies, sin IP/UA persistidos, hash diario rotativo, retención 90 días. El prompt `Actions::GENERATE_LEGAL_PAGE` instruye a la IA a tratarlo como hecho propio (no como "processor" de terceros) y, en `cookie_policy`, a aclarar que no requiere consentimiento de cookies. Si el módulo está apagado, no se menciona nada (sin cambio de comportamiento). Test `tests/legal_page_own_analytics.php` (7/7 PASS, vía reflexión sobre el método privado ya que `generate()` llama a IA real). Suite completa sin regresión.
- [x] B1 Diseño reservas — `cursor/booking-design.md` **aprobado por el usuario 2026-07-02** (auto_confirm=0 por defecto; campos cliente fijos; recordatorios fuera de v1)
- [x] B2 Migraciones + CRUD servicios — `2026_07_02_booking.sql` (+ bloque idéntico en `install/schema.sql`, diff normalizado OK), `ServiceStore` + `BookingAdminController` + vistas `views/admin/booking/*` + `booking-service-editor.js`; test `tests/booking_services.php` 24/24 PASS; verificado en navegador (pendiente confirmación manual del usuario)
- [x] B3 Motor de disponibilidad — `AvailabilityEngine` (función pura `slots()` + `findSlot()` para B4 + loader `forService()`); test `tests/booking_availability.php` 27/27 PASS (recurrencia, partido, buffers, huecos incompletos, excepciones servicio/global y precedencia, min_notice, max_advance, pasado, aforo/remaining, canceladas, solapes parciales, DST invierno/verano, multi-día, findSlot, reglas corruptas)
- [x] B4 API pública JSON — `/api/booking/v1/*` (services, availability, create, cancel, OPTIONS preflight), anti-doble-reserva transaccional (lock de fila de servicio), rate limit por IP-hash, honeypot, CORS con API key cifrada + allowlist, conversión `booking_created` en Analytics; fix transversal: guard de módulos con fallback a sitio público. Test `tests/booking_api.php` 28/28 PASS (incluye carrera real con 2 POST concurrentes → 201+409)
- [x] B5 Emails + gestión admin — `BookingMailer` (creación/confirmación/cancelación, ICS adjunto RFC 5545, estados email_status tolerantes a fallos), página pública `/_booking/cancel/{id}` (GET confirmación + POST), listado `/admin/booking/reservas` con filtros y acciones confirmar/cancelar. Test `tests/booking_emails.php` 17/17 PASS
- [x] B6 Widget embebible — `public/js/pp-booking-widget.js` (sin dependencias, estilos prefijados, agenda→huecos→formulario, honeypot, manejo 409/429) + panel "Reservas desde otras webs" en `/admin/booking` (API key `ppbk_*` cifrada, allowlist de orígenes, snippet copiable). Verificado end-to-end en navegador desde página HTML externa (origen distinto, CORS+key): reserva creada → gestionada en admin

**FASE B COMPLETA (2026-07-02).** Pendiente de verificación manual del usuario: `/admin/booking` (servicios + integración), `/admin/booking/reservas` (queda una reserva demo confirmada de "Cliente Externo") y widget externo.
- [x] C1 Diseño commerce — `cursor/commerce-design.md` **aprobado por el usuario 2026-07-02** (IVA incluido por defecto pero configurable por sitio `commerce_prices_include_tax`; envío plano + umbral gratis OK; EUR fijo v1 OK)
- [x] C2 Migraciones + CRUD productos — `2026_07_02_commerce.sql` (+ bloque idéntico en `install/schema.sql`, diff normalizado OK, aplicada y registrada en dev), `ProductStore` + `CommerceSettings` + `CommerceAdminController` + vistas + `commerce-product-editor.js` (media picker vía `/admin/media/library`); test `tests/commerce_products.php` 29/29 PASS; verificado en navegador (pendiente confirmación manual del usuario)
- [x] C3 Catálogo público — `ShopRenderer` (shell con design system + chrome del sitio, rutas dinámicas sin caché) + `ShopController` (grid `/tienda` + ficha `/tienda/p/{slug}`); verificado en navegador con el diseño real del sitio dev
- [x] C4 Carrito + checkout manual — `CartService` (sesión + totales por línea en ambos modos IVA), `OrderStore` (transaccional con lock de sitio, números `PC-<año>-<seq>`, snapshot, stock al pasar a paid), `PaymentMethodInterface`+`ManualPayment`, `CommerceMailer`, página gracias con access_key, honeypot+rate limit, tipo legal `purchase_conditions` condicional al módulo (typesFor + hechos `own_commerce` no inventables). Test `tests/commerce_checkout.php` 37/37 PASS; compra end-to-end verificada en navegador (pedido demo PC-2026-0001)
- [x] C5 StripeCheckout — búsqueda web docs Stripe → `cursor/stripe-api.md`; sin SDK (REST propio); settings cifradas + UI `/admin/commerce/pagos`; webhook firmado `/tienda/stripe/webhook`; reconciliación en gracias + reintento `/tienda/pagar/{num}`. Test `tests/commerce_stripe.php` 45/45 PASS
- [x] C6 Pedidos admin — `OrderStore::{listForAdmin(filtros status/method/q), countByStatus, saveAdminNotes}` + máquina de estados `ADMIN_TRANSITIONS`; `CommerceAdminController::{orders,order,orderStatus,orderNotes}` + rutas `/admin/commerce/pedidos*`; vistas `orders`/`order` (tabs con contadores, buscador, detalle con acciones+notas internas); email de estado al cliente (`CommerceMailer::sendStatusChange` ya existía) + decremento de stock al confirmar (vía `transition`). Test `tests/commerce_orders_admin.php` 25/25 PASS
- [x] C7 Integración — evento `purchase` en Analytics al completar el checkout (patrón booking_created; etiqueta "Compra" en el dashboard) + placeholder canvas `{{products:featured|limit=N|heading=...}}` (`FeaturedProductsRenderer`, solo con módulo activo, con pista condicional `{modules_hint}` en los 3 prompts canvas) + flush de caché de páginas al tocar productos. Test `tests/commerce_c7.php` 25/25 PASS

**FASE C COMPLETA (2026-07-06) → FEAT-3 COMPLETO.** Pendiente de verificación manual del usuario: pedidos demo en `/admin/commerce/pedidos`, página de pagos, y compra Stripe en modo test con claves reales (ver C5).

## Decisiones cerradas (usuario, 2026-07-02)

1. ✔ Monolito modular (`app/Modules/*` + activación por sitio, desactivado por defecto). PromptCommerce se mantiene como marca del módulo en la UI.
2. ✔ Stripe entra en el alcance de PromptCommerce (C5); se elimina solo la fase suelta "pagos Stripe" del roadmap general. Productos simples sin variantes en fase 1.
3. ✔ Orden: Analítica → Reservas → Commerce.

**Compatibilidad con prod (cliente actual):** los módulos nacen desactivados por sitio → actualizar la instalación prod del cliente es seguro (solo añade tablas vacías y rutas 404); su web no cambia salvo activación explícita. Si el usuario prefiere no actualizarla, tampoco bloquea nada. Requisito en ambos casos: cada migración nueva va a `database/migrations` Y a `install/schema.sql`.

## Executor's Feedback or Assistance Requests (FEAT-3)

**F0.1 completado (Executor, 2026-07-02).** El patrón de módulo queda establecido:
- `app/Modules/ModuleRegistry.php`: catálogo de módulos (hello disponible; analytics/booking/commerce marcados `available:false` hasta construirse), flag por sitio en `settings` (`module_<key>_enabled`), `isEnabled/setEnabled/statusFor`, middleware `requireEnabled()` (404 si apagado) y `registerRoutes()` (incluye `app/Modules/<Ucfirst>/routes.php` de cada módulo). No hizo falta tocar el autoloader (`App\` ya mapea a `app/`) ni migraciones (se reutiliza `settings`, que ya tiene UNIQUE (site_id, setting_key)).
- Módulo de prueba `app/Modules/Hello/` (controller + routes) con ruta admin `/admin/modules/hello` y pública `/_module/hello/ping`.
- Panel admin: `ModulesController` + vista `views/admin/modules/index.php` (tarjetas on/off) + enlace "Módulos" en el nav + CSS `.pp-modules-grid/.pp-module-card`.
- Test `tests/modules_registry.php` (14 asserts, ALL PASS).

Verificado en navegador (login admin, sitio dev): módulo desactivado → `/_module/hello/ping` y `/admin/modules/hello` dan 404; tras activar desde el panel → 200 (JSON `{module:hello,ok:true}` y página admin); tras desactivar → 404 de nuevo. Sin errores de consola; flag restaurado a desactivado.

**Pendiente confirmación manual del usuario** antes de pasar a Fase A: abrir `/admin/modules`, activar/desactivar "Módulo de prueba" y comprobar el comportamiento. Nota: el icono del nav reutiliza el de "settings" (no hay icono propio de módulos aún); si se quiere uno dedicado, es un añadido menor en el sprite de iconos.

**A1 (2026-07-02):** documento de diseño `cursor/analytics-design.md` APROBADO por el usuario (retención 90 días, país/geo fase 2). Hallazgo relevante del código: las páginas públicas se cachean como HTML estático → el toggle del módulo debe hacer `CacheService::flush` (aplica a A3).

**A2 (2026-07-02):** completada. Migración `2026_07_02_analytics.sql` aplicada en dev; mismas 3 tablas añadidas a `install/schema.sql` (equivalencia verificada por diff normalizado de los CREATE TABLE — no fue posible importar schema.sql en BD temporal porque el usuario MySQL `ppress` solo tiene grants sobre `ppress_dev`).

**A3 (2026-07-02):** completada. Nuevos ficheros: `app/Modules/Analytics/{EventRecorder.php, AnalyticsController.php, routes.php}`, `public/js/pp-analytics.js`; `Response::noContent()` (204). `EventRecorder::record()` es el punto único de ingesta (lo reusará A6 server-side): descarta bots por UA, no persiste IP/UA, calcula visitor_hash = 16 bytes de sha256(salt_diario·site·ip·ua), deriva device/browser, normaliza path (sin query) y referrer (a host, sin www., descarta auto-referencias). Endpoint `POST /_analytics/collect` stateless (sin CSRF), 204 siempre, doble-check de módulo activo + sitio existente. Script cliente con sendBeacon + `window.ppTrack(nombre)` para eventos. Inyección en `PageController::render()` (cubre home y show) solo si el módulo está activo; el toggle de `/admin/modules` hace `CacheService::flush`. **Marcado `analytics` como `available:true` en ModuleRegistry** (antes false) → esto rompió 2 asserts de `tests/modules_registry.php` que usaban analytics como ejemplo de "no disponible"; migrados a `commerce`. Verificado en navegador: módulo off → collect 404 y script ausente; on → collect 204, script inyectado con data-site, pageview real (navegación del navegador) registrado con device/browser correctos; off de nuevo → flush limpia el script y collect vuelve a 404. Sin errores de consola. Datos de prueba limpiados; módulo restaurado a off.

Caveat de verificación (no es bug): en el proxy del preview el `HTTP_HOST` del servidor (127.0.0.1:8788) no coincide con el host del navegador (localhost), así que el filtro de auto-referencia no reconoció el referrer interno y guardó `localhost`. En producción (dominio único) coincide y se filtra.

**A4 (2026-07-02):** completada. `app/Modules/Analytics/RollupService.php` + `scripts/analytics_rollup.php` (CLI cron opcional; el rollup real es perezoso, disparado por el dashboard en A5). `maybeRun($siteId)` fija un lock `analytics_rollup_last` en settings (TTL 1h) antes de trabajar y es tolerante a fallos. `run()` consolida cada día completo (< hoy) con crudos en `analytics_daily` por dimensión (total/page/referrer/device/browser/event; para 'event' la col pageviews guarda el nº de ocurrencias), purga crudos > 90 días (los rollups persisten) y salts > 2 días. Re-agrega borrando+reinsertando el día → idempotente. Test `tests/analytics_rollup.php` 22/22 con datos sintéticos de ayer/anteayer/hoy/hace-100d. Suite completa sin regresión (modules_registry, analytics_events, analytics_rollup, chrome_config). Datos de prueba limpiados.

Decisión de semántica registrada: `analytics_daily.pageviews` para la dimensión `event` almacena el nº de ocurrencias del evento (no pageviews) — la reusa el dashboard (A5) como "conteo del evento".

**A5 (2026-07-02):** completada. Nuevos: `app/Modules/Analytics/StatsService.php` (fusiona rollups + día en curso en vivo; top por dimensión; deltas vs periodo anterior), rutas admin en el módulo (`GET /admin/analytics` dispara `RollupService::maybeRun` — ahí vive el rollup perezoso — y `GET /admin/analytics/data` JSON), vista `views/admin/analytics/index.php` (shell + seed JSON inline), `admin/assets/js/analytics-dashboard.js` (gráfica SVG a mano: barras pageviews con animación + línea/área visitantes + tooltip con guía vertical y barra resaltada; cambio de rango por fetch; listas con barras de proporción; labels humanos ES: Directo/Ordenador/Móvil/Envío de formulario; escapeHtml en todo dato dinámico), CSS `pp-analytics-*` + icono nav `pp-icon--analytics`. Nav "Analítica" condicional (solo módulo activo) vía array_splice en layout.php. Delta oculto si el periodo anterior es 0 (no inventar porcentajes). Verificado en navegador: primera carga con rollup de 95 días = 150 ms; 2.083 filas rollup; retención purgó crudos >90d; cambio de rango 30→7 OK; tooltip OK; sin errores de consola; suite completa PASS.
- OJO: los tests de analítica BORRAN los datos del site al terminar (aislamiento). El seed de demo se re-sembró después con `$TMPDIR/seed_analytics.php` + `php scripts/analytics_rollup.php`.
- Estado dev actual: módulo analytics ACTIVO con ~5.9k eventos sintéticos de demo (95 días) para que el usuario pruebe el dashboard. Limpieza cuando termine: `DELETE FROM analytics_events; DELETE FROM analytics_daily; DELETE FROM analytics_salts;` (y desactivar el módulo si se quiere).

**A6 (2026-07-02):** completada. Hook en `PublicFormController::submit` justo tras `lastInsertId()`: si el módulo analytics está activo para el sitio, `EventRecorder::record('form_submit', /slug-de-la-página-origen)` con try/catch envolvente. Verificado end-to-end vía preview: POST real a `/forms/469` → submission creada + evento `form_submit` en `analytics_events` (path `/inicio`) + visible al instante en `/admin/analytics/data` (día en curso en vivo); mismo POST con módulo off → submission OK, cero eventos. Envíos y evento de prueba borrados. Con esto la FASE A queda completa; siguiente hito: B1 (documento de diseño de Reservas, recomendado Fable).

**B1 (2026-07-02, Executor):** documento de diseño `cursor/booking-design.md` redactado y a la espera de aprobación. Puntos clave: 3 tablas (`booking_services`, `booking_hours` — horario recurrente y excepciones unificados en una tabla, `booking_bookings`), slots calculados al vuelo (nunca persistidos), aforo por servicio (cubre cita 1:1 y clases grupales), persistencia en UTC + interpretación de horarios en `sites.timezone` (columna ya existente), API `/api/booking/v1/*` en ISO-8601 con offset (los módulos registran rutas antes del catch-all → prefijo viable), anti-doble-reserva con `SELECT … FOR UPDATE` + recálculo del slot en servidor, API key cifrada en settings + CORS por allowlist para el widget externo. 3 preguntas abiertas para el usuario en §10 (auto-confirmación por defecto, campos del cliente, recordatorios fuera de v1).

**B2 (2026-07-02, Executor):** completada. Migración `2026_07_02_booking.sql` aplicada en dev y registrada en `migrations`; mismas 3 tablas en `install/schema.sql` (diff normalizado idéntico). Módulo `booking` marcado `available:true`. Nuevos: `app/Modules/Booking/{ServiceStore, BookingAdminController, routes}.php`, vistas `views/admin/booking/{index,edit}.php`, `admin/assets/js/booking-service-editor.js`, CSS `pp-booking-*` + icono `pp-icon--booking`, nav "Reservas" condicional. El editor guarda config + horario semanal (varias franjas/día, validación de solapes e inversión) + excepciones (cerrado u horario especial); el guardado reescribe las reglas completas (delete+insert). Test `tests/booking_services.php` 24/24 PASS; suite completa sin regresión. Verificado en navegador: guard 404↔200 al activar el módulo, crear servicio, añadir franjas/excepción por UI, guardar, persistencia tras recarga, y rechazo de franja invertida con mensaje claro. Dos fixes visuales sobre la marcha: `[hidden]` vs `display:inline-flex` (regla explícita) y botón Eliminar con `pp-btn--danger-text` (la combinación ghost+danger dejaba texto blanco). Estado dev: módulo booking ACTIVO con servicio demo "Consulta inicial" (id 2) para verificación manual. Ojo: los tests de analítica borraron el seed demo del dashboard; re-sembrado con `$TMPDIR/seed_analytics.php` + rollup (5.7k eventos).

**B3 (2026-07-02, Executor):** completada. `app/Modules/Booking/AvailabilityEngine.php`: `slots()` es función PURA (recibe servicio, reglas booking_hours, reservas activas, rango, zona y "ahora" UTC inyectado → tests deterministas); `findSlot()` valida un inicio concreto recalculando (lo usará B4 para no fiarse del cliente; acepta el instante expresado en cualquier zona); `forService()` es el loader con BD (carga reglas propias+globales y reservas del rango con margen de ±1 día por el desfase UTC). Precedencia excepción-servicio > excepción-global > recurrente; parrilla duration+buffer desde el inicio de franja; hueco debe caber entero; remaining = capacity − solapes activos (`bStart < slotEnd AND bEnd > slotStart`). Test `tests/booking_availability.php` 27/27 PASS (incluye DST marzo con offsets +01/+02 y mismos 9:00 locales → distinto UTC). Verificado además contra la BD dev con el servicio demo: parrilla de 40 min correcta y última cita que no desborda la franja.

**B4 (2026-07-02, Executor):** completada. Nuevos: `BookingService` (create transaccional + cancelWithToken idempotente + rate limit 5/10min por ip_hash HMAC), `BookingApiController` (contrato del diseño §6; CORS: same-origin sin clave, cross-origin exige `X-Booking-Key` — settings `booking_api_key` cifrada con app_key — y origin en `booking_allowed_origins`; honeypot `company_url` responde 201 sin crear), rutas públicas + `Router::options()` (método nuevo en core), columna `ip_hash` añadida a booking_bookings (migración + schema + ALTER dev; la migración aún no distribuida, edición segura). Anti-doble-reserva: `findSlot` re-valida el hueco en servidor y la transacción bloquea la FILA DEL SERVICIO (`SELECT … FOR UPDATE` del padre, no del rango) → serializa por servicio sin deadlocks de gap-locks; verificado con carrera HTTP real (php -S con PHP_CLI_SERVER_WORKERS=4 + curl_multi): un 201, un 409, 1 fila. Evento `booking_created` en analytics (try/catch, no rompe reservas). **Fix transversal importante:** `ModuleRegistry::requireEnabled` usaba `Auth::siteId()` (sesión admin) → las rutas públicas de módulos daban 404 a visitantes reales sin sesión (bug latente que afectaba a `/_analytics/collect` en prod; no se vio porque las verificaciones llevaban sesión admin en el navegador). Ahora `resolveSiteId()` cae al sitio público (primer site, mismo criterio que PublicPageController). Test `tests/booking_api.php` 28/28 PASS (sin sesión → valida el fix); suite completa sin regresión; demo analytics re-sembrado tras sus tests.

**B5+B6 (2026-07-02, Executor):** completadas — FASE B terminada. B5: `MailMessage` ganó soporte de adjuntos (`attach()`, PHPMailer `addStringAttachment`) — cambio en `app/Services/Mail` retrocompatible; `BookingMailer` cubre creación (cliente+admin), confirmación (ICS `text/calendar`, UID estable `booking-<id>@host`, DTSTART UTC) y cancelación; sin SMTP configurado marca `skipped` y la reserva JAMÁS se pierde. Página pública de cancelación `/_booking/cancel/{id}` (HTML autónomo, GET confirma + POST ejecuta + aviso al admin). Admin `/admin/booking/reservas`: filtros próximas/pasadas/estado/servicio, contador de pendientes, confirmar/cancelar con email y conservación de filtros. B6: widget `pp-booking-widget.js` deriva la API del origen de su propio `src`, muestra horas en la zona del sitio (sin convertir al visitante), refresca agenda si recibe 409; panel de integración en `/admin/booking` genera claves `ppbk_*` (cifradas con app_key, se muestran en claro solo al admin), valida orígenes (esquema+host[:puerto]) y pinta el snippet. Verificado en navegador con DOS servidores preview (8788 app + 8797 página externa en scratchpad, config `external-widget` en launch.json): widget cargó cross-origin con key+allowlist, reserva completada, confirmada desde admin, página de cancelación OK, cero errores de consola. Tests: `booking_emails.php` 17/17; suite completa verde. Estado dev: reserva demo id 11 confirmada (Cliente Externo, 06/07 09:00); API key demo activa con orígenes localhost:8797.

**C1 (2026-07-02, Executor):** documento `cursor/commerce-design.md` redactado, a la espera de aprobación. Claves: 3 tablas (`commerce_products` con slug único por sitio e imagen de la biblioteca, `commerce_orders` con snapshot de totales + número público `PC-<año>-<seq>`, `commerce_order_items` con snapshot de nombre/precio y `SET NULL` al borrar producto), importes SIEMPRE en céntimos (EUR fijo v1, columna currency preparada), precios con IVA incluido y desglose hacia atrás, carrito en sesión PHP (ya arranca en público, sin tabla), rutas `/tienda/*` dinámicas fuera de la caché de páginas y con CSRF (hay sesión, a diferencia de la API booking), `PaymentMethodInterface` con ManualPayment (C4) y StripeCheckout (C5, claves cifradas por sitio + webhook con firma + modo test/live, previa búsqueda web de docs → `cursor/stripe-api.md`), stock decrementado al pasar a `paid` (sobreventa teórica aceptada y documentada), nuevo tipo `purchase_conditions` en LegalPageGenerator con hechos no inventables (patrón own_analytics), conversión `purchase` en Analytics (C7). 3 preguntas abiertas al usuario en §10.

**C2 (2026-07-02, Executor, Opus):** completada. Migración `2026_07_02_commerce.sql` aplicada y registrada en dev; mismas 3 tablas en `install/schema.sql` (diff normalizado idéntico; se añadió `access_key` a commerce_orders para la URL /gracias, reflejado en el diseño). Módulo `commerce` marcado `available:true` → los 2 asserts de modules_registry que usaban commerce como "no disponible" migrados a un módulo inexistente (todo el catálogo está ya construido). Nuevos: `app/Modules/Commerce/{ProductStore, CommerceSettings, CommerceAdminController, routes}.php`, vistas `views/admin/commerce/{index,edit}.php`, `admin/assets/js/commerce-product-editor.js` (selector de imagen reutilizando el endpoint JSON `/admin/media/library` y el CSS `pp-modal` existente — el markup del modal usa `__header/__body/__footer` y el atributo `hidden`, no clases propias), CSS `pp-commerce-*` + icono nav `pp-icon--commerce` + `.is-hidden` global, nav "Tienda" condicional. `CommerceSettings` centraliza settings del módulo + dinero (eurosToCents/centsToInput/format — céntimos enteros siempre, coma decimal española). Productos nacen en borrador (active=0) desde el alta rápida; regla: producto activo exige precio > 0; slug único por sitio con sufijos -2/-3 y solo se regenera si cambia el nombre. Test 29/29 PASS; suite completa sin regresión. Verificado en navegador: guard 404↔200, alta, selector de imagen (modal con 32 imágenes, elegir, persistir), precio "19,95" ↔ 1995 céntimos, validación sin perder edición, listado con miniatura. Estado dev: módulo commerce ACTIVO con producto demo "Camiseta corporativa" (id 4, 19,95 €, stock 25, imagen 173).

**C3+C4 (2026-07-02, Executor, Fable):** completadas. C3: `ShopRenderer::send()` reproduce el shell de PageController::render (DesignSystem::renderHead + VisualStyleService bodyClass + BrandService header/footer + pp-ux.js + analytics condicional) con CSS de tienda inline sobre tokens `--pp-*` → la tienda hereda paleta/tipografía/radios del sitio sin configurar nada; rutas `/tienda` y `/tienda/p/{slug}` dinámicas (nunca CacheService). C4: `CartService` en `$_SESSION` (mapa product_id→qty por sitio; totales SIEMPRE desde BD; redondeo por línea half-up en ambos modos de IVA — incluido: iva=total−total/(1+r); excluido: iva=round(neto×r)); `OrderStore::createFromCart` transaccional con lock de la fila del SITIO (serializa checkouts: números consecutivos + stock sin carreras), `transition()` decrementa stock exactamente una vez (pending_payment→paid, GREATEST(0,…)); página `/tienda/gracias/{num}?k=` con access_key anti-enumeración; `Payments\{PaymentMethodInterface, PaymentStart, ManualPayment, PaymentMethods}` (StripeCheckout se registra en C5); `CommerceMailer` patrón BookingMailer. Legal: tipo `purchase_conditions` añadido a `LegalPageGenerator::TYPES` con `typesFor($siteId)` que lo oculta sin commerce (controllers de Privacidad migrados a typesFor), hechos objetivos `{own_commerce}` (métodos de pago reales, envío, IVA incl/excl, compra invitado, cookie de sesión exenta) inyectados al prompt GENERATE_LEGAL_PAGE con estructura para condiciones de compra (desistimiento 14 días, garantía, ODR) + instrucción para privacy. Tests: `commerce_checkout.php` 37/37 (matemática IVA, secuencia, out_of_stock dentro del lock, doble decremento, rate limit, flujo HTTP completo con cookie jar y CSRF, honeypot, 404 con clave mala); suite completa verde. Verificado en navegador: compra completa con el diseño real del sitio; "Condiciones de compra" aparece/desaparece con el toggle del módulo. Estado dev: pedido demo PC-2026-0001 pending_payment (comprador@example.com). OJO tests HTTP: no usar CURLOPT_CUSTOMREQUEST con FOLLOWLOCATION (re-POSTea el redirect); usar CURLOPT_POST y dejar que el 302 pase a GET.

**C5 (2026-07-06, Executor, Fable):** completada. Búsqueda web previa (regla APIs externas) → `cursor/stripe-api.md` (Checkout Sessions, form-encoding anidado, firma de webhook HMAC-SHA256 con tolerancia 5 min, eventos `checkout.session.*`, fulfillment webhook+success page, claves por modo). **Desviación consciente del diseño C1:** sin SDK `stripe/stripe-php` — la integración solo necesita crear/leer Checkout Sessions y verificar firmas (documentado oficialmente para hacerlo a mano), así el módulo no arrastra dependencia nueva y los tests corren sin red (transporte fake inyectable `StripeApi::$httpOverride`). Nuevos: `Payments/{StripeConfig,StripeApi,StripeCheckout}.php`, `StripeWebhookController.php`, vista `views/admin/commerce/payments.php` + rutas (`GET/POST /admin/commerce/pagos`, `POST /tienda/stripe/webhook`, `GET /tienda/pagar/{number}`), CSS `pp-stripe-*`. Decisiones de diseño: (1) line_items en céntimos BRUTOS desde el snapshot (`line_total_cents` lleva el IVA en ambos modos) → la suma en Stripe SIEMPRE iguala `total_cents`; si el bruto no es divisible por qty (redondeo por línea en modo IVA excluido) la línea colapsa a qty 1 con «× N» en el nombre. (2) Interfaz de pagos ganó `pendingInstructions()` PURO para la página de gracias (start() de Stripe tiene efectos — crea sesión — y gracias se recarga); manual reusa su HTML. (3) cancel_url y success_url apuntan ambas a gracias (el carrito ya se vació al crear el pedido): si sigue pendiente muestra botón "Completar el pago" (`/tienda/pagar/{num}` crea sesión nueva); al aterrizar, `reconcileStripe` consulta la sesión y confirma si `payment_status=paid` (refuerzo oficial del webhook — cubre dev sin webhook). (4) Webhook verifica contra los whsec de AMBOS modos (cubre cambio test↔live), 400 si firma inválida, 200 a eventos irrelevantes; idempotente vía `OrderStore::transition` (no-op si ya paid); `payment_ref` pasa de `cs_…` a `pi_…` al confirmar (conciliación en el Dashboard). (5) UI `/admin/commerce/pagos` para no técnicos: pasos 1-2-3, radio pruebas/real con explicación, claves tipo password que nunca se re-muestran (placeholder `sk_test_••••abcd`, blanco = conservar), URL de webhook con botón copiar, validación amigable (pk_ pegada → mensaje específico), botón "Desactivar pago con tarjeta" que borra secretos, textarea de instrucciones de pago manual (primera UI de ese setting). Tests: `commerce_stripe.php` 45/45 (cifrado, firma pura, line_items ambos modos IVA, start con transporte fake + fallo de API → fallback con reintento, markPaid idempotente/sitio ajeno, webhook HTTP real firmado → paid+stock, réplica sin doble decremento, firma mala → 400); regresión: commerce_checkout/products, modules_registry, legal PASS. Verificado en navegador (preview 8788): página de pagos, error pk_, guardado+badge "Activo · modo pruebas"+máscaras, radio de tarjeta apareciendo/desapareciendo en el checkout público según claves, desactivar OK. Sin migraciones (payment_ref ya existía; settings reutiliza tabla). Estado dev: claves de prueba borradas, sin pedidos nuevos. **Pendiente del usuario para el criterio de éxito completo:** compra end-to-end en modo test con claves reales de Stripe (tarjeta 4242…) — requiere su cuenta de Stripe; con `stripe listen --forward-to localhost:8765/tienda/stripe/webhook` o pegando la URL pública del webhook.

**C6 (2026-07-06, Executor, Opus):** completada. Gestión de pedidos en `/admin/commerce/pedidos`. Nuevos métodos en `OrderStore`: `listForAdmin` (filtros status/method/búsqueda por nº·email·nombre, 200 máx, item_count por subquery), `countByStatus` (para los contadores de las tabs), `saveAdminNotes`, y la constante `ADMIN_TRANSITIONS` (pending_payment→paid/cancelled, paid→shipped/cancelled, shipped→cancelled, cancelled→∅) que restringe/valida lo que el panel ofrece SIN tocar `transition()` (que el webhook de Stripe sigue usando directo). Controller: `orders` (listado), `order` (detalle), `orderStatus` (valida contra ADMIN_TRANSITIONS → `transition()` → `sendStatusChange`), `orderNotes`. Reutiliza `CommerceMailer::sendStatusChange` (paid/shipped/cancelled, ya existía de C4) y el decremento de stock de `transition()` (solo pending_payment→paid, una vez). Vistas `views/admin/commerce/{orders,order}.php` pensadas para no técnicos: tabs de estado con contadores en vivo, buscador + filtro de método, detalle con panel "¿Qué quieres hacer con este pedido?" (solo las acciones válidas + microcopy del efecto), líneas+totales, datos de cliente/envío, referencia de pago (cs_/pi_), estado del email, y notas internas privadas. Fix colateral: el aviso al admin de `CommerceMailer::sendCreated` enlazaba a `admin/commerce/orders` (ruta inexistente) → corregido a `admin/commerce/pedidos/{id}`. CSS `pp-order-*` (tabs tipo pill, grid detalle 1.4fr/1fr responsive, totales alineados dt/dd — la clase pública `pp-shop-totals` no está estilada en el admin, se le dio estilo propio). Test `tests/commerce_orders_admin.php` 25/25 PASS (máquina de estados, filtros, búsqueda, notas, y flujo HTTP con login admin: listado, filtro, detalle, transición inválida rechazada, pending→paid con stock, paid→shipped, notas, 404→listado). Regresión commerce_checkout/stripe/products verde. Verificado en navegador: listado con 3 pedidos demo (tabs+pills), detalle, "Marcar como pagado" → pill "Pagado" + stock descontado + acciones actualizadas a enviar/cancelar; sin errores de consola. **Estado dev:** módulo commerce ACTIVO con 3 pedidos demo (PC-2026-0001 pending manual, -0002 paid manual, -0003 pending stripe) sobre "Camiseta corporativa" (stock 25→23 por el pedido pagado) para verificación manual del usuario.

**C7 (2026-07-06, Executor, Fable):** completada — FASE C y FEAT-3 terminados. (1) **Conversión `purchase`**: registrada en `ShopController::checkoutSubmit` justo tras crear el pedido, en la petición del visitante (mismo patrón y punto de anclaje que `booking_created` en B4; el webhook de Stripe NO puede registrarla porque la petición es del servidor de Stripe, con UA de bot y sin contexto del visitante). Etiquetas nuevas en el dashboard: `purchase` → "Compra" y de paso `booking_created` → "Reserva" (faltaba desde B4). (2) **Placeholder `{{products:featured}}`**: tercera clase de placeholder canvas junto a form/posts (regex + ref canónica con opciones limit/heading ordenadas — las claves ya estaban permitidas en `parsePlaceholderOptions`); expande a `App\Modules\Commerce\FeaturedProductsRenderer` (markup autocontenido con tokens `--pp-*`, CSS emitido una vez por petición, activos más recientes con agotados al final, tarjetas → ficha + CTA "Ver toda la tienda"); módulo apagado o sin productos activos → comentario HTML invisible (la página no rompe). Envuelto en `.pp-canvas-embed[data-pp-placeholder]` → el editor FH4 lo revierte al placeholder al guardar, gratis. (3) **IA**: variable `{modules_hint}` añadida a COMPOSE_CANVAS_PAGE + EDIT_CANVAS_SECTION + EDIT_CANVAS_PAGE, rellenada por `CanvasService::modulesHint($siteId)`: con tienda activa enseña la sintaxis y prohíbe dibujar tarjetas de producto a mano; sin tienda, prohíbe explícitamente el placeholder (evita huecos invisibles en sitios sin commerce). (4) **Caché**: `ProductStore::{create,update,delete}` hacen `CacheService::flush` del sitio (las canvas cachean HTML estático con el grid dentro; mismo criterio que el toggle de módulos). Fix colateral: los checkouts HTTP de `tests/commerce_checkout.php` dejaban eventos `purchase` huérfanos en dev con analytics activo → el test ahora captura MAX(id) previo y borra solo los suyos. Tests: `commerce_c7.php` 25/25 (hint por estado del módulo, comentario con módulo off/sin productos, grid con limit/heading/precio/enlaces/ref canónica, CSS una sola vez, flush de caché en update, compra HTTP → evento con analytics on y ausencia con off, limpieza quirúrgica sin vaciar analytics_events); regresión completa verde (commerce_checkout/stripe/products/orders_admin, modules_registry, canvas_runtime, blog_canvas_regression, canvas_settings, canvas_box_editor — 0 FAIL). Verificado en navegador con página canvas temporal `/c7-demo` (creada y borrada): grid renderizado con chrome del sitio, tarjeta → `/tienda/p/camiseta-corporativa`, CTA → `/tienda`, `data-pp-placeholder="products:featured|limit=3|heading=Productos destacados"`. Estado dev: sin restos (página demo borrada, evento de prueba borrado, módulos como estaban: commerce/analytics/booking ON, 3 pedidos demo de C6 intactos).

## Lessons (FEAT-3)

- Los tests que tocan `settings` deben GUARDAR y RESTAURAR los valores previos, no borrarlos a ciegas en la limpieza: `booking_api.php` machacó la API key real generada desde el admin hasta que se corrigió.
- `Auth::siteId()` es SOLO sesión admin: cualquier ruta pública que lo use da 404/null a visitantes reales. Para rutas públicas usar `ModuleRegistry::resolveSiteId()` (fallback al primer site). Y al verificar rutas públicas en navegador, probar también SIN sesión admin (curl), o el bug queda enmascarado.
- El usuario MySQL de dev (`ppress`) NO puede crear bases de datos temporales (grants solo sobre `ppress_dev`); para comparar `install/schema.sql` con migraciones usar diff textual normalizado, no import a BD scratch. Y ojo con `2>/dev/null` en mysql: convirtió ese fallo de permisos en un falso "DIFFER".
- `.pp-card` del admin es `display:flex` en fila (viene de las tarjetas de dashboard): una tarjeta de contenido/formulario necesita override `display:block` o sale todo en columnas horizontales (C5 lo sufrió; `.pp-booking-integration` probablemente también lo arrastra).
- Al factorizar un método en dos (p.ej. start() → pendingInstructions()), revisar el `return` del cuerpo movido: en C5 quedó devolviendo `PaymentStart` dentro de un método `?string` y solo lo cazó el test HTTP de regresión de C4 (el test nuevo no pasaba por el camino manual). Correr siempre la suite del módulo entero, no solo el test de la tarea.

---

# FEAT-4 — Anti-bot propio: time-trap + proof-of-work (2026-07-07, Planner)

## Background and Motivation (FEAT-4)

Los formularios de la plataforma ya cumplen bien la parte RGPD, pero la protección anti-bot actual se limita a tres capas básicas: token CSRF, honeypot `company_url` (responde "ok" falso sin guardar) y rate limit por IP hasheada (5 envíos / 10 min / sección, `FormSubmissionService::isRateLimited`). Eso para bots que ejecutan JS o rellenan el honeypot con cuidado no basta. El usuario quiere una solución **interna, sin depender de Google ni de terceros**, coherente con el posicionamiento RGPD del producto (sin cookies, sin identificar al visitante, sin enviar datos fuera).

Decisión del usuario (2026-07-07): implementar las opciones 1 (trampa de tiempo con timestamp firmado) y 2 (proof-of-work estilo ALTCHA, propio). Descartadas por ahora: heurísticas de contenido/spam-score (opción 3, posible mejora futura del panel) y captchas visuales caseros o Turnstile (opción 4).

## Key Challenges and Analysis (FEAT-4)

- **Tres superficies de envío comparten el patrón honeypot** y son candidatas al mismo escudo: formularios (`FormController::submit`), booking (`BookingApiController` L97) y checkout commerce (`ShopController` L218). Conviene un servicio único (`App\Services\Security\BotGuard`) en vez de lógica duplicada. Alcance de FEAT-4: formularios primero; booking/commerce como tarea final de extensión.
- **Caché de páginas NO es problema para el time-trap**: las páginas con formulario nunca se cachean (`PageController::pageHasForm` L51/L81 y `$cacheable = !$canvasHasForm` L235, cubre clásico y canvas). El timestamp firmado se puede emitir en el render (`SectionRenderer` L385-388, junto al CSRF y el honeypot actuales) y refleja la visita real.
- **Material de clave ya existe**: `app_key` en `config/config.php` (64 hex = 32 bytes). No usarla en crudo: derivar clave de propósito con `hash_hmac('sha256', 'botguard', app_key)` para no acoplar el escudo a la clave maestra de cifrado (`Core\Crypto`).
- **El PoW debe ser stateless en la emisión pero anti-replay en la verificación**: un reto firmado con HMAC no necesita BD para validarse, pero un bot podría resolver una vez y reenviar la misma solución N veces. Hace falta registro de retos consumidos (tabla pequeña con TTL corto, purga perezosa) — mismo patrón "lazy" que el rollup de analytics.
- **Degradación sin JavaScript**: el PoW requiere JS (`crypto.subtle`, disponible en todos los navegadores modernos pero solo en contextos seguros — HTTPS o localhost; dev funciona). Propuesta (pendiente de confirmar por el usuario): sin solución PoW en el POST, el envío se ACEPTA si pasa CSRF + honeypot + time-trap + rate limit, para no romper accesibilidad ni navegadores raros. El time-trap queda como suelo mínimo universal. Alternativa más dura (rechazar sin PoW) se descarta de inicio: penaliza a humanos reales.
- **La respuesta ante bot detectado imita el éxito** (patrón honeypot existente): "ok" falso, sin crear nada, para no dar señal al atacante. El time-trap y el PoW inválido deben seguir el mismo criterio.
- **Instalaciones nuevas**: cualquier tabla nueva debe añadirse a `database/migrations` Y a `install/schema.sql` (lección de la divergencia install/migrations ya conocida).
- **Dónde NO tocar**: el flujo RGPD/consent de los formularios y el guardado de submissions no cambian; el escudo actúa antes, como gate.

## High-level Task Breakdown (FEAT-4)

### Fase 1 — Time-trap (suelo mínimo universal)

- **AB1 — Servicio `BotGuard` + time-trap en formularios.** Crear `app/Services/Security/BotGuard.php`: `issueTimestamp()` → valor `ts.firma` (HMAC-SHA256 con clave derivada de `app_key`) y `verifyTimestamp(?string, int $minSeconds = 3, int $maxSeconds = 21600)` → enum ok / too_fast / expired / invalid. Emitir hidden `_pp_ts` en `SectionRenderer` (junto a `_csrf`/honeypot, L386-388). En `FormController::submit`, tras el honeypot: too_fast/invalid → respuesta "ok" falsa (mismo patrón honeypot); expired → error amable pidiendo reintentar (humano legítimo con pestaña abierta >6h). Test CLI `tests/botguard_timetrap.php`: firma válida/manipulada, <3s, >6h, envío normal pasa.
  - _Éxito_: test ALL PASS; envío humano en navegador funciona igual que antes; un `curl` inmediato con `_pp_ts` recién emitido (<3s) devuelve "ok" sin crear fila en `form_submissions`.

### Fase 2 — Proof-of-work propio (estilo ALTCHA)

- **AB2 — Emisión y verificación del reto PoW en `BotGuard` + migración.** Reto: `{salt aleatoria, dificultad (bits/umbral), expira, firma HMAC}`; solución: número `n` tal que `sha256(salt + n)` cumple el umbral. `issueChallenge()` / `verifySolution()` con anti-replay: tabla `botguard_solved` (hash del reto, expires_at; índice único) con purga perezosa de caducados. Migración + `install/schema.sql`. Dificultad inicial conservadora (~0,5-1 s de CPU media), constante en el servicio para ajustar fácil. Test CLI: solución correcta pasa una sola vez (replay falla), firma manipulada falla, reto caducado falla.
  - _Éxito_: test ALL PASS; diff normalizado migración vs schema.sql sin divergencia.
- **AB3 — Endpoint de reto + script cliente.** Ruta pública `POST /_botguard/challenge` (stateless, sin CSRF, 200 JSON; patrón de `/_analytics/collect`: siempre responde, tolerante a fallos) con rate limit propio por IP hasheada. Script `public/js/pp-botguard.js`: al cargar página con `form[data-pp-form-id]`, pide reto y resuelve en segundo plano con `crypto.subtle` (troceado con `setTimeout`/`requestIdleCallback` para no congelar la UI; sin Web Worker externo para mantener un solo fichero), rellena hidden `_pp_pow`; si el usuario envía antes de terminar, el submit espera a la solución con un pequeño estado "enviando…". Inyectar el script solo en páginas con formulario (mismo mecanismo que decide no cachearlas). CSS del estado en `styles.css` (nunca inline).
  - _Éxito_: en navegador, el hidden `_pp_pow` se rellena solo; consola sin errores; página sin formulario NO carga el script.
- **AB4 — Verificación PoW en el submit + degradación sin JS.** En `FormController::submit`: `_pp_pow` presente y válido → pasa; presente e inválido/replay → "ok" falso; ausente → se acepta si pasa el resto de capas (decisión de degradación, confirmar con usuario antes de implementar). Registrar en `form_submissions` un campo ligero `bot_check` (p.ej. 'pow' / 'timetrap-only') para poder auditar cuántos envíos llegan sin PoW — sin datos personales nuevos. Test CLI de integración: envío con PoW válido crea submission con bot_check='pow'; inválido no crea nada y responde ok; sin PoW crea con 'timetrap-only'.
  - _Éxito_: test ALL PASS + verificación en navegador de un envío real end-to-end; regresión de suite de formularios verde.

### Fase 3 — Extensión a las otras superficies

- **AB5 — Booking y Commerce.** Aplicar `BotGuard` (time-trap y, donde el flujo lo permita, PoW) a `BookingApiController` y `ShopController::checkoutSubmit`, reutilizando el servicio sin duplicar lógica. El widget de booking (`pp-booking-widget.js`) y el checkout renderizado en `ShopController` L432 ya emiten honeypot; añadir ahí los campos. Regresión completa de booking/commerce.
  - _Éxito_: tests `booking_api.php`, `commerce_checkout.php` y los nuevos verdes; reserva y compra reales en navegador funcionan.

## Project Status Board (FEAT-4)

- [x] AB1 — BotGuard + time-trap en formularios (2026-07-07, pendiente verificación manual del usuario)
- [x] AB2 — Reto PoW + anti-replay + migración (2026-07-07)
- [x] AB3 — Endpoint de reto + pp-botguard.js (2026-07-07)
- [x] AB4 — Verificación PoW en submit + degradación sin JS (2026-07-07)
- [x] AB5 — Extensión a booking y commerce (2026-07-07) — FEAT-4 COMPLETA, pendiente confirmación Planner/usuario

## Decisiones cerradas (usuario, 2026-07-07)

1. **Degradación sin JS (AB4)**: CONFIRMADA la propuesta — sin PoW se acepta si pasan las demás capas, auditable vía `bot_check`.
2. **Umbrales**: CONFIRMADOS los iniciales — time-trap 3 s mín / 6 h caducidad; PoW ~0,5-1 s de CPU. Constantes ajustables en `BotGuard`.

## Current Status / Progress Tracking (FEAT-4)

**AB1 (2026-07-07, Executor):** completada. Nuevo `app/Services/Security/BotGuard.php`: `issueTimestamp()` → `_pp_ts = "<unix_ts>.<hmac-sha256>"` con clave de propósito derivada (`hash_hmac('sha256','botguard-v1', app_key)`), y `verifyTimestamp()` → OK / TOO_FAST / EXPIRED / INVALID (incluye rechazo de timestamps futuros y `hash_equals` contra timing attacks). Hidden `_pp_ts` emitido en `SectionRenderer` junto a `_csrf`/honeypot (cubre también formularios embebidos en canvas vía `{{form:id}}`, verificado). En `FormController::submit`, tras el honeypot: TOO_FAST/INVALID → "ok" falso sin crear nada (mismo patrón honeypot); EXPIRED → error 422 amable pidiendo recargar. Test `tests/botguard_timetrap.php` 14/14 PASS. Verificación HTTP end-to-end con `php -S` y página canvas temporal `/ab1-demo` ({{form:469}}): POST inmediato (<3 s), firma manipulada y `_pp_ts` ausente → los tres responden `{"ok":true}` SIN `submission_id` y sin fila en BD; POST con token de hace 60 s → submission real creada. Regresión: canvas_runtime, form_templates, form_inline_insert, blog_canvas_regression → 0 FAIL. Estado dev limpio: submission de prueba y página demo borradas, `__forms` restaurada a draft, caché flush, server parado.

**AB2 (2026-07-07, Executor):** completada. PoW añadido a `BotGuard`: `issueChallenge()` emite reto stateless `"1.<expira>.<salt 32hex>.<bits>.<hmac>"` (POW_BITS=15 ≈ 33k hashes de media; POW_TTL=2 h; constantes ajustables); `verifySolution("_pp_pow" = reto + ".<nonce>")` → POW_OK/INVALID/EXPIRED/REPLAY. Verificación = 1 hash + firma; rechaza bits rebajados y retos futuros/pasados. Anti-replay: tabla `botguard_solved` (hash del reto SIN nonce → re-resolver el mismo reto con otro nonce también es REPLAY; INSERT IGNORE detecta el duplicado; purga perezosa de caducados en cada verificación). Migración `2026_07_07_botguard.sql` aplicada en dev + tabla añadida a `install/schema.sql` (diff normalizado IDENTICAL) + `BotGuard::ensureSchema()` como red de seguridad. Test `tests/botguard_pow.php` 16/16 PASS (resuelve retos reales por fuerza bruta en PHP, ~7 ms; limpia sus filas). Regresión AB1 verde. Nota para AB3: el usuario ha delegado la verificación manual — el Executor verifica en navegador/curl por su cuenta.

**AB3 (2026-07-07, Executor):** completada. (1) `POST /_botguard/challenge` (`BotGuardController`, ruta en app/routes.php junto a /forms): stateless, sin CSRF, sin rate limit — DESVIACIÓN razonada del plan: emitir el reto es 1 HMAC sin BD, más barato que el lookup que exigiría un rate limit; el coste real recae en resolver (cliente) y el anti-replay ya impide reutilizar. (2) `public/js/pp-botguard.js`: un reto POR formulario (si compartieran solución, el 2º envío sería REPLAY → falso ok a un humano); resuelve con crypto.subtle en lotes de 128 digests concurrentes cediendo el hilo entre lotes; inserta hidden `_pp_pow` (con `data-pp-expires`); intercepta submit en CAPTURA sobre document (corre antes que el listener AJAX de pp-ux.js, cuyo guard `e.defaultPrevented` hace el resto) → si el reto sigue resolviéndose retiene con aria-busy (estilo ya existente en DesignSystem) y relanza con requestSubmit(); reto caducado al enviar → retira el campo (degradación confirmada); sin crypto.subtle/fetch (HTTP plano o navegador viejo) el script no hace nada. (3) Inyección en `PageController::render` SOLO en páginas con formulario (nunca cacheadas) con `?v=filemtime`. Verificado en navegador (preview 8788, página canvas temporal /ab3-demo): script presente solo donde toca, `_pp_pow` relleno solo, envío AJAX con éxito, camino "enviar antes de resolver" retiene y relanza. **Bug cazado y corregido en la verificación**: si la promesa terminaba con el form aún 'pending', el relanzamiento se re-interceptaba en bucle infinito (pestaña congelada) → blindaje en finish(): 'pending' pasa a 'failed' antes de relanzar. Consola limpia; regresión verde (botguard 16+14, canvas_runtime, form_templates); dev limpio (submissions/página demo/botguard_solved borrados, caché flush).

**AB4 (2026-07-07, Executor):** completada — Fases 1 y 2 TERMINADAS. `FormController::submit` verifica `_pp_pow` tras el time-trap: válido → `bot_check='pow'`; inválido/replay → "ok" falso sin fila; ausente o caducado → degradación confirmada (`bot_check='timetrap'`). Columna `bot_check VARCHAR(16) DEFAULT 'none'` añadida en los TRES sitios (ensureSchema de FormSubmissionService, migración 2026_07_07_botguard.sql con ALTER, install/schema.sql) y aplicada en dev. Test integración HTTP `tests/botguard_submit.php` 10/10 (server propio + página canvas temporal + CSRF de sesión real: pow válido crea fila 'pow', replay y corrupto → ok falso sin fila, sin pow → fila 'timetrap', time-trap fresco gana a un PoW válido, ts caducado → 422; limpia todo). Regresión completa 0 FAIL (botguard×3, form_templates, form_inline_insert, canvas_runtime, blog_canvas_regression, modules_registry). Dev limpio (0 restos, botguard_solved vacía).

**AB5 (2026-07-07, Executor):** completada — FEAT-4 terminada a falta de confirmación. **Booking**: la respuesta de availability incluye `bot_ts` (=issueTimestamp); `pp-booking-widget.js` lo guarda y lo reenvía como `_pp_ts` al crear la reserva; en `BookingApiController::create`, `_pp_ts` presente y TOO_FAST/INVALID → 201 falso sin crear; AUSENTE o caducado → se acepta (compatibilidad con integraciones directas del API público, decisión coherente con la degradación confirmada). **Commerce**: checkout renderiza hidden `_pp_ts` y `checkoutSubmit` lo verifica ESTRICTO (ausente/manipulado/<3 s → éxito aparente del honeypot: vaciar carrito + redirect; caducado → error amable re-renderizando). PoW NO aplicado a booking/commerce: el plan decía "donde el flujo lo permita" — el widget corre en orígenes externos (CORS) y ambos flujos son multi-paso con rate limit propio; el solver actual es específico de formularios. Tests actualizados: `commerce_checkout` (+2 checks: render de _pp_ts y bot directo sin él → sin pedido), `commerce_c7` (POST con ts envejecido), `booking_api` (+3 checks: bot_ts en availability, ts fresco y manipulado → 201 falso sin crear; el resto del test cubre "ausente → se acepta"). Regresión completa booking+commerce 8 suites 0 FAIL. Verificado en navegador (harness estático temporal + widget real): reserva end-to-end creada con bot_ts viajando; borrada después. Dev limpio (harness, reserva, botguard_solved vacía).

**Pendiente para el cierre de FEAT-4 (pedido por el usuario 2026-07-07):** entregar el bloque SQL consolidado para actualizar la BD del sitio en producción (como mínimo `2026_07_07_botguard.sql` + INSERT en migrations; revisar si esa instalación tiene ya las tablas de FEAT-3 y las columnas Canvas — ver memoria de divergencia install/migrations).

## Executor's Feedback or Assistance Requests (FEAT-4)

- AB1 lista para verificación manual del usuario: publicar (o previsualizar) una página con formulario, enviarlo como humano normal (debería funcionar igual que siempre) y, si se quiere, reenviar inmediatamente tras recargar (<3 s) para ver el "ok" falso sin submission en el panel. Tras confirmación, siguiente tarea AB2 (reto PoW + anti-replay + migración, recomendada con Fable).

---

Histórico completo de tareas cerradas (Unsplash/imágenes, panel/chat Studio, limpieza plantillas-bloque, chrome header/footer) archivado en [`.cursor/scratchpad-archive.md`](scratchpad-archive.md).

# FEAT-5 — Asistente central del sitio: cambios multi-página por chat/documento (2026-07-17, Planner)

## Background and Motivation (FEAT-5)

Una vez entregada la web, el cliente pide tandas de cambios que afectan a varias páginas ("cambiad el teléfono en toda la web", "aquí va el nuevo texto de Servicios y quitad la sección de precios", a veces en un documento adjunto). Hoy eso exige entrar página a página al Studio y pedir cada cambio por separado. Se quiere una IA "central" a nivel de sitio: recibe una petición en texto libre y/o un documento, decide qué páginas tocar, aplica los cambios y devuelve un informe (qué hizo, qué no es viable, qué no entendió).

## Key Challenges and Analysis (FEAT-5)

**Qué ya existe y se reutiliza (por eso la viabilidad es ALTA):**
- Pipeline de edición por chat de una página canvas: `CanvasController::chat()` → `applySectionEdit`/`applyPageEdit`, con sanitizado (`CanvasSanitizer`), versionado (25 versiones, undo/redo, restore) y draft/publish. El motor de "aplicar un cambio a una página" está resuelto; el asistente central es un orquestador encima.
- Extracción de documentos: `TextExtractor` (ya usado en Onboarding/Documents/Posts) + `DocumentSummarizer`.
- Infra IA: `AIProviderFactory`, `PromptBuilder`, `AILogger`/`AIPricing` (coste registrado por llamada), override de modelo por petición.

**Retos reales:**
1. **Planificación (nuevo)**: mapear la petición → lista de tareas por página. Necesita una llamada IA "planner" con el sitemap del sitio (páginas: título, slug, render_mode, lista de secciones vía `CanvasService::listSections`) que devuelva JSON: items `{page_id, section?, instruction, viable|no_viable|ambiguo, motivo}`. Este paso es también el que genera el informe (lo no viable / no entendido se clasifica ANTES de tocar nada).
2. **Páginas en modo `sections` (bloques)**: el pipeline de chat solo cubre canvas. V1: el planner marca cambios sobre páginas de bloques como "no viable desde aquí" con enlace al editor clásico (o solo campos de texto simples si sale barato). No bloquea el valor principal: las páginas de marketing son canvas (pivote C10).
3. **Duración/UX**: N páginas = N llamadas IA (30-90 s cada una). PHP sin colas → tabla de trabajos + ejecución secuencial con polling desde el navegador (mismo patrón request-a-request que ya usa el Studio, pero encadenado por JS). Sin cron ni workers.
4. **Seguridad del resultado**: cada cambio se guarda como versión draft con summary "Asistente — <petición>"; NADA se publica solo. El informe final lista cada página con enlace a su preview y su botón de publicar/deshacer ya existentes. Reversión = restore por página (ya existe).
5. **Ambigüedad**: si el planner clasifica un item como ambiguo, NO se ejecuta; se pregunta en el propio chat central. Mejor preguntar que adivinar (misma filosofía que el resto del producto).
6. **Coste**: 1 llamada planner (barata, solo sitemap+petición) + 1 por página afectada. Se muestra el plan ANTES de ejecutar → el usuario confirma y ve cuántas páginas se van a tocar.

**Decisión de diseño clave (a validar por el usuario):** flujo en dos fases — (1) "Proponer plan": la IA responde con el desglose por página y lo no viable; (2) "Aplicar": el usuario confirma y se ejecutan los items viables uno a uno con progreso visible. Evita sorpresas, cortes a mitad y gasto no consentido.

## High-level Task Breakdown (FEAT-5)

### Fase 1 — Planner + informe (sin aplicar nada aún)
- **F5-T1**: UI `/admin/assistant` (chat central): textarea + subida de documento (reutiliza `TextExtractor`), listado de conversaciones no necesario en v1 (stateless por petición). Éxito: la página carga, acepta texto y/o doc, muestra el texto extraído.
- **F5-T2**: Servicio `SiteAssistantPlanner`: construye sitemap (páginas + secciones canvas), llama a la IA, devuelve plan JSON validado (items con page_id real, clasificación viable/no_viable/ambiguo + motivo en castellano). Éxito: con una petición de prueba multi-página devuelve plan coherente; item sobre página de bloques sale como no_viable con motivo claro; petición sin sentido sale 100% ambigua.
- **F5-T3**: Render del plan en el chat: agrupado por estado, con "Aplicar N cambios" solo sobre los viables. Éxito: visual verificado en navegador.

### Fase 2 — Ejecución + informe final
- **F5-T4**: Tabla `assistant_jobs` (+items) y endpoint "ejecutar siguiente item": reutiliza internamente el mismo camino que `CanvasController::chat()` (extraer a un servicio compartido `CanvasChatService` lo que hoy vive en el controller, sin duplicar). Cada item aplicado = versión draft con summary del asistente. Éxito: job de 2 páginas deja 2 versiones draft correctas; un fallo IA en el item 2 no rompe el item 1 y queda registrado.
- **F5-T5**: Progreso por polling en la UI + informe final: por página → hecho (enlace a preview + publicar), fallido (motivo), omitido. Éxito: verificado end-to-end en navegador con el dev server.

### Fase 3 — Pulido (posterior, según uso real)
- **F5-T6** (opcional): publicar en lote desde el informe; historial de peticiones del asistente; soporte de campos simples en páginas de bloques.

## Project Status Board (FEAT-5)

- [x] F5-T1 UI chat central + subida de documento (2026-07-17)
- [x] F5-T2 SiteAssistantPlanner (plan JSON + clasificación) (2026-07-17)
- [x] F5-T3 Render del plan + confirmación (2026-07-17)
- [x] F5-T4 Jobs + ejecución item a item (extraer CanvasChatService) (2026-07-17)
- [x] F5-T5 Progreso + informe final (2026-07-17)
- [ ] F5-T6 (opcional) publicación en lote / historial / bloques

## Decisiones pendientes del usuario (FEAT-5)

1. ¿OK al flujo en dos fases (plan → confirmar → aplicar)? Alternativa: aplicar directo sin confirmación (más rápido, más riesgo/gasto).
2. Páginas de bloques en v1: ¿basta con marcarlas "no viable, usa el editor clásico"?
3. ¿El asistente puede tocar también posts del blog, o solo páginas? (Propuesta v1: solo páginas.)

## Decisiones cerradas (usuario, 2026-07-17) (FEAT-5)

1. Flujo en dos fases confirmado: plan → confirmar → aplicar.
2. No existen ya páginas en modo bloques (todo es canvas) → desaparece el reto #2: el planner no necesita la rama "no viable por bloques". Simplifica F5-T2.
3. Alcance v1: solo páginas, no posts del blog.
4. Futuro (backlog, no v1): **F5-T7** — subir varias entradas de blog de golpe y que el asistente las planifique, redacte y publique.

## Current Status / Progress Tracking (FEAT-5)

**F5-T1 (2026-07-17): completada y verificada E2E en navegador.** Nuevos ficheros: `app/Controllers/Admin/AssistantController.php` (GET /admin/assistant + POST /admin/assistant/extract), `views/admin/assistant/index.php`, `admin/assets/js/assistant.js`; CSS namespaced `ppa-*` añadido a `admin/assets/css/admin.css`; entrada "Asistente" en el nav de `views/admin/layout.php` (los splices de módulos pasan de índice 12 a 13 por el item nuevo). El adjunto es stateless: se extrae texto (TextExtractor, cap 60k chars) desde un tmp con extensión y se borra; NO crea filas en `documents`. El envío del chat es stub client-side hasta F5-T2 (`sendRequest()` en assistant.js indica dónde enchufar POST /admin/assistant/plan con {instruction, doc_text}). Verificado: página carga con sesión admin, subida real de TXT → chip con nombre + nº caracteres + "Ver texto" con preview, envío pinta burbujas user/assistant; .png rechazado con 422.

**F5-T2 + F5-T3 (2026-07-17): completadas y verificadas E2E con IA real.** Nueva acción `Actions::PLAN_SITE_CHANGES` (output json, tier main, temp 0.3) + validador de shape `validateSitePlan` en AIActionRunner. Nuevo servicio `app/Services/SiteAssistantPlanner.php`: `sitePages()` (páginas no-article, sin slugs __*, con flag editable=canvas+page_canvas y lista de secciones vía CanvasService::listSections), `renderSiteMap()` (bloques EDITABLES / SIN EDITOR) y `normalizeItems()` (invariantes post-IA: status del vocabulario, page_id real, "aplicar" solo sobre editables, sección inexistente cae a página completa). Endpoint `POST /admin/assistant/plan` en AssistantController (instruction ≤4000 + doc_text ≤60k, doc recortado a 30k en el prompt). UI: `renderPlan()` en assistant.js pinta summary + tarjetas por estado (SE APLICARÁ verde / NECESITO ACLARAR amarillo / NO VIABLE rojo) + botón "Aplicar N cambios" (stub hasta F5-T4, punto de enganche `applyPlan()`). Verificado con IA real (gemini-3-flash via OpenRouter, ~$0.002/plan): petición multi-página → 3 aplicar con página+sección correctas y Bizum/logo no_viable con motivo claro; petición sin sentido → 100% ambiguo con pregunta concreta; documento sin texto libre → plan correcto extraído del doc. Captura OK, consola limpia.

**F5-T4 + F5-T5 (2026-07-17): completadas y verificadas E2E con IA real.** (1) REFACTOR: el pipeline de CanvasController::chat() (imágenes, enrutado sección/página, verificación de imágenes, guardado) movido a `app/Services/Canvas/CanvasChatService::applyInstruction()` (param origin: 'chat'|'assistant'); el controller delega y mapea errores a HTTP; regresión OK (chat del Studio probado vía HTTP + tests/canvas_runtime.php 49/49). (2) JOBS: migración `2026_07_17_assistant_jobs.sql` (assistant_jobs + assistant_job_items, FK cascade) aplicada al dev y AÑADIDA a install/schema.sql (no agravar la divergencia conocida). `app/Services/SiteAssistantJobs.php`: createJob (re-valida items contra sitePages, cap 12), stepJob (un item por request, fallo no detiene los siguientes, job done al agotar pendientes), jobState. Endpoints `POST /admin/assistant/apply` y `POST /admin/assistant/jobs/{id}/step` (el navegador llama step en bucle — sin cron ni colas). (3) UI: progreso por item (en cola/aplicando/hecho/falló) e informe final con enlace "Revisar y publicar →" al Studio por página y nota de que todo queda en borrador. VERIFICADO: job real de 2 páginas → item 1 (Servicios·hero) falló por timeout 60s de OpenRouter y NO impidió el item 2 (Sobre nosotros·quote) que quedó como versión draft origin='assistant' con el texto nuevo en page_canvas; job 'done'; informe correcto con ambos estados. El fallo por timeout es entorno/latencia, no bug: comportamiento diseñado.

**Diagnóstico de incidencia (2026-07-17, Executor):** el mensaje genérico aportado por el usuario corresponde al timeout anterior de 60 s (confirmado en `ai_logs`: `edit_canvas_section`, 60002 ms, 0 bytes). La corrección ya está incluida en `daf4f62`: planner 90 s, sección 120 s, página completa 180 s, margen de request 420 s y un reintento para status 0/429/5xx. Revalidado en HEAD: sintaxis PHP correcta, `tests/canvas_runtime.php` 49/49 PASS y job equivalente de rediseño completo (hero + cinco tarjetas) finalizado en 25,998 ms como borrador. Pendiente: que el usuario repita el cambio en su instalación desplegando primero `daf4f62` si producción aún no lo contiene.

**Seguimiento producción (2026-07-17, Executor):** el usuario confirma que `daf4f62` está desplegado y el item Inicio sigue fallando. El endpoint sí termina y devuelve un fallo controlado, pero `SiteAssistantJobs` sustituye cualquier `AIException` por el mismo texto genérico; no hay evidencia suficiente para distinguir timeout del hosting, HTTP 4xx/5xx, contexto excedido o JSON inválido. Siguiente dato imprescindible antes de cambiar código: `error_message`, `duration_ms`, `provider` y `model` de las dos filas `edit_canvas_page` más recientes en `ai_logs` de producción (visible en `/admin/ai/usage` o mediante SELECT de solo lectura).

**Fix JSON truncado en producción (2026-07-17, Executor):** `ai_logs` confirmó cuatro fallos `Parse/validate: No se pudo parsear JSON`; la salida empezaba correctamente por el hero pero quedaba incompleta, por reescribir Inicio entera en un único `EDIT_CANVAS_PAGE`. Causa de planificación: `PLAN_SITE_CHANGES` ordenaba agrupar todos los cambios de una página en un item. Corregido en `Actions.php`: ahora genera un item por sección existente (permite varios con el mismo `page_id`) y reserva página completa para cambios realmente globales/estructurales. Nuevo test `tests/site_assistant_section_planning.php` verifica la regla y que la normalización conserva `hero` + `servicios` de la misma página (4/4 PASS); `canvas_runtime.php` 49/49 PASS. Verificación con planner IA real sobre la petición de Inicio: produjo dos items `page_id=135`, secciones `hero` y `services`, coste estimado $0.001997, sin aplicar contenido. Pendiente verificación manual en producción tras desplegar este nuevo parche y volver a generar el plan (un plan antiguo seguirá conteniendo el item global).

**Segundo seguimiento producción (2026-07-17, Executor):** tras desplegar la división, producción identifica 2 cambios pero el usuario informa de otro fallo genérico. Reproducción local sin guardar mediante `CanvasChatService::applySectionEdit` sobre la Inicio real: `hero` OK (HTML canvas final 6.805 bytes, CSS 4.489) y `services` OK (HTML 6.805, CSS 4.930) con el mismo Gemini Flash; ambas respuestas JSON válidas en ~8 s combinados. Esto descarta un fallo determinista del nuevo prompt/código. Pendiente dato de producción: resumen `X/2`, qué item falló y las filas recientes `edit_canvas_section` (`model`, `duration_ms`, `error_message`) para comparar entorno/modelo y no parchear a ciegas.

**Fix definitivo de serialización Canvas (2026-07-17, Executor):** producción confirmó que los 4 intentos nuevos (2× `hero`, 2× `servicios`) devolvían HTML pero `json_decode` fallaba. Se elimina la causa estructural: `EDIT_CANVAS_SECTION` y `EDIT_CANVAS_PAGE` ya no transportan HTML/CSS dentro de strings JSON; usan salida de texto con sobre `<pp-html>`, `<pp-css>`, `<pp-reply>`, parseado por `CanvasChatService::parseEditEnvelope`. Esto hace irrelevantes las comillas, saltos y atributos del HTML. TDD: `tests/canvas_edit_envelope.php` 9/9 PASS (comillas, CSS, solo-estilo, vacío rechazado); `site_assistant_section_planning` 4/4 y `canvas_runtime` 49/49 PASS; lint y diff-check verdes. Verificación con IA real sin guardar sobre Inicio: `hero` OK (6.805 bytes HTML final / 4.544 CSS) y `services` OK (6.997 / 4.821), ~9 s total. Sin migración. Pendiente despliegue y prueba manual de un plan NUEVO en producción.

**Fix aclaraciones y continuidad del chat (2026-07-17, Executor):** captura de producción mostró dos falsos `ambiguo`: el propio `reason` decía que eran aplicables, pero no había botón; responder "sí, procede" generaba un plan nuevo sin contexto y devolvía 0 cambios. Backend: `SiteAssistantPlanner` normaliza alias (`aplicable`/`viable`→`aplicar`) y promociona un falso ambiguo cuando la página es editable, la instrucción es concreta y el motivo no contiene pregunta/dato faltante; las preguntas reales siguen ambiguas. Frontend: cada ambiguo ejecutable muestra "Aplicar con esta información"; al confirmarlo pasa a verde y habilita el botón de lote; respuestas afirmativas breves (`sí`, `vale`, `sí procede`, etc.) confirman el último plan en vez de replantear esa frase aislada. TDD backend `site_assistant_section_planning` 7/7 PASS; envelope 9/9 y canvas 49/49; `node --check` verde. Verificado visualmente en el panel local con navegador: ambigüedad real mostró pregunta + botón, clic pasó la tarjeta a "Se aplicará" y habilitó "Aplicar 1 cambio"; no se ejecutó ni guardó el cambio de prueba. Pendiente despliegue y QA manual en producción.

**Fix ampliaciones cuando las subpáginas no existen (2026-07-17, Executor):** el plan de producción descartaba Primaria y las ocho especialidades completas porque el documento pedía fichas/páginas nuevas. Se ajusta `PLAN_SITE_CHANGES` para ofrecer la mejor alternativa viable: consolidar ese contenido en la página relacionada existente mediante secciones, tarjetas o acordeones con anclas y CTAs; solo URLs independientes y menú/jerarquía global quedan `no_viable`. También se prohíbe inventar IDs de páginas inexistentes. TDD `site_assistant_section_planning` 10/10 PASS; lint/diff-check verdes. Planificación IA real (sin aplicar) con cambios Oposiciones 2–4: 2 items `aplicar` sobre Servicios (`services`: Primaria/Secundaria, modalidades, ocho especialidades, 50€/170€/duración; `presentation`: fichas homogéneas con profesorado, preparación, metodología y CTA) + 1 `no_viable` limitado a subpáginas/menú; coste estimado $0.002755. Pendiente despliegue y QA manual con plan nuevo en producción.

**Fix crear página sin captura usando página base (2026-07-17, Executor):** el botón "Generar página" permanecía disabled porque `updateSubmit()` exigía `files.length > 0`; por eso el clic no producía evento ni error de consola. Además, el backend rechazaba siempre `images=[]`, ignorando que `seed_page_id=Inicio` aportaba una base válida. Corregidas ambas capas: fuente visual válida = captura O página canvas base del mismo sitio; si faltan ambas se muestra error explícito. La UI reacciona al cambio del selector, explica que la captura es opcional con página base y actualiza el progreso. Nuevo test `tests/page_reference_seed.php` 7/7 PASS (seed sin imagen, imagen sin seed, ambas ausentes, contrato JS/copy); `node --check`, lint, canvas 49/49 y diff-check verdes. Verificado visualmente en `/admin/pages/studio`: modo Referencia, cero archivos, Inicio seleccionado, título+objetivo rellenos → botón habilitado. No se pulsó la generación final para evitar coste y crear una página QA. Sin migración. Pendiente despliegue y prueba manual real en producción.

**Referencia de secciones entre páginas en Studio (2026-07-17, Executor):** el chat Canvas ya puede resolver instrucciones del tipo «haz esta sección como `metodología` de la página Inicio». Nuevo `CanvasCrossPageReference`: busca únicamente páginas canvas del mismo sitio, excluye la página actual, identifica la página y la sección por su ID técnico o por su encabezado visible (aunque sea `sec-2`) y entrega a la IA el HTML de esa sección y el CSS de la página origen como contexto de solo lectura. Por defecto conserva el contenido de la sección actual y copia únicamente estructura/estilo; los textos de origen solo se permiten ante una petición explícita («copia también sus textos/contenido»). Se añadió una pista de uso bajo el chat. TDD `tests/canvas_cross_page_reference.php` 9/9 PASS; `canvas_runtime.php` 49/49, envelope 9/9, planner 10/10, lint y diff-check verdes. Sin migración. Pendiente QA manual del usuario antes de considerar cerrado este hito.

## Executor's Feedback or Assistance Requests (referencias entre páginas)

- Hito listo para prueba manual: abrir el Studio de una página, seleccionar una sección y pedir «Haz esta sección como la sección X de la página Y». Confirmar que cambia la página actual, mantiene sus textos y deja intacta la página Y. Después probar «... y copia también sus textos» para validar el modo explícito de copia de contenido.

## Lessons (FEAT-5)

- `Core\Response::json/html/redirect` son `never` (hacen `exit`): un `finally` tras un catch que responda JSON NO se ejecuta. Limpiar recursos (tmp files) ANTES de llamar a Response::*.
- `finfo` clasifica contenido texto-ish como `text/plain` aunque el archivo se llame `foto.png` → validar también la extensión del nombre original (rechazar extensiones fuera de la whitelist aunque el mime cuele). Ojo: `DocumentController::detectType` tiene la misma laxitud en /admin/documents.
- Si una clase CSS fija `display:flex`, pisa el atributo HTML `hidden`; añadir `.clase[hidden]{display:none}`.
- En zsh, `status` es una variable especial de solo lectura; no usarla como variable temporal en scripts de verificación. Usar un nombre específico como `task_http_code`.
- El provider HTTP tenía timeout de 60s y las ediciones canvas largas (sobre todo PÁGINA COMPLETA) lo superaban → "Error de red ... timed out" (visto en dev y reproducido por el usuario en prod). **Arreglado (2026-07-17)**: los providers ya leían `options['timeout']` — se añadió por acción en Actions.php (EDIT_CANVAS_PAGE y COMPOSE_CANVAS_PAGE 180s, EDIT_CANVAS_SECTION 120s, PLAN_SITE_CHANGES 90s), reintento único automático en SiteAssistantJobs::applyWithRetry para fallos transitorios (status 0/429/5xx, sleep 2s), y set_time_limit ampliado (step 420s, canvas chat 240s). Verificado: rediseño full-page de Servicios vía job → done. OJO PROD: si el hosting usa PHP-FPM con `request_terminate_timeout` bajo, ese límite mata el request aunque set_time_limit sea mayor.

---

# [I18N-BASE · 27/07/2026] Idioma del sitio de verdad (pasos 1 y 2)

## Background and Motivation (I18N)

Al preparar una instalación nueva de producción surgió la pregunta de si se puede hacer una web en francés. Auditoría: `sites.language` existía y `fr` ya estaba en la lista, el renderizador público emitía `<html lang>` correcto y las páginas legales sí seguían el idioma — pero **todo el pipeline de generación IA pasaba `'language' => 'es'` literal**, y el microcopy del frontend (botón Enviar, columnas del footer, banner de cookies) estaba fijo en castellano. Resultado: web en francés con contenido en español.

## Key Challenges and Analysis (I18N)

1. **Pasar el idioma NO basta.** Verificado con IA real: con `sites.language = fr` y `Idioma: français` en el user_template, Gemini seguía escribiendo en castellano, porque la memoria del sitio, la instrucción del cliente y el HTML actual están en castellano y el modelo los imita. La orden de idioma tiene que ir en el **system prompt** y como regla dura.
2. **Cambiar el idioma no puede ser destructivo.** Traducir automáticamente lo ya escrito sería silencioso e irreversible. Se separa: lo que se resuelve en cada render (microcopy) cambia solo; lo escrito (contenido, títulos, menú personalizado) NO se toca y se avisa por escrito en Ajustes.
3. **El texto del usuario no se pisa nunca.** Los textos del banner de cookies son editables. `Microcopy::resolve()` respeta lo escrito salvo que sea vacío o exactamente el default castellano histórico (que nadie eligió: se guardó solo).
4. **La jerarquía de páginas era 100% castellana.** `resolveParentIdForPage` buscaba `inicio`/`servicios`/`blog` por slug y título; en un sitio en otro idioma no encontraba nada y todas las páginas colgaban de la raíz.
5. **El menú no se configura: se deriva.** El menú automático sale de los títulos de las páginas publicadas, así que basta con que los títulos nazcan en el idioma correcto (paso 1 + plan de reserva del onboarding traducido).

## High-level Task Breakdown (I18N)

- [x] **Paso 1 — Idioma real en la generación IA.**
- [x] **Paso 2 — Microcopy del frontend por idioma + menú.**
- [ ] **Paso 3 — Multi-idioma real** (idioma por página, rutas `/fr/`, `hreflang`, selector). Pendiente de planificar; es un proyecto en sí (routing + SEO + Studio).

## Project Status Board (I18N)

- [x] `LanguageService`: catálogo único de idiomas, `codeFor()` cacheado, `promptLabel()` (endónimo), `forget()`.
- [x] Sustituidos los 7 `'language' => 'es'` del pipeline (Onboarding ×4, CanvasChatService ×2, CustomBlockGenerator; CanvasGenerator por defecto).
- [x] `Actions::languageRule()` en el system prompt de las 5 acciones que escriben texto visible.
- [x] Ajustes: catálogo compartido, `LanguageService::forget()` al guardar y aviso explícito de qué cambia y qué no.
- [x] `Microcopy`: 33 claves × 7 idiomas (footer, formularios, banner de cookies, títulos de página base).
- [x] Enchufado en SectionRenderer, FormController, BrandService (con `self::$lang`), CookieBanner (`render($manifest, $lang)`).
- [x] `resolveParentIdForPage` busca la home por `page_type` y por título/slug del idioma del sitio; castellano como último recurso.
- [x] Plan de reserva del onboarding con títulos localizados + `parent_title`.
- [x] Tests: `tests/site_language.php` (11) y `tests/site_microcopy.php` (11).
- [ ] Paso 3 (planner).
- [ ] Commerce, Booking y emails transaccionales: siguen 100% en castellano (fuera del alcance acordado).

## Current Status / Progress Tracking (I18N)

**Pasos 1 y 2 completados y verificados (27/07/2026).**

Verificación paso 1 con IA real: sitio 1 puesto en `fr`, `EDIT_CANVAS_SECTION` sobre un hero → antes del `languageRule()` devolvía castellano; después devuelve francés (`Préparez vos concours d'enseignement avec des experts`) manteniendo `<pp-reply>` en castellano (el panel es castellano). Prompts construidos vía `PromptBuilder::forAction` muestran `Idioma: français` en las tres acciones comprobadas.

Verificación paso 2 en navegador con el sitio en `fr`: `<html lang="fr">`, columnas del footer `Explorer` / `Mentions légales`, aria-labels `Navigation principale` / `Liens légaux`, enlace `Paramètres des cookies`, banner completo en francés (título, descripción, `Tout accepter`, `Refuser les facultatifs`, `Personnaliser`) y modal con categorías `Nécessaires (toujours actifs)` / `Mesure d'audience`. Botón de formulario `Envoyer` verificado renderizando una sección form real. Regresión con el sitio en `es`: HTML idéntico al previo (`Explora`, `Legal`, `Aceptar todas`, `lang="es"`).

Verificación del cambio desde Ajustes vía HTTP con sesión real: guardar `fr` devuelve 302, el select queda en `fr` y el aviso sale completo ("Idioma cambiado a Français: … El contenido ya escrito, los títulos de página y las etiquetas del menú NO se traducen solos"). Sitio restaurado a `es` y datos originales (`name`, `url`, `timezone`, `article_template`) devueltos a su valor previo.

Suite: 14 tests PASS (chrome_config, canvas_runtime, canvas_edit_envelope, site_assistant_section_planning, custom_block_renderer/sanitizer, form_templates, form_inline_insert, page_internal_pages, seo_services, legal_page_own_analytics, modules_registry, site_language, site_microcopy). Sin migración de BD.

## Executor's Feedback or Assistance Requests (I18N)

- **Fuera de alcance, confirmado por auditoría:** Commerce (`ShopController`: `Añadir al carrito`, `Finalizar compra`, `Carrito`…), Booking y **todos los emails transaccionales** (`CommerceMailer`, `BookingMailer`) siguen en castellano — unas 60 cadenas más. Son módulos opcionales y los emails los recibe el cliente final, así que si la web francesa lleva tienda o reservas hay que hacer otra tanda. El `Microcopy` ya está preparado para absorberlas.
- El panel de administración sigue siendo castellano-only (no hay capa i18n en `views/admin/`). No afecta a la web pública.

## Lessons (I18N)

- Instruir el idioma en el `user_template` NO funciona si la memoria del sitio y la instrucción están en otro idioma: el modelo imita el idioma dominante del contexto. La orden va en el **system prompt**, explícita y por encima del resto ("son fuente de HECHOS, no de idioma").
- Pasar el **endónimo** (`français`) ancla mejor que el código ISO (`fr`).
- Al verificar por HTTP contra el panel, un POST a `/admin/settings` sobrescribe TODOS los campos del formulario: capturar antes los valores originales (name, url, timezone, article_template) o se pierden. La tabla `settings` usa `setting_key`/`setting_value`, no `key`/`value`.
- La página pública se sirve desde `CacheService`: tras cambiar idioma o microcopy hay que `CacheService::flush($siteId)` (lo hace Ajustes) y recargar sin caché en el navegador, o se ve el HTML viejo.

---

# [I18N-FULL · 27/07/2026] Multi-idioma real (paso 3) — PLAN

## Background and Motivation (I18N-FULL)

Con [I18N-BASE] cerrado, una web PromptPress puede ser **de un idioma cualquiera**: la IA genera en ese idioma y el microcopy del frontend base lo sigue. Lo que NO existe es que **una misma web tenga varios idiomas**: hoy un sitio = un idioma, sin idioma por página, sin rutas `/fr/`, sin `hreflang` y sin selector.

Además queda pendiente lo que [I18N-BASE] dejó fuera a propósito: Commerce, Booking y los emails transaccionales siguen 100% en castellano. **El usuario confirma que la web de producción inminente llevará tienda o reservas**, así que eso deja de ser deuda futura y pasa a ser bloqueante.

## Decisiones cerradas (usuario, 27/07/2026)

1. **URLs**: idioma principal SIN prefijo (`/`, `/contacto`), idiomas secundarios CON prefijo (`/fr/`, `/fr/contact`). Motivo: las webs ya publicadas no cambian ni una URL — cero redirecciones, cero riesgo SEO.
2. **Activación**: opt-in por sitio desde Ajustes. Un sitio monolingüe sigue viéndose y comportándose exactamente igual que hoy.
3. **Creación de traducciones**: las dos vías — job "traducir el sitio a X" + botón "traducir esta página". Todo aterriza en borrador.
4. **Módulos**: la web de prod lleva tienda o reservas → Commerce/Booking y emails entran en la PRIMERA fase, antes de abrir al público.

## Key Challenges and Analysis (I18N-FULL)

**1. El enrutado casi no hay que tocarlo.** `app/routes.php:340` ya expone `$router->get('/{slug:path}')` y `pages.slug` es `VARCHAR(500)` con `UNIQUE (site_id, slug)`, admitiendo barras (ya se usan para `/servicios/diseno-web`). Es decir: **el prefijo de idioma puede vivir dentro del propio slug** (`fr/contact`). No hace falta una capa de routing nueva. La única excepción real es la **home de cada idioma** (`/fr` y `/fr/`), que hoy no tiene forma de resolverse porque `/` está cableado a `PageController::home`.

**2. El blog no es un caso aparte.** No existe tabla `posts`: las entradas son `pages` con `page_type='article'` + `post_meta`. Un solo modelo que versionar por idioma, no dos.

**3. El idioma pasa de ser del SITIO a ser de la PÁGINA.** Hoy `LanguageService::codeFor($siteId)` decide todo. En multi-idioma quien manda es la página que se está sirviendo. Todo lo que hoy resuelve por sitio (microcopy, `<html lang>`, menú, legales del footer) tiene que pasar a resolver por idioma de render. `sites.language` sobrevive como **idioma principal** y como valor por defecto.

**4. El menú y los legales se derivan, y hay que filtrarlos.** `BrandService::navPages()` y la consulta de legales del footer traen TODAS las páginas publicadas del sitio. Sin filtro por idioma, una web bilingüe mostraría el menú mezclado. Es un cambio pequeño pero está en varios sitios.

**5. Los emails transaccionales tienen un idioma propio que NO es el del sitio.** El idioma correcto de un "Pedido recibido" es el del cliente que compró — es decir, el de la página desde la que compró. Si en la fase 0 lo resolvemos solo por `sites.language`, en cuanto exista multi-idioma habría que volver a migrar. **Conclusión: añadir `language` a `commerce_orders` y `booking_bookings` YA en la fase 1**, aunque hasta la fase 3 se rellene siempre con el idioma del sitio. Migrar una vez, no dos.

**6. El catálogo es el límite honesto de v1.** `commerce_products` y `booking_services` tienen UN solo juego de nombre/descripción/slug (`uq_cp_slug`). Traducir el catálogo es otro modelo de datos (campos por idioma o filas hermanas), con impacto en carrito, pedidos y Stripe. **v1 traduce el ESCAPARATE, no el CATÁLOGO**: la tienda francesa tendrá botones, formularios y emails en francés, pero los nombres de producto tal y como se escribieron. Hay que decirlo claro antes de venderlo.

**7. Las rutas de módulo están cableadas en castellano.** `/tienda`, `/tienda/carrito`, `/tienda/checkout` (`app/Modules/Commerce/routes.php`). Una web francesa tendría contenido en francés bajo URLs en castellano. No rompe nada, pero es feo. Fuera de v1; se arregla haciendo configurable el segmento base.

**8. Coste de la traducción masiva.** Traducir un sitio de 8 páginas canvas son 8 llamadas IA largas. `SiteAssistantJobs` ya resuelve exactamente esto (un item por request, un fallo no detiene los demás, reintento en transitorios): el job de traducción debe montarse encima, no inventar una cola nueva.

**9. Riesgo de colisión de slugs.** Con el prefijo dentro del slug, una página del idioma principal llamada "fr" chocaría con el espacio de nombres del francés. Hace falta una guarda al crear/renombrar.

## High-level Task Breakdown (I18N-FULL)

### FASE 0 — Módulos y emails en el idioma del sitio (BLOQUEANTE para la web de prod)
No depende de nada del multi-idioma: sirve ya para una web monolingüe en francés.

- **T0.1** Ampliar `Microcopy` con el escaparate de Commerce: catálogo, ficha, carrito, checkout, gracias, errores de validación públicos (`ShopController`, `CartService`).
  *Criterio*: recorrer /tienda, /tienda/carrito y /tienda/checkout con el sitio en `fr` sin encontrar una sola cadena en castellano (grep sobre el HTML servido).
- **T0.2** Ídem para Booking público: formulario de reserva, disponibilidad, cancelación (`BookingApiController`, `BookingCancelController`, validaciones de `BookingService`).
  *Criterio*: mismo test sobre el flujo público de reservas.
- **T0.3** Emails transaccionales por idioma: `CommerceMailer` (recibido/pago/enviado/cancelado) y `BookingMailer` (confirmada/cancelada). Asunto y cuerpo desde `Microcopy`, con placeholders para importes, números de pedido y nombres.
  *Criterio*: disparar los 4 emails de Commerce y los 2 de Booking con el sitio en `fr` y verificar asunto y cuerpo en francés en `email_log`; con el sitio en `es`, texto byte-idéntico al actual (regresión).
- **T0.4** Decidir el juego de idiomas a rellenar: `es` completo + los que se usen de verdad (hoy `fr`). El resto cae a `es` sin romper (`Microcopy` ya lo hace).
  *Criterio*: `tests/site_microcopy.php` distingue claves "core" (obligatorias en los 7) de claves "módulo" (obligatorias solo en los idiomas declarados) y pasa.

### FASE 1 — Modelo de datos
- **T1.1** Migración: `pages.language` (CHAR(5), NOT NULL) + `pages.translation_group` (CHAR(36)) + índices `(site_id, language, status)` y `(translation_group)`. Backfill: toda página existente → idioma del sitio y grupo propio.
  *Criterio*: en una BD con datos, tras migrar, `COUNT(*)` por idioma = total de páginas y cada página tiene grupo no vacío; la web sigue sirviéndose igual.
- **T1.2** Migración: `language` en `commerce_orders` y `booking_bookings` (ver análisis #5), rellenado con el idioma del sitio.
  *Criterio*: pedidos y reservas antiguos quedan con idioma no nulo.
- **T1.3** Idiomas activos por sitio: `site_languages` (o JSON en `settings`) con lista + cuál es el principal. Opt-in desde Ajustes.
  *Criterio*: un sitio sin configurar se comporta exactamente como hoy; añadir `fr` no cambia ninguna URL existente.
- **T1.4** `LanguageService`: `activeFor($siteId)`, `primaryFor($siteId)`, `forPage($page)`, y prefijo de URL por idioma.
  *Criterio*: tests unitarios de las cuatro funciones, incluido el caso "idioma principal no lleva prefijo".

### FASE 2 — URLs y resolución
- **T2.1** Slug con prefijo automático al crear/traducir en idioma secundario; guarda anti-colisión (análisis #9).
  *Criterio*: crear una página `fr` produce slug `fr/...`; intentar crear una página del idioma principal con slug `fr` se rechaza con mensaje claro.
- **T2.2** Home por idioma: `/fr` y `/fr/` resuelven a la home francesa; `/` sigue sirviendo la principal.
  *Criterio*: las cuatro URLs (`/`, `/fr`, `/fr/`, `/fr/contact`) responden 200 con el idioma correcto en `<html lang>`.
- **T2.3** Caché: clave de home por idioma (hoy `CacheService::HOME_KEY` es única).
  *Criterio*: publicar un cambio en la home francesa no sirve la española cacheada, ni al revés.

### FASE 3 — Chrome, navegación y microcopy por página
- **T3.1** `navPages()`, legales del footer y listados de entradas filtran por el idioma de la página servida.
  *Criterio*: navegando en `/fr/` el menú solo trae páginas `fr`; en `/` solo las principales.
- **T3.2** Selector de idioma en el header (opcional, configurable en el editor de chrome): enlaza a la traducción equivalente vía `translation_group`; si no existe, a la home de ese idioma.
  *Criterio*: desde `/contacto` el selector lleva a `/fr/contact`; desde una página sin traducir, a `/fr/`.
- **T3.3** Microcopy pasa a resolverse por idioma de render, no por idioma de sitio (`Microcopy::site()` → variante por idioma explícito).
  *Criterio*: en `/fr/` el botón de formulario es `Envoyer` aunque el idioma principal del sitio sea `es`.

### FASE 4 — SEO
- **T4.1** `hreflang` recíprocos + `x-default` en el `<head>` de cada página traducida.
- **T4.2** Sitemap con `xhtml:link` alternates.
- **T4.3** Repaso de `SeoIndexingService::canonicalForPage` con prefijos.
  *Criterio (los tres)*: sitemap válido contra el XSD de sitemaps y hreflang recíprocos verificados a mano sobre un sitio de 2 idiomas y 3 páginas.

### FASE 5 — Panel y creación de traducciones
- **T5.1** Listado de páginas con idioma y traducciones agrupadas.
  *Criterio*: se ve de un vistazo qué páginas faltan por traducir.
- **T5.2** Acción IA `TRANSLATE_PAGE`: preserva estructura HTML/CSS, atributos `data-pp-*`, placeholders `{{form:REF}}` y reescribe enlaces internos al idioma destino. Reutiliza `Actions::languageRule()`.
  *Criterio*: traducir una página canvas real produce el MISMO número de secciones y de `data-pp-field`, con los enlaces internos apuntando a `/fr/...`.
- **T5.3** Botón "traducir esta página" → clona (secciones o canvas) y deja borrador.
- **T5.4** Job "traducir el sitio a X" montado sobre `SiteAssistantJobs`.
  *Criterio*: traducir un sitio de 3 páginas deja 3 borradores `fr` correctamente agrupados, y un fallo en una no impide las otras.

### FASE 6 — Generación en sitios multi-idioma
- **T6.1** Crear página nueva en un sitio multi-idioma pregunta el idioma; onboarding y asistente central respetan el idioma de la página.
  *Criterio*: generar una página desde el Studio en un sitio bilingüe produce contenido en el idioma elegido y slug con el prefijo correcto.

## FUERA de alcance v1 (explícito)

- **Catálogo multi-idioma** (nombres/descripciones de productos y servicios de reserva). Ver análisis #6. La tienda francesa tendrá chrome y emails en francés y catálogo en el idioma en que se escribió.
- **Rutas de módulo traducidas** (`/tienda` → `/boutique`). Ver análisis #7.
- **Panel de administración multi-idioma.** Sigue en castellano.
- **Detección automática de idioma del visitante** (Accept-Language / geo). Decisión consciente: es fuente clásica de problemas de SEO y de experiencias raras. El visitante elige.

## Project Status Board (I18N-FULL)

- [ ] T0.1 Escaparate Commerce por idioma
- [ ] T0.2 Booking público por idioma
- [ ] T0.3 Emails transaccionales por idioma
- [ ] T0.4 Política de idiomas obligatorios en el diccionario
- [ ] T1.1 · T1.2 · T1.3 · T1.4 Modelo de datos
- [ ] T2.1 · T2.2 · T2.3 URLs y resolución
- [ ] T3.1 · T3.2 · T3.3 Chrome y microcopy por página
- [ ] T4.1 · T4.2 · T4.3 SEO
- [ ] T5.1 · T5.2 · T5.3 · T5.4 Panel y traducción
- [ ] T6.1 Generación multi-idioma

## Executor's Feedback or Assistance Requests (I18N-FULL)

- Pendiente de confirmar por el usuario antes de ejecutar: si la web de prod es **monolingüe en francés con tienda**, basta la FASE 0 para lanzar y el resto puede ir después sin prisa. Las fases 1-6 solo hacen falta cuando una misma web deba tener dos idiomas a la vez.

### Actualización [I18N-FULL] · 27/07/2026 (posterior a las decisiones iniciales)

Dos hechos nuevos del usuario. **Sustituyen** a lo que dicen los puntos indicados más arriba:

5. **La web de producción será MULTILINGÜE**, no monolingüe en francés. Queda resuelta la pregunta abierta de "Executor's Feedback (I18N-FULL)": **no basta con la FASE 0**; hacen falta las fases 1-6. La fase 0 sigue siendo lo primero (es prerrequisito e independiente), pero ya no es el final del camino.

6. **Catálogo: modelo preparado ahora, traducción después.** Sustituye al primer punto de "FUERA de alcance v1". El diseño de datos de la fase 1 debe contemplar traducciones de `commerce_products` y `booking_services` desde el principio; lo que se pospone es la UI y la traducción IA del catálogo, no el modelo. Mismo criterio que `language` en `commerce_orders` (análisis #5): **migrar una vez**.

- **T1.5 (NUEVA)** Modelo de catálogo preparado para traducciones: filas hermanas o campos por idioma en `commerce_products` y `booking_services`, ligadas por grupo de traducción, más el repaso de `uq_cp_slug` (hoy único por `(site_id, slug)`, que impide dos variantes idiomáticas del mismo producto).
  *Criterio*: la migración admite dos variantes idiomáticas del mismo producto sin romper el `UNIQUE`, y con una sola variante el carrito, el checkout, los pedidos y Stripe se comportan exactamente igual que hoy (regresión con `tests/commerce_checkout.php` y `commerce_stripe.php`).
  *No incluye*: editor en el panel ni traducción IA del catálogo — eso es fase posterior, ya sin migración.

Board: añadir `[ ] T1.5 Modelo de catálogo preparado para traducciones` al bloque de la FASE 1.

## Current Status / Progress Tracking (I18N-FULL)

**T0.1 completada y verificada (27/07/2026).** Escaparate de Commerce en el idioma del sitio.

Alcance real: 53 claves `shop.*` nuevas en `Microcopy`, en 6 idiomas (`MODULE_LANGUAGES` = es, en, ca, gl, fr, pt). Se enchufan en `ShopController` (catálogo, ficha, carrito, checkout, gracias, errores de validación y desglose de totales), `CartService` (avisos de stock y badge del carrito) y —hallazgo durante la verificación en navegador, no estaba en el inventario inicial— `ManualPayment` y `StripeCheckout`: las etiquetas de método de pago y las instrucciones de pago manual son texto público que sale en el checkout, en la página de gracias Y en el email.

Dos decisiones de diseño:
- `Microcopy::t()` acepta ahora interpolación (`{n}`, `{product}`, `{rate}`, `{email}`, `{number}`). Los valores NO se escapan en el diccionario: escapar es responsabilidad del punto de render, que ya llama a `e()`. Escapar en ambos sitios produce «&amp;quot;» en nombres de producto con comillas o `&`. Los `{token}` que se queden sin valor se eliminan: un hueco es feo, un `{product}` crudo parece una web rota.
- **`eu` queda fuera de `MODULE_LANGUAGES` a propósito.** En un checkout el texto tiene efectos contractuales y no puedo dar por revisado el euskera; prefiero castellano correcto. La maquinaria está: añadirlo es rellenar la columna. Las claves del NÚCLEO (footer, formularios, cookies) sí siguen completas en los 7.

**Verificación E2E real en navegador**, sitio 1 puesto en `fr`, recorriendo el flujo completo con sesión y CSRF reales:
- Catálogo: `Boutique`, badge `Panier`.
- Ficha: `Ajouter au panier`, `Quantité`, `TVA (21 %) incluse`.
- Carrito: `Votre panier`, cabeceras `Produit / Prix / Quantité / Total`, `Retirer`, `Mettre à jour le panier`, `Finaliser la commande`, totales `Sous-total` / `TVA incluse`.
- Checkout: `Vos coordonnées`, `Nom et prénom`, `Notes de commande`, `Paiement`, `Passer la commande`, `Votre commande`; método de pago `Virement bancaire ou paiement convenu`.
- Errores de validación con datos inválidos: `Le nom est obligatoire.`, `Nous avons besoin d'une adresse e-mail valide pour la commande.`, `Choisissez un moyen de paiement.`
- Pedido real completado (PC-2026-0004): `Merci pour votre commande !`, `Nous avons envoyé un récapitulatif à jean@example.test.`, instrucciones de pago manual en francés (`en attente de paiement`, `Indiquez le numéro de commande en référence`), `Retour à la boutique`.
- **Regresión**: sitio devuelto a `es` → catálogo y página de gracias vuelven a texto castellano idéntico al previo.
- **Limpieza**: pedido de prueba y sus líneas borrados; stock del producto intacto (23, no se decrementa hasta el pago); idioma del sitio restaurado a `es`.

Tests: `tests/commerce_microcopy.php` NUEVO (10/10) — cobertura del diccionario en los idiomas declarados, fallback del idioma no declarado, interpolación, no-escapado, ausencia de castellano fijo en el render, métodos de pago localizados y whitelist de claves con marcado inline. Regresión completa en verde: commerce_products, commerce_checkout, commerce_c7, commerce_orders_admin, commerce_stripe, site_microcopy, site_language, chrome_config, modules_registry. Sin migración.

**T0.4 resuelta de paso**: la política de idiomas por grupo de claves (núcleo vs módulo) está implementada en `Microcopy::MODULE_LANGUAGES` + `isModuleKey()` + `missing()`, que es lo que la tarea pedía. No hizo falta tarea aparte.

## Lessons (I18N-FULL)

- El inventario por grep NO basta para el texto público de un módulo: las etiquetas de método de pago (`ManualPayment::label`) y las instrucciones de pago vivían en clases de `Payments/`, fuera de los ficheros "obvios", y solo aparecieron al recorrer el checkout de verdad en el navegador. Antes de dar por cerrada una tarea de traducción hay que **recorrer el flujo**, no solo leer el controlador.
- En `ShopController` la variable `$t` ya era el array de totales: meter un traductor llamado `$t` colisiona en silencio. El traductor se llama `$tr`.
- Un pedido de prueba en el checkout NO decrementa stock (eso ocurre al pagar), pero sí crea filas en `commerce_orders` y `commerce_order_items`: hay que borrarlas al terminar la verificación.

**T0.2 completada y verificada (27/07/2026).** Flujo público de reservas en el idioma del sitio.

Alcance: 30 claves `booking.*` en los 6 idiomas de `MODULE_LANGUAGES`, repartidas en tres superficies distintas —y cada una con su forma de recibir el idioma:

1. **API pública** (`BookingApiController`, `BookingService`): mensajes de validación (`fields`), confirmación de reserva (`confirmed` / `pending`) y respuesta del honeypot. Resueltos con `Microcopy::site($siteId, …)`.
2. **Páginas de cancelación** (`BookingCancelController`): las abre el cliente desde el email. Traducidas las tres pantallas (confirmar, ya cancelada, cancelada) y el `<html lang>`, que estaba fijo en `es`.
3. **Widget** (`public/js/pp-booking-widget.js`): es un JS **estático** que también se embebe en webs externas, así que no puede leer `sites.language`. Decisión: **idioma y textos viajan desde la API** en `GET /api/booking/v1/services` (`lang` + `texts`), y el JS solo conserva fallback castellano para lo que pinta ANTES de esa respuesta (el "Cargando…" inicial y el error de conexión). Una sola fuente de verdad, la de PHP; nada de duplicar el diccionario en JS.

De paso, los días de la semana dejan de estar cableados (`['dom','lun','mar','mié',…]`) y salen de `toLocaleDateString(lang, {weekday:'short'})`.

**Bug encontrado al probar la API de verdad (no lo pillaron los tests unitarios):** `widgetTexts()` usaba `Microcopy::t()`, que limpia los `{token}` sin valor — así que «{n} créneaux» se servía al navegador como «créneaux» y «Réserver à {time}» como «Réserver à». Corregido con `Microcopy::template()`, que devuelve la plantilla CRUDA para clientes que interpolan ellos mismos. Test de regresión añadido (`widget_texts_keep_their_placeholders`).

**Verificación E2E real:**
- API en `fr`: `lang: fr` + paquete `texts` completo con placeholders intactos.
- Widget embebido en una página de prueba: días `mer. 29/7` / `lun. 3/8`, `4 créneaux`, placeholders `Votre nom *` / `Votre e-mail *` / `Téléphone (facultatif)`, botón `Réserver à 10:00`, pie `Heure locale : Europe/Madrid`.
- Validación contra la API con datos inválidos: los tres mensajes en francés.
- Reserva real creada (#77): `Réservation reçue, en attente de confirmation…`.
- Página de cancelación desde el enlace del email: `Annuler la réservation`, `Voulez-vous vraiment annuler cette réservation ?`, botón `Oui, annuler la réservation`; ejecutada la cancelación → `Réservation annulée` + `Votre réservation a été annulée. Merci de nous avoir prévenus.`; `<html lang="fr">`.
- **Regresión** con el sitio en `es`: página de cancelación y API vuelven a castellano idéntico (`<html lang="es">`, `Tu nombre *`).
- **Limpieza**: reserva de prueba borrada, `public/__widget-test.html` (página temporal de embebido) eliminada, idioma restaurado a `es`.

Tests: `tests/booking_microcopy.php` NUEVO (9/9). Regresión en verde: booking_api, booking_availability, booking_services, booking_emails, commerce_*, site_*, chrome_config, modules_registry, canvas_runtime. `node --check` del widget OK. Sin migración.

**Pendiente conocido, decidido a propósito:** los `detail` de error de la API (`'from/to deben ser fechas Y-m-d…'`, `'rango máximo N días'`) siguen en castellano. Son contrato de API para integradores, no texto de cliente final: el widget nunca los muestra (pinta su propio mensaje). Si algún día hay documentación pública de la API, se traducen ahí.

### Lessons (T0.2)

- `Microcopy::t()` y `Microcopy::template()` NO son intercambiables. `t()` es el texto final para renderizar (limpia placeholders sin valor); `template()` es la plantilla cruda para un cliente que interpola por su cuenta (el widget JS). Confundirlas es un bug silencioso: el texto sale bien formado pero sin el dato.
- Cuando un asset es estático y embebible fuera del sitio, el idioma NO puede deducirse en el cliente: se sirve desde la API junto con los datos. El fallback en el JS solo cubre el hueco anterior a la primera respuesta.

**T0.3 completada y verificada (27/07/2026).** Emails transaccionales en el idioma del cliente. **Con esto la FASE 0 queda cerrada.**

Alcance: 28 claves `mail.*` en los 6 idiomas de `MODULE_LANGUAGES`, más un servicio nuevo `App\Services\DateFormat`.

**Tres decisiones de diseño, las tres deliberadas:**

1. **Los avisos al ADMINISTRADOR se quedan en castellano.** Los recibe el dueño del sitio, que gestiona un panel en castellano; traducirlos no ayuda a nadie y duplica la superficie de error en textos que nadie revisa. Mismo criterio que el `<pp-reply>` del Studio. Está en el test (`admin_notices_stay_in_spanish`) para que se lea como decisión y no como olvido. Detalle pulido tras ver los correos reales: el aviso al admin llevaba la fecha en francés dentro de un texto castellano — ahora usa `$whenAdmin` en castellano.

2. **`DateFormat` nuevo, y el castellano no cambia ni un carácter.** `BookingMailer::humanWhen()` formateaba con `IntlDateFormatter('es_ES')` y patrón `EEEE d 'de' MMMM 'de' y, HH:mm`: el `'de'` va incrustado en el patrón, así que un email en francés habría salido «mercredi 29 de juillet de 2026». `DateFormat` usa patrón propio donde lo tengo claro (es, fr, pt, en) y **el formato largo de ICU donde no** — catalán y gallego apostrofan ante vocal («29 d'abril»), y un patrón escrito a mano con `'de'` fijo estaría mal la mitad de los meses. El patrón castellano se conserva EXACTO: test de regresión byte a byte.

3. **Resolución de idioma preparada para la fase 1.** `CommerceMailer::language()` y `BookingMailer::language()` leen `$order['language']` / `$booking['language']` si existe y caen al idioma del sitio si no. Cuando T1.2 añada esas columnas, los emails pasan solos a usar el idioma con el que el cliente compró, sin volver a tocar los mailers.

**Verificación E2E con emails REALES.** Levanté un SMTP de captura local (script en scratchpad) y configuré el sitio contra él, disparando los 6 emails de cliente + los 2 avisos de admin en francés y luego en castellano. 18 mensajes capturados y revisados uno a uno:
- Cliente en `fr`: `Commande reçue`, `Paiement reçu`, `Commande expédiée`, `Commande annulée`, `Nous avons bien reçu votre réservation`, `Réservation confirmée/annulée`. Cuerpos completos correctos (`Bonjour Marie Durand,` · `Total : 20,00 € (dont 3,50 € de TVA)` · `Date et heure: mercredi 29 juillet 2026, 10:00`).
- **El adjunto `.ics` decodificado**: `DESCRIPTION:Réservation au nom de Marie Durand` — el evento que acaba en el calendario del cliente también va en su idioma.
- Admin en ambas tandas: `Nuevo pedido …`, `Nueva reserva: … — miércoles 29 de julio de 2026, 10:00` (castellano íntegro).
- **Regresión en `es`**: cuerpos idénticos a los originales, incluida la fecha larga.
- Limpieza verificada: 0 pedidos QA, 0 reservas de prueba, ajustes de correo restaurados a "ninguno" (como estaban), idioma `es`, SMTP de captura apagado.

Tests: `tests/mail_microcopy.php` NUEVO (9/9). Regresión en verde (15 suites: booking_*, commerce_*, site_*, chrome_config, modules_registry). Sin migración.

**Recomendación antes de vender esto en otros idiomas:** el francés lo doy por bueno; **catalán y gallego merecen una lectura de nativo** antes de usarlos comercialmente, sobre todo en los emails, que tienen valor contractual. El euskera sigue fuera de `MODULE_LANGUAGES` a propósito (cae a castellano). Añadir cualquiera de ellos es rellenar columnas: la maquinaria no cambia.

### Lessons (T0.3)

- Los patrones de fecha ICU escritos a mano NO son traducibles literalmente: el `'de'` castellano/portugués no vale en francés, y en catalán/gallego la contracción depende de la inicial del mes (`d'abril` vs `de maig`). Ante la duda, dejar que ICU ponga el formato largo del locale.
- Un email tiene DOS destinatarios con idiomas distintos (cliente y administrador) construidos en la misma función. Al traducir hay que separar las variables desde el principio, o se cuelan mezclas como un texto castellano con la fecha en francés.
- Para verificar emails de verdad no hace falta un servicio externo: un SMTP de captura de 40 líneas en Python permite leer los bytes exactos que recibiría el cliente, adjuntos `.ics` incluidos.

## Current Status / Progress Tracking (I18N-FULL · FASE 1)

**FASE 1 completada y verificada (27/07/2026).** Modelo de datos del multi-idioma. T1.1 a T1.5.

**Una sola migración**: `database/migrations/2026_07_27_multilanguage_model.sql`. NO se replica en `install/schema.sql` porque el instalador ya ejecuta el Migrator canónico sobre `database/migrations/` (verificado en `install/migrate.php`): la divergencia histórica está resuelta y duplicar aquí sería volver a abrirla.

- **T1.1** `pages.language` (VARCHAR 5) + `pages.translation_group` (CHAR 36) + índices `(site_id, language, status)` y `(translation_group)`. Backfill con el idioma REAL de cada sitio (JOIN con `sites`), no un `'es'` a lo bruto, y `UUID()` por fila para que cada página arranque en su propio grupo. Verificado sobre la BD: 31 páginas, 31 grupos distintos, 0 huérfanas.
- **T1.2** `language` en `commerce_orders` y `booking_bookings`, con backfill. Y lo que hacía falta para que sirva: `OrderStore::createFromCart()` y `BookingService::create()` lo **escriben** al crear. Verificado con una reserva real por API con el sitio en `fr` → fila guardada con `language=fr`. A partir de ahora los emails de ese cliente irán en francés aunque el sitio cambie de idioma después (los mailers ya leían la columna desde T0.3).
- **T1.3** Tabla `site_languages` (site_id, code, is_primary, sort_order) con FK cascade y UNIQUE (site_id, code). Backfill: cada sitio existente estrena su idioma actual como principal. **El instalador también la rellena** al crear el sitio — una instalación nueva crea el sitio DESPUÉS de migrar, así que la migración no la cubre.
- **T1.4** `LanguageService`: `activeFor()`, `primaryFor()`, `isMultilingual()`, `forPage()`, `prefixFor()`, `enable()`, `disable()`, con caché por request. `prefixFor()` implementa la decisión de URLs: **cadena vacía para el principal**, código para los demás.
- **T1.5** `language` + `translation_group` en `commerce_products` y `booking_services`, y el UNIQUE `uq_cp_slug` pasa de `(site_id, slug)` a `(site_id, language, slug)`. Verificado con inserciones reales: dos variantes idiomáticas del mismo slug conviven, y el slug duplicado en el MISMO idioma se sigue rechazando. Solo el modelo: editor y traducción IA del catálogo son fase posterior.

**UI de Ajustes (opt-in).** Nueva tarjeta "Idiomas adicionales" con lista de activos, el principal marcado como «sin prefijo», alta por selector y baja por idioma. Dos guardas, ambas probadas por HTTP con sesión real:
- desactivar el idioma **principal** → rechazado ("dejaría el sitio sin home");
- desactivar un idioma **con páginas** → rechazado, diciendo cuántas. Mensaje explícito: «desactivar un idioma nunca borra contenido».
El copy de la tarjeta explica que el principal mantiene sus URLs y que los adicionales viven bajo prefijo, que es lo que evita romper webs publicadas.

Tests: `tests/site_languages_model.php` NUEVO (21/21), contra la BD real y sin dejar basura. Regresión en verde (18 suites). Estado final verificado: `site_languages` con solo `es` principal, 31 páginas, 0 páginas `fr`, idioma del sitio `es`, `public/` sin ficheros temporales.

### Lessons (Fase 1)

- El instalador crea el sitio DESPUÉS de correr las migraciones. Cualquier tabla que necesite una fila por sitio no puede rellenarse solo desde la migración: hay que sembrarla también en `install/steps/admin.php`.
- `UUID()` dentro de un `UPDATE` se evalúa **por fila**, que es justo lo que se quiere para dar un grupo propio a cada página. Con un valor fijo, todas las páginas quedarían hermanadas.
- Cambiar un UNIQUE existente (`uq_cp_slug`) es seguro si el nuevo es MÁS laxo: si `(site_id, slug)` era único, `(site_id, language, slug)` también lo es, así que el DROP+ADD no puede fallar por duplicados.
- Para revisar visualmente un bloque nuevo del panel sin meter credenciales en el navegador: obtener el HTML autenticado por curl, aislar el fragmento y servirlo con el CSS del panel y un `<base>`. El layout completo fuera de su contexto no cuadra, pero el fragmento sí.

## Current Status / Progress Tracking (I18N-FULL · FASE 2)

**FASE 2 completada y verificada (27/07/2026).** URLs y resolución por idioma. T2.1, T2.2, T2.3.

**Confirmado el análisis #1 del plan: no se ha tocado el enrutado.** El router ya normaliza la barra final (`/fr/` → `/fr`) y el catch-all `/{slug:path}` cubre todo. El prefijo de idioma vive DENTRO de `pages.slug`, que ya admitía barras.

- **T2.1 · Prefijo en el slug.** `LanguageService::applySlugPrefix()` — sin prefijo para el principal, `xx/...` para los secundarios. Es **idempotente y reescribe**: aplicarlo dos veces no da `fr/fr/contact`, y cambiar de idioma sustituye el prefijo en vez de acumularlo. `PageController::uniqueSlug()` acepta ahora `?string $lang` y lo aplica.
- **T2.1 · Guarda anti-colisión.** `slugCollidesWithLanguage()`: una página del idioma principal no puede ocupar `/fr/...` porque dejaría sin sitio (o sin home) al francés. Un idioma NO activo no reserva nada — `/pt/algo` es un slug legítimo mientras el portugués esté apagado. Enganchada en `PageController::validate()`, así que actúa también cuando el slug se escribe a mano. **Verificado por HTTP en el panel real**: crear una página castellana con slug `fr/robado` devuelve «`fr/` está reservado para las páginas en Français».
- **T2.2 · Home por idioma.** `PageController::homePageFor($siteId, $lang)` + `serveHome()`. `/` sirve la home del principal; `/fr` y `/fr/` la francesa, detectadas por `languageFromHomeSlug()`. Compatibilidad cuidada: en un sitio MONOLINGÜE se sigue aceptando una home sin idioma asignado (fila anterior a la migración); en uno multi-idioma NO, porque serviría la home equivocada.
- **T2.3 · Caché de home por idioma.** `CacheService::homeKey($siteId, $lang)`: el principal conserva la clave `__home` de siempre (los sitios monolingües no notan nada) y cada idioma adicional tiene la suya. `invalidatePage()` invalida la home del idioma de la página, no la del sitio. Verificado en disco: `__home.html` y `__home__fr.html` conviven.
- **Extra no planificado pero necesario**: `<html lang>` de las páginas públicas pasa a salir de la PÁGINA (`LanguageService::forPage()`), no del sitio. Servir `/fr/contact` con `lang="es"` era un fallo de SEO y accesibilidad, y arreglarlo aquí es parte de servir bien la página.

**Verificación E2E con una web bilingüe real** (home + página interior en francés, servidas por el servidor de desarrollo):

| URL | HTTP | `<html lang>` | contenido |
|---|---|---|---|
| `/` | 200 | es | home castellana |
| `/fr` | 200 | fr | Bienvenue chez nous |
| `/fr/` | 200 | fr | Bienvenue chez nous |
| `/fr/contact` | 200 | fr | Parlons de votre projet |

Caché cruzada comprobada: segunda petición idéntica a la primera (viene de caché) en ambos idiomas, y `/` y `/fr` distintas entre sí. Regresión monolingüe: `/`, `/inicio`, `/canvas-demo` siguen en 200 con `lang=es` y `/no-existe` sigue devolviendo 404.

Tests: `tests/site_language_urls.php` NUEVO (16/16). Regresión en verde (18 suites). Sin migración. Estado final: 31 páginas, `site_languages` solo con `es`, caché limpia.

### Lessons (Fase 2)

- El router ya hacía el trabajo: normaliza la barra final y tiene catch-all multi-segmento. Antes de añadir rutas para `/fr/`, mirar qué resuelve ya lo que hay — la fase entera se hizo sin tocar `routes.php`.
- `page_sections.status` es `enum('editable','locked','deleted')`, NO `published`/`draft` como `pages.status`. Insertar secciones a mano con el vocabulario equivocado falla con «Data truncated for column 'status'».
- Al añadir una dimensión (idioma) a una clave de caché, el valor por defecto debe conservar la clave ANTIGUA para el caso por defecto (`__home` para el idioma principal). Si todas las claves cambian, cada sitio ya publicado pierde su caché de golpe al desplegar.

## Current Status / Progress Tracking (I18N-FULL · FASE 3)

**FASE 3 completada y verificada (27/07/2026).** Chrome, navegación y microcopy por idioma de PÁGINA. T3.1, T3.2, T3.3.

El cambio conceptual de la fase: hasta aquí todo se resolvía por el idioma del SITIO. Ahora manda el idioma de la **página que se está sirviendo**, que en una web bilingüe no tiene por qué coincidir.

- **T3.1 · Filtrado por idioma.** `BrandService::navPages()` y la consulta de legales del footer filtran por `language`, y el menú excluye además la home del propio idioma (cuyo slug es el prefijo). `SectionRenderer::renderPostsListing()` lista solo las entradas del idioma que se está pintando.
- **T3.2 · Selector de idioma.** `BrandService::languageSwitcher()` — aparece **solo** si el sitio sirve más de un idioma; una web monolingüe no ve ni rastro. `languageSwitchTarget()` resuelve el destino por `translation_group`: si existe la traducción, enlaza a ella; si no, a la home de ese idioma (**nunca a un 404**). Los idiomas se nombran con su ENDÓNIMO («Français», no «Francés»): quien busca la versión francesa reconoce su idioma escrito como lo escribe él. Marca el actual con `aria-current` y cada enlace lleva `hreflang`. CSS en `DesignSystem` (el design system del front, no el del panel).
- **T3.3 · Microcopy por idioma de render.** `publicHeader()`/`publicFooter()` aceptan `?string $lang`, `SectionRenderer::setSiteContext()` también, y las llamadas internas pasan de `Microcopy::site($siteId, …)` a `Microcopy::t($key, self::$lang)`. `PageController::render()` los alimenta con `LanguageService::forPage()`.

**Verificación E2E en navegador con web bilingüe real:**
- `/qa3-servicios` (castellano): menú solo con páginas castellanas, footer `EXPLORA` / `LEGAL` / `Configurar cookies`, selector con **Español** subrayado como actual.
- `/fr/services`: menú solo con `Nos services`, footer `EXPLORER` / `MENTIONS LÉGALES` / `Paramètres des cookies`, banner de cookies en francés, selector con **Français** como actual.
- Selector comprobado en ambos sentidos: desde la página castellana enlaza a `/fr/services` y desde la francesa a `/qa3-servicios` — la traducción EQUIVALENTE, no la home.
- Regresión monolingüe: sin selector (0 ocurrencias), menú con sus 6 enlaces, `lang="es"`, footer `Explora`.

Tests: `tests/site_language_chrome.php` NUEVO (11/11), repetible (limpia restos de ejecuciones interrumpidas al arrancar). Regresión en verde (18 suites). Sin migración.

### Limitación conocida detectada al verificar (NO es un bug de esta fase)

En la página francesa, el **tagline del footer** sigue en castellano. Sale de `site_memory` (la descripción del negocio): es CONTENIDO escrito una vez por sitio, no microcopy, así que cae del lado de «lo escrito no se traduce solo» — coherente con lo decidido, pero visible en una web bilingüe.

Lo mismo aplicaría a: el nombre de marca del footer, las etiquetas de menú personalizadas y el `heading`/CTA de newsletter si el usuario los ha escrito. Todo eso vive en `ChromeService`/`site_memory` con UN valor por sitio.

**Dónde encaja arreglarlo**: no en esta fase. O bien un «chrome por idioma» (guardar esas cadenas por idioma, migración pequeña), o bien que la herramienta de traducción de la FASE 5 las incluya. Conviene decidirlo antes de cerrar la fase 5.

### Lessons (Fase 3)

- Al ordenar un test que crea contenido y desactiva idiomas: **borrar las páginas ANTES de desactivar el idioma**. La guarda de la fase 1 (no desactivar un idioma con páginas) hizo fallar dos asserts, y tenía razón — el fallo estaba en el test.
- El CSS del front vive en `DesignSystem.php`, no en `admin/assets/css/admin.css`. Añadir estilos del sitio público al CSS del panel no rompe nada pero no se aplica: el usuario ve el HTML sin estilar.
- `page_sections` de prueba: recordar el `enum('editable','locked','deleted')` de su columna `status` (ver lección de la fase 2).

## Current Status / Progress Tracking (I18N-FULL · FASE 4)

**FASE 4 completada y verificada (27/07/2026).** SEO multi-idioma. T4.1, T4.2, T4.3.

**Esta fase encontró el fallo más grave de todo el proyecto**, y estaba en código anterior al multi-idioma:

> `SeoIndexingService::canonicalForPage()` devolvía la raíz del sitio para CUALQUIER página con `page_type='home'`. Con una home francesa (`page_type='home'`, slug `fr`), su canonical apuntaba a `/` — es decir, le decía a Google **«soy un duplicado de la home castellana»**. Eso es la forma más rápida de que te desindexen la versión traducida. Corregido con `isPrimaryHome()`: solo la home del idioma principal vive en la raíz.

- **T4.1 · hreflang.** Nuevo `SeoHreflangService`: `alternatesFor()` (versiones publicadas del `translation_group`, excluyendo `noindex`), `renderTags()` para el `<head>` y `sitemapLinks()` para el sitemap. Reglas respetadas: **recíprocos** (todas las versiones se declaran entre sí, incluida a sí misma), URLs **absolutas**, `x-default` al idioma principal, y **nada si la página no tiene traducciones** — declararse sola es ruido.
- **T4.2 · Sitemap.** Namespace `xhtml` + `<xhtml:link rel="alternate">` por URL. De paso, dos correcciones necesarias:
  - `self::site()` no seleccionaba `language`, así que no podía distinguir la home principal de la secundaria (la francesa se listaba como `/`);
  - **deduplicación por `<loc>`** y resolución de "qué página se sirve REALMENTE en la raíz" vía `PageController::homePageFor()`. La BD de desarrollo tiene 22 páginas marcadas como `home`: antes el sitemap listaba `/` veintidós veces y esas páginas nunca aparecían con su URL real. Ahora la raíz sale una vez y cada página con su slug (26 URLs).
- **T4.3 · Canonical.** Ver arriba. La home principal sigue devolviendo `/`; las demás, su slug.

**Divergencia detectada y corregida de camino (bug de la fase 1):** cambiar el «Idioma principal» en Ajustes actualizaba `sites.language` pero NO `site_languages`, así que `primaryFor()` seguía devolviendo el idioma viejo y los prefijos de URL dejaban de cuadrar. Nuevo `LanguageService::setPrimary()`, llamado desde Ajustes, con una guarda: **en una web multi-idioma el cambio se rechaza**, porque reescribiría las URLs de todas las páginas (el idioma sin prefijo pasaría a tenerlo). Primero hay que desactivar los adicionales.

**Verificación E2E por HTTP:**
- `/qa5-contacto` y `/fr/qa5-contact` emiten los mismos tres `<link rel="alternate">` (es, fr, x-default) — recíprocos byte a byte.
- canonical: `/` → raíz; `/fr` → `/fr` (antes → raíz).
- sitemap: XML válido parseado con ElementTree, raíz una sola vez, `/fr` presente, alternates correctos por URL.
- Sitio monolingüe: **0** etiquetas hreflang. No se ensucia el `<head>` de quien no usa esto.

Tests: `tests/site_language_seo.php` NUEVO (14/14). Actualizada UNA aserción de `tests/seo_services.php` (`sitemap_has_urlset` fijaba la etiqueta `<urlset>` literal; ahora comprueba la intención, porque el namespace `xhtml` es un añadido deliberado). Ampliado `site_languages_model.php` con `setPrimary`. Regresión en verde (18 suites). Sin migración.

### Lessons (Fase 4)

- Un `canonical` mal calculado no "se ve mal": desindexa. Merece test propio y explícito por cada caso (home principal, home secundaria, página interior), no confiar en que "la URL sale bien en el navegador".
- Cuando una función decide por `page_type`, comprobar qué pasa si hay VARIAS filas con ese tipo. `page_type='home'` no está limitado a una fila por sitio y la BD de desarrollo tenía 22.
- Si dos fuentes guardan el mismo dato (`sites.language` y `site_languages.is_primary`), hay que sincronizarlas EN EL MISMO sitio donde se escribe, o divergen en silencio. Mejor aún: que solo una sea la verdad — aquí se mantienen ambas por compatibilidad, pero `setPrimary()` es el único camino de escritura.

---

# [I18N-FULL · FASE 5] Traducción de contenido — PLAN (27/07/2026)

## Decisiones cerradas (usuario)

1. **Motor híbrido por tipo de página.** Traducción LITERAL donde no cabe creatividad (legales, formularios, fichas con datos) y REESCRITURA nativa donde el texto vende (home, servicios, landings). Motivo: el riesgo de que la IA "mejore" un aviso legal es el que no queremos correr; el de que una home suene plana es el que menos compensa aceptar.
2. **Alcance: páginas + SEO + chrome.** El catálogo va aparte (ver 4).
3. **Chrome por idioma**: tagline, nombre de marca del footer, etiquetas de menú personalizadas y textos de newsletter pasan a guardarse por idioma. Son pocas cadenas y salen en TODAS las páginas.
4. **El catálogo se mueve a una FASE 7 propia.** Motivo verificado en código, no supuesto: `ProductStore::all()` y `findActiveBySlug()` no filtran por idioma, así que un producto con gemelo francés duplicaría las fichas en `/tienda` y la búsqueda por slug devolvería una variante arbitraria (el UNIQUE ya permite el mismo slug en dos idiomas). Además la tienda vive en `/tienda`, una sola URL sin prefijo. Traducir catálogo arrastra las rutas de módulo por idioma, que el plan tenía fuera de v1.
5. **Todo aterriza en BORRADOR.** Nunca autopublicar una traducción automática.
6. **Orden**: primero página a página, luego el lote.

## Key Challenges and Analysis (Fase 5)

**1. Dos formatos de página, dos tratamientos.** Canvas (HTML+CSS libre en `page_canvas`) y secciones (JSON tipado en `page_sections`). El canvas exige preservar estructura, clases, `data-pp-*`, `data-pp-behavior` y placeholders `{{form:REF}}`; las secciones exigen respetar el esquema de cada tipo. Son dos rutas de código distintas dentro de la misma acción.

**2. Enlaces internos.** Una página traducida que enlaza a `/contacto` manda al visitante francés al castellano. Los enlaces internos deben reescribirse al idioma destino usando `translation_group`, y si la página destino no está traducida, dejar el original (mejor un enlace que funciona en otro idioma que uno roto).

**3. Validación automática por tipo.** En modo LITERAL se puede exigir isomorfismo: mismo número de secciones y de `data-pp-field`. En modo REESCRITURA no —el copy cambia—, así que la validación es más laxa: estructura de secciones y presencia de placeholders. Esto refuerza la elección híbrida: donde más riesgo hay, más se puede validar.

**4. El grupo de traducción ya existe** (fase 1) y `BrandService::languageSwitchTarget()` ya lo usa. Traducir = crear la página hermana con el MISMO `translation_group`.

**5. El lote debe montarse sobre `SiteAssistantJobs`**, que ya va por pasos (un item por request), aguanta fallos sin detener el resto y reintenta transitorios. No inventar cola nueva.

**6. Coste.** Traducir una web de 8 páginas canvas son 8 llamadas largas. Conviene estimar y mostrar el coste antes de lanzar el lote, como ya hace el asistente.

## High-level Task Breakdown (Fase 5)

- **T5.1 · Chrome por idioma.** Migración pequeña: las cadenas de `ChromeService` con un valor por sitio pasan a tener uno por idioma (o tabla auxiliar), y `BrandService` las lee por `self::$lang`. Incluye el tagline de `site_memory`.
  *Criterio*: con dos idiomas activos, el footer francés muestra tagline y etiquetas en francés y el castellano sigue exactamente igual que hoy.
- **T5.2 · Acción IA `TRANSLATE_PAGE`.** Modo literal / reescritura según `page_type`, con `Actions::languageRule()` ya existente. Preserva estructura, `data-pp-*` y `{{form:REF}}`, y reescribe enlaces internos.
  *Criterio*: traducir una página canvas real produce el mismo número de secciones y de `data-pp-field`, con los enlaces internos apuntando al idioma destino.
- **T5.3 · Traducir una página (bajo demanda).** Botón en el panel: clona (canvas o secciones), traduce, guarda como BORRADOR con el mismo `translation_group` y el slug prefijado (`uniqueSlug(..., $lang)`, ya listo de la fase 2).
  *Criterio*: la traducción aparece en el listado, el selector de idioma la enlaza, y publicarla es un acto humano explícito.
- **T5.4 · Listado con idioma y traducciones.** Columna de idioma y qué falta por traducir, agrupado por `translation_group`.
  *Criterio*: de un vistazo se ve qué páginas no tienen versión en cada idioma activo.
- **T5.5 · Job "traducir el sitio a X"** sobre `SiteAssistantJobs`, con estimación de coste antes de lanzar.
  *Criterio*: traducir un sitio de 3 páginas deja 3 borradores correctamente agrupados; un fallo en una no impide las otras.
- **T5.6 · SEO de la traducción**: `meta_title` y `meta_description` se traducen con la página.
  *Criterio*: la página traducida no hereda el meta en el idioma original.

## Project Status Board (Fase 5)

- [ ] T5.1 Chrome por idioma
- [ ] T5.2 Acción IA TRANSLATE_PAGE (híbrida)
- [ ] T5.3 Traducir una página bajo demanda
- [ ] T5.4 Listado con estado de traducción
- [ ] T5.5 Job de traducción del sitio
- [ ] T5.6 SEO traducido

## FASE 7 (nueva, movida desde el alcance de la 5)

Catálogo multi-idioma: filtrado por idioma en `ProductStore` y en el escaparate, resolución de variante por ficha, prefijos de ruta para tienda y reservas (`/fr/boutique`), y repaso de carrito, pedidos y Stripe. Diseño propio antes de ejecutar.

## Current Status / Progress Tracking (I18N-FULL · FASE 5)

**T5.1 completada y verificada (27/07/2026).** Chrome por idioma. Cierra el hueco visible que dejó la fase 3.

**Sin migración.** La config de chrome ya es JSON en `settings`, así que la capa de idioma vive dentro: una clave `i18n` con forma `['fr' => ['header' => [...], 'footer' => [...]]]`. Resultó más barato de lo que estimé en el plan (allí dije «migración pequeña»; al final, ninguna).

**Decisión de diseño: la capa contiene SOLO texto.** El layout, los colores y los bordes siguen compartidos entre idiomas, a propósito: si se duplicaran, cambiar un color solo afectaría a un idioma y el sitio se desincronizaría visualmente. Hay test que lo fija (`layout_and_style_are_shared`).

Cubre: tagline, nombre de marca del footer, las cinco etiquetas de columna, textos de newsletter, copyright, etiqueta del CTA del header, menú del header y navegación del pie.

**Dos detalles que importan:**
- **El menú se sustituye ENTERO**, no etiqueta a etiqueta: en otro idioma apunta a otras páginas, así que traducir solo las etiquetas dejaría enlaces al idioma equivocado. Si un idioma no tiene menú configurado, cae a la navegación automática, que desde la fase 3 ya filtra por idioma — buen valor por defecto.
- **Un campo no traducido cae a la base**, no se queda vacío: vale más una etiqueta en el idioma original que un hueco en el footer.

Implementación: `ChromeService::localized($config, $lang)` resuelve, `sanitizeI18n()` sanea (solo idiomas admitidos, solo campos de texto, mismas reglas de longitud que la base) y `BrandService::publicHeader/publicFooter` la aplican al arrancar. De paso se extrajo `sanitizeMenu()` del cuerpo de `sanitize()` para que la capa reutilice exactamente las mismas reglas en vez de duplicarlas.

Tests: `tests/site_language_chrome_i18n.php` NUEVO (12/12). Regresión en verde (20 suites, incluido `chrome_config`). Estado final: capa `i18n` vacía en el sitio real (nadie la ha tocado), config intacta, 31 páginas, solo `es` activo.

### Lessons (T5.1)

- `ChromeService::sanitize()` descarta todo lo que no esté en su lista blanca. Cualquier clave nueva en la config hay que contemplarla ahí o se pierde silenciosamente **al guardar** — no al leer, que es lo que hace el fallo difícil de ver.
- Los ítems de menú con `type` implícito caen a `'page'`, que exige `page_id > 0`; un ítem `['label','url']` sin `type` se descarta entero. Al construir menús en tests hay que poner `type => 'link'`.

### Pendiente de T5.1 (siguiente sesión)

La capa se lee y se renderiza, pero **el editor de chrome del panel todavía no la escribe**: hoy solo se puede poblar por código o por importación. Falta añadir al editor un conmutador de idioma que edite `i18n[lang]` cuando el sitio es multi-idioma. Es UI, no lógica — la lógica está y probada. Conviene hacerlo junto a T5.4 (listado con estado de traducción), que también es panel.

**T5.2 completada y verificada (27/07/2026).** Motor de traducción híbrido.

Nuevo `App\Services\PageTranslator` + dos acciones IA (`TRANSLATE_PAGE_CANVAS`, `TRANSLATE_PAGE_SECTIONS`). El servicio **no persiste nada**: devuelve la traducción para que T5.3 decida. Así el guardado (y su política de borrador) queda en un solo sitio.

**Modo por tipo de página, con la lista al revés de como la escribí primero.** El test me corrigió: definí `LITERAL_TYPES` y todo lo demás caía a reescritura, así que un tipo desconocido se habría reescrito. Ahora la lista explícita es `REWRITE_TYPES` (home, service, landing, product) y **cualquier otro tipo, conocido o no, se traduce con fidelidad**: el modo con más libertad —y más riesgo— se opta tipo por tipo.

**Validación proporcional al riesgo**, que era el argumento para elegir híbrido:
- LITERAL: mismo número de secciones Y de campos editables. Si se pierde algo, se detecta solo.
- REESCRITURA: el copy cambia a propósito, así que solo se exige que no desaparezcan secciones ni quede la página en nada.

**Enlaces internos** (`rewriteInternalLinks`): `/contacto` en una página francesa mandaría al visitante de vuelta al castellano. Se resuelve por `translation_group`; si la página destino no está traducida se deja el original —mejor un enlace que funciona en otro idioma que uno roto— y los externos y las anclas no se tocan.

**UX no técnica** (petición explícita del usuario): todos los mensajes de fallo están escritos para alguien que no sabe qué es un `<section>`, dicen **siempre** que no se ha guardado nada, e invitan a reintentar. Hay un test que lo vigila (`validation_error_is_human_readable`): rechaza mensajes que contengan `data-pp-`, `<section`, «isomorf», «regex» o «null».

**Verificación con IA REAL** sobre dos páginas canvas creadas al efecto:
- **Legal (fiel)**: «Mentions légales», con NIF `B12345678`, dirección y «article 30 du RGPD» intactos. Estructura 1→1 secciones, 3→3 campos.
- **Home (adaptación)**: «Votre réussite au concours d'enseignement commence ici» — no es la traducción literal de «Tu plaza docente comienza aquí», es cómo lo diría un nativo. `{{form:contacto}}` intacto, 2→2 secciones, 3→3 campos.
- SEO traducido en ambas (T5.6 queda cubierta de paso para el flujo canvas).

Tests: `tests/page_translation.php` NUEVO (17/17), sin llamadas a IA (modo, enlaces, validación y contrato de prompts). Regresión en verde (17 suites). Sin migración. BD limpia: 31 páginas, 0 restos.

### Observación menor (no bloqueante)

En la página legal, el texto del enlace `<a href="/qa8-contacto">contacto</a>` se quedó como «contacto»: el modelo lo interpretó como parte de la URL. Con textos de enlace reales («escríbenos aquí») no debería pasar. Si se repite en pruebas con contenido real, se resuelve con una línea en el prompt.

### Lessons (T5.2)

- `CanvasService` expone `get()`, no `load()`. Confirmar el nombre real antes de llamarlo: el error solo aparece en ejecución, y aquí apareció ya con la llamada a IA hecha (coste tirado).
- Al elegir entre dos comportamientos donde uno es más arriesgado, la lista explícita debe ser la del comportamiento ARRIESGADO, no la del seguro. Así lo desconocido cae siempre del lado bueno.

**T5.3 completada y verificada (27/07/2026).** Traducir una página desde el panel.

**Arquitectura: traducir y guardar, separados.** `PageTranslator` (IA) devuelve; `TranslationWriter` (BD) persiste. Así el riesgo real del guardado —duplicar páginas, pisar contenido, publicar sin querer— se prueba **entero sin gastar una sola llamada a la IA** (`tests/page_translation_write.php`, 14/14).

**Tres invariantes fijadas por test:**
1. La traducción nace en **borrador**. La publica una persona, mirándola.
2. La página **original no se toca jamás** (se comprueba título, estado y slug tras traducir).
3. Pedirlo dos veces **no crea una segunda página**: devuelve la que ya existe.

**UX para usuario no técnico** (petición explícita), con todo verificado en el panel real:
- La columna «Idiomas» y el script **solo aparecen si el sitio es multi-idioma**. Verificado: en un sitio de un idioma, 0 columnas, 0 chips, 0 scripts. Quien no usa esto no ve nada nuevo.
- Cada página muestra su idioma y un chip por idioma pendiente (`+ Français`) o un enlace a la traducción existente, marcada «· borrador» si no está publicada.
- **Antes** de traducir, un diálogo dice literalmente qué va a pasar: «Se guardará como **borrador** para que la revises antes de publicarla, y **tu página actual no cambia**».
- **Durante**, spinner + mensajes que van rotando cada 7 s («Adaptando los textos al idioma…», «Revisando enlaces y estructura…»). Una espera larga en silencio parece un cuelgue.
- **Después**, botón directo «Abrir la traducción»; si falla, «Volver a intentarlo» con el mensaje del servidor ya escrito en cristiano.

**Verificación E2E con IA real por HTTP**: «Nuestros servicios» (/qa10-servicios, publicada, es) → «Nos services» (/fr/qa10-servicios, **draft**, fr), mismo `translation_group`, SEO traducido y contenido adaptado («Préparation aux concours de l'enseignement»). El original quedó intacto.

**Dos fallos encontrados ejecutando, no leyendo:**
1. **El diálogo se veía sin pulsar nada.** `display:grid` en `.pp-tr-overlay` pisa el atributo `hidden`. Es EXACTAMENTE la lección que ya estaba escrita en este scratchpad (FEAT-5) y aun así la repetí. Añadidas las reglas `[hidden]{display:none}` a las tres clases con display.
2. **Las comprobaciones corrían DESPUÉS de llamar a la IA**: traducir a un idioma inactivo, o una página ya traducida, gastaba una llamada para nada. Movidas antes con `TranslationWriter::precheck()`. Medido: la respuesta pasa de 2,4 s a **0,015 s**. Y de paso, el enlace a la traducción existente respeta que la página sea canvas (Studio) o de secciones (editor).

Ficheros: `app/Services/TranslationWriter.php` (nuevo), `admin/assets/js/page-translate.js` (nuevo), endpoint `POST /admin/pages/{id}/translate`, columna en el listado y CSS. Añadido `<pp-title>` al envelope de traducción: **el título de la página es lo que se ve en el menú del sitio**, y se me había quedado fuera en T5.2.

Tests: `page_translation_write.php` NUEVO (14/14). Regresión en verde (18 suites). Sin migración. BD limpia: 31 páginas, solo `es` activo.

### Lessons (T5.3)

- Repetida la lección de `[hidden]` vs `display:flex/grid`. Escribirla no basta: **al añadir CSS con `display` a un elemento que se oculta con `hidden`, la regla `[hidden]{display:none}` va en el mismo commit**, no después.
- Las guardas baratas (¿existe ya? ¿idioma activo?) van SIEMPRE antes de la operación cara. Es correctitud y es dinero.
- Separar "calcular con IA" de "escribir en BD" no es purismo: es lo que permite probar el 90% del riesgo sin gastar en llamadas.

**T5.4 completada y verificada (27/07/2026).** Estado de traducción de un vistazo.

- **Tarjeta «Traducciones»** al principio del mapa del sitio: por cada idioma adicional, «X de Y páginas traducidas», barra de progreso y enlace «Ver las N que faltan». Con todo traducido, «Todo traducido 🎉».
- **Filtro** `?sin_traducir=fr`: deja solo las páginas del idioma principal que aún no tienen versión en ese idioma, con banner explicativo y enlace para quitarlo. Verificado: de 33 páginas, la tabla muestra 32 y la ya traducida desaparece.
- **Solo cuentan las páginas del idioma PRINCIPAL.** Las que ya son traducciones no se traducen a su vez; incluirlas daría un porcentaje sin significado.
- Todo esto **solo existe si el sitio es multi-idioma**, igual que en T5.3.

**Detalle de coherencia detectado al verificar:** con el filtro puesto, la vista «Mapa» seguía mostrando el sitio entero, contradiciendo el banner de «solo lo que falta». Como el mapa es una vista jerárquica del sitio completo (filtrarla rompería el árbol), **se oculta la pestaña Mapa mientras hay filtro** y se arranca en Lista. Verificado por HTTP: 0 pestañas con filtro, 1 sin filtro.

Regresión en verde (14 suites). Sin migración. BD limpia.

### Pendiente explícito: editor de chrome por idioma (nuevo T5.7)

En T5.1 dije que el conmutador de idioma del editor de «Header y pie» encajaba en T5.4. **Al abrirlo he decidido no meterlo aquí**: el editor es un único `buildConfig()` que serializa TODA la config desde 409 líneas de JS, y añadir edición por capas exige estado de UI, habilitar/deshabilitar campos estructurales y semántica de guardado cuidadosa (no pisar la base al editar una capa). Hacerlo de refilón dentro de otra tarea es como se rompen los editores.

Queda como **T5.7**, con la lógica ya lista y probada desde T5.1 (`ChromeService::localized()` + `sanitizeI18n()`): es solo UI. Mientras tanto, la capa `i18n` se puede poblar por código o desde la traducción automática.

**T5.5 completada y verificada (28/07/2026).** Traducción del sitio completo, por pasos.

**Decisión: se reutiliza el PATRÓN del asistente, no sus tablas.** `assistant_jobs`/`assistant_job_items` están modeladas para «aplicar una instrucción a una sección»; meter aquí la traducción obligaría a reaprovechar columnas para otra cosa (`instruction` como idioma, `reply` como id de la página creada). Tablas propias (`translation_jobs`, `translation_job_items`), columnas explícitas y **cero riesgo para el asistente central, que ya está en producción**. Migración `2026_07_28_translation_jobs.sql`.

Del asistente se copia lo que está probado: **un item por petición HTTP** (el navegador llama a `step` en bucle), **un fallo no detiene los demás**, y **un reintento automático solo para fallos transitorios** (status 0, 429, 5xx).

Reglas propias de traducir: solo páginas del idioma principal; las que ya tienen versión se marcan `skipped` (no se duplican); todo a **borrador**.

**Verificación E2E real por HTTP**: trabajo de 2 páginas → paso 1 traduce «QA13 Servicios» (→ `/fr/qa13-servicios`, draft), paso 2 «QA13 Contacto» (→ `/fr/qa13-contacto`, draft), paso 3 sobre el trabajo ya terminado no rompe nada. Contenido correcto en francés y en el modo que toca (el contacto, literal; el de servicios, adaptado).

**Tres fallos encontrados ejecutando:**
1. **Orden de rutas**: `/pages/{id}` estaba declarada ANTES y capturaba `/pages/translate-all` como si `translate-all` fuera un id → 404. Movidas las rutas específicas por delante, con comentario para que no vuelva a pasar.
2. **El test traducía el sitio real**: `candidates()` devolvía las 31 páginas del sitio de desarrollo, no solo las del test, y un paso llegó a traducir «Inicio» de verdad (borrada después). Se añadió el parámetro `?array $onlyPageIds` — que además hace falta para la UI cuando el usuario quiera traducir un subconjunto.
3. **Título del diálogo en singular** («Traducir página») también en modo lote. Ahora dice «Traducir 32 páginas».

**UX del lote**, verificada en el panel: botón «Traducir las N de golpe» junto al resumen; diálogo que dice cuántas páginas, que **cada una queda en borrador**, que **las actuales no cambian**, que tardará unos minutos y —lo importante— que **si cierra la ventana el trabajo se queda donde iba y lo ya traducido no se pierde**. Durante el proceso, lista de páginas con ✅/⚠️/↷ y contador «3 de 12». Al terminar, resumen «N traducidas · M ya estaban · K no se han podido» y aviso de que las fallidas pueden traducirse de una en una.

Tests: `tests/translation_jobs.php` NUEVO (15/15), sin llamadas a IA: cubre selección de candidatas, creación, saltar lo ya traducido, que un fallo no detenga el trabajo y que un `step` sobre un trabajo terminado sea inocuo. Regresión en verde (19 suites). BD limpia: 31 páginas, 0 jobs, solo `es`.

### Lessons (T5.5)

- **Orden de rutas**: una ruta con comodín (`/pages/{id}`) declarada antes se come cualquier ruta literal más específica que llegue después. Las literales van SIEMPRE primero.
- Un test que opera sobre el sitio de desarrollo real debe **acotar su ámbito explícitamente**. Si la operación cuesta dinero (llamadas a IA), no acotar no es solo lentitud: es gasto y contenido basura en la BD.
- Reutilizar el *patrón* de un sistema probado no obliga a reutilizar sus *tablas*. Cuando los payloads difieren, columnas propias salen más baratas que reinterpretar las ajenas.

**T5.7 completada y verificada (28/07/2026). FASE 5 CERRADA.** Editor de «Header y pie» por idioma.

**Regla que gobierna la pantalla: al editar un idioma secundario solo se toca su capa de texto.** El diseño (colores, bordes, disposición), los bloques del pie, las redes sociales y los datos de contacto son COMUNES y se **bloquean visiblemente**, para que nadie crea que está cambiando «solo la versión francesa» de un color. `buildConfig()` devuelve la base intacta con `i18n[lang]` actualizado; **editar una traducción no puede modificar el original**.

**UX para usuario no técnico**, verificada en el editor real:
- Barra superior: «Estás editando los textos en» + pestañas «Español · principal» / «Français», con el aviso de que el diseño y los datos de contacto son comunes.
- Al cambiar de idioma, aviso explícito: «Lo que dejes en blanco usará el texto en Español. El diseño y los datos de contacto están bloqueados porque son iguales en todos los idiomas.»
- **Un campo vacío significa «usa el texto del idioma principal», y se ve**: el placeholder pasa a mostrar el texto castellano real. Verificado: el CTA sin traducir muestra «Pide información» como pista.
- Cambiar de idioma con cambios sin guardar pide confirmación.
- El menú se edita por idioma (apunta a otras páginas); vacío = navegación automática, que ya filtra por idioma desde la fase 3.
- La vista previa se pinta en el idioma que se está editando (`lang` viaja al endpoint de preview).

**Verificación E2E**: con base castellana + capa francesa, al pulsar «Français» el lema pasa a francés, el CTA queda vacío con el castellano de placeholder y el selector de fondo se deshabilita. Guardado en francés por HTTP → **base intacta** (`Tu plaza docente, más cerca` / `Pide información`) y capa actualizada. Renderizado: footer ES en castellano, footer FR y CTA del header en francés.

Regresión en verde (18 suites, incluido `chrome_config`). Sin migración. Estado final: config de chrome del sitio restaurada a la de fábrica, solo `es` activo, 31 páginas.

### Estado de la FASE 5

- [x] T5.1 Chrome por idioma (lógica)
- [x] T5.2 Motor de traducción híbrido
- [x] T5.3 Traducir una página bajo demanda
- [x] T5.4 Estado de traducción y filtro
- [x] T5.5 Traducción del sitio por pasos
- [x] T5.6 SEO traducido (cubierto por el motor)
- [x] T5.7 Editor de chrome por idioma (UI)

**Pendientes del proyecto**: FASE 6 (generar páginas nuevas en sitios multi-idioma) y FASE 7 (catálogo multi-idioma + rutas de módulo por idioma).

### Lessons (T5.7)

- En un editor con capas, el estado vacío necesita explicarse en la propia interfaz. «Vacío = hereda» es obvio para quien lo programó y opaco para todos los demás: ponerlo de placeholder con el valor heredado real lo resuelve sin una línea de ayuda.
- Al añadir un modo a un editor existente, la lista de controles que NO pertenecen al modo hay que enumerarla explícitamente y deshabilitarlos. Dejar editable algo que en ese modo no se guarda es peor que no ofrecer el modo.

## Current Status / Progress Tracking (I18N-FULL · FASE 6)

**FASE 6 completada y verificada (28/07/2026).** Crear y editar contenido en un sitio multi-idioma.

**El fallo que justificaba la fase, encontrado al empezar**: `CanvasChatService` pasaba a la IA `LanguageService::promptLabelFor($siteId)` — el idioma del SITIO. En una web bilingüe, pedirle un cambio a una página francesa desde el Studio habría devuelto **castellano**. Ahora manda `LanguageService::forPage($page, $siteId)`.

**Verificado con IA real** sobre una página francesa de un sitio con principal castellano:
- contenido devuelto en francés («…avec un suivi personnalisé et adapté à chaque profil…»),
- y el mensaje al administrador **en castellano**, como se decidió en T0.1 (el panel es castellano). Las dos cosas a la vez, que era el objetivo.

**Creación de páginas con idioma:**
- `PageController::createPageRow()` — punto ÚNICO que resuelve idioma, prefijo de slug y grupo de traducción. `store()` pasa por él, así que el formulario del panel y cualquier llamante futuro comparten la misma lógica.
- Un idioma que no esté activo cae al principal: vale más una página en el idioma de la casa que una en un idioma que la web no sirve.
- Verificado por HTTP: crear con `language=fr` produce `/fr/qa15-nouvelle`, idioma `fr`, grupo propio.

**UX**: el selector de idioma solo aparece si el sitio es multi-idioma. Al crear explica que un idioma no principal vivirá bajo su prefijo; **al editar sale deshabilitado**, con el motivo escrito: cambiar el idioma movería la URL de la página, y para eso está traducir desde el listado.

`CustomBlockGenerator` respeta ahora el `language` que le imponga el llamante (bloque para una página en otro idioma) en vez de forzar siempre el del sitio.

Tests: `tests/page_creation_language.php` NUEVO (9/9). Regresión en verde (23 suites). Sin migración. BD limpia: 31 páginas, solo `es`.

### Nota sobre la FASE 7 tras el dato del usuario (la web de prod llevará RESERVAS, no tienda)

El bloqueo que documenté para la fase 7 era de **Commerce**: `ProductStore` no filtra por idioma, así que un producto con gemelo francés duplicaría fichas en `/tienda` y la búsqueda por slug sería ambigua.

**Reservas no tiene ese problema**: `booking_services` no usa slug (el widget apunta a un servicio por ID con `data-service="N"`), no hay listado público de servicios que pueda salir duplicado, y la API solo se consulta por id. Una web bilingüe **solo con reservas** puede lanzarse sin la fase 7: basta con crear un servicio por idioma y embeber en cada página el suyo. Queda pendiente de confirmar en una prueba real antes de darlo por bueno del todo.

## Verificación: RESERVAS bilingües (28/07/2026)

Prometido tras el dato del usuario (la web de producción llevará reservas, no tienda). **Se ejecutó el caso real y NO estaba listo**: la lectura de código decía que sí, la prueba dijo que no. Dos huecos, los dos de cara al cliente final:

1. **El widget del servicio francés salía en castellano.** `GET /api/booking/v1/services` devolvía siempre `lang` = idioma del SITIO. Corregido: el widget indica de qué servicio es (`?service=N`) y la API responde con los textos en el idioma de ESE servicio. Sin el parámetro sigue devolviendo el idioma del sitio, así que los embebidos antiguos no cambian.
2. **La reserva se guardaba con el idioma del sitio.** Una reserva hecha en el servicio francés quedaba como `es`, así que el cliente habría recibido **email y página de cancelación en castellano**. Corregido: el idioma sale del SERVICIO (`BookingService::serviceLanguage()`), y de ahí viaja a la reserva, al mensaje de confirmación, al email y a la página de cancelación.

**Verificación E2E completa, con dos servicios hermanos (mismo `translation_group`, uno `es` y otro `fr`):**
- Widget del servicio 41 (fr): `lang: fr`, `Votre nom *`, `Réserver à {time}`. Del 40 (es): `Tu nombre *`. Sin parámetro: idioma del sitio.
- Reserva en el francés → guardada como `fr`; respuesta al cliente «Réservation confirmée. Nous vous avons envoyé les détails par e-mail.»
- Página de cancelación de esa reserva: `<html lang="fr">`, «Annuler la réservation», «Voulez-vous vraiment…», «Oui, annuler…».
- **Email real capturado**: asunto «Réservation confirmée : … — mercredi 29 juillet 2026, 11:00» y cuerpo en francés; el aviso al admin, en castellano.
- Regresión: reserva en el servicio castellano → mensaje en castellano.

**Conclusión: una web bilingüe SOLO con reservas es lanzable.** Receta: un servicio por idioma (con el mismo grupo de traducción si se quiere emparejarlos) y, en cada página, el widget de su servicio. La FASE 7 sigue pendiente solo para tiendas bilingües.

### Lessons

- «Lo he verificado leyendo el código» no es verificar. Aquí la lectura daba luz verde y la ejecución encontró DOS fallos que habrían llegado al cliente final: un widget en el idioma equivocado y un email de confirmación en el idioma equivocado.
- Cuando un dato (el idioma) tiene que viajar desde el contenido hasta un email, hay que seguirlo **de punta a punta en una ejecución real**. Cada salto —widget → API → reserva → email → página de cancelación— es un sitio donde puede caerse al valor por defecto.

---

# [FONTS] Fuentes personalizadas (brandbook) — PLAN (28/07/2026)

## Background and Motivation

Clientes con brandbook necesitan usar SUS tipografías en la web, no las 14 Google Fonts curadas. Requisitos del usuario:

1. Subir archivos de fuente desde la interfaz (admin) **y** desde el onboarding.
2. Indicar el rol de cada familia: **títulos**, **textos** o **ambas**.
3. Subir **varios pesos** por familia y que PromptPress los gestione bien (que el navegador use el peso real, no uno sintetizado).

## Key Challenges and Analysis

### Dónde vive hoy la tipografía (mapa real del código)

- `app/Services/DesignSystem.php:34` — `FONT_OPTIONS`: **const** con 14 familias. `validateValue()` (`:242`) rechaza cualquier valor fuera de esa const → hoy es imposible guardar una fuente propia.
- `DesignSystem::fontCssValue()` (`:280`) — compone `"Familia", fallback`; el fallback serif se decide con un `in_array` hardcodeado de 3 familias.
- `DesignSystem::googleFontsUsed()` (`:468`) + `renderFontsLink()` (`:337`) — meten toda familia ≠ `system` en el `<link>` de Google Fonts. Una fuente propia acabaría pedida a Google (404 silencioso y texto en fallback).
- `DesignSystem::renderHead()` (`:359`) — punto único donde el front público inyecta fuentes + vars. **Aquí es donde tiene que salir el `@font-face`.**
- `DesignSystem::applySkinToTokens()` (`:417`) y `Personality\SkinComposer::applyUserAnchors()` (`:66`) — el skin inferido por IA **pisa** `font_heading`/`font_body`.
- `VisualStyleService::renderCss()` (`:266-280`) — cada dirección visual impone sus propias `--pp-font-heading/body`.
- Onboarding step 2: `OnboardingController::TYPOGRAPHY` (`:39`) → `typography_pair` (`:896`), select en `views/admin/onboarding/index.php:204`.
- Admin diseño: `DesignController::render()` pasa `fontOptions` (`:279`); la vista pinta el select y expone `window.PP_DESIGN_FONTS` (`views/admin/design/index.php:32`) para el preview en vivo.
- Subida de archivos: patrón ya resuelto con el logo → `DesignController::updateLogo()` (`:81`) + `OnboardingController::saveLogo()` (`:1095`), guardando en `storage/uploads/{siteId}/brand/`.
- **`storage/` no es servible por web**: el logo se sirve por ruta PHP (`/brand-assets/{site}/logo` → `BrandAssetController`). Las fuentes necesitan lo mismo.

### Los 4 riesgos reales (aquí es donde se rompe si no se planifica)

1. **Precedencia.** Hay TRES capas que escriben `--pp-font-*`: tokens del design system, skin de personalidad y dirección visual. Si el cliente sube su fuente de marca y luego la IA regenera el diseño o cambia la dirección visual, la fuente desaparece. **Regla propuesta: una familia propia asignada a un rol gana SIEMPRE**, y se aplica al final de `renderHead()` (en el `<style>` inline, que ya tiene la máxima prioridad).
2. **Pesos.** Si se sube solo Regular y el design system pide `weight_bold: 700`, el navegador sintetiza una negrita falsa (fea, y el cliente de brandbook lo nota). Hay que declarar un `@font-face` por archivo con su `font-weight` y `font-style` reales, y avisar en la UI de qué pesos usa el sitio y cuáles faltan.
3. **Validación de archivos.** Aceptar binarios subidos por el usuario y servirlos: hay que validar extensión **y magic bytes** (`wOF2`, `wOFF`, `OTTO`, `\x00\x01\x00\x00`, `true`), tamaño máximo, nombre aleatorizado, `X-Content-Type-Options: nosniff` y MIME correcto al servir. Mismo endurecimiento que ya tiene el logo.
4. **Caché.** `/design.css` cachea 60s y `CacheService` cachea páginas. Cualquier alta/baja de fuente tiene que hacer `CacheService::flush($siteId)` y el `@font-face` debe llevar un parámetro de versión para invalidar el navegador.

### Decisiones que propongo (el Planner las asume salvo que digas lo contrario)

- **D1 — Formatos aceptados: `woff2`, `woff`, `ttf`, `otf`.** Los brandbooks casi siempre entregan TTF/OTF; PHP no puede convertir a WOFF2 sin binarios externos, así que se sirve tal cual. La UI recomienda WOFF2 por peso.
- **D2 — Modelo de datos: dos tablas** (`site_font_families` + `site_font_files`). Una sola tabla obligaría a repetir nombre/rol/fallback en cada peso y hace frágil el borrado. Con dos tablas, la UI es una tarjeta por familia con sus pesos dentro.
- **D3 — Clave del token: `custom:{slug}`.** `font_heading` pasa a poder valer `Inter`, `system` o `custom:helvetica-now`. Así no se rompe nada de lo existente y `googleFontsUsed()` solo tiene que excluir el prefijo.
- **D4 — Rol como propiedad de la familia** (`heading` | `body` | `both`), tal y como lo pediste. Asignar un rol desasigna a la familia que lo tuviera antes (un rol, una familia).
- **D5 — En el onboarding, campo opcional que no estorbe**: el select de parejas sigue mandando; debajo, un "¿Tienes tu propia tipografía?" que sube archivos y, si se sube algo, gana sobre la pareja elegida.
- **D6 — Licencias**: aviso visible en la UI de que el cliente debe tener derechos de uso web (webfont license). No se valida técnicamente; queda registrado.

## High-level Task Breakdown (FONTS)

**F1 — Esquema de datos + migración**
`database/migrations/2026_07_29_custom_fonts.sql` con `site_font_families` (id, site_id, name, slug, role, fallback_stack, created_at; unique site_id+slug) y `site_font_files` (id, family_id, weight, style, format, path, file_size, original_name; unique family_id+weight+style). Añadir lo mismo a `install/schema.sql` (lección ya aprendida: los installs nuevos divergen si no se toca este archivo).
*Criterio:* migración aplicada en local, `DESCRIBE` de ambas tablas correcto, y una instalación limpia desde `install/schema.sql` las crea igual.

**F2 — Servicio `CustomFontService`**
CRUD (listar familias con sus archivos, crear familia, añadir archivo, borrar archivo, borrar familia con sus ficheros de disco), validación de subida (magic bytes + tamaño + formato), guardado en `storage/uploads/{siteId}/fonts/`, y `renderFontFaceCss(int $siteId): string` que emite un `@font-face` por archivo con `font-display: swap`.
*Criterio:* test CLI en `tests/` que crea familia + 3 pesos con ficheros de prueba, genera el CSS y verifica que salen 3 bloques `@font-face` con weight/style correctos; y que un archivo con extensión `.woff2` pero contenido PNG es rechazado.

**F3 — Ruta pública de servido**
`/brand-assets/{site}/font/{id}` (`BrandAssetController::font()`), calcado del logo: valida que el archivo pertenece al site, MIME por formato, `nosniff`, `Cache-Control: public, max-age=31536000, immutable` (el id es inmutable).
*Criterio:* `curl -I` de un id válido devuelve 200 + `font/woff2`; id de otro site devuelve 404.

**F4 — Integración en el pipeline de tokens (el núcleo)**
- `FONT_OPTIONS` deja de ser la única fuente: nuevo `DesignSystem::fontOptions(int $siteId)` = curadas + `custom:*`.
- `validateValue()` acepta `custom:{slug}` existente para ese site.
- `fontCssValue()` resuelve `custom:{slug}` → `"Nombre", {fallback_stack}`.
- `googleFontsUsed()` excluye `custom:*`.
- `renderHead()` inyecta el `@font-face` **antes** de las vars y, al final, un `<style>` con la asignación por rol que pisa skin y dirección visual.
- `applySkinToTokens()` y `SkinComposer::applyUserAnchors()` no sobrescriben un rol que tenga familia propia asignada.
*Criterio:* con una familia `both` asignada, `renderHead()` contiene el `@font-face`, `--pp-font-heading` y `--pp-font-body` apuntan a ella, y NO aparece en el `<link>` de Google. Repetir con `sites.skin_json` presente y con una dirección visual activa: la fuente propia sigue ganando.

**F5 — UI en `/admin/design`**
Bloque "Tus tipografías" dentro de la tarjeta de Tipografía: subida (familia nueva o peso adicional a una existente), selector de rol, lista de pesos con botón de borrado, aviso de licencia, y aviso "tu sitio usa peso 700 para títulos y no lo has subido". Los selects de fuente pasan a incluir las propias; `window.PP_DESIGN_FONTS` y el preview en vivo cargan el `@font-face` para que se vean de verdad.
*Criterio:* subir 3 pesos, asignar rol "ambas", guardar, recargar → la web pública se ve con la fuente (verificación con servidor real + `curl` del HTML y del CSS, no solo lectura de código).

**F6 — Onboarding (step 2)**
Campo de subida múltiple + selector de rol bajo el select de parejas. `saveDesign()` procesa los archivos y, si hay familia propia, escribe `custom:{slug}` en los tokens en vez de la pareja. Preview del step 2 usa la fuente subida.
*Criterio:* onboarding completo desde cero con una fuente propia → el sitio recién creado ya renderiza con ella.

**F7 — Invalidación de caché + repaso final**
`CacheService::flush()` en toda alta/baja, versión en la URL del CSS, y borrado en cascada al eliminar familia (ficheros de disco incluidos).
*Criterio:* subir/borrar una fuente se refleja en la web pública sin borrar caché a mano.

## Project Status Board (FONTS)

- [x] F1 — Esquema + migración
- [x] F2 — `CustomFontService` (validación, almacenamiento, `@font-face`)
- [x] F3 — Ruta pública `/brand-assets/{site}/font/{id}`
- [x] F4 — Integración en `DesignSystem` + precedencia sobre skin y dirección visual
- [x] F5 — UI en `/admin/design`
- [x] F6 — Onboarding step 2
- [x] F7 — Caché e invalidación

## Executor's Feedback or Assistance Requests (FONTS)

Pendiente de que el Planner (usuario) valide el plan y las decisiones D1–D6 antes de ejecutar F1.

## Current Status / Progress Tracking (FONTS · 28/07/2026)

Las 7 tareas están implementadas y verificadas en local (servidor `:8788`, subidas reales por `curl` y comprobación en navegador).

**Archivos nuevos**
- `database/migrations/2026_07_29_custom_fonts.sql` — `site_font_families` + `site_font_files`.
- `app/Services/CustomFontService.php` — CRUD, validación, `@font-face`, deducción de peso/estilo.
- `tests/custom_fonts.php` — 34 comprobaciones, todas en verde.

**Archivos tocados**
- `app/Services/DesignSystem.php` — `fontOptions()`, `fontCssValue()` resuelve `custom:{slug}`, `googleFontsUsed()` excluye las propias, `renderHead()` inyecta `@font-face` + override final, `applyCustomFontsToTokens()`, `syncCustomFontTokens()`.
- `app/Controllers/Public/BrandAssetController.php` + `app/routes.php` — ruta de servido y `@font-face` en `/design.css`.
- `app/Controllers/Admin/DesignController.php` + `views/admin/design/index.php` + `admin/assets/css/admin.css` + `admin/assets/js/design-system.js` — bloque "Tus tipografías de marca".
- `app/Controllers/Admin/OnboardingController.php` + `views/admin/onboarding/index.php` + `admin/assets/js/onboarding.js` — campo plegable en el paso 2.

**Verificado de punta a punta**
- Subida de 2 archivos (TTF) desde `/admin/design` → peso deducido del nombre (Regular→400, Bold→700), `@font-face` en la home, `--pp-font-heading/body` con la familia real, cero peticiones a Google Fonts.
- `/brand-assets/1/font/6` devuelve 200 + `font/ttf` + bytes idénticos al archivo subido; el mismo id bajo otro `site` devuelve 404.
- Onboarding paso 2 con 2 archivos y rol "solo títulos" → familia creada con el nombre deducido del archivo, cursiva detectada, rol aplicado y la pareja tipográfica elegida arriba cede ante la fuente de marca.
- Aviso de peso ausente: con 400 y 700 subidos y `buttons.font_weight = 600`, el panel avisa de que falta Semibold (600).
- Estado de desarrollo devuelto a limpio: familias borradas, archivos fuera del disco, tokens de vuelta a Inter.

### Lessons (FONTS)

- El preview de `/admin/design` llevaba tiempo sin aplicar la tipografía: `style="<?= $previewInline ?>"` metía valores con comillas dobles (`"Inter", system-ui…`) sin escapar, así que el atributo `style` se cerraba en la primera comilla y el navegador descartaba el resto. Se ve solo si comparas la fuente calculada con la esperada — a ojo parecía "una sans cualquiera". Corregido con `e()`.
- La dirección visual (`VisualStyleService`) declara sus fuentes con un selector de CLASE (`.pp-visual-style--x`), no con `:root`. Cualquier override posterior tiene que igualar esa especificidad: se usa `:root,[class*="pp-visual-style--"]` y se emite el último del `<head>`.
- Asignar el rol de una fuente tiene que escribir TAMBIÉN los tokens (`syncCustomFontTokens`). Con solo aplicarlo al renderizar, el desplegable de Tipografía seguía enseñando "Inter" mientras la web se veía con la fuente de marca: el usuario deja de saber quién manda.
- Un test que lee el estado real del sitio (las fuentes que haya subido el usuario) pasa o falla según el día. `tests/custom_fonts.php` aparta los roles reales al empezar y los restaura en `register_shutdown_function`.

---

# [UX4] Logo claro/oscuro · multi-subida · parar generación · parpadeo de fuente — PLAN (28/07/2026)

## Background and Motivation

Cuatro peticiones del usuario tras subir a producción la funcionalidad de tipografías:

1. Logo en dos versiones (claro/oscuro) con una marcada como principal, para que la IA use la que contraste con el fondo.
2. Subida múltiple de imágenes en Medios.
3. Poder parar una generación del Studio ya enviada.
4. El parpadeo de fuente genérica → fuente propia dura demasiado, incluso con buena conexión.

## Key Challenges and Analysis

### U1 — Logo claro / oscuro

- Hoy solo hay UN logo: setting `site_logo_path` + fila en `media` ([DesignController::updateLogo](app/Controllers/Admin/DesignController.php:82), [BrandService::publicLogoUrl](app/Services/BrandService.php:77)), servido por `/brand-assets/{site}/logo`.
- **Hallazgo:** `ChromeService` YA declara el hueco `header.logo.dark_variant_path` ([ChromeService.php:49](app/Services/ChromeService.php:49) y [:189](app/Services/ChromeService.php:189)) — con default y saneado — pero **nadie lo lee**: no hay UI ni render. Es un slot muerto de un intento anterior; la funcionalidad puede aterrizar ahí en vez de inventar otro sitio.
- El riesgo real no es técnico sino de etiquetado: "logo oscuro" es ambiguo (¿el logo de tinta oscura, o el que va sobre fondo oscuro?). La UI debe nombrarlos **por el fondo donde van**: "Para fondos claros" / "Para fondos oscuros". Marcar cuál es el principal = cuál se usa cuando no se sabe el fondo.
- Consumidores a actualizar: cabecera pública, pie (que hoy pinta el NOMBRE, no el logo, y va sobre `--pp-on-surface`, es decir fondo oscuro), panel, y el contexto que recibe la IA de Canvas.

### U2 — Subida múltiple en Medios

- [MediaController::upload](app/Controllers/Admin/MediaController.php:98) procesa un único `$_FILES['file']`; la vista tiene un input sin `multiple` ([views/admin/media/index.php:47](views/admin/media/index.php:47)). El endpoint ya responde JSON cuando la petición es AJAX, así que el camino barato es: `multiple` en el input + subir de una en una desde JS reutilizando ese endpoint, con progreso por archivo. Sin tocar `MediaService::store`.
- Ventaja de subir de una en una: un archivo corrupto no tumba la tanda, y no chocamos con `upload_max_filesize`/`post_max_size` del hosting, que es lo que rompe las subidas múltiples de golpe.

### U3 — Parar la generación

- El chat del Studio hace `fetch` sin `AbortController` ([canvas-studio.js:405](admin/assets/js/canvas-studio.js:405)); solo desactiva el botón con la bandera `busy`.
- **Cuidado con el falso "cancelar":** abortar el fetch en el navegador NO detiene a PHP. La petición sigue, el modelo responde y el cambio se guarda igual; el usuario creería haberlo parado y se encontraría la página modificada. Un cancelar honesto necesita que el servidor mire una marca antes de persistir.
- Diseño propuesto: el chat manda un `request_id`; "Parar" aborta el fetch Y llama a `POST /admin/canvas/cancel` con ese id; `CanvasChatService` comprueba la marca justo antes de escribir y descarta el resultado. Como red de seguridad ya existe el historial undo/redo.

### U4 — Parpadeo de la fuente propia

Tres causas, de mayor a menor sospecha:

1. **El lock de sesión de PHP serializa las descargas.** `App::boot()` llama a `Session::start()` en TODAS las rutas ([core/App.php:55](core/App.php:55)), incluida `/brand-assets/{site}/font/{id}`, y no se cierra la sesión antes de servir. El handler de ficheros de PHP mantiene un lock exclusivo por sesión durante toda la petición: las fuentes hacen cola detrás de la petición de la página y entre ellas. Con buena conexión el síntoma es exactamente ese — la página pinta ya con la genérica y las fuentes entran con retraso. Arreglo: `session_write_close()` (o no arrancar sesión) antes de `readfile()` en las rutas de asset.
2. **No hay `<link rel="preload">`** de las fuentes de los roles asignados: el navegador no empieza a descargarlas hasta que aplica el CSS.
3. **TTF/OTF pesan 5-10× más que WOFF2** (en pruebas locales, 486 KB por corte). Hay que avisar en la UI y recomendar convertir.

`font-display: swap` es lo que produce el cambio visible; es la opción correcta (mejor eso que texto invisible), pero con 1 y 2 resueltos la ventana se reduce a casi nada.

## High-level Task Breakdown (UX4)

**U4.1** — `session_write_close()` antes de servir en `BrandAssetController` (logo + fuente). *Criterio:* dos peticiones concurrentes a fuentes distintas dejan de serializarse (medido con `curl` en paralelo contra un servidor multiproceso).
**U4.2** — `<link rel="preload" as="font" type=… crossorigin>` en `renderHead()` para los cortes de los roles asignados (máx. 2-3, no todos). *Criterio:* el HTML público trae el preload y la fuente aparece ya descargada en la primera pintura.
**U4.3** — Aviso en `/admin/design#fonts` cuando un archivo no es WOFF2, con su peso real. *Criterio:* subir un TTF de 400 KB muestra el aviso; un WOFF2 de 30 KB no.
**U2.1** — `multiple` en el input de Medios + cola de subida en JS con progreso y errores por archivo. *Criterio:* seleccionar 5 imágenes las sube todas; si una falla, las otras 4 entran y el error nombra el archivo.
**U1.1** — Dos slots de logo (fondo claro / fondo oscuro) + principal, sobre el hueco existente de `ChromeService`. *Criterio:* subir ambos, marcar principal, y ver el correcto en cabecera (claro) y pie (oscuro).
**U1.2** — Exponer ambas variantes al contexto de la IA de Canvas. *Criterio:* pedir en el chat "pon el logo sobre la sección oscura" y que use la variante para fondo oscuro.
**U3.1** — Botón "Parar" con `AbortController` + `request_id` + endpoint de cancelación comprobado antes de persistir. *Criterio:* cancelar a mitad deja la página EXACTAMENTE como estaba (verificado recargando, no solo por lo que diga la UI).

## Project Status Board (UX4)

- [x] U4.1 — Cerrar sesión antes de servir assets
- [x] U4.2 — Preload de las fuentes en uso
- [x] U4.3 — Aviso de formato/peso no óptimo
- [x] U2.1 — Subida múltiple en Medios
- [x] U1.1 — Logo claro/oscuro + principal
- [x] U1.2 — Variantes de logo disponibles para la IA
- [x] U3.1 — Parar generación (cancelación real)

---

# [SLIDER] Carrusel de Canvas: siempre horizontal y sin poder elegir las fotos — 28/07/2026 (Executor)

## Diagnóstico (reproducido, no deducido)

Las dos quejas del usuario tenían **la misma causa**, y no estaba en el carrusel sino en el guardado del Studio.

`serializeAndSave()` ([CanvasController.php:489](app/Controllers/Admin/CanvasController.php:489)) guarda el `outerHTML` del **DOM vivo** de la sección. Para entonces `pp-ux.js` ya ha montado el carrusel: envuelve los slides en `.pp-ux-slider__track`, añade dos `<button>` de flechas y marca el contenedor con `data-pp-ux-ready="1"`. El normalizador del servidor limpiaba lo del Studio (`pp-studio-*`, `contenteditable`) pero **no sabía nada de pp-ux**, así que todo ese andamiaje se persistía.

Al recargar, `initSlider` empieza con `if (el.dataset.ppUxReady) return;` → **no engancha listeners**. Resultado: tira horizontal congelada, flechas muertas y, como no se puede pasar de slide, las fotos que quedan fuera de pantalla son inalcanzables → "no me permite elegir las fotos".

Prueba A/B sobre el MISMO HTML dañado, instrumentando `Element.prototype.scrollBy`:
- pp-ux.js anterior → `scrollBy` llamado **0 veces** al pulsar la flecha (sin listeners).
- pp-ux.js nuevo → `scrollBy({left:444})` llamado **1 vez** (carrusel recuperado).

Se hizo así porque medir `scrollLeft` no valía: en el navegador headless `behavior:'smooth'` no anima y todo parecía roto. Con `'auto'` sí movía. **Lección: en este entorno, verificar scroll por el efecto es un falso negativo; hay que verificar la llamada.**

Daño ya presente en la BD local: página 135 con 14 clases `pp-ux-*` incrustadas (animaciones `reveal` quemadas) y la 146 con el juego completo.

## Cambios

1. **`CanvasService::stripRuntimeBehaviorMarkup()`** (nuevo) — desenvuelve el track, borra flechas y puntos, quita `data-pp-ux-ready` y toda clase `pp-ux-*`, y restaura la cifra del contador. Se llama desde `normalizeEditedSectionHtml()`, por donde pasa TODA edición directa. La fuente de verdad es siempre `data-pp-behavior`.
2. **`pp-ux.js` · defensa en `initSlider`** — si encuentra andamiaje de una sesión anterior lo deshace antes de montar. Esto recupera en caliente las páginas ya dañadas, sin esperar a la reparación.
3. **`scripts/repair_canvas_runtime_markup.php`** — repara el HTML ya guardado. Con `--dry-run`. Idempotente.
4. **Disposiciones del carrusel** (petición del usuario: "siempre horizontal") — `data-pp-slider="strip|single|vertical"`: en fila (defecto), una a una a todo el ancho con puntos de posición, o pila vertical con flechas arriba/abajo. CSS en `DesignSystem::renderSectionBaseCss()`.
5. **Control en el Studio** — panel de sección: "Galería · cómo se ven las fotos" (En fila / Una a una / En vertical) + "Elegir fotos (N)".
6. **Selección múltiple de fotos** — la biblioteca entra en modo galería: se tocan las fotos en orden (numeradas), y "Usar N fotos" reconstruye los slides clonando el primero como plantilla, así heredan maquetado y pies.
7. **La IA conoce las disposiciones** — `data-pp-slider` documentado en el prompt de generación y en los dos de edición; para galerías de fotos se le indica `single`.

## Verificación

- `tests/canvas_runtime_markup.php` — 16 comprobaciones en verde (limpieza, conservación de fotos/pies/clases del autor, slides al nivel correcto, contador, idempotencia, HTML limpio intacto).
- Ciclo real en el Studio: editar un titular de una sección con carrusel → lo guardado ya NO trae andamiaje y conserva las 4 fotos.
- "Una a una": slide de 932 px sobre pista de 940 (ancho completo) y 4 puntos de posición.
- "En vertical": `flex-direction: column`, `scroll-snap-type: y mandatory`, flechas arriba/abajo, sin puntos.
- Elegir fotos: 3 seleccionadas en orden (1,2,3) → el carrusel pasa de 4 a 3 fotos, con sus pies, y se guarda `data-pp-slider="vertical"` sin una sola clase de runtime.
- Preview pública: disposición vertical, 3 fotos, flecha viva.
- Reparación: 2 páginas arregladas; segunda pasada, 0 cambios.

### Lessons

- El Studio serializa el DOM vivo: **cualquier JS que modifique el DOM en tiempo de ejecución acaba en la base de datos** salvo que el normalizador lo deshaga explícitamente. Al añadir un comportamiento nuevo a `pp-ux.js` hay que añadir su limpieza en `stripRuntimeBehaviorMarkup()` a la vez, no después.
- Un `data-*-ready` como guarda de idempotencia es una bomba si el HTML se persiste: la marca sobrevive a la recarga y desactiva para siempre el propio comportamiento que protegía. Si se usa, el montador debe saber deshacer su estado (es lo que ahora hace `initSlider`).
- En el navegador headless de verificación, `scrollBy({behavior:'smooth'})` no produce desplazamiento. Verificar el EFECTO da falsos negativos; hay que instrumentar la LLAMADA.

## Current Status / Progress Tracking (UX4 · 29/07/2026)

Las 7 tareas implementadas y verificadas. Orden ejecutado: parpadeo → multi-subida → logo → parar generación.

**U4 · Parpadeo de la fuente**
- `Session::close()` (nuevo en `core/Session.php`) y llamada en las rutas de asset de `BrandAssetController`.
- `CustomFontService::renderPreloadLinks()` + `bestFileFor()`: `<link rel="preload">` SOLO de los dos cortes que se ven primero (peso de títulos y peso de textos). Va lo primero del `<head>`.
- Aviso en `/admin/design#fonts` con el peso total y los archivos TTF/OTF o >120 KB.
- **Honestidad sobre la verificación:** el preload y el aviso están comprobados en el HTML real. La serialización por lock de sesión NO se pudo medir en local: el servidor de desarrollo sirve en 1-3 ms y el ruido se come la diferencia. El mecanismo está documentado en PHP y el cambio es inocuo, pero no tengo prueba propia de que fuera la causa del parpadeo del usuario. Lo que sí es medible: 1,1 MB de TTF en la prueba, que en WOFF2 serían ~100 KB.

**U2 · Subida múltiple en Medios**
- Input `multiple` + cola en JS que sube de una en una reutilizando el endpoint AJAX existente; fila por archivo con su estado y motivo del fallo.
- `MediaController::uploadBatch()` para el camino SIN JavaScript (el navegador manda `file[]`).
- Verificado: 3 imágenes por la cola con recarga al terminar; tanda mixta (ok/roto/ok) → 2 subidas + el error exacto del archivo malo, sin cortar la tanda.
- **Hallazgo colateral (NO arreglado, fuera de alcance):** `MediaService::detectMime()` cae a deducir el tipo por la EXTENSIÓN cuando finfo no reconoce el mime, así que un `.txt` renombrado a `.jpg` entra en la biblioteca como imagen. Reproducido. Queda propuesto como tarea aparte.

**U1 · Logo claro / oscuro**
- Nombrados por el FONDO donde van, no por su color. Ajustes: `site_logo_path`, `site_logo_dark_path`, `site_logo_primary`.
- Ruta `/brand-assets/{site}/logo/dark`; `BrandService::logoPathFor()/logoVariantFor()/logoUrl($siteId,$fondo)` con recambio a la otra variante.
- El pie (fondo oscuro) pasa a usar el logo para fondo oscuro si existe; si no, sigue con el nombre en texto (un logo de tinta oscura sobre el pie no se vería).
- `BrandService::logoHintForAi()` entra en el contexto de generación y en `available_images` del chat, con la regla de contraste.
- Verificado: subida de ambas, marcar principal (la cabecera cambia), borrar la principal (recambio automático y el ajuste `site_logo_primary` se corrige).

**U3 · Parar la generación**
- `CanvasCancelToken` (marca en disco, no en sesión) + endpoint `POST /admin/canvas/{id}/cancel` + `request_id` desde el navegador.
- La comprobación va justo antes de `CanvasService::save()`, que es la única línea que toca la página.
- El chat llama a `Session::close()` antes de la petición a la IA: sin eso, la petición de cancelar se quedaría bloqueada esperando a la generación que quiere parar.
- **Verificado con una generación real:** cancelada a los 2 s → el endpoint respondió en 5 ms (prueba de que el lock ya no serializa), la IA terminó su trabajo (`ai_logs`: `edit_canvas_page`, success, 2117 ms) y el chat devolvió 409 "Cambio cancelado. Tu página no se ha tocado" con el sha1 de la página INTACTO. Control positivo: la misma instrucción sin cancelar → 200 y la página sí cambia.

### Lessons (UX4)

- "Cancelar" en el navegador no cancela nada: abortar el `fetch` deja al servidor trabajando y guardando. Si el usuario ve "cancelado", el servidor tiene que haber mirado una marca antes de escribir.
- Guardar esa marca en la sesión habría sido inútil: la petición larga retiene el lock del fichero de sesión, así que la petición de cancelar se habría quedado esperando justo a lo que quiere parar. Marca en disco.
- Al medir concurrencia en local con `php -S`, ojo: por defecto es de un solo proceso (`PHP_CLI_SERVER_WORKERS=4` para varios) y con respuestas de 1-3 ms el coste de arrancar `curl` domina la medición. Si no se puede medir, decirlo en vez de adornar.

---

# [UPD] Actualizar subiendo el ZIP desde Ajustes — 29/07/2026 (Executor)

## Punto de partida

La maquinaria ya existía: `UpdateInstallerService::apply()` hacía backup → descarga → verificación → extracción → despliegue → migraciones. Solo sabía DESCARGAR de una URL remota. Lo añadido es la puerta de entrada manual, el mantenimiento y la vuelta atrás.

## Cambios

1. **`MaintenanceMode`** (nuevo) — marca en fichero (no en BD: tiene que funcionar con el código a medias). Gate en `Core\App::run()`: el público recibe 503 con página propia; **el panel sigue abierto** para que quien actualiza no se quede fuera y pueda restaurar. La marca caduca a los 15 min: una actualización cortada no puede dejar el sitio caído para siempre.
2. **`UpdateInstallerService::applyFromUpload()`** — valida la subida (ZIP real por cabecera `PK\x03\x04`, tamaño), checksum SHA-256 opcional ANTES de tocar nada, backup, y la misma tubería de siempre.
3. **Huella de paquete** (`assertLooksLikePromptPress`) — el zip debe traer `index.php`, `app/`, `core/`, `config/constants.php` y `database/migrations`. Sin esto, cualquier zip se volcaría sobre la raíz.
4. **`restore()` + `backups()`** — listado de copias y vuelta atrás, guardando antes el estado actual (`*_prerestore.zip`). Nombre de copia validado contra path traversal.
5. **UI en Ajustes → Actualizaciones** — subida del ZIP, campo de checksum opcional, listado de copias con botón Restaurar, y textos que dicen exactamente qué se toca y qué no.

## Bug preexistente encontrado y arreglado

`UpdateInstallerService::runMigrations()` instanciaba `PromptPress\Database\Migrator` **sin cargar la clase**: ese namespace no está en el autoloader (los demás callers hacen `require_once` a mano). Es decir, la actualización remota que ya estaba en producción **desplegaba los archivos y moría justo después, al migrar**: código nuevo con base de datos vieja y un fatal en pantalla. Añadido el `require_once`.

## Verificación

`tests/update_from_zip.php` — 18 comprobaciones en verde: rechazo de zip que no es PromptPress (con el motivo), de archivo que no es zip y de checksum que no cuadra (sin desplegar nada); despliegue correcto con lectura de versión, copia de seguridad creada y listada, `config/config.php` intacto; segunda versión y restauración al estado anterior con copia de seguridad previa; nombre de copia manipulado rechazado; y, al final, que `index.php` y `config/constants.php` siguen siendo byte a byte los mismos.

Extremo a extremo por HTTP: subida real del ZIP con su checksum → "Actualización aplicada (v0.1.0-dev)" + copia guardada; el sitio siguió respondiendo 200 y los cambios recientes del código seguían en su sitio. Restauración por HTTP → copia restaurada + `*_prerestore.zip` creada.

Mantenimiento: con la marca activa, la web pública devuelve **503 + Retry-After: 120** con la página "Volvemos enseguida" y el panel sigue en 200. Con la marca envejecida a 1000 s, se limpia sola y la web vuelve a 200.

## Incidente durante el desarrollo (mío)

La primera versión del test metía en el paquete de prueba un `index.php` y un `config/constants.php` **inventados**, y el despliegue —que es real— se los llevó por delante en la instalación de desarrollo: la app dejó de arrancar. Restaurado desde git en el momento. El test se reescribió para que el paquete lleve **copias byte a byte de los archivos reales** (sobrescribirlos es un no-op) y solo un centinela nuevo en `storage/`, y ahora se niega a ejecutarse si `PP_ENV` no es `development`.

### Lessons (UPD)

- Un test que ejerce un despliegue real no puede llevar contenido inventado para rutas reales. O el paquete replica los archivos existentes, o el test no se ejecuta contra la instalación de trabajo. Aquí costó dejar la app sin front controller.
- `deploy()` COPIA, nunca borra. Bueno: no se pierden `config.php`, `storage/` ni marcas como `install/.installed`. Malo: el código eliminado en la versión nueva sobrevive, y **restaurar tampoco borra lo que la versión rota añadió**. Está dicho en la interfaz para que nadie espere otra cosa.
- Cargar una clase fuera del autoload solo por `use` no falla hasta que se instancia. En un flujo largo (backup, descarga, despliegue…) ese fallo aparece en el peor momento: con los archivos ya sustituidos.

---

# [STUDIO-2] Barra lateral de edición + chat flotante · imágenes propias primero · bug del fondo — PLAN (29/07/2026, Planner)

## Background and Motivation (STUDIO-2)

Cuatro peticiones del usuario en una sola conversación, todas sobre el Studio de páginas canvas:

1. **Separar los dos modos de edición**: la barra lateral se queda SOLO para edición manual (y así puede ir creciendo con más controles), y el chat pasa a ser una conversación flotante sobre la página.
2. **Mejorar la edición por chat** (pregunta abierta: qué se puede hacer).
3. **Prioridad a las imágenes del negocio**: hoy la plataforma tira de Unsplash por defecto; las fotos que sube el cliente deben ser la primera opción y Unsplash el relleno.
4. **Bug**: con una foto de fondo, si le pides a la IA que ponga "una capa blanca encima", después ya no se puede cambiar esa foto desde la barra lateral.

## Key Challenges and Analysis (STUDIO-2)

### A. Estructura actual del Studio (lo que hay que mover)

`views/admin/canvas/studio.php` → `.cvstudio-main` = `.cvstudio-stage` (iframe) + `aside.cvstudio-chat` (380 px fijos) que hoy mete TRES cosas en la misma columna: `#edit-panel` (panel manual contextual, oculto hasta que seleccionas), `#chat-messages` y el composer (insertar formulario + chip de contexto + textarea + modelo + Aplicar/Parar). El panel manual y el chat se pelean por el mismo alto: cuando el panel de sección está abierto, el chat queda reducido a una rendija. Esa es la razón de fondo por la que "no caben más elementos" en la barra.

Todo el cambio de layout es front-end (studio.php + `cvstudio-*` en admin.css + canvas-studio.js). No toca backend ni el contrato de mensajes con el iframe (`postMessage` source `pp-studio` / `pp-studio-parent`).

### B. Qué le falta hoy a la edición por chat (revisado en código)

- **No hay memoria de conversación**: `CanvasChatService::applyInstruction()` manda solo la instrucción actual (+ sección + `element_context`). "Ahora un poco más grande" no tiene a qué referirse. Es el fallo más visible y el más barato de arreglar.
- **La selección de elemento viaja como PROSA**: `element_context` = `"texto con texto \"...\""` (canvas-studio.js:80). El modelo tiene que adivinar qué nodo es. Marcar el nodo elegido en el HTML que se le manda (`data-pp-target="1"`) es determinista y elimina la ambigüedad.
- **Después de aplicar no se ve qué cambió**: `reloadPreview()` recarga el iframe entero; se recupera el scroll pero no hay ninguna señal de qué parte se ha tocado.
- **Espera opaca**: una edición de sección puede tardar 30-60 s con tres puntitos. Existe "Parar" (bien), pero no hay ni tiempo transcurrido ni expectativa.
- **Errores genéricos** (ya en Lessons): cualquier `AIException` cae en "La IA no devolvió un cambio válido". Además, si el sobre `<pp-html>/<pp-css>` viene mal, no hay reintento (el generador SÍ reintenta; el chat no).
- **Todo se interpreta como edición**: una pregunta ("¿queda bien este azul?") acaba modificando la página.

### C. Imágenes: por qué siempre salen fotos de Unsplash (causas concretas)

1. `CanvasChatService::prepareRequestedImages()` — en cuanto la instrucción menciona una foto, **va a Unsplash primero** y descarga 3 imágenes a la biblioteca, haya o no fotos propias del negocio. La biblioteca propia solo se mira como red de seguridad si Unsplash falla (`hasLibraryImages`).
2. `CanvasChatService::availableImages()` — lista las 12 imágenes más recientes `ORDER BY id DESC`, **sin distinguir origen**. Como el paso 1 acaba de meter 3 de Unsplash, esas encabezan la lista. Se retroalimenta.
3. **La generación de páginas ni siquiera consulta la biblioteca**: `OnboardingController::resolvePlanImages()` y `genericBusinessImages()` resuelven los briefs **exclusivamente** contra `ImageBankService` (Unsplash). Un negocio con 20 fotos propias recibe una página de stock.
4. **Las fotos propias llegan sin descripción**: `media.alt_text` es opcional al subir (en la BD de dev: 30/30 de Unsplash con alt, 1 de 3 subidas sin alt). En el prompt aparecen como `- /storage/uploads/1/IMG_2043.jpg — ` (ruta pelada), mientras las de Unsplash llevan descripción. Aunque se prioricen, el modelo no sabe qué son. **Sin descripciones, la prioridad no se puede ejercer bien**: esta es la pieza habilitadora.

La columna `media.source` ('upload' | 'unsplash') ya existe y es la palanca para todo lo anterior.

### D. Bug del fondo tras "ponle una capa blanca" — diagnóstico verificado

Reproducido sobre páginas canvas reales de la BD de dev (637 y 774). Hay tres mecanismos distintos, todos de la misma familia: **el panel manual pierde el "asa" de la imagen cuando la IA reestructura la sección**.

- **Mecanismo 1 (el del usuario, principal)** — la capa blanca es un elemento que CONTIENE el contenido. Página 637: `<section data-pp-section="hero" class="lx-hero">` con `background-image:url(foto)` en la hoja, y dentro `<div class="lx-hero__overlay">` con `background:rgba(255,255,255,.85)`. Al hacer clic dentro del hero, `visualBoxFrom()` (overlay del preview, CanvasController) devuelve ese div → el panel se abre como **"Bloque"**, cuyos controles NO tienen nada de imagen de fondo. El panel de **Sección** —único sitio con "Imagen de fondo · Cambiar/Quitar"— se vuelve inalcanzable salvo que aciertes a pinchar una franja de la sección por fuera de la caja blanca. Si la capa cubre la sección entera (`inset:0`), inalcanzable del todo.
- **Mecanismo 2** — velo incrustado en la propia regla del fondo. Página 774: `background-image: linear-gradient(rgba(255,255,255,.7),rgba(255,255,255,.7)), url("...jpg")` en la hoja de estilos. Al reemplazar, el overlay lee `bgEl.style.backgroundImage` (**solo el inline**, aquí vacío) → escribe inline `url(nueva)` → la capa inline gana a la hoja y **el velo desaparece** al cambiar la foto. Y si la IA llega a dejar el `background-image` con solo el gradiente (sin `url`), `cssBgUrlOf()` devuelve null → `hasBgImage=false` → el panel ofrece "Poner imagen de fondo" y el cambio parece no hacer nada.
- **Mecanismo 3** — la foto acaba en un div intermedio (`.hero__bg{background-image:url()}`). `bgImageOf()` solo busca `<img>` que cubran y `cssBgUrlOf()` solo mira el elemento en sí → ninguno la encuentra → "esta sección no tiene imagen de fondo".

Conclusión: no es un bug puntual, es una fragilidad estructural. Los dos arreglos que lo cierran de forma duradera son **poder subir de ámbito siempre** (elemento → bloque → sección) y **resolver quién lleva de verdad el fondo** en lugar de asumir que es la `<section>`.

## High-level Task Breakdown (STUDIO-2)

Orden pensado para que el alivio llegue pronto y los cambios de UI se toquen una sola vez.

### Fase 1 — Bug del fondo (pequeña, sin rediseño)

- **D1 · Resolver el dueño del fondo.** `resolveBgTarget(section)` en el overlay del preview: devuelve el elemento que realmente lleva el fondo (la sección, un descendiente con `background-image` que cubra, o un `<img>` de cobertura). `hasBgImage`, `bgdim`, `bgimg mark/remove` y `replace-image` pasan a operar sobre él.
  *Éxito*: en una sección con la foto en un wrapper interior, el panel dice "Imagen de fondo" y Cambiar/Quitar funcionan.
- **D2 · Conservar el velo al reemplazar.** Leer `getComputedStyle(el).backgroundImage` (no solo el inline), conservar todas las capas que no sean `url(...)` (gradientes/velos) y sustituir únicamente la capa de imagen.
  *Éxito*: en la página 774 (velo blanco 0.7 + foto), cambiar la foto mantiene el velo.
- **D3 · Regla de prompt para las capas.** En `EDIT_CANVAS_SECTION`/`EDIT_CANVAS_PAGE`: una "capa/velo" sobre una foto de fondo se aplica como capa extra del `background-image` del mismo elemento (o `::before`), nunca envolviendo el contenido en una caja opaca, y nunca eliminando la capa `url(...)`.
  *Éxito*: pedir "capa blanca encima de la foto" deja la sección con foto + velo y el panel sigue ofreciendo Cambiar.

### Fase 2 — Barra lateral manual + chat flotante (+ el arreglo estructural del bug)

- **A1 · La barra lateral pasa a ser el Editor.** El `aside` deja de contener el chat: queda `#edit-panel` a pantalla completa de la columna, con tres estados — sin selección (ayuda + lista de secciones), elemento seleccionado (controles de hoy), sección seleccionada (controles de sección). Ancho actual (380 px), sin scroll compartido con nada.
  *Éxito*: seleccionar texto/botón/imagen/sección llena la barra sin recortar nada; sin selección la barra no está vacía.
- **A2 · Migas de ámbito (arregla el mecanismo 1 del bug).** Cabecera del panel con la cadena real del elemento: `Texto ▸ Bloque ▸ Sección ▸ Página`, clicable para subir de ámbito, y `Esc` sube un nivel. El iframe manda la cadena de ancestros editables junto con `element-selected`.
  *Éxito*: en el hero de la página 637 (caja blanca sobre foto), un clic dentro de la caja y luego "Sección" en las migas da acceso a "Imagen de fondo · Cambiar" — el caso exacto del usuario.
- **A3 · Chat flotante.** Pastilla anclada abajo a la derecha sobre el lienzo ("Pídeme un cambio…") que se despliega en panel de conversación (chat + chip de contexto + modelo + Aplicar/Parar + insertar formulario). Recuerda plegado/desplegado en `localStorage`, se despliega solo mientras hay una petición en curso y muestra estado en la pastilla si está plegado. Por debajo de ~1100 px la barra lateral pasa a cajón superpuesto.
  *Éxito*: se puede conversar con el chat viendo el panel manual completo a la vez; plegado, el lienzo gana ~380 px de ancho.

### Fase 3 — Imágenes propias primero

- **C1 · Fuente única de imágenes para la IA.** `MediaLibraryService::forAi($siteId, ...)`: fotos de la biblioteca ordenadas **propias primero** (`source='upload'`), etiquetadas (`foto propia del negocio` / `banco de imágenes`) y con descripción y orientación. Lo consumen chat y generación.
  *Éxito*: test que, con biblioteca mixta, devuelve todas las propias antes que cualquiera de banco.
- **C2 · Chat: Unsplash deja de ser el primer recurso.** En `applyInstruction()`, si el sitio tiene fotos propias utilizables NO se llama a Unsplash; solo se recurre a él si no hay ninguna propia o si el usuario lo pide explícitamente ("busca una foto de…"). Regla de prompt: prioriza siempre las fotos propias; el banco solo si ninguna encaja.
  *Éxito*: con biblioteca propia, "pon una foto de fondo aquí" no genera ninguna descarga de Unsplash (verificable en `media`).
- **C3 · Generación: la biblioteca propia entra en el reparto.** `resolvePlanImages()` y el pool sin referencias buscan primero en las fotos propias del sitio (casando el brief con descripción/nombre) y solo piden a Unsplash lo que falte.
  *Éxito*: sitio con 6 fotos propias descritas → página generada usando propias; sin fotos propias → comportamiento actual intacto.
- **C4 · Que las fotos propias se puedan describir** (habilitador de C1-C3; decisión pendiente del usuario sobre automático vs. manual). Descripción con IA de las imágenes sin `alt_text`, editable siempre desde la biblioteca.
  *Éxito*: una foto recién subida llega al prompt con una descripción útil, no con la ruta pelada.
- **C5 · Que se note en la interfaz.** En el modal de imágenes del Studio: filtro "Tus fotos / De banco" y la pestaña Unsplash presentada como alternativa cuando no hay foto propia.
  *Éxito*: el modal abre en "Tus fotos" y se ve de un vistazo cuáles son propias.

### Fase 4 — Calidad de la edición por chat

- **B1 · Memoria de conversación.** Enviar los últimos 3-4 turnos (instrucción + respuesta + ámbito) al prompt de edición. *Éxito*: "ponlo un poco más grande" tras un cambio anterior se aplica a lo mismo.
- **B2 · Marcar el elemento seleccionado en el HTML.** Sustituir la prosa de `element_context` por un `data-pp-target="1"` en el nodo elegido dentro del HTML que se manda; regla de prompt "aplica el cambio al elemento marcado". *Éxito*: con dos titulares iguales en la sección, se cambia el que estaba seleccionado.
- **B3 · Ver qué ha cambiado.** Tras aplicar: volver a la sección tocada, resaltarla un instante y mantener la selección. *Éxito*: tras un cambio en una sección del final, la vista queda en ella y se distingue.
- **B4 · Espera y errores honestos.** Contador de tiempo y expectativa realista mientras trabaja; mensajes de error por causa (agotó el tiempo / respuesta cortada / proveedor caído) con la salida sugerida; reintento automático UNA vez si el sobre `<pp-html>/<pp-css>` viene mal. *Éxito*: un fallo de sobre se recupera solo; un timeout lo dice con esas palabras.
- **B5 (opcional) · Preguntas sin tocar la página.** Detectar que el mensaje es una pregunta y responder sin editar. *Éxito*: "¿este azul se lee bien?" responde y la página no cambia de versión.
- **B6 (opcional) · Atajos deterministas.** Peticiones simples que el panel ya sabe hacer (color, tamaño, espaciado, quitar fondo) se resuelven sin llamar a la IA. *Éxito*: "pon el fondo blanco" se aplica al instante y sin coste. **Riesgo**: falsos positivos; solo con un conjunto pequeño y con "deshacer" a la vista.

## Project Status Board (STUDIO-2)

- [x] D1 resolver dueño del fondo
- [x] D2 conservar velo al reemplazar
- [x] D3 regla de prompt para capas
- [x] A1 barra lateral = editor manual
- [x] A2 migas de ámbito (cierra el bug de raíz)
- [x] A3 chat flotante
- [x] C1 fuente única de imágenes para la IA
- [x] C2 chat: propias antes que Unsplash
- [x] C3 generación: propias en el reparto
- [x] C4 descripción de fotos propias (automática al subir)
- [x] C5 modal de imágenes: propias primero
- [x] B1 memoria de conversación
- [x] B2 marcar elemento seleccionado
- [x] B3 ver qué ha cambiado
- [x] B4 espera y errores honestos
- [ ] B5 (opcional) preguntas sin editar
- [ ] B6 (opcional) atajos deterministas

## Current Status / Progress Tracking (STUDIO-2)

**Entrega 1 (29/07/2026) — D1+D2+D3+A2: el bug del fondo, cerrado por los dos lados.**

- **D1** `resolveBgTarget(sec)` en el overlay del preview (`CanvasController::overlayScript`): el fondo puede vivir en la sección, en un envoltorio interior o en un `<img>` de cobertura. `hasBgImage`, `bgdim`, `bgimg mark/remove` y `replace-image` operan sobre el elemento que lo lleva de verdad. Verificado en la página 774, cuyo fondo está en `.fgl-hero__frame` (un div interior): antes el panel decía "Poner imagen de fondo" y cualquier cambio iba a la sección, DETRÁS del frame — invisible.
- **D2** `bgLayers()` lee el `background-image` del estilo COMPUTADO y separa capas respetando paréntesis (`splitLayers`). Al reemplazar la foto se conservan todas las capas que no son `url(...)`. Verificado: velo blanco 0.6 intacto tras cambiar la foto.
- **D2b (hallazgo colateral, bug real preexistente)** — `bgdim` sobre un fondo CSS reescribía la URL leída del computado, que es ABSOLUTA. `CanvasSanitizer::scrubCssDeclarations` solo acepta rutas `/` o `https://`, así que convertía esa URL en `none`: **atenuar el fondo borraba la foto**. Añadido `siteUrl()`, que devuelve las URLs del propio sitio a ruta relativa antes de escribirlas. Verificado: ahora guarda `/storage/uploads/...`.
- **A2** Migas de ámbito `Página › Sección › Bloque › Elemento` en la cabecera del panel, clicables, con `Esc` para subir un nivel. El iframe manda la cadena de ancestros con `element-selected` y atiende `select-scope`. Verificado en la página 637 (el caso exacto del usuario, capa blanca envolviendo el contenido): clic dentro de la caja → panel "Bloque" (sin controles de fondo) → clic en "Hero" → "Imagen de fondo · Cambiar/Quitar" accesible. `Esc` recorre Texto → Bloque → Hero → cerrado. Ida y vuelta por las migas conserva el marcado del elemento (imagen/caja).
- **D3** Regla en `EDIT_CANVAS_SECTION` y `EDIT_CANVAS_PAGE`: las capas/velos se aplican como capa extra del `background-image` del mismo elemento (o `::before`), nunca quitando la capa `url(...)` ni envolviendo el contenido en una caja opaca.

Verificación: navegador real sobre las páginas 637, 774 y 135 (secciones con `<img>`); sin errores de consola; las páginas de prueba quedaron restauradas con `undo` (774 de vuelta en la versión 420, sin estilos inline). Suites en verde: `canvas_runtime` (49), `canvas_runtime_markup`, `canvas_box_editor`, `canvas_edit_envelope`, `canvas_settings`.

**Entrega 2 (29/07/2026) — A1+A3: barra lateral de edición manual + chat flotante.**

- **A1** `views/admin/canvas/studio.php`: el `aside` pasa de `cvstudio-chat` a `cvstudio-side` y contiene solo edición manual. Dos estados excluyentes: `#edit-panel` (controles del elemento seleccionado, ahora a toda la altura de la barra) y `#side-empty` (sin selección). El estado vacío no está vacío: explica en una frase cómo se edita, lista **"Partes de esta página"** numeradas —clic para ir a esa parte y abrir su panel, ratón por encima para resaltarla en la página— y agrupa **"Añadir a la página"**, donde se ha movido "+ Formulario" (era una acción manual metida en el composer del chat).
- **A3** El chat vive en `#chat-dock`, flotando sobre el lienzo abajo a la derecha. Plegado es una pastilla; desplegado, panel de conversación con su composer. Estado recordado en `localStorage` (`pp-studio-chat-open`), abierto la primera vez. La pastilla es informativa: dice "Cambiar «Hero»" cuando hay una parte seleccionada y "Aplicando el cambio…" mientras trabaja, y marca un punto si llega respuesta con el chat plegado. Insertar un formulario despliega el chat, que es donde se cuenta el progreso.
- Responsive: por debajo de 1100 px la barra se superpone al lienzo (no lo estruja) y el dock se aparta para no quedar debajo.
- Nuevos mensajes al iframe: `select` con `panel:true` (abre el panel de esa sección) y `highlight` (resalte al pasar el ratón por la lista).

Verificación en navegador: plegar/desplegar con persistencia tras recarga; lista de partes navegable con resalte y marca de la parte actual; panel y ayuda nunca visibles a la vez; a 1024 px ni la pastilla ni el panel quedan bajo la barra; modal de imágenes por encima de todo (z-index 50); y **una petición real de chat de principio a fin** desde el dock ("pon el fondo de esta parte en negro" sobre "Frase inspiracional", 2 s, con su Deshacer) — deshecha después, la página 637 vuelve a su versión "Generación inicial". Sin errores de consola. Suites en verde: canvas_runtime (49), canvas_runtime_markup (16), canvas_box_editor (4), canvas_edit_envelope (10), canvas_image_requests (9), canvas_settings (4), canvas_cross_page_reference (10).

**Entrega 3 (29/07/2026) — C1..C5: las fotos del negocio son la primera opción.**

- **C1** `app/Services/MediaLibraryService.php` (nuevo): fuente única para chat y generación. `images()` ordena `(source='upload') DESC, id DESC`; `forAi()` devuelve el bloque de prompt separado en dos — "FOTOS PROPIAS DEL NEGOCIO (PRIORITARIAS…)" y "BANCO DE IMÁGENES (solo si NINGUNA foto propia encaja)" — con descripción y orientación calculada (horizontal/vertical/cuadrada). Los logos de marca (`/brand/`) quedan fuera: se ofrecen aparte por `BrandService` y no son fotos de contenido.
- **C2** `CanvasChatService::applyInstruction()` ya NO llama a Unsplash por defecto: solo si el sitio no tiene fotos propias o si el usuario lo pide con palabras explícitas (`requestsImageBank()`: unsplash / banco de imágenes / de internet / stock). Además inyecta una directiva de prioridad en la instrucción cuando hay fotos propias. `availableImages()` pasa a delegar en C1.
- **C3** `resolvePlanImages()` reparte primero las fotos propias casando el brief con la descripción y el nombre de archivo (`MediaLibraryService::bestMatch()`, con desempate por orientación y sin repetir); Unsplash solo cubre lo que falte. `genericBusinessImages()` arranca con hasta 6 fotos propias y solo completa con banco si no llega a 4.
- **C4** Acción de visión `DESCRIBE_IMAGE` (tier LIGHT, JSON `{alt}`) + `describeAfterResponse()`: al subir una imagen sin texto alternativo se describe con IA DESPUÉS de responder al navegador (`register_shutdown_function` + `fastcgi_finish_request` cuando existe), mismo patrón que el procesado de documentos del onboarding. Nunca pisa un alt escrito por el usuario.
- **C5** Selector de imágenes del Studio: chips "Tus fotos / De banco / Todas" (abre siempre en "Tus fotos"), distintivo "Tu foto" sobre las propias en la vista mezclada, y el filtro se oculta en la pestaña de Unsplash. El endpoint `/admin/media/library` devuelve `source` y ordena propias primero.

Verificación:
- `tests/media_library_priority.php` (nuevo, 11 comprobaciones): orden propias-primero, `ownOnly`, formato del bloque de prompt, caída al nombre de archivo cuando no hay alt, orientación, y `bestMatch` (acierto, respeto de usadas, null sin coincidencia). Inserta y borra sus propias filas.
- `tests/canvas_image_requests.php` ampliado con 4 casos de `requestsImageBank` (13 PASS).
- **C4 real por HTTP**: subida de un PNG desde el navegador → respuesta en **1,32 s** con `alt_text` vacío y, segundos después, en BD "Fondo azul con la palabra \"AULA\" escrita en letras blancas centradas". La descripción NO hace esperar a la subida.
- **C2 real**: "pon una foto de fondo en esta sección" sobre la página 637 → **0 descargas nuevas de Unsplash** (33 filas en `media` antes y después) y el log de `ai_logs` confirma el prompt con el bloque de propias delante y la directiva de prioridad. El modelo eligió una foto ya existente de la biblioteca en vez de la única foto "propia" del entorno dev, que es una imagen de prueba con la palabra TEST — comportamiento correcto: la prioridad no obliga a usar una foto que no pega.
- Todo restaurado: página 637 en su versión 361 y `media` con las mismas 33 filas del principio.
- Suites en verde: canvas_runtime (49), canvas_runtime_markup (16), canvas_box_editor (4), canvas_edit_envelope (10), canvas_image_requests (13), canvas_settings (4), canvas_cross_page_reference (10), canvas_cancel (11), media_library_priority (11).

**Entrega 4 (29/07/2026) — B1..B4: el chat entiende el contexto y dice la verdad.**

- **B1 memoria de conversación.** El Studio guarda los últimos turnos (`chatHistory`) y manda los 4 más recientes; `CanvasController::parseChatHistory()` los valida y acota (4 turnos, 300 caracteres por campo) y `CanvasChatService::historyBlock()` los pone DELANTE de la petición, etiquetando la actual ("PETICIÓN ACTUAL (la única que debes aplicar)") y avisando de que los turnos anteriores son contexto, no pendientes.
- **B2 el elemento seleccionado se marca en el HTML.** El overlay calcula el camino del nodo dentro de su sección como índices de hijos (`pathWithinSection` → "0.1") y `CanvasChatService::markTarget()` le pone `data-pp-target="1"` con DOMDocument antes de mandar la sección al modelo. La descripción en prosa se conserva como apoyo. `stripTargetMarks()` limpia la marca del resultado por si el modelo no la quita.
- **B3 ver qué ha cambiado.** La respuesta del chat trae `changed_section`; al recargar el preview, el Studio manda `flash` al iframe, que lleva la vista a esa parte y le da un destello (`@keyframes pp-studio-flash`, respetando `prefers-reduced-motion`).
- **B4 espera y errores honestos.** El mensaje "Aplicando el cambio…" cuenta segundos y, al pasar de 15 s y de 45 s, explica por qué tarda y recuerda "Parar". Los errores salen por CAUSA vía `CanvasController::chatErrorMessage()` (timeout / respuesta cortada / 401 / 429 / 5xx), con la salida sugerida distinta según si el cambio era de una sección o de la página entera. Y `CanvasChatService::runEdit()` reintenta UNA vez cuando el sobre `<pp-html>/<pp-css>` llega incompleto, avisando al modelo de lo que faltó.

Verificación:
- `tests/canvas_chat_context.php` (nuevo, 30 comprobaciones): marcado por camino (nodo correcto entre hermanos iguales, enlaces, caminos fuera de rango/vacíos/basura que NO marcan), limpieza de marcas, parseo y acotado de la memoria, orden y etiquetado del bloque, y los seis caminos del mensaje de error.
- **Conversación real de dos turnos en navegador** (lo que prueba B1 y B2 juntos): "pon este titular en color rojo" → rojo; después **"ahora hazlo un poco más grande"**, sin decir qué, y el titular pasó de 32 px a 40 px **conservando el rojo**. El log confirma el bloque de memoria con el turno anterior y el `<h1 ... data-pp-target="1">` marcado; el HTML guardado quedó sin la marca.
- B3 verificado: cambio sobre "Proceso por pasos" → el destello apareció en esa sección y no en otra.
- B4 verificado: en una petición de 2 s el mensaje pasó de "Aplicando el cambio…" a "Aplicando el cambio… 1 s".
- Página 637 restaurada a su versión 361 (sin estilos inline ni CSS de chat). Sin errores de consola.
- **168 comprobaciones en verde** en 11 suites de canvas/medios.

## Executor's Feedback or Assistance Requests (STUDIO-2)

- **B5 y B6 quedan sin hacer, a propósito** (estaban marcadas como opcionales): B5 (responder preguntas sin editar) y B6 (atajos deterministas sin IA). B6 es la que más cuidado pide: un falso positivo aplicaría un cambio que el usuario no pidió, así que solo merece la pena con un conjunto muy pequeño de frases y el "Deshacer" a la vista.
- **Pendiente de decisión del usuario**: en el entorno dev no hay fotos propias reales (solo una imagen de prueba y los logos), así que la prioridad no se puede ver "en bonito" hasta que un sitio real suba material. Para probarlo de verdad conviene subir 3-4 fotos del negocio y regenerar una página.
- Las imágenes ya subidas ANTES de este cambio siguen sin descripción: la automática solo actúa en subidas nuevas. Si interesa, un comando/botón "describir las que faltan" es media hora de trabajo (reutiliza `describeNow()`).
- `tests/canvas_image_requests.php` **llevaba roto desde FEAT-5** (reflexionaba sobre `CanvasController::requestsImages`, método que se movió a `CanvasChatService`): moría con exit 255 y SIN imprimir nada, así que pasaba por "sin fallos". Corregido el import y las dos reflexiones; 9/9 PASS. Cubre justo la detección de peticiones de imagen que toca la Fase 3.
- Pendiente de la Fase 2: la captura de la entrega deja claro que con el panel de sección abierto el chat queda reducido a una franja — es exactamente el problema que A1+A3 resuelven.

## Decisiones cerradas (29/07/2026, usuario)

1. **Chat flotante** = pastilla abajo a la derecha que se despliega en panel; recuerda plegado/desplegado y se despliega sola mientras hay una petición en curso. (A3)
2. **Descripción de imágenes** = automática al subir con el modelo ligero cuando la imagen llega sin `alt_text`, siempre editable a mano. (C4)
3. **Unsplash en generación** = relleno: primero todas las fotos propias que encajen, banco sólo para completar lo que falte. (C3)

### Lessons (STUDIO-2)

- `getComputedStyle(el).backgroundImage` devuelve URLs ABSOLUTAS. Escribirlas de vuelta en un estilo inline hace que `CanvasSanitizer` las convierta en `none` (solo admite rutas `/` o `https://`) y la imagen desaparece al guardar. Pasar siempre por `siteUrl()` antes de reescribir un fondo leído del computado.
- Al depurar el Studio, el iframe de preview se carga sin `?t=` en la primera pintada: para ver código nuevo del overlay hay que forzar `iframe.src = '...preview?t=' + Date.now()`, si no se depura contra la versión cacheada.
- Un `click` sintético sobre un texto NO reproduce el flujo real del usuario: la edición inline entra por `mousedown`. Para verificar el panel de "Texto" hay que despachar `mousedown`.
- Un test que muere por `ReflectionException` sale con 255 y **sin imprimir nada**: parece que "no falla". Al mover métodos entre clases, revisar quién los reflexiona (`grep ReflectionMethod`).
- Se repitió la lección del `[hidden]`: `#side-empty` es `display:flex`, así que `hidden` no lo ocultaba y panel + ayuda se pintaban a la vez. Al crear cualquier contenedor flex/grid que se vaya a ocultar por atributo, añadir la regla `[hidden]{display:none}` EN EL MISMO commit.
- Al superponer paneles en el Studio hay que revisar el z-index de los tres actores: modal (50) > barra lateral superpuesta (45) > chat flotante (40). El dock quedaba debajo de la barra en pantallas estrechas.
- `intval('')` es `0`: convertir antes de validar el formato hacía que un camino de elemento vacío o con basura marcase el PRIMER hijo de la sección, es decir, la IA cambiando el elemento equivocado con toda confianza. Validar el formato (regex) y luego convertir.
- Muestrear la UI cada 250 ms desde `javascript_tool` no garantiza 4 muestras por segundo (los timers se estrangulan): una comprobación de "esto no se actualiza" puede ser un artefacto del muestreo. Confirmar leyendo el nodo concreto (`textContent`) antes de dar por roto el código.
- Filtrar filas DESPUÉS del `LIMIT` es una trampa: `images($siteId, 1, true)` con el filtro de logos en PHP devolvía vacío cuando la única fila del LIMIT era un logo, y `hasOwnImages()` mentía. El filtro tiene que ir en el WHERE.
- `Response::json()` hace `exit`, así que un `register_shutdown_function` registrado antes SÍ se ejecuta después de enviar el cuerpo: sirve para trabajo post-respuesta (descripción con IA) sin hacer esperar al navegador. Medido: subida en 1,32 s con la descripción llegando después.
- Detectar el fondo de una sección por geometría (`getBoundingClientRect`) es frágil si se mide antes del layout; en la práctica funciona porque se calcula al seleccionar, ya con la página pintada, pero conviene no moverlo a la carga.

---

# [C3-FIX] La generación seguía bajando fotos de Unsplash con 9 propias — 30/07/2026 (Executor)

## Lo que reportó el usuario

Página nueva desde /admin/pages con IA, desde referencia visual + documento ya
subido. El sitio tenía **9 imágenes en Medios, todas con descripción**, y la
página salió con **2 fotos descargadas de Unsplash**.

## Causas (tres, encadenadas)

1. **`COMPOSE_CANVAS_PAGE` no recibe `available_images`.** La única vía por la
   que una foto llega a la generación canvas es dentro de `sections_outline`.
2. **Con referencia visual, el pool de fotos propias no se enviaba.** El bloque
   de pool estaba dentro de `if (!$hasRefs)`, así que en el flujo "desde
   referencia" el modelo **nunca veía** las 9 fotos: solo lo que nosotros le
   hubiéramos asignado sección a sección.
3. **El emparejado por sección era léxico.** `bestMatch()` exigía una palabra
   compartida entre el brief y la descripción. Verificado con briefs y
   descripciones realistas: "estudiantes en un aula preparando oposiciones" no
   empareja con "Grupo de alumnos en clase…" (sinónimos), y "equipo docente del
   centro" emparejaba con la foto de la **fachada** por compartir "centro" —
   una asignación equivocada, que es peor que ninguna porque el modelo la da por
   buena. Los briefs sin pareja caían directos a Unsplash **teniendo 6 fotos
   propias sin usar**: exactamente las 2 descargas del usuario.

## Arreglo

- El pool de fotos propias **va siempre** al outline (con referencia también),
  etiquetado como prioritario, con "cada foto una vez" y "entre una propia que
  encaje y una de banco, la propia". Las secciones sin foto asignada dejan de
  decir "diséñala sin foto" y pasan a "elige la que mejor encaje del POOL".
- **Presupuesto de banco**: se cuenta cuánta foto pide la página y cuántas
  propias hay; si las propias cubren, a Unsplash **no se le llama**. Solo se
  descarga el déficit.
- `bestMatch()` acepta `minScore`; la generación exige **2 palabras** en común
  para asignar. Con menos, la sección se queda sin asignar y decide el modelo,
  que sí entiende de significado. La orientación pasa a desempatar (+0,5) sin
  poder alcanzar el umbral por sí sola.
- Las bandas `theme: image` ya no se degradan a `dark` si queda pool: antes se
  perdía la banda de foto por no tener asignación nuestra.

## Verificación

- `tests/canvas_generation_own_images.php` (nuevo, 7 comprobaciones): con 9
  fotos propias descritas y 5 briefs con sinónimos, **0 descargas de banco** y
  ninguna imagen asignada de banco; el umbral 2 no empareja por casualidad
  (documentando que con 1 sí lo hacía); las coincidencias claras siguen
  asignándose; las bandas de foto se conservan.
- **Generación real end-to-end** en dev por el mismo camino del usuario
  (referencia visual guardada + 9 fotos propias): el prompt llevó **las 9 fotos
  en el POOL** y 6 secciones con "elige del POOL"; **0 descargas de Unsplash**
  (antes 2); la página resultante usó **2 fotos propias y 0 de banco**. Página y
  filas de prueba borradas: `media` vuelve a 33 filas.
- 15 suites, 206 comprobaciones en verde.

### Lessons (C3-FIX)

- Emparejar imágenes con briefs comparando palabras es una idea mala en dos
  direcciones: **falla por sinónimos** y **acierta por casualidad** (una palabra
  incidental compartida). Si hay un modelo en el bucle que entiende el
  significado, el trabajo del código es ponerle delante el material completo y
  bien descrito, no decidir por él.
- Al probar un matcher con datos sintéticos, usar las MISMAS claves que lee el
  código (`alt_text`, no `alt`): mi primera demostración "probaba" que fallaban
  5 de 5 briefs porque solo comparaba contra el nombre del archivo. Casi
  diagnostiqué la causa equivocada.
- Un `if (!$hasRefs)` alrededor de un bloque de contexto es una bomba de
  relojería: el flujo "con referencia" es el principal del producto y se queda
  sin ese contexto sin que nada lo señale.

---

# [ONB2] Paso 2 del onboarding: identidad visual (paleta IA, logos, tipografías, ancho) — PLAN (30/07/2026, Planner)

## Background and Motivation (ONB2)

El paso 2 («Dale dirección visual a la IA») es donde el usuario entrega su marca,
y hoy se queda corto en cuatro puntos que el usuario ha señalado:

1. **Logo:** solo un archivo, cuando el motor YA soporta dos versiones
   (fondo claro / fondo oscuro + cuál es la principal) desde [UX4] U1.1.
2. **Color:** se elige UN color principal con un picker pobre, y a partir de él
   se «adapta» uno de los 8 presets curados. El usuario quiere lo contrario:
   entregar **su paleta de marca** y que la IA derive la paleta de la web
   (fondos, superficies, texto, texto secundario, líneas, acentos) cuidando
   tonos y contrastes.
3. **Color de texto:** no debería ser un campo suelto; es una decisión de la paleta.
4. **Tipografías:** el paso admite UNA familia propia, aunque el modelo de datos
   ya distingue títulos y textos.

Y de fondo: la tarjeta del onboarding mide 720 px fijos, así que el paso 2 —el
único con dos columnas— reparte ~390 px de campos + 290 px de preview. Todo va
apretado.

## Key Challenges and Analysis (ONB2)

### Lo que ya existe (no hay que inventarlo)

- **Logos:** `site_logo_path`, `site_logo_dark_path`, `site_logo_primary` y
  `BrandService::LOGO_VARIANTS` / `logoPathFor()` / `logoUrl()`. La pantalla de
  Diseño ya tiene los dos slots. El onboarding solo escribe el claro
  ([OnboardingController::saveLogo](app/Controllers/Admin/OnboardingController.php:1098)).
- **Tipografías propias:** `CustomFontService` maneja familias con rol
  (`heading` / `body` / `both`), varios archivos por peso y `@font-face`. El
  onboarding solo crea UNA familia
  ([saveCustomFonts](app/Controllers/Admin/OnboardingController.php:2434)).
- **Tokens de color:** `DesignSystem` ya declara los 9 tokens que necesita una
  paleta completa (primary, primary_dark, secondary, accent, bg, surface, text,
  text_muted, border). No hace falta almacenamiento nuevo para el sitio clásico.
- **Acciones de IA:** `Actions` + `AIActionRunner` con `output: json` y tier
  ligero es el patrón (ver `EXTRACT_BUSINESS_PROFILE`).

### El nudo real: dónde vive la paleta

Hoy el motor resuelve el color así
([VisualStyleService::paletteForSite](app/Services/VisualStyleService.php:293)):
override explícito → **preset persistido** (`site_palette_preset`) → generación
automática desde el `primary`. `PalettePresets` es un catálogo **estático de 8
slugs**: no admite una paleta a medida, porque `tokens()` y `get()` no reciben
`siteId`.

Decisión (usuario, 30/07/2026): **en el paso 2 la IA sustituye a los presets** —
el usuario ya no elige entre «Studio mono» y «Crema y tinta». Pero el catálogo
NO se borra: sigue siendo el fallback del motor y lo declaran las plantillas
(`PageTemplateService`, `palette_preset`). Por tanto:

- Paleta a medida → setting nuevo `site_palette_custom` (JSON con los 8 tokens),
  leído **antes** del preset en `paletteForSite()`.
- Además se vuelca a los tokens de `DesignSystem`, para que bloques clásicos,
  panel y Canvas cuenten lo mismo. Es la misma lección de `sites.language` vs
  `site_languages`: si dos fuentes guardan el dato, se escriben en el mismo sitio.
- Un sitio sin `site_palette_custom` se comporta **exactamente** como hoy.

### El contraste no se le puede dejar al modelo

Pedirle «cuida los contrastes» a un LLM produce paletas bonitas que fallan AA en
el texto secundario y en el texto sobre el botón. La generación tiene que pasar
por un **validador WCAG en PHP** que sea el que manda: ratio texto/fondo ≥ 4,5;
muted/fondo ≥ 4,5; línea/fondo ≥ 1,5; y el color del texto del botón (blanco o
negro) lo elige el validador contra el acento, no el modelo. Si una propuesta no
cumple, se corrige por HSL (subir/bajar luminosidad en pasos) antes de enseñarla;
si no converge en N pasos, se descarta esa propuesta. Esto además hace el
resultado testeable sin llamar a la IA.

### Extraer colores del logo: sin IA

Cuantizar los píxeles dominantes de un PNG/JPG/WebP con GD es determinista,
instantáneo y gratis; mandar la imagen a un modelo de visión no aporta nada aquí.
Para SVG, leer `fill` / `stop-color` del XML. Es un atajo para el usuario que no
tiene su manual de marca a mano, no la vía principal.

### Ancho y composición

`.pp-onboarding-card` es `min(720px, …)` para los 5 pasos. Ensanchar todos
estropearía los pasos de una sola columna (líneas de texto demasiado largas). Va
un modificador aplicado **solo al paso 2**: ~1120 px, campos a `minmax(0,1fr)` y
preview sticky de ~340 px, con los campos cortos (esquinas, tipografía) en
subrejilla de dos columnas. El paso pasa a leerse en cuatro bloques: **Marca**
(nombre + logos) · **Inspiración** (referencias) · **Color** (paleta de marca →
paleta generada) · **Tipografía y forma**.

### Riesgo de alcance

El paso 2 alimenta al `SkinComposer` del paso 5 y a toda la generación. Cambiar
qué se guarda ahí toca la creación de la web entera; por eso O2.6 (persistencia)
lleva una prueba de regresión explícita del camino «sitio sin paleta a medida».

## High-level Task Breakdown (ONB2)

**O2.1 — Contenedor ancho y recomposición del paso 2.**
Modificador de tarjeta ancha aplicado solo a `step === 2`; rejilla campos +
preview sticky; agrupación en los cuatro bloques. Solo CSS de `admin.css` + el
marcado de la vista.
*Criterio:* a 1440 px, columna de campos ≥ 620 px y preview ≥ 320 px; a 900 px
cae a una columna con el preview debajo; a 375 px no hay scroll horizontal; los
pasos 1, 3, 4 y 5 mantienen su ancho actual (comprobado a ojo y por CSS).

**O2.2 — Logo en dos versiones + cuál es la principal.**
`saveLogo()` parametrizado por variante (`logo_light`, `logo_dark`), dos zonas de
subida con las etiquetas de `BrandService::LOGO_VARIANTS` y un selector de
principal. El preview del paso usa la variante que toca.
*Criterio:* subir las dos y marcar la oscura como principal deja `site_logo_path`,
`site_logo_dark_path` y `site_logo_primary` correctos, `/admin/design` las muestra
igual, y la cabecera pública pinta la principal. Con una sola versión, el
comportamiento es el de hoy.

**O2.3 — Picker de color propio (sin librerías).**
Componente `pp-color-picker` en `admin/assets/js/` + estilos en `admin.css`:
área saturación/luminosidad, slider de tono, campo HEX validado en vivo,
cuentagotas si el navegador trae `EyeDropper`, y colores recientes del sitio.
Manejable con teclado.
*Criterio:* arrastrar en el área actualiza HEX y preview en vivo; escribir un HEX
inválido no rompe el formulario ni pierde el valor anterior; en un navegador sin
`EyeDropper` el botón no aparece (no falla); Tab + flechas permiten elegir color.

**O2.4 — Paleta de marca del usuario (entrada) + extracción del logo.**
Lista de hasta 5 colores (añadir / quitar / reordenar por rol simple: principal,
secundario, acentos), cada uno con el picker de O2.3, persistida en
`site_brand_palette` (JSON). Botón «Extraer del logo» →
`POST /admin/onboarding/extract-logo-colors`, cuantización con GD (XML para SVG).
*Criterio:* con un logo de 3 colores planos, el botón devuelve esos 3 en menos de
2 s y sin llamar a la IA (verificado en `ai_logs`: sin fila nueva); sin logo
subido, mensaje claro en vez de error; los colores sobreviven a recargar el paso.

**O2.5 — Generación IA de la paleta del sitio + validador de contraste.**
Acción nueva `GENERATE_SITE_PALETTE` (JSON, tier ligero) que recibe la paleta de
marca, la descripción/sector del negocio (memoria del paso 1) y, si hay,
la dirección visual de las referencias; devuelve **3 propuestas** con los 8
tokens, un nombre y una frase de justificación. Endpoint
`POST /admin/onboarding/generate-palette`. El validador WCAG descrito arriba
corrige o descarta antes de mostrar. Las tarjetas de propuesta sustituyen a la
rejilla de presets y el campo «Color de texto» desaparece.
*Criterio:* test con una respuesta de modelo **deliberadamente mala** (texto gris
claro sobre fondo blanco) → la paleta que llega a la vista cumple AA en los
cuatro pares comprobados; una generación real produce 3 propuestas distintas
entre sí y coherentes con los colores de marca.

**O2.6 — Persistir y consumir la paleta elegida.**
`site_palette_custom` (JSON) leído en `paletteForSite()` antes del preset +
volcado a los tokens de `DesignSystem`. Un único camino de escritura.
*Criterio:* elegida una paleta, la home generada, el preview del paso 5 y
`/admin/design` muestran los mismos colores; y un sitio SIN `site_palette_custom`
renderiza byte a byte lo mismo que antes del cambio (comparación de la CSS
generada, no impresión visual).

**O2.7 — Dos tipografías propias: títulos y textos.**
Dos zonas de subida con rol fijo (`heading`, `body`) + casilla «usar la misma
para ambas» (rol `both`, que es el comportamiento de hoy). Reutiliza
`ensureFamily` / `addFile` / `assignRole`.
*Criterio:* subir una serif para títulos y una sans para textos crea DOS familias
con sus roles en `/admin/design#fonts`, y el HTML público aplica cada una donde
toca (`--pp-font-heading` / `--pp-font-body`).

**O2.8 — Regresión y pruebas.**
`tests/onboarding_step2.php` nuevo (paleta: validador de contraste, extracción de
color del logo, guardado y lectura de `site_palette_custom`, dos familias de
fuente, dos logos) + suite completa en verde.
*Criterio:* todas las suites pasan y la nueva cubre los cinco puntos, sin dejar
filas ni archivos de prueba.

Orden propuesto: **O2.1 → O2.3 → O2.4 → O2.5 → O2.6 → O2.2 → O2.7 → O2.8**
(el layout primero para no maquetar dos veces; el picker antes que la paleta
porque esta lo usa; los logos y las fuentes al final porque son independientes).

## Project Status Board (ONB2)

- [x] O2.1 — Contenedor ancho y recomposición del paso 2
- [x] O2.3 — Picker de color propio
- [x] O2.4 — Paleta de marca + extracción del logo
- [x] O2.5 — Generación IA de la paleta + validador de contraste
- [x] O2.6 — Persistencia y consumo de la paleta
- [x] O2.2 — Logo claro/oscuro + principal en el onboarding
- [x] O2.7 — Dos tipografías propias (títulos / textos)
- [x] O2.8 — Tests y regresión

## Decisiones cerradas (30/07/2026, usuario)

1. La paleta de marca se entrega como **lista de HEX** + botón para **extraerla
   del logo**. No se sube el manual de marca como imagen.
2. En el paso 2, **la paleta generada por IA sustituye a los 8 presets curados**.
   El catálogo sigue existiendo por debajo (fallback del motor y plantillas).
3. El picker de color se construye **a medida, sin librerías externas**.

## Current Status / Progress Tracking (ONB2)

**O2.1 — Contenedor ancho y recomposición del paso 2 · HECHO (30/07/2026)**

- `.pp-onboarding-card--wide` (1120px) aplicado SOLO cuando `$step === 2`; el
  resto de pasos sigue en 720px.
- Rejilla del paso: campos `minmax(0,1fr)` + preview de 340px pegajoso, y el
  contenido agrupado en cuatro bloques (`.pp-onboarding-block`): Tu marca ·
  Inspiración · Color · Tipografía y forma. El control de esquinas se mueve al
  lado del selector de tipografía para que el bloque final vaya a dos columnas.
- **Dos bugs preexistentes destapados por el ancho** (el campo HEX del color):
  `.pp-onboarding-swatches input{position:absolute;opacity:0}` —hecho para los
  radios de los swatches— también cogía al campo HEX, dejándolo INVISIBLE y
  anclado al bloque inicial, así que su `width:100%` era el ancho del viewport;
  y `.pp-onboarding-swatches>div{display:flex}` le ganaba a su propia rejilla.
  Con la tarjeta de 720px el desastre cabía dentro de la ventana y no se veía;
  con 1120px la página empezó a desbordar. Arreglado acotando la primera regla a
  `label input` y subiendo la especificidad de la fila HEX.
- Verificado en navegador (servidor de dev, sesión admin real): a 1280px →
  tarjeta 1120, campos 686, preview 340, campo HEX 170px, visible y sin
  desbordamiento; a 900px → una columna con el preview debajo; a 375px → sin
  scroll horizontal; paso 1 sigue en 720px. `POST /admin/onboarding/step/2`
  responde 302 al paso 3, o sea el formulario sigue guardando tras el troceado.

**O2.3 — Picker de color propio · HECHO (30/07/2026)**

- `admin/assets/js/color-picker.js` (nuevo, sin dependencias) + estilos `.pp-cp*`
  en `admin.css`. Se monta sobre cualquier `input[data-pp-color]`: muestra de
  color que abre un panel con área saturación/luminosidad, tono, cuentagotas
  (solo si el navegador trae `EyeDropper`) y colores recientes (localStorage).
  Teclado: flechas sobre el área, Escape cierra, foco visible.
- El `input type="color"` nativo ("Libre") desaparece del paso 2; el campo HEX
  sigue existiendo y ahora es el que lleva el picker.
- Cada cambio escribe en el input y dispara `input`/`change`, así que el preview
  del paso sigue funcionando sin tocarlo.

Tres trampas que costaron una vuelta cada una (y que el componente ya evita):

1. **Bucle de eventos.** `commit()` dispara `input` y el propio campo escucha
   `input` → recursión infinita. Hay una bandera `emitting` que corta el ciclo,
   y así el mismo listener sirve para lo que teclea el usuario y para los
   cambios externos.
2. **`focus()` desplaza la página.** Al enfocar el área antes de leer su
   rectángulo, el scroll movía el elemento y el color salía desplazado (o negro
   del todo). `focus({preventScroll:true})` y la lectura después.
3. **El CSS de la página anfitriona se cuela.** El control de tono iba dentro de
   un `<label>` y `.pp-onboarding-swatches label input{position:absolute;opacity:0}`
   lo dejaba invisible. Ahora el panel no usa `label` y sus reglas van con
   especificidad propia (`.pp-cp .pp-cp__hue input`).

- `syncHex()` de `onboarding.js` avisa al picker (`ppColorPicker.sync()`) en vez
  de escribir `.value` a pelo: si no, al pulsar un swatch la muestra del picker
  se quedaba con el color anterior. `sync()` NO dispara eventos a propósito —
  hacerlo desmarcaba el swatch que el usuario acababa de pulsar.
- Verificado en navegador con la secuencia completa: abrir panel → elegir en el
  área (#ea580c → #bf5b26 con s/v correctos) → arrastrar → tono 210 (#2673bf,
  conserva s/v) → flecha derecha (sube saturación) → escribir "zzz" (no rompe;
  al salir del campo restaura) → escribir "#123456" (se aplica y el preview lo
  sigue) → swatch verde (picker y preview se actualizan y el radio SIGUE
  marcado) → Escape cierra. Reciente guardado tras soltar el ratón.
- **Sobre las capturas:** el panel del navegador de esta sesión devolvía
  fotogramas desfasados tras hacer scroll por JS; las medidas de esta
  verificación salen del DOM (posiciones y estilos calculados), y la captura
  final se hizo con la ventana alta para no tener que desplazarse.

**O2.4 — Paleta de marca del usuario + extracción del logo · HECHO (30/07/2026)**

- `App\Services\BrandColorExtractor` (nuevo): colores dominantes de un logo.
  Rasterizados con GD (se reduce a 72px, se agrupan los píxeles en cubos de 24 y
  el color de cada grupo es la MEDIA real de sus píxeles, no el centro del cubo,
  para que un azul corporativo salga exacto). SVG: se leen `fill`/`stroke`/
  `stop-color` del XML, incluidos los que van dentro de un `style`. Sin IA.
- Regla que costó una iteración: filtrar solo blancos y negros casi puros no
  bastaba. **El logo real del sitio de dev es monocromo** y devolvía cinco grises
  casi idénticos disfrazados de paleta. Ahora manda el color: si hay tonos
  cromáticos se devuelven solo esos; si el logo es monocromo de verdad, se
  devuelve UN neutro (el más oscuro, la tinta de la marca) y punto.
- UI en el bloque Color: lista de hasta 5 colores, cada uno con el picker de
  O2.3, botón «+ Añadir color» (se desactiva al llegar al tope) y «Extraer del
  logo». Guardado en el ajuste `site_brand_palette` (JSON), con deduplicado y
  tope en el servidor — no me fío de que la lista llegue limpia del navegador.
- Endpoint `POST /admin/onboarding/extract-logo-colors`. Mira las dos variantes
  de logo (claro y oscuro) aunque el paso todavía solo suba una: cuando O2.2
  añada la segunda, esto ya la aprovecha.
- Verificado: logo sintético de 3 colores → `#1f4eff, #ff8a3d` en 1-2 ms (el
  negro se descarta, como debe); el mismo logo en SVG → los mismos dos colores;
  fichero inexistente → lista vacía sin error; logo REAL del sitio (monocromo)
  → `#222429`. En el navegador: el botón añade el color y le monta el picker;
  el tope de 5 y el borrado funcionan; tras enviar el paso, el ajuste guarda
  `["#222429","#ff8a3d","#16a34a","#1f4eff"]` (el duplicado que colé a propósito
  se cayó en el servidor) y al recargar el paso vuelven los 4 con su picker.
  **0 llamadas a la IA** en `ai_logs` durante toda la prueba.

**O2.5 + O2.6 — Paleta generada por IA, validada y consumida · HECHO (30/07/2026)**

- `App\Services\BrandPaletteService` (nuevo): sanea, corrige y persiste.
  - **El contraste no se le pide al modelo, se comprueba.** Mínimos: texto y
    texto apagado sobre fondo 4,5:1; texto sobre superficie 4,5:1; línea 1,4:1;
    acento sobre fondo 2,5:1; y el acento tiene que admitir encima una etiqueta
    blanca o negra con 4,5:1 (quién gana lo decide `labelOn()`, no el modelo).
    La corrección mueve SOLO la luminosidad (HSL): el tono es la decisión de
    diseño. Si una propuesta no llega, se descarta entera.
  - Detalle que salió probando: corrigiendo el texto justo hasta 4,5 el texto
    apagado (que exige lo mismo) acababa en el mismo gris — salieron #757575 y
    #747474. Al corregir, el texto apunta a AAA (7:1) y así queda jerarquía.
- Acción de IA `generate_site_palette` (tier ligero, JSON, 3 propuestas con
  nombre y una frase de por qué) + `POST /admin/onboarding/generate-palette`.
- **Red de seguridad:** si la IA falla, el endpoint cae a las recetas curadas de
  `PalettePresets` adaptadas al color de marca, y lo dice en la respuesta. El
  paso nunca se queda sin paletas que ofrecer.
- UI: fuera la rejilla de 8 presets y fuera el campo «Color de texto» (lo decide
  la paleta). Quedan la paleta guardada del sitio + las propuestas, en tarjetas
  elegibles; lo que viaja en el formulario es el JSON de la marcada.
- Persistencia (O2.6): `site_palette_custom` + volcado a los tokens del design
  system (primary, primary_dark, accent, bg, surface, text, text_muted, border,
  secondary). `VisualStyleService::paletteForSite()` la mira ANTES del preset.
  El catálogo de presets sigue vivo como fallback y para las plantillas.
- Efecto colateral arreglado de camino: `saveDesign()` metía `#1c1917` como
  «color de texto» por defecto en cada guardado. Sin el campo en el formulario,
  eso habría pisado el texto de la paleta en cada paso por el step 2; ahora, si
  el formulario no manda color de texto, se conserva el que hubiera.

Verificación (todo end-to-end, servidor de dev):

- **Generación real** con los 4 colores de marca del sitio: 3 paletas distintas
  de verdad (clara sobria, cálida, oscura), con los colores de marca
  reconocibles en los acentos y textos en español sobre el negocio real
  (`google/gemini-3.1-flash-lite-preview`).
- **Paleta mala forzada** (gris claro sobre blanco, botón amarillo pálido): 5
  incumplimientos antes; después de corregir, **ninguno**, y la etiqueta del
  botón resuelta a `#111111`. Una paleta oscura ya correcta sale intacta.
  Una paleta incompleta se rechaza.
- **Elegida la oscura y enviado el paso:** `site_palette_custom` guardado,
  `paletteForSite()` devuelve exactamente esos 8 tokens y los tokens del design
  system quedan sincronizados.
- **Regresión (criterio de O2.6):** borrando la paleta a medida, la salida de
  `paletteForSite()` para los 7 estilos visuales y el hash de la CSS generada
  son **idénticos** al código anterior al cambio (comparado contra la versión
  en git, no a ojo).
- **Fallback sin IA:** con el modelo ligero apuntando a propósito a un modelo
  inexistente, el endpoint responde `ok:true, fallback:true` con 3 paletas de
  catálogo adaptadas al azul de marca y el aviso correspondiente. Modelo
  restaurado después.

**O2.2 + O2.7 — Dos logos y dos tipografías · HECHO (30/07/2026)**

- **Logos:** el paso 2 sube ya las dos variantes (`logo` y `logo_dark`), con el
  radio de cuál manda por defecto, reutilizando `BrandService::LOGO_VARIANTS` y
  las claves que ya usa Diseño. `saveLogo()` pasa a estar parametrizada por
  variante. Dos detalles: el ajuste del media pasa a `{clave}_media_id` (el
  patrón nuevo; `DesignController::deleteLogo` ya limpiaba las dos formas), y
  solo se acepta como principal una variante que exista de verdad — marcar la
  que no está subida dejaría la cabecera sin logo. En el JS, subir una versión
  cuando no hay ninguna marcada la marca sola.
- **Tipografías:** dos huecos con rol fijo (títulos / textos) y casilla «uso la
  misma para ambos», que es el comportamiento antiguo (una familia con rol
  `both`). Reutiliza `ensureFamily`/`addFile`/`assignRole`.
- Verificado con subidas reales (`Montserrat-Bold.otf` para títulos y
  `Kanit-ExtraLight.ttf` para textos, más un logo para fondo oscuro): dos
  familias con sus roles, `logo_primary=dark`, `logoPathFor()` devolviendo la
  correcta para cada fondo, y el HTML público con **dos `@font-face`**,
  `--pp-font-heading`/`--pp-font-body` apuntando a cada familia y el logo para
  fondo oscuro en el marcado.

**Hallazgo importante durante la verificación (y arreglo):** la paleta elegida
NO llegaba a la web pública. `DesignSystem::renderHead()` aplica el `skin_json`
inferido POR ENCIMA de los tokens, así que el skin compuesto en el paso 5 pisaba
el color que el usuario acababa de elegir a mano. Ahora la paleta a medida se
aplica después del skin (`applyCustomPaletteToTokens`), por el mismo criterio
que ya se usaba con las tipografías propias: lo que el usuario decide gana a lo
que nosotros deducimos. La hoja `/design.css` también la aplica.

**O2.8 — Tests y regresión · HECHO (30/07/2026)**

- `tests/onboarding_step2.php` NUEVO: **26 comprobaciones**, sin llamar a la IA
  (extracción de color, validador de contraste, propuestas de reserva,
  persistencia y consumo de la paleta, la paleta ganando al skin, el sitio sin
  paleta comportándose como antes, y dos comprobaciones de contrato: las claves
  de logo que espera el panel y las 8 claves de paleta del catálogo).
- **Regresión completa: 65 suites en verde.** `update_from_zip` no cuenta: se
  niega a ejecutarse fuera de `PP_ENV=development`, como está diseñado.
- `site_languages_model` falló en la primera pasada por **dos páginas de prueba
  que otras suites de la misma tanda dejaron sin `translation_group`** («Inicio
  Canvas» e «Inicio DMB2», creadas minutos antes por `canvas_generate` y
  `dmb2_reference_regen`). No es del cambio: borradas esas dos filas, la suite
  pasa. La base vuelve a **31 páginas**.
- **Estado de dev restaurado**: borradas las dos familias de fuente de prueba,
  el logo oscuro de prueba (archivo + fila en `media` + ajustes), y las paletas
  de prueba; los colores del sitio vuelven a `#ea580c`/`#1c1917`. Verificado
  después: `families()` vacío, `logo_primary=light`, `palette custom: null`.

### Lessons (ONB2)

- **Ensanchar un contenedor es un test de estrés del CSS de dentro.** Los 720px
  tapaban dos bugs del campo HEX (invisible y con el ancho del viewport) que
  llevaban ahí desde siempre; a 1120px la página empezó a desbordar. Si al
  ensanchar algo aparece un desbordamiento, mira primero qué llevaba roto.
- **Un componente propio tiene que defenderse del CSS de la página.** El control
  de tono del picker desaparecía porque el onboarding esconde los `label input`
  (así dibuja sus swatches). Dentro de un componente reutilizable: nada de
  `label` como envoltorio genérico, y sus reglas con especificidad propia.
- **Cuidado con los eventos que uno mismo dispara.** `commit()` emitía `input` y
  el propio campo lo escuchaba: recursión infinita. Y al revés: sincronizar el
  picker disparando eventos desmarcaba el swatch recién pulsado. Dos caminos
  distintos — uno que avisa (`set`) y otro que no (`sync`).
- **Al LLM se le pide el criterio, no la garantía.** El contraste lo comprueba y
  lo corrige el servidor; así además se puede probar sin gastar una llamada.
- **Filtrar "blancos y negros" no es filtrar neutros.** El logo monocromo del
  sitio de dev devolvía cinco grises casi idénticos que parecían una paleta.
- **Un default en un guardado es una escritura silenciosa.** Al quitar el campo
  «color de texto», el `'#1c1917'` por defecto de `saveDesign()` habría pisado
  el texto de la paleta en cada guardado del paso.
- **`renderHead()` tiene un orden de precedencia y hay que respetarlo.** Skin
  inferido → paleta elegida → tipografías propias. Guardar bien un dato no
  significa que llegue a la página: hay que mirar quién lo pisa después.
