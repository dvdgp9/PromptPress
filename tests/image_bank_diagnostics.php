<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ImageBankService;

$failed = 0;
function check_image_diagnostic(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$cases = [
    'network' => [0, null, 'timeout', [], 'network_error'],
    'auth' => [401, '{"errors":["Unauthorized"]}', null, [], 'authentication_error'],
    'rate' => [429, '{}', null, ['x-ratelimit-remaining' => '0'], 'rate_limited'],
    'provider' => [503, '{}', null, [], 'provider_error'],
    'invalid' => [200, '<html>error</html>', null, [], 'invalid_response'],
    'empty' => [200, '{"results":[]}', null, [], 'no_results'],
    'success' => [200, '{"results":[{"id":"photo"}]}', null, ['x-ratelimit-remaining' => '42'], 'ok'],
];

foreach ($cases as $name => [$status, $body, $error, $headers, $expected]) {
    $result = ImageBankService::classifySearchResponse($status, $body, $error, $headers);
    check_image_diagnostic($name . '_status', $result['status'] === $expected);
}

$rate = ImageBankService::classifySearchResponse(429, '{}', null, ['x-ratelimit-remaining' => '0']);
check_image_diagnostic('rate_metadata', $rate['http_status'] === 429 && $rate['rate_limit_remaining'] === 0 && $rate['items'] === []);

ImageBankService::resetDiagnostics();
check_image_diagnostic('diagnostics_reset', ImageBankService::lastSearchFailure() === null);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
