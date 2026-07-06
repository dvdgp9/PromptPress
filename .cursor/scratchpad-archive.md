# Archivo de tareas cerradas

Histórico completo movido aquí el 2026-07-02 para sanear `.cursor/scratchpad.md`. Todas las tareas de este archivo están **completadas y verificadas**; se conservan solo como referencia histórica. El scratchpad activo vive en `.cursor/scratchpad.md`.

---

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
- [x] Tarea 3: resolución y validación de imágenes en Studio. Implementada y verificada.
- [x] Tarea 4: regresión final. Automatizada y confirmada.

# Lessons

- Unsplash puede sufrir incidencias parciales: una petición `200` aislada no descarta errores intermitentes.
- No representar errores del proveedor como una lista vacía sin conservar el motivo.
- En comandos `php -r` bajo shell, usar nombres PHP con una sola barra invertida dentro de comillas simples; duplicarlas produce un error de parseo.
- Las expresiones regulares en español deben cubrir explícitamente `imágenes` con tilde; `imagen(?:es)?` no lo hace.

---

# [2026-06-22] Studio: chat IA rechaza cambios + panel tapa el chat

## Background and Motivation
Dos problemas en el Studio (editor canvas en vivo):
1. El panel contextual de edición (commit 599cace añadió controles "Bloque" con 4 inputs de radio por esquina) crece tanto que aplasta el chat: "el chat casi no se ve".
2. Al pedir por chat un cambio de estilo (ej. border-top-left-radius 46px en la tarjeta "100% adaptado a LOMLOE") falla con "La IA no devolvió un cambio válido. Tu página no ha cambiado."

## Key Challenges and Analysis
- La acción `edit_canvas_section` obliga al modelo a devolver la SECCIÓN HTML COMPLETA reescrita, y `AIActionRunner::validateCanvasEdit` EXIGE `html` no vacío (lanza AIException si falta). El controlador mapea cualquier AIException sin status 401/403/429/5xx al mensaje genérico "no devolvió un cambio válido".
- Reproducción local: en una sección Hero grande con SVG (7.2 KB), el modelo devolvió `html` de solo 2.9 KB → reescribió y RECORTÓ la ilustración SVG, además de `css_append` correcto. En secciones muy grandes esto provoca o truncado (JSON inválido → AIException) o destrozo del SVG. Para un cambio SOLO de estilo, el modelo no necesita devolver HTML; basta `css_append`. Pero el validador lo rechaza si `html` viene vacío.
- Causa raíz problema 2: forzar reescritura completa del HTML para cambios de estilo. Solución: permitir ediciones SOLO-CSS (html vacío = sección sin cambios), instruir al modelo a no reescribir el HTML en cambios de estilo, y subir max_tokens como defensa.
- Problema 1: `.cvstudio-panel{max-height:62%}` + chat `flex:1`. Bajar el tope del panel y garantizar altura mínima del chat.

## Project Status Board — Executor
- [x] T1 validateCanvasEdit acepta solo-CSS (`AIActionRunner.php`). Test reflexión OK.
- [x] T2 applySectionEdit/applyPageEdit: html vacío conserva original (`CanvasController.php`). Verificado: SVG intacto.
- [x] T3 prompts edit_canvas_section/page "PREFIERE SOLO CSS" + max_tokens sección 9000→16000 (`Actions.php`).
- [x] T4 CSS: `.cvstudio-panel` max-height 62%→50%; `.cvstudio-chat__messages` min-height:96px (`admin.css`).
- [x] T5 Regresión: API real → IA devuelve html:"" + css_append con border-top-left-radius:46px; cambio de texto sigue devolviendo HTML; tests canvas_runtime/box_editor/settings PASS; preview verificado (panel 50% + chat legible), sin errores de consola.

