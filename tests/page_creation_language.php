<?php

declare(strict_types=1);

/**
 * I18N-FULL FASE 6 — Crear y editar contenido en un sitio multi-idioma.
 *
 * Dos cosas distintas:
 *   1. Al CREAR una página se elige idioma, y de ahí salen su slug con prefijo
 *      y su grupo de traducción propio.
 *   2. Al EDITAR contenido con IA manda el idioma de la PÁGINA, no el del
 *      sitio. Sin esto, pedirle un cambio a una página francesa desde el Studio
 *      devolvía texto en castellano.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function checkNew(string $name, bool $ok, string $detail = ''): void
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

$siteId = 1;
Database::execute("DELETE FROM pages WHERE site_id = ? AND (slug LIKE 'qa14-%' OR slug LIKE 'fr/qa14-%')", [$siteId]);
$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// ---------------------------------------------------------------------------
// 1. El idioma de la página manda al editar con IA
// ---------------------------------------------------------------------------

// La decisión tiene que salir de la PÁGINA. Se comprueba en el código porque
// la alternativa es gastar una llamada a IA por assert.
$chatSrc  = (string) file_get_contents(PP_ROOT . '/app/Services/Canvas/CanvasChatService.php');
checkNew(
    'studio_chat_uses_the_page_language',
    substr_count($chatSrc, 'LanguageService::forPage($page, $siteId)') >= 2
        && !str_contains($chatSrc, 'LanguageService::promptLabelFor($siteId)'),
    'editar una página francesa desde el Studio debe responder en francés'
);

$blockSrc = (string) file_get_contents(PP_ROOT . '/app/Services/Renderer/CustomBlockGenerator.php');
checkNew(
    'block_generator_accepts_a_page_language',
    str_contains($blockSrc, "\$input['language']"),
    'quien genera bloques para una página concreta debe poder imponer su idioma'
);

// Y el resolutor devuelve lo que toca.
checkNew(
    'page_language_beats_site_language',
    LanguageService::forPage(['language' => 'fr'], $siteId) === 'fr'
        && LanguageService::forPage([], $siteId) === LanguageService::codeFor($siteId),
    LanguageService::forPage(['language' => 'fr'], $siteId)
);

// ---------------------------------------------------------------------------
// 2. Crear una página eligiendo idioma
// ---------------------------------------------------------------------------

$created = PageController::createPageRow($siteId, [
    'title'     => 'Nouvelle page QA14',
    'slug'      => 'qa14-nouvelle',
    'page_type' => 'landing',
    'status'    => 'draft',
    'language'  => 'fr',
]);
$page = Database::selectOne('SELECT * FROM pages WHERE id = ?', [(int) $created]);

checkNew(
    'new_page_stores_the_chosen_language',
    ($page['language'] ?? '') === 'fr',
    (string) ($page['language'] ?? '')
);

checkNew(
    'new_page_slug_gets_the_language_prefix',
    ($page['slug'] ?? '') === 'fr/qa14-nouvelle',
    (string) ($page['slug'] ?? '')
);

checkNew(
    'new_page_gets_its_own_translation_group',
    trim((string) ($page['translation_group'] ?? '')) !== '',
    'sin grupo, ni el selector de idioma ni los hreflang la encuentran'
);

// Una página del idioma principal sigue como siempre: sin prefijo.
$createdEs = PageController::createPageRow($siteId, [
    'title'     => 'Página QA14',
    'slug'      => 'qa14-pagina',
    'page_type' => 'landing',
    'status'    => 'draft',
    'language'  => $primary,
]);
$pageEs = Database::selectOne('SELECT * FROM pages WHERE id = ?', [(int) $createdEs]);
checkNew(
    'primary_language_page_has_no_prefix',
    ($pageEs['slug'] ?? '') === 'qa14-pagina' && ($pageEs['language'] ?? '') === $primary,
    (string) ($pageEs['slug'] ?? '')
);

// Un idioma que no está activo no se acepta: cae al principal.
$createdBad = PageController::createPageRow($siteId, [
    'title'     => 'QA14 idioma raro',
    'slug'      => 'qa14-raro',
    'page_type' => 'landing',
    'status'    => 'draft',
    'language'  => 'pt',
]);
$pageBad = Database::selectOne('SELECT * FROM pages WHERE id = ?', [(int) $createdBad]);
checkNew(
    'inactive_language_falls_back_to_primary',
    ($pageBad['language'] ?? '') === $primary && ($pageBad['slug'] ?? '') === 'qa14-raro',
    (string) ($pageBad['language'] ?? '')
);

// ---------------------------------------------------------------------------
// 3. El formulario ofrece el selector solo si hace falta
// ---------------------------------------------------------------------------

$formSrc = (string) file_get_contents(PP_ROOT . '/views/admin/pages/form.php');
checkNew(
    'form_offers_language_only_when_multilingual',
    str_contains($formSrc, 'isMultilingual') && str_contains($formSrc, 'name="language"'),
    'en una web de un idioma no debe aparecer un desplegable que no significa nada'
);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

Database::execute("DELETE FROM pages WHERE site_id = ? AND (slug LIKE 'qa14-%' OR slug LIKE 'fr/qa14-%')", [$siteId]);
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkNew(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary],
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
