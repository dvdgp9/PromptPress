<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use Core\Database;

/**
 * CartService — carrito en sesión y cálculo de totales (C4).
 *
 * El carrito es un mapa product_id => cantidad en $_SESSION (la sesión ya
 * arranca en toda petición pública). Los totales se recalculan SIEMPRE desde
 * la BD (precio/IVA/stock actuales), nunca desde datos del cliente.
 *
 * Dinero en céntimos enteros. Redondeo POR LÍNEA (half-up), de modo que el
 * desglose de IVA del pedido sea la suma exacta de sus líneas:
 *   - IVA incluido (B2C): line_total = precio×qty; iva = line − line/(1+r).
 *   - IVA excluido (B2B): neto = precio×qty; iva = round(neto×r); line = neto+iva.
 */
final class CartService
{
    private const KEY = 'pp_commerce_cart';

    /** @return array<int,int> product_id => qty */
    public static function items(int $siteId): array
    {
        $all = $_SESSION[self::KEY] ?? [];
        $cart = $all[$siteId] ?? [];
        return is_array($cart) ? array_filter(array_map('intval', $cart), static fn (int $q): bool => $q > 0) : [];
    }

    /**
     * Fija la cantidad de un producto (0 = quitar). Acota a stock disponible.
     * Devuelve un aviso si hubo que ajustar, o null.
     */
    public static function put(int $siteId, int $productId, int $qty): ?string
    {
        $cart = self::items($siteId);
        if ($qty <= 0) {
            unset($cart[$productId]);
            self::persist($siteId, $cart);
            return null;
        }
        $p = ProductStore::find($siteId, $productId);
        if ($p === null || (int) $p['active'] !== 1) {
            unset($cart[$productId]);
            self::persist($siteId, $cart);
            return 'Ese producto ya no está disponible.';
        }
        $qty = min($qty, 99);
        $warning = null;
        if ($p['stock'] !== null && $qty > (int) $p['stock']) {
            $qty = (int) $p['stock'];
            $warning = $qty > 0
                ? 'Solo quedan ' . $qty . ' unidades de «' . (string) $p['name'] . '».'
                : '«' . (string) $p['name'] . '» está agotado.';
        }
        if ($qty > 0) {
            $cart[$productId] = $qty;
        } else {
            unset($cart[$productId]);
        }
        self::persist($siteId, $cart);
        return $warning;
    }

    /** Suma cantidades (botón "añadir al carrito"). */
    public static function add(int $siteId, int $productId, int $qty): ?string
    {
        $current = self::items($siteId)[$productId] ?? 0;
        return self::put($siteId, $productId, $current + max(1, $qty));
    }

    public static function clear(int $siteId): void
    {
        self::persist($siteId, []);
    }

    /**
     * Totales calculados desde BD. Las líneas de productos desaparecidos o
     * desactivados se omiten (y deberían purgarse con put()).
     *
     * @return array{lines: array<int, array{product_id:int, name:string, slug:string,
     *               unit_price_cents:int, tax_rate:float, quantity:int, available_stock:?int,
     *               line_total_cents:int, line_tax_cents:int, media_path:?string}>,
     *               subtotal_cents:int, tax_cents:int, shipping_cents:int, total_cents:int,
     *               prices_include_tax:bool, item_count:int}
     */
    public static function totals(int $siteId): array
    {
        $includeTax = CommerceSettings::pricesIncludeTax($siteId);
        $cart = self::items($siteId);

        $lines = [];
        $subtotal = 0;
        $tax = 0;
        $count = 0;
        if ($cart !== []) {
            $ids = implode(',', array_map('intval', array_keys($cart)));
            $rows = Database::select(
                "SELECT p.*, m.path AS media_path
                   FROM commerce_products p
                   LEFT JOIN media m ON m.id = p.media_id
                  WHERE p.site_id = ? AND p.active = 1 AND p.id IN ($ids)",
                [$siteId]
            );
            foreach ($rows as $p) {
                $pid = (int) $p['id'];
                $qty = $cart[$pid] ?? 0;
                if ($qty <= 0) {
                    continue;
                }
                [$lineTotal, $lineTax] = self::lineAmounts((int) $p['price_cents'], (float) $p['tax_rate'], $qty, $includeTax);
                $lines[] = [
                    'product_id'       => $pid,
                    'name'             => (string) $p['name'],
                    'slug'             => (string) $p['slug'],
                    'unit_price_cents' => (int) $p['price_cents'],
                    'tax_rate'         => (float) $p['tax_rate'],
                    'quantity'         => $qty,
                    'available_stock'  => $p['stock'] !== null ? (int) $p['stock'] : null,
                    'line_total_cents' => $lineTotal,
                    'line_tax_cents'   => $lineTax,
                    'media_path'       => $p['media_path'] !== null ? (string) $p['media_path'] : null,
                ];
                $subtotal += $lineTotal;
                $tax += $lineTax;
                $count += $qty;
            }
        }

        $shipping = 0;
        if ($lines !== []) {
            $shipping = CommerceSettings::shippingCents($siteId);
            $freeOver = CommerceSettings::freeShippingOverCents($siteId);
            if ($freeOver !== null && $subtotal >= $freeOver) {
                $shipping = 0;
            }
        }

        return [
            'lines'              => $lines,
            'subtotal_cents'     => $subtotal,
            'tax_cents'          => $tax,
            'shipping_cents'     => $shipping,
            'total_cents'        => $subtotal + $shipping,
            'prices_include_tax' => $includeTax,
            'item_count'         => $count,
        ];
    }

    /**
     * Importe final e IVA de una línea según el modo del sitio.
     *
     * @return array{0:int, 1:int} [line_total_cents, line_tax_cents]
     */
    public static function lineAmounts(int $unitPriceCents, float $taxRate, int $qty, bool $includeTax): array
    {
        $rate = $taxRate / 100;
        if ($includeTax) {
            $total = $unitPriceCents * $qty;
            $taxPart = (int) round($total - $total / (1 + $rate));
            return [$total, $taxPart];
        }
        $net = $unitPriceCents * $qty;
        $taxPart = (int) round($net * $rate);
        return [$net + $taxPart, $taxPart];
    }

    /** Enlace al carrito con contador, para la cabecera de la tienda. */
    public static function badge(int $siteId): string
    {
        $count = array_sum(self::items($siteId));
        return '<a class="pp-btn pp-btn--ghost" href="' . e(base_url('tienda/carrito')) . '">'
            . 'Carrito' . ($count > 0 ? ' (' . $count . ')' : '') . '</a>';
    }

    /** @param array<int,int> $cart */
    private static function persist(int $siteId, array $cart): void
    {
        $_SESSION[self::KEY][$siteId] = $cart;
    }
}
