<?php
/**
 * Mapa del sitio + lista de páginas.
 * @var array $pages
 * @var array $pageTree
 * @var array $pageTypes
 * @var array $pageOptions
 * @var string $csrf
 * @var array $aiMeta
 */
\Core\View::extend('admin/layout');

$fmtDate = function ($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i', $ts) : $d;
};

$statusBadge = function (array $p): string {
    return ($p['status'] ?? '') === 'published'
        ? '<span class="pp-badge pp-badge--success">' . e(__('status.published')) . '</span>'
        : '<span class="pp-badge pp-badge--muted">' . e(__('status.draft')) . '</span>';
};
// FH6 — las páginas canvas (HTML libre + chat) se distinguen y se editan en el Studio.
$canvasBadge = function (array $p): string {
    return ($p['render_mode'] ?? 'sections') === 'canvas'
        ? ' <span class="pp-badge pp-badge--canvas" title="' . e(__('pages.canvas_title')) . '">Canvas</span>'
        : '';
};

$typeInitial = function (array $p) use ($pageTypes): string {
    $label = (string) ($pageTypes[$p['page_type']] ?? $p['page_type'] ?? __('js.onb.page'));
    return mb_strtoupper(mb_substr($label, 0, 1));
};

// PAGES-OPS G2/G4 — La portada se ve de un vistazo: hasta ahora el único
// indicio era una clase en la barra de "navegación probable".
$homeBadge = function (array $p): string {
    return ($p['page_type'] ?? '') === 'home'
        ? ' <span class="pp-badge pp-badge--home" title="' . e(__('pages.home_title')) . '">' . e(__('page_type.home')) . '</span>'
        : '';
};

// Adónde lleva "Ver": a la URL pública si está publicada, y al preview del
// panel si es un borrador (que en la web pública daría 404).
$viewUrl = function (array $p): string {
    if (($p['status'] ?? '') !== 'published') {
        return base_url('admin/pages/' . (int) $p['id'] . '/preview');
    }
    return ($p['page_type'] ?? '') === 'home'
        ? base_url('')
        : base_url(ltrim((string) $p['slug'], '/'));
};

/**
 * PAGES-OPS G2 — Los datos que necesita cualquier acción sobre la página. Van
 * en el contenedor (fila o tarjeta) y los lee el mismo JS para las dos vistas:
 * si cada vista tuviera su propio marcado, acabarían divergiendo.
 */
$actionData = function (array $p) use ($viewUrl): string {
    $id = (int) $p['id'];
    return ' data-page-id="' . $id . '"'
        . ' data-page-title="' . e((string) $p['title']) . '"'
        . ' data-page-status="' . e((string) ($p['status'] ?? 'draft')) . '"'
        . ' data-page-type="' . e((string) ($p['page_type'] ?? '')) . '"'
        . ' data-page-slug="' . e((string) $p['slug']) . '"'
        . ' data-page-lang="' . e((string) ($p['language'] ?? '')) . '"'
        . ' data-page-view="' . e($viewUrl($p)) . '"'
        . ' data-page-edit="' . e(base_url('admin/pages/' . $id . '/edit')) . '"';
};

$parentOptions = function (?int $currentId, ?int $selectedId) use ($pageOptions) {
    $html = '<option value="">' . e(__('pages.root')) . '</option>';
    foreach ($pageOptions as $opt) {
        if ((int) $opt['id'] === (int) $currentId) continue;
        $selected = (int) $opt['id'] === (int) $selectedId ? ' selected' : '';
        $html .= '<option value="' . (int) $opt['id'] . '"' . $selected . '>'
              . e($opt['label']) . ' · /' . e($opt['slug']) . '</option>';
    }
    return $html;
};