## Lessons
- El mensaje "La IA no devolvió un cambio válido" es genérico: cualquier AIException sin status 401/403/429/5xx cae ahí (CanvasController match por httpStatus, no por mensaje). Engaña al diagnosticar.
- Forzar al modelo a reescribir la SECCIÓN/PÁGINA HTML completa en cambios de estilo es la causa raíz: trunca en secciones grandes y recorta/destroza SVG. Permitir html:"" + css_append es más robusto, rápido y seguro.

## [2026-06-22] Seguimiento: petición de imágenes falla con "no devolvió un cambio válido"
- Arreglado bug propio: la directiva "prefiere solo CSS" podía hacer que una imagen se pusiera como background-image en CSS con html:"" → la verificación `imageCount` solo mira el HTML de la sección → rechazo. Ahora, si `$requiresImages`, se añade directiva que OBLIGA a devolver HTML con <img>; `applyPageEdit` también recibe `$effectiveInstruction`. Verificado: 4/4 runs devuelven <img> en HTML. tests canvas_image_requests/runtime/box_editor PASS.

## [2026-06-22] Confirmado: truncado por reescribir SVG enorme en peticiones de imagen
- Causa: la directiva previa forzaba HTML para TODA imagen → el modelo reescribía el SVG entero → en Hero real trunca → AIException.
- Fix definitivo: directiva por tipo (fondo→CSS sin tocar SVG; contenido→<img> en HTML); `applyPageEdit` recibe `$effectiveInstruction`; verificación de imágenes cuenta HTML y CSS (background-image).
- Verificado: repro OK, tests canvas_image_requests/runtime/box_editor PASS.

## [2026-06-22] Fondos por CSS gestionables a mano (issues #1 y #2)
- Causa #2: el panel solo detectaba fondo vía <img> de cobertura; los fondos por CSS no se detectaban.
- Fix: `cssBgUrlOf`, panel siempre ofrece control de fondo, operaciones bgimg/bgdim/bgcolor sobre data-pp-bg-edit, sanitizer conserva background-image inline local/https.
- Verificado en navegador; tests canvas_* PASS.

## [2026-06-23] Falsos positivos de "petición de imagen" + control demasiado estricto
- Bug A: `requestsImages()` disparaba con cualquier mención de foto/imagen → reescrito para distinguir petición real de mención descriptiva.
- Bug B: fallo de Unsplash bloqueaba TODA edición con imagen → si hay biblioteca local, no se bloquea.
- Bug C: mover imagen de contenido a fondo (1→1) se rechazaba → ahora solo rechaza si after===0.
- Nota: Unsplash de PROD estaba mal configurado (auth 401/403) en esa fecha.

