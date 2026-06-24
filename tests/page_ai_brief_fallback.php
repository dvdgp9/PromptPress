<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use App\Services\AI\AIException;

$failed = 0;
function check_brief(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$ref = new ReflectionClass(PageController::class);
$fallback = $ref->getMethod('fallbackBrief');
$fallback->setAccessible(true);
$brief = $fallback->invoke(null, 'Página de servicios de formación con solicitud de información', 'Preparación de oposiciones y prueba CCSE.');

check_brief('fallback title present', ($brief['title'] ?? '') !== '');
check_brief('fallback detects service page', ($brief['page_type'] ?? '') === 'service', (string) ($brief['page_type'] ?? ''));
check_brief('fallback recommends form', ($brief['recommended_form']['needed'] ?? false) === true);
check_brief('fallback has form section', in_array('form', array_column((array) ($brief['sections'] ?? []), 'type'), true));
check_brief('fallback has usable sections', count((array) ($brief['sections'] ?? [])) >= 4);

$isJson = $ref->getMethod('isJsonParseAiError');
$isJson->setAccessible(true);
$publicError = $ref->getMethod('publicAiBriefError');
$publicError->setAccessible(true);
$exception = new AIException('No se pudo parsear JSON de la respuesta del modelo. Respuesta: { "items": [');

check_brief('detects json parse ai error', $isJson->invoke(null, $exception) === true);
$message = (string) $publicError->invoke(null, $exception);
check_brief('public error hides raw model JSON', !str_contains($message, '"items"') && str_contains($message, 'formato incompleto'), $message);

echo PHP_EOL . ($failed === 0 ? 'OK' : "$failed FALLOS") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
