<?php

// PAGE-FROM-REF — smoke test SIN llamadas IA: comprueba que el prompt de
// COMPOSE_CANVAS_PAGE interpola los inputs nuevos (base_design/source_content),
// que los fallbacks aparecen con inputs vacíos, y que CanvasService::designSeed
// extrae ADN de una página canvas real.

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AI\Actions;
use App\Services\AI\PromptBuilder;
use App\Services\Canvas\CanvasService;
use Core\Database;

$siteId = 1;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $failed;
    echo ($ok ? 'OK  ' : 'FAIL') . '  ' . $name . ($detail !== '' ? '  — ' . $detail : '') . PHP_EOL;
    if (!$ok) $failed++;
}

function userPrompt(array $built): string {
    foreach ($built['messages'] as $m) {
        if (($m['role'] ?? '') === 'user') return (string) $m['content'];
    }
    return '';
}

// --- 1) Sin inputs nuevos (camino onboarding normal): fallbacks + sin fugas ---
$built = PromptBuilder::forAction(Actions::COMPOSE_CANVAS_PAGE, [
    'page_title' => 'Servicios',
    'page_goal'  => 'Presentar los servicios',
    'language'   => 'es',
], $siteId);
$u = userPrompt($built);
check('1a sin fugas {base_design}/{source_content}', !str_contains($u, '{base_design}') && !str_contains($u, '{source_content}'));
// El fallback (empty→texto guía) vive en CanvasGenerator::generate, único caller
// de COMPOSE_CANVAS_PAGE; aquí se verifica el contrato a nivel de fuente para que
// nadie lo elimine sin que falle el test.
$gen = (string) file_get_contents(PP_ROOT . '/app/Services/Canvas/CanvasGenerator.php');
check('1b fallback semilla en CanvasGenerator', str_contains($gen, 'sin semilla concreta') && str_contains($gen, "\$input['base_design']"));
check('1c fallback contenido en CanvasGenerator', str_contains($gen, 'no aportó contenido propio') && str_contains($gen, "\$input['source_content']"));

// --- 2) Con inputs nuevos: aparecen tal cual en el prompt ---
$seedMarker = 'SEED-CSS-MARKER-12345 .lx-hero{padding:6rem}';
$contentMarker = 'CONTENIDO-REAL: Precio 49€, contacto Ana López.';
$built2 = PromptBuilder::forAction(Actions::COMPOSE_CANVAS_PAGE, [
    'page_title'     => 'Servicios',
    'page_goal'      => 'Presentar los servicios',
    'language'       => 'es',
    'base_design'    => $seedMarker,
    'source_content' => $contentMarker,
], $siteId);
$u2 = userPrompt($built2);
check('2a semilla inyectada', str_contains($u2, $seedMarker));
check('2b contenido inyectado', str_contains($u2, $contentMarker));

// --- 3) Reglas duras presentes en el system prompt ---
$sys = '';
foreach ($built2['messages'] as $m) { if (($m['role'] ?? '') === 'system') { $sys = (string) $m['content']; break; } }
check('3a regla SEMILLA en system', str_contains($sys, 'SEMILLA DE COHERENCIA'));
check('3b regla CONTENIDO en system', str_contains($sys, 'CONTENIDO APORTADO'));
check('3c regla anti-invención', str_contains($sys, 'NUNCA inventes información'));

// --- 4) designSeed sobre una página canvas real del sitio ---
$row = Database::selectOne(
    'SELECT pc.page_id FROM page_canvas pc JOIN pages p ON p.id = pc.page_id WHERE p.site_id = ? ORDER BY pc.page_id DESC LIMIT 1',
    [$siteId]
);
if ($row === null) {
    check('4 designSeed (no hay página canvas en site 1)', true, 'omitido');
} else {
    $pid = (int) $row['page_id'];
    $seed = CanvasService::designSeed($pid);
    check('4a designSeed no vacío page=' . $pid, trim($seed) !== '', 'len=' . mb_strlen($seed));
    check('4b designSeed menciona secciones o CSS', str_contains($seed, 'secciones de la semilla') || str_contains($seed, 'CSS de la semilla'));
    check('4c designSeed acotado (<3500)', mb_strlen($seed) < 3500, 'len=' . mb_strlen($seed));
    echo '----- designSeed(' . $pid . ") preview -----\n" . mb_substr($seed, 0, 400) . "\n-------------------------------\n";
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
