<?php

// ADMIN-I18N Fase 0 — La maquinaria de traducción del panel.
//
// Este test se escribe ANTES de migrar una sola vista, y es el que impide que
// el panel multi-idioma se pudra: si alguien añade una cadena al castellano y
// no la traduce, aquí salta. Si alguien deja una clave huérfana en francés
// después de borrar la del castellano, también.
//
// No toca base de datos: la resolución de idioma se prueba por su función pura
// (`resolveFrom`), que es exactamente lo que llama `locale()` tras leer la BD.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AdminI18n;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  → ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

/** Extrae los placeholders `{x}` de un texto, ordenados y sin repetir. */
function placeholders(string $text): array
{
    preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
}

// ======================================================================
// 1. Catálogos: existen, son arrays planos y el castellano manda
// ======================================================================
check('castellano_es_el_idioma_fuente', AdminI18n::SOURCE === 'es');
check('locales_del_panel', AdminI18n::LOCALES === ['es', 'en', 'fr', 'pt'], implode(',', AdminI18n::LOCALES));

$catalogs = [];
foreach (AdminI18n::LOCALES as $locale) {
    $file = PP_ROOT . '/lang/admin/' . $locale . '.php';
    check("catalogo_existe_{$locale}", is_file($file), $file);
    $catalogs[$locale] = is_file($file) ? require $file : [];
    check("catalogo_es_array_{$locale}", is_array($catalogs[$locale]));

    // Plano: nada de arrays anidados. Las claves llevan el namespace dentro
    // ('onboarding.step2.title'), que es lo que hace `grep` útil.
    $nested = array_filter($catalogs[$locale], 'is_array');
    check("catalogo_plano_{$locale}", $nested === [], implode(',', array_keys($nested)));

    $nonString = array_filter($catalogs[$locale], fn($v) => !is_string($v));
    check("catalogo_solo_strings_{$locale}", $nonString === [], implode(',', array_keys($nonString)));
}

$source = $catalogs[AdminI18n::SOURCE] ?? [];
check('catalogo_fuente_no_vacio', $source !== [], 'lang/admin/es.php está vacío');

// El catálogo cargado por el servicio y el fichero tienen que coincidir: si no,
// el servicio está leyendo de otro sitio y todo lo demás de este test miente.
check('servicio_lee_el_mismo_catalogo', AdminI18n::catalog('es') === $source);

// ======================================================================
// 2. Sin huecos, sin huérfanas, sin placeholders descuadrados
// ======================================================================
foreach (AdminI18n::LOCALES as $locale) {
    if ($locale === AdminI18n::SOURCE) continue;

    $missing = array_diff(array_keys($source), array_keys($catalogs[$locale]));
    check("sin_huecos_{$locale}", $missing === [], count($missing) . ' sin traducir: ' . implode(', ', array_slice($missing, 0, 12)));

    $orphans = array_diff(array_keys($catalogs[$locale]), array_keys($source));
    check("sin_huerfanas_{$locale}", $orphans === [], count($orphans) . ' sobran: ' . implode(', ', array_slice($orphans, 0, 12)));

    // Un `{nombre}` que se pierde en la traducción es un texto roto en
    // producción, y es el error más fácil de cometer traduciendo con IA.
    $broken = [];
    foreach ($source as $key => $text) {
        if (!isset($catalogs[$locale][$key])) continue;
        $a = placeholders($text);
        $b = placeholders($catalogs[$locale][$key]);
        if ($a !== $b) {
            $broken[] = $key . ' (es: ' . implode('|', $a) . ' / ' . $locale . ': ' . implode('|', $b) . ')';
        }
    }
    check("placeholders_cuadran_{$locale}", $broken === [], implode('; ', array_slice($broken, 0, 8)));
}

// Las claves vacías no fallan visiblemente: pintan un hueco. Mejor cazarlas.
$empties = [];
foreach ($catalogs as $locale => $cat) {
    foreach ($cat as $key => $text) {
        if (trim($text) === '') $empties[] = "{$locale}:{$key}";
    }
}
check('sin_traducciones_vacias', $empties === [], implode(', ', array_slice($empties, 0, 10)));

// ======================================================================
// 3. `t()`: interpolación, fallback y clave desconocida
// ======================================================================
AdminI18n::setLocale('es');
$firstKey = array_key_first($source);
check('t_devuelve_el_castellano', AdminI18n::t($firstKey) === $source[$firstKey], $firstKey);

// Clave inexistente: devuelve la clave, NUNCA una excepción ni un hueco. Una
// traducción que falta no puede tumbar una pantalla del panel.
check('t_clave_desconocida_devuelve_la_clave', AdminI18n::t('no.existe.esta.clave') === 'no.existe.esta.clave');

// Interpolación con `{var}`.
check('t_interpola', AdminI18n::interpolate('Quedan {n} de {total}', ['n' => 3, 'total' => 10]) === 'Quedan 3 de 10');
check('t_interpola_sin_vars_deja_el_texto', AdminI18n::interpolate('Quedan {n}', []) === 'Quedan {n}');

