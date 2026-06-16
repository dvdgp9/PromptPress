<?php
/**
 * @var array  $issues        lista plana de problemas
 * @var array  $byPage        problemas agrupados por página
 * @var array  $sectionTypes  mapa tipo => etiqueta
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Enlaces<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Enlaces internos</h2>
</div>

<p class="pp-page-intro">
    Revisamos los botones y enlaces de tus secciones para detectar destinos que no
    funcionarán: páginas que no existen o que están en borrador (no visibles en tu web).
</p>

<?php if (empty($issues)): ?>
    <div class="pp-links-ok">
        <div class="pp-links-ok__icon" aria-hidden="true">✓</div>
        <div>
            <p class="pp-links-ok__title">Todos los enlaces apuntan a páginas publicadas</p>
            <p class="pp-links-ok__hint">No hay botones rotos en tu sitio.</p>
        </div>
    </div>
<?php else: ?>
    <div class="pp-alert pp-alert--warning">
        <?= count($issues) === 1 ? 'Hay 1 enlace que revisar.' : 'Hay ' . count($issues) . ' enlaces que revisar.' ?>
    </div>

    <?php foreach ($byPage as $pageId => $group): ?>
    <div class="pp-form-card">
        <div class="pp-links-group__head">
            <h3><?= e($group['title']) ?></h3>
            <a href="<?= e(base_url('admin/pages/' . (int) $pageId . '/edit')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm">Editar página →</a>
        </div>
        <ul class="pp-links-list">
            <?php foreach ($group['issues'] as $i): ?>
            <li class="pp-links-item">
                <code class="pp-links-item__link"><?= e($i['link']) ?></code>
                <span class="pp-links-item__where">en <?= e($sectionTypes[$i['section_type']] ?? $i['section_type']) ?></span>
                <?php if ($i['problem'] === 'missing'): ?>
                    <span class="pp-links-badge pp-links-badge--missing">No existe esa página</span>
                <?php else: ?>
                    <span class="pp-links-badge pp-links-badge--draft">En borrador</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>

    <p class="pp-form-hint">
        <strong>¿Cómo se arregla?</strong> «No existe» → crea esa página (o cambia el destino del botón).
        «En borrador» → abre la página de destino y cámbiala a <em>Publicada</em>.
    </p>
<?php endif; ?>
