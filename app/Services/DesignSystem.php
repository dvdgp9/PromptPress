<?php

namespace App\Services;

use Core\Database;

/**
 * Design system del sitio.
 *
 * Los tokens se almacenan en la tabla `design_system` (T0.3) como JSON por categoría,
 * con unique key (site_id, category). Categorías: colors, typography, buttons, spacing.
 *
 * El mismo conjunto de claves se usa en:
 *   1) el form del admin (T5.1)
 *   2) la previsualización en vivo (T5.2)
 *   3) la generación de CSS variables para páginas públicas (T5.3)
 *
 * Para garantizar coherencia, cada field declara su `css_var` — el nombre de la
 * variable CSS bajo la que se expondrá al front.
 *
 * Tipos de field:
 *   color       input[type=color] + text hex sincronizados
 *   font        select con fuentes populares (Google Fonts + system-ui)
 *   size        input[type=number] con unidad (px, rem)
 *   range       input[type=range] con display numérico y unidad
 *   select      <select> con options
 *   toggle      checkbox (0/1)
 */
final class DesignSystem
{
    public const CATEGORIES = ['colors', 'typography', 'buttons', 'spacing'];

    /** Fuentes curated (Google Fonts con fallback system). */
    public const FONT_OPTIONS = [
        'system'          => 'Sistema (default)',
        'Inter'           => 'Inter',
        'Roboto'          => 'Roboto',
        'Open Sans'       => 'Open Sans',
        'Lato'            => 'Lato',
        'Montserrat'      => 'Montserrat',
        'Poppins'         => 'Poppins',
        'Nunito'          => 'Nunito',
        'Raleway'         => 'Raleway',
        'Work Sans'       => 'Work Sans',
        'Source Sans 3'   => 'Source Sans 3',
        'Playfair Display'=> 'Playfair Display (serif)',
        'Merriweather'    => 'Merriweather (serif)',
        'Lora'            => 'Lora (serif)',
    ];

