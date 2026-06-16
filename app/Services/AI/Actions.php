<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Definición declarativa de las acciones de IA soportadas.
 *
 * Cada acción describe:
 *   - label: texto humano para la UI
 *   - output: 'json' | 'text' — cómo debe responder el modelo
 *   - instruction: prompt de rol/instrucción que se mete en el system message
 *   - user_template: plantilla del mensaje del usuario con `{placeholders}`
 *   - required: campos obligatorios del input
 *   - options: overrides para AIProviderInterface::chat() (temperature, max_tokens...)
 *
 * La expansión de placeholders se hace en `PromptBuilder`. Los placeholders
 * desconocidos se reemplazan por cadena vacía (no rompe el prompt).
 *
 * T6.3 consumirá estas acciones para ejecutar las operaciones reales.
 */
final class Actions
{
    public const GENERATE_SECTION        = 'generate_section';
    public const REWRITE_TEXT            = 'rewrite_text';
    public const IMPROVE_SEO             = 'improve_seo';
    public const GENERATE_PAGE_STRUCTURE = 'generate_page_structure';
    public const DISCOVER_PAGE_OPPORTUNITIES = 'discover_page_opportunities';
    public const GENERATE_PAGE_BRIEF = 'generate_page_brief';
    public const ANALYZE_SITE_ARCHITECTURE = 'analyze_site_architecture';
    public const EXTRACT_BUSINESS_PROFILE = 'extract_business_profile';
    public const PROPOSE_LAYOUT_VARIATIONS = 'propose_layout_variations';
    public const GENERATE_ARTICLE          = 'generate_article';
    public const GENERATE_ARTICLE_FROM_DOCUMENT = 'generate_article_from_document';
    public const SUGGEST_RELATED_ARTICLES   = 'suggest_related_articles';
    public const GENERATE_LEGAL_PAGE        = 'generate_legal_page';
    public const INFER_BRAND_PERSONALITY    = 'infer_brand_personality';
    public const RECREATE_FROM_REFERENCE    = 'recreate_from_reference';
    public const GENERATE_CUSTOM_BLOCK_FROM_REFERENCE = 'generate_custom_block_from_reference';
    public const COMPOSE_CUSTOM_PAGE_FROM_REFERENCE = 'compose_custom_page_from_reference';
    public const COMPOSE_CANVAS_PAGE = 'compose_canvas_page';
    public const EDIT_CANVAS_SECTION = 'edit_canvas_section';
    public const EDIT_CANVAS_PAGE = 'edit_canvas_page';
    public const DESCRIBE_REFERENCE_LAYOUT  = 'describe_reference_layout';

    /**
     * FH2 — Principios POSITIVOS de diseño (la otra cara del anti-slop):
     * lo que hace que una web parezca de un buen estudio, no solo "correcta".
     * Destilado del skill design-taste-frontend (Design Read, dials de
     * densidad/variedad, vocabulario de patrones, craft tipográfico).
     * Solo COMPOSE_CANVAS_PAGE (crear desde cero); las ediciones conservan
     * la dirección ya establecida.
     */
    public static function canvasDesignCraft(): string
    {
        return
            "DIRECCIÓN DE ARTE — DECIDE ANTES DE MAQUETAR (esto es lo que separa una web de estudio de una genérica):\n"
          . "1. LENGUAJE: antes de escribir nada, elige UNA dirección estética coherente con el SECTOR y el tono del negocio (p. ej.: editorial sobrio · tech contundente · cálido cercano · lujo sereno · técnico preciso · audaz juvenil). Esa decisión gobierna TODA la página: tipografía, aire, color, ritmo. No mezcles direcciones.\n"
          . "2. DENSIDAD: elige un nivel de aire y mantenlo en toda la página — `aireado` (mucho espacio, secciones grandes, lujo/editorial), `equilibrado` (estándar), o `compacto` (denso, técnico/producto). La densidad la marca el sector, no el capricho por sección.\n"
          . "3. UN MOMENTO MEMORABLE: una página de estudio tiene UNA sección con carácter (un titular enorme a sangre, una cita a toda pantalla, un bento con un tile rotundo, una imagen full-bleed con texto encima), no 7 secciones uniformes. Decide cuál es tu sección protagonista y dale fuerza; las demás la apoyan.\n\n"
          . "PALETA DE PATRONES (elige con intención según el rol de cada sección, busca VARIEDAD):\n"
          . "- Heroes: split asimétrico (texto + visual), editorial/manifiesto (titular gigante, sin foto), cover full-bleed (foto a sangre + overlay + texto).\n"
          . "- Contenido: bento asimétrico (celdas de distinto tamaño), split texto+imagen alternado, lista vertical con numeración grande, columnas con jerarquía 2fr/1fr, tarjetas con una destacada.\n"
          . "- Ritmo/respiro: banda full-width tintada o oscura con una sola frase potente, cita grande a pantalla, marquee CSS de palabras clave (animación pura CSS, sin JS), tira de logos/sellos.\n"
          . "- Cierre: banda de marca con titular fuerte + CTA, o split con el formulario.\n\n"
          . "CRAFT TIPOGRÁFICO (usa de verdad var(--pp-font-heading) y var(--pp-font-body)):\n"
          . "- Jerarquía por PESO y color y medida, no solo por tamaño. Titulares display con interlineado compacto (line-height ~1.0-1.1) y tracking ligeramente cerrado; cuerpo cómodo (line-height ~1.6, medida máx ~65 caracteres con max-width en ch).\n"
          . "- UN momento tipográfico fuerte por página (un titular realmente grande donde toca). Para enfatizar una palabra dentro de un titular, usa cursiva o más peso de LA MISMA fuente, nunca otra fuente.\n"
          . "- Escala fluida con clamp() para que respire en móvil y luzca en desktop.\n\n";
    }

    /**
     * FH2/FH3 — Principios de "anti-slop" de diseño (destilados del skill
     * design-taste-frontend, adaptados a PromptPress: español, tokens de
     * marca, sin JS). Lo que delata una web hecha por IA, y su antídoto.
     * Compartido por COMPOSE_CANVAS_PAGE y las ediciones canvas.
     */
    public static function canvasAntiSlop(): string
    {
        return
            "DIRECCIÓN DE ARTE — ANTI-CLICHÉ DE IA (lo que delata una web hecha por IA; cúmplelo a rajatabla):\n"
          . "LAYOUT:\n"
          . "- Hero: por defecto NO centrado — usa split asimétrico (texto a un lado, visual al otro) o alineado a la izquierda con aire a la derecha. Centrado solo si la referencia es claramente manifiesto/editorial. Máximo 4 elementos de texto en el hero (eyebrow opcional, titular ≤2 líneas, apoyo ≤20 palabras, CTAs); nada de microtextos de confianza dentro del hero (van en su sección debajo).\n"
          . "- VARIEDAD DE FAMILIAS: cada sección usa una familia de layout DISTINTA (split, grid de tarjetas, banda full-width, lista vertical, mosaico, cita grande…). Una página de 7 secciones necesita ≥4 familias diferentes; ninguna familia dos veces seguidas. Máx 2 splits imagen/texto consecutivos.\n"
          . "- PROHIBIDO '3 tarjetas iguales en fila' como recurso por defecto: usa grid asimétrico (2fr/1fr), mosaico con celdas de distinto tamaño, o lista con jerarquía. Mosaico: tantas celdas como contenido real (sin huecos vacíos) y 2-3 celdas con variación visual real (foto o banda tintada), no todo texto sobre blanco.\n"
          . "- EYEBROWS (la etiquetita en mayúsculas sobre el titular): MÁXIMO 1 por cada 3 secciones en TODA la página. No pongas una sobre cada titular; el titular solo suele bastar.\n"
          . "- PROHIBIDO: numerar secciones de forma decorativa ('01 /', '001 ·'), indicadores de scroll ('descubre más ↓'), puntos de color decorativos, texto rotado vertical, pills/etiquetas superpuestas sobre fotos, créditos de foto decorativos, tiras de texto tipo 'MARCA · MOVIMIENTO · DISEÑO'.\n"
          . "TEXTO (registro humano, no de IA):\n"
          . "- PROHIBIDOS los verbos hueco de marketing IA: 'impulsa', 'potencia', 'eleva', 'revoluciona', 'transforma tu negocio', 'lleva X al siguiente nivel', 'desbloquea', 'sin complicaciones', 'soluciones a medida'. Escribe como el dueño del negocio: concreto, específico, con el vocabulario del oficio.\n"
          . "- PROHIBIDA la raya larga (—) en cualquier texto visible: usa punto, coma o dos puntos.\n"
          . "- Números/cifras solo si vienen del contexto del negocio; nunca inventes '+500 clientes', '99%', '4,9/5', 'más de 10 años'.\n"
          . "- Testimonios: máximo 3 líneas, atribución con nombre + rol (nunca solo un nombre), comillas tipográficas (« » o \" \").\n"
          . "- Un único registro de voz en toda la página (no mezcles técnico, poético y comercial).\n"
          . "VISUAL:\n"
          . "- Jerarquía por peso y color, no solo por tamaño gigante. Sombras siempre con --pp-shadow-* (jamás negro puro). Un único criterio de radios en toda la página (vía --pp-radius-*). Un único color de acento (el de la marca) en toda la página.\n"
          . "- Contraste AA en todos los CTAs y textos sobre foto (usa overlay si hace falta); ningún texto de botón a dos líneas; no dupliques CTAs con la misma intención y etiquetas distintas (un solo texto para 'contactar' en toda la página).\n";
    }

