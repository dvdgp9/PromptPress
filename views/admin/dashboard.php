<?php
/**
 * Dashboard / Escritorio — stats del sitio.
 * @var string $userName
 * @var string $siteName
 * @var int    $countPages
 * @var int    $countPublished
 * @var int    $countDrafts
 * @var int    $countMedia
 * @var int    $countDocuments
 * @var int    $countAILogs
 * @var int    $aiTokensInput
 * @var int    $aiTokensOutput
 * @var float  $aiCostTotal
 * @var array  $recentPages
 * @var array  $recentAILogs
 */
\Core\View::extend('admin/layout');

// Helpers locales
$fmtNum   = fn($n) => number_format((int) $n, 0, ',', '.');
$fmtCost  = fn($c) => '$' . number_format((float) $c, 4, '.', '');
$fmtDate  = function ($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i', $ts) : $d;
};
$pageTypeLabels = [
    'home' => 'Inicio', 'service' => 'Servicio', 'product' => 'Producto',
    'landing' => 'Landing', 'article' => 'Artículo', 'contact' => 'Contacto',
];
$actionLabels = [
    'generate_structure' => 'Generar estructura',
    'generate_section'   => 'Generar sección',
    'rewrite'            => 'Reescribir',
    'improve_seo'        => 'Mejorar SEO',
    'summarize'          => 'Resumir',
    'test'               => 'Test conexión',
];
?>

<?php \Core\View::start('title'); ?>Escritorio<?php \Core\View::end(); ?>

