<?php

declare(strict_types=1);

/**
 * ADMIN-I18N — Vigilante de los ficheros ya traducidos.
 *
 * Uso:
 *   php scripts/i18n_lint.php            # informe completo
 *   php scripts/i18n_lint.php --keys     # solo las claves usadas y no definidas
 *
 * Contesta a las tres preguntas que se repiten en cada fase:
 *
 *   1. ¿Qué claves usa el código (`__('x')`, `pp.t('x')`) que NO están en el
 *      catálogo castellano? Esas salen en pantalla como `x.y` literal.
 *   2. ¿Qué claves sobran en el catálogo, sin un solo uso en el código?
 *   3. ¿Qué castellano se ha vuelto a colar a pelo en un fichero YA migrado?
 *
 * La tercera es la que evita que esto se pudra: sin ella, la próxima feature
 * escribe «Guardar» en una vista traducida y nadie se entera hasta que un
 * cliente francés lo ve.
 *
 * LÍMITE CONOCIDO de la tercera: detecta castellano por sus acentos y signos
 * de apertura. Una frase sin ninguno («No se pudieron cargar los mensajes»)
 * NO salta. Sirve para cazar descuidos, no para certificar que un fichero está
 * limpio; eso lo dice leerlo.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AdminI18n;

/**
 * Ficheros y carpetas que NO se vigilan. El linter mira TODO lo demás.
 *
 * Fue al revés hasta el 11/08/2026 —una lista blanca de lo ya migrado— y ahí
 * estuvo el fallo: una capa entera de servicios nunca llegó a la lista, así que
 * nadie la miraba y el informe salía limpio con castellano fijo dentro. Con
 * exclusiones explícitas, lo que se olvida SALTA en vez de esconderse.
 *
 * Cada exclusión necesita un motivo. Los tres que hay:
 *   - `app/Services/AI/`  → prompts enteros; su idioma es parte del contrato.
 *   - `Microcopy.php`     → catálogo del idioma del SITIO, no del panel.
 *   - `views/public/`     → lo ve el visitante; su idioma es el de la web.
 */
const NOT_UI = [
    'app/Services/AI/',
    'app/Services/Microcopy.php',
    'views/public/',
];

/** ¿Este fichero está fuera del alcance del panel? */
function outOfScope(string $rel): bool
{
    foreach (NOT_UI as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            return true;
        }
    }
    return false;
}

/** Sitios donde un acento NO es interfaz: comentarios y cosas que no se pintan. */
function stripNonUi(string $code): string
{
    // Comentarios de bloque, de línea (`//`), docblocks y comentarios HTML:
    // un `<!-- Stats: … -->` con acentos no es texto de interfaz.
    //
    // Los comentarios multilínea se sustituyen por SUS MISMOS saltos de línea,
    // no por vacío: si se colapsan, todo lo que viene después se desplaza y el
    // informe acaba señalando números de línea que no existen.
    $keepLines = fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n"));

    $code = preg_replace_callback('#/\*.*?\*/#s', $keepLines, $code) ?? $code;
    $code = preg_replace_callback('#<!--.*?-->#s', $keepLines, $code) ?? $code;
    // `[ \t]*` y NO `\s*`: `\s` incluye el salto de línea, así que con `\s*` una
    // sola coincidencia se tragaba varias líneas de comentario seguidas —y con
    // ellas sus saltos—, desplazando todo lo de abajo.
    $code = preg_replace('#^[ \t]*//.*$#m', '', $code) ?? $code;
    $code = preg_replace('#<\?php[ \t]*//.*$#m', '', $code) ?? $code;
    // Comentario al final de una línea de código (`return; // porque…`). Se
    // exige un espacio delante y se excluye `://` para no destrozar las URLs,
    // que es lo único parecido a un comentario que aparece a media línea.
    $code = preg_replace('#(?<![:"\'])\s//[^"\'\n]*$#m', '', $code) ?? $code;
    return $code;
}

