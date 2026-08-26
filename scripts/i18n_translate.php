<?php

declare(strict_types=1);

/**
 * ADMIN-I18N T0.6 — Rellena los catálogos del panel a partir del castellano.
 *
 * Uso:
 *   php scripts/i18n_translate.php                 # todos los idiomas, lo que falte
 *   php scripts/i18n_translate.php --lang=fr       # solo francés
 *   php scripts/i18n_translate.php --dry-run       # enseña qué haría y no escribe
 *   php scripts/i18n_translate.php --force         # rehace TODAS las claves (cuidado)
 *   php scripts/i18n_translate.php --site=1        # sitio del que salen las credenciales de IA
 *
 * Comportamiento por defecto, y es lo importante: **solo añade lo que falta**.
 * Una traducción corregida a mano no se pisa nunca, porque la corrección de un
 * humano vale más que la siguiente pasada del modelo. `--force` existe para
 * cuando cambia el texto castellano de origen, y avisa de lo que va a rehacer.
 *
 * Trocea en lotes: 2.000 cadenas en un solo prompt se truncan, y una respuesta
 * truncada es un catálogo a medias.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AdminI18n;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\LanguageService;

/**
 * Cadenas por llamada. Bajo a propósito: prefiero más llamadas que una
 * respuesta truncada.
 *
 * Se bajó de 40 a 25 tras ver fallar dos lotes en francés: el JSON de vuelta
 * se cortó a media clave. El francés y el portugués ocupan bastante más que el
 * castellano, así que el lote que cabía en la fuente no cabía en el destino.
 */
const BATCH_SIZE = 25;

/**
 * Términos del producto que tienen que traducirse SIEMPRE igual. Sin esto, el
 * modelo alterna «Escritorio»/«Panel»/«Inicio» entre lotes y el panel queda
 * hablando de tres cosas distintas que son la misma.
 *
 * Se le pasa al modelo tal cual, así que se escribe para que lo lea él.
 */
const GLOSSARY = [
    'en' => [
        'Escritorio (la pantalla de inicio del panel)' => 'Dashboard',
        'Páginas'      => 'Pages',
        'Entradas (artículos del blog)' => 'Posts',
        'Medios'       => 'Media',
        'Formularios'  => 'Forms',
        'Mensajes (respuestas recibidas de un formulario)' => 'Messages',
        'Conocimiento (lo que la web sabe del negocio)' => 'Knowledge',
        'Diseño'       => 'Design',
        'Ajustes'      => 'Settings',
        'Sitio / web'  => 'site',
        'Asistente'    => 'Assistant',
        'Reservas'     => 'Bookings',
        'Tienda'       => 'Shop',
        'Header y pie' => 'Header & footer',
    ],
    'fr' => [
        'Escritorio (la pantalla de inicio del panel)' => 'Tableau de bord',
        'Páginas'      => 'Pages',
        'Entradas (artículos del blog)' => 'Articles',
        'Medios'       => 'Médias',
        'Formularios'  => 'Formulaires',
        'Mensajes (respuestas recibidas de un formulario)' => 'Messages',
        'Conocimiento (lo que la web sabe del negocio)' => 'Connaissances',
        'Diseño'       => 'Design',
        'Ajustes'      => 'Réglages',
        'Sitio / web'  => 'site',
        'Asistente'    => 'Assistant',
        'Reservas'     => 'Réservations',
        'Tienda'       => 'Boutique',
        'Header y pie' => 'En-tête et pied de page',
    ],
    'pt' => [
        'Escritorio (la pantalla de inicio del panel)' => 'Painel',
        'Páginas'      => 'Páginas',
        'Entradas (artículos del blog)' => 'Artigos',
        'Medios'       => 'Media',
        'Formularios'  => 'Formulários',
        'Mensajes (respuestas recibidas de un formulario)' => 'Mensagens',
        'Conocimiento (lo que la web sabe del negocio)' => 'Conhecimento',
        'Diseño'       => 'Design',
        'Ajustes'      => 'Definições',
        'Sitio / web'  => 'site',
        'Asistente'    => 'Assistente',
        'Reservas'     => 'Reservas',
        'Tienda'       => 'Loja',
        'Header y pie' => 'Cabeçalho e rodapé',
    ],
];

$opts    = getopt('', ['lang::', 'site::', 'dry-run', 'force']);
$only    = isset($opts['lang']) ? strtolower((string) $opts['lang']) : null;
$siteId  = (int) ($opts['site'] ?? 1);
$dryRun  = array_key_exists('dry-run', $opts);
$force   = array_key_exists('force', $opts);

$sourceFile = PP_ROOT . '/lang/admin/' . AdminI18n::SOURCE . '.php';
$source     = require $sourceFile;
if (!is_array($source) || $source === []) {
    fwrite(STDERR, "El catálogo fuente ({$sourceFile}) está vacío.\n");
    exit(2);
}

$targets = array_values(array_filter(
    AdminI18n::LOCALES,
    fn(string $l) => $l !== AdminI18n::SOURCE && ($only === null || $l === $only)
));

if ($targets === []) {
    fwrite(STDERR, "Nada que traducir. Idiomas posibles: " . implode(', ', AdminI18n::LOCALES) . "\n");
    exit(2);
}

