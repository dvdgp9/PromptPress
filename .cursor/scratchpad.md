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
