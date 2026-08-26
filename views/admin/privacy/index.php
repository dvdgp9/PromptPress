<?php
/**
 * Panel Privacidad — E-GDPR G2.
 *
 * @var string $tab           tab activa: summary|legal|pages|cookies|forms
 * @var array  $manifest      manifest completo del sitio
 * @var array  $status        ['level'=>..., 'gaps'=>[...]]
 * @var array  $legalInput    valores del form (para repintar tras error)
 * @var array  $legalErrors   errores por campo
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$levelLabels = [
    'green'  => [__('privacy.level.green'),  'verde'],
    'yellow' => [__('privacy.level.yellow'), 'amarillo'],
    'orange' => [__('privacy.level.orange'), 'naranja'],
    'red'    => [__('privacy.level.red'),    'rojo'],
];
$levelInfo = $levelLabels[$status['level']] ?? $levelLabels['yellow'];
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.privacy')) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/privacy-generate.js')) ?>?v=<?= @filemtime(PP_ROOT . '/admin/assets/js/privacy-generate.js') ?: '1' ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('nav.privacy')) ?></h2>
        <p class="pp-page-header__lead"><?= e(__('privacy.intro')) ?></p>
    </div>
    <span class="pp-status-pill pp-status-pill--<?= e($status['level']) ?>" title="<?= e(__('privacy.compliance_status')) ?>">
        <span class="pp-status-pill__dot" aria-hidden="true"></span>
        <?= e($levelInfo[0]) ?>
    </span>
</div>

<nav class="pp-privacy-tabs" role="tablist" aria-label="<?= e(__('privacy.tabs_aria')) ?>">
    <?php
    $tabs = [
        'summary' => [__('seo.tab_summary'),      'summary'],
        'legal'   => [__('privacy.tab_legal'),    'legal'],
        'pages'   => [__('privacy.tab_pages'),    'pages'],
        'cookies' => [__('privacy.tab_cookies'),  'cookies'],
        'forms'   => [__('forms.title'),          'forms'],
    ];
    foreach ($tabs as $key => $info):
        $isActive = $tab === $key;
    ?>
    <a href="<?= e(base_url('admin/privacy?tab=' . $key)) ?>"
       class="pp-privacy-tab<?= $isActive ? ' is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $isActive ? 'true' : 'false' ?>">
        <?= e($info[0]) ?>
    </a>
    <?php endforeach; ?>
</nav>

<div class="pp-privacy-content">
    <?php
    switch ($tab) {
        case 'legal':
            include __DIR__ . '/tab_legal.php';
            break;
        case 'pages':
            include __DIR__ . '/tab_pages.php';
            break;
        case 'cookies':
            // Las integraciones de tracking se gestionan en Marketing; aquí solo
            // banner + estado. El wizard mantiene las tarjetas inline.
            $hideTrackingSection = true;
            include __DIR__ . '/tab_cookies.php';
            break;
        case 'forms':
            include __DIR__ . '/tab_forms.php';
            break;
        case 'summary':
        default:
            include __DIR__ . '/tab_summary.php';
    }
    ?>
</div>
