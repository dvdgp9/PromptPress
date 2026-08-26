<?php

declare(strict_types=1);

/** FEAT-RESOURCES R2 — archivos protegidos y preparación de descarga directa. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Resources\ResourceFileService;
use App\Modules\Resources\ResourceStore;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_rf(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

/** @param callable():mixed $fn */
function throws_rf(string $name, callable $fn, string $contains): void
{
    try {
        $fn();
        check_rf($name, false, 'no lanzó excepción');
    } catch (InvalidArgumentException|RuntimeException $e) {
        check_rf($name, str_contains(mb_strtolower($e->getMessage()), mb_strtolower($contains)), $e->getMessage());
    }
}

/** EPUB mínimo válido: mimetype es el primer miembro, STORED y sin extra. */
function make_epub_r2(): string
{
    $name = 'mimetype';
    $data = 'application/epub+zip';
    $crc = crc32($data);
    $local = "PK\x03\x04"
        . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, strlen($data), strlen($data), strlen($name), 0)
        . $name . $data;
    $central = "PK\x01\x02"
        . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, strlen($data), strlen($data), strlen($name), 0, 0, 0, 0, 0, 0)
        . $name;
    $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($central), strlen($local), 0);
    return $local . $central . $eocd;
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rf('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

// Primera expectativa TDD: la clase aún no existe en el primer run.
$effective = ResourceFileService::effectiveMaxSize();
check_rf('límite efectivo positivo y <=20MiB', $effective > 0 && $effective <= ResourceStore::MAX_FILE_SIZE, (string) $effective);

$tmpDir = sys_get_temp_dir() . '/pp-res-r2-' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0700, true);
$pdf = $tmpDir . '/guia.pdf';
$epub = $tmpDir . '/manual.epub';
$fakePdf = $tmpDir . '/falso.pdf';
$fakeEpub = $tmpDir . '/falso.epub';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
file_put_contents($epub, make_epub_r2());
file_put_contents($fakePdf, 'esto no es un PDF');
file_put_contents($fakeEpub, "PK\x03\x04" . str_repeat("\x00", 80));

$file = static fn (string $path, string $name, int $error = UPLOAD_ERR_OK, ?int $size = null): array => [
    'name' => $name,
    'type' => 'application/octet-stream', // deliberadamente no fiable
    'tmp_name' => $path,
    'error' => $error,
    'size' => $size ?? (int) filesize($path),
];

$created = [];
$storedPaths = [];
ResourceFileService::$moveOverride = static fn (string $from, string $to): bool => copy($from, $to);

