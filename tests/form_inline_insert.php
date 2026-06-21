<?php

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use App\Services\FormPlacementStore;
use App\Services\FormStore;
use Core\Database;

$failed = 0;
function formInlineCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
formInlineCheck('hay un sitio para la prueba', $siteId > 0);
if ($siteId === 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'forms-r-t3-' . time();
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, status, sort_order, created_at, updated_at)
     VALUES (?, ?, ?, 'landing', 'published', 0, ?, ?)",
    [$siteId, 'Test FORMS-R T3', $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();
$formId = FormStore::createFromTemplate($siteId, 'newsletter');

try {
    $base = '<section data-pp-section="hero"><h1>Hero</h1></section>'
        . '<section data-pp-section="cta"><h2>CTA</h2></section>';
    CanvasService::save($pageId, $base, '', 'test', 'Base T3');
    $embed = '<section data-pp-section="form-test">{{form:' . $formId . '}}</section>';
    $inserted = CanvasService::insertAfterSection($base, $embed, 'hero');
    CanvasService::save($pageId, $inserted, '', 'test', 'Insert T3');
    FormPlacementStore::record($formId, $pageId, 'Hero newsletter');

    $form = FormStore::find($siteId, $formId);
    formInlineCheck('crea el formulario desde plantilla', ($form['form_type'] ?? '') === 'newsletter');

    $heroPos = strpos($inserted, 'data-pp-section="hero"');
    $formPos = strpos($inserted, 'data-pp-section="form-test"');
    $ctaPos = strpos($inserted, 'data-pp-section="cta"');
    formInlineCheck('inserta tras la seccion seleccionada', $heroPos < $formPos && $formPos < $ctaPos);

    $placement = Database::selectOne(
        'SELECT source_label FROM form_placements WHERE form_id = ? AND page_id = ?',
        [$formId, $pageId]
    );
    formInlineCheck('registra placement y etiqueta de origen', ($placement['source_label'] ?? '') === 'Hero newsletter');

    $usage = FormPlacementStore::usageMap($siteId);
    formInlineCheck('usageMap cuenta la pagina publicada', ($usage[$formId] ?? 0) === 1);

    CanvasService::save($pageId, $base, '', 'test', 'Remove T3');
    $gone = Database::selectOne(
        'SELECT id FROM form_placements WHERE form_id = ? AND page_id = ?',
        [$formId, $pageId]
    );
    formInlineCheck('sincroniza al quitar el embed', $gone === null);
} finally {
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
    Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
}

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
