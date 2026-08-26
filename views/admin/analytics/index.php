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

<?php \Core\View::start('title'); ?><?= e(__('nav.analytics')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('nav.analytics')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('analytics.intro')) ?></p>
    </div>
    <div class="pp-analytics-ranges" role="tablist" aria-label="<?= e(__('analytics.range_aria')) ?>">
        <?php foreach ($ranges as $r): ?>
        <button type="button" role="tab"
                class="pp-analytics-range<?= (int) $stats['range'] === $r ? ' is-active' : '' ?>"
                data-range="<?= (int) $r ?>"
                aria-selected="<?= (int) $stats['range'] === $r ? 'true' : 'false' ?>">
            <?= e(__('analytics.n_days', ['n' => (int) $r])) ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<div id="pp-analytics" data-endpoint="<?= e(base_url('admin/analytics/data')) ?>">

    <!-- KPIs -->
    <div class="pp-analytics-kpis">
        <div class="pp-analytics-kpi" data-kpi="visitors">
            <span class="pp-analytics-kpi__label"><?= e(__('analytics.visitors')) ?></span>
            <span class="pp-analytics-kpi__value">—</span>
            <span class="pp-analytics-kpi__delta" hidden></span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="pageviews">
            <span class="pp-analytics-kpi__label"><?= e(__('analytics.pageviews')) ?></span>
            <span class="pp-analytics-kpi__value">—</span>
            <span class="pp-analytics-kpi__delta" hidden></span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="avg">
            <span class="pp-analytics-kpi__label"><?= e(__('analytics.views_per_day')) ?></span>
            <span class="pp-analytics-kpi__value">—</span>
        </div>
        <div class="pp-analytics-kpi" data-kpi="events">
            <span class="pp-analytics-kpi__label"><?= e(__('analytics.conversions')) ?></span>
            <span class="pp-analytics-kpi__value">—</span>
        </div>
    </div>

    <!-- Gráfica principal -->
    <div class="pp-analytics-chart-card">
        <div class="pp-analytics-chart-head">
            <h3><?= e(__('analytics.daily')) ?></h3>
            <div class="pp-analytics-legend">
                <span class="pp-analytics-legend__item pp-analytics-legend__item--pv"><?= e(__('analytics.pageviews')) ?></span>
                <span class="pp-analytics-legend__item pp-analytics-legend__item--vis"><?= e(__('analytics.visitors')) ?></span>
            </div>
        </div>
        <div class="pp-analytics-chart" data-chart>
            <div class="pp-analytics-tooltip" data-tooltip hidden></div>
        </div>
    </div>

    <!-- Estado vacío -->
    <div class="pp-analytics-empty" data-empty hidden>
        <div class="pp-analytics-empty__icon" aria-hidden="true">📊</div>
        <h3><?= e(__('analytics.empty')) ?></h3>
        <p><?= e(__('analytics.empty_help')) ?></p>
    </div>

    <!-- Desgloses -->
    <div class="pp-analytics-grid" data-breakdowns>
        <div class="pp-analytics-card">
            <h3><?= e(__('analytics.top_pages')) ?></h3>
            <ol class="pp-analytics-list" data-list="pages"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3><?= e(__('analytics.sources')) ?></h3>
            <ol class="pp-analytics-list" data-list="referrers"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3><?= e(__('analytics.devices')) ?></h3>
            <div class="pp-analytics-devices" data-devices></div>
            <h3 class="pp-analytics-card__subtitle"><?= e(__('analytics.browsers')) ?></h3>
            <ol class="pp-analytics-list" data-list="browsers"></ol>
        </div>
        <div class="pp-analytics-card">
            <h3><?= e(__('analytics.conversions')) ?></h3>
            <ol class="pp-analytics-list" data-list="events"></ol>
            <p class="pp-analytics-hint"><?= __('analytics.events_hint.html') ?></p>
        </div>
    </div>

    <p class="pp-analytics-footnote"><?= e(__('analytics.footnote')) ?></p>
</div>

<script type="application/json" id="pp-analytics-data"><?= json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php $js = PP_ROOT . '/admin/assets/js/analytics-dashboard.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/analytics-dashboard.js')) ?>?v=<?= e($jsVer) ?>"></script>
