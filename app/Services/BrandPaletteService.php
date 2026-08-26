<?php

namespace App\Services;

use App\Services\BrandColorExtractor;
use App\Services\LanguageService;
use Core\Database;

/**
 * ONB2 O2.5/O2.6 — Paleta del sitio derivada de los colores de la marca.
 *
 * Dos responsabilidades:
 *
 *  1. **Sanear lo que devuelve la IA.** Un modelo produce paletas bonitas que
 *     fallan AA justo donde duele: el texto secundario y el texto sobre el
 *     botón. Aquí el contraste no se pide, se COMPRUEBA y se corrige; y si una
 *     propuesta no se puede arreglar, se descarta. Eso además hace la
 *     generación testeable sin llamar al modelo.
 *  2. **Guardar la paleta elegida** (`site_palette_custom`) y servirla a
 *     `VisualStyleService::paletteForSite()`, que la mira ANTES que el preset.
 *
 * Las claves son las mismas que declara `PalettePresets`, para que el resto del
 * motor no note la diferencia: bg, surface, text, muted, line, accent,
 * accent_dark, accent_2.
 */
final class BrandPaletteService
{
    public const SETTING_KEY = 'site_palette_custom';

    public const KEYS = ['bg', 'surface', 'text', 'muted', 'line', 'accent', 'accent_dark', 'accent_2'];

    /** Mínimos de contraste (WCAG AA para texto; el resto, legibilidad real). */
    private const MIN_TEXT = 4.5;
    /**
     * Al CORREGIR el texto apuntamos a AAA, no al mínimo. Si lo dejáramos justo
     * en 4,5 el texto apagado (que también exige 4,5) acabaría en el mismo tono
     * y se perdería la jerarquía: en una prueba salieron #757575 y #747474.
     */
    private const TARGET_TEXT = 7.0;
    private const MIN_MUTED = 4.5;
    private const MIN_LINE = 1.4;
    private const MIN_ACCENT_ON_BG = 2.5;
    private const MIN_LABEL_ON_ACCENT = 4.5;

    /** Pasos máximos de corrección antes de dar una propuesta por imposible. */
    private const MAX_STEPS = 60;

    // =====================================================================
    // Persistencia
    // =====================================================================

