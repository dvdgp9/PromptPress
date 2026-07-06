<?php

declare(strict_types=1);

/**
 * FEAT-3 C7 — Tests de la integración transversal de Commerce.
 *
 * Parte 1 (CLI): placeholder {{products:featured}} en canvas (módulo off →
 * comentario invisible; sin productos → comentario; con productos → grid con
 * nombre/precio/enlace, límite y heading, ref canónica para el editor),
 * modulesHint por sitio, y flush de caché de páginas al tocar productos.
 * Parte 2 (HTTP): una compra real registra el evento `purchase` en Analytics
 * (y no lo registra con el módulo Analytics apagado).
 *
 * Restaura módulos y limpia productos/pedidos/eventos de prueba al final
 * (solo los suyos: NO vacía analytics_events, que puede tener datos demo).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\FeaturedProductsRenderer;
use App\Modules\Commerce\ProductStore;
use App\Modules\ModuleRegistry;
use App\Services\CacheService;
use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function check_c7(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_c7('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$commerceWas  = ModuleRegistry::isEnabled($siteId, 'commerce');
$analyticsWas = ModuleRegistry::isEnabled($siteId, 'analytics');

// ---------------------------------------------------------------------------
// modulesHint
// ---------------------------------------------------------------------------
ModuleRegistry::setEnabled($siteId, 'commerce', false);
check_c7('hint sin tienda: prohíbe el placeholder', str_contains(CanvasService::modulesHint($siteId), 'NO uses'));
ModuleRegistry::setEnabled($siteId, 'commerce', true);
check_c7('hint con tienda: enseña {{products:featured}}', str_contains(CanvasService::modulesHint($siteId), '{{products:featured}}'));

// ---------------------------------------------------------------------------
// Placeholder {{products:...}} en canvas
// ---------------------------------------------------------------------------
$canvasHtml = '<section data-pp-section="tienda"><h2>Lo nuestro</h2>{{products:featured|limit=2|heading=Los favoritos}}</section>';

// Módulo apagado → comentario invisible.
ModuleRegistry::setEnabled($siteId, 'commerce', false);
$out = CanvasService::expandPlaceholders($canvasHtml, $siteId);
check_c7('módulo off → comentario, sin grid', str_contains($out, '<!-- pp:products') && !str_contains($out, 'pp-featured-products'), $out);

// Módulo activo pero sin productos activos → comentario. Guardamos qué
// productos estaban activos para restaurarlos exactamente al final.
ModuleRegistry::setEnabled($siteId, 'commerce', true);
$prevActiveIds = array_map(
    static fn (array $r): int => (int) $r['id'],
    Database::select('SELECT id FROM commerce_products WHERE site_id = ? AND active = 1', [$siteId])
);
Database::execute('UPDATE commerce_products SET active = 0 WHERE site_id = ?', [$siteId]);
FeaturedProductsRenderer::resetCssEmitted();
$out = CanvasService::expandPlaceholders($canvasHtml, $siteId);
check_c7('sin productos activos → comentario', str_contains($out, 'sin productos activos'), $out);

// Con productos: grid real.
$pid1 = ProductStore::create($siteId, ['name' => 'Test C7 · Taza', 'price_cents' => 1200, 'tax_rate' => 21, 'stock' => 5, 'active' => '1']);
$pid2 = ProductStore::create($siteId, ['name' => 'Test C7 · Póster', 'price_cents' => 900, 'tax_rate' => 21, 'stock' => 0, 'active' => '1']);
$pid3 = ProductStore::create($siteId, ['name' => 'Test C7 · Lámina', 'price_cents' => 1500, 'tax_rate' => 21, 'stock' => '', 'active' => '1']);

FeaturedProductsRenderer::resetCssEmitted();
$out = CanvasService::expandPlaceholders($canvasHtml, $siteId);
check_c7('grid renderizado con clase propia', str_contains($out, 'pp-featured-products__grid'));
check_c7('respeta limit=2', substr_count($out, 'class="pp-featured-products__card"') === 2, $out);
check_c7('heading renderizado', str_contains($out, 'Los favoritos'));
check_c7('precio formateado presente', str_contains($out, '15,00&nbsp;€') || str_contains($out, '15,00 €'), $out);
check_c7('enlace a la ficha de producto', str_contains($out, '/tienda/p/test-c7'), $out);
check_c7('CTA a la tienda', str_contains($out, '/tienda"'));
check_c7('CSS del bloque emitido', str_contains($out, '.pp-featured-products{'));
check_c7('ref canónica para el editor (FH4)', str_contains($out, 'data-pp-placeholder="products:featured|limit=2|heading=Los favoritos"'), $out);
check_c7('agotados al final (stock 0 no en limit=2)', !str_contains($out, 'Póster'));

// El CSS solo se emite una vez por petición.
$out2 = CanvasService::expandPlaceholders($canvasHtml, $siteId);
check_c7('CSS no se re-emite en el mismo proceso', !str_contains($out2, '.pp-featured-products{'));

// Sin opciones → default limit 3 y sin heading.
FeaturedProductsRenderer::resetCssEmitted();
$out = CanvasService::expandPlaceholders('{{products:featured}}', $siteId);
check_c7('default: 3 tarjetas y ref canónica simple', substr_count($out, 'class="pp-featured-products__card"') === 3
    && str_contains($out, 'data-pp-placeholder="products:featured"'), $out);
check_c7('agotado marcado con pill', str_contains($out, 'Agotado'));

// ---------------------------------------------------------------------------
// Flush de caché de páginas al tocar productos
// ---------------------------------------------------------------------------
CacheService::put($siteId, 'c7-cache-probe', '<html>probe</html>');
check_c7('probe cacheado', CacheService::get($siteId, 'c7-cache-probe') !== null);
ProductStore::update($siteId, $pid1, ['name' => 'Test C7 · Taza', 'price_cents' => 1300, 'tax_rate' => 21, 'stock' => 5, 'active' => '1']);
check_c7('update de producto invalida la caché de páginas', CacheService::get($siteId, 'c7-cache-probe') === null);

// ---------------------------------------------------------------------------
// Parte 2 — evento purchase por HTTP
// ---------------------------------------------------------------------------
$port = 8791;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;
$jar = tempnam(sys_get_temp_dir(), 'c7jar');

function shop_c7(string $method, string $url, array $post = []): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
        // UA "real": EventRecorder descarta bots (curl incluido) por User-Agent.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; test C7) AppleWebKit/537.36',
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$status, $body, $finalUrl];
}
function csrf_c7(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}
function purchases_count(int $siteId): int
{
    return (int) (Database::selectOne(
        "SELECT COUNT(*) AS n FROM analytics_events WHERE site_id = ? AND event_type = 'purchase' AND path = '/tienda/checkout'",
        [$siteId]
    )['n'] ?? 0);
}
function buy_c7(string $base, int $siteId, int $pid, string $email): bool
{
    $slug = (string) ProductStore::find($siteId, $pid)['slug'];
    [, $html] = shop_c7('GET', $base . '/tienda/p/' . $slug);
    shop_c7('POST', $base . '/tienda/carrito', ['_csrf' => csrf_c7($html), 'product_id' => $pid, 'quantity' => 1]);
    [, $html] = shop_c7('GET', $base . '/tienda/checkout');
    [, , $finalUrl] = shop_c7('POST', $base . '/tienda/checkout', [
        '_csrf' => csrf_c7($html), 'name' => 'Cliente C7', 'email' => $email,
        'payment_method' => 'manual', 'company_url' => '',
    ]);
    return str_contains($finalUrl, '/tienda/gracias/');
}

$purchasesBefore = purchases_count($siteId);

// Con Analytics ACTIVO: la compra registra el evento.
ModuleRegistry::setEnabled($siteId, 'analytics', true);
check_c7('compra con analytics on completada', buy_c7($base, $siteId, $pid1, 'c7-on@example.com'));
check_c7('evento purchase registrado', purchases_count($siteId) === $purchasesBefore + 1);

// Con Analytics APAGADO: compra OK, sin evento.
ModuleRegistry::setEnabled($siteId, 'analytics', false);
check_c7('compra con analytics off completada', buy_c7($base, $siteId, $pid1, 'c7-off@example.com'));
check_c7('sin evento nuevo con analytics off', purchases_count($siteId) === $purchasesBefore + 1);

// ---------------------------------------------------------------------------
// Limpieza (solo lo nuestro) y restauración
// ---------------------------------------------------------------------------
proc_terminate($proc);
proc_close($proc);
@unlink($jar);
Database::execute("DELETE FROM commerce_orders WHERE site_id = ? AND customer_email LIKE 'c7-%@example.com'", [$siteId]);
// El evento purchase de prueba: el más reciente en /tienda/checkout.
Database::execute(
    "DELETE FROM analytics_events WHERE site_id = ? AND event_type = 'purchase' AND path = '/tienda/checkout'
      ORDER BY id DESC LIMIT 1",
    [$siteId]
);
foreach ([$pid1, $pid2, $pid3] as $pid) {
    ProductStore::delete($siteId, $pid);
}
// Reactivar exactamente los productos que estaban activos al empezar.
if ($prevActiveIds !== []) {
    Database::execute(
        'UPDATE commerce_products SET active = 1 WHERE site_id = ? AND id IN (' . implode(',', $prevActiveIds) . ')',
        [$siteId]
    );
}
ModuleRegistry::setEnabled($siteId, 'commerce', $commerceWas);
ModuleRegistry::setEnabled($siteId, 'analytics', $analyticsWas);
$left = (int) Database::selectOne("SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ? AND name LIKE 'Test C7%'", [$siteId])['n'];
check_c7('limpieza completa', $left === 0 && purchases_count($siteId) === $purchasesBefore);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
