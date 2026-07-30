<?php

declare(strict_types=1);

/**
 * I18N-FULL T5.3 — Guardado de una traducción.
 *
 * A propósito separado de la llamada a IA: traducir y guardar son dos cosas, y
 * el riesgo real (duplicar páginas, pisar contenido, publicar sin querer) está
 * en el guardado. Así se prueba entero sin gastar una sola llamada.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use App\Services\LanguageService;
use App\Services\TranslationWriter;
use Core\Database;

$failed = 0;
function checkWrite(string $name, bool $ok, string $detail = ''): void
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
$cleanup = function () use ($siteId): void {
    $ids = array_column(Database::select(
        "SELECT id FROM pages WHERE site_id = ? AND (slug LIKE 'qa9-%' OR slug LIKE 'fr/qa9-%')",
        [$siteId]
    ), 'id');
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        Database::execute("DELETE FROM page_canvas WHERE page_id IN ($in)");
        Database::execute("DELETE FROM page_versions WHERE page_id IN ($in)");
        Database::execute("DELETE FROM page_sections WHERE page_id IN ($in)");
        Database::execute("DELETE FROM pages WHERE id IN ($in)");
    }
};
$cleanup();

$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// --- Página canvas de origen ------------------------------------------------
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode,
        meta_title, meta_description, created_at, updated_at, published_at)
     VALUES (?, 'Servicios QA9', 'qa9-servicios', 'service', ?, UUID(), 'published', 'canvas',
        'Servicios | Academia', 'Descripción original', NOW(), NOW(), NOW())",
    [$siteId, $primary]
);
$sourceId = (int) Database::lastInsertId();
CanvasService::save($sourceId, '<section data-pp-section="hero"><h1>Servicios</h1></section>', 'body{}', 'edit', 'QA');
$source = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$sourceId]);

// Carga útil como la que devuelve PageTranslator, sin haber llamado a la IA.
$payload = [
    'ok'               => true,
    'title'            => 'Nos services',
    'html'             => '<section data-pp-section="hero"><h1>Nos services</h1></section>',
    'meta_title'       => 'Nos services | Academia',
    'meta_description' => 'Description traduite',
];

// ---------------------------------------------------------------------------
// 1. Creación de la traducción
// ---------------------------------------------------------------------------

$result = TranslationWriter::createCanvas($siteId, $source, 'fr', $payload);
checkWrite('translation_is_created', ($result['ok'] ?? false) === true, json_encode($result, JSON_UNESCAPED_UNICODE));

$new = Database::selectOne('SELECT * FROM pages WHERE id = ?', [(int) ($result['page_id'] ?? 0)]);

checkWrite(
    'translation_lands_as_draft',
    ($new['status'] ?? '') === 'draft',
    'una traducción automática NUNCA se publica sola: la publica una persona'
);

checkWrite(
    'translation_shares_the_translation_group',
    ($new['translation_group'] ?? '') === ($source['translation_group'] ?? 'x'),
    'sin el grupo compartido, el selector de idioma y los hreflang no la encuentran'
);

checkWrite(
    'translation_slug_is_prefixed',
    ($new['slug'] ?? '') === 'fr/qa9-servicios',
    (string) ($new['slug'] ?? '')
);

checkWrite(
    'translated_title_and_seo_are_stored',
    ($new['title'] ?? '') === 'Nos services'
        && ($new['meta_title'] ?? '') === 'Nos services | Academia'
        && ($new['meta_description'] ?? '') === 'Description traduite',
    json_encode(['t' => $new['title'] ?? '', 'mt' => $new['meta_title'] ?? ''], JSON_UNESCAPED_UNICODE)
);

checkWrite(
    'translation_keeps_type_and_render_mode',
    ($new['page_type'] ?? '') === 'service' && ($new['render_mode'] ?? '') === 'canvas',
    'la traducción es la misma página en otro idioma, no otra cosa'
);

$canvas = CanvasService::get((int) $new['id']);
checkWrite(
    'translated_canvas_content_is_saved',
    str_contains((string) ($canvas['html'] ?? ''), 'Nos services'),
    (string) ($canvas['html'] ?? '')
);

// ---------------------------------------------------------------------------
// 2. Lo que NO debe pasar
// ---------------------------------------------------------------------------

$sourceAfter = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$sourceId]);
checkWrite(
    'source_page_is_untouched',
    $sourceAfter['title'] === $source['title']
        && $sourceAfter['status'] === $source['status']
        && $sourceAfter['slug'] === $source['slug'],
    'traducir jamás puede modificar la página original'
);

// Pedirlo dos veces NO crea una segunda página.
$again = TranslationWriter::createCanvas($siteId, $source, 'fr', $payload);
checkWrite(
    'asking_twice_does_not_duplicate',
    ($again['ok'] ?? false) === false && ($again['error'] ?? '') === 'exists'
        && (int) ($again['page_id'] ?? 0) === (int) $new['id'],
    json_encode($again, JSON_UNESCAPED_UNICODE)
);

checkWrite(
    'existing_translation_message_is_helpful',
    isset($again['message']) && str_contains((string) $again['message'], 'Français'),
    (string) ($again['message'] ?? '')
);

// No se traduce al idioma que ya tiene la página.
$sameLang = TranslationWriter::createCanvas($siteId, $source, $primary, $payload);
checkWrite(
    'cannot_translate_into_its_own_language',
    ($sameLang['ok'] ?? false) === false,
    json_encode($sameLang, JSON_UNESCAPED_UNICODE)
);

// No se traduce a un idioma que no está activo en el sitio.
$inactive = TranslationWriter::createCanvas($siteId, $source, 'pt', $payload);
checkWrite(
    'cannot_translate_into_an_inactive_language',
    ($inactive['ok'] ?? false) === false,
    json_encode($inactive, JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------------------
// 3. Estado de traducción de una página (para el panel)
// ---------------------------------------------------------------------------

$status = TranslationWriter::statusFor($siteId, $source);
checkWrite(
    'status_reports_existing_and_missing_languages',
    ($status['fr']['exists'] ?? false) === true
        && (int) ($status['fr']['page_id'] ?? 0) === (int) $new['id']
        && ($status['fr']['status'] ?? '') === 'draft',
    json_encode($status, JSON_UNESCAPED_UNICODE)
);

checkWrite(
    'status_excludes_the_pages_own_language',
    !isset($status[$primary]),
    'no tiene sentido ofrecer traducir una página a su propio idioma'
);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

$cleanup();
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkWrite(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary]
        && (int) Database::selectOne(
            "SELECT COUNT(*) c FROM pages WHERE site_id = ? AND slug LIKE 'qa9-%'",
            [$siteId]
        )['c'] === 0,
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
