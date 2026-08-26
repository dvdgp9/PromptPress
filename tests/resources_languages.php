<?php

declare(strict_types=1);

/** FEAT-RESOURCES R8 — disponibilidad en uno, varios o todos los idiomas. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceStore;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_rl(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$hasScope = Database::selectOne(
    "SELECT 1 ok FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'language_scope'"
) !== null;
$hasPivot = Database::selectOne(
    "SELECT 1 ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_languages'"
) !== null;
check_rl('esquema soporta alcance e idiomas múltiples', $hasScope && $hasPivot);
check_rl('store expone idiomas visibles', method_exists(ResourceStore::class, 'visibleLanguages'));

if (!$hasScope || !$hasPivot || !method_exists(ResourceStore::class, 'visibleLanguages')) {
    echo PHP_EOL . $failed . ' FAILED' . PHP_EOL;
    exit(1);
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rl('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$primary = LanguageService::primaryFor($siteId);
$frWasActive = LanguageService::isActive($siteId, 'fr');
$ptWasActive = LanguageService::isActive($siteId, 'pt');
$created = [];
$key = ModuleRegistry::settingKey('resources');
$originalModule = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $key]);
$base = [
    'file_path' => 'storage/resources/' . $siteId . '/r8-language.pdf',
    'original_filename' => 'r8-language.pdf',
    'file_mime' => 'application/pdf',
    'file_size' => 2048,
    'access_mode' => 'direct',
    'status' => 'published',
];

try {
    if (!$frWasActive) LanguageService::enable($siteId, 'fr');
    ModuleRegistry::setEnabled($siteId, 'resources', true);

    $id = ResourceStore::create($siteId, $base + [
        'title' => 'Recurso multidioma R8',
        'language' => $primary,
        'language_scope' => 'selected',
        'languages' => [$primary],
    ]);
    $created[] = $id;
    $resource = ResourceStore::find($siteId, $id);
    check_rl('selección inicial conserva un idioma', ($resource['language_scope'] ?? '') === 'selected' && ($resource['languages'] ?? []) === [$primary], json_encode($resource));
    check_rl('solo aparece en el idioma seleccionado',
        ResourceStore::findPublishedBySlug($siteId, $primary, (string) $resource['slug']) !== null
        && ResourceStore::findPublishedBySlug($siteId, 'fr', (string) $resource['slug']) === null);

    ResourceStore::update($siteId, $id, ['languages' => [$primary, 'fr'], 'language_scope' => 'selected']);
    $resource = ResourceStore::find($siteId, $id);
    check_rl('permite seleccionar varios idiomas', count($resource['languages'] ?? []) === 2 && in_array('fr', $resource['languages'], true), json_encode($resource));
    check_rl('el mismo recurso es visible en ambos catálogos',
        ResourceStore::findPublishedBySlug($siteId, $primary, (string) $resource['slug']) !== null
        && ResourceStore::findPublishedBySlug($siteId, 'fr', (string) $resource['slug']) !== null);

    ResourceStore::update($siteId, $id, ['language_scope' => 'all']);
    if (!$ptWasActive) LanguageService::enable($siteId, 'pt');
    LanguageService::forget($siteId);
    $resource = ResourceStore::find($siteId, $id);
    check_rl('todos incluye idiomas activados después',
        ($resource['language_scope'] ?? '') === 'all'
        && in_array('pt', ResourceStore::visibleLanguages($siteId, $resource), true)
        && ResourceStore::findPublishedBySlug($siteId, 'pt', (string) $resource['slug']) !== null,
        json_encode(ResourceStore::visibleLanguages($siteId, $resource)));

    $view = (string) file_get_contents(PP_ROOT . '/views/admin/resources/edit.php');
    $studio = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
    check_rl('editor muestra siempre disponibilidad multidioma',
        str_contains($view, 'name="languages[]"') && str_contains($view, 'name="language_scope"')
        && !str_contains($view, 'if (count($languages) > 1)'));
    check_rl('Studio explica una incompatibilidad de idioma',
        str_contains($studio, 'cv.resources.language_mismatch') && str_contains($studio, 'admin/resources'));
} finally {
    foreach ($created as $id) ResourceStore::delete($siteId, $id);
    if (!$ptWasActive) {
        LanguageService::forget($siteId);
        LanguageService::disable($siteId, 'pt');
    }
    if (!$frWasActive) {
        LanguageService::forget($siteId);
        LanguageService::disable($siteId, 'fr');
    }
    LanguageService::forget($siteId);
    if ($originalModule === null) Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
    else ModuleRegistry::setEnabled($siteId, 'resources', (string) $originalModule['setting_value'] === '1');
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
