# IAIA Analytics — Plugin de WordPress (proyecto independiente)

> Proyecto SEPARADO de PromptPress. Todo lo que se haga vive en la carpeta
> `iaia-analytics/` para poder extraerla a su propio repositorio en cualquier
> momento. El nombre "IAIA Analytics" (slug `iaia-analytics`) es provisional y
> renombrable antes de publicar.

## Background and Motivation

- (2026-07-15) El usuario tiene webs en WordPress y quiere dejar de depender de
  Google Analytics, principalmente para **reducir la carga GDPR**: con GA los
  datos salen a terceros; con analítica propia los datos nunca abandonan el
  servidor de cada web.
- PromptPress ya tiene un módulo de analítica propio, privacy-first (sin
  cookies, sin IP/UA persistidos, hash de visitante con salt diario que se
  purga a los 2 días, retención de eventos crudos 90 días, agregados
  indefinidos). Diseño original: `cursor/analytics-design.md` del repo padre.
- Se evaluaron dos arquitecturas: (A) servicio central en subdominio +
  plugin conector, y (B) **plugin de WP autocontenido** (todo dentro del propio
  WordPress: ingesta, BD, rollup y dashboard). Se eligió (B) por simplicidad,
  cero infraestructura nueva y máxima solidez GDPR (ni siquiera hay
  transferencia entre servidores propios). (A) queda abierta como evolución
  futura; el core (recorder/rollup/stats) sería el mismo.

## Key Challenges and Analysis

### Inventario de lo que se porta (código origen en el repo padre)

| Pieza | Origen | Líneas | Cambios esperados |
|---|---|---|---|
| Tracker frontend | `public/js/pp-analytics.js` | 46 | Endpoint → REST de WP; el resto casi intacto |
| Ingesta | `app/Modules/Analytics/EventRecorder.php` | 189 | `Core\Database` → `$wpdb`; quitar dependencia de `sites`/`ModuleRegistry` |
| Rollup perezoso | `app/Modules/Analytics/RollupService.php` | 150 | `settings` → `wp_options` para el lock; `$wpdb` |
| Stats | `app/Modules/Analytics/StatsService.php` | 171 | Solo capa BD |
| Endpoint collect | `AnalyticsController::collect` | ~35 | → ruta REST `register_rest_route` |
| Vista dashboard | `views/admin/analytics/index.php` | 107 | Adaptar shell a página de admin de WP (sin layout de PromptPress) |
| JS dashboard | `admin/assets/js/analytics-dashboard.js` | 294 | Cambiar endpoint de datos; resto intacto (SVG chart puro, sin deps) |
| CSS dashboard | Bloque `.pp-analytics-*` en `admin/assets/css/admin.css` (~línea 9855) | ~200 | Extraer a `assets/css/analytics.css` propio del plugin |
| Esquema SQL | `database/migrations/2026_07_02_analytics.sql` | 46 | Prefijo `$wpdb->prefix`, crear con `dbDelta` en activación |

### Decisiones técnicas (Planner)

1. **`site_id` se mantiene en el esquema con valor fijo `1`.** Minimiza la
   divergencia con el código origen y deja la puerta abierta a WP multisite.
   Se elimina la FK a `sites` (no existe en WP).
2. **Ingesta vía REST API de WP**: `POST /wp-json/iaia-analytics/v1/collect`,
   pública (`permission_callback => '__return_true'`), responde 204 siempre.
   Es same-origin (la web se trackea a sí misma) → **no hay problema de CORS
   ni de preflight con sendBeacon**. Nota: el body llega como JSON estándar,
   WP REST lo parsea nativamente.
3. **Lock del rollup y ajustes** → `wp_options` (`get_option`/`update_option`)
   en lugar de la tabla `settings` de PromptPress. Rollup sigue siendo
   perezoso (al abrir el dashboard, máx. 1 vez/hora); opcionalmente se puede
   añadir WP-Cron después, no en el MVP.
4. **Tablas propias con prefijo**: `{$prefix}iaia_events`, `{$prefix}iaia_daily`,
   `{$prefix}iaia_salts`. Creación en `register_activation_hook` con `dbDelta`.
   `uninstall.php` las elimina (borrar plugin = borrar datos, avisado en readme).
5. **No trackear a usuarios logueados con capacidad de edición**
   (`current_user_can('edit_posts')`) — evita inflar datos con las visitas
   propias del administrador. Filtro server-side al encolar el tracker.
6. **CSS en fichero propio** (`assets/css/analytics.css`), nunca inline
   (lección de usuario). JS del dashboard también como asset encolado solo en
   la página del plugin.
