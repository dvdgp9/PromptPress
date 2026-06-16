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
    'green'  => ['Todo en orden', 'verde'],
    'yellow' => ['Casi listo', 'amarillo'],
    'orange' => ['Antes de publicar', 'naranja'],
    'red'    => ['Atención', 'rojo'],
];
$levelInfo = $levelLabels[$status['level']] ?? $levelLabels['yellow'];
?>

<?php \Core\View::start('title'); ?>Privacidad<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Privacidad</h2>
        <p class="pp-page-header__lead">Cumple con la normativa europea sin pelearte con el legalismo. Te avisamos de lo que falte y la IA se encarga de los textos.</p>
    </div>
    <span class="pp-status-pill pp-status-pill--<?= e($status['level']) ?>" title="Estado de cumplimiento">
        <span class="pp-status-pill__dot" aria-hidden="true"></span>
        <?= e($levelInfo[0]) ?>
    </span>
</div>

<nav class="pp-privacy-tabs" role="tablist" aria-label="Secciones de Privacidad">
    <?php
    $tabs = [
        'summary' => ['Resumen',          'summary'],
        'legal'   => ['Datos de tu empresa', 'legal'],
        'pages'   => ['Páginas legales',  'pages'],
        'cookies' => ['Cookies',          'cookies'],
        'forms'   => ['Formularios',      'forms'],
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
