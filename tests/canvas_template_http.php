<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S4 — contrato HTTP de las plantillas manuales.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function templateHttpCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
templateHttpCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'studio-template-s4-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Studio Template S4', ?, 'landing', 'fr', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();
CanvasService::save(
    $pageId,
    '<section data-pp-section="hero"><h1>Accueil</h1></section><section data-pp-section="fin"><p>Fin</p></section>',
    '',
    'test',
    'Base S4'
);

$port = 8797;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(450000);
$baseUrl = 'http://127.0.0.1:' . $port;
$cookieJar = tempnam(sys_get_temp_dir(), 'studio-s4-');

$http = static function (string $method, string $url, array $post = []) use ($cookieJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Studio Template S4 test)',
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
};
$csrfOf = static function (string $html): string {
    if (preg_match('/<meta name="csrf" content="([^"]+)"/', $html, $m) === 1) return $m[1];
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1) return $m[1];
    return '';
};

try {
    [, $login] = $http('GET', $baseUrl . '/admin/login');
    $http('POST', $baseUrl . '/admin/login', [
        'identifier' => 'admin', 'password' => 'supersecret123', '_csrf' => $csrfOf($login),
    ]);
    [$studioStatus, $studio] = $http('GET', $baseUrl . '/admin/canvas/' . $pageId);
    $csrf = $csrfOf($studio);
    templateHttpCheck('Studio S4 autenticado', $studioStatus === 200 && $csrf !== '');

    $versions = count(CanvasService::versions($pageId));
    [$status, $body] = $http('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf,
        'action' => 'insert_template',
        'template' => 'text',
        'section' => 'fin',
        'position' => 'before',
    ]);
    $json = json_decode($body, true) ?: [];
    $saved = CanvasService::get($pageId);
    $ids = array_column(CanvasService::listSections((string) ($saved['html'] ?? '')), 'id');
    templateHttpCheck('plantilla se inserta en punto exacto',
        $status === 200 && ($json['ok'] ?? false) === true
        && count($ids) === 3 && $ids[0] === 'hero' && str_starts_with($ids[1], 'manual-text-') && $ids[2] === 'fin',
        $body
    );
    templateHttpCheck('contenido sigue francés de página',
        str_contains((string) ($saved['html'] ?? ''), 'Une idée à retenir')
        && !str_contains((string) ($saved['html'] ?? ''), 'Una idea para recordar')
    );
    templateHttpCheck('inserción crea una sola versión y devuelve foco',
        count(CanvasService::versions($pageId)) === $versions + 1
        && ($json['changed_section'] ?? '') === ($ids[1] ?? '')
        && ($json['focus_section'] ?? '') === ($ids[1] ?? '')
    );

    $beforeInvalid = count(CanvasService::versions($pageId));
    [$badStatus] = $http('POST', $baseUrl . '/admin/canvas/' . $pageId . '/structure', [
        '_csrf' => $csrf, 'action' => 'insert_template', 'template' => 'unknown',
        'section' => 'fin', 'position' => 'before',
    ]);
    templateHttpCheck('plantilla desconocida no modifica nada',
        $badStatus === 422 && count(CanvasService::versions($pageId)) === $beforeInvalid,
        (string) $badStatus
    );
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink($cookieJar);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