    /**
     * Schema completo del design system.
     * Retorna array categoría => [label, icon, fields[]].
     */
    public static function schema(): array
    {
        return [
            'colors' => [
                'label' => 'Colores',
                'icon'  => 'palette',
                'hint'  => 'Paleta base. Todos los tokens se expondrán como variables CSS en las páginas públicas.',
                'fields' => [
                    ['key' => 'primary',      'label' => 'Color principal',    'type' => 'color', 'default' => '#6366f1', 'css_var' => '--pp-primary',      'hint' => 'CTA, enlaces, énfasis'],
                    ['key' => 'primary_dark', 'label' => 'Principal oscuro',   'type' => 'color', 'default' => '#4f46e5', 'css_var' => '--pp-primary-dark', 'hint' => 'Hover de CTA'],
                    ['key' => 'secondary',    'label' => 'Secundario',         'type' => 'color', 'default' => '#64748b', 'css_var' => '--pp-secondary'],
                    ['key' => 'accent',       'label' => 'Acento',             'type' => 'color', 'default' => '#f59e0b', 'css_var' => '--pp-accent', 'hint' => 'Highlights, badges especiales'],
                    ['key' => 'bg',           'label' => 'Fondo de página',    'type' => 'color', 'default' => '#ffffff', 'css_var' => '--pp-bg'],
                    ['key' => 'surface',      'label' => 'Fondo de secciones', 'type' => 'color', 'default' => '#f9fafb', 'css_var' => '--pp-surface'],
                    ['key' => 'text',         'label' => 'Texto principal',    'type' => 'color', 'default' => '#1f2937', 'css_var' => '--pp-text'],
                    ['key' => 'text_muted',   'label' => 'Texto secundario',   'type' => 'color', 'default' => '#6b7280', 'css_var' => '--pp-text-muted'],
                    ['key' => 'border',       'label' => 'Bordes',             'type' => 'color', 'default' => '#e5e7eb', 'css_var' => '--pp-border'],
                    ['key' => 'success',      'label' => 'Éxito',              'type' => 'color', 'default' => '#10b981', 'css_var' => '--pp-success'],
                    ['key' => 'danger',       'label' => 'Error / peligro',    'type' => 'color', 'default' => '#ef4444', 'css_var' => '--pp-danger'],
                ],
            ],

            'typography' => [
                'label' => 'Tipografía',
                'icon'  => 'type',
                'hint'  => 'Familias y tamaños de texto. Las Google Fonts se cargarán automáticamente.',
                'fields' => [
                    ['key' => 'font_heading',   'label' => 'Fuente de títulos', 'type' => 'font', 'default' => 'Inter',  'css_var' => '--pp-font-heading'],
                    ['key' => 'font_body',      'label' => 'Fuente de texto',   'type' => 'font', 'default' => 'Inter',  'css_var' => '--pp-font-body'],
                    ['key' => 'base_size',      'label' => 'Tamaño base',       'type' => 'range', 'default' => 16, 'min' => 14, 'max' => 20, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-font-size-base'],
                    ['key' => 'scale_ratio',    'label' => 'Escala de títulos', 'type' => 'select', 'default' => '1.25', 'css_var' => '--pp-font-scale',
                        'options' => ['1.125' => '1.125 · Minor Second', '1.200' => '1.200 · Minor Third', '1.250' => '1.250 · Major Third', '1.333' => '1.333 · Perfect Fourth', '1.414' => '1.414 · Aug. Fourth', '1.500' => '1.500 · Perfect Fifth']],
                    ['key' => 'line_height',    'label' => 'Interlineado',      'type' => 'range', 'default' => 1.5, 'min' => 1.2, 'max' => 2.0, 'step' => 0.1, 'unit' => '', 'css_var' => '--pp-line-height'],
                    ['key' => 'weight_regular', 'label' => 'Peso texto',        'type' => 'select', 'default' => '400', 'css_var' => '--pp-weight-regular',
                        'options' => ['300' => '300 · Light', '400' => '400 · Regular', '500' => '500 · Medium']],
                    ['key' => 'weight_bold',    'label' => 'Peso títulos',      'type' => 'select', 'default' => '700', 'css_var' => '--pp-weight-bold',
                        'options' => ['600' => '600 · Semibold', '700' => '700 · Bold', '800' => '800 · Extrabold', '900' => '900 · Black']],
                ],
            ],

            'buttons' => [
                'label' => 'Botones',
                'icon'  => 'button',
                'hint'  => 'Aspecto de los botones y CTAs.',
                'fields' => [
                    ['key' => 'radius',         'label' => 'Radio',          'type' => 'range', 'default' => 8, 'min' => 0, 'max' => 40, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-btn-radius'],
                    ['key' => 'padding_x',      'label' => 'Padding horiz.', 'type' => 'range', 'default' => 20, 'min' => 10, 'max' => 40, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-btn-padding-x'],
                    ['key' => 'padding_y',      'label' => 'Padding vert.',  'type' => 'range', 'default' => 10, 'min' => 6, 'max' => 24, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-btn-padding-y'],
                    ['key' => 'font_size',      'label' => 'Tamaño texto',   'type' => 'range', 'default' => 15, 'min' => 12, 'max' => 20, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-btn-font-size'],
                    ['key' => 'font_weight',    'label' => 'Peso',           'type' => 'select', 'default' => '600', 'css_var' => '--pp-btn-weight',
                        'options' => ['400' => '400 · Regular', '500' => '500 · Medium', '600' => '600 · Semibold', '700' => '700 · Bold']],
                    ['key' => 'text_transform', 'label' => 'Estilo texto',   'type' => 'select', 'default' => 'none', 'css_var' => '--pp-btn-text-transform',
                        'options' => ['none' => 'Normal', 'uppercase' => 'MAYÚSCULAS', 'lowercase' => 'minúsculas']],
                    ['key' => 'shadow',         'label' => 'Sombra',         'type' => 'select', 'default' => 'sm', 'css_var' => '--pp-btn-shadow',
                        'options' => ['none' => 'Sin sombra', 'sm' => 'Suave', 'md' => 'Media', 'lg' => 'Pronunciada']],
                ],
            ],

            'spacing' => [
                'label' => 'Espaciados',
                'icon'  => 'spacing',
                'hint'  => 'Unidad base y ancho máximo del contenido.',
                'fields' => [
                    ['key' => 'unit',          'label' => 'Unidad base',      'type' => 'range', 'default' => 8, 'min' => 4, 'max' => 16, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-space-unit', 'hint' => 'Múltiplos: xs=0.5, sm=1, md=2, lg=4, xl=8'],
                    ['key' => 'section_y',     'label' => 'Separación secciones', 'type' => 'range', 'default' => 80, 'min' => 20, 'max' => 160, 'step' => 4, 'unit' => 'px', 'css_var' => '--pp-section-y'],
                    ['key' => 'container_max', 'label' => 'Ancho máximo',     'type' => 'range', 'default' => 1200, 'min' => 720, 'max' => 1440, 'step' => 40, 'unit' => 'px', 'css_var' => '--pp-container-max'],
                    ['key' => 'radius_card',   'label' => 'Radio de tarjetas','type' => 'range', 'default' => 12, 'min' => 0, 'max' => 32, 'step' => 1, 'unit' => 'px', 'css_var' => '--pp-radius-card'],
                ],
            ],
        ];
    }

    /**
     * Valores por defecto (merge de todos los `default` del schema).
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::schema() as $cat => $def) {
            $out[$cat] = [];
            foreach ($def['fields'] as $f) {
                $out[$cat][$f['key']] = $f['default'];
            }
        }
        return $out;
    }

    /**
     * Carga tokens desde BD para un site; fallback a defaults si faltan.
     * Devuelve [category => [key => value]].
     */
    public static function load(int $siteId): array
    {
        $rows = Database::select(
            'SELECT category, tokens FROM design_system WHERE site_id = ?',
            [$siteId]
        );
        $defaults = self::defaults();
        foreach ($rows as $r) {
            $decoded = json_decode($r['tokens'], true);
            if (!is_array($decoded)) continue;
            if (!isset($defaults[$r['category']])) continue;
            // Merge: los valores de BD sobreescriben, las keys ausentes quedan con default
            $defaults[$r['category']] = array_merge($defaults[$r['category']], $decoded);
        }
        return $defaults;
    }

    /**
     * Guarda una categoría (UPSERT).
     */
    public static function saveCategory(int $siteId, string $category, array $tokens): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Categoría inválida: $category");
        }
        Database::execute(
            'INSERT INTO design_system (site_id, category, tokens)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tokens = VALUES(tokens)',
            [$siteId, $category, json_encode($tokens, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * Valida y limpia los valores recibidos del form para una categoría.
     * Devuelve [tokens, errors]. Los tokens devueltos están normalizados a sus tipos esperados.
     */
    public static function validateCategory(string $category, array $input): array
    {
        $schema = self::schema()[$category] ?? null;
        if ($schema === null) {
            return [[], ['_category' => 'Categoría desconocida.']];
        }
        $tokens = [];
        $errors = [];
        foreach ($schema['fields'] as $f) {
            $key = $f['key'];
            $raw = $input[$key] ?? null;
            $val = self::normalizeValue($f, $raw);
            $err = self::validateValue($f, $val);
            if ($err !== null) {
                $errors[$key] = $err;
                // Aun así guardamos el valor para re-render
                $tokens[$key] = (string) ($raw ?? $f['default']);
            } else {
                $tokens[$key] = $val;
            }
        }
        return [$tokens, $errors];
    }

    private static function normalizeValue(array $f, $raw)
    {
        if ($raw === null) return $f['default'];
        $raw = is_string($raw) ? trim($raw) : $raw;

        switch ($f['type']) {
            case 'color':
                return is_string($raw) ? strtolower($raw) : $f['default'];
            case 'range':
                $n = is_numeric($raw) ? (float) $raw : $f['default'];
                // Redondear según step si es entero
                if (!empty($f['step']) && fmod($f['step'], 1) == 0) return (int) round($n);
                return $n;
            default:
                return (string) $raw;
        }
    }

    private static function validateValue(array $f, $val): ?string
    {
        switch ($f['type']) {
            case 'color':
                if (!is_string($val) || !preg_match('/^#[0-9a-f]{6}$/i', $val)) {
                    return 'Debe ser un color hexadecimal (#rrggbb).';
                }
                break;
            case 'range':
                if (!is_numeric($val)) return 'Debe ser numérico.';
                if (isset($f['min']) && $val < $f['min']) return 'Valor mínimo ' . $f['min'];
                if (isset($f['max']) && $val > $f['max']) return 'Valor máximo ' . $f['max'];
                break;
            case 'select':
                if (!isset($f['options'][$val])) return 'Valor no válido.';
                break;
            case 'font':
                if (!isset(self::FONT_OPTIONS[$val])) return 'Fuente no reconocida.';
                break;
        }
        return null;
    }

    /**
     * Convierte los tokens cargados en un array plano [css_var => value_with_unit].
     * Usado por T5.2 (preview) y T5.3 (generación CSS).
     */
    public static function toCssVars(array $loadedTokens): array
    {
        $vars = [];
        foreach (self::schema() as $cat => $def) {
            $tokenSet = $loadedTokens[$cat] ?? [];
            foreach ($def['fields'] as $f) {
                if (empty($f['css_var'])) continue;
                $val = $tokenSet[$f['key']] ?? $f['default'];

                // Post-procesado según tipo
                switch ($f['type']) {
                    case 'range':
                        $unit = $f['unit'] ?? '';
                        $val = $val . ($unit === '' ? '' : $unit);
                        break;
                    case 'font':
                        $val = self::fontCssValue((string) $val);
                        break;
                }
                $vars[$f['css_var']] = $val;
            }
        }
        return $vars;
    }

    /**
     * Compone el valor CSS para una fuente (incluye fallback).
     */
    public static function fontCssValue(string $fontKey): string
    {
        $systemStack = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif';
        if ($fontKey === '' || $fontKey === 'system') {
            return $systemStack;
        }
        // Las Google Fonts siempre con fallback a system
        $isSerif = in_array($fontKey, ['Playfair Display', 'Merriweather', 'Lora'], true);
        $fallback = $isSerif ? 'Georgia, "Times New Roman", serif' : $systemStack;
        return '"' . $fontKey . '", ' . $fallback;
    }

    /**
     * Mapeo de tokens de sombra a valores CSS reales.
     *
     * D0b.3 — Migrados de `rgba(0,0,0,...)` literal a `color-mix(in srgb, var(--pp-text) X%, transparent)`
     * para que las sombras se tinten con la tinta del sitio (modelo Skin), no negro neutro.
     */
    public const SHADOW_PRESETS = [
        'none' => 'none',
        'sm'   => '0 1px 2px color-mix(in srgb, var(--pp-text) 6%, transparent), 0 1px 3px color-mix(in srgb, var(--pp-text) 8%, transparent)',
        'md'   => '0 4px 6px color-mix(in srgb, var(--pp-text) 7%, transparent), 0 2px 4px color-mix(in srgb, var(--pp-text) 6%, transparent)',
        'lg'   => '0 10px 15px color-mix(in srgb, var(--pp-text) 10%, transparent), 0 4px 6px color-mix(in srgb, var(--pp-text) 5%, transparent)',
    ];

    // ======================================================================
    // T5.3 — Renderizado para el front público
    // ======================================================================

    /**
     * Devuelve el contenido CSS completo del design system para el sitio dado.
     * Incluye `:root { --pp-*: ...; }` con TODAS las variables listas para usar.
     *
     * Los tokens que no son valores CSS directos (e.g. buttons.shadow = 'sm')
     * se traducen a su preset real.
     */
    public static function renderCssVars(array $loadedTokens): string
    {
        $vars = self::toCssVars($loadedTokens);

        // Shadow preset → valor CSS real
        if (isset($vars['--pp-btn-shadow'])) {
            $preset = (string) $vars['--pp-btn-shadow'];
            $vars['--pp-btn-shadow'] = self::SHADOW_PRESETS[$preset] ?? self::SHADOW_PRESETS['sm'];
        }

        $lines = [];
        foreach ($vars as $name => $value) {
            $lines[] = '    ' . $name . ': ' . $value . ';';
        }
        return ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /**
     * Devuelve el `<link>` de Google Fonts para precargar las fuentes del sitio.
     * Cadena vacía si todas son `system`.
     */
    public static function renderFontsLink(array $loadedTokens): string
    {
        $fonts = self::googleFontsUsed($loadedTokens);
        if (empty($fonts)) return '';

        $families = implode('&family=', array_map(
            fn($f) => str_replace(' ', '+', $f) . ':wght@300;400;500;600;700;800;900',
            $fonts
        ));
        $href = 'https://fonts.googleapis.com/css2?family=' . $families . '&display=swap';
        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
             . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
             . '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Devuelve el bloque completo a inyectar en el `<head>` de las páginas
     * públicas: `<link>` de fuentes + `<style>` con variables CSS.
     *
     * @param int $siteId
     * @return string HTML listo para inyectar
     */
    public static function renderHead(int $siteId, ?string $visualStyleSlug = null, ?string $paletteOverride = null): string
    {
        $tokens = self::load($siteId);

        // D-Slice 1 (S1.7) — Si el sitio tiene `skin_json` compuesto desde el
        // vector de personalidad, lo sobreponemos a los tokens base. El resto
        // de variables (line_height, paddings, container_max, etc.) siguen
        // viniendo de los defaults/diseño manual del usuario.
        $skin = self::loadSkin($siteId);
        if ($skin !== null) {
            $tokens = self::applySkinToTokens($tokens, $skin);
        }

        $fonts  = self::renderFontsLink($tokens);
        $styleSlug = $visualStyleSlug !== null ? VisualStyleService::normalizeSlug($visualStyleSlug) : null;
        $styleFonts = $styleSlug !== null ? VisualStyleService::fontsLink($styleSlug) : '';

        // Si hay skin compuesto, no aplicamos VisualStyle (entrarían en conflicto).
        $styleCss = ($skin === null && $styleSlug !== null)
            ? VisualStyleService::renderCss($siteId, $styleSlug, $paletteOverride)
            : '';

        $cssHref = htmlspecialchars(base_url('design.css'), ENT_QUOTES, 'UTF-8');
        $cssLink = '<link rel="stylesheet" href="' . $cssHref . '">';

        // Si hay skin compuesto, emitimos un `<style>` inline con las vars
        // sobreescritas. Esto garantiza prioridad sobre cualquier preset.
        $skinCss = $skin !== null ? "<style>\n" . self::renderCssVars($tokens) . "</style>" : '';

        return ($fonts !== '' ? $fonts . "\n" : '')
             . ($styleFonts !== '' ? $styleFonts . "\n" : '')
             . $cssLink
             . ($skinCss !== '' ? "\n" . $skinCss : '')
             . ($styleCss !== '' ? "\n" . $styleCss : '');
    }

    /**
     * D-Slice 1 — Lee el skin compuesto del sitio (sites.skin_json).
     * Devuelve null si no existe (entonces se respeta el flujo manual actual).
     */
    public static function loadSkin(int $siteId): ?array
    {
        try {
            $row = Database::selectOne('SELECT skin_json FROM sites WHERE id = ? LIMIT 1', [$siteId]);
            if (!$row || $row['skin_json'] === null) return null;
            $decoded = json_decode((string) $row['skin_json'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * D-Slice 1 — Combina los tokens base con el skin compuesto.
     * El skin sobrescribe paleta, tipografía (familia + escala + peso),
     * radii (btn + card) y mapea shadow_level a sombra de botón.
     * Lo demás (line_height, paddings, container_max…) se conserva.
     */
    private static function applySkinToTokens(array $tokens, array $skin): array
    {
        $palette = (array) ($skin['palette']     ?? []);
        $typo    = (array) ($skin['typography']  ?? []);
        $radii   = (array) ($skin['radii']       ?? []);
        $shadow  = (string) ($skin['shadow_level'] ?? '');

        // Colors
        $tokens['colors'] = array_merge($tokens['colors'] ?? [], array_filter([
            'primary'      => $palette['primary']      ?? null,
            'primary_dark' => $palette['primary_dark'] ?? null,
            'accent'       => $palette['accent']       ?? null,
            'bg'           => $palette['bg']           ?? null,
            'surface'      => $palette['surface']      ?? null,
            'text'         => $palette['text']         ?? null,
            'text_muted'   => $palette['text_muted']   ?? null,
            'border'       => $palette['border']       ?? null,
        ], static fn ($v) => $v !== null));

        // Typography
        $tokens['typography'] = array_merge($tokens['typography'] ?? [], array_filter([
            'font_heading' => $typo['font_heading'] ?? null,
            'font_body'    => $typo['font_body']    ?? null,
            'scale_ratio'  => $typo['scale_ratio']  ?? null,
            'weight_bold'  => $typo['weight_bold']  ?? null,
        ], static fn ($v) => $v !== null && $v !== ''));

        // Buttons: radius + shadow level
        if (isset($radii['btn'])) {
            $tokens['buttons']['radius'] = (int) $radii['btn'];
        }
        if ($shadow !== '') {
            // shadow_level del anchor → preset del design system.
            $tokens['buttons']['shadow'] = match ($shadow) {
                'none'      => 'none',
                'dramatic'  => 'lg',
                default     => 'sm', // 'subtle' y cualquier valor desconocido
            };
        }

        // Spacing: card radius
        if (isset($radii['card'])) {
            $tokens['spacing']['radius_card'] = (int) $radii['card'];
        }

        return $tokens;
    }

    /**
     * Lista de Google Fonts efectivamente usadas (para el <link> loader).
     */
    public static function googleFontsUsed(array $loadedTokens): array
    {
        $fonts = [];
        $typo = $loadedTokens['typography'] ?? [];
        foreach (['font_heading', 'font_body'] as $k) {
            $f = $typo[$k] ?? null;
            if ($f && $f !== 'system' && !in_array($f, $fonts, true)) {
                $fonts[] = $f;
            }
        }
        return $fonts;
    }

    /**
     * CSS base público para las secciones (T7.1). Usa los tokens --pp-*.
     * Mantenerlo minimalista y neutro: layout + tipografía base.
     * Los diseños específicos pueden acumularse aquí o extraerse a un archivo
     * separado si crecen demasiado.
     */
    public static function renderSectionBaseCss(): string
    {
        return <<<CSS
/* PromptPress — estilos base de secciones (T7.1 + T18.1 variantes + T18.5 tokens) */

/* ============================================================
   T18.5 — Tokens visuales derivados (sombras, radii, ritmo, divisor).
   Construidos sobre los tokens base del design system (--pp-primary,
   --pp-text, --pp-radius-card, --pp-section-y…). Cualquier sección puede
   referenciarlos para mantener una jerarquía visual coherente.
   ============================================================ */
:root{
    /* Sombras tintadas al texto del sitio (no glow neutro). Subir números = más elevación. */
    --pp-shadow-sm: 0 1px 2px color-mix(in srgb, var(--pp-text) 10%, transparent), 0 1px 3px color-mix(in srgb, var(--pp-text) 8%, transparent);
    --pp-shadow-md: 0 6px 14px -8px color-mix(in srgb, var(--pp-text) 18%, transparent), 0 2px 6px color-mix(in srgb, var(--pp-text) 8%, transparent);
    --pp-shadow-lg: 0 18px 36px -16px color-mix(in srgb, var(--pp-text) 22%, transparent), 0 4px 10px color-mix(in srgb, var(--pp-text) 8%, transparent);
    --pp-shadow-xl: 0 30px 60px -28px color-mix(in srgb, var(--pp-text) 26%, transparent), 0 6px 16px color-mix(in srgb, var(--pp-text) 10%, transparent);
    /* Inner edge sutil para tarjetas elevadas (refuerza materialidad sin glow). */
    --pp-shadow-inner-edge: inset 0 1px 0 color-mix(in srgb, #ffffff 80%, transparent);

    /* Escala de radii basada en --pp-radius-card. */
    --pp-radius-sm: calc(var(--pp-radius-card) * .55);
    --pp-radius-md: var(--pp-radius-card);
    --pp-radius-lg: calc(var(--pp-radius-card) * 1.25);
    --pp-radius-xl: calc(var(--pp-radius-card) * 1.6);
    --pp-radius-2xl: calc(var(--pp-radius-card) * 2);

    /* Padding vertical de sección adaptativo: respira en mobile, no abruma en desktop. */
    --pp-section-pad-y: clamp(48px, 7vw, var(--pp-section-y));
    /* Hueco vertical entre encabezado y contenido de sección. */
    --pp-rhythm: clamp(28px, 4vw, 48px);

    /* Línea divisoria estándar — usar siempre que se separen secciones por borde. */
    --pp-divider: 1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);
    --pp-divider-strong: 1px solid color-mix(in srgb, var(--pp-text) 16%, transparent);

    /* Curvas de easing para animaciones cortas (skill: spring-like, no linear). */
    --pp-ease-out: cubic-bezier(.16, 1, .3, 1);
    --pp-ease-in-out: cubic-bezier(.65,0,.35,1);

    /* ----------------------------------------------------------
       D0b.1 — Tokens semánticos para el modelo Skin componible.
       Permiten que cualquier layout sea skin-agnostic.
       ---------------------------------------------------------- */

    /* On-color: texto sobre fondos invertidos / saturados.
       Default `#fff` asume primary oscuro; el Skin puede sobrescribirlos
       (p.ej. primary pastel → --pp-on-primary: var(--pp-text)). */
    --pp-on-primary: #fff;
    --pp-on-text: var(--pp-bg);
    --pp-on-surface: var(--pp-text);

    /* Escala de spacing 4→128. Para componentes nuevos y migración progresiva. */
    --pp-space-1: 4px;
    --pp-space-2: 8px;
    --pp-space-3: 12px;
    --pp-space-4: 16px;
    --pp-space-5: 24px;
    --pp-space-6: 32px;
    --pp-space-7: 48px;
    --pp-space-8: 64px;
    --pp-space-9: 96px;
    --pp-space-10: 128px;

    /* Tipografía fluida unificada. Valores escogidos para mantener paridad
       con los clamps actuales más usados en SectionRenderer. */
    --pp-text-h1: clamp(2.1rem, 5vw, 3.5rem);
    --pp-text-h2: clamp(1.7rem, 3.2vw, 2.5rem);
    --pp-text-h3: clamp(1.4rem, 2.4vw, 1.85rem);
    --pp-text-h4: clamp(1.2rem, 1.8vw, 1.4rem);
    --pp-text-h5: 1.15rem;
    --pp-text-h6: 1.05rem;
    --pp-text-body: var(--pp-font-size-base);
    --pp-text-small: .88rem;
    --pp-text-eyebrow: .78rem;

    /* Escala de duraciones. */
    --pp-dur-fast: 150ms;
    --pp-dur-base: 220ms;
    --pp-dur-slow: 480ms;
}

*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--pp-bg);color:var(--pp-text);font-family:var(--pp-font-body);font-size:var(--pp-font-size-base);line-height:var(--pp-line-height);font-weight:var(--pp-weight-regular);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
img{max-width:100%;height:auto;display:block}
a{color:var(--pp-primary);text-decoration:none}
a:hover{text-decoration:underline}
h1,h2,h3,h4,h5,h6{font-family:var(--pp-font-heading);font-weight:var(--pp-weight-bold);line-height:1.1;margin:0 0 .6em;letter-spacing:-.015em}
p{margin:0 0 1em}
.container{max-width:var(--pp-container-max);margin:0 auto;padding:0 24px}

.pp-site-header{position:sticky;top:0;z-index:20;min-height:68px;border-bottom:var(--pp-divider);background:color-mix(in srgb,var(--pp-bg) 88%,transparent);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.pp-site-header__inner{display:flex;align-items:center;gap:clamp(16px,3vw,40px);max-width:var(--pp-container-max);margin:0 auto;padding:14px 24px}
.pp-site-header__brand{display:inline-flex;align-items:center;gap:12px;color:var(--pp-text);font-family:var(--pp-font-heading);font-weight:800;font-size:1.08rem;text-decoration:none;letter-spacing:0}
.pp-site-header__brand:hover{text-decoration:none}
.pp-site-header__logo-img{display:block;width:auto;max-width:168px;max-height:42px;object-fit:contain}
.pp-site-header__logo-fallback{display:grid;place-items:center;width:38px;height:38px;border-radius:var(--pp-radius-sm);background:var(--pp-primary);color:var(--pp-on-primary);font-weight:900}
.pp-site-header__nav{display:flex;align-items:center;gap:clamp(14px,2vw,28px);margin-left:auto;flex-wrap:wrap}
.pp-site-header__link{color:var(--pp-text-muted);text-decoration:none;font-size:.94rem;font-weight:600;transition:color 140ms ease}
.pp-site-header__link:hover{color:var(--pp-text);text-decoration:none}
.pp-site-header__cta{font-size:.9rem;padding:10px 18px;white-space:nowrap}
.pp-site-header__nav:empty{display:none}
.pp-site-header__inner > .pp-site-header__cta{margin-left:0}
.pp-site-header__inner > .pp-site-header__cta:first-of-type{margin-left:auto}
.pp-site-header__nav + .pp-site-header__cta{margin-left:0}
@media (max-width:720px){.pp-site-header{position:relative}.pp-site-header__nav{display:none}.pp-site-header__inner > .pp-site-header__cta{margin-left:auto}}

/* Public footer (E-GDPR G3 — enlaces legales) */
.pp-site-footer{margin-top:96px;background:var(--pp-on-surface);color:color-mix(in srgb,var(--pp-on-text) 72%,transparent);font-size:.92rem}
.pp-site-footer__grid{display:flex;flex-wrap:wrap;justify-content:space-between;gap:clamp(32px,5vw,64px);max-width:var(--pp-container-max);margin:0 auto;padding:clamp(40px,6vw,72px) 24px}
.pp-site-footer__brandcol{display:flex;flex-direction:column;gap:12px;flex:1 1 280px;max-width:420px}
.pp-site-footer__col{flex:0 1 auto;min-width:150px}
.pp-site-footer__name{color:var(--pp-on-text);font-family:var(--pp-font-heading);font-weight:800;font-size:1.25rem}
.pp-site-footer__tagline{margin:0;line-height:1.6;max-width:34em}
.pp-site-footer__col{display:flex;flex-direction:column;gap:10px}
.pp-site-footer__col-title{color:var(--pp-on-text);font-weight:700;font-size:.82rem;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px}
.pp-site-footer__link{color:color-mix(in srgb,var(--pp-on-text) 72%,transparent);text-decoration:none;font-size:.92rem;transition:color 150ms ease}
.pp-site-footer__link:hover{color:var(--pp-on-text);text-decoration:none}
.pp-site-footer__bottom{border-top:1px solid color-mix(in srgb,var(--pp-on-text) 14%,transparent)}
.pp-site-footer__bottom .pp-site-footer__copy{display:block;max-width:var(--pp-container-max);margin:0 auto;padding:18px 24px;font-size:.84rem}
@media (max-width:640px){.pp-site-footer__grid{grid-template-columns:1fr;gap:28px}}

/* Click-to-load videos (E-GDPR G4b) */
.pp-video-cta{position:relative;width:100%;aspect-ratio:16/9;border-radius:12px;overflow:hidden;background-size:cover;background-position:center;cursor:pointer;isolation:isolate;display:flex;align-items:center;justify-content:center;color:#fff}
.pp-video-cta__overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,.3) 0%,rgba(15,23,42,.65) 100%);z-index:1}
.pp-video-cta__play{position:relative;z-index:2;appearance:none;background:rgba(255,255,255,.95);color:#0f172a;border:0;cursor:pointer;width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 12px 28px -8px color-mix(in srgb, var(--pp-text) 55%, transparent);transition:transform 200ms var(--pp-ease-out),background 150ms ease}
.pp-video-cta:hover .pp-video-cta__play{transform:scale(1.08);background:#fff}
.pp-video-cta__play svg{margin-left:4px}
.pp-video-cta__notice{position:absolute;left:16px;right:16px;bottom:14px;z-index:2;display:flex;flex-direction:column;gap:2px;font-size:.78rem;line-height:1.4;text-shadow:0 1px 2px rgba(0,0,0,.5);pointer-events:none}
.pp-video-cta__notice strong{font-weight:700}
.pp-video-cta__notice span{opacity:.92}
.pp-video-cta__iframe{width:100%;height:100%;border:0;display:block}
.pp-video-cta.is-loaded{cursor:default}
.pp-video-cta.is-loaded .pp-video-cta__overlay,.pp-video-cta.is-loaded .pp-video-cta__play,.pp-video-cta.is-loaded .pp-video-cta__notice{display:none}

/* Cookie banner + modal (E-GDPR G4) */
.pp-cb{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;background:var(--pp-bg);color:var(--pp-text);border:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);border-radius:16px;box-shadow:0 24px 48px -16px color-mix(in srgb, var(--pp-text) 18%, transparent),0 0 0 1px color-mix(in srgb, var(--pp-text) 4%, transparent);opacity:0;transform:translateY(20px);transition:opacity 240ms ease,transform 240ms var(--pp-ease-out);max-width:980px;margin:0 auto;pointer-events:auto}
.pp-cb.is-visible{opacity:1;transform:translateY(0)}
.pp-cb__inner{display:flex;align-items:center;gap:24px;padding:20px 24px;flex-wrap:wrap}
.pp-cb__text{flex:1;min-width:280px}
.pp-cb__title{margin:0 0 6px;font-size:1rem;font-weight:700}
.pp-cb__desc{margin:0;font-size:.88rem;line-height:1.55;color:var(--pp-text-muted,#64748b)}
.pp-cb__link{color:var(--pp-primary);text-decoration:underline;text-underline-offset:3px}
.pp-cb__actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pp-cb__btn{appearance:none;border:0;cursor:pointer;font:inherit;font-size:.88rem;font-weight:600;padding:10px 18px;border-radius:10px;transition:transform 150ms ease,filter 150ms ease,box-shadow 150ms ease;line-height:1.2}
.pp-cb__btn:hover{transform:translateY(-1px)}
.pp-cb__btn--primary{background:var(--pp-primary);color:var(--pp-on-primary);box-shadow:0 4px 12px -4px color-mix(in srgb, var(--pp-primary) 40%, transparent)}
.pp-cb__btn--primary:hover{filter:brightness(.97)}
.pp-cb__btn--secondary{background:transparent;color:var(--pp-text);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--pp-text) 22%,transparent)}
.pp-cb__btn--secondary:hover{background:color-mix(in srgb,var(--pp-text) 4%,transparent)}
@media (max-width:560px){.pp-cb__inner{padding:18px 18px 16px}.pp-cb__actions{width:100%}.pp-cb__btn{flex:1 1 auto;justify-content:center;text-align:center}}

.pp-cb-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 200ms ease}
.pp-cb-modal.is-visible{opacity:1}
.pp-cb-modal__backdrop{position:absolute;inset:0;background:color-mix(in srgb, var(--pp-text) 55%, transparent);backdrop-filter:blur(4px)}
.pp-cb-modal__panel{position:relative;background:var(--pp-bg);color:var(--pp-text);border-radius:18px;width:min(540px,92vw);max-height:90vh;overflow:auto;box-shadow:0 40px 80px -20px color-mix(in srgb, var(--pp-text) 45%, transparent);transform:scale(.96);transition:transform 220ms var(--pp-ease-out)}
.pp-cb-modal.is-visible .pp-cb-modal__panel{transform:scale(1)}
.pp-cb-modal__head{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:var(--pp-divider)}
.pp-cb-modal__head h2{margin:0;font-size:1.05rem}
.pp-cb-modal__close{appearance:none;background:transparent;border:0;font-size:1.6rem;line-height:1;color:var(--pp-text-muted,#64748b);cursor:pointer;padding:4px 8px;border-radius:6px}
.pp-cb-modal__close:hover{background:color-mix(in srgb,var(--pp-text) 6%,transparent);color:var(--pp-text)}
.pp-cb-modal__body{padding:18px 24px;display:flex;flex-direction:column;gap:12px}
.pp-cb-modal__foot{display:flex;justify-content:flex-end;gap:8px;padding:16px 24px;border-top:var(--pp-divider);flex-wrap:wrap}
.pp-cb-cat{display:flex;align-items:flex-start;gap:14px;padding:14px;border:1px solid var(--pp-divider-color,rgba(0,0,0,.08));border-radius:12px;cursor:pointer;transition:border-color 150ms ease}
.pp-cb-cat:hover{border-color:color-mix(in srgb,var(--pp-text) 18%,transparent)}
.pp-cb-cat input{position:absolute;opacity:0;pointer-events:none}
.pp-cb-cat__switch{width:38px;height:22px;border-radius:999px;background:color-mix(in srgb,var(--pp-text) 18%,transparent);position:relative;flex-shrink:0;transition:background 200ms ease;margin-top:2px}
.pp-cb-cat__switch::after{content:"";position:absolute;width:18px;height:18px;background:#fff;border-radius:50%;top:2px;left:2px;transition:left 200ms cubic-bezier(.16,1,.3,1);box-shadow:0 2px 4px rgba(0,0,0,.15)}
.pp-cb-cat input:checked + .pp-cb-cat__switch{background:var(--pp-primary)}
.pp-cb-cat input:checked + .pp-cb-cat__switch::after{left:18px}
.pp-cb-cat input:disabled + .pp-cb-cat__switch{opacity:.7;cursor:not-allowed}
.pp-cb-cat__text{display:flex;flex-direction:column;gap:2px}
.pp-cb-cat__text strong{font-size:.95rem;font-weight:600}
.pp-cb-cat__text em{font-style:normal;font-weight:500;color:var(--pp-text-muted,#64748b);font-size:.82rem}
.pp-cb-cat__text span{font-size:.82rem;color:var(--pp-text-muted,#64748b);line-height:1.45}

.pp-section{padding:var(--pp-section-pad-y) 0;position:relative}
.pp-section + .pp-section{padding-top:clamp(44px, 6vw, var(--pp-section-y))}

/* Divisor entre secciones (opcional). Usar como `<hr class="pp-section-divider">`. */
.pp-section-divider{border:0;border-top:var(--pp-divider);max-width:var(--pp-container-max);margin:0 auto;height:0}

/* Buttons */
.pp-btn{display:inline-block;background:var(--pp-primary);color:#fff;padding:var(--pp-btn-padding-y) var(--pp-btn-padding-x);border-radius:var(--pp-btn-radius);font-weight:var(--pp-btn-weight);font-size:var(--pp-btn-font-size);text-transform:var(--pp-btn-text-transform);text-decoration:none;box-shadow:var(--pp-btn-shadow);border:0;cursor:pointer;line-height:1.2;transition:transform 150ms var(--pp-ease-out),filter 150ms ease,box-shadow 150ms ease}
.pp-btn:hover{filter:brightness(.97);text-decoration:none;transform:translateY(-1px)}
.pp-btn:active{transform:translateY(0)}
.pp-btn--lg{font-size:calc(var(--pp-btn-font-size) * 1.1);padding:calc(var(--pp-btn-padding-y) * 1.15) calc(var(--pp-btn-padding-x) * 1.2)}
.pp-btn--ghost{background:transparent;color:var(--pp-text);box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--pp-text) 18%, transparent)}
.pp-btn--ghost:hover{background:color-mix(in srgb, var(--pp-text) 4%, transparent);filter:none}

/* ============================================================
   PP-FRIENDLY CUSTOM BLOCKS (DMB-F2)
   ============================================================ */
.ppb-container{max-width:var(--pp-container-max);margin:0 auto;padding:0 24px}
.ppb-section{position:relative}
.ppb-header,.ppb-body,.ppb-footer{min-width:0}
.ppb-stack{display:flex;flex-direction:column;gap:var(--pp-rhythm)}
.ppb-stack--tight{gap:var(--pp-space-3)}
.ppb-stack--loose{gap:var(--pp-space-7)}
.ppb-cluster{display:flex;align-items:center;gap:var(--pp-space-3);flex-wrap:wrap}
.ppb-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(32px,5vw,72px);align-items:center}
.ppb-split--text-heavy{grid-template-columns:minmax(0,1.18fr) minmax(0,.82fr)}
.ppb-split--media-heavy{grid-template-columns:minmax(0,.82fr) minmax(0,1.18fr)}
.ppb-split--media-left > .ppb-media{order:-1}
.ppb-split--media-right > .ppb-media{order:2}
.ppb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));gap:var(--pp-space-5)}
.ppb-grid--2{grid-template-columns:repeat(2,minmax(0,1fr))}
.ppb-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))}
.ppb-grid--4{grid-template-columns:repeat(4,minmax(0,1fr))}
.ppb-mosaic{display:grid;grid-template-columns:1.2fr .8fr;gap:var(--pp-space-5);align-items:stretch}
.ppb-align-start{align-items:flex-start;text-align:left}
.ppb-align-center{align-items:center;justify-content:center;text-align:center;margin-left:auto;margin-right:auto}
.ppb-align-end{align-items:flex-end;text-align:right}
.ppb-text-center{text-align:center}
.ppb-measure-sm{max-width:34rem}
.ppb-measure-md{max-width:46rem}
.ppb-measure-lg{max-width:64rem}
/* Fix QA: una caja con measure dentro de un contexto centrado debe centrarse
   ELLA también (max-width sin margin:auto se pega a la izquierda). Igual para
   actions/cluster: en contexto centrado, centran sus hijos flex. */
.ppb-text-center .ppb-measure-sm,.ppb-text-center .ppb-measure-md,.ppb-text-center .ppb-measure-lg,
.ppb-align-center .ppb-measure-sm,.ppb-align-center .ppb-measure-md,.ppb-align-center .ppb-measure-lg,
.ppb-measure-sm.ppb-text-center,.ppb-measure-md.ppb-text-center,.ppb-measure-lg.ppb-text-center{margin-left:auto;margin-right:auto}
.ppb-text-center .ppb-actions,.ppb-text-center .ppb-cluster{justify-content:center}
.ppb-text-center .ppb-stack{align-items:center}
.ppb-eyebrow,.ppb-kicker{display:inline-flex;width:max-content;max-width:100%;font-size:var(--pp-text-eyebrow);font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--pp-primary);background:color-mix(in srgb,var(--pp-primary) 9%,transparent);padding:6px 12px;border-radius:999px;margin:0}
.ppb-heading-xl{font-size:var(--pp-text-h1);line-height:1.02;letter-spacing:0;margin:0;text-wrap:balance}
.ppb-heading-lg{font-size:var(--pp-text-h2);line-height:1.08;letter-spacing:0;margin:0;text-wrap:balance}
.ppb-heading-md{font-size:var(--pp-text-h3);line-height:1.12;letter-spacing:0;margin:0;text-wrap:balance}
.ppb-lead{font-size:clamp(1.05rem,1.4vw,1.24rem);line-height:1.58;color:var(--pp-text-muted);margin:0;text-wrap:pretty}
.ppb-copy{font-size:var(--pp-text-body);line-height:1.68;color:var(--pp-text);margin:0}
.ppb-small{font-size:var(--pp-text-small);color:var(--pp-text-muted);margin:0}
.ppb-card,.ppb-panel{background:var(--pp-bg);border:1px solid color-mix(in srgb,var(--pp-text) 9%,transparent);border-radius:var(--pp-radius-lg);padding:var(--pp-space-5)}
.ppb-card--flat{box-shadow:none}
.ppb-card--raised{box-shadow:var(--pp-shadow-lg)}
.ppb-card--accent{border-color:color-mix(in srgb,var(--pp-primary) 24%,transparent);background:color-mix(in srgb,var(--pp-primary) 5%,var(--pp-bg))}
.ppb-panel{padding:clamp(28px,5vw,64px)}
.ppb-panel--inverted{background:var(--pp-text);color:var(--pp-bg);border-color:var(--pp-text)}
.ppb-panel--inverted .ppb-lead,.ppb-panel--inverted .ppb-copy,.ppb-panel--inverted .ppb-small{color:color-mix(in srgb,var(--pp-bg) 78%,transparent)}
.ppb-strip{display:flex;align-items:center;justify-content:space-between;gap:var(--pp-space-4);padding:var(--pp-space-4) 0;border-top:var(--pp-divider);border-bottom:var(--pp-divider);flex-wrap:wrap}
.ppb-media{margin:0;min-width:0}
.ppb-media img{display:block;width:100%;height:100%;object-fit:cover;border-radius:inherit}
.ppb-media--frame{border-radius:var(--pp-radius-xl);overflow:hidden;box-shadow:var(--pp-shadow-lg);background:var(--pp-surface)}
.ppb-media--bleed img{border-radius:0}
.ppb-media--landscape{aspect-ratio:16/10}
.ppb-media--portrait{aspect-ratio:4/5}
.ppb-media--square{aspect-ratio:1/1}
.ppb-caption{font-size:var(--pp-text-small);color:var(--pp-text-muted);margin:var(--pp-space-2) 0 0}
.ppb-actions{display:flex;align-items:center;gap:var(--pp-space-3);flex-wrap:wrap}
.ppb-actions--center{justify-content:center}
.ppb-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:var(--pp-space-3)}
.ppb-list--check li{position:relative;padding-left:1.65em}
.ppb-list--check li::before{content:"";position:absolute;left:0;top:.55em;width:.7em;height:.7em;border-radius:50%;background:var(--pp-primary)}
.ppb-list--numbered{counter-reset:ppb-list}
.ppb-list--numbered li{counter-increment:ppb-list;display:grid;grid-template-columns:auto 1fr;gap:var(--pp-space-3)}
.ppb-list--numbered li::before{content:counter(ppb-list, decimal-leading-zero);font-weight:800;color:var(--pp-primary)}
.ppb-item{min-width:0}
.ppb-item__icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:var(--pp-radius-sm);background:color-mix(in srgb,var(--pp-primary) 11%,transparent);color:var(--pp-primary);margin-bottom:var(--pp-space-3)}
.ppb-item__icon .pp-icon{width:22px;height:22px}
span[data-ppb-icon] .pp-icon{display:block}
.ppb-item__title{font-size:var(--pp-text-h4);margin:0 0 var(--pp-space-2);line-height:1.15}
.ppb-item__text{color:var(--pp-text-muted);line-height:1.6;margin:0}
.ppb-badge{display:inline-flex;align-items:center;width:max-content;font-size:var(--pp-text-small);font-weight:800;color:var(--pp-text-muted);background:color-mix(in srgb,var(--pp-text) 6%,transparent);border-radius:999px;padding:4px 10px;margin-bottom:var(--pp-space-3)}
.ppb-badge--accent{color:var(--pp-primary);background:color-mix(in srgb,var(--pp-primary) 11%,transparent)}
.ppb-stat{display:flex;flex-direction:column;gap:var(--pp-space-1)}
.ppb-stat__value{font-family:var(--pp-font-heading);font-size:var(--pp-text-h2);font-weight:var(--pp-weight-bold);line-height:1;color:var(--pp-primary)}
.ppb-stat__label{font-size:var(--pp-text-small);color:var(--pp-text-muted)}
.ppb-quote{margin:0}
.ppb-quote__text{font-family:var(--pp-font-heading);font-size:var(--pp-text-h3);line-height:1.25;margin:0;color:var(--pp-text)}
.ppb-quote__cite{display:block;margin-top:var(--pp-space-3);font-style:normal;color:var(--pp-text-muted)}
.ppb-gap-sm{gap:var(--pp-space-3)}
.ppb-gap-md{gap:var(--pp-space-5)}
.ppb-gap-lg{gap:var(--pp-space-7)}
.ppb-pad-sm{padding:var(--pp-space-4)}
.ppb-pad-md{padding:var(--pp-space-5)}
.ppb-pad-lg{padding:var(--pp-space-7)}

/* ------------------------------------------------------------
   D-MB2 R2 — Dirección de arte por sección (themes + ritmo).
   El bloque custom declara `data-ppb-theme` / `data-ppb-pad` en su raíz;
   SectionRenderer los mapea a clases `pp-section--ppbt-*` / `--ppbp-*`
   en el wrapper <section>, de modo que el fondo ocupa TODO el ancho.
   Nota técnica: dentro de un theme oscuro se re-skinnean los tokens
   (--pp-text, --pp-text-muted, --pp-bg…) para que toda la gramática ppb
   herede el contraste correcto sin reglas por clase. Los tokens `--pp-on-*`
   se computan en :root, por lo que conservan el valor ORIGINAL de la marca
   dentro del scope re-skinneado (sin ciclos de var()).
   ------------------------------------------------------------ */
.pp-section.pp-section--ppbp-sm{padding-block:clamp(28px,4vw,56px)}
.pp-section.pp-section--ppbp-md{padding-block:var(--pp-section-pad-y)}
.pp-section.pp-section--ppbp-lg{padding-block:clamp(72px,9vw,128px)}
.pp-section.pp-section--ppbp-xl{padding-block:clamp(96px,12vw,176px)}

.pp-section.pp-section--ppbt-surface{background:var(--pp-surface)}
.pp-section.pp-section--ppbt-tint{background:color-mix(in srgb,var(--pp-primary) 7%,var(--pp-bg))}
.pp-section.pp-section--ppbt-dark,
.pp-section.pp-section--ppbt-image{background:var(--pp-on-surface);--ppb-ink:var(--pp-on-text)}
.pp-section.pp-section--ppbt-primary{background:linear-gradient(135deg,var(--pp-primary),var(--pp-primary-dark,var(--pp-primary)));--ppb-ink:var(--pp-on-primary)}
.pp-section--ppbt-dark,.pp-section--ppbt-image,.pp-section--ppbt-primary{
  color:var(--ppb-ink);
  --pp-text:var(--ppb-ink);
  --pp-text-muted:color-mix(in srgb,var(--ppb-ink) 74%,transparent);
  --pp-bg:color-mix(in srgb,var(--ppb-ink) 7%,transparent);
  --pp-surface:color-mix(in srgb,var(--ppb-ink) 10%,transparent);
  --pp-shadow-sm:none;--pp-shadow-md:none;--pp-shadow-lg:none;--pp-shadow-xl:none;
}
/* Acentos de marca sobre fondo de marca: pasan a usar el on-color. */
.pp-section--ppbt-primary .ppb-eyebrow,.pp-section--ppbt-primary .ppb-kicker{color:var(--pp-on-primary);background:color-mix(in srgb,var(--pp-on-primary) 14%,transparent)}
.pp-section--ppbt-primary .ppb-stat__value,.pp-section--ppbt-primary .ppb-list--numbered li::before{color:var(--pp-on-primary)}
.pp-section--ppbt-primary .ppb-item__icon{color:var(--pp-on-primary);background:color-mix(in srgb,var(--pp-on-primary) 16%,transparent)}
.pp-section--ppbt-primary .ppb-list--check li::before{background:var(--pp-on-primary)}
.pp-section--ppbt-primary .ppb-badge--accent{color:var(--pp-on-primary);background:color-mix(in srgb,var(--pp-on-primary) 14%,transparent)}
.pp-section--ppbt-primary .pp-btn--primary,.pp-section--ppbt-primary .pp-btn:not(.pp-btn--ghost){background:var(--pp-on-primary);color:var(--pp-primary)}
.pp-section--ppbt-primary .pp-btn--ghost,.pp-section--ppbt-dark .pp-btn--ghost,.pp-section--ppbt-image .pp-btn--ghost{color:var(--ppb-ink);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--ppb-ink) 42%,transparent)}

/* Cover: foto a sangre con overlay, contenido encima. Pensado para
   theme="image" (la sección pierde su padding y el cover manda). */
.ppb-cover{position:relative;isolation:isolate;display:flex;align-items:flex-end;min-height:clamp(420px,60vh,680px);padding:clamp(48px,7vw,96px) 0}
.ppb-cover__bg{position:absolute;inset:0;z-index:-2;margin:0}
.ppb-cover__bg img{width:100%;height:100%;object-fit:cover;border-radius:0}
.ppb-cover::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(195deg,color-mix(in srgb,var(--pp-on-surface) 34%,transparent) 0%,color-mix(in srgb,var(--pp-on-surface) 86%,transparent) 96%)}
.ppb-cover__content{position:relative;width:100%}
.pp-section.pp-section--ppbt-image{padding:0}

@media (max-width:900px){.ppb-grid--4{grid-template-columns:repeat(2,minmax(0,1fr))}.ppb-split,.ppb-split--text-heavy,.ppb-split--media-heavy,.ppb-mosaic{grid-template-columns:1fr}.ppb-split--media-left > .ppb-media,.ppb-split--media-right > .ppb-media{order:initial}}
@media (max-width:640px){.ppb-container{padding:0 20px}.ppb-grid--2,.ppb-grid--3,.ppb-grid--4{grid-template-columns:1fr}.ppb-actions--stack-mobile{flex-direction:column;align-items:stretch}.ppb-actions--stack-mobile .pp-btn{text-align:center}.ppb-strip{align-items:flex-start;flex-direction:column}.ppb-card,.ppb-panel{padding:var(--pp-space-4)}}

/* ============================================================
   FH5 — pp-ux: comportamientos declarativos (data-pp-behavior).
   El JS vive en public/js/pp-ux.js; aquí solo sus estilos base.
   ============================================================ */
/* reveal — estado inicial y entrada (solo si hay JS y sin reduced-motion) */
.pp-ux-reveal{opacity:0;transform:translateY(22px);transition:opacity .6s var(--pp-ease-out),transform .6s var(--pp-ease-out)}
.pp-ux-reveal.pp-ux-in{opacity:1;transform:none}
[data-pp-reveal-delay="1"]{transition-delay:.08s}
[data-pp-reveal-delay="2"]{transition-delay:.16s}
[data-pp-reveal-delay="3"]{transition-delay:.24s}
[data-pp-reveal-delay="4"]{transition-delay:.32s}
[data-pp-reveal-delay="5"]{transition-delay:.4s}
@media (prefers-reduced-motion:reduce){.pp-ux-reveal{opacity:1;transform:none;transition:none}}

/* slider — track scroll-snap + flechas */
.pp-ux-slider{position:relative}
.pp-ux-slider__track{display:flex;gap:24px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:4px}
.pp-ux-slider__track::-webkit-scrollbar{display:none}
.pp-ux-slider__track > *{flex:0 0 auto;width:min(420px,86%);scroll-snap-align:start}
.pp-ux-slider__arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;display:grid;place-items:center;width:42px;height:42px;border-radius:50%;border:0;cursor:pointer;background:var(--pp-bg);color:var(--pp-text);box-shadow:var(--pp-shadow-md);transition:transform .15s var(--pp-ease-out),opacity .2s ease}
.pp-ux-slider__arrow:hover{transform:translateY(-50%) scale(1.06)}
.pp-ux-slider__arrow svg{width:20px;height:20px}
.pp-ux-slider__arrow--prev{left:-8px}
.pp-ux-slider__arrow--next{right:-8px}
.pp-ux-slider--at-start .pp-ux-slider__arrow--prev,.pp-ux-slider--at-end .pp-ux-slider__arrow--next{opacity:0;pointer-events:none}

/* accordion — details/summary con tokens (también sin JS) */
[data-pp-behavior="accordion"] details{border:1px solid color-mix(in srgb,var(--pp-text) 10%,transparent);border-radius:var(--pp-radius-md);background:var(--pp-bg);margin:0 0 10px;overflow:hidden}
[data-pp-behavior="accordion"] summary{list-style:none;cursor:pointer;padding:16px 48px 16px 18px;font-weight:700;position:relative;color:var(--pp-text)}
[data-pp-behavior="accordion"] summary::-webkit-details-marker{display:none !important}
/* El indicador lo dibuja SIEMPRE la plataforma (chevron). Los `!important`
   evitan que el CSS por página (auto-scopeado, y por tanto más específico)
   pinte su propio icono +/− sobre el mismo ::after y se vean dos marcadores. */
[data-pp-behavior="accordion"] summary::before{content:none !important}
[data-pp-behavior="accordion"] summary::after{content:"" !important;position:absolute !important;right:18px !important;top:50% !important;left:auto !important;width:10px !important;height:10px !important;border:0 !important;border-right:2px solid var(--pp-primary) !important;border-bottom:2px solid var(--pp-primary) !important;background:none !important;transform:translateY(-70%) rotate(45deg);transition:transform .2s var(--pp-ease-out)}
[data-pp-behavior="accordion"] details[open] summary::after{transform:translateY(-30%) rotate(225deg)}
[data-pp-behavior="accordion"] details > :not(summary){padding:0 18px 16px;margin:0;color:var(--pp-text-muted);line-height:1.6}

/* menú móvil del header (pp-ux.js inyecta el botón) */
.pp-site-header__burger{display:none;flex-direction:column;justify-content:center;gap:5px;width:42px;height:42px;margin-left:auto;border:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);border-radius:var(--pp-radius-sm);background:transparent;cursor:pointer;padding:0 10px}
.pp-site-header__burger span{display:block;height:2px;border-radius:2px;background:var(--pp-text);transition:transform .2s var(--pp-ease-out),opacity .2s ease}
.is-nav-open .pp-site-header__burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.is-nav-open .pp-site-header__burger span:nth-child(2){opacity:0}
.is-nav-open .pp-site-header__burger span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
@media (max-width:720px){
  .pp-site-header__burger{display:flex}
  .pp-site-header.is-nav-open .pp-site-header__nav{display:flex;position:absolute;left:0;right:0;top:100%;flex-direction:column;align-items:stretch;gap:0;background:var(--pp-bg);border-bottom:var(--pp-divider);box-shadow:var(--pp-shadow-lg);padding:8px 0;z-index:30}
  .pp-site-header.is-nav-open .pp-site-header__link{padding:13px 24px;font-size:1rem}
}

/* ============================================================
   FH1 — CANVAS (HTML libre). Solo red de seguridad: el diseño
   real lo trae el CSS scoped de cada página.
   ============================================================ */
.pp-canvas{min-width:0}
.pp-canvas *,.pp-canvas *::before,.pp-canvas *::after{box-sizing:border-box}
.pp-canvas img{max-width:100%;height:auto}
.pp-canvas section{position:relative}
/* El formulario del sistema dentro de un canvas se auto-encapsula en tarjeta:
   debe ser legible sobre CUALQUIER fondo que la IA haya pintado detrás. */
.pp-canvas .pp-form{padding:0}
.pp-canvas .pp-form__panel{background:var(--pp-bg);color:var(--pp-text);border-radius:var(--pp-radius-xl);padding:clamp(24px,4vw,44px);box-shadow:var(--pp-shadow-lg);max-width:640px;margin:0 auto}
.pp-canvas .pp-form__heading{color:var(--pp-text)}
.pp-canvas .pp-form__desc,.pp-canvas .pp-form__label{color:var(--pp-text-muted)}
.pp-canvas .pp-form__label{color:var(--pp-text);font-weight:600}

/* ============================================================
   HERO
   ============================================================ */
.pp-section--hero{background:linear-gradient(180deg, color-mix(in srgb, var(--pp-primary) 5%, var(--pp-surface)) 0%, var(--pp-surface) 100%)}
.pp-hero__heading{font-size:clamp(2.1rem, 5vw, 3.5rem);margin-bottom:.35em;letter-spacing:-.025em;line-height:1.05}
.pp-hero__subheading{color:var(--pp-text-muted);max-width:38em;margin:0 0 1.5em;font-size:clamp(1.05rem, 1.4vw, 1.2rem);line-height:1.55}
.pp-hero__eyebrow{display:inline-block;font-size:.78rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--pp-primary);background:color-mix(in srgb, var(--pp-primary) 9%, transparent);padding:6px 12px;border-radius:999px;margin:0 0 1.4em}
.pp-hero__cta{margin:0;display:flex;gap:12px;flex-wrap:wrap}

/* default — centrado con respiración */
.pp-hero__inner--default{text-align:center;display:flex;flex-direction:column;align-items:center}
.pp-hero__inner--default .pp-hero__subheading{margin-left:auto;margin-right:auto;text-align:center}
.pp-hero__inner--default .pp-hero__cta{justify-content:center}

/* split — texto + media */
.pp-hero__inner--split{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(32px, 5vw, 64px);align-items:center}
.pp-hero__inner--split .pp-hero__media img{width:100%;height:auto;border-radius:var(--pp-radius-xl);box-shadow:var(--pp-shadow-lg),0 0 0 1px color-mix(in srgb, var(--pp-text) 6%, transparent)}
@media (max-width:820px){.pp-hero__inner--split{grid-template-columns:1fr}.pp-hero__inner--split .pp-hero__media{order:-1}}

/* with-image-bg — overlay tintado, no glow */
.pp-hero__inner--bg{position:relative;isolation:isolate;background-size:cover;background-position:center;padding:clamp(56px, 9vw, 120px) clamp(28px, 5vw, 64px);border-radius:var(--pp-radius-xl);overflow:hidden;color:#fff;min-height:clamp(360px, 50vh, 560px);display:flex;align-items:flex-end}
.pp-hero__inner--bg .pp-hero__overlay{position:absolute;inset:0;z-index:0;background:linear-gradient(180deg, color-mix(in srgb, var(--pp-text) 0%, transparent) 0%, color-mix(in srgb, var(--pp-text) 65%, transparent) 100%),linear-gradient(135deg, color-mix(in srgb, var(--pp-primary) 35%, transparent), transparent 60%);box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}
.pp-hero__inner--bg .pp-hero__text{position:relative;z-index:1;max-width:42em}
.pp-hero__inner--bg .pp-hero__heading{color:#fff}
.pp-hero__inner--bg .pp-hero__subheading{color:rgba(255,255,255,.88)}
.pp-hero__inner--bg .pp-hero__eyebrow{background:rgba(255,255,255,.14);color:#fff;backdrop-filter:blur(8px)}
.pp-hero__inner--bg .pp-btn--ghost{color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.45)}
.pp-hero__inner--bg .pp-btn--ghost:hover{background:rgba(255,255,255,.1)}

/* poster-stack — portada editorial con bloque vertical */
.pp-section--hero--poster-stack{background:var(--pp-text);color:var(--pp-bg)}
.pp-section--hero--poster-stack .pp-hero__inner--default{min-height:clamp(520px,76dvh,760px);align-items:flex-start;text-align:left;justify-content:center;position:relative;isolation:isolate;padding-top:clamp(64px,9vw,120px);padding-bottom:clamp(64px,9vw,120px)}
.pp-section--hero--poster-stack .pp-hero__inner--default::after{content:"";position:absolute;right:24px;bottom:24px;width:clamp(120px,22vw,320px);height:clamp(120px,22vw,320px);border:1px solid color-mix(in srgb,var(--pp-bg) 22%,transparent);background:linear-gradient(135deg,color-mix(in srgb,var(--pp-primary) 86%,transparent),transparent 66%);z-index:-1}
.pp-section--hero--poster-stack .pp-hero__heading{max-width:10ch;color:var(--pp-bg);font-size:clamp(3rem,9vw,8rem);line-height:.86;text-transform:uppercase;letter-spacing:0}
.pp-section--hero--poster-stack .pp-hero__subheading{color:color-mix(in srgb,var(--pp-bg) 78%,transparent);max-width:44rem;margin-left:0;text-align:left}
.pp-section--hero--poster-stack .pp-hero__eyebrow{border-radius:0;background:var(--pp-primary);color:var(--pp-text);font-weight:900}
.pp-section--hero--poster-stack .pp-hero__cta{justify-content:flex-start}
.pp-section--hero--poster-stack .pp-btn--ghost{color:var(--pp-bg);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--pp-bg) 34%,transparent)}

/* statement-left — aire caro, columna izquierda dominante */
.pp-section--hero--statement-left{background:var(--pp-bg)}
.pp-section--hero--statement-left .pp-hero__inner--default{align-items:flex-start;text-align:left;padding-left:clamp(24px,14vw,220px);padding-top:clamp(72px,10vw,150px);padding-bottom:clamp(72px,9vw,130px)}
.pp-section--hero--statement-left .pp-hero__heading{max-width:12ch;font-size:clamp(2.6rem,7vw,6.4rem);line-height:.95}
.pp-section--hero--statement-left .pp-hero__subheading{margin-left:0;text-align:left;max-width:46rem}
.pp-section--hero--statement-left .pp-hero__cta{justify-content:flex-start}

/* metric-led — hero con barras de datos simuladas */
.pp-section--hero--metric-led{background:linear-gradient(180deg,var(--pp-bg),var(--pp-surface))}
.pp-section--hero--metric-led .pp-hero__inner--default{position:relative;align-items:flex-start;text-align:left;padding-top:clamp(60px,9vw,130px);padding-bottom:clamp(70px,10vw,150px)}
.pp-section--hero--metric-led .pp-hero__inner--default::after{content:"47.2%\\A 8.6x\\A 312";white-space:pre;position:absolute;right:24px;top:50%;transform:translateY(-50%);font-family:var(--pp-font-heading);font-size:clamp(2rem,5vw,5rem);font-weight:900;line-height:.95;color:color-mix(in srgb,var(--pp-primary) 88%,var(--pp-text));text-align:right;border-left:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);padding-left:clamp(24px,5vw,70px)}
.pp-section--hero--metric-led .pp-hero__heading{max-width:12ch;font-size:clamp(2.6rem,6.4vw,6rem)}
.pp-section--hero--metric-led .pp-hero__subheading{margin-left:0;text-align:left;max-width:38rem}
.pp-section--hero--metric-led .pp-hero__cta{justify-content:flex-start}
@media (max-width:760px){.pp-section--hero--poster-stack .pp-hero__inner--default,.pp-section--hero--statement-left .pp-hero__inner--default,.pp-section--hero--metric-led .pp-hero__inner--default{min-height:auto;padding-left:24px}.pp-section--hero--metric-led .pp-hero__inner--default::after{position:static;transform:none;text-align:left;border-left:0;border-top:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);padding:22px 0 0;margin-top:28px}}

/* ============================================================
   TEXT + IMAGE
   ============================================================ */
.pp-ti{display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px, 4vw, 56px);align-items:center}
.pp-ti--no-media{grid-template-columns:1fr;max-width:48em;margin-left:auto;margin-right:auto;text-align:center}
.pp-ti--no-media .pp-ti__heading{font-size:clamp(1.75rem, 3vw, 2.5rem);letter-spacing:-.02em}
.pp-ti--left .pp-ti__text{order:2}
.pp-ti--left .pp-ti__media{order:1}
.pp-ti__heading{font-size:clamp(1.6rem, 2.6vw, 2.3rem);letter-spacing:-.02em}
.pp-ti__body{color:var(--pp-text);max-width:38em}
.pp-ti__body p{line-height:1.65}
.pp-ti__media img{width:100%;border-radius:var(--pp-radius-lg);box-shadow:var(--pp-shadow-lg)}

/* wide-media — imagen 60% */
.pp-ti--v-wide-media{grid-template-columns:2fr 3fr}
.pp-ti--v-wide-media.pp-ti--left{grid-template-columns:3fr 2fr}

/* card — todo dentro de tarjeta */
.pp-ti--v-card{background:var(--pp-bg);padding:clamp(28px, 4vw, 56px);border-radius:var(--pp-radius-xl);border:1px solid color-mix(in srgb, var(--pp-text) 7%, transparent);box-shadow:var(--pp-shadow-xl)}
.pp-ti--v-card .pp-ti__media img{box-shadow:none;border-radius:var(--pp-radius-card)}

@media (max-width:760px){.pp-ti,.pp-ti--v-wide-media,.pp-ti--v-wide-media.pp-ti--left{grid-template-columns:1fr}.pp-ti--left .pp-ti__text,.pp-ti--left .pp-ti__media{order:initial}}

/* ============================================================
   BENEFITS
   ============================================================ */
.pp-benefits__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);text-align:center;letter-spacing:-.02em}
.pp-benefits__subheading{color:var(--pp-text-muted);max-width:40em;margin:0 auto 2.4em;text-align:center;font-size:1.05rem}
.pp-benefits__grid{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;text-align:left}
.pp-benefit{background:var(--pp-bg);border:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent);border-radius:var(--pp-radius-lg);padding:28px;transition:transform 220ms var(--pp-ease-out),box-shadow 220ms ease;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 60ms)}
.pp-benefit:hover{transform:translateY(-2px);box-shadow:var(--pp-shadow-md)}
.pp-benefit__icon{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;color:var(--pp-primary);background:color-mix(in srgb, var(--pp-primary) 11%, transparent);border-radius:12px;margin-bottom:16px}
.pp-benefit__icon .pp-icon{width:22px;height:22px;display:block}
.pp-icon{display:inline-block;width:1em;height:1em;vertical-align:-.125em;flex:none}
.pp-benefit__title{font-size:1.1rem;margin:.2em 0 .5em;letter-spacing:-.01em}
.pp-benefit__desc{color:var(--pp-text-muted);margin:0;line-height:1.6}

