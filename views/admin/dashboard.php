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
// Las CLAVES son valores de base de datos y no se traducen nunca; solo su etiqueta.
$pageTypeLabels = [
    'home' => __('page_type.home'), 'service' => __('page_type.service'), 'product' => __('page_type.product'),
    'landing' => __('page_type.landing'), 'article' => __('page_type.article'), 'contact' => __('page_type.contact'),
];
$actionLabels = [
    'generate_structure' => __('ai_action.generate_structure'),
    'generate_section'   => __('ai_action.generate_section'),
    'rewrite'            => __('ai_action.rewrite'),
    'improve_seo'        => __('ai_action.improve_seo'),
    'summarize'          => __('ai_action.summarize'),
    'test'               => __('ai_action.test'),
];
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.dashboard')) ?><?php \Core\View::end(); ?>

<div class="pp-dashboard">

    <div class="pp-dashboard__welcome">
        <h2><?= e(__('dashboard.greeting', ['nombre' => $userName])) ?> 👋</h2>
        <p><?= e(__('dashboard.summary_of')) ?> <strong><?= e($siteName) ?></strong>.</p>
    </div>

    <?php
    // E-GDPR G6 — Widget de cumplimiento. Solo se muestra cuando hay gaps.
    $compliance = $compliance ?? ['level' => 'green', 'gaps' => []];
    if (($compliance['level'] ?? 'green') !== 'green' && !empty($compliance['gaps'])):
        $level = $compliance['level'];
        $wizardPending = !($wizardCompleted ?? true);
        if ($wizardPending) {
            $title = __('dashboard.compliance.wizard_title');
            $ctaLabel = __('dashboard.compliance.wizard_cta');
            $ctaUrl   = base_url('admin/privacy/wizard');
        } else {
            $title = match ($level) {
                'red'    => __('dashboard.compliance.title_red'),
                'orange' => __('dashboard.compliance.title_orange'),
                default  => __('dashboard.compliance.title_yellow'),
            };
            $ctaLabel = __('dashboard.compliance.cta');
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
                <?php // Plural explícito: hay idiomas donde no basta con añadir una 's'.
                $gapCount = count($compliance['gaps']); ?>
                <span><?= e(__($gapCount === 1 ? 'dashboard.compliance.points_one' : 'dashboard.compliance.points_other', ['n' => $gapCount])) ?></span>
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
            <span class="pp-stat__label"><?= e(__('dashboard.stat.ai_calls')) ?></span>
            <span class="pp-stat__value"><?= $fmtNum($countAILogs) ?></span>
            <?php if ($countAILogs > 0): ?>
            <span class="pp-stat__sub">
                <?= $fmtNum($aiTokensInput + $aiTokensOutput) ?> tokens · <?= $fmtCost($aiCostTotal) ?>
            </span>
            <?php else: ?>
            <span class="pp-stat__sub"><?= e(__('dashboard.stat.no_activity')) ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-stat">
            <span class="pp-stat__label"><?= e(__('nav.pages')) ?></span>
            <span class="pp-stat__value"><?= $fmtNum($countPages) ?></span>
            <?php if ($countPages > 0): ?>
            <span class="pp-stat__sub">
                <?= e(__('dashboard.stat.pages_sub', ['publicadas' => $fmtNum($countPublished), 'borradores' => $fmtNum($countDrafts)])) ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= e(base_url('admin/media')) ?>" class="pp-stat">
            <span class="pp-stat__label"><?= e(__('nav.media')) ?></span>
            <span class="pp-stat__value"><?= $fmtNum($countMedia) ?></span>
        </a>

        <a href="<?= e(base_url('admin/documents')) ?>" class="pp-stat">
            <span class="pp-stat__label"><?= e(__('nav.documents')) ?></span>
            <span class="pp-stat__value"><?= $fmtNum($countDocuments) ?></span>
        </a>
    </section>

    <!-- Quick actions -->
    <div class="pp-dashboard__section">
        <h3><?= e(__('dashboard.quick_actions')) ?></h3>
        <div class="pp-quick-actions">
            <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-quick-action">
                <span class="pp-icon--pages"></span>
                <span><?= e(__('dashboard.quick.create_page')) ?></span>
            </a>
            <a href="<?= e(base_url('admin/memory')) ?>" class="pp-quick-action">
                <span class="pp-icon--memory"></span>
                <span><?= e(__('dashboard.quick.define_knowledge')) ?></span>
            </a>
            <a href="<?= e(base_url('admin/design')) ?>" class="pp-quick-action">
                <span class="pp-icon--design"></span>
                <span><?= e(__('dashboard.quick.configure_design')) ?></span>
            </a>
            <a href="<?= e(base_url('admin/settings')) ?>" class="pp-quick-action">
                <span class="pp-icon--settings"></span>
                <span><?= e(__('nav.settings')) ?></span>
            </a>
        </div>
    </div>

    <div class="pp-dashboard__grid">

        <!-- Recent pages -->
        <div class="pp-dashboard__section">
            <div class="pp-section-header">
                <h3><?= e(__('dashboard.recent_pages')) ?></h3>
                <?php if (!empty($recentPages)): ?>
                <a href="<?= e(base_url('admin/pages')) ?>" class="pp-link"><?= e(__('dashboard.see_all')) ?> →</a>
                <?php endif; ?>
            </div>

            <?php if (empty($recentPages)): ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__title"><?= e(__('dashboard.empty_pages.title')) ?></div>
                <div class="pp-empty__text"><?= e(__('dashboard.empty_pages.text')) ?></div>
                <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-btn pp-btn--primary">
                    <?= e(__('dashboard.quick.create_page')) ?>
                </a>
            </div>
            <?php else: ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th><?= e(__('table.title')) ?></th>
                            <th><?= e(__('table.type')) ?></th>
                            <th><?= e(__('table.status')) ?></th>
                            <th><?= e(__('table.updated')) ?></th>
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
                                <span class="pp-badge pp-badge--success"><?= e(__('status.published')) ?></span>
                                <?php else: ?>
                                <span class="pp-badge pp-badge--muted"><?= e(__('status.draft')) ?></span>
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
                <h3><?= e(__('dashboard.recent_ai')) ?></h3>
                <?php if (!empty($recentAILogs)): ?>
                <a href="<?= e(base_url('admin/ai-usage')) ?>" class="pp-link"><?= e(__('dashboard.see_all')) ?> →</a>
                <?php endif; ?>
            </div>

            <?php if (empty($recentAILogs)): ?>
            <div class="pp-empty pp-empty--inline">
                <div class="pp-empty__title"><?= e(__('dashboard.empty_ai.title')) ?></div>
                <div class="pp-empty__text"><?= e(__('dashboard.empty_ai.text')) ?></div>
            </div>
            <?php else: ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th><?= e(__('table.action')) ?></th>
                            <th><?= e(__('table.model')) ?></th>
                            <th>Tokens</th>
                            <th><?= e(__('table.date')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAILogs as $log): ?>
                        <tr>
                            <td>
                                <?= e($actionLabels[$log['action_type']] ?? $log['action_type']) ?>
                                <?php if ($log['status'] === 'error'): ?>
                                <span class="pp-badge pp-badge--danger"><?= e(__('status.error')) ?></span>
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