/**
 * ¿Esta línea lleva castellano visible?
 *
 * Dos pasadas, porque la primera sola es ciega. Los acentos y los signos `¿¡`
 * pillan casi todo, pero «Cambios sin guardar» o «No se pudo cargar» no llevan
 * ninguno: frases enteras del panel se colaban por ahí y así fue como se
 * escaparon los servicios y varios JS. La segunda pasada exige DOS palabras
 * funcionales del castellano dentro de una misma cadena, que es lo bastante
 * raro en código inglés como para no llenar el informe de ruido.
 */
function looksSpanish(string $line): bool
{
    if (preg_match('/[áéíóúñ¿¡]/iu', $line)) {
        return true;
    }
    $w = '\b(el|la|los|las|un|una|de|del|para|con|sin|por|que|como|cuando|donde'
       . '|pero|tus|tu|sus|este|esta|estos|estas|no|se|ya|hay|todos|todas|puedes'
       . '|debes|elige|escribe|guarda|nombre|correo|fecha|texto|error|aviso'
       . '|cambios|sitio|nuevo|nueva|activo|enviar|buscar|volver|cerrar|abrir'
       . '|desde|hasta|antes|luego|otra|otro|pudo|pudieron|puede|falta|faltan)\b';
    if (!preg_match('/(>[^<>{}\n]{10,}<|\'[^\']{10,}\'|"[^"]{10,}")/', $line, $m)) {
        return false;
    }
    // Rutas, clases CSS y URLs se parecen a una frase corta; se descartan.
    $frag = trim($m[0], '\'"<>');
    if (preg_match('#^https?://|^[a-z0-9_\-./{}$ ]+$#i', $frag)) {
        return false;
    }
    // Comillas de cadenas DISTINTAS con código en medio: `'.pp-cb'); if (el) …`
    // casa dos veces «el» sin ser castellano. Si dentro hay código, no cuenta.
    if (preg_match('/\)\s*[;{]|\)\s*\./', $frag)) {
        return false;
    }
    return (bool) preg_match('/' . $w . '[^\'"<>]*' . $w . '/iu', $m[0]);
}

$opts     = getopt('', ['keys']);
$onlyKeys = array_key_exists('keys', $opts);

$catalog = AdminI18n::catalog(AdminI18n::SOURCE);
$used    = [];
$hardcoded = [];

// ---------------------------------------------------------------------------
// Recorrido: claves usadas en TODO el proyecto (aunque el fichero no esté
// migrado del todo) + castellano suelto en todo lo que no esté excluido.
// ---------------------------------------------------------------------------
$roots = ['app', 'views', 'admin/assets/js', 'core'];
$files = [];
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PP_ROOT . '/' . $root));
    foreach ($it as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'js'], true)) {
            $files[] = $f->getPathname();
        }
    }
}

