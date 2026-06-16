<?php
// QA D-MB2 R1+R2 — regenera una home desde la referencia guardada del sitio 1
// con el pipeline nuevo (themes + imágenes Unsplash). Llamadas IA reales.
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

$siteId = 1;
$rc = new ReflectionClass(\App\Controllers\Admin\OnboardingController::class);

$loadRefs = $rc->getMethod('loadReferenceImagesForVision');
$loadRefs->setAccessible(true);
$refImages = $loadRefs->invoke(null, $siteId);
echo 'reference images: ' . count($refImages) . PHP_EOL;
if ($refImages === []) {
    fwrite(STDERR, "NO_REFS — sube referencias en paso 2 antes de la QA\n");
    exit(1);
}

$create = $rc->getMethod('createReferenceAiPage');
$create->setAccessible(true);

$t0 = microtime(true);
try {
    $result = $create->invoke(
        null,
        $siteId,
        ['reason' => 'QA D-MB2 R1+R2'],
        'Inicio DMB2',
        'home',
        'Página de inicio que presenta el negocio, genera confianza y dirige al contacto',
        '',
        0,
        $refImages
    );
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
$elapsed = round(microtime(true) - $t0, 1);

echo "page id: {$result['id']}  sections: {$result['sections_count']}  elapsed: {$elapsed}s\n";

// Resumen por sección: theme, pad, nº imágenes y warnings.
$rows = \Core\Database::select(
    'SELECT sort_order, content FROM page_sections WHERE page_id = ? ORDER BY sort_order',
    [$result['id']]
);
foreach ($rows as $row) {
    $c = json_decode((string) $row['content'], true) ?: [];
    $art = $c['art'] ?? [];
    $imgs = substr_count((string) ($c['html'] ?? ''), '<img');
    $warn = count($c['validation']['warnings'] ?? []);
    echo sprintf(
        "  #%d theme=%-8s pad=%-4s imgs=%d warnings=%d\n",
        (int) $row['sort_order'],
        ($art['theme'] ?? '') !== '' ? $art['theme'] : '(none)',
        ($art['pad'] ?? '') !== '' ? $art['pad'] : '-',
        $imgs,
        $warn
    );
}

// Publicar para QA visual.
\Core\Database::execute("UPDATE pages SET status='published' WHERE id = ?", [$result['id']]);
$slug = \Core\Database::selectOne('SELECT slug FROM pages WHERE id = ?', [$result['id']]);
echo 'published at /' . ($slug['slug'] ?? '?') . PHP_EOL;
