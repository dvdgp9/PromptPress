<?php

declare(strict_types=1);

/** FEAT-RESOURCES R5 — entrega firmada tras formulario. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Resources\ResourceAccessService;
use App\Modules\Resources\ResourceFileService;
use App\Modules\Resources\ResourceStore;
use App\Modules\ModuleRegistry;
use App\Services\FormStore;
use App\Services\FormSubmissionService;
use App\Services\LanguageService;
use App\Services\Security\BotGuard;
use Core\Database;

$failed = 0;
function check_rd(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$hasService = class_exists(ResourceAccessService::class);
check_rd('existe servicio de acceso firmado', $hasService);
check_rd('controller de formulario integra contexto opcional',
    str_contains((string) file_get_contents(PP_ROOT . '/app/Controllers/Public/FormController.php'), '_resource_context'));
check_rd('ficha pública renderiza el formulario elegido',
    str_contains((string) file_get_contents(PP_ROOT . '/app/Modules/Resources/ResourcePublicController.php'), 'ResourceAccessService'));
check_rd('JS ofrece la descarga tras el éxito',
    str_contains((string) file_get_contents(PP_ROOT . '/public/js/pp-ux.js'), 'download_url'));

if (!$hasService) {
    echo PHP_EOL . $failed . ' FAILED' . PHP_EOL;
    exit(1);
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rd('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$lang = LanguageService::primaryFor($siteId);
$formId = 0;
$resourceId = 0;
$submissionId = 0;
$file = '';
$proc = null;
$cookieJar = '';
$maxSubmission = 0;
$settingKey = ModuleRegistry::settingKey('resources');
$originalModule = Database::selectOne(
    'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, $settingKey]
);

try {
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    $formId = FormStore::create($siteId, [
        'heading' => 'Accede a la guía',
        'description' => 'Déjanos tus datos y la descarga empezará al momento.',
        'submit_text' => 'Acceder a la descarga',
        'success_message' => 'Tu descarga está lista.',
        'fields' => [
            ['label' => 'Nombre', 'name' => 'nombre', 'field_type' => 'text', 'required' => '1'],
            ['label' => 'Email', 'name' => 'email', 'field_type' => 'email', 'required' => '1'],
        ],
    ]);

    $dir = PP_ROOT . '/storage/resources/' . $siteId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = $dir . '/' . str_repeat('a', 64) . '.pdf';
    file_put_contents($file, "%PDF-1.4\nR5 signed delivery\n%%EOF\n");
    $resourceId = ResourceStore::create($siteId, [
        'title' => 'Guía entrega R5', 'description' => 'Recurso condicionado de prueba.',
        'file_path' => 'storage/resources/' . $siteId . '/' . basename($file),
        'original_filename' => 'Guía entrega R5.pdf', 'file_mime' => 'application/pdf',
        'file_size' => filesize($file), 'access_mode' => 'form', 'form_id' => $formId,
        'language' => $lang, 'status' => 'published',
    ]);
    $resource = ResourceStore::find($siteId, $resourceId);

    $now = 1800000000;
    $context = ResourceAccessService::issueFormContext($siteId, $resource, 3600, $now);
    check_rd('contexto válido liga sitio, recurso y formulario',
        ResourceAccessService::validateFormContext($context, $siteId, $formId, $now + 30)['id'] === $resourceId);
    check_rd('contexto manipulado se rechaza',
        ResourceAccessService::validateFormContext($context . 'x', $siteId, $formId, $now + 30) === null);
    check_rd('contexto no cruza formulario',
        ResourceAccessService::validateFormContext($context, $siteId, $formId + 1, $now + 30) === null);
    check_rd('contexto caducado se rechaza',
        ResourceAccessService::validateFormContext($context, $siteId, $formId, $now + 3601) === null);

    FormSubmissionService::ensureSchema();
    $containerId = FormStore::containerPageId($siteId);
    Database::execute(
        "INSERT INTO form_submissions
            (site_id,page_id,section_id,page_title,section_heading,payload,ip_hash,bot_check,status,email_status,autoresponder_status,created_at)
         VALUES (?,?,?,?,?,?,'r5-test','timetrap','unread','skipped','disabled',UTC_TIMESTAMP())",
        [$siteId, $containerId, $formId, 'Guía entrega R5', 'Accede a la guía', '{}']
    );
    $submissionId = (int) Database::lastInsertId();
    $token = ResourceAccessService::issueDownloadToken($siteId, $resourceId, $submissionId, 86400, $now);
    check_rd('token de descarga valida submission del formulario correcto',
        ResourceAccessService::validateDownloadToken($token, $siteId, $resourceId, $now + 30)['id'] === $resourceId);
    check_rd('token no cruza recurso',
        ResourceAccessService::validateDownloadToken($token, $siteId, $resourceId + 1, $now + 30) === null);
    check_rd('token caduca a las 24 horas',
        ResourceAccessService::validateDownloadToken($token, $siteId, $resourceId, $now + 86401) === null);

    $prepared = ResourceFileService::prepareConditionedDownload($siteId, $lang, (string) $resource['slug'], $token, $now + 30);
    check_rd('archivo condicionado solo se prepara con token válido',
        is_array($prepared) && (string) $prepared['absolute_path'] === $file);
    check_rd('sin token no degrada a descarga directa',
        ResourceFileService::prepareDirectDownload($siteId, $lang, (string) $resource['slug']) === null);

    // Ciclo HTTP completo: ficha -> formulario -> JSON con enlace -> descarga.
    $maxSubmission = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) m FROM form_submissions')['m'] ?? 0);
    $port = 8832;
    $root = PP_ROOT;
    $proc = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    usleep(400000);
    $baseUrl = 'http://127.0.0.1:' . $port;
    $cookieJar = (string) tempnam(sys_get_temp_dir(), 'pprd');
    $request = static function (string $method, string $url, array $fields = [], bool $json = false): array {
        global $cookieJar;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        if ($json) curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    };

    $page = $request('GET', $baseUrl . '/recursos/' . $resource['slug']);
    preg_match('/name="_csrf" value="([0-9a-f]+)"/', $page['body'], $csrfMatch);
    preg_match('/name="_resource_context" value="([^"]+)"/', $page['body'], $contextMatch);
    $csrf = $csrfMatch[1] ?? '';
    $pageContext = html_entity_decode($contextMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
    check_rd('HTTP ficha integra el formulario elegido y contexto opaco',
        $page['status'] === 200 && $csrf !== '' && $pageContext !== ''
        && str_contains($page['body'], 'data-pp-form-id="' . $formId . '"'));

    $fields = [
        '_csrf' => $csrf, '_return' => '/recursos/' . $resource['slug'], '_lang' => $lang,
        '_pp_ts' => BotGuard::issueTimestamp(time() - 60), '_resource_context' => $pageContext,
        'nombre' => 'Lectora R5', 'email' => 'lectora-r5@example.test',
    ];
    $sent = $request('POST', $baseUrl . '/forms/' . $formId, $fields, true);
    $json = json_decode($sent['body'], true);
    $downloadUrl = is_array($json) ? (string) ($json['download_url'] ?? '') : '';
    check_rd('POST válido guarda respuesta y devuelve enlace firmado',
        $sent['status'] === 200 && ($json['ok'] ?? false) === true
        && (int) ($json['submission_id'] ?? 0) > 0 && $downloadUrl !== '', $sent['body']);

    $download = $request('GET', $downloadUrl);
    check_rd('GET firmado descarga el binario con cabeceras seguras',
        $download['status'] === 200 && $download['body'] === (string) file_get_contents($file)
        && str_contains($download['headers'], 'Content-Disposition: attachment;')
        && str_contains($download['headers'], 'X-Content-Type-Options: nosniff'));
    $withoutToken = $request('GET', $baseUrl . '/recursos/' . $resource['slug'] . '/descargar');
    check_rd('HTTP condicionado sin token devuelve 404', $withoutToken['status'] === 404);
    $tampered = $request('GET', $downloadUrl . 'x');
    check_rd('HTTP token manipulado devuelve 404 indistinguible', $tampered['status'] === 404);

    $badContextFields = $fields;
    $badContextFields['_pp_ts'] = BotGuard::issueTimestamp(time() - 60);
    $badContextFields['_resource_context'] .= 'x';
    $bad = $request('POST', $baseUrl . '/forms/' . $formId, $badContextFields, true);
    $badJson = json_decode($bad['body'], true);
    check_rd('contexto inválido conserva formulario normal pero no concede acceso',
        $bad['status'] === 200 && ($badJson['ok'] ?? false) === true
        && !isset($badJson['download_url']) && isset($badJson['submission_id']));

    $noJsFields = $fields;
    $noJsFields['_pp_ts'] = BotGuard::issueTimestamp(time() - 60);
    $noJs = $request('POST', $baseUrl . '/forms/' . $formId, $noJsFields, false);
    check_rd('sin JavaScript redirige al enlace firmado tras guardar',
        $noJs['status'] === 302 && preg_match('/^Location: .*\/descargar\?token=/mi', $noJs['headers']) === 1,
        $noJs['headers']);
} finally {
    if (is_resource($proc)) proc_terminate($proc);
    if ($cookieJar !== '' && is_file($cookieJar)) unlink($cookieJar);
    if ($maxSubmission > 0) Database::execute('DELETE FROM form_submissions WHERE id > ?', [$maxSubmission]);
    elseif ($formId > 0) Database::execute('DELETE FROM form_submissions WHERE section_id = ?', [$formId]);
    if ($submissionId > 0) Database::execute('DELETE FROM form_submissions WHERE id = ?', [$submissionId]);
    if ($resourceId > 0) ResourceStore::delete($siteId, $resourceId);
    if ($formId > 0) Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
    if ($file !== '' && is_file($file)) unlink($file);
    if ($originalModule === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $settingKey]);
    } else {
        ModuleRegistry::setEnabled($siteId, 'resources', (string) $originalModule['setting_value'] === '1');
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
