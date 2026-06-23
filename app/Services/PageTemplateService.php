<?php

namespace App\Services;

/**
 * T18.3 — Catálogo de plantillas de página declarativas (JSON).
 *
 * Una plantilla es un preset de secuencia de secciones (tipo + variante +
 * `image_query` opcional) que puede usarse para crear páginas listas para
 * que la IA solo tenga que rellenar el contenido.
 *
 * Las plantillas viven como JSON en `config/page_templates/*.json`.
 * Schema esperado:
 *   {
 *     "slug": "service-pro",
 *     "label": "Servicio profesional",
 *     "page_type": "landing|home|contact|generic",
 *     "description": "…",
 *     "suggested_palette": "primary|accent",
 *     "needs_images": ["hero", "gallery"],
 *     "sections": [
 *       { "type": "hero", "variant": "split", "image_query": "…" },
 *       …
 *     ]
 *   }
 *
 * Los thumbnails se renderizan dinámicamente vía `renderThumbnailSvg()` a
 * partir de la secuencia de secciones — así nunca se desincronizan respecto
 * al JSON real y no hay que mantener 15 SVGs separados a mano.
 */
final class PageTemplateService
{
    /** @var array<string,array>|null cache por request */
    private static ?array $cache = null;

    /**
     * Devuelve todas las plantillas válidas del catálogo, indexadas por slug.
     * Las inválidas se descartan silenciosamente (con `error_log` para diagnóstico).
     */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        $dir = self::dir();
        $out = [];
        if (!is_dir($dir)) {
            self::$cache = [];
            return $out;
        }

        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) continue;
            $tpl = json_decode($raw, true);
            if (!is_array($tpl)) {
                error_log('[PageTemplateService] JSON inválido: ' . $file);
                continue;
            }
            $errors = self::validate($tpl);
            if (!empty($errors)) {
                error_log('[PageTemplateService] plantilla descartada (' . basename($file) . '): ' . implode('; ', $errors));
                continue;
            }
            $out[$tpl['slug']] = $tpl;
        }

        self::$cache = $out;
        return $out;
    }

    /** Devuelve una plantilla por slug, o null. */
    public static function get(string $slug): ?array
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    /**
     * Valida una plantilla contra el schema esperado y contra los tipos/variantes
     * declarados en SectionSchemas. Devuelve array de errores (vacío = válida).
     *
     * @return string[]
     */
    public static function validate(array $tpl): array
    {
        $errors = [];

        // Campos obligatorios
        foreach (['slug', 'label', 'page_type', 'description', 'sections'] as $required) {
            if (!isset($tpl[$required]) || $tpl[$required] === '') {
                $errors[] = "Falta campo obligatorio: {$required}";
            }
        }
        if (!empty($errors)) return $errors;

        $slug = (string) $tpl['slug'];
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            $errors[] = "Slug inválido (kebab-case): {$slug}";
        }

        $pageType = (string) $tpl['page_type'];
        $allowedPageTypes = ['home', 'landing', 'contact', 'about', 'service', 'generic'];
        if (!in_array($pageType, $allowedPageTypes, true)) {
            $errors[] = "page_type inválido '{$pageType}' (permitidos: " . implode(', ', $allowedPageTypes) . ')';
        }

        if (!is_array($tpl['sections']) || empty($tpl['sections'])) {
            $errors[] = 'sections debe ser un array no vacío';
            return $errors;
        }

        // Visual style y palette preset opcionales — validados contra los catálogos.
        if (isset($tpl['visual_style']) && (string) $tpl['visual_style'] !== '') {
            $vs = (string) $tpl['visual_style'];
            if (!VisualStyleService::get($vs)) {
                $errors[] = "visual_style desconocido: '{$vs}'";
            }
        }
        if (isset($tpl['palette_preset']) && (string) $tpl['palette_preset'] !== '') {
            $pp = (string) $tpl['palette_preset'];
            if (!PalettePresets::get($pp)) {
                $errors[] = "palette_preset desconocido: '{$pp}'";
            }
        }

        // Validar cada sección contra el catálogo de tipos y variantes.
        $allTypes = SectionSchemas::all();
        foreach ($tpl['sections'] as $idx => $section) {
            if (!is_array($section)) {
                $errors[] = "section[{$idx}] no es un objeto";
                continue;
            }
            $type = (string) ($section['type'] ?? '');
            if ($type === '' || !isset($allTypes[$type])) {
                $errors[] = "section[{$idx}].type desconocido: '{$type}'";
                continue;
            }
            $variant = (string) ($section['variant'] ?? 'default');
            if ($variant !== '' && !SectionSchemas::isValidVariant($type, $variant)) {
                $errors[] = "section[{$idx}].variant '{$variant}' no existe para type '{$type}'";
            }
        }

        return $errors;
    }

    /**
     * Renderiza un thumbnail SVG abstracto a partir de la secuencia de secciones.
     * Cada tipo se mapea a una "fila" con altura/decorado característicos —
     * suficiente para que el usuario distinga plantillas en una galería.
     *
     * Devuelve un SVG completo (string), seguro para inyectar tal cual en HTML.
     */
    public static function renderThumbnailSvg(array $tpl, int $width = 320, int $height = 200): string
    {
        $sections = is_array($tpl['sections'] ?? null) ? $tpl['sections'] : [];
        if (empty($sections)) {
            return self::emptyThumb($width, $height);
        }

        // Mapa tipo → altura proporcional + render hint.
        // Los hint son patrones simples (rect, grid, columns) que se pintan dentro de la fila.
        $rows = [];
        foreach ($sections as $s) {
            $type = (string) ($s['type'] ?? 'generic');
            $variant = (string) ($s['variant'] ?? 'default');
            $rows[] = ['type' => $type, 'variant' => $variant, 'h' => self::rowHeight($type)];
        }

        // Escalar para que la suma de alturas + gaps quepa en $height (con padding).
        $padX = 14; $padY = 12; $gap = 6;
        $availH = $height - 2 * $padY - $gap * (count($rows) - 1);
        $sumH = array_sum(array_column($rows, 'h'));
        if ($sumH <= 0) return self::emptyThumb($width, $height);
        $scale = $availH / $sumH;

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . htmlspecialchars((string)($tpl['label'] ?? ''), ENT_QUOTES, 'UTF-8') . ' (vista previa)">';
        $svg .= '<rect width="100%" height="100%" rx="10" fill="#f8fafc"/>';

        $y = $padY;
        $w = $width - 2 * $padX;
        foreach ($rows as $row) {
            $rowH = max(8, (int) round($row['h'] * $scale));
            $svg .= self::renderRow($row['type'], $row['variant'], $padX, $y, $w, $rowH);
            $y += $rowH + $gap;
        }
        $svg .= '</svg>';
        return $svg;
    }

    /** Limpia el cache (útil en tests). */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Construye contenido placeholder representativo para previsualizar una
     * sección sin tener que llamar a la IA. Usa frases reales en español,
     * imágenes desde picsum.photos con seed estable y nombres profesionales
     * (sin "John Doe"). Cada llamada con el mismo type+seed devuelve lo mismo
     * — los previews no fluctúan.
     */
    public static function placeholderContent(string $type, string $seed = 'demo'): array
    {
        $img = static fn(string $tag, int $w = 1200, int $h = 800): string =>
            'https://picsum.photos/seed/' . urlencode($seed . '-' . $tag) . '/' . $w . '/' . $h;

        return match ($type) {
            'hero' => [
                'eyebrow'    => 'Estudio de diseño',
                'heading'    => 'Diseñamos lo que tu negocio necesita decir',
                'subheading' => 'Marcas, sitios y experiencias que conectan con tu audiencia y mueven los números que importan.',
                'cta_text'   => 'Pedir presupuesto',
                'cta_url'    => '/contacto',
                'cta_text_secondary' => 'Ver casos',
                'cta_url_secondary'  => '/portfolio',
                'image_url'        => $img('hero', 1200, 900),
                'background_image' => $img('hero-bg', 1600, 900),
            ],

            'text_image' => [
                'heading'    => 'Trabajamos contigo, no para ti',
                'body'       => "Empezamos entendiendo tu negocio en una sesión inicial sin compromiso. Después diseñamos un plan a medida con entregables claros y plazos realistas.\n\nNada de procesos opacos: tendrás visibilidad de cada paso y un único punto de contacto durante todo el proyecto.",
                'image_url'  => $img('text-image', 1200, 900),
                'image_side' => 'right',
                'cta_text'   => 'Conoce el método',
                'cta_url'    => '/proceso',
            ],

            'benefits' => [
                'heading'    => 'Por qué elegirnos',
                'subheading' => 'Tres cosas que nos diferencian de la competencia y que vas a notar desde la primera reunión.',
                'items' => [
                    ['icon' => 'rocket', 'title' => 'Lanzamientos rápidos',  'description' => 'Cerramos proyectos en 4-6 semanas con sprints semanales y demos los viernes.'],
                    ['icon' => 'shield', 'title' => 'Cero sorpresas',         'description' => 'Presupuesto cerrado, sin extras ni horas escondidas. Lo que ves es lo que pagas.'],
                    ['icon' => 'chart',  'title' => 'Resultados medibles',    'description' => 'Cada entregable trae métricas asociadas: tráfico, leads, conversión, lo que importe.'],
                    ['icon' => 'heart',  'title' => 'Soporte cercano',        'description' => 'Slack compartido durante 90 días. Respondemos en horas, no en días.'],
                ],
            ],

            'testimonials' => [
                'heading'    => 'Lo que dicen quienes ya trabajaron con nosotros',
                'subheading' => '',
                'items' => [
                    ['quote' => 'Subimos las conversiones un 47% en tres meses. Pasamos de 80 leads/mes a más de 200 sin tocar el presupuesto en ads.', 'author' => 'Marta Iribarren', 'role' => 'Directora comercial · Vitalium', 'avatar_url' => $img('avatar-1', 200, 200)],
                    ['quote' => 'Lo que más me gustó: explicaron las decisiones técnicas sin hacerme sentir tonto. Y el resultado se ve.',                     'author' => 'David Calleja',   'role' => 'Fundador · Heredera Café',     'avatar_url' => $img('avatar-2', 200, 200)],
                    ['quote' => 'Plazos cumplidos al día. Increíble en este sector. Hemos repetido para tres proyectos más.',                              'author' => 'Lucía Rentero',   'role' => 'CMO · Norta Estudio',          'avatar_url' => $img('avatar-3', 200, 200)],
                ],
            ],

            'stats' => [
                'heading'    => 'Lo que conseguimos en 2025',
                'subheading' => '',
                'items' => [
                    ['value' => '128', 'suffix' => '',   'label' => 'Proyectos entregados'],
                    ['value' => '47',  'suffix' => '%',  'label' => 'Aumento medio en leads'],
                    ['value' => '4.9', 'suffix' => '/5', 'label' => 'Satisfacción de clientes'],
                    ['value' => '12',  'suffix' => '',   'label' => 'Países atendidos'],
                ],
            ],

            'gallery' => [
                'heading'    => 'Trabajos recientes',
                'subheading' => '',
                'items' => [
                    ['image_url' => $img('g1', 1000, 750), 'caption' => 'Vitalium · rebranding completo', 'alt' => 'Web de Vitalium en pantalla'],
                    ['image_url' => $img('g2', 800, 800),  'caption' => 'Norta Estudio · sistema visual', 'alt' => 'Identidad de Norta'],
                    ['image_url' => $img('g3', 1000, 700), 'caption' => 'Heredera Café · packaging',     'alt' => 'Packaging de Heredera Café'],
                    ['image_url' => $img('g4', 900, 900),  'caption' => 'Aero Logística · landing',      'alt' => 'Landing de Aero'],
                    ['image_url' => $img('g5', 1100, 700), 'caption' => 'Plantío Wines · e-commerce',    'alt' => 'Tienda de Plantío'],
                ],
            ],

            'steps' => [
                'heading'    => 'Cómo trabajamos',
                'subheading' => 'Un proceso claro de cuatro fases. Cada una con entregables y revisión conjunta.',
                'items' => [
                    ['title' => 'Reunión inicial', 'description' => 'Una hora para entender objetivos, audiencia y restricciones.'],
                    ['title' => 'Propuesta',       'description' => 'Te enviamos plan, calendario y presupuesto cerrado en 48h.'],
                    ['title' => 'Producción',      'description' => 'Sprints semanales con demos y retroalimentación.'],
                    ['title' => 'Entrega',         'description' => 'Lanzamiento, formación al equipo y 90 días de soporte incluido.'],
                ],
            ],

            'logos_strip' => [
                'heading' => 'Confían en nosotros',
                'items' => [
                    ['logo_url' => $img('logo1', 200, 80), 'name' => 'Vitalium',  'link_url' => ''],
                    ['logo_url' => $img('logo2', 200, 80), 'name' => 'Heredera',  'link_url' => ''],
                    ['logo_url' => $img('logo3', 200, 80), 'name' => 'Norta',     'link_url' => ''],
                    ['logo_url' => $img('logo4', 200, 80), 'name' => 'Aero',      'link_url' => ''],
                    ['logo_url' => $img('logo5', 200, 80), 'name' => 'Plantío',   'link_url' => ''],
                    ['logo_url' => $img('logo6', 200, 80), 'name' => 'Bermejo',   'link_url' => ''],
                ],
            ],

            'pricing' => [
                'heading'    => 'Planes y precios',
                'subheading' => 'Tres opciones para todos los tamaños. Sin permanencia ni costes ocultos.',
                'items' => [
                    [
                        'plan_name' => 'Inicio', 'price' => '890€', 'period' => '/proyecto',
                        'description' => 'Para emprendedores y proyectos puntuales.',
                        'features'  => "Web one-page\nDiseño responsive\nFormulario de contacto\nEntrega en 2 semanas",
                        'cta_text'  => 'Empezar', 'cta_url' => '/contacto', 'highlighted' => '0',
                    ],
                    [
                        'plan_name' => 'Profesional', 'price' => '2.490€', 'period' => '/proyecto',
                        'description' => 'Lo más elegido por PYMES.',
                        'features'  => "Hasta 8 páginas\nIdentidad visual\nIntegración CRM\nSEO técnico\nSoporte 90 días",
                        'cta_text'  => 'Reservar plaza', 'cta_url' => '/contacto', 'highlighted' => '1',
                    ],
                    [
                        'plan_name' => 'A medida', 'price' => 'Desde 6.000€', 'period' => '',
                        'description' => 'Para proyectos complejos o multi-idioma.',
                        'features'  => "Sin límite de páginas\nMulti-idioma\nIntegraciones a medida\nGestor dedicado\nSoporte premium",
                        'cta_text'  => 'Hablar', 'cta_url' => '/contacto', 'highlighted' => '0',
                    ],
                ],
            ],

            'faq' => [
                'heading' => 'Preguntas frecuentes',
                'items' => [
                    ['question' => '¿Cuánto tarda un proyecto?',       'answer' => 'Entre 2 y 8 semanas según alcance. Te damos un calendario cerrado en la propuesta.'],
                    ['question' => '¿El precio incluye el dominio?',   'answer' => 'No, pero te ayudamos a registrarlo y configurarlo. El coste anual lo pagas tú directamente al registrador.'],
                    ['question' => '¿Puedo editar la web yo mismo?',   'answer' => 'Sí. La entregamos con un panel sencillo y te formamos en una sesión de 1h.'],
                    ['question' => '¿Qué pasa si no me convence?',     'answer' => 'En las dos primeras semanas devolvemos el 100% sin preguntas. Después prorrateamos según trabajo entregado.'],
                    ['question' => '¿Trabajáis con clientes fuera de España?', 'answer' => 'Sí. Tenemos clientes en LATAM y Europa. Las reuniones se ajustan a tu zona horaria.'],
                    ['question' => '¿Hacéis sólo diseño o también texto?',     'answer' => 'Ambos. Nuestro equipo redacta los textos a partir de tu información, y tú validas antes de publicar.'],
                ],
            ],

            'cta' => [
                'heading'     => '¿Empezamos a hablar?',
                'description' => 'Una llamada de 30 minutos sin compromiso. Te decimos qué haríamos y cuánto costaría.',
                'cta_text'    => 'Reservar llamada',
                'cta_url'     => '/contacto',
            ],

            'form' => [
                'heading'         => 'Cuéntanos sobre tu proyecto',
                'description'    => 'Te respondemos en menos de 24 horas laborables.',
                'submit_text'    => 'Enviar',
                'success_message' => 'Gracias. Te escribimos pronto.',
                'image_url'      => $img('form-image', 900, 1100),
                'fields' => [
                    ['label' => 'Nombre',   'name' => 'nombre',  'field_type' => 'text',     'required' => '1', 'placeholder' => 'Tu nombre'],
                    ['label' => 'Email',    'name' => 'email',   'field_type' => 'email',    'required' => '1', 'placeholder' => 'tu@email.com'],
                    ['label' => 'Empresa',  'name' => 'empresa', 'field_type' => 'text',     'required' => '0', 'placeholder' => 'Empresa (opcional)'],
                    ['label' => 'Mensaje',  'name' => 'mensaje', 'field_type' => 'textarea', 'required' => '1', 'placeholder' => 'Cuéntanos qué necesitas'],
                ],
            ],

            default => [],
        };
    }

    // ======================================================================
    // Helpers privados
    // ======================================================================

    private static function dir(): string
    {
        // app/Services/PageTemplateService.php → ../../config/page_templates
        return dirname(__DIR__, 2) . '/config/page_templates';
    }

    private static function rowHeight(string $type): int
    {
        return match ($type) {
            'hero'         => 60,
            'gallery'      => 44,
            'pricing'      => 52,
            'cta'          => 30,
            'form'         => 48,
            'testimonials' => 36,
            'benefits'     => 36,
            'stats'        => 24,
            'steps'        => 38,
            'logos_strip'  => 16,
            'faq'          => 30,
            'text_image'   => 36,
            default        => 28,
        };
    }

    /** Pinta una "fila" estilizada en el SVG según tipo+variante. */
    private static function renderRow(string $type, string $variant, int $x, int $y, int $w, int $h): string
    {
        $primary = '#6366f1';
        $muted   = '#94a3b8';
        $faint   = '#cbd5e1';
        $surface = '#ffffff';
        $border  = '#e2e8f0';

        $out = '';

        switch ($type) {
            case 'hero':
                if ($variant === 'split') {
                    $half = (int) (($w - 8) / 2);
                    $out .= self::svgRect($x, $y, $half, $h, $surface, $border);
                    $out .= self::svgBar($x + 8, $y + 10, (int) ($half * .55), 5, $muted);
                    $out .= self::svgBar($x + 8, $y + 20, (int) ($half * .35), 4, $faint);
                    $out .= self::svgBar($x + 8, $y + $h - 14, 28, 7, $primary);
                    $out .= self::svgRect($x + $half + 8, $y, $half, $h, $muted, $border);
                } elseif ($variant === 'with-image-bg') {
                    $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="6" fill="url(#g_hero)"/>';
                    $out .= '<defs><linearGradient id="g_hero" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1e293b"/><stop offset="1" stop-color="#475569"/></linearGradient></defs>';
                    $out .= self::svgBar($x + 12, $y + $h - 22, (int) ($w * .5), 6, '#fff');
                    $out .= self::svgBar($x + 12, $y + $h - 12, (int) ($w * .3), 4, 'rgba(255,255,255,.7)');
                } else {
                    $out .= self::svgRect($x, $y, $w, $h, $surface, $border);
                    $out .= self::svgBar($x + (int) ($w * .25), $y + 14, (int) ($w * .5), 7, $muted);
                    $out .= self::svgBar($x + (int) ($w * .35), $y + 26, (int) ($w * .3), 4, $faint);
                    $out .= self::svgBar($x + (int) (($w - 28) / 2), $y + $h - 14, 28, 7, $primary);
                }
                break;

            case 'text_image':
                $half = (int) (($w - 8) / 2);
                $textW = $variant === 'wide-media' ? (int) ($w * .35) : $half;
                $imgW  = $w - $textW - 8;
                $out .= self::svgRect($x, $y, $textW, $h, $surface, $border);
                $out .= self::svgBar($x + 6, $y + 8, (int) ($textW * .65), 5, $muted);
                $out .= self::svgBar($x + 6, $y + 18, (int) ($textW * .85), 3, $faint);
                $out .= self::svgBar($x + 6, $y + 24, (int) ($textW * .7), 3, $faint);
                $out .= self::svgRect($x + $textW + 8, $y, $imgW, $h, $muted, $border);
                break;

            case 'benefits':
                $cols = 3; $gap = 4;
                $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                for ($i = 0; $i < $cols; $i++) {
                    $cx = $x + $i * ($colW + $gap);
                    if ($variant === 'numbered') {
                        $out .= '<rect x="' . $cx . '" y="' . ($y + 4) . '" width="' . $colW . '" height="2" fill="' . $faint . '"/>';
                        $out .= self::svgBar($cx, $y + 10, 14, 7, $primary);
                        $out .= self::svgBar($cx, $y + 22, (int) ($colW * .8), 3, $faint);
                        $out .= self::svgBar($cx, $y + 28, (int) ($colW * .6), 3, $faint);
                    } else {
                        $out .= self::svgRect($cx, $y, $colW, $h, $surface, $border);
                        $out .= '<circle cx="' . ($cx + 10) . '" cy="' . ($y + 10) . '" r="5" fill="' . $primary . '"/>';
                        $out .= self::svgBar($cx + 6, $y + 20, (int) ($colW * .7), 3, $muted);
                        $out .= self::svgBar($cx + 6, $y + 26, (int) ($colW * .5), 3, $faint);
                    }
                }
                break;

            case 'testimonials':
                if ($variant === 'featured-quote') {
                    $out .= self::svgBar($x + (int) ($w * .15), $y + 6, (int) ($w * .7), 6, $muted);
                    $out .= self::svgBar($x + (int) ($w * .25), $y + 16, (int) ($w * .5), 5, $faint);
                    $out .= '<circle cx="' . ($x + (int) ($w / 2)) . '" cy="' . ($y + $h - 8) . '" r="4" fill="' . $primary . '"/>';
                } else {
                    $cols = 3; $gap = 4;
                    $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                    for ($i = 0; $i < $cols; $i++) {
                        $cx = $x + $i * ($colW + $gap);
                        $out .= self::svgRect($cx, $y, $colW, $h, $surface, $border);
                        $out .= self::svgBar($cx + 6, $y + 6, (int) ($colW * .8), 3, $faint);
                        $out .= self::svgBar($cx + 6, $y + 12, (int) ($colW * .6), 3, $faint);
                        $out .= '<circle cx="' . ($cx + 10) . '" cy="' . ($y + $h - 8) . '" r="3" fill="' . $primary . '"/>';
                    }
                }
                break;

            case 'stats':
                $cols = 4; $gap = 2;
                $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                for ($i = 0; $i < $cols; $i++) {
                    $cx = $x + $i * ($colW + $gap);
                    if ($variant === 'inline-bar' && $i > 0) {
                        $out .= '<line x1="' . $cx . '" y1="' . $y . '" x2="' . $cx . '" y2="' . ($y + $h) . '" stroke="' . $border . '" stroke-width="1"/>';
                    }
                    $out .= self::svgBar($cx + (int) ($colW / 4), $y + 4, (int) ($colW / 2), 8, $primary);
                    $out .= self::svgBar($cx + (int) ($colW / 3), $y + 16, (int) ($colW / 3), 3, $faint);
                }
                break;

            case 'gallery':
                if ($variant === 'mosaic') {
                    $bigW = (int) ($w * .55) - 4;
                    $smW = $w - $bigW - 8;
                    $smH = (int) (($h - 4) / 2);
                    $out .= self::svgRect($x, $y, $bigW, $h, $muted, $border);
                    $out .= self::svgRect($x + $bigW + 8, $y, $smW, $smH, $faint, $border);
                    $out .= self::svgRect($x + $bigW + 8, $y + $smH + 4, $smW, $smH, $faint, $border);
                } else {
                    $cols = 4; $gap = 3;
                    $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                    for ($i = 0; $i < $cols; $i++) {
                        $cx = $x + $i * ($colW + $gap);
                        $out .= self::svgRect($cx, $y, $colW, $h, $muted, $border);
                    }
                }
                break;

            case 'steps':
                if ($variant === 'horizontal') {
                    $cols = 4; $gap = 6;
                    $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                    for ($i = 0; $i < $cols; $i++) {
                        $cx = $x + $i * ($colW + $gap);
                        $out .= '<circle cx="' . ($cx + 8) . '" cy="' . ($y + 8) . '" r="5" fill="' . $primary . '"/>';
                        if ($i < $cols - 1) {
                            $out .= '<line x1="' . ($cx + 14) . '" y1="' . ($y + 8) . '" x2="' . ($cx + $colW + $gap) . '" y2="' . ($y + 8) . '" stroke="#c7d2fe" stroke-width="2"/>';
                        }
                        $out .= self::svgBar($cx, $y + 18, (int) ($colW * .8), 4, $faint);
                        $out .= self::svgBar($cx, $y + 26, (int) ($colW * .6), 3, $faint);
                    }
                } else {
                    for ($i = 0; $i < 3; $i++) {
                        $ry = $y + $i * 12;
                        $out .= '<circle cx="' . ($x + 8) . '" cy="' . ($ry + 4) . '" r="4" fill="' . $primary . '"/>';
                        if ($i < 2) {
                            $out .= '<line x1="' . ($x + 8) . '" y1="' . ($ry + 8) . '" x2="' . ($x + 8) . '" y2="' . ($ry + 16) . '" stroke="#c7d2fe" stroke-width="2"/>';
                        }
                        $out .= self::svgBar($x + 18, $ry + 1, (int) ($w * .55), 4, $muted);
                        $out .= self::svgBar($x + 18, $ry + 7, (int) ($w * .35), 3, $faint);
                    }
                }
                break;

            case 'logos_strip':
                $cols = 6; $gap = 6;
                $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                for ($i = 0; $i < $cols; $i++) {
                    $cx = $x + $i * ($colW + $gap);
                    $out .= self::svgRect($cx, $y + 2, $colW, $h - 4, $faint, $border);
                }
                if ($variant === 'marquee') {
                    $out .= '<rect x="' . $x . '" y="' . $y . '" width="14" height="' . $h . '" fill="#f8fafc"/>';
                    $out .= '<rect x="' . ($x + $w - 14) . '" y="' . $y . '" width="14" height="' . $h . '" fill="#f8fafc"/>';
                }
                break;

            case 'pricing':
                $cols = 3; $gap = 4;
                $colW = (int) (($w - $gap * ($cols - 1)) / $cols);
                for ($i = 0; $i < $cols; $i++) {
                    $cx = $x + $i * ($colW + $gap);
                    $highlight = $i === 1;
                    $fill = $highlight ? '#eef2ff' : $surface;
                    $stroke = $highlight ? $primary : $border;
                    $out .= self::svgRect($cx, $y, $colW, $h, $fill, $stroke);
                    $out .= self::svgBar($cx + 6, $y + 6, (int) ($colW * .4), 4, $muted);
                    $out .= self::svgBar($cx + 6, $y + 14, (int) ($colW * .55), 8, $highlight ? $primary : '#475569');
                    $out .= self::svgBar($cx + 6, $y + 28, (int) ($colW * .8), 3, $faint);
                    $out .= self::svgBar($cx + 6, $y + 34, (int) ($colW * .6), 3, $faint);
                    $out .= self::svgBar($cx + 6, $y + $h - 10, (int) ($colW * .85), 6, $highlight ? $primary : $faint);
                }
                break;

            case 'faq':
                if ($variant === 'two-columns') {
                    $half = (int) (($w - 6) / 2);
                    for ($i = 0; $i < 2; $i++) {
                        $cx = $x + $i * ($half + 6);
                        $out .= self::svgBar($cx, $y + 4, (int) ($half * .8), 4, $muted);
                        $out .= self::svgBar($cx, $y + 14, (int) ($half * .85), 4, $muted);
                        $out .= self::svgBar($cx, $y + 24, (int) ($half * .7), 4, $muted);
                    }
                } else {
                    for ($i = 0; $i < 3; $i++) {
                        $ry = $y + $i * 10;
                        $out .= '<line x1="' . $x . '" y1="' . $ry . '" x2="' . ($x + $w) . '" y2="' . $ry . '" stroke="' . $border . '" stroke-width="1"/>';
                        $out .= self::svgBar($x, $ry + 3, (int) ($w * .5), 4, $muted);
                    }
                }
                break;

            case 'cta':
                if ($variant === 'card') {
                    $out .= '<rect x="' . ($x + 14) . '" y="' . $y . '" width="' . ($w - 28) . '" height="' . $h . '" rx="8" fill="url(#g_cta)"/>';
                    $out .= '<defs><linearGradient id="g_cta" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $primary . '"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs>';
                    $out .= self::svgBar($x + (int) ($w * .3), $y + 8, (int) ($w * .4), 5, '#fff');
                    $out .= self::svgBar($x + (int) ($w * .4), $y + $h - 12, (int) ($w * .2), 6, '#fff');
                } elseif ($variant === 'split') {
                    $out .= self::svgRect($x, $y, $w, $h, $surface, $border);
                    $out .= self::svgBar($x + 8, $y + (int) (($h - 6) / 2), (int) ($w * .55), 6, $muted);
                    $out .= self::svgBar($x + $w - 60, $y + (int) (($h - 8) / 2), 50, 8, $primary);
                } else {
                    $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="6" fill="url(#g_cta_d)"/>';
                    $out .= '<defs><linearGradient id="g_cta_d" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="' . $primary . '"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs>';
                    $out .= self::svgBar($x + (int) ($w * .25), $y + 6, (int) ($w * .5), 5, '#fff');
                    $out .= self::svgBar($x + (int) ($w * .4), $y + $h - 10, (int) ($w * .2), 6, '#fff');
                }
                break;

            case 'form':
                if ($variant === 'with-side-image') {
                    $half = (int) (($w - 6) / 2);
                    $out .= self::svgRect($x, $y, $half, $h, $muted, $border);
                    $out .= self::svgRect($x + $half + 6, $y, $half, $h, $surface, $border);
                    $out .= self::svgBar($x + $half + 12, $y + 6, (int) ($half * .7), 5, $faint);
                    $out .= self::svgBar($x + $half + 12, $y + 16, (int) ($half * .85), 6, $faint);
                    $out .= self::svgBar($x + $half + 12, $y + 28, (int) ($half * .85), 6, $faint);
                    $out .= self::svgBar($x + $half + 12, $y + $h - 12, 28, 7, $primary);
                } elseif ($variant === 'inline-card') {
                    $out .= self::svgRect($x + 14, $y, $w - 28, $h, $surface, $border);
                    $out .= self::svgBar($x + 22, $y + 8, (int) ($w * .5), 6, $faint);
                    $out .= self::svgBar($x + 22, $y + 20, (int) ($w * .65), 6, $faint);
                    $out .= self::svgBar($x + 22, $y + $h - 12, 28, 7, $primary);
                } else {
                    $out .= self::svgBar($x, $y + 4, (int) ($w * .8), 6, $faint);
                    $out .= self::svgBar($x, $y + 16, (int) ($w * .9), 6, $faint);
                    $out .= self::svgBar($x, $y + 28, (int) ($w * .6), 6, $faint);
                    $out .= self::svgBar($x, $y + $h - 12, 28, 7, $primary);
                }
                break;

            default:
                $out .= self::svgRect($x, $y, $w, $h, $surface, $border);
                break;
        }

        return $out;
    }

    private static function svgRect(int $x, int $y, int $w, int $h, string $fill, string $stroke = ''): string
    {
        $strokeAttr = $stroke ? ' stroke="' . $stroke . '" stroke-width="1"' : '';
        return '<rect x="' . $x . '" y="' . $y . '" width="' . max(0, $w) . '" height="' . max(0, $h) . '" rx="3" fill="' . $fill . '"' . $strokeAttr . '/>';
    }

    private static function svgBar(int $x, int $y, int $w, int $h, string $fill): string
    {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . max(0, $w) . '" height="' . max(0, $h) . '" rx="2" fill="' . $fill . '"/>';
    }

    private static function emptyThumb(int $w, int $h): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="100%"><rect width="100%" height="100%" rx="10" fill="#f8fafc" stroke="#e2e8f0"/></svg>';
    }
}
