<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * AdminPage — página "Analítica" en wp-admin, con tres vistas (F1/F2):
 *
 *  - (por defecto)  Dashboard general: shell server-rendered + dashboard.js
 *                   (gráfica, KPIs, tops); cambio de rango vía REST sin recargar.
 *  - ?view=list     Lista completa de una dimensión (dim=page|referrer|device|
 *                   browser|event) con buscador (q) sobre la clave.
 *  - ?view=detail   Serie temporal y KPIs de UNA clave concreta (key), p. ej.
 *                   una página. Reutiliza dashboard.js para la gráfica; el
 *                   cambio de rango aquí son enlaces (recarga, no fetch).
 *
 * Abrir cualquier vista dispara el rollup perezoso.
 */
final class AdminPage
{
    public const SLUG = 'iaia-analytics';

    /** Etiquetas de dimensión para cabeceras y migas. */
    private const DIM_LABELS = [
        'page'     => 'Páginas',
        'referrer' => 'Fuentes de tráfico',
        'device'   => 'Dispositivos',
        'browser'  => 'Navegadores',
        'event'    => 'Conversiones',
    ];

    /** Etiquetas de claves especiales (paridad con LABELS de dashboard.js). */
    private const KEY_LABELS = [
        'referrer' => ['' => 'Directo'],
        'device'   => ['desktop' => 'Ordenador', 'mobile' => 'Móvil', 'tablet' => 'Tablet'],
        'browser'  => ['chrome' => 'Chrome', 'safari' => 'Safari', 'firefox' => 'Firefox', 'edge' => 'Edge', 'opera' => 'Opera', 'other' => 'Otros'],
    ];

    /** Hooks de página devueltos por add_(sub)menu_page, para el enqueue. */
    private static array $pageHooks = [];

    public static function registerMenu(): void
    {
        self::$pageHooks[] = (string) add_menu_page(
            'Analítica',
            'Analítica',
            'manage_options',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-chart-bar',
            30
        );
        // Renombrar el primer subítem (WP duplica el top-level) y añadir el importador.
        add_submenu_page(self::SLUG, 'Analítica', 'Panel', 'manage_options', self::SLUG, [self::class, 'render']);
        self::$pageHooks[] = (string) add_submenu_page(
            self::SLUG,
            'Importar histórico de GA4',
            'Importar GA4',
            'manage_options',
            self::SLUG . '-import',
            [self::class, 'renderImport']
        );
    }

