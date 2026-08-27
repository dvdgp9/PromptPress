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

---

# [ONB-FOTOS] Subir fotos del negocio en el onboarding (y usarlas en la generación) — PLAN (31/07/2026, Planner)

## Background and Motivation (ONB-FOTOS)

Petición del usuario: poder subir fotos del negocio durante el onboarding, que se
analicen, y que luego se usen al crear la web.

Punto de partida real (importante, porque cambia el tamaño del trabajo): **el
consumo ya está resuelto**. En [C3-FIX] (commit 29d7150) se arregló que las fotos
propias van siempre al outline como pool prioritario, que el presupuesto de banco
se calcula restando las propias, y que sin pareja léxica decide el modelo. Es
decir: si hay filas en `media` con `source = 'upload'` y su `alt_text` descrito,
la generación ya las prefiere sobre Unsplash.

Lo que falta es **el momento**: hoy la única puerta de entrada de fotos propias es
Medios (`/admin/media`), que el usuario descubre DESPUÉS del onboarding — cuando
la home ya se ha generado con fotos de banco. El onboarding pide documentos
(paso 4) y referencias visuales (paso 2, que son inspiración de diseño, no
contenido), pero nunca pide el material fotográfico del negocio.

## Key Challenges and Analysis (ONB-FOTOS)

1. **Fotos propias ≠ referencias visuales.** Son dos cosas que se parecen mucho en
   la UI y significan lo contrario: la referencia (paso 2) es "quiero que se
   parezca a esto" y se guarda en `storage/uploads/{site}/references` como ajuste
   `onboarding_visual_references`; la foto propia es contenido y tiene que acabar
   en la tabla `media` con `source = 'upload'`, que es exactamente lo que filtra
   `MediaLibraryService::images($siteId, N, true)`. Si se guardan mal, la
   generación no las ve. El copy tiene que separarlas sin ambigüedad.

2. **Reutilizar el endpoint que ya existe en vez de escribir otro.**
   `POST /admin/media/upload` (MediaController::upload) ya acepta un archivo por
   petición, valida con `MediaService::validate`, guarda con `MediaService::store`
   (`source = 'upload'`, dimensiones incluidas), **dispara solo la descripción por
   IA** (`describeAfterResponse`) y responde JSON con id/url/alt. Subir desde el
   onboarding con una petición por archivo evita además el problema de
   `post_max_size` que ya nos mordió en `saveReferenceImages` (un POST grande vacía
   `$_POST` y `$_FILES` y el paso "no guarda nada" sin decir por qué).

3. **La descripción tiene que estar lista ANTES de generar.** `describeAfterResponse`
   es fire-and-forget: no hay garantía de que el `alt_text` esté escrito cuando el
   paso 5 componga la home. Sin `alt_text`, `describeRow()` cae al nombre del
   archivo ("IMG_2841 (sin descripción)"), que es justo el material con el que el
   emparejado falla y la sección se va al banco. Hay que hacer la descripción
   **visible y esperable**: el paso ya tiene un endpoint hecho para eso,
   `POST /admin/media/describe-missing` (lotes de 3, devuelve `remaining`,
   distingue fallo de IA de archivo ausente y corta el bucle honestamente).
   El onboarding puede llamarlo en bucle con barra de progreso y dejar el alt
   editable en la miniatura.

4. **Invalidar el borrador de la home.** `saveReferenceImages()` llama a
   `invalidateHomeDraft()` por una razón: si el usuario ya vio una preview y luego
   cambia el material, la preview está mentida. Subir fotos tiene que invalidar
   igual, o el usuario subirá 8 fotos y verá la misma home con Unsplash.

5. **"Que se analicen" tiene dos lecturas.** La que da valor hoy es la descripción
   por IA de cada foto (es lo que consume la generación). La otra —deducir sector,
   paleta o estilo a partir de las fotos— es una función distinta que pisa el paso
   2 (paleta desde logo) y el paso 1 (memoria). Propongo dejarla fuera de esta
   tanda y decidirla aparte con datos.

6. **Cuántas fotos.** `genericBusinessImages()` coge hasta 6 propias y
   `MediaLibraryService::forAi()` hasta 14. Pedir 4–8 en el onboarding es lo
   razonable: cubre una home entera sin banco y no convierte el paso en una tarea.
   Tope duro sugerido: 12 (más solo estorba y alarga la descripción).

7. **El paso tiene que ser saltable y honesto.** Si no hay fotos, la web se genera
   con banco: hay que decirlo en el propio paso, no dejarlo como sorpresa.

## Decisión de encaje (a validar por el usuario)

