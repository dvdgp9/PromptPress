<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\ModuleRegistry;
use App\Services\BrandService;
use App\Services\DesignSystem;
use App\Services\VisualStyleService;
use Core\Database;
use Core\Response;

/**
 * ShopRenderer — shell público de la tienda (C3).
 *
 * Reproduce el mismo esqueleto que PageController::render (design system,
 * header/footer del sitio, pp-ux.js, script de analytics condicional) para
 * que /tienda/* se vea como una página más del sitio. Las rutas de tienda
 * son SIEMPRE dinámicas: no pasan por CacheService (carrito/stock cambian
 * por visitante), ver cursor/commerce-design.md §4.
 *
 * El CSS propio de la tienda (grid, ficha, carrito) se emite inline una vez
 * por página y se apoya en los tokens del design system (--pp-*), de modo
 * que hereda paleta, tipografía y radios del sitio sin configurar nada.
 */
final class ShopRenderer
{
    /**
     * Renderiza una página de tienda completa y termina la respuesta.
     *
     * @param array{title?:string, description?:string, noindex?:bool, body:string} $page
     */
    public static function send(int $siteId, array $page, int $status = 200): never
    {
        $site = Database::selectOne('SELECT name, language, url FROM sites WHERE id = ?', [$siteId]) ?? [];
        $lang = (string) ($site['language'] ?? 'es');
        $siteName = (string) ($site['name'] ?? '');

        $title = trim((string) ($page['title'] ?? 'Tienda'));
        $desc  = trim((string) ($page['description'] ?? ''));

        $styleSlug  = VisualStyleService::selectedForSite($siteId);
        $designHead = DesignSystem::renderHead($siteId, $styleSlug);

        $h  = '<!doctype html>';
        $h .= '<html lang="' . e($lang) . '"><head>';
        $h .= '<meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<title>' . e($title) . ($siteName !== '' && $siteName !== $title ? ' — ' . e($siteName) : '') . '</title>';
        if ($desc !== '') {
            $h .= '<meta name="description" content="' . e($desc) . '">';
        }
        if (!empty($page['noindex'])) {
            $h .= '<meta name="robots" content="noindex,follow">';
        }
        $h .= '<meta property="og:type" content="website">';
        $h .= '<meta property="og:title" content="' . e($title) . '">';
        if ($desc !== '') {
            $h .= '<meta property="og:description" content="' . e($desc) . '">';
        }
        if ($siteName !== '') {
            $h .= '<meta property="og:site_name" content="' . e($siteName) . '">';
        }
        $h .= $designHead;
        $h .= '<style>' . self::css() . '</style>';
        $h .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $h .= BrandService::publicHeader($siteId);
        $h .= '<main class="pp-shop">' . $page['body'] . '</main>';
        $h .= BrandService::publicFooter($siteId);
        $h .= '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
            $analyticsJs = PP_ROOT . '/public/js/pp-analytics.js';
            $ver = @filemtime($analyticsJs) ?: PP_VERSION;
            $h .= '<script src="' . e(base_url('public/js/pp-analytics.js')) . '?v=' . e((string) $ver) . '"'
                . ' data-site="' . $siteId . '" defer></script>';
        }
        $h .= '</body></html>';

