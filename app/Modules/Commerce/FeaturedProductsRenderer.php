<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use Core\Database;

/**
 * FeaturedProductsRenderer — bloque "productos destacados" para páginas
 * canvas (FEAT-3 C7), expandido desde el placeholder {{products:featured}}.
 *
 * Markup autocontenido con tokens de marca (--pp-*): funciona en cualquier
 * página del sitio sin depender del CSS de la tienda (que es inline del
 * ShopRenderer). El CSS del bloque se emite una sola vez por petición.
 *
 * Muestra los productos ACTIVOS más recientes (con stock primero). Si no hay
 * productos activos devuelve cadena vacía: la página no enseña un hueco roto.
 */
final class FeaturedProductsRenderer
{
    private static bool $cssEmitted = false;

    /**
     * @param array{limit?:int|string, heading?:string} $opts
     */
    public static function render(int $siteId, array $opts = []): string
    {
        $limit = max(1, min(12, (int) ($opts['limit'] ?? 3)));
        $heading = trim((string) ($opts['heading'] ?? ''));

        $products = Database::select(
            'SELECT p.*, m.path AS media_path
               FROM commerce_products p
               LEFT JOIN media m ON m.id = p.media_id
              WHERE p.site_id = ? AND p.active = 1
              ORDER BY (p.stock IS NOT NULL AND p.stock <= 0) ASC, p.created_at DESC, p.id DESC
              LIMIT ' . $limit,
            [$siteId]
        );
        if ($products === []) {
            return '';
        }

        $h = '<div class="pp-featured-products">';
        if ($heading !== '') {
            $h .= '<h2 class="pp-featured-products__heading">' . e($heading) . '</h2>';
        }
        $h .= '<div class="pp-featured-products__grid" style="--ppfp-cols:' . min(count($products), 4) . '">';
        foreach ($products as $p) {
            $url = base_url('tienda/p/' . (string) $p['slug']);
            $soldOut = $p['stock'] !== null && (int) $p['stock'] <= 0;
            $img = trim((string) ($p['media_path'] ?? ''));

            $h .= '<a class="pp-featured-products__card" href="' . e($url) . '">';
            $h .= $img !== ''
                ? '<img class="pp-featured-products__img" src="' . e(base_url(ltrim($img, '/'))) . '" alt="' . e((string) $p['name']) . '" loading="lazy">'
                : '<span class="pp-featured-products__img pp-featured-products__img--empty" aria-hidden="true"></span>';
            $h .= '<span class="pp-featured-products__body">';
            $h .= '<span class="pp-featured-products__name">' . e((string) $p['name']) . '</span>';
            $h .= '<span class="pp-featured-products__price">' . e(CommerceSettings::format((int) $p['price_cents']))
                . ($soldOut ? ' <span class="pp-featured-products__soldout">Agotado</span>' : '') . '</span>';
            $h .= '</span></a>';
        }
        $h .= '</div>';
        $h .= '<p class="pp-featured-products__more"><a class="pp-btn pp-btn--ghost" href="' . e(base_url('tienda')) . '">Ver toda la tienda</a></p>';
        $h .= '</div>';

        if (!self::$cssEmitted) {
            self::$cssEmitted = true;
            $h .= '<style>' . self::css() . '</style>';
        }
        return $h;
    }

    private static function css(): string
    {
        return <<<'CSS'
.pp-featured-products{max-width:var(--pp-container-max,1100px);margin:0 auto;padding:8px 0}
.pp-featured-products__heading{text-align:center;margin:0 0 28px;font-family:var(--pp-font-heading,inherit)}
.pp-featured-products__grid{display:grid;grid-template-columns:repeat(var(--ppfp-cols,3),1fr);gap:24px}
.pp-featured-products__card{display:flex;flex-direction:column;text-decoration:none;color:var(--pp-text,#1f2937);background:var(--pp-surface,#fff);border-radius:var(--pp-radius-lg,14px);overflow:hidden;box-shadow:var(--pp-shadow-sm,0 1px 3px rgba(0,0,0,.08));transition:transform .18s ease,box-shadow .18s ease}
.pp-featured-products__card:hover{transform:translateY(-3px);box-shadow:var(--pp-shadow-md,0 6px 18px rgba(0,0,0,.12))}
.pp-featured-products__img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
.pp-featured-products__img--empty{background:color-mix(in srgb,var(--pp-primary,#888) 12%,var(--pp-surface,#fff))}
.pp-featured-products__body{display:flex;flex-direction:column;gap:6px;padding:16px 18px 18px}
.pp-featured-products__name{font-weight:600;font-family:var(--pp-font-heading,inherit)}
.pp-featured-products__price{color:var(--pp-primary,#1f2937);font-weight:700}
.pp-featured-products__soldout{font-size:.75em;font-weight:600;color:var(--pp-text-muted,#6b7280);border:1px solid currentColor;border-radius:999px;padding:1px 8px;margin-left:6px;vertical-align:middle}
.pp-featured-products__more{text-align:center;margin:26px 0 0}
@media(max-width:820px){.pp-featured-products__grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.pp-featured-products__grid{grid-template-columns:1fr}}
CSS;
    }

    /** Solo para tests: permite re-emitir el CSS en el mismo proceso. */
    public static function resetCssEmitted(): void
    {
        self::$cssEmitted = false;
    }
}