## [2026-06-23] Limpieza del flujo obsoleto "plantillas-bloque" (planificado y ejecutado)
Tras quitar el botón "Crear desde plantilla" del header de /admin/pages, se retiró el flujo de creación por plantillas-bloque (galería, aiCreateFromTemplate, generatePageFromTemplate, applyImagesToSection, rama muerta de onboarding, bindTemplateFlow en JS, templateCards en studio()). Se conservó lo vivo: aiTemplatePreview (usado por /admin/design), PageTemplateService (all/get/placeholderContent), config/page_templates/*.json, filterSectionContent.
Verificado: lint OK, 22/22 tests OK, navegador OK (galería → 404 esperado, /admin/design con 18 cards y previews funcionando).

## [2026-06-23] Selector de modelo de IA en el chat del Studio
Chat del canvas ahora muestra el modelo activo (TIER_MAIN) y permite elegir otro por petición, validado contra lista curada (`SettingsAIController::suggestedModelsFor`). Verificado en navegador y tests.

## [2026-06-23] Modal de imágenes del Studio: subir + buscar en Unsplash
Modal "Elige una imagen" ampliado con pestañas "Tu biblioteca" / "Buscar en Unsplash" + botón "Subir imagen", reutilizando endpoints existentes. Verificado end-to-end en navegador.

## [2026-06-23] Añadir secciones nuevas desde el chat del Studio
Bug: pedir una sección nueva con una sección seleccionada iba por applySectionEdit (solo reemplaza 1 sección) → se descartaba. Fix: `requestsNewSection()` detecta la intención y enruta a applyPageEdit usando la sección seleccionada como referencia de posición. Verificado: 11/11 casos + repro real OK.

## [2026-06-23] Generación canvas más opinada: ritmo + imágenes garantizados
Diagnóstico: el cuello estaba en DESCRIBE (clasificaba ritmo/fotos copiando la referencia). Fix con red determinista `ensurePlanRhythm()` + `defaultPlanForType()` para el modo sin referencia. Verificado con 22/22 tests.

## [2026-06-23] Corrección: la generación empeoró (página corta, poco fiel, reseñas inventadas)
Causa: las reglas de prompt añadidas para "ritmo/imágenes proactivas" diluían la fidelidad y provocaban invención de reseñas + truncado por JSON más grande. Revertidas esas reglas de prompt (el ritmo lo sigue garantizando el código determinista); reforzada anti-invención en canvasAntiSlop; max_tokens de DESCRIBE 1800→2600. Verificado 2/2 runs sin datos inventados, 22/22 tests OK.

## [2026-06-24] Ampliar edición manual de header y footer en /admin/chrome
Nuevos controles: destino del logo, anchura del header, alineación del menú, ocultar CTA en móvil, target de menú, columnas del footer, nombre de marca del footer, títulos manuales de columnas, texto del botón newsletter, y selector de color de fondo del header (Automático/Claro/Oscuro/Marca/Transparente). Render público conserva defaults históricos si los campos están vacíos. Verificado con `tests/chrome_config.php`, lint y `git diff --check`.

## [2026-06-24] Reorganizar panel /admin/chrome + bordes manuales
Bordes configurables por header/footer (modo "Todos" o "Por lado", grosor 0-24px + color hex), activos solo si hay grosor explícito. Panel reorganizado en tres grupos (Header, Footer, Contenido del pie). Verificado con tests y lint.

## [2026-06-24] Quitar franja blanca antes del footer público
`.pp-site-footer{margin-top:96px}` causaba una franja blanca visible en páginas cuya última sección ya tenía su propio fondo. Cambiado a `margin-top:0`. Cobertura añadida en `tests/chrome_config.php`.

## [2026-06-24] 422 en creación de página por JSON de brief roto
`POST /admin/pages/ai-brief` devolvía 422 con JSON crudo del modelo cuando `GENERATE_PAGE_BRIEF` truncaba. Fix: retry automático + fallback local `ok:true, fallback:true` si el retry también falla; sin exponer JSON crudo; max_tokens 1200→1800. Añadido `tests/page_ai_brief_fallback.php`.

## [2026-06-24] Ocultar contenedor interno de formularios en /admin/pages
`FormStore` crea una página oculta `__forms` por sitio para alojar secciones de formulario reutilizables; aparecía como tarjeta editable normal. Fix: filtro de slugs internos en índice, JSON, seed pages, selectores de enlaces, jerarquía y contexto IA. Añadido `tests/page_internal_pages.php`.

## [2026-06-24] Rediseño UX del editor /admin/chrome (Header y pie)
Rediseño completo del editor (tabs Header/Pie, editor de bloques unificado del pie con switch+reordenar+contenido inline, coherencia visual Header, bordes plegados tras disclosure "avanzado", guardado AJAX con toast y aviso de cambios sin guardar). Modelo de datos (`ChromeService`) y render público (`BrandService`) sin cambios → regresión cero en páginas públicas. Todas las tareas (1, 2, 2.5, 3, 4) verificadas en navegador con 0 errores de consola.

## Lessons (Studio/chrome, general)
- Antes de editar `/admin/chrome`, revisar siempre `ChromeService`, `BrandService`, `views/admin/chrome/index.php`, `admin/assets/js/chrome-editor.js` y `DesignSystem`: la UI, saneado y render público van acoplados.