    /** @return array<string,string>|null */
    public static function forSite(int $siteId): ?array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::SETTING_KEY]
        );
        $raw = trim((string) ($row['setting_value'] ?? ''));
        if ($raw === '') return null;

        $data = json_decode($raw, true);
        return is_array($data) ? self::normalize($data) : null;
    }

    // =====================================================================
    // DESIGN-MANDA T11 — Materia prima y propuestas.
    //
    // Todo esto vivía en `OnboardingController`, así que el editor bueno de
    // paleta solo existía dentro de un flujo de un solo uso. Al bajarlo aquí,
    // el paso 2 del onboarding y la pestaña Diseño comparten LÓGICA, no copias.
    // =====================================================================

    /** Clave de los colores de MARCA (materia prima, no la paleta de la web). */
    public const BRAND_COLORS_KEY = 'site_brand_palette';

    public const BRAND_COLORS_MAX = 5;

    /**
     * Colores de marca declarados por el usuario (manual de marca o extraídos
     * del logo). No son la paleta de la web: son con lo que se deriva.
     *
     * @return array<int,string>
     */
    public static function brandColors(int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::BRAND_COLORS_KEY]
        );
        $list = json_decode((string) ($row['setting_value'] ?? '[]'), true);
        if (!is_array($list)) return [];

        return self::cleanHexList($list);
    }

    /** @param array<int,mixed> $colors */
    public static function saveBrandColors(int $siteId, array $colors): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::BRAND_COLORS_KEY, json_encode(self::cleanHexList($colors), JSON_UNESCAPED_SLASHES)]
        );
    }

    /**
     * Normaliza una lista suelta de hex: descarta lo inválido, quita repetidos
     * y corta al máximo.
     *
     * @param array<int,mixed> $raw
     * @return array<int,string>
     */
    public static function cleanHexList(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $hex = self::hex((string) $value);
            if ($hex !== null && !in_array($hex, $out, true)) $out[] = $hex;
            if (count($out) >= self::BRAND_COLORS_MAX) break;
        }
        return $out;
    }

    /**
     * Colores dominantes de los logos del sitio (claro y oscuro).
     *
     * @return array<int,string>
     */
    public static function extractFromLogos(int $siteId): array
    {
        $colors = [];
        foreach (['site_logo_path', 'site_logo_dark_path'] as $key) {
            $row = Database::selectOne(
                'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
                [$siteId, $key]
            );
            $rel = trim((string) ($row['setting_value'] ?? ''));
            if ($rel === '') continue;

            $path = PP_ROOT . '/' . ltrim($rel, '/');
            if (!is_file($path)) continue;

            foreach (BrandColorExtractor::fromFile($path) as $hex) {
                if (!in_array($hex, $colors, true)) $colors[] = $hex;
            }
        }
        return array_slice($colors, 0, self::BRAND_COLORS_MAX);
    }

    /**
     * Propuestas de paleta para la web a partir de los colores de marca.
     *
     * Lo que devuelve el modelo pasa SIEMPRE por el validador de contraste; si
     * una propuesta no se puede arreglar, no se enseña. Si la IA falla entera,
     * se cae a las recetas curadas para no dejar al usuario sin nada que elegir.
     *
     * @param array<int,string> $brandColors
     * @return array{palettes:array<int,array<string,mixed>>,model:string,fallback:bool,error:string}
     */
    public static function propose(int $siteId, array $brandColors, string $styleHint = ''): array
    {
        $proposals = [];
        $model = '';
        $error = '';

        try {
            $result = \App\Services\AI\AIActionRunner::run(\App\Services\AI\Actions::GENERATE_SITE_PALETTE, [
                'brand_colors'     => implode(', ', $brandColors),
                'business_context' => self::businessContext($siteId),
                'language'         => LanguageService::promptLabelFor($siteId),
                'design_language'  => $styleHint !== '' ? $styleHint : '(sin referencia)',
            ], $siteId);
            $model = (string) ($result['model'] ?? '');
            $proposals = self::normalizeProposals((array) ($result['data'] ?? []));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $fallback = false;
        if ($proposals === []) {
            $proposals = self::fallbackProposals($brandColors);
            $fallback = true;
        }

        return ['palettes' => $proposals, 'model' => $model, 'fallback' => $fallback, 'error' => $error];
    }

    /**
     * @param array<string,mixed> $data respuesta cruda del modelo
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeProposals(array $data): array
    {
        $list = $data['palettes'] ?? [];
        if (!is_array($list)) return [];

        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $tokens = self::enforceContrast((array) ($item['tokens'] ?? []));
            if ($tokens === null) continue;   // irreparable: fuera
            $out[] = [
                'name'      => mb_substr(trim((string) ($item['name'] ?? 'Paleta')), 0, 60) ?: 'Paleta',
                'rationale' => mb_substr(trim((string) ($item['rationale'] ?? '')), 0, 240),
                'tokens'    => $tokens,
                'source'    => 'ai',
            ];
            if (count($out) >= 3) break;
        }
        return $out;
    }

    /** Resumen corto del negocio para que la paleta no sea un ejercicio abstracto. */
    private static function businessContext(int $siteId): string
    {
        $rows = Database::select(
            'SELECT field_key, field_value FROM site_memory WHERE site_id = ? AND field_key IN (?, ?, ?)',
            [$siteId, 'business_description', 'target_audience', 'tone_of_voice']
        );
        $parts = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['field_value'] ?? ''));
            if ($value !== '') $parts[] = $row['field_key'] . ': ' . mb_substr($value, 0, 400);
        }
        // i18n-ignore: relleno del prompt cuando no hay datos.
        return $parts === [] ? '(sin datos; usa un registro sobrio y profesional)' : implode("\n", $parts);
    }

    /** @param array<string,string> $tokens */
    public static function save(int $siteId, array $tokens): bool
    {
        $clean = self::normalize($tokens);
        if ($clean === null) return false;

        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::SETTING_KEY, json_encode($clean, JSON_UNESCAPED_SLASHES)]
        );
        return true;
    }

    public static function clear(int $siteId): void
    {
        Database::execute(
            'DELETE FROM settings WHERE site_id = ? AND setting_key = ?',
            [$siteId, self::SETTING_KEY]
        );
    }

    // =====================================================================
    // Saneado
    // =====================================================================

    /**
     * Deja solo las 8 claves, en HEX de 6 dígitos. Si falta alguna, no hay
     * paleta: preferimos no tener a tener una a medias.
     *
     * @param array<string,mixed> $raw
     * @return array<string,string>|null
     */
    public static function normalize(array $raw): ?array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $hex = self::hex((string) ($raw[$key] ?? ''));
            if ($hex === null) return null;
            $out[$key] = $hex;
        }
        return $out;
    }

    /**
     * Toma una propuesta cruda y devuelve una que CUMPLE los mínimos, o null si
     * no se puede llegar sin desfigurarla.
     *
     * La corrección es siempre de luminosidad (HSL), nunca de tono: el tono es
     * la decisión de diseño; la luminosidad es la que hace legible o ilegible.
     *
     * @param array<string,mixed> $raw
     * @return array<string,string>|null
     */
    public static function enforceContrast(array $raw): ?array
    {
        $p = self::normalize($raw);
        if ($p === null) return null;

        $darkBg = self::relativeLuminance($p['bg']) < 0.5;

        // Texto y texto apagado: se alejan del fondo (a negro si el fondo es
        // claro, a blanco si es oscuro) hasta cumplir.
        $p['text'] = self::pushUntil($p['text'], $p['bg'], self::TARGET_TEXT, $darkBg);
        if (self::contrast($p['text'], $p['bg']) < self::MIN_TEXT) return null;

        // El texto tiene que leerse también sobre las secciones, no solo sobre
        // el fondo de la página.
        if (self::contrast($p['text'], $p['surface']) < self::MIN_TEXT) {
            $p['surface'] = self::pushUntil($p['surface'], $p['text'], self::TARGET_TEXT, !$darkBg);
            if (self::contrast($p['text'], $p['surface']) < self::MIN_TEXT) return null;
        }

        $p['muted'] = self::pushUntil($p['muted'], $p['bg'], self::MIN_MUTED, $darkBg);
        if (self::contrast($p['muted'], $p['bg']) < self::MIN_MUTED) return null;

        // La línea no es texto: solo tiene que verse.
        $p['line'] = self::pushUntil($p['line'], $p['bg'], self::MIN_LINE, $darkBg);

        // El acento es fondo de botón: tiene que despegarse del fondo Y admitir
        // una etiqueta legible encima (blanco o negro, lo decide el contraste).
        $p['accent'] = self::pushUntil($p['accent'], $p['bg'], self::MIN_ACCENT_ON_BG, $darkBg);
        if (self::contrast($p['accent'], $p['bg']) < self::MIN_ACCENT_ON_BG) return null;
        if (self::bestLabelContrast($p['accent']) < self::MIN_LABEL_ON_ACCENT) {
            $p['accent'] = self::pushLabelContrast($p['accent']);
            if (self::bestLabelContrast($p['accent']) < self::MIN_LABEL_ON_ACCENT) return null;
        }

        // El acento oscuro es el hover: siempre más oscuro que el acento.
        if (self::relativeLuminance($p['accent_dark']) >= self::relativeLuminance($p['accent'])) {
            $p['accent_dark'] = self::shiftLightness($p['accent'], -0.14);
        }

        // El segundo acento también se usa sobre el fondo (badges, subrayados).
        $p['accent_2'] = self::pushUntil($p['accent_2'], $p['bg'], self::MIN_ACCENT_ON_BG, $darkBg);

        return $p;
    }

    /**
     * Comprobación pura, para tests y para decidir si algo hay que tocarlo.
     *
     * @param array<string,string> $p
     * @return array<int,string> lista de incumplimientos ([] = todo correcto)
     */
    // i18n-ignore-start: diagnóstico de contraste que solo se registra en el
    // log y se le pasa a la IA para que corrija la paleta; no se pinta.
    public static function contrastIssues(array $p): array
    {
        $clean = self::normalize($p);
        if ($clean === null) return ['paleta incompleta'];

        $issues = [];
        $add = function (string $label, float $value, float $min) use (&$issues): void {
            if ($value < $min) $issues[] = sprintf('%s: %.2f (mínimo %.2f)', $label, $value, $min);
        };
        $add('texto sobre fondo', self::contrast($clean['text'], $clean['bg']), self::MIN_TEXT);
        $add('texto sobre superficie', self::contrast($clean['text'], $clean['surface']), self::MIN_TEXT);
        $add('texto apagado sobre fondo', self::contrast($clean['muted'], $clean['bg']), self::MIN_MUTED);
        $add('línea sobre fondo', self::contrast($clean['line'], $clean['bg']), self::MIN_LINE);
        $add('acento sobre fondo', self::contrast($clean['accent'], $clean['bg']), self::MIN_ACCENT_ON_BG);
        $add('etiqueta sobre acento', self::bestLabelContrast($clean['accent']), self::MIN_LABEL_ON_ACCENT);
        return $issues;
    }

    // i18n-ignore-end

    /**
     * DESIGN-MANDA T3/T5 — El mismo diagnóstico que `contrastIssues()`, pero en
     * DATOS en vez de en frases: el panel está traducido (ADMIN-I18N) y necesita
     * poner cada incumplimiento en el idioma del gestor.
     *
     * `contrastIssues()` se queda como está: sus frases viajan al log y a la IA,
     * que es carga, no interfaz.
     *
     * @param array<string,string> $p
     * @return array<int,array{pair:string,value:float,min:float}>
     */
    public static function contrastReport(array $p): array
    {
        $clean = self::normalize($p);
        if ($clean === null) return [];

        $checks = [
            ['text_on_bg',      self::contrast($clean['text'], $clean['bg']),        self::MIN_TEXT],
            ['text_on_surface', self::contrast($clean['text'], $clean['surface']),   self::MIN_TEXT],
            ['muted_on_bg',     self::contrast($clean['muted'], $clean['bg']),       self::MIN_MUTED],
            ['line_on_bg',      self::contrast($clean['line'], $clean['bg']),        self::MIN_LINE],
            ['accent_on_bg',    self::contrast($clean['accent'], $clean['bg']),      self::MIN_ACCENT_ON_BG],
            ['label_on_accent', self::bestLabelContrast($clean['accent']),           self::MIN_LABEL_ON_ACCENT],
        ];

        $out = [];
        foreach ($checks as [$pair, $value, $min]) {
            if ($value < $min) {
                $out[] = ['pair' => $pair, 'value' => round($value, 2), 'min' => $min];
            }
        }
        return $out;
    }

    /** Color de texto legible sobre un fondo: blanco o negro, el que gane. */
    public static function labelOn(string $background): string
    {
        return self::contrast('#ffffff', $background) >= self::contrast('#111111', $background)
            ? '#ffffff'
            : '#111111';
    }

    // =====================================================================
    // Propuestas de reserva (sin IA)
    // =====================================================================

    /**
     * Si la IA no está disponible, el paso no puede quedarse sin paletas. Estas
     * salen de las recetas curadas de `PalettePresets` adaptadas al color
     * principal de la marca — el mismo camino que usaba el paso antes de O2.5.
     *
     * @param array<int,string> $brandColors
     * @return array<int,array<string,mixed>>
     */
    public static function fallbackProposals(array $brandColors, int $limit = 3): array
    {
        $primary = '';
        foreach ($brandColors as $candidate) {
            $hex = self::hex((string) $candidate);
            if ($hex !== null) { $primary = $hex; break; }
        }

        $out = [];
        foreach (['studio-mono', 'ink-bone', 'night-citrus', 'cream-ink'] as $slug) {
            $preset = PalettePresets::get($slug);
            $tokens = self::enforceContrast(PalettePresets::tokens($slug, $primary !== '' ? $primary : null));
            if ($tokens === null) continue;
            $out[] = [
                'name' => (string) ($preset['label'] ?? $slug),
                'rationale' => (string) ($preset['description'] ?? ''),
                'tokens' => $tokens,
                'source' => 'preset',
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    // =====================================================================
    // Color
    // =====================================================================

    public static function contrast(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);
        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $channel = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * Empuja la luminosidad de $color hasta que contraste lo suficiente con
     * $against. `$toLight` decide la dirección: hacia el blanco o hacia el negro.
     */
    private static function pushUntil(string $color, string $against, float $min, bool $toLight): string
    {
        $step = $toLight ? 0.02 : -0.02;
        $current = $color;
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            if (self::contrast($current, $against) >= $min) return $current;
            $next = self::shiftLightness($current, $step);
            if ($next === $current) break;   // tope: ya es blanco o negro puro
            $current = $next;
        }
        return $current;
    }

    /** Igual, pero contra la mejor etiqueta posible (blanco o negro). */
    private static function pushLabelContrast(string $accent): string
    {
        // Hacia donde ya esté más cerca de resolverse.
        $toLight = self::contrast('#111111', $accent) > self::contrast('#ffffff', $accent);
        $current = $accent;
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            if (self::bestLabelContrast($current) >= self::MIN_LABEL_ON_ACCENT) return $current;
            $next = self::shiftLightness($current, $toLight ? 0.02 : -0.02);
            if ($next === $current) break;
            $current = $next;
        }
        return $current;
    }

    private static function bestLabelContrast(string $background): float
    {
        return max(self::contrast('#ffffff', $background), self::contrast('#111111', $background));
    }

    public static function shiftLightness(string $hex, float $delta): string
    {
        [$r, $g, $b] = self::rgb($hex);
        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);
        $l = max(0.0, min(1.0, $l + $delta));
        return self::hslToHex($h, $s, $l);
    }

    private static function hex(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v !== '' && $v[0] !== '#') $v = '#' . $v;
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $clean = self::hex($hex) ?? '#000000';
        return [
            (int) hexdec(substr($clean, 1, 2)),
            (int) hexdec(substr($clean, 3, 2)),
            (int) hexdec(substr($clean, 5, 2)),
        ];
    }

    /** @return array{0:float,1:float,2:float} */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255; $gf = $g / 255; $bf = $b / 255;
        $max = max($rf, $gf, $bf); $min = min($rf, $gf, $bf);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) return [0.0, 0.0, $l];
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max === $rf)      $h = fmod((($gf - $bf) / $d) + ($gf < $bf ? 6 : 0), 6);
        elseif ($max === $gf)  $h = (($bf - $rf) / $d) + 2;
        else                   $h = (($rf - $gf) / $d) + 4;
        return [$h * 60, $s, $l];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $hp = fmod(($h < 0 ? $h + 360 : $h), 360) / 60;
        $x = $c * (1 - abs(fmod($hp, 2) - 1));
        $m = $l - $c / 2;
        $set = match (true) {
            $hp < 1 => [$c, $x, 0.0],
            $hp < 2 => [$x, $c, 0.0],
            $hp < 3 => [0.0, $c, $x],
            $hp < 4 => [0.0, $x, $c],
            $hp < 5 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };
        return sprintf(
            '#%02x%02x%02x',
            (int) round(($set[0] + $m) * 255),
            (int) round(($set[1] + $m) * 255),
            (int) round(($set[2] + $m) * 255)
        );
    }
}
