<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandService;
use App\Services\ChromeService;
use Core\Database;

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

$failed = 0;
function check_chrome(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_chrome('hay site para render', $siteId > 0);
if ($siteId <= 0) {
    exit(1);
}

$config = ChromeService::sanitize([
    'header' => [
        'layout' => [
            'sticky' => false,
            'transparent_over_hero' => true,
            'density' => 'tall',
            'logo_position' => 'center',
            'width' => 'full',
            'nav_alignment' => 'center',
            'mobile_cta' => 'hide',
        ],
        'style' => ['background' => 'brand'],
        'brand' => ['url' => '/clientes'],
        'menu' => [
            ['type' => 'link', 'label' => 'Servicios', 'url' => '/servicios'],
        ],
        'cta' => ['mode' => 'custom', 'label' => 'Hablar ahora', 'url' => '/contacto', 'style' => 'ghost'],
    ],
    'footer' => [
        'style' => ['background' => 'light', 'columns' => 4],
        'blocks' => ['brand', 'nav', 'contact', 'social', 'newsletter'],
        'brand' => ['name' => 'Marca Footer'],
        'labels' => [
            'nav' => 'Mapa',
            'contact' => 'Dónde estamos',
            'social' => 'Canales',
            'newsletter' => 'Avisos',
        ],
        'tagline' => 'Texto de pie personalizado',
        'nav' => [
            ['type' => 'link', 'label' => 'Blog', 'url' => '/blog', 'target' => '_blank'],
        ],
        'contact' => ['address' => 'Calle Test 1', 'phone' => '+34 600 000 000', 'email' => 'hola@example.test'],
        'social' => [
            ['network' => 'linkedin', 'url' => 'https://linkedin.example/test'],
        ],
        'newsletter' => ['enabled' => true, 'heading' => 'Recibe novedades', 'form_ref' => '/newsletter', 'cta_label' => 'Apuntarme'],
    ],
]);

check_chrome('sanitize conserva anchura full', ($config['header']['layout']['width'] ?? '') === 'full');
check_chrome('sanitize conserva alineación center', ($config['header']['layout']['nav_alignment'] ?? '') === 'center');
check_chrome('sanitize conserva mobile_cta hide', ($config['header']['layout']['mobile_cta'] ?? '') === 'hide');
check_chrome('sanitize conserva fondo header brand', ($config['header']['style']['background'] ?? '') === 'brand');
check_chrome('sanitize limita columnas a 4', ($config['footer']['style']['columns'] ?? 0) === 4);
check_chrome('sanitize conserva label newsletter', ($config['footer']['labels']['newsletter'] ?? '') === 'Avisos');
check_chrome('sanitize conserva target página/enlace footer', ($config['footer']['nav'][0]['target'] ?? '') === '_blank');

$header = BrandService::publicHeader($siteId, $config);
check_chrome('header aplica clase full', str_contains($header, 'pp-site-header--full'));
check_chrome('header aplica nav center', str_contains($header, 'pp-site-header--nav-center'));
check_chrome('header aplica CTA ocultable en móvil', str_contains($header, 'pp-site-header__cta--mobile-hidden'));
check_chrome('header aplica fondo brand', str_contains($header, 'pp-site-header--bg-brand'));
check_chrome('header usa destino de marca', str_contains($header, '/clientes'));

$footer = BrandService::publicFooter($siteId, $config);
check_chrome('footer aplica fondo claro', str_contains($footer, 'pp-site-footer--light'));
check_chrome('footer aplica columnas 4', str_contains($footer, 'pp-site-footer--cols-4'));
check_chrome('footer usa nombre de marca manual', str_contains($footer, 'Marca Footer'));
check_chrome('footer usa título nav manual', str_contains($footer, 'Mapa'));
check_chrome('footer usa título contacto manual', str_contains($footer, 'Dónde estamos'));
check_chrome('footer usa título social manual', str_contains($footer, 'Canales'));
check_chrome('footer usa título newsletter manual', str_contains($footer, 'Avisos'));
check_chrome('footer usa CTA newsletter manual', str_contains($footer, 'Apuntarme'));
check_chrome('footer respeta target blank en nav', str_contains($footer, 'target="_blank" rel="noopener"'));

echo PHP_EOL . ($failed === 0 ? 'OK' : "$failed FALLOS") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