echo "Fuente: " . count($source) . " cadenas en castellano.\n";
echo "Destino: " . implode(', ', $targets) . ($dryRun ? "  [DRY-RUN]" : '') . "\n\n";

$exitCode = 0;

foreach ($targets as $locale) {
    $file     = PP_ROOT . '/lang/admin/' . $locale . '.php';
    $existing = is_file($file) ? (array) require $file : [];

    // Lo que hay que traducir: lo que falta (o todo, con --force).
    $pending = $force
        ? $source
        : array_diff_key($source, $existing);

    // Claves que ya no existen en castellano: sobran y las quita el test, así
    // que se limpian aquí en vez de obligar a hacerlo a mano.
    $orphans = array_diff_key($existing, $source);
    if ($orphans !== []) {
        echo "[{$locale}] " . count($orphans) . " clave(s) huérfana(s) se eliminan: "
           . implode(', ', array_slice(array_keys($orphans), 0, 5)) . "\n";
        $existing = array_intersect_key($existing, $source);
    }

    if ($pending === []) {
        echo "[{$locale}] al día ({$file}).\n";
        if ($orphans !== [] && !$dryRun) {
            writeCatalog($file, $locale, $source, $existing);
        }
        continue;
    }

    echo "[{$locale}] " . count($pending) . " cadena(s) por traducir"
       . ($force ? ' (--force: se rehacen todas)' : '') . ".\n";

    if ($dryRun) {
        foreach (array_slice(array_keys($pending), 0, 10) as $k) {
            echo "    · {$k}\n";
        }
        continue;
    }

    $batches = array_chunk($pending, BATCH_SIZE, true);
    $done    = 0;

    foreach ($batches as $i => $batch) {
        echo "    lote " . ($i + 1) . '/' . count($batches) . ' (' . count($batch) . ")… ";

        try {
            $result = AIActionRunner::run(Actions::TRANSLATE_ADMIN_UI, [
                'language'     => LanguageService::promptLabel($locale),
                'glossary'     => formatGlossary($locale),
                'strings_json' => json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ], $siteId);
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            $exitCode = 1;
            continue;
        }

        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            echo "ERROR: respuesta no interpretable.\n";
            $exitCode = 1;
            continue;
        }

        // Saneado: solo se acepta lo que se pidió, string y no vacío. Si el
        // modelo inventa una clave, se descarta; si se deja una sin traducir,
        // el test lo cantará en vez de colarse un hueco silencioso.
        $accepted = 0;
        foreach ($batch as $key => $original) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if (!placeholdersMatch($original, $value)) {
                echo "\n      ! '{$key}' descartada: se perdió una variable. ";
                continue;
            }
            $existing[$key] = trim($value);
            $accepted++;
        }

        $done += $accepted;
        echo $accepted . " ok" . ($accepted < count($batch) ? ' (' . (count($batch) - $accepted) . ' descartadas)' : '') . "\n";
    }

    writeCatalog($file, $locale, $source, $existing);
    echo "[{$locale}] escrito: " . count($existing) . " cadenas en {$file}\n\n";
}

echo $exitCode === 0
    ? "Listo. Ahora: php tests/admin_i18n.php\n"
    : "Terminado CON ERRORES. Revisa arriba; el catálogo tiene solo lo que se pudo traducir.\n";

exit($exitCode);

// =========================================================================

/** Las mismas variables `{x}`, en el mismo conjunto, antes y después. */
function placeholdersMatch(string $original, string $translated): bool
{
    preg_match_all('/\{([a-z0-9_]+)\}/i', $original, $a);
    preg_match_all('/\{([a-z0-9_]+)\}/i', $translated, $b);
    $x = array_unique($a[1]);
    $y = array_unique($b[1]);
    sort($x);
    sort($y);
    return $x === $y;
}

function formatGlossary(string $locale): string
{
    $entries = GLOSSARY[$locale] ?? [];
    if ($entries === []) {
        return '(sin glosario para este idioma)';
    }
    $lines = [];
    foreach ($entries as $es => $target) {
        $lines[] = '- ' . $es . ' → ' . $target;
    }
    return implode("\n", $lines);
}

/**
 * Escribe el catálogo en el MISMO orden que el castellano, para que un diff
 * entre dos idiomas se pueda leer línea a línea.
 *
 * @param array<string,string> $source
 * @param array<string,string> $strings
 */
function writeCatalog(string $file, string $locale, array $source, array $strings): void
{
    $out  = "<?php\n\n";
    $out .= "// ADMIN-I18N — generado por scripts/i18n_translate.php desde lang/admin/es.php.\n";
    $out .= "// Se puede corregir a mano: el script solo AÑADE lo que falta, no pisa nada.\n";
    $out .= "// Idioma: {$locale}. Última pasada: " . date('Y-m-d H:i') . ".\n\n";
    $out .= "return [\n";

    foreach (array_keys($source) as $key) {
        if (!isset($strings[$key])) {
            continue;
        }
        $out .= '    ' . var_export($key, true) . ' => ' . var_export($strings[$key], true) . ",\n";
    }

    $out .= "];\n";

    file_put_contents($file, $out);
}
