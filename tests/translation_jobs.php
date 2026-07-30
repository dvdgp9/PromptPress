<?php

declare(strict_types=1);

/**
 * I18N-FULL T5.5 — Traducción del sitio por pasos.
 *
 * Cubre la selección de páginas, el ciclo de vida del trabajo y —lo que más
 * importa— que las páginas ya traducidas se SALTEN en vez de duplicarse.
 *
 * No llama a la IA: el paso que traduce de verdad se verifica aparte con una
 * ejecución real. Aquí se prueba la orquestación, que es donde está el riesgo
 * de dejar el trabajo colgado o duplicar contenido.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\LanguageService;
use App\Services\TranslationJobs;
use Core\Database;

$failed = 0;
function checkJob(string $name, bool $ok, string $detail = ''): void
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
    Database::execute("DELETE FROM translation_jobs WHERE site_id = ?", [$siteId]);
    $ids = array_column(Database::select(
        "SELECT id FROM pages WHERE site_id = ? AND (slug LIKE 'qa12-%' OR slug LIKE 'fr/qa12-%')",
        [$siteId]
    ), 'id');
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        Database::execute("DELETE FROM page_sections WHERE page_id IN ($in)");
        Database::execute("DELETE FROM pages WHERE id IN ($in)");
    }
};
$cleanup();

$primary = LanguageService::primaryFor($siteId);
LanguageService::enable($siteId, 'fr');
LanguageService::forget($siteId);

// Escenario: 2 páginas sin traducir + 1 ya traducida + 1 que ES una traducción.
$mk = function (string $title, string $slug, string $lang, string $group) use ($siteId): int {
    Database::execute(
        "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode, created_at, updated_at)
         VALUES (?, ?, ?, 'landing', ?, ?, 'published', 'sections', NOW(), NOW())",
        [$siteId, $title, $slug, $lang, $group]
    );
    return (int) Database::lastInsertId();
};

$a = $mk('QA12 Uno', 'qa12-uno', $primary, 'qa12-g1');
$b = $mk('QA12 Dos', 'qa12-dos', $primary, 'qa12-g2');
$c = $mk('QA12 Tres', 'qa12-tres', $primary, 'qa12-g3');
$cFr = $mk('QA12 Trois', 'fr/qa12-trois', 'fr', 'qa12-g3'); // ya traducida

// ---------------------------------------------------------------------------
// 1. Selección de candidatas
// ---------------------------------------------------------------------------

// Acotado a las páginas del test: el sitio de desarrollo tiene decenas de
// páginas reales y, sin esto, el trabajo las traduciría TODAS de verdad.
$scope = [$a, $b, $c, $cFr];
$candidates = array_column(TranslationJobs::candidates($siteId, 'fr', $scope), 'id');

checkJob(
    'candidates_include_untranslated_pages',
    in_array($a, $candidates, true) && in_array($b, $candidates, true),
    implode(',', $candidates)
);

checkJob(
    'candidates_exclude_already_translated',
    !in_array($c, $candidates, true),
    'la página con versión francesa no debe volver a traducirse'
);

checkJob(
    'candidates_exclude_translations_themselves',
    !in_array($cFr, $candidates, true),
    'una traducción no se traduce a su vez'
);

// ---------------------------------------------------------------------------
// 2. Creación del trabajo
// ---------------------------------------------------------------------------

$created = TranslationJobs::createJob($siteId, 'fr', null, $scope);
checkJob('job_is_created', ($created['ok'] ?? false) === true, json_encode($created, JSON_UNESCAPED_UNICODE));
$jobId = (int) ($created['job_id'] ?? 0);

$state = TranslationJobs::jobState($jobId, $siteId);
checkJob(
    'job_only_contains_pending_pages',
    (int) ($state['total'] ?? 0) === count($candidates)
        && ($state['counts']['pending'] ?? 0) === count($candidates),
    json_encode($state['counts'] ?? [], JSON_UNESCAPED_UNICODE)
);

checkJob(
    'job_knows_its_target_language',
    ($state['target_lang'] ?? '') === 'fr' && ($state['language'] ?? '') === 'Français',
    json_encode(['l' => $state['language'] ?? ''], JSON_UNESCAPED_UNICODE)
);

// Idiomas inválidos: se rechaza sin crear nada.
$before = (int) Database::selectOne('SELECT COUNT(*) c FROM translation_jobs WHERE site_id = ?', [$siteId])['c'];
$badLang = TranslationJobs::createJob($siteId, 'pt', null, $scope);
$sameLang = TranslationJobs::createJob($siteId, $primary, null, $scope);
$after = (int) Database::selectOne('SELECT COUNT(*) c FROM translation_jobs WHERE site_id = ?', [$siteId])['c'];
checkJob(
    'invalid_language_creates_no_job',
    ($badLang['ok'] ?? true) === false && ($sameLang['ok'] ?? true) === false && $before === $after,
    json_encode([$badLang, $sameLang], JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------------------
// 3. Ciclo de vida: una página que se traduce entre medias se SALTA
// ---------------------------------------------------------------------------

// Simulamos que alguien tradujo «QA12 Uno» a mano mientras el trabajo esperaba.
$mk('QA12 Un', 'fr/qa12-un', 'fr', 'qa12-g1');

$step = TranslationJobs::stepJob($jobId, $siteId);
$state = $step['job'] ?? [];
$itemA = null;
foreach (($state['items'] ?? []) as $item) {
    if ((int) $item['page_id'] === $a) {
        $itemA = $item;
    }
}
checkJob(
    'page_translated_meanwhile_is_skipped_not_duplicated',
    ($itemA['status'] ?? '') === 'skipped',
    json_encode($itemA, JSON_UNESCAPED_UNICODE)
);

$copies = (int) Database::selectOne(
    "SELECT COUNT(*) c FROM pages WHERE site_id = ? AND translation_group = 'qa12-g1' AND language = 'fr'",
    [$siteId]
)['c'];
checkJob('no_duplicate_page_was_created', $copies === 1, 'copias en francés: ' . $copies);

// El trabajo sigue vivo: quedaba otra página pendiente.
checkJob(
    'job_continues_after_a_skipped_item',
    ($state['status'] ?? '') !== 'done' && ($state['counts']['pending'] ?? 0) >= 1,
    json_encode($state['counts'] ?? [], JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------------------
// 4. Un fallo no detiene el trabajo
// ---------------------------------------------------------------------------

// Se borra la página pendiente para forzar un fallo controlado en su paso.
Database::execute('DELETE FROM pages WHERE id = ?', [$b]);
$step = TranslationJobs::stepJob($jobId, $siteId);
$state = $step['job'] ?? [];

checkJob(
    'a_failing_item_is_marked_and_the_job_finishes',
    ($state['counts']['failed'] ?? 0) === 1 && ($state['status'] ?? '') === 'done',
    json_encode($state['counts'] ?? [], JSON_UNESCAPED_UNICODE)
);

$failedItem = null;
foreach (($state['items'] ?? []) as $item) {
    if ($item['status'] === 'failed') {
        $failedItem = $item;
    }
}
checkJob(
    'failure_message_is_human_readable',
    isset($failedItem['error'])
        && !preg_match('/exception|null|stack|SQL/i', (string) $failedItem['error']),
    (string) ($failedItem['error'] ?? '')
);

// Un `step` sobre un trabajo terminado no rompe ni reabre nada.
$again = TranslationJobs::stepJob($jobId, $siteId);
checkJob(
    'stepping_a_finished_job_is_harmless',
    ($again['ok'] ?? false) === true && (($again['job']['status'] ?? '') === 'done'),
    json_encode($again['job']['counts'] ?? [], JSON_UNESCAPED_UNICODE)
);

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

$cleanup();
LanguageService::disable($siteId, 'fr');
LanguageService::forget($siteId);

checkJob(
    'test_leaves_no_traces',
    LanguageService::activeFor($siteId) === [$primary]
        && (int) Database::selectOne('SELECT COUNT(*) c FROM translation_jobs WHERE site_id = ?', [$siteId])['c'] === 0,
    'quedan restos'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