$renderNode = function (array $node) use (&$renderNode, $pageTypes, $statusBadge, $canvasBadge, $homeBadge, $parentOptions, $typeInitial, $actionData, $viewUrl) {
    $id = (int) $node['id'];
    $label = (string) (($node['nav_label'] ?? '') ?: $node['title']);
    $children = (array) ($node['children'] ?? []);
    $depth = (int) ($node['depth'] ?? 0);
    $status = (string) ($node['status'] ?? 'draft');
    ob_start();
    ?>
    <li class="pp-map-node pp-map-node--depth-<?= min($depth, 3) ?>" data-page-id="<?= $id ?>" data-page-lang="<?= e((string) ($node['language'] ?? '')) ?>">
        <article class="pp-map-card pp-map-card--<?= e($status) ?>"
                 draggable="true"
                 <?= $actionData($node) ?>
                 data-page-label="<?= e($label) ?>"
                 data-page-parent="<?= e((string) ($node['parent_id'] ?? '')) ?>"
                 data-page-nav="<?= e((string) ($node['nav_label'] ?? '')) ?>"
                 data-page-order="<?= (int) ($node['tree_sort_order'] ?? 0) ?>"
                 data-page-preview="<?= e(base_url('admin/pages/' . $id . '/preview')) ?>"
                 data-page-structure="<?= e('/admin/pages/' . $id . '/structure') ?>">
            <div class="pp-map-card__main">
                <div class="pp-map-card__top">
                    <span class="pp-map-card__mark" aria-hidden="true"><?= e($typeInitial($node)) ?></span>
                    <div class="pp-map-card__title">
                        <span class="pp-map-card__type"><?= e($pageTypes[$node['page_type']] ?? $node['page_type']) ?></span>
                        <h3><?= e($label) ?></h3>
                    </div>
                    <?= $statusBadge($node) . $homeBadge($node) . $canvasBadge($node) ?>
                </div>
                <div class="pp-map-card__meta">
                    <code>/<?= e($node['slug']) ?></code>
                    <span><?= e(__('pages.children', ['n' => count($children)])) ?></span>
                </div>
                <div class="pp-map-card__actions">
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e($viewUrl($node)) ?>" target="_blank" rel="noopener"><?= e(__('common.view')) ?></a>
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>"><?= e(__('common.edit')) ?></a>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-inspect-page="<?= $id ?>"><?= e(__('pages.structure')) ?></button>
                    <?php // Las acciones que cambian o destruyen van en un menú:
                          // en una tarjeta pequeña, "Eliminar" a un click del resto
                          // se pulsa sin querer. ?>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm pp-page-menu-btn"
                            data-page-menu aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('pages.more_actions')) ?>">⋯</button>
                </div>
            </div>
        </article>
        <?php if ($children !== []): ?>
        <ol class="pp-map-children">
            <?php foreach ($children as $child): ?>
                <?= $renderNode($child) ?>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </li>
    <?php
    return ob_get_clean();
};

$pagesCount = count($pages);
$rootsCount = count($pageTree);
$draftsCount = count(array_filter($pages, fn($p) => ($p['status'] ?? '') !== 'published'));
$publishedCount = $pagesCount - $draftsCount;
$pageMapPayload = array_map(fn($p) => [
    'id' => (int) $p['id'],
    'parent_id' => isset($p['parent_id']) ? (int) $p['parent_id'] : null,
    'title' => (string) $p['title'],
    'label' => (string) (($p['nav_label'] ?? '') ?: $p['title']),
    'slug' => (string) $p['slug'],
], $pages);
?>

<?php \Core\View::start('title'); ?>Mapa del sitio<?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-pages-map-mode<?php \Core\View::end(); ?>
<?php \Core\View::start('scripts'); ?>
<?php // Versionado con filemtime: sin él, el navegador sirve el JS viejo y los
      // cambios "no funcionan" aunque el código esté bien. ?>
<script src="<?= e(base_url('admin/assets/js/pages-map.js')) ?>?v=<?= @filemtime(PP_ROOT . '/admin/assets/js/pages-map.js') ?: '1' ?>"></script>
<?php \Core\View::end(); ?>

