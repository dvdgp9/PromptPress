<?php
// FH8 — Test de uniqueSlug con $ignoreId (re-guardar no genera "slug-2").
require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  → ' . $detail . PHP_EOL; }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check('hay un site para probar', $siteId > 0);
if ($siteId === 0) { exit(1); }

$base = 'fh8-test-' . substr((string) time(), -6);
$now = date('Y-m-d H:i:s');
$createdBy = (int) (Database::selectOne('SELECT id FROM users ORDER BY id ASC LIMIT 1')['id'] ?? 1);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, status, sort_order, created_by, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?)",
    [$siteId, 'FH8 Test', $base, 'landing', 'draft', 0, $createdBy, $now, $now]
);
$pageId = (int) Database::lastInsertId();

// 1. Re-guardar el MISMO slug en la misma página NO debe colisionar consigo mismo.
$slug1 = PageController::uniqueSlug($siteId, $base, $pageId);
check('mismo slug ignorando la propia página se mantiene', $slug1 === $base, "esperado=$base obtenido=$slug1");

// 2. Sin ignoreId sí colisiona (comportamiento legacy intacto).
$slug2 = PageController::uniqueSlug($siteId, $base);
check('sin ignoreId desambigua', $slug2 === $base . '-2', "obtenido=$slug2");

// 3. Otra página distinta pidiendo el mismo base sí desambigua.
$slug3 = PageController::uniqueSlug($siteId, $base, $pageId + 999999);
check('otra página desambigua', $slug3 === $base . '-2', "obtenido=$slug3");

Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