7. **API pública `ppTrack(nombre)` se conserva** (mismo contrato que en
   PromptPress) para eventos personalizados; renombrable después si molesta.
8. **Sin build step, sin Composer, sin dependencias**: PHP plano + JS plano,
   igual que el origen. Requisitos: WP ≥ 6.x, PHP ≥ 8.1 (el código origen usa
   `match` y `declare(strict_types=1)`), MySQL/MariaDB.

### Riesgos / puntos de atención

- **`$wpdb` no tiene placeholders `?`**: hay que convertir todas las queries a
  `$wpdb->prepare()` con `%s`/`%d`. Es mecánico pero propenso a erratas —
  revisar query a query, no con buscar-y-reemplazar ciego.
- **`BINARY(16/32)`** (visitor_hash, salt): `dbDelta` es quisquilloso con tipos
  poco comunes. Verificar que crea bien las columnas; si no, fallback a
  `$wpdb->query(CREATE TABLE ...)` directo en la activación.
- **Zona horaria**: PromptPress usa `NOW()`/`CURRENT_DATE` de MySQL y `date()`
  de PHP. En WP conviene ser consistente (WP maneja su propia TZ via
  `wp_timezone()`); decisión MVP: seguir usando la TZ de MySQL/PHP del
  servidor como el origen, revisar si los días "bailan".
- **Entorno de pruebas**: hace falta un WordPress local para verificar
  (instalación + activación + tracking + dashboard). Propuesta: `wp-env`
  (Docker) o un WP en el MAMP/valet local del usuario. **Pendiente de decidir
  con el usuario** (ver Feedback).
- GDPR: el diseño no persiste datos personales y no usa cookies → sin banner
  según el criterio de la industria privacy-first. No somos asesoría legal;
  documentarlo en el readme del plugin con esa cautela.

## High-level Task Breakdown

Cada tarea es pequeña y auto-verificable. El Executor hace UNA tarea, verifica,
y espera confirmación antes de seguir.

- [ ] **T0 — Esqueleto del plugin.**
  Estructura de carpetas y fichero principal `iaia-analytics.php` con cabecera
  de plugin válida, constantes (versión, prefijo de tablas), autoload simple de
  clases del plugin, hooks de activación/desactivación vacíos y `uninstall.php`
  stub. Estructura propuesta:
  ```
  iaia-analytics/
  ├── cursor/scratchpad.md      ← este documento
  ├── plugin/                   ← LO QUE SE ZIPEA
  │   ├── iaia-analytics.php
  │   ├── uninstall.php
  │   ├── includes/  (Schema.php, EventRecorder.php, RollupService.php,
  │   │               StatsService.php, RestController.php, AdminPage.php,
  │   │               Tracker.php)
  │   └── assets/    (js/tracker.js, js/dashboard.js, css/analytics.css)
  ├── docs/          (readme del plugin, notas GDPR)
  └── scripts/       (build-zip.sh)
  ```
  *Éxito:* `php -l` limpio en todos los ficheros; el plugin se activa en un WP
  local sin errores ni warnings en `debug.log`.

- [ ] **T1 — Esquema y ciclo de vida de las tablas.**
  `Schema.php`: creación de `iaia_events`, `iaia_daily`, `iaia_salts` en la
  activación (adaptación del SQL de `database/migrations/2026_07_02_analytics.sql`
  con prefijo WP y sin FK a sites); `uninstall.php` real (DROP + delete_option).
  *Éxito:* activar el plugin crea las 3 tablas con las columnas/índices
  correctos (verificado con `SHOW CREATE TABLE`); borrar el plugin las elimina.

- [ ] **T2 — Portar `EventRecorder` a `$wpdb`.**
  Misma lógica que el origen (bot filter, sanitización, referrer host, device/
  browser, hash con salt diario, purga de salts). Cambios: `$wpdb->prepare`,
  tabla con prefijo, y el check de auto-referencia compara contra
  `home_url()` en vez de `$_SERVER['HTTP_HOST']`.
  *Éxito:* test CLI (script en `scripts/` contra un WP local con `wp eval-file`
  o similar) que inserta un pageview y verifica la fila: sin IP/UA, hash de 16
  bytes, referrer interno descartado, bot descartado.