/* cards-icon-top — icono más grande, énfasis vertical */
.pp-benefits--v-cards-icon-top .pp-benefits__grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px}
.pp-benefits--v-cards-icon-top .pp-benefit{padding:36px 28px;border:1px solid color-mix(in srgb, var(--pp-primary) 10%, transparent);box-shadow:0 1px 0 color-mix(in srgb, var(--pp-text) 4%, transparent),0 12px 24px -16px color-mix(in srgb, var(--pp-text) 18%, transparent)}
.pp-benefits--v-cards-icon-top .pp-benefit__icon{width:56px;height:56px;border-radius:16px;background:color-mix(in srgb, var(--pp-primary) 14%, transparent);box-shadow:inset 0 1px 0 color-mix(in srgb, var(--pp-bg) 80%, transparent);margin-bottom:20px}
.pp-benefits--v-cards-icon-top .pp-benefit__icon .pp-icon{width:28px;height:28px}

/* numbered — número grande, sin tarjeta */
.pp-benefits--v-numbered .pp-benefits__grid{grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:32px 28px}
.pp-benefits--v-numbered .pp-benefit{background:transparent;border:0;padding:0;border-top:1px solid color-mix(in srgb, var(--pp-text) 12%, transparent);padding-top:24px}
.pp-benefits--v-numbered .pp-benefit:hover{transform:none;box-shadow:none}
.pp-benefits--v-numbered .pp-benefit__num{display:block;font-family:var(--pp-font-heading);font-weight:800;font-size:clamp(2rem, 3.5vw, 2.8rem);line-height:1;color:var(--pp-primary);letter-spacing:-.04em;margin-bottom:14px;font-variant-numeric:tabular-nums}

