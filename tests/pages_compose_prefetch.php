<?php

declare(strict_types=1);

// STUDIO-UX F9 — Componer mientras el usuario lee el plan.
//
// El flujo de creación era estrictamente secuencial: se prepara el plan, el
// usuario lee objetivo/público/SEO/CTA/estructura (medio minuto largo), pulsa
// «Crear página completa» y ENTONCES arranca la composición: 25,9 s de media y
// 47,8 s en el peor caso medidos en `ai_logs`.
//
// A diferencia del prefetch del onboarding, aquí la precomposición NO crea la
// página: guarda html/css y solo se persiste al confirmar. Así cambiar de idea
// (o cerrar la pestaña) no deja páginas fantasma en el listado.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use Core\Database;

$failed = 0;
function prefetchCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
prefetchCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

// --- La firma: qué hace que una precomposición siga sirviendo ---------------
$briefA = ['title' => 'Servicios', 'page_type' => 'landing', 'goal' => 'Captar alumnos', 'audience' => 'Docentes'];
$briefB = $briefA;
$briefB['goal'] = 'Otro objetivo';

$sigA = PageController::compositionSignature($briefA, '');
$sigB = PageController::compositionSignature($briefB, '');
prefetchCheck('la firma es estable para el mismo plan',
    $sigA === PageController::compositionSignature($briefA, '') && $sigA !== '');
prefetchCheck('cambiar el plan cambia la firma', $sigA !== $sigB);
prefetchCheck('cambiar de modelo cambia la firma',
    $sigA !== PageController::compositionSignature($briefA, 'google/otro-modelo'));

// --- Guardar y recuperar sin tocar `pages` ----------------------------------
$html = '<section data-pp-section="hero"><h1>Precompuesto</h1></section>';
$css = '.demo{color:red}';
$pagesBefore = (int) Database::selectOne('SELECT COUNT(*) n FROM pages WHERE site_id = ?', [$siteId])['n'];

PageController::storeComposition($siteId, $sigA, $html, $css, ['title' => 'Servicios', 'page_type' => 'landing']);
$pagesAfterStore = (int) Database::selectOne('SELECT COUNT(*) n FROM pages WHERE site_id = ?', [$siteId])['n'];
prefetchCheck('precomponer NO crea ninguna página', $pagesAfterStore === $pagesBefore,
    "antes {$pagesBefore}, después {$pagesAfterStore}");

$stored = PageController::readComposition($siteId, $sigA);
prefetchCheck('la precomposición se recupera entera',
    is_array($stored) && ($stored['html'] ?? '') === $html && ($stored['css'] ?? '') === $css,
    var_export($stored, true));

prefetchCheck('una firma distinta no devuelve la precomposición ajena',
    PageController::readComposition($siteId, $sigB) === null);

// --- Persistir la precomposición: una página, sin llamar a la IA ------------
$aiBefore = (int) Database::selectOne('SELECT COUNT(*) n FROM ai_logs WHERE site_id = ?', [$siteId])['n'];
$created = PageController::persistComposition($siteId, $stored ?? [], 0);
$pageId = (int) ($created['id'] ?? 0);
prefetchCheck('persistir crea la página', $pageId > 0, var_export($created, true));

if ($pageId > 0) {
    $canvas = \App\Services\Canvas\CanvasService::get($pageId);
    prefetchCheck('la página nace con el HTML precompuesto',
        $canvas !== null && str_contains((string) $canvas['html'], 'Precompuesto'),
        (string) ($canvas['html'] ?? ''));
    prefetchCheck('y en modo canvas, como borrador',
        (string) (Database::selectOne('SELECT render_mode FROM pages WHERE id = ?', [$pageId])['render_mode'] ?? '') === 'canvas'
        && (string) (Database::selectOne('SELECT status FROM pages WHERE id = ?', [$pageId])['status'] ?? '') === 'draft');

    $aiAfter = (int) Database::selectOne('SELECT COUNT(*) n FROM ai_logs WHERE site_id = ?', [$siteId])['n'];
    prefetchCheck('reutilizar la precomposición no gasta ni una llamada a la IA',
        $aiAfter === $aiBefore, "antes {$aiBefore}, después {$aiAfter}");

    Database::execute('DELETE FROM page_versions WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

// --- Consumir la precomposición la retira ------------------------------------
prefetchCheck('al persistirla, la precomposición deja de estar disponible',
    PageController::readComposition($siteId, $sigA) === null);

// --- Limpieza defensiva ------------------------------------------------------
PageController::storeComposition($siteId, $sigA, $html, $css, ['title' => 'Servicios', 'page_type' => 'landing']);
PageController::clearComposition($siteId);
prefetchCheck('se puede descartar explícitamente', PageController::readComposition($siteId, $sigA) === null);

// --- Contratos ---------------------------------------------------------------
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/PageController.php');
$onboarding = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/OnboardingController.php');
$routes = (string) file_get_contents(PP_ROOT . '/app/routes.php');
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/page-studio.js');

prefetchCheck('hay ruta para precomponer', str_contains($routes, '/pages/ai-prepare'));
prefetchCheck('componer y persistir están separados',
    str_contains($onboarding, 'compose_only') && str_contains($onboarding, 'function persistCanvasComposition'));
prefetchCheck('la creación reutiliza la precomposición si encaja',
    str_contains($controller, 'readComposition(') && str_contains($controller, 'persistComposition('));
prefetchCheck('el front precompone al pintar el plan',
    str_contains($js, 'ai-prepare') && str_contains($js, 'prefetch'));
prefetchCheck('cambiar el plan descarta lo precompuesto',
    str_contains($controller, 'clearComposition(') && str_contains($js, "prefetchedBrief = ''"));
// La guarda NO puede ser la promesa: al resolverse la primera precomposición,
// ninguna otra volvería a dispararse en toda la sesión (visto en navegador).
prefetchCheck('la promesa se libera al terminar, y la guarda es el plan',
    str_contains($js, 'prefetchPromise = null;') && str_contains($js, 'payload === prefetchedBrief'));
// Sin esto, pulsar "Crear" mientras aún compone lanzaría una SEGUNDA generación
// en paralelo: el doble de coste y una carrera por quién guarda.
prefetchCheck('pulsar Crear mientras compone espera, no genera otra vez',
    str_contains($js, 'var ready = prefetchPromise') && str_contains($js, 'function requestCreate('));
prefetchCheck('la precomposición es de un solo uso',
    str_contains($controller, 'self::clearComposition($siteId);'));
prefetchCheck('los tipos no-canvas no precomponen',
    str_contains($controller, 'isCanvasMarketingType($pageType)'));

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
