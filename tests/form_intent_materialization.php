<?php

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use App\Services\FormStore;
use Core\Database;

$failed = 0;
function intentCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$stamp = time() . '-' . random_int(1000, 9999);
Database::execute(
    'INSERT INTO sites (name, url, language, timezone) VALUES (?, ?, ?, ?)',
    ['QA Form Intent', 'https://qa-' . $stamp . '.test', 'es', 'Europe/Madrid']
);
$siteId = (int) Database::lastInsertId();
$pageIds = [];

try {
    foreach (['uno', 'dos'] as $suffix) {
        Database::execute(
            "INSERT INTO pages (site_id, title, slug, page_type, status) VALUES (?, ?, ?, 'landing', 'draft')",
            [$siteId, 'QA ' . $suffix, 'qa-' . $suffix . '-' . $stamp]
        );
        $pageIds[] = (int) Database::lastInsertId();
    }

    $html = '<section data-pp-section="contacto"><h2>Escribenos</h2>{{form:contact}}</section>';
    $first = CanvasService::save($pageIds[0], $html, '', 'test', 'Intent contact');
    preg_match('/\{\{form:(\d+)\}\}/', $first['html'], $match);
    $formId = (int) ($match[1] ?? 0);

    intentCheck('reescribe la intencion a ID', $formId > 0 && !str_contains($first['html'], '{{form:contact}}'));
    $form = FormStore::find($siteId, $formId);
    intentCheck('crea la plantilla tipada correcta', ($form['form_type'] ?? '') === 'contact');

    $second = CanvasService::save($pageIds[1], $html, '', 'test', 'Intent contact again');
    intentCheck('deduplica el mismo tipo entre paginas', str_contains($second['html'], '{{form:' . $formId . '}}'));

    $containerId = FormStore::containerPageId($siteId);
    $count = Database::selectOne(
        "SELECT COUNT(*) AS n FROM page_sections WHERE page_id = ? AND section_type = 'form' AND status != 'deleted'",
        [$containerId]
    );
    intentCheck('solo existe una definicion contact', (int) ($count['n'] ?? 0) === 1);

    $generator = new ReflectionClass(\App\Services\Canvas\CanvasGenerator::class);
    $availableForms = $generator->getMethod('availableForms')->invoke(null, $siteId);
    intentCheck('el prompt ofrece la intencion contact', str_contains($availableForms, '{{form:contact}}'));
    intentCheck('el prompt ofrece el ID ya materializado', str_contains($availableForms, '{{form:' . $formId . '}}'));

    $unknown = CanvasService::save(
        $pageIds[1],
        '<section data-pp-section="x">{{form:inventado}}</section>',
        '',
        'test',
        'Unknown intent'
    );
    intentCheck('no materializa tipos fuera del catalogo', str_contains($unknown['html'], '{{form:inventado}}'));
} finally {
    Database::execute('DELETE FROM sites WHERE id = ?', [$siteId]);
}

echo $failed === 0 ? "\nOK\n" : "\n$failed FALLOS\n";
exit($failed === 0 ? 0 : 1);
