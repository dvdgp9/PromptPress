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

## [2026-06-23] Selector de modelo de IA en el chat del Studio
- El chat del canvas usa TIER_MAIN (ai_model). Ahora muestra el modelo activo y permite elegir otro por petición.
- SettingsAIController::suggestedModelsFor($provider) (nuevo público) expone la lista curada.
- CanvasController: helpers chatModelIds()/humanModelLabel()/chatModelOptions(); studio() pasa 'aiModels'; chat() acepta `model` y valida contra chatModelIds() antes de AIProviderFactory::setModelOverride() (no IDs arbitrarios).
- UI: selector en el footer del composer (views/admin/canvas/studio.php) + envío de `model` en canvas-studio.js + CSS .cvstudio-chat__formfoot/.cvstudio-model. Selección pegajosa; modelo principal marcado "(actual)".
- Verificado: opciones correctas (incluye gemini-3.5-flash), selector renderiza con el actual preseleccionado, el submit envía model=elegido (interceptado), lint PHP/JS OK, tests canvas_* OK, sin errores de consola.

## [2026-06-23] Modal de imágenes del Studio: subir + buscar en Unsplash
- Antes el modal "Elige una imagen" solo listaba la biblioteca. Ahora: pestañas "Tu biblioteca" / "Buscar en Unsplash" + botón "Subir imagen".
- Reutiliza endpoints existentes: GET /media/library, POST /media (subida, _csrf + X-Requested-With), GET /media/bank/search, POST /media/bank/import (_csrf). Backend sin cambios.
- CanvasController::studio() pasa bankAvailable (ImageBankService::isAvailable) + data-attrs (upload/bank-search/bank-import). UI en views/admin/canvas/studio.php; lógica en canvas-studio.js (loadLibrary/upload/unsplash search+import → useMedia → replace-image, sirve para imagen de contenido y de fondo). mediaPath() normaliza a ruta relativa (subida/Unsplash dan url → pathname) para que el sanitizer conserve el background-image inline.
- CSS: .cvstudio-media-bar/tabs/upload/search; fix .cvstudio-media-search[hidden]{display:none} (la regla display:flex anulaba [hidden]).
- Verificado en navegador: pestañas OK; búsqueda Unsplash (classroom → 12 resultados con crédito); import end-to-end → /storage/uploads/...jpg aplicado como fondo; subida de PNG generado → /storage/uploads/...png aplicado; search oculta en biblioteca y visible en Unsplash; sin errores de consola. tests canvas_* OK.

## [2026-06-23] Añadir secciones nuevas desde el chat del Studio
- Bug: con una sección seleccionada, pedir "mete/añade una sección nueva" iba a applySectionEdit → CanvasService::replaceSection toma SOLO el primer elemento del HTML devuelto y reemplaza la sección elegida → la sección nueva se descartaba ("no creó nada") o el desajuste estructural daba AIException ("no devolvió un cambio válido").
- Fix: añadir una sección es un cambio de PÁGINA. requestsNewSection() detecta la intención (verbo insertar/añadir/crear/duplicar + "una/otra/nueva sección|franja|banda|apartado|bloque", distinguiendo de "añade un botón a esta sección"). Si true, chat() enruta a applyPageEdit usando la sección seleccionada como referencia de posición (no aplica el element-context). Prompt EDIT_CANVAS_PAGE: regla explícita para insertar `<section data-pp-section="id-unico">` en la posición pedida sin borrar otras.
- Verificado: requestsNewSection 11/11 casos; repro real con la frase del usuario → 3 secciones → 4 (hero,especialidades,parallax-cita,diferenciadores), posición correcta, resto intacto. tests canvas_* OK.

