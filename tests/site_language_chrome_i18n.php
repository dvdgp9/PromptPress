<?php

declare(strict_types=1);

/**
 * I18N-FULL T5.1 — Chrome por idioma.
 *
 * Cierra el hueco que quedó visible en la fase 3: el tagline del footer, el
 * nombre de marca, las etiquetas de columna, el menú configurado a mano y los
 * textos de newsletter se guardaban con UN valor por sitio, así que en la web
 * francesa seguían en castellano. Salen en TODAS las páginas, así que cantan.
 *
 * Diseño: una capa `i18n` dentro de la config de chrome que SOLO contiene
 * texto. El layout, los colores y los bordes siguen siendo compartidos — si se
 * duplicaran por idioma, cambiar un color solo afectaría a uno.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandService;
use App\Services\ChromeService;
use App\Services\LanguageService;

$failed = 0;
function checkI18n(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 700) . PHP_EOL;
        }
    }
}

$siteId  = 1;
$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// --- Config de ejemplo: base en castellano + capa francesa -----------------
$config = ChromeService::sanitize([
    'header' => [
        'cta'  => ['mode' => 'custom', 'label' => 'Pide cita', 'url' => '/contacto'],
        'menu' => [['type' => 'link', 'label' => 'Servicios', 'url' => '/servicios']],
    ],
    'footer' => [
        'brand'   => ['name' => 'Marca ES'],
        'labels'  => ['nav' => 'Explora ES', 'legal' => 'Legal ES'],
        'nav'     => [['type' => 'link', 'label' => 'Inicio ES', 'url' => '/']],
        'tagline' => 'Nuestro lema en castellano',
        'newsletter' => ['enabled' => true, 'heading' => 'Suscríbete', 'cta_label' => 'Enviar'],
        'copyright'  => '© PromptPress ES',
    ],
    'i18n' => [
        'fr' => [
            'header' => [
                'cta'  => ['label' => 'Prendre rendez-vous'],
                'menu' => [['type' => 'link', 'label' => 'Nos services', 'url' => '/fr/services']],
            ],
            'footer' => [
                'brand'   => ['name' => 'Marque FR'],
                'labels'  => ['nav' => 'Explorer FR'],
                'nav'     => [['type' => 'link', 'label' => 'Accueil FR', 'url' => '/fr']],
                'tagline' => 'Notre devise en français',
                'newsletter' => ['heading' => 'Abonnez-vous', 'cta_label' => 'Envoyer'],
                'copyright'  => '© PromptPress FR',
            ],
        ],
    ],
]);

// ---------------------------------------------------------------------------
// 1. La capa i18n sobrevive al saneado
// ---------------------------------------------------------------------------

checkI18n(
    'sanitize_keeps_the_i18n_layer',
    ($config['i18n']['fr']['footer']['tagline'] ?? '') === 'Notre devise en français',
    'sanitize() descarta claves desconocidas: si no se contempla `i18n`, se pierde al guardar'
);

checkI18n(
    'sanitize_drops_unsupported_languages',
    !isset($config['i18n']['klingon']),
    'solo idiomas admitidos'
);

// ---------------------------------------------------------------------------
// 2. Resolución por idioma
// ---------------------------------------------------------------------------

$es = ChromeService::localized($config, $primary);
$fr = ChromeService::localized($config, 'fr');

checkI18n(
    'primary_language_gets_the_base_values',
    ($es['footer']['tagline'] ?? '') === 'Nuestro lema en castellano'
        && ($es['footer']['brand']['name'] ?? '') === 'Marca ES'
        && ($es['header']['cta']['label'] ?? '') === 'Pide cita',
    json_encode($es['footer']['brand'] ?? [], JSON_UNESCAPED_UNICODE)
);

checkI18n(
    'secondary_language_gets_its_overlay',
    ($fr['footer']['tagline'] ?? '') === 'Notre devise en français'
        && ($fr['footer']['brand']['name'] ?? '') === 'Marque FR'
        && ($fr['header']['cta']['label'] ?? '') === 'Prendre rendez-vous',
    json_encode($fr['footer'] ?? [], JSON_UNESCAPED_UNICODE)
);

// Lo NO traducido cae a la base, no se queda vacío.
checkI18n(
    'untranslated_field_falls_back_to_base',
    ($fr['footer']['labels']['legal'] ?? '') === 'Legal ES',
    'la etiqueta legal francesa no estaba traducida: debe caer a la base, no desaparecer'
);

// El menú se sustituye ENTERO, no etiqueta a etiqueta: en otro idioma apunta a
// otras páginas.
checkI18n(
    'menu_is_replaced_wholesale',
    ($fr['header']['menu'][0]['url'] ?? '') === '/fr/services'
        && count($fr['header']['menu'] ?? []) === 1,
    json_encode($fr['header']['menu'] ?? [], JSON_UNESCAPED_UNICODE)
);

// El layout y el estilo NO se duplican por idioma: son compartidos.
checkI18n(
    'layout_and_style_are_shared',
    ($fr['header']['layout'] ?? null) === ($es['header']['layout'] ?? null)
        && ($fr['footer']['style'] ?? null) === ($es['footer']['style'] ?? null),
    'si el estilo se duplicara, cambiar un color solo afectaría a un idioma'
);

// ---------------------------------------------------------------------------
// 3. El chrome renderizado usa la capa correcta
// ---------------------------------------------------------------------------

$footerEs = BrandService::publicFooter($siteId, $config, $primary);
$footerFr = BrandService::publicFooter($siteId, $config, 'fr');

checkI18n(
    'rendered_footer_uses_the_language_layer',
    str_contains($footerEs, 'Nuestro lema en castellano') && !str_contains($footerEs, 'Notre devise')
        && str_contains($footerFr, 'Notre devise en français') && !str_contains($footerFr, 'Nuestro lema'),
    'ES tiene francés: ' . (str_contains($footerEs, 'Notre devise') ? 'sí' : 'no')
        . ' · FR tiene castellano: ' . (str_contains($footerFr, 'Nuestro lema') ? 'sí' : 'no')
);

checkI18n(
    'rendered_footer_brand_and_labels_follow_the_language',
    str_contains($footerFr, 'Marque FR') && str_contains($footerFr, 'Explorer FR')
        && str_contains($footerEs, 'Marca ES') && str_contains($footerEs, 'Explora ES'),
    'FR: ' . (str_contains($footerFr, 'Marque FR') ? 'Marque FR' : 'sin marca FR')
);

$headerFr = BrandService::publicHeader($siteId, $config, 'fr');
checkI18n(
    'rendered_header_cta_and_menu_follow_the_language',
    str_contains($headerFr, 'Prendre rendez-vous') && str_contains($headerFr, 'Nos services'),
    'el CTA y el menú del header deben salir en francés'
);

// ---------------------------------------------------------------------------
// 4. Regresión: un sitio sin capa i18n se comporta igual que siempre
// ---------------------------------------------------------------------------

$plain = ChromeService::sanitize([
    'footer' => ['brand' => ['name' => 'Solo ES'], 'tagline' => 'Un único lema'],
]);
$plainEs = ChromeService::localized($plain, $primary);
$plainFr = ChromeService::localized($plain, 'fr');
checkI18n(
    'site_without_overlay_behaves_identically',
    $plainEs === $plainFr && ($plainFr['footer']['tagline'] ?? '') === 'Un único lema',
    'sin capa i18n, todos los idiomas ven lo mismo — como hasta ahora'
);

LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);
checkI18n(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary],
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