foreach ($files as $path) {
    $rel  = str_replace(PP_ROOT . '/', '', $path);
    $code = (string) file_get_contents($path);

    // Claves usadas: `__('x')` en PHP, `pp.t('x')` en JS. Se buscan sobre el
    // código SIN comentarios: los ejemplos de un docblock no son usos reales.
    preg_match_all("/(?:__|pp\.t)\(\s*'([a-z0-9_.]+)'/i", stripNonUi($code), $m);
    foreach ($m[1] as $key) {
        // Clave construida por concatenación (`__('grupo.' . $x)`): lo que se
        // captura es solo el prefijo, y comprobarlo daría un falso positivo.
        if (str_ends_with($key, '.')) {
            continue;
        }
        $used[$key] = ($used[$key] ?? 0) + 1;
    }

    if (outOfScope($rel)) {
        continue;
    }

    // Castellano a pelo en algo que se pinta en el panel.
    $clean = stripNonUi($code);
    $lines = preg_split('/\R/', $clean) ?: [];
    // El marcador `i18n-ignore` se busca sobre el código CON comentarios: si se
    // mirara el limpio, el propio comentario que justifica la excepción habría
    // desaparecido antes de poder leerlo.
    $rawLines = preg_split('/\R/', $code) ?: [];

    // Regiones excluidas a bloque: `i18n-ignore-start` … `i18n-ignore-end`.
    // Existen por un caso concreto y grande: los controladores que construyen
    // prompts llevan párrafos enteros de castellano que NO son interfaz sino
    // carga que viaja al modelo. Traducirlos rompería la generación, y marcar
    // línea a línea cien renglones dejaría el fichero ilegible.
    $ignored = [];
    $depth = 0;
    foreach ($rawLines as $n => $raw) {
        if (str_contains($raw, 'i18n-ignore-start')) {
            $depth++;
        }
        $ignored[$n] = $depth > 0;
        if (str_contains($raw, 'i18n-ignore-end') && $depth > 0) {
            $depth--;
        }
    }

    foreach ($lines as $i => $line) {
        if (!looksSpanish($line)) {
            continue;
        }
        if ($ignored[$i] ?? false) {
            continue;
        }
        // Una línea que ya llama a __() está migrada; el acento es del texto
        // que rodea a la llamada o de un atributo, y se revisa a ojo.
        if (str_contains($line, '__(') || str_contains($line, 'pp.t(')) {
            continue;
        }
        // Escape explícito para castellano que NO es interfaz: mensajes de
        // error internos que nunca llegan a pantalla, nombres de fichero,
        // valores que viajan a la IA. El marcador vale en la propia línea o en
        // la anterior, que es donde se escribe de forma natural el comentario
        // que lo justifica.
        // Se mira la línea y, hacia arriba, el bloque de comentario pegado a
        // ella: una justificación de dos renglones es lo normal, y exigir que
        // el marcador vaya en el último invitaría a no explicar nada.
        $marked = str_contains($rawLines[$i] ?? '', 'i18n-ignore');
        for ($j = $i - 1; !$marked && $j >= 0; $j--) {
            $above = trim($rawLines[$j] ?? '');
            $isComment = str_starts_with($above, '//')
                || str_starts_with($above, '*')
                || str_starts_with($above, '/*')
                || str_starts_with($above, '<!--');   // las vistas comentan en HTML
            if (!$isComment) {
                break;
            }
            $marked = str_contains($above, 'i18n-ignore');
        }
        if ($marked) {
            continue;
        }
        $hardcoded[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
    }
}

// ---------------------------------------------------------------------------
// Informe
// ---------------------------------------------------------------------------
$missing = array_values(array_diff(array_keys($used), array_keys($catalog)));
sort($missing);

if ($onlyKeys) {
    foreach ($missing as $key) {
        echo $key . PHP_EOL;
    }
    exit($missing === [] ? 0 : 1);
}

echo "== Claves usadas en el código pero SIN definir en lang/admin/es.php ==\n";
if ($missing === []) {
    echo "  ninguna\n";
} else {
    foreach ($missing as $key) {
        echo "  {$key}  (usada {$used[$key]}×)\n";
    }
}

$unused = array_values(array_diff(array_keys($catalog), array_keys($used)));
sort($unused);
// Informativo, no cuenta como problema: aquí caen sin remedio las claves que
// se construyen concatenando (`__('timezone.' . $tz)`) o eligiendo entre dos
// (singular/plural), porque el nombre completo no existe en el código.
echo "\n== Claves definidas y sin uso literal (revisar, puede ser normal) ==\n";
echo $unused === [] ? "  ninguna\n" : '  ' . implode("\n  ", $unused) . "\n";

echo "\n== Castellano a pelo en el panel ==\n";
echo $hardcoded === [] ? "  ninguno\n" : '  ' . implode("\n  ", $hardcoded) . "\n";

$problems = count($missing) + count($hardcoded);
echo "\n" . ($problems === 0
    ? "OK — nada pendiente.\n"
    : "PENDIENTE: {$problems} cosa(s) que mirar (las claves sin usar no cuentan).\n");

exit($problems === 0 ? 0 : 1);