## [2026-06-23] Generación canvas más opinada: ritmo + imágenes garantizados
- Diagnóstico: COMPOSE (COMPOSE_CUSTOM_PAGE_FROM_REFERENCE) aplica fielmente temas (dark/primary/image) e imágenes del outline (verificado 6/6). El cuello era DESCRIBE: clasificaba el ritmo/fotos COPIANDO la referencia → referencias claras/sin fotos = páginas planas y sin imágenes. (No era Unsplash: ya funciona en prod.)
- Fix:
  1. Prompt DESCRIBE_REFERENCE_LAYOUT: "RITMO OBLIGATORIO" (≥1 banda fuerte aunque la referencia sea plana, sin 3+ claras seguidas) + "IMÁGENES PROACTIVAS" (image_brief para hero/método/testimonios/galería/servicios-con-media aunque la referencia no muestre fotos).
  2. ensurePlanRhythm(): red determinista — si el plan con referencia no tiene banda fuerte (dark/primary/image/tint), promueve la sección de cita/cierre/CTA (o la del medio) a dark. No toca planes ya ritmados ni páginas <3 secciones.
  3. defaultPlanForType(): para el modo SIN referencia (DESCRIBE da plan vacío), plan opinado de landing [image,-,surface,dark,image,surface,primary] con briefs de imagen del sector (businessImageSubject).
  4. Wired en createReferenceCanvasPage: plan vacío → defaultPlanForType; con referencia → ensurePlanRhythm.
- Verificado: ensurePlanRhythm (plano→mete dark en cita; ritmado→intacto; <3→no fuerza); defaultPlanForType 7 secciones con ritmo+briefs; COMPOSE honra outline; 22/22 tests OK.
- Caveat: subjects del modo sin-referencia salen del sector (español) → Unsplash puede no encontrar (sesgo inglés); el modo con referencia usa subjects en inglés del modelo (fiable). Ritmo: garantizado por código en ambos.

## [2026-06-23] Corrección: la generación empeoró (página corta, poco fiel, reseñas inventadas)
- Causa: las 2 reglas que añadí al prompt DESCRIBE ("RITMO OBLIGATORIO" + "IMÁGENES PROACTIVAS") diluían la fidelidad a la referencia, mencionaban "testimonios/prueba social" (→ el modelo inventaba reseñas) y engordaban el JSON del plan (→ truncado a 1800 tok → menos secciones → página corta).
- Fix:
  1. Revertidas ambas reglas del prompt DESCRIBE. El ritmo lo sigue garantizando el código determinista ensurePlanRhythm (sin riesgo de prompt). Mantengo defaultPlanForType (sin-referencia).
  2. Anti-invención reforzada en canvasAntiSlop (COMPOSE): prohíbe inventar porcentajes/valoraciones/volúmenes ('cientos de alumnos')/años/premios/logos/nombres/testimonios; y REDIRIGE la prueba social sin datos reales a hechos comprobables (método, garantías, modalidad, campus 24h, profesorado, normativa) + cita del propio centro.
  3. DESCRIBE max_tokens 1800→2600 (planes completos, páginas más largas/fieles).
- Verificado: repro COMPOSE con sección "testimonios" y contexto sin datos → 2/2 runs SIN cifras/volúmenes inventados (antes inventaba '92% aprobados', 'cientos de alumnos'). 22/22 tests OK.

## [2026-06-24] Executor — Ampliar edición manual de header y footer en /admin/chrome

### Background and Motivation
El usuario pide que `/admin/chrome` permita editar más elementos manuales del header y footer. El editor actual cubre menú, CTA básico, layout mínimo, bloques, navegación del pie, contacto, redes, newsletter y fondo. Hay campos ya previstos o hardcodeados que conviene exponer sin cambiar el comportamiento por defecto de sitios existentes.

### Key Challenges and Analysis
- Mantener regresión cero: si un sitio no configura estos campos, el render debe seguir usando los textos y clases actuales.
- `footer.style.columns` existe en `ChromeService::defaults()` y `sanitize()`, pero el editor no lo manda y `BrandService` no lo aplica.
- Títulos visibles como "Explora", "Legal", "Contacto", "Síguenos", "Newsletter" y el CTA "Suscribirme" están hardcodeados en `BrandService`; son buenos candidatos para edición manual.
- El enlace de marca del header siempre apunta a home; se puede exponer como override opcional.
- No se necesita documentación externa ni APIs nuevas; se trabaja sobre PHP/JS/CSS local.

### High-level Task Breakdown
1. Ampliar contrato de configuración y render público para nuevos campos manuales. Éxito: `ChromeService::sanitize()` conserva campos nuevos con defaults y `BrandService` los renderiza con fallback histórico.
2. Exponer controles en `/admin/chrome` y conectarlos en `chrome-editor.js`. Éxito: los campos se hidratan desde config, se incluyen en `config_json` y aparecen en la vista previa.
3. Añadir/ajustar CSS público/admin necesario sin inline CSS. Éxito: columnas footer y controles nuevos se ven correctamente.
4. Verificar lint y pruebas focalizadas. Éxito: `php -l`, `node --check`, prueba de servicio/render y `git diff --check` correctos.

