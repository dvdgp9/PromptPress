<?php

declare(strict_types=1);

/**
 * RSV-UI — El calendario de reservas: cómo se ve y cómo se viste.
 *
 * Dos cosas que el usuario pidió el 06/09/2026 y que aquí se vigilan:
 *
 *  1. **Que se vea.** El widget enseñaba la agenda como una tira horizontal con
 *     scroll: con 14 o 31 días había que arrastrar a ciegas. Ahora es un
 *     calendario mensual y las horas van agrupadas por franja. Como el JS no se
 *     puede ejecutar aquí, se comprueba sobre el fichero: que estén las piezas
 *     del calendario y que NO haya vuelto la tira.
 *  2. **Que se pueda vestir.** El ancho (`card` / `wide` / `full`) tiene que
 *     llegar por los tres caminos —sección clásica, placeholder canvas y panel
 *     del Studio— y, sobre todo, SOBREVIVIR al guardado del editor en vivo: el
 *     HTML de dentro del embed se regenera en cada render, así que lo único que
 *     persiste es el propio placeholder.
 *
 * Crea su propio servicio de prueba y lo borra al final; deja el flag del
 * módulo como estaba.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\BookingEmbedRenderer;
use App\Modules\Booking\ServiceStore;
use App\Modules\ModuleRegistry;
use App\Services\Canvas\CanvasService;
use App\Services\DesignSystem;
use App\Services\Renderer\SectionRenderer;
use App\Services\SectionSchemas;

$failed = 0;
function check_rsv(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (\Core\Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rsv('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$moduleWasOn = ModuleRegistry::isEnabled($siteId, 'booking');
ModuleRegistry::setEnabled($siteId, 'booking', true);
$serviceId = ServiceStore::create($siteId, ['name' => 'zzz Test calendario ancho', 'duration_min' => 30]);
$cleanup = static function () use ($serviceId, $siteId, $moduleWasOn): void {
    ServiceStore::delete($siteId, $serviceId);
    ModuleRegistry::setEnabled($siteId, 'booking', $moduleWasOn);
};

// ---------------------------------------------------------------------------
// 1. El ancho se sanea antes de llegar al HTML
// ---------------------------------------------------------------------------

check_rsv('los tres anchos son los declarados',
    BookingEmbedRenderer::WIDTHS === ['card', 'wide', 'full'],
    implode(',', BookingEmbedRenderer::WIDTHS));
check_rsv('sin ancho, la tarjeta de siempre',
    BookingEmbedRenderer::normalizeWidth(null) === 'card');
check_rsv('un ancho inventado no pasa',
    BookingEmbedRenderer::normalizeWidth('marciano') === 'card');
check_rsv('mayúsculas y espacios se toleran',
    BookingEmbedRenderer::normalizeWidth('  FULL ') === 'full');
foreach (BookingEmbedRenderer::WIDTHS as $w) {
    check_rsv("ancho «{$w}» se respeta", BookingEmbedRenderer::normalizeWidth($w) === $w);
}

// ---------------------------------------------------------------------------
// 2. El embed lo lleva en la clase Y en el atributo
// ---------------------------------------------------------------------------
// La clase la necesita la vista SIN JavaScript (la previa del editor, que corre
// con `sandbox="allow-same-origin"`); el atributo lo lee el widget al montar.

$default = BookingEmbedRenderer::render($siteId, ['service_id' => $serviceId]);
check_rsv('por defecto sale la tarjeta',
    str_contains($default, 'pp-booking-embed--w-card') && str_contains($default, 'data-width="card"'),
    $default);

$full = BookingEmbedRenderer::render($siteId, ['service_id' => $serviceId, 'width' => 'full']);
check_rsv('ancho completo: clase para la previa sin JS',
    str_contains($full, 'pp-booking-embed--w-full'), $full);
check_rsv('ancho completo: atributo para el widget',
    str_contains($full, 'data-width="full"'), $full);

$wild = BookingEmbedRenderer::render($siteId, ['service_id' => $serviceId, 'width' => '100%']);
check_rsv('un ancho raro no ensucia el HTML',
    str_contains($wild, 'data-width="card"') && !str_contains($wild, '100%'), $wild);

// ---------------------------------------------------------------------------
// 3. El CSS público conoce los tres anchos
// ---------------------------------------------------------------------------

$css = DesignSystem::renderSectionBaseCss();
check_rsv('el CSS público trae el ancho intermedio', str_contains($css, '.pp-booking-embed--w-wide'));
check_rsv('el CSS público trae el ancho completo', str_contains($css, '.pp-booking-embed--w-full'));

// ---------------------------------------------------------------------------
// 4. La sección clásica: campo en el schema + clase en el HTML
// ---------------------------------------------------------------------------

$schema = SectionSchemas::allForView($siteId)['booking'] ?? null;
$widthField = null;
foreach ((array) ($schema['fields'] ?? []) as $f) {
    if (($f['key'] ?? '') === 'width') $widthField = $f;
}
check_rsv('el editor de secciones ofrece el ancho', $widthField !== null, json_encode($schema['fields'] ?? null, JSON_UNESCAPED_UNICODE));
check_rsv('con sus tres opciones',
    $widthField !== null && array_keys((array) $widthField['options']) === ['card', 'wide', 'full'],
    json_encode($widthField['options'] ?? null, JSON_UNESCAPED_UNICODE));
// Las etiquetas pasan por el catálogo de idiomas: si falta la clave, `__()`
// devuelve la propia clave y el desplegable enseñaría "section_schema.booking…".
check_rsv('las etiquetas del ancho están traducidas',
    $widthField !== null && !str_contains((string) $widthField['label'], 'section_schema.'),
    (string) ($widthField['label'] ?? ''));

SectionRenderer::setSiteContext($siteId);
$sectionFull = SectionRenderer::render([
    'id' => 0, 'section_type' => 'booking',
    'content_json' => ['service_id' => (string) $serviceId, 'width' => 'full'],
    'style_json' => null,
]);
check_rsv('la sección pinta el ancho elegido', str_contains($sectionFull, 'pp-booking-embed--w-full'), $sectionFull);
$sectionOld = SectionRenderer::render([
    'id' => 0, 'section_type' => 'booking',
    'content_json' => ['service_id' => (string) $serviceId],   // guardada antes de RSV-UI
    'style_json' => null,
]);
check_rsv('una sección vieja sigue saliendo como tarjeta',
    str_contains($sectionOld, 'pp-booking-embed--w-card'), $sectionOld);

// ---------------------------------------------------------------------------
// 5. El placeholder canvas: expansión y, sobre todo, ida y vuelta
// ---------------------------------------------------------------------------

$has = false;
$expanded = CanvasService::expandPlaceholders('{{booking:auto|width=full}}', $siteId, $has);
check_rsv('{{booking:auto|width=full}} pinta a todo ancho',
    str_contains($expanded, 'data-width="full"'), $expanded);

// Esto es lo que de verdad importa: el Studio guarda el DOM VIVO y el embed
// vuelve a ser texto. Si `width` no está en las opciones canónicas, el ancho se
// pierde en el primer guardado y el gestor ve cómo su cambio se deshace solo.
$back = CanvasService::normalizeEditedSectionHtml($expanded);
check_rsv('el ancho sobrevive al guardado del editor en vivo',
    str_contains($back, 'width=full'), $back);
check_rsv('y vuelve en orden canónico (days antes que width)',
    str_contains($back, '{{booking:auto|width=full}}')
    || str_contains($back, '{{booking:auto|days=7|width=full}}'), $back);

$both = CanvasService::normalizeEditedSectionHtml(
    CanvasService::expandPlaceholders('{{booking:auto|width=full|days=7}}', $siteId, $has)
);
check_rsv('las dos opciones juntas vuelven ordenadas',
    str_contains($both, '{{booking:auto|days=7|width=full}}'), $both);

check_rsv('el prompt de la IA explica el ancho',
    str_contains(CanvasService::modulesHint($siteId), 'width=full'),
    CanvasService::modulesHint($siteId));

// ---------------------------------------------------------------------------
// 6. El widget: calendario mensual, franjas horarias y ancho
// ---------------------------------------------------------------------------

$js = (string) file_get_contents(PP_ROOT . '/public/js/pp-booking-widget.js');
check_rsv('el widget lee el ancho del contenedor', str_contains($js, "data-width"), '');
check_rsv('y tiene hoja para los tres anchos',
    str_contains($js, '.ppbk.ppbk--w-wide') && str_contains($js, '.ppbk.ppbk--w-full'));
check_rsv('el mes se pinta como rejilla de 7 columnas',
    str_contains($js, 'ppbk-grid') && str_contains($js, 'repeat(7,1fr)'));
check_rsv('hay navegación de mes', str_contains($js, 'month_prev') && str_contains($js, 'month_next'));
check_rsv('las horas van por franjas',
    str_contains($js, 'part_morning') && str_contains($js, 'part_afternoon') && str_contains($js, 'part_evening'));
check_rsv('los tres pasos están rotulados',
    str_contains($js, 'pick_day') && str_contains($js, 'pick_time') && str_contains($js, 'pick_data'));
// La tira horizontal era el problema: si vuelve, este test lo canta.
check_rsv('ya no queda la tira horizontal de días', !str_contains($js, '.ppbk-days{'), '');
check_rsv('el primer día de la semana sale del idioma', str_contains($js, 'getWeekInfo'));
// El Studio cambia los días de agenda en caliente y necesita rehacer el widget.
check_rsv('el widget se deja remontar desde fuera', str_contains($js, 'window.ppBookingMount'));

// ---------------------------------------------------------------------------
// 7. El panel del Studio
// ---------------------------------------------------------------------------

$overlay = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
check_rsv('el overlay detecta el calendario de la sección',
    str_contains($overlay, 'bookingEmbedOf') && str_contains($overlay, 'p.booking = '), '');
check_rsv('el overlay reescribe el placeholder, no el HTML del embed',
    str_contains($overlay, "setBookingOpt") && str_contains($overlay, "data-pp-placeholder', head + '|days="), '');
check_rsv('el overlay entiende las dos ops nuevas',
    str_contains($overlay, "msg.op === 'bookingwidth'") && str_contains($overlay, "msg.op === 'bookingdays'"), '');

$studio = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');
check_rsv('el panel pinta los controles del calendario',
    str_contains($studio, "seg('bookingwidth', 'full'") && str_contains($studio, "seg('bookingdays', '31'"), '');
check_rsv('y los marca como grupo de una sola opción',
    str_contains($studio, 'bookingwidth: 1') && str_contains($studio, 'bookingdays: 1'), '');

// ---------------------------------------------------------------------------
// 8. Cambiar el servicio de un calendario ya insertado
// ---------------------------------------------------------------------------
// El servicio no es una opción: es la propia referencia del placeholder
// (`booking:auto` / `booking:228`). Cambiarlo tiene que conservar ancho y días,
// o cambiar de calendario desharía de paso cómo estaba colocado.

check_rsv('el overlay sabe QUÉ servicio pinta cada calendario',
    str_contains($overlay, "out = { days: '14', width: 'card', service:"), '');
check_rsv('el overlay puede cambiar el servicio conservando lo demás',
    str_contains($overlay, 'function setBookingService(embed, ref)')
    && str_contains($overlay, "'booking:' + ref + '|days=' + opts.days + '|width=' + opts.width"), '');
check_rsv('el overlay entiende la op de cambio de servicio',
    str_contains($overlay, "msg.op === 'bookingservice'"), '');
// Al cambiar de servicio hay que remontar: el nombre y la duración que enseña
// el widget vienen del servidor y son los del servicio anterior.
check_rsv('y remonta el widget para que cambie el nombre',
    str_contains($overlay, 'window.ppBookingMount(svcBox)'), '');
check_rsv('y renombra la parte en la lista',
    str_contains($overlay, "svcSec.setAttribute('data-pp-label', msg.value.label)"), '');

check_rsv('el panel ofrece el desplegable de servicio',
    str_contains($studio, "id=\"ep-booking-service\"") && str_contains($studio, 'PP_BOOKING_SERVICES'), '');
check_rsv('el panel resuelve «automático» antes de mandarlo',
    str_contains($studio, "ref === 'auto' ? list[0]") && str_contains($studio, "applyOp('bookingservice'"), '');
check_rsv('el panel pone al día el nombre de la parte sin recargar',
    str_contains($studio, 'function renameSection(sectionId, label)')
    && str_contains($studio, 'renameSection(panelState.sectionId, label)'), '');

$studioView = (string) file_get_contents(PP_ROOT . '/views/admin/canvas/studio.php');
check_rsv('la vista del Studio sirve los servicios al panel',
    str_contains($studioView, 'window.PP_BOOKING_SERVICES'), '');

// Las etiquetas del desplegable pasan por el catálogo del navegador (`js.`),
// que es otro fichero que el de PHP: si faltan, el panel enseña la clave.
foreach (['js.cv.booking_service', 'js.cv.booking_auto', 'js.cv.booking_section_label'] as $key) {
    check_rsv("la clave {$key} está traducida", __($key) !== $key, __($key));
}
// El nombre de la parte lo escriben dos sitios (PHP al insertar, JS al cambiar
// de servicio) y tienen que decir lo mismo.
check_rsv('el nombre de la parte es el mismo desde PHP que desde el panel',
    __('js.cv.booking_section_label') === __('cv.booking.section_label'),
    __('js.cv.booking_section_label') . ' vs ' . __('cv.booking.section_label'));

// ---------------------------------------------------------------------------

$cleanup();
echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
