<?php

declare(strict_types=1);

// STUDIO-UX F6 — Copiar una sección de otra página, literal y sin IA.
//
// El chat ya sabía hacer "esta sección como la de Inicio", pero reescribiendo
// con el modelo: 6,7 s de media y con coste. Copiar es leer e insertar.
//
// El riesgo de verdad es el CSS. Las clases NO son únicas entre páginas: en dev,
// `inicio` y `sobre-nosotros` definen las dos `.fgl-hero` con reglas distintas
// (una es un hero a pantalla completa, la otra una banda plana). Pegar el CSS de
// origen tal cual repintaría la página de destino. Por eso la copia va con las
// clases renombradas y solo con las reglas que le tocan.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasSanitizer;
use App\Services\Canvas\CanvasService;

$failed = 0;
function copyCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

// ---------------------------------------------------------------- CSS
$sourceCss = '.fgl-hero{height:80vh;color:#fff}'
    . '.fgl-hero__title{font-size:3rem}'
    . '.fgl-quote{padding:10rem 0}'
    . '@media (max-width:640px){.fgl-hero{height:60vh}.fgl-quote{padding:4rem 0}}'
    . '.fgl-hero__title:hover{opacity:.8}'
    . '.fgl-hero .fgl-badge{border:1px solid}';

$extracted = CanvasSanitizer::extractRulesForClasses($sourceCss, ['fgl-hero', 'fgl-hero__title', 'fgl-badge'], '-k1');

copyCheck('renombra las clases pedidas',
    str_contains($extracted, '.fgl-hero-k1{') && str_contains($extracted, '.fgl-hero__title-k1{'),
    $extracted);
copyCheck('no se lleva reglas ajenas a la sección',
    !str_contains($extracted, '.fgl-quote{'), $extracted);
copyCheck('conserva las media queries que sí afectan',
    str_contains($extracted, '@media (max-width:640px)') && str_contains($extracted, '.fgl-hero-k1{height:60vh}'),
    $extracted);
copyCheck('no arrastra dentro del @media lo que no toca',
    !str_contains($extracted, '.fgl-quote{padding:4rem 0}'), $extracted);
copyCheck('mantiene pseudoclases y descendientes',
    str_contains($extracted, '.fgl-hero__title-k1:hover') && str_contains($extracted, '.fgl-hero-k1 .fgl-badge-k1'),
    $extracted);

// Los keyframes referenciados viajan con la regla que los usa.
$animated = '.fx-card{animation:fadeUp .4s ease}@keyframes fadeUp{from{opacity:0}to{opacity:1}}@keyframes otra{from{opacity:0}}';
$animOut = CanvasSanitizer::extractRulesForClasses($animated, ['fx-card'], '-k2');
copyCheck('se lleva los @keyframes que usa', str_contains($animOut, '@keyframes fadeUp'), $animOut);
copyCheck('no se lleva los @keyframes que no usa', !str_contains($animOut, '@keyframes otra'), $animOut);

// Las clases del sistema (pp-*) son globales: renombrarlas rompería el runtime.
$ppCss = '.pp-btn{padding:1rem}.fgl-hero{height:80vh}';
$ppOut = CanvasSanitizer::extractRulesForClasses($ppCss, ['fgl-hero'], '-k3');
copyCheck('las clases pp- no se tocan', !str_contains($ppOut, '.pp-btn-k3'), $ppOut);

// ---------------------------------------------------------------- Copia
$sourceHtml = '<section data-pp-section="hero" class="fgl-hero">'
    . '<h1 class="fgl-hero__title">Titular</h1>'
    . '<span class="fgl-badge pp-chip">Nuevo</span>'
    . '{{form:391}}'
    . '</section>'
    . '<section data-pp-section="cta"><p>Otra</p></section>';

$targetHtml = '<section data-pp-section="hero" class="fgl-hero"><h1>El hero de destino</h1></section>'
    . '<section data-pp-section="faqs"><h2>Preguntas</h2></section>';
