<?php

declare(strict_types=1);

/**
 * ONB-FOTOS F5 — Fotos del negocio en el paso 4 del onboarding.
 *
 * Lo que se puede romper en silencio aquí no es la subida (eso falla a gritos),
 * sino el CAMINO: que la foto acabe donde la generación la busca
 * (`media.source = 'upload'`), que el logo no se cuele como foto de contenido,
 * que el tope se cuente sobre las propias y no sobre toda la biblioteca, y que
 * el borrador de la home se invalide al cambiar el material — si no, el usuario
 * sube fotos y sigue viendo la home que se generó sin ellas.
 *
 * No llama a la IA: la descripción con visión se prueba en la verificación E2E.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\OnboardingController;
use App\Services\MediaLibraryService;
use App\Services\MediaService;
use Core\Database;

$failed = 0;
function check_photos(string $name, bool $ok, string $extra = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($extra !== '' ? ' — ' . $extra : '') . PHP_EOL;
    if (!$ok) $failed++;
}

$siteId = 1;
$ids = [];
$files = [];
$draftBackup = Database::selectOne(
    "SELECT setting_value v FROM settings WHERE site_id = ? AND setting_key = 'onboarding_home_draft'",
    [$siteId]
);

/** Escribe un JPG real en storage/uploads/{site} y lo registra como el paso. */
$makePhoto = static function (int $siteId, string $alt, string $dir = '') use (&$ids, &$files): array {
    $base = MediaService::ensureSiteDir($siteId) . ($dir !== '' ? '/' . $dir : '');
    if (!is_dir($base)) mkdir($base, 0775, true);
    $name = 'pp-test-foto-' . bin2hex(random_bytes(6)) . '.jpg';
    $abs = $base . '/' . $name;
    $im = imagecreatetruecolor(120, 80);
    imagefilledrectangle($im, 0, 0, 120, 80, imagecolorallocate($im, 180, 120, 60));
    imagejpeg($im, $abs, 70);
    imagedestroy($im);
    $files[] = $abs;

    $rel = 'storage/uploads/' . $siteId . ($dir !== '' ? '/' . $dir : '') . '/' . $name;
    $row = MediaService::storeFromBinary($abs, $rel, 'image/jpeg', $siteId, null, [
        'original_name' => 'foto-negocio.jpg',
        'alt_text' => $alt,
    ]);
    $ids[] = (int) $row['id'];
    return $row;
};

