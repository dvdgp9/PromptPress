<?php
/**
 * @var string $tab
 * @var string $csrf
 * @var array $kpis
 * @var array $redirects
 * @var array $notFound
 * @var string $notFoundStatus
 * @var array $metaIssues
 * @var array $linkIssues
 * @var array $linksByPage
 * @var array $sectionTypes
 * @var array $indexation
 * @var array $technicalIssues
 */
\Core\View::extend('admin/layout');

$tabUrl = static fn(string $name): string => base_url('admin/seo?tab=' . $name);
$isTab = static fn(string $name): string => $tab === $name ? ' is-active' : '';
$fmtDate = static fn(?string $date): string => $date ? date('d/m/Y H:i', strtotime($date)) : 'Sin datos';
$statusLabel = static fn(int $code): string => $code === 410 ? '410 retirado' : ($code === 302 ? '302 temporal' : '301 permanente');
?>

<?php \Core\View::start('title'); ?>SEO<?php \Core\View::end(); ?>

<section class="pp-seo-shell">
    <header class="pp-seo-hero">
        <div>
            <span class="pp-seo-eyebrow"><?= e(__('seo.eyebrow')) ?></span>
            <h2><?= e(__('seo.title')) ?></h2>
            <p><?= e(__('seo.intro')) ?></p>
        </div>
        <div class="pp-seo-hero__status" aria-label="<?= e(__('seo.status_aria')) ?>">
            <span><?= (int) $kpis['open404'] ?></span>
            <small><?= e(__('seo.open_404')) ?></small>
        </div>
    </header>

    <nav class="pp-seo-tabs" aria-label="<?= e(__('seo.tabs_aria')) ?>">
        <a class="pp-seo-tab<?= $isTab('summary') ?>" href="<?= e($tabUrl('summary')) ?>"><?= e(__('seo.tab_summary')) ?></a>
        <a class="pp-seo-tab<?= $isTab('404') ?>" href="<?= e($tabUrl('404')) ?>">404</a>
        <a class="pp-seo-tab<?= $isTab('redirects') ?>" href="<?= e($tabUrl('redirects')) ?>"><?= e(__('seo.tab_redirects')) ?></a>
        <a class="pp-seo-tab<?= $isTab('links') ?>" href="<?= e($tabUrl('links')) ?>"><?= e(__('links.title')) ?></a>
        <a class="pp-seo-tab<?= $isTab('advanced') ?>" href="<?= e($tabUrl('advanced')) ?>"><?= e(__('seo.tab_advanced')) ?></a>
    </nav>

    <?php if ($tab === 'summary'): ?>
        <div class="pp-seo-kpis">
            <article class="pp-seo-kpi pp-seo-kpi--wide">
                <span><?= e(__('seo.open_404')) ?></span>
                <strong><?= (int) $kpis['open404'] ?></strong>
                <p><?= e(__('seo.card_404_help')) ?></p>
                <a href="<?= e($tabUrl('404')) ?>"><?= e(__('seo.review_404')) ?></a>
            </article>
            <article class="pp-seo-kpi">
                <span><?= e(__('seo.active_redirects')) ?></span>
                <strong><?= (int) $kpis['activeRedirects'] ?></strong>
                <p><?= e(__('seo.redirects_help')) ?></p>
                <a href="<?= e($tabUrl('redirects')) ?>"><?= e(__('seo.manage')) ?></a>
            </article>
            <article class="pp-seo-kpi">
                <span><?= e(__('seo.links_to_check')) ?></span>
                <strong><?= (int) $kpis['linkIssues'] ?></strong>
                <p><?= e(__('seo.links_help')) ?></p>
                <a href="<?= e($tabUrl('links')) ?>"><?= e(__('seo.see_links')) ?></a>
            </article>
            <article class="pp-seo-kpi">
                <span><?= e(__('seo.metas_to_improve')) ?></span>
                <strong><?= (int) $kpis['metaIssues'] ?></strong>
                <p><?= e(__('seo.metas_help')) ?></p>
            </article>
            <article class="pp-seo-kpi">
                <span><?= e(__('seo.sitemap_robots')) ?></span>
                <strong><?= (int) ($indexation['published_pages'] ?? 0) ?></strong>
                <p><?= e(__('seo.sitemap_help')) ?></p>
                <a href="<?= e((string) ($indexation['sitemap_url'] ?? base_url('sitemap.xml'))) ?>" target="_blank" rel="noopener">Abrir sitemap</a>
            </article>
            <article class="pp-seo-kpi">
                <span><?= e(__('seo.advanced_audit')) ?></span>
                <strong><?= (int) $kpis['technicalIssues'] ?></strong>
                <p><?= e(__('seo.advanced_help')) ?></p>
                <a href="<?= e($tabUrl('advanced')) ?>"><?= e(__('seo.see_advanced')) ?></a>
            </article>
        </div>

        <section class="pp-seo-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3><?= e(__('seo.public_indexation')) ?></h3>
                    <p><?= e(__('seo.indexation_help')) ?></p>
                </div>
            </div>
            <div class="pp-seo-indexation">
                <a href="<?= e((string) ($indexation['sitemap_url'] ?? base_url('sitemap.xml'))) ?>" target="_blank" rel="noopener">
                    <span>Sitemap XML</span>
                    <code><?= e((string) ($indexation['sitemap_url'] ?? base_url('sitemap.xml'))) ?></code>
                </a>
                <a href="<?= e((string) ($indexation['robots_url'] ?? base_url('robots.txt'))) ?>" target="_blank" rel="noopener">
                    <span>Robots.txt</span>
                    <code><?= e((string) ($indexation['robots_url'] ?? base_url('robots.txt'))) ?></code>
                </a>
            </div>
        </section>

        <section class="pp-seo-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3><?= e(__('seo.pending_metas')) ?></h3>
                    <p><?= e(__('seo.pending_metas_help')) ?></p>
                </div>
            </div>
            <?php if (empty($metaIssues)): ?>
                <div class="pp-seo-empty">
                    <strong><?= e(__('seo.metas_ok')) ?></strong>
                    <span><?= e(__('seo.metas_ok_hint')) ?></span>
                </div>
            <?php else: ?>
                <div class="pp-seo-meta-list">
                    <?php foreach (array_slice($metaIssues, 0, 8) as $m): ?>
                        <article class="pp-seo-meta-item">
                            <div>
                                <strong><?= e((string) $m['title']) ?></strong>
                                <code>/<?= e((string) $m['slug']) ?></code>
                                <span><?= e(implode(' · ', $m['notes'])) ?></span>
                            </div>
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e((string) $m['edit_url']) ?>"><?= e(__('seo.open_editor')) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'redirects'): ?>
        <section class="pp-seo-split">
            <form method="POST" action="<?= e(base_url('admin/seo/redirects')) ?>" class="pp-seo-panel pp-seo-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="pp-seo-panel__head">
                    <div>
                        <h3><?= e(__('seo.new_redirect')) ?></h3>
                        <p><?= __('seo.new_redirect_help.html') ?></p>
                    </div>
                </div>
                <label>URL antigua
                    <input class="pp-input" name="source_path" placeholder="<?= e(__('seo.old_url')) ?>" required>
                    <small><?= e(__('seo.old_url_help')) ?></small>
                </label>
                <label><?= e(__('seo.send_to')) ?>
                    <input class="pp-input" name="target_path" placeholder="<?= e(__('seo.new_url')) ?>">
                    <small><?= e(__('seo.410_help')) ?></small>
                </label>
                <label>Tipo
                    <select class="pp-input" name="status_code">
                        <option value="301">301 <?= e(__('seo.permanent')) ?></option>
                        <option value="302">302 <?= e(__('seo.temporary')) ?></option>
                        <option value="410">410 <?= e(__('seo.gone')) ?></option>
                    </select>
                </label>
                <button class="pp-btn pp-btn--primary" type="submit"><?= e(__('seo.save_redirect')) ?></button>
            </form>

            <section class="pp-seo-panel">
                <div class="pp-seo-panel__head">
                    <div>
                        <h3><?= e(__('seo.tab_redirects')) ?></h3>
                        <p><?= e(__('seo.auto_redirects_help')) ?></p>
                    </div>
                </div>
                <?php if (empty($redirects)): ?>
                    <div class="pp-seo-empty">
                        <strong><?= e(__('seo.no_redirects')) ?></strong>
                        <span><?= e(__('seo.no_redirects_hint')) ?></span>
                    </div>
                <?php else: ?>
                    <div class="pp-seo-table">
                        <?php foreach ($redirects as $r): ?>
                            <div class="pp-seo-row">
                                <div>
                                    <code><?= e((string) $r['source_path']) ?></code>
                                    <span><?= e($statusLabel((int) $r['status_code'])) ?><?= (int) $r['auto_created'] === 1 ? ' · ' . e(__('seo.automatic')) : '' ?></span>
                                </div>
                                <div>
                                    <code><?= e((string) ($r['target_path'] ?? __('seo.no_target'))) ?></code>
                                    <span><?= e(__('seo.n_visits', ['n' => (int) $r['hit_count']])) ?><?= !empty($r['last_hit_at']) ? ' · ' . e($fmtDate((string) $r['last_hit_at'])) : '' ?></span>
                                </div>
                                <form method="POST" action="<?= e(base_url('admin/seo/redirects/' . (int) $r['id'])) ?>" class="pp-seo-row__actions">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <?php if ((int) $r['is_active'] === 1): ?>
                                        <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="deactivate"><?= e(__('seo.pause')) ?></button>
                                    <?php else: ?>
                                        <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="activate"><?= e(__('modules.enable')) ?></button>
                                    <?php endif; ?>
                                    <button class="pp-btn pp-btn--ghost pp-btn--sm" name="action" value="delete"><?= e(__('common.delete')) ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </section>
    <?php endif; ?>

    <?php if ($tab === '404'): ?>
        <section class="pp-seo-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3><?= e(__('seo.monitor_404')) ?></h3>
                    <p><?= e(__('seo.monitor_404_help')) ?></p>
                </div>
                <div class="pp-seo-filter">
                    <a class="<?= $notFoundStatus === 'open' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=open')) ?>"><?= e(__('seo.open')) ?></a>
                    <a class="<?= $notFoundStatus === 'resolved' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=resolved')) ?>"><?= e(__('seo.resolved')) ?></a>
                    <a class="<?= $notFoundStatus === 'ignored' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=ignored')) ?>"><?= e(__('seo.ignored')) ?></a>
                </div>
            </div>
            <?php if (empty($notFound)): ?>
                <div class="pp-seo-empty">
                    <strong><?= e(__('seo.no_404')) ?></strong>
                    <span><?= e(__('seo.no_404_hint')) ?></span>
                </div>
            <?php else: ?>
                <div class="pp-seo-404-list">
                    <?php foreach ($notFound as $n): ?>
                        <article class="pp-seo-404">
                            <div class="pp-seo-404__main">
                                <code><?= e((string) $n['requested_path']) ?></code>
                                <span><?= e(__('seo.n_visits', ['n' => (int) $n['hit_count']])) ?> · <?= e(__('seo.last')) ?>: <?= e($fmtDate((string) $n['last_seen_at'])) ?></span>
                                <?php if (!empty($n['referrer'])): ?><small><?= e(__('inbox.origin')) ?>: <?= e((string) $n['referrer']) ?></small><?php endif; ?>
                            </div>
                            <form method="POST" action="<?= e(base_url('admin/seo/redirects')) ?>" class="pp-seo-404__redirect">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="source_path" value="<?= e((string) $n['requested_path']) ?>">
                                <input type="hidden" name="from_404_id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="status_code" value="301">
                                <label><?= e(__('seo.send_to')) ?>
                                    <input class="pp-input" name="target_path" placeholder="<?= e(__('seo.destination')) ?>">
                                </label>
                                <button class="pp-btn pp-btn--primary pp-btn--sm"><?= e(__('seo.create_301')) ?></button>
                            </form>
                            <form method="POST" action="<?= e(base_url('admin/seo/404/' . (int) $n['id'])) ?>" class="pp-seo-row__actions">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="resolved"><?= e(__('seo.mark_resolved')) ?></button>
                                <button class="pp-btn pp-btn--ghost pp-btn--sm" name="action" value="ignore"><?= e(__('seo.ignore')) ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'links'): ?>
        <section class="pp-seo-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3><?= e(__('links.title')) ?></h3>
                    <p><?= e(__('seo.links_panel_help')) ?></p>
                </div>
            </div>
            <?php if (empty($linkIssues)): ?>
                <div class="pp-seo-empty">
                    <strong><?= e(__('seo.links_ok')) ?></strong>
                    <span><?= e(__('seo.links_ok_hint')) ?></span>
                </div>
            <?php else: ?>
                <?php foreach ($linksByPage as $pageId => $group): ?>
                    <div class="pp-seo-link-group">
                        <div class="pp-seo-link-group__head">
                            <h4><?= e((string) $group['title']) ?></h4>
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages/' . (int) $pageId . '/edit')) ?>"><?= e(__('links.edit_page')) ?></a>
                        </div>
                        <?php foreach ($group['issues'] as $issue): ?>
                            <div class="pp-seo-link-issue">
                                <code><?= e((string) $issue['link']) ?></code>
                                <span><?= e($sectionTypes[$issue['section_type']] ?? $issue['section_type']) ?></span>
                                <strong><?= $issue['problem'] === 'missing' ? 'No existe' : 'En borrador' ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'advanced'): ?>
        <section class="pp-seo-panel pp-seo-advanced-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3><?= e(__('seo.tech_audit')) ?></h3>
                    <p><?= e(__('seo.tech_audit_help')) ?></p>
                </div>
            </div>
            <div class="pp-seo-advanced-note">
                <strong><?= e(__('seo.advanced_zone')) ?></strong>
                <span><?= e(__('seo.advanced_zone_help')) ?></span>
            </div>
            <?php if (empty($technicalIssues)): ?>
                <div class="pp-seo-empty">
                    <strong><?= e(__('seo.no_tech_issues')) ?></strong>
                    <span><?= e(__('seo.no_tech_issues_hint')) ?></span>
                </div>
            <?php else: ?>
                <div class="pp-seo-technical-list">
                    <?php foreach ($technicalIssues as $issue): ?>
                        <?php
                            $severity = (string) ($issue['severity'] ?? 'info');
                            $editUrl = (string) (
                                (($issue['page_type'] ?? '') === 'article')
                                    ? base_url('admin/posts/' . (int) $issue['page_id'] . '/edit')
                                    : ((($issue['render_mode'] ?? '') === 'canvas')
                                        ? base_url('admin/canvas/' . (int) $issue['page_id'])
                                        : base_url('admin/pages/' . (int) $issue['page_id'] . '/edit'))
                            );
                        ?>
                        <article class="pp-seo-technical-item pp-seo-technical-item--<?= e($severity) ?>">
                            <div>
                                <span><?= e(strtoupper($severity)) ?></span>
                                <strong><?= e((string) $issue['label']) ?></strong>
                                <p><?= e((string) $issue['detail']) ?></p>
                                <code>/<?= e((string) $issue['slug']) ?></code>
                            </div>
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e($editUrl) ?>"><?= e(__('seo.open_editor')) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