### Project Status Board
- [x] Tarea 1: contrato y render público ampliados.
- [x] Tarea 2: controles del editor y serialización JS.
- [x] Tarea 3: estilos CSS necesarios.
- [x] Tarea 4: verificación automatizada.

### Current Status / Progress Tracking
Executor completó la implementación. Nuevos controles en `/admin/chrome`: destino del logo/marca, anchura del header, alineación del menú, ocultar CTA en móvil, target nueva pestaña para páginas del menú, columnas del footer, nombre de marca del footer, títulos manuales de columnas del footer y texto del botón newsletter. El render público conserva defaults históricos cuando los campos están vacíos.

Verificado: `php -l` en `ChromeService.php`, `BrandService.php`, `views/admin/chrome/index.php`, `DesignSystem.php` y `tests/chrome_config.php`; `node --check admin/assets/js/chrome-editor.js`; `php tests/chrome_config.php`; `git diff --check`.

Extensión 2026-06-24: añadido selector "Color de fondo" para el header con opciones Automático, Claro, Oscuro, Color de marca y Transparente. Se guarda en `header.style.background`, se renderiza con clases `pp-site-header--bg-*` y se cubre en `tests/chrome_config.php`.

### Executor's Feedback or Assistance Requests
No bloqueado. Queda pendiente prueba manual del usuario/Planner en `/admin/chrome`: guardar una configuración con los nuevos campos y revisar la vista previa desktop/móvil antes de dar el proyecto por cerrado.

### Lessons
- Antes de editar `/admin/chrome`, revisar siempre `ChromeService`, `BrandService`, `views/admin/chrome/index.php`, `admin/assets/js/chrome-editor.js` y `DesignSystem`: la UI, saneado y render público van acoplados.

## [2026-06-24] Executor — Reorganizar panel /admin/chrome + bordes manuales

### Background and Motivation
El usuario pide usar `design-taste-frontend` para repensar el panel completo de `/admin/chrome`: reorganizar, compactar y hacerlo más operativo. Además solicita control manual completo de bordes: grosor y color de cada borde individualmente o de todos juntos.

### Key Challenges and Analysis
- El panel actual es una lista larga de tarjetas iguales; obliga a mucho scroll y mezcla estructura, estilo, contenido y navegación.
- Hay que mantener CSS en `admin/assets/css/admin.css` y estilos públicos en `DesignSystem`, sin CSS inline.
- Los bordes deben ser configurables para header y footer sin romper defaults: si no hay override, el diseño actual debe seguir igual.
- Para evitar una UI pesada, el control de bordes debe tener modo "Todos" y modo "Por lado", con campos numéricos y color HTML.

### High-level Task Breakdown
1. Ampliar contrato `chrome_config` para `header.style.border` y `footer.style.border`. Éxito: sanitize valida `mode`, widths 0-24px y colores hex seguros.
2. Render público de bordes manuales. Éxito: `BrandService` emite clases/variables por elemento y `DesignSystem` aplica los bordes sin afectar defaults.
3. Reorganizar UI del editor en grupos compactos. Éxito: el panel queda dividido en bloques "Header", "Footer" y "Contenido del pie" con layout de dos columnas internas donde procede.
4. Conectar JS y tests. Éxito: preview/guardado incluyen bordes; `tests/chrome_config.php`, lint y `git diff --check` pasan.

### Project Status Board
- [x] Tarea 1: contrato y render de bordes.
- [x] Tarea 2: reorganización visual del panel.
- [x] Tarea 3: JS de serialización/hidratación.
- [x] Tarea 4: verificación automatizada.

### Current Status / Progress Tracking
Executor completó la implementación. El panel queda reorganizado en tres grupos compactos: Header, Footer y Contenido del pie. Dentro de Header/Footer se agrupan subpaneles de Menú, CTA, Layout, Apariencia, Bloques, Navegación y Marca/títulos, reduciendo scroll y mezclas conceptuales.