/* offset-grid — módulo bento asimétrico, sin 3 columnas iguales */
.pp-benefits--v-offset-grid .pp-benefits__heading,.pp-benefits--v-offset-grid .pp-benefits__subheading{text-align:left;margin-left:0}
.pp-benefits--v-offset-grid .pp-benefits__grid{grid-template-columns:1.4fr .8fr 1fr;grid-auto-flow:dense;align-items:stretch}
.pp-benefits--v-offset-grid .pp-benefit:nth-child(1){grid-row:span 2;background:var(--pp-text);color:var(--pp-bg)}
.pp-benefits--v-offset-grid .pp-benefit:nth-child(1) .pp-benefit__desc{color:color-mix(in srgb,var(--pp-bg) 74%,transparent)}
.pp-benefits--v-offset-grid .pp-benefit:nth-child(3n){margin-top:34px}
.pp-benefits--v-offset-grid .pp-benefit{border-radius:calc(var(--pp-radius-card) * .7)}

/* manifesto — texto fuerte, sin cajas ni iconos */
.pp-benefits--v-manifesto .pp-benefits__heading{text-align:left;max-width:12ch;font-size:clamp(2.2rem,5.6vw,5.4rem);line-height:.94}
.pp-benefits--v-manifesto .pp-benefits__subheading{text-align:left;margin-left:0}
.pp-benefits--v-manifesto .pp-benefits__grid{display:block;border-top:1px solid color-mix(in srgb,var(--pp-text) 18%,transparent)}
.pp-benefits--v-manifesto .pp-benefit{display:grid;grid-template-columns:minmax(90px,.22fr) 1fr;gap:24px;background:transparent;border:0;border-bottom:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);border-radius:0;padding:26px 0}
.pp-benefits--v-manifesto .pp-benefit__icon{display:none}
.pp-benefits--v-manifesto .pp-benefit::before{content:counter(benefit,decimal-leading-zero);counter-increment:benefit;font-family:var(--pp-font-heading);font-weight:900;color:var(--pp-primary)}
.pp-benefits--v-manifesto .pp-benefits__grid{counter-reset:benefit}
.pp-benefits--v-manifesto .pp-benefit:hover{transform:none;box-shadow:none}

