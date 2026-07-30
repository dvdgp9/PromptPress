<?php

declare(strict_types=1);

/**
 * I18N-FULL FASE 2 — URLs y resolución por idioma.
 *
 * La decisión de diseño que se verifica aquí: el idioma PRINCIPAL conserva sus
 * URLs (`/`, `/contacto`) y los secundarios viven bajo prefijo (`/fr/`,
 * `/fr/contact`). Como `pages.slug` admite barras y el router ya expone un
 * catch-all `{slug:path}`, el prefijo vive DENTRO del slug: no hace falta
 * tocar el enrutado.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use App\Services\CacheService;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function checkUrls(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
        }
    }
}

$siteId = 1;
$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// ---------------------------------------------------------------------------
// T2.1 — El prefijo vive dentro del slug
// ---------------------------------------------------------------------------

checkUrls(
    'primary_language_slug_has_no_prefix',
    LanguageService::applySlugPrefix($siteId, 'contacto', $primary) === 'contacto',
    LanguageService::applySlugPrefix($siteId, 'contacto', $primary)
);

checkUrls(
    'secondary_language_slug_gets_prefixed',
    LanguageService::applySlugPrefix($siteId, 'contact', 'fr') === 'fr/contact',
    LanguageService::applySlugPrefix($siteId, 'contact', 'fr')
);

// Idempotente: aplicar el prefijo dos veces no produce `fr/fr/contact`.
checkUrls(
    'applying_the_prefix_twice_is_idempotent',
    LanguageService::applySlugPrefix($siteId, 'fr/contact', 'fr') === 'fr/contact',
    LanguageService::applySlugPrefix($siteId, 'fr/contact', 'fr')
);

// Cambiar de idioma reescribe el prefijo, no lo acumula.
checkUrls(
    'changing_language_rewrites_the_prefix',
    LanguageService::applySlugPrefix($siteId, 'fr/contact', $primary) === 'contact',
    LanguageService::applySlugPrefix($siteId, 'fr/contact', $primary)
);

// La home de un idioma secundario es EXACTAMENTE el prefijo.
checkUrls(
    'secondary_home_slug_is_just_the_prefix',
    LanguageService::applySlugPrefix($siteId, '', 'fr') === 'fr',
    LanguageService::applySlugPrefix($siteId, '', 'fr')
);

// --- Guarda anti-colisión --------------------------------------------------
// Una página del idioma principal NO puede ocupar el espacio de nombres de un
// idioma activo: `/fr/...` tiene que seguir siendo del francés.
checkUrls(
    'primary_page_cannot_squat_a_language_namespace',
    LanguageService::slugCollidesWithLanguage($siteId, 'fr/algo', $primary) === true
        && LanguageService::slugCollidesWithLanguage($siteId, 'fr', $primary) === true,
    'debería detectar la colisión'
);

checkUrls(
    'a_language_owns_its_own_namespace',
    LanguageService::slugCollidesWithLanguage($siteId, 'fr/contact', 'fr') === false,
    'el francés sí puede usar /fr/'
);

// Un idioma NO activo no reserva espacio de nombres: `/pt/algo` es un slug
// legítimo mientras el portugués no esté activado.
checkUrls(
    'inactive_language_does_not_reserve_a_namespace',
    LanguageService::slugCollidesWithLanguage($siteId, 'pt/algo', $primary) === false,
    'pt no está activo: no debe reservar nada'
);

// uniqueSlug aplica el prefijo cuando se le dice el idioma.
$generated = PageController::uniqueSlug($siteId, slugify('Nous contacter'), null, 'fr');
checkUrls(
    'unique_slug_applies_the_language_prefix',
    str_starts_with($generated, 'fr/'),
    $generated
);

// ---------------------------------------------------------------------------
// T2.3 — La caché de la home es por idioma
// ---------------------------------------------------------------------------

checkUrls(
    'home_cache_key_is_per_language',
    CacheService::homeKey($siteId, $primary) !== CacheService::homeKey($siteId, 'fr')
        && CacheService::homeKey($siteId, $primary) === CacheService::HOME_KEY,
    CacheService::homeKey($siteId, $primary) . ' vs ' . CacheService::homeKey($siteId, 'fr')
);

// Escribir la home francesa no puede pisar la castellana.
CacheService::put($siteId, CacheService::homeKey($siteId, $primary), '<p>HOME ES</p>');
CacheService::put($siteId, CacheService::homeKey($siteId, 'fr'), '<p>HOME FR</p>');
checkUrls(
    'writing_one_home_does_not_overwrite_the_other',
    CacheService::get($siteId, CacheService::homeKey($siteId, $primary)) === '<p>HOME ES</p>'
        && CacheService::get($siteId, CacheService::homeKey($siteId, 'fr')) === '<p>HOME FR</p>',
    'ES=' . (CacheService::get($siteId, CacheService::homeKey($siteId, $primary)) ?? 'null')
        . ' FR=' . (CacheService::get($siteId, CacheService::homeKey($siteId, 'fr')) ?? 'null')
);

// Invalidar la home francesa deja intacta la castellana.
CacheService::invalidatePage($siteId, ['slug' => 'fr', 'page_type' => 'home', 'language' => 'fr']);
checkUrls(
    'invalidating_one_home_leaves_the_other_alone',
    CacheService::get($siteId, CacheService::homeKey($siteId, 'fr')) === null
        && CacheService::get($siteId, CacheService::homeKey($siteId, $primary)) === '<p>HOME ES</p>',
    'tras invalidar fr: ES=' . var_export(CacheService::get($siteId, CacheService::homeKey($siteId, $primary)), true)
);
CacheService::forget($siteId, CacheService::homeKey($siteId, $primary));

// ---------------------------------------------------------------------------
// T2.2 — Resolución de la home por idioma (a nivel de consulta)
// ---------------------------------------------------------------------------

// Creamos una home francesa de verdad para comprobar la resolución.
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, created_at, updated_at)
     VALUES (?, 'Accueil QA', 'fr', 'home', 'fr', UUID(), 'published', NOW(), NOW())",
    [$siteId]
);
$frHomeId = (int) Database::lastInsertId();

$resolved = \App\Controllers\Public\PageController::homePageFor($siteId, 'fr');
checkUrls(
    'french_home_is_resolved_by_language',
    (int) ($resolved['id'] ?? 0) === $frHomeId,
    'resuelta: ' . json_encode($resolved['slug'] ?? null)
);

$resolvedEs = \App\Controllers\Public\PageController::homePageFor($siteId, $primary);
checkUrls(
    'primary_home_is_not_the_french_one',
    (int) ($resolvedEs['id'] ?? 0) !== $frHomeId,
    'la home principal no puede ser la francesa'
);

// El slug `fr` debe reconocerse como "home del idioma francés".
checkUrls(
    'bare_language_code_is_recognised_as_that_home',
    LanguageService::languageFromHomeSlug($siteId, 'fr') === 'fr'
        && LanguageService::languageFromHomeSlug($siteId, 'contacto') === null
        && LanguageService::languageFromHomeSlug($siteId, 'pt') === null,
    'fr=' . var_export(LanguageService::languageFromHomeSlug($siteId, 'fr'), true)
);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

Database::execute('DELETE FROM pages WHERE id = ?', [$frHomeId]);
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkUrls(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary]
        && Database::selectOne('SELECT COUNT(*) c FROM pages WHERE site_id = ? AND language = ?', [$siteId, 'fr'])['c'] === 0,
    'quedan restos del test'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