// El destino tiene SU PROPIA .fgl-hero, distinta. Es el caso peligroso.
$targetCss = '.fgl-hero{background:var(--pp-primary-dark);padding:4rem 0}';

$out = CanvasService::copySectionInto(
    $targetHtml, $targetCss, $sourceHtml, $sourceCss, 'hero', 'faqs', 'after', '-k1'
);

copyCheck('la copia devuelve html, css e id', is_array($out) && isset($out['html'], $out['css'], $out['id']), var_export($out, true));
copyCheck('entra en el punto pedido con id propio',
    array_column(CanvasService::listSections((string) ($out['html'] ?? '')), 'id') === ['hero', 'faqs', 'hero-2'],
    implode(',', array_column(CanvasService::listSections((string) ($out['html'] ?? '')), 'id')));
copyCheck('la copia lleva las clases renombradas',
    str_contains((string) ($out['html'] ?? ''), 'class="fgl-hero-k1"')
        && str_contains((string) ($out['html'] ?? ''), 'class="fgl-hero__title-k1"'),
    (string) ($out['html'] ?? ''));
copyCheck('las clases del sistema siguen intactas en el HTML',
    str_contains((string) ($out['html'] ?? ''), 'pp-chip')
        && !str_contains((string) ($out['html'] ?? ''), 'pp-chip-k1'),
    (string) ($out['html'] ?? ''));
copyCheck('el placeholder viaja sin expandir',
    str_contains((string) ($out['html'] ?? ''), '{{form:391}}'), (string) ($out['html'] ?? ''));

// EL PUNTO CLAVE: la página de destino no cambia de aspecto.
copyCheck('el hero de destino conserva SU regla',
    str_contains((string) ($out['css'] ?? ''), '.fgl-hero{background:var(--pp-primary-dark);padding:4rem 0}'),
    (string) ($out['css'] ?? ''));
copyCheck('y la copia trae la suya, aparte',
    str_contains((string) ($out['css'] ?? ''), '.fgl-hero-k1{height:80vh;color:#fff}'),
    (string) ($out['css'] ?? ''));
copyCheck('el hero original del destino sigue con su clase sin sufijo',
    str_contains((string) ($out['html'] ?? ''), '<section data-pp-section="hero" class="fgl-hero">'),
    (string) ($out['html'] ?? ''));

// Rechazos.
copyCheck('rechaza una sección que no existe en el origen',
    CanvasService::copySectionInto($targetHtml, $targetCss, $sourceHtml, $sourceCss, 'fantasma', 'faqs', 'after', '-k1') === null);
copyCheck('rechaza un ancla que no existe en el destino',
    CanvasService::copySectionInto($targetHtml, $targetCss, $sourceHtml, $sourceCss, 'hero', 'fantasma', 'after', '-k1') === null);

// ---------------------------------------------------------------- Contratos
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
$view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');

$routes = (string) file_get_contents(PP_ROOT . '/app/routes.php');

copyCheck('hay ruta y controlador para listar las partes de otras páginas',
    str_contains($routes, "/canvas/{id}/copy-sources") && str_contains($controller, 'function copySources'));
copyCheck('hay ruta y controlador para copiar',
    str_contains($routes, "/canvas/{id}/copy-section") && str_contains($controller, 'function copySection'));
copyCheck('la copia no pasa por la IA',
    !preg_match('/function copySection.*?AIActionRunner/s', $controller));
copyCheck('el origen se valida contra el sitio de la sesión',
    str_contains($controller, 'self::findCanvasPage($sourcePageId, $siteId)'));
copyCheck('el Studio ofrece traer una parte de otra página',
    str_contains($js, 'copySourcesUrl') && str_contains($js, 'copySectionUrl')
        && str_contains($view, 'studio-copy-section') && str_contains($view, 'data-copy-sources-url'));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['cv.copy_from_page', 'cv.copy_from_page_desc', 'js.cv.copy_pick_page', 'js.cv.section_copied'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    copyCheck('microcopia de copiar completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