        Response::html($h, $status);
    }

    /** URL pública de la imagen de un producto (o '' si no tiene). */
    public static function imageUrl(?string $mediaPath): string
    {
        $path = trim((string) $mediaPath);
        return $path !== '' ? base_url(ltrim($path, '/')) : '';
    }

    /** CSS de la tienda, montado sobre los tokens del design system. */
    private static function css(): string
    {
        return <<<'CSS'
.pp-shop{padding:clamp(32px,5vw,64px) 0}
.pp-shop .container{max-width:var(--pp-container-max);margin:0 auto;padding:0 24px}
.pp-shop-head{margin-bottom:clamp(20px,3vw,36px);display:flex;align-items:end;justify-content:space-between;gap:16px;flex-wrap:wrap}
.pp-shop-head h1{margin:0}
.pp-shop-breadcrumb{font-size:.88rem;color:var(--pp-text-muted);margin-bottom:10px}
.pp-shop-breadcrumb a{color:inherit;text-decoration:none}
.pp-shop-breadcrumb a:hover{color:var(--pp-primary)}
.pp-shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:clamp(16px,2.5vw,28px)}
.pp-shop-card{display:flex;flex-direction:column;background:var(--pp-bg);border:1px solid var(--pp-border);border-radius:var(--pp-radius-card);overflow:hidden;text-decoration:none;color:inherit;transition:transform 150ms ease,box-shadow 150ms ease}
.pp-shop-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px -14px color-mix(in srgb,var(--pp-text) 35%,transparent);text-decoration:none}
.pp-shop-card__img{aspect-ratio:4/3;background:var(--pp-surface);display:block;width:100%;object-fit:cover}
.pp-shop-card__img--empty{display:flex;align-items:center;justify-content:center;color:var(--pp-text-muted);font-size:.85rem}
.pp-shop-card__body{padding:16px 18px 18px;display:flex;flex-direction:column;gap:6px;flex:1}
.pp-shop-card__name{font-family:var(--pp-font-heading);font-weight:var(--pp-weight-bold);font-size:1.05rem;line-height:1.25;margin:0}
.pp-shop-card__price{margin-top:auto;font-weight:700;color:var(--pp-primary);font-size:1.05rem}
.pp-shop-empty{text-align:center;color:var(--pp-text-muted);padding:48px 0}
.pp-shop-soldout{display:inline-block;font-size:.78rem;font-weight:600;color:var(--pp-danger);border:1px solid color-mix(in srgb,var(--pp-danger) 35%,transparent);border-radius:99px;padding:2px 10px}
.pp-shop-product{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,4fr);gap:clamp(24px,4vw,56px);align-items:start}
@media (max-width:760px){.pp-shop-product{grid-template-columns:1fr}}
.pp-shop-product__img{width:100%;border-radius:var(--pp-radius-card);border:1px solid var(--pp-border);background:var(--pp-surface);object-fit:cover;aspect-ratio:4/3}
.pp-shop-product__img--empty{display:flex;align-items:center;justify-content:center;color:var(--pp-text-muted)}
.pp-shop-product__price{font-size:1.6rem;font-weight:700;color:var(--pp-primary);margin:8px 0 2px}
.pp-shop-product__tax{font-size:.82rem;color:var(--pp-text-muted);margin:0 0 16px}
.pp-shop-product__desc{white-space:pre-line;margin:0 0 22px}
.pp-shop-qty{display:flex;align-items:center;gap:12px;margin:0 0 18px}
.pp-shop-qty label{font-size:.9rem;color:var(--pp-text-muted)}
.pp-shop-qty input{width:80px;padding:9px 10px;border:1px solid var(--pp-border);border-radius:var(--pp-btn-radius);font:inherit;background:var(--pp-bg);color:var(--pp-text)}
.pp-shop-stocknote{font-size:.85rem;color:var(--pp-text-muted);margin-top:10px}
.pp-shop-notice{background:color-mix(in srgb,var(--pp-accent) 12%,transparent);border:1px solid color-mix(in srgb,var(--pp-accent) 35%,transparent);border-radius:10px;padding:10px 14px}
.pp-shop-error{background:color-mix(in srgb,var(--pp-danger) 10%,transparent);border:1px solid color-mix(in srgb,var(--pp-danger) 35%,transparent);color:var(--pp-danger);border-radius:10px;padding:10px 14px}
.pp-shop-table{width:100%;border-collapse:collapse;margin:18px 0}
.pp-shop-table th{text-align:left;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pp-text-muted);padding:8px 10px;border-bottom:1px solid var(--pp-border)}
.pp-shop-table td{padding:12px 10px;border-bottom:1px solid var(--pp-border);vertical-align:middle}
.pp-shop-table a{color:inherit;text-decoration:none;font-weight:600}
.pp-shop-table a:hover{color:var(--pp-primary)}
.pp-shop-table input[type=number]{width:76px;padding:8px;border:1px solid var(--pp-border);border-radius:var(--pp-btn-radius);font:inherit;background:var(--pp-bg);color:var(--pp-text)}
.pp-shop-num{text-align:right;white-space:nowrap}
.pp-shop-remove{border:0;background:transparent;color:var(--pp-text-muted);font-size:1.2rem;cursor:pointer;line-height:1}
.pp-shop-remove:hover{color:var(--pp-danger)}
.pp-shop-totals{max-width:340px;margin:0 0 22px auto}
.pp-shop-totals div{display:flex;justify-content:space-between;gap:16px;padding:5px 10px}
.pp-shop-totals dt{color:var(--pp-text-muted)}
.pp-shop-totals dd{margin:0;font-weight:600}
.pp-shop-totals__grand{border-top:1px solid var(--pp-border);margin-top:6px;padding-top:10px!important;font-size:1.15rem}
.pp-shop-totals__grand dt{color:var(--pp-text)}
.pp-shop-totals__grand dd{color:var(--pp-primary);font-weight:700}
.pp-shop-totals__tax dt,.pp-shop-totals__tax dd{font-size:.82rem;font-weight:400;color:var(--pp-text-muted)}
.pp-shop-cart-actions{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.pp-shop-checkout{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:clamp(24px,4vw,56px);align-items:start}
@media (max-width:820px){.pp-shop-checkout{grid-template-columns:1fr}}
.pp-shop-form label{display:block;margin:0 0 14px;font-size:.9rem;font-weight:600}
.pp-shop-form input,.pp-shop-form textarea{display:block;width:100%;margin-top:6px;padding:11px 12px;border:1px solid var(--pp-border);border-radius:var(--pp-btn-radius);font:inherit;font-weight:400;background:var(--pp-bg);color:var(--pp-text);box-sizing:border-box}
.pp-shop-form h2{font-size:1.15rem;margin:24px 0 14px}
.pp-shop-form-row{display:grid;grid-template-columns:2fr 1fr;gap:14px}
.pp-shop-pay{display:flex!important;align-items:center;gap:10px;border:1px solid var(--pp-border);border-radius:10px;padding:12px 14px;cursor:pointer;font-weight:500!important}
.pp-shop-pay:has(input:checked){border-color:var(--pp-primary);background:color-mix(in srgb,var(--pp-primary) 6%,transparent)}
.pp-shop-pay input{width:auto!important;margin:0!important;display:inline!important}
.pp-shop-form button[type=submit]{margin-top:18px}
.pp-shop-hp{position:absolute!important;left:-9999px;opacity:0;height:0;overflow:hidden}
.pp-shop-summary{background:var(--pp-surface);border:1px solid var(--pp-border);border-radius:var(--pp-radius-card);padding:22px 24px;position:sticky;top:90px}
.pp-shop-summary h2{font-size:1.1rem;margin:0 0 8px}
.pp-shop-summary .pp-shop-totals{max-width:none}
.pp-shop-thanks{max-width:640px}
.pp-shop-instructions{background:var(--pp-surface);border:1px solid var(--pp-border);border-radius:var(--pp-radius-card);padding:18px 22px;margin:18px 0}
CSS;
    }
}
