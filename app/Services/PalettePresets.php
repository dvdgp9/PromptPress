<?php

namespace App\Services;

/**
 * T18.x — Paletas presets curadas.
 *
 * Sustituyen la generación automática por desaturación (que tiraba todo a
 * tonos terracota). Cada paleta es una combinación trabajada a mano de
 * fondos, tipografía y acentos, pensada para encajar con un visual_style
 * concreto y darle identidad propia a cada plantilla.
 *
 * Cada paleta declara las mismas claves que VisualStyleService::paletteForSite():
 *   bg, surface, text, muted, line, accent, accent_dark, accent_2
 *
 * Si una plantilla declara `palette_preset`, sobrescribe a la generación
 * automática del visual_style (que sigue funcionando como fallback).
 */
final class PalettePresets
{
    public const SETTING_KEY = 'site_palette_preset';

    /**
     * @return array<string,array<string,string>>
     */
    public static function all(): array
    {
        return [
            'studio-mono' => [
                'label' => 'Studio mono',
                'description' => 'Blanco, negro y un acento eléctrico. Clean & confident.',
                'bg' => '#ffffff',
                'surface' => '#f5f5f4',
                'text' => '#0a0a0a',
                'muted' => '#666666',
                'line' => '#dcdcd8',
                'accent' => '#0a0a0a',
                'accent_dark' => '#000000',
                'accent_2' => '#ff3b00',
            ],
            'cream-ink' => [
                'label' => 'Crema y tinta',
                'description' => 'Cálido y editorial. Cremas, tinta carbón y mostaza tostada.',
                'bg' => '#f6efe2',
                'surface' => '#fffbf0',
                'text' => '#1a1814',
                'muted' => '#6b6457',
                'line' => '#dcd3bf',
                'accent' => '#1a1814',
                'accent_dark' => '#000000',
                'accent_2' => '#c97a2b',
            ],
            'ink-bone' => [
                'label' => 'Hueso & cobalto',
                'description' => 'Crudo, alto contraste, acento azul cobalto. Galería contemporánea.',
                'bg' => '#f0eee7',
                'surface' => '#ffffff',
                'text' => '#0a0a0a',
                'muted' => '#5d5d5d',
                'line' => '#ccc7bd',
                'accent' => '#1f4eff',
                'accent_dark' => '#0028b3',
                'accent_2' => '#0a0a0a',
            ],
            'night-citrus' => [
                'label' => 'Noche cítrico',
                'description' => 'Azul medianoche con acento naranja. Para campañas y producto.',
                'bg' => '#0d121c',
                'surface' => '#16203a',
                'text' => '#f4f6fb',
                'muted' => '#8a92ad',
                'line' => '#283456',
                'accent' => '#ff8a3d',
                'accent_dark' => '#cc5e15',
                'accent_2' => '#5fc8d7',
            ],
            'agave' => [
                'label' => 'Agave',
                'description' => 'Verde profundo + arena. Botánico, ético, cercano.',
                'bg' => '#f4f1e6',
                'surface' => '#ffffff',
                'text' => '#1c2818',
                'muted' => '#5a6757',
                'line' => '#d2cdb6',
                'accent' => '#3f6d33',
                'accent_dark' => '#2a4a22',
                'accent_2' => '#c19c4f',
            ],
            'boutique-rosa' => [
                'label' => 'Boutique rosa',
                'description' => 'Rosados pálidos con bordó. Femenino, premium, nada cliché.',
                'bg' => '#fbf2ee',
                'surface' => '#ffffff',
                'text' => '#3b1c1c',
                'muted' => '#8b5e5e',
                'line' => '#ead5cf',
                'accent' => '#a33049',
                'accent_dark' => '#74172e',
                'accent_2' => '#ce8b76',
            ],
            'depth-teal' => [
                'label' => 'Teal profundo',
                'description' => 'Verde azulado oscuro con acento ámbar. Lujoso y nocturno.',
                'bg' => '#0f1d22',
                'surface' => '#162a30',
                'text' => '#eef6f3',
                'muted' => '#8aa19b',
                'line' => '#22404a',
                'accent' => '#f9b14a',
                'accent_dark' => '#cf8a23',
                'accent_2' => '#75e0c1',
            ],
            'paper-cobalt' => [
                'label' => 'Papel cobalto',
                'description' => 'Papel crudo con cobalto y mostaza. Editorial técnico, B2B con carácter.',
                'bg' => '#fcfaf4',
                'surface' => '#ffffff',
                'text' => '#0d1a3d',
                'muted' => '#586089',
                'line' => '#d8d4c2',
                'accent' => '#1f3df0',
                'accent_dark' => '#0824b6',
                'accent_2' => '#f2c94c',
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function defaultSlug(): string
    {
        return 'studio-mono';
    }

    public static function normalizeSlug(?string $slug): string
    {
        $slug = trim((string) $slug);
        return self::get($slug) ? $slug : self::defaultSlug();
    }

    /**
     * Devuelve la paleta lista para inyectar como CSS variables. Si se pasa
     * `$primary`, la receta se adapta a ese color en vez de usar acentos fijos.
     */
    public static function tokens(string $slug, ?string $primary = null): array
    {
        $slug = self::normalizeSlug($slug);
        $p = self::get($slug);
        $primary = self::validHex((string) $primary) ?: (string) $p['accent'];
        if ($primary !== '') {
            return self::adaptiveTokens($slug, $p, $primary);
        }
        return [
            'bg' => $p['bg'],
            'surface' => $p['surface'],
            'text' => $p['text'],
            'muted' => $p['muted'],
            'line' => $p['line'],
            'accent' => $p['accent'],
            'accent_dark' => $p['accent_dark'],
            'accent_2' => $p['accent_2'],
        ];
    }

    private static function adaptiveTokens(string $slug, array $p, string $primary): array
    {
        $textDark = self::mix('#111111', $primary, 0.06);
        $mutedDark = self::mix('#666666', $primary, 0.10);
        $primaryDark = self::mix($primary, '#111111', 0.28);
        $warm = self::mix('#f3ddc9', $primary, 0.13);
        $bone = self::mix('#f5f1e8', $primary, 0.06);
        $darkBg = self::mix('#101014', $primary, 0.24);
        $darkSurface = self::mix('#191a20', $primary, 0.30);
        $accentWarm = self::desaturate(self::shiftHue($primary, 46), 0.82);
        $accentCool = self::desaturate(self::shiftHue($primary, -82), 0.78);
        $accentOpp = self::desaturate(self::shiftHue($primary, 172), 0.70);

        return match ($slug) {
            'studio-mono' => [
                'bg' => '#ffffff',
                'surface' => '#f5f5f4',
                'text' => '#0f0f0f',
                'muted' => '#666666',
                'line' => '#dcdcd8',
                'accent' => $primary,
                'accent_dark' => $primaryDark,
                'accent_2' => $accentOpp,
            ],
            'cream-ink' => [
                'bg' => $warm,
                'surface' => self::mix('#fff7e8', $primary, 0.08),
                'text' => $textDark,
                'muted' => self::mix('#6b6457', $primary, 0.08),
                'line' => self::mix('#d9bfa5', $primary, 0.12),
                'accent' => $primary,
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#c97a2b', $accentWarm, 0.40),
            ],
            'ink-bone' => [
                'bg' => $bone,
                'surface' => '#ffffff',
                'text' => self::mix('#101010', $primary, 0.04),
                'muted' => self::mix('#5d5d5d', $primary, 0.08),
                'line' => self::mix('#c9c3b7', $primary, 0.10),
                'accent' => $primary,
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#f2c94c', $accentWarm, 0.28),
            ],
            'night-citrus' => [
                'bg' => $darkBg,
                'surface' => $darkSurface,
                'text' => '#f4f6fb',
                'muted' => self::mix('#8a92ad', $primary, 0.12),
                'line' => self::mix('#283456', $primary, 0.18),
                'accent' => self::mix($primary, '#ffffff', 0.10),
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#ffb547', $accentWarm, 0.28),
            ],
            'agave' => [
                'bg' => self::mix('#eaf1dc', $primary, 0.08),
                'surface' => '#ffffff',
                'text' => self::mix('#1c2818', $primary, 0.12),
                'muted' => self::mix('#5a6757', $primary, 0.10),
                'line' => self::mix('#bdc8aa', $primary, 0.10),
                'accent' => self::desaturate($primary, 0.88),
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#c19c4f', $accentWarm, 0.38),
            ],
            'boutique-rosa' => [
                'bg' => self::mix('#fde7e8', $primary, 0.10),
                'surface' => '#ffffff',
                'text' => self::mix('#3b1c1c', $primary, 0.12),
                'muted' => self::mix('#8b5e5e', $primary, 0.12),
                'line' => self::mix('#e8c0bd', $primary, 0.12),
                'accent' => self::desaturate($primary, 0.86),
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#ce8b76', $accentWarm, 0.34),
            ],
            'depth-teal' => [
                'bg' => self::mix('#0f1d22', $primary, 0.20),
                'surface' => self::mix('#162a30', $primary, 0.22),
                'text' => '#eef6f3',
                'muted' => self::mix('#8aa19b', $primary, 0.10),
                'line' => self::mix('#22404a', $primary, 0.16),
                'accent' => self::mix($primary, '#ffffff', 0.08),
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#75e0c1', $accentCool, 0.45),
            ],
            'paper-cobalt' => [
                'bg' => self::mix('#fcfaf4', $primary, 0.04),
                'surface' => '#ffffff',
                'text' => self::mix('#0d1a3d', $primary, 0.10),
                'muted' => self::mix('#586089', $primary, 0.10),
                'line' => self::mix('#d8d4c2', $primary, 0.08),
                'accent' => $primary,
                'accent_dark' => $primaryDark,
                'accent_2' => self::mix('#f2c94c', $accentOpp, 0.22),
            ],
            default => [
                'bg' => self::mix('#f3f6f4', $primary, 0.07),
                'surface' => '#ffffff',
                'text' => $textDark,
                'muted' => $mutedDark,
                'line' => self::mix('#dededb', $primary, 0.08),
                'accent' => $primary,
                'accent_dark' => $primaryDark,
                'accent_2' => $accentCool,
            ],
        };
    }

    /**
     * @return array<int,array{slug:string,label:string,description:string,swatches:array<int,string>}>
     */
    public static function cards(?string $primary = null): array
    {
        $out = [];
        foreach (self::all() as $slug => $p) {
            $tokens = self::tokens($slug, $primary);
            $out[] = [
                'slug' => $slug,
                'label' => $p['label'],
                'description' => $p['description'],
                'swatches' => [$tokens['bg'], $tokens['surface'], $tokens['text'], $tokens['accent'], $tokens['accent_2']],
            ];
        }
        return $out;
    }

    public static function selectedForSite(int $siteId): ?string
    {
        $row = \Core\Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::SETTING_KEY]
        );
        $val = trim((string) ($row['setting_value'] ?? ''));
        if ($val === '') return null;
        return self::get($val) ? $val : null;
    }

    public static function saveSelectedForSite(int $siteId, string $slug): void
    {
        $slug = self::normalizeSlug($slug);
        \Core\Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::SETTING_KEY, $slug]
        );
    }

    private static function validHex(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : '';
    }

    private static function mix(string $a, string $b, float $weightB): string
    {
        $weightB = max(0, min(1, $weightB));
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        return sprintf(
            '#%02x%02x%02x',
            (int) round($ar * (1 - $weightB) + $br * $weightB),
            (int) round($ag * (1 - $weightB) + $bg * $weightB),
            (int) round($ab * (1 - $weightB) + $bb * $weightB)
        );
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::validHex($hex) ?: '#000000', '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function shiftHue(string $hex, float $deg): string
    {
        [$h, $s, $l] = self::rgbToHsl(...self::rgb($hex));
        $h = fmod(($h + $deg + 360), 360);
        return self::hslToHex($h, $s, $l);
    }

    private static function desaturate(string $hex, float $amount): string
    {
        [$h, $s, $l] = self::rgbToHsl(...self::rgb($hex));
        return self::hslToHex($h, $s * max(0, min(1, $amount)), $l);
    }

    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        $h = 0; $s = 0; $l = ($max + $min) / 2;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            if ($max === $r) $h = (($g - $b) / $d + ($g < $b ? 6 : 0));
            elseif ($max === $g) $h = (($b - $r) / $d + 2);
            else $h = (($r - $g) / $d + 4);
            $h *= 60;
        }
        return [$h, $s, $l];
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };
        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
