<?php
/**
 * Dashboard de Analítica propia (FEAT-3 A5).
 *
 * Shell server-rendered: los datos iniciales van inline como JSON y
 * analytics-dashboard.js pinta todo (gráfica SVG, listas, KPIs) y gestiona el
 * cambio de rango vía GET /admin/analytics/data sin recargar.
 *
 * @var array $stats   datos de StatsService::forRange
 * @var array $ranges  [7, 30, 90]
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Analítica<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Analítica</h2>
        <p class="pp-page-header__lead">Tu tráfico, tu dato. Sin cookies, sin Google Analytics: nadie más ve las visitas de tu web.</p>
    </div>
    <div class="pp-analytics-ranges" role="tablist" aria-label="Rango de fechas">
        <?php foreach ($ranges as $r): ?>
        <button type="button" role="tab"
                class="pp-analytics-range<?= (int) $stats['range'] === $r ? ' is-active' : '' ?>"
                data-range="<?= (int) $r ?>"
                aria-selected="<?= (int) $stats['range'] === $r ? 'true' : 'false' ?>">
            <?= (int) $r ?> días
        </button>
        <?php endforeach; ?>
    </div>
</div>

<div id="pp-analytics" data-endpoint="<?= e(base_url('admin/analytics/data')) ?>">

    <!-- KPIs -->
    <div class="pp-analytics-kpis">
        <div class="pp-analytics-kpi" data-kpi="visitors">
            <span class="pp-analytics-kpi__label">Visitantes</span>
            <span class="pp-analytics-kpi__value">—</span>
            <span class="pp-analytics-kpi__delta" hidden></span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="pageviews">
            <span class="pp-analytics-kpi__label">Páginas vistas</span>
            <span class="pp-analytics-kpi__value">—</span>
            <span class="pp-analytics-kpi__delta" hidden></span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="avg">
            <span class="pp-analytics-kpi__label">Vistas / día</span>
            <span class="pp-analytics-kpi__value">—</span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="events">
            <span class="pp-analytics-kpi__label">Conversiones</span>
            <span class="pp-analytics-kpi__value">—</span>
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
        <div class="pp-analytics-empty__icon" aria-hidden="true">📊</div>
        <h3>Aún no hay visitas registradas</h3>
        <p>Cuando alguien visite tu web, verás aquí sus estadísticas. Las visitas se cuentan sin cookies y de forma anónima desde el momento en que activaste el módulo.</p>
    </div>

    <!-- Desgloses -->
    <div class="pp-analytics-grid" data-breakdowns>
        <div class="pp-analytics-card">
            <h3>Páginas más vistas</h3>
            <ol class="pp-analytics-list" data-list="pages"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3>Fuentes de tráfico</h3>
            <ol class="pp-analytics-list" data-list="referrers"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3>Dispositivos</h3>
            <div class="pp-analytics-devices" data-devices></div>
            <h3 class="pp-analytics-card__subtitle">Navegadores</h3>
            <ol class="pp-analytics-list" data-list="browsers"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3>Conversiones</h3>
            <ol class="pp-analytics-list" data-list="events"></ol>
            <p class="pp-analytics-hint">Los envíos de formulario se registran automáticamente. Añade eventos propios con <code>ppTrack('nombre')</code>.</p>
        </div>
    </div>

    <p class="pp-analytics-footnote">Los visitantes se identifican con un código anónimo que cambia cada día: los totales de varios días suman los únicos de cada día. Los datos detallados se conservan 90 días; los resúmenes diarios, para siempre.</p>
</div>

<script type="application/json" id="pp-analytics-data"><?= json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php $js = PP_ROOT . '/admin/assets/js/analytics-dashboard.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/analytics-dashboard.js')) ?>?v=<?= e($jsVer) ?>"></script>