- [ ] **T3 — Endpoint REST de ingesta + tracker.**
  `RestController.php` registra `POST /iaia-analytics/v1/collect` (mismos
  checks que `AnalyticsController::collect`, sin site/módulo: solo payload
  válido → `EventRecorder::record`, 204 siempre). `Tracker.php` encola
  `assets/js/tracker.js` (adaptación de `pp-analytics.js`: endpoint REST vía
  `wp_localize_script`/atributo data, sin `data-site`) en el frontend,
  excluyendo a editores logueados.
  *Éxito:* visitar la web local genera una fila de pageview; `ppTrack('x')`
  desde consola genera un evento `x`; visitar logueado como admin NO genera
  fila; `curl` con UA de bot NO genera fila.

- [ ] **T4 — Portar `RollupService` y `StatsService`.**
  Rollup perezoso con lock en `wp_options` (TTL 1 h), consolidación de días
  completos, purga 90 días + salts 2 días. Stats idéntico (rangos 7/30/90,
  serie diaria, live de hoy, top por dimensión, deltas del periodo anterior).
  *Éxito:* script de test que siembra eventos de ayer y de hoy, ejecuta el
  rollup forzado y comprueba: filas de `iaia_daily` correctas por dimensión,
  eventos crudos de hace 91 días purgados, `StatsService::forRange` devuelve
  serie continua con el live de hoy fusionado (paridad con
  `tests/analytics_rollup.php` del repo padre).

- [ ] **T5 — Dashboard en wp-admin.**
  `AdminPage.php`: entrada de menú "Analítica" (icono chart), página que
  renderiza el shell (adaptación de `views/admin/analytics/index.php` sin el
  layout de PromptPress), encola `dashboard.js` + `analytics.css` (portados; el
  CSS extraído del bloque `.pp-analytics-*` de `admin/assets/css/admin.css`) y
  endpoint de datos para el cambio de rango (REST `GET .../v1/stats?range=N`,
  restringido a `manage_options`). El rollup perezoso se dispara al cargar la
  página.
  *Éxito:* con datos sembrados, el dashboard muestra KPIs, gráfica SVG, tops y
  deltas; el selector 7/30/90 días actualiza sin recargar; sin errores JS en
  consola; estilos correctos dentro del admin de WP (sin chocar con su CSS).

- [ ] **T6 — Empaquetado y documentación.**
  `scripts/build-zip.sh` (zip de `plugin/` → `iaia-analytics.zip`),
  `docs/readme.md` (instalación, qué datos se guardan y cuáles no, retenciones,
  posicionamiento GDPR con cautela legal, cómo usar `ppTrack`).
  *Éxito:* el zip generado se instala desde "Plugins → Añadir nuevo → Subir" en
  un WP limpio y todo el flujo (T1–T5) funciona de una pieza.

## Fase 2 (Planner, 2026-07-15) — Análisis de nuevas funcionalidades

Contexto previo: endurecimiento de privacidad aplicado (IP truncada a /24//48
antes del hash + sal diaria purgada al empezar el día siguiente). La postura
"anonimización, sin banner" validada con la consultoría CONDICIONA el diseño
de la Fase 2: nada que identifique o siga a un visitante individual.

