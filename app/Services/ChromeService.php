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