<div class="pp-dashboard">

    <div class="pp-dashboard__welcome">
        <h2>Hola, <?= e($userName) ?> 👋</h2>
        <p>Resumen de <strong><?= e($siteName) ?></strong>.</p>
    </div>

    <?php
    // E-GDPR G6 — Widget de cumplimiento. Solo se muestra cuando hay gaps.
    $compliance = $compliance ?? ['level' => 'green', 'gaps' => []];
    if (($compliance['level'] ?? 'green') !== 'green' && !empty($compliance['gaps'])):
        $level = $compliance['level'];
        $wizardPending = !($wizardCompleted ?? true);
        if ($wizardPending) {
            $title = 'Configura tu privacidad en 4 pasos';
            $ctaLabel = 'Completar asistente';
            $ctaUrl   = base_url('admin/privacy/wizard');
        } else {
            $title = match ($level) {
                'red'    => 'Atención · hay algo que arreglar en privacidad',
                'orange' => 'Antes de publicar, completa tu privacidad',
                default  => 'Casi listo · revisa estos puntos de privacidad',
            };
            $ctaLabel = 'Ir a Privacidad';
            $ctaUrl   = base_url('admin/privacy');
        }
        $topGaps = array_slice($compliance['gaps'], 0, 2);
    ?>
    <section class="pp-compliance-widget pp-compliance-widget--<?= e($level) ?>">
        <div class="pp-compliance-widget__head">
            <div class="pp-compliance-widget__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="M12 8v4"/>
                    <circle cx="12" cy="16" r="0.9" fill="currentColor"/>
                </svg>
            </div>
            <div class="pp-compliance-widget__title">
                <strong><?= e($title) ?></strong>
                <span><?= count($compliance['gaps']) ?> punto<?= count($compliance['gaps']) === 1 ? '' : 's' ?> que revisar para cumplir la normativa europea.</span>
            </div>
            <a class="pp-btn pp-btn--primary pp-btn--sm" href="<?= e($ctaUrl) ?>"><?= e($ctaLabel) ?></a>
        </div>
        <ul class="pp-compliance-widget__items">
            <?php foreach ($topGaps as $g): ?>
            <li class="pp-compliance-widget__item">
                <span class="pp-compliance-widget__dot pp-compliance-widget__dot--<?= e($g['severity']) ?>"></span>
                <a href="<?= e(base_url(ltrim($g['cta_url'], '/'))) ?>"><?= e($g['title']) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- Stats: agrupadas por divider, no card-boxing. Métrica IA destacada. -->
    <section class="pp-stats">
        <a href="<?= e(base_url('admin/ai-usage')) ?>" class="pp-stat pp-stat--primary">
            <span class="pp-stat__label">Llamadas IA</span>
            <span class="pp-stat__value"><?= $fmtNum($countAILogs) ?></span>
            <?php if ($countAILogs > 0): ?>
            <span class="pp-stat__sub">
                <?= $fmtNum($aiTokensInput + $aiTokensOutput) ?> tokens · <?= $fmtCost($aiCostTotal) ?>
            </span>
            <?php else: ?>
            <span class="pp-stat__sub">Sin actividad todavía</span>
            <?php endif; ?>
        </a>

        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-stat">
            <span class="pp-stat__label">Páginas</span>
            <span class="pp-stat__value"><?= $fmtNum($countPages) ?></span>
            <?php if ($countPages > 0): ?>
            <span class="pp-stat__sub">
                <?= $fmtNum($countPublished) ?> publicadas · <?= $fmtNum($countDrafts) ?> borradores
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= e(base_url('admin/media')) ?>" class="pp-stat">
            <span class="pp-stat__label">Medios</span>
            <span class="pp-stat__value"><?= $fmtNum($countMedia) ?></span>
        </a>

        <a href="<?= e(base_url('admin/documents')) ?>" class="pp-stat">
            <span class="pp-stat__label">Documentos</span>
            <span class="pp-stat__value"><?= $fmtNum($countDocuments) ?></span>
        </a>
    </section>

    <!-- Quick actions -->
    <div class="pp-dashboard__section">
        <h3>Acciones rápidas</h3>
        <div class="pp-quick-actions">
            <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-quick-action">
                <span class="pp-icon--pages"></span>
                <span>Crear página</span>
            </a>
            <a href="<?= e(base_url('admin/memory')) ?>" class="pp-quick-action">
                <span class="pp-icon--memory"></span>
                <span>Definir conocimiento</span>
            </a>
            <a href="<?= e(base_url('admin/design')) ?>" class="pp-quick-action">
                <span class="pp-icon--design"></span>
                <span>Configurar diseño</span>
            </a>
            <a href="<?= e(base_url('admin/settings')) ?>" class="pp-quick-action">
                <span class="pp-icon--settings"></span>
                <span>Ajustes</span>
            </a>
        </div>
    </div>

    <div class="pp-dashboard__grid">

        <!-- Recent pages -->
        <div class="pp-dashboard__section">
            <div class="pp-section-header">
                <h3>Páginas recientes</h3>
                <?php if (!empty($recentPages)): ?>
                <a href="<?= e(base_url('admin/pages')) ?>" class="pp-link">Ver todas →</a>
                <?php endif; ?>
            </div>

            <?php if (empty($recentPages)): ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__title">Aún no hay páginas</div>
                <div class="pp-empty__text">Crea tu primera página para empezar.</div>
                <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-btn pp-btn--primary">
                    Crear página
                </a>
            </div>
            <?php else: ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Actualizada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPages as $p): ?>
                        <tr>
                            <td>
                                <a href="<?= e(base_url('admin/pages/' . $p['id'] . '/edit')) ?>">
                                    <?= e($p['title']) ?>
                                </a>
                            </td>
                            <td><?= e($pageTypeLabels[$p['page_type']] ?? $p['page_type']) ?></td>
                            <td>
                                <?php if ($p['status'] === 'published'): ?>
                                <span class="pp-badge pp-badge--success">Publicada</span>
                                <?php else: ?>
                                <span class="pp-badge pp-badge--muted">Borrador</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($fmtDate($p['updated_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent AI calls -->
        <div class="pp-dashboard__section">
            <div class="pp-section-header">
                <h3>Actividad IA reciente</h3>
                <?php if (!empty($recentAILogs)): ?>
                <a href="<?= e(base_url('admin/ai-usage')) ?>" class="pp-link">Ver todo →</a>
                <?php endif; ?>
            </div>

            <?php if (empty($recentAILogs)): ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__title">Sin actividad IA todavía</div>
                <div class="pp-empty__text">Cuando uses la IA para generar contenido, verás el historial aquí.</div>
            </div>
            <?php else: ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Acción</th>
                            <th>Modelo</th>
                            <th>Tokens</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAILogs as $log): ?>
                        <tr>
                            <td>
                                <?= e($actionLabels[$log['action_type']] ?? $log['action_type']) ?>
                                <?php if ($log['status'] === 'error'): ?>
                                <span class="pp-badge pp-badge--danger">Error</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?= e($log['model']) ?></small></td>
                            <td>
                                <?= $fmtNum($log['tokens_input']) ?> / <?= $fmtNum($log['tokens_output']) ?>
                            </td>
                            <td><?= e($fmtDate($log['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

</div>
