<?php

declare(strict_types=1);

/**
 * FEAT-3 C2 — Tests de ProductStore + CommerceSettings.
 *
 * Crea productos, verifica slug único por sitio, normalización (céntimos,
 * stock ilimitado, IVA acotado), utilidades de dinero y el borrado. Limpia
 * al final. Los productos de prueba se marcan con un prefijo reconocible.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Commerce\CommerceSettings;
use App\Modules\Commerce\ProductStore;
use Core\Database;

$failed = 0;
function check_cp(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_cp('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$created = [];

// --- Utilidades de dinero ----------------------------------------------------
check_cp('eurosToCents "12,50" → 1250', CommerceSettings::eurosToCents('12,50') === 1250);
check_cp('eurosToCents "12.50" → 1250', CommerceSettings::eurosToCents('12.50') === 1250);
check_cp('eurosToCents "  9 € " → 900', CommerceSettings::eurosToCents('  9 € ') === 900);
check_cp('eurosToCents "" → 0', CommerceSettings::eurosToCents('') === 0);
check_cp('eurosToCents basura → 0', CommerceSettings::eurosToCents('abc') === 0);
check_cp('centsToInput 1250 → "12,50"', CommerceSettings::centsToInput(1250) === '12,50');
check_cp('format 1250 → "12,50 €"', CommerceSettings::format(1250) === '12,50 €');
check_cp('format 123456 → "1.234,56 €"', CommerceSettings::format(123456) === '1.234,56 €');

// --- create + normalización --------------------------------------------------
$id = ProductStore::create($siteId, [
    'name' => '  Camiseta Test C2  ', 'price_cents' => 1999, 'tax_rate' => 21, 'stock' => 5, 'active' => '1',
]);
$created[] = $id;
check_cp('create devuelve id', $id > 0);

$p = ProductStore::find($siteId, $id);
check_cp('find hidrata el producto', $p !== null);
check_cp('name normalizado (trim)', ($p['name'] ?? '') === 'Camiseta Test C2');
check_cp('slug derivado del nombre', ($p['slug'] ?? '') === 'camiseta-test-c2', (string) ($p['slug'] ?? ''));
check_cp('price_cents guardado', (int) $p['price_cents'] === 1999);
check_cp('stock 5', (int) $p['stock'] === 5);

// --- slug único por sitio ----------------------------------------------------
$id2 = ProductStore::create($siteId, ['name' => 'Camiseta Test C2']);
$created[] = $id2;
$p2 = ProductStore::find($siteId, $id2);
check_cp('slug duplicado se desambigua (-2)', ($p2['slug'] ?? '') === 'camiseta-test-c2-2', (string) ($p2['slug'] ?? ''));

// --- stock ilimitado ---------------------------------------------------------
$id3 = ProductStore::create($siteId, ['name' => 'Servicio Test C2', 'stock' => '']);
$created[] = $id3;
$p3 = ProductStore::find($siteId, $id3);
check_cp('stock vacío → NULL (ilimitado)', $p3['stock'] === null);

// --- normalize acota IVA y precio --------------------------------------------
$norm = ProductStore::normalize(['name' => 'x', 'price_cents' => -50, 'tax_rate' => 150, 'stock' => -3]);
check_cp('normalize: precio negativo → 0', $norm['price_cents'] === 0);
check_cp('normalize: IVA acotado a 99.99', $norm['tax_rate'] === 99.99, (string) $norm['tax_rate']);
check_cp('normalize: stock negativo → 0', $norm['stock'] === 0);

// --- update regenera slug solo si cambia el nombre ---------------------------
ProductStore::update($siteId, $id, ['name' => 'Camiseta Test C2', 'price_cents' => 2500, 'active' => '1']);
$p = ProductStore::find($siteId, $id);
check_cp('update sin cambio de nombre conserva slug', ($p['slug'] ?? '') === 'camiseta-test-c2');
check_cp('update aplica nuevo precio', (int) $p['price_cents'] === 2500);
ProductStore::update($siteId, $id, ['name' => 'Sudadera Test C2', 'price_cents' => 2500, 'active' => '1']);
$p = ProductStore::find($siteId, $id);
check_cp('update con nuevo nombre regenera slug', ($p['slug'] ?? '') === 'sudadera-test-c2', (string) ($p['slug'] ?? ''));

// --- findActiveBySlug solo activos -------------------------------------------
ProductStore::update($siteId, $id3, ['name' => 'Servicio Test C2', 'active' => '0']);
check_cp('findActiveBySlug ignora inactivos', ProductStore::findActiveBySlug($siteId, 'servicio-test-c2') === null);
check_cp('findActiveBySlug encuentra activos', ProductStore::findActiveBySlug($siteId, 'sudadera-test-c2') !== null);

// --- all + aislamiento por site ----------------------------------------------
$all = ProductStore::all($siteId);
$mine = array_filter($all, static fn (array $r): bool => in_array((int) $r['id'], $created, true));
check_cp('all incluye los productos creados', count($mine) === 3);
check_cp('find aislado por site', ProductStore::find($siteId + 999999, $id) === null);
check_cp('update de id inexistente → false', ProductStore::update($siteId, 99999999, ['name' => 'x']) === false);

// --- delete ------------------------------------------------------------------
foreach ($created as $cid) {
    ProductStore::delete($siteId, $cid);
}
check_cp('delete deja el sitio limpio', ProductStore::find($siteId, $id) === null && ProductStore::find($siteId, $id2) === null && ProductStore::find($siteId, $id3) === null);

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