/* proof-strip — fila horizontal de argumentos compactos */
.pp-benefits--v-proof-strip .pp-benefits__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0;border-top:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);border-bottom:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent)}
.pp-benefits--v-proof-strip .pp-benefit{border:0;border-right:1px solid color-mix(in srgb,var(--pp-text) 10%,transparent);border-radius:0;background:transparent;box-shadow:none}
.pp-benefits--v-proof-strip .pp-benefit:last-child{border-right:0}
.pp-benefits--v-proof-strip .pp-benefit__icon{display:none}
@media (max-width:820px){.pp-benefits--v-offset-grid .pp-benefits__grid{grid-template-columns:1fr}.pp-benefits--v-offset-grid .pp-benefit:nth-child(3n){margin-top:0}.pp-benefits--v-manifesto .pp-benefit{grid-template-columns:1fr}.pp-benefits--v-proof-strip .pp-benefit{border-right:0;border-bottom:1px solid color-mix(in srgb,var(--pp-text) 10%,transparent)}}

@keyframes pp-rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media (prefers-reduced-motion:reduce){.pp-benefit{animation:none}}

/* ============================================================
   FAQ
   ============================================================ */
.pp-faq__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);text-align:center;margin-bottom:1.5em;letter-spacing:-.02em}
.pp-faq__list{max-width:44em;margin:0 auto;display:flex;flex-direction:column;gap:12px}
.pp-faq__item{border:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);border-radius:var(--pp-radius-card);background:var(--pp-bg);padding:0 22px;overflow:hidden;transition:border-color 200ms ease,box-shadow 200ms ease}
.pp-faq__item[open]{border-color:color-mix(in srgb, var(--pp-primary) 32%, transparent);box-shadow:0 8px 20px -12px color-mix(in srgb, var(--pp-primary) 28%, transparent)}
.pp-faq__q{cursor:pointer;font-weight:600;padding:18px 36px 18px 0;list-style:none;position:relative;letter-spacing:-.005em}
.pp-faq__q::-webkit-details-marker{display:none}
.pp-faq__q::after{content:"";position:absolute;right:4px;top:50%;width:14px;height:14px;border-right:2px solid var(--pp-text-muted);border-bottom:2px solid var(--pp-text-muted);transform:translateY(-65%) rotate(45deg);transition:transform 220ms var(--pp-ease-out)}
.pp-faq__item[open] .pp-faq__q::after{transform:translateY(-25%) rotate(-135deg);border-color:var(--pp-primary)}
.pp-faq__a{padding:0 0 18px;color:var(--pp-text-muted);line-height:1.65}
.pp-faq__a p:last-child{margin-bottom:0}

/* accordion — minimal sin tarjetas, separadores 1px */
.pp-faq--v-accordion .pp-faq__list{gap:0;max-width:48em}
.pp-faq--v-accordion .pp-faq__item{background:transparent;border:0;border-top:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);border-radius:0;padding:0}
.pp-faq--v-accordion .pp-faq__item:last-child{border-bottom:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent)}
.pp-faq--v-accordion .pp-faq__item[open]{box-shadow:none}
.pp-faq--v-accordion .pp-faq__q{padding:22px 36px 22px 0;font-size:1.05rem}

/* two-columns — dos columnas en md+ */
.pp-faq--v-two-columns .pp-faq__list{max-width:none;display:grid;grid-template-columns:1fr 1fr;gap:12px 24px}
@media (max-width:760px){.pp-faq--v-two-columns .pp-faq__list{grid-template-columns:1fr}}

/* ============================================================
   CTA
   ============================================================ */
.pp-cta__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);letter-spacing:-.02em}
.pp-cta__desc{max-width:40em;font-size:1.05rem;line-height:1.55}
.pp-cta__cta{margin:1.5em 0 0}

