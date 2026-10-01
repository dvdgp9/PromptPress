<?php

declare(strict_types=1);

/**
 * REF-HTML — Una maqueta HTML como referencia del onboarding.
 *
 * Los clientes traen su web «hecha con una IA» en un único .html y piden «lo
 * más parecido a esto». Leer el CÓDIGO es más fiel que una captura: el orden de
 * secciones, el número de elementos y las columnas vienen escritos.
 *
 * Lo que se vigila aquí:
 *   - la limpieza: a la IA no le llega ni un script, ni imágenes base64 (son el
 *     97 % del peso de una maqueta «autocontenida»), ni comentarios;
 *   - el almacenamiento: el .html NUNCA acaba en `storage/uploads` (público:
 *     sería HTML del cliente servido desde el dominio del panel). Se guarda solo
 *     la versión limpia, como .txt, en `storage/documents`;
 *   - el cableado: la home sigue la estructura del código y el control de
 *     deriva (pensado para lo que la visión se inventa) no le borra secciones
 *     que la maqueta SÍ tiene, como un proceso por pasos.
 *
 * La maqueta real del cliente NO es fixture: aquí se genera una con su forma.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AI\Actions;
use App\Services\Canvas\CanvasGenerator;
use App\Services\Canvas\ReferenceHtml;

$failed = 0;
function check_ref(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$b64 = 'data:image/jpeg;base64,' . str_repeat('QUJDREVGR0g', 20000);
$maqueta = <<<HTML
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><title>Clínica Ejemplo</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display">
<style>
:root{--gold:#c8a96a;--navy:#14213d}
.surgery-wrap{display:grid;grid-template-columns:1.1fr .9fr;gap:48px}
.hero{background:url({$b64}) center/cover}
</style>
<!-- comentario interno con notas del diseñador -->
</head>
<body>
<header><nav class="menu"><a href="#cirugia">Cirugía</a><a href="#contacto">Contacto</a></nav></header>
<section class="hero"><img src="{$b64}" alt="Paciente sonriendo en consulta">
<h1>Cirugía estética personalizada</h1>
<a class="btn gold" href="#contacto" onclick="track('hero')">Solicitar valoración</a></section>
<section id="cirugia" class="surgery"><div class="surgery-wrap">
<div class="surgery-copy"><h2>Armonía y naturalidad</h2>
<svg viewBox="0 0 24 24"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg></div>
<div id="surgeryCarousel" class="carousel"><div class="slide"><h3>Cirugía mamaria</h3></div></div>
</div></section>
<section class="journey"><h2>Un proceso cuidado</h2><div class="journey-step">01 · Valoración</div><div class="journey-step">02 · Plan</div></section>
<iframe src="https://maps.example.com/embed"></iframe>
<footer>Clínica Ejemplo · Granada</footer>
<script>document.querySelectorAll('.slide').forEach(function(s){s.hidden=true});</script>
</body></html>
HTML;

// ---------------------------------------------------------------------------
// 1. Limpieza
// ---------------------------------------------------------------------------

$clean = ReferenceHtml::clean($maqueta);
check_ref('sin scripts', !str_contains(strtolower($clean), '<script'), $clean);
check_ref('sin imágenes base64', !str_contains($clean, 'base64,'), mb_substr($clean, 0, 300));
check_ref('sin comentarios', !str_contains($clean, '<!--') && !str_contains($clean, 'notas del diseñador'));
check_ref('sin atributos on*', !str_contains($clean, 'onclick'));
check_ref('sin iframes', !str_contains(strtolower($clean), '<iframe'));
check_ref('sin el trazo de los SVG', !str_contains($clean, '15.09'));
check_ref('conserva secciones y encabezados', str_contains($clean, '<section') && str_contains($clean, 'Cirugía estética personalizada')
    && str_contains($clean, 'Armonía y naturalidad'));
check_ref('conserva clases e ids (dicen la composición)', str_contains($clean, 'surgery-wrap') && str_contains($clean, 'surgeryCarousel'));
check_ref('conserva el alt de las imágenes', str_contains($clean, 'Paciente sonriendo en consulta'));
check_ref('conserva el CSS de composición', str_contains($clean, 'grid-template-columns'));
check_ref('conserva título, menú y pie', str_contains($clean, 'Clínica Ejemplo') && str_contains($clean, '<nav') && str_contains($clean, '<footer'));
check_ref('pesa una fracción del original', mb_strlen($clean) < mb_strlen($maqueta) / 20, mb_strlen($clean) . ' de ' . mb_strlen($maqueta));

$huge = '<html><body>' . str_repeat('<section><h2>Bloque</h2><p>' . str_repeat('texto ', 200) . '</p></section>', 200) . '</body></html>';
check_ref('respeta el tope de tamaño', mb_strlen(ReferenceHtml::clean($huge)) <= ReferenceHtml::MAX_CHARS);

$latin1 = mb_convert_encoding('<html><body><h1>Clínica estética en Almería</h1></body></html>', 'Windows-1252', 'UTF-8');
$fromLatin1 = ReferenceHtml::clean($latin1);
check_ref('un archivo en Windows-1252 sale en UTF-8', mb_check_encoding($fromLatin1, 'UTF-8') && str_contains($fromLatin1, 'Almería'), $fromLatin1);

// ---------------------------------------------------------------------------
// 2. Qué cuenta como subida HTML
// ---------------------------------------------------------------------------

check_ref('maqueta.html (text/html) es HTML', ReferenceHtml::isHtmlUpload('maqueta.html', 'text/html'));
check_ref('maqueta.HTM (text/plain) es HTML', ReferenceHtml::isHtmlUpload('maqueta.HTM', 'text/plain'));
check_ref('una foto no', !ReferenceHtml::isHtmlUpload('foto.png', 'image/png'));
check_ref('un .html que en realidad es una imagen, no', !ReferenceHtml::isHtmlUpload('truco.html', 'image/png'));
check_ref('un .png con HTML dentro, no', !ReferenceHtml::isHtmlUpload('truco.png', 'text/html'));

// ---------------------------------------------------------------------------
// 3. Almacenamiento: limpio, como .txt y fuera de lo público
// ---------------------------------------------------------------------------

$siteId = 1;
$item = ReferenceHtml::store($siteId, $maqueta, 'YROA_Web_Final.html');
$abs = PP_ROOT . '/' . ($item['path'] ?? '');
check_ref('se guarda en storage/documents/<site>/references/',
    str_starts_with((string) $item['path'], 'storage/documents/' . $siteId . '/references/'), (string) $item['path']);
check_ref('nunca en storage/uploads', !str_contains((string) $item['path'], 'uploads'));
check_ref('como .txt (nunca servible como HTML)', str_ends_with((string) $item['path'], '.txt'));
check_ref('lo guardado es la versión limpia', is_file($abs) && file_get_contents($abs) === $clean);
check_ref('el item dice que es HTML', ReferenceHtml::isHtmlItem($item) && ($item['original_name'] ?? '') === 'YROA_Web_Final.html');
check_ref('una captura no es item HTML', !ReferenceHtml::isHtmlItem(['path' => 'storage/uploads/1/references/a.png', 'mime' => 'image/png']));
check_ref('se lee de vuelta desde la lista de referencias',
    ReferenceHtml::load([['path' => 'storage/uploads/1/references/a.png', 'mime' => 'image/png'], $item]) === $clean);
check_ref('sin item HTML no hay texto', ReferenceHtml::load([['path' => 'x.png', 'mime' => 'image/png']]) === '');
@unlink($abs);

$err = '';
try { ReferenceHtml::store($siteId, '<html><body><script>alert(1)</script></body></html>', 'vacia.html'); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
check_ref('una maqueta sin contenido se rechaza', $err !== '');

// ---------------------------------------------------------------------------
// 4. Cableado con el motor
// ---------------------------------------------------------------------------

$describe = (array) Actions::get(Actions::DESCRIBE_REFERENCE_HTML);
check_ref('existe la acción que describe la estructura desde el código',
    in_array('reference_html', (array) ($describe['required'] ?? []), true), json_encode($describe['required'] ?? null));
check_ref('y devuelve el mismo plan que la de capturas', str_contains((string) ($describe['instruction'] ?? ''), '"sections"')
    && str_contains((string) ($describe['instruction'] ?? ''), 'image_brief'));

$compose = (array) Actions::get(Actions::COMPOSE_CANVAS_PAGE);
check_ref('la composición recibe la maqueta', str_contains((string) ($compose['user_template'] ?? ''), '{reference_html}'));
check_ref('con sus reglas propias', str_contains((string) ($compose['instruction'] ?? ''), 'REFERENCIA EN CÓDIGO'));

check_ref('el control de deriva sigue con capturas',
    CanvasGenerator::shouldCheckDrift(['reference_images' => [['mime' => 'image/png', 'data' => 'x']]]));
check_ref('pero no cuando la estructura sale del código',
    !CanvasGenerator::shouldCheckDrift([
        'reference_images' => [['mime' => 'image/png', 'data' => 'x']],
        'reference_html' => $clean,
        'reference_html_mode' => 'structure',
    ]));
check_ref('en modo estilo (otras páginas) sí', CanvasGenerator::shouldCheckDrift([
    'reference_images' => [['mime' => 'image/png', 'data' => 'x']],
    'reference_html' => $clean,
    'reference_html_mode' => 'style',
]));

$view = (string) file_get_contents(PP_ROOT . '/views/admin/onboarding/index.php');
check_ref('el paso 2 deja elegir .html', (bool) preg_match('/name="visual_references\[\]"[^>]*accept="[^"]*\.html/', $view));

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
