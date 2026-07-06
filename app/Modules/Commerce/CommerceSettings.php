<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use Core\Database;

/**
 * CommerceSettings — configuración del módulo Commerce por sitio + utilidades
 * de dinero (C2). Los ajustes viven en la tabla `settings` (sin tablas nuevas).
 *
 * Importes en céntimos enteros en toda la lógica; la conversión a/desde euros
 * ocurre solo en el borde (formularios y presentación).
 */
final class CommerceSettings
{
    public const CURRENCY = 'EUR';

    /** @var array<string,string> defaults de los settings del módulo */
    private const DEFAULTS = [
        'commerce_prices_include_tax'      => '1',   // B2C por defecto (precio final con IVA)
        'commerce_shipping_cents'          => '0',
        'commerce_free_shipping_over_cents' => '',   // '' = sin umbral de envío gratis
        'commerce_manual_instructions'     => '',
    ];

    public static function get(int $siteId, string $key): string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $key]
        );
        if ($row !== null) {
            return (string) $row['setting_value'];
        }
        return self::DEFAULTS[$key] ?? '';
    }

    public static function set(int $siteId, string $key, string $value, bool $encrypted = false): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$siteId, $key, $value, $encrypted ? 1 : 0]
        );
    }

    /** ¿Los precios que introduce el admin ya incluyen el IVA (B2C)? */
    public static function pricesIncludeTax(int $siteId): bool
    {
        return self::get($siteId, 'commerce_prices_include_tax') !== '0';
    }

    public static function shippingCents(int $siteId): int
    {
        return max(0, (int) self::get($siteId, 'commerce_shipping_cents'));
    }

    /** Umbral de envío gratis en céntimos, o null si no hay. */
    public static function freeShippingOverCents(int $siteId): ?int
    {
        $raw = self::get($siteId, 'commerce_free_shipping_over_cents');
        return $raw === '' ? null : max(0, (int) $raw);
    }

    // ---- Utilidades de dinero -------------------------------------------

    /** "12,50" o "12.50" € → 1250 céntimos. Cadena vacía → 0. */
    public static function eurosToCents(string $euros): int
    {
        $euros = trim(str_replace([' ', '€'], '', $euros));
        if ($euros === '') {
            return 0;
        }
        $euros = str_replace(',', '.', $euros);
        if (!is_numeric($euros)) {
            return 0;
        }
        return (int) round((float) $euros * 100);
    }

    /** 1250 → "12,50" (para rellenar inputs; sin símbolo). */
    public static function centsToInput(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /** 1250 → "12,50 €" (para presentación). */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }
}