Recomendación: **ampliar el paso 4** de "Documento" a "Materiales" con dos zonas
(documentos · fotos), en vez de añadir un paso 6. El wizard ya tiene 5 pasos y la
guía UX pide fricción mínima; documentos y fotos son la misma pregunta ("¿qué
material tienes ya?") y el paso 4 es hoy el más ligero de todos.
Alternativa si se quiere darle peso propio: paso nuevo entre 4 y 5.

## High-level Task Breakdown (ONB-FOTOS)

**F1 — Backend: recibir fotos del onboarding**
- Endpoint `POST /admin/onboarding/upload-photos` (o reutilizar directamente
  `/admin/media/upload` desde el JS del onboarding; decidir en F1 tras comprobar
  que el CSRF y el `site_id` de sesión del onboarding sirven tal cual).
- Guardar SIEMPRE vía `MediaService::store()` → `source = 'upload'`.
- Tope 12 fotos, 8 MB, JPG/PNG/WebP; errores por archivo, no por lote.
- Al guardar al menos una: `invalidateHomeDraft($siteId)`.
- *Criterio de éxito:* subida real desde el paso; `MediaLibraryService::images($id, 6, true)`
  devuelve las fotos; `onboarding_home_draft` queda vacío.

**F2 — Backend: descripción con progreso**
- Reutilizar `POST /admin/media/describe-missing` (lotes de 3 + `remaining`).
- *Criterio de éxito:* tras el bucle, `countMissingAlt($siteId) === 0` y cada
  `alt_text` describe la foto en el idioma del sitio.

**F3 — UI del paso**
- Dropzone propia para fotos, separada del dropzone de documentos, con copy que la
  distinga de las referencias visuales del paso 2.
- Rejilla de miniaturas: estado *subiendo → describiendo → descrita*, alt visible y
  editable (`POST /admin/media/{id}/alt`), botón de borrar.
- Estado vacío honesto: "sin fotos usaremos un banco de imágenes".
- CSS en `admin/assets/css/*` (nunca inline).
- *Criterio de éxito:* subir 4 fotos, ver las 4 descripciones aparecer, editar una,
  borrar otra, recargar y que el estado persista.

**F4 — Que la generación las use de verdad (verificación E2E, no supuesto)**
- Completar el onboarding con fotos y comprobar en la generación real de la home:
  las fotos llegan al prompt, las secciones eligen del POOL y las descargas de
  Unsplash bajan (idealmente 0 si cubren).
- Revisar si el tope de 6 en `genericBusinessImages()` se queda corto ahora que
  pedimos hasta 12.
- *Criterio de éxito:* misma evidencia que en [C3-FIX] (conteo de fotos propias
  usadas vs descargas de banco), con el camino que recorre el usuario.

**F5 — Tests**
- Suite nueva `tests/onboarding_photos.php`, sin gastar IA salvo lo imprescindible:
  la foto subida queda como `source = 'upload'` y la ve `images(ownOnly)`; el logo
  sigue excluido; el borrador de la home se invalida; los límites (tipo, tamaño,
  tope) rechazan bien; `countMissingAlt` baja al describir.
- *Criterio de éxito:* suite en verde + regresión completa de suites.

## Project Status Board (ONB-FOTOS)

- [ ] F1 — Backend: subida de fotos desde el onboarding
- [ ] F2 — Descripción por IA con progreso
- [ ] F3 — UI del paso (dropzone + rejilla + alt editable)
- [ ] F4 — Verificación E2E de la generación con fotos propias
- [ ] F5 — Tests + regresión

## Executor's Feedback or Assistance Requests (ONB-FOTOS)

Pendiente de confirmar por el usuario antes de ejecutar:
1. ¿Ampliar el paso 4 a "Materiales" (recomendado) o paso nuevo?
2. ¿Esperar a que terminen las descripciones antes de dejar avanzar (recomendado,
   es lo que hace que la generación las use) o describir en segundo plano?
3. ¿Análisis extra de las fotos (sector/estilo/paleta) dentro o fuera de esta tanda?
   Recomendación: fuera.

## Decisiones cerradas (31/07/2026, usuario)

1. **Encaje: ampliar el paso 4.** Pasa de "Documento" a "Materiales", con dos zonas
   (documentos · fotos del negocio). El wizard sigue con 5 pasos.
2. **Descripción bloqueante con progreso.** Se describe con IA antes de dejar
   avanzar, con barra de progreso sobre `POST /admin/media/describe-missing`. Es lo
   que garantiza que la generación entienda las fotos y no se vaya al banco.
3. **Sin análisis extra en esta tanda.** Nada de deducir sector, estilo ni paleta a
   partir de las fotos: se decide aparte cuando veamos el resultado en generación.

## Project Status Board (ONB-FOTOS) — actualizado 31/07/2026

- [x] F1 — Backend: subida de fotos desde el onboarding
- [x] F2 — Descripción por IA con progreso
- [x] F3 — UI del paso (dropzone + rejilla + alt editable)
- [x] F4 — Verificación E2E de la generación con fotos propias
- [x] F5 — Tests + regresión

## Current Status / Progress Tracking (ONB-FOTOS · 31/07/2026, Executor)

**Qué se ha hecho**

- `OnboardingController`: tres endpoints JSON nuevos —`uploadPhoto`, `updatePhotoAlt`,
  `deletePhoto`— más `saveBusinessPhotos()` (camino sin JS) y `businessPhotos()`
  para pintar la rejilla. Constante `BUSINESS_PHOTOS_MAX = 12`. Todo pasa por
  `MediaService::store()`, así que la foto nace con `source = 'upload'`, que es
  lo que filtra el pool de la generación. Cualquier alta, cambio de descripción
  o borrado llama a `invalidateHomeDraft()`.
- Rutas: `/admin/onboarding/upload-photo`, `/photo-alt`, `/photo-delete`.
- Paso 4 renombrado a **Materiales**: fotos arriba, documentos debajo, con copy
  que lo separa explícitamente de las referencias visuales del paso 2.
- `onboarding.js` · `bindBusinessPhotos()`: drag&drop y selector, **una petición
  por archivo**, rejilla con miniatura + descripción editable + borrar, bucle
  sobre `/admin/media/describe-missing` con progreso, y el botón "Continuar"
  deshabilitado mientras se sube o se describe.
- CSS en `admin/assets/css/admin.css` (nada inline).

**Verificación (hecha, no supuesta)**

- Subida real por HTTP: dos fotos → `source = 'upload'`, `images(ownOnly)` pasa de
  1 a 3, `onboarding_home_draft` vaciado. Descripción por lotes: 2 descritas en
  español y correctas, `remaining = 0`.
- Límites: la foto 13 se rechaza con mensaje ("ya tienes 12"); un `.txt` se
  rechaza por tipo; un id de otro sitio da 404; sin CSRF, 403. Borrado: quita la
  fila y el archivo del disco.
- Flujo completo por el navegador (drop simulado con un `File` real): subida →
  análisis → "Fotos analizadas…", 4 tarjetas descritas, "Continuar" reactivado,
  cero errores en consola.
- **F4, la que importa**: `prepare-home` real con 4 fotos propias → las 4 llegan
  al prompt de `COMPOSE_CANVAS_PAGE` dentro del "POOL DE FOTOS PROPIAS" con su
  descripción, **0 descargas de Unsplash** (ninguna fila nueva en `media`, 0 URLs
  remotas) y la home usa **2 fotos propias** como fondo de sección. Ojo al leerlo:
  las fotos de fondo viven en la columna `css` de `page_canvas`, no en `html`.
- `tests/onboarding_photos.php` NUEVO: **28 comprobaciones** sin gastar IA.
- **Regresión: todas las suites en verde.** `update_from_zip` no cuenta (se niega
  fuera de `PP_ENV=development`, por diseño).
- `site_languages_model` falló en la primera pasada por lo de siempre: páginas
  creadas durante la propia tanda sin `translation_group` (el borrador de home de
  mi verificación y las páginas de `canvas_generate` / `dmb2_reference_regen`).
  Borradas esas tres, la suite pasa. La base vuelve a **30 páginas**.
- **Estado de dev restaurado**: borradas las fotos de prueba (filas + archivos);
  el sitio vuelve a 1 imagen propia (el PNG de "TEST" que ya estaba) y 31 de
  banco. El borrador de home quedó vacío y se regenerará solo al entrar al paso 5.

### Lessons (ONB-FOTOS)

- **La mitad del trabajo ya estaba hecha y verlo primero evitó rehacerla.** El
  consumo se arregló en [C3-FIX]; aquí solo faltaba la puerta de entrada. Antes
  de diseñar, mirar qué parte del camino ya existe.
- **Buscar una ruta en un JSON de `ai_logs` sin escapar las barras da un falso
  negativo.** `storage/uploads` aparece como `storage\/uploads`: parecía que las
  fotos no llegaban al prompt cuando sí llegaban.
- **Una página Canvas no guarda sus imágenes solo en el HTML.** Los fondos de
  sección van en la columna `css`; contar `<img>` en `html` daba "0 fotos usadas"
  con dos fotos propias perfectamente colocadas.
- **Describir después de responder no sirve cuando lo siguiente es generar.**
  `describeAfterResponse` vale en Medios; en el onboarding el paso 5 llega antes
  y la foto entraría al prompt como nombre de archivo. Por eso el paso espera con
  barra de progreso.
- **Una petición por archivo no es un capricho.** Es lo que evita el `post_max_size`
  que ya vació `$_FILES` con las referencias, y de paso da error por foto.

---

# [PAGES-OPS] /admin/pages: operaciones que faltan sobre las páginas — PLAN (31/07/2026, Planner)

## Background and Motivation (PAGES-OPS)

El usuario reporta que en `/admin/pages` faltan opciones: no se puede devolver una
página a borrador, no se puede eliminar desde el mapa (solo desde la lista) y no se
puede elegir otra página como inicio. Pide además revisar qué más falta.

Revisado el código: `views/admin/pages/index.php` (mapa + lista),
`app/Controllers/Admin/PageController.php`, `admin/assets/js/pages-map.js`,
`CanvasController` y el resolutor de home en `app/Controllers/Public/PageController.php`.

## Key Challenges and Analysis (PAGES-OPS)

### Lo que confirma el reporte

1. **Volver a borrador.** El backend YA existe por dos caminos distintos:
   `PageController::update()` guarda `status` desde el formulario clásico, y
   `POST /admin/canvas/{id}/publish` con `publish=0` despublica. Lo que falta es la
   acción en la lista y en el mapa. Ojo al detalle que lo hace más grave de lo que
   parece: **para una página canvas el formulario clásico es inalcanzable** —
   `PageController::edit()` redirige al Studio si `render_mode = 'canvas'`—, así que
   hoy despublicar solo se puede entrando al Studio de cada página.

2. **Eliminar desde el mapa.** La tarjeta del mapa ofrece Editar / Preview /
   Estructura / Crear hija; borrar solo está en la tabla de la lista.

3. **Elegir otra home.** No hay ninguna acción "marcar como inicio". La home pública
   se resuelve en `Public\PageController::homePageFor()` por
   `page_type = 'home' AND status = 'published'`, por idioma, con
   `ORDER BY updated_at DESC LIMIT 1`. De ahí salen dos problemas:
   - `page_type` solo se edita en el formulario clásico → en una página canvas **no
     se puede cambiar en absoluto**, y hoy casi todas las páginas de marketing son canvas.
   - Si dos páginas tienen `page_type='home'`, gana **la actualizada más
     recientemente**: la home del sitio puede cambiar sola al editar la otra. Eso no
     es una funcionalidad que falte, es un comportamiento silencioso que hay que cerrar.

### Lo que he encontrado además (ordenado por daño, no por esfuerzo)

4. **Borrar es definitivo y silencioso.** Sin papelera ni confirmación informada:
   - las hijas no se borran ni se avisan — la FK es `ON DELETE SET NULL`, así que
     **suben a raíz** y el mapa cambia de forma sin que nadie lo haya pedido;
   - las traducciones del mismo `translation_group` se quedan huérfanas;
   - si la página estaba publicada e indexada, no se ofrece **redirección 301**
     aunque `SeoRedirectService` ya la crea sola al cambiar un slug y hay monitor de
     404s. La pieza existe; este camino no la usa.
5. **Borrar o despublicar la home deja el sitio sin `/`** (cae a `renderFallback`)
   sin ningún aviso previo.
6. **No se puede duplicar una página.** Es la operación más natural cuando ya tienes
   una landing que funciona y quieres la siguiente.
7. **La lista no tiene búsqueda, ni filtro por estado/tipo, ni orden por columna, ni
   acciones en lote.** Con 30 páginas ya es una tabla plana que se recorre a ojo.
8. **Falta el enlace "Ver"** a la URL pública (o al preview del borrador) en la lista.
9. **El mapa no permite reordenar arrastrando**: el orden es un campo numérico dentro
   del inspector.
10. **La home no se distingue en el mapa ni en la lista.** El único indicio es la
    clase `is-home` en la barra de "navegación probable".
11. **En sitios multi-idioma el mapa mezcla idiomas**: `buildPageTree()` no filtra por
    idioma, así que las traducciones aparecen como raíces extra y la arquitectura se
    vuelve ilegible. La lista sí tiene columna de idiomas; el mapa no.
12. **Antes de borrar no se puede ver quién enlaza a esa página**, aunque
    `LinkAuditService` ya lo calcula para `/admin/links`.

### El hilo común

Casi todo lo que falta es la misma pieza: **una barra de acciones por página,
coherente en lista y mapa**, apoyada en endpoints que en su mayoría ya existen. Lo
único que necesita decisión de producto de verdad es la home (cómo se marca y qué
pasa con la anterior) y si el borrado gana papelera o se queda en confirmación
informada.

## Propuesta (pendiente de que el usuario elija alcance)

- **P1 · Acciones por página, iguales en lista y mapa**: publicar / volver a borrador,
  eliminar (también desde el mapa), ver, duplicar. Un único endpoint de estado que
  valga para clásicas y canvas.
- **P2 · Home explícita**: acción "marcar como inicio" que ponga `page_type='home'` en
  la elegida y **degrade la anterior** en la misma operación (fin de la ambigüedad del
  `updated_at DESC`), con la home señalada en mapa y lista y protegida al borrar.
- **P3 · Borrado informado**: el diálogo dice qué arrastra (hijas, traducciones,
  enlaces entrantes) y ofrece crear la redirección 301 si estaba publicada.
- **P4 · Lista usable**: buscador, filtros por estado/tipo, y acciones en lote.
- **P5 · Mapa**: reordenar arrastrando y filtro por idioma en sitios multi-idioma.

## Decisiones cerradas (31/07/2026, usuario)

- Entra **todo**: P1 (acciones por página), P2 (home explícita), P3 (borrado
  informado), P4 (lista usable) y P5 (mapa).
- **Borrado: confirmación informada**, no papelera. Sigue siendo definitivo, pero
  antes dice qué se lleva por delante y ofrece la redirección 301. Sin tablas nuevas.

## High-level Task Breakdown (PAGES-OPS)

**G1 — Un único endpoint de estado que valga para los dos mundos**
- `POST /admin/pages/{id}/status` con `status=published|draft`. Sirve para páginas
  clásicas y canvas (hoy son dos caminos distintos: el formulario y
  `/admin/canvas/{id}/publish`). Invalida caché igual que `update()`, y mantiene
  `published_at` con el mismo criterio.
- El Studio sigue usando su endpoint: no se toca lo que ya funciona.
- Aviso al despublicar la home: el sitio se queda sin `/`.
- *Criterio:* despublicar y republicar una página canvas y una clásica desde la
  lista; la web pública lo refleja (200 → 404 → 200) y el badge cambia sin recargar.

**G2 — Barra de acciones por página, la misma en lista y mapa**
- Acciones: Ver (URL pública o preview si es borrador), Editar, Publicar / Volver a
  borrador, Duplicar, Marcar como inicio, Eliminar.
- Un solo sitio que genere esa barra para no acabar con dos verdades: la tabla y la
  tarjeta del mapa consumen el mismo marcado/JS.
- *Criterio:* todas las acciones funcionan desde las dos vistas y el inspector, y
  la página no se recarga entera para un cambio de estado.

**G3 — Duplicar página**
- `POST /admin/pages/{id}/duplicate`: copia la fila (título «… (copia)», slug único,
  **siempre borrador**), su contenido (`page_canvas` o `page_sections`), y los metadatos.
- **Grupo de traducción propio** (vía `createPageRow`, que ya hace `UUID()`): una copia
  no es una traducción. Ojo con esto, es el error fácil.
- *Criterio:* duplicar una canvas y una clásica; la copia abre en su editor con el
  mismo contenido, en borrador, sin tocar la original ni su grupo.

**G4 — Home explícita (y fin de la ambigüedad actual)**
- `POST /admin/pages/{id}/set-home`: pone `page_type='home'` en la elegida y **degrada
  la anterior del mismo idioma** a `landing` en la misma operación (no hay tipo
  "página" genérico en `PAGE_TYPES`; `landing` es el neutro que ya usa el alta).
- Alcance por idioma: cada idioma tiene su home, como ya asume `homePageFor()`.
- Si la elegida es borrador, se avisa de que no se servirá hasta publicarla.
- Home señalada en mapa y lista, y protegida en el diálogo de borrado.
- *Criterio:* marcar otra página como inicio cambia `/` en la web pública; deja de
  haber dos `page_type='home'` a la vez; editar la antigua ya no puede recuperar la
  home por `updated_at`.

**G5 — Borrado informado + redirección**
- `GET /admin/pages/{id}/delete-info` (JSON): hijas que subirían a raíz, traducciones
  del grupo, enlaces entrantes (`LinkAuditService`), si estaba publicada y si es la home.
- Diálogo con ese resumen y casilla **"crear redirección 301 hacia…"** (selector de
  página) cuando la página estaba publicada. Usa `SeoRedirectService`.
- *Criterio:* borrar una página publicada con hijas y enlaces entrantes muestra los
  tres avisos, y con la casilla marcada la URL vieja responde 301 a la nueva.

**G6 — Lista usable**
- Buscador por título/slug, filtros por estado y tipo, y selección múltiple con
  acciones en lote (publicar / volver a borrador / eliminar). Filtrado en cliente:
  el listado ya viene entero y son decenas de páginas, no miles.
- Columna "Ver" y marca de home.
- *Criterio:* buscar "contacto" deja solo esa; seleccionar 3 borradores y publicarlos
  en un gesto; el filtro de idiomas existente sigue funcionando.

**G7 — Mapa: arrastrar para reordenar y filtro de idioma**
- Drag & drop de tarjetas para cambiar padre y orden, escribiendo por el endpoint de
  estructura que ya existe (con su validación de ciclos).
- Selector de idioma cuando el sitio es multi-idioma: hoy `buildPageTree()` mezcla
  idiomas y las traducciones aparecen como raíces extra.
- *Criterio:* mover una rama entera y recargar sin que cambie nada de sitio; en un
  sitio con dos idiomas, el mapa enseña un idioma cada vez.

**G8 — Tests + regresión**
- Suite `tests/pages_operations.php`: estado (los dos render modes), duplicado (grupo
  propio, borrador, contenido copiado), set-home (degradación de la anterior, por
  idioma), delete-info (recuento de hijas/traducciones/enlaces) y la redirección 301
  al borrar. Sin gastar IA.
- *Criterio:* suite en verde + regresión completa.

## Project Status Board (PAGES-OPS)

- [ ] G1 — Endpoint único de estado (publicar / volver a borrador)
- [ ] G2 — Barra de acciones compartida entre lista y mapa
- [ ] G3 — Duplicar página
- [ ] G4 — Home explícita con degradación de la anterior
- [ ] G5 — Borrado informado + redirección 301
- [ ] G6 — Lista: buscador, filtros y acciones en lote
- [ ] G7 — Mapa: drag & drop y filtro de idioma
- [ ] G8 — Tests + regresión

## Current Status / Progress Tracking (PAGES-OPS · 31/07/2026, Executor)

**Todo hecho (G1–G8).**

Backend (`PageController`, todo JSON): `updateStatus` (un solo camino para clásicas
y canvas), `duplicate`, `setHome`, `deleteInfo`, `move`, `bulk`, y `destroy`
ampliado con `redirect_to` + respuesta JSON. Internos nuevos: `applyStatus`,
`demoteOtherHomes`, `copyPageContent`, `inboundLinks`, `redirectTargets`,
`createDeletionRedirect`. Rutas en `app/routes.php` (con `/pages/bulk` ANTES de
`/pages/{id}`, o lo captura como id).

Front: `views/admin/pages/index.php` (marca de inicio, "Ver", buscador, filtros,
selección múltiple, menú ⋯ en lista y tarjetas, selector de idioma del mapa) y
`admin/assets/js/pages-map.js` (menú, diálogo de borrado informado, lote, filtros,
filtro de idioma, drag & drop). CSS al final de `admin/assets/css/admin.css`.

**Verificación (por HTTP y por navegador, no supuesta)**

- Estado: publicar/despublicar una canvas y una clásica; el aviso de "te quedas sin
  portada" salta al despublicar la home; estado inválido → 422; el badge cambia en
  la propia fila/tarjeta sin recargar.
- Duplicar: copia de una canvas con su HTML+CSS (4440/4819 bytes idénticos), en
  borrador, `home` → `landing`, slug único y **grupo de traducción distinto**.
- Home: en el sitio de dev había **22 páginas con `page_type='home'`** (basura de
  suites), que es justo la ambigüedad del `updated_at DESC`. Marcar una degradó las
  otras 21 y quedó **una sola home**; con la elegida en borrador la web cae al
  fallback y el aviso lo dice; al marcar la buena, `GET /` → 200.
- Borrado informado: `delete-info` detecta hijas, traducciones y **20 páginas que
  enlazaban** a la de prueba; borrando una publicada con destino,
  `/inicio-canvas-13-2` pasó de 200 a **301 → /contacto**.
- Lote: publicar dos de golpe; borrar con la home dentro la salta con motivo.
- Mapa: arrastrar una tarjeta sobre otra la hace hija; soltar en el borde superior
  la coloca como hermana y el servidor renumera (0,1,2); el ciclo se rechaza.
- Idioma del mapa: activando `fr` temporalmente, con "Español" la página francesa
  queda oculta y con "Français" solo se ve ella. Revertido después.
- `tests/pages_operations.php` NUEVO: **39 comprobaciones**, sin IA.
- **Regresión completa en verde** (`update_from_zip` se niega por diseño).
  `site_languages_model` volvió a fallar por las páginas sin `translation_group`
  que dejan `canvas_generate` y `dmb2_reference_regen`; borradas, pasa. Es el
  problema de fondo que ya está anotado como tarea aparte.

**Estado de dev**: 30 páginas, una sola home (135 «Inicio»), 0 redirecciones, 0
páginas huérfanas de idioma/grupo. Las 21 homes duplicadas quedaron degradadas a
`landing`: es lo que hace la propia función y deja la base coherente.

### Lessons (PAGES-OPS)

- **`display:flex` en la clase pisa el atributo `[hidden]`.** La barra de lote se
  quedaba visible con 0 seleccionadas. Ya estaba avisado en el CSS de `pp-ps` y he
  vuelto a caer; ahora hay regla `[hidden]{display:none}` para la barra y para la
  rejilla de fotos del onboarding, y un test que lo comprueba.
- **En el mapa hay dos elementos con el mismo `data-page-id`**: el `<li>` del árbol
  y la tarjeta. Coger "el primero" daba diálogos que decían «esta página».
- **Una badge más puede romper un layout que parecía estable**: con estado + inicio
  + canvas, el título de la tarjeta se partía letra a letra (`flex:1` + 
  `overflow-wrap:anywhere`). Las badges ahora bajan de línea antes de estrujarlo.
- **La renumeración de hermanas la hace el servidor.** Si la hiciera el navegador
  serían N peticiones y un árbol a medio ordenar en cuanto una fallara.
- **La ambigüedad de la home no era teórica**: 22 páginas marcadas como inicio en la
  base de dev, sirviendo la última editada.

## Cierre (PAGES-OPS · 31/07/2026, Planner)

**Tarea aprobada y CERRADA por el usuario.** G1–G8 entregadas y verificadas;
entregable `deliverables/promptpress-update-20260731.zip` desde los commits
`1bf4d7a` (onboarding: fotos) y `2b046b0` (páginas: operaciones).

---

# [PAGES-LANG] Páginas que nacen sin idioma ni grupo de traducción — PLAN (31/07/2026, Planner)

## Background and Motivation (PAGES-LANG)

`tests/site_languages_model.php` comprueba un invariante global: **ninguna página
sin `language` ni `translation_group`**. Falla en cada regresión completa desde
hace al menos tres sesiones (30/07, 31/07 ×2), y siempre se ha "arreglado"
borrando a mano las páginas que las suites acababan de crear. El fallo es real:
la suite dice la verdad y el código de creación es el que está mal.

## Key Challenges and Analysis (PAGES-LANG)

Hay **13 sitios que hacen `INSERT INTO pages` en producción** y solo **2**
rellenan idioma y grupo:

- OK: `PageController::createPageRow()` (el alta "oficial", con `UUID()`) y
  `TranslationWriter` (que necesita el grupo por definición).
- Sin idioma ni grupo: `OnboardingController` ×3 (páginas del onboarding y el
  borrador de la home), `PostController` ×4 (entradas del blog),
  `PageController` ×2 (creación rápida y creación con IA),
  `Compliance\LegalPageGenerator` (páginas legales) y `FormStore` (la página
  interna `__forms`).

Consecuencias, por orden de daño:

1. Una página sin `translation_group` **no se puede traducir**: el flujo de
   traducción agrupa por ese campo. En un sitio multi-idioma, todo lo creado por
   esos caminos queda fuera del circuito.
2. Sin `language`, el resolutor público la trata por compatibilidad como del
   idioma principal — funciona hoy, pero es una excepción que hay que arrastrar.
3. La regresión completa miente: un fallo permanente que se aprende a ignorar es
   peor que no tener test.

El error fácil al arreglarlo: dar a todas el MISMO grupo (o el de otra página).
El grupo es «esta página y sus traducciones»; cada alta nueva necesita el suyo,
igual que hace `createPageRow()` con `UUID()`.

## High-level Task Breakdown (PAGES-LANG)

**L1 — Rellenar idioma y grupo en los 11 caminos que faltan**
- Idioma: el principal del sitio (`LanguageService::primaryFor`), salvo que el
  camino ya sepa cuál toca. Grupo: `UUID()` propio.
- *Criterio:* crear una página por cada camino y ver `language` y
  `translation_group` rellenos.

**L2 — Test de contrato que no dependa de ejecutar los 13 caminos**
- Recorrer los `INSERT INTO pages` de `app/` y exigir que todos declaren las dos
  columnas. Así queda cubierto también el camino que se escriba mañana.
- *Criterio:* la suite falla si alguien añade un INSERT sin las columnas.

**L3 — Backfill de lo que ya está en la base**
- Las filas existentes sin idioma/grupo se rellenan (idioma del sitio, grupo
  propio), sin tocar las que ya lo tienen.
- *Criterio:* `site_languages_model` pasa sin borrar páginas a mano, y pasa
  también después de una regresión completa.

## Project Status Board (PAGES-LANG)

- [ ] L1 — Idioma y grupo en los 11 caminos de creación
- [ ] L2 — Test de contrato sobre los INSERT
- [ ] L3 — Backfill de las filas existentes + regresión

## Current Status / Progress Tracking (PAGES-LANG · 31/07/2026, Executor)

**L1, L2 y L3 hechas.**

- **L1** — Los 11 caminos que faltaban ya escriben `language` (el principal del
  sitio) y `translation_group` (`UUID()`, uno por fila):
  `OnboardingController` ×3, `PostController` ×4, `PageController` ×2 (creación
  rápida y con IA), `Compliance\LegalPageGenerator` y `FormStore`.
- **L2** — `tests/site_languages_model.php` gana
  `every_insert_into_pages_sets_language_and_group`: recorre los
  `INSERT INTO pages` de `app/` y exige las dos columnas. Comprobado que FALLA de
  verdad quitándoselas a `FormStore` (señaló el archivo y la línea) y vuelve a
  pasar al restaurarlo. Cubre también el INSERT que se escriba mañana, sin tener
  que ejecutar los 13 flujos ni gastar IA.
- **L3** — `PageController::repairMissingLanguageGroups()` (llamada desde
  `index()`, junto a las otras reparaciones) rellena lo ya creado por el camino
  viejo; idempotente y sin coste cuando no hay filas que tocar. Copia canónica en
  `database/migrations/2026_07_31_pages_language_backfill.sql`.

**Verificación**

- Página huérfana creada a mano (`language=''`, `translation_group=NULL`): al abrir
  `/admin/pages` queda con `es` y grupo propio, y sin grupos compartidos.
- La página que crea `canvas_generate` —el reincidente de las últimas tres
  sesiones— nace ya con idioma y grupo.
- Suites de los caminos tocados en verde: legal, formularios (×2), blog canvas,
  idioma en creación, páginas internas.
- **Regresión completa en verde, incluida `site_languages_model`, y por primera
  vez SIN borrar páginas a mano.**

**Estado de dev**: 30 páginas, 0 huérfanas, una sola home (135 «Inicio»).

### Lessons (PAGES-LANG)

- **Un test que falla siempre acaba siendo un test que se ignora.** Tres sesiones
  "arreglándolo" borrando páginas a mano; el test tenía razón y el código de alta
  era el que estaba mal, en 11 de 13 caminos.
- **Que exista un alta "oficial" no significa que se use.** `createPageRow()` hacía
  lo correcto desde el principio y casi nadie pasaba por ahí. Cuando la corrección
  hay que repetirla en N sitios, el test que vale es el que mira el CÓDIGO, no el
  que ejercita un flujo.
- **Un backfill de migración es de una sola pasada.** Si el código que ensucia
  sigue vivo, la base vuelve a ensuciarse: primero el alta (L1), después la
  reparación (L3).

### Pendiente relacionado (no entra aquí)

Las suites `canvas_generate` y `dmb2_reference_regen` dejan sus páginas creadas y
PUBLICADAS con `page_type='home'`, así que tras una regresión completa la portada
del sitio de dev pasa a ser una página de prueba. Ya no rompe
`site_languages_model`, pero ensucia el estado y hay que borrarlas a mano.

---

# [PALETA-2] El segundo color de la paleta no llegaba a la web — 31/07/2026 (Executor)

## Lo que reportó el usuario

Web creada con una paleta verde + rosa. El rosa quedó guardado como
«secundario» y **no aparece por ninguna parte** en la web generada.

## Diagnóstico (medido, no deducido)

En la base de dev, sobre 20 páginas canvas: **142 usos de `--pp-primary` y 0 de
`--pp-accent`**. El color no se pierde por el camino —`BrandPaletteService` →
`applyCustomPaletteToTokens()` → `--pp-accent` está en el CSS de todas las
páginas— sino que **nadie lo usa**. Dos causas:

1. **El prompt de composición no lo permitía.** En `Actions.php`, la sección
   "MARCA (ley, no sugerencia)" enumera los tokens permitidos ("Colores y
   tipografías SOLO vía tokens del sitio: …") y `--pp-accent` NO estaba en la
   lista, con un "PROHIBIDO inventar colores" a continuación. El modelo hacía
   exactamente lo que se le pedía: la paleta que veía no tenía segundo color.
2. **El token «Secundario» era el color del texto.** Tanto
   `DesignSystem::applyCustomPaletteToTokens()` como `saveCustomPalette()` del
   onboarding asignaban `secondary = palette['text']`, así que el panel enseñaba
   como secundario un casi-negro que no era el que el usuario había elegido.

## Arreglo

- `var(--pp-accent)` entra en la lista de tokens permitidos del prompt de
  composición, con una regla de **dosis**: el principal manda (CTA, enlaces,
  énfasis) y el acento acompaña en 1-3 sitios (detalles, badges, subrayados,
  iconos, hovers), nunca en el CTA principal ni como fondo de una banda entera.
  Los prompts de edición dicen lo mismo, para que "usa más el rosa" funcione.
- `secondary` pasa a ser el segundo color de marca (`accent_2`) en los dos
  sitios que lo escribían. **Sin riesgo medido**: `--pp-secondary` no lo pinta
  nada del sitio público (0 usos en las 20 páginas canvas y en las 59 secciones
  clásicas); solo arregla lo que se ve en el panel.
- Etiquetas del design system con el papel de cada color.

## Verificación

- Con paleta verde+rosa: `primary=#0f7a4a`, `accent=secondary=#e0559b`, y el CSS
  público emite `--pp-accent: #e0559b`.
- **Generación real por el camino del panel**: la página nueva usa
  `--pp-accent` **2 veces** (`border-left: 4px solid var(--pp-accent)` y un
  `background`) frente a 11 de `--pp-primary`. Es exactamente la dosis pedida.
- `tests/onboarding_step2.php` +9 comprobaciones, incluidas dos de contrato del
  prompt (si alguien quita `var(--pp-accent)` de la lista, la suite falla).
  Suites relacionadas en verde (bloques, canvas, referencia, idiomas).
- Estado de dev restaurado: 30 páginas, sin paleta a medida (como estaba).

## Lo que NO arregla

Las páginas **ya generadas** no cambian: nacieron sin el segundo color. Se
pueden retocar por el chat del Studio ("usa el rosa en los detalles") o
regenerar.

### Lessons (PALETA-2)

- **Un dato puede estar guardado, convertido y publicado en el CSS y aun así no
  existir para quien pinta la página.** El recorrido del color estaba bien hasta
  el último paso; el fallo era una lista blanca en un prompt.
- **Cuidado con los nombres que se pisan.** «Secundario» significaba una cosa en
  el paso 2 (segundo color de marca) y otra en el design system (color del
  texto). El usuario lee la etiqueta, no el mapeo.
- **Antes de temer una regresión, medirla.** Remapear `--pp-secondary` daba
  miedo hasta contar sus usos reales: cero.

---

# [FORMS-LANG] Los formularios se quedan en castellano — PLAN (10/08/2026, Planner)

## Background and Motivation (FORMS-LANG)

El usuario tiene una web en producción **en francés** y los formularios salen
**en castellano**: encabezado, etiquetas de los campos, botón de enviar, nota de
privacidad y mensajes. Pide dos cosas, en este orden:

1. Que un formulario nuevo nazca en el **idioma principal del sitio**.
2. Que en una web **multi-idioma** el formulario se **traduzca** con la página.

## Key Challenges and Analysis (FORMS-LANG)

Diagnóstico leyendo el código (no son hipótesis: son los sitios exactos donde se
pierde el idioma).

**1. El catálogo de plantillas está escrito en castellano, literal.**
`app/Services/FormTemplates.php` guarda `heading`, `submit_text`,
`success_message`, las etiquetas de campo ("Nombre", "Email", "Mensaje"),
`retention_period` y el autorespondedor en castellano fijo. Ese texto se
**persiste en la BD** al crear el formulario (`FormStore::createFromTemplate`),
así que no es un problema de render: nace en castellano y se queda. Vale tanto
para el botón "crear formulario" del panel como para la materialización
automática de `{{form:contact}}` que hace la IA al generar una página.

**2. El renderizador público tiene castellano incrustado.**
En `SectionRenderer::renderForm()` / `renderFormPrivacyNotice()`:
"Selecciona una opción", "Seleccionar archivo", "Ningún archivo seleccionado",
el checkbox de marketing ("Acepto recibir comunicaciones comerciales…"), y toda
la nota de privacidad RGPD ("Tus datos se tratarán en base a nuestro interés
legítimo…", "Más información en nuestra política de privacidad"). También
`FormSubmissionService::fileHelpForField()` ("Formatos permitidos… Máximo N MB").
Nada de esto pasa por `Microcopy`, que sí cubre `form.submit`, `form.success`,
`form.error`, `form.rate_limited` en los 7 idiomas.

**3. En páginas canvas se pierde el idioma de render.**
`Public\PageController::render()` hace bien `SectionRenderer::setSiteContext($siteId, $lang)`
con el idioma de la PÁGINA, pero después `CanvasService::renderPublic()` vuelve a
llamar a `setSiteContext($siteId)` **sin idioma** (y `expandPlaceholders()` otra
vez), lo que resetea el idioma al del SITIO. En una web bilingüe con páginas
canvas, hasta el poco microcopy que ya está traducido sale en el idioma
equivocado. Es un bug de una línea con efecto en todo lo que renderice el canvas.

**4. Un formulario es una entidad de sitio, no de página: la traducción no lo toca.**
Los formularios viven como secciones `form` de una página contenedora oculta
(`FormStore`, slug `__forms`) y las páginas los referencian con `{{form:12}}`.
El prompt de traducción (`Actions.php:610`) ordena **copiar los placeholders
exactos**, y hace bien. Consecuencia: la página francesa traducida enseña el
MISMO formulario castellano. `PageTranslator` nunca ve ese contenido.

**5. El panel de formularios no conoce el idioma.** `views/admin/forms/*` no
tiene ninguna noción de idioma, y `FormStore::all()` lista todo junto.

### La decisión de fondo: ¿cómo es un formulario multi-idioma?

- **Opción A — un formulario por idioma** (variantes hermanas, al estilo del
  `translation_group` de páginas). El editor actual no cambia. Pero **parte la
  bandeja de entrada en dos**, duplica configuración sensible (email de aviso,
  autorespondedor, base legal, retención) y esa duplicación se desincroniza sola.
- **Opción B — un formulario, textos por idioma** (recomendada). Se añade un
  bloque `i18n` dentro del JSON del formulario: `{"fr": {"heading":…, "submit_text":…,
  "fields": {"nombre": {"label":…, "placeholder":…}}}}`. Al pintar, se resuelve
  con el idioma de la página; si falta ese idioma, cae al texto base. Ventajas:
  **una sola bandeja de leads**, un solo sitio para el RGPD, el `{{form:12}}` de
  la página traducida sigue funcionando sin tocar nada, y el `name` de los campos
  (la clave de los datos guardados) no cambia nunca.
- **Opción C — solo idioma principal**, sin traducción por página. Arregla el
  caso del usuario hoy (web francesa monolingüe) y deja el multi-idioma fuera.

**Recomendación: B**, y hacer la fase 1 (idioma principal) antes que la 2.

### Segunda decisión: ¿diccionario o IA para traducir los textos?

- Las **5 plantillas** del catálogo son un conjunto cerrado y pequeño (unos 30
  textos). Traducirlas con un **diccionario** al estilo `Microcopy` es
  determinista, gratis, instantáneo y **testeable** (`tests/site_language.php` ya
  falla si queda un hueco). Es lo correcto para lo que nace de plantilla.
- Lo que el usuario haya **escrito a mano** (campos propios, encabezado
  personalizado) no lo cubre ningún diccionario: ahí sí hace falta **IA**, en una
  acción explícita "traducir este formulario a X".

**Recomendación: diccionario para plantillas + microcopy, IA solo para traducir
formularios ya existentes/personalizados.**

## Riesgos y qué NO hay que romper

- **Los `name` de los campos son la clave de los datos.** `nombre`, `email`,
  `mensaje` identifican columnas en las submissions guardadas y las variables del
  autorespondedor (`{{nombre}}`). Se traduce la **etiqueta**, nunca el `name`:
  si se traduce el `name`, las bandejas viejas dejan de casar y el autorespondedor
  se queda con huecos.
- **Los formularios ya creados en prod no se arreglan solos.** El texto está
  guardado. Cambiar plantillas solo afecta a los nuevos → hace falta una acción
  de retrocompatibilidad ("traducir este formulario") para la web francesa que ya
  existe. Esto es exactamente el caso del usuario.
- **La nota de privacidad tiene efectos legales.** Traducirla con diccionario
  revisado, no con IA suelta.
- **No tocar el email de aviso al dueño del sitio.** Ese va al administrador, no
  al visitante; su idioma es otra discusión.

## High-level Task Breakdown (FORMS-LANG)

### Fase 1 — Que el formulario nazca en el idioma del sitio

- **T1. Idioma de render en canvas.** `CanvasService::renderPublic()` y
  `expandPlaceholders()` reciben y propagan el idioma en vez de resetearlo.
  *Criterio:* página canvas en `fr` con un formulario → el botón sale "Envoyer"
  sin tocar nada más. Test que renderice la misma página en `es` y `fr` y compare.

- **T2. Microcopy para lo que hoy está incrustado.** Nuevas claves en 7 idiomas:
  `form.select_placeholder`, `form.file_button`, `form.file_empty`,
  `form.file_help` (con `{formats}` y `{max}`), `form.marketing_consent`,
  `form.privacy_basis_*` (consent/contract/legitimate_interest),
  `form.privacy_retention`, `form.privacy_link`. `SectionRenderer` y
  `FormSubmissionService::fileHelpForField()` pasan a usarlas.
  *Criterio:* `tests/site_language.php` en verde (falla si falta un idioma) y
  cero literales castellanos en el HTML de un formulario renderizado en `fr`.

- **T3. Plantillas traducidas.** `FormTemplates` deja de devolver castellano fijo:
  el catálogo pasa a resolverse por idioma (diccionario propio o claves de
  Microcopy `form.tpl.*`), con los `name` de campo intactos. `createFromTemplate`
  y la materialización de `{{form:TIPO}}` reciben el idioma principal del sitio.
  *Criterio:* en un sitio `fr`, crear el formulario de contacto guarda
  "Contactez-nous" / "Envoyer" / "Nom", y el `name` sigue siendo `nombre`.
  `tests/form_templates.php` ampliado a dos idiomas.

- **T4. Autorespondedor por idioma.** `autoresponder_subject` / `_body` por
  defecto en el idioma del sitio (claves `mail.*` de Microcopy ya existen como
  familia). *Criterio:* formulario creado en `fr` con cuerpo en francés y
  `{{nombre}}`/`{{sitio}}` intactos.

- **T5. Arreglar lo que ya existe en prod.** Acción en el editor de formularios:
  **"Traducir este formulario al idioma del sitio"** (IA, revisando antes de
  guardar). Traduce labels, placeholders, heading, botón y mensajes; nunca los
  `name`. *Criterio:* el formulario castellano de la web francesa del usuario
  queda en francés en una pulsación, con los leads antiguos intactos.

### Fase 2 — Multi-idioma (solo si se elige la opción B)

- **T6. Modelo `i18n` dentro del formulario** + resolución en render por idioma
  de página, con caída al texto base. *Criterio:* `/contact` (fr) y `/contacto`
  (es) comparten `{{form:12}}` y cada una lo pinta en su idioma.
- **T7. Traducir el formulario al traducir la página.** Cuando `PageTranslator`
  traduce una página que contiene `{{form:N}}`, se rellena el bloque `i18n` de
  ese formulario en el idioma destino si falta. *Criterio:* traducir la home al
  francés deja el formulario listo en francés sin pasos extra.
- **T8. UI del editor:** pestañas de idioma en el formulario, aviso de "sin
  traducir" y botón de traducir por idioma. *Criterio:* se ve de un vistazo qué
  idiomas están cubiertos.
- **T9. Documentación + tests de regresión** (una bandeja, un `name`, un RGPD).

## Project Status Board (FORMS-LANG)

- [ ] Decisión del usuario: modelo multi-idioma (A / B / C)
- [ ] Decisión del usuario: alcance ahora (solo Fase 1 / Fase 1+2)
- [ ] T1 idioma de render en canvas
- [ ] T2 microcopy de lo incrustado
- [ ] T3 plantillas por idioma
- [ ] T4 autorespondedor por idioma
- [ ] T5 traducir formularios ya existentes
- [ ] T6-T9 (fase 2, condicionada a la decisión)

## Executor's Feedback or Assistance Requests (FORMS-LANG)

Pendiente: nada hasta que el usuario cierre las dos decisiones de arriba.

## Decisiones cerradas (10/08/2026, usuario)

1. **Modelo: opción B** — un solo formulario con sus textos por idioma. Una
   bandeja de leads, un RGPD, `{{form:N}}` intacto en la página traducida.
2. **Alcance: Fase 1 + Fase 2 completas** (T1-T9). Orden de ejecución sin
   cambios: T1→T5 primero, porque son las que desbloquean la web francesa que ya
   está en producción, y T6→T9 después sobre esa base.

Invariantes que la implementación NO puede romper (recordatorio para el Executor):
los `name` de los campos nunca se traducen; el email de aviso al dueño se queda
como está; la nota de privacidad se traduce con diccionario revisado, no con IA.

## Current Status / Progress Tracking (FORMS-LANG · 10/08/2026, Executor)

Fase 1 y Fase 2 implementadas y verificadas. Estado del board:

- [x] T1 idioma de render en canvas — `CanvasService::renderPublic()` y
      `expandPlaceholders()` reciben idioma; si no se pasa, se deduce de la fila
      de `pages`. `Public\PageController` lo pasa explícito.
- [x] T2 microcopy de lo incrustado — 15 claves nuevas × 7 idiomas (select, file,
      consentimiento de marketing, nota de privacidad completa, retenciones,
      errores del envío). Hidden `_lang` en el formulario para que el POST sepa
      desde qué idioma se envió.
- [x] T3 plantillas por idioma — `FormTemplates::catalog($lang)`; el formulario
      nace en `LanguageService::primaryFor($siteId)`. Los `name` intactos.
- [x] T4 autorespondedor por idioma — asunto y cuerpo del catálogo traducidos;
      el cuerpo se lee con `Microcopy::template()` para no perder `{{nombre}}`.
- [x] T5 traducir formularios existentes — acción `TRANSLATE_FORM` +
      `FormTranslator` + `POST /admin/formularios/{id}/translate`.
- [x] T6 modelo i18n — `FormI18n` (bloque `i18n` dentro del JSON, campos
      indexados por `name`, caída al texto base).
- [x] T7 traducir el formulario al traducir la página — enganchado en
      `TranslationWriter::createCanvas()`, best-effort.
- [x] T8 UI del editor — tarjeta "Idiomas" con estado por idioma y botón de
      traducir; aviso destacado si la base no coincide con el idioma principal.
- [x] T9 tests — `tests/forms_language.php`, 37 comprobaciones.

### Verificación

- `tests/forms_language.php`: 37/37. Suites relacionadas en verde
  (form_templates, form_inline_insert, form_intent_materialization,
  site_language, site_microcopy, site_language_urls, page_translation,
  page_translation_write, translation_jobs, canvas_runtime,
  blog_canvas_regression, mail_microcopy, botguard_submit).
- **Camino real con IA en dev**: activado `fr` en el sitio 1, traducido el
  formulario 469 desde el panel. La IA devolvió labels traducidas, claves de
  campo intactas y `{{nombre}}`/`{{sitio}}` conservados.
- **Público real**: `/fr/contact-…` sirvió `Contactez-nous` / `Envoyer` / `Nom`
  y la nota RGPD en francés, con `_lang=fr`; `/inicio-canvas-12` (es) siguió en
  castellano — el MISMO formulario 469, una sola bandeja.
- **Envío real**: POST a `/forms/469` con `_lang=fr` respondió
  «Merci, nous vous contacterons bientôt.»; con `_lang=es`, en castellano.
- Estado de dev restaurado: `fr` desactivado, `i18n` del formulario 469
  borrado, página de prueba eliminada, cero submissions creadas.

### Lo que NO cubre

- Los formularios **por sección** de páginas clásicas ya se traducían con la
  página (`TRANSLATE_PAGE_SECTIONS`): no se han tocado.
- El email de **aviso al dueño** sigue en castellano, como se decidió.
- Un `retention_period` escrito a mano por el usuario no se traduce: es una
  declaración legal suya.

### Lessons (FORMS-LANG)

- **Hay texto que no se traduce al pintar porque se copió al crear.** Las
  plantillas escribían castellano en la BD; ningún arreglo en el renderizador
  podía llegar a él. De ahí que hiciera falta, además, una acción para los
  formularios que ya existen.
- **Un `setSiteContext($siteId)` sin idioma pisa el idioma de la página.** El
  controlador público lo hacía bien y el canvas lo deshacía una línea después.
- **El editor reconstruye el content entero desde el POST**: cualquier clave
  nueva que no viaje en el formulario (`language`, `i18n`) se pierde al guardar
  si no se arrastra a mano desde lo que había.

## [LEGAL-SLUG] URLs de las páginas legales en el idioma del sitio (10/08/2026, Executor)

**Problema:** los textos legales ya se generaban en el idioma del sitio, pero el
slug estaba fijo en castellano (`/privacidad`, `/aviso-legal`…). Una web francesa
quedaba con textos franceses en URLs castellanas.

**Arreglo:** mapa `LegalPageGenerator::SLUGS` (4 tipos × 7 idiomas) y
`slugFor($siteId, $type)`, que sigue al idioma PRINCIPAL del sitio.

**Lo delicado no era traducir el slug, era no romper lo publicado.** Una página
legal ya creada tiene su URL en el pie de todas las páginas, en la nota de
privacidad de los formularios y, con suerte, en Google. Por eso:

- `findExistingPage()` resuelve en dos pasos: por el id de `manifest.legal_pages`
  (con `page_type='legal'`, para no machacar una página reconvertida en otra
  cosa) y, si no, por CUALQUIERA de los slugs conocidos del tipo (todos los
  idiomas + el castellano histórico).
- `upsertLegalPage()` conserva el slug de la página existente; solo las que
  nacen ahora estrenan el del idioma, pasando por `uniqueSlug()`.
- `PrivacyController::loadLegalPagesState()` usa esa misma resolución: buscar
  solo por el slug castellano habría hecho "desaparecer" del panel las páginas
  de una web en otro idioma, y el botón de generar habría creado un duplicado.
- `typesFor()` devuelve el slug REAL (el de la página si existe), así el panel
  enseña la URL que verá el visitante. Lee el manifest una sola vez para los
  cuatro tipos.

**Verificación:** `tests/legal_page_slugs.php` (16 comprobaciones) + generación
REAL con IA en dev con el sitio en francés: creó "Mentions Légales" en
`/mentions-legales` con `language=fr` y el texto en francés. Estado de dev
restaurado (idioma, página aparcada y manifest).

---

# [ADMIN-I18N] El panel de control en el idioma del cliente — PLAN (10/08/2026, Planner)

## Background and Motivation (ADMIN-I18N)

La web pública ya sabe hablar siete idiomas: `LanguageService` manda, `Microcopy`
cubre los textos del frontend que no escribe nadie, las páginas se traducen y las
legales nacen con slug del idioma. Pero **el panel sigue siendo castellano puro**:
el onboarding, el menú lateral, los botones, los avisos, los errores de validación
y los textos de los editores JS.

Eso significa que un cliente francés o portugués puede tener una web perfecta en su
idioma, y para mantenerla tiene que pelearse con un panel en un idioma que no habla.
El onboarding es lo peor: es la primerísima pantalla que ve, antes de tener nada.

Decisión del usuario (10/08/2026), tomada antes de escribir este plan:

1. **Idioma del panel = idioma principal del sitio, con override por usuario.**
   Por defecto el panel habla el idioma de la web; cada usuario puede fijar el suyo
   en su perfil (columna nueva `users.language`, `NULL` = heredar del sitio).
2. **Idiomas del panel: `es`, `en`, `fr`, `pt`.** Los "de país". `ca`, `gl` y `eu`
   quedan fuera de esta entrega (esos gestores leen castellano) pero la maquinaria
   tiene que admitirlos sin tocar código: añadir un idioma = añadir un fichero.
3. **Por fases.** Primero la maquinaria + onboarding + navegación (lo que ve un
   cliente nuevo). El resto del panel después, sección a sección.

## Key Challenges and Analysis (ADMIN-I18N)

### El volumen, medido (no estimado a ojo)

| Superficie | Ficheros | Líneas | Cadenas traducibles (aprox.) |
|---|---|---|---|
| `views/admin/**` | 56 | 11.359 | ~1.635 nodos de texto + 154 literales PHP |
| `app/Controllers/Admin/**` | 26 | 15.087 | ~475 literales acentuados (flashes, errores, etiquetas) |
| `admin/assets/js/**` | 20 | 11.416 | ~384 literales acentuados |
| `install/**` | — | — | fuera de esta entrega (ver Fase 5) |

Total realista: **~2.000–2.500 cadenas**. A mano es inviable; con IA es una tarde de
proceso por idioma. Lo caro no es traducir, es **extraer**: tocar 100 ficheros
cambiando literales por claves sin romper nada.

### Lo que NO se puede traducir (el riesgo de verdad)

De los ~475 literales acentuados de los controladores, una parte **no son interfaz:
son prompts para la IA**. `app/Services/AI/Actions.php` y compañía llevan
instrucciones al modelo escritas en castellano. Si el extractor las trata como UI y
las traduce, se rompe la generación de contenido, y se rompe en silencio.

Regla dura para el Executor: **se migra solo lo que se pinta en pantalla**. Todo lo
que viaja a un proveedor de IA, a la base de datos como valor, a un `name=` de
formulario o a una clave de array se queda tal cual. Ante la duda: no migrar.

Tampoco se tocan: `Microcopy` (es del frontend público, otro dominio), los mailers
al cliente final (ya usan el idioma del pedido), ni los valores almacenados —
solo sus etiquetas.

### Cómo conviven "idioma de la web" e "idioma del panel"

Ya hay dos conceptos: `sites.language`/`site_languages` (principal + adicionales) y,
ahora, el idioma en el que se le habla al gestor. Resolución en cascada, una sola
función:

```
users.language (si no es NULL y está entre los idiomas del panel)
  → LanguageService::primaryFor($siteId)   (si está entre los del panel)
  → 'es'
```

Un sitio en catalán da panel en castellano hasta que exista `ca` en el catálogo: es
degradación correcta, no un error.

### Decisiones técnicas

- **Catálogos PHP planos**, `lang/admin/{es,en,fr,pt}.php`, `'clave' => 'texto'`.
  Sin gettext (no hay extensión garantizada en el hosting), sin JSON (los ficheros
  PHP se cachean en opcache y no hay que parsear nada).
- **Claves con namespace por sección**: `onboarding.step2.title`, `nav.pages`,
  `pages.flash.saved`. Legibles en el código, ordenables en el catálogo.
- **Castellano es la fuente y el último fallback.** Si falta una clave en `fr`, sale
  el castellano y el test lo canta. Nunca se pinta la clave cruda al usuario.
- **Helper global `__('clave', ['n' => 3])`**, interpolación con `{n}`. Devuelve
  texto **sin escapar**: en las vistas se escribe `<?= e(__('...')) ?>`. Para textos
  con HTML intencionado, clave con sufijo `.html` y sin `e()`. Esta regla es lo que
  evita convertir una traducción en un XSS.
- **JS**: el layout inyecta `window.PP_I18N` con las claves del prefijo `js.` del
  idioma activo, y `admin.js` expone `pp.t(clave, vars)`. Un único punto de entrada
  para los 20 ficheros.
- **`<html lang="es">` del layout pasa a ser el idioma del panel.**

### Cómo se traduce, y cómo no se pudre

- `scripts/i18n_translate.php`: lee `lang/admin/es.php`, detecta claves que faltan
  en `en/fr/pt` y las traduce por lotes con el proveedor de IA ya integrado,
  pasándole un **glosario de producto** (Escritorio, Entradas, Canvas, Diseño…)
  para que un término no salga de tres maneras distintas. Solo añade lo que falta:
  nunca pisa una traducción ya revisada.
- `tests/admin_i18n.php` (se escribe **antes** de migrar nada, TDD): falla si hay
  claves de `es` sin traducir, si hay claves huérfanas en otros idiomas, o si los
  placeholders `{x}` no coinciden entre idiomas.
- `scripts/i18n_lint.php`: sobre una **lista de ficheros ya migrados**, detecta
  literales acentuados nuevos que se hayan colado. Es lo que impide que la próxima
  feature vuelva a meter castellano a pelo en una vista ya traducida.

### Riesgos y qué no hay que romper

- **Prompts de IA traducidos por error** → generación rota en silencio. Mitigación:
  regla dura arriba + revisión explícita de cada literal que salga de `Services/AI`.
- **Longitud del texto**: francés y portugués son ~20 % más largos que el castellano.
  El sidebar y los botones hay que mirarlos en `fr` antes de dar por buena una fase.
- **Flashes en sesión**: se traducen al generarlos, no al pintarlos. El idioma no
  cambia entre el POST y el redirect, así que es correcto y más simple.
- **Onboarding**: mientras se hace el alta puede que el sitio aún no tenga idioma
  fijado. Hasta el paso donde se elige, el panel usa `Accept-Language` del navegador
  filtrado por los idiomas soportados, y a partir de ahí el del sitio.
- **Estados mixtos**: entre fases habrá pantallas traducidas y pantallas en
  castellano. Es feo pero es aceptable y reversible; el orden de fases pone lo
  traducido donde más se ve.

## High-level Task Breakdown (ADMIN-I18N)

### Fase 0 — Maquinaria (sin traducir nada todavía)

- **T0.1 · Test primero.** `tests/admin_i18n.php` con las tres comprobaciones
  (huecos, huérfanas, placeholders) sobre catálogos que aún no existen.
  *Criterio: el test corre y falla por la razón correcta.*
- **T0.2 · `App\Services\AdminI18n`** + `lang/admin/{es,en,fr,pt}.php` vacíos.
  Métodos: `locale()`, `t($key, $vars)`, `jsCatalog()`, `LOCALES`.
  *Criterio: `AdminI18n::t('x.y')` devuelve `'x.y'` sin reventar y el test pasa en verde con catálogos vacíos.*
- **T0.3 · Helper global `__()`** en `core/Helpers.php` (comprobando que no choca
  con nada) + resolución de idioma en el arranque del admin.
  *Criterio: una cadena piloto en el layout cambia al cambiar el idioma del sitio.*
- **T0.4 · `users.language`** (migración + `install/schema.sql`, que ya se sabe que
  divergen) + selector en el perfil/Ajustes.
  *Criterio: un usuario con `language='en'` ve la cadena piloto en inglés aunque el sitio sea `fr`; con `NULL` la ve en `fr`.*
- **T0.5 · Puente JS**: `window.PP_I18N` en el layout + `pp.t()` en `admin.js`.
  *Criterio: una cadena piloto en `admin.js` cambia de idioma.*
- **T0.6 · `scripts/i18n_translate.php` + glosario.**
  *Criterio: con 5 claves piloto en `es`, genera `en/fr/pt` correctas y no pisa una traducción editada a mano.*

### Fase 1 — Lo que ve un cliente nuevo

- **T1.1 · Layout y navegación** (`views/admin/layout.php`: menú, cabecera, avatar).
- **T1.2 · Onboarding completo**: `views/admin/onboarding/*` (751 líneas),
  `OnboardingController.php` y `admin/assets/js/onboarding.js`.
- **T1.3 · Escritorio** (`views/admin/dashboard.php` + `DashboardController`).
- **T1.4 · Ajustes** (`views/admin/settings/*` + `SettingsController`,
  `SettingsAIController`, `MailSettingsController`).
- **T1.5 · Login y errores de sesión** (`views/admin/auth/*`, `AuthController`).
- *Criterio de fase: un alta completa de principio a fin en francés, sin una sola
  palabra en castellano, revisada a pantalla; `i18n_lint` limpio sobre esos ficheros.*

### Fase 2 — Contenido del día a día
Páginas, Entradas, Medios, Formularios, Mensajes, Conocimiento, Documentos
(vistas + controladores + `pages-form.js`, `pages-map.js`, `post-editor.js`,
`document-*.js`).

### Fase 3 — Editores
Canvas Studio, Page Studio, Sections Editor, Diseño, Header y pie
(los JS gordos: `canvas-studio.js`, `page-studio.js`, `sections-editor.js`,
`design-system.js`, `chrome-editor.js`, `color-picker.js`).

### Fase 4 — El resto
SEO, Marketing, Privacidad (+ wizard), IA/uso, Módulos, Analítica, Asistente,
Tienda y Reservas.

### Fase 5 — Instalador (decidir al llegar)
`install/**` corre antes de que exista sitio ni usuario: el idioma saldría de un
selector en el primer paso o de `Accept-Language`. Es un plan aparte; no bloquea nada.

## Project Status Board (ADMIN-I18N)

- [x] T0.1 Test `tests/admin_i18n.php`
- [x] T0.2 Servicio `AdminI18n` + catálogos
- [x] T0.3 Helper `__()` + resolución de idioma
- [x] T0.4 `users.language` + selector de perfil
- [x] T0.5 Puente JS (`PP_I18N` / `pp.t`)
- [x] T0.6 Script de traducción con IA + glosario
- [x] T1.1 Layout y navegación
- [x] T1.2 Onboarding (vista + controlador + JS)
- [x] T1.3 Escritorio
- [x] T1.4 Ajustes (General; IA y Correo pendientes → ver más abajo)
- [x] T1.5 Login
- [x] Extra no planeado: `scripts/i18n_lint.php` (el vigilante de la Fase 0 que no llegué a escribir entonces)
- [x] T1.6 Ajustes · IA y Ajustes · Correo (lo que quedó fuera de la Fase 1)
- [x] T2.1 Conocimiento (`memory`) — incluye las etiquetas del paso 1 del onboarding
- [x] T2.2 Documentos (vistas + controlador + 2 JS)
- [x] T2.3 Medios y banco de imágenes (vistas + controlador + `MediaService`)
- [x] T2.4 Formularios: listado, editor y bandeja de Mensajes (+ controladores, `FormTemplates`)
- [x] T2.5 Páginas: mapa del sitio y editor clásico
- [x] T2.6 Entradas: listado, editor y estudio de creación
- [x] T2.7 `PageController` (avisos e interfaz; los prompts se quedan)
- [x] T2.8 `PostController` + `pages/studio.php` + los 5 JS de la fase
- [x] Fase 3 — Canvas Studio (vista + 1.400 líneas de JS), editor de secciones
      (2.550 líneas de JS), Diseño (+ preview-all y showcase-anchors), Header y
      pie, y los cuatro JS auxiliares (color-picker, design-system,
      unsplash-picker, chrome-editor)
- [x] Fase 3b — `CanvasController`, `SectionController`, `DesignController`,
      `ChromeController` y `SectionSchemas` (250 claves generadas por script)
- [x] Fase 4a — Módulos, Enlaces internos, Asistente (+ su JS), Marketing, SEO
- [x] Fase 4b — Analítica (+ su JS), IA (uso, explorador de prompts, test),
      Privacidad (índice, resumen y páginas legales)
- [x] Fase 4c — Privacidad completa (las 7 vistas + los 4 pasos del wizard +
      `TrackingCatalog` con 39 claves generadas), Tienda: listado y editor
- [x] Fase 4d — Tienda: `orders`, `order`, `payments`; Reservas (3 vistas +
      `BookingAdminController`); controladores de Fase 4 (Privacy,
      PrivacyWizard, Marketing, Seo, Assistant, AITest, Modules,
      `CommerceAdminController`), avisos al admin de los dos mailers,
      `LegalPageGenerator::typesFor()`, `HelloController` y los tres JS que
      quedaban (privacy-generate, color-picker, commerce-product-editor)
- [x] Fase 5 — Instalador: `InstallerApp`, layout (con selector de idioma
      propio), los 5 pasos y `AIProviderTester`

### Lessons (ADMIN-I18N Fase 3)

- **`pgrep -f "<patrón>"` se encuentra a sí mismo.** Nueve shells de espera
  (`until ! pgrep -f "scripts/i18n_translate.php"; do sleep; done`) se quedaron
  colgados para siempre: el patrón aparece en la propia línea de comandos del
  shell que espera, así que cada uno se detectaba a sí mismo. El traductor había
  terminado hacía rato. Para esperar a un proceso: usar el mecanismo de tareas en
  segundo plano del entorno, o el truco del corchete (`pgrep -f "[i]18n_..."`).

## Current Status / Progress Tracking (ADMIN-I18N · 10/08/2026, Executor)

**Fase 0 COMPLETA.** La maquinaria está montada y verificada de punta a punta.
El panel sigue en castellano salvo cuatro cadenas piloto de la topbar: es lo
esperado, la traducción de pantallas es Fase 1.

**Qué se ha construido**

| Pieza | Fichero |
|---|---|
| Test (escrito primero) | `tests/admin_i18n.php` — 40 comprobaciones |
| Servicio | `app/Services/AdminI18n.php` |
| Catálogos | `lang/admin/{es,en,fr,pt}.php` (15 claves piloto) |
| Helper global | `__()` en `core/Helpers.php` |
| Columna + selector | `database/migrations/2026_08_10_admin_language.sql`, `install/schema.sql`, `SettingsController::panelLanguage()`, ruta `POST /admin/settings/panel-language` |
| Puente JS | `window.PP_I18N` en `views/admin/layout.php` + `pp.t()` en `admin/assets/js/admin.js` |
| Traducción con IA | `scripts/i18n_translate.php` + acción `Actions::TRANSLATE_ADMIN_UI` |

**Verificación (medida, no deducida)**

- `php tests/admin_i18n.php` → 40/40 en verde.
- Sin regresiones: `site_language`, `site_languages_model`, `forms_language`,
  `site_language_chrome_i18n`, `legal_page_slugs`, `canvas_settings`,
  `onboarding_step2`, `page_from_ref_prompt` → todos OK.
- Servidor real (`:8788`), sesión admin: sitio en `es` → `<html lang="es">` y
  «Salir». Sitio cambiado a `fr` → `<html lang="fr">`, «Déconnexion» y el
  `PP_I18N` del navegador en francés. Preferencia de usuario a `en` desde el
  selector real → `<html lang="en">` y «Log out» **con la web todavía en
  francés**: la cascada usuario → sitio funciona.
- En el navegador, `window.pp.t('js.saving')` devolvió `"Saving…"`.
- Traducción con IA REAL: 15 claves × 3 idiomas, 45/45 aceptadas, 0 descartadas
  por placeholders.
- Corrección a mano en `en` (`Logout` → `Log out`) y nueva pasada del script:
  **no la pisó**. Es la garantía de que revisar traducciones no es trabajo
  perdido.
- Estado de dev restaurado: `sites.language='es'`, `site_languages` principal
  `es`, `users.language=NULL`.

### Lessons (ADMIN-I18N Fase 0)

- **`PromptBuilder::expandTemplate()` se come cualquier `{palabra}` de una
  instrucción de IA.** Escribir `{n}` o `{nombre}` como ejemplo en el prompt los
  deja vacíos antes de llegar al modelo. Los ejemplos de variable se pasan como
  tokens desde `AIActionRunner` (`{var_token}`), igual que ya se hacía con
  `{{nombre}}` en `DESIGN_FORM`. Verificado montando el prompt de verdad.
- La sustitución es de UNA pasada, así que el JSON de entrada puede llevar
  `{n}` dentro sin que se lo coman. Solo hay riesgo en la instrucción.
- `users.language` es `NULL` por defecto, no `'es'`. Con `'es'` por defecto,
  cambiar el idioma de la web no cambiaría nunca el del panel.
- `pp.t()` va en su propia IIFE y la PRIMERA de `admin.js`: el bloque siguiente
  hace `return` temprano si no encuentra el sidebar, y las pantallas standalone
  (Canvas Studio) también necesitan traducir.

## Current Status / Progress Tracking (ADMIN-I18N Fase 1 · 10/08/2026, Executor)

**Fase 1 COMPLETA.** Un alta entera en francés, de la pantalla de acceso al
último paso del onboarding, sin una palabra en castellano.

**Catálogo:** 501 claves × 4 idiomas.

**Qué se ha migrado**

- Layout y menú lateral (20 entradas), `<html lang>`, título de pestaña.
- Login (vista + `AuthController`).
- Escritorio completo (vista + widget de cumplimiento con plural explícito).
- Ajustes · General: vista entera, `SettingsController` (flashes, errores de
  validación, zonas horarias, estilos de entrada).
- Onboarding: los 5 pasos de la vista, `OnboardingController` (solo lo que se
  pinta) y `admin/assets/js/onboarding.js` entero (~110 cadenas).
- Herramienta nueva no planeada para Fase 1: **`scripts/i18n_lint.php`**, que es
  el vigilante que la Fase 0 dejó a deber.

**Verificación**

- `tests/admin_i18n.php` en verde; `scripts/i18n_lint.php` sin nada pendiente.
- 21 tests relacionados (idioma, formularios, canvas, onboarding, microcopy,
  SEO, traducción) → **0 fallos**.
- Servidor real con el sitio en francés: login, menú, escritorio y los 5 pasos
  del onboarding revisados a pantalla. `pp.t()` resolviendo en francés en el
  navegador (90 claves `js.`, 5,4 KB por carga).
- Estado de dev restaurado y comprobado: sitio `es`, un solo idioma activo,
  `users.language` a NULL, sin páginas ni formularios residuales.

### Lessons (ADMIN-I18N Fase 1)

- **El linter no lo ve todo, y hay que decirlo:** detecta castellano por acentos
  y signos de apertura. «Las imprescindibles vienen marcadas» no tiene ninguno y
  se coló hasta que la vi en una captura. La verificación visual no es opcional.
- **Trampa seria encontrada en `onboarding.js`:** el código comparaba la
  ETIQUETA visible (`priority === 'Imprescindible'`) para decidir qué páginas van
  premarcadas. Traducirla habría dejado todas las casillas vacías sin que fallara
  ningún test. Se separó en `isEssentialPage()`, que mira los datos
  (`page_type`, `priority`). Buscar este patrón en cada fase: comparar texto
  visible es una bomba de relojería para el i18n.
- **Constantes de PHP no pueden llevar texto traducido**: se evalúan antes de
  saber el idioma. El patrón que ha quedado es guardar la CLAVE en la constante
  y resolverla en un método (`typographyForView()`, `BrandService::variantLabel()`,
  `timezoneOptions()`).
- **Cambiar una constante compartida rompe fases futuras**: al convertir
  `BrandService::LOGO_VARIANTS['label']` en clave, la pantalla de Diseño (Fase 3)
  habría enseñado `brand.logo_light` en crudo. Arreglado en el momento. Antes de
  tocar una constante compartida: `grep` de todos sus usos.
- **El expandidor de prompts tiene su propio marcador**: las regiones de
  `OnboardingController` que construyen prompts van entre `i18n-ignore-start` e
  `i18n-ignore-end`. Son ~100 líneas de castellano que NO se traducen jamás.
- **Los lotes de traducción se truncan**: 40 cadenas por llamada iban bien en
  inglés y se cortaban a media clave en francés (es más largo). Bajado a 25 y
  `max_tokens` a 16000. Si un lote falla, el script no pierde nada: la siguiente
  pasada solo pide lo que falta.
- **Verificar en navegador ESCRIBE en la base de datos**: pasar por el paso 5 del
  onboarding creó una página `Accueil` y un formulario en francés reales, que
  luego hicieron fallar tres tests. No era código roto, era residuo mío. Tras
  verificar en el navegador, revisar `pages`, `page_sections` y `site_languages`.

## Current Status / Progress Tracking (ADMIN-I18N Fase 2 · 10/08/2026, Executor)

**Fase 2 COMPLETA** (más lo que quedaba de la 1). Catálogo: **1.539 claves × 4 idiomas**.

**Superficie cubierta hasta aquí:** onboarding, login, escritorio, navegación,
Ajustes (General + IA + Correo), Conocimiento, Documentos, Medios, banco de
imágenes, Formularios (listado, editor, bandeja), Páginas (mapa, editor clásico,
Page Studio) y Entradas (listado, editor, estudio de creación) — con sus
controladores y sus nueve ficheros JS.

**Verificación:** `scripts/i18n_lint.php` sin nada pendiente y **23 tests
relacionados en verde, 0 regresiones**.

### Lessons (ADMIN-I18N Fase 2)

- **El patrón "una constante, dos consumidores" se repite en todo el proyecto**:
  `MemoryController::FIELDS`, `FormTemplates::catalog()`, `AI_MODELS`,
  `MODEL_PRESETS`, `TYPOGRAPHY`, `TIMEZONES`, `BrandService::LOGO_VARIANTS`.
  Todas alimentan a la vez una pantalla y un prompt (o un valor de BD). La
  solución que ha quedado como estándar: la constante guarda la CLAVE o el
  castellano de la IA, y un método `…ForView()` devuelve la copia traducida.
  Nunca traducir la constante en su sitio.
- **`i18n-ignore-start` / `i18n-ignore-end`** por método es lo que hace manejable
  un controlador como `PageController` (3.400 líneas, 12 métodos que construyen
  prompts). Sin eso el informe del linter era ilegible.
- **Contenido generado ≠ interfaz.** `PageController` tiene textos por defecto
  ('Da el siguiente paso', 'Qué ofrecemos', 'Tu nombre') que acaban DENTRO de la
  página del cliente. No son del panel: deberían salir de `Microcopy` en el
  idioma de la WEB. Se han dejado como estaban y está anotado abajo.
- Cuidado con los `sprintf` al migrar: varios mensajes usaban `%s` posicional y
  hubo que pasarlos a `{variable}` con nombre para que el traductor pueda
  reordenar.

## Current Status / Progress Tracking (ADMIN-I18N Fases 4d y 5 · 11/08/2026, Executor)

Cerradas las dos fases que quedaban. El panel ya no tiene castellano fijo en
ninguna pantalla: `php scripts/i18n_lint.php` sale limpio con los 100+ ficheros
migrados en su lista.

**Fase 4d.** Tienda (`orders`, `order`, `payments`) y Reservas (`index`,
`bookings`, `edit`) más sus controladores. Y once controladores/servicios que
seguían escupiendo mensajes fijos: `PrivacyController`, `PrivacyWizardController`,
`MarketingController`, `SeoController`, `AssistantController`, `AITestController`,
`ModulesController`, `CommerceAdminController`, `BookingAdminController`,
`AIProviderTester` y `LegalPageGenerator`.

Tres decisiones que conviene tener presentes:

1. **Los avisos al admin de los mailers** (`BookingMailer`, `CommerceMailer`)
   estaban fijos en castellano con un comentario que decía «el panel es
   castellano». Ya no lo es, así que ahora salen en el idioma del panel. Ojo al
   detalle: esos correos se envían dentro de la petición de un VISITANTE, sin
   sesión de admin, así que `AdminI18n` resuelve por el idioma principal del
   sitio. Es lo correcto —quien gestiona una web francesa querrá el aviso en
   francés— pero no es lo mismo que la preferencia personal del usuario.
2. **`LegalPageGenerator::TYPES`**: se traduce `label` (lo lee el gestor) y NO
   `title` (es el título de la página que ve el visitante).
3. **`BookingAdminController::WEEKDAYS`** pasa a guardar claves; los nombres de
   los días se resuelven en `weekdayLabels()` al pintar.

**Fase 5 — instalador.** El caso raro: corre antes de que exista sitio o
usuario, así que no hay de dónde deducir el idioma. Solución: selector propio
(ES/EN/FR/PT) en la cabecera del instalador, que guarda la elección en sesión;
si no se toca, manda el `Accept-Language` del navegador. El idioma elegido en el
instalador se propone además como idioma principal del sitio en el paso 3.

Dos regiones marcadas `i18n-ignore` a propósito: la pantalla de pánico de
`install/index.php` (salta antes de que exista catálogo, incluso si falla el
boot) y el runner CLI de `install/migrate.php` (salida de terminal).

## Current Status / Progress Tracking (ADMIN-I18N Fase 6 · 11/08/2026, Executor)

**El linter tenía un agujero y por eso di por cerrada una fase que no lo estaba.**
`scripts/i18n_lint.php` funcionaba con una lista BLANCA (`MIGRATED`): solo miraba
los ficheros que yo iba apuntando al cerrar cada fase. Una capa entera —los
servicios que devuelven mensajes al panel desde debajo de los controladores—
nunca llegó a esa lista, así que el informe salía «nada pendiente» con castellano
fijo dentro. Lo encontré barriendo a mano, no con la herramienta.

**Arreglo de fondo:** la lista pasa a ser de EXCLUSIONES (`NOT_UI`), y son tres,
cada una con motivo: `app/Services/AI/` (prompts), `Microcopy.php` (catálogo del
idioma del SITIO) y `views/public/`. Todo lo demás se vigila. Ahora lo que se
olvida salta en vez de esconderse.

**Lo que faltaba y ya está migrado:** `DesignSystem` (49 claves: etiquetas y
ayudas de todos los tokens), `VisualStyleService`, `CustomFontService`,
`ImageBankService`, `PageTranslator`, `TranslationJobs`, `TranslationWriter`,
`FormTranslator`, `UpdateService`, `UpdateInstallerService`, `SiteAssistantJobs`,
`SiteAssistantPlanner`, `SeoRedirectService`, `Seo404Service`,
`SeoTechnicalAuditService`, `CanvasChatService`, `GapDetector`, `SmtpTransport`,
`MailService`, `VersionService`, `FormStore` y `FormSubmissionService`.

**Dos decisiones nuevas del mismo tipo que las de la Fase 4d:**

1. El **aviso por email de un formulario** lo recibe quien gestiona el sitio, así
   que pasa al idioma del panel, igual que los de Reservas y Tienda.
2. El **motivo de fallo de la autorrespuesta** (`autoresponder_error`) se genera
   en la petición del visitante pero se LEE en la bandeja de Mensajes: va en el
   idioma del panel y queda congelado en la fila.

**Lo que NO se traduce, ahora explícito en el código** (regiones `i18n-ignore`
con el motivo escrito, para que la próxima persona no tenga que deducirlo):
prompts y patrones que leen castellano, excepciones internas, tablas de
transliteración, endónimos de idioma, y todo lo que ve el VISITANTE —contenido
demo de plantillas, banner de cookies, páginas de error, vuelta de Stripe—, que
es idioma del SITIO y su sitio natural es `Microcopy`.

**Caso aparte, decidido y anotado:** los avisos de `CustomBlockSanitizer`
(«data-ppb-icon solo es válido en \<span\>») son diagnóstico técnico sobre el
markup que devolvió el modelo y solo salen en el explorador de prompts. Quedan en
castellano con la nota puesta. Si quieres que se traduzcan, es media hora.

**Segundo punto ciego, del mismo linter:** detectaba castellano SOLO por acentos
y `¿¡`. «Cambios sin guardar», «No se pudo cargar el historial» o «Ruta en el
sitio» no llevan ninguno, así que frases enteras del panel eran invisibles —otras
65, repartidas entre 8 ficheros JS, 8 vistas y una docena de controladores.
`looksSpanish()` añade una segunda pasada: exige DOS palabras funcionales del
castellano dentro de una misma cadena. Da pocos falsos positivos y los que daba
(dos cadenas distintas con código en medio: `'.pp-cb'); if (el) …`) se descartan
mirando si el fragmento lleva código dentro. De paso, el marcador `i18n-ignore`
ahora también vale en un comentario HTML `<!-- -->`, que es como se comenta en
las vistas.

**Trampa que costó un rato:** un comentario que decía «de aquí a `i18n-ignore-end`»
cerraba la región en su propia línea, porque el linter busca el marcador por
`str_contains`. No nombrar los marcadores dentro del texto de un marcador.

## Executor's Feedback or Assistance Requests (ADMIN-I18N)

**Bug preexistente encontrado en Fase 2 (NO tocado, decide tú):** los textos por
defecto que `PageController` mete en las páginas generadas —encabezados como
«Qué ofrecemos» o «Da el siguiente paso», y los campos del formulario
automático— están fijos en castellano. En una web francesa, si la IA falla y se
usa el fallback, el visitante ve castellano. Es el mismo problema que resolvió
FORMS-LANG para los formularios, y su sitio natural es `Microcopy` (idioma de la
web), no el catálogo del panel. Lo dejo señalado en vez de traducirlo al idioma
del gestor, que sería peor.

**Decisión de registro — CERRADA (11/08/2026):** el francés trata de «vous» en
todo el panel. El usuario lo dio por bueno, así que se queda. El portugués tutea.

**Pendiente explícito de la Fase 1, que NO he tocado:** las pestañas *Ajustes ·
IA* (`views/admin/settings/ai.php`, 282 líneas) y *Ajustes · Correo*
(`mail.php`, 320 líneas) con sus controladores. El plan decía «Ajustes» sin
distinguir; he cerrado General, que es la que toca el cliente nuevo, y esas dos
son configuración técnica que se ve una vez. Se pueden hacer al empezar la Fase 2
en un rato.

**Nada bloqueante.** Dos cosas que el Planner debería saber antes de la Fase 1:

1. **Coste de la traducción con IA**: 15 cadenas × 3 idiomas fue una llamada por
   idioma. Las ~2.000 cadenas del panel completo serán unos 50 lotes × 3 = 150
   llamadas. Barato, pero conviene ejecutarlo por fases y no de golpe.
2. **Bug ajeno detectado de paso** (NO tocado, fuera de alcance): en
   `views/admin/settings/index.php` los formularios de añadir/quitar idioma
   adicional están anidados dentro del formulario grande de Ajustes. El parser
   HTML descarta los `<form>` anidados, así que esos botones podrían estar
   enviando al formulario de fuera. El selector nuevo del panel se puso
   deliberadamente FUERA del formulario grande para no heredar el problema.

---

## Lessons (LOGO-PREVIEW)

- **Diseño → previsualización de logos desbordada.** `.pp-logo-slot__preview` era
  `display:grid;place-items:center` con `height:110px` y la `img` con
  `max-width/max-height:100%`. En grid el `max-height:100%` de la imagen no se
  resolvía contra el área de la celda, así que los logos grandes se salían de la
  caja y tapaban el selector de archivo y los botones. Arreglo en
  `admin/assets/css/admin.css` (~11047): contenedor a `display:flex` centrado,
  `height:120px`, `overflow:hidden`, y la `img` con `width/height:auto` +
  `max-width/max-height:100%` (así encaja sin ampliar logos pequeños).
  Verificado en navegador: imagen 163×94 dentro de una caja 250×120.
- Mismo patrón corregido de paso en el paso 2 del onboarding
  (`.pp-onboarding-logo-field>span`, grid → flex); allí el `overflow:hidden` ya
  evitaba el desborde pero podía recortar el logo en vez de contenerlo.

---

# [DESIGN-MANDA] Que la pestaña Diseño mande de verdad — PLAN (11/08/2026, Planner)

## Background and Motivation (DESIGN-MANDA)

El usuario pregunta para qué sirve la paleta de "Diseño" y si "Estilo del sitio"
hace algo, dado que todas las páginas se generan ya con Canvas. La investigación
sobre el código y sobre el sitio de dev (site_id=1) devuelve dos respuestas
incómodas:

1. **La paleta SÍ es el mecanismo real de color de todo el sitio** — el prompt de
   Canvas prohíbe inventar hex y obliga a usar `var(--pp-primary)` y compañía
   (`Actions.php:828`), así que cambiar la paleta recolorea todas las páginas
   canvas ya generadas sin regenerar nada. **Pero el formulario de Diseño no es
   quien la fija:** `DesignSystem::renderHead()` aplica encima el skin inferido
   (`sites.skin_json`) y la paleta del onboarding (`site_palette_custom`), que
   pisan lo que el usuario acaba de guardar.
2. **"Estilo del sitio" está muerto en cualquier sitio con skin** — `renderHead`
   solo emite su CSS si `$skin === null` (`DesignSystem.php:466`).

Verificado en el sitio de dev (que tiene skin y NO tiene paleta a medida):

| Token | Lo que dice el formulario (BD) | Lo que sirve la web |
|---|---|---|
| `colors.text` | `#1c1917` | `#1f2937` (skin) |
| `colors.primary_dark` | `#ea580c` | `#9f3f11` (skin) |
| `typography.scale_ratio` | `1.125` | `1.250` (skin) |
| `buttons.radius` | `8` | `10` (skin) |
| `spacing.radius_card` | `8` | `14` (skin) |

Y la home imprime `<body class="pp-visual-style pp-visual-style--signal-clean">`
pero NO contiene `<style id="pp-visual-style-css">`: la clase es decorativa.

El problema de producto es de confianza: el panel enseña una previsualización en
vivo construida con los tokens crudos, así que el cliente ve cambiar el color en
el panel y no ve cambiar su web. Es peor que si el campo no existiera.

## Key Challenges and Analysis (DESIGN-MANDA)

**C1. Hay tres motores de estilo compitiendo y ninguno gana siempre.**
Cadena actual en `renderHead()`: defaults → `design_system` (formulario) → skin
inferido → paleta del onboarding → tipografías de marca → CSS del estilo visual
(solo si no hay skin). El formulario está en el ESCALÓN MÁS BAJO, justo al revés
del principio que el propio código ya declara dos veces: *"lo que el usuario
decide gana a lo que nosotros deducimos"*.

**C2. El alcance real es mayor que "los colores".** El skin pisa 8 colores, 4
tokens de tipografía (familia titulares, familia texto, escala, peso negrita),
`buttons.radius`, `buttons.shadow` y `spacing.radius_card`. Es la mayor parte de
lo editable del panel. Lo que sí llega hoy: secundario (que no lo usa nada),
éxito, peligro, tamaños base, line-height, paddings y `container_max`.

**C3. No hay que inventar un mecanismo nuevo para los colores.** La paleta a
medida (`site_palette_custom`) YA está por encima del skin, y el onboarding ya
hace el patrón correcto: guarda la paleta Y la vuelca a los tokens del design
system, comentado como *"un único camino de escritura"*
(`OnboardingController::saveCustomPalette`). Diseño debe hacer exactamente lo
mismo al guardar colores. Para lo NO-color (tipografías, radios, sombra) no
existe equivalente: ahí sí hace falta una capa nueva de overrides manuales.

**C4. La previsualización miente y hay que arreglarla en el mismo movimiento.**
`DesignController::render()` pasa `tokens => DesignSystem::load()` y
`cssVars => toCssVars(load())`, ambos crudos. Igual `CanvasController:52`
(`brandVars`). Si no unificamos, arreglaremos el render público y el panel
seguirá contando otra cosa.

**C5. Quitar "Estilo del sitio" tiene una consecuencia real y acotada.** Hoy no
emite nada en sitios con skin (todos los que pasan por onboarding), pero SÍ
emite en sitios sin skin. Y `DesignController::reset()` pone `skin_json = NULL`,
así que un sitio "restablecido" hoy cae en el modo estilo-visual. Al quitarlo,
esos sitios pierden el tratamiento de heroes/secciones de bloques
(`baseVisualCss`) y se quedan con el CSS base. Es aceptable —y coherente con que
todo se hace en Canvas— pero hay que decirlo y comprobarlo, no descubrirlo
después.

**C6. Contraste: decisión cerrada = guardar tal cual y avisar.** Ya existe
`BrandPaletteService::contrastIssues()`, que devuelve la lista de pares que
fallan. Ojo: sus mensajes están en castellano hardcodeado y el panel está
traducido (ADMIN-I18N) → hay que devolver claves/datos, no frases hechas.

**C7. Las tipografías de marca (brandbook) siguen mandando sobre el select.** Es
intencionado y no se toca, pero entonces el panel TIENE que decirlo en el campo,
o reproduciremos el mismo problema de confianza con otro nombre.

## Decisiones cerradas (11/08/2026, usuario)

1. **"Estilo del sitio": se quita del panel.** No es un editor de paleta (su
   color sale de `paletteForSite`), así que quitarlo no resta ninguna palanca:
   la pestaña Colores pasa a ser el editor real.
2. **Contraste: guardar el color tal cual y avisar** en el panel indicando qué
   par falla. Es decisión del usuario, no nuestra.
3. **"Regenerar con IA" respeta lo editado a mano.** La IA recompone el skin,
   pero los campos que el usuario tocó explícitamente se mantienen.

## Riesgos y qué NO hay que romper

- **No cambiar el aspecto de ningún sitio ya publicado sin querer.** T1 es un
  refactor a coste cero: la home debe servir EXACTAMENTE las mismas variables
  antes y después. Es el control de que la extracción está bien hecha.
- **`design.css` y la caché.** Cualquier cambio de tokens exige
  `CacheService::flush($siteId)` (ya lo hace `update()`); los overrides nuevos y
  el borrado del estilo visual también.
- **Sitios sin skin** (instalaciones nuevas, sitios restablecidos): son los
  únicos que ven un cambio visual real al quitar el estilo visual (C5).
- **Instalaciones nuevas divergentes**: recordar `[[install-migration-divergence]]`
  — si se añade una clave de settings nueva, no hace falta migración de esquema
  (settings es clave/valor), pero conviene comprobar que un sitio recién
  instalado sin skin ni paleta sigue pintando bien.
- **No tocar los prompts de IA** en toda esta tarea: el contrato de Canvas con
  `var(--pp-*)` es justo lo que hace que esto funcione.

## High-level Task Breakdown (DESIGN-MANDA)

**T1 — `DesignSystem::effective()`: una sola cadena de precedencia.**
Extraer de `renderHead()` la composición de tokens a un método público
`effective(int $siteId): array` y que `renderHead()` lo use. Sin cambios de
comportamiento todavía.
*Criterio de éxito:* `curl` de la home antes y después produce el MISMO bloque
`:root{...}` (diff vacío).

**T2 — El panel enseña lo efectivo, no lo crudo.**
`DesignController::render()` y `CanvasController::studio()` (`brandVars`) pasan a
`effective()`. El formulario se rellena con lo que la web sirve de verdad.
*Criterio de éxito:* en el sitio de dev, la pestaña Colores muestra `#1f2937` en
Texto (no `#1c1917`) y Tipografía muestra escala `1.250` (no `1.125`).

**T3 — Guardar colores manda: write-through a `site_palette_custom`.**
En `DesignController::update()`, tras validar, volcar los 8 colores a la paleta
(`primary→accent`, `primary_dark→accent_dark`, `accent→accent_2`, `bg`,
`surface`, `text`, `text_muted→muted`, `border→line`) con
`BrandPaletteService::save()` (sin `enforceContrast`), y recoger
`contrastIssues()` para el aviso. Mismo "único camino de escritura" que el
onboarding.
*Criterio de éxito:* poner Texto a `#101010` y guardar → la home sirve
`--pp-text:#101010`; y el paso 2 del onboarding muestra esa misma paleta.

**T4 — Overrides manuales para lo NO-color.**
Clave de settings nueva `design_manual_tokens` (JSON `{categoria:{clave:valor}}`)
con lo que el usuario cambió respecto a la línea base heredada. Se aplica en
`effective()` DESPUÉS del skin y de la paleta, y ANTES de las tipografías de
marca (C7). Si el usuario devuelve un campo a su valor heredado, el override se
borra (idempotente).
*Criterio de éxito:* poner escala `1.125` en el panel → la home sirve
`--pp-font-scale:1.125` aunque el skin diga `1.250`. Y radio de botón, sombra y
radio de tarjeta igual.

**T5 — Aviso de contraste + aviso de tipografía de marca en el panel.**
Mensajes traducidos (claves en `lang/admin/*.php`, los 4 idiomas), no las frases
hardcodeadas de `contrastIssues()`. En los selects de tipografía, nota cuando una
familia del brandbook manda sobre el campo.
*Criterio de éxito:* guardar texto `#cccccc` sobre fondo blanco → se guarda y
aparece el aviso nombrando el par que falla; con una fuente de marca asignada a
titulares, el campo lo indica.

**T6 — `reset()` y `regenerate()` coherentes.**
`reset()` borra también `design_manual_tokens` y `site_palette_custom` (vuelta
real a defaults). `regenerate()` NO los toca (decisión 3).
*Criterio de éxito:* editar a mano → Regenerar con IA → la home sigue sirviendo
lo editado a mano; Restablecer → la home vuelve a los defaults.

**T7 — Quitar "Estilo del sitio" del panel.**
Fuera la sección plegable de `views/admin/design/index.php` y el guardado de
`visual_style` en `update()`. `renderHead()` deja de emitir
`<style id="pp-visual-style-css">` y el `<link>` de sus Google Fonts. Se mantiene
`VisualStyleService::bodyClass()` y el servicio (compatibilidad con lo ya
publicado y con `[class*="pp-visual-style--"]` de `renderCssVars`).
*Criterio de éxito:* la home no trae ese `<style>` ni ese `<link>`; el diff de
variables `:root` es vacío respecto a antes (en sitio con skin). Comprobar
aparte, y dejar anotado, cómo queda un sitio SIN skin (C5).

**T8 — Test de regresión `tests/design_precedence.php`.**
Al estilo de `tests/article_template.php`: monta un sitio de prueba con skin +
paleta + overrides y verifica el orden de precedencia campo a campo, incluido que
un override borrado vuelve a heredar.
*Criterio de éxito:* `php tests/design_precedence.php` → todo PASS.

**T9 — Documentación.** Lessons en el scratchpad + actualizar la memoria del
proyecto con la cadena de precedencia definitiva.

## Project Status Board (DESIGN-MANDA)

- [x] T1 — `DesignSystem::effective()` (refactor sin cambio de comportamiento)
- [x] T2 — El panel y el Studio leen lo efectivo
- [x] T3 — Guardar colores escribe `site_palette_custom`
- [x] T4 — Overrides manuales para tipografía, radios y sombra
- [x] T5 — Avisos de contraste y de tipografía de marca (4 idiomas)
- [x] T6 — `reset()` y `regenerate()` coherentes
- [x] T7 — Quitar "Estilo del sitio" del panel
- [x] T8 — `tests/design_precedence.php`
- [x] T9 — Lessons + memoria

## Executor's Feedback or Assistance Requests (DESIGN-MANDA)

(vacío — pendiente de arrancar T1)

## Decisiones cerradas — ampliación (11/08/2026, usuario + Planner)

**4. Unificar: Diseño es el único sitio donde se edita la identidad visual.** El
plan original hacía que los 11 campos de color MANDARAN, pero dejaba el editor
bueno (colores de marca, extraer del logo, propuestas de la IA con contraste
comprobado) encerrado en el paso 2 del onboarding, un flujo de un solo uso. Se
sube a Diseño y el onboarding pasa a consumir lo mismo.

**Modelo elegido: los campos son la verdad, la IA y el logo son ayudantes.** No
se sustituyen los 11 selectores por tarjetas de paleta; se les añade encima una
fila de colores de marca + dos botones ("Extraer del logo", "Generar paleta")
que RELLENAN esos campos. Un solo modelo mental, nada de dos editores del mismo
dato, y encaja con el write-through de T3 sin inventar otro camino de escritura.

Estado de partida verificado:
- **Tipografías propias: ya resuelto en un 90%.** `DesignSystem::fontOptions()`
  ya devuelve curadas + `custom:{slug}` y el select las pinta con su propia
  letra; la tarjeta de subida y roles está en la misma pantalla. Solo falta que
  el rol no pise el select en silencio (ya en T5) y traducir el "(tuya)"
  hardcodeado de `CustomFontService::fontOptions():155`.
- **Paleta: nada de esto está en Diseño.** `site_brand_palette` y los endpoints
  `/admin/onboarding/extract-logo-colors` y `/admin/onboarding/generate-palette`
  viven solo en `OnboardingController` (`routes.php:141-142`).

## High-level Task Breakdown — ampliación (DESIGN-MANDA)

**T10 — Subir el editor de paleta a Diseño.**
En la pestaña Colores, encima de los campos: fila de colores de marca
(`site_brand_palette`, editable, con "+" y "×") + botones "Extraer del logo" y
"Generar paleta con IA". Elegir una propuesta RELLENA los 11 campos del
formulario (no guarda por su cuenta): el usuario sigue viendo lo que va a
guardar y puede retocar antes.
*Criterio de éxito:* desde Diseño, sin pasar por el onboarding, se puede extraer
del logo, generar propuestas y aplicar una; los campos cambian; al guardar, la
home sirve esos colores.

**T11 — Un solo camino: mover los endpoints y que el onboarding los consuma.**
`extract-logo-colors` y `generate-palette` pasan a `/admin/design/*`
(`DesignController`), y el paso 2 del onboarding apunta a las rutas nuevas
reutilizando el mismo JS. Si el componente no se puede compartir entero sin
enredar, el mínimo irrenunciable es compartir ENDPOINTS y servicio; la
duplicación admisible es de plantilla, nunca de lógica.
*Criterio de éxito:* `grep` de la lógica de generación de paleta aparece en UN
solo controlador; el paso 2 del onboarding sigue funcionando igual que antes
(generar, elegir, guardar).

**T12 — Traducir el "(tuya)" de las tipografías propias.**
Clave en `lang/admin/*.php` (4 idiomas) en vez del literal castellano.
*Criterio de éxito:* con el panel en inglés, el select no dice "(tuya)".

## Project Status Board — ampliación (DESIGN-MANDA)

- [x] T10 — Editor de paleta (marca + logo + IA) dentro de la pestaña Colores
- [x] T11 — Endpoints de paleta en Diseño; el onboarding los consume
- [x] T12 — "(tuya)" traducido en las 4 lenguas

**Orden sugerido:** T1 → T2 → T3 → T4 → T6 → T5 → T7 → T10 → T11 → T12 → T8 → T9.
Primero que el panel MANDE y diga la verdad (T1-T6), luego limpieza (T7), luego
la unificación (T10-T12), y el test de regresión al final, cuando la cadena de
precedencia ya no se vaya a mover.

## Current Status / Progress Tracking (DESIGN-MANDA T1 · 11/08/2026, Executor)

**T1 completada — `DesignSystem::effective()`, refactor a coste cero.**

Cambios:
- `DesignSystem::effective(int $siteId): array` — la cadena de precedencia
  entera (defaults → design_system → skin → paleta a medida → tipografías de
  marca) en UN solo sitio, documentada.
- `renderHead()` la consume. Sigue leyendo el skin aparte porque lo necesita
  como booleano (decide si emite el `<style>` en línea y si aplica la dirección
  visual), no solo por sus tokens.
- `loadSkin()` memorizada por sitio (`$skinMemo`) para que las dos llamadas por
  página no sean dos consultas. `forgetSkin()` se llama donde el skin se
  reescribe: `PersonalityInference::persist()`, el nudge de personalidad y
  `DesignController::reset()`.
- **Hallazgo no previsto en el plan:** la ruta `/design.css` (`routes.php:47`)
  era un CUARTO camino de composición — aplicaba paleta y tipografías pero se
  saltaba el skin. Invisible en la web (el `<style>` en línea la pisa por ser
  posterior), pero servía valores distintos a quien mirase la hoja. Unificada a
  `effective()`.

Verificación:
- Diff de la home antes/después: solo cambian el token CSRF y la marca antispam
  (valores por petición). Las variables `--pp-*` servidas son **idénticas**
  (diff vacío sobre las 29 variables ordenadas).
- `design.css` SÍ cambia, y era el objetivo: converge con el `<style>` en línea.
  Comparadas las 29 variables de ambos: **cero discrepancias** (antes diferían
  en 6: primary_dark, accent, text, font-scale, btn-radius, radius-card).
- `/`, `/inicio-canvas`, `/inicio-dmb2`, `/privacidad`, `/design.css` → HTTP 200.
- `php -l` limpio en los 4 archivos tocados.
- Contraste efectivo vs crudo en site 1: text `#1f2937` vs `#1c1917`, escala
  `1.250` vs `1.125`, radio botón `10` vs `8`. Es exactamente el desajuste que
  T2 va a dejar de esconder en el panel.

**Pendiente de confirmación del Planner para seguir con T2.**

## Current Status / Progress Tracking (DESIGN-MANDA T2-T6, T8 · 11/08/2026, Executor)

**Hechas: T2, T3, T4, T6, T8 y la mitad de T5 (avisos de contraste).**

- **T2** — `DesignController::index()` y `CanvasController::studio()` (`brandVars`)
  leen `effective()`. Verificado en el navegador: el formulario ahora enseña
  `#1f2937` / `1.250` / `10`, que es lo que sirve la web; antes enseñaba
  `#1c1917` / `1.125` / `8`.
- **T3** — `DesignController::syncPaletteFromColors()` vuelca los 8 colores a
  `site_palette_custom` al guardar. SIN `enforceContrast()`: en el onboarding la
  paleta la propone la IA y corregirla tiene sentido; aquí el hex lo ha elegido
  una persona.
- **T4** — Overrides manuales en `design_manual_tokens` para typography, buttons
  y spacing. Piezas nuevas en `DesignSystem`: `inherited()` (la cadena sin lo
  manual), `baseline()` (heredado + tipografías de marca, la vara de medir) y
  `diffManualTokens()`. Dos detalles que costaron pensar y están cubiertos por
  test: la línea base incluye las tipografías de marca (si no, cada guardado
  inventaría un override fantasma en el campo que manda el brandbook), y la
  comparación es laxa (`"10"` del formulario vs `10` del token no es un cambio).
- **T5 (parcial)** — `BrandPaletteService::contrastReport()` devuelve DATOS
  (`pair`/`value`/`min`) en vez de las frases castellanas de `contrastIssues()`,
  que se queda intacta porque su salida va al log y a la IA. 7 claves nuevas en
  los 4 idiomas y un flash `warning` nuevo en la vista. **Pendiente de T5:** la
  nota en los selects de tipografía cuando una familia del brandbook manda.
- **T6** — `reset()` borra también `design_manual_tokens` y la paleta a medida.
  `regenerate()` no los toca, según la decisión 3.
- **T8** — `tests/design_precedence.php`, 12 comprobaciones, todas PASS. Hace
  copia de seguridad de las dos claves de settings que toca y las restaura en un
  `finally`.

Verificación de extremo a extremo (formulario real, no solo servicios):
- Cambiar texto a `#101010` y escala a `1.125` en el panel → la home sirve
  `--pp-text:#101010` y `--pp-font-scale:1.125`. **Ese era el bug de origen.**
- `design_manual_tokens` quedó en `{"typography":{"scale_ratio":"1.125"}}`: solo
  el campo tocado, sin overrides fantasma en los otros 20 campos del formulario.
- Guardar texto `#cccccc` sobre fondo blanco → se guarda Y avisa: *"Colores
  guardados, pero hay contraste bajo: texto sobre el fondo (1.6:1 < 4.5:1)…"*.
- Sitio de dev restaurado a su estado original; test repetido después: TODO OK.

**Nota de paso:** el aviso destapó que `line` (#e5e7eb sobre blanco, 1.2:1) ya
incumplía el mínimo ANTES de todo esto. No lo toco: es un valor por defecto
heredado y arreglarlo es decisión de diseño, no de esta tarea.

**Siguiente:** T7 (quitar "Estilo del sitio"), luego T10-T12 (unificación) y
cerrar T5.

## Current Status / Progress Tracking (DESIGN-MANDA T7, T10-T12, T5 · 11/08/2026, Executor)

**Cerradas todas las tareas del plan (T1-T12).**

- **T7** — "Estilo del sitio" fuera del panel: sección plegable y su script
  eliminados de la vista, `visual_style` ya no se persiste, `renderHead()` deja
  de emitir el `<style>` y el `<link>` de fuentes de la dirección visual.
  `VisualStyleService::bodyClass()` se mantiene (lo ya publicado lo lleva) y los
  parámetros de `renderHead()` siguen en la firma porque muchas llamadas los
  pasan; ahora se ignoran.
- **T10** — Editor de paleta dentro de la pestaña Colores: colores de marca
  editables, "Extraer del logo" y "Generar paleta". Las propuestas RELLENAN los
  campos (no guardan): el usuario ve y retoca antes de guardar.
- **T11** — La lógica bajó a `BrandPaletteService` (`brandColors`,
  `saveBrandColors`, `cleanHexList`, `extractFromLogos`, `propose`,
  `normalizeProposals`, `businessContext`). Los dos controladores solo hacen
  HTTP. Borrados de `OnboardingController` los helpers ya duplicados; sus
  constantes quedan como alias de las del servicio.
- **T5 (cerrada)** — Nota en los campos de tipografía cuando una familia de
  marca manda sobre el select, con el nombre de la familia y cómo soltarla.
- **T12** — El "(tuya)" del select ya no es un literal castellano.

Verificación:
- Panel: editor presente, sin errores de consola, sin desbordamiento
  horizontal; el bloque "Estilo del sitio" ya no existe.
- Flujo real con IA: "Extraer del logo" → `#222429`; "Generar paleta" → 3
  propuestas (`google/gemini-3.1-flash-lite-preview`); aplicar una cambió los
  campos de `#ea580c/#ffffff/#1f2937` a `#3b82f6/#222429/#f4f4f5` y avisó
  "Paleta aplicada. Revisa y guarda.".
- Onboarding paso 2 (forzado con `?step=2`): extracción y generación siguen
  funcionando ya sobre el servicio compartido. La extracción respondió "esos
  colores ya estaban en la lista" porque los había guardado Diseño: la prueba
  de que el almacén es común.
- `grep` de la lógica de propuestas: aparece en UN solo archivo.
- `tests/design_precedence.php`: TODO OK. Rutas públicas 200. Variables `--pp-*`
  servidas idénticas a la línea base de T1.
- Sitio de dev limpio: borradas las claves de prueba.

## Lessons (DESIGN-MANDA)

- **La cadena de precedencia del design system vive en `DesignSystem::effective()`
  y en ningún otro sitio.** Orden: defaults → `design_system` → skin inferido
  (`sites.skin_json`) → paleta a medida (`site_palette_custom`) → overrides
  manuales (`design_manual_tokens`) → tipografías de marca. Si hay que tocar
  quién gana, se toca ahí. `load()` sigue devolviendo lo CRUDO a propósito.
- **Un panel que enseña valores crudos mientras la web pinta valores compuestos
  es peor que un panel sin ese campo.** El síntoma es siempre el mismo: "cambio
  el color y no pasa nada". Antes de añadir una capa que pise tokens, mirar qué
  enseña el formulario.
- **Para calcular un override, la línea base tiene que ser exactamente lo que el
  formulario mostró.** Si la línea base se queda corta (p. ej. sin las
  tipografías de marca), cada guardado inventa overrides fantasma en campos que
  el usuario ni tocó. Y la comparación debe ser laxa: el formulario devuelve
  `"10"` donde el token guarda `10`.
- **Al mover un editor de un flujo a otro, mover la LÓGICA, no la plantilla.**
  Aquí bajó a `BrandPaletteService` y los dos controladores quedaron en HTTP.
  Duplicar la plantilla es tolerable; duplicar la generación de paleta habría
  sido crear el mismo bug en dos sitios.
- **`/design.css` es un consumidor más de los tokens y se olvida.** Componía la
  cadena a medias (sin skin). Cualquier cambio en la precedencia tiene que pasar
  por ahí; ahora usa `effective()` y no se puede desincronizar.

---

## Background and Motivation (MODULOS)

Arranca el trabajo sobre los módulos adicionales. Primer paso pedido por el
usuario: retirar el "Módulo de prueba" (`hello`). Nació en FEAT-3 F0.1 para
validar el sistema de módulos (tarjeta on/off + dos rutas que dan 404 al
apagarlo). Ese sistema ya lo ejercitan los tres módulos reales
(Analytics, Booking, Commerce), así que la tarjeta solo confunde a quien abre
"Módulos" en un sitio en producción.

## Project Status Board (MODULOS)

- [x] M0 — Borrar el módulo de prueba `hello` de código, catálogo, traducciones y tests.

## Current Status / Progress Tracking (MODULOS M0 · 12/08/2026, Executor)

Hecho:
- Borrado `app/Modules/Hello/` (controller + routes).
- `ModuleRegistry::MODULES`: fuera la entrada `hello`. El catálogo queda en 3.
  Actualizado el ejemplo del docblock (usaba `Hello\HelloController` para
  explicar el autoloader; ahora `Analytics\AnalyticsController`).
- Traducciones: fuera `hello.active`, `hello.body.html`, `module.hello.label` y
  `module.hello.description` en `lang/admin/{es,en,fr,pt}.php`.
- `tests/modules_registry.php`: la cobaya del flag pasa a ser `booking`
  (activar/desactivar no toca datos y se restaura el valor original). Añadido un
  check de que `hello` ya NO está en el catálogo.
- Migración `database/migrations/2026_08_12_drop_hello_module.sql`: barre el flag
  `module_hello_enabled` de `settings`. Sin la entrada en el catálogo
  `isEnabled()` ya devolvía false, pero la fila quedaría como basura.

Verificado:
- `php tests/modules_registry.php` → ALL PASS (14 checks).
- `php scripts/i18n_lint.php` → "OK — nada pendiente", sin claves usadas y no
  definidas, sin castellano a pelo.
- Panel en el navegador (`/admin/modules`): tres tarjetas (Analítica propia,
  Reservas y calendarios, PromptCommerce), ninguna de prueba.
- Rutas del módulo borrado: `/admin/modules/hello` → 404 y
  `/_module/hello/ping` → 404.
- Migración aplicada en el sitio dev: en `settings` solo quedan
  `module_analytics_enabled`, `module_booking_enabled`, `module_commerce_enabled`.

## Project Status Board (MODULOS) — ampliación

- [x] M1 — Repaso de la interfaz del módulo Reservas: arreglar controles sin CSS
      y comprobar el flujo completo antes de que el usuario pruebe.

## Current Status / Progress Tracking (MODULOS M1 · 12/08/2026, Executor)

Arreglado (todo en `admin/assets/css/admin.css` salvo el último punto):

1. **Campo de alta rápida sin estilo** — `.pp-booking-new__form input[type="text"]`
   solo tenía `flex:1`. No vive dentro de un `.pp-form-group`, así que no
   heredaba nada: salía con el aspecto nativo del navegador (borde inset gris,
   Arial 13px, sin padding) al lado de un botón con estilo. Ahora lleva el mismo
   borde, radio, padding, tipografía y foco que el resto. Lo comparte la Tienda
   (alta rápida de producto), así que se arregla en los dos sitios.
2. **Tarjeta "Reservas desde otras webs" descuadrada** — `.pp-card` base es
   `display:flex; align-items:center` (nació para las tarjetas de métricas del
   escritorio), y esta tarjeta tiene título + texto + formulario: los tres
   salían en fila, uno al lado del otro. Añadido `display:block` + márgenes,
   igual que ya hacían `.pp-stripe-card` y `.pp-order-block`.
3. **El formulario de alta se encogía al contenido** dentro de esa tarjeta flex
   (hueco de ~140px para el nombre del servicio): `flex:1` en el form y
   `max-width:460px` en el input.
4. **Horas y fechas del editor sin estilo** — el bloque base de inputs no
   incluye `time` ni `date` en su lista de tipos, y estos además están fuera de
   `.pp-form-group`. Estilados `.pp-booking-range input[type=time]` y los
   `date`/`time` de las excepciones. Las filas que añade el JS heredan igual.
5. **Selects del filtro de "Reservas recibidas" sin estilo** — mismo caso.
   Los comparte Pedidos de la tienda.
6. **La píldora de estado sin color no parecía una píldora** —
   `.pp-status-pill` nacía con fondo y borde transparentes, así que "Pendiente"
   salía como texto en negrita al lado de un "Confirmada" verde. Ahora el
   estado neutro es gris. Afecta a los cinco sitios que la usan a pelo
   (Pendiente, Inactivo, Borrador, Sin configurar, Próximamente) y en todos era
   la misma intención.
7. **El aviso al confirmar/cancelar mentía** (esto no es CSS):
   `BookingAdminController::bookingStatus` decía siempre "Hemos avisado al
   cliente por email", incluso en un sitio sin SMTP donde el email se marca
   `skipped` y nadie recibe nada. `BookingMailer::sendStatusChange()` y
   `deliverToCustomer()` ahora devuelven el resultado ('sent' | 'skipped' |
   'failed') y el panel elige el texto: nuevas claves
   `bk.ok.{confirmed,cancelled}_{no_mail,mail_failed}` en los 4 idiomas.

Verificado (sitio dev, navegador):
- `/admin/booking`: campo de alta con estilo, tarjeta de integración apilada.
- `/admin/booking/services/3`: horas con estilo; añadir franja y excepción por
  JS genera los `name` correctos (`hours[1][1000][start]`, `exceptions[...]`) y
  las filas nuevas heredan el estilo.
- `/admin/booking/reservas`: selects con estilo, "Pendiente" gris vs
  "Confirmada" verde. Confirmar una reserva avisó "No hemos avisado al cliente:
  este sitio todavía no tiene email configurado" (correcto: dev sin SMTP).
- Sin regresiones donde se reutilizan esas clases: Tienda, Pedidos, Métodos de
  pago ("Sin configurar" ahora es una píldora gris), Módulos.
- **Flujo externo completo**: web de prueba servida en `http://localhost:8797`
  (origen ya en la allowlist) con el snippet del panel → el widget pinta días y
  huecos, y una reserva enviada desde ahí llegó al panel. CORS correcto
  (`Access-Control-Allow-Origin` al origen concreto). API con clave: `services`
  y `availability` 200; link de cancelación del email (`/_booking/cancel/{id}?token=`)
  pinta bien y confirma antes de cancelar.
- Tests: `booking_services`, `booking_availability`, `booking_api`,
  `booking_emails`, `booking_microcopy`, `modules_registry` → todos PASS.
  `scripts/i18n_lint.php` → "OK — nada pendiente".
- Datos de prueba borrados: `booking_bookings` vuelve a 0 filas.

## Executor's Feedback or Assistance Requests (MODULOS)

No arreglado a propósito (decidir antes de tocar):

- **La tienda tiene el MISMO bug del aviso**: `order.ok.status_changed` promete
  "Hemos avisado al cliente por email" sin mirar si salió
  (`CommerceAdminController` ~línea 271). Se arregla igual que Reservas, pero es
  otro módulo.
- **La API pública devuelve `origin_not_allowed` tanto si el origen no está en
  la lista como si la clave es incorrecta.** Depurar un embed ajeno a ciegas es
  incómodo (me pasó al revisar). Separar los dos errores es fácil, pero es
  tocar el contrato de una API pública: ¿lo separamos?
- **Un día sin franjas no dice nada** en el editor de horario: la fila queda
  vacía y "+ Añadir franja" se va al borde derecho, lejos de las horas. Se lee
  "no hay nada configurado" pero no "cerrado". Un texto "Cerrado" y el botón
  junto a las franjas mejoraría la lectura.
- **No hay forma de poner el calendario en una página del propio sitio**: el
  widget solo existe como snippet para webs externas (no hay bloque/sección de
  reservas ni acción del asistente). Si el módulo va a crecer, este es el hueco
  gordo.

## Background and Motivation (MODULOS M2)

El módulo Reservas solo sabía vivir FUERA: el calendario existía como snippet
`<script>` con clave de API para pegar en webs ajenas. Para ponerlo en una
página de la propia web había que copiar HTML a mano — imposible para el
público objetivo de PromptPress. Encargo del usuario: que se pueda poner el
calendario en las páginas de la web, "cuidando cómo hacerlo" porque quien lo va
a usar no es técnico y tiene que poder añadirlo y controlarlo fácilmente. Junto
con eso, arreglar los bugs detectados en la revisión previa (M1).

## Key Challenges and Analysis (MODULOS M2)

- **Hay DOS clases de página y las dos tienen que servir.** Las canvas (HTML
  libre + chat) y las estructuradas (secciones). El precedente exacto ya estaba
  en el repo: la tienda se embebe con la sección `form`/el placeholder
  `{{products:featured}}` + una pista al prompt (`modulesHint`). Se ha copiado
  ese patrón en vez de inventar uno nuevo.
- **Nadie debe ver un id.** El selector de servicio se rellena con los servicios
  REALES del sitio (nombre + duración). Y el valor por defecto es "automático":
  la sección recién añadida ya funciona sin configurar nada.
- **Reutilizar el widget, no reescribirlo.** El JS del calendario ya estaba
  probado contra la API pública. Se le ha añadido un segundo modo de montaje
  (contenedores `[data-pp-booking]`) conservando el clásico (`data-service` en
  el `<script>`), así que los embeds que ya existan en webs ajenas siguen igual.
- **En el propio sitio no hace falta clave**: mismo origen, el guard CORS lo deja
  pasar sin API key ni lista de orígenes. El gestor no ve nunca un snippet.
- **Lo que no se puede pintar, no se pinta.** Módulo apagado, sin servicios
  activos o servicio elegido desactivado → la sección devuelve cadena vacía y el
  placeholder deja un comentario HTML. Nunca un calendario roto en la web.

## Project Status Board (MODULOS) — ampliación

- [x] M2 — Calendario de reservas en las páginas del propio sitio (sección +
      placeholder canvas + pista al asistente).
- [x] M3 — Los cuatro bugs pendientes de la revisión M1.

## Current Status / Progress Tracking (MODULOS M2-M3 · 12/08/2026, Executor)

### El calendario en tus páginas (M2)

Piezas nuevas:
- `app/Modules/Booking/BookingEmbedRenderer.php` — única fuente del embed:
  `embeddableServices()` (activos del sitio), `resolveServiceId()` (vacío = el
  primero activo; id desactivado o ajeno = null) y `render()` (contenedor +
  widget). Un servicio elegido y luego desactivado hace DESAPARECER el
  calendario, no cambiarlo por otro sin avisar.
- `public/js/pp-booking-widget.js` — refactor a `mount(root, …)`: modo A (el
  `<script>` lleva `data-service`, webs externas, compatible con lo ya pegado
  fuera) y modo B (contenedores `[data-pp-booking]`, la propia web). Varios
  calendarios en una misma página no se pisan (estado por montaje) y el
  atributo `-ready` evita montar dos veces. Los colores pasan a salir de las
  variables del design system con los valores antiguos como respaldo: en tu web
  el calendario usa TU paleta; en una web ajena queda igual que siempre.
- Sección `booking` ("Calendario de reservas"): schema en `SectionSchemas`,
  `SectionRenderer::renderBooking()`, dos variantes (calendario solo / con
  título y texto), icono en `sections-editor.js`, CSS público en `DesignSystem`.
- Placeholder `{{booking:N}}` / `{{booking:auto}}` / `{{booking:auto|days=7}}`
  en `CanvasService`, y `modulesHint()` ampliado: por eso "pon un calendario
  para pedir cita" en el chat del studio ahora inserta el bloque real.
- Bloque "En tu propia web" en el panel de Reservas, ANTES del snippet externo:
  los dos caminos (añadir la sección / pedírselo al asistente) contados en dos
  líneas, sin código a la vista.

Decisiones que conviene no perder:
- El catálogo de tipos se recorta por sitio: `SectionSchemas::allForSite()` y
  `SectionController::sectionTypesForView($siteId)` esconden `booking` con el
  módulo apagado, y los prompts de estructura de página usan la lista recortada
  (antes la IA podía proponer un tipo que se pintaría vacío). `all()` sigue
  siendo el catálogo completo a propósito: describe los schemas al modelo y no
  debe depender de la BD.
- El contenedor del calendario NO va vacío: adelanta nombre, duración y
  "Cargando disponibilidad…". Se ve mientras carga y, sobre todo, en la
  previsualización del editor, cuyo iframe es `sandbox="allow-same-origin"` y no
  ejecuta scripts. Sin eso, el gestor añadía un calendario y su vista previa
  decía "necesitas activar JavaScript", que parece un error.
- Esa previsualización además ya no emite `<script>` (se quitan en
  `variantPreview`): no se podían ejecutar y ensuciaban la consola del panel con
  "Blocked script execution".

### Bugs arreglados (M3)

1. **La tienda prometía un email que no salía** — mismo arreglo que Reservas:
   `CommerceMailer::sendStatusChange()` devuelve 'sent'|'skipped'|'failed' y el
   panel elige el texto (`order.ok.status_changed_{no_mail,mail_failed}`).
2. **La API pública confundía dos errores distintos** — `origin_not_allowed` se
   devolvía tanto por origen no permitido como por clave incorrecta. Ahora la
   clave mala responde `invalid_api_key`. No filtra nada: la lista de orígenes
   no es secreta y el atacante ya controla su origen.
3. **Un día sin franjas no decía nada** — aviso "Cerrado" (servidor + JS al
   añadir/quitar) y "+ Añadir franja" pegado a las horas en vez de volando en el
   borde derecho.
4. **15 etiquetas del panel pintaban la clave en crudo** — `AdminI18n::jsCatalog()`
   solo exportaba el prefijo `js.` y se ignoraba el sufijo `_js`, que existe
   justo para eso. Se veía "nav.design_js" en el editor de secciones y afectaba
   también al editor de header/pie, al studio y al banco de imágenes.
5. **De propina**: `normalizeEditedSectionHtml()` solo revertía `form` y `posts`,
   así que un embed de `products` se BORRABA al editar la sección en vivo. Ahora
   revierte los cuatro tipos (hay test).

Verificado:
- Tests: nuevo `tests/booking_embed.php` (42 checks: catálogo, resolución del
  servicio, HTML del embed, la sección y sus variantes, el placeholder y su ida
  y vuelta, el catálogo filtrado por módulo y TODO el comportamiento con el
  módulo apagado) → ALL PASS. Sin regresiones en booking_*, commerce_*,
  canvas_*, modules_registry, design_precedence, admin_i18n, site_language*.
  `tests/admin_i18n.php` actualizado: comprobaba que al JS solo viajaran claves
  `js.`, que era justo el bug 4.
- `scripts/i18n_lint.php` → "OK — nada pendiente" (nuevas claves en es/en/fr/pt).
- Navegador, camino "sección": añadida desde el desplegable en una página real,
  el selector ofrece el servicio por su nombre, la vista previa enseña el avance
  del calendario, y en la web pública se pinta el calendario con la paleta del
  sitio. **Reserva completada desde la propia web, sin clave de API**, y llegó
  al panel.
- Navegador, camino "canvas": `{{booking:auto}}` guardado en una página canvas
  publicada → el calendario real se pinta (el placeholder sobrevive al
  sanitizado porque se expande en render, después de sanear). Página restaurada
  a su contenido original.
- API: origen no permitido → `origin_not_allowed`; clave mala → `invalid_api_key`;
  clave buena → 200; preflight sin clave → 204.
- Editor de horario: "Cerrado" en los seis días sin franjas y sincronizado al
  añadir/quitar una franja.
- Consola limpia en la web pública. Datos de prueba borrados (0 reservas, 0
  secciones booking, página canvas restaurada).

## Executor's Feedback or Assistance Requests (MODULOS M2)

- El widget sigue teniendo un ancho fijo de 420px (`max-width` en su CSS). En la
  variante "con título y texto" queda bien; en "calendario solo" se ve centrado
  y algo pequeño en pantallas grandes. Si se quiere un calendario "a lo ancho"
  (semana completa en rejilla) es otro diseño, no un ajuste.
- No hay forma de poner DOS servicios en la misma página con un selector para
  que el visitante elija; se pueden poner dos secciones, una por servicio. Si
  hace falta el selector, decidirlo antes de que alguien monte la página a mano.

## Current Status / Progress Tracking (MODULOS M4 · 12/08/2026, Executor)

El usuario avisó de lo que yo había dado por hecho: **en su sitio TODAS las
páginas menos las entradas son canvas**. Al comprobarlo en serio (no solo el
render, sino el flujo de trabajo real en el Studio) salieron cuatro cosas.

1. **Faltaba el botón.** El Studio ya tenía "AÑADIR A LA PÁGINA → + Formulario"
   en la barra lateral: ESE es el gesto que conoce el gestor en canvas, no el
   chat. Añadido **"+ Calendario"** al lado, con menú de servicios por su nombre
   ("Automático" + cada servicio con duración y precio). Nuevo endpoint
   `POST /admin/canvas/{id}/insert-booking` (`CanvasController::insertBooking`),
   calcado de `insertForm`: inserta `{{booking:N}}` en su propia sección después
   de la parte seleccionada (o al final), versiona y lo cuenta en el chat. Si el
   gestor no elige servicio se guarda `{{booking:auto}}`, que sigue funcionando
   si mañana cambia cuál es el primer servicio activo. Sin el módulo activo el
   botón no se pinta.
2. **BUG GORDO pre-existente: el Studio entero corría con `pp` sin definir.**
   `canvas-studio.js` llama a `pp.t()` 94 veces, pero el Studio es una vista
   standalone que NO carga `admin.js` (donde vivía `pp.t`) ni `window.PP_I18N`:
   cada llamada lanzaba `ReferenceError: pp is not defined` y cortaba el script
   a media función. Por eso mi primer intento de insertar no hacía nada. Arreglo:
   `pp.t` sale de `admin.js` a `admin/assets/js/pp-i18n.js`, y lo cargan tanto
   `admin/layout` como el Studio (que ahora también emite `PP_I18N`). El
   comentario que había en `admin.js` decía que servía "también a las pantallas
   standalone como el Canvas Studio" — la intención estaba, el cableado no.
3. **`data-pp-label` se escribía y nadie lo leía.** `insertForm` lo ponía desde
   FORMS-R y `listSections()` seguía derivando el nombre del id, así que la parte
   insertada aparecía como "Booking 3 cf4f44f6" (y los formularios, como
   "Form 12 ab12cd34"). Ahora manda `data-pp-label` en el servidor
   (`listSections`) y en el overlay del iframe (etiqueta al pasar el ratón,
   selección y lista lateral, que ahora recibe `{id,label}`). Se lee
   "Calendario: test".
4. **La caché de páginas no se invalidaba al tocar un servicio.** El embed lleva
   escritos el nombre y la duración del servicio, y las páginas se cachean como
   HTML: renombrar un servicio dejaba el nombre viejo, y desactivarlo dejaba un
   calendario que luego decía "no disponible" en vez de desaparecer. `ServiceStore`
   ahora hace flush igual que `ProductStore` con {{products:featured}}.

Verificado:
- Studio de una página canvas real: "+ Calendario" → menú con "Automático" y
  "test · 60 min" → insertado al final, la conversación lo cuenta, aparece como
  parte 8 "Calendario: test" y **el calendario funciona en vivo dentro del
  iframe del Studio** (2 días, 4 huecos). Página restaurada a su contenido
  original.
- Consola del Studio limpia (antes: dos `ReferenceError` por carga).
- Panel normal tras partir el i18n en dos ficheros: `pp.t` existe, 600 claves,
  `pp.t('nav.design_js')` → "Diseño", sin errores de consola.
- `tests/booking_embed.php` ampliado con el nombre visible de la parte (con y
  sin `data-pp-label`) → ALL PASS. Suite completa (21 ficheros de test, incluido
  todo canvas_*) sin un solo FAIL. `i18n_lint` limpio.

## Lessons (MODULOS)

- **Antes de dar por bueno un flujo, hay que recorrerlo en la pantalla donde vive
  el usuario, no solo comprobar que el render sale bien.** El calendario se
  pintaba perfecto en la web pública y en el editor de secciones; lo que faltaba
  era el botón para ponerlo, y el Studio estaba lanzando excepciones desde antes.
- **Una vista standalone paga aparte todo lo que el layout da gratis** (i18n del
  navegador, aquí). Si se añade una pantalla sin `admin/layout`, hay que revisar
  qué scripts del layout necesita su JS.
- **Un atributo que se escribe y nadie lee es un bug esperando.** `data-pp-label`
  llevaba desde FORMS-R puesto en el HTML y sin usar.
- **Todo bloque que incruste datos del sitio en HTML cacheado obliga a invalidar
  la caché cuando esos datos cambian.** `ProductStore` ya lo hacía; `ServiceStore`
  no, y el calendario acababa de convertirse en el segundo caso.

## Current Status / Progress Tracking (MODULOS M5 · 12/08/2026, Executor)

Petición del usuario: sin ningún servicio creado no tiene sentido enseñar cómo
meter las reservas en la web; y esa parte pesaba demasiado en la pantalla cuando
lo importante del apartado es GESTIONAR servicios y reservas.

Reordenada `/admin/booking`:
- **Sin servicios**: la pantalla es un solo paso. Tarjeta con "Crea tu primer
  servicio reservable", el campo del nombre y una línea de qué es un servicio
  (`bk.lead_empty` cambia el lead de la cabecera). NO se pinta nada de publicar,
  ni el botón "Reservas recibidas" (no puede haber ninguna).
- **Con servicios**: el alta rápida se mete DENTRO de la tarjeta del listado
  (una caja menos, la tabla manda), y las dos formas de publicar se juntan en un
  ÚNICO `<details>` plegado al final ("Poner el calendario en una web"), con el
  patrón `pp-seo-advanced` que ya usa el panel para lo secundario. Dentro:
  primero tu web (dos líneas, sin código) y luego las webs ajenas con clave y
  snippet.
- **Integración de la gestión**: las reservas pendientes se ven ya en esta
  pantalla — el botón "Reservas recibidas" lleva el número y se pone en primario
  cuando hay algo que confirmar. `pendingCount()` se extrajo a un método privado
  y lo comparten `index()` y `bookings()`.

El mismo criterio, aplicado a los otros dos sitios donde se ofrecía el
calendario (esto es la parte de "integrar todo un poco más"):
- El tipo de sección "Calendario de reservas" desaparece del editor si el módulo
  está activo pero NO hay servicios activos (antes aparecía con un texto de
  ayuda diciendo que fueras a crearlos: una vía muerta).
- El botón "+ Calendario" del Studio tampoco se pinta sin servicios (antes salía
  con un menú que solo decía "créalos en Reservas").
- `modulesHint` ya lo hacía: sin servicios no anuncia el placeholder a la IA.
  Ahora los tres coinciden.

Verificado:
- Sin servicios (ocultando temporalmente el del sitio dev y restaurándolo con su
  MISMO id y su horario): pantalla de un solo paso, sin publicar y sin botón de
  recibidas; Studio sin "+ Calendario"; catálogo de secciones sin el tipo.
- Con servicio: listado dominando la pantalla, publicar plegado en una línea, y
  al desplegarlo los dos bloques en orden. Studio con "+ Calendario" de vuelta.
- Con una reserva pendiente: el botón pasa a primario y dice "Reservas
  recibidas · 1"; la columna "Reservas próximas" del listado marca 1.
- `tests/booking_embed.php` cubre ahora el caso "módulo activo sin servicios"
  desactivando TODOS los servicios del sitio y restaurándolos (comprueba además
  que quedan como estaban) → ALL PASS. Suite y `i18n_lint` sin fallos.
- Servicio del sitio dev intacto (id 3, activo, horario del lunes) y 0 reservas.

## Lessons (MODULOS) — ampliación

- **Una pantalla de gestión no puede pesar lo mismo en lo que se hace a diario y
  en lo que se configura una vez.** Aquí: servicios y reservas al frente,
  publicar plegado. El patrón para lo secundario ya existía (`pp-seo-advanced`).
- **Si una acción no se puede completar, no se ofrece.** Un botón que al abrirse
  solo dice "primero crea otra cosa" es peor que no estar: el estado vacío de la
  pantalla dueña del dato ya lo explica. Y el criterio tiene que ser el MISMO en
  todos los sitios donde se ofrece (aquí eran tres, y solo uno lo cumplía).
- **Una caché estática por proceso en un servicio de catálogo miente en los
  tests** (y en cualquier flujo que cree el dato y vuelva a leerlo en la misma
  petición). Si lo que ahorra son dos consultas indexadas, no vale la pena.

## Current Status / Progress Tracking (MODULOS M6 · 12/08/2026, Executor)

Petición: el horario semanal era incómodo. Para "lunes a viernes de 8 a 16"
había que repetir el mismo gesto cinco veces, día a día.

Añadido un **horario rápido** encima de la rejilla de la semana
(`views/admin/booking/edit.php` + `booking-service-editor.js` + CSS):
- Fila de días como chips (Lu Ma Mi Ju Vi Sá Do) que se marcan y desmarcan, con
  dos atajos: **Lunes a viernes** y **Todos los días**.
- Hora de inicio y de fin, y **Aplicar a los días marcados**.
- Aplicar SUSTITUYE las franjas de esos días (es lo que se espera al decir "L-V
  de 8 a 16"), pero si alguno ya tenía horario se pregunta antes: un horario a
  medio configurar puede ser un rato de trabajo.
- Después se puede retocar cualquier día por separado y añadir más franjas
  (mañana y tarde) donde haga falta: la rejilla de siempre no cambia.

Detalles que importan:
- Los campos del horario rápido NO llevan `name`: no se envían. Lo único que se
  guarda son las franjas de la rejilla, que es lo que el atajo rellena. Así el
  backend no se ha tocado (`ServiceStore::update` y su validación siguen igual).
- Las etiquetas de los chips salen de los días ya traducidos (`mb_substr` de 2
  letras): funciona en los cuatro idiomas sin una lista nueva.
- Los avisos van al lado del botón (no en `alert`): "Marca primero los días",
  "La hora de inicio debe ser anterior a la de fin", y al aplicar "Horario
  aplicado a 5 días. Recuerda guardar."
- El texto de sustituir tiene singular y plural (`js.bk.quick_overwrite_one/many`).

Verificado en navegador:
- Caso del usuario: "Lunes a viernes" + 08:00–16:00 + Aplicar → las cinco filas
  quedan con la franja, sábado y domingo siguen "Cerrado". Preguntó antes de
  pisar el lunes, que ya tenía horario.
- Caso mixto: sábado 10:00–13:00 puesto a mano después del atajo. Guardado real
  → `booking_hours` tiene L-V 08–16 y sábado 10–13, y la API de disponibilidad
  devuelve 8 huecos de lunes a viernes y 3 el sábado.
- Errores: sin días, sin horas y horas invertidas avisan y no tocan nada.
- Sin errores de consola. Horario del servicio del sitio dev restaurado a como
  estaba (solo lunes 10:00–14:00).
- Tests de booking, módulos, i18n y diseño sin fallos; `i18n_lint` limpio.

## Current Status / Progress Tracking (MODULOS M7 · 12/08/2026, Executor)

Tres avisos del usuario tras usar el Studio en un sitio en francés.

### 1. El calendario hablaba castellano en una web francesa

Dos causas encadenadas, las dos arregladas:

- **`booking_services.language` tiene DEFAULT 'es' y nadie lo fijaba al crear**,
  así que en una web francesa TODOS los servicios nacían en castellano. Y ese
  idioma no es solo cosmético: se copia a cada reserva
  (`booking_bookings.language`) y de ahí salen los EMAILS al cliente y su página
  de cancelación. `ServiceStore::create()` ahora lo fija con el idioma del sitio,
  y la migración `2026_08_12_booking_service_language.sql` alinea los que ya
  existen (se puede: el idioma del servicio no se elige en el panel, no hay
  campo, así que cualquier valor distinto es el defecto de la columna).
- **El widget preguntaba a la API sin decir en qué idioma se le está leyendo.**
  Ahora el embed emite `data-lang` con el idioma de la PÁGINA
  (`SectionRenderer::$lang` en la sección, `$pageLang` en el placeholder canvas),
  el widget lo manda como `?lang=`, y la API lo antepone al idioma del servicio.
  Precedencia: `?lang=` (página) → idioma del servicio (webs ajenas, que no
  pueden mandarlo) → idioma del sitio.
- Cierre del bucle: al reservar, el widget manda también `lang`, la reserva se
  guarda con ese idioma (`BookingService::bookingLanguage()`) y el mensaje de
  respuesta vuelve en él. Antes el mensaje se recalculaba desde el servicio y
  volvía en castellano en una página francesa.

### 2. El chip del chat del Studio mostraba "cv.ask_change"

`canvas-studio.js` pinta el chip con `pp.t('cv.ask_change')`, pero esa clave no
lleva prefijo `js.` ni sufijo `_js`, así que no viaja al navegador y `pp.t()`
devuelve la clave. Se veía bien al cargar (lo pinta el servidor) y se rompía en
cuanto algo repintaba el chip. Renombrada a `cv.ask_change_js` en los 4 idiomas,
la vista y el JS — que es justo para lo que existe el sufijo. Repasadas TODAS las
llamadas `pp.t()` del panel: era la única clave fuera de convención.

### 3. La IA anunciaba "Durée 15 minutes" al lado de un calendario de 60

`modulesHint()` le daba al modelo el id y el nombre del servicio, pero no la
duración ni el precio, así que escribía el texto de alrededor a ojo. Ahora la
pista lleva la ficha real de cada servicio (`3 = "test" (60 min, 30 €)`) y le
dice explícitamente que el calendario YA muestra esos datos, que si los menciona
use exactamente esos, y que NUNCA invente duración, precio, horarios ni plazas.

Verificado:
- API: `?lang=fr` manda sobre el idioma del servicio; un `lang` basura se ignora
  sin romper.
- Navegador, página francesa real con el calendario: días ("lun. 17/8"), huecos
  ("4 créneaux"), formulario ("Votre nom *", "Réserver à 10:00") y confirmación
  ("Réservation reçue…") en francés, con el servicio guardado en castellano.
  La reserva quedó guardada con `language='fr'`.
- Studio: el chip dice "Pídeme un cambio" al cargar, al minimizar y al reabrir.
- Migración probada poniendo el sitio en francés: el servicio pasó a `fr`; sitio
  y servicio restaurados a `es` después.
- Tests nuevos en `booking_api.php` (precedencia de `lang`, idioma guardado en la
  reserva y mensaje de vuelta) y en `booking_embed.php` (data-lang del embed,
  fallback con idioma no soportado, y que un servicio nace con el idioma del
  sitio). 17 ficheros de test sin fallos; `i18n_lint` limpio.
- Datos de prueba borrados (0 reservas).

## Lessons (MODULOS) — ampliación

- **Un DEFAULT de columna es una decisión de producto escondida.** `language
  DEFAULT 'es'` convirtió todas las webs francesas en webs con servicios
  españoles, y el síntoma visible (el calendario) era el menos grave: lo gordo
  eran los emails al cliente.
- **Si un dato tiene un dueño más específico, ese manda.** El idioma de la
  PÁGINA sabe más que el del servicio sobre en qué idioma se está leyendo; el
  del servicio sigue valiendo donde no hay página (webs ajenas).
- **Al darle a la IA un bloque real (calendario, productos), hay que darle
  también sus DATOS.** Si no, rellena el texto de alrededor a ojo y contradice
  al bloque que el propio sistema pinta al lado.

## Background and Motivation (MODULOS M8)

Petición: que los campos que se le piden al cliente al reservar se puedan editar
por servicio — un predeterminado, pero pudiendo añadir y cambiar campos. El
formulario estaba cableado en el JS del widget (nombre, email, teléfono, notas)
y cada negocio necesita lo suyo: matrícula, nº de personas, alergias, "acepto
las condiciones"…

Decisiones cerradas con el usuario antes de empezar:
- **Nombre y email fijos**; teléfono y notas configurables (mostrar/ocultar,
  obligatorio, renombrar) + campos propios encima.
- **Tipos**: texto, texto largo, teléfono, email, número, fecha, desplegable y
  casilla. Sin subida de archivos (arrastra límites, tipos y borrado).

## Current Status / Progress Tracking (MODULOS M8 · 13/08/2026, Executor)

- Migración `2026_08_13_booking_custom_fields.sql`: `booking_services.fields_json`
  (QUÉ se pide) y `booking_bookings.extra_json` (QUÉ contestó el cliente). Las
  dos NULL-ables: NULL = el formulario de siempre, así que lo ya instalado sigue
  igual sin tocar nada.
- `app/Modules/Booking/BookingFields.php`, con toda la lógica: `defaults()`,
  `forService()`, `normalize()` (sanea definiciones), `forWidget()` (lo que ve el
  cliente, con etiquetas traducidas) y `validate()` (la comprobación que MANDA).
- Panel: sección "Datos que pides al cliente" en el editor de servicio, con el
  aviso de que nombre y email son fijos, las dos filas base y un repetidor de
  campos propios (etiqueta, tipo, opciones si es desplegable, obligatorio).
- Widget: pinta el formulario a partir de la definición que sirve la API, marca
  en rojo el campo que el servidor rechaza y le pone el foco.
- Las respuestas se ven en "Reservas recibidas" y viajan en el aviso por email
  al gestor (`{extra}` en `bk.mail.admin_new_body`).

Decisiones que conviene no perder:
- **La validación de verdad es la del servidor**, contra lo GUARDADO. El widget
  puede pintar lo que quiera: un POST a mano sin los obligatorios, con una opción
  inventada en un desplegable o con un campo que nadie definió se rechaza o se
  ignora (hay test de los tres casos).
- **Las respuestas se guardan con su etiqueta al lado del valor.** Si mañana se
  borra el campo "Matrícula", las reservas viejas se siguen leyendo.
- **La clave del campo es estable** (se deriva de la etiqueta una vez y se
  conserva en un `hidden`): renombrar la etiqueta no huérfana lo ya contestado.
- Un campo oculto no puede ser obligatorio; un desplegable sin opciones cae a
  texto; las claves reservadas (`email`, `name`, `lang`…) se prefijan para no
  chocar con el cuerpo JSON de la API.

Cosas que aparecieron al probar y hubo que arreglar:
1. **Una casilla desmarcada no se envía**, así que "no pedir teléfono" no se
   guardaba nunca: cada casilla lleva ahora un `<input type="hidden" value="0">`
   delante.
2. **El SELECT de la API no traía `fields_json`**, así que servía siempre los
   campos por defecto aunque el servicio tuviera otros.
3. **La etiqueta del teléfono seguía diciendo "(opcional)"** al hacerlo
   obligatorio: se compone con `booking.field_phone` + `(opcional)`/`*` según
   esté configurado (claves nuevas, porque `booking.ph_*` lleva el sufijo pegado).
4. **La casilla del widget se estiraba al 100%** y echaba su texto fuera de la
   tarjeta: era orden de reglas CSS con la misma especificidad.
5. `tests/booking_microcopy.php` saltaba por una variable JS llamada `el` (la
   confundía con el artículo). Renombrada a `node`: mejor eso que aflojar el test.

Verificado:
- `tests/booking_fields.php` nuevo (31 checks): defaults, normalización de
  definiciones sucias, los seis casos de validación, respuestas guardadas y la
  ida y vuelta por la BD. ALL PASS. Resto de la suite sin fallos; `i18n_lint`
  limpio en los cuatro idiomas del panel y con las cadenas del cliente en los
  seis del widget.
- Navegador: configurados teléfono obligatorio, notas fuera y tres campos
  propios (texto, desplegable y casilla, los tres obligatorios) → guardados →
  el formulario público los pinta en orden y en francés → el servidor rechaza
  con el campo marcado en rojo → una reserva correcta guarda las respuestas y se
  ven en el panel.
- Servicio del sitio dev devuelto a su configuración por defecto y 0 reservas.

## Lessons (MODULOS) — ampliación

- **Una casilla sin marcar no existe para el navegador.** Cualquier "quitar
  esto" hecho con checkbox necesita un hidden delante o el "no" no llega nunca.
- **Al añadir una columna hay que repasar los SELECT con lista explícita.** Aquí
  la API pedía columnas una a una y se quedó sin la nueva: el código estaba bien
  y el resultado, mal.
- **Guardar la etiqueta junto al valor** convierte un dato configurable en un
  dato legible para siempre, aunque la configuración cambie después.

## Current Status / Progress Tracking (MODULOS M9 · 14/08/2026, Executor)

Petición: que los emails de confirmación se puedan editar por servicio (con un
predeterminado que se pueda adaptar), y que el módulo se entere de si hay o no
correo configurado — avisando y llevando a la pantalla de ajustes.

### Emails editables por servicio

- Migración `2026_08_14_booking_service_emails.sql`: `booking_services.emails_json`.
  NULL = las plantillas de siempre, así que nada cambia para lo ya instalado.
- `app/Modules/Booking/BookingEmails.php`: los tres mensajes al cliente
  (`received`, `confirmed`, `cancelled`), con `defaultTemplate()` (la de
  siempre, expresada con tokens), `normalize()` y `render()`.
- `BookingMailer` ya no compone texto: pide el mensaje a `BookingEmails` y se
  queda con lo suyo (a quién, el .ics de las confirmadas, el registro de envío).
- Editor: sección "Emails al cliente" con los tres plegados, insignia
  Estándar/A medida, el mensaje por defecto como `placeholder` (se ve lo que se
  enviará sin escribir nada), "Partir del mensaje estándar" y "Volver al
  estándar".

Decisiones que conviene no perder:
- **Los tokens tienen el MISMO nombre en todos los idiomas del panel**
  (`{cliente} {servicio} {fecha} {precio} {sitio} {detalles} {cancelar}
  {respuestas}`). Un token traducido rompería la plantilla al cambiar de idioma.
- **La plantilla por defecto se compone con `Microcopy::template()`, no con
  `t()`**: `t()` limpia los `{token}` sin valor y colapsa espacios, así que
  devolvía "Hola ," y una plantilla mutilada. Ya existía el método para esto.
- **Si el gestor quita `{cancelar}`, el cliente se queda sin enlace para anular
  y NO se le añade por detrás.** Añadirlo a escondidas convertiría la plantilla
  en una caja negra; el editor avisa en cuanto el texto se queda sin el token.
- Guardar sin escribir nada deja `NULL`, no un JSON vacío.
- Efecto lateral asumido: el email de "confirmada por el gestor" y el de
  "confirmada al reservar" comparten ahora plantilla (antes se diferenciaban en
  una frase). Son el mismo mensaje para el cliente y ahora se edita una sola vez.

### Aviso de correo sin configurar

`MailService::isConfigured()` decide, y el aviso sale en las TRES pantallas
donde importa: el listado de servicios (al entrar), Reservas recibidas (donde se
pulsa Confirmar) y el editor de emails (donde no tendría sentido escribir un
mensaje que no se va a enviar). Todos con el botón "Configurar el correo" a
`/admin/settings/mail`.

**Bug encontrado al probarlo**: el aviso aparecía y a los cinco segundos
desaparecía. `admin.js` auto-oculta TODOS los `.pp-alert` a los 5 s (nacieron
como mensajes flash), y un aviso permanente no puede irse solo. Ahora se
respetan los marcados con `data-pp-persist`.

Verificado:
- `tests/booking_email_templates.php` (19 checks): por defecto = lo de siempre y
  traducido, lo reescrito manda, tokens sustituidos, sin huecos con tokens
  vacíos, saneado, JSON corrupto → defaults, y guardar en blanco no deja basura.
  ALL PASS; resto de la suite sin fallos; `i18n_lint` limpio.
- Navegador: plantilla a medida escrita y guardada; el render real usa la del
  servicio en `received` y las de siempre en los otros dos. El aviso de "sin
  correo" sigue en pantalla pasados 6 s.
- Servicio del sitio dev devuelto a su estado (sin campos ni emails a medida) y
  0 reservas.

## Lessons (MODULOS) — ampliación

- **Un "aviso permanente" y un "mensaje flash" no pueden compartir componente.**
  Aquí el auto-dismiss de los flash se comía la advertencia de configuración.
- **Antes de escribir un helper, buscar si ya existe.** `Microcopy::template()`
  estaba justo para esto (plantilla con tokens intactos) y su docblock ya
  explicaba el bug que yo acababa de cometer con `t()`.

---

# FEAT-RESOURCES — Recursos y ebooks descargables (2026-08-25, Planner)

## Background and Motivation (FEAT-RESOURCES)

Se necesita poder publicar en una web en producción recursos descargables —el
primer caso concreto son ebooks— y gestionarlos desde PromptPress como contenido
propio. El recurso puede ser gratuito con descarga directa, entregarse después
de completar un formulario o, más adelante, asociarse a una compra.

PromptPress no tiene un registro genérico de tipos de contenido personalizables
tipo WordPress. Las entidades actuales tienen funciones concretas: `pages`
contiene páginas y artículos, `media` es una biblioteca de imágenes,
`documents` es conocimiento privado para la IA y `commerce_products` representa
productos simples físicos. Reutilizar cualquiera de ellas para PDFs públicos
mezclaría permisos, interfaz y ciclos de vida que hoy están deliberadamente
separados.

Decisión de Planner: crear un módulo pequeño e independiente llamado
**Recursos**, siguiendo el patrón de `Analytics`, `Booking` y `Commerce`. La v1
resuelve descarga directa y captación mediante formulario; deja un punto de
extensión claro para venta digital, pero no implementa aún la entrega de compras
ni un sistema genérico de Custom Post Types.

## Key Challenges and Analysis (FEAT-RESOURCES)

### 1. Entidad y alcance correctos

- Un recurso no es una página genérica ni un documento de contexto para IA.
  Necesita título, slug, descripción, portada, archivo, tipo/tamaño, categoría
  opcional, idioma, modo de acceso y estado borrador/publicado.
- `cover_media_id` puede reutilizar una imagen existente de `media`; el archivo
  descargable debe tener almacenamiento y validación propios.
- V1 admite PDF/EPUB. No se incluirán ZIP ni archivos ejecutables para mantener
  una superficie de subida pequeña. El límite de tamaño será configurable desde
  una única constante y se verificará tanto por extensión como por MIME real.
- Multiidioma debe formar parte del esquema inicial (`language` y
  `translation_group`), aunque la primera interfaz cree recursos en el idioma
  principal del sitio. Añadirlo después produciría el mismo tipo de deuda ya
  corregida en páginas, productos y reservas.

### 2. Descarga segura y estable

- Los archivos vivirán fuera del acceso público directo, bajo
  `storage/resources/{site_id}/`, con denegación web equivalente a
  `storage/documents`. Nunca se enlaza el path físico desde el HTML.
- Toda descarga pasa por un controller que comprueba sitio, recurso publicado y
  modo de acceso; responde con `Content-Type`, `Content-Length`,
  `Content-Disposition` y `X-Content-Type-Options: nosniff`.
- La URL pública usa el slug/id lógico. Así se puede sustituir el archivo sin
  romper enlaces y registrar la descarga en Analytics sin guardar IP ni datos
  personales nuevos.
- Borrar o sustituir archivos debe ser conservador: primero confirmar que el
  recurso pertenece al sitio y que ningún flujo lo está usando; la eliminación
  de una fila debe retirar también su archivo físico, con error visible si no se
  puede completar de forma coherente.

### 3. Dos modos de acceso en v1

- `direct`: el botón llama directamente al endpoint de descarga.
- `form`: el recurso referencia un formulario existente de `FormStore`. La ficha
  renderiza ese formulario con un contexto firmado que vincula
  recurso+formulario+caducidad. Solo después de que `FormController` valide y
  guarde una respuesta válida devuelve una URL de descarga temporal.
- El enlace temporal se firma con HMAC derivada de `app_key`; no necesita una
  nueva tabla de sesiones. Incluirá recurso, envío y expiración. No pretende ser
  DRM, sino evitar que saltarse el formulario sea la ruta normal.
- La respuesta inmediata mostrará el botón de descarga. Como mejora separada,
  el autoresponder podrá aceptar un token `{{descarga}}` cuando el envío tenga
  contexto de recurso; no se debe acoplar este token al primer corte si retrasa
  el flujo principal.
- Un recurso no puede publicarse en modo `form` sin un formulario válido del
  mismo sitio. Si el formulario se elimina de forma suave, la ficha del recurso
  debe mostrar un estado no disponible y nunca abrir una descarga directa por
  accidente.

### 4. Publicación dentro de páginas

- El módulo tendrá catálogo `/recursos` y ficha `/recursos/{slug}` con el shell,
  design system, idioma y analítica ya usados por la tienda.
- Para insertar listados en páginas Canvas se seguirá el patrón existente:
  `{{resources:featured|limit=3|heading=Recursos}}`, expandido por un renderer
  dinámico y reversible mediante `data-pp-placeholder`.
- El Studio y la IA solo ofrecerán ese bloque si el módulo está activo y existe
  al menos un recurso publicado. Si no hay contenido utilizable, no se ofrece
  una acción que termina en una vía muerta.
- Los cambios de recursos invalidarán la caché de páginas porque el listado se
  expande a HTML al renderizar, igual que `products:featured`.

### 5. Relación futura con Commerce

- No se añadirá `purchase` como opción incompleta en la interfaz v1.
- La ampliación futura correcta será dar a `commerce_products` un tipo
  `physical|digital` y relacionar el producto digital con un recurso/archivo.
  Tras un pedido `paid`, Commerce emitirá enlaces firmados ligados al pedido y
  con límites configurables. El módulo Recursos seguirá siendo dueño del archivo
  y Commerce será dueño del derecho de acceso.
- Esta separación evita duplicar catálogo, pago, pedido y archivo, y permite que
  el mismo ebook pase de gratuito a vendido sin cambiar su almacenamiento.

### 6. Compatibilidad, privacidad y producto

- Activar el módulo no publica nada automáticamente; nace vacío y los recursos
  nacen como borrador.
- Las descargas directas pueden registrarse como evento agregado
  `resource_download` en Analytics. No se crea una tabla de tracking propia en
  v1 salvo que aparezca una necesidad real de auditoría individual.
- La captación usa el consentimiento, retención, anti-bot, bandeja de respuestas
  y avisos por email que ya pertenecen a Formularios; Recursos no los replica.
- Migración e instalación nueva deben quedar alineadas: cualquier tabla/columna
  se añade a `database/migrations` y a `install/schema.sql`.
- Antes de ejecutar, el usuario debe invocar `@web` según la regla del proyecto
  para contrastar documentación actual de subida/descarga segura de PHP y
  cabeceras HTTP. El resultado se documentará en `cursor/resources-api.md` antes
  de escribir el endpoint de archivos.

## High-level Task Breakdown (FEAT-RESOURCES)

### R0 — Investigación y contrato aprobado

- Invocar `@web` y documentar en `cursor/resources-api.md`: detección MIME,
  subida segura, nombres de descarga UTF-8, cabeceras HTTP, protección de
  almacenamiento y límites prácticos PDF/EPUB.
- Cerrar el contrato v1: extensiones, tamaño máximo, campos, URLs y comportamiento
  exacto de `direct` y `form`.
- **Éxito:** documento breve revisado por el usuario; ninguna decisión de
  seguridad o producto pendiente antes de migrar la BD.

### R1 — Esquema y store, con TDD

- Escribir primero tests del store: aislamiento por `site_id`, borrador por
  defecto, slug único por sitio/idioma, idioma y grupo de traducción, validación
  de acceso, formulario del mismo sitio y borrado conservador.
- Crear tabla `resources` en migración e `install/schema.sql`; implementar
  `ResourceStore` y el alta/edición de metadatos sin subir aún archivos.
- **Éxito:** tests del store pasan y el esquema migración↔instalación es
  equivalente. El usuario verifica solo el modelo de campos antes de continuar.

### R2 — Almacenamiento y descarga directa

- Escribir tests de validación de upload, pertenencia, traversal, MIME/extensión,
  sustitución, archivo ausente y respuestas de descarga.
- Implementar `ResourceFileService`, directorio protegido, subida/sustitución y
  endpoint de descarga directa con cabeceras seguras.
- **Éxito:** un PDF y un EPUB válidos se descargan con su nombre; un archivo
  inválido, un recurso ajeno, borrador o path manipulado no se sirven. Usuario
  prueba manualmente una descarga antes de avanzar.

### R3 — Administración del módulo

- Registrar `resources` en `ModuleRegistry`, rutas con guard y navegación
  condicional.
- Crear listado, alta y edición: título, descripción, portada mediante el media
  picker existente, categoría, archivo, idioma, modo de acceso, formulario y
  borrador/publicado. CSS únicamente en `admin/assets/css/admin.css`.
- Estados vacíos: primero crear un recurso; las opciones de publicación se
  muestran cuando hay al menos uno.
- **Éxito:** ciclo admin alta→borrador→publicar→sustituir archivo→despublicar
  funciona en navegador, y módulo apagado devuelve 404 y desaparece del menú.

### R4 — Catálogo y ficha públicos

- Escribir tests HTTP de visibilidad, slug/idioma, 404, portada ausente y modo de
  acceso.
- Construir `/recursos` y `/recursos/{slug}` reutilizando shell, DesignSystem,
  chrome, hreflang/SEO donde proceda y Analytics.
- **Éxito:** catálogo y ficha heredan el diseño real del sitio; solo aparecen
  recursos publicados del idioma correcto y Lighthouse/HTML no presenta errores
  básicos de accesibilidad.

### R5 — Descarga condicionada por formulario

- Escribir tests de contexto firmado: form/recurso/sitio correctos, expiración,
  manipulación, formulario eliminado, envío inválido y reuso del enlace.
- Renderizar el formulario asociado en la ficha; extender el resultado de un
  envío válido para devolver una URL temporal y mostrar el botón sin recargar.
  Mantener intactos los envíos normales sin contexto de recurso.
- **Éxito:** no existe URL de descarga antes del envío; tras un envío válido se
  guarda el lead y se obtiene el ebook; falsificar contexto o usar otro formulario
  falla. Usuario prueba el flujo completo como visitante sin sesión admin.

### R6 — Bloque dinámico para páginas y Studio

- Añadir renderer y placeholder canónico
  `{{resources:featured|limit=N|heading=...}}`, reversión al guardar, pista IA y
  botón/selector del Studio condicionado a recursos publicados.
- Invalidar caché en crear/editar/publicar/eliminar recursos.
- **Éxito:** una página Canvas muestra tarjetas reales, el Studio conserva el
  placeholder tras editar y guardar, y con módulo vacío/apagado no deja huecos
  ni ofrece el bloque.

### R7 — Integraciones y cierre de v1

- Registrar `resource_download` en Analytics con etiqueta no personal
  (id/slug lógico), revisar traducciones del panel y web pública y ejecutar la
  suite completa.
- Mejora opcional y separable: token `{{descarga}}` en autoresponder para envíos
  originados desde un recurso.
- Documentar la futura integración Commerce `physical|digital` sin implementarla.
- **Éxito:** suites nuevas y regresión pasan, `i18n_lint` limpio, sin datos de
  prueba, y el usuario valida descarga directa y mediante formulario en móvil y
  escritorio. Solo Planner anuncia la finalización tras esa verificación.

## Project Status Board (FEAT-RESOURCES)

- [x] R0 Investigación `@web` + contrato de la v1 — aprobado por el usuario 25/08/2026
- [x] R1 Esquema + `ResourceStore` con TDD — aprobado por el usuario 25/08/2026
- [x] R2 Archivos protegidos + descarga directa — aprobado por el usuario 25/08/2026
- [x] R3 Panel de administración del módulo — aprobado por el usuario 25/08/2026; validación manual agrupada al final en producción
- [x] R4 Catálogo y ficha públicos — verificado localmente; validación manual agrupada al final en producción
- [x] R5 Entrega condicionada por formulario — verificada end-to-end; validación manual agrupada al final en producción
- [x] R6 Bloque dinámico para páginas y Studio — verificado localmente; validación manual agrupada al final en producción
- [x] R7 Analytics, i18n, regresión y cierre — implementación verificada; pendiente validación final del usuario en producción
- [x] R8 Disponibilidad multidioma y diagnóstico en Studio — implementación y QA local verificadas; pendiente revalidación del usuario en producción
- [x] R9 Idioma de página en bloques Studio — implementación y QA local verificadas; pendiente revalidación del usuario en producción
- [x] R10 Deduplicación contextual de heading en Recursos — implementación y QA local verificadas; pendiente revalidación del usuario en producción
- [x] R11 Enlaces públicos según idioma real de página — implementación y QA local verificadas; pendiente revalidación del usuario en producción

## Current Status / Progress Tracking (FEAT-RESOURCES · 25/08/2026, Planner)

- Arquitectura actual revisada sin modificar funcionalidad.
- Confirmado que no existe un sistema genérico de Custom Post Types y que
  `documents`, `media` y `commerce_products` no deben asumir este caso.
- Alcance recomendado de v1: módulo Recursos, PDF/EPUB, portada reutilizada de
  Media, descarga directa, descarga tras formulario, catálogo/ficha y bloque
  Canvas. Venta digital queda expresamente fuera de v1.
- Plan preparado; ninguna tarea de implementación iniciada.

## Current Status / Progress Tracking (FEAT-RESOURCES R0 · 25/08/2026, Executor)

- Investigación actualizada completada con documentación oficial de PHP,
  IANA, W3C EPUB, RFC 6266/9110, Apache/Nginx y la guía de seguridad de OWASP.
- Creado `cursor/resources-api.md` con el contrato v1: PDF+EPUB, máximo de
  producto 20 MiB pero límite efectivo del hosting visible, validación EPUB OCF,
  almacenamiento protegido, nombres internos aleatorios y descarga por streaming.
- Cerrado el diseño propuesto de descarga directa y descarga tras formulario:
  contexto HMAC, enlace temporal de 24 horas y comportamiento normal intacto
  para formularios sin contexto de recurso.
- Entorno comprobado: PHP 8.4.11 y Fileinfo activo; el desarrollo tiene
  `upload_max_filesize=10M`, de modo que ahora su límite efectivo es 10 MiB hasta
  cambiar la configuración del servidor.
- R0 sigue sin marcar como completada hasta que el usuario revise y apruebe el
  contrato. No se han creado migraciones ni tocado funcionalidad.

**Aprobación:** el usuario aprobó avanzar el 25/08/2026; R0 marcada completada.

## Current Status / Progress Tracking (FEAT-RESOURCES R1 · 25/08/2026, Executor)

- TDD: `tests/resources_store.php` se escribió antes del store; el primer run
  falló al no existir aún `resources`/`ResourceStore`, como esperaba el contrato.
- Añadida `database/migrations/2026_08_25_resources.sql` y bloque idéntico en
  `install/schema.sql`: una tabla `resources`, FKs de sitio/portada/formulario,
  unique por sitio+idioma+slug e índices público/traducción/formulario.
- Implementado `app/Modules/Resources/ResourceStore.php`: create/find/all/update/
  delete, borrador por defecto, update parcial, slug estable y desambiguado por
  idioma, UUID de traducción, relaciones limitadas al sitio y reglas de
  publicación. R1 solo gobierna metadatos; no mueve archivos.
- Publicar exige los cuatro metadatos de un PDF/EPUB permitido y, en modo
  `form`, un formulario activo del mismo sitio. Un formulario eliminado nunca
  degrada a descarga directa.
- `tests/resources_store.php`: **34/34 PASS**. Regresiones
  `tests/site_language.php` y `tests/commerce_products.php`: PASS.
- Equivalencia normalizada migración↔instalación: PASS. Fixtures retiradas:
  `resources=0`, media/form temporales=0 e idioma inglés dev restaurado a off.
- La ejecución del Migrator aplicó correctamente esta migración y cuatro
  migraciones anteriores que existían en el repo pero aún no constaban como
  aplicadas en dev (booking language, drop hello, custom fields y emails); cero
  errores.
- R1 queda pendiente de validación del modelo de campos por el usuario antes de
  marcarla en el board y empezar R2.

**Aprobación:** el usuario confirmó el modelo de campos el 25/08/2026; R1
marcada completada.

## Current Status / Progress Tracking (FEAT-RESOURCES R2 · 25/08/2026, Executor)

- TDD: creado `tests/resources_files.php`; el primer run falló porque aún no
  existía `ResourceFileService`, confirmando que el test precedió a la solución.
- Implementado `ResourceFileService`: límite efectivo del hosting, errores
  `UPLOAD_ERR_*` con diagnóstico, extensión+Fileinfo, firma `%PDF-` y estructura
  OCF mínima de EPUB sin descomprimirlo, nombre físico aleatorio de 64 hex,
  reemplazo seguro y limpieza tras error.
- Directorio `storage/resources` protegido con `.htaccess`; la protección se
  autocura en runtime porque los paquetes de actualización excluyen este árbol
  para no copiar/sobrescribir ebooks de una instalación.
- Preparados `ResourceDownloadController` y las rutas principal/multiidioma. No
  están expuestas todavía: R3 registrará `resources` en `ModuleRegistry` y
  conectará el panel bajo el guard por sitio.
- Descarga preparada por streaming de 64 KiB, sin cargar el ebook entero, con
  MIME/bytes, `filename` ASCII + `filename*` UTF-8, `nosniff`, `private,no-store`
  y `Accept-Ranges:none`. Borradores, modo formulario, traversal, archivo ausente
  o metadatos incoherentes no se sirven.
- Ciclo de vida cubierto: `SiteResetService` borra filas y carpeta por sitio;
  build package y updater excluyen `/storage/resources` para preservar datos.
- Los mensajes de error se migraron a 33 claves en los cuatro idiomas del panel
  (`es/en/fr/pt`) en lugar de silenciar el linter.
- `tests/resources_files.php`: **29/29 PASS**; `resources_store`: 34/34 PASS;
  `modules_registry`, catálogo admin i18n y regresiones de microcopy: PASS;
  `i18n_lint`: limpio; `git diff --check`: limpio.
- Limpieza verificada: tabla `resources=0`, sin binarios de prueba; solo
  `.htaccess` y `.gitkeep` bajo el directorio raíz.
- R2 queda pendiente de conformidad del usuario. La descarga HTTP manual se hará
  al final de R3, cuando exista un recurso administrable y el módulo pueda
  activarse sin exponer una pantalla incompleta.

## Executor's Feedback or Assistance Requests (FEAT-RESOURCES)

### R4 completada localmente (25/08/2026)

- Añadidos catálogo `/recursos`, ficha `/recursos/{slug}` y sus variantes con
  prefijo de idioma, todas bajo el guard del módulo.
- La consulta pública está aislada por sitio, idioma y estado publicado. La
  ficha de un borrador o de otro idioma devuelve 404.
- El shell reutiliza DesignSystem, header, footer y Analytics; añade canonical,
  hreflang y Open Graph. Los estilos propios viven en
  `public/css/resources.css`, sin CSS inline del módulo.
- UX revisada con la skill de diseño y en navegador real: catálogo editorial de
  dos columnas, fallback PDF/EPUB sin imagen rota, ficha asimétrica con acción
  clara, estados vacío/foco/reduced-motion y adaptación a una columna móvil.
- Seguridad de transición: una ficha en modo `form` explica el requisito pero
  no expone el endpoint directo hasta que R5 conecte el formulario.
- `tests/resources_public.php`: 21 checks HTTP/store/UI, ALL PASS. Regresiones
  `resources_store` 34/34, `resources_files` 29/29, `resources_admin` 12/12,
  `modules_registry` y `site_language`: PASS. Linter i18n y diff: limpios.
- Fixtures visuales retiradas (`resources=0`) y servidor local detenido.

No se solicita validación manual intermedia: por indicación del usuario, se
agrupará toda la revisión en su página de producción al finalizar R7. Siguiente
tarea autorizable: R5, entrega condicionada por formulario.

### R5 completada localmente (25/08/2026)

- La ficha condicionada incrusta el formulario seleccionado con su contenido,
  campos, traducción, validaciones, RGPD, anti-bot y estilos reales; un
  formulario borrado muestra indisponibilidad y nunca degrada a acceso directo.
- Añadido `ResourceAccessService`: contexto HMAC de 6 h ligado a
  sitio+recurso+formulario y token de descarga de 24 h ligado además al id de
  respuesta guardada. Clave derivada de `app_key` con propósito independiente
  `resource-download-v1`; tokens manipulados, caducados o cruzados devuelven
  404 sin revelar la causa.
- `FormController` conserva intacto su ciclo previo y solo concede acceso tras
  guardar correctamente la respuesta. Formularios normales o contextos
  inválidos siguen funcionando sin `download_url`.
- Con JS, la descarga comienza automáticamente, queda un enlace «Descargar de
  nuevo» y el formulario se retira del estado final para reducir ruido. Sin JS,
  el POST redirige al enlace firmado tras guardar.
- `tests/resources_form_delivery.php`: 21 checks, incluyendo ciclo HTTP ficha →
  POST → binario, cabeceras seguras, token inválido/caducado/cruzado, contexto
  inválido y fallback sin JS; ALL PASS.
- Regresiones `resources_public/files/store/admin`, `botguard_submit`,
  `forms_language`, `modules_registry`, sintaxis JS/PHP, i18n y diff: PASS.
- Revisión en navegador real con la skill de diseño: formulario alineado con la
  jerarquía de la ficha, etiquetas sobre controles, privacidad visible y estado
  de éxito reducido a confirmación + re-descarga. Dos entregas reales 200.
- Fixtures retiradas: `resources=0`, formulario/respuestas/binario temporales
  eliminados y servidor detenido.

La revisión manual sigue agrupada para producción al cerrar R7. Siguiente
tarea: R6, bloque dinámico para páginas y Studio.

### R6 completada localmente (25/08/2026)

- TDD: `tests/resources_embed.php` nació antes de la implementación y falló en
  11 contratos. Tras implementar, cubre módulo apagado, límite/idioma/estado,
  placeholder canónico, reversión de edición, enlaces reales, pista IA, Studio
  condicionado e invalidación de caché; **13 comprobaciones, ALL PASS**.
- Añadido `FeaturedResourcesRenderer` y soporte Canvas para
  `{{resources:featured|limit=N|heading=...}}`. El render usa publicaciones
  reales del idioma de la página, nunca inventa tarjetas y carga
  `public/css/resources.css` solo cuando el bloque produce contenido.
- Studio muestra `+ Recursos` únicamente si el módulo está activo y hay
  publicaciones en el idioma de esa página. Selector breve de cantidades
  reales (1/3/6, acotadas a las disponibles), inserción tras la parte activa o
  al final, confirmación humana y sección movible/borrable en el índice.
- UX guiada por `design-taste-frontend`: jerarquía editorial de dos columnas,
  etiquetas claras, foco/active/reduced-motion, una columna móvil y estados
  vacíos sin vías muertas. En navegador se corrigió además el singular
  «El recurso publicado más reciente» detectado durante la revisión real.
- Guardar una sección desde edición en vivo revierte el HTML expandido al
  placeholder canónico. Crear, editar, publicar, despublicar o eliminar un
  recurso invalida la caché del sitio; un catálogo vacío/apagado no deja hueco.
- Prueba real de Studio sobre página temporal: botón condicionado → selector →
  insertar 2 → preview con dos fichas enlazadas → mensaje de confirmación →
  sección visible en «Partes de esta página». Escritorio y móvil revisados;
  al retirar las publicaciones desapareció el botón y el bloque quedó vacío.
- Regresiones en serie: `resources_store/files/admin/public/form_delivery`,
  `canvas_runtime` y `site_language`, además de lint PHP/JS y
  `git diff --check`: PASS. Página, recursos y versiones temporales retirados.

La revisión manual continúa agrupada para producción al cerrar R7. Siguiente
tarea del board: R7, Analytics, i18n, regresión final y entrega para validación.

### R7 implementada y verificada localmente (25/08/2026)

- TDD: nuevo `tests/resources_analytics.php`. Primer ciclo falló al no existir
  el evento; segundo ciclo descubrió que el dashboard enseñaría el identificador
  técnico. Tras corregir ambos, **13 comprobaciones, ALL PASS**.
- Cada descarga válida registra `resource_download` solo si Analytics está
  activo y después de validar recurso, permiso/token y archivo. La etiqueta es
  `/recursos/{slug}/descargar`: estable y sin query, token, email ni datos del
  formulario. Descargas 404 y Analytics apagado no registran nada.
- Dashboard traducido en `es/en/fr/pt`: «Descarga de recurso» y equivalentes.
  `i18n_lint` informa cero claves ausentes y cero castellano literal en panel;
  `admin_i18n`, microcopy pública, emails, idiomas y Booking: PASS.
- Ampliado `cursor/resources-api.md` con el contrato futuro Commerce
  `physical|digital`: Recursos posee el binario; Commerce, precio/pedido/derecho
  de acceso. No se implementa venta digital ni se mezcla con esta v1.
- El token opcional `{{descarga}}` de autoresponder se mantiene fuera de v1:
  R5 ya entrega por UI y fallback sin JS; añadir semántica de email justo antes
  de producción ampliaría superficie sin ser requisito de aprobación.
- Regresión total ejecutada en serie: **83/83 archivos PASS**. El test de update
  se ejecutó declarando entorno de desarrollo, nunca con `--force`; confirmó
  ZIP inválido/checksum, despliegue, rollback, mantenimiento y huellas intactas.
- Lint PHP/JS, `git diff --check`, i18n y limpieza: PASS. Fixtures finales:
  recursos R6/R7=0, página temporal=0, eventos temporales=0; storage conserva
  solo `.gitkeep` y `.htaccess`.
- Generado `deliverables/promptpress-resources-v1-20260825.zip`: 1007 archivos,
  2,5 MB, sin secretos ni datos de `storage/resources`. SHA-256:
  `6506df41e58975b065e9726ba6d4f309fa434c6ebd8abfc625c89c201eb79fa5`.

La implementación queda lista para la validación agrupada del usuario en
producción (directa, mediante formulario, Studio, móvil/escritorio y Analytics).
Solo Planner anunciará el cierre definitivo tras esa confirmación.

### R8 implementada y verificada localmente (25/08/2026)

- Incidencia reproducida desde producción: el editor ocultaba el idioma cuando
  solo había uno activo y Studio ocultaba silenciosamente `+ Recursos` si el
  idioma interno de la página no coincidía con el del recurso.
- TDD: `tests/resources_languages.php` se escribió primero y falló por ausencia
  de alcance/pivote y de `visibleLanguages`. Tras implementar, sus 10 contratos
  pasan: uno, varios y todos los idiomas; `todos` incluye idiomas que se activen
  después; el editor siempre muestra la disponibilidad y Studio explica el
  desajuste.
- Añadida migración `2026_08_25_resources_languages.sql` y equivalente en
  `install/schema.sql`: `resources.language_scope` (`selected|all`) y tabla
  `resource_languages`. El backfill asigna a cada recurso existente su idioma
  anterior, por lo que una actualización no amplía su visibilidad sin permiso.
- El editor separa idioma base de disponibilidad: selector claro de uno o varios
  idiomas y opción «Todos los idiomas», con ayuda explícita de que también cubre
  los que se activen en el futuro. La lista admin resume esa disponibilidad.
- Catálogo, ficha, bloque Canvas, selector de Studio y `hreflang` consultan la
  disponibilidad nueva. La unicidad de slug evita ambigüedades entre alcances
  que se solapan.
- UX revisada con `design-taste-frontend` y navegador real: en página francesa,
  `all` muestra `+ Recursos`; con solo español, Studio conserva el botón
  desactivado, explica «ninguno está disponible en Français» y ofrece «Revisar
  idiomas». El control «Todos» desactiva visualmente los chips individuales sin
  ocultar qué idiomas cubre.
- Regresión completa en serie: **84/84 archivos PASS**, incluido update/rollback
  declarado en desarrollo y sin `--force`. PHP/JS lint, `admin_i18n`,
  `i18n_lint` y `git diff --check`: PASS. Fixtures retiradas (`resource=0`,
  `page=0`, francés restaurado a inactivo) y servidor local detenido.
- Generado `deliverables/promptpress-resources-v1.1-20260825.zip`: 1009 archivos,
  2,5 MB, incluye la migración nueva y excluye secretos/datos de recursos.
  SHA-256: `bd95da5dac71c220cb695f1fe7c8a04febf0ebe1c15629969039ac1d717bb889`.

R8 queda lista para revalidación en la instalación de producción. El usuario
debe actualizar con este ZIP, abrir `PDF Test`, asignar Français o «Todos los
idiomas» y volver a Studio. Como Executor no se anuncia aún el cierre final.

### R9 implementada y verificada localmente (25/08/2026)

- Incidencia de producción reproducida conceptualmente: el panel estaba en
  español y la página en francés, pero `insertResources()` persistía el heading
  con `__()`, es decir, con el idioma del gestor. La preview también declaraba
  el idioma general del sitio en vez del idioma de la página.
- TDD ampliado en `tests/resources_embed.php`: el primer ciclo falló en tres
  contratos. Ahora exige que Studio no persista textos automáticos del panel,
  que preview pase explícitamente el idioma de página, que el bloque hable
  francés y que un bloque antiguo se repare sin tocar headings personalizados.
- La inserción guarda solo `{{resources:featured|limit=N}}`; el renderer resuelve
  heading y CTA dinámicamente desde el idioma de la página. El nombre y las
  respuestas del chrome de administración continúan en el idioma del gestor,
  que es el comportamiento correcto.
- Compatibilidad retroactiva: `FeaturedResourcesRenderer` reconoce únicamente
  headings automáticos antiguos de los catálogos (`Recursos destacados`, etc.)
  y los vuelve a localizar. Un heading escrito por el usuario se conserva.
- QA real en navegador con panel español + página `fr` + recurso `all`:
  `Ressources`, `Voir la ressource`, URL `/fr/recursos/...` y parte de Studio
  `Ressources`. Después se convirtió el placeholder al formato antiguo español
  y el render siguió saliendo íntegramente en francés.
- Regresión dirigida: Recursos, Canvas runtime, creación/idioma de página,
  idioma del sitio y admin i18n: PASS. PHP lint, `i18n_lint` y
  `git diff --check`: PASS. Fixtures retiradas y francés restaurado a inactivo.
- Generado `deliverables/promptpress-resources-v1.2-20260825.zip` (1009 archivos,
  2,5 MB). SHA-256:
  Sustituido por el build R10 indicado a continuación.

### R10 implementada y verificada localmente (25/08/2026)

- Feedback visual de producción: una sección con título editorial propio
  mostraba además el heading genérico `Recursos` del grid dinámico.
- TDD en `tests/resources_embed.php`: el test nuevo falló primero al encontrar
  dos `h2`. La regla final conserva el heading descriptivo de la sección y
  elimina únicamente `pp-featured-resources__head` cuando existe otro h1-h6
  fuera del embed dentro del mismo `data-pp-section`.
- Un bloque independiente sigue mostrando `Ressources`; por tanto no se pierde
  jerarquía visual ni accesibilidad. Cuando se deduplica, el `section`
  del renderer conserva `aria-label="Ressources"`.
- La guía `design-taste-frontend` llevó a preferir una regla contextual frente
  a ocultar siempre el título: evita repetición sin crear listados anónimos.
- QA real en navegador, página francesa: un único heading visible
  `Ressources pour votre bien-être`, párrafo editorial, región accesible y CTA
  `Voir la ressource`; cero heading genérico repetido.
- Regresiones de Recursos, Canvas runtime/markup, Blog Canvas y Commerce Canvas:
  PASS. PHP lint, `i18n_lint`, `git diff --check` y limpieza de fixtures: PASS.
- Reempaquetado `deliverables/promptpress-resources-v1.2-20260825.zip` (1009
  archivos, 2,5 MB). SHA-256 final:
  `ff0d43f5be37f610f86c2efc81a096f6e440263dd5020b7a0c9e71af1c56ba00`.

### R11 implementada y verificada localmente (25/08/2026)

- Incidencia de producción: el bloque podía renderizar una tarjeta para una
  página francesa, pero `/fr/recursos/{slug}` devolvía 404 porque los
  controladores públicos exigían que `fr` estuviera además en `site_languages`.
  Es un estado real de instalaciones antiguas/importadas: la fila de `pages`
  conserva `language=fr` aunque la activación global esté desfasada.
- TDD nuevo `tests/resources_page_language.php`: primer run reprodujo CTA con
  enlace francés pero ficha 404 y ausencia del nuevo contrato en el store. La
  cobertura final prueba sitio principal español, francés globalmente inactivo,
  página `fr`, recurso `all`, CTA localizado, ficha 200 y descarga PDF 200.
- `ResourceStore::languageAvailableForSite()` unifica la regla: son válidos los
  idiomas activos y cualquier idioma soportado que ya use una página del sitio.
  `visibleLanguages()` aplica la misma fuente, también para hreflang, y la
  hidratación en lote calcula el conjunto una sola vez para evitar N+1 queries.
- `ResourcePublicController` y `ResourceDownloadController` usan esa regla. Un
  código no soportado sigue devolviendo 404; un recurso no disponible en ese
  idioma sigue sin ser accesible por conocer el slug.
- QA real en navegador con `fr_active=false`: tarjeta `Voir la ressource` →
  `/fr/recursos/guide-anti-inflammatoire-r11` → ficha 200 con `Retour aux
  ressources` y `Télécharger`. La guía UX confirmó que el idioma de la página
  debe gobernar CTA, enlace, ficha y descarga como un recorrido indivisible.
- Regresión total: **85/85 archivos PASS** (84 suites normales + update/rollback
  declarado en desarrollo, sin `--force`). PHP lint, `i18n_lint`,
  `git diff --check` y limpieza de fixtures: PASS.
- Generado `deliverables/promptpress-resources-v1.3-20260825.zip`: 1010 archivos,
  2,5 MB, sin secretos ni datos de recursos. SHA-256:
  `4efcec7841decafe2a751cb9f183074c421f2b9cd41661a905ce88bd39acf071`.

- **Pendiente ahora:** conformidad para cerrar R2 con sus pruebas automatizadas.
  La verificación manual de la respuesta HTTP se encadena a R3 porque el módulo
  aún no debe activarse sin interfaz de administración.
- No iniciar R3 hasta esa confirmación. En Executor se completará una sola tarea
  del Project Status Board cada vez y se esperará la verificación manual del
  usuario antes de pasar a la siguiente.

**Actualización R3 (25/08/2026):** las restricciones anteriores de R2 están
resueltas. R3 está implementada y validada automáticamente, por HTTP real y en
navegador, pero permanece sin marcar hasta que el usuario pruebe el panel. Abrir
`/admin/resources`, crear un borrador, añadir PDF/EPUB, alternar descarga directa
y formulario, publicar y volver a borrador. No iniciar R4 hasta esa conformidad.

## Current Status / Progress Tracking (FEAT-RESOURCES R3 · 25/08/2026, Executor)

- Registrado `resources` como módulo disponible, con rutas admin protegidas,
  icono y navegación estrictamente condicional al flag por sitio.
- Implementado `ResourceAdminController` y las vistas de listado/editor. El alta
  rápida solo pide título y crea borrador; el editor ordena el trabajo en tres
  pasos: presentación, archivo y forma de entrega. Portada reutiliza el selector
  de Medios; idioma solo aparece cuando el sitio es multiidioma.
- UX de publicación preventiva: el panel lateral explica en vivo si falta
  archivo o formulario, permite borrador/publicado de forma explícita y no
  oculta errores del store. En modo formulario, la selección aparece solo al
  elegir esa entrega; si aún no hay formularios ofrece una salida directa para
  crearlo. El borrado usa diálogo informado y elimina también el binario.
- Responsive verificado en navegador a 390×844: columna única, cero overflow
  horizontal y acciones después del contenido. Escritorio verificado con
  jerarquía 2+1, estado vacío enfocado y selector de 35 imágenes sin errores de
  consola. La skill `design-taste-frontend` guio la jerarquía, reducción de
  tarjetas innecesarias, estados, feedback táctil y divulgación progresiva.
- Ciclo HTTP real verificado: crear borrador → subir/publicar PDF (302) →
  sustituir archivo y despublicar (302) → republicar sin resubir (302). Descarga
  final 200 con `Content-Disposition` del nombre sustituido y cabeceras seguras.
  Módulo apagado: admin 404, descarga 404 y enlace de navegación ausente.
- TDD/regresión: `resources_admin` 12 comprobaciones, `resources_store` 34/34,
  `resources_files` 29/29, `modules_registry` y `admin_i18n` PASS; PHP/JS lint,
  `i18n_lint` y `git diff --check` limpios. Fixtures retiradas: `resources=0`,
  storage solo contiene `.htaccess` y `.gitkeep`. El flag del módulo se conservó
  en su estado previo (`enabled=true`).
- R3 sigue pendiente de prueba manual del usuario; no se marca ni se inicia R4
  hasta recibir su confirmación, según el flujo Executor del proyecto.

## Lessons (FEAT-RESOURCES)

- Fileinfo puede identificar un EPUB como `application/zip`; aceptar ese MIME sin
  más convertiría cualquier ZIP renombrado en ebook. La validación correcta
  combina extensión, Fileinfo y la cabecera OCF (`mimetype` primero, sin
  compresión y con contenido exacto `application/epub+zip`).
- Un límite declarado por la aplicación no supera `upload_max_filesize`. La UI
  debe enseñar el mínimo efectivo del producto y del hosting para que un fallo de
  infraestructura no parezca un error genérico del módulo.
- Separar el store de metadatos del servicio de archivos permite probar slugs,
  permisos multisitio y reglas de publicación sin crear binarios falsos ni
  mezclar transacciones SQL con efectos de filesystem; R2 será el único dueño de
  esos efectos.
- Un directorio de datos nuevo tiene tres ciclos además de subir/descargar:
  reset del sitio, creación de paquetes y despliegue de updates. Excluir Recursos
  del paquete evita filtrar ebooks reales, pero obliga a autocurar su `.htaccess`
- Las suites `resources_store` y `resources_files` comparten tabla y directorio:
  no deben ejecutarse en paralelo. Una ejecución concurrente hizo que sus
  limpiezas se cruzaran; se repitieron en serie y se verificó `resources=0` y
  ausencia de binarios antes de cerrar R3.
  al crear la primera carpeta en una instalación actualizada.
- Los mensajes técnicos de subida también llegan al panel. Pasarlos por el
  catálogo desde el servicio mantiene el diagnóstico (`UPLOAD_ERR_*`) sin volver
  a introducir castellano fijo tras ADMIN-I18N.
- Una conversión nueva no termina al insertar la fila: también necesita una
  etiqueta humana en el mapa del dashboard y en los cuatro idiomas del panel.
  `resource_download` se registra después de preparar el archivo para que un 404
  o token inválido nunca infle las métricas.
- El idioma original del contenido y los idiomas donde está disponible son
  conceptos distintos. Ocultar el selector cuando solo hay un idioma activo
  deja desajustes antiguos sin vía de corrección; la disponibilidad debe ser
  siempre visible y Studio debe diagnosticar una incompatibilidad, no desaparecer.
- En Studio hay dos idiomas simultáneos: el del gestor para botones/respuestas y
  el de la página para cualquier texto que se persista o llegue al visitante.
  Un placeholder dinámico no debe guardar defaults del panel; debe resolverlos
  al renderizar con el idioma de la página y reparar defaults históricos.
- Los embeds dinámicos necesitan jerarquía contextual: un heading interno es
  útil cuando el bloque está solo, pero redundante dentro de una sección que ya
  tiene título. Deduplicar por el DOM del mismo `data-pp-section` conserva ambas
  situaciones y mantiene el nombre accesible mediante `aria-label`.
- Para una URL pública, `site_languages` no puede contradecir una página que ya
  existe con ese idioma. Recursos debe aceptar la unión de idiomas activos e
  idiomas realmente usados por `pages`; después la disponibilidad concreta del
  recurso sigue siendo la barrera que evita exponer contenido indebido.

---

# [STUDIO-STRUCTURE] Añadir, colocar, mover y eliminar secciones manualmente — PLAN (26/08/2026)

## Background and Motivation (STUDIO-STRUCTURE)

El usuario quiere controlar la estructura de una página Canvas directamente
desde Studio: escoger exactamente dónde insertar una sección y poder retirarla
sin escribir una orden al chat ni esperar una respuesta de IA. La operación debe
ser cómoda para una persona no técnica, reversible y coherente con la edición
directa que Studio ya ofrece para textos, imágenes y estilos.

Alcance acordado para esta primera versión:

- páginas Canvas editadas desde Studio; el editor clásico de `page_sections` no
  cambia;
- operaciones sobre secciones top-level: insertar en un punto explícito, mover
  arriba/abajo y eliminar;
- biblioteca inicial de secciones de contenido deterministas más los bloques
  funcionales que Studio ya conoce (formularios, calendario y recursos);
- ninguna de estas operaciones llama a IA ni abre el chat;
- duplicar secciones y ordenar mediante drag & drop quedan fuera de v1. No son
  necesarios para cubrir la petición y duplicar HTML exige reescribir ids,
  `for`, fragmentos y relaciones ARIA sin introducir colisiones.

## Key Challenges and Analysis (STUDIO-STRUCTURE)

### Estado real comprobado

- `Partes de esta página` se construye con `renderSectionList()`, pero hoy cada
  fila solo selecciona y desplaza la preview. No hay acciones de estructura.
- Los endpoints de Formulario, Calendario y Recursos aceptan `section` y llaman
  a `CanvasService::insertAfterSection()`: insertan después de la selección o al
  final. La capacidad técnica existe parcialmente, pero la posición no se ve ni
  permite insertar antes de la primera sección.
- `POST /admin/canvas/{id}/section` solo reemplaza el HTML de una sección editada;
  no mueve ni elimina.
- `CanvasService::save()` y el historial Deshacer/Rehacer ya permiten que cada
  operación estructural sea una versión reversible. Debe reutilizarse esta vía,
  no crear un segundo historial.
- La nota histórica de R6 que describía el bloque como “movible/borrable en el
  índice” era una expectativa de UX, no una capacidad implementada. Este plan
  corrige explícitamente esa discrepancia.

### Decisión de UX

La estructura se editará principalmente en la barra lateral, donde ya existe la
lista numerada y donde funciona igual con ratón, teclado y pantalla táctil:

1. Habrá un punto `+ Añadir aquí` antes de la primera sección, entre cada pareja
   y al final. En reposo será compacto; en hover/foco/selección mostrará su texto
   completo. No se dibujarán controles flotantes encima del contenido público.
2. Al pulsarlo se abrirá un único selector de sección anclado a ese punto. La
   posición elegida se conservará aunque después se abra un subselector de
   Formulario, Calendario o Recursos.
3. La fila activa mostrará acciones pequeñas y etiquetadas: subir, bajar y
   eliminar. En móvil y teclado serán visibles al enfocar/seleccionar, no
   dependerán de hover.
4. Eliminar será inmediato y devolverá foco a una sección vecina. Se mostrará
   `Sección eliminada · Deshacer`; no habrá modal rutinario porque el historial
   ya hace la acción recuperable. También se permitirá eliminar la última
   sección y se mostrará un estado vacío con `Añadir la primera sección`.
5. Los cambios deterministas informarán en la propia barra lateral y no abrirán
   el chat. Durante la petición se bloqueará únicamente la operación afectada;
   un fallo conservará la estructura y mostrará un error inline accionable.
6. Tras insertar o mover, Studio recargará la preview conservando el scroll,
   seleccionará la sección resultante y la señalará brevemente. Se respetará
   `prefers-reduced-motion`.

La guía `design-taste-frontend` lleva a priorizar manipulación directa,
feedback táctil, estados completos y separación por líneas/espacio sobre añadir
otra colección de tarjetas o menús permanentes que saturen la barra.

### Biblioteca inicial recomendada

Para que “añadir sección” no dependa de IA, la primera biblioteca será pequeña y
editable con las capacidades que Studio ya tiene:

- **Texto**: antetítulo opcional, título y párrafo;
- **Texto + imagen**: composición adaptable con imagen sustituible desde Medios;
- **Llamada a la acción**: título, texto y botón editable;
- **Formulario**, **Calendario** y **Recursos**: reutilizan datos reales y sus
  selectores existentes, solo cambia el punto de inserción.

No se incluyen todavía FAQ, galería o tarjetas repetibles: Studio no ofrece aún
controles para añadir/quitar elementos internos y una plantilla rígida sería
frustrante. Podrán añadirse después sobre el mismo catálogo cuando exista ese
editor interno.

Las tres plantillas básicas tendrán ids únicos, HTML semántico y microcopy
inicial en el idioma de la página, no en el idioma del panel. Sus estilos serán
clases externas prefijadas y tokens de marca; no se generará CSS inline ni se
copiará CSS arbitrario de otras secciones.

### Contrato técnico propuesto

- Añadir un endpoint estructural CSRF/site-scoped para `insert_template`,
  `move` y `delete`. Recibirá ids opacos y una posición explícita
  (`before|after`); el servidor resolverá siempre la sección top-level real.
- Incorporar en `CanvasService` operaciones DOM puras para insertar relativo a
  un ancla, mover y eliminar. Los límites (subir la primera, bajar la última,
  ancla inexistente) no podrán corromper ni concatenar HTML silenciosamente.
- Extender los tres endpoints funcionales existentes para aceptar la misma
  posición explícita, conservando compatibilidad: una petición antigua sin
  posición seguirá insertando después de la selección o al final.
- Cada operación válida se guardará con `CanvasService::save()`, resumen humano,
  estado de historial y lista actualizada de secciones. El borrado retira solo
  la colocación de Formulario/Calendario/Recurso, nunca el objeto administrado.
- No se intentará limpiar CSS antiguo al borrar: los selectores pueden ser
  compartidos y una limpieza automática sería más peligrosa que el CSS huérfano.
- Los textos del panel se añadirán a es/en/fr/pt. Los textos iniciales de las
  plantillas se resolverán con el idioma de página mediante microcopy pública.

## High-level Task Breakdown (STUDIO-STRUCTURE)

### S1 — Contrato DOM estructural con TDD

- Escribir primero pruebas para insertar antes/después/al inicio/al final,
  eliminar una sección exacta, mover en ambos sentidos y respetar límites.
- Cubrir ids desconocidos, HTML con embeds/placeholder y conservación byte-lógica
  del resto de secciones en la medida permitida por DOMDocument.
- Implementar las operaciones puras en `CanvasService`.
- **Éxito:** la nueva suite falla antes de implementar y queda verde; ninguna
  operación inválida modifica el HTML.

### S2 — Endpoint, versionado y seguridad

- Añadir controlador/ruta estructural con CSRF, pertenencia al sitio, validación
  estricta de acción/posición/template y respuestas JSON diagnósticas.
- Guardar cada cambio como una sola versión y devolver `history` + `sections`.
- Adaptar Formulario/Calendario/Recursos a inserción explícita manteniendo el
  contrato antiguo.
- **Éxito:** pruebas HTTP verifican autorización, operación, orden final,
  Deshacer/Rehacer y que eliminar un embed no elimina su entidad real.

### S3 — Lista de partes como editor de estructura

- Añadir puntos de inserción antes/entre/después y controles subir/bajar/eliminar
  en la fila activa.
- Implementar loading local, error inline, foco posterior, estado vacío y
  confirmación reversible con `Deshacer`.
- Mantener selección, scroll y destello después de recargar la preview.
- **Éxito:** en escritorio y móvil se puede colocar, mover y borrar sin chat ni
  IA; teclado permite recorrer y activar todas las acciones.

### S4 — Selector único y plantillas básicas

- Crear el selector por categorías `Contenido` y `Bloques funcionales`.
- Materializar Texto, Texto + imagen y CTA con ids únicos, HTML semántico,
  idioma de página y estilos externos basados en tokens de marca.
- Integrar dentro del mismo flujo los selectores existentes de Formulario,
  Calendario y Recursos, conservando la posición escogida.
- **Éxito:** las seis entradas aparecen solo cuando son viables, se insertan en
  el punto exacto y las básicas pueden editarse inmediatamente con Studio.

### S5 — i18n, accesibilidad y pulido responsive

- Traducir panel/estados/errores en es/en/fr/pt y separar siempre idioma del
  gestor de idioma del contenido público.
- Revisar roles, nombres accesibles, foco, contraste, objetivos táctiles,
  reduced-motion y ausencia de overflow a 390 px.
- **Éxito:** lint i18n limpio; recorrido completo por teclado; QA visual en
  escritorio y móvil con panel español sobre página francesa.

### S6 — Regresión, navegador real y entrega

- Ejecutar suites Canvas, Studio, formularios, Booking, Recursos, idiomas,
  caché e historial; lint PHP/JS/CSS y `git diff --check`.
- Probar una página temporal: insertar en tres posiciones, editar, mover, borrar,
  deshacer, rehacer, publicar/preview y repetir en móvil.
- Empaquetar actualización solo tras limpiar fixtures y confirmar que no incluye
  datos ni secretos.
- **Éxito:** suite completa sin regresiones, cero errores de consola y recorrido
  manual reproducible listo para validación del usuario en producción.

## Project Status Board (STUDIO-STRUCTURE)

- [x] S1 — Contrato DOM estructural con TDD
- [x] S2 — Endpoint, versionado y seguridad
- [x] S3 — Lista de partes como editor de estructura
- [x] S4 — Selector único y plantillas básicas
- [x] S5 — i18n, accesibilidad y responsive
- [x] S6 — Regresión, QA real y paquete de actualización

## Executor's Feedback or Assistance Requests (STUDIO-STRUCTURE)

S5 y S6 completadas. El ZIP final listo para subir mediante el modo de
actualización es `deliverables/promptpress-studio-final-20260826.zip`. No hay
migración de base de datos.

## Current Status / Progress Tracking (STUDIO-STRUCTURE)

### S1 completada (26/08/2026, Executor)

- Se escribió primero `tests/canvas_structure.php`; el ciclo inicial terminó en
  error al no existir las nuevas operaciones y pasó a verde tras implementarlas.
- `CanvasService::insertSectionRelative()` inserta una única `<section>`
  top-level antes/después de un ancla o en los extremos sin ancla. Rechaza
  posición, ancla, forma o id duplicado inválidos con `null`; nunca degrada en
  silencio a insertar al final.
- `deleteSection()` elimina solo la sección top-level exacta, permite que la
  página quede vacía y no confunde un `data-pp-section` anidado con una parte.
- `moveSection()` desplaza un puesto arriba/abajo; los extremos son no-op válidos
  y dirección/id desconocidos se rechazan.
- Los tres helpers comparten serialización DOM y preservan labels, contenido y
  placeholders de Formulario/Recursos. El método histórico
  `insertAfterSection()` permanece intacto para no alterar aún endpoints vivos.
- Verificación: nueva suite **20/20 PASS**; `canvas_runtime` **49/49 PASS**;
  `form_inline_insert` **6/6 PASS**; lint PHP y `git diff --check`, limpios.

### S2 completada (26/08/2026, Executor)

- Se añadió `POST /admin/canvas/{id}/structure`, protegido por sesión admin,
  scope del sitio y CSRF. Solo acepta `move` (`up|down`) y `delete`; ids
  obsoletos devuelven conflicto 409 y peticiones inválidas, 422.
- Cada mutación real guarda una única versión con origen `structure`, devuelve
  `history`, `sections`, `changed_section` y `focus_section`. Mover en un límite
  devuelve `changed=false` y no crea versiones vacías.
- Formulario, Calendario y Recursos comparten ahora
  `insertAtRequestedPosition()`: aceptan `before|after` explícito y conservan el
  comportamiento histórico si una UI antigua no manda `position`.
- Las respuestas de inserción incluyen el id concreto recién creado para que S3
  pueda seleccionarlo y llevar el foco tras recargar.
- TDD HTTP real: la suite nueva empezó con 405 y con Formulario insertado en la
  posición antigua. Tras implementar, **16/16 PASS**: login, CSRF, aislamiento
  multisitio, orden, una sola versión, undo/redo, no-op, 409/422, inserción antes
  del ancla y borrado de colocación sin borrar la entidad Formulario.
- Regresión: `canvas_structure` 20/20, `canvas_runtime` 49/49,
  `form_inline_insert` 6/6, `booking_embed` y `resources_embed`: ALL PASS.
  `i18n_lint`: cero claves ausentes y cero castellano literal; lint PHP y
  `git diff --check`: limpios.

### S3 completada (26/08/2026, Executor)

- `Partes de esta página` permanece visible al abrir el editor contextual. Cada
  fila seleccionable incorpora acciones accesibles para subir, bajar y eliminar,
  con estados de límite, carga y selección sin depender exclusivamente de hover.
- Hay puntos compactos de inserción antes de la primera parte y después de cada
  parte. La posición elegida queda explícita en el panel y se conserva al abrir
  Formulario, Calendario o Recursos; una estructura que cambia invalida anclas
  antiguas para evitar insertar silenciosamente en otro lugar.
- Mover y eliminar actualizan lista, historial y preview sin abrir el chat. El
  borrado ofrece `Deshacer`, devuelve el foco a una parte vecina y el feedback
  se anuncia con una región de estado `aria-live`.
- Las inserciones funcionales usan ahora la posición escogida, muestran progreso
  y resultado en la barra lateral y enfocan la sección recién insertada tras la
  recarga del iframe. Deshacer/Rehacer retiran mensajes estructurales obsoletos.
- Se añadieron textos del flujo en es/en/fr/pt, estilos responsive/táctiles y
  respeto de `prefers-reduced-motion`.
- QA en navegador autenticado: mover y deshacer conservaron el mismo orden en
  lista e iframe; la selección no saltó al chat; un Formulario se insertó entre
  dos secciones exactamente en el punto indicado. A 390×844 no hubo overflow y
  los controles siguieron operables. La página temporal se eliminó al terminar.
- Verificación automatizada: `canvas_structure_ui` **15/15 PASS**,
  `canvas_structure_http` **16/16 PASS**, `canvas_structure` **20/20 PASS**,
  `canvas_runtime` **49/49 PASS**, `form_inline_insert` **6/6 PASS** y suites
  Booking/Recursos completas. Lint PHP/JS, i18n y `git diff --check`: limpios.

### S4 completada (26/08/2026, Executor)

- Studio presenta un solo botón `Añadir sección`. El menú separa `Contenido`
  de `Bloques funcionales` y solo pinta Calendario/Recursos cuando existe una
  opción realmente insertable; Formulario reutiliza entidades y plantillas.
- Las entradas básicas son Texto, Texto + imagen y Llamada a la acción. Se
  materializan sin IA mediante una whitelist backend y usan exclusivamente la
  gramática pública `ppb`/tokens de DesignSystem, sin CSS inline ni CSS nuevo
  duplicado en cada página.
- El contenido inicial se resuelve con `LanguageService::forPage()`: una página
  francesa recibe títulos, cuerpo, CTA, alt y label franceses aunque el gestor
  esté en castellano. Las claves públicas están completas en los siete idiomas.
- Texto + imagen incluye un SVG local neutro y reemplazable desde Medios. Las
  tres plantillas contienen elementos editables de inmediato y ids únicos.
- El endpoint estructural acepta `insert_template`, valida plantilla/posición,
  respeta el ancla exacta, crea una sola versión y devuelve foco, historial y
  lista de partes. Una plantilla desconocida no modifica el Canvas.
- QA real autenticada: `Texto + imagen` se insertó entre `Accueil` y
  `Conclusion`, apareció como `Texte et image`, mostró microcopy francés y su
  imagen abrió directamente el editor. El selector conservó después
  `Añadir al final` al abrir ocho opciones de Formulario. Se eliminó la página
  temporal al acabar.
- TDD: `canvas_section_templates` **7/7 PASS**, `canvas_template_http` **6/6
  PASS** y `canvas_template_ui` **11/11 PASS**. También pasaron las suites S1-S3,
  Canvas 49/49, Formulario 6/6, Booking, Recursos e idioma. Lint PHP/JS/i18n y
  `git diff --check`: limpios.

### Paquete provisional generado (26/08/2026, Executor)

- Se creó `deliverables/promptpress-studio-s4-20260826.zip` con el empaquetador
  oficial. Contiene 1.018 archivos, versión 1.0.0 y tamaño 2,5 MB.
- El propio empaquetador verificó la huella exigida por el actualizador y la
  ausencia de `config/config.php` y `config/image_bank.php`. `unzip -t` terminó
  sin errores. SHA-256:
  `252b777e22cd33f381ac00710db1e4a8152b1a01e9c24b9b72ade772a0504871`.

### S5 completada (26/08/2026, Executor)

- El selector de secciones usa un grupo accesible, no un menú ARIA: puede
  contener opciones, subselectores y el campo de origen del Formulario sin
  anunciar una estructura de menú inválida. Todos los disparadores declaran
  `aria-controls`; Escape cierra el selector y devuelve el foco a su botón.
- El foco del botón principal y de cada opción es visible. En dispositivos
  táctiles las acciones de estructura y los puntos de inserción alcanzan 40 px;
  las opciones de bloque mantienen 52 px.
- A menos de 640 px la barra lateral ocupa el ancho útil pero deja 56 px libres
  abajo; el chat conserva una anchura operable y no queda reducido detrás de la
  barra. El selector y sus animaciones respetan reduced-motion.
- Verificación TDD: `canvas_structure_accessibility` **8/8 PASS** junto a
  `canvas_template_ui` **11/11 PASS**, lint PHP/JS y `git diff --check` limpios.

### S6 completada (26/08/2026, Executor)

- Regresión completa verde: `canvas_section_templates`, `canvas_template_http`,
  `canvas_template_ui`, `canvas_structure_accessibility`, `canvas_structure_ui`,
  `canvas_structure_http`, `canvas_structure`, `canvas_runtime`,
  `form_inline_insert`, `booking_embed`, `resources_embed`, `site_language`,
  `site_language_chrome` y `page_creation_language`; lint PHP/JS, lint i18n y
  `git diff --check` también limpios.
- QA real en una página temporal francesa: se insertaron Texto al inicio, CTA
  entre secciones y Texto + imagen al final. El orden quedó correcto, el
  contenido y el alt fueron franceses, la imagen abrió su editor, mover/borrar/
  deshacer/rehacer funcionaron, la publicación y preview público fueron
  correctas y el modo móvil se activó sin error. Consola: 0 errores.
- La página temporal publicada se eliminó al terminar, sin dejar contenido de
  QA en desarrollo.
- Se creó `deliverables/promptpress-studio-final-20260826.zip` con el
  empaquetador oficial: 1.019 archivos, versión 1.0.0, 2,5 MB. `unzip -t` pasó
  sin errores y la inspección confirma que no incluye configuración sensible ni
  datos de `storage`. SHA-256:
  `3245e78cecad1061914014683d3ed28d6968462185dafe5db6602862da85f419`.

## Lessons (STUDIO-STRUCTURE)

- Un helper de edición estructural no debe imitar el fallback permisivo de una
  inserción antigua: si el usuario eligió una posición exacta y el ancla ya no
  existe, insertar al final oculta una carrera y produce un resultado distinto
  al solicitado. El contrato nuevo devuelve `null` y deja decidir al endpoint
  cómo explicar el conflicto.
- Una operación de estructura válida puede no cambiar nada (subir la primera o
  bajar la última). Guardarla como versión haría que Deshacer pareciera roto;
  el endpoint debe reconocer el no-op y devolver estado sin ensuciar historial.
- Una lista de estructura persistente no puede vivir dentro del contenedor que
  se oculta al seleccionar contenido: debe quedar fuera del estado vacío para
  que las acciones sigan disponibles durante la edición contextual.
- La posición de inserción es estado de interfaz de primera clase. Debe mostrarse,
  viajar en la petición y borrarse si cambia la estructura; inferirla desde la
  última selección produce resultados sorprendentes.
- Las acciones deterministas de Studio necesitan feedback local y foco predecible,
  no mensajes en el chat: así mover, borrar o insertar sigue siendo inmediato y
  reversible sin interrumpir el flujo de edición.
- El contenido inicial de una plantilla de Studio no es microcopy del panel: en
  cuanto se guarda forma parte de la web pública. Debe salir de Microcopy con el
  idioma de la página y no de `__()` con el idioma del gestor.
- Las plantillas básicas no necesitan una hoja CSS propia si se componen con la
  gramática pública estable `ppb`. Esto evita acumular reglas por inserción y hace
  que hereden inmediatamente tipografía, color, espaciado y responsive de marca.
- Un selector único puede conservar subselectores complejos sin convertirse en
  una cadena de modales: mantener Formulario/Calendario/Recursos dentro del mismo
  panel conserva contexto y posición, y las separaciones por líneas reducen la
  sensación de colección de tarjetas.
- Un selector que contiene subflujos o campos no es un menú ARIA: `role=group`
  con nombres y controles explícitos comunica mejor su estructura a lectores de
  pantalla y evita prometer un patrón de teclado que no existe.
- Un panel lateral que funciona en escritorio puede dejar el chat inútil en
  móvil por solapamiento, aunque no haya overflow. El breakpoint debe reservar
  espacio físico para el dock y limitar explícitamente el ancho de su panel.
- En QA de controles repetidos, las etiquetas visuales pueden coincidir (por
  ejemplo, Deshacer en toolbar y en estado). Usar ids y regiones concretas evita
  comprobar o activar el elemento equivocado.

---

# [ASSISTANT-RICH] Pegar texto enriquecido e imágenes en el IA Assistant — PLAN (26/08/2026)

## Background and Motivation (ASSISTANT-RICH)

Los clientes entregan cambios copiando contenido desde Word, Google Docs, Gmail
u otras herramientas: títulos, párrafos, listas, enlaces e imágenes aparecen en
un mismo bloque. El Assistant central solo acepta hoy texto plano de hasta 4.000
caracteres y un documento PDF/DOCX/TXT cuyo contenido se aplana a texto. La
petición es poder pegar el bloque completo, dejar que la IA entienda su
estructura y sus imágenes, proponga dónde aplicarlo y, tras confirmación, actúe
mediante el flujo de borradores ya existente.

La viabilidad es **alta**. La estimación cualitativa es 8/10: el pipeline de
planificar, confirmar, editar páginas, versionar y dejar borradores ya existe;
también existen una biblioteca multimedia segura y soporte de visión en los
providers OpenAI-compatible. El trabajo nuevo se concentra en ingerir el
portapapeles, normalizarlo y transportar ese contexto sin perderlo entre el plan
y la ejecución.

Alcance recomendado para v1:

- pegar párrafos, títulos, listas, negritas/cursivas, citas, enlaces y tablas
  sencillas desde las fuentes habituales;
- aceptar imágenes que el navegador entregue como archivo/binario y guardarlas
  en la biblioteca del sitio;
- conservar el orden mediante referencias visibles (`Bloque 1`, `Imagen 1`,
  etc.), no intentar reproducir exactamente la maquetación de Word/Google Docs;
- mantener el flujo seguro actual: **proponer plan → confirmar → aplicar como
  borrador**. “Decidir y actuar” no significa publicar automáticamente.

Fuera de v1: fidelidad visual píxel a píxel del documento original, edición
colaborativa del contenido pegado, OCR de PDFs escaneados y descarga automática
de cualquier imagen remota encontrada en HTML.

## Key Challenges and Analysis (ASSISTANT-RICH)

### Corrección de objetivo tras ver un correo real (26/08/2026)

Las capturas del correo de ejemplo aclaran que **texto enriquecido no es el
producto principal**, sino un canal de entrada. El usuario no busca solamente
que la IA conserve negritas, listas e imágenes al pegarlas: busca delegarle el
trabajo de leer una petición de cliente larga y desordenada, contrastarla con el
estado real de PromptPress y devolver un criterio operativo antes de actuar.

El resultado esperado debe separar, como mínimo:

1. **Puedo hacerlo ahora**: cambio soportado y automatizable por el Assistant;
   explica qué páginas/módulos tocará y queda listo para confirmar.
2. **La plataforma lo permite, pero el Assistant aún no lo automatiza**: indica
   desde qué panel puede hacerse o propone una tarea manual concreta.
3. **Necesito información o archivos**: copy pendiente, precio con interrogante,
   FAQ no entregada, CGV/CGP mencionado pero ausente, credenciales o decisión de
   diseño. No inventa ni ejecuta esa parte.
4. **No está incluido en la plataforma**: funcionalidad que exige desarrollo;
   explica el alcance sin presentarla falsamente como simple cambio de contenido.
5. **Requiere revisión sensible**: textos/consentimientos legales, cobros o
   decisiones que el Assistant puede implementar técnicamente pero no validar
   jurídicamente por su cuenta.

El email y sus capturas son **material de ejemplo**, no instrucciones para
modificar el sitio actual. El Assistant futuro tendrá que distinguir igualmente
entre la orden del operador (“analiza esta petición”) y las instrucciones citadas
dentro del material del cliente.

Esto exige ampliar la arquitectura más allá de `PLAN_SITE_CHANGES`. El prompt
actual considera automáticamente no viable todo lo que no sea editar una página
Canvas existente (`page_id=0`), aunque PromptPress sí tenga Formularios,
Reservas, Recursos, Commerce, Medios, Diseño, Chrome o creación de páginas en
otros paneles. Un modelo no debe adivinar las capacidades a partir de nombres.

Se necesita un **registro de capacidades del Assistant** alimentado por el estado
real del sitio:

- operación disponible en la plataforma;
- módulo activo/disponible y datos ya configurados;
- si existe un ejecutor automático o solo una ruta manual del panel;
- información mínima requerida;
- riesgos/confirmaciones especiales;
- límites y resultado que se puede prometer.

El planner clasificará cada petición contra ese registro y devolverá una salida
tipada, no una opinión libre del modelo. Solo los items con ejecutor registrado
podrán pasar al job. Así “puedo hacerlo” significa capacidad comprobada y no una
alucinación.

Las imágenes también cambian de papel. En un correo como el mostrado pueden ser:

- una referencia visual (“quiero una ficha de ebook parecida a esta”);
- evidencia de un problema actual (“al pulsar Mon parcours no aparece nada”);
- una captura de estructura (“dos tipos de reserva en un mismo selector”);
- un activo que debe publicarse realmente.

No todas deben guardarse como fotos de contenido. El ingest debe conservar su
posición y permitir/inferir el rol; si no es seguro, el plan pregunta antes de
usar la imagen en la web.

### Estado real comprobado

- `views/admin/assistant/index.php` usa un `<textarea maxlength="4000">`; el
  navegador descarta formato e imágenes al pegar.
- `AssistantController::extract()` acepta un único PDF/DOCX/TXT, extrae hasta
  60.000 caracteres y descarta el archivo. `TextExtractor` devuelve texto plano:
  no conserva jerarquía, estilos ni imágenes internas del DOCX/PDF.
- `SiteAssistantPlanner` recorta el documento a 30.000 caracteres para el prompt.
  El plan y `assistant_job_items.instruction` dependen de que la IA vuelva a
  copiar el contenido relevante en una instrucción de máximo 4.000 caracteres.
  Esto sirve para cambios cortos, pero perdería o resumiría un artículo largo.
- `MediaService` ya valida MIME real, limita cada imagen a 10 MB, reescala y la
  guarda por `site_id`; `MediaLibraryService` la describe y la ofrece al editor
  Canvas. No conviene crear un segundo almacenamiento para imágenes del chat.
- `AIActionRunner` ya transporta `_images` y `OpenAIProvider` las convierte en
  mensajes multimodales. `OpenRouterProvider` y `MistralProvider` heredan ese
  camino. `AnthropicProvider` todavía ignora imágenes, y en los proveedores
  compatibles el modelo concreto puede no tener visión: hace falta una capacidad
  explícita y un fallback visible, no asumir que todo modelo “ve”.
- `AIActionRunner` entrega hoy el `input` completo a `AILogger`, que lo serializa
  como JSON tanto en éxito como en error. Pasar `_images` con base64 por el flujo
  actual copiaría los binarios y datos del cliente dentro de `ai_logs`. Antes de
  habilitar visión aquí hay que redactar `_images` globalmente y registrar solo
  `media_id`, MIME, dimensiones, bytes y hash; no basta con acordarse en este
  único endpoint.
- La ejecución del Assistant llama a `CanvasChatService`, que recibe únicamente
  la instrucción del item. Aunque una imagen recién pegada aparecería entre las
  primeras de la biblioteca, hoy no existe una referencia inequívoca que obligue
  a usar **esa** imagen ni un mecanismo que entregue el texto fuente largo al
  item que lo necesita.

### Qué significa “texto enriquecido” en este producto

No se debe mandar HTML crudo del portapapeles a la IA ni guardarlo para pintar
después. Word, Gmail y Google Docs añaden estilos, spans, comentarios y URLs
internas; conservar ese HTML introduciría ruido, XSS y resultados impredecibles.

El contrato correcto es un documento semántico normalizado, por ejemplo:

- bloques con id estable: `heading`, `paragraph`, `list_item`, `quote`,
  `table_row`, `image`;
- texto y marcas útiles (`strong`, `em`, enlace saneado), sin estilos ni clases
  de origen;
- cada imagen sustituida por una referencia `IMG-1` enlazada a un `media_id`, su
  ruta interna, descripción y posición entre los bloques;
- una versión de texto legible con delimitadores de bloque para el prompt y una
  preview humana antes de enviar.

Así la IA entiende jerarquía y orden sin poder ejecutar HTML del cliente. El
Canvas sigue pasando por su sanitizer y versionado habituales.

El bloque se delimitará además como **material fuente no confiable**. Frases del
propio documento como “ignora las instrucciones anteriores” no pueden cambiar el
rol del planner ni autorizar operaciones; solo la instrucción escrita en el
composer y la confirmación humana gobiernan el plan.

### Imágenes: límite del navegador que hay que explicar

Hay tres casos distintos al pegar:

1. El portapapeles entrega un `File`/blob (captura, imagen local o ciertas copias
   desde Word): se puede subir automáticamente con `MediaService` y analizar con
   visión.
2. El HTML trae una imagen `data:`: se convierte a blob, se valida y se sube por
   el mismo camino.
3. El HTML solo trae una URL remota, `blob:` o `file:` sin binario (frecuente en
   Gmail/Google Docs): **no** se debe descargar desde el servidor de forma
   automática. Hacerlo abre riesgos SSRF, privacidad, hotlink y credenciales
   caducadas. La preview marcará “Imagen no importada” y pedirá arrastrarla o
   elegirla desde Medios.

Por eso la promesa de producto debe ser “pega texto e imágenes compatibles y te
diremos qué hemos podido importar”, no “cualquier pegado conservará siempre el
100 %”. Se necesita una matriz de QA real por navegador y fuente.

### Transporte del contexto sin truncarlo

La IA planificadora no debe reemitir todo el texto dentro del JSON del plan. Se
le enviarán bloques numerados y responderá, para cada item, con
`source_block_ids` y `media_ids`. Al confirmar:

- el job guardará una copia normalizada de los bloques y el manifiesto de medios;
- cada item persistirá solo las referencias que le corresponden;
- `stepJob()` resolverá esas referencias y añadirá el fragmento fuente exacto a
  `CanvasChatService::applyInstruction()` en el momento de editar;
- si el planner no asigna referencias pero el cambio depende del material, el
  backend lo mantendrá ambiguo en vez de ejecutar una paráfrasis incompleta.

Para la v1 no hace falta un sistema de conversaciones ni una tabla de documentos
permanentes. El contenido normalizado puede viajar durante la propuesta y quedar
persistido en `assistant_jobs` solo al confirmar; una migración añadirá el bundle
fuente al job y las referencias a sus items. `install/schema.sql` debe recibir el
mismo cambio.

### UX recomendada

- Sustituir visualmente el textarea por un composer `contenteditable` de altura
  contenida, con placeholder real, pegado, escritura normal y fallback accesible.
- Tras pegar, mostrar el contenido dentro del mismo composer y una línea de
  estado: “12 bloques · 3 imágenes importadas · 1 necesita revisión”. No abrir
  un modal ni convertir cada párrafo en una tarjeta.
- Las imágenes aparecen como miniaturas compactas en su posición; cada una puede
  quitarse o reemplazarse desde Medios. Los avisos se muestran inline junto al
  punto que no pudo importarse.
- Cubrir estados completos: normalizando, subiendo imágenes, listo, importación
  parcial, error recuperable y contenido demasiado grande. El botón de plan se
  bloquea solo mientras haya operaciones pendientes.
- Permitir “Pegar sin formato” como salida explícita y conservar la subida de
  PDF/DOCX/TXT actual. El usuario no pierde ninguna capacidad existente.
- Estilos en hoja externa, nunca inline; foco visible, controles táctiles de al
  menos 40 px, navegación completa por teclado y layout sin overflow móvil.

## High-level Task Breakdown (ASSISTANT-RICH)

### AR0 — Contrato de producto y compatibilidad, sin implementar

- Confirmar el alcance v1 anterior y fijar límites iniciales: 60.000 caracteres,
  hasta 8 imágenes almacenadas, 10 MB por imagen y formatos JPEG/PNG/WebP/GIF;
  el servidor aplicará siempre el menor límite efectivo de PHP. Una llamada de
  visión enviará como máximo 4 imágenes JPEG/PNG/WebP reducidas a 1.600 px,
  reutilizando el límite que ya está probado en páginas de referencia.
- Antes de desarrollar, pedir al usuario que etiquete `@web` y consultar la
  documentación vigente de Clipboard API/contenteditable y de entrada de imagen
  de los proveedores/modelos admitidos. Documentar resultados por API en
  `cursor/clipboard-api.md` y `cursor/ai-vision-api.md`.
- Preparar una matriz manual Chrome/Safari con Google Docs, Word, Gmail, Notion,
  HTML web, captura y archivo local, anotando qué fuente entrega binario y cuál
  solo URL.
- **Éxito:** contrato y mensajes de limitación aprobados; matriz mínima reproducible
  y ninguna promesa de compatibilidad basada en suposición.

### AR0b — Taxonomía de decisión y registro de capacidades

- Inventariar operaciones reales de Páginas/Canvas, Diseño/Chrome, Formularios,
  Reservas, Recursos, Commerce, Medios y legales; para cada una marcar
  `automatizable`, `manual_en_plataforma`, `requiere_datos` o
  `requiere_desarrollo`.
- Definir la salida del analista con las cinco categorías anteriores, evidencia
  de la decisión, dependencias, preguntas y siguiente acción sugerida.
- Añadir handlers solo para operaciones que ya tengan un camino seguro y
  reversible. Una capacidad sin handler nunca se presenta como autoejecutable.
- Usar el correo de ejemplo como fixture de aceptación, sin convertir sus
  instrucciones en cambios reales.
- **Éxito:** el fixture produce un desglose útil: páginas/contenido ejecutable,
  datos y adjuntos ausentes, módulos o acciones manuales, desarrollo nuevo y
  asuntos legales; ninguna categoría depende de que el modelo “recuerde” qué
  incluye PromptPress.

### AR1 — Normalizador semántico con TDD

- Escribir primero fixtures de HTML realista/sucio y pruebas para jerarquía,
  listas, enlaces, tablas, orden de imágenes, límites y eliminación de
  script/style/event handlers/clases de origen.
- Implementar un normalizador server-side autoritativo que produzca bloques con
  ids estables y texto para prompt. El cliente puede limpiar para la preview,
  pero el backend no confiará en esa limpieza.
- Definir truncado por bloques completos; nunca cortar en mitad de un enlace,
  carácter multibyte o referencia de imagen.
- **Éxito:** los fixtures de Word/Docs/Gmail producen el mismo contrato semántico,
  el payload malicioso no sobrevive y el texto plano actual sigue funcionando.

### AR2 — Composer enriquecido y captura de portapapeles

- Añadir el composer accesible sin librería pesada: escritura, pegado, “pegar sin
  formato”, preview ordenada y restauración del textarea como fallback.
- Interceptar `ClipboardEvent`: mapear HTML/texto y blobs a placeholders estables;
  convertir solo `data:` válidos y detectar URLs/`blob:`/`file:` no resolubles.
- Implementar estados inline de progreso/error/importación parcial, eliminar o
  reemplazar imagen y mantener el adjunto documental existente.
- **Éxito:** teclado y móvil permiten completar el flujo; pegar contenido mixto
  no bloquea la página y el usuario ve exactamente qué se importará.

### AR3 — Ingesta de imágenes reutilizando Medios

- Subir cada blob por el camino validado de `MediaService`, con CSRF, scope del
  sitio, nombres seguros, tamaño/MIME real y respuesta JSON diagnóstica.
- Asociar cada placeholder a `media_id` y ruta interna; describir la imagen por
  el mecanismo existente cuando el modelo activo tenga visión.
- No hacer fetch server-side de URLs arbitrarias. Ofrecer reemplazo desde la
  biblioteca y conservar una advertencia bloqueante si una instrucción exige una
  imagen que no llegó a importarse.
- **Éxito:** una imagen pegada aparece en Medios y queda referenciada en su lugar;
  una URL privada/remota no provoca ninguna petición del servidor.

### AR4 — Planner multimodal y gate de capacidades

- Extender `PLAN_SITE_CHANGES` para recibir los bloques, manifiesto de imágenes y,
  cuando proceda, `_images`; ampliar su salida con `source_block_ids` y
  `media_ids`, validados contra el bundle real.
- Redactar `_images` en `AILogger` para todas las acciones IA antes de conectar
  este flujo: conservar metadatos diagnósticos, nunca base64 ni binarios.
- Incorporar una capacidad explícita `supportsVision` por provider/modelo. Añadir
  soporte Anthropic solo después de revisar su documentación vigente; para un
  modelo sin visión, usar texto/alt si existe y mostrar la degradación al usuario.
- Mantener el presupuesto: el planner recibe como máximo las imágenes pegadas
  necesarias, no toda la biblioteca ni binarios duplicados en logs.
- **Éxito:** el mismo caso genera un plan coherente con un modelo visual; con uno
  no visual nunca se afirma haber inspeccionado la imagen y el plan solicita la
  aclaración necesaria.

### AR5 — Persistencia y ejecución fiel del material confirmado

- Migrar `assistant_jobs` para guardar el bundle fuente normalizado y
  `assistant_job_items` para guardar referencias de bloques/medios; reflejar el
  esquema idéntico en instalaciones nuevas.
- Revalidar referencias, site ownership y límites en `createJob()`. En cada step,
  resolver solo el fragmento citado y adjuntarlo a la instrucción de edición con
  rutas exactas de las imágenes pegadas.
- Evitar que la IA resuma o invente el texto marcado como literal; si el fragmento
  no cabe, dividir el item por secciones/bloques antes de ejecutar.
- **Éxito:** un pegado largo puede distribuirse entre dos páginas sin que el plan
  tenga que copiarlo; ambas versiones draft conservan literalmente los bloques y
  usan solo media del mismo sitio.

### AR6 — Regresión y QA end-to-end

- Suites para normalización, uploads, aislamiento multisitio, provider con/sin
  visión, planner, persistencia, ejecución, sanitizado Canvas y límites de coste.
- QA real: pegar desde cada fuente de AR0, revisar plan, aplicar en al menos dos
  páginas, comprobar imágenes/textos, deshacer y confirmar que nada se publica.
- Verificar errores de consola, accesibilidad por teclado, 390 px, archivos
  temporales, logs sin binarios/base64 y ausencia de secretos/datos de cliente en
  paquetes de actualización.
- **Éxito:** recorrido reproducible verde; fallos parciales se explican y dejan el
  sitio y el job en un estado recuperable.

## Project Status Board (ASSISTANT-RICH)

- [x] AR0 — Contrato, límites y matriz aprobados por el usuario (26/08/2026)
- [x] AR0b — Taxonomía + registro aprobados por el usuario (26/08/2026)
- [x] AR1 — Normalizador semántico aprobado por el usuario (26/08/2026)
- [x] AR2 — Composer enriquecido aprobado por el usuario (26/08/2026)
- [ ] AR3 — Implementado y probado; pendiente validación del usuario en producción
- [ ] AR4 — Implementado y probado; pendiente revisión del usuario
- [ ] AR5 — Persistencia de referencias + ejecución fiel
- [ ] AR6 — Regresión y QA real

## Current Status / Progress Tracking (ASSISTANT-RICH · 26/08/2026, Planner)

- Auditoría de arquitectura completada; no se modificó código ni base de datos.
- Viabilidad alta confirmada por capacidades existentes: Assistant por fases,
  jobs con borradores/versionado, `MediaService`, biblioteca para IA y transporte
  `_images` en providers OpenAI-compatible.
- El principal riesgo no es la visión, sino perder el texto largo entre planner
  y executor. El plan lo resuelve con bloques referenciables persistidos al
  confirmar, en lugar de pedir al modelo que copie todo dentro de su JSON.
- Requisito corregido con el correo real: la meta principal es pensar/triage por
  el operador. El rich text pasa a ser infraestructura de ingesta; la pieza de
  producto central es un registro verificable de capacidades y una clasificación
  operativa antes de ejecutar.
- Siguiente decisión del usuario: aprobar AR0. Antes de empezar Executor, debe
  etiquetar `@web` para verificar APIs y modelos vigentes como exige el proyecto.

### AR0 iniciada (26/08/2026, Executor)

- El usuario autoriza el paso a Executor y la consulta directa de documentación
  web vigente.
- Alcance de este hito: contrato de ingesta, límites iniciales, documentación
  oficial por API y matriz de compatibilidad. No se implementará todavía el
  composer, el normalizador, la ingesta de imágenes ni el registro de
  capacidades (AR0b).

### AR0 preparada para revisión (26/08/2026, Executor)

- Contrato de entrada, estados, límites, seguridad y matriz mínima documentados
  en `cursor/assistant-ingestion-contract.md`.
- Documentación oficial consultada y decisiones registradas por API en
  `cursor/clipboard-api.md`, `cursor/openai-vision-api.md`,
  `cursor/anthropic-vision-api.md`, `cursor/openrouter-vision-api.md` y
  `cursor/mistral-vision-api.md`.
- Se fija una importación honesta: hasta 60.000 caracteres y 8 imágenes
  almacenadas; hasta 4 imágenes normalizadas a 1.600 px por llamada visual. Las
  referencias remotas/privadas sin bytes no se descargan y quedan visibles como
  pendientes.
- OpenAI/OpenRouter/Mistral tienen un formato de imagen aprovechable en la base
  actual, pero la capacidad depende del modelo. Anthropic requiere implementar
  la traducción de imágenes antes de poder presentarse como visual.
- No se modificó código ni base de datos. AR0 queda sin marcar hasta que el
  usuario revise y apruebe el contrato; conforme a la secuencia acordada, no se
  inicia AR0b en este hito.

### AR0 aprobada / AR0b iniciada (26/08/2026, Executor)

- El usuario confirmó que se puede continuar y que el modelo operativo es
  `google/gemini-3.7-flash` servido mediante OpenRouter.
- La documentación vigente de OpenRouter confirma entrada multimodal, salida
  estructurada y tool calling para ese modelo. El provider/modelo no bloquea el
  objetivo; la ingesta multimodal seguirá tratándose en AR2–AR4.
- Alcance de este hito: implementar y probar la taxonomía de decisiones, un
  registro verificable de capacidades y la respuesta del planner basada en ese
  registro. No se inicia todavía el composer ni la subida de imágenes.

### AR0b preparada para revisión (26/08/2026, Executor)

- Nuevo `AssistantCapabilityRegistry`: inventario verificable de Páginas Canvas,
  creación de páginas, Entradas, Header/pie, Diseño, Formularios, Medios, SEO,
  legales, Analítica, Reservas, Recursos, Commerce y desarrollo no registrado.
  Incluye estado real del módulo, contador de elementos configurados, panel,
  datos requeridos, sensibilidad y handler. Solo `pages.canvas.edit` declara
  `mode=automatic` + `handler=canvas_edit`.
- El planner recibe el registro y devuelve `capability_id`, una de las cinco
  categorías operativas, evidencia, siguiente acción y datos ausentes. El
  backend impone la categoría real aunque el modelo afirme que puede ejecutar
  más; ids inventados caen a `custom.development`.
- `SiteAssistantJobs` repite el gate al confirmar: un JSON manipulado solo entra
  al job si conserva `pages.canvas.edit + automatable_now + aplicar`. Las
  categorías `needs_input` y `sensitive_review` ya no pueden promocionarse con
  un simple “sí”; no se inventan datos ni se elude la revisión.
- La interfaz pinta las cinco decisiones con etiquetas humanas, datos faltantes
  y siguiente paso. Traducciones ES/EN/FR/PT completas.
- Compatibilidad Gemini: se normaliza únicamente el wrapper inequívoco `[{plan}]`
  que el modelo devolvió pese a pedir `json_object`; listas múltiples siguen
  rechazándose.
- Pruebas: `site_assistant_capabilities.php` 17/17, planificación de secciones
  10/10, `admin_i18n.php` completo, `modules_registry.php` completo, lints PHP,
  `node --check` y `git diff --check`, todo verde.
- Verificación real del registro sobre site 1: 14 capacidades; 51 páginas, 3
  formularios, 35 medios, 4 legales, 4 servicios de reserva, 0 recursos y 1
  producto; módulos Analytics/Booking/Resources/Commerce activos.
- Prueba real de planificación y navegador, sin aplicar cambios: las cinco
  categorías aparecieron una vez, solo había botón “Aplicar 1 cambio”, no había
  promoción de información ausente y la consola quedó sin errores. Se crearon
  únicamente logs IA con una petición sintética.
- Hallazgo de configuración: las llamadas reales usaron
  `google/gemini-3-flash-preview`, no `google/gemini-3.7-flash`. No se cambió el
  modelo automáticamente.

### Configuración Gemini corregida (26/08/2026, Executor)

- Por petición expresa del usuario, el setting `ai_model` del site 1 cambió de
  `google/gemini-3-flash-preview` a `google/gemini-3.7-flash`. Provider y API key
  no se tocaron; `ai_model_light` permanece en
  `google/gemini-3.1-flash-lite-preview`.
- También se sustituyó el modelo 3 Preview en Ajustes, comprobador del provider,
  presets/defaults de Onboarding, vista y estimación de coste. Nuevas
  instalaciones ya recomiendan 3.7 en vez de volver al id antiguo.
- Prueba TDD `gemini_37_configuration.php`: 7/7. Llamada real a OpenRouter:
  provider `openrouter`, modelo devuelto `google/gemini-3.7-flash`, contenido
  `OK` (6 tokens de entrada, 78 de salida).
- El ping anterior de 5 tokens resultó insuficiente porque el modelo consumió
  tokens de razonamiento y devolvió contenido vacío. El test de conexión del
  panel usa ahora 128 tokens y timeout de 30 segundos para evitar un falso
  negativo.

### AR1 iniciada (26/08/2026, Executor)

- El usuario valida el resultado anterior y autoriza continuar.
- Alcance exclusivo: fixtures y pruebas de HTML sucio, normalización
  server-side a bloques semánticos, ids estables, marcas útiles, imágenes como
  referencias, límites y eliminación de contenido activo. El composer y los
  uploads permanecen fuera de este hito.

### AR1 preparada para revisión (26/08/2026, Executor)

- Nuevo `AssistantContentNormalizer`: convierte HTML de portapapeles en una
  representación inerte con bloques `heading`, `paragraph`, `list_item`,
  `quote`, `table_row` e `image`; conserva orden, profundidad de listas, celdas,
  marcas `strong`/`em`/enlaces HTTP(S), ids estables y referencias `IMG-*`.
- El backend elimina contenido activo completo (`script`, `style`, formularios,
  iframes, SVG, embeds y similares), descarta destinos de enlace no HTTP(S) y
  nunca devuelve HTML de origen. Las imágenes `data:` válidas quedan capturadas;
  URLs remotas y referencias `blob:`/`file:` quedan explícitamente como
  `needs_review`, sin descargarlas.
- Los límites se aplican por bloques completos (60.000 caracteres, 8 imágenes y
  10 MB por imagen por defecto), con `warnings`, estado `partial` y texto de
  prompt referenciable; el fallback de texto plano mantiene párrafos y listas.
- Fixtures TDD añadidos para Gmail, Word y Google Docs. Test específico
  `assistant_content_normalizer.php`: 15/15 PASS, incluido HTML malicioso,
  Unicode, orden texto-imagen-texto, tablas, links inseguros y truncado.
- Regresión verde: `site_assistant_capabilities.php` 17/17,
  `site_assistant_section_planning.php` 10/10, catálogo i18n completo, lints PHP
  y `git diff --check`. No hay cambio visual ni de base de datos en este hito.

### AR1 aprobada / AR2 preparada para revisión (26/08/2026, Executor)

- El usuario aprobó AR1 y autorizó continuar. AR2 añade un editor enriquecido
  progresivo sobre el textarea existente, sin librerías: escritura, pegado HTML,
  modo «Pegar sin formato», Cmd/Ctrl+Enter, labels ARIA, estado `aria-live`,
  límites y fallback legible.
- El cliente reconstruye una preview permitiendo solo estructura útil: títulos,
  párrafos, listas, tablas, énfasis y enlaces HTTP(S). Elimina estilos/clases,
  handlers y contenido activo. El backend no confía en ella: `plan()` vuelve a
  pasar `rich_html` por `AssistantContentNormalizer` y entrega al planner su
  contrato referenciable, con un límite global de 60.000 caracteres.
- Las imágenes `data:`/blobs disponibles se previsualizan mediante object URL y
  conservan su posición; URLs remotas, `blob:` o `file:` sin bytes aparecen como
  referencias a revisar. Al serializar, todas usan `data-ppa-source` inerte:
  nunca se crea un `<img src=URL-remota>` que pueda descargar datos por accidente.
  En AR2 los binarios aún no se envían ni se guardan; eso pertenece a AR3.
- Pruebas: `assistant_rich_composer.js` 6/6 y normalizador 16/16; regresión de
  capacidades 17/17, secciones 10/10, i18n completo, lints PHP/JS y diff verde.
- QA real en navegador: HTML mixto con título, strong, enlace, lista anidada,
  tabla, imagen remota y script; estructura correcta, 0 nodos activos y 0
  imágenes remotas cargadas. «Pegar sin formato» elimina marcas pero mantiene
  listas. PNG pegado queda exactamente entre «Antes» y «Después». A 390×844 no
  hay scroll horizontal, el botón ocupa el ancho útil y la consola queda limpia.

### AR2 aprobada / AR3 preparada para revisión (26/08/2026, Executor)

- El usuario aprobó avanzar y hará la validación manual directamente en
  producción. No se desplegó ni se modificó producción desde este entorno.
- Cada blob/data-image pegado se sube automáticamente por el endpoint existente
  `/admin/media`: CSRF, sesión/sitio, MIME real, límite de 10 MB, nombre seguro,
  resize y alta en `media` siguen siendo responsabilidad de `MediaService`. La
  respuesta JSON ahora incluye también `path` y `mime_type` diagnósticos.
- El placeholder muestra estados `uploading`, `stored` o `upload_failed`, permite
  reintentar, quitar o abrir un selector accesible de la biblioteca. URLs
  remotas, `blob:` y `file:` sin bytes nunca se descargan; bloquean «Proponer
  plan» hasta ser reemplazadas o eliminadas, con explicación visible.
- Una imagen almacenada se serializa como `data-ppa-media-id` + ruta interna.
  Nuevo `AssistantMediaReferences` vuelve a consultar la BD por `site_id`: ignora
  la ruta declarada por el navegador, rechaza ids ajenos/inexistentes y solo
  expone al planner `media_id` y path verificados.
- La descripción visual reutiliza `MediaLibraryService::describeAfterResponse`
  cuando el pegado no aporta un alt útil; por tanto usa la capacidad visual ya
  configurada sin bloquear la respuesta de subida en producción.
- Pruebas: composer 7/7, normalizador 16/16, referencias 4/4, biblioteca 11/11,
  onboarding photos 28/28, capacidades 17/17, secciones 10/10, i18n completo,
  lints PHP/JS y diff verde.
- QA real: PNG pegado entre dos párrafos → fila `media` site 1, MIME PNG real,
  path interno, fichero existente, placeholder `stored` en la misma posición y
  botón habilitado. Referencia HTTP/file → 0 cargas remotas, aviso bloqueante y
  botón deshabilitado; selector con 36 elementos → reemplazo válido y desbloqueo.
  Cuadrícula revisada en escritorio y a 390 px (2 columnas, sin overflow),
  consola limpia. Fila y archivo sintéticos eliminados al terminar (0 restos).

## Executor's Feedback or Assistance Requests (ASSISTANT-RICH)

AR3 queda preparado para la prueba del usuario en producción: pegar el correo
real, esperar a que las imágenes indiquen «guardada en Medios» y reemplazar o
quitar cualquier referencia privada pendiente. Conforme al flujo de un único
hito del Executor, AR4 no empieza hasta recibir el resultado de esa prueba.

### Release 1.1.0 solicitada (26/08/2026, Executor)

- El usuario pide versionar primero el estado actual y recibir un ZIP para
  instalarlo personalmente en producción.
- El árbol de trabajo contiene una entrega acumulada coherente (Assistant
  enriquecido, Recursos, reservas, Studio e internacionalización), por lo que
  se versionará el snapshot completo en lugar de publicar un subconjunto con
  dependencias ausentes.
- `PP_VERSION` pasa de `1.0.0` a `1.1.0`. Credenciales, datos runtime de Medios,
  Documentos y Recursos, logs, cachés y paquetes generados quedan excluidos de
  Git y del ZIP.
- Criterio de éxito: pruebas de regresión relevantes verdes, commit sin datos
  locales ni secretos, push normal a `origin/main`, paquete aceptado por el
  fingerprint del actualizador y verificado con `unzip -t` + SHA-256.

### Release 1.1.0 preparada para producción (26/08/2026, Executor)

- Snapshot de producto versionado en `83654f1` y publicado mediante push normal
  en `origin/main`; HEAD remoto comprobado contra el commit local.
- Regresión verde: Gemini 3.7, normalizador y composer enriquecidos, referencias
  de Medios, taxonomía del Assistant, idiomas del panel, formularios, reservas,
  Canvas y todas las suites de Recursos ejecutadas para esta entrega.
- ZIP generado en
  `deliverables/promptpress-1.1.0-assistant-rich-ar3-20260826.zip`: 1.031
  archivos, 2,6 MB y versión declarada `1.1.0`.
- SHA-256:
  `b7124bd155a89a79b9aa40f7612aafeedf669c864067e2b9dadb8243b6ccef2c`.
- `unzip -t` sin errores; fingerprint y archivos de AR3 presentes; credenciales,
  `.git`, herramientas locales, entregables y todo el storage runtime ausentes.
- No se ha desplegado desde este entorno. AR3 continúa pendiente únicamente de
  la validación manual que el usuario realizará en producción.

### AR3 · corrección UX para imágenes externas de Gmail (27/08/2026, Executor)

- La prueba en producción confirmó que Gmail entrega las imágenes del correo
  como referencias externas, no como archivos de portapapeles. El comportamiento
  seguro era correcto, pero repetir el mismo aviso y bloquear todo el análisis
  obligaba al operador a resolver una incidencia normal imagen por imagen.
- El composer muestra ahora un único resumen con el número de imágenes y dos
  acciones: «Continuar solo con el texto» y «Revisar imágenes». Cada posición se
  conserva como marcador breve para poder sustituir solo las imágenes relevantes.
- Continuar descarta exclusivamente referencias `remote_url`/`unresolved`; una
  subida real fallida sigue bloqueando y conserva «Reintentar». No se añaden
  descargas automáticas de URLs ni se amplía la superficie SSRF.
- Pruebas TDD del composer: 9/9. Regresión verde de normalizador, referencias de
  Medios, capacidades, planificación e i18n. QA visual local: aviso único para
  dos imágenes, continuar habilita «Proponer plan» sin perder el texto y revisar
  mueve el foco a la primera acción «Elegir de Medios».
- AR3 sigue pendiente de revalidación en producción; no se ha hecho push, ZIP ni
  despliegue como parte de esta corrección.

### AR4 iniciada (27/08/2026, Executor)

- El usuario aprueba continuar y agrupar el siguiente paquete de producción tras
  cerrar la fase y realizar una pasada conjunta de ajustes.
- La corrección UX de Gmail queda aislada en el commit `cc328e0`.
- Documentación oficial de OpenRouter reverificada: las imágenes viajan como
  partes `image_url` y la capacidad autoritativa se publica en
  `architecture.input_modalities`. El gate será explícito y conservador; un
  fallo de descubrimiento nunca se interpretará como soporte visual.
- Orden de implementación AR4: redacción global de `_images` en logs, gate de
  provider/modelo, preparación acotada de hasta cuatro medios verificados,
  referencias tipadas del plan y degradación visible sin visión.

### AR4 preparada para revisión (27/08/2026, Executor)

- Nuevo `AIProviderCapabilities`: OpenRouter consulta/cachea seis horas el
  endpoint oficial del modelo y solo habilita visión si
  `architecture.input_modalities` contiene `image`. Fallo de red, modelo ausente
  o provider sin transporte implementado degradan a texto; Anthropic permanece
  explícitamente deshabilitado en esta fase.
- `AIActionRunner` aplica el gate global antes de adjuntar imágenes y
  `AILogger::sanitizeForStorage()` redacta `_images` en éxito, fallo de provider
  y fallo de parseo. El log conserva únicamente MIME, bytes, dimensiones,
  `media_id` y SHA-256; nunca base64, binarios ni URLs privadas.
- Nuevo `AssistantVisionImages`: acepta solo medios `stored` del sitio, no hace
  red, limita a cuatro JPEG/PNG/WebP, convierte GIF a frame PNG, rechaza más de
  10 MB/25 MP y redecodifica a un máximo de 1.600 px antes de enviar.
- `PLAN_SITE_CHANGES` recibe manifiesto visual y devuelve
  `source_block_ids`/`media_ids`; el backend elimina ids inventados, ajenos,
  pendientes o repetidos. Sin visión, el prompt prohíbe afirmar que se inspeccionó
  una imagen y exige `needs_input` cuando el alt no basta.
- La UI muestra «La IA ha analizado N imágenes con modelo» o la degradación
  «El plan se ha basado solo en el texto», con singular/plural en ES/EN/FR/PT.
- Pruebas nuevas: redacción de logs 7/7, capacidades 5/5, preparación visual
  5/5, contrato multimodal 5/5 y smoke real OpenRouter 5/5. El modelo activo
  `google/gemini-3.7-flash` fue verificado con entrada `text + image`; devolvió
  referencias válidas `B1/B2` y `media_id=302`. El log sintético se eliminó.
- Regresión verde de normalizador, Medios, capacidades, planner, i18n, onboarding
  y composer. QA visual verde para visión usada y degradación solo texto. No se
  crearon páginas ni jobs, no se aplicaron cambios y no se tocó producción.

### AR5 iniciada (27/08/2026, Executor)

- El usuario autoriza continuar con AR5 y mantener agrupados el siguiente push y
  paquete de producción hasta cerrar la fase.
- Se implementará un sobre fuente firmado y de vida corta entre `/plan` y
  `/apply`: contiene el bundle semántico normalizado, pero el navegador no puede
  modificarlo sin invalidar la firma. El job persistirá una copia depurada al
  confirmarse; no se guardará HTML crudo.
- `createJob()` volverá a validar ids de bloque, ids de medios, propiedad por
  sitio, límites y páginas editables dentro de una transacción. Cada item llevará
  únicamente sus referencias y `stepJob()` reconstruirá el fragmento literal y
  las rutas actuales verificadas justo antes de editar.
- Primero se añaden pruebas de firma/manipulación y de construcción literal del
  contexto; después migración, integración HTTP y regresión del Assistant.

### AR5 preparada para revisión (27/08/2026, Executor)

- Nuevo `AssistantSourceEnvelope`: el bundle normalizado viaja comprimido con
  HMAC-SHA256, scope de sitio y caducidad de dos horas. La firma liga también
  los items del plan; cambiar página, instrucción o referencias después de la
  propuesta obliga a volver a planificar. El sobre nunca contiene HTML ni rutas
  de medios confiadas al navegador.
- Migración `2026_08_27_assistant_source_bundle.sql` y esquema de instalación
  añaden `source_bundle_json`, `source_block_ids_json` y `media_ids_json`.
  Migración aplicada en desarrollo y segunda ejecución idempotente verificada.
- `createJob()` vuelve a sanear el bundle, consulta en BD la propiedad/ruta de
  cada medio, descarta ids inexistentes, ordena bloques como en el documento y
  guarda job+items en una transacción. Fuentes de más de 24.000 caracteres por
  paso se dividen solo entre bloques completos; nunca en mitad de un párrafo.
- `stepJob()` reconstruye exclusivamente las referencias del item justo antes de
  llamar al Canvas. El prompt delimita el material como datos no confiables,
  exige copiar literalmente cuando se incorpora texto y enumera las rutas
  internas verificadas de los medios autorizados.
- Pruebas nuevas: sobre/firma 7/7 y persistencia/contexto 9/9. Regresión verde de
  todas las suites `assistant*`, taxonomía y planificación del Assistant,
  composer JS, referencias multimedia, Canvas runtime/settings/images/box y
  sintaxis JS/PHP. No se hizo ninguna llamada IA ni se modificó una página; los
  jobs sintéticos se eliminaron al terminar.
- AR5 queda implementada y pendiente de validación del usuario. Push, ZIP y QA
  real sobre dos páginas permanecen agrupados para la pasada conjunta acordada.

### AR6 iniciada (27/08/2026, Executor)

- El usuario autoriza la regresión/QA end-to-end y solicita publicar después en
  GitHub. Se validarán primero suite, migraciones, paquete, consola, responsive,
  ingesta/planificación y ejecución reversible; el push se hará solo tras quedar
  verde y sin residuos de prueba.

### AR6 preparada para revisión y publicación (27/08/2026, Executor)

- QA real en `/admin/assistant`: pegado HTML tipo Gmail con lista, negrita,
  cursiva e imagen remota; el aviso agrupado apareció correctamente, se sustituyó
  la referencia desde Medios y Gemini 3.7 Flash confirmó análisis visual.
- El plan detectó dos páginas Canvas temporales y dos secciones distintas. Se
  aplicaron ambos items, quedaron en `draft`, cada uno creó una versión y el job
  persistió solo `B2`/`B3`; la imagen no necesaria no se asignó a los cambios.
- El primer recorrido descubrió una regresión real: `CanvasSanitizer` convertía
  rayas tipográficas `—/–` en guiones ASCII, por lo que el texto no era literal
  aunque el executor lo hubiera copiado bien. Corregido: el saneador ya no muta
  puntuación confirmada; el anti-slop permanece responsabilidad del prompt.
- Recorrido repetido tras el fix: los dos textos llegaron byte por byte con su
  raya larga; ambos borradores siguieron sin publicar. `Deshacer` se validó en
  el Studio para las dos páginas (texto ausente y `Rehacer` disponible).
- Logs de las seis llamadas reales verificados sin base64 ni `data:image`; bundle
  del job sin HTML crudo. Consola limpia en Assistant y en una carga nueva del
  Studio. La emulación de viewport del navegador integrado no aplicó el override
  solicitado (reportó 1280 px); se verificó el contrato CSS móvil a 700 px y la
  suite del composer, pero la comprobación visual exacta a 390 px queda para la
  prueba del ZIP en producción.
- Regresión verde: normalizador, Medios y aislamiento, planner multimodal,
  sobre/firma, persistencia/contexto, capacidades, Canvas sanitizer/runtime,
  envelopes, contexto conversacional, cancelación, imágenes, editor de cajas,
  composer JS y lint PHP/JS. La prueba destructiva del instalador de updates no
  se ejecutó sobre el workspace; el propio constructor reabre y valida el ZIP.
- Limpieza completada: deshechos ambos cambios y eliminadas únicamente las dos
  páginas temporales 2607/2608, jobs 7/10 y logs QA 1455–1460. Eran fixtures
  locales recuperables solo desde la copia del entorno; no se tocó contenido real.
- Publicación técnica completada: commits `cc328e0`, `9f48c63`, `d985400` y
  `e6d67b5` enviados a `origin/main`. ZIP final validado en
  `deliverables/promptpress-1.1.0-assistant-rich-ar6.zip` (1.045 archivos,
  2,6 MB, SHA-256 `463e5cc59f7b681f3ea4c6961cb4324de7891bcbd97a73668f4ede1018c37209`),
  sin configuración local, uploads, logs, `.cursor` ni credenciales.

## Lessons (ASSISTANT-RICH)

- “Aceptar rich text” y “mandar HTML al modelo” no son equivalentes. El valor
  está en conservar jerarquía y referencias; el HTML de origen es una superficie
  de ataque y una fuente de ruido.
- Una imagen visible en el contenido pegado no implica que el navegador entregue
  sus bytes. Diseñar el flujo alrededor de ese supuesto produciría fallos
  silenciosos justo en Gmail/Google Docs.
- En un pipeline planificador→executor, el contenido largo debe viajar por
  referencias verificables. Hacer que el planner lo repita en cada instrucción
  introduce truncado, paráfrasis y coste multiplicado por página.
- Soporte multimodal en el formato HTTP no demuestra que el modelo configurado
  tenga visión. La capacidad debe comprobarse y reflejarse en la interfaz.
- La API de Clipboard define tipos intercambiables, pero no obliga a Gmail,
  Docs, Word o Notion a entregar los bytes de cada imagen incrustada. La
  compatibilidad debe medirse por fuente+navegador y admitir `partial` como un
  resultado normal, no como un error silencioso.
- Antes de pasar imágenes por el runner común, la redacción de `_images` en
  `AILogger` es una condición de seguridad global, no un detalle del endpoint
  del Assistant.
- Gemini puede respetar el contenido de un schema pero envolver el objeto único
  en una lista. Normalizar solo `[objeto_con_items]` preserva robustez sin
  convertir respuestas múltiples o ambiguas en un plan ejecutable.
- La confirmación humana no resuelve por sí sola datos ausentes: una categoría
  `needs_input` no debe tener un atajo que cambie su status a ejecutable sin
  incorporar primero esos datos y volver a planificar.
- Un ping con `max_tokens` extremadamente bajo no es una prueba de conexión
  fiable para modelos con razonamiento: la llamada puede ser correcta y agotar
  el presupuesto antes de emitir texto. El smoke test debe reservar salida
  suficiente y verificar el id de modelo devuelto.
- Para conservar el orden real de una imagen inline no basta con añadirla al
  final del párrafo: el normalizador debe cerrar el bloque textual, emitir la
  referencia de imagen y abrir un nuevo bloque con el texto posterior.
- El texto alternativo de una imagen es metadato útil para IA y accesibilidad,
  pero no debe contarse como un párrafo independiente al validar contenido.
- Un fallback no es útil si solo existe: al derivar texto desde un DOM rico hay
  que introducir separadores explícitos entre listas anidadas, filas y celdas;
  `textContent` concatena esos elementos aunque la preview visual parezca bien.
- Serializar una referencia remota como `<img src>` puede iniciar una descarga
  incluso fuera de la preview. Usar `data-ppa-source` hasta el normalizador
  server-side conserva la referencia sin producir una petición del navegador.
- Un botón deshabilitado no explica por qué no se puede continuar. Cuando una
  imagen queda pendiente, el estado global debe sustituir el mensaje genérico de
  importación por una advertencia accionable (reemplazar, eliminar o reintentar).
- Las grids dentro de un diálogo flex necesitan `min-height:0`,
  `grid-auto-rows:max-content` y `align-content:start`; sin ello muchas filas se
  comprimen para caber y ocultan las etiquetas aunque exista `overflow-y:auto`.
- Nunca aceptar la ruta interna enviada junto al `media_id`: resolver siempre la
  fila por `id + site_id` y reconstruir el path desde la BD evita tanto cruces
  multisitio como sustitución de ruta manipulada.
- Una referencia externa procedente de un correo es un caso normal, no un error
  excepcional. Debe resumirse y ofrecer degradación explícita a texto; solo los
  fallos de una subida que sí tenía bytes deben mantener el bloqueo de error.

### AR7 iniciada (27/08/2026, Executor)

- El usuario confirma que el pegado desde Gmail debe ofrecer importación directa
  en bloque. Se añadirá «Importar N imágenes» al aviso existente: el backend
  intentará descargar cada referencia accesible, guardará los éxitos en Medios y
  dejará únicamente los fallos para selección manual o descarte.
- Es un cambio de red sensible: las URLs pegadas son datos no confiables. El
  importador limitará esquema, redirects, tiempo, tamaño y tipos; bloqueará
  credenciales, localhost, redes privadas/reservadas y DNS que resuelva a ellas.
  No reenviará cookies del navegador ni persistirá URLs temporales de Gmail.
- TDD: primero contratos de seguridad del importador y del lote parcial en el
  composer; después endpoint, UI, traducciones y regresión completa del flujo.
- Criterio de éxito de este único hito: un lote puede acabar parcialmente
  importado sin perder el orden; cada éxito se convierte en medio verificado del
  sitio y los fallos conservan el fallback «Elegir de Medios».

### AR7 preparada para revisión (27/08/2026, Executor)

- El aviso de Gmail ofrece ahora «Importar N imágenes». Envía únicamente las
  referencias HTTP/HTTPS del lote al nuevo endpoint autenticado
  `/admin/media/import-remote`; blobs sin bytes siguen necesitando reemplazo.
- `RemoteImageImporter` valida URL y cada redirect, rechaza credenciales,
  localhost, puertos no estándar y rangos privados/reservados IPv4/IPv6. La
  descarga queda fijada a una IP pública resuelta, sin proxy/cookies, con 20 s,
  máximo 3 redirects, 10 MB y 25 MP; solo JPEG/PNG/WebP/GIF detectados por
  contenido entran en Medios. Las URLs temporales no se persisten.
- El lote es parcial por diseño: cada éxito sustituye su marcador en la misma
  posición y habilita el plan cuando ya no quedan pendientes; cada fallo conserva
  «Elegir de Medios», «Continuar solo con el texto» y el resumen de revisión.
  Singular/plural corregidos en ES/EN/FR/PT.
- QA real: una PNG pública se importó, creó media 476 y habilitó «Proponer plan».
  Segundo recorrido con una pública + `127.0.0.1` importó solo la pública, dejó
  la privada pendiente y no produjo ninguna petición local. Consola sin errores.
- Limpieza: medios sintéticos 476/477 y sus archivos eliminados; comprobación
  final con 0 restos. No se propuso plan, no hubo llamada IA ni cambios de página.
- Pruebas verdes: seguridad URL/IP 25 checks, composer 11/11, i18n completo,
  normalizador 16/16, referencias 4/4, visión 5/5, planner 5/5, source envelope
  7/7, contexto de jobs 9/9, responsive 7/7, lint PHP/JS y `git diff --check`.

### AR7 preparada para publicación (27/08/2026, Executor)

- Implementación versionada en `8bff515` y paquete generado mediante el
  empaquetador oficial en
  `deliverables/promptpress-1.1.0-assistant-rich-ar7.zip`.
- ZIP: 1.047 archivos de aplicación, 2,6 MB, 1.210 entradas incluyendo
  directorios; `unzip -t` sin errores. Incluye `RemoteImageImporter`, composer y
  pruebas AR7; excluye configuración, credenciales, `.cursor`, logs y uploads.
- SHA-256:
  `55e07028301d1534f0708648e8930e428d3dca596d7eaa6f7609048507ce2036`.
- Push normal de AR7 completado en `origin/main`; la comprobación final confirma
  que el hash remoto coincide exactamente con HEAD local y el árbol queda limpio.

## Lesson (AR7)

- `FILTER_FLAG_NO_RES_RANGE` de PHP no bloquea por sí solo rangos como CGNAT
  `100.64.0.0/10`, benchmark `198.18.0.0/15` o multicast. En controles SSRF
  hace falta una denylist CIDR explícita además de fijar cURL a la IP ya validada.

### AR7.1 corrección iniciada (27/08/2026, Executor)

- La prueba real en producción importó 2/4 referencias, pero las dos filas
  marcadas como éxito aparecen rotas en Medios. El flujo descarga con `tempnam`
  y usa `rename`; ese movimiento conserva el modo restrictivo del temporal y el
  servidor de estáticos puede no tener permiso de lectura aunque PHP sí lo tenga.
- La corrección exigirá modo legible tras mover y tras redimensionar, validación
  final antes de devolver éxito y rollback de fila+archivo si no se puede servir.
  La biblioteca reparará de forma acotada los `email-*` ya importados para que
  las dos imágenes existentes se recuperen al instalar la actualización.

### AR7.1 preparada para revisión (27/08/2026, Executor)

- Causa reproducida y corregida: `tempnam` crea `0600` y `rename` conservaba el
  modo. El importador aplica y comprueba `0644` antes de guardar y después del
  resize; valida de nuevo tamaño, MIME y dimensiones. Si la validación final o
  la fila fallan, elimina tanto BD como archivo antes de devolver fallo.
- Reparación retroactiva acotada: al abrir `/admin/media` o cargar la biblioteca,
  solo los paths propios `storage/uploads/{site}/email-*` pasan por la misma
  validación/chmod. No toca uploads normales, otros sitios ni rutas manipuladas.
- QA real: PNG externa importada como media 479, archivo `0644`, 13.504 bytes,
  respuesta pública `200 image/png` y preview sin errores de consola. Después se
  forzó el mismo archivo a `0600`; abrir Medios lo reparó a `0644` y siguió
  respondiendo 200. Fila 479 y archivo sintético eliminados (0 restos).
- Regresión verde: importador 28 checks, composer 11/11, i18n, normalizador,
  referencias, visión, planner, source envelope, jobs, responsive, lint PHP/JS
  y `git diff --check`.

## Lesson (AR7.1)

- `is_readable()` solo prueba el usuario de PHP y puede dar un falso positivo si
  nginx/Apache sirve estáticos con otro usuario. Un archivo destinado a URL
  pública debe tener además el bit de lectura para otros y comprobarse tras
  cualquier transformación que pueda recrearlo.

### AR7.1 preparada para publicación (27/08/2026, Executor)

- Fix versionado en `2a5fed9`. El ZIP AR7 anterior queda sustituido por
  `deliverables/promptpress-1.1.0-assistant-rich-ar7.1.zip` para evitar instalar
  de nuevo el paquete con el problema de permisos.
- Paquete: 1.047 archivos de aplicación, 2,6 MB y 1.210 entradas con
  directorios; `unzip -t` sin errores. Incluye importador/controlador/test
  corregidos y excluye configuración, credenciales, `.cursor`, logs y uploads.
- SHA-256:
  `a684534af72dd2a8a19c41a5593cd68c64e7e36f0880a9e5d4eb1edb174d1554`.

### AR7.2 filtro de emojis iniciado (27/08/2026, Executor)

- La validación en producción aclara el caso: Gmail serializa ✨/🍀 como `<img>`
  públicas y las fotografías reales como referencias privadas no descargables.
  El usuario arrastrará las fotos directamente; el hito se limita a impedir que
  los emojis se cuenten, importen o envíen a visión como imágenes.
- Regla: si el `alt` completo es una secuencia emoji Unicode válida (incluyendo
  variante, tono de piel, ZWJ, banderas o keycaps), el nodo se convierte en texto
  en la misma posición antes de clasificar su `src`. Texto mixto o nombres de
  archivo no se consideran emoji.

### AR7.2 preparada para revisión (27/08/2026, Executor)

- `isEmojiOnlyAlt()` reconoce pictogramas Unicode, variantes, modificadores,
  secuencias ZWJ, banderas regionales y keycaps; rechaza vacío, nombres de
  archivo y texto mixto. El saneador convierte el `<img>` en `Text` antes de
  crear referencias o consumir el límite de imágenes.
- QA real con HTML tipo Gmail: dos `<img alt="✨/🍀">` + dos fotografías privadas.
  Resultado: texto `Hola ✨ gracias 🍀`, exactamente 2 markers pendientes,
  resumen «2 imágenes», ninguna descarga/alta y consola limpia.
- El arrastre directo no cambia: los archivos reales del clipboard siguen el
  camino `captured` → upload → medio verificado.