Bordes manuales implementados para header y footer con modo "Todos juntos" o "Por lado". Cada borde admite grosor 0-24 px y color hex; el render público solo activa el borde manual cuando hay grosor explícito para evitar que los inputs de color por defecto anulen el diseño histórico. Los estilos estáticos viven en `DesignSystem`; los valores manuales se pasan como variables CSS por elemento para poder soportar colores arbitrarios.

Verificado: `php -l` en archivos tocados, `node --check admin/assets/js/chrome-editor.js`, `php tests/chrome_config.php` y `git diff --check` correctos.

### Executor's Feedback or Assistance Requests
No bloqueado. Pendiente únicamente prueba manual autenticada en `/admin/chrome`, porque la ruta local redirige a login sin sesión activa.

## [2026-06-24] Executor — Quitar franja blanca antes del footer público

### Background and Motivation
El usuario muestra que todas las páginas tienen una franja blanca entre el final del body y el inicio del footer. La captura encaja con el margen global del footer público, no con una sección concreta.

### Key Challenges and Analysis
- La regla `.pp-site-footer{margin-top:96px;...}` en `DesignSystem::css()` añade separación superior fija a todos los footers.
- En páginas donde la última sección ya tiene su propio fondo/espaciado, ese margen se ve como una franja blanca artificial.

### High-level Task Breakdown
1. Eliminar el margen superior global del footer. Éxito: `.pp-site-footer` queda con `margin-top:0`.
2. Añadir regresión focalizada. Éxito: test confirma que el CSS no contiene `margin-top:96px` para el footer.

### Project Status Board
- [x] Tarea 1: footer sin margen superior global.
- [x] Tarea 2: verificación automatizada.

### Current Status / Progress Tracking
Implementado en `DesignSystem.php`: `.pp-site-footer{margin-top:0;...}`. Añadida cobertura en `tests/chrome_config.php`.

### Executor's Feedback or Assistance Requests
Pendiente solo prueba visual manual en una página real para confirmar que el footer queda pegado a la última sección.

## [2026-06-24] Executor — 422 en creación de página por JSON de brief roto

### Background and Motivation
El usuario reporta en el Studio de creación de páginas un error `ai-create:1 Failed to load resource: 422` mientras revisaba el "Plan de página". La UI mostraba un error crudo: "No se pudo parsear JSON de la respuesta del modelo..." seguido de un fragmento de la respuesta.

### Key Challenges and Analysis
- El fallo ocurre en `POST /admin/pages/ai-brief`, antes de crear la página completa. El modelo devolvió JSON incompleto/no parseable para `GENERATE_PAGE_BRIEF`.
- El endpoint devolvía 422 y exponía parte del output crudo del modelo, aunque la pantalla podía seguir mostrando parte del plan.
- `GENERATE_PAGE_BRIEF` tenía `max_tokens=1200`, ajustado para respuestas compactas pero vulnerable a truncado si incluye formulario y varias secciones.

### High-level Task Breakdown
1. Reintentar automáticamente una vez si `ai-brief` falla por JSON no parseable. Éxito: el usuario no ve el primer fallo transitorio.
2. Si el reintento falla, devolver un brief local base con `ok:true` y `fallback:true`, no 422. Éxito: el usuario puede continuar creando la página.
3. No exponer JSON crudo del modelo en errores de brief. Éxito: mensaje público limpio.
4. Ajustar prompt/límite de tokens y añadir test. Éxito: pruebas focalizadas pasan.

### Project Status Board
- [x] Tarea 1: retry de `ai-brief` ante JSON roto.
- [x] Tarea 2: fallback local de brief.
- [x] Tarea 3: mensaje público limpio.
- [x] Tarea 4: prompt/token budget y test.

### Current Status / Progress Tracking
Implementado en `PageController::aiBrief`: reintento compacto si el error contiene "No se pudo parsear JSON"; fallback local usable si vuelve a fallar. `Actions::GENERATE_PAGE_BRIEF` ahora exige JSON compacto y sube `max_tokens` de 1200 a 1800. Añadido `tests/page_ai_brief_fallback.php`.

Verificado: `php -l app/Controllers/Admin/PageController.php && php -l app/Services/AI/Actions.php && php -l tests/page_ai_brief_fallback.php`, `php tests/page_ai_brief_fallback.php`, `php tests/chrome_config.php` y `git diff --check` pasan.