try {
    $vPdf = ResourceFileService::validateUpload($file($pdf, 'Guía práctica.pdf'));
    check_rf('PDF válido detectado por contenido', $vPdf['extension'] === 'pdf' && $vPdf['mime'] === 'application/pdf');
    check_rf('nombre original conserva Unicode', $vPdf['original_name'] === 'Guía práctica.pdf');

    $vEpub = ResourceFileService::validateUpload($file($epub, 'Manual.epub'));
    check_rf('EPUB OCF válido detectado', $vEpub['extension'] === 'epub' && $vEpub['mime'] === 'application/epub+zip', json_encode($vEpub));

    throws_rf('PDF falso rechazado', static fn () => ResourceFileService::validateUpload($file($fakePdf, 'falso.pdf')), 'contenido');
    throws_rf('EPUB sin mimetype OCF rechazado', static fn () => ResourceFileService::validateUpload($file($fakeEpub, 'falso.epub')), 'epub');
    throws_rf('extensión no permitida', static fn () => ResourceFileService::validateUpload($file($pdf, 'guia.zip')), 'formato');
    throws_rf('upload parcial da diagnóstico', static fn () => ResourceFileService::validateUpload($file($pdf, 'guia.pdf', UPLOAD_ERR_PARTIAL)), 'parcial');
    throws_rf('límite efectivo se aplica', static fn () => ResourceFileService::validateUpload($file($pdf, 'guia.pdf', UPLOAD_ERR_OK, $effective + 1)), 'supera');

    $id = ResourceStore::create($siteId, ['title' => 'Recurso archivos R2']);
    $created[] = $id;
    $saved = ResourceFileService::storeUpload($siteId, $id, $file($pdf, '../Guía práctica.pdf'));
    $storedPaths[] = $saved['absolute_path'];
    $r = ResourceStore::find($siteId, $id);
    check_rf('store guarda archivo fuera de URL pública', is_file($saved['absolute_path'])
        && str_starts_with((string) $r['file_path'], 'storage/resources/' . $siteId . '/'));
    check_rf('nombre físico aleatorio canónico', preg_match('/^[a-f0-9]{64}\.pdf$/', basename((string) $r['file_path'])) === 1);
    check_rf('metadatos persistidos', ($r['file_mime'] ?? '') === 'application/pdf'
        && (int) $r['file_size'] === filesize($pdf)
        && ($r['original_filename'] ?? '') === 'Guía práctica.pdf');
    check_rf('directorio raíz protegido para Apache',
        is_file(PP_ROOT . '/storage/resources/.htaccess')
        && str_contains((string) file_get_contents(PP_ROOT . '/storage/resources/.htaccess'), 'Require all denied'));

    $oldAbs = $saved['absolute_path'];
    $saved2 = ResourceFileService::storeUpload($siteId, $id, $file($epub, 'Manual avanzado.epub'));
    $storedPaths[] = $saved2['absolute_path'];
    $r = ResourceStore::find($siteId, $id);
    check_rf('reemplazo actualiza a EPUB', is_file($saved2['absolute_path']) && ($r['file_mime'] ?? '') === 'application/epub+zip');
    check_rf('reemplazo retira archivo anterior', !is_file($oldAbs));

    ResourceStore::update($siteId, $id, ['status' => 'published']);
    $prepared = ResourceFileService::prepareDirectDownload($siteId, LanguageService::primaryFor($siteId), 'recurso-archivos-r2');
    check_rf('descarga directa publicada se prepara', is_array($prepared) && $prepared['absolute_path'] === $saved2['absolute_path']);
    $headers = ResourceFileService::headersFor($prepared ?? []);
    check_rf('cabeceras incluyen MIME, bytes y no-sniff',
        ($headers['Content-Type'] ?? '') === 'application/epub+zip'
        && (int) ($headers['Content-Length'] ?? 0) === filesize($epub)
        && ($headers['X-Content-Type-Options'] ?? '') === 'nosniff'
        && ($headers['Accept-Ranges'] ?? '') === 'none');
    check_rf('Content-Disposition tiene fallback y UTF-8',
        str_contains((string) ($headers['Content-Disposition'] ?? ''), 'filename="Manual avanzado.epub"')
        && str_contains((string) ($headers['Content-Disposition'] ?? ''), "filename*=UTF-8''Manual%20avanzado.epub"));

    ResourceStore::update($siteId, $id, ['status' => 'draft']);
    check_rf('borrador no prepara descarga', ResourceFileService::prepareDirectDownload($siteId, $primary = LanguageService::primaryFor($siteId), 'recurso-archivos-r2') === null);
    ResourceStore::update($siteId, $id, ['status' => 'published', 'access_mode' => 'form']);
    // No se puede publicar form sin form_id: la guarda del store debe actuar.
    check_rf('modo form no se alcanzó sin formulario', false, 'ResourceStore permitió publicar form sin formulario');
} catch (InvalidArgumentException $expected) {
    check_rf('modo form sin formulario bloqueado antes de descargar', str_contains($expected->getMessage(), 'formulario'), $expected->getMessage());

    // Continúa con el recurso directo publicado para las guardas del path.
    $id = $created[0] ?? 0;
    if ($id > 0) {
        ResourceStore::update($siteId, $id, ['status' => 'published', 'access_mode' => 'direct']);
        $r = ResourceStore::find($siteId, $id);
        $goodPath = (string) ($r['file_path'] ?? '');
        Database::execute("UPDATE resources SET file_path = ? WHERE id = ?", ['storage/resources/' . $siteId . '/../config/config.php', $id]);
        check_rf('path traversal almacenado no se sirve', ResourceFileService::prepareDirectDownload($siteId, LanguageService::primaryFor($siteId), 'recurso-archivos-r2') === null);
        Database::execute('UPDATE resources SET file_path = ? WHERE id = ?', [$goodPath, $id]);

        $abs = PP_ROOT . '/' . $goodPath;
        $backup = $abs . '.r2missing';
        rename($abs, $backup);
        check_rf('archivo físico ausente no se sirve', ResourceFileService::prepareDirectDownload($siteId, LanguageService::primaryFor($siteId), 'recurso-archivos-r2') === null);
        rename($backup, $abs);

        check_rf('deleteFileAndResource borra fila y binario', ResourceFileService::deleteFileAndResource($siteId, $id)
            && ResourceStore::find($siteId, $id) === null && !is_file($abs));
        $created = array_values(array_diff($created, [$id]));
    }

    // Fallo del mover: no persiste metadatos ni deja destino parcial.
    $idFail = ResourceStore::create($siteId, ['title' => 'Fallo mover R2']);
    $created[] = $idFail;
    $before = glob(PP_ROOT . '/storage/resources/' . $siteId . '/*') ?: [];
    ResourceFileService::$moveOverride = static fn (string $from, string $to): bool => false;
    throws_rf('fallo al mover se diagnostica', static fn () => ResourceFileService::storeUpload($siteId, $idFail, $file($pdf, 'fallo.pdf')), 'guardar');
    $after = glob(PP_ROOT . '/storage/resources/' . $siteId . '/*') ?: [];
    check_rf('fallo al mover no deja archivo nuevo', $before === $after && ResourceStore::find($siteId, $idFail)['file_path'] === null);

    $buildScript = (string) file_get_contents(PP_ROOT . '/scripts/build_package.php');
    $updater = (string) file_get_contents(PP_ROOT . '/app/Services/UpdateInstallerService.php');
    $reset = (string) file_get_contents(PP_ROOT . '/app/Services/SiteResetService.php');
    $routes = (string) file_get_contents(PP_ROOT . '/app/Modules/Resources/routes.php');
    check_rf('paquetes y updates preservan ebooks de la instalación',
        str_contains($buildScript, "'/storage/resources'") && str_contains($updater, "'/storage/resources'"));
    check_rf('reset del sitio incluye filas y directorio de recursos',
        str_contains($reset, "DELETE FROM resources WHERE site_id = ?")
        && str_contains($reset, "storage/resources/' . \$siteId"));
    check_rf('rutas directa y multiidioma quedan preparadas para R3',
        str_contains($routes, "'/recursos/{slug}/descargar'")
        && str_contains($routes, "'/{lang}/recursos/{slug}/descargar'"));
} finally {
    ResourceFileService::$moveOverride = null;
    foreach ($created as $resourceId) {
        $row = ResourceStore::find($siteId, $resourceId);
        if ($row !== null && !empty($row['file_path'])) $storedPaths[] = PP_ROOT . '/' . ltrim((string) $row['file_path'], '/');
        Database::execute('DELETE FROM resources WHERE id = ? AND site_id = ?', [$resourceId, $siteId]);
    }
    foreach (array_unique($storedPaths) as $path) {
        if (is_file($path)) unlink($path);
        if (is_file($path . '.r2missing')) unlink($path . '.r2missing');
    }
    foreach ([$pdf, $epub, $fakePdf, $fakeEpub] as $path) if (is_file($path)) unlink($path);
    if (is_dir($tmpDir)) rmdir($tmpDir);
}

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
