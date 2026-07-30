<?php

declare(strict_types=1);

/**
 * I18N-FULL FASE 1 — Modelo de datos del multi-idioma.
 *
 * Verifica sobre la BD REAL (no mocks) que:
 *   - `pages` tiene idioma y grupo de traducción, con todas las filas migradas;
 *   - pedidos y reservas guardan el idioma con el que compró el cliente (T1.2),
 *     que es lo que hará que los emails dejen de depender del idioma del sitio;
 *   - existe el catálogo de idiomas activos por sitio, opt-in (T1.3);
 *   - `LanguageService` resuelve idiomas activos, principal y prefijo (T1.4);
 *   - productos y servicios admiten variantes idiomáticas sin romper el UNIQUE
 *     de slug (T1.5).
 *
 * NO deja basura: lo que crea, lo borra.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function checkModel(string $name, bool $ok, string $detail = ''): void
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

function hasColumn(string $table, string $column): bool
{
    return (bool) Database::selectOne(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
}

$siteId = 1;

// ---------------------------------------------------------------------------
// T1.1 — pages: idioma y grupo de traducción
// ---------------------------------------------------------------------------

checkModel(
    'pages_have_language_and_translation_group',
    hasColumn('pages', 'language') && hasColumn('pages', 'translation_group'),
    'faltan columnas en `pages`'
);

$orphans = Database::selectOne(
    "SELECT COUNT(*) c FROM pages WHERE language IS NULL OR language = '' OR translation_group IS NULL OR translation_group = ''"
);
checkModel(
    'every_existing_page_was_backfilled',
    (int) ($orphans['c'] ?? 1) === 0,
    'páginas sin idioma o sin grupo: ' . ($orphans['c'] ?? '?')
);

// El backfill tiene que respetar el idioma del sitio, no meter 'es' a lo bruto.
$mismatch = Database::selectOne(
    'SELECT COUNT(*) c FROM pages p JOIN sites s ON s.id = p.site_id WHERE p.language <> s.language'
);
checkModel(
    'backfill_used_the_site_language',
    (int) ($mismatch['c'] ?? 1) === 0,
    'páginas cuyo idioma no coincide con el del sitio: ' . ($mismatch['c'] ?? '?')
);

// Cada página existente debe tener grupo propio (aún no hay traducciones).
$dupGroups = Database::selectOne(
    'SELECT COUNT(*) c FROM (SELECT translation_group FROM pages GROUP BY translation_group HAVING COUNT(*) > 1) x'
);
checkModel(
    'existing_pages_start_in_their_own_group',
    (int) ($dupGroups['c'] ?? 1) === 0,
    'grupos compartidos antes de traducir nada: ' . ($dupGroups['c'] ?? '?')
);

// ---------------------------------------------------------------------------
// T1.2 — el idioma viaja con el pedido y con la reserva
// ---------------------------------------------------------------------------

checkModel(
    'orders_and_bookings_store_language',
    hasColumn('commerce_orders', 'language') && hasColumn('booking_bookings', 'language'),
    'sin esta columna los emails seguirían atados al idioma del sitio'
);

// ---------------------------------------------------------------------------
// T1.3 — idiomas activos por sitio (opt-in)
// ---------------------------------------------------------------------------

$tableExists = (bool) Database::selectOne(
    'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
    ['site_languages']
);
checkModel('site_languages_table_exists', $tableExists, 'falta la tabla `site_languages`');

$primaryRow = Database::selectOne(
    'SELECT code FROM site_languages WHERE site_id = ? AND is_primary = 1 LIMIT 1',
    [$siteId]
);
checkModel(
    'every_site_starts_with_its_language_as_primary',
    ($primaryRow['code'] ?? '') === LanguageService::codeFor($siteId),
    'principal=' . ($primaryRow['code'] ?? 'ninguno') . ' · sites.language=' . LanguageService::codeFor($siteId)
);

// ---------------------------------------------------------------------------
// T1.4 — API de LanguageService
// ---------------------------------------------------------------------------

$active = LanguageService::activeFor($siteId);
checkModel(
    'active_languages_default_to_just_the_primary',
    $active === [LanguageService::primaryFor($siteId)],
    'activos: ' . implode(',', $active)
);

checkModel(
    'a_single_language_site_is_not_multilingual',
    LanguageService::isMultilingual($siteId) === false,
    'un sitio de un idioma debe comportarse exactamente como hasta ahora'
);

// El idioma principal NO lleva prefijo; los secundarios sí. Es la decisión de
// URLs que evita romper las webs ya publicadas.
$primary = LanguageService::primaryFor($siteId);
checkModel(
    'primary_language_has_no_url_prefix',
    LanguageService::prefixFor($siteId, $primary) === ''
        && LanguageService::prefixFor($siteId, 'fr') === 'fr',
    'prefijo principal="' . LanguageService::prefixFor($siteId, $primary) . '" · fr="' . LanguageService::prefixFor($siteId, 'fr') . '"'
);

// forPage(): el idioma de una página manda sobre el del sitio.
checkModel(
    'page_language_wins_over_site_language',
    LanguageService::forPage(['language' => 'fr'], $siteId) === 'fr'
        && LanguageService::forPage(['language' => ''], $siteId) === LanguageService::codeFor($siteId),
    LanguageService::forPage(['language' => 'fr'], $siteId)
);

// --- Activar un idioma secundario y comprobar el comportamiento -------------
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);
$activeNow = LanguageService::activeFor($siteId);
checkModel(
    'enabling_a_language_makes_the_site_multilingual',
    in_array('fr', $activeNow, true) && LanguageService::isMultilingual($siteId),
    'activos tras habilitar fr: ' . implode(',', $activeNow)
);
checkModel(
    'enabling_a_language_does_not_change_the_primary',
    LanguageService::primaryFor($siteId) === $primary,
    'el principal ha cambiado solo: ' . LanguageService::primaryFor($siteId)
);

// Desactivar NO puede tirar contenido por delante.
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, created_at, updated_at)
     VALUES (?, 'QA idioma', 'qa-lang-fr', 'landing', 'fr', UUID(), 'draft', NOW(), NOW())",
    [$siteId]
);
$qaPageId = (int) Database::lastInsertId();
$blocked = LanguageService::disable($siteId, 'fr');
checkModel(
    'cannot_disable_a_language_that_still_has_pages',
    $blocked['ok'] === false && ($blocked['pages'] ?? 0) >= 1,
    json_encode($blocked, JSON_UNESCAPED_UNICODE)
);

Database::execute('DELETE FROM pages WHERE id = ?', [$qaPageId]);
$now = LanguageService::disable($siteId, 'fr');
checkModel(
    'disabling_an_empty_language_works',
    $now['ok'] === true,
    json_encode($now, JSON_UNESCAPED_UNICODE)
);

checkModel(
    'the_primary_language_can_never_be_disabled',
    LanguageService::disable($siteId, $primary)['ok'] === false,
    'dejar un sitio sin idioma principal lo dejaría sin home'
);

// --- El idioma principal y `sites.language` no pueden divergir --------------
// Ajustes cambia `sites.language`; si el catálogo no se entera, `primaryFor()`
// devuelve el idioma viejo y los prefijos de URL dejan de cuadrar.
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);
checkModel(
    'primary_cannot_change_while_other_languages_are_active',
    LanguageService::setPrimary($siteId, 'fr')['error'] === 'multilingual',
    'cambiar el principal reescribiría las URLs de todas las páginas'
);
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkModel(
    'primary_can_change_on_a_single_language_site',
    LanguageService::setPrimary($siteId, 'pt')['ok'] === true
        && LanguageService::primaryFor($siteId) === 'pt',
    LanguageService::primaryFor($siteId)
);
LanguageService::setPrimary($siteId, $primaryRow['code'] ?? 'es');
LanguageService::forget($siteId);

// ---------------------------------------------------------------------------
// T1.5 — catálogo preparado para traducciones
// ---------------------------------------------------------------------------

checkModel(
    'catalogue_tables_are_ready_for_translations',
    hasColumn('commerce_products', 'language') && hasColumn('commerce_products', 'translation_group')
        && hasColumn('booking_services', 'language') && hasColumn('booking_services', 'translation_group'),
    'faltan columnas de idioma en el catálogo'
);

// El UNIQUE de slug era (site_id, slug): impedía la variante francesa del mismo
// producto. Debe pasar a incluir el idioma.
$uniqueCols = Database::select(
    "SELECT COLUMN_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commerce_products' AND INDEX_NAME = 'uq_cp_slug'
     ORDER BY SEQ_IN_INDEX"
);
$cols = array_column($uniqueCols, 'COLUMN_NAME');
checkModel(
    'product_slug_is_unique_per_language',
    $cols === ['site_id', 'language', 'slug'],
    'columnas del UNIQUE: ' . implode(', ', $cols)
);

// Prueba real: dos variantes idiomáticas del mismo slug deben convivir.
$okTwoVariants = true;
$detail = '';
try {
    Database::execute(
        "INSERT INTO commerce_products (site_id, name, slug, language, translation_group, price_cents, tax_rate, active, created_at, updated_at)
         VALUES (?, 'QA producto', 'qa-i18n-producto', 'es', 'qa-group-1', 1000, 21.00, 0, NOW(), NOW())",
        [$siteId]
    );
    Database::execute(
        "INSERT INTO commerce_products (site_id, name, slug, language, translation_group, price_cents, tax_rate, active, created_at, updated_at)
         VALUES (?, 'QA produit', 'qa-i18n-producto', 'fr', 'qa-group-1', 1000, 21.00, 0, NOW(), NOW())",
        [$siteId]
    );
} catch (\Throwable $e) {
    $okTwoVariants = false;
    $detail = $e->getMessage();
}
Database::execute("DELETE FROM commerce_products WHERE slug = 'qa-i18n-producto' AND site_id = ?", [$siteId]);
checkModel('same_product_slug_can_exist_in_two_languages', $okTwoVariants, $detail);

// ...pero el mismo slug en el MISMO idioma debe seguir prohibido.
$duplicateRejected = false;
try {
    Database::execute(
        "INSERT INTO commerce_products (site_id, name, slug, language, price_cents, tax_rate, active, created_at, updated_at)
         VALUES (?, 'QA a', 'qa-i18n-dup', 'es', 1000, 21.00, 0, NOW(), NOW())",
        [$siteId]
    );
    Database::execute(
        "INSERT INTO commerce_products (site_id, name, slug, language, price_cents, tax_rate, active, created_at, updated_at)
         VALUES (?, 'QA b', 'qa-i18n-dup', 'es', 1000, 21.00, 0, NOW(), NOW())",
        [$siteId]
    );
} catch (\Throwable $e) {
    $duplicateRejected = true;
}
Database::execute("DELETE FROM commerce_products WHERE slug = 'qa-i18n-dup' AND site_id = ?", [$siteId]);
checkModel('duplicate_slug_in_the_same_language_is_still_rejected', $duplicateRejected);

// ---------------------------------------------------------------------------
// Estado final: el sitio queda como estaba
// ---------------------------------------------------------------------------

LanguageService::forget($siteId);
checkModel(
    'site_is_left_as_it_was_found',
    LanguageService::activeFor($siteId) === [$primary] && !LanguageService::isMultilingual($siteId),
    'activos al terminar: ' . implode(',', LanguageService::activeFor($siteId))
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