/* default — banner ancho */
.pp-section--cta.pp-section--cta:not(:has(.pp-cta--v-card)):not(:has(.pp-cta--v-split)):not(:has(.pp-cta--v-poster-close)):not(:has(.pp-cta--v-quiet-inline)){background:linear-gradient(135deg, var(--pp-primary) 0%, color-mix(in srgb, var(--pp-primary) 70%, var(--pp-accent)) 100%);color:var(--pp-on-primary)}
.pp-cta--v-default{text-align:center;color:var(--pp-on-primary)}
.pp-cta--v-default .pp-cta__heading{color:var(--pp-on-primary)}
.pp-cta--v-default .pp-cta__desc{color:color-mix(in srgb, var(--pp-on-primary) 92%, transparent);margin-left:auto;margin-right:auto}
.pp-cta--v-default .pp-btn--primary{background:var(--pp-on-primary);color:var(--pp-primary)}
.pp-cta--v-default .pp-btn--primary:hover{filter:none;background:color-mix(in srgb, var(--pp-on-primary) 92%, transparent)}

/* card — tarjeta centrada con sombra y patrón sutil */
.pp-cta--v-card{position:relative;max-width:60em;margin:0 auto;text-align:center;padding:clamp(40px, 5vw, 72px) clamp(28px, 5vw, 56px);border-radius:var(--pp-radius-2xl);background:linear-gradient(135deg, color-mix(in srgb, var(--pp-primary) 96%, var(--pp-on-primary)) 0%, color-mix(in srgb, var(--pp-primary) 75%, var(--pp-accent)) 100%);color:var(--pp-on-primary);overflow:hidden;box-shadow:0 30px 60px -28px color-mix(in srgb, var(--pp-primary) 50%, transparent),inset 0 1px 0 color-mix(in srgb, var(--pp-on-primary) 18%, transparent)}
.pp-cta--v-card::before{content:"";position:absolute;inset:0;background-image:radial-gradient(circle at 20% 0%, color-mix(in srgb, var(--pp-on-primary) 18%, transparent), transparent 40%),radial-gradient(circle at 80% 100%, color-mix(in srgb, var(--pp-on-primary) 12%, transparent), transparent 35%);pointer-events:none}
.pp-cta--v-card .pp-cta__heading{color:var(--pp-on-primary);position:relative}
.pp-cta--v-card .pp-cta__desc{color:color-mix(in srgb, var(--pp-on-primary) 92%, transparent);margin-left:auto;margin-right:auto;position:relative}
.pp-cta--v-card .pp-cta__cta{position:relative}
.pp-cta--v-card .pp-btn--primary{background:var(--pp-on-primary);color:var(--pp-primary)}

/* split — texto izq, botón derecha */
.pp-cta--v-split{display:grid;grid-template-columns:1.4fr 1fr;gap:32px;align-items:center;padding:clamp(28px, 4vw, 48px);border-radius:var(--pp-radius-xl);background:var(--pp-surface);border:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent)}
.pp-cta--v-split .pp-cta__text{min-width:0}
.pp-cta--v-split .pp-cta__heading{margin:0 0 .3em}
.pp-cta--v-split .pp-cta__desc{margin:0;color:var(--pp-text-muted)}
.pp-cta--v-split .pp-cta__action{justify-self:end}
@media (max-width:720px){.pp-cta--v-split{grid-template-columns:1fr}.pp-cta--v-split .pp-cta__action{justify-self:start}}

/* Cuando la sección es card o split, neutralizar el background full-bleed por defecto. */
.pp-section--cta:has(.pp-cta--v-card),
.pp-section--cta:has(.pp-cta--v-split),
.pp-section--cta:has(.pp-cta--v-quiet-inline){background:transparent;color:inherit}

/* poster-close — cierre contundente */
.pp-section--cta:has(.pp-cta--v-poster-close){background:var(--pp-text);color:var(--pp-bg)}
.pp-cta--v-poster-close{padding:clamp(56px,9vw,120px) 0;text-align:left}
.pp-cta--v-poster-close .pp-cta__heading{max-width:10ch;color:var(--pp-bg);font-size:clamp(2.8rem,8vw,7rem);line-height:.88;text-transform:uppercase}
.pp-cta--v-poster-close .pp-cta__desc{color:color-mix(in srgb,var(--pp-bg) 78%,transparent)}
.pp-cta--v-poster-close .pp-btn--primary{background:var(--pp-primary);color:var(--pp-text)}

/* quiet-inline — cierre discreto con separador */
.pp-cta--v-quiet-inline{display:grid;grid-template-columns:1.4fr auto;align-items:center;gap:28px;border-top:1px solid color-mix(in srgb,var(--pp-text) 16%,transparent);border-bottom:1px solid color-mix(in srgb,var(--pp-text) 16%,transparent);padding:28px 0}
.pp-cta--v-quiet-inline .pp-cta__heading{margin:0;font-size:clamp(1.35rem,2.5vw,2.1rem)}
.pp-cta--v-quiet-inline .pp-cta__desc{margin:.35rem 0 0;color:var(--pp-text-muted)}
.pp-cta--v-quiet-inline .pp-cta__cta{margin:0}
@media (max-width:720px){.pp-cta--v-quiet-inline{grid-template-columns:1fr}}

/* ============================================================
   FORM
   ============================================================ */
.pp-form{max-width:44em;margin:0 auto}
.pp-form__panel{background:var(--pp-bg)}
.pp-form__heading{text-align:center;font-size:clamp(1.7rem, 3.2vw, 2.4rem);letter-spacing:-.02em}
.pp-form__desc{text-align:center;color:var(--pp-text-muted);margin-bottom:2em}
.pp-form__row{margin-bottom:18px;display:flex;flex-direction:column;gap:6px}
.pp-form__label{font-weight:500;font-size:.95rem}
.pp-form__req{color:var(--pp-danger)}
.pp-form__control{width:100%;padding:12px 14px;border:1px solid color-mix(in srgb, var(--pp-text) 14%, transparent);border-radius:var(--pp-radius-sm);font:inherit;background:var(--pp-bg);color:var(--pp-text);transition:border-color 150ms ease,box-shadow 150ms ease}
.pp-form__control:focus{outline:none;border-color:var(--pp-primary);box-shadow:0 0 0 3px color-mix(in srgb, var(--pp-primary) 18%, transparent)}
.pp-form__check{display:flex;gap:8px;align-items:center}
.pp-form__notice{margin:0 0 18px;padding:14px 16px;border-radius:var(--pp-radius-card);font-weight:500}
.pp-form__notice--success{background:color-mix(in srgb, var(--pp-success) 12%, var(--pp-bg));color:color-mix(in srgb, var(--pp-success) 70%, var(--pp-text));border:1px solid color-mix(in srgb, var(--pp-success) 35%, transparent)}
.pp-form__notice--error{background:color-mix(in srgb, var(--pp-danger) 10%, var(--pp-bg));color:color-mix(in srgb, var(--pp-danger) 70%, var(--pp-text));border:1px solid color-mix(in srgb, var(--pp-danger) 30%, transparent)}
.pp-form__hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
/* E-GDPR G5 — checkbox de consentimiento de marketing y nota de privacidad */
.pp-form__row--consent{padding:14px 16px;border:1px solid color-mix(in srgb, var(--pp-text) 12%, transparent);border-radius:var(--pp-radius-sm);background:color-mix(in srgb, var(--pp-bg) 96%, var(--pp-text) 2%);margin-bottom:14px}
.pp-form__row--consent .pp-form__check{align-items:flex-start;gap:10px;font-size:.88rem;line-height:1.5;color:var(--pp-text-muted,#64748b)}
.pp-form__row--consent input[type="checkbox"]{margin-top:3px;flex-shrink:0}
.pp-form__privacy{font-size:.78rem;color:var(--pp-text-muted,#64748b);line-height:1.5;margin:14px 0 0;text-align:left;max-width:60ch}
.pp-form__privacy a{color:var(--pp-primary);text-decoration:underline;text-underline-offset:3px}

/* inline-card — formulario en tarjeta elevada */
.pp-form--v-inline-card{max-width:48em}
.pp-form--v-inline-card .pp-form__panel{background:var(--pp-bg);padding:clamp(32px, 4vw, 56px);border-radius:var(--pp-radius-xl);border:1px solid color-mix(in srgb, var(--pp-text) 7%, transparent);box-shadow:0 30px 60px -30px color-mix(in srgb, var(--pp-text) 22%, transparent)}

/* with-side-image — grid 1fr 1fr texto + imagen */
.pp-form--v-with-side-image{max-width:none;display:grid;grid-template-columns:1fr 1fr;gap:clamp(32px, 5vw, 64px);align-items:stretch}
.pp-form--v-with-side-image .pp-form__media{order:-1;align-self:stretch}
.pp-form--v-with-side-image .pp-form__media img{width:100%;height:100%;object-fit:cover;border-radius:var(--pp-radius-xl);min-height:320px;box-shadow:var(--pp-shadow-lg)}
.pp-form--v-with-side-image .pp-form__panel{padding:clamp(28px, 3vw, 48px);background:var(--pp-bg);border-radius:var(--pp-radius-xl);border:1px solid color-mix(in srgb, var(--pp-text) 7%, transparent)}
.pp-form--v-with-side-image .pp-form__heading,.pp-form--v-with-side-image .pp-form__desc{text-align:left}
@media (max-width:820px){.pp-form--v-with-side-image{grid-template-columns:1fr}.pp-form--v-with-side-image .pp-form__media img{min-height:220px}}

/* ============================================================
   TESTIMONIALS
   ============================================================ */
.pp-testimonials__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);text-align:center;letter-spacing:-.02em}
.pp-testimonials__subheading{color:var(--pp-text-muted);max-width:40em;margin:0 auto 2.4em;text-align:center;font-size:1.05rem}
.pp-testimonials__grid{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px}
.pp-testimonial{background:var(--pp-bg);border:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent);border-radius:var(--pp-radius-lg);padding:28px;display:flex;flex-direction:column;gap:18px;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 80ms);box-shadow:var(--pp-shadow-md)}
.pp-testimonial__quote{margin:0;font-size:1.05rem;line-height:1.55;color:var(--pp-text);position:relative;padding-left:0}
.pp-testimonial__quote::before{content:"";position:absolute;top:-.18em;left:-.05em;width:1.25em;height:1.25em;border-left:2px solid color-mix(in srgb, var(--pp-primary) 42%, transparent);border-top:2px solid color-mix(in srgb, var(--pp-primary) 42%, transparent);transform:rotate(-45deg);opacity:.8;pointer-events:none}
.pp-testimonial__caption{display:flex;align-items:center;gap:12px;margin-top:auto}
.pp-testimonial__avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;flex:none;box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--pp-text) 10%, transparent)}
.pp-testimonial__person{display:flex;flex-direction:column;font-size:.92rem;line-height:1.3}
.pp-testimonial__person strong{color:var(--pp-text);font-weight:600}
.pp-testimonial__role{color:var(--pp-text-muted);font-size:.85rem}

/* featured-quote */
.pp-testimonials--v-featured-quote .pp-testimonial--featured{max-width:48em;margin:0 auto;padding:clamp(36px, 5vw, 64px);text-align:center;background:transparent;border:0;box-shadow:none;animation:none}
.pp-testimonials--v-featured-quote .pp-testimonial__quote{font-size:clamp(1.4rem, 2.5vw, 2rem);line-height:1.35;color:var(--pp-text);font-weight:500;letter-spacing:-.015em;padding-left:0}
.pp-testimonials--v-featured-quote .pp-testimonial__quote::before{position:static;display:block;font-size:4rem;text-align:center;margin-bottom:.1em}
.pp-testimonials--v-featured-quote .pp-testimonial__caption{justify-content:center}

/* quote-wall — citas como pared editorial */
.pp-testimonials--v-quote-wall .pp-testimonials__heading{text-align:left;font-size:clamp(2.1rem,5vw,4.6rem);line-height:.96;max-width:12ch}
.pp-testimonials--v-quote-wall .pp-testimonials__subheading{text-align:left;margin-left:0}
.pp-testimonials--v-quote-wall .pp-testimonials__grid{columns:2 320px;display:block;gap:24px}
.pp-testimonials--v-quote-wall .pp-testimonial{break-inside:avoid;margin:0 0 24px;border-radius:0;border:0;border-top:1px solid color-mix(in srgb,var(--pp-text) 15%,transparent);box-shadow:none;background:transparent;padding:24px 0}
.pp-testimonials--v-quote-wall .pp-testimonial:nth-child(2n){padding-left:clamp(0px,4vw,54px)}

/* ============================================================
   STATS
   ============================================================ */
.pp-stats__heading{font-size:clamp(1.6rem, 3vw, 2.3rem);text-align:center;letter-spacing:-.02em}
.pp-stats__subheading{color:var(--pp-text-muted);max-width:40em;margin:0 auto 2em;text-align:center}
.pp-stats__grid{margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:32px;text-align:center}
.pp-stat{display:flex;flex-direction:column;gap:6px;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 70ms)}
.pp-stat__value{display:flex;align-items:baseline;justify-content:center;gap:2px;font-family:var(--pp-font-heading);font-weight:800;letter-spacing:-.04em;line-height:1;color:var(--pp-primary);font-variant-numeric:tabular-nums}
.pp-stat__num{font-size:clamp(2.4rem, 5vw, 3.6rem)}
.pp-stat__suffix{font-size:clamp(1.4rem, 3vw, 2rem);color:color-mix(in srgb, var(--pp-primary) 80%, var(--pp-accent))}
.pp-stat__label{margin:0;color:var(--pp-text-muted);font-size:.95rem;font-weight:500}

/* inline-bar — fila con divisores 1px (anti-card density) */
.pp-stats--v-inline-bar .pp-stats__grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0;border-top:1px solid color-mix(in srgb, var(--pp-text) 12%, transparent);border-bottom:1px solid color-mix(in srgb, var(--pp-text) 12%, transparent)}
.pp-stats--v-inline-bar .pp-stat{padding:28px 16px;border-right:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent)}
.pp-stats--v-inline-bar .pp-stat:last-child{border-right:0}
.pp-stats--v-inline-bar .pp-stat__num{font-size:clamp(2rem, 3.5vw, 2.6rem)}
.pp-stats--v-inline-bar .pp-stat__suffix{font-size:clamp(1.2rem, 2vw, 1.6rem)}
@media (max-width:680px){.pp-stats--v-inline-bar .pp-stat{border-right:0;border-bottom:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent)}.pp-stats--v-inline-bar .pp-stat:last-child{border-bottom:0}}

/* scoreboard — números de campaña */
.pp-stats--v-scoreboard{background:var(--pp-text);color:var(--pp-bg);padding:clamp(32px,5vw,64px);max-width:calc(var(--pp-container-max) - 48px)}
.pp-stats--v-scoreboard .pp-stats__heading,.pp-stats--v-scoreboard .pp-stats__subheading{color:var(--pp-bg);text-align:left;margin-left:0}
.pp-stats--v-scoreboard .pp-stats__grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1px;background:color-mix(in srgb,var(--pp-bg) 18%,transparent);text-align:left}
.pp-stats--v-scoreboard .pp-stat{background:var(--pp-text);padding:24px}
.pp-stats--v-scoreboard .pp-stat__value{justify-content:flex-start;color:var(--pp-primary)}
.pp-stats--v-scoreboard .pp-stat__label{color:color-mix(in srgb,var(--pp-bg) 72%,transparent)}

/* ============================================================
   GALLERY
   ============================================================ */
