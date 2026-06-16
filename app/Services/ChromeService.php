<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * CHROME-EDITOR — Configuración editable del header y el footer del sitio.
 *
 * La config vive como JSON en `settings` (setting_key='chrome_config'). Si está
 * vacía o falta un campo, se cae al COMPORTAMIENTO AUTOMÁTICO histórico
 * (páginas publicadas, tagline de la memoria, etc.), de modo que un sitio sin
 * configurar se ve EXACTAMENTE igual que antes (regresión cero).
 *
 * `BrandService` consume los resolvers de aquí para construir el HTML.
 */
final class ChromeService
{
    public const SETTING_KEY = 'chrome_config';

    /** Estructura por defecto (todo en "auto": sin overrides). */
    public static function defaults(): array
    {
        return [
            'header' => [
                'layout' => [
                    'sticky'                => true,   // como hoy
                    'transparent_over_hero' => false,
                    'density'               => 'regular', // compact|regular|tall
                    'logo_position'         => 'left',    // left|center
                ],
                'logo'  => ['dark_variant_path' => ''],
                'menu'  => [],   // vacío => navegación automática (páginas publicadas)
                'cta'   => ['mode' => 'auto', 'label' => '', 'url' => '', 'style' => 'primary'], // auto|custom|off
            ],
            'footer' => [
                'style'   => ['background' => 'dark', 'columns' => 0], // columns 0 => auto
                'blocks'  => [], // vacío => orden automático (brand, nav, legal)
                'tagline' => '', // vacío => tagline de la memoria
                'nav'     => [], // vacío => páginas publicadas
                'contact' => ['address' => '', 'phone' => '', 'email' => '', 'hours' => ''],
                'social'  => [], // [{network,url}]
                'newsletter' => ['enabled' => false, 'form_ref' => '', 'heading' => ''],
                'copyright'  => '', // vacío => "© AÑO · Nombre"
            ],
        ];
    }

