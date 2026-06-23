# Background and Motivation

El 22-06-2026 una incidencia parcial de la API de Unsplash provocó que PromptPress generase una web sin imágenes y no informase al usuario. El Studio aceptó después la petición de añadir imágenes, modificó una sección, pero no incorporó ninguna fotografía. Se mantiene Unsplash como único proveedor; Pexels queda fuera de alcance.

# Key Challenges and Analysis

- `ImageBankService::search()` convierte cualquier error HTTP o respuesta inválida en `[]`, indistinguible de una búsqueda válida sin resultados.
- Los flujos de generación son best-effort y continúan sin comunicar que faltan imágenes.
- El Studio solo ofrece a la IA imágenes ya presentes en la biblioteca; no valida que una petición de añadir imágenes haya producido imágenes.
- Los mensajes al usuario no deben exponer claves ni detalles internos, pero los logs sí deben incluir proveedor, operación, código HTTP, cuota y consulta.

# High-level Task Breakdown

1. Añadir diagnóstico estructurado a las consultas de Unsplash y cobertura de pruebas, manteniendo compatibilidad con `search()`. Éxito: se distinguen éxito, cero resultados, timeout/red, autenticación, límite y error del proveedor; los logs contienen contexto seguro.
2. Propagar el estado de imágenes a los flujos de creación y mostrar un aviso no bloqueante cuando la página se genere sin imágenes por error externo. Éxito: la página se crea y la respuesta/UI explica que faltan imágenes.
3. Detectar en Studio las peticiones explícitas de imágenes, intentar resolverlas desde Unsplash y no confirmar el cambio si no se añadió ninguna. Éxito: ante fallo se conserva el contenido y se muestra un error concreto; ante éxito se añaden imágenes.
4. Ejecutar regresión y revisar mensajes/logs. Éxito: pruebas relevantes correctas y ningún secreto expuesto.

# Project Status Board

- [x] Tarea 1: diagnóstico estructurado del proveedor y pruebas. Validada por el usuario.
- [x] Tarea 2: aviso visible en generación. Validada por el usuario.
- [ ] Tarea 3: resolución y validación de imágenes en Studio. Implementada; pendiente de prueba manual.
- [ ] Tarea 4: regresión final. Automatizada correctamente; pendiente de confirmación humana.

# Current Status / Progress Tracking

Tareas 1 y 2 validadas. Tarea 3 implementada: el Studio detecta peticiones explícitas de fotos/imágenes, consulta e importa hasta tres opciones de Unsplash antes de llamar a la IA y verifica que el HTML resultante incorpora recursos visuales. Si falla la búsqueda/descarga o la IA no añade ninguna imagen, no guarda la edición y devuelve un error específico. Tarea 4 automatizada: pruebas de intención/contador, nueve diagnósticos, regresión Canvas runtime y settings correctas; lint PHP/JS y `git diff --check` correctos; ningún secreto aparece en líneas añadidas.

# Executor's Feedback or Assistance Requests

Se solicita prueba manual en producción de generación durante fallo de Unsplash y petición de imágenes desde Studio. El Planner debe confirmar después el cierre del proyecto.

# Lessons

- Unsplash puede sufrir incidencias parciales: una petición `200` aislada no descarta errores intermitentes.
- No representar errores del proveedor como una lista vacía sin conservar el motivo.
- En comandos `php -r` bajo shell, usar nombres PHP con una sola barra invertida dentro de comillas simples; duplicarlas produce un error de parseo.
- Las expresiones regulares en español deben cubrir explícitamente `imágenes` con tilde; `imagen(?:es)?` no lo hace.

---

# [2026-06-22] Studio: chat IA rechaza cambios + panel tapa el chat

## Background and Motivation (nuevo)
Dos problemas en el Studio (editor canvas en vivo):
1. El panel contextual de edición (commit 599cace añadió controles "Bloque" con 4 inputs de radio por esquina) crece tanto que aplasta el chat: "el chat casi no se ve".
2. Al pedir por chat un cambio de estilo (ej. border-top-left-radius 46px en la tarjeta "100% adaptado a LOMLOE") falla con "La IA no devolvió un cambio válido. Tu página no ha cambiado."