### Executor's Feedback or Assistance Requests
Pendiente prueba manual: repetir la creación de página desde la misma idea. Si Gemini vuelve a devolver JSON roto, ahora debe aparecer un plan base en vez de un 422 bloqueante.

## [2026-06-24] Executor — Ocultar contenedor interno de formularios en /admin/pages

### Background and Motivation
El usuario detecta en `/admin/pages` una tarjeta "Formularios (sistema)" con slug `/__forms`. Pregunta por qué aparece ahí.

### Key Challenges and Analysis
- `FormStore` crea una página contenedora oculta por sitio con slug `__forms` para alojar secciones `form` reutilizables.
- Esa página técnica debe existir, pero no debe aparecer como página editable normal en el mapa de páginas, selectores ni contexto de IA.

### High-level Task Breakdown
1. Filtrar slugs internos en cargas administrativas de páginas. Éxito: `__forms` no aparece en `/admin/pages`, selector de enlaces ni datos de IA.
2. Mantener la página contenedora intacta. Éxito: no se borra ni se modifica `FormStore`.
3. Añadir regresión. Éxito: test verifica que `__forms` no entra en opciones ni árbol.

### Project Status Board
- [x] Tarea 1: filtro de páginas internas.
- [x] Tarea 2: test de regresión.

### Current Status / Progress Tracking
Implementado en `PageController.php`: añadido filtro de slugs internos (`__forms`) en el índice, JSON de páginas, seed pages, selectores de enlaces, jerarquía y contexto de oportunidades. Añadido `tests/page_internal_pages.php`.

Verificado: `php -l app/Controllers/Admin/PageController.php && php -l tests/page_internal_pages.php`, `php tests/page_internal_pages.php`, `php tests/page_ai_brief_fallback.php`, `php tests/chrome_config.php` y `git diff --check` pasan.

### Executor's Feedback or Assistance Requests
Pendiente solo prueba manual autenticada en `/admin/pages`: la tarjeta "Formularios (sistema)" ya no debería aparecer.

## [2026-06-24] Rediseño UX del editor /admin/chrome (Header y pie)

### Background and Motivation
El usuario pide mejorar la página `/admin/chrome` (editor de header y pie) aplicando principios canónicos de diseño y buena UX. Tras análisis se aprueba el alcance **Nivel 1 + Nivel 2**. Nota: el modelo de datos JSON (`ChromeService`) y el render público (`BrandService`) NO cambian; solo se rediseña la UI del editor → regresión cero en páginas públicas.

### Key Challenges and Analysis
- `chrome-editor.js` lee/escribe la config por IDs de DOM en `buildConfig()`. Toda reestructura de HTML debe **conservar esos IDs** (o actualizar el JS en paralelo). Los paneles ocultos (`display:none`) siguen aportando valores (`getElementById` funciona en oculto).
- Problemas: (1) todo visible a la vez sin tabs (Hick/Miller); (2) pie partido en 2 tarjetas sin relación (Gestalt proximidad); (3) modo "auto" invisible; (4) bordes pesan demasiado (falta divulgación progresiva); (5) guardado con recarga completa sin feedback ni aviso de cambios sin guardar (ya existe `.pp-toast`).
- Se reutiliza `pp-tabs`/`pp-tab`/`pp-tab-panel` (página Diseño). Acento naranja `--pp-primary` en todo. Stack vanilla (sin React/Tailwind/Motion).

### High-level Task Breakdown
1. **Tabs Header / Pie.** Panel Header = tarjeta Header actual; panel Pie = Footer + Contenido del pie. JS de cambio de tab. Éxito: tabs alternan paneles; preview y guardado funcionan; `php tests/chrome_config.php` pasa.
2. **Editor de bloques unificado del pie.** Cada bloque = fila con switch + reordenar + contenido inline. Conserva contrato de IDs. Éxito: activar bloque revela contenido; JSON guardado idéntico para misma config; test pasa.
3. **Pulido Nivel 2.** Chips "auto: …" en listas/selects automáticos; bordes plegados tras disclosure. Éxito: listas vacías muestran hint; bordes ocultos por defecto.
4. **Guardado AJAX + toast + aviso cambios sin guardar.** Quitar "Actualizar vista previa" redundante.