.pp-gallery__heading{font-size:clamp(1.7rem, 3vw, 2.4rem);text-align:center;letter-spacing:-.02em}
.pp-gallery__subheading{color:var(--pp-text-muted);max-width:40em;margin:0 auto 2em;text-align:center}
.pp-gallery__grid{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.pp-gallery__item{margin:0;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 50ms)}
.pp-gallery__figure{margin:0;position:relative;overflow:hidden;border-radius:var(--pp-radius-lg);background:var(--pp-surface);aspect-ratio:4/3}
.pp-gallery__img{width:100%;height:100%;object-fit:cover;transition:transform 600ms var(--pp-ease-out)}
.pp-gallery__figure:hover .pp-gallery__img{transform:scale(1.04)}
.pp-gallery__caption{position:absolute;left:0;right:0;bottom:0;padding:14px 16px;color:#fff;font-size:.9rem;font-weight:500;background:linear-gradient(180deg, transparent 0%, color-mix(in srgb, var(--pp-text) 78%, transparent) 100%)}

/* mosaic — primer item grande, asimetría */
.pp-gallery--v-mosaic .pp-gallery__grid{grid-template-columns:repeat(4, 1fr);grid-auto-rows:160px;gap:14px}
.pp-gallery--v-mosaic .pp-gallery__item:nth-child(6n+1){grid-column:span 2;grid-row:span 2}
.pp-gallery--v-mosaic .pp-gallery__item:nth-child(6n+4){grid-row:span 2}
.pp-gallery--v-mosaic .pp-gallery__figure{aspect-ratio:auto;height:100%}
@media (max-width:780px){.pp-gallery--v-mosaic .pp-gallery__grid{grid-template-columns:repeat(2,1fr)}.pp-gallery--v-mosaic .pp-gallery__item:nth-child(6n+1),.pp-gallery--v-mosaic .pp-gallery__item:nth-child(6n+4){grid-column:auto;grid-row:auto}}

/* editorial-strip — franja horizontal con crops variables */
.pp-gallery--v-editorial-strip .pp-gallery__heading,.pp-gallery--v-editorial-strip .pp-gallery__subheading{text-align:left;margin-left:0}
.pp-gallery--v-editorial-strip .pp-gallery__grid{display:grid;grid-template-columns:1.5fr .8fr 1.1fr .7fr;gap:12px;align-items:end}
.pp-gallery--v-editorial-strip .pp-gallery__figure{height:clamp(260px,38vw,520px);aspect-ratio:auto;border-radius:0}
.pp-gallery--v-editorial-strip .pp-gallery__item:nth-child(2n) .pp-gallery__figure{height:clamp(180px,28vw,360px)}
.pp-gallery--v-editorial-strip .pp-gallery__item:nth-child(3n) .pp-gallery__figure{height:clamp(220px,32vw,430px)}
@media (max-width:780px){.pp-gallery--v-editorial-strip .pp-gallery__grid{grid-template-columns:1fr 1fr}.pp-gallery--v-editorial-strip .pp-gallery__figure,.pp-gallery--v-editorial-strip .pp-gallery__item:nth-child(n) .pp-gallery__figure{height:260px}}

/* ============================================================
   STEPS
   ============================================================ */
.pp-steps__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);text-align:center;letter-spacing:-.02em}
.pp-steps__subheading{color:var(--pp-text-muted);max-width:40em;margin:0 auto 2.4em;text-align:center}
.pp-steps__list{list-style:none;padding:0;margin:0;counter-reset:step}
.pp-step{display:grid;grid-template-columns:auto 1fr;gap:20px 24px;padding-bottom:32px;position:relative;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 80ms)}
.pp-step:last-child{padding-bottom:0}
.pp-step__num{font-family:var(--pp-font-heading);font-weight:800;font-size:1.05rem;width:44px;height:44px;border-radius:50%;background:color-mix(in srgb, var(--pp-primary) 12%, transparent);color:var(--pp-primary);display:flex;align-items:center;justify-content:center;flex:none;letter-spacing:-.02em;font-variant-numeric:tabular-nums;box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--pp-primary) 30%, transparent)}
.pp-step__body{padding-top:8px}
.pp-step__title{font-size:1.15rem;margin:0 0 .35em;letter-spacing:-.01em}
.pp-step__desc{margin:0;color:var(--pp-text-muted);line-height:1.6;max-width:46em}

/* default — vertical con conector */
.pp-steps--v-default .pp-steps__list{max-width:44em;margin:0 auto}
.pp-steps--v-default .pp-step:not(:last-child)::before{content:"";position:absolute;left:21px;top:48px;bottom:8px;width:2px;background:color-mix(in srgb, var(--pp-primary) 18%, transparent);border-radius:2px}

/* horizontal — timeline */
.pp-steps--v-horizontal .pp-steps__list{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;counter-reset:step;align-items:start}
.pp-steps--v-horizontal .pp-step{grid-template-columns:1fr;gap:14px;padding-bottom:0;text-align:left;position:relative}
.pp-steps--v-horizontal .pp-step:not(:last-child)::after{content:"";position:absolute;top:21px;left:56px;right:-12px;height:2px;background:color-mix(in srgb, var(--pp-primary) 18%, transparent);border-radius:2px}
.pp-steps--v-horizontal .pp-step__body{padding-top:0}
@media (max-width:760px){.pp-steps--v-horizontal .pp-step:not(:last-child)::after{display:none}}

/* staggered-cards — tarjetas escalonadas */
.pp-steps--v-staggered-cards .pp-steps__heading,.pp-steps--v-staggered-cards .pp-steps__subheading{text-align:left;margin-left:0}
.pp-steps--v-staggered-cards .pp-steps__list{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.pp-steps--v-staggered-cards .pp-step{display:block;background:var(--pp-bg);border:1px solid color-mix(in srgb,var(--pp-text) 10%,transparent);padding:28px;border-radius:calc(var(--pp-radius-card) * .8)}
.pp-steps--v-staggered-cards .pp-step:nth-child(2){margin-top:44px}
.pp-steps--v-staggered-cards .pp-step:nth-child(3){margin-top:88px}
.pp-steps--v-staggered-cards .pp-step__num{margin-bottom:18px;border-radius:0}
@media (max-width:820px){.pp-steps--v-staggered-cards .pp-steps__list{grid-template-columns:1fr}.pp-steps--v-staggered-cards .pp-step:nth-child(n){margin-top:0}}

/* ============================================================
   LOGOS STRIP
   ============================================================ */
.pp-logos__heading{text-align:center;color:var(--pp-text-muted);font-size:.85rem;font-weight:600;letter-spacing:.14em;text-transform:uppercase;margin:0 0 1.6em}
.pp-logos__track-wrap{overflow:hidden;mask-image:linear-gradient(90deg, transparent 0, #000 8%, #000 92%, transparent 100%);-webkit-mask-image:linear-gradient(90deg, transparent 0, #000 8%, #000 92%, transparent 100%)}
.pp-logos__track{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:clamp(28px, 5vw, 56px)}
.pp-logos__cell{display:flex;align-items:center;justify-content:center;height:48px}
.pp-logos__img{max-height:40px;width:auto;opacity:.7;filter:grayscale(1);transition:opacity 200ms ease,filter 200ms ease}
.pp-logos__cell:hover .pp-logos__img{opacity:1;filter:grayscale(0)}

/* marquee — scroll infinito horizontal */
.pp-logos--v-marquee .pp-logos__track{flex-wrap:nowrap;justify-content:flex-start;width:max-content;animation:pp-marquee 38s linear infinite}
.pp-logos--v-marquee .pp-logos__track-wrap{padding:0}
.pp-logos--v-marquee .pp-logos__cell{flex:none}
@keyframes pp-marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
@media (prefers-reduced-motion:reduce){.pp-logos--v-marquee .pp-logos__track{animation:none;flex-wrap:wrap;justify-content:center;width:auto}}

/* ============================================================
   PRICING
   ============================================================ */
.pp-pricing__heading{font-size:clamp(1.7rem, 3.2vw, 2.5rem);text-align:center;letter-spacing:-.02em}
.pp-pricing__subheading{color:var(--pp-text-muted);max-width:42em;margin:0 auto 2.4em;text-align:center;font-size:1.05rem}
.pp-pricing__grid{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;align-items:stretch}
.pp-plan{position:relative;background:var(--pp-bg);border:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent);border-radius:var(--pp-radius-xl);padding:32px;display:flex;flex-direction:column;gap:14px;animation:pp-rise 480ms var(--pp-ease-out) both;animation-delay:calc(var(--pp-stagger,0) * 80ms);transition:transform 220ms var(--pp-ease-out)}
.pp-plan:hover{transform:translateY(-2px)}
.pp-plan__badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--pp-primary);color:var(--pp-on-primary);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:6px 14px;border-radius:999px;box-shadow:0 6px 14px -6px color-mix(in srgb, var(--pp-primary) 60%, transparent)}
.pp-plan--featured{border-color:color-mix(in srgb, var(--pp-primary) 40%, transparent);box-shadow:0 30px 60px -28px color-mix(in srgb, var(--pp-primary) 38%, transparent),inset 0 1px 0 color-mix(in srgb, var(--pp-bg) 50%, transparent);background:linear-gradient(180deg, color-mix(in srgb, var(--pp-primary) 5%, var(--pp-bg)) 0%, var(--pp-bg) 100%);transform:translateY(-4px)}
.pp-plan--featured:hover{transform:translateY(-6px)}
.pp-plan__name{font-size:1.1rem;margin:0;letter-spacing:-.01em;color:var(--pp-text-muted);font-weight:600}
.pp-plan--featured .pp-plan__name{color:var(--pp-primary)}
.pp-plan__desc{margin:0;color:var(--pp-text-muted);font-size:.92rem}
.pp-plan__price{margin:6px 0 4px;display:flex;align-items:baseline;gap:4px;font-family:var(--pp-font-heading)}
.pp-plan__amount{font-size:clamp(2.2rem, 4vw, 3rem);font-weight:800;letter-spacing:-.03em;color:var(--pp-text);line-height:1;font-variant-numeric:tabular-nums}
.pp-plan__period{color:var(--pp-text-muted);font-size:1rem;font-weight:500}
.pp-plan__features{list-style:none;padding:0;margin:6px 0 0;display:flex;flex-direction:column;gap:10px;flex:1}
.pp-plan__features li{position:relative;padding-left:26px;line-height:1.5;color:var(--pp-text)}
.pp-plan__features li::before{content:"";position:absolute;left:0;top:.45em;width:14px;height:14px;border-radius:50%;background:color-mix(in srgb, var(--pp-primary) 14%, transparent)}
.pp-plan__features li::after{content:"";position:absolute;left:4px;top:.7em;width:6px;height:3px;border-left:2px solid var(--pp-primary);border-bottom:2px solid var(--pp-primary);transform:rotate(-45deg)}
.pp-plan__cta{margin:auto 0 0}
.pp-plan__cta .pp-btn{width:100%;text-align:center}

/* comparison — más compacto, escala única */
.pp-pricing--v-comparison .pp-pricing__grid{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0;border:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);border-radius:var(--pp-radius-xl);overflow:hidden}
.pp-pricing--v-comparison .pp-plan{border:0;border-radius:0;padding:28px 24px;border-right:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent);background:var(--pp-bg);box-shadow:none;transform:none !important;animation:none}
.pp-pricing--v-comparison .pp-plan:last-child{border-right:0}
.pp-pricing--v-comparison .pp-plan--featured{background:color-mix(in srgb, var(--pp-primary) 5%, var(--pp-bg))}
.pp-pricing--v-comparison .pp-plan__amount{font-size:clamp(1.8rem, 3vw, 2.2rem)}
@media (max-width:780px){.pp-pricing--v-comparison .pp-plan{border-right:0;border-bottom:1px solid color-mix(in srgb, var(--pp-text) 8%, transparent)}.pp-pricing--v-comparison .pp-plan:last-child{border-bottom:0}}

/* editorial-list — precios como lista de oferta */
.pp-pricing--v-editorial-list .pp-pricing__heading{text-align:left;font-size:clamp(2rem,5vw,4.8rem);line-height:.95;max-width:12ch}
.pp-pricing--v-editorial-list .pp-pricing__subheading{text-align:left;margin-left:0}
.pp-pricing--v-editorial-list .pp-pricing__grid{display:block;border-top:1px solid color-mix(in srgb,var(--pp-text) 18%,transparent)}
.pp-pricing--v-editorial-list .pp-plan{display:grid;grid-template-columns:1fr auto;gap:18px 34px;border:0;border-bottom:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);border-radius:0;box-shadow:none;background:transparent;padding:28px 0}
.pp-pricing--v-editorial-list .pp-plan__price{grid-column:2;grid-row:1 / span 3;margin:0;align-self:start}
.pp-pricing--v-editorial-list .pp-plan__features{grid-column:1 / -1;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 18px}
.pp-pricing--v-editorial-list .pp-plan__cta{grid-column:1 / -1}

/* split-value — plan recomendado como bloque dominante */
.pp-pricing--v-split-value .pp-pricing__grid{grid-template-columns:1.15fr .85fr;align-items:stretch}
.pp-pricing--v-split-value .pp-plan{border-radius:0}
.pp-pricing--v-split-value .pp-plan--featured{grid-row:span 2;background:var(--pp-text);color:var(--pp-bg);transform:none}
.pp-pricing--v-split-value .pp-plan--featured .pp-plan__name,.pp-pricing--v-split-value .pp-plan--featured .pp-plan__amount{color:var(--pp-bg)}
.pp-pricing--v-split-value .pp-plan--featured .pp-plan__desc,.pp-pricing--v-split-value .pp-plan--featured .pp-plan__period,.pp-pricing--v-split-value .pp-plan--featured .pp-plan__features li{color:color-mix(in srgb,var(--pp-bg) 78%,transparent)}
.pp-pricing--v-split-value .pp-plan--featured .pp-plan__features li::before{background:color-mix(in srgb,var(--pp-primary) 55%,transparent)}
@media (max-width:780px){.pp-pricing--v-editorial-list .pp-plan{grid-template-columns:1fr}.pp-pricing--v-editorial-list .pp-plan__price{grid-column:auto;grid-row:auto}.pp-pricing--v-split-value .pp-pricing__grid{grid-template-columns:1fr}}

/* T18.4 — Atribución de imágenes del banco (Unsplash). Discreta pero visible. */
.pp-image-attr{display:block;margin-top:8px;font-size:.72rem;color:var(--pp-text-muted);letter-spacing:.02em;text-align:right}
.pp-image-attr a{color:inherit;text-decoration:underline;text-underline-offset:2px;text-decoration-color:color-mix(in srgb, var(--pp-text-muted) 40%, transparent)}
.pp-image-attr a:hover{color:var(--pp-text);text-decoration-color:currentColor}
.pp-hero__inner--bg .pp-image-attr{position:absolute;right:14px;bottom:6px;z-index:2;color:rgba(255,255,255,.78);text-shadow:0 1px 2px rgba(0,0,0,.4);margin:0}
.pp-hero__inner--bg .pp-image-attr a{color:rgba(255,255,255,.92)}
.pp-gallery__figure .pp-image-attr{position:absolute;left:8px;bottom:6px;color:rgba(255,255,255,.85);text-shadow:0 1px 2px rgba(0,0,0,.4);margin:0;font-size:.65rem}
.pp-gallery__figure .pp-image-attr a{color:rgba(255,255,255,.95)}

/* ============================================================
   F21.T21.2.d — Hero automático de entrada de blog
   Se inyecta delante del article_body en el render público.
   ============================================================ */