## Key Challenges and Analysis (nuevo)
- La acción `edit_canvas_section` obliga al modelo a devolver la SECCIÓN HTML COMPLETA reescrita, y `AIActionRunner::validateCanvasEdit` EXIGE `html` no vacío (lanza AIException si falta). El controlador mapea cualquier AIException sin status 401/403/429/5xx al mensaje genérico "no devolvió un cambio válido".
- Reproducción local: en una sección Hero grande con SVG (7.2 KB), el modelo devolvió `html` de solo 2.9 KB → reescribió y RECORTÓ la ilustración SVG, además de `css_append` correcto. En secciones muy grandes esto provoca o truncado (JSON inválido → AIException) o destrozo del SVG. Para un cambio SOLO de estilo, el modelo no necesita devolver HTML; basta `css_append`. Pero el validador lo rechaza si `html` viene vacío.
- Causa raíz problema 2: forzar reescritura completa del HTML para cambios de estilo. Solución: permitir ediciones SOLO-CSS (html vacío = sección sin cambios), instruir al modelo a no reescribir el HTML en cambios de estilo, y subir max_tokens como defensa.
- Problema 1: `.cvstudio-panel{max-height:62%}` + chat `flex:1`. Bajar el tope del panel y garantizar altura mínima del chat.

## High-level Task Breakdown (nuevo)
- T1: `validateCanvasEdit` acepta edición solo-CSS (html vacío con css/css_append presente). Éxito: no lanza si hay css.
- T2: `applySectionEdit` con html vacío conserva la sección original y aplica solo css_append. `applyPageEdit` con html vacío conserva el html original. Éxito: cambio solo-CSS se guarda sin tocar HTML.
- T3: Prompts `edit_canvas_section`/`edit_canvas_page`: permitir `"html":""` para cambios solo de estilo; subir max_tokens sección 9000→16000. Éxito: el modelo devuelve html vacío + css_append en pruebas.
- T4: CSS layout: bajar tope del panel y min-height del chat. Éxito: con panel "Bloque" abierto, el chat sigue legible.
- T5: Reproducción/regresión con prompts nuevos y test canvas. Éxito: cambio de border-radius vía chat se aplica sin romper HTML.

## Project Status Board (Studio chat/panel) — Executor
- [x] T1 validateCanvasEdit acepta solo-CSS (`AIActionRunner.php`). Test reflexión OK.
- [x] T2 applySectionEdit/applyPageEdit: html vacío conserva original (`CanvasController.php`). Verificado: SVG intacto.
- [x] T3 prompts edit_canvas_section/page "PREFIERE SOLO CSS" + max_tokens sección 9000→16000 (`Actions.php`).
- [x] T4 CSS: `.cvstudio-panel` max-height 62%→50%; `.cvstudio-chat__messages` min-height:96px (`admin.css`).
- [x] T5 Regresión: API real → IA devuelve html:"" + css_append con border-top-left-radius:46px; cambio de texto sigue devolviendo HTML; tests canvas_runtime/box_editor/settings PASS; preview verificado (panel 50% + chat legible), sin errores de consola.

## Lessons (Studio chat/panel)
- El mensaje "La IA no devolvió un cambio válido" es genérico: cualquier AIException sin status 401/403/429/5xx cae ahí (CanvasController match por httpStatus, no por mensaje). Engaña al diagnosticar.
- Forzar al modelo a reescribir la SECCIÓN/PÁGINA HTML completa en cambios de estilo es la causa raíz: trunca en secciones grandes y recorta/destroza SVG. Permitir html:"" + css_append es más robusto, rápido y seguro.

## [2026-06-22] Seguimiento: petición de imágenes falla con "no devolvió un cambio válido"
- Arreglado bug propio: la directiva "prefiere solo CSS" podía hacer que una imagen se pusiera como background-image en CSS con html:"" → la verificación `imageCount` solo mira el HTML de la sección → rechazo. Ahora, si `$requiresImages`, se añade directiva que OBLIGA a devolver HTML con <img>; `applyPageEdit` también recibe `$effectiveInstruction`. Verificado: 4/4 runs devuelven <img> en HTML. tests canvas_image_requests/runtime/box_editor PASS.
- NO se pudo reproducir el AIException exacto del usuario en local con el código nuevo. Hipótesis pendiente de confirmar con el usuario: (a) están probando en un entorno SIN los cambios desplegados (prod) → código viejo (max_tokens 9000, validador exige html) y en una sección Hero enorme con SVG + petición de imagen + elemento seleccionado (que fuerza preservar el SVG) → truncado → JSON inválido → AIException; o (b) revisar ai_logs/php-errors.log del servidor donde prueban para ver la causa real.

## [2026-06-22] Confirmado: truncado por reescribir SVG enorme en peticiones de imagen
- Usuario en PROD (centroformacionfedericogarcialorca.com/admin/canvas/9), cambios desplegados. "Ponle imagen de fondo en Hero" y "Pon imágenes en la página" → "no devolvió un cambio válido". Pista: "antes rápido, ahora piensa bastante" = truncado al reescribir el Hero (SVG grande).
- Causa: mi directiva previa forzaba HTML para TODA imagen → el modelo reescribía el SVG entero (18.9s en repro con sección de 6.4KB) → en su Hero real trunca → AIException.
- Fix definitivo:
  - Directiva por tipo: imagen de FONDO → CSS (`background-image`, html:"") sin tocar el SVG; imagen de CONTENIDO → <img> en HTML. (CanvasController + prompts section/page).
  - `applyPageEdit` recibe `$effectiveInstruction` (antes raw).
  - Verificación de imágenes ahora cuenta imágenes en HTML Y en CSS (background-image), antes/después; acepta fondos solo-CSS.
