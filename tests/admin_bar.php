<?php

declare(strict_types=1);

/**
 * ADMIN-BAR — La píldora para saltar al panel desde la web.
 *
 * Lo que de verdad hay que sujetar aquí no es que la barra salga, sino que NO
 * salga para el visitante y que una página servida con barra **no acabe en la
 * caché**: el HTML público se guarda con clave de sitio + slug + idioma y sin
 * nada del usuario, así que una página cacheada con la barra dentro enseñaría
 * los enlaces del panel a cualquiera.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AdminBar;
use Core\Database;

$failed = 0;
function barCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$canvasPage   = ['id' => 42, 'render_mode' => 'canvas',   'page_type' => 'home',    'status' => 'published'];
$classicPage  = ['id' => 43, 'render_mode' => 'sections', 'page_type' => 'landing', 'status' => 'published'];
$articlePage  = ['id' => 44, 'render_mode' => 'sections', 'page_type' => 'article', 'status' => 'published'];
$draftPage    = ['id' => 45, 'render_mode' => 'canvas',   'page_type' => 'home',    'status' => 'draft'];

// --- Sin sesión: ni una palabra ---------------------------------------------
unset($_SESSION['user_id']);
barCheck('sin sesión no se pinta nada', AdminBar::render($canvasPage) === '');
barCheck('sin sesión, shouldRender() dice que no', AdminBar::shouldRender() === false);

// --- Con sesión --------------------------------------------------------------
$userId = (int) (Database::selectOne('SELECT id FROM users ORDER BY id LIMIT 1')['id'] ?? 0);
if ($userId <= 0) {
    echo "SKIP: no hay usuarios en la base de datos de desarrollo.\n";
    exit(0);
}
$_SESSION['user_id'] = $userId;

barCheck('con sesión, shouldRender() dice que sí', AdminBar::shouldRender() === true);

$html = AdminBar::render($canvasPage);
barCheck('con sesión se pinta la píldora', str_contains($html, 'pp-adminbar'), $html);
barCheck('lleva al panel', str_contains($html, 'href="' . e(base_url('admin/')) . '"'), $html);

// --- A dónde lleva "Editar" según el tipo de página --------------------------
// Una entrada del blog es una fila de `pages` con page_type='article', así que
// las tres salen del mismo sitio y solo se distinguen aquí.
barCheck('una página canvas lleva al Studio',
    AdminBar::editUrl($canvasPage) === base_url('admin/canvas/42'), AdminBar::editUrl($canvasPage));
barCheck('una página clásica lleva a su editor',
    AdminBar::editUrl($classicPage) === base_url('admin/pages/43/edit'), AdminBar::editUrl($classicPage));
barCheck('una entrada lleva al editor de entradas',
    AdminBar::editUrl($articlePage) === base_url('admin/posts/44/edit'), AdminBar::editUrl($articlePage));
barCheck('sin id no inventa una URL rota',
    AdminBar::editUrl(['id' => 0]) === base_url('admin/'), AdminBar::editUrl(['id' => 0]));

barCheck('el enlace de una canvas dice "Studio"',
    str_contains(AdminBar::render($canvasPage), e(__('bar.edit_studio'))));
barCheck('el de una entrada habla de entradas',
    str_contains(AdminBar::render($articlePage), e(__('bar.edit_post'))));

// La ruta pública solo sirve páginas publicadas, así que la barra nunca se
// pinta sobre un borrador y no tiene por qué hablar de ellos.
barCheck('la barra no habla de borradores',
    !str_contains(AdminBar::render($draftPage), 'pp-adminbar__badge'), AdminBar::render($draftPage));

// --- Sin depender de assets externos ----------------------------------------
// Un `.css` o un `.js` aparte volverían a depender de que el navegador se
// traiga la versión nueva, que es el fallo que costó la 1.2.8.
barCheck('el estilo y el script viajan en línea',
    str_contains($html, '<style>') && str_contains($html, '<script>')
    && !str_contains($html, '<link ') && !str_contains($html, 'src='), $html);
barCheck('no se imprime en papel', str_contains($html, '@media print'));

// --- La guarda que importa ---------------------------------------------------
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Public/PageController.php');
barCheck('la página con barra NO se guarda en caché',
    str_contains($controller, '$cacheable = ($adminBar === \'\')'), 'la guarda de CacheService::put ya no mira la barra');
barCheck('con sesión tampoco se LEE la caché',
    substr_count($controller, 'AdminBar::shouldRender() && !self::pageHasForm') === 2,
    'las dos rutas públicas (home y resto) tienen que saltarse la lectura');
barCheck('la respuesta con barra se marca como privada',
    str_contains($controller, "header('Cache-Control: private, no-store, max-age=0')")
    && str_contains($controller, 'self::serve($h, false, $adminBar !== \'\')'), $controller);

unset($_SESSION['user_id']);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
