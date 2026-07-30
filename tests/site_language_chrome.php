<?php

declare(strict_types=1);

/**
 * I18N-FULL FASE 3 — Chrome, navegación y microcopy por idioma de PÁGINA.
 *
 * Hasta ahora todo se resolvía por el idioma del SITIO. En una web bilingüe eso
 * produce mezclas: navegar por `/fr/` con el menú en castellano. A partir de
 * aquí manda el idioma de la página que se está sirviendo.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandService;
use App\Services\LanguageService;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

$failed = 0;
function checkChrome(string $name, bool $ok, string $detail = ''): void
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

// Restos de una ejecución anterior interrumpida: el test debe poder repetirse.
Database::execute(
    "DELETE FROM pages WHERE site_id = ? AND (slug LIKE 'qa-%' OR slug LIKE 'fr/qa-%' OR slug = 'fr')",
    [$siteId]
);

$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// --- Escenario: una página castellana y su traducción francesa -------------
$group = 'qa-group-' . bin2hex(random_bytes(4));
$made  = [];

$mk = function (string $title, string $slug, string $type, string $lang, string $grp) use ($siteId, &$made): int {
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode, created_at, updated_at, published_at)
         VALUES (?, ?, ?, ?, ?, ?, 'published', 'sections', NOW(), NOW(), NOW())",
        [$siteId, $title, $slug, $type, $lang, $grp]
    );
    $id = (int) Database::lastInsertId();
    $made[] = $id;
    return $id;
};

$esServices = $mk('Servicios QA', 'qa-servicios', 'service', $primary, $group);
$frServices = $mk('Nos services QA', 'fr/qa-services', 'service', 'fr', $group);
$frHome     = $mk('Accueil QA', 'fr', 'home', 'fr', 'qa-home-' . bin2hex(random_bytes(3)));
$esLegal    = $mk('Aviso legal QA', 'qa-aviso', 'legal', $primary, 'qa-legal-es');
$frLegal    = $mk('Mentions QA', 'fr/qa-mentions', 'legal', 'fr', 'qa-legal-fr');

// ---------------------------------------------------------------------------
// T3.1 — El menú y los legales se filtran por idioma
// ---------------------------------------------------------------------------

$headerEs = BrandService::publicHeader($siteId, null, $primary);
$headerFr = BrandService::publicHeader($siteId, null, 'fr');

checkChrome(
    'menu_shows_only_pages_of_the_served_language',
    str_contains($headerEs, 'Servicios QA') && !str_contains($headerEs, 'Nos services QA')
        && str_contains($headerFr, 'Nos services QA') && !str_contains($headerFr, 'Servicios QA'),
    'ES tiene francés: ' . (str_contains($headerEs, 'Nos services QA') ? 'sí' : 'no')
        . ' · FR tiene castellano: ' . (str_contains($headerFr, 'Servicios QA') ? 'sí' : 'no')
);

$footerEs = BrandService::publicFooter($siteId, null, $primary);
$footerFr = BrandService::publicFooter($siteId, null, 'fr');

checkChrome(
    'legal_links_are_filtered_by_language',
    str_contains($footerEs, 'Aviso legal QA') && !str_contains($footerEs, 'Mentions QA')
        && str_contains($footerFr, 'Mentions QA') && !str_contains($footerFr, 'Aviso legal QA'),
    'los enlaces legales de un idioma no pueden aparecer en el otro'
);

// ---------------------------------------------------------------------------
// T3.3 — El microcopy sigue al idioma de render, no al del sitio
// ---------------------------------------------------------------------------

// OJO: el sitio sigue en castellano. Aun así el footer francés debe estar en
// francés — eso es exactamente lo que fallaba antes de esta fase.
checkChrome(
    'footer_microcopy_follows_the_page_language',
    str_contains($footerFr, 'Explorer') && str_contains($footerEs, 'Explora'),
    'FR: ' . (str_contains($footerFr, 'Explorer') ? 'Explorer' : 'sin Explorer')
        . ' · ES: ' . (str_contains($footerEs, 'Explora') ? 'Explora' : 'sin Explora')
);

SectionRenderer::setSiteContext($siteId, 'fr');
$formFr = SectionRenderer::render([
    'id' => 999001, 'section_type' => 'form', 'style' => '{}',
    'content' => json_encode(['heading' => 'Contact', 'fields' => [['type' => 'text', 'label' => 'Nom']]]),
]);
SectionRenderer::setSiteContext($siteId, $primary);
$formEs = SectionRenderer::render([
    'id' => 999002, 'section_type' => 'form', 'style' => '{}',
    'content' => json_encode(['heading' => 'Contacto', 'fields' => [['type' => 'text', 'label' => 'Nombre']]]),
]);

checkChrome(
    'section_microcopy_follows_the_render_language',
    str_contains($formFr, 'Envoyer') && str_contains($formEs, 'Enviar'),
    'FR botón Envoyer: ' . (str_contains($formFr, 'Envoyer') ? 'sí' : 'no')
);

// ---------------------------------------------------------------------------
// T3.2 — Selector de idioma
// ---------------------------------------------------------------------------

checkChrome(
    'switcher_appears_only_when_multilingual',
    str_contains($headerFr, 'pp-site-header__lang'),
    'falta el selector en un sitio con dos idiomas'
);

// Enlaza a la traducción EQUIVALENTE, no a la home, cuando existe.
$target = BrandService::languageSwitchTarget($siteId, ['id' => $esServices, 'translation_group' => $group], 'fr');
checkChrome(
    'switcher_links_to_the_equivalent_translation',
    str_contains($target, '/fr/qa-services'),
    $target
);

// Si esa página no está traducida, cae a la home de ese idioma (nunca a un 404).
$target404 = BrandService::languageSwitchTarget($siteId, ['id' => $esLegal, 'translation_group' => 'qa-legal-es'], 'fr');
checkChrome(
    'switcher_falls_back_to_that_language_home',
    rtrim($target404, '/') === rtrim(base_url('fr'), '/'),
    $target404
);

// El selector nombra los idiomas en su propio idioma (endónimo), no traducidos.
checkChrome(
    'switcher_uses_endonyms',
    str_contains($headerFr, 'Español') && str_contains($headerFr, 'Français'),
    'un selector que dice «Espagnol» en vez de «Español» es peor UX'
);

// ---------------------------------------------------------------------------
// Limpieza (antes de desactivar: la guarda de la fase 1 impide —con razón—
// desactivar un idioma que todavía tiene páginas)
// ---------------------------------------------------------------------------

if ($made !== []) {
    Database::execute('DELETE FROM pages WHERE id IN (' . implode(',', array_map('intval', $made)) . ')');
}
$disabled = LanguageService::disable($siteId, 'fr');
checkChrome(
    'language_can_be_disabled_once_its_pages_are_gone',
    ($disabled['ok'] ?? false) === true,
    json_encode($disabled, JSON_UNESCAPED_UNICODE)
);
LanguageService::forget($siteId);

// En un sitio de un solo idioma NO debe aparecer nada.
$headerMono = BrandService::publicHeader($siteId, null, $primary);
checkChrome(
    'monolingual_site_shows_no_switcher',
    !str_contains($headerMono, 'pp-site-header__lang'),
    'una web de un idioma no debe ver ni rastro del selector'
);

checkChrome(
    'test_leaves_no_traces',
    (int) Database::selectOne('SELECT COUNT(*) c FROM pages WHERE site_id = ? AND language = ?', [$siteId, 'fr'])['c'] === 0
        && LanguageService::activeFor($siteId) === [$primary],
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
