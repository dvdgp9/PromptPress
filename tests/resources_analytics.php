<?php

declare(strict_types=1);

/** FEAT-RESOURCES R7 — conversión de descarga sin datos personales. */

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
function check_ra(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_ra('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);
check_ra('dashboard traduce la conversión de recursos',
    str_contains((string) file_get_contents(PP_ROOT . '/admin/assets/js/analytics-dashboard.js'), "resource_download: pp.t('js.an.resource_download')"));
foreach (['es', 'en', 'fr', 'pt'] as $locale) {
    $catalog = require PP_ROOT . '/lang/admin/' . $locale . '.php';
    check_ra('etiqueta analytics disponible en ' . $locale, trim((string) ($catalog['js.an.resource_download'] ?? '')) !== '');
}

$lang = LanguageService::primaryFor($siteId);
$resourceId = 0;
$file = '';
$proc = null;
$maxEvent = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) n FROM analytics_events')['n'] ?? 0);
$original = [];
foreach (['resources', 'analytics'] as $module) {
    $key = ModuleRegistry::settingKey($module);
    $original[$module] = Database::selectOne(
        'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
        [$siteId, $key]
    );
}

try {
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    ModuleRegistry::setEnabled($siteId, 'analytics', true);
    $dir = PP_ROOT . '/storage/resources/' . $siteId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = $dir . '/' . bin2hex(random_bytes(32)) . '.pdf';
    file_put_contents($file, "%PDF-1.4\nR7 analytics\n%%EOF\n");
    $resourceId = ResourceStore::create($siteId, [
        'title' => 'Guía Analytics R7',
        'file_path' => 'storage/resources/' . $siteId . '/' . basename($file),
        'original_filename' => 'guia-analytics-r7.pdf',
        'file_mime' => 'application/pdf',
        'file_size' => filesize($file),
        'access_mode' => 'direct',
        'language' => $lang,
        'status' => 'published',
    ]);
    $resource = ResourceStore::find($siteId, $resourceId);
    $slug = (string) $resource['slug'];
    $expectedPath = '/recursos/' . $slug . '/descargar';

    $port = 8834;
    $root = PP_ROOT;
    $proc = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    usleep(400000);
    $get = static function (string $path): int {
        $ch = curl_init('http://127.0.0.1:8834' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 R7Test Chrome/125.0',
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    };

    check_ra('descarga válida responde 200', $get($expectedPath . '?utm=secret') === 200);
    $events = Database::select(
        "SELECT event_type,path FROM analytics_events WHERE site_id = ? AND id > ? ORDER BY id",
        [$siteId, $maxEvent]
    );
    check_ra('descarga registra resource_download', count($events) === 1 && ($events[0]['event_type'] ?? '') === 'resource_download', json_encode($events));
    check_ra('etiqueta es slug lógico sin query ni token', ($events[0]['path'] ?? '') === $expectedPath && !str_contains((string) ($events[0]['path'] ?? ''), '?'), json_encode($events));

    $beforeInvalid = count($events);
    check_ra('archivo inexistente devuelve 404', $get('/recursos/no-existe-r7/descargar') === 404);
    $afterInvalid = (int) (Database::selectOne('SELECT COUNT(*) n FROM analytics_events WHERE site_id = ? AND id > ?', [$siteId, $maxEvent])['n'] ?? -1);
    check_ra('una descarga fallida no cuenta conversión', $afterInvalid === $beforeInvalid);

    ModuleRegistry::setEnabled($siteId, 'analytics', false);
    check_ra('descarga sigue funcionando con Analytics apagado', $get($expectedPath) === 200);
    $afterOff = (int) (Database::selectOne('SELECT COUNT(*) n FROM analytics_events WHERE site_id = ? AND id > ?', [$siteId, $maxEvent])['n'] ?? -1);
    check_ra('Analytics apagado no registra evento', $afterOff === $beforeInvalid);
} finally {
    if (is_resource($proc)) proc_terminate($proc);
    Database::execute('DELETE FROM analytics_events WHERE site_id = ? AND id > ?', [$siteId, $maxEvent]);
    if ($resourceId > 0) ResourceStore::delete($siteId, $resourceId);
    if ($file !== '' && is_file($file)) unlink($file);
    foreach ($original as $module => $row) {
        $key = ModuleRegistry::settingKey($module);
        if ($row === null) Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
        else ModuleRegistry::setEnabled($siteId, $module, (string) $row['setting_value'] === '1');
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
