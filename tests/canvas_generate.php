<?php

// FH2 — QA real: genera una página CANVAS desde la referencia guardada del
// sitio 1 (visión + Unsplash + composición libre). Llamadas IA reales.

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

$create = $rc->getMethod('createReferenceCanvasPage');
$create->setAccessible(true);

$t0 = microtime(true);
try {
    $result = $create->invoke(
        null,
        $siteId,
        ['reason' => 'QA FH2 canvas'],
        'Inicio Canvas',
        'home',
        'Página de inicio que presenta el negocio, genera confianza y dirige al contacto',
        '',
        0,
        $refImages
    );
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
$elapsed = round(microtime(true) - $t0, 1);

$pageId = (int) $result['id'];
echo "page id: {$pageId}  sections: {$result['sections_count']}  elapsed: {$elapsed}s\n";

$canvas = \App\Services\Canvas\CanvasService::get($pageId);
$txt = trim(preg_replace('/\s+/', ' ', strip_tags($canvas['html'] ?? '')));
echo 'html: ' . strlen($canvas['html'] ?? '') . ' bytes · css: ' . strlen($canvas['css'] ?? '') . " bytes · texto visible: " . mb_strlen($txt) . " chars\n";
echo 'imágenes: ' . substr_count($canvas['html'] ?? '', '<img') . ' · svg inline: ' . substr_count($canvas['html'] ?? '', '<svg') . ' · placeholders form: ' . substr_count($canvas['html'] ?? '', '{{form:') . "\n";
echo 'tokens de marca en css: ' . substr_count($canvas['css'] ?? '', 'var(--pp-') . ' · hex sospechosos: ';
preg_match_all('/#[0-9a-fA-F]{3,8}\b/', (string) ($canvas['css'] ?? ''), $hex);
$badHex = array_values(array_diff(array_map('strtolower', $hex[0]), ['#fff', '#ffffff', '#000', '#000000']));
echo count($badHex) . ($badHex ? ' (' . implode(',', array_slice($badHex, 0, 6)) . ')' : '') . "\n";
$emoji = preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $txt);
echo 'emojis: ' . ($emoji ? 'SÍ (mal)' : '0') . "\n";

\Core\Database::execute("UPDATE pages SET status='published' WHERE id = ?", [$pageId]);
$slug = \Core\Database::selectOne('SELECT slug FROM pages WHERE id = ?', [$pageId]);
echo 'published at /' . ($slug['slug'] ?? '?') . PHP_EOL;
