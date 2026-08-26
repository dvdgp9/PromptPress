<?php

declare(strict_types=1);

/** FEAT-RESOURCES R11 — la página manda aunque su idioma no esté activo globalmente. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceStore;
use App\Services\Canvas\CanvasService;
use App\Services\LanguageService;
use App\Services\Microcopy;
use Core\Database;

$failed = 0;
function check_rpl(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rpl('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$active = LanguageService::activeFor($siteId);
$secondary = !in_array('fr', $active, true) ? 'fr' : '';
if ($secondary === '') {
    foreach (array_keys(LanguageService::LANGUAGES) as $code) {
        if (!in_array($code, $active, true)) {
            $secondary = $code;
            break;
        }
    }
}
check_rpl('hay idioma soportado pero inactivo para reproducir', $secondary !== '');
if ($secondary === '') exit(1);

$resourceId = 0;
$pageId = 0;
$proc = null;
$key = ModuleRegistry::settingKey('resources');
$originalModule = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $key]);
$slug = 'r11-resource-page-language';
$pageSlug = $secondary . '/r11-page-language';
$relativeFile = 'storage/resources/' . $siteId . '/' . str_repeat('b', 64) . '.pdf';
$absoluteFile = PP_ROOT . '/' . $relativeFile;

try {
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    if (!is_dir(dirname($absoluteFile))) mkdir(dirname($absoluteFile), 0775, true);
    file_put_contents($absoluteFile, str_pad("%PDF-1.4\nR11\n", 2048, 'x'));
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode, created_at, updated_at)
         VALUES (?, 'R11 page language', ?, 'landing', ?, UUID(), 'draft', 'canvas', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        [$siteId, $pageSlug, $secondary]
    );
    $pageId = (int) Database::lastInsertId();

    $resourceId = ResourceStore::create($siteId, [
        'title' => 'R11 multilingual resource',
        'slug' => $slug,
        'language' => LanguageService::primaryFor($siteId),
        'language_scope' => 'all',
        'file_path' => $relativeFile,
        'original_filename' => 'r11-test.pdf',
        'file_mime' => 'application/pdf',
        'file_size' => 2048,
        'access_mode' => 'direct',
        'status' => 'published',
    ]);
    $resource = ResourceStore::find($siteId, $resourceId);
    $actualSlug = (string) ($resource['slug'] ?? '');

    check_rpl(
        'store reconoce el idioma usado por una página',
        method_exists(ResourceStore::class, 'languageAvailableForSite')
            && ResourceStore::languageAvailableForSite($siteId, $secondary)
    );

    $hasForm = false;
    $hasResources = false;
    $block = CanvasService::expandPlaceholders(
        '{{resources:featured|limit=1}}',
        $siteId,
        $hasForm,
        $secondary,
        $hasResources
    );
    check_rpl(
        'tarjeta usa microcopy e hipervínculo del idioma de página',
        $hasResources
            && str_contains($block, Microcopy::t('resources.view', $secondary))
            && str_contains($block, '/' . $secondary . '/recursos/' . $actualSlug),
        $block
    );

    $port = 8835;
    $root = PP_ROOT;
    $proc = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    usleep(400000);
    $ch = curl_init('http://127.0.0.1:' . $port . '/' . $secondary . '/recursos/' . rawurlencode($actualSlug));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $html = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    check_rpl(
        'clic desde la página abre la ficha y no un 404',
        $status === 200
            && str_contains($html, 'R11 multilingual resource')
            && str_contains($html, Microcopy::t('resources.download', $secondary)),
        'status=' . $status . ' body=' . mb_substr(strip_tags($html), 0, 250)
    );

    $ch = curl_init('http://127.0.0.1:' . $port . '/' . $secondary . '/recursos/' . rawurlencode($actualSlug) . '/descargar');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $binary = (string) curl_exec($ch);
    $downloadStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    check_rpl(
        'la descarga conserva el mismo idioma válido',
        $downloadStatus === 200 && str_starts_with($binary, '%PDF-1.4'),
        'status=' . $downloadStatus
    );
} finally {
    if (is_resource($proc)) proc_terminate($proc);
    if ($resourceId > 0) ResourceStore::delete($siteId, $resourceId);
    if ($pageId > 0) Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$pageId, $siteId]);
    if (is_file($absoluteFile)) unlink($absoluteFile);
    if ($originalModule === null) Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
    else ModuleRegistry::setEnabled($siteId, 'resources', (string) $originalModule['setting_value'] === '1');
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
