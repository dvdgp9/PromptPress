<?php

declare(strict_types=1);

/**
 * ANL-FIX — El orden de los scripts del panel.
 *
 * El layout emite `pp-i18n.js` (quien define `window.pp`) DESPUÉS del
 * contenido, y solo después la sección `scripts`. Una vista que extienda el
 * layout y suelte su `<script src>` en el contenido se ejecuta antes de que
 * `pp` exista.
 *
 * Eso no falla siempre —depende de si el script llama a `pp.t()` al arrancar o
 * solo dentro de funciones—, y por eso es un fallo traicionero: el dashboard de
 * analítica llevaba roto en producción con los KPIs en «—» y los botones de
 * rango muertos, mientras el editor de entradas, con el mismo defecto, seguía
 * funcionando de milagro.
 *
 * Este test cierra la clase entera de fallo en vez de ese caso.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

$failed = 0;
function orderCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 1200) . PHP_EOL;
    }
}

// 1. La premisa: el layout carga pp-i18n.js antes que la sección `scripts`.
$layout = (string) file_get_contents(PP_ROOT . '/views/admin/layout.php');
$i18nPos    = strpos($layout, 'pp-i18n.js');
$contentPos = strpos($layout, "View::section('content')");
$scriptsPos = strpos($layout, "View::section('scripts'");
orderCheck('el_layout_carga_pp_i18n', $i18nPos !== false);
orderCheck('pp_i18n_va_despues_del_contenido', $i18nPos > $contentPos);
orderCheck('la_seccion_scripts_va_la_ultima', $scriptsPos > $i18nPos);

// 2. Ninguna vista que extienda el layout puede soltar scripts en el contenido.
$offenders = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PP_ROOT . '/views/admin'));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = (string) $file->getPathname();
    $short = str_replace(PP_ROOT . '/', '', $path);
    if ($short === 'views/admin/layout.php') continue;

    $source = (string) file_get_contents($path);
    // Una vista standalone no extiende el layout: se ocupa de sus propios
    // scripts (el Studio carga pp-i18n.js él mismo, y hace bien).
    if (!str_contains($source, "View::extend('admin/layout')")) continue;
    if (!str_contains($source, '<script src=')) continue;

    $start = strpos($source, "View::start('scripts')");
    if ($start === false) {
        $offenders[] = $short . ' (carga scripts y no abre la sección `scripts`)';
        continue;
    }
    // Y que ningún <script src> quede por delante de esa apertura.
    $firstScript = strpos($source, '<script src=');
    if ($firstScript < $start) {
        $offenders[] = $short . ' (tiene un <script src> antes de abrir la sección)';
    }
}

orderCheck(
    'ninguna_vista_carga_scripts_fuera_de_la_seccion',
    $offenders === [],
    "Estas vistas ejecutan su JS antes de que `pp` exista:\n  - " . implode("\n  - ", $offenders)
    . "\nMételos entre View::start('scripts') y View::end()."
);

// 3. El caso concreto que estaba roto, fijado por su nombre.
$analytics = (string) file_get_contents(PP_ROOT . '/views/admin/analytics/index.php');
orderCheck('analytics_usa_la_seccion_scripts', str_contains($analytics, "View::start('scripts')"));

// Y la razón por la que a analytics le dolía y a otros no: construye su tabla
// de etiquetas con pp.t() en el nivel superior, o sea nada más cargar.
$dashboardJs = (string) file_get_contents(PP_ROOT . '/admin/assets/js/analytics-dashboard.js');
$labelsAtLoad = (bool) preg_match('/var LABELS = \{[^}]*pp\.t\(/s', $dashboardJs);
orderCheck(
    'analytics_sigue_usando_pp_al_arrancar',
    $labelsAtLoad,
    'Si esto deja de ser cierto, el test de arriba sigue valiendo, pero este '
    . 'recordatorio de por qué existe ya no describe la realidad.'
);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
