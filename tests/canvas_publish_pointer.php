<?php

declare(strict_types=1);

// STUDIO-UX C4 — Borrador contra publicado.
//
// Antes `page_canvas` era a la vez el estado de trabajo y lo que servía el
// público: cada retoque de una página publicada salía al aire al instante,
// mientras el chip verde «Publicada» sugería lo contrario. Ahora el público lee
// `published_version_id` y el Studio sigue editando el estado de trabajo.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function pubCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
pubCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'studio-c4-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Studio C4', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();

$page = static fn(string $t): string => '<section data-pp-section="hero"><h1>' . $t . '</h1></section>';
$publishedPointer = static fn(int $id): int => (int) (Database::selectOne(
    'SELECT published_version_id FROM page_canvas WHERE page_id = ?', [$id]
)['published_version_id'] ?? 0);

try {
    // --- Sin puntero publicado: compatibilidad con lo que ya existe ----------
    CanvasService::save($pageId, $page('Uno'), '', 'generate', 'Generación inicial');
    pubCheck('un borrador nace sin versión publicada', $publishedPointer($pageId) === 0);
    pubCheck(
        'sin puntero publicado el render público cae al estado de trabajo',
        str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Uno'),
        CanvasService::renderPublic($pageId, $siteId)['html']
    );
    pubCheck('no hay cambios sin publicar en un borrador recién creado',
        CanvasService::hasUnpublishedChanges($pageId) === false);

    // --- Publicar fija el puntero -------------------------------------------
    CanvasService::markPublished($pageId);
    $firstPublished = $publishedPointer($pageId);
    pubCheck('publicar apunta a la versión actual', $firstPublished > 0);
    pubCheck('recién publicado no hay nada pendiente',
        CanvasService::hasUnpublishedChanges($pageId) === false);

    // --- Editar NO cambia lo que ve el público ------------------------------
    CanvasService::save($pageId, $page('Dos, en borrador'), '', 'inline', 'Hero — Edición directa');
    pubCheck(
        'el público sigue viendo la versión publicada',
        str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Uno')
            && !str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Dos'),
        CanvasService::renderPublic($pageId, $siteId)['html']
    );
    pubCheck(
        'el Studio sí ve el estado de trabajo',
        str_contains(CanvasService::renderDraft($pageId, $siteId)['html'], 'Dos, en borrador'),
        CanvasService::renderDraft($pageId, $siteId)['html']
    );
    pubCheck('y se avisa de que hay cambios sin publicar',
        CanvasService::hasUnpublishedChanges($pageId) === true);
    pubCheck('el estado de historial lo publica para la UI',
        (CanvasService::historyState($pageId)['has_unpublished'] ?? null) === true);

    // --- Deshacer tampoco toca lo publicado ---------------------------------
    CanvasService::undo($pageId);
    pubCheck(
        'deshacer no cambia lo que ve el público',
        str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Uno'),
        CanvasService::renderPublic($pageId, $siteId)['html']
    );
    CanvasService::redo($pageId);

    // --- Publicar los cambios ----------------------------------------------
    CanvasService::markPublished($pageId);
    pubCheck('publicar cambios mueve el puntero', $publishedPointer($pageId) !== $firstPublished);
    pubCheck(
        'y ahora el público ve lo nuevo',
        str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Dos, en borrador'),
        CanvasService::renderPublic($pageId, $siteId)['html']
    );
    pubCheck('sin cambios pendientes tras publicar',
        CanvasService::hasUnpublishedChanges($pageId) === false);

    // --- Despublicar suelta el puntero -------------------------------------
    CanvasService::clearPublished($pageId);
    pubCheck('despublicar suelta la versión publicada', $publishedPointer($pageId) === 0);

    // --- El podado no puede llevarse la versión que está al aire ------------
    CanvasService::markPublished($pageId);
    $live = $publishedPointer($pageId);
    // Muchos cambios seguidos, cada uno con su resumen para que no se fusionen.
    for ($i = 0; $i < 40; $i++) {
        CanvasService::save($pageId, $page('Ruido ' . $i), '', 'inline', 'Sección ' . $i . ' — Edición directa');
    }
    $stillThere = Database::selectOne('SELECT id FROM page_versions WHERE id = ?', [$live]);
    pubCheck(
        'la versión publicada sobrevive al podado del historial',
        $stillThere !== null,
        'versión ' . $live . ' borrada tras 40 cambios'
    );
    pubCheck(
        'y el público sigue pudiendo renderizarse',
        str_contains(CanvasService::renderPublic($pageId, $siteId)['html'], 'Dos, en borrador'),
        CanvasService::renderPublic($pageId, $siteId)['html']
    );
} finally {
    Database::execute('DELETE FROM page_versions WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

// --- Quién renderiza qué -----------------------------------------------------
$publicCtrl = (string) file_get_contents(PP_ROOT . '/app/Controllers/Public/PageController.php');
$studioCtrl = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
$onboarding = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/OnboardingController.php');
$adminPages = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/PageController.php');

pubCheck('el sitio público renderiza la versión publicada',
    str_contains($publicCtrl, 'CanvasService::renderPublic('));
pubCheck('el preview del Studio renderiza el borrador',
    str_contains($studioCtrl, 'CanvasService::renderDraft(') && !str_contains($studioCtrl, 'CanvasService::renderPublic('));
pubCheck('el preview del onboarding renderiza el borrador',
    str_contains($onboarding, 'CanvasService::renderDraft('));
pubCheck('el preview del panel de páginas renderiza el borrador',
    str_contains($adminPages, 'CanvasService::renderDraft('));

$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
pubCheck('el Studio ofrece publicar los cambios pendientes',
    str_contains($js, 'has_unpublished') && str_contains($js, "js.cv.publish_changes"));

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['js.cv.publish_changes', 'js.cv.unpublished_changes'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    pubCheck('microcopia de publicación completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