    /** Encola CSS/JS solo en las páginas del plugin. */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if (!in_array($hookSuffix, self::$pageHooks, true)) {
            return;
        }
        wp_enqueue_style(
            'iaia-analytics-dashboard',
            IAIA_ANALYTICS_URL . 'assets/css/analytics.css',
            [],
            IAIA_ANALYTICS_VERSION
        );
        wp_enqueue_script(
            'iaia-analytics-dashboard',
            IAIA_ANALYTICS_URL . 'assets/js/dashboard.js',
            [],
            IAIA_ANALYTICS_VERSION,
            ['in_footer' => true]
        );
    }

    public static function render(): void
    {
        RollupService::maybeRun();

        $view  = isset($_GET['view']) ? (string) $_GET['view'] : '';
        $dim   = isset($_GET['dim']) ? (string) $_GET['dim'] : '';
        $range = isset($_GET['range']) ? (int) $_GET['range'] : 30;
        $range = in_array($range, StatsService::RANGES, true) ? $range : 30;

        if ($view === 'list' && isset(self::DIM_LABELS[$dim])) {
            self::renderList($dim, $range);
            return;
        }
        if ($view === 'detail' && isset(self::DIM_LABELS[$dim]) && isset($_GET['key'])) {
            self::renderDetail($dim, (string) wp_unslash($_GET['key']), $range);
            return;
        }
        self::renderDashboard($range);
    }

    /** Página "Importar GA4" (F4): formulario + resultado + historial. */
    public static function renderImport(): void
    {
        $result = null;
        if (isset($_POST['iaia_ga4_import'])) {
            check_admin_referer('iaia_ga4_import');
            $dimension = isset($_POST['dimension']) ? (string) $_POST['dimension'] : '';
            $result    = isset($_FILES['csv'])
                ? Ga4Importer::import($_FILES['csv'], $dimension)
                : ['ok' => false, 'error' => 'No se ha adjuntado ningún fichero.'];
        }
        $log = get_option('iaia_analytics_ga4_imports', []);
        $log = is_array($log) ? array_reverse($log) : [];
        $dims = [
            'total'    => 'Totales del sitio (fecha + vistas + usuarios)',
            'page'     => 'Páginas (fecha + ruta de página + vistas + usuarios)',
            'referrer' => 'Fuentes de tráfico (fecha + fuente de la sesión + vistas + usuarios)',
            'device'   => 'Dispositivos (fecha + categoría de dispositivo + vistas + usuarios)',
        ];
        ?>
<div class="wrap iaia-analytics-wrap">
    <div class="iaia-analytics-header">
        <div>
            <p class="iaia-analytics-breadcrumb"><a href="<?php echo esc_url(self::url()); ?>">&larr; Analítica</a></p>
            <h1>Importar histórico de GA4</h1>
            <p class="iaia-analytics-header__lead">Vuelca tus datos antiguos de Google Analytics en los resúmenes diarios del plugin. Reimportar el mismo fichero no duplica nada.</p>
        </div>
    </div>

    <?php if ($result !== null): ?>
        <?php if ($result['ok']): ?>
        <div class="notice notice-success"><p>
            Importadas <strong><?php echo (int) $result['imported']; ?></strong> filas
            (<?php echo (int) $result['days']; ?> días<?php echo $result['skipped'] > 0 ? ', ' . (int) $result['skipped'] . ' filas descartadas' : ''; ?>).
            <a href="<?php echo esc_url(self::url()); ?>">Ver el panel</a>.
        </p></div>
        <?php else: ?>
        <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="pp-analytics-card iaia-analytics-fullcard">
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('iaia_ga4_import'); ?>
            <input type="hidden" name="iaia_ga4_import" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="iaia-ga4-dim">Tipo de datos</label></th>
                    <td>
                        <select name="dimension" id="iaia-ga4-dim">
                            <?php foreach ($dims as $value => $text): ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($text); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="iaia-ga4-csv">Fichero CSV</label></th>
                    <td>
                        <input type="file" name="csv" id="iaia-ga4-csv" accept=".csv,text/csv" required>
                        <p class="description">Exportado desde GA4 (Exploraciones o Informes) con la dimensión <strong>Fecha</strong> en valores diarios. Cabeceras en español o inglés; máx. 20 MB.</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary">Importar</button></p>
        </form>

        <h3 class="pp-analytics-card__subtitle">Cómo exportar desde GA4</h3>
        <ol class="iaia-analytics-steps">
            <li>En GA4, abre <strong>Explorar &rarr; Exploración libre</strong> (formato tabla).</li>
            <li>Añade la dimensión <strong>Fecha</strong> y, si aplica, la dimensión del tipo de datos (Ruta de página, Fuente de la sesión o Categoría de dispositivo).</li>
            <li>Añade las métricas <strong>Vistas</strong> y <strong>Usuarios activos</strong>, amplía el rango de fechas y las filas mostradas.</li>
            <li>Exporta como CSV y súbelo aquí, un fichero por tipo de datos (empieza por Totales).</li>
        </ol>
        <p class="pp-analytics-hint">Los "usuarios" de GA4 y los visitantes anónimos de este plugin se calculan distinto: el histórico importado es orientativo. Si un día importado también tiene datos propios, los propios prevalecen.</p>

        <?php if ($log !== []): ?>
        <h3 class="pp-analytics-card__subtitle">Importaciones realizadas</h3>
        <ol class="pp-analytics-list">
            <?php foreach ($log as $entry): ?>
            <li class="pp-analytics-list__item">
                <span class="pp-analytics-list__label"><?php echo esc_html($entry['file'] . ' (' . $entry['dimension'] . ')'); ?></span>
                <span class="pp-analytics-list__count"><?php echo esc_html($entry['rows'] . ' filas, ' . $entry['days'] . ' días, ' . $entry['when']); ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>
</div>
        <?php
    }

    /** URL de la página con parámetros extra. */
    private static function url(array $params = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $params), admin_url('admin.php'));
    }

    private static function keyLabel(string $dim, string $key): string
    {
        return self::KEY_LABELS[$dim][$key] ?? ($key === '' ? '(vacío)' : $key);
    }

    /** Selector de rango como ENLACES (vistas list/detail; recargan la página). */
    private static function rangeLinks(int $active, array $params): void
    {
        echo '<div class="pp-analytics-ranges" role="tablist" aria-label="Rango de fechas">';
        foreach (StatsService::RANGES as $r) {
            printf(
                '<a role="tab" class="pp-analytics-range%s" aria-selected="%s" href="%s">%d días</a>',
                $active === $r ? ' is-active' : '',
                $active === $r ? 'true' : 'false',
                esc_url(self::url(array_merge($params, ['range' => $r]))),
                $r
            );
        }
        echo '</div>';
    }

    // ------------------------------------------------------------ Dashboard

    private static function renderDashboard(int $range): void
    {
        $stats  = StatsService::forRange($range);
        $ranges = StatsService::RANGES;

        $endpoint = rest_url(RestController::NAMESPACE . '/stats');
        $nonce    = wp_create_nonce('wp_rest');
        ?>
<div class="wrap iaia-analytics-wrap">
    <div class="iaia-analytics-header">
        <div>
            <h1>Analítica</h1>
            <p class="iaia-analytics-header__lead">Tu tráfico, tu dato. Sin cookies y sin terceros: nadie más ve las visitas de tu web.</p>
        </div>
        <div class="pp-analytics-ranges" role="tablist" aria-label="Rango de fechas">
            <?php foreach ($ranges as $r): ?>
            <button type="button" role="tab"
                    class="pp-analytics-range<?php echo $range === $r ? ' is-active' : ''; ?>"
                    data-range="<?php echo (int) $r; ?>"
                    aria-selected="<?php echo $range === $r ? 'true' : 'false'; ?>">
                <?php echo (int) $r; ?> días
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="pp-analytics"
         data-endpoint="<?php echo esc_attr($endpoint); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>">

        <!-- KPIs -->
        <div class="pp-analytics-kpis">
            <div class="pp-analytics-kpi" data-kpi="visitors">
                <span class="pp-analytics-kpi__label">Visitantes</span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
                <span class="pp-analytics-kpi__delta" hidden></span>
            </div>
            <div class="pp-analytics-kpi" data-kpi="pageviews">
                <span class="pp-analytics-kpi__label">Páginas vistas</span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
                <span class="pp-analytics-kpi__delta" hidden></span>
            </div>
            <div class="pp-analytics-kpi" data-kpi="avg">
                <span class="pp-analytics-kpi__label">Vistas / día</span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
            </div>
            <div class="pp-analytics-kpi" data-kpi="events">
                <span class="pp-analytics-kpi__label">Conversiones</span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
            </div>
        </div>

        <!-- Gráfica principal -->
        <div class="pp-analytics-chart-card">
            <div class="pp-analytics-chart-head">
                <h3>Evolución diaria</h3>
                <div class="pp-analytics-legend">
                    <span class="pp-analytics-legend__item pp-analytics-legend__item--pv">Páginas vistas</span>
                    <span class="pp-analytics-legend__item pp-analytics-legend__item--vis">Visitantes</span>
                </div>
            </div>
            <div class="pp-analytics-chart" data-chart>
                <div class="pp-analytics-tooltip" data-tooltip hidden></div>
            </div>
        </div>

        <!-- Estado vacío -->
        <div class="pp-analytics-empty" data-empty hidden>
            <div class="pp-analytics-empty__icon" aria-hidden="true"><span class="dashicons dashicons-chart-bar"></span></div>
            <h3>Aún no hay visitas registradas</h3>
            <p>Cuando alguien visite tu web, verás aquí sus estadísticas. Las visitas se cuentan sin cookies y de forma anónima desde el momento en que activaste el plugin.</p>
        </div>

        <!-- Desgloses -->
        <div class="pp-analytics-grid" data-breakdowns>
            <div class="pp-analytics-card">
                <h3>Páginas más vistas <a class="pp-analytics-card__more" href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => 'page', 'range' => $range])); ?>">Ver todas</a></h3>
                <ol class="pp-analytics-list" data-list="pages"></ol>
            </div>
            <div class="pp-analytics-card">
                <h3>Fuentes de tráfico <a class="pp-analytics-card__more" href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => 'referrer', 'range' => $range])); ?>">Ver todas</a></h3>
                <ol class="pp-analytics-list" data-list="referrers"></ol>
            </div>
            <div class="pp-analytics-card">
                <h3>Dispositivos</h3>
                <div class="pp-analytics-devices" data-devices></div>
                <h3 class="pp-analytics-card__subtitle">Navegadores <a class="pp-analytics-card__more" href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => 'browser', 'range' => $range])); ?>">Ver todos</a></h3>
                <ol class="pp-analytics-list" data-list="browsers"></ol>
            </div>
            <div class="pp-analytics-card">
                <h3>Conversiones <a class="pp-analytics-card__more" href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => 'event', 'range' => $range])); ?>">Ver todas</a></h3>
                <ol class="pp-analytics-list" data-list="events"></ol>
                <p class="pp-analytics-hint">Añade eventos propios llamando a <code>ppTrack('nombre')</code> desde tu web.</p>
            </div>
        </div>

        <p class="pp-analytics-footnote">Los visitantes se identifican con un código anónimo que cambia cada día: los totales de varios días suman los únicos de cada día. Los datos detallados se conservan 90 días; los resúmenes diarios, para siempre.</p>
    </div>

    <script type="application/json" id="pp-analytics-data"><?php echo wp_json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</div>
        <?php
    }

    // ----------------------------------------------------- Lista (drill-down)

    private static function renderList(string $dim, int $range): void
    {
        $q     = isset($_GET['q']) ? sanitize_text_field((string) wp_unslash($_GET['q'])) : '';
        $rows  = StatsService::listDim($dim, $range, $q);
        $label = self::DIM_LABELS[$dim];
        $unit  = $dim === 'event' ? 'veces' : 'vistas';
        $max   = $rows !== [] ? max(1, $rows[0]['pv']) : 1;
        ?>
<div class="wrap iaia-analytics-wrap">
    <div class="iaia-analytics-header">
        <div>
            <p class="iaia-analytics-breadcrumb"><a href="<?php echo esc_url(self::url(['range' => $range])); ?>">&larr; Analítica</a></p>
            <h1><?php echo esc_html($label); ?></h1>
        </div>
        <?php self::rangeLinks($range, ['view' => 'list', 'dim' => $dim, 'q' => $q]); ?>
    </div>

    <?php if ($dim === 'page' || $dim === 'referrer' || $dim === 'event'): ?>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="iaia-analytics-search">
        <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
        <input type="hidden" name="view" value="list">
        <input type="hidden" name="dim" value="<?php echo esc_attr($dim); ?>">
        <input type="hidden" name="range" value="<?php echo (int) $range; ?>">
        <input type="search" name="q" value="<?php echo esc_attr($q); ?>"
               placeholder="Buscar en <?php echo esc_attr(mb_strtolower($label)); ?>…" aria-label="Buscar">
        <button type="submit" class="button">Buscar</button>
        <?php if ($q !== ''): ?>
        <a class="iaia-analytics-search__clear" href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => $dim, 'range' => $range])); ?>">Limpiar</a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="pp-analytics-card iaia-analytics-fullcard">
        <?php if ($rows === []): ?>
        <p class="pp-analytics-list__empty"><?php echo $q === '' ? 'Sin datos en este periodo.' : 'Nada coincide con la búsqueda en este periodo.'; ?></p>
        <?php else: ?>
        <ol class="pp-analytics-list">
            <?php foreach ($rows as $row): ?>
            <li class="pp-analytics-list__item">
                <span class="pp-analytics-list__bar" style="width:<?php echo max(2, (int) round($row['pv'] / $max * 100)); ?>%"></span>
                <span class="pp-analytics-list__label" title="<?php echo esc_attr($row['k']); ?>">
                    <a href="<?php echo esc_url(self::url(['view' => 'detail', 'dim' => $dim, 'key' => $row['k'], 'range' => $range])); ?>">
                        <?php echo esc_html(self::keyLabel($dim, $row['k'])); ?>
                    </a>
                </span>
                <span class="pp-analytics-list__count" title="<?php echo esc_attr(number_format_i18n($row['vis'])); ?> visitantes">
                    <?php echo esc_html(number_format_i18n($row['pv'])); ?> <small><?php echo esc_html($unit); ?></small>
                </span>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </div>