    /**
     * D-MB2 — Guardrails PromptPress-friendly HTML compartidos por las
     * acciones que escriben bloques (por bloque y página completa).
     */
    private static function ppbGuardrails(): string
    {
        return
            "REGLAS CRÍTICAS:\n"
          . "- La referencia aporta estructura, ritmo, jerarquía, densidad y criterio de composición. NO copies colores, tipografías, textos, marcas, claims ni nombres de la referencia.\n"
          . "- El contenido debe ser nuevo y útil para el negocio del usuario, usando el contexto de empresa y documentos disponibles.\n"
          . "- Devuelve fragmentos, no incluyas `section`, `html`, `head` ni `body`.\n"
          . "- Usa SOLO estas etiquetas: div, header, footer, article, figure, figcaption, h1, h2, h3, h4, p, span, strong, em, br, ul, ol, li, a, img, blockquote, cite.\n"
          . "- Usa SOLO clases `ppb-*` permitidas y botones `pp-btn`, `pp-btn--primary`, `pp-btn--ghost`, `pp-btn--lg`.\n"
          . "- Clases PP-friendly permitidas: ppb-container, ppb-section, ppb-header, ppb-body, ppb-footer, ppb-stack, ppb-stack--tight, ppb-stack--loose, ppb-cluster, ppb-split, ppb-split--media-left, ppb-split--media-right, ppb-split--text-heavy, ppb-split--media-heavy, ppb-grid, ppb-grid--2, ppb-grid--3, ppb-grid--4, ppb-mosaic, ppb-align-start, ppb-align-center, ppb-align-end, ppb-text-center, ppb-measure-sm, ppb-measure-md, ppb-measure-lg, ppb-eyebrow, ppb-heading-xl, ppb-heading-lg, ppb-heading-md, ppb-lead, ppb-copy, ppb-small, ppb-kicker, ppb-card, ppb-card--flat, ppb-card--raised, ppb-card--accent, ppb-panel, ppb-panel--inverted, ppb-strip, ppb-media, ppb-media--frame, ppb-media--bleed, ppb-media--portrait, ppb-media--landscape, ppb-media--square, ppb-caption, ppb-actions, ppb-actions--center, ppb-actions--stack-mobile, ppb-list, ppb-list--check, ppb-list--numbered, ppb-item, ppb-item__icon, ppb-item__title, ppb-item__text, ppb-badge, ppb-badge--accent, ppb-stat, ppb-stat__value, ppb-stat__label, ppb-quote, ppb-quote__text, ppb-quote__cite, ppb-gap-sm, ppb-gap-md, ppb-gap-lg, ppb-pad-sm, ppb-pad-md, ppb-pad-lg, ppb-cover, ppb-cover__bg, ppb-cover__content.\n\n"
          . "DIRECCIÓN DE ARTE DE LA SECCIÓN (data-ppb-theme / data-ppb-pad, SOLO en el elemento raíz):\n"
          . "- El elemento raíz del bloque puede declarar `data-ppb-theme` con uno de: `surface` (fondo gris muy suave), `tint` (banda tintada con el color de marca al ~7%), `primary` (banda intensa del color de marca, texto en on-color), `dark` (banda oscura elegante), `image` (foto a sangre con overlay oscuro; SOLO si hay imagen disponible). Sin el atributo, el fondo es el de la página.\n"
          . "- También puede declarar `data-ppb-pad`: `sm`, `md`, `lg` o `xl` (respiración vertical de la sección). Úsalo con intención: un hero respira `lg`/`xl`; una franja de datos puede ser `sm`.\n"
          . "- Si el contexto te indica un TEMA DE FONDO para una sección, respétalo: es el ritmo de la página completa.\n"
          . "- Dentro de un theme `dark`, `primary` o `image` NO uses `ppb-panel--inverted` (la sección ya es oscura). Nunca pongas `ppb-panel--inverted` dentro de una `ppb-card`.\n"
          . "- Patrón cover (para theme `image`): raíz `<div data-ppb-theme=\"image\"><div class=\"ppb-cover\"><figure class=\"ppb-cover__bg\"><img ... data-pp-field=\"cover.image\" data-pp-type=\"image\"></figure><div class=\"ppb-cover__content\"><div class=\"ppb-container\">...texto y CTAs...</div></div></div></div>`. El texto sobre la foto ya queda legible por el overlay automático.\n"
          . "- `ppb-cover` SOLO como hijo directo del elemento raíz (es un patrón de sección entera). NUNCA dentro de tarjetas, grids o columnas: para poner imagen en una tarjeta usa `<figure class=\"ppb-media ppb-media--landscape\"><img ...></figure>` y el texto FUERA de la figure, debajo.\n"
          . "- Cada imagen disponible puede usarse UNA sola vez. NUNCA repitas la misma imagen. Si hay menos imágenes que tarjetas/items, las tarjetas restantes van sin imagen (texto, o `ppb-item__icon`).\n"
          . "- En los demás themes, recuerda envolver el contenido en `ppb-container` (el theme pinta el fondo a TODO el ancho; el contenido va contenido).\n"
          . "- Prohibido: style, scripts, iframes, formularios, SVG inline, tablas, clases clásicas `pp-hero__*`, `pp-benefits__*`, `container`, URLs `javascript:` o `#`.\n"
          . "- Todo contenido visible importante debe llevar SIEMPRE LOS DOS atributos juntos: `data-pp-field` Y `data-pp-type`. Nunca pongas uno sin el otro. Tipos válidos: text, richtext, image, link, cta, list, group.\n"
          . "- Los nombres `data-pp-field` deben cumplir EXACTAMENTE este patrón: `^[a-z][a-z0-9]*(\\.[a-z0-9]+)*$`. Usa puntos para jerarquía: `header.heading`, `header.lead`, `cta.primary`, `items.0.title`. PROHIBIDO usar guiones bajos: no escribas `cta_primary` ni `header_heading`.\n"
          . "- Usa `data-pp-repeat` en contenedores de colecciones. Su valor debe ser UNA sola palabra en minúsculas (sin puntos), p. ej. `items`, `cards`, `steps`, `services`, `features`. Los hijos se indexan: `items.0.title`, `items.1.title`.\n"
          . "- PROHIBIDO usar emojis o pictogramas Unicode en CUALQUIER texto (✓ ⚡ 🚀 ★ ✦ etc.): el sistema los elimina y el bloque queda peor. Cero emojis, siempre.\n"
          . "- ICONOS: usa EXCLUSIVAMENTE la librería del sistema mediante un span VACÍO: `<span class=\"ppb-item__icon\" data-ppb-icon=\"nombre\"></span>` (el SVG lo inyecta el renderer; NUNCA escribas SVG ni pongas texto/emoji dentro del span). Nombres disponibles (kebab-case, no inventes otros): " . implode(', ', \App\Services\Renderer\Icons::names()) . ". Si ninguno encaja, omite el icono.\n"
          . "- NUNCA simules widgets interactivos (calendarios, formularios, buscadores, mapas, reproductores): este formato no los soporta. Si la referencia tiene uno, sustitúyelo por contenido estático equivalente + un CTA hacia la acción real (p. ej. el calendario de reservas de la referencia → texto que invita a reservar + botón a `/contacto`).\n"
          . "- PROHIBIDO el texto placeholder: nada de `[Módulo de...]`, `[Imagen de...]`, `Lorem ipsum` ni corchetes descriptivos. Todo texto visible debe ser contenido real y útil.\n"
          . "- Para CTAs usa rutas relativas reales si encajan, por ejemplo `/contacto` o `/servicios`. Si no hay destino claro, omite el CTA.\n"
          . "- NO inventes datos de contacto (emails, teléfonos, direcciones, horarios): si no vienen en el contexto, no existen. NO generes secciones tipo footer ni de contacto con datos: el sitio ya tiene footer global.\n\n"
          . "DENSIDAD Y CONSISTENCIA (los fallos más frecuentes — evítalos):\n"
          . "- UNIFORMIDAD en grids/mosaicos: TODAS las tarjetas de un mismo grid siguen EXACTAMENTE el mismo patrón. O todas con imagen, o ninguna. O todas con icono, o ninguna. Todas con título + texto. Si hay menos imágenes disponibles que tarjetas → NINGUNA tarjeta lleva imagen (usa iconos en todas).\n"
          . "- Cada tarjeta/item lleva SIEMPRE título + 1-2 frases de descripción. Una tarjeta con solo un título (o solo un icono) es un bloque ROTO.\n"
          . "- Cada sección debe sentirse COMPLETA: eyebrow/kicker cuando aporte + titular + texto de apoyo + contenido (grid/lista/split) + CTA si la sección vende. Una sección con un titular y una frase es insuficiente.\n"
          . "- `ppb-mosaic` solo si tienes contenido desigual REAL (1 destacado + secundarios todos completos). Si dudas, usa `ppb-grid--3`.\n"
          . "- El nº de items debe CUADRAR con las columnas: `ppb-grid--2` → 2/4/6 items, `ppb-grid--3` → 3/6, `ppb-grid--4` → 4/8. Nunca dejes una tarjeta huérfana en la última fila: añade o quita una, o cambia de grid.\n\n"
          . "CTAs (una web sin conversión es decoración):\n"
          . "- El hero lleva SIEMPRE 1-2 CTAs visibles: primario `pp-btn pp-btn--primary pp-btn--lg` + opcional secundario `pp-btn pp-btn--ghost pp-btn--lg`, dentro de `ppb-actions`.\n"
          . "- La última sección de la página cierra SIEMPRE con un CTA primario claro.\n"
          . "- Etiquetas de CTA accionables y específicas (\"Pide tu presupuesto\", \"Reserva una sesión\"), no genéricas (\"Saber más\").\n"
          . "- No inventes imágenes. Usa solo rutas/URLs proporcionadas como disponibles; si no hay imagen disponible, omite `img`.\n"
          . "- Si incluyes `img`, debe tener `src`, `alt`, `loading=\"lazy\"`, `decoding=\"async\"`, `data-pp-field` y `data-pp-type=\"image\"`.\n"
          . "- Cada bloque debe tener al menos un campo editable y texto significativo.\n";
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            self::GENERATE_SECTION => [
                'label'        => 'Generar sección',
                'output'       => 'json',
                'required'     => ['section_type', 'page_title'],
                'instruction'  =>
                    "Vas a generar el contenido de UNA sección de página web del tipo \"{section_type}\".\n"
                  . "Debes devolver ÚNICAMENTE un objeto JSON válido (sin texto antes ni después, sin markdown).\n"
                  . "El JSON debe cumplir este schema (solo las claves definidas):\n{section_schema}\n"
                  . "Respeta el tono de la marca en todos los textos generados.\n\n"
                  . "REGLAS DE CONTENIDO (obligatorio, el renderer las aplica):\n"
                  . "- URLs: nunca inventes dominios ni URLs absolutas. Usa rutas relativas como \"/contacto\", \"/servicios\". Si no hay un destino real, deja `cta_url` como cadena vacía \"\" (NUNCA \"#\", \"TODO\", \"javascript:\", ni similares: el botón se omite si la URL está vacía).\n"
                  . "- Imágenes: NO inventes URLs de imágenes. Si no se proporciona una URL real (http(s):// o ruta relativa con extensión .jpg/.png/.webp/.svg), deja `image_url` y `background_image` como \"\". El layout se adapta automáticamente.\n"
                  . "- Iconos (solo en `benefits.items[].icon`): usa SOLO uno de estos nombres exactos en kebab-case, sin prefijos: {available_icons}. Si ninguno encaja, deja `icon` como \"\". NUNCA escribas \"icon-shield\", \"lucide-shield\" ni nombres inventados.\n"
                  . "- Longitudes: titulares cortos y directos (≤80 chars en heading, ≤180 en subheading/description). Beneficios: 3-6 items, title ≤40 chars, description 1-2 frases.\n"
                  . "- No uses HTML ni markdown en los textos: solo texto plano. Saltos de párrafo con doble \\n cuando aplique.",
                'user_template' =>
                    "Genera la sección \"{section_type}\" para la página titulada: \"{page_title}\".\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.7,
                    'max_tokens'      => 1500,
                ],
            ],