try {
    // ------------------------------------------------------------------
    // 1. La foto acaba donde la generación la busca
    // ------------------------------------------------------------------
    $ownBefore = count(MediaLibraryService::images($siteId, 50, true));
    $foto = $makePhoto($siteId, 'Profesor impartiendo clase a un grupo de alumnos en un aula');

    check_photos('la foto del paso se guarda como propia (source=upload)',
        (string) ($foto['source'] ?? '') === 'upload', (string) ($foto['source'] ?? 'sin source'));

    $own = MediaLibraryService::images($siteId, 50, true);
    $ownIds = array_map(static fn(array $r): int => (int) $r['id'], $own);
    check_photos('la ve el pool de fotos propias de la generación',
        in_array((int) $foto['id'], $ownIds, true));
    check_photos('cuenta para el tope del paso', count($own) === $ownBefore + 1);

    // El pool que se le enseña al modelo tiene que llegar con la descripción:
    // es lo que hace que la elija por lo que MUESTRA y no por el nombre.
    $prompt = MediaLibraryService::forAi($siteId, 30);
    check_photos('llega al prompt como foto propia prioritaria',
        str_contains($prompt, 'FOTOS PROPIAS DEL NEGOCIO')
        && str_contains($prompt, 'Profesor impartiendo clase'));

    // ------------------------------------------------------------------
    // 2. El logo no es una foto de contenido
    // ------------------------------------------------------------------
    $logo = $makePhoto($siteId, 'Logo de la marca', 'brand');
    $ownConLogo = array_map(
        static fn(array $r): int => (int) $r['id'],
        MediaLibraryService::images($siteId, 50, true)
    );
    check_photos('el logo no entra en las fotos del negocio',
        !in_array((int) $logo['id'], $ownConLogo, true));

    // ------------------------------------------------------------------
    // 3. Contrato con el navegador: lo que pinta la rejilla del paso
    // ------------------------------------------------------------------
    $payload = (new ReflectionMethod(OnboardingController::class, 'photoPayload'))->invoke(null, $foto);
    check_photos('photoPayload da las claves que lee el JS',
        array_keys($payload) === ['id', 'url', 'name', 'alt_text'], implode(',', array_keys($payload)));
    check_photos('photoPayload devuelve URL absoluta servible',
        str_contains((string) $payload['url'], (string) $foto['path']));

    $grid = (new ReflectionMethod(OnboardingController::class, 'businessPhotos'))->invoke(null, $siteId);
    check_photos('businessPhotos nunca pasa del tope del paso',
        count($grid) <= OnboardingController::BUSINESS_PHOTOS_MAX,
        count($grid) . ' de ' . OnboardingController::BUSINESS_PHOTOS_MAX);
    check_photos('businessPhotos excluye el logo',
        !in_array((int) $logo['id'], array_column($grid, 'id'), true));

    // ------------------------------------------------------------------
    // 4. Cambiar el material invalida la preview de la home
    // ------------------------------------------------------------------
    // page_id 0: no hay página que borrar, solo se comprueba que el ajuste se
    // vacía (con una página real esto además la elimina).
    Database::execute(
        "INSERT INTO settings (site_id, setting_key, setting_value) VALUES (?, 'onboarding_home_draft', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$siteId, json_encode(['page_id' => 0, 'title' => 'Inicio'])]
    );
    (new ReflectionMethod(OnboardingController::class, 'invalidateHomeDraft'))->invoke(null, $siteId);
    $draftAfter = Database::selectOne(
        "SELECT setting_value v FROM settings WHERE site_id = ? AND setting_key = 'onboarding_home_draft'",
        [$siteId]
    );
    check_photos('subir o quitar fotos invalida el borrador de la home',
        trim((string) ($draftAfter['v'] ?? '')) === '', (string) ($draftAfter['v'] ?? ''));

    // ------------------------------------------------------------------
    // 5. La descripción pendiente es la que mueve la barra del paso
    // ------------------------------------------------------------------
    Database::execute('UPDATE media SET alt_text = NULL WHERE id = ?', [(int) $foto['id']]);
    $pendientes = MediaLibraryService::idsMissingAlt($siteId, 10);
    check_photos('una foto sin describir sale en la cola de descripción',
        in_array((int) $foto['id'], $pendientes, true));
    check_photos('countMissingAlt la cuenta', MediaLibraryService::countMissingAlt($siteId) > 0);

    // Sin descripción, al modelo le llega el nombre del archivo: por eso el
    // paso espera a que termine el análisis antes de dejar avanzar.
    check_photos('sin descripción el prompt cae al nombre del archivo',
        str_contains(MediaLibraryService::forAi($siteId, 30), '(sin descripción)'));

    // ------------------------------------------------------------------
    // 6. Camino sin JavaScript: sin archivos no toca nada
    // ------------------------------------------------------------------
    $antes = count(MediaLibraryService::images($siteId, 50, true));
    $_FILES = [];
    (new ReflectionMethod(OnboardingController::class, 'saveBusinessPhotos'))->invoke(null, $siteId);
    check_photos('el guardado del paso sin archivos no crea nada',
        count(MediaLibraryService::images($siteId, 50, true)) === $antes);

    // ------------------------------------------------------------------
    // 7. Contrato de rutas: el JS llama a estas tres, y a describe-missing
    // ------------------------------------------------------------------
    $routes = (string) file_get_contents(PP_ROOT . '/app/routes.php');
    foreach (['upload-photo' => 'uploadPhoto', 'photo-alt' => 'updatePhotoAlt', 'photo-delete' => 'deletePhoto'] as $path => $method) {
        check_photos('ruta /onboarding/' . $path . ' → ' . $method,
            str_contains($routes, "/onboarding/{$path}") && str_contains($routes, "'{$method}'")
            && method_exists(OnboardingController::class, $method));
    }
    check_photos('la descripción por lotes sigue teniendo su ruta',
        str_contains($routes, '/media/describe-missing'));

    // ------------------------------------------------------------------
    // 8. Contrato con la vista y el JS del paso
    // ------------------------------------------------------------------
    $view = (string) file_get_contents(PP_ROOT . '/views/admin/onboarding/index.php');
    $js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/onboarding.js');
    foreach (['data-photos', 'data-photos-input', 'data-photos-grid', 'data-photos-status', 'data-photo-alt', 'data-photo-remove'] as $hook) {
        check_photos('la vista y el JS comparten el gancho ' . $hook,
            str_contains($view, $hook) && str_contains($js, $hook));
    }
    foreach (['uploadUrl' => 'data-upload-url', 'altUrl' => 'data-alt-url', 'deleteUrl' => 'data-delete-url', 'describeUrl' => 'data-describe-url'] as $dataset => $attr) {
        check_photos('la vista pasa ' . $attr . ' y el JS lo lee',
            str_contains($view, $attr) && str_contains($js, 'dataset.' . $dataset));
    }
} finally {
    foreach ($ids as $id) Database::execute('DELETE FROM media WHERE id = ? AND site_id = ?', [$id, $siteId]);
    foreach ($files as $abs) if (is_file($abs)) @unlink($abs);
    Database::execute(
        "INSERT INTO settings (site_id, setting_key, setting_value) VALUES (?, 'onboarding_home_draft', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        [$siteId, (string) ($draftBackup['v'] ?? '')]
    );
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
