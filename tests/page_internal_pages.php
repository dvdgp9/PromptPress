<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;

$failed = 0;
function check_internal_pages(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$pages = [
    ['id' => 1, 'title' => 'Inicio', 'slug' => 'inicio', 'page_type' => 'home', 'parent_id' => null, 'nav_label' => null],
    ['id' => 2, 'title' => 'Servicios', 'slug' => 'servicios', 'page_type' => 'service', 'parent_id' => 1, 'nav_label' => null],
    ['id' => 3, 'title' => 'Formularios (sistema)', 'slug' => '__forms', 'page_type' => 'landing', 'parent_id' => null, 'nav_label' => null],
];

$ref = new ReflectionClass(PageController::class);

$isInternal = $ref->getMethod('isInternalPageSlug');
$isInternal->setAccessible(true);
check_internal_pages('detecta slug interno __forms', $isInternal->invoke(null, '__forms') === true);
check_internal_pages('no marca slug publico como interno', $isInternal->invoke(null, 'contacto') === false);

$visible = $ref->getMethod('visibleAdminPages');
$visible->setAccessible(true);
$visiblePages = $visible->invoke(null, $pages);
check_internal_pages('filtra pagina interna del listado admin', count($visiblePages) === 2, json_encode($visiblePages));
check_internal_pages('no queda __forms visible', !in_array('__forms', array_column($visiblePages, 'slug'), true));

$options = $ref->getMethod('pageOptions');
$options->setAccessible(true);
$pageOptions = $options->invoke(null, $pages);
check_internal_pages('filtra pagina interna de opciones', count($pageOptions) === 2, json_encode($pageOptions));

$tree = $ref->getMethod('buildPageTree');
$tree->setAccessible(true);
$pageTree = $tree->invoke(null, $pages);
$treeSlugs = [];
$collect = function (array $nodes) use (&$collect, &$treeSlugs): void {
    foreach ($nodes as $node) {
        $treeSlugs[] = (string) ($node['slug'] ?? '');
        $collect(is_array($node['children'] ?? null) ? $node['children'] : []);
    }
};
$collect($pageTree);
check_internal_pages('filtra pagina interna del arbol', !in_array('__forms', $treeSlugs, true), json_encode($pageTree));

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