            // F21.T21.5 — Generar artículo de blog completo (cuerpo editorial).
            // Devuelve title + excerpt + array de bloques (paragraph, heading H2/H3,
            // list, quote, divider). NO genera secciones tipo landing (hero, cta…).
            // El resultado se inserta como UNA sección article_body en la entrada.
            self::GENERATE_ARTICLE => [
                'label'        => 'Generar artículo',
                'output'       => 'json',
                'required'     => ['topic'],
                'instruction'  =>
                    "Eres un redactor profesional especializado en artículos de blog útiles, específicos y memorables.\n"
                  . "Optimiza para dos lectores: el humano (lectura cómoda, párrafos cortos) y los LLMs (frases declarativas, datos concretos, jerarquía clara).\n\n"
                  . "FORMATO DE SALIDA (obligatorio): un único objeto JSON sin texto antes/después, sin markdown.\n"
                  . "Schema:\n"
                  . "{\n"
                  . "  \"title\": \"título final del artículo (≤80 chars, claro y específico)\",\n"
                  . "  \"excerpt\": \"gancho directo en 1-2 frases (≤155 chars, sin clickbait)\",\n"
                  . "  \"blocks\": [\n"
                  . "    {\"type\":\"paragraph\",\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":2,\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":3,\"text\":\"...\"},\n"
                  . "    {\"type\":\"list\",\"style\":\"unordered\",\"items\":[\"...\",\"...\"]},\n"
                  . "    {\"type\":\"quote\",\"text\":\"...\",\"attribution\":\"...\"},\n"
                  . "    {\"type\":\"divider\"}\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLAS DE ESTRUCTURA:\n"
                  . "- NO incluyas H1: el título ya se renderiza arriba. Usa SOLO heading level 2 o 3.\n"
                  . "- Empieza con 1-2 párrafos de gancho (NO con un H2). Frase de apertura concreta y honesta.\n"
                  . "- 3-5 secciones temáticas, cada una con un H2 + 2-4 párrafos. Subdivide con H3 solo si la sección lo necesita.\n"
                  . "- Una sola lista (opcional) cuando aporte (pasos, checklist, ejemplos). Máx 6 items, ítems breves (4-12 palabras).\n"
                  . "- Una sola cita (opcional, solo si refuerza). Atribución real o vacía; nunca inventes nombres.\n"
                  . "- Cierra con un párrafo final que invite al siguiente paso con la empresa: menciona el servicio/propuesta de valor relevante de la memoria de forma concreta. CTA claro pero natural, nunca hard-sell.\n\n"
                  . "PROMOCIÓN (sutil y contextual):\n"
                  . "- El artículo debe APORTAR VALOR por sí mismo: la mayor parte es contenido útil e independiente. La promoción es el complemento, no el eje.\n"
                  . "- Donde encaje de forma natural, conecta el tema con cómo la empresa ayuda (servicios/diferenciadores de la memoria). 1-2 menciones contextuales como máximo, integradas en el contenido útil; nunca un párrafo-anuncio en medio.\n"
                  . "- Tono editorial, no publicitario: nada de superlativos vacíos, urgencias falsas ni 'la mejor opción'. Si no hay forma natural de mencionar el servicio, déjalo solo para el CTA final.\n\n"
                  . "REGLAS DE CONTENIDO:\n"
                  . "- NO inventes datos: cifras, nombres propios, estadísticas, fechas. Si no sabes algo concreto, NO lo metas.\n"
                  . "- Frases ACTIVAS. Sujeto + verbo + objeto. Frases cortas (15-25 palabras de media).\n"
                  . "- Párrafos de 2-4 frases. Si un párrafo crece, divídelo.\n"
                  . "- Lenguaje claro y concreto. Evita estos clichés: \"en el cambiante mundo de hoy\", \"no es ningún secreto\", \"en la era digital\", \"hoy en día\", \"sin lugar a dudas\", \"vamos a explorar\", \"sumérgete\".\n"
                  . "- NO uses adverbios huecos (claramente, evidentemente, obviamente, simplemente, básicamente).\n"
                  . "- Densidad razonable de keywords del tema, sin keyword-stuffing.\n"
                  . "- NO markdown ni HTML en los textos. Texto plano. Para énfasis, usa la palabra justa, no asteriscos.\n"
                  . "- Respeta el tono y la identidad del sitio (memoria) que viene en contexto.\n\n"
                  . "LONGITUD ({length_label}):\n"
                  . "- corto: 5-7 bloques totales (400-600 palabras aprox).\n"
                  . "- medio: 8-12 bloques totales (700-1000 palabras).\n"
                  . "- largo: 12-18 bloques totales (1100-1500 palabras).",
                'user_template' =>
                    "Tema del artículo: \"{topic}\"\n"
                  . "Audiencia: {audience}\n"
                  . "Tono: {tone}\n"
                  . "Longitud objetivo: {length_label}\n"
                  . "Ángulo o enfoque adicional:\n{details}\n",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.65,
                    'max_tokens'      => 4000,
                ],
            ],

            // F21.T21.6 — Genera artículo destilando un documento fuente.
            // A diferencia de GENERATE_ARTICLE (creativo), aquí la IA debe ser
            // FIEL al documento: extrae, reformula, estructura. No inventa
            // datos ni añade interpretaciones que no están.
            self::GENERATE_ARTICLE_FROM_DOCUMENT => [
                'label'        => 'Generar artículo desde documento',
                'output'       => 'json',
                'required'     => ['document_text'],
                'instruction'  =>
                    "Eres un editor profesional. Tu tarea: transformar el documento que te dan en un artículo de blog útil y legible, sin inventar nada que no esté en el documento.\n\n"
                  . "FORMATO DE SALIDA (obligatorio): JSON único, sin texto antes/después, sin markdown.\n"
                  . "Schema:\n"
                  . "{\n"
                  . "  \"title\": \"título atractivo basado en el contenido (≤80 chars)\",\n"
                  . "  \"excerpt\": \"resumen en 1-2 frases (≤155 chars)\",\n"
                  . "  \"blocks\": [\n"
                  . "    {\"type\":\"paragraph\",\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":2,\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":3,\"text\":\"...\"},\n"
                  . "    {\"type\":\"list\",\"style\":\"unordered\",\"items\":[\"...\"]},\n"
                  . "    {\"type\":\"quote\",\"text\":\"...\",\"attribution\":\"...\"},\n"
                  . "    {\"type\":\"divider\"}\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLA FUNDAMENTAL — FIDELIDAD AL DOCUMENTO:\n"
                  . "- TODO dato concreto (números, nombres, fechas, citas) debe estar en el documento. Si NO está, NO lo incluyas.\n"
                  . "- Si el documento contiene una cita literal de alguien, úsala como block `quote` con la atribución correcta.\n"
                  . "- No añadas opiniones que no estén en el documento. Reformula, no fabriques.\n"
                  . "- Si el documento es corto o vago, el artículo será corto y vago: NO rellenes con paja.\n\n"
                  . "REGLAS DE ESTRUCTURA:\n"
                  . "- NO incluyas H1 (el título se renderiza arriba). Solo heading level 2 o 3.\n"
                  . "- Empieza con 1-2 párrafos de gancho que ya estén destilados del documento.\n"
                  . "- 3-5 secciones H2 que agrupen el contenido del documento por temas/argumentos.\n"
                  . "- Si el documento tiene puntos numerados o checklist, conviértelos en un block `list`.\n"
                  . "- Cierre breve con la conclusión principal del documento + un CTA final que invite al siguiente paso con la empresa, mencionando el servicio/propuesta de valor relevante de la memoria (NO del documento). El CTA usa la memoria del negocio, nunca inventa datos. Tono natural, no publicitario.\n\n"
                  . "REGLAS DE ESTILO (web-friendly):\n"
                  . "- Frases activas, párrafos de 2-4 frases.\n"
                  . "- NO uses estos clichés: \"en el cambiante mundo de hoy\", \"hoy en día\", \"no es ningún secreto\", \"sin lugar a dudas\", \"vamos a explorar\", \"sumérgete\".\n"
                  . "- NO uses adverbios huecos (claramente, evidentemente, simplemente, básicamente).\n"
                  . "- Lenguaje del documento traducido a lenguaje claro y específico para web.\n"
                  . "- NO markdown ni HTML en los textos.\n\n"
                  . "ÁNGULO ({angle}):\n"
                  . "Si te indican un ángulo o enfoque, filtra y prioriza el contenido del documento que respalde ese ángulo. No fuerces.\n\n"
                  . "LONGITUD ({length_label}):\n"
                  . "- corto: 5-7 bloques (400-600 palabras).\n"
                  . "- medio: 8-12 bloques (700-1000 palabras).\n"
                  . "- largo: 12-18 bloques (1100-1500 palabras).\n"
                  . "Si el documento es demasiado breve para alcanzar la longitud objetivo, NO la fuerces — entrega un artículo más corto pero honesto.",
                'user_template' =>
                    "Documento fuente:\n---\n{document_text}\n---\n\n"
                  . "Audiencia: {audience}\n"
                  . "Tono: {tone}\n"
                  . "Longitud objetivo: {length_label}\n"
                  . "Ángulo/enfoque: {angle}\n",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.5,        // más conservador, prioriza fidelidad
                    'max_tokens'      => 4000,
                ],
            ],

            // F21.T21.7 — Sugerir entradas nuevas relacionadas con las existentes.
            // No genera el artículo, solo PROPONE ideas: título, ángulo, por qué
            // ahora. El usuario elige una y se dispara GENERATE_ARTICLE después.
            self::SUGGEST_RELATED_ARTICLES => [
                'label'        => 'Sugerir entradas relacionadas',
                'output'       => 'json',
                'required'     => [],
                'instruction'  =>
                    "Eres un editor que ayuda a planificar el blog de un negocio. Tu tarea: proponer entradas NUEVAS que llenen huecos temáticos del blog actual.\n\n"
                  . "FORMATO DE SALIDA (obligatorio): JSON único.\n"
                  . "Schema:\n"
                  . "{\n"
                  . "  \"suggestions\": [\n"
                  . "    {\n"
                  . "      \"title\": \"título atractivo (≤80 chars)\",\n"
                  . "      \"angle\": \"el ángulo o promesa concreta del artículo (1 frase)\",\n"
                  . "      \"audience\": \"a quién va dirigido específicamente\",\n"
                  . "      \"why_now\": \"por qué encaja con lo que ya hay publicado (1 frase, justificación clara)\"\n"
                  . "    }\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLAS:\n"
                  . "- Propón {count} ideas distintas. Variadas entre sí: ángulos, formatos (lista vs análisis vs caso), audiencias.\n"
                  . "- NO repitas títulos ya publicados ni variantes superficiales (ej. \"7 errores caros…\" vs \"5 errores comunes…\" del mismo tema).\n"
                  . "- Busca huecos: temas tangenciales no cubiertos, contraangulos, casos prácticos, errores frecuentes, comparativas.\n"
                  . "- Cada título debe ser concreto. NO \"Cómo ser mejor en X\"; SÍ \"Las 3 métricas que te dicen si tu landing convierte\".\n"
                  . "- Mantén coherencia con el negocio (memoria) y el tono de las entradas existentes.\n"
                  . "- NO clichés: \"en el cambiante mundo de hoy\", \"no es ningún secreto\", \"hoy en día\".\n"
                  . "- Si el blog tiene muy pocas entradas (≤2), prioriza pilares fundamentales del tema; si tiene muchas, busca nichos específicos y tangenciales.",
                'user_template' =>
                    "Entradas publicadas hasta hoy ({existing_count} total):\n---\n{existing_posts}\n---\n\n"
                  . "{focus_line}"
                  . "Propón {count} entradas nuevas que complementen estas y abran caminos editoriales útiles.",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.85,    // más creativa: queremos variedad
                    'max_tokens'      => 1200,
                ],
            ],

            self::REWRITE_TEXT => [
                'label'        => 'Reescribir texto',
                'output'       => 'text',
                'required'     => ['original_text'],
                'instruction'  =>
                    "Reescribe el texto que te dará el usuario manteniendo el mismo significado "
                  . "pero aplicando el tono y la identidad de la marca definidos más abajo.\n"
                  . "{rewrite_goal}\n"
                  . "Devuelve ÚNICAMENTE el texto reescrito, sin comillas ni explicaciones.",
                'user_template' =>
                    "Texto original:\n---\n{original_text}\n---",
                'options'      => [
                    'temperature' => 0.75,
                    'max_tokens'  => 800,
                ],
            ],

            self::IMPROVE_SEO => [
                'label'        => 'Mejorar SEO',
                'output'       => 'json',
                'required'     => ['page_title', 'page_content'],
                'instruction'  =>
                    "Propón el mejor título SEO (≤60 chars), meta description (≤155 chars), "
                  . "y slug (ASCII, kebab-case, ≤50 chars) para esta página. El slug puede incluir barras para URLs anidadas, sin barra inicial.\n"
                  . "Usa las keywords del contexto cuando sea natural. No uses relleno ni clickbait.\n"
                  . "Mantén el slug actual si ya es claro y describe bien la intención de búsqueda.\n"
                  . "No inventes ubicaciones, precios, servicios ni promesas que no aparezcan en el contexto.\n"
                  . "Responde SÓLO con JSON: {\"seo_title\":\"...\",\"meta_description\":\"...\",\"slug\":\"...\"}",
                'user_template' =>
                    "Título actual de la página: \"{page_title}\"\n\n"
                  . "Tipo de página: {page_type}\n"
                  . "Slug actual: {current_slug}\n"
                  . "Meta título actual: {current_meta_title}\n"
                  . "Meta descripción actual: {current_meta_description}\n\n"
                  . "Contenido de la página:\n---\n{page_content}\n---",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.4,
                    'max_tokens'      => 300,
                ],
            ],

            self::GENERATE_PAGE_STRUCTURE => [
                'label'        => 'Estructurar página',
                'output'       => 'json',
                'required'     => ['page_title', 'page_goal'],
                'instruction'  =>
                    "Diseña la estructura óptima de secciones para una página web con el objetivo dado.\n"
                  . "Devuelve un JSON con forma: {\"sections\":[{\"type\":\"<uno de los tipos permitidos>\",\"variant\":\"<variante válida>\",\"rationale\":\"...\"}, ...]}\n"
                  . "TIPOS PERMITIDOS (usa SOLO estos, no inventes otros como 'contact', 'about', etc.): {available_section_types}\n"
                  . "VARIANTES VÁLIDAS POR TIPO:\n{variants_by_type}\n"
                  . "Elige variantes distintas y con intención visual. Evita usar siempre `default`.\n"
                  . "Usa entre 4 y 7 secciones, en el orden en que deberían aparecer. Las razones deben ser breves (≤15 palabras).",
                'user_template' =>
                    "Título de la página: \"{page_title}\"\n"
                  . "Objetivo: {page_goal}\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.6,
                    'max_tokens'      => 700,
                ],
            ],

            // Recrear una página a partir de una CAPTURA de referencia (visión).
            // La imagen viaja por `$input['_images']` (no es un placeholder de texto).
            self::RECREATE_FROM_REFERENCE => [
                'label'        => 'Recrear desde referencia',
                'output'       => 'json',
                'required'     => ['page_title', 'page_goal'],
                'instruction'  =>
                    "Estás viendo la CAPTURA de una página web que sirve de REFERENCIA visual.\n"
                  . "Tu tarea: identificar las secciones que la componen (de arriba abajo) y su disposición, "
                  . "y traducirlas a la estructura de secciones de nuestro sistema.\n"
                  . "Devuelve un JSON con forma: {\"sections\":[{\"type\":\"<tipo permitido>\",\"variant\":\"<variante válida>\",\"rationale\":\"qué bloque de la referencia representa\"}, ...]}\n"
                  . "TIPOS PERMITIDOS (usa SOLO estos; si un bloque no encaja en ninguno, usa el más parecido): {available_section_types}\n"
                  . "VARIANTES VÁLIDAS POR TIPO:\n{variants_by_type}\n"
                  . "Elige la variante cuya disposición se parezca más a la del bloque de la referencia.\n"
                  . "IMPORTANTE: inspírate en la ESTRUCTURA, el orden y el aire visual de la referencia, "
                  . "NO copies su texto ni su marca. El contenido se generará después para el negocio del usuario.\n"
                  . "Respeta el nº de secciones que realmente veas (normalmente entre 4 y 9), en su orden de aparición.",
                'user_template' =>
                    "Recrea la estructura de la página de la captura adjunta.\n"
                  . "Es para MI negocio — Título de mi página: \"{page_title}\"\n"
                  . "Objetivo de mi página: {page_goal}\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.4,
                    'max_tokens'      => 1100,
                ],
            ],

            self::DESCRIBE_REFERENCE_LAYOUT => [
                'label'        => 'Describir la estructura de una referencia visual',
                'output'       => 'json',
                'required'     => ['page_title'],
                'instruction'  =>
                    "Eres un director de arte de una agencia TOP. Estás viendo una o varias CAPTURAS de webs que el usuario aporta como REFERENCIA.\n"
                  . "Tu tarea: leer la composición de la referencia de ARRIBA A ABAJO y describir su ESTRUCTURA y su AIRE visual, para que otra IA escriba después una web nueva inspirada en ella.\n"
                  . "Devuelve ÚNICAMENTE JSON válido con esta forma exacta:\n"
                  . "{\n"
                  . "  \"design_language\": \"2-4 frases sobre el aire general: densidad (minimalista vs denso), uso del espacio en blanco, alineación dominante (centrada/izquierda), uso de tarjetas/paneles, ritmo, contraste de tamaños, sensación (sobrio, cálido, editorial, técnico...).\",\n"
                  . "  \"sections\": [\n"
                  . "    {\n"
                  . "      \"role\": \"qué es esta sección (hero, prueba social, lista de servicios, proceso por pasos, galería, FAQ, cierre/CTA...)\",\n"
                  . "      \"composition\": \"cómo está dispuesta: nº de columnas, media a izquierda/derecha, grid de N, tarjetas, alineación, jerarquía de titulares...\",\n"
                  . "      \"density\": \"holgada | media | densa\",\n"
                  . "      \"emphasis\": \"qué destaca visualmente en esta sección\",\n"
                  . "      \"background\": \"clara | suave | tintada | intensa | oscura | foto\",  // tratamiento del FONDO de la sección en la referencia: clara=fondo de página, suave=gris/neutro suave, tintada=banda con un tinte de color claro, intensa=banda saturada de color, oscura=banda oscura, foto=imagen a sangre detrás del contenido\n"
                  . "      \"image_brief\": {\"subject\": \"<stock photo search query IN ENGLISH for this section, about the USER'S business — never the reference's brand>\", \"orientation\": \"landscape | portrait | squarish\", \"count\": <1-4, nº de fotos DISTINTAS que necesita la sección (1 normal; 3-4 solo para grids/galerías de fotos)>}  // null si la sección no lleva fotografía\n"
                  . "    }\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLAS:\n"
                  . "- Respeta el NÚMERO y el ORDEN reales de secciones que veas (normalmente entre 3 y 8). No inventes secciones que no aparecen ni fuerces un número fijo.\n"
                  . "- Describe SOLO estructura, disposición y aire. NO transcribas textos, NO menciones colores ni tipografías concretas, NO nombres de marca de la referencia (la marca y el color los pone el usuario, no la referencia).\n"
                  . "- `background` captura el RITMO de bandas de la página (alternancia claro/tintado/oscuro/foto). Es estructura, no color concreto: una banda rosa pastel y una azul pastel son ambas `tintada`.\n"
                  . "- `image_brief.subject`: en INGLÉS, 3-6 palabras, pensado para buscar en un banco de fotos, y sobre el negocio del USUARIO (usa el contexto), no sobre lo que sale en la captura. SIEMPRE escenas REALES y concretas: personas trabajando, manos, objetos, espacios (ej.: 'physiotherapist treating patient back', 'team meeting around laptop'). PROHIBIDO lo abstracto: nada de 'abstract', 'technology background', '3d render', 'neon', 'wallpaper', 'pattern'.\n"
                  . "- `image_brief.count`: si la sección es un grid de N tarjetas CON foto, pide exactamente N fotos (máx 4). Si no se pueden conseguir N, el generador hará las tarjetas sin foto: mejor eso que tarjetas desiguales.\n"
                  . "- Si hay varias capturas, intégralas como un único lenguaje de diseño coherente.\n"
                  . "- Sé concreto y accionable: 'grid de 3 tarjetas con icono arriba' es útil; 'sección bonita' no.",
                'user_template' =>
                    "Página que voy a crear: \"{page_title}\"\n"
                  . "Objetivo de la página: {block_goal}\n"
                  . "Idioma del contenido: {language}\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.3,
                    'max_tokens'      => 1800,
                ],
            ],

            self::GENERATE_CUSTOM_BLOCK_FROM_REFERENCE => [
                'label'        => 'Generar bloque PP-friendly desde referencia',
                'output'       => 'json',
                'required'     => ['page_title', 'block_goal'],
                'instruction'  =>
                    "Estás viendo una referencia visual de una web. Tu tarea es generar UN bloque de página en PromptPress-friendly HTML.\n"
                  . "Devuelve ÚNICAMENTE JSON válido con esta forma exacta:\n"
                  . "{\n"
                  . "  \"html\": \"<div class=\\\"ppb-container ...\\\">...</div>\",\n"
                  . "  \"rationale\": {\n"
                  . "    \"summary\": \"...\",\n"
                  . "    \"reference_takeaways\": [\"...\"],\n"
                  . "    \"brand_application\": [\"...\"]\n"
                  . "  }\n"
                  . "}\n\n"
                  . self::ppbGuardrails()
                  . "- Si se te pasan errores de validación previos, corrige exactamente esos problemas.",
                'user_template' =>
                    "Página: \"{page_title}\"\n"
                  . "Objetivo del bloque: {block_goal}\n"
                  . "Rol/sección dentro de la página: {section_role}\n"
                  . "Idioma: {language}\n"
                  . "Imágenes disponibles para usar en `img` si hacen falta:\n{available_images}\n"
                  . "Errores de validación previos a corregir:\n{validation_feedback}\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.55,
                    'max_tokens'      => 7000,
                ],
            ],

            // D-MB2 R3 — Compone la PÁGINA COMPLETA (todas las secciones) en una
            // sola llamada, guiada por el outline derivado de la referencia.
            // Coherencia de ritmo garantizada porque el mismo "pase de diseño"
            // escribe todas las secciones a la vez.
            self::COMPOSE_CUSTOM_PAGE_FROM_REFERENCE => [
                'label'        => 'Componer página PP-friendly desde referencia',
                'output'       => 'json',
                'required'     => ['page_title', 'sections_outline'],
                'instruction'  =>
                    "Eres el director de arte de una agencia TOP ejecutando una página completa. Estás viendo la referencia visual del cliente; el outline (abajo) ya define las secciones, su orden, su tema de fondo y sus imágenes.\n"
                  . "Tu tarea: escribir TODAS las secciones de la página en PromptPress-friendly HTML, de una vez, con coherencia de ritmo y de voz.\n"
                  . "Devuelve ÚNICAMENTE JSON válido con esta forma exacta:\n"
                  . "{\n"
                  . "  \"rationale\": {\"summary\": \"2-3 frases: qué tomaste de la referencia y cómo lo vestiste con la marca\"},\n"
                  . "  \"sections\": [\n"
                  . "    {\"html\": \"<div data-ppb-theme=\\\"...\\\">...</div>\"}\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLAS DE PÁGINA (además de las de bloque):\n"
                  . "- Devuelve EXACTAMENTE una entrada en `sections` por cada sección del outline, en el mismo orden.\n"
                  . "- Cada `html` es un bloque independiente y completo; su elemento raíz lleva el `data-ppb-theme` y `data-ppb-pad` que indica el outline para esa sección.\n"
                  . "- RITMO: varía la composición entre secciones — no repitas el patrón 'header centrado + grid' en todas; alterna alineación izquierda/centrada, splits y grids según el outline. Dos secciones consecutivas no deben verse iguales.\n"
                  . "- Las imágenes listadas en el outline pertenecen a SU sección: úsalas ahí y solo ahí, máximo una vez cada una.\n"
                  . "- Textos específicos del negocio: titulares ≤80 chars, párrafos de 1-3 frases CON SUSTANCIA (argumentos, beneficios concretos), no frases de relleno de 8 palabras. Una página 'completa pero escueta' se percibe vacía: cada sección debe poder sostenerse sola.\n"
                  . "- Los nombres `data-pp-field` solo deben ser únicos DENTRO de cada sección (cada sección es un bloque independiente).\n\n"
                  . self::ppbGuardrails(),
                'user_template' =>
                    "Página: \"{page_title}\"\n"
                  . "Objetivo de la página: {page_goal}\n"
                  . "Idioma: {language}\n"
                  . "LENGUAJE DE DISEÑO DEL SITIO (derivado de la referencia): {design_language}\n\n"
                  . "OUTLINE DE SECCIONES (orden, rol, tema de fondo e imágenes por sección):\n{sections_outline}\n\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.55,
                    'max_tokens'      => 20000,
                ],
            ],

            // FH2 — Modo CANVAS: la página completa en HTML+CSS LIBRES (sin la
            // gramática ppb), bajo el contrato mínimo del runtime Canvas. Es la
            // acción estrella del pivote C10.
            self::COMPOSE_CANVAS_PAGE => [
                'label'        => 'Componer página Canvas (HTML libre)',
                'output'       => 'json',
                'required'     => ['page_title', 'page_goal'],
                'instruction'  =>
                    "Eres director de arte y maquetador senior de una agencia web TOP. Vas a diseñar y construir UNA página completa, única y profesional, en HTML+CSS libres. Si hay capturas adjuntas, son la REFERENCIA visual del cliente: hereda su estructura, ritmo, densidad y aire — nunca sus textos, colores, tipografías ni marca.\n"
                  . "Devuelve ÚNICAMENTE JSON válido con esta forma exacta:\n"
                  . "{\n"
                  . "  \"html\": \"<section data-pp-section=\\\"hero\\\">...</section><section data-pp-section=\\\"...\\\">...</section>\",\n"
                  . "  \"css\": \"/* CSS completo de la página */\",\n"
                  . "  \"rationale\": {\"direction\": \"el lenguaje estético elegido + densidad + cuál es la sección protagonista (1 frase)\", \"reference_applied\": [\"3-6 decisiones concretas tomadas de las capturas: estructura, ritmo, alineación, densidad, fondos, media\"], \"summary\": \"2-3 frases: qué tomaste de la referencia y cómo lo aplicaste a la marca\"}\n"
                  . "}\n"
                  . "PIENSA PRIMERO la `direction` (lenguaje + densidad + sección protagonista) y LUEGO construye toda la página coherente con esa decisión.\n\n"
                  . "CONTRATO CANVAS (el sistema lo verifica; incumplirlo degrada tu trabajo):\n"
                  . "- `html`: SOLO el contenido (sin <html>/<head>/<body>/<style>). El nivel superior es una secuencia de `<section data-pp-section=\"slug-descriptivo\">` (hero, servicios, proceso, prueba-social, cierre...).\n"
                  . "- PROHIBIDO: <script>, <iframe>, <video>, <form>/<input> crudos, atributos on*, position:fixed, @import, fuentes externas, librerías externas. Todo eso se elimina automáticamente.\n"
                  . "- `css`: el estilo completo de la página. Usa clases con un prefijo corto propio (p. ej. `.lx-hero`). El CSS se aísla automáticamente a esta página: no afecta al resto del sitio.\n"
                  . "- Responsive OBLIGATORIO: la página debe verse perfecta en móvil (≤480px), tablet y desktop. Usa @media; el grid de desktop colapsa a 1 columna en móvil; tipografía fluida con clamp().\n\n"
                  . "MARCA (ley, no sugerencia):\n"
                  . "- Colores y tipografías SOLO vía tokens del sitio: var(--pp-primary), var(--pp-primary-dark), var(--pp-bg), var(--pp-surface), var(--pp-text), var(--pp-text-muted), var(--pp-on-primary), var(--pp-on-text), var(--pp-font-heading), var(--pp-font-body), var(--pp-radius-sm|md|lg|xl), var(--pp-shadow-sm|md|lg|xl), var(--pp-container-max).\n"
                  . "- Tonos derivados SIEMPRE con color-mix(in srgb, var(--pp-primary) N%, ...) o transparencias. PROHIBIDO inventar colores hex/rgb propios (única excepción: blanco/negro puros en overlays sobre fotografía).\n"
                  . "- La referencia NUNCA aporta color ni tipografía: solo estructura, composición y aire.\n\n"
                  . "COMPONENTES FUNCIONALES:\n"
                  . "- Formularios: NUNCA los dibujes. Si la página necesita uno, escribe el placeholder `{{form:REF}}` en su sección (REF = id o slug de los formularios disponibles listados abajo). Si no hay disponibles, usa un CTA a `/contacto`.\n"
                  . "- Botones del sistema disponibles si quieres consistencia: `pp-btn pp-btn--primary pp-btn--lg` y `pp-btn--ghost` (ya estilados por el skin). También puedes diseñar los tuyos con tokens.\n"
                  . "- Iconos: PROHIBIDO usar emojis o pictogramas Unicode en cualquier texto (cero, siempre). Si necesitas iconos, dibuja SVG inline sencillo de trazo (viewBox 24, stroke=\"currentColor\", fill=\"none\", stroke-width 2), estilo lucide, coherentes entre sí.\n"
                  . "- Imágenes: SOLO las URLs de `available_images` (cada una máximo una vez, en su sección indicada), siempre con alt descriptivo. Si una sección no tiene imagen disponible, diséñala sin foto (color, tipografía grande, SVG decorativo).\n\n"
                  . "REFERENCIAS VISUALES ADJUNTAS:\n"
                  . "- Si hay capturas adjuntas, son la fuente principal de arquitectura visual. El resultado debe reconocerse por composición y ritmo, no por copiar textos, colores ni marca.\n"
                  . "- No generes una landing estándar por defecto. Prohibido caer en la secuencia automática hero centrado + tarjetas + testimonios + CTA si esa secuencia no aparece en las capturas.\n"
                  . "- Ajusta el número de secciones al ritmo de la referencia. No añadas testimonios, logos de clientes, métricas, FAQ, carruseles o galerías si no aparecen claramente en las capturas o no hay datos reales del negocio.\n"
                  . "- PROHIBIDO inventar citas inspiracionales, blockquotes, frases tipo manifiesto, reseñas o 'lo que dicen nuestros clientes'. Solo pueden aparecer si el usuario aportó ese texto o la captura muestra inequívocamente ese módulo.\n"
                  . "- No conviertas cualquier página en 'proceso por fases'. Si la referencia no muestra una secuencia temporal/pasos, evita secciones de Fase 1/Fase 2/Paso 1/Paso 2.\n"
                  . "- En `rationale.reference_applied`, enumera decisiones verificables tomadas de las capturas. Si no puedes verlas, diseña una página más simple y dilo ahí; no inventes una referencia.\n\n"
                  . "INTERACCIÓN (sin escribir JS — el sistema aporta los comportamientos vía `data-pp-behavior`):\n"
                  . "- FAQ/acordeón: `<div data-pp-behavior=\"accordion\"><details><summary>Pregunta</summary><p>Respuesta</p></details>…</div>` (ya viene estilado; solo uno abierto a la vez).\n"
                  . "- Aparición al hacer scroll: añade `data-pp-behavior=\"reveal\"` a tarjetas/bloques (escalonado con `data-pp-reveal-delay=\"1..5\"`). Úsalo con intención en 2-3 secciones, no en todo.\n"
                  . "- Carrusel (testimonios/galería con 3+ items): `<div data-pp-behavior=\"slider\"><div>…slide…</div>…</div>` (flechas y deslizamiento automáticos).\n"
                  . "- Cifra animada: `<span data-pp-behavior=\"counter\">120</span>` SOLO con cifras reales del contexto.\n"
                  . "- Además puedes usar animaciones CSS puras (@keyframes) para marquees o detalles sutiles.\n\n"
                  . "CALIDAD (los fallos que el cliente ya ha penalizado — evítalos):\n"
                  . "- Cada tarjeta/item: título + 1-2 frases. Tarjetas uniformes dentro de un mismo grid. Sin huérfanos en la última fila.\n"
                  . "- Cada sección completa y con sustancia; CTAs claros (hero y cierre, etiquetas accionables específicas); jerarquía tipográfica fuerte; un solo h1.\n"
                  . "- NO inventes datos de contacto, precios, métricas ni clientes. NO texto placeholder ni lorem ipsum. NO simules widgets (calendarios, buscadores, players, MAPAS).\n"
                  . "- La página NO incluye header, menú ni footer: el sitio ya los pone alrededor. NUNCA generes una sección de enlaces legales, datos de contacto o tipo footer. La ÚLTIMA sección es siempre un cierre comercial: titular fuerte + CTA primario (o el placeholder de formulario).\n"
                  . "- El cierre NUNCA lleva dirección, email ni teléfono: NO los conoces y el sistema ya los muestra donde toca. Si usas `{{form:REF}}`, el placeholder ES el contenido principal de esa sección (titular + 1 frase + placeholder); no lo acompañes de columnas de datos de contacto.\n"
                  . "- Elementos centrados de verdad (max-width + margin auto); aire generoso entre secciones; alterna fondos (claro/tintado/oscuro/foto) según el ritmo de la referencia.\n"
                  . "- VARIEDAD ENTRE PÁGINAS: si el contexto incluye 'HERO DE LA HOME', estás diseñando una página interior — su hero NO puede ser un clon del de la home: cambia la composición (altura más contenida, banner compacto, split asimétrico, alineación distinta, con/sin foto) manteniendo el mismo lenguaje de marca. Al navegar entre páginas debe notarse que son páginas distintas de la misma web.\n\n"
                  . self::canvasDesignCraft()
                  . self::canvasAntiSlop(),
                'user_template' =>
                    "Página: \"{page_title}\"\n"
                  . "Objetivo: {page_goal}\n"
                  . "Idioma del contenido: {language}\n"
                  . "LENGUAJE DE DISEÑO DEL SITIO (derivado de la referencia): {design_language}\n\n"
                  . "ESTRUCTURA SUGERIDA (de la referencia; nº y orden de secciones, fondo e imágenes por sección):\n{sections_outline}\n\n"
                  . "FORMULARIOS DISPONIBLES (para {{form:REF}}):\n{available_forms}\n\n"
                  . "{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.6,
                    'max_tokens'      => 30000,
                ],
            ],

            // FH3 — Edición conversacional de UNA sección de una página canvas.
            // El usuario (no técnico) pide en lenguaje natural; devolvemos la
            // sección reescrita + CSS adicional + una respuesta humana corta.
            self::EDIT_CANVAS_SECTION => [
                'label'        => 'Editar sección canvas (chat)',
                'output'       => 'json',
                'required'     => ['instruction', 'section_html'],
                'instruction'  =>
                    "Eres el diseñador de confianza de un cliente NO técnico. Te pide un cambio sobre UNA sección de su página web. Aplica EXACTAMENTE lo que pide (interpretando su intención con criterio profesional) y no toques nada que no haya pedido.\n"
                  . "Devuelve ÚNICAMENTE JSON válido:\n"
                  . "{\n"
                  . "  \"html\": \"<section data-pp-section=\\\"...\\\">...</section>\",   // la sección COMPLETA reescrita, mismo data-pp-section\n"
                  . "  \"css_append\": \"/* solo reglas NUEVAS o modificadas */\",          // se añade al CSS de la página; cadena vacía si no hace falta\n"
                  . "  \"reply\": \"frase corta en español, tono cercano, explicando qué has cambiado\"  // p. ej. \"Listo, he puesto el titular más grande y el botón en verde.\"\n"
                  . "}\n\n"
                  . "REGLAS:\n"
                  . "- Cambia lo MÍNIMO necesario para cumplir la petición. Conserva textos, imágenes y estructura que el cliente no ha mencionado.\n"
                  . "- El HTML cumple el contrato Canvas: sin <script>/<iframe>/<form> crudos, sin on*, sin position:fixed. Formularios solo vía {{form:REF}}.\n"
                  . "- `css_append`: solo reglas nuevas o que SOBRESCRIBEN a las existentes (van después en la cascada). Reutiliza los nombres de clase existentes cuando modificas algo; usa tokens de marca (var(--pp-*)) para colores/tipos.\n"
                  . "- Si pide una imagen nueva, usa solo las de `available_images`; si no hay ninguna que encaje, mantén la actual y dilo en `reply`.\n"
                  . "- PROHIBIDO: emojis, datos de contacto inventados, placeholders. Responsive intacto.\n"
                  . "- Mantén el nivel de diseño: nada de clichés de IA (eyebrows en cada titular, verbos hueco como 'impulsa/transforma', datos inventados, raya larga —, 3 tarjetas iguales). Texto humano y concreto.\n"
                  . "- Si piden interacción (acordeón, carrusel, animación al scroll, cifra animada), usa `data-pp-behavior=\"accordion|slider|reveal|counter\"` (acordeón = `<div data-pp-behavior=\"accordion\"><details><summary>…</summary><p>…</p></details>…</div>`); NUNCA escribas JS. El comportamiento YA VIENE ESTILADO: NO añadas tu propio icono +/− ni reglas `::after`/`::before` sobre el `summary` (la plataforma dibuja el chevron).\n"
                  . "- Si la petición es imposible o peligrosa (p. ej. 'añade un vídeo de YouTube'), NO lo simules: haz la mejor alternativa válida y explícalo en `reply` con naturalidad.\n"
                  . "- `reply` máximo 2 frases, sin tecnicismos (nada de 'CSS', 'HTML', 'clases'): habla de lo visual ('el titular', 'el fondo', 'el botón').",
                'user_template' =>
                    "Petición del cliente: \"{instruction}\"\n\n"
                  . "Sección actual:\n```html\n{section_html}\n```\n\n"
                  . "CSS actual de la página (contexto, NO lo devuelvas entero):\n```css\n{page_css}\n```\n\n"
                  . "Imágenes disponibles si pide cambiar/añadir fotos:\n{available_images}\n\n"
                  . "Página: \"{page_title}\" · Idioma: {language}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.4,
                    'max_tokens'      => 9000,
                ],
            ],

            // FH3 — Edición conversacional a nivel de PÁGINA entera (cambios
            // globales: "más aire", "todo más sobrio", "reordena secciones").
            self::EDIT_CANVAS_PAGE => [
                'label'        => 'Editar página canvas (chat)',
                'output'       => 'json',
                'required'     => ['instruction', 'page_html'],
                'instruction'  =>
                    "Eres el diseñador de confianza de un cliente NO técnico. Te pide un cambio GLOBAL sobre su página web. Aplica lo que pide con criterio profesional, tocando lo mínimo necesario.\n"
                  . "Devuelve ÚNICAMENTE JSON válido:\n"
                  . "{\n"
                  . "  \"html\": \"...página completa (secuencia de <section data-pp-section>)...\",\n"
                  . "  \"css\": \"...CSS completo actualizado de la página...\",\n"
                  . "  \"reply\": \"frase corta en español explicando qué has cambiado\"\n"
                  . "}\n\n"
                  . "REGLAS:\n"
                  . "- Conserva los `data-pp-section` existentes (puedes reordenar secciones si lo piden, manteniendo sus ids). Conserva todo contenido no mencionado.\n"
                  . "- Contrato Canvas: sin <script>/<iframe>/<form> crudos, sin on*, sin position:fixed; formularios solo vía {{form:REF}}; tokens de marca para color/tipo; responsive intacto; cero emojis.\n"
                  . "- Si pide una imagen nueva, usa solo `available_images`.\n"
                  . "- Mantén el nivel de diseño: nada de clichés de IA (eyebrows en cada titular, verbos hueco como 'impulsa/transforma', datos inventados, raya larga —, 3 tarjetas iguales).\n"
                  . "- Si piden interacción (acordeón, carrusel, animación al scroll, cifra animada), usa `data-pp-behavior=\"accordion|slider|reveal|counter\"` (acordeón = `<div data-pp-behavior=\"accordion\"><details><summary>…</summary><p>…</p></details>…</div>`); NUNCA escribas JS. El comportamiento YA VIENE ESTILADO: NO añadas tu propio icono +/− ni reglas `::after`/`::before` sobre el `summary` (la plataforma dibuja el chevron).\n"
                  . "- `reply` máximo 2 frases, sin tecnicismos.",
                'user_template' =>
                    "Petición del cliente: \"{instruction}\"\n\n"
                  . "Página actual:\n```html\n{page_html}\n```\n\n"
                  . "CSS actual:\n```css\n{page_css}\n```\n\n"
                  . "Imágenes disponibles:\n{available_images}\n\n"
                  . "Página: \"{page_title}\" · Idioma: {language}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.4,
                    'max_tokens'      => 30000,
                ],
            ],

            self::DISCOVER_PAGE_OPPORTUNITIES => [
                'label'        => 'Detectar oportunidades de página',
                'output'       => 'json',
                'required'     => ['existing_pages'],
                'instruction'  =>
                    "Analiza el sitio y detecta oportunidades concretas para crear nuevas páginas.\n"
                  . "Prioriza huecos reales: servicios mencionados en memoria/documentos que no tengan página, landings SEO útiles, páginas de contacto/confianza necesarias o páginas que completen la arquitectura actual.\n"
                  . "No propongas ideas genéricas si hay oportunidades específicas. No inventes servicios que no estén en el contexto.\n"
                  . "Devuelve JSON con esta forma exacta: {\"site_summary\":\"...\",\"opportunities\":[{\"title\":\"...\",\"page_type\":\"service|product|landing|article|contact\",\"goal\":\"...\",\"audience\":\"...\",\"reason\":\"...\",\"priority\":\"high|medium|low\",\"source\":\"memory|documents|pages\",\"details\":\"...\"}]}.\n"
                  . "Máximo 5 oportunidades. Títulos claros y accionables. Razones de una frase.",
                'user_template' =>
                    "Páginas existentes del sitio:\n{existing_pages}\n\n"
                  . "Notas adicionales del usuario:\n{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.35,
                    'max_tokens'      => 900,
                ],
            ],

            self::GENERATE_PAGE_BRIEF => [
                'label'        => 'Preparar brief de página',
                'output'       => 'json',
                'required'     => ['page_idea'],
                'instruction'  =>
                    "Convierte la idea del usuario en un plan de página web listo para aprobar.\n"
                  . "La experiencia debe ser guiada: infiere todo lo posible desde memoria, documentos y páginas existentes. Solo incluye preguntas si faltan decisiones realmente importantes.\n"
                  . "Si la página necesita capturar leads, recomienda un formulario y sus campos; la plataforma lo creará automáticamente.\n"
                  . "Devuelve JSON con esta forma exacta: {\"title\":\"...\",\"page_type\":\"home|service|product|landing|article|contact\",\"goal\":\"...\",\"audience\":\"...\",\"tone\":\"...\",\"seo_intent\":\"...\",\"primary_cta\":\"...\",\"recommended_form\":{\"needed\":true,\"purpose\":\"...\",\"fields\":[{\"label\":\"...\",\"name\":\"...\",\"field_type\":\"text|email|tel|textarea|select|checkbox\",\"required\":true,\"placeholder\":\"...\"}]},\"sections\":[{\"type\":\"hero|text_image|benefits|faq|cta|form\",\"heading\":\"...\",\"purpose\":\"...\"}],\"questions\":[\"...\"],\"extra_context\":\"...\"}.\n"
                  . "Usa entre 4 y 7 secciones. Si recommended_form.needed=true, incluye una sección type=form cerca del final. No inventes datos de contacto, precios ni promesas.",
                'user_template' =>
                    "Idea u oportunidad elegida:\n{page_idea}\n\n"
                  . "Páginas existentes del sitio:\n{existing_pages}\n\n"
                  . "Notas adicionales:\n{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.45,
                    'max_tokens'      => 1200,
                ],
            ],

            self::ANALYZE_SITE_ARCHITECTURE => [
                'label'        => 'Analizar arquitectura del sitio',
                'output'       => 'json',
                'required'     => ['site_map_context'],
                'instruction'  =>
                    "Actúa como arquitecto de información para un sitio web creado con PromptPress.\n"
                  . "Analiza memoria, documentos, páginas existentes, slugs, formularios y secciones para detectar cómo debería organizarse el sitio.\n"
                  . "No inventes servicios ni páginas que no estén apoyados por el contexto. Prioriza claridad de navegación, captación de leads y cobertura de servicios/SEO.\n"
                  . "Devuelve JSON con esta forma exacta: {\"summary\":\"...\",\"health\":{\"score\":0,\"label\":\"...\"},\"suggested_groups\":[{\"label\":\"...\",\"slug\":\"...\",\"reason\":\"...\",\"priority\":\"high|medium|low\"}],\"missing_pages\":[{\"title\":\"...\",\"page_type\":\"service|product|landing|article|contact\",\"parent_slug\":\"...\",\"goal\":\"...\",\"reason\":\"...\",\"priority\":\"high|medium|low\",\"architecture_context\":\"...\"}],\"diagnostics\":[{\"label\":\"...\",\"severity\":\"info|warning|critical\",\"detail\":\"...\"}]}.\n"
                  . "Usa máximo 4 grupos sugeridos, 6 páginas faltantes y 5 diagnósticos. El score debe ser 0-100.\n\n"
                  . "F22 — DIRECTIVA DE OBJETIVO DEL USUARIO (sesga la propuesta):\n{intent_directive}",
                'user_template' =>
                    "Contexto estructural del sitio:\n{site_map_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.35,
                    'max_tokens'      => 1400,
                ],
            ],

            self::EXTRACT_BUSINESS_PROFILE => [
                'label'        => 'Extraer perfil del negocio',
                'output'       => 'json',
                'required'     => ['document_text', 'field_schema'],
                'instruction'  =>
                    "Analiza un dossier comercial, brochure o documento de empresa y convierte su contenido en memoria inicial para PromptPress.\n"
                  . "Extrae solo información apoyada por el documento. No inventes servicios, precios, claims, ubicaciones ni datos de contacto.\n"
                  . "Si un campo no aparece claro, devuélvelo vacío. Mantén textos útiles pero compactos.\n"
                  . "Devuelve JSON con esta forma exacta: {\"company_name\":\"...\",\"confidence\":\"high|medium|low\",\"fields\":{\"business_description\":\"...\",\"target_audience\":\"...\",\"tone_of_voice\":\"...\",\"services\":\"...\",\"value_proposition\":\"...\",\"unique_selling_points\":\"...\",\"keywords\":\"...\",\"contact_info\":\"...\"},\"notes\":[\"...\"]}.\n"
                  . "Campos disponibles y significado:\n{field_schema}\n"
                  . "Para tone_of_voice usa una opción breve compatible con el selector si encaja: profesional, cercano, premium, técnico, informal.",
                'user_template' =>
                    "Documento:\n---\n{document_text}\n---",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.2,
                    'max_tokens'      => 1200,
                ],
            ],

            self::PROPOSE_LAYOUT_VARIATIONS => [
                'label'        => 'Proponer variaciones de layout',
                'output'       => 'json',
                'required'     => ['page_title', 'sections_layout'],
                'instruction'  =>
                    "Propón exactamente 3 variaciones de layout para una página ya existente, sin rehacer el contenido.\n"
                  . "Devuelve SOLO JSON con esta forma exacta: {\"variations\":[{\"label\":\"...\",\"rationale\":\"...\",\"sections\":[{\"type\":\"...\",\"variant\":\"...\"}]}]}.\n"
                  . "Cada variación debe conservar exactamente el mismo número de secciones y los mismos tipos totales (solo cambia orden y/o variant).\n"
                  . "No inventes tipos nuevos, no elimines ni añadas secciones.\n"
                  . "TIPOS válidos: {available_section_types}.\n"
                  . "Variantes válidas por tipo: {variants_by_type}.\n"
                  . "`label`: máx. 4 palabras. `rationale`: 1 frase breve útil para UX.",
                'user_template' =>
                    "Título de la página: \"{page_title}\"\n"
                  . "Objetivo de la página: {page_goal}\n"
                  . "Secciones actuales (orden actual):\n{sections_layout}\n"
                  . "Contexto opcional:\n{extra_context}",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.45,
                    'max_tokens'      => 900,
                ],
            ],

            // D-Slice 1 (S1.5) — Inferencia del vector de personalidad de marca a
            // partir del texto del negocio. Devuelve los 4 ejes skin + sector +
            // confianza en una sola llamada (cero coste extra vs hacer 2).
            self::INFER_BRAND_PERSONALITY => [
                'label'        => 'Inferir personalidad de marca',
                'output'       => 'json',
                'required'     => ['business_context'],
                'instruction'  =>
                    "Eres un analista de identidad de marca. Lee el contexto de un negocio y devuelve un JSON con su personalidad visual en 4 ejes numéricos (0.0 a 1.0).\n\n"
                  . "FORMATO DE SALIDA (obligatorio): un único objeto JSON, sin texto antes/después, sin markdown.\n"
                  . "Schema:\n"
                  . "{\n"
                  . "  \"warmth\":     <float 0.0-1.0>,   // 0=frío clínico, 1=cálido humano\n"
                  . "  \"formality\":  <float 0.0-1.0>,   // 0=casual lúdico, 1=institucional formal\n"
                  . "  \"modernity\":  <float 0.0-1.0>,   // 0=clásico atemporal, 1=vanguardista\n"
                  . "  \"energy\":     <float 0.0-1.0>,   // 0=sereno calmado, 1=vibrante contundente\n"
                  . "  \"inferred_sector\": <string>,     // una palabra o frase corta describiendo el sector (ej: \"clinica-dental\", \"cafe-especialidad\", \"agencia-creativa\", \"despacho-legal\")\n"
                  . "  \"confidence\": \"high\" | \"medium\" | \"low\",\n"
                  . "  \"rationale\":  <string máx 200 chars>  // 1-2 frases explicando los valores\n"
                  . "}\n\n"
                  . "GUÍAS DE INTERPRETACIÓN:\n"
                  . "- warmth: léxico cercano/familiar/artesanal → ALTO. Léxico técnico/preciso/eficiente → BAJO.\n"
                  . "- formality: tradición/premium/institucional → ALTO. Tuteo/cercanía/divertido → BAJO.\n"
                  . "- modernity: IA/innovación/digital/disrupción → ALTO. Generaciones/oficio/tradición → BAJO.\n"
                  . "- energy: audaz/potente/líder/explosivo → ALTO. Sereno/equilibrio/mindful/calma → BAJO.\n\n"
                  . "REGLAS:\n"
                  . "- Los 4 ejes son INDEPENDIENTES. Un negocio puede ser cálido + formal (lujo artesanal) o frío + casual (startup minimalista).\n"
                  . "- Si el contexto es muy vago, devuelve `confidence: low` y valores cercanos a 0.5.\n"
                  . "- `inferred_sector`: prefiere kebab-case (ej: \"cafe-especialidad\", no \"Café de Especialidad\").\n"
                  . "- NO inventes información que no esté en el contexto. NO añadas campos al JSON.",
                'user_template' =>
                    "CONTEXTO DEL NEGOCIO:\n{business_context}\n\n"
                  . "Devuelve el JSON de personalidad.",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.2,
                    'max_tokens'      => 400,
                ],
            ],

            // E-GDPR G3 — Generación de páginas legales (privacidad, cookies,
            // aviso legal) con NO INVENTAR datos. Si falta cualquier dato real
            // del responsable, tracking, processors, plazos… la IA debe escribir
            // textualmente `TODO-LEGAL: descripción` en lugar de inventar.
            self::GENERATE_LEGAL_PAGE => [
                'label'        => 'Generar página legal',
                'output'       => 'json',
                'required'     => ['legal_page_type', 'controller_data', 'page_language'],
                'instruction'  =>
                    "Eres un especialista en textos legales web para España y la UE (RGPD + LSSI). "
                  . "Tu objetivo: redactar el cuerpo de la página de \"{legal_page_type}\" con un texto claro, preciso y plano, "
                  . "que respete escrupulosamente los datos reales aportados.\n\n"
                  . "FORMATO DE SALIDA (obligatorio): un único objeto JSON sin texto antes/después, sin markdown.\n"
                  . "Schema:\n"
                  . "{\n"
                  . "  \"title\": \"título de la página\",\n"
                  . "  \"blocks\": [\n"
                  . "    {\"type\":\"paragraph\",\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":2,\"text\":\"...\"},\n"
                  . "    {\"type\":\"heading\",\"level\":3,\"text\":\"...\"},\n"
                  . "    {\"type\":\"list\",\"style\":\"unordered\",\"items\":[\"...\",\"...\"]},\n"
                  . "    {\"type\":\"divider\"}\n"
                  . "  ]\n"
                  . "}\n\n"
                  . "REGLA CRÍTICA — NUNCA INVENTAR DATOS LEGALES:\n"
                  . "- Si falta cualquier dato concreto (NIF/CIF, dirección, plazo de conservación, base jurídica, processors, transferencias internacionales, datos del DPO, etc.), escribe literalmente `TODO-LEGAL: descripción del dato que falta` dentro del texto, en lugar de inventar un valor o cláusula.\n"
                  . "- NO declares que el sitio cumple la ley, ni que está \"certificado\", \"verificado\" o \"garantizado\".\n"
                  . "- NO listes processors (Google Analytics, Meta, etc.), cookies o transferencias internacionales que no estén en la información dada.\n"
                  . "- NO omitas processors o tratamientos que SÍ están en la información proporcionada.\n"
                  . "- Si la información aportada está incompleta, el texto puede tener varios `TODO-LEGAL:` — el usuario los completará a mano luego.\n\n"
                  . "ESTILO:\n"
                  . "- Lenguaje claro y plano, no recargado. Frases cortas. Sin formalismos innecesarios.\n"
                  . "- 1ª persona del plural cuando el sujeto es el responsable (\"Recopilamos…\", \"Usamos…\").\n"
                  . "- 2ª persona cuando te diriges al visitante (\"Tus datos…\", \"Puedes ejercer…\").\n"
                  . "- NO uses markdown, HTML, ni adornos. Texto plano en cada `text`.\n"
                  . "- NO uses H1 (el título ya se renderiza arriba). Solo H2/H3.\n\n"
                  . "ESTRUCTURA ESPERADA POR TIPO:\n"
                  . "- privacy_policy: 1) Responsable del tratamiento  2) Finalidades  3) Base jurídica  4) Categorías de datos  5) Destinatarios / processors  6) Transferencias internacionales (si aplica)  7) Plazos de conservación  8) Derechos del usuario y cómo ejercerlos  9) Autoridad de control (AEPD para España).\n"
                  . "- cookie_policy: 1) Qué son las cookies  2) Categorías que usamos (Necesarias, Analítica, Personalización, Marketing, Multimedia externa)  3) Lista de servicios concretos (de la información dada)  4) Cómo gestionarlas / revocar consentimiento  5) Más información.\n"
                  . "- legal_notice: 1) Datos identificativos (LSSI art. 10)  2) Objeto del sitio  3) Propiedad intelectual  4) Responsabilidad  5) Legislación aplicable y jurisdicción.\n\n"
                  . "IDIOMA: Redacta toda la salida en {page_language}.",
                'user_template' =>
                    "Tipo de página legal a generar: {legal_page_type}\n\n"
                  . "DATOS DEL RESPONSABLE (lo que sabemos):\n{controller_data}\n\n"
                  . "CARACTERÍSTICAS DEL SITIO:\n{site_features}\n\n"
                  . "SERVICIOS DE TRACKING ACTIVOS:\n{tracking_services}\n\n"
                  . "FORMULARIOS DE LA WEB:\n{forms_list}\n\n"
                  . "PROCESSORS / TERCEROS DECLARADOS:\n{processors_list}\n\n"
                  . "Recuerda: cualquier dato que NO esté arriba → `TODO-LEGAL: ...`.",
                'options'      => [
                    'response_format' => 'json',
                    'temperature'     => 0.3,
                    'max_tokens'      => 3500,
                ],
            ],
        ];
    }

    /** Tarea de creación (modelo principal). */
    public const TIER_MAIN  = 'main';
    /** Tarea auxiliar/corta (modelo auxiliar). */
    public const TIER_LIGHT = 'light';

    /**
     * Clasifica cada acción en un tier. Si la acción no está mapeada, se
     * asume `TIER_MAIN` (más conservador: gasta más, pero no rompe calidad).
     */
    public static function tierOf(string $action): string
    {
        return match ($action) {
            self::REWRITE_TEXT,
            self::IMPROVE_SEO,
            self::DISCOVER_PAGE_OPPORTUNITIES,
            self::GENERATE_PAGE_BRIEF,
            self::ANALYZE_SITE_ARCHITECTURE,
            self::EXTRACT_BUSINESS_PROFILE,
            self::PROPOSE_LAYOUT_VARIATIONS => self::TIER_LIGHT,
            self::SUGGEST_RELATED_ARTICLES => self::TIER_LIGHT,
            self::INFER_BRAND_PERSONALITY  => self::TIER_LIGHT,
            default                               => self::TIER_MAIN,
        };
    }

    /** @return array<string,mixed>|null */
    public static function get(string $action): ?array
    {
        return self::all()[$action] ?? null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