<section class="pp-site-map"
         id="pp-site-map"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>"
         data-ai-configured="<?= !empty($aiMeta['configured']) ? '1' : '0' ?>">
    <script type="application/json" id="pp-map-pages-data"><?= json_encode($pageMapPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <div class="pp-page-header pp-map-header">
        <div>
            <span class="pp-map-eyebrow"><?= e(__('pages.eyebrow')) ?></span>
            <h2><?= e(__('pages.title')) ?></h2>
            <p class="pp-page-intro"><?= e(__('pages.intro')) ?></p>
        </div>
        <div class="pp-page-header__actions">
            <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--primary"><?= e(__('pages.new_ai')) ?></a>
            <span class="pp-map-header__sep" aria-hidden="true"></span>
            <button type="button" class="pp-btn pp-btn--secondary" id="pp-architect-run"><?= e(__('pages.analyze')) ?></button>
            <a href="<?= e(base_url('admin/links')) ?>" class="pp-btn pp-btn--secondary"><?= e(__('pages.check_links')) ?></a>
            <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-link pp-map-header__manual"><?= e(__('pages.create_manual')) ?></a>
        </div>
    </div>

<?php if (!empty($isMultilingual) && !empty($translationCoverage)): ?>
    <div class="pp-tr-summary">
        <div class="pp-tr-summary__head">
            <strong><?= e(__('pages.translations')) ?></strong>
            <span><?= e(__('pages.translations_help')) ?></span>
        </div>
        <?php foreach ($translationCoverage as $code => $cov): ?>
            <div class="pp-tr-lang<?= $untranslatedFilter === $code ? ' is-filtered' : '' ?>">
                <div class="pp-tr-lang__top">
                    <span class="pp-tr-lang__name"><?= e($languageLabels[$code] ?? $code) ?></span>
                    <span class="pp-tr-lang__count">
                        <?= e(__('pages.translated_count', ['hechas' => (int) $cov['done'], 'total' => (int) $cov['total']])) ?>
                    </span>
                </div>
                <div class="pp-tr-bar" role="img"
                     aria-label="<?= e(__('pages.translated_aria', ['pct' => (int) $cov['percent'], 'idioma' => $languageLabels[$code] ?? $code])) ?>">
                    <span style="width:<?= (int) $cov['percent'] ?>%"></span>
                </div>
                <div class="pp-tr-lang__foot">
                    <?php if ((int) $cov['missing'] === 0): ?>
                        <span class="pp-tr-done-msg"><?= e(__('pages.all_translated')) ?> 🎉</span>
                    <?php else: ?>
                        <?php if ($untranslatedFilter === $code): ?>
                            <a href="<?= e(base_url('admin/pages')) ?>"><?= e(__('pages.see_all')) ?></a>
                        <?php else: ?>
                            <a href="<?= e(base_url('admin/pages?sin_traducir=' . urlencode((string) $code))) ?>">
                                <?= e(__('pages.see_missing', ['n' => (int) $cov['missing']])) ?>
                            </a>
                        <?php endif; ?>
                        <button type="button" class="pp-tr-bulk-btn"
                                data-pp-translate-all="<?= e($code) ?>"
                                data-pp-lang-label="<?= e($languageLabels[$code] ?? $code) ?>"
                                data-pp-missing="<?= (int) $cov['missing'] ?>">
                            <?= e(__('pages.translate_all', ['n' => (int) $cov['missing']])) ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($untranslatedFilter)): ?>
    <div class="pp-tr-filter-banner">
        <?= __('pages.filter_banner.html', ['idioma' => e($languageLabels[$untranslatedFilter] ?? $untranslatedFilter)]) ?>
        <a href="<?= e(base_url('admin/pages')) ?>"><?= e(__('pages.remove_filter')) ?></a>
    </div>
