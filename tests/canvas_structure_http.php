<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S2 — contrato HTTP real: sesión admin, CSRF, versionado,
// move/delete, undo/redo e inserción funcional en una posición explícita.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use App\Services\FormStore;
use Core\Database;

$failed = 0;
function structureHttpCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
structureHttpCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'studio-structure-s2-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Studio Structure S2', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();
$formId = FormStore::createFromTemplate($siteId, 'newsletter');
$baseHtml = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="features"><h2>Features</h2></section>'
    . '<section data-pp-section="cta"><p>CTA</p></section>';
CanvasService::save($pageId, $baseHtml, '', 'test', 'Base S2');

// Segundo sitio: prueba que un id válido no atraviesa el scope de la sesión.
Database::execute(
    "INSERT INTO sites (name, url, language, timezone, created_at, updated_at)
     VALUES ('Foreign S2', 'https://foreign-s2.invalid', 'es', 'Europe/Madrid', ?, ?)",
    [$now, $now]
);
$foreignSiteId = (int) Database::lastInsertId();
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Foreign Canvas S2', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$foreignSiteId, $slug . '-foreign', $now, $now]
);
$foreignPageId = (int) Database::lastInsertId();
CanvasService::save($foreignPageId, $baseHtml, '', 'test', 'Foreign base S2');

$port = 8795;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(450000);
$baseUrl = 'http://127.0.0.1:' . $port;
$cookieJar = tempnam(sys_get_temp_dir(), 'studio-s2-');

function structureHttp(string $method, string $url, array $post = []): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Studio Structure S2 test)',
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html'],
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

function structureCsrf(string $html): string
{
    if (preg_match('/<meta name="csrf" content="([^"]+)"/', $html, $m) === 1) return $m[1];
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1) return $m[1];
    return '';
}

function structureJson(string $body): array
{
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

try {
    // Login admin y token del Studio.
    [$status, $html] = structureHttp('GET', $baseUrl . '/admin/login');
    structureHttp('POST', $baseUrl . '/admin/login', [
        'identifier' => 'admin',
        'password' => 'supersecret123',
        '_csrf' => structureCsrf($html),
    ]);
    [$status, $studio] = structureHttp('GET', $baseUrl . '/admin/canvas/' . $pageId);
    $csrf = structureCsrf($studio);
    structureHttpCheck('Studio autenticado entrega CSRF', $status === 200 && $csrf !== '', (string) $status);

    // Sin CSRF: rechazo y cero versiones nuevas.
    $versionsBefore = count(CanvasService::versions($pageId));
    [$status] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        'action' => 'move', 'section' => 'features', 'direction' => 'up',
    ]);
    structureHttpCheck('estructura exige CSRF', $status >= 400 && count(CanvasService::versions($pageId)) === $versionsBefore, (string) $status);

    [$status] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $foreignPageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'delete', 'section' => 'hero',
    ]);
    structureHttpCheck(
        'estructura no atraviesa el sitio de la sesión',
        $status === 404 && count(CanvasService::listSections((string) CanvasService::get($foreignPageId)['html'])) === 3,
        (string) $status
    );

    // Movimiento válido: una versión y respuesta suficiente para refrescar UI.
    [$status, $body] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'move', 'section' => 'features', 'direction' => 'up',
    ]);
    $json = structureJson($body);
    structureHttpCheck('move HTTP responde ok', $status === 200 && ($json['ok'] ?? false) === true, $body);
    structureHttpCheck('move HTTP devuelve orden e historial',
        array_column($json['sections'] ?? [], 'id') === ['features', 'hero', 'cta']
        && ($json['history']['can_undo'] ?? false) === true,
        $body
    );
    structureHttpCheck('move crea una sola versión', count(CanvasService::versions($pageId)) === $versionsBefore + 1);

    // Undo/redo existentes deben comprender la versión estructural.
    [$status, $undoBody] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/undo', ['_csrf' => $csrf]);
    structureHttpCheck('undo restaura orden anterior',
        $status === 200 && array_column(CanvasService::listSections((string) CanvasService::get($pageId)['html']), 'id') === ['hero', 'features', 'cta'],
        $undoBody
    );
    [$status, $redoBody] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/redo', ['_csrf' => $csrf]);
    structureHttpCheck('redo reaplica movimiento',
        $status === 200 && array_column(CanvasService::listSections((string) CanvasService::get($pageId)['html']), 'id') === ['features', 'hero', 'cta'],
        $redoBody
    );

    // Límite y errores no crean versiones.
    $versionsBeforeNoop = count(CanvasService::versions($pageId));
    [$status, $body] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'move', 'section' => 'features', 'direction' => 'up',
    ]);
    $json = structureJson($body);
    structureHttpCheck('movimiento en límite es no-op explícito', $status === 200 && ($json['changed'] ?? true) === false, $body);
    structureHttpCheck('no-op no crea versión', count(CanvasService::versions($pageId)) === $versionsBeforeNoop);

    [$status] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'move', 'section' => 'missing', 'direction' => 'up',
    ]);
    structureHttpCheck('sección obsoleta devuelve conflicto', $status === 409 && count(CanvasService::versions($pageId)) === $versionsBeforeNoop, (string) $status);

    [$status] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'move', 'section' => 'hero', 'direction' => 'left',
    ]);
    structureHttpCheck('dirección inválida devuelve 422', $status === 422 && count(CanvasService::versions($pageId)) === $versionsBeforeNoop, (string) $status);

    // Los endpoints funcionales comparten posición before/after explícita.
    [$status, $body] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/insert-form', [
        '_csrf' => $csrf, 'form_id' => $formId, 'section' => 'features', 'position' => 'before',
    ]);
    $json = structureJson($body);
    $afterForm = CanvasService::get($pageId);
    $formSectionId = (string) (($json['sections'][0]['id'] ?? ''));
    structureHttpCheck('formulario se inserta antes del ancla exacta',
        $status === 200 && str_starts_with($formSectionId, 'form-' . $formId . '-')
        && array_column(CanvasService::listSections((string) $afterForm['html']), 'id')[1] === 'features',
        $body
    );

    // Borrar colocación no borra el formulario administrado.
    [$status, $body] = structureHttp('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'delete', 'section' => $formSectionId,
    ]);
    $json = structureJson($body);
    structureHttpCheck('delete HTTP retira la colocación',
        $status === 200 && ($json['ok'] ?? false) === true
        && !str_contains((string) CanvasService::get($pageId)['html'], '{{form:' . $formId . '}}'),
        $body
    );
    structureHttpCheck('delete conserva entidad de formulario', FormStore::find($siteId, $formId) !== null);
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink($cookieJar);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
    Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$foreignPageId]);
    Database::execute('DELETE FROM sites WHERE id = ?', [$foreignSiteId]);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
