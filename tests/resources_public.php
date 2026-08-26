<?php

declare(strict_types=1);

/** FEAT-RESOURCES R4 — contrato del catálogo y la ficha públicos. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Resources\ResourceStore;
use App\Modules\ModuleRegistry;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\FormStore;
use Core\Database;

$failed = 0;
function check_rp(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rp('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$lang = LanguageService::primaryFor($siteId);
$ids = [];
$formId = 0;
$proc = null;
$settingKey = ModuleRegistry::settingKey('resources');
$originalModule = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $settingKey]);
$base = [
    'file_path' => 'storage/resources/' . $siteId . '/r4-test.pdf',
    'original_filename' => 'r4-test.pdf',
    'file_mime' => 'application/pdf',
    'file_size' => 2048,
    'access_mode' => 'direct',
    'language' => $lang,
];

try {
    $ids[] = ResourceStore::create($siteId, $base + ['title' => 'R4 publicado visible', 'status' => 'published']);
    $ids[] = ResourceStore::create($siteId, $base + ['title' => 'R4 borrador oculto', 'status' => 'draft']);
    $containerId = FormStore::containerPageId($siteId);
    Database::execute(
        "INSERT INTO page_sections (page_id, section_type, sort_order, content, status, created_at, updated_at)
         VALUES (?, 'form', 99998, ?, 'editable', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        [$containerId, json_encode(['heading' => 'Formulario R4', 'fields' => []])]
    );
    $formId = (int) Database::lastInsertId();
    $ids[] = ResourceStore::create($siteId, array_merge($base, [
        'title' => 'R4 acceso con formulario', 'status' => 'published',
        'access_mode' => 'form', 'form_id' => $formId,
    ]));

    $hasPublicQuery = method_exists(ResourceStore::class, 'publishedForLanguage');
    check_rp('store expone consulta pública por idioma', $hasPublicQuery);
    if ($hasPublicQuery) {
        $rows = ResourceStore::publishedForLanguage($siteId, $lang);
        $titles = array_column($rows, 'title');
        check_rp('catálogo incluye publicados', in_array('R4 publicado visible', $titles, true));
        check_rp('catálogo excluye borradores', !in_array('R4 borrador oculto', $titles, true));
    }

    $routes = (string) file_get_contents(PP_ROOT . '/app/Modules/Resources/routes.php');
    check_rp('existen catálogo y ficha en ruta principal',
        str_contains($routes, "get('/recursos'") && str_contains($routes, "get('/recursos/{slug}'"));
    check_rp('existen catálogo y ficha con prefijo de idioma',
        str_contains($routes, "get('/{lang}/recursos'") && str_contains($routes, "get('/{lang}/recursos/{slug}'"));
    check_rp('las rutas usan controlador público dedicado', str_contains($routes, 'ResourcePublicController'));

    check_rp('renderer público existe', is_file(PP_ROOT . '/app/Modules/Resources/ResourceRenderer.php'));
    check_rp('CSS público aislado existe', is_file(PP_ROOT . '/public/css/resources.css'));
    check_rp('catálogo tiene microcopy en idioma del visitante', Microcopy::t('resources.title', $lang) !== '');
    check_rp('CTA directa tiene microcopy', Microcopy::t('resources.download', $lang) !== '');
    check_rp('acceso por formulario no promete descarga directa', Microcopy::t('resources.form_required', $lang) !== '');

    ModuleRegistry::setEnabled($siteId, 'resources', true);
    $published = ResourceStore::find($siteId, $ids[0]);
    $draft = ResourceStore::find($siteId, $ids[1]);
    $conditioned = ResourceStore::find($siteId, $ids[2]);
    $port = 8831;
    $root = PP_ROOT;
    $proc = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    usleep(400000);
    $get = static function (string $path) use ($port): array {
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    };

    [$status, $html] = $get('/recursos');
    check_rp('HTTP catálogo 200 muestra publicado y oculta borrador',
        $status === 200 && str_contains($html, 'R4 publicado visible') && !str_contains($html, 'R4 borrador oculto'));
    check_rp('catálogo enlaza CSS externo y no incrusta CSS propio',
        str_contains($html, '/public/css/resources.css') && !str_contains($html, '.pp-resource-card{'));
    check_rp('catálogo declara canonical y hreflang',
        str_contains($html, 'rel="canonical"') && str_contains($html, 'hreflang="' . $lang . '"'));

    [$status, $html] = $get('/recursos/' . (string) $published['slug']);
    check_rp('HTTP ficha publicada 200 con descarga directa',
        $status === 200 && str_contains($html, '/recursos/' . $published['slug'] . '/descargar'));
    check_rp('sin portada renderiza fallback accesible sin imagen rota',
        str_contains($html, 'pp-resource__cover--empty') && !str_contains($html, '<img class="pp-resource__cover"'));

    [$status] = $get('/recursos/' . (string) $draft['slug']);
    check_rp('HTTP borrador no es público', $status === 404);
    [$status, $html] = $get('/recursos/' . (string) $conditioned['slug']);
    check_rp('ficha condicionada no filtra el endpoint directo antes de R5',
        $status === 200
        && str_contains($html, Microcopy::t('resources.form_required', $lang))
        && !str_contains($html, '/recursos/' . $conditioned['slug'] . '/descargar'));
    [$status] = $get('/zz/recursos');
    check_rp('HTTP idioma no admitido devuelve 404', $status === 404);

    ModuleRegistry::setEnabled($siteId, 'resources', false);
    [$status] = $get('/recursos');
    check_rp('módulo apagado oculta catálogo', $status === 404);
} finally {
    if (is_resource($proc)) proc_terminate($proc);
    foreach ($ids as $id) ResourceStore::delete($siteId, $id);
    if ($formId > 0) Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
    if ($originalModule === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $settingKey]);
    } else {
        ModuleRegistry::setEnabled($siteId, 'resources', (string) $originalModule['setting_value'] === '1');
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
