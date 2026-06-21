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
        ? '<span class="pp-badge pp-badge--success">Publicada</span>'
        : '<span class="pp-badge pp-badge--muted">Borrador</span>';
};
// FH6 — las páginas canvas (HTML libre + chat) se distinguen y se editan en el Studio.
$canvasBadge = function (array $p): string {
    return ($p['render_mode'] ?? 'sections') === 'canvas'
        ? ' <span class="pp-badge pp-badge--canvas" title="Página de diseño libre: se edita con el chat del Studio">Canvas</span>'
        : '';
};

$typeInitial = function (array $p) use ($pageTypes): string {
    $label = (string) ($pageTypes[$p['page_type']] ?? $p['page_type'] ?? 'Página');
    return mb_strtoupper(mb_substr($label, 0, 1));
};

$parentOptions = function (?int $currentId, ?int $selectedId) use ($pageOptions) {
    $html = '<option value="">Raíz</option>';
    foreach ($pageOptions as $opt) {
        if ((int) $opt['id'] === (int) $currentId) continue;
        $selected = (int) $opt['id'] === (int) $selectedId ? ' selected' : '';
        $html .= '<option value="' . (int) $opt['id'] . '"' . $selected . '>'
              . e($opt['label']) . ' · /' . e($opt['slug']) . '</option>';
    }
    return $html;
};