// Fallback: un idioma del panel al que le falte una clave cae al castellano.
// Se simula con una clave que solo existe en la fuente.
AdminI18n::setLocale('fr');
AdminI18n::injectForTesting('es', ['test.solo.es' => 'Solo en castellano']);
check('t_cae_al_castellano_si_falta', AdminI18n::t('test.solo.es') === 'Solo en castellano');
AdminI18n::resetForTesting();

// ======================================================================
// 4. Resolución del idioma del panel
// ======================================================================
// Orden: preferencia del usuario → idioma principal del sitio → navegador → es.
check('resuelve_preferencia_del_usuario', AdminI18n::resolveFrom('en', 'fr', null) === 'en');
check('resuelve_idioma_del_sitio_si_el_usuario_no_tiene', AdminI18n::resolveFrom(null, 'fr', null) === 'fr');
check('resuelve_castellano_por_defecto', AdminI18n::resolveFrom(null, null, null) === 'es');

// Un sitio en catalán es legítimo, pero el panel aún no habla catalán: cae al
// castellano en vez de romper. Cuando exista `lang/admin/ca.php`, esto cambia
// solo añadiéndolo a LOCALES.
check('idioma_sin_catalogo_cae_a_castellano', AdminI18n::resolveFrom(null, 'ca', null) === 'es');
check('preferencia_de_usuario_sin_catalogo_cae_al_sitio', AdminI18n::resolveFrom('eu', 'pt', null) === 'pt');
check('basura_no_rompe', AdminI18n::resolveFrom('xx', 'zz', 'no-es-un-header') === 'es');

// Accept-Language: solo cuenta cuando no hay ni usuario ni sitio (el alta, antes
// de que el sitio tenga idioma).
check('acepta_el_navegador_si_no_hay_nada', AdminI18n::resolveFrom(null, null, 'fr-FR,fr;q=0.9,en;q=0.8') === 'fr');
check('navegador_con_idioma_sin_catalogo', AdminI18n::resolveFrom(null, null, 'ca-ES,ca;q=0.9,en;q=0.7') === 'en');
check('el_sitio_gana_al_navegador', AdminI18n::resolveFrom(null, 'pt', 'fr-FR,fr;q=0.9') === 'pt');

// ======================================================================
// 5. Puente con el JavaScript
// ======================================================================
// Al JS solo viajan dos formas de clave: el prefijo `js.` (cadenas que solo
// existen para el JS) y el sufijo `_js` (una cadena del panel que ADEMÁS
// necesita el JS, para no duplicar el texto). Mandar al navegador las 2.000
// cadenas del panel en cada carga no tiene sentido.
//
// El sufijo se añadió aquí porque faltaba en `jsCatalog()`: sus 17 claves se
// usaban con `pp.t()` y el panel pintaba el nombre de la clave en crudo
// ("nav.design_js") en el editor de secciones, el de header/pie y el studio.
AdminI18n::setLocale('es');
$isForJs = static fn (string $k): bool => str_starts_with($k, 'js.') || str_ends_with($k, '_js');
$js = AdminI18n::jsCatalog();
$colados = array_filter(array_keys($js), static fn($k) => !$isForJs($k));
check('jscatalog_solo_claves_para_js', $colados === [], implode(',', $colados));

$jsSource = array_filter(array_keys($source), $isForJs);
check('jscatalog_lleva_todas_las_js', count($js) === count($jsSource), count($js) . ' vs ' . count($jsSource));

// Y que no se cuele el catálogo entero por un sufijo demasiado laxo.
check('jscatalog_no_es_todo_el_catalogo', count($js) < count($source) / 2, count($js) . ' de ' . count($source));
check('jscatalog_serializa_a_json', json_encode($js, JSON_UNESCAPED_UNICODE) !== false);

// ======================================================================
// 6. El helper global
// ======================================================================
check('helper_global_existe', function_exists('__'));
check('helper_equivale_a_t', __($firstKey) === AdminI18n::t($firstKey));
check('helper_interpola', __('no.existe.{x}.clave', ['x' => 'y']) !== '');

// Las claves con HTML intencionado van marcadas con sufijo `.html`, porque en
// la vista se pintan SIN escapar. Cualquier OTRA clave que traiga etiquetas es
// un accidente esperando a que alguien la pinte con `e()` y le salga la etiqueta
// en pantalla — o peor, sin `e()`.
$htmlLeak = [];
foreach ($catalogs as $locale => $cat) {
    foreach ($cat as $key => $text) {
        if (str_ends_with($key, '.html')) continue;
        if (preg_match('/<[a-z\/][^>]*>/i', $text)) $htmlLeak[] = "{$locale}:{$key}";
    }
}
check('sin_html_fuera_de_claves_html', $htmlLeak === [], implode(', ', array_slice($htmlLeak, 0, 10)));

echo PHP_EOL . ($failed === 0 ? "OK — todo en verde" : "FALLOS: {$failed}") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
