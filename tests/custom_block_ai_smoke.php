<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\Renderer\CustomBlockGenerator;
use App\Services\Renderer\SectionRenderer;
use Core\App;
use Core\Database;

App::boot();

$site = Database::selectOne('SELECT id, name FROM sites ORDER BY id ASC LIMIT 1');
if (!$site) {
    fwrite(STDERR, "No hay sitios en la base de datos.\n");
    exit(1);
}
$siteId = (int) $site['id'];

$image = createReferenceImage();
$started = microtime(true);

try {
    $result = CustomBlockGenerator::generate($siteId, [
        'page_title' => 'Página de prueba desde referencia visual',
        'block_goal' => 'Crear un hero editorial con mensaje claro y una llamada a la acción.',
        'section_role' => 'Primer bloque de una landing para un estudio de estrategia digital.',
        'language' => 'es',
        'available_images' => '',
        'extra_context' => "Negocio ficticio para QA: estudio de estrategia digital para pymes.\nNo inventes teléfonos, direcciones ni precios.",
        'is_first_section' => true,
        '_images' => [$image],
    ], 2);
} catch (\Throwable $e) {
    $elapsed = (int) round((microtime(true) - $started) * 1000);
    echo "SITE {$siteId} " . (string) ($site['name'] ?? '') . PHP_EOL;
    echo "OK false" . PHP_EOL;
    echo "ELAPSED_MS {$elapsed}" . PHP_EOL;
    echo "ERROR " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$elapsed = (int) round((microtime(true) - $started) * 1000);
$content = (array) ($result['content'] ?? []);
$rendered = SectionRenderer::render([
    'id' => 9501,
    'section_type' => 'custom_block',
    'content_json' => $content,
    'style_json' => null,
]);

echo "SITE {$siteId} " . (string) ($site['name'] ?? '') . PHP_EOL;
echo "OK " . (($result['ok'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo "MODEL " . (string) ($result['model'] ?? '') . PHP_EOL;
echo "TOKENS_IN " . (int) ($result['tokens_in'] ?? 0) . PHP_EOL;
echo "TOKENS_OUT " . (int) ($result['tokens_out'] ?? 0) . PHP_EOL;
echo "COST " . (string) ($result['estimated_cost'] ?? '') . PHP_EOL;
echo "ELAPSED_MS {$elapsed}" . PHP_EOL;
echo "ATTEMPTS " . count((array) ($result['attempts'] ?? [])) . PHP_EOL;
echo "SANITIZED " . (($content['validation']['sanitized'] ?? false) ? 'true' : 'false') . PHP_EOL;
echo "FIELDS " . implode(',', array_keys((array) ($content['fields'] ?? []))) . PHP_EOL;
echo "WARNINGS " . count((array) ($content['validation']['warnings'] ?? [])) . PHP_EOL;
echo "ERRORS " . count((array) ($content['validation']['errors'] ?? [])) . PHP_EOL;
echo "HTML_PREVIEW " . mb_substr(preg_replace('/\s+/', ' ', (string) ($content['html'] ?? '')), 0, 500) . PHP_EOL;
echo "RENDER_OK " . (str_contains($rendered, 'pp-section--custom_block') ? 'true' : 'false') . PHP_EOL;

/**
 * @return array{mime:string,data:string}
 */
function createReferenceImage(): array
{
    if (!function_exists('imagecreatetruecolor')) {
        $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
        return ['mime' => 'image/png', 'data' => base64_encode((string) $png1x1)];
    }

    $w = 1200;
    $h = 900;
    $img = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($img, 246, 244, 239);
    $ink = imagecolorallocate($img, 26, 32, 44);
    $muted = imagecolorallocate($img, 95, 105, 120);
    $accent = imagecolorallocate($img, 24, 118, 210);
    $card = imagecolorallocate($img, 255, 255, 255);

    imagefilledrectangle($img, 0, 0, $w, $h, $bg);
    imagefilledrectangle($img, 80, 70, 1120, 120, $card);
    imagefilledrectangle($img, 80, 180, 650, 590, $bg);
    imagefilledrectangle($img, 720, 180, 1080, 590, $card);
    imagefilledrectangle($img, 80, 680, 1080, 820, $card);
    imagefilledrectangle($img, 82, 682, 330, 818, $card);
    imagefilledrectangle($img, 352, 682, 622, 818, $card);
    imagefilledrectangle($img, 644, 682, 916, 818, $card);

    imagestring($img, 5, 105, 92, 'REFERENCE NAV', $muted);
    imagestring($img, 5, 100, 210, 'Editorial hero with strong headline', $ink);
    imagestring($img, 5, 100, 250, 'Wide whitespace, left copy, right visual panel', $muted);
    imagefilledrectangle($img, 100, 330, 270, 380, $accent);
    imagestring($img, 5, 125, 348, 'PRIMARY CTA', $card);
    imagestring($img, 5, 760, 375, 'VISUAL', $accent);
    imagestring($img, 5, 115, 735, 'Benefit card', $ink);
    imagestring($img, 5, 382, 735, 'Proof card', $ink);
    imagestring($img, 5, 680, 735, 'Action card', $ink);

    ob_start();
    imagepng($img);
    $raw = (string) ob_get_clean();
    imagedestroy($img);

    return ['mime' => 'image/png', 'data' => base64_encode($raw)];
}