- Repro nueva: "Ponle imagen de fondo en Hero" → html:"" + css_append con background-image+url, SVG intacto, verificación pasa (before 0 → after 1), ~12-14s. Page-level "pon imágenes" → 4.5s, pasa. Tests canvas_image_requests/runtime/box_editor PASS.
- PENDIENTE usuario: desplegar estos cambios nuevos (commit+push+pull en prod) y reprobar.

## [2026-06-22] Fondos por CSS gestionables a mano (issues #1 y #2)
- Causa #2: el panel detectaba el fondo solo con `bgImageOf()` (busca un <img> de cobertura). Los fondos por CSS (background-image) que introdujo el flujo de IA no se detectaban → no salían los controles "Imagen de fondo/Oscurecer". "Antes se podía" porque antes los fondos eran <img>.
- Fix (overlay en CanvasController + panel canvas-studio.js):
  - `cssBgUrlOf(el)`: detecta background-image (inline o por hoja de estilos), ignora velos linear-gradient.
  - `describe` sección: `hasBgImage = <img> de cobertura O background-image CSS`.
  - Panel sección SIEMPRE ofrece control de fondo: "Poner imagen de fondo" si no hay; "Cambiar/Quitar/Atenuar" si hay (resuelve #1: poner fondo a mano y verlo al instante en el elemento exacto).
  - Ops para fondo CSS (estilo inline, alta especificidad, se serializa y guarda): bgimg mark/replace/remove sobre la sección (data-pp-bg-edit); bgdim = velo blanco translúcido (VEIL_PRESETS) "Atenuar fondo"; bgcolor pasa a backgroundColor (no shorthand) para no borrar la imagen.
  - replace-image: rama para data-pp-bg-edit (pone background-image inline conservando velo). serializeAndSave limpia los marcadores.
  - El sanitizer conserva `background-image: url("/...")` inline (scrubInlineStyle permite url local/https).
- Verificado en navegador (preview): sección sin fondo → "Poner imagen de fondo"; elegir imagen → background-image inline cover/center, guardado en HTML; reselección detecta el fondo CSS → "Cambiar/Atenuar"; "Atenuar Medio" → velo rgba(255,255,255,.6) conservando url; "Quitar" → none; camino <img> del hero intacto (marca el <img>); sin errores de consola. Tests canvas_* PASS.
- Unificado: un fondo puesto por IA (css_append) también queda detectable/editable por el panel.
- PENDIENTE usuario: desplegar (commit+push+pull) y probar a mano "Poner imagen de fondo" en el hero.

## [2026-06-23] Falsos positivos de "petición de imagen" + control demasiado estricto
- Bug A (social proof): `requestsImages()` disparaba con cualquier mención de foto/imagen → "Ponle menos anchura a la caja de foto+texto" lanzaba Unsplash + control de imagen sin que el usuario pidiera tocar imágenes. Reescrito para distinguir petición real (verbo+imagen, "imagen de fondo") de mención descriptiva ("de foto/imagen") o de eliminación. 12 casos + 4 tests nuevos PASS (incluye las 2 frases del usuario).
- Bug B (hero "Unsplash rechazó la configuración de acceso"): si Unsplash falla se bloqueaba TODA edición con imagen. Ahora: si la biblioteca del sitio tiene imágenes, no se bloquea (la IA usa available_images). Nuevo helper `hasLibraryImages`.
- Bug C (control estricto): al MOVER una imagen de contenido a fondo, el total no aumenta (1→1) → el control `after>before` rechazaba un cambio válido. Cambiado a rechazar solo si `after===0` (el resultado se queda sin ninguna imagen). Reproducido hero "foto de fondo + CTA centrado": IA aplica background-image CSS usando imagen de biblioteca, control ACEPTA.
- Nota: el Unsplash de PROD está mal configurado (auth 401/403). El usuario debe revisar la access key si quiere imágenes nuevas de Unsplash; mientras, las de su biblioteca funcionan.
- PENDIENTE usuario: desplegar y reprobar; arreglar credenciales Unsplash en prod.

## [2026-06-23] PLAN — Limpieza del flujo obsoleto "plantillas-bloque"
Contexto: tras quitar el botón "Crear desde plantilla" del header de /admin/pages, el flujo de CREACIÓN por plantillas-bloque quedó sin entrada viva. PERO las plantillas siguen vivas como sustrato de las PREVIEWS de estilo visual (/admin/design). Limpieza quirúrgica, no borrado total.

### CONSERVAR (no tocar — está vivo)
- `PageTemplateService` (servicio): `all()`, `get()`, `placeholderContent()`. Usos vivos: editor de bloques (SectionController:428), variaciones de layout (PageController:2821 variationPreviewHtml), y la preview.
- `aiTemplatePreview()` + ruta `GET /pages/ai/templates/{slug}/preview`. VIVO: previews de estilo visual en /admin/design (VisualStyleService::cardsForSite → preview_url).
- `config/page_templates/*.json` (los necesita la preview).
- `filterSectionContent()` (flujo clásico de secciones para artículos/legales — vivo).
- VisualStyleService, views/admin/design/index.php.

### BORRAR (muerto; sin entrada viva)
1. Galería: `PageController::aiTemplatesGallery()` + ruta `GET /pages/ai/templates` + vista `views/admin/pages/templates.php`.
2. Crear-desde-plantilla: `PageController::aiCreateFromTemplate()` + ruta `POST /pages/ai-create-from-template`.
3. `PageController::generatePageFromTemplate()` + helper exclusivo `applyImagesToSection()` (solo lo llama generatePageFromTemplate).
4. Onboarding: rama muerta en `createAiPage()` (~1292-1311) + cómputo vestigial `suggested_templates`/`default_template` (~602-628) → luego `PageTemplateService::suggestForPage()` queda sin uso → borrar.
5. page-studio.js: `bindTemplateFlow()` + vars `templateGrid/templateForm/templateSlug/templateTitle/templateGoal/templateStatus/templateSubmit` + su invocación (inerte: los ids no existen en studio.php).
6. Preparación de datos del studio: quitar `$data['templateCards']` en `PageController::studio()` (189-199) y el `@var $templateCards` del docblock de studio.php. (visualStyleCards en studio.php tb es vestigial — confirmar y quitar el docblock; NO tocar cardsForSite, que /admin/design sí usa.)

### ORDEN (hojas→raíz, verificando tras cada paso)
1. page-studio.js: quitar template-flow muerto. Verif: studio crea OK.
2. Vista templates.php + ruta gallery + aiTemplatesGallery().
3. Ruta create-from-template + aiCreateFromTemplate() + generatePageFromTemplate() + applyImagesToSection().
4. Onboarding: rama muerta + cómputo suggested_templates; luego suggestForPage() del service.
5. studio(): quitar templateCards (+ docblocks vestigiales).
6. Verif final: `php -l` en tocados, `node --check` JS, tests canvas_*; navegador: /admin/pages (crear con IA), /admin/design (previews de estilo cargan), onboarding (crea canvas).

### RIESGOS
- NO borrar aiTemplatePreview, los JSON de plantillas, placeholderContent ni filterSectionContent (romperían /admin/design o el editor de bloques).
- Antes de borrar applyImagesToSection/suggestForPage, re-grep para confirmar 0 usos nuevos.
- PageController es grande; ir por pasos. Estimación: ~6 ediciones, ~300-400 líneas muertas retiradas.
- No hay tests que referencien los métodos muertos (verificado).

## [2026-06-23] EJECUTADO — Limpieza flujo plantillas-bloque (Executor)
Hecho por pasos con verificación. BORRADO (muerto): bindTemplateFlow + vars en page-studio.js; vista templates.php; ruta GET /pages/ai/templates + aiTemplatesGallery(); ruta POST /pages/ai-create-from-template + aiCreateFromTemplate() + generatePageFromTemplate() + imageGenerationWarning() + applyImagesToSection() + fetchAndApply(); onboarding: enrichWithTemplates() + rama plantilla muerta de createAiPage() + guard $templateSlug; PageTemplateService::suggestForPage() + imageHungrySections(); $data['templateCards'/'visualStyleCards'/'selectedVisualStyle'] en PageController::studio() + sus @var en studio.php; imports huérfanos (ImageBankService en PageController, PageTemplateService en OnboardingController).
CONSERVADO (vivo): aiTemplatePreview() + ruta /pages/ai/templates/{slug}/preview (lo usan las visual style cards de /admin/design); PageTemplateService (all/get/placeholderContent); config/page_templates/*.json; filterSectionContent; variantContentHint (caller vivo en 557); aiVariations/applyVariation.
VERIFICADO: lint PHP/JS OK; 22/22 tests OK; navegador: /admin/pages (header sin "Crear desde plantilla": [+ Nueva página con IA | Analizar sitio | Revisar enlaces | Crear manualmente]), /admin/pages/studio 200, /admin/design 200 con 18 visual style cards y sus iframes de preview funcionando (200, HTML real), galería /pages/ai/templates → 404, sin errores de consola.
PENDIENTE usuario: desplegar (commit+push+pull).
