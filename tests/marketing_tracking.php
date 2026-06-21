<?php

// MKT — Tests del panel de Marketing: catálogo ampliado, snippets de código
// personalizado y gating de consentimiento en el banner. Sin llamadas IA.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Compliance\TrackingCatalog;
use App\Services\Compliance\CookieBanner;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  → ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

// ----------------------------------------------------------------------
// 1. Catálogo ampliado: los nuevos servicios existen con su config.
// ----------------------------------------------------------------------
$services = TrackingCatalog::services();
foreach (['ga4', 'meta_pixel', 'recaptcha', 'gtm', 'google_ads', 'tiktok_pixel', 'linkedin'] as $key) {
    check("catálogo contiene '$key'", isset($services[$key]));
}
check('gtm es analytics', ($services['gtm']['category'] ?? '') === 'analytics');
check('google_ads es advertising', ($services['google_ads']['category'] ?? '') === 'advertising');
check('tiktok es advertising', ($services['tiktok_pixel']['category'] ?? '') === 'advertising');
check('linkedin es advertising', ($services['linkedin']['category'] ?? '') === 'advertising');

// Patrón de cada servicio nuevo valida su ejemplo de placeholder.
$patternCases = [
    ['gtm', 'container_id', 'GTM-ABC123', true],
    ['gtm', 'container_id', 'UA-12345', false],
    ['google_ads', 'conversion_id', 'AW-123456789', true],
    ['google_ads', 'conversion_id', 'G-XXXX', false],
    ['linkedin', 'partner_id', '1234567', true],
    ['linkedin', 'partner_id', 'abc', false],
];
foreach ($patternCases as [$svc, $field, $val, $expect]) {
    $pattern = $services[$svc]['config_fields'][$field]['pattern'] ?? '';
    $ok = $pattern !== '' && (bool) preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $val) === $expect;
    check("patrón $svc.$field con '$val' => " . ($expect ? 'válido' : 'inválido'), $ok);
}

// ----------------------------------------------------------------------
// 2. CookieBanner::loadService tiene rama para cada servicio nuevo.
// ----------------------------------------------------------------------
$manifest = [
    'tracking' => ['services' => [
        ['key' => 'gtm', 'enabled' => true, 'config' => ['container_id' => 'GTM-ABC123']],
        ['key' => 'google_ads', 'enabled' => true, 'config' => ['conversion_id' => 'AW-123456789']],
        ['key' => 'tiktok_pixel', 'enabled' => true, 'config' => ['pixel_id' => 'CABCDEFGHIJKLMNO']],
        ['key' => 'linkedin', 'enabled' => true, 'config' => ['partner_id' => '1234567']],
    ]],
];
$html = CookieBanner::render($manifest);
check('banner JS tiene rama gtm', str_contains($html, "s.key === 'gtm'"));
check('banner JS tiene rama google_ads', str_contains($html, "s.key === 'google_ads'"));
check('banner JS tiene rama tiktok_pixel', str_contains($html, "s.key === 'tiktok_pixel'"));
check('banner JS tiene rama linkedin', str_contains($html, "s.key === 'linkedin'"));
check('config gtm presente en JSON', str_contains($html, 'GTM-ABC123'));
check('needsBanner true con analytics+advertising', TrackingCatalog::needsBanner($manifest));

// ----------------------------------------------------------------------
// 3. Snippets de código personalizado: normalización + categorías activas.
// ----------------------------------------------------------------------
$mCustom = [
    'tracking' => ['custom' => [
        ['id' => 'c1', 'label' => 'Hotjar', 'category' => 'analytics', 'placement' => 'head', 'code' => '<script>HJ</script>', 'enabled' => true],
        ['id' => 'c2', 'label' => 'Sin código', 'category' => 'analytics', 'placement' => 'body_end', 'code' => '', 'enabled' => true],
        ['id' => 'c3', 'label' => 'Pausado', 'category' => 'advertising', 'placement' => 'body_end', 'code' => '<script>X</script>', 'enabled' => false],
        ['id' => 'c4', 'label' => 'Cat inválida', 'category' => 'inventada', 'placement' => 'body_end', 'code' => '<script>Y</script>', 'enabled' => true],
    ]],
];
$pub = TrackingCatalog::customForPublic($mCustom);
check('customForPublic descarta vacíos/pausados/cat-inválida (solo 1)', count($pub) === 1, 'count=' . count($pub));
check('customForPublic conserva el habilitado válido', ($pub[0]['id'] ?? '') === 'c1');
check('customForPublic respeta placement head', ($pub[0]['placement'] ?? '') === 'head');

$activeCustom = TrackingCatalog::activeCategories($mCustom);
check('activeCategories incluye analytics por snippet', in_array('analytics', $activeCustom, true));
check('activeCategories no incluye advertising (snippet pausado)', !in_array('advertising', $activeCustom, true));
check('needsBanner true por snippet analytics', TrackingCatalog::needsBanner($mCustom));

// El snippet aparece en el JSON del banner para que el JS lo gatee (no inline).
$htmlCustom = CookieBanner::render($mCustom);
check('banner inyecta cargador genérico injectCustom', str_contains($htmlCustom, 'function injectCustom'));
check('snippet va en C.custom (gateado), código en el JSON de config', str_contains($htmlCustom, 'Hotjar') || str_contains($htmlCustom, 'HJ'));

// ----------------------------------------------------------------------
// 4. Placements y categorías expuestas para la UI.
// ----------------------------------------------------------------------
check('PLACEMENTS tiene head y body_end', isset(TrackingCatalog::PLACEMENTS['head'], TrackingCatalog::PLACEMENTS['body_end']));
$cats = TrackingCatalog::customCategoryChoices();
check('customCategoryChoices devuelve las 4 categorías', count($cats) === count(TrackingCatalog::CATEGORIES));

echo PHP_EOL . ($failed === 0 ? "TODOS OK" : "FALLARON $failed") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