.pp-article-hero { padding: clamp(48px, 8vw, 96px) 0 0; background: var(--pp-bg); }
.pp-article-hero__inner { text-align: center; padding-bottom: clamp(28px, 4vw, 48px); }
.pp-article-hero__meta {
    font-size: .8rem;
    color: var(--pp-text-muted);
    letter-spacing: .04em;
    text-transform: uppercase;
    font-weight: 600;
    margin: 0 0 18px;
}
.pp-article-hero__meta time { font-variant-numeric: tabular-nums; }
.pp-article-hero__title {
    font-family: var(--pp-font-heading);
    font-weight: 700;
    font-size: clamp(2rem, 4.5vw, 3.4rem);
    letter-spacing: -.025em;
    line-height: 1.1;
    margin: 0 auto 18px;
    max-width: 22ch;
}
.pp-article-hero__lead {
    font-size: clamp(1.1rem, 1.6vw, 1.3rem);
    line-height: 1.55;
    color: var(--pp-text-muted);
    max-width: 56ch;
    margin: 0 auto;
}
.pp-article-hero__media {
    margin: 0 auto;
    max-width: 1080px;
    padding: 0 clamp(16px, 3vw, 32px);
}
.pp-article-hero__media img {
    width: 100%;
    height: auto;
    max-height: 540px;
    object-fit: cover;
    border-radius: var(--pp-radius-lg, 14px);
    display: block;
}
.pp-article-hero__media .pp-image-attr { text-align: center; margin-top: 4px; }

/* Si hay hero, eliminar el padding-top del primer article_body para que no acumule espacio */
.pp-article-hero + main .pp-section--article_body:first-of-type { padding-top: clamp(32px, 4vw, 48px); }
main:has(.pp-article-hero) .pp-section--article_body { padding-top: clamp(32px, 4vw, 48px); }

/* ============================================================
   F21.T21.2.d — Cuerpo de artículo (blog)
   Tipografía editorial: ancho cómodo, line-height generoso, jerarquía clara.
   Hereda fuentes y colores del design system, pero impone medida estricta
   para legibilidad larga.
   ============================================================ */
.pp-section--article_body { padding: clamp(32px, 5vw, 64px) 0; }
.pp-article-body { display: flex; justify-content: center; }
.pp-article-body__content {
    width: 100%;
    max-width: 65ch;      /* ancho de lectura cómodo (default) */
    font-size: clamp(1.05rem, 1.2vw, 1.18rem);
    line-height: 1.75;
    color: var(--pp-text);
}
.pp-article-body--narrow .pp-article-body__content { max-width: 55ch; }
.pp-article-body--wide   .pp-article-body__content { max-width: 75ch; }

/* Párrafos */
.pp-article-body__p { margin: 0 0 1.2em; }
.pp-article-body__p:first-child { font-size: 1.1em; color: color-mix(in srgb, var(--pp-text) 92%, transparent); }
.pp-article-body__content a { color: var(--pp-primary); text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; }
.pp-article-body__content a:hover { text-decoration-thickness: 2px; }

/* Encabezados internos */
.pp-article-body__h2 {
    font-family: var(--pp-font-heading);
    font-weight: 700;
    font-size: clamp(1.5rem, 2.4vw, 1.85rem);
    letter-spacing: -.02em;
    line-height: 1.25;
    margin: 1.8em 0 .6em;
    color: color-mix(in srgb, var(--pp-text) 96%, #000);
}
.pp-article-body__h3 {
    font-family: var(--pp-font-heading);
    font-weight: 700;
    font-size: clamp(1.2rem, 1.8vw, 1.4rem);
    letter-spacing: -.015em;
    line-height: 1.3;
    margin: 1.4em 0 .5em;
}

/* Imágenes embebidas */
.pp-article-body__figure {
    margin: 1.8em 0;
    /* Salir del max-width del content para imágenes más anchas */
    width: 100%;
}
.pp-article-body__figure img {
    width: 100%;
    height: auto;
    border-radius: var(--pp-radius-md, 10px);
    display: block;
}
.pp-article-body__figure figcaption {
    margin-top: .6em;
    font-size: .88em;
    line-height: 1.5;
    color: var(--pp-text-muted);
    font-style: italic;
    text-align: center;
}
.pp-article-body__figure .pp-image-attr { text-align: center; margin-top: 4px; }

/* Listas */
.pp-article-body__list { padding-left: 1.4em; margin: 1.2em 0 1.4em; }
.pp-article-body__list li { margin-bottom: .55em; padding-left: .2em; }
.pp-article-body__list--ul li::marker { color: var(--pp-primary); }
.pp-article-body__list--ol li::marker { color: var(--pp-primary); font-weight: 700; }

/* Cita destacada */
.pp-article-body__quote {
    margin: 2em 0;
    padding: 0 0 0 24px;
    border-left: 3px solid var(--pp-primary);
    font-style: italic;
    color: color-mix(in srgb, var(--pp-text) 88%, transparent);
}
.pp-article-body__quote p {
    font-size: 1.18em;
    line-height: 1.55;
    margin: 0 0 .4em;
}
.pp-article-body__quote cite {
    display: block;
    font-style: normal;
    font-size: .88em;
    color: var(--pp-text-muted);
    letter-spacing: .01em;
}

/* Divisor */
.pp-article-body__divider {
    border: 0;
    width: 80px;
    height: 1px;
    background: color-mix(in srgb, var(--pp-text) 18%, transparent);
    margin: 2.4em auto;
    position: relative;
}

/* Plantilla editorial visual — más revista, sin cambiar el contenido canónico */
.pp-article-page.pp-article-template--visual .pp-article-hero {
    padding-top: clamp(40px, 7vw, 88px);
    background:
        linear-gradient(180deg,
            color-mix(in srgb, var(--pp-primary) 7%, var(--pp-bg)) 0%,
            var(--pp-bg) 72%);
}
.pp-article-page.pp-article-template--visual .pp-article-hero__inner {
    text-align: left;
    display: grid;
    grid-template-columns: minmax(0, .72fr) minmax(220px, .28fr);
    gap: clamp(24px, 5vw, 72px);
    align-items: end;
    padding-bottom: clamp(32px, 5vw, 64px);
}
.pp-article-page.pp-article-template--visual .pp-article-hero__meta {
    color: color-mix(in srgb, var(--pp-primary) 72%, var(--pp-text));
    margin-bottom: 22px;
    grid-column: 1;
    grid-row: 1;
}
.pp-article-page.pp-article-template--visual .pp-article-hero__title {
    grid-column: 1;
    grid-row: 2;
    margin-inline: 0;
    max-width: 18ch;
    font-size: clamp(2.25rem, 6vw, 5rem);
    line-height: .98;
}
.pp-article-page.pp-article-template--visual .pp-article-hero__lead {
    grid-column: 2;
    grid-row: 1 / span 2;
    margin-inline: 0;
    max-width: 42ch;
    align-self: end;
    padding-bottom: .45em;
    font-size: clamp(1.02rem, 1.4vw, 1.22rem);
}
.pp-article-page.pp-article-template--visual .pp-article-hero__media {
    max-width: min(1240px, calc(100vw - 32px));
    padding-inline: clamp(16px, 4vw, 48px);
}
.pp-article-page.pp-article-template--visual .pp-article-hero__media img {
    aspect-ratio: 21 / 9;
    max-height: 620px;
    object-fit: cover;
    border-radius: clamp(14px, 2vw, 26px);
}
.pp-article-page.pp-article-template--visual .pp-section--article_body {
    padding-top: clamp(40px, 6vw, 84px);
}
.pp-article-page.pp-article-template--visual .pp-article-body {
    justify-content: flex-start;
}
.pp-article-page.pp-article-template--visual .pp-article-body__content {
    max-width: 70ch;
    font-size: clamp(1.06rem, 1.15vw, 1.2rem);
}
.pp-article-page.pp-article-template--visual .pp-article-body__p:first-child {
    font-size: clamp(1.16rem, 1.6vw, 1.38rem);
    line-height: 1.62;
}
.pp-article-page.pp-article-template--visual .pp-article-body__h2 {
    margin-top: 2.15em;
    padding-top: .65em;
    border-top: 1px solid color-mix(in srgb, var(--pp-text) 13%, transparent);
}
.pp-article-page.pp-article-template--visual .pp-article-body__figure {
    width: min(100%, 980px);
    margin: 2.2em 0;
}
.pp-article-page.pp-article-template--visual .pp-article-body__quote {
    margin: 2.4em 0;
    padding: 24px clamp(22px, 4vw, 42px);
    border-left: 0;
    border-top: 1px solid color-mix(in srgb, var(--pp-primary) 28%, transparent);
    border-bottom: 1px solid color-mix(in srgb, var(--pp-primary) 18%, transparent);
    background: color-mix(in srgb, var(--pp-primary) 6%, transparent);
}
@media (max-width: 780px) {
    .pp-article-page.pp-article-template--visual .pp-article-hero__inner {
        display: block;
        text-align: left;
    }
    .pp-article-page.pp-article-template--visual .pp-article-hero__lead {
        margin-top: 18px;
        padding-bottom: 0;
    }
    .pp-article-page.pp-article-template--visual .pp-article-hero__media img {
        aspect-ratio: 4 / 3;
        border-radius: var(--pp-radius-md, 10px);
    }
}

/* ============================================================
   F21.T21.3 — Listado de entradas (sección posts_listing)
   ============================================================ */
.pp-posts-listing__heading {
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    text-align: center;
    letter-spacing: -.02em;
    margin: 0 0 .35em;
}
.pp-posts-listing__subheading {
    color: var(--pp-text-muted);
    text-align: center;
    max-width: 50ch;
    margin: 0 auto 2.4em;
    font-size: 1.05rem;
    line-height: 1.55;
}

/* Card base — usado por todas las variantes con tweaks */
.pp-post-card {
    display: flex;
    flex-direction: column;
    background: transparent;
    color: inherit;
    text-decoration: none;
    border-radius: var(--pp-radius-md, 10px);
    overflow: hidden;
    transition: transform .22s var(--pp-ease-out, cubic-bezier(.16,1,.3,1)), box-shadow .22s ease;
}
.pp-post-card:hover { text-decoration: none; transform: translateY(-3px); }

.pp-post-card__media {
    position: relative;
    aspect-ratio: 16/10;
    overflow: hidden;
    background: var(--pp-surface, #f5f5f4);
    border-radius: var(--pp-radius-md, 10px);
}
.pp-post-card__media img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .6s var(--pp-ease-out, cubic-bezier(.16,1,.3,1));
}
.pp-post-card:hover .pp-post-card__media img { transform: scale(1.04); }
.pp-post-card__media--empty {
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--pp-primary) 14%, var(--pp-surface, #f5f5f4)),
        color-mix(in srgb, var(--pp-accent, var(--pp-primary)) 8%, var(--pp-surface, #f5f5f4)));
}

.pp-post-card__body {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 16px 4px 0;
}
.pp-post-card__meta {
    margin: 0;
    font-size: .78rem;
    color: var(--pp-text-muted);
    letter-spacing: .03em;
    text-transform: uppercase;
    font-weight: 600;
    display: flex; flex-wrap: wrap; gap: 4px;
}
.pp-post-card__title {
    margin: 0;
    font-family: var(--pp-font-heading);
    font-weight: 700;
    font-size: 1.18rem;
    letter-spacing: -.015em;
    line-height: 1.25;
    color: var(--pp-text);
    transition: color .15s ease;
}
.pp-post-card:hover .pp-post-card__title { color: var(--pp-primary); }
.pp-post-card__excerpt {
    margin: 0;
    color: var(--pp-text-muted);
    font-size: .95rem;
    line-height: 1.55;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Stagger animation (mismo patrón que el resto de listados) */
.pp-posts-listing__grid li,
.pp-posts-listing__list li,
.pp-posts-listing__rest li {
    animation: pp-rise 480ms var(--pp-ease-out, cubic-bezier(.16,1,.3,1)) both;
    animation-delay: calc(var(--pp-stagger, 0) * 60ms);
}
@media (prefers-reduced-motion: reduce) {
    .pp-posts-listing__grid li, .pp-posts-listing__list li, .pp-posts-listing__rest li {
        animation: none;
    }
}

/* Variante 1: default — grid de tarjetas */
.pp-posts-listing--v-default .pp-posts-listing__grid {
    list-style: none;
    padding: 0; margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 32px 24px;
}

/* Variante 2: editorial-list — lista 1-col con thumb lateral */
.pp-posts-listing--v-editorial-list .pp-posts-listing__list {
    list-style: none;
    padding: 0; margin: 0;
    max-width: 64em;
    margin-left: auto;
    margin-right: auto;
}
.pp-posts-listing--v-editorial-list .pp-posts-listing__list li {
    padding: 28px 0;
    border-top: 1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);
}
.pp-posts-listing--v-editorial-list .pp-posts-listing__list li:last-child {
    border-bottom: 1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);
}
.pp-posts-listing--v-editorial-list .pp-post-card--row {
    flex-direction: row;
    align-items: center;
    gap: 28px;
}
.pp-posts-listing--v-editorial-list .pp-post-card--row .pp-post-card__media {
    flex: 0 0 220px;
    aspect-ratio: 4/3;
    border-radius: var(--pp-radius-md, 10px);
}
.pp-posts-listing--v-editorial-list .pp-post-card--row .pp-post-card__body {
    flex: 1;
    min-width: 0;
    padding: 0;
}
.pp-posts-listing--v-editorial-list .pp-post-card__title {
    font-size: clamp(1.2rem, 1.6vw, 1.5rem);
}
@media (max-width: 720px) {
    .pp-posts-listing--v-editorial-list .pp-post-card--row { flex-direction: column; align-items: stretch; }
    .pp-posts-listing--v-editorial-list .pp-post-card--row .pp-post-card__media { flex: none; }
}

/* Variante 3: featured-first — 1 destacada + grid pequeño */
.pp-posts-listing--v-featured-first .pp-posts-listing__featured {
    margin-bottom: 40px;
}
.pp-posts-listing--v-featured-first .pp-post-card--featured {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: clamp(24px, 4vw, 56px);
    align-items: center;
    padding: 0;
}
.pp-posts-listing--v-featured-first .pp-post-card--featured .pp-post-card__media {
    aspect-ratio: 4/3;
    border-radius: var(--pp-radius-lg, 14px);
}
.pp-posts-listing--v-featured-first .pp-post-card--featured .pp-post-card__body {
    padding: 0;
}
.pp-posts-listing--v-featured-first .pp-post-card--featured .pp-post-card__title {
    font-size: clamp(1.5rem, 2.6vw, 2rem);
    margin: .2em 0;
}
.pp-posts-listing--v-featured-first .pp-post-card--featured .pp-post-card__excerpt {
    font-size: 1.05rem;
    -webkit-line-clamp: 4;
}
@media (max-width: 800px) {
    .pp-posts-listing--v-featured-first .pp-post-card--featured { grid-template-columns: 1fr; }
}
.pp-posts-listing--v-featured-first .pp-posts-listing__rest {
    list-style: none;
    padding: 0; margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 24px 20px;
    padding-top: 32px;
    border-top: 1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);
}
.pp-posts-listing--v-featured-first .pp-post-card--compact .pp-post-card__media { aspect-ratio: 4/3; }
.pp-posts-listing--v-featured-first .pp-post-card--compact .pp-post-card__title {
    font-size: 1.02rem;
}

/* Generic */
.pp-generic h2{font-size:clamp(1.5rem, 2.5vw, 2rem)}
CSS;
    }
}
