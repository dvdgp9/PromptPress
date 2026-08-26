<?php

declare(strict_types=1);

/**
 * MODULOS M2 — El calendario de reservas dentro de las páginas del propio sitio.
 *
 * Cubre los dos caminos que tiene el gestor (ninguno pide copiar código):
 *   1. Sección "Calendario de reservas" del editor de páginas.
 *   2. Placeholder {{booking:N}} de las páginas canvas (lo inserta el asistente).
 *
 * Y lo que tiene que pasar cuando algo no cuadra: módulo apagado, sin servicios
 * activos o servicio elegido desactivado → NO se pinta un calendario roto.
 *
 * Crea su propio servicio de prueba y lo borra al final; deja el flag del módulo
 * como estaba.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\SectionController;
use App\Modules\Booking\BookingEmbedRenderer;
use App\Modules\Booking\ServiceStore;
use App\Modules\ModuleRegistry;
use App\Services\Canvas\CanvasService;
use App\Services\Renderer\SectionRenderer;
use App\Services\SectionSchemas;
use Core\Database;

$failed = 0;
function check_be(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_be('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$moduleWasOn = ModuleRegistry::isEnabled($siteId, 'booking');
ModuleRegistry::setEnabled($siteId, 'booking', true);

// Servicios propios de la prueba: uno activo y uno desactivado. Se les pone un
// nombre que ordena al final para no depender de los servicios reales del sitio.
$activeId = ServiceStore::create($siteId, ['name' => 'zzz Test embed activo', 'duration_min' => 30]);
$offId    = ServiceStore::create($siteId, ['name' => 'zzz Test embed apagado', 'duration_min' => 45]);
Database::execute('UPDATE booking_services SET active = 0 WHERE id = ?', [$offId]);

$cleanup = static function () use ($activeId, $offId, $siteId, $moduleWasOn): void {
    ServiceStore::delete($siteId, $activeId);
    ServiceStore::delete($siteId, $offId);
    ModuleRegistry::setEnabled($siteId, 'booking', $moduleWasOn);
};

// ---------------------------------------------------------------------------
// 1. Catálogo de servicios embebibles
// ---------------------------------------------------------------------------

$services = BookingEmbedRenderer::embeddableServices($siteId);
$ids = array_column($services, 'id');
check_be('embeddableServices incluye el activo', in_array($activeId, $ids, true));
check_be('embeddableServices excluye el desactivado', !in_array($offId, $ids, true));

// ---------------------------------------------------------------------------
// 2. Resolución del servicio
// ---------------------------------------------------------------------------

check_be('service_id vacío = el primero activo', BookingEmbedRenderer::resolveServiceId($siteId, '') === $ids[0]);
check_be('service_id 0 = el primero activo', BookingEmbedRenderer::resolveServiceId($siteId, 0) === $ids[0]);
check_be('service_id válido se respeta', BookingEmbedRenderer::resolveServiceId($siteId, $activeId) === $activeId);
// Elegiste un servicio y lo desactivaste: mejor que desaparezca el calendario
// que enseñar otro servicio distinto sin avisar.
check_be('servicio desactivado NO cae al primero', BookingEmbedRenderer::resolveServiceId($siteId, $offId) === null);
check_be('servicio inexistente NO cae al primero', BookingEmbedRenderer::resolveServiceId($siteId, 999999) === null);

// ---------------------------------------------------------------------------
// 3. HTML del embed
// ---------------------------------------------------------------------------

$html = BookingEmbedRenderer::render($siteId, ['service_id' => $activeId]);
check_be('el embed trae el contenedor que monta el widget', str_contains($html, 'data-pp-booking'), $html);
check_be('el embed apunta al servicio elegido', str_contains($html, 'data-service="' . $activeId . '"'), $html);
check_be('el embed carga el widget', str_contains($html, 'pp-booking-widget.js'), $html);
// El contenedor NO va vacío: sin esto la previsualización del editor (iframe sin
// scripts) solo enseñaba el aviso de "activa JavaScript".
check_be('el embed adelanta el nombre del servicio', str_contains($html, 'zzz Test embed activo'), $html);
check_be('el embed no pide clave de API en el propio sitio', !str_contains($html, 'data-key'), $html);

$days7 = BookingEmbedRenderer::render($siteId, ['service_id' => $activeId, 'days' => 7]);
check_be('days se respeta', str_contains($days7, 'data-days="7"'));
$daysWild = BookingEmbedRenderer::render($siteId, ['service_id' => $activeId, 'days' => 999]);
check_be('days se acota a 31', str_contains($daysWild, 'data-days="31"'));
$daysZero = BookingEmbedRenderer::render($siteId, ['service_id' => $activeId, 'days' => 0]);
check_be('days 0 cae al valor por defecto', str_contains($daysZero, 'data-days="' . BookingEmbedRenderer::DEFAULT_DAYS . '"'));

check_be('servicio desactivado no pinta embed', BookingEmbedRenderer::render($siteId, ['service_id' => $offId]) === '');

// El calendario habla el idioma de la PÁGINA, no el del servicio: un servicio
// creado en castellano dentro de una página francesa tiene que salir en francés.
$fr = BookingEmbedRenderer::render($siteId, ['service_id' => $activeId, 'lang' => 'fr']);
check_be('el embed viaja con el idioma de la página', str_contains($fr, 'data-lang="fr"'), $fr);
check_be('el aviso de carga sale en ese idioma', str_contains($fr, 'Chargement'), $fr);
check_be('idioma no soportado cae al del sitio',
    str_contains(BookingEmbedRenderer::render($siteId, ['service_id' => $activeId, 'lang' => 'marciano']),
                 'data-lang="' . \App\Services\LanguageService::codeFor($siteId) . '"'));

// Un servicio nuevo nace con el idioma del SITIO. La columna tiene DEFAULT 'es',
// así que sin fijarlo al crear, toda web francesa tenía servicios en castellano.
$langRow = Database::selectOne('SELECT language FROM booking_services WHERE id = ?', [$activeId]);
check_be('el servicio nace con el idioma del sitio',
    (string) ($langRow['language'] ?? '') === \App\Services\LanguageService::codeFor($siteId),
    json_encode($langRow));

// ---------------------------------------------------------------------------
// 4. La sección del editor de páginas
// ---------------------------------------------------------------------------

SectionRenderer::setSiteContext($siteId);
$section = [
    'id' => 0,
    'section_type' => 'booking',
    'content_json' => ['service_id' => (string) $activeId, 'heading' => 'Pide tu cita', 'description' => 'Cuando quieras.'],
    'style_json' => ['variant' => 'with-text'],
];
$out = SectionRenderer::render($section);
check_be('la sección pinta el calendario', str_contains($out, 'data-pp-booking'), $out);
check_be('la sección pinta el título', str_contains($out, 'Pide tu cita'), $out);
check_be('la sección respeta la variante', str_contains($out, 'pp-booking-section--v-with-text'), $out);

// Variante "con texto" sin nada que enseñar → cae a "calendario solo" en vez de
// dejar media rejilla vacía.
$noText = SectionRenderer::render([
    'id' => 0, 'section_type' => 'booking',
    'content_json' => ['service_id' => (string) $activeId],
    'style_json' => ['variant' => 'with-text'],
]);
check_be('variante con texto sin texto cae a default', str_contains($noText, 'pp-booking-section--v-default'), $noText);

// Sección apuntando a un servicio desactivado → no se pinta nada.
$broken = SectionRenderer::render([
    'id' => 0, 'section_type' => 'booking',
    'content_json' => ['service_id' => (string) $offId, 'heading' => 'Pide tu cita'],
    'style_json' => null,
]);
check_be('sección con servicio desactivado no pinta nada', $broken === '' || !str_contains($broken, 'data-pp-booking'), $broken);

// ---------------------------------------------------------------------------
// 5. El placeholder de las páginas canvas
// ---------------------------------------------------------------------------

$has = false;
$expanded = CanvasService::expandPlaceholders('<p>a</p>{{booking:' . $activeId . '}}<p>b</p>', $siteId, $has);
check_be('{{booking:N}} se expande', str_contains($expanded, 'data-service="' . $activeId . '"'), $expanded);
check_be('el embed va envuelto para el editor en vivo', str_contains($expanded, 'data-pp-placeholder="booking:' . $activeId . '"'), $expanded);

$auto = CanvasService::expandPlaceholders('{{booking:auto}}', $siteId, $has);
check_be('{{booking:auto}} coge el primero activo', str_contains($auto, 'data-service="' . $ids[0] . '"'), $auto);

$withDays = CanvasService::expandPlaceholders('{{booking:auto|days=7}}', $siteId, $has);
check_be('{{booking:auto|days=7}} respeta los días', str_contains($withDays, 'data-days="7"'), $withDays);

// Ida y vuelta del editor en vivo: el embed expandido tiene que volver a ser el
// placeholder al guardar, o editar la sección se lo comería.
$back = CanvasService::normalizeEditedSectionHtml($withDays);
check_be('el embed vuelve a ser placeholder al guardar', str_contains($back, '{{booking:auto|days=7}}'), $back);
// Mismo caso de products, que hasta ahora se BORRABA al editar la sección.
$prodBack = CanvasService::normalizeEditedSectionHtml(
    CanvasService::expandPlaceholders('{{products:featured|limit=4}}', $siteId, $has)
);
check_be('el embed de products también vuelve a ser placeholder', str_contains($prodBack, '{{products:featured|limit=4}}'), $prodBack);

$badRef = CanvasService::expandPlaceholders('{{booking:999999}}', $siteId, $has);
check_be('placeholder con servicio inexistente deja un comentario, no un hueco roto',
    str_contains($badRef, '<!-- pp:booking') && !str_contains($badRef, 'data-pp-booking'), $badRef);

check_be('modulesHint anuncia las reservas', str_contains(CanvasService::modulesHint($siteId), '{{booking:auto}}'));

// El bloque que inserta el botón "+ Calendario" del Studio: su id lleva un
// sufijo aleatorio, así que el nombre visible tiene que salir de `data-pp-label`
// o "Partes de esta página" mostraría "Booking 3 cf4f44f6".
$inserted = '<section data-pp-section="booking-' . $activeId . '-abcd1234"'
    . ' data-pp-label="Calendario: zzz Test embed activo" class="pp-canvas-booking-embed">'
    . '{{booking:' . $activeId . '}}</section>';
$parts = CanvasService::listSections($inserted);
check_be('la parte insertada se lista con su nombre legible',
    ($parts[0]['label'] ?? '') === 'Calendario: zzz Test embed activo',
    json_encode($parts, JSON_UNESCAPED_UNICODE));
// Y sin `data-pp-label` se sigue derivando del id, como el resto de secciones.
$plain = CanvasService::listSections('<section data-pp-section="cta-final"></section>');
check_be('sin data-pp-label el nombre se deriva del id', ($plain[0]['label'] ?? '') === 'Cta final', json_encode($plain));

// ---------------------------------------------------------------------------
// 6. El catálogo de secciones depende del módulo
// ---------------------------------------------------------------------------

check_be('el tipo booking está en el catálogo con el módulo activo',
    array_key_exists('booking', SectionSchemas::allForSite($siteId)));
check_be('el desplegable "Añadir sección" lo ofrece',
    array_key_exists('booking', SectionController::sectionTypesForView($siteId)));

$schema = SectionSchemas::allForView($siteId)['booking'] ?? null;
$serviceField = null;
foreach ((array) ($schema['fields'] ?? []) as $f) {
    if (($f['key'] ?? '') === 'service_id') $serviceField = $f;
}
check_be('el selector de servicio enumera los servicios reales del sitio',
    $serviceField !== null && array_key_exists((string) $activeId, (array) $serviceField['options']),
    json_encode($serviceField['options'] ?? null, JSON_UNESCAPED_UNICODE));
check_be('el selector no ofrece servicios desactivados',
    $serviceField !== null && !array_key_exists((string) $offId, (array) $serviceField['options']));
check_be('el selector deja elegir "automático"',
    $serviceField !== null && array_key_exists('0', (array) $serviceField['options']));

// ---------------------------------------------------------------------------
// 7. Con el módulo apagado no existe nada de esto
// ---------------------------------------------------------------------------

ModuleRegistry::setEnabled($siteId, 'booking', false);
check_be('módulo apagado: el embed no se pinta', BookingEmbedRenderer::render($siteId, ['service_id' => $activeId]) === '');
check_be('módulo apagado: la sección no se pinta', !str_contains(SectionRenderer::render($section), 'data-pp-booking'));
$offExpanded = CanvasService::expandPlaceholders('{{booking:auto}}', $siteId, $has);
check_be('módulo apagado: el placeholder deja un comentario invisible',
    str_contains($offExpanded, '<!-- pp:booking') && !str_contains($offExpanded, 'data-pp-booking'), $offExpanded);
check_be('módulo apagado: el tipo desaparece del catálogo',
    !array_key_exists('booking', SectionSchemas::allForSite($siteId)));
check_be('módulo apagado: el desplegable no lo ofrece',
    !array_key_exists('booking', SectionController::sectionTypesForView($siteId)));
check_be('módulo apagado: modulesHint lo prohíbe explícitamente',
    str_contains(CanvasService::modulesHint($siteId), 'NO uses placeholders {{booking:'));

// ---------------------------------------------------------------------------
// 8. Módulo ACTIVO pero sin servicios: tampoco se ofrece poner un calendario
//    (MODULOS M5). No hay nada que insertar, así que ofrecerlo es una vía
//    muerta: el criterio es el mismo en la pantalla de Reservas, en el
//    desplegable de secciones y en el botón "+ Calendario" del Studio.
// ---------------------------------------------------------------------------

ModuleRegistry::setEnabled($siteId, 'booking', true);

// Se desactivan TODOS los servicios activos del sitio (los de la prueba y los
// que ya hubiera) y se restauran justo después: así el caso se comprueba de
// verdad en cualquier sitio, no solo en uno recién instalado.
$wereActive = array_column(BookingEmbedRenderer::embeddableServices($siteId), 'id');
if ($wereActive !== []) {
    Database::execute(
        'UPDATE booking_services SET active = 0 WHERE id IN (' . implode(',', array_map('intval', $wereActive)) . ')'
    );
}
check_be('sin servicios activos: el tipo no está en el catálogo',
    !array_key_exists('booking', SectionSchemas::allForSite($siteId)));
check_be('sin servicios activos: el desplegable no lo ofrece',
    !array_key_exists('booking', SectionController::sectionTypesForView($siteId)));
check_be('sin servicios activos: modulesHint no anuncia reservas',
    str_contains(CanvasService::modulesHint($siteId), 'NO uses placeholders {{booking:'));
check_be('sin servicios activos: el embed no se pinta',
    BookingEmbedRenderer::render($siteId, ['service_id' => 0]) === '');

if ($wereActive !== []) {
    Database::execute(
        'UPDATE booking_services SET active = 1 WHERE id IN (' . implode(',', array_map('intval', $wereActive)) . ')'
    );
}
check_be('restaurados: el tipo vuelve al catálogo',
    array_key_exists('booking', SectionSchemas::allForSite($siteId)));
check_be('restaurados: los servicios activos son los mismos que antes',
    array_column(BookingEmbedRenderer::embeddableServices($siteId), 'id') === $wereActive,
    json_encode(['antes' => $wereActive, 'ahora' => array_column(BookingEmbedRenderer::embeddableServices($siteId), 'id')]));

$cleanup();
check_be('limpieza: servicios de prueba borrados',
    ServiceStore::find($siteId, $activeId) === null && ServiceStore::find($siteId, $offId) === null);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : ($failed . ' FAILED')) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
