<?php

declare(strict_types=1);

/**
 * EMB-OPT — Los embeds de una página canvas, editables.
 *
 * Hasta ahora solo el calendario de reservas tenía ajustes; el formulario no
 * admitía ninguno (`canonicalPlaceholderRef()` cortaba con `form:N` a secas) y
 * entradas, productos y recursos tenían opciones escritas que nadie podía
 * tocar.
 *
 * Lo que se fija aquí es lo de siempre con los placeholders: que una opción
 * nueva SOBREVIVA al ida y vuelta del Studio. `normalizeEditedSectionHtml()`
 * reconstruye el embed desde `data-pp-placeholder`, así que una opción que no
 * esté dada de alta en las tres listas blancas desaparece sola y en silencio.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceStore;
use App\Services\Canvas\CanvasService;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_eo(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_eo('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

/** El ida y vuelta que hace el Studio al guardar una sección editada. */
function roundTrip(string $placeholder, int $siteId): string
{
    $has = false;
    return CanvasService::normalizeEditedSectionHtml(
        CanvasService::expandPlaceholders($placeholder, $siteId, $has)
    );
}

// ---------------------------------------------------------------------------
// EMB-1 — El formulario deja de ser un bloque mudo
// ---------------------------------------------------------------------------
$formId = (int) (Database::selectOne(
    "SELECT id FROM page_sections WHERE section_type = 'form' AND status != 'deleted' ORDER BY id DESC LIMIT 1"
)['id'] ?? 0);

if ($formId <= 0) {
    echo "SKIP: no hay formularios en la base de datos de desarrollo.\n";
} else {
    $ph = '{{form:' . $formId . '|width=wide|heading=Escríbenos|subheading=Te contestamos en 24 h}}';
    $back = roundTrip($ph, $siteId);
    check_eo('el formulario conserva sus opciones al guardar',
        str_contains($back, 'width=wide')
        && str_contains($back, 'heading=Escríbenos')
        && str_contains($back, 'subheading=Te contestamos en 24 h'), $back);

    $has = false;
    $out = CanvasService::expandPlaceholders($ph, $siteId, $has);
    check_eo('el título de la colocación manda sobre el del formulario',
        str_contains($out, 'Escríbenos') && str_contains($out, 'Te contestamos en 24 h'), $out);
    check_eo('el ancho llega como clase del envoltorio',
        str_contains($out, 'pp-canvas-embed--w-wide'), $out);
    // EMB-4 — sin esta marca, el Studio no deja editar el título a mano.
    check_eo('los títulos del formulario van marcados como editables',
        str_contains($out, 'data-pp-embed-field="heading"')
        && str_contains($out, 'data-pp-embed-field="subheading"'), $out);

    // Un formulario sin títulos de colocación tiene que seguir enseñando los suyos.
    $plain = CanvasService::expandPlaceholders('{{form:' . $formId . '}}', $siteId, $has);
    check_eo('sin títulos de colocación manda el formulario',
        !str_contains($plain, 'Escríbenos') && str_contains($plain, 'pp-form'), $plain);
    check_eo('un ancho inventado no ensucia el envoltorio',
        !str_contains(
            CanvasService::expandPlaceholders('{{form:' . $formId . '|width=gigante}}', $siteId, $has),
            'pp-canvas-embed--w-'
        ));
}

// ---------------------------------------------------------------------------
// EMB-3 — Recursos: cómo se ven y cuáles se enseñan.
// Se crean recursos propios (dos categorías) y se borran al final; el módulo se
// deja como estaba.
// ---------------------------------------------------------------------------
$lang = LanguageService::primaryFor($siteId);
$moduleWasOn = ModuleRegistry::isEnabled($siteId, 'resources');
$created = [];
$base = [
    'description' => 'Recurso de prueba de EMB-OPT.',
    'file_path' => 'storage/resources/' . $siteId . '/emb-opt.pdf',
    'original_filename' => 'emb-opt.pdf',
    'file_mime' => 'application/pdf',
    'file_size' => 1024,
    'access_mode' => 'direct',
    'language' => $lang,
    'status' => 'published',
];

try {
    ModuleRegistry::setEnabled($siteId, 'resources', true);
    $created[] = ResourceStore::create($siteId, $base + ['title' => 'EMB Guía 1', 'category' => 'Guías']);
    $created[] = ResourceStore::create($siteId, $base + ['title' => 'EMB Guía 2', 'category' => 'Guías']);
    $created[] = ResourceStore::create($siteId, $base + ['title' => 'EMB Plantilla', 'category' => 'Plantillas']);

    $ph = '{{resources:featured|limit=2|variant=list|category=Guías|subheading=Para empezar}}';
    $back = roundTrip($ph, $siteId);
    check_eo('recursos conserva variante, categoría y subtítulo',
        str_contains($back, 'variant=list')
        && str_contains($back, 'category=Guías')
        && str_contains($back, 'subheading=Para empezar'), $back);

    $has = false;
    $out = CanvasService::expandPlaceholders($ph, $siteId, $has, $lang);
    check_eo('la variante lista sale como clase', str_contains($out, 'pp-featured-resources--list'), $out);
    check_eo('el subtítulo se pinta', str_contains($out, 'Para empezar'), $out);
    // Lo importante del filtro: que EXCLUYA lo de otra categoría.
    check_eo('la categoría filtra de verdad',
        str_contains($out, 'EMB Guía') && !str_contains($out, 'EMB Plantilla'), $out);

    $otra = CanvasService::expandPlaceholders(
        '{{resources:featured|limit=6|category=Plantillas}}', $siteId, $has, $lang);
    check_eo('otra categoría enseña lo suyo y nada más',
        str_contains($otra, 'EMB Plantilla') && !str_contains($otra, 'EMB Guía'), $otra);

    // Una categoría sin recursos no puede dejar un encabezado colgando.
    $vacia = CanvasService::expandPlaceholders(
        '{{resources:featured|category=NoExisteEstaCategoria}}', $siteId, $has, $lang);
    check_eo('una categoría vacía no deja un bloque a medias',
        !str_contains($vacia, 'pp-featured-resources'), $vacia);

    // El orden canónico es el declarado en `$optionKeys`, no el que escribió nadie.
    $desordenado = roundTrip('{{resources:featured|subheading=B|limit=2|category=Guías|heading=A|variant=list}}', $siteId);
    check_eo('las opciones se guardan en orden canónico',
        str_contains($desordenado, 'resources:featured|limit=2|variant=list|heading=A|subheading=B|category=Guías'), $desordenado);
} finally {
    foreach ($created as $rid) {
        ResourceStore::delete($siteId, (int) $rid);
    }
    ModuleRegistry::setEnabled($siteId, 'resources', $moduleWasOn);
}

// ---------------------------------------------------------------------------
// Lo que NO puede colarse
// ---------------------------------------------------------------------------
$intruso = roundTrip('{{resources:featured|limit=2|onclick=alert(1)}}', $siteId);
check_eo('una opción que no está en la lista blanca se cae',
    !str_contains($intruso, 'onclick'), $intruso);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
