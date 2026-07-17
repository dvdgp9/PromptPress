<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use Core\Database;

$failed = 0;
function checkReferenceSeed(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$seed = Database::selectOne(
    "SELECT p.id, p.site_id
     FROM pages p
     INNER JOIN page_canvas pc ON pc.page_id = p.id
     WHERE p.render_mode = 'canvas'
     ORDER BY CASE WHEN p.page_type = 'home' THEN 0 ELSE 1 END, p.id ASC
     LIMIT 1"
);
checkReferenceSeed('canvas_seed_fixture_exists', $seed !== null);

$hasValidator = method_exists(PageController::class, 'referenceSourceError');
checkReferenceSeed('reference_source_validator_exists', $hasValidator);
if ($seed !== null && $hasValidator) {
    $method = new ReflectionMethod(PageController::class, 'referenceSourceError');
    $siteId = (int) $seed['site_id'];
    $seedId = (int) $seed['id'];

    checkReferenceSeed(
        'seed_without_image_is_valid',
        $method->invoke(null, $siteId, $seedId, []) === null
    );
    checkReferenceSeed(
        'image_without_seed_is_valid',
        $method->invoke(null, $siteId, 0, [['mime' => 'image/png', 'data' => 'x']]) === null
    );
    $missing = $method->invoke(null, $siteId, 0, []);
    checkReferenceSeed(
        'missing_both_is_rejected',
        is_string($missing) && str_contains($missing, 'captura') && str_contains($missing, 'página base'),
        (string) $missing
    );
}

$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/page-studio.js');
checkReferenceSeed(
    'frontend_accepts_files_or_seed',
    str_contains($js, 'files.length > 0 || seedEl.value')
        && str_contains($js, "seedEl.addEventListener('change', updateSubmit)"),
    'La habilitación del botón debe reaccionar a archivos o página base.'
);

$view = (string) file_get_contents(PP_ROOT . '/views/admin/pages/studio.php');
checkReferenceSeed(
    'view_explains_optional_capture',
    str_contains($view, 'opcional si eliges una página base'),
    'La interfaz debe explicar por qué se puede continuar sin captura.'
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