</div>
        <?php
    }

    // --------------------------------------------------- Detalle de una clave

    private static function renderDetail(string $dim, string $key, int $range): void
    {
        $stats = StatsService::keyStats($dim, $key, $range);
        $label = self::keyLabel($dim, $key);
        ?>
<div class="wrap iaia-analytics-wrap">
    <div class="iaia-analytics-header">
        <div>
            <p class="iaia-analytics-breadcrumb">
                <a href="<?php echo esc_url(self::url(['range' => $range])); ?>">&larr; Analítica</a> /
                <a href="<?php echo esc_url(self::url(['view' => 'list', 'dim' => $dim, 'range' => $range])); ?>"><?php echo esc_html(self::DIM_LABELS[$dim]); ?></a>
            </p>
            <h1><?php echo esc_html($label); ?></h1>
        </div>
        <?php self::rangeLinks($range, ['view' => 'detail', 'dim' => $dim, 'key' => $key]); ?>
    </div>

    <div id="pp-analytics">
        <div class="pp-analytics-kpis">
            <div class="pp-analytics-kpi" data-kpi="visitors">
                <span class="pp-analytics-kpi__label"><?php echo $dim === 'event' ? 'Visitantes que lo hicieron' : 'Visitantes'; ?></span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
                <span class="pp-analytics-kpi__delta" hidden></span>
            </div>
            <div class="pp-analytics-kpi" data-kpi="pageviews">
                <span class="pp-analytics-kpi__label"><?php echo $dim === 'event' ? 'Veces' : 'Páginas vistas'; ?></span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
                <span class="pp-analytics-kpi__delta" hidden></span>
            </div>
            <div class="pp-analytics-kpi" data-kpi="avg">
                <span class="pp-analytics-kpi__label"><?php echo $dim === 'event' ? 'Veces / día' : 'Vistas / día'; ?></span>
                <span class="pp-analytics-kpi__value">&nbsp;</span>
            </div>
        </div>

        <div class="pp-analytics-chart-card">
            <div class="pp-analytics-chart-head">
                <h3>Evolución diaria</h3>
                <div class="pp-analytics-legend">
                    <span class="pp-analytics-legend__item pp-analytics-legend__item--pv"><?php echo $dim === 'event' ? 'Veces' : 'Páginas vistas'; ?></span>
                    <span class="pp-analytics-legend__item pp-analytics-legend__item--vis">Visitantes</span>
                </div>
            </div>
            <div class="pp-analytics-chart" data-chart>
                <div class="pp-analytics-tooltip" data-tooltip hidden></div>
            </div>
        </div>

        <div class="pp-analytics-empty" data-empty hidden>
            <div class="pp-analytics-empty__icon" aria-hidden="true"><span class="dashicons dashicons-chart-bar"></span></div>
            <h3>Sin datos en este periodo</h3>
            <p>Prueba con un rango de fechas más amplio.</p>
        </div>
    </div>

    <script type="application/json" id="pp-analytics-data"><?php echo wp_json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</div>
        <?php
    }
}