$renderNode = function (array $node) use (&$renderNode, $pageTypes, $statusBadge, $canvasBadge, $parentOptions, $typeInitial) {
    $id = (int) $node['id'];
    $label = (string) (($node['nav_label'] ?? '') ?: $node['title']);
    $children = (array) ($node['children'] ?? []);
    $depth = (int) ($node['depth'] ?? 0);
    $status = (string) ($node['status'] ?? 'draft');
    ob_start();
    ?>
    <li class="pp-map-node pp-map-node--depth-<?= min($depth, 3) ?>" data-page-id="<?= $id ?>">
        <article class="pp-map-card pp-map-card--<?= e($status) ?>"
                 data-page-type="<?= e((string) $node['page_type']) ?>"
                 data-page-title="<?= e((string) $node['title']) ?>"
                 data-page-label="<?= e($label) ?>"
                 data-page-slug="<?= e((string) $node['slug']) ?>"
                 data-page-status="<?= e($status) ?>"
                 data-page-parent="<?= e((string) ($node['parent_id'] ?? '')) ?>"
                 data-page-nav="<?= e((string) ($node['nav_label'] ?? '')) ?>"
                 data-page-order="<?= (int) ($node['tree_sort_order'] ?? 0) ?>"
                 data-page-edit="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>"
                 data-page-preview="<?= e(base_url('admin/pages/' . $id . '/preview')) ?>"
                 data-page-structure="<?= e('/admin/pages/' . $id . '/structure') ?>">
            <div class="pp-map-card__main">
                <div class="pp-map-card__top">
                    <span class="pp-map-card__mark" aria-hidden="true"><?= e($typeInitial($node)) ?></span>
                    <div class="pp-map-card__title">
                        <span class="pp-map-card__type"><?= e($pageTypes[$node['page_type']] ?? $node['page_type']) ?></span>
                        <h3><?= e($label) ?></h3>
                    </div>
                    <?= $statusBadge($node) . $canvasBadge($node) ?>
                </div>
                <div class="pp-map-card__meta">
                    <code>/<?= e($node['slug']) ?></code>
                    <span><?= count($children) ?> hijas</span>
                </div>
                <div class="pp-map-card__actions">
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>">Editar</a>
                    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages/' . $id . '/preview')) ?>">Preview</a>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-inspect-page="<?= $id ?>">Estructura</button>
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
<script src="<?= e(base_url('admin/assets/js/pages-map.js')) ?>"></script>
<?php \Core\View::end(); ?>

<section class="pp-site-map"
         id="pp-site-map"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>"
         data-ai-configured="<?= !empty($aiMeta['configured']) ? '1' : '0' ?>">
    <script type="application/json" id="pp-map-pages-data"><?= json_encode($pageMapPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <div class="pp-page-header pp-map-header">
        <div>
            <span class="pp-map-eyebrow">Arquitectura del sitio</span>
            <h2>Mapa del sitio</h2>
            <p class="pp-page-intro">Organiza la web como una estructura viva: páginas reales, ramas, huecos y próximas páginas sugeridas por IA.</p>
        </div>
        <div class="pp-page-header__actions">
            <a href="<?= e(base_url('admin/pages/ai/templates')) ?>" class="pp-btn pp-btn--primary">Crear desde plantilla</a>
            <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--secondary">Nueva página con IA</a>
            <button type="button" class="pp-btn pp-btn--secondary" id="pp-architect-run">Analizar sitio</button>
            <a href="<?= e(base_url('admin/links')) ?>" class="pp-btn pp-btn--secondary">Revisar enlaces</a>
            <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-link pp-map-header__manual">Crear manualmente</a>
        </div>
    </div>

    <div class="pp-map-tabs" role="tablist" aria-label="Vistas de páginas">
        <button type="button" class="is-active" data-map-tab="map">Mapa</button>
        <button type="button" data-map-tab="list">Lista</button>
    </div>

    <div class="pp-map-view is-active" data-map-view="map">
        <aside class="pp-architect-panel is-collapsed" id="pp-architect-panel" aria-live="polite">
            <div class="pp-architect-panel__head">
                <div>
                    <strong>AI Site Architect</strong>
                    <span>Diagnóstico bajo demanda · usa caché si nada cambió</span>
                </div>
                <div class="pp-architect-panel__head-actions">
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-architect-refresh" hidden>Reanalizar</button>
                    <button type="button" class="pp-architect-panel__toggle" id="pp-architect-toggle" aria-expanded="false" aria-controls="pp-architect-body" aria-label="Mostrar diagnóstico">
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
                            <strong>Arquitectura actual</strong>
                            <span><?= (int) $pagesCount ?> páginas · <?= (int) $rootsCount ?> raíces · <?= (int) $publishedCount ?> publicadas · <?= (int) $draftsCount ?> borradores</span>
                        </div>
                        <div class="pp-map-canvas-tools">
                            <div class="pp-map-legend" aria-label="Leyenda del mapa">
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--real"></i>Página real</span>
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--draft"></i>Borrador</span>
                                <span><i class="pp-map-legend__dot pp-map-legend__dot--ai"></i>Sugerencia IA</span>
                            </div>
                            <div class="pp-map-density" aria-label="Densidad del mapa">
                                <button type="button" data-map-density="cozy" class="is-active">Cómodo</button>
                                <button type="button" data-map-density="compact">Compacto</button>
                            </div>
                        </div>
                </div>
                <?php if (empty($pageTree)): ?>
                    <?php if (empty($hasMemory)): ?>
                        <div class="pp-empty pp-empty--inline pp-empty--onboard">
                            <div class="pp-empty__title">Aún no sabemos nada de tu negocio</div>
                            <div class="pp-empty__text">Antes de generar páginas con IA, cuéntanos a qué te dedicas, a quién te diriges y con qué tono. La IA usa este contexto en cada acción.</div>
                            <div class="pp-empty__actions">
                                <a href="<?= e(base_url('admin/memory')) ?>" class="pp-btn pp-btn--primary">Configurar mi sitio</a>
                                <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-link">o crear una página igualmente</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="pp-empty pp-empty--inline">
                            <div class="pp-empty__title">Todavía no hay arquitectura</div>
                            <div class="pp-empty__text">Crea la primera página con IA o deja que PromptPress proponga una estructura inicial.</div>
                            <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--primary">Crear primera página</a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="pp-map-nav-preview" aria-label="Navegación pública probable">
                        <div>
                            <strong>Navegación probable</strong>
                            <span>Primer nivel visible para el visitante</span>
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
                        <div class="pp-map-canvas" aria-label="Árbol visual del sitio">
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

    <div class="pp-map-view" data-map-view="list" hidden>
        <?php if (empty($pages)): ?>
            <div class="pp-empty">
                <div class="pp-empty__title">Todavía no hay páginas</div>
                <div class="pp-empty__text">Crea la primera página para empezar a construir tu sitio.</div>
                <a href="<?= e(base_url('admin/pages/studio')) ?>" class="pp-btn pp-btn--primary">Crear primera página con IA</a>
            </div>
        <?php else: ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Slug</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Actualizada</th>
                            <th style="width:180px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $p): ?>
                        <tr>
                            <td><a href="<?= e(base_url('admin/pages/' . $p['id'] . '/edit')) ?>"><strong><?= e($p['title']) ?></strong></a></td>
                            <td><code>/<?= e($p['slug']) ?></code></td>
                            <td><?= e($pageTypes[$p['page_type']] ?? $p['page_type']) ?></td>
                            <td><?= $statusBadge($p) . $canvasBadge($p) ?></td>
                            <td><small><?= e($fmtDate($p['updated_at'])) ?></small></td>
                            <td>
                                <div class="pp-actions">
                                    <a href="<?= e(base_url('admin/pages/' . $p['id'] . '/edit')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm">Editar</a>
                                    <form method="POST" action="<?= e(base_url('admin/pages/' . $p['id'] . '/delete')) ?>" class="pp-inline-form" onsubmit="return confirm('¿Seguro que quieres eliminar «<?= e($p['title']) ?>»? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="_csrf" value="<?= e(\Core\CSRF::token()) ?>">
                                        <button type="submit" class="pp-btn pp-btn--danger pp-btn--sm">Eliminar</button>
                                    </form>
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
</section>
