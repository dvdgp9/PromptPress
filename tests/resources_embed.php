<?php

declare(strict_types=1);

/** FEAT-RESOURCES R6 — bloque dinámico de recursos para Canvas Studio. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceStore;
use App\Services\CacheService;
use App\Services\Canvas\CanvasService;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_re(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_re('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$lang = LanguageService::primaryFor($siteId);
$created = [];
$key = ModuleRegistry::settingKey('resources');
$original = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $key]);
$frWasActive = LanguageService::isActive($siteId, 'fr');
$base = [
    'description' => 'Una guía útil para probar el bloque.',
    'category' => 'Guías',
    'file_path' => 'storage/resources/' . $siteId . '/r6-test.pdf',
    'original_filename' => 'r6-test.pdf',
    'file_mime' => 'application/pdf',
    'file_size' => 2048,
    'access_mode' => 'direct',
    'language' => $lang,
    'status' => 'published',
];

try {
    ModuleRegistry::setEnabled($siteId, 'resources', false);
    $hasForm = false;
    $hasResources = false;
    $off = CanvasService::expandPlaceholders('{{resources:featured|limit=2}}', $siteId, $hasForm, $lang, $hasResources);
    check_re('módulo apagado no renderiza tarjetas', !str_contains($off, 'pp-featured-resources') && !$hasResources, $off);

    ModuleRegistry::setEnabled($siteId, 'resources', true);
    for ($i = 1; $i <= 4; $i++) {
        $created[] = ResourceStore::create($siteId, $base + ['title' => 'Recurso R6 ' . $i]);
    }
    $created[] = ResourceStore::create($siteId, array_merge($base, ['title' => 'Borrador R6', 'status' => 'draft']));

    $hasResources = false;
    $out = CanvasService::expandPlaceholders(
        '{{ resources: featured | heading = Selección útil | limit = 2 }}',
        $siteId,
        $hasForm,
        $lang,
        $hasResources
    );
    check_re('renderiza bloque y marca stylesheet necesario', $hasResources && str_contains($out, 'pp-featured-resources'), $out);
    check_re('respeta límite y excluye borradores', substr_count($out, 'pp-featured-resources__card') === 2 && !str_contains($out, 'Borrador R6'), $out);
    check_re('conserva heading y referencia canónica', str_contains($out, 'Selección útil') && str_contains($out, 'data-pp-placeholder="resources:featured|limit=2|heading=Selección útil"'), $out);
    check_re('tarjetas enlazan a fichas reales', str_contains($out, '/recursos/recurso-r6-'), $out);

    $normalized = CanvasService::normalizeEditedSectionHtml('<section data-pp-section="r"><div class="pp-canvas-embed" data-pp-placeholder="resources:featured|limit=2|heading=Selección útil"><div>runtime</div></div></section>');
    check_re('edición Studio revierte al placeholder', str_contains($normalized, '{{resources:featured|limit=2|heading=Selección útil}}'), $normalized);

    $hint = CanvasService::modulesHint($siteId);
    check_re('IA recibe el placeholder de recursos cuando está disponible', str_contains($hint, '{{resources:featured'), $hint);
    ModuleRegistry::setEnabled($siteId, 'resources', false);
    check_re('IA recibe negativa explícita con módulo apagado', str_contains(CanvasService::modulesHint($siteId), 'NO uses placeholders {{resources:'), CanvasService::modulesHint($siteId));

    $controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
    $view = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
    $js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
    $routes = (string) file_get_contents(PP_ROOT . '/app/routes.php');
    check_re('Studio filtra recursos publicables', str_contains($controller, 'resourcesForStudio'));
    check_re('Studio ofrece selector de cantidad condicionado', str_contains($view, 'data-resources-limit') && str_contains($view, '!empty($publishedResources)'));
    check_re('Studio conecta inserción completa', str_contains($routes, 'insert-resources') && str_contains($js, 'insertResourcesUrl'));
    check_re(
        'Studio no guarda textos del idioma del panel dentro del bloque',
        str_contains($controller, "\$placeholder = '{{resources:featured|limit=' . \$limit . '}}';")
            && !str_contains($controller, "\$heading = __('cv.resources.default_heading')")
    );
    check_re(
        'preview usa explícitamente el idioma de la página',
        str_contains($controller, 'CanvasService::renderDraft($pageId, $siteId, $pageLang)')
            && str_contains($controller, "e(\$pageLang) . '\"><head>'")
    );

    if (!$frWasActive) LanguageService::enable($siteId, 'fr');
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    $created[] = ResourceStore::create($siteId, array_merge($base, [
        'title' => 'Guide français R9',
        'language_scope' => 'all',
    ]));
    $frHasResources = false;
    $frOut = CanvasService::expandPlaceholders(
        '{{resources:featured|limit=1}}',
        $siteId,
        $hasForm,
        'fr',
        $frHasResources
    );
    check_re(
        'bloque dinámico habla el idioma de la página',
        $frHasResources
            && str_contains($frOut, '>Ressources<')
            && str_contains($frOut, 'Voir la ressource')
            && !str_contains($frOut, 'Ver recurso'),
        $frOut
    );
    $legacyFr = CanvasService::expandPlaceholders(
        '{{resources:featured|limit=1|heading=Recursos destacados}}',
        $siteId,
        $hasForm,
        'fr',
        $frHasResources
    );
    check_re(
        'bloques antiguos corrigen su heading automático al idioma de página',
        str_contains($legacyFr, '>Ressources<')
            && !str_contains($legacyFr, '>Recursos destacados<'),
        $legacyFr
    );
    $customFr = CanvasService::expandPlaceholders(
        '{{resources:featured|limit=1|heading=Mes guides favoris}}',
        $siteId,
        $hasForm,
        'fr',
        $frHasResources
    );
    check_re(
        'un heading personalizado por el usuario se conserva',
        str_contains($customFr, '>Mes guides favoris<'),
        $customFr
    );
    $contextualFr = CanvasService::expandPlaceholders(
        '<section data-pp-section="resources-context"><h2>Ressources pour votre bien-être</h2>'
            . '<p>Des guides pratiques pour avancer à votre rythme.</p>'
            . '{{resources:featured|limit=1}}</section>',
        $siteId,
        $hasForm,
        'fr',
        $frHasResources
    );
    check_re(
        'evita repetir Recursos si la sección ya tiene título',
        substr_count($contextualFr, '<h2') === 1
            && str_contains($contextualFr, 'Ressources pour votre bien-être')
            && !str_contains($contextualFr, 'pp-featured-resources__head'),
        $contextualFr
    );
    check_re(
        'bloque independiente conserva un heading accesible',
        str_contains($frOut, 'pp-featured-resources__head')
            && str_contains($frOut, '<h2>Ressources</h2>'),
        $frOut
    );
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    CacheService::put($siteId, 'r6-resource-cache', 'stale', 300);
    ResourceStore::update($siteId, $created[0], ['title' => 'Recurso R6 actualizado']);
    check_re('update invalida caché de páginas', CacheService::get($siteId, 'r6-resource-cache') === null);
} finally {
    foreach ($created as $id) ResourceStore::delete($siteId, $id);
    if ($original === null) {
        Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
    } else {
        ModuleRegistry::setEnabled($siteId, 'resources', (string) $original['setting_value'] === '1');
    }
    if (!$frWasActive) {
        LanguageService::forget($siteId);
        LanguageService::disable($siteId, 'fr');
        LanguageService::forget($siteId);
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