### Project Status Board
- [x] Tarea 1: tabs Header/Pie.
- [x] Tarea 2: editor de bloques unificado del pie.
- [ ] Tarea 3: pulido (chips auto + bordes plegados).
- [ ] Tarea 4: guardado AJAX + toast + aviso cambios sin guardar.

### Current Status / Progress Tracking
Tarea 1 (verificada): tabs Header/Pie en `index.php`, handler en `chrome-editor.js`, `.pp-chrome-tabs` en `admin.css`.

Tarea 2 (verificada): editor de bloques unificado del pie.
- `index.php`: se eliminan las tarjetas "Footer" + "Contenido del pie"; el pie pasa a UNA tarjeta "Bloques del pie" con lista `#footer-blocks`. Cada bloque (`.pp-fblock`) = interruptor (`.pp-switch`/`.pp-fblock-on`) + nombre + chip `auto:` (nav/legal) + reordenar (↑/↓) + cuerpo desplegable con los inputs del bloque (ids intactos). Apariencia (`f_bg`/`f_columns`/bordes) baja a sección propia. Se elimina el checkbox redundante `n_enabled` (newsletter ahora se activa con el interruptor del bloque).
- `chrome-editor.js`: `footerBlocks` reemplaza a `blocksList`; `buildConfig` lee `blocks` desde `.pp-fblock` (orden DOM, on=interruptor) y deriva `newsletter.enabled = blocks incluye 'newsletter'`. Handlers de plegar/desplegar, reordenar y dim/auto-expand al togglear. Código muerto eliminado (`blockRow`, `BLOCK_LABELS`, `ALL_BLOCKS`).
- `admin.css`: estilos `.pp-fblock*`, `.pp-switch*` (naranja de marca), `.pp-fappearance`, `.pp-chrome-panel__body`.
Comprobado: `php -l`, `node --check`, `php tests/chrome_config.php` (OK), `git diff --check` limpio, sin refs muertas. En navegador (1440px): plegar/desplegar OK, interruptor on→un-dim+auto-expand, reorden ↑/↓ reordena y refleja en preview, preview renderiza columna newsletter al activarla, 0 errores de consola. Captura del JSON de `buildConfig()`: shape idéntico al modelo `ChromeService` (mismas claves header/footer, `labels`, `newsletter.enabled` derivado, `blocks` en orden DOM solo activos) → guardado byte-compatible sin tocar la BD dev.

Tarea 2.5 (verificada) — Coherencia del tab Header (a petición del usuario: el Header seguía con la rejilla 2×2 vieja, incoherente con el Pie):
- `index.php`: el Header pasa de `pp-chrome-panel__grid` (4 subpaneles con bordes) a `pp-chrome-panel__body` con secciones apiladas (`.pp-chrome-section`): Menú · Botón · Disposición · Apariencia, divisores suaves. Los dos booleanos (`h_sticky`, `h_transparent`) pasan a interruptores `.pp-switch-row` (naranja, como el Pie). Label `Menú`→`Alineación del menú` y `Logo`→`Posición del logo` (information scent). El footer reusa la misma clase `.pp-chrome-section` (antes `.pp-fappearance`).
- `chrome-editor.js`: disclosure del CTA — los campos `[data-cta-custom]` (Texto/Destino) solo aparecen con Modo=Personalizado (`syncCtaCustom`).
- `admin.css`: `.pp-chrome-section*`, `.pp-switch-row`; eliminado `.pp-fappearance`.
Comprobado: `php -l`, `node --check`, `php tests/chrome_config.php` OK. En navegador: 4 secciones, interruptores togglean y refrescan preview, ids intactos, CTA custom oculto salvo en Personalizado, 0 errores de consola. Empty-state de menú/footernav ya existía vía CSS `:empty::before`.

### Executor's Feedback or Assistance Requests
Tareas 1, 2 y 2.5 listas para verificación manual (recarga `/admin/chrome`, revisa Header y Pie, guarda y comprueba el sitio público). Pendiente confirmar antes de Tarea 3, que queda reducida a: plegar los controles de bordes tras un disclosure "avanzado" (los chips `auto:` ya están en las cabeceras de bloque nav/legal; empty-states de menú/footernav ya existen).