    /** Config mergeada (defaults + lo guardado). */
    public static function load(int $siteId): array
    {
        $raw = '';
        try {
            $row = Database::selectOne(
                'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
                [$siteId, self::SETTING_KEY]
            );
            $raw = (string) ($row['setting_value'] ?? '');
        } catch (\Throwable $e) {
            $raw = '';
        }
        $stored = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($stored)) $stored = [];
        return self::mergeDeep(self::defaults(), $stored);
    }

    /** ¿El sitio tiene configuración de chrome guardada (no vacía)? */
    public static function isConfigured(int $siteId): bool
    {
        try {
            $row = Database::selectOne(
                'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
                [$siteId, self::SETTING_KEY]
            );
        } catch (\Throwable $e) {
            return false;
        }
        $raw = trim((string) ($row['setting_value'] ?? ''));
        return $raw !== '' && $raw !== '[]' && $raw !== '{}';
    }

    public static function save(int $siteId, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::SETTING_KEY, $json]
        );
    }

    /**
     * Normaliza/sanea una config cruda (p. ej. del editor) sobre los defaults:
     * enums validados, longitudes acotadas y tamaños de listas limitados.
     */
    public static function sanitize(array $raw): array
    {
        $d = self::defaults();
        $h = (array) ($raw['header'] ?? []);
        $f = (array) ($raw['footer'] ?? []);
        $hl = (array) ($h['layout'] ?? []);
        $cta = (array) ($h['cta'] ?? []);
        $fs = (array) ($f['style'] ?? []);
        $fc = (array) ($f['contact'] ?? []);
        $fn = (array) ($f['newsletter'] ?? []);

        $cut = static fn($v, int $n): string => mb_substr(trim((string) $v), 0, $n);
        $enum = static fn($v, array $opts, string $def): string => in_array($v, $opts, true) ? (string) $v : $def;

        // Menú (cap 14 ítems, hijos cap 8)
        $menu = [];
        foreach (array_slice((array) ($h['menu'] ?? []), 0, 14) as $it) {
            $item = self::sanitizeMenuItem((array) $it, $cut, $enum, false);
            if ($item !== null) $menu[] = $item;
        }

        // Bloques footer (subconjunto único)
        $allowedBlocks = ['brand', 'nav', 'legal', 'contact', 'social', 'newsletter'];
        $blocks = [];
        foreach ((array) ($f['blocks'] ?? []) as $b) {
            $b = (string) $b;
            if (in_array($b, $allowedBlocks, true) && !in_array($b, $blocks, true)) $blocks[] = $b;
        }

        // Redes (cap 10)
        $social = [];
        foreach (array_slice((array) ($f['social'] ?? []), 0, 10) as $s) {
            $s = (array) $s;
            $url = $cut($s['url'] ?? '', 300);
            $net = $cut($s['network'] ?? '', 40);
            if ($url !== '' && $net !== '') $social[] = ['network' => $net, 'url' => $url];
        }

        return [
            'header' => [
                'layout' => [
                    'sticky'                => (bool) ($hl['sticky'] ?? true),
                    'transparent_over_hero' => (bool) ($hl['transparent_over_hero'] ?? false),
                    'density'               => $enum($hl['density'] ?? 'regular', ['compact', 'regular', 'tall'], 'regular'),
                    'logo_position'         => $enum($hl['logo_position'] ?? 'left', ['left', 'center'], 'left'),
                ],
                'logo' => ['dark_variant_path' => $cut($h['logo']['dark_variant_path'] ?? '', 300)],
                'menu' => $menu,
                'cta'  => [
                    'mode'  => $enum($cta['mode'] ?? 'auto', ['auto', 'custom', 'off'], 'auto'),
                    'label' => $cut($cta['label'] ?? '', 60),
                    'url'   => $cut($cta['url'] ?? '', 300),
                    'style' => $enum($cta['style'] ?? 'primary', ['primary', 'ghost'], 'primary'),
                ],
            ],
            'footer' => [
                'style'   => [
                    'background' => $enum($fs['background'] ?? 'dark', ['dark', 'light', 'brand'], 'dark'),
                    'columns'    => max(0, min(4, (int) ($fs['columns'] ?? 0))),
                ],
                'blocks'  => $blocks,
                'tagline' => $cut($f['tagline'] ?? '', 200),
                'nav'     => $d['footer']['nav'],
                'contact' => [
                    'address' => $cut($fc['address'] ?? '', 300),
                    'phone'   => $cut($fc['phone'] ?? '', 60),
                    'email'   => $cut($fc['email'] ?? '', 120),
                    'hours'   => $cut($fc['hours'] ?? '', 120),
                ],
                'social'  => $social,
                'newsletter' => [
                    'enabled'  => (bool) ($fn['enabled'] ?? false),
                    'form_ref' => $cut($fn['form_ref'] ?? '', 120),
                    'heading'  => $cut($fn['heading'] ?? '', 120),
                ],
                'copyright'  => $cut($f['copyright'] ?? '', 160),
            ],
        ];
    }

    private static function sanitizeMenuItem(array $it, callable $cut, callable $enum, bool $isChild): ?array
    {
        $type = $enum($it['type'] ?? 'page', $isChild ? ['page', 'link'] : ['page', 'link', 'dropdown'], 'page');
        $base = [
            'type'    => $type,
            'label'   => $cut($it['label'] ?? '', 120),
            'visible' => ($it['visible'] ?? true) !== false,
        ];
        if ($type === 'dropdown') {
            $children = [];
            foreach (array_slice((array) ($it['children'] ?? []), 0, 8) as $c) {
                $child = self::sanitizeMenuItem((array) $c, $cut, $enum, true);
                if ($child !== null) $children[] = $child;
            }
            if ($base['label'] === '' || $children === []) return null;
            $base['children'] = $children;
            return $base;
        }
        if ($type === 'link') {
            $base['url'] = $cut($it['url'] ?? '', 300);
            $base['target'] = $enum($it['target'] ?? '_self', ['_self', '_blank'], '_self');
            if ($base['url'] === '' || $base['label'] === '') return null;
            return $base;
        }
        // page
        $base['page_id'] = (int) ($it['page_id'] ?? 0);
        $base['target'] = $enum($it['target'] ?? '_self', ['_self', '_blank'], '_self');
        if ($base['page_id'] <= 0) return null;
        return $base;
    }

    /** Merge recursivo: $override gana; arrays asociativos se funden, listas se reemplazan. */
    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && self::isAssoc($base[$k]) && self::isAssoc($v)) {
                $base[$k] = self::mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) !== range(0, count($a) - 1);
    }
}