<?php endif; ?>

    <div class="pp-map-tabs" role="tablist" aria-label="<?= e(__('pages.views_aria')) ?>">
        <?php if (empty($untranslatedFilter)): ?>
            <?php // Con filtro activo se oculta el mapa: es una vista jerárquica
                  // del sitio ENTERO y contradiría el aviso de «solo lo que falta». ?>
            <button type="button" class="is-active" data-map-tab="map"><?= e(__('pages.tab_map')) ?></button>
        <?php endif; ?>
        <button type="button" class="<?= !empty($untranslatedFilter) ? 'is-active' : '' ?>" data-map-tab="list"><?= e(__('pages.tab_list')) ?></button>
    </div>

    <div class="pp-map-view<?= empty($untranslatedFilter) ? ' is-active' : '' ?>" data-map-view="map">
        <aside class="pp-architect-panel is-collapsed" id="pp-architect-panel" aria-live="polite">
            <div class="pp-architect-panel__head">
                <div>
                    <strong>AI Site Architect</strong>
                    <span><?= e(__('pages.architect_help')) ?></span>
                </div>
                <div class="pp-architect-panel__head-actions">
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-architect-refresh" hidden><?= e(__('pages.reanalyze')) ?></button>
                    <button type="button" class="pp-architect-panel__toggle" id="pp-architect-toggle" aria-expanded="false" aria-controls="pp-architect-body" aria-label="<?= e(__('pages.show_diagnosis')) ?>">
                        <span class="pp-architect-panel__chevron" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
            <div class="pp-architect-panel__body" id="pp-architect-body" hidden>
                <div class="pp-map-skeleton"><span></span><span></span><span></span></div>
            </div>
        </aside>

        <div class="pp-map-layout">
            <div class="pp-map-tree-wrap">
                    <div class="pp-map-canvas-head">
                        <div>
                            <strong><?= e(__('pages.current_architecture')) ?></strong>
                            <span><?= e(__('pages.counts', ['paginas' => (int) $pagesCount, 'raices' => (int) $rootsCount, 'publicadas' => (int) $publishedCount, 'borradores' => (int) $draftsCount])) ?></span>
                        </div>
                        <div class="pp-map-canvas-tools">
                            <div class="pp-map-legend" aria-label="<?= e(__('pages.legend_aria')) ?>">
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--real"></i><?= e(__('pages.legend_real')) ?></span>
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--draft"></i><?= e(__('status.draft')) ?></span>
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--ai"></i><?= e(__('pages.legend_ai')) ?></span>
                            </div>
                            <?php // PAGES-OPS G7 — Sin este filtro, en un sitio con
                                  // dos idiomas el árbol mezcla las traducciones como
                                  // raíces extra y deja de leerse como arquitectura. ?>
                            <?php if (!empty($isMultilingual)): ?>
                                <select class="pp-map-lang-filter" data-map-lang aria-label="<?= e(__('pages.map_lang_aria')) ?>">
                                    <?php foreach (($mapLanguages ?? []) as $code): ?>
                                        <option value="<?= e((string) $code) ?>"<?= $code === ($primaryLang ?? '') ? ' selected' : '' ?>>
                                            <?= e($languageLabels[$code] ?? $code) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <div class="pp-map-density" aria-label="<?= e(__('pages.density_aria')) ?>">
                                <button type="button" data-map-density="cozy" class="is-active"><?= e(__('pages.density_cozy')) ?></button>
                                <button type="button" data-map-density="compact"><?= e(__('pages.density_compact')) ?></button>
                            </div>
                        </div>
                </div>
                <?php if (empty($pageTree)): ?>
                    <?php if (empty($hasMemory)): ?>
                        <div class="pp-empty pp-empty--inline pp-empty--onboard">
                            <div class="pp-empty__title"><?= e(__('pages.no_memory_title')) ?></div>
                            <div class="pp-empty__text"><?= e(__('pages.no_memory_text')) ?></div>
                            <div class="pp-empty__actions">
                                <a href="<?= e(base_url('admin/memory')) ?>" class="pp-btn pp-btn--primary"><?= e(__('pages.configure_site')) ?></a>
                                <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-link"><?= e(__('pages.create_anyway')) ?></a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="pp-empty pp-empty--inline">
                            <div class="pp-empty__title"><?= e(__('pages.no_architecture')) ?></div>
                            <div class="pp-empty__text"><?= e(__('pages.no_architecture_text')) ?></div>
                            <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--primary"><?= e(__('pages.create_first')) ?></a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="pp-map-nav-preview" aria-label="<?= e(__('pages.nav_preview_aria')) ?>">
                        <div>
                            <strong><?= e(__('pages.likely_nav')) ?></strong>
                            <span><?= e(__('pages.likely_nav_help')) ?></span>
                        </div>
                        <nav>
                            <?php foreach ($pageTree as $node): ?>
                                <?php $navLabel = (string) (($node['nav_label'] ?? '') ?: $node['title']); ?>
                                <button type="button"
                                        data-focus-page="<?= (int) $node['id'] ?>"
                                        class="<?= ($node['page_type'] ?? '') === 'home' ? 'is-home' : '' ?>">
                                    <?= e($navLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <div class="pp-map-workbench">
                        <div class="pp-map-canvas" aria-label="<?= e(__('pages.tree_aria')) ?>">
                            <ol class="pp-map-tree">
                                <?php foreach ($pageTree as $node): ?>
                                    <?= $renderNode($node) ?>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="pp-map-intelligence" id="pp-map-suggestions"></div>
            </div>
        </div>
    </div>

    <div class="pp-map-view<?= !empty($untranslatedFilter) ? ' is-active' : '' ?>" data-map-view="list"<?= empty($untranslatedFilter) ? ' hidden' : '' ?>>
        <?php if (empty($pages)): ?>
            <div class="pp-empty">
                <div class="pp-empty__title"><?= e(__('pages.empty_title')) ?></div>
                <div class="pp-empty__text"><?= e(__('pages.empty_text')) ?></div>
                <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--primary"><?= e(__('pages.create_first_ai')) ?></a>
            </div>
        <?php else: ?>
            <?php // PAGES-OPS G6 — Buscador y filtros en cliente: el listado ya
                  // viene entero del servidor y son decenas de páginas, no miles.
                  // Recargar la página para filtrar sería peor experiencia. ?>
            <div class="pp-pages-toolbar" data-pages-toolbar>
                <input type="search" class="pp-pages-search" placeholder="<?= e(__('pages.search_placeholder')) ?>"
                       aria-label="<?= e(__('pages.search_aria')) ?>" data-pages-search>
                <select data-pages-filter="status" aria-label="<?= e(__('pages.filter_status')) ?>">
                    <option value=""><?= e(__('pages.all_statuses')) ?></option>
                    <option value="published"><?= e(__('status.published')) ?></option>
                    <option value="draft"><?= e(__('pages.drafts')) ?></option>
                </select>
                <select data-pages-filter="type" aria-label="<?= e(__('pages.filter_type')) ?>">
                    <option value=""><?= e(__('pages.all_types')) ?></option>
                    <?php foreach ($pageTypes as $typeKey => $typeLabel): ?>
                        <option value="<?= e((string) $typeKey) ?>"><?= e((string) $typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="pp-pages-toolbar__count" data-pages-count><?= e(__('pages.n_pages', ['n' => count($pages)])) ?></span>
            </div>

            <div class="pp-pages-bulk" data-pages-bulk hidden>
                <strong><span data-bulk-count>0</span> <?= e(__('pages.selected')) ?></strong>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-bulk-action="publish"><?= e(__('pages.publish')) ?></button>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-bulk-action="draft"><?= e(__('pages.to_draft')) ?></button>
                <button type="button" class="pp-btn pp-btn--danger pp-btn--sm" data-bulk-action="delete"><?= e(__('common.delete')) ?></button>
                <button type="button" class="pp-link" data-bulk-clear><?= e(__('pages.clear_selection')) ?></button>
            </div>

            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th class="pp-pages-check"><input type="checkbox" data-bulk-all aria-label="<?= e(__('pages.select_all')) ?>"></th>
                            <th><?= e(__('table.title')) ?></th>
                            <th><?= e(__('pages.slug')) ?></th>
                            <th><?= e(__('table.type')) ?></th>
                            <th><?= e(__('table.status')) ?></th>
                            <?php if (!empty($isMultilingual)): ?>
                                <th><?= e(__('form_edit.languages')) ?></th>
                            <?php endif; ?>
                            <th><?= e(__('table.updated')) ?></th>
                            <th style="width:220px"><?= e(__('pages.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $p): ?>
                        <tr<?= $actionData($p) ?> data-pages-row
                            data-search="<?= e(mb_strtolower((string) $p['title'] . ' ' . (string) $p['slug'])) ?>">
                            <td class="pp-pages-check"><input type="checkbox" data-bulk-item value="<?= (int) $p['id'] ?>" aria-label="<?= e(__('pages.select_page', ['titulo' => (string) $p['title']])) ?>"></td>
                            <td><a href="<?= e(base_url('admin/pages/' . $p['id'] . '/edit')) ?>"><strong><?= e($p['title']) ?></strong></a></td>
                            <td><code>/<?= e($p['slug']) ?></code></td>
                            <td><?= e($pageTypes[$p['page_type']] ?? $p['page_type']) ?></td>
                            <td><?= $statusBadge($p) . $homeBadge($p) . $canvasBadge($p) ?></td>
                            <?php if (!empty($isMultilingual)): ?>
                                <td class="pp-tr-cell">
                                    <span class="pp-tr-own"><?= e($languageLabels[$p['language'] ?? ''] ?? ($p['language'] ?? '')) ?></span>
                                    <?php foreach (($translationStatus[(int) $p['id']] ?? []) as $code => $info): ?>
                                        <?php if ($info['exists']): ?>
                                            <a class="pp-tr-chip is-done"
                                               href="<?= e(base_url(($p['render_mode'] ?? '') === 'canvas'
                                                    ? 'admin/canvas/' . (int) $info['page_id']
                                                    : 'admin/pages/' . (int) $info['page_id'] . '/edit')) ?>"
                                               title="<?= e(__('pages.see_version', ['idioma' => $languageLabels[$code] ?? $code])) ?><?= $info['status'] === 'draft' ? ' ' . e(__('pages.unpublished_draft')) : '' ?>">
                                                <?= e($languageLabels[$code] ?? $code) ?><?= $info['status'] === 'draft' ? ' ·&nbsp;' . e(__('status.draft')) : '' ?>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="pp-tr-chip is-missing"
                                                    data-pp-translate="<?= (int) $p['id'] ?>"
                                                    data-pp-lang="<?= e($code) ?>"
                                                    data-pp-lang-label="<?= e($languageLabels[$code] ?? $code) ?>"
                                                    data-pp-page-title="<?= e($p['title']) ?>">
                                                + <?= e($languageLabels[$code] ?? $code) ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                            <?php endif; ?>
                            <td><small><?= e($fmtDate($p['updated_at'])) ?></small></td>
                            <td>
                                <div class="pp-actions">
                                    <a href="<?= e($viewUrl($p)) ?>" class="pp-btn pp-btn--secondary pp-btn--sm" target="_blank" rel="noopener"><?= e(__('common.view')) ?></a>
                                    <a href="<?= e(base_url('admin/pages/' . $p['id'] . '/edit')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('common.edit')) ?></a>
                                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm pp-page-menu-btn"
                                            data-page-menu aria-haspopup="true" aria-expanded="false" aria-label="<?= e(__('pages.more_actions_for', ['titulo' => (string) $p['title']])) ?>">⋯</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="pp-map-inspector" id="pp-map-inspector" tabindex="-1" hidden></div>

<?php if (!empty($isMultilingual)): ?>
    <p class="pp-tr-help">
        <?= __('pages.multilang_note.html') ?>
    </p>

    <div class="pp-tr-overlay" id="pp-tr-overlay" hidden
         data-pp-pages-url="<?= e(rtrim(base_url('admin/pages'), '/')) ?>">
        <div class="pp-tr-dialog" role="dialog" aria-modal="true" aria-labelledby="pp-tr-title">
            <h3 id="pp-tr-title"><?= e(__('pages.translate_page')) ?></h3>
            <p class="pp-tr-body" id="pp-tr-body"></p>
            <div class="pp-tr-progress" id="pp-tr-progress" hidden>
                <span class="pp-tr-spinner" aria-hidden="true"></span>
                <span id="pp-tr-progress-text"><?= e(__('pages.translating')) ?></span>
            </div>
            <ul class="pp-tr-joblist" id="pp-tr-joblist" hidden></ul>
            <div class="pp-tr-actions" id="pp-tr-actions">
                <button type="button" class="pp-btn pp-btn--secondary" data-pp-tr-cancel><?= e(__('common.cancel')) ?></button>
                <button type="button" class="pp-btn pp-btn--primary" data-pp-tr-confirm><?= e(__('pages.translate')) ?></button>
            </div>
        </div>
    </div>
    <script src="<?= e(base_url('admin/assets/js/page-translate.js?v=' . @filemtime(PP_ROOT . '/admin/assets/js/page-translate.js'))) ?>" defer></script>
<?php endif; ?>
</section>