### F1 — Drill-down por áreas (fuentes, páginas, dispositivos…) — VIABLE
Los rollups diarios ya guardan (día × dimensión × valor), así que se puede:
vista de detalle por dimensión con la lista completa (no solo el top 8),
y al pulsar un elemento (p. ej. una página) su serie temporal propia.
LÍMITE técnico a documentar: no hay cruce de dimensiones ("fuentes de UNA
página") porque los rollups son unidimensionales; cruzar solo sería posible
sobre eventos crudos (ventana de 90 días) o añadiendo rollups 2D (cardinalidad
alta — decidir solo si de verdad se necesita).

### F2 — Buscador de páginas — VIABLE Y BARATO
Búsqueda sobre dim_key de la dimensión 'page' en iaia_daily (LIKE), enlazando
a la vista de detalle de F1. Sale casi gratis con F1.

### F3 — "Camino de un visitante concreto" — NO (incompatible con GDPR)
Es exactamente el "perfilado / identificador de usuario / grabación de
recorrido" que el punto 3 de la consultoría prohíbe sin consentimiento, y
además es técnicamente imposible entre días (hash rotativo) y aún más con la
IP truncada. ALTERNATIVA legal y útil: **flujos agregados** — en el rollup
diario calcular transiciones "página A → página B" (conteos anónimos, solo
agregados persistidos) y mostrar "entradas más comunes" y "a dónde van después".
Estadística de grupo, sin individuos: coherente con la postura sin banner.

### F4 — Importar histórico de GA4 — VIABLE (con matices honestos)
El histórico de GA4 se puede volcar en iaia_daily porque el modelo encaja:
día × dimensión (páginas, fuentes, dispositivos, totales). Dos rutas:
  (a) **CSV exportado desde GA4** (Informes → compartir/exportar): importador
      en el admin del plugin. Simple, sin credenciales. Recomendada.
  (b) API de datos de GA4 con service account: más completa pero mucha
      fricción de credenciales. Solo si (a) se queda corta.
Matices: las métricas no son 1:1 (los "usuarios" de GA4 no equivalen exactos a
nuestros únicos diarios; sesiones no existen aquí); los días importados
conviene marcarlos como origen 'ga4' para poder distinguirlos/reimportar.
GA4 además borra el histórico según su retención configurada: cuanto antes se
exporte, más se salva.

### F5 — Seguridad en actualizaciones — PRIORITARIA (ver pregunta del usuario)
HOY: "borrar plugin + subir de nuevo" EJECUTA uninstall.php → **borra todas
las tablas y el histórico**. Dos protecciones:
  1. WP ≥ 5.5: al subir el zip de un plugin ya instalado ofrece "Reemplazar el
     plugin actual con el subido" — esa vía NO borra nada y es la correcta.
  2. Cambiar uninstall.php para que POR DEFECTO conserve los datos, con una
     opción explícita "borrar datos al desinstalar" (patrón estándar en
     plugins de analítica). Cambio pequeño; hacerlo ANTES de usar el plugin
     en producción.

### Orden propuesto: F5 → F1+F2 → F4 → F3(agregado)
F5 es un seguro (media hora). F1+F2 comparten UI (2-3 días). F4 es un módulo
aparte (2-3 días vía CSV). El F3 agregado toca el rollup (1-2 días) y puede
esperar a ver si se echa de menos.

### Fuera de alcance del MVP (backlog)

- WP-Cron para el rollup (hoy: perezoso al abrir el dashboard, suficiente).
- Soporte multisite real (network activation, un site_id por blog).
- Widget de resumen en el Dashboard de WP (wp-admin home).
- Exportación CSV, exclusión de rutas, objetivos/conversiones configurables.
- Modo "servicio central" (arquitectura A) reutilizando este mismo core.
- i18n (`__()`/text domain) — el MVP sale en castellano hardcodeado como el origen.

## Project Status Board — Fase 2

- [x] Endurecimiento privacidad (IP /24 + sal solo del día) — 21/21 tests
- [x] F5 — uninstall.php conserva datos por defecto (opción `iaia_analytics_delete_on_uninstall='1'` para borrado real)
- [x] F1 — Drill-down: vistas list (?view=list&dim=…) y detail (?view=detail&dim=…&key=…) con serie propia
- [x] F2 — Buscador en las listas (param q, filtro server-side)
- [x] F4 — Importador GA4 (submenú "Importar GA4", CSV es/en, comentarios #, fechas YYYYMMDD, idempotente, log de imports)
- [ ] F3 — Flujos agregados (backlog: a ver si se echa de menos)

## Project Status Board

- [x] T0 — Esqueleto del plugin
- [x] T1 — Esquema y ciclo de vida de tablas
- [x] T2 — EventRecorder sobre $wpdb
- [x] T3 — Endpoint REST + tracker frontend
- [x] T4 — RollupService + StatsService
- [x] T5 — Dashboard en wp-admin
- [x] T6 — Zip + documentación

## Current Status / Progress Tracking

- (2026-07-15) Plan inicial redactado por el Planner.
- (2026-07-15, Executor) **T0–T6 implementados y verificados.** MVP completo:
  - Entorno de pruebas montado en `dev/` (WordPress es_ES + wp-cli sobre el
    MySQL local; ver "Entorno de pruebas" abajo). El plugin se probó primero
    por symlink y después con el ciclo real: zip → instalar → activar →
    trackear → uninstall (tablas fuera) → reinstalar.
  - T1: activar crea las 3 tablas; `wp plugin uninstall` las elimina (0 tablas
    tras uninstall, verificado).
  - T2/T3: pageview real vía REST guarda fila sin IP/UA (hash 32 hex);
    Googlebot descartado; `"Form Submit!!"` → `form_submit`; referrer propio →
    NULL (Directo); mismo IP+UA → mismo hash del día; admin logueado NO recibe
    el tracker (0 menciones vs 3 anónimo). Siempre responde 204.
  - T4: `scripts/test-rollup.php` — 19/19 aserciones PASS (agregados por
    dimensión, purga >90 días, purga de salts >2 días, fusión rollup+live,
    deltas). Ejecutar con `dev/wp.sh eval-file scripts/test-rollup.php`.
  - T5: dashboard verificado en navegador con 589 eventos sembrados
    (`scripts/seed-demo.php`): KPIs con deltas, gráfica SVG, tops, selector
    7/30/90 vía REST con nonce (200, sin errores de consola);
    `GET /stats` anónimo → 401.
  - T6: `scripts/build-zip.sh` genera `iaia-analytics.zip` (17 ficheros,
    ~60 KB); instalado desde el zip con éxito.

### Entorno de pruebas (dev/, ignorado en git)

- WP en `dev/wordpress`, URL `http://127.0.0.1:8899`, admin `admin` /
  `supersecret123`. Servidor: `php -S 127.0.0.1:8899 -t dev/wordpress`.
- BD: reutiliza `ppress_dev` (no había root de MySQL para crear otra) con
  prefijo de tablas `iaiawp_` — NO toca ninguna tabla de PromptPress. Limpieza
  total: `DROP TABLE` de `iaiawp_*` y borrar `dev/`.
- `dev/wp.sh <cmd>` = wp-cli con memoria/errores ajustados (PHP 8.4 escupe
  deprecations del phar si no).

- (2026-07-15, Executor) **Restyling post-MVP** (auditoría con taste-skill; los
  dashboards quedan fuera de su alcance, se aplicaron solo los principios
  transversales): emojis de dispositivos (🖥️📱📲) y del estado vacío (📊)
  sustituidos por Dashicons nativos de wp-admin (cero dependencias); placeholder
  `—` de los KPIs → `&nbsp;` (ban de em-dash visible); subtítulo "Navegadores"
  con jerarquía real (menor y muted). Verificado por DOM en el WP local y zip
  regenerado. Ojo dev: al re-enlazar el plugin por symlink hay que reiniciar
  `php -S` (caché de realpath sirve URLs de assets rotas).

- (2026-07-15, Executor, Fase 2) Todo verificado en el WP local:
  - F5: `wp plugin uninstall` conserva las 3 tablas y los datos (probado).
  - F1/F2: lista completa por dimensión con barras + buscador (probado con
    q=blog) + detalle con serie diaria propia (probado con /blog/post-1);
    dashboard.js con guards para vistas sin desgloses; rangos como enlaces en
    list/detail (solo el dashboard usa fetch+nonce).
  - F4: subida real por HTTP (curl multipart + nonce) de CSVs de muestra
    estilo GA4: totales y páginas importados a iaia_daily, "(not set)"
    descartado, ON DUPLICATE = reimportación idempotente. La exploración de
    GA4 debe exportarse con dimensión Fecha en valores diarios.
  - Matiz F4: el histórico importado solo se ve cuando el rango del dashboard
    lo alcanza (7/30/90 días desde hoy). Posible mejora backlog: rango "Todo"
    o selector de fechas libre.
  - Suite completa: 21/21 aserciones. Zip regenerado (18 ficheros).

## Executor's Feedback or Assistance Requests

- (resuelto 2026-07-15) Entorno de pruebas: el usuario no tenía WP local;
  se montó la opción (c) php -S + MySQL local en `dev/`.
- Pedido al Planner/usuario: probar el zip en una web WordPress real
  (hosting de verdad) antes de darlo por bueno — el entorno local no cubre
  proxies inversos, object cache ni plugins de caché/optimización que puedan
  diferir el JS del tracker.

## Lessons

- (heredadas del repo padre) `install/schema.sql` y `database/migrations`
  divergen — aquí no aplica, pero cuidado con mantener un único origen de
  verdad para el esquema (Schema.php).
- `sendBeacon` + blob `application/json` solo funciona sin CORS en same-origin;
  en este plugin todo es same-origin, no tocar ese código sin recordarlo.
- `wp eval-file` ejecuta el script con `eval()`: no admite
  `declare(strict_types=1)` (fatal "must be the very first statement").
- wp-cli.phar con PHP 8.4 necesita `-d memory_limit=512M` (el extractor del
  core se queda sin memoria con los 128M por defecto) y `-d error_reporting=0`
  para silenciar deprecations de Symfony.
- `visitor_hash`/`salt` se guardan en HEX (CHAR 32/64), no BINARY como en el
  origen: $wpdb puede mutilar binario no-UTF8 al validar charset.
- Fechas: todo usa la hora local de WP (`current_time`), nunca `NOW()` de
  MySQL, para que created_at, rollup y stats compartan zona horaria.
- La gráfica anima las barras con `animation … backwards` + delay: una captura
  inmediata tras cambiar de rango puede mostrar barras "ausentes" a medio
  animar. No es un bug; re-capturar un segundo después.
