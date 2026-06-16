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
            <span class="pp-seo-eyebrow">Visibilidad orgánica</span>
            <h2>SEO del sitio</h2>
            <p>Revisa URLs rotas, redirecciones y señales básicas de indexación sin salir del panel.</p>
        </div>
        <div class="pp-seo-hero__status" aria-label="Estado SEO">
            <span><?= (int) $kpis['open404'] ?></span>
            <small>404 abiertos</small>
        </div>
    </header>

    <nav class="pp-seo-tabs" aria-label="Secciones SEO">
        <a class="pp-seo-tab<?= $isTab('summary') ?>" href="<?= e($tabUrl('summary')) ?>">Resumen</a>
        <a class="pp-seo-tab<?= $isTab('404') ?>" href="<?= e($tabUrl('404')) ?>">404</a>
        <a class="pp-seo-tab<?= $isTab('redirects') ?>" href="<?= e($tabUrl('redirects')) ?>">Redirecciones</a>
        <a class="pp-seo-tab<?= $isTab('links') ?>" href="<?= e($tabUrl('links')) ?>">Enlaces internos</a>
        <a class="pp-seo-tab<?= $isTab('advanced') ?>" href="<?= e($tabUrl('advanced')) ?>">Avanzado</a>
    </nav>

    <?php if ($tab === 'summary'): ?>
        <div class="pp-seo-kpis">
            <article class="pp-seo-kpi pp-seo-kpi--wide">
                <span>404 abiertos</span>
                <strong><?= (int) $kpis['open404'] ?></strong>
                <p>URLs visitadas que ahora no tienen página ni redirección.</p>
                <a href="<?= e($tabUrl('404')) ?>">Revisar 404</a>
            </article>
            <article class="pp-seo-kpi">
                <span>Redirecciones activas</span>
                <strong><?= (int) $kpis['activeRedirects'] ?></strong>
                <p>Reglas que preservan tráfico al mover URLs.</p>
                <a href="<?= e($tabUrl('redirects')) ?>">Gestionar</a>
            </article>
            <article class="pp-seo-kpi">
                <span>Enlaces a revisar</span>
                <strong><?= (int) $kpis['linkIssues'] ?></strong>
                <p>Botones o enlaces internos que no llevan a una página publicada.</p>
                <a href="<?= e($tabUrl('links')) ?>">Ver enlaces</a>
            </article>
            <article class="pp-seo-kpi">
                <span>Metas a mejorar</span>
                <strong><?= (int) $kpis['metaIssues'] ?></strong>
                <p>El panel avisa; la edición se hace en el editor correspondiente.</p>
            </article>
            <article class="pp-seo-kpi">
                <span>Sitemap y robots</span>
                <strong><?= (int) ($indexation['published_pages'] ?? 0) ?></strong>
                <p>Páginas publicadas incluidas en el sitemap público.</p>
                <a href="<?= e((string) ($indexation['sitemap_url'] ?? base_url('sitemap.xml'))) ?>" target="_blank" rel="noopener">Abrir sitemap</a>
            </article>
            <article class="pp-seo-kpi">
                <span>Auditoría avanzada</span>
                <strong><?= (int) $kpis['technicalIssues'] ?></strong>
                <p>Señales técnicas para revisar solo si sabes qué estás tocando.</p>
                <a href="<?= e($tabUrl('advanced')) ?>">Ver avanzado</a>
            </article>
        </div>

        <section class="pp-seo-panel">
            <div class="pp-seo-panel__head">
                <div>
                    <h3>Indexación pública</h3>
                    <p>PromptPress genera automáticamente los archivos que los buscadores revisan para descubrir el sitio.</p>
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
                    <h3>Metadatos pendientes</h3>
                    <p>No se editan aquí para evitar duplicar el Studio. Abre el editor correcto y ajusta la página en contexto.</p>
                </div>
            </div>
            <?php if (empty($metaIssues)): ?>
                <div class="pp-seo-empty">
                    <strong>Las páginas publicadas tienen metadatos razonables.</strong>
                    <span>No hay títulos o descripciones urgentes que revisar.</span>
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
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e((string) $m['edit_url']) ?>">Abrir editor</a>
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
                        <h3>Nueva redirección</h3>
                        <p>Usa rutas internas. Ejemplo: de <code>/servicio-antiguo</code> a <code>/servicios</code>.</p>
                    </div>
                </div>
                <label>URL antigua
                    <input class="pp-input" name="source_path" placeholder="/url-antigua" required>
                    <small>Debe ser una ruta que ya no quieres mostrar.</small>
                </label>
                <label>Enviar a
                    <input class="pp-input" name="target_path" placeholder="/url-nueva">
                    <small>Para 410 puedes dejarlo vacío.</small>
                </label>
                <label>Tipo
                    <select class="pp-input" name="status_code">
                        <option value="301">301 permanente</option>
                        <option value="302">302 temporal</option>
                        <option value="410">410 contenido retirado</option>
                    </select>
                </label>
                <button class="pp-btn pp-btn--primary" type="submit">Guardar redirección</button>
            </form>

            <section class="pp-seo-panel">
                <div class="pp-seo-panel__head">
                    <div>
                        <h3>Redirecciones</h3>
                        <p>Las automáticas se crean cuando cambias el slug de una página publicada.</p>
                    </div>
                </div>
                <?php if (empty($redirects)): ?>
                    <div class="pp-seo-empty">
                        <strong>No hay redirecciones todavía.</strong>
                        <span>Cuando cambies una URL publicada, PromptPress guardará una 301 automáticamente.</span>
                    </div>
                <?php else: ?>
                    <div class="pp-seo-table">
                        <?php foreach ($redirects as $r): ?>
                            <div class="pp-seo-row">
                                <div>
                                    <code><?= e((string) $r['source_path']) ?></code>
                                    <span><?= e($statusLabel((int) $r['status_code'])) ?><?= (int) $r['auto_created'] === 1 ? ' · automática' : '' ?></span>
                                </div>
                                <div>
                                    <code><?= e((string) ($r['target_path'] ?? 'Sin destino')) ?></code>
                                    <span><?= (int) $r['hit_count'] ?> visitas<?= !empty($r['last_hit_at']) ? ' · ' . e($fmtDate((string) $r['last_hit_at'])) : '' ?></span>
                                </div>
                                <form method="POST" action="<?= e(base_url('admin/seo/redirects/' . (int) $r['id'])) ?>" class="pp-seo-row__actions">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <?php if ((int) $r['is_active'] === 1): ?>
                                        <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="deactivate">Pausar</button>
                                    <?php else: ?>
                                        <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="activate">Activar</button>
                                    <?php endif; ?>
                                    <button class="pp-btn pp-btn--ghost pp-btn--sm" name="action" value="delete">Eliminar</button>
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
                    <h3>Monitor 404</h3>
                    <p>URLs que alguien ha intentado visitar y que no existen. Convierte las importantes en redirecciones.</p>
                </div>
                <div class="pp-seo-filter">
                    <a class="<?= $notFoundStatus === 'open' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=open')) ?>">Abiertos</a>
                    <a class="<?= $notFoundStatus === 'resolved' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=resolved')) ?>">Resueltos</a>
                    <a class="<?= $notFoundStatus === 'ignored' ? 'is-active' : '' ?>" href="<?= e(base_url('admin/seo?tab=404&status=ignored')) ?>">Ignorados</a>
                </div>
            </div>
            <?php if (empty($notFound)): ?>
                <div class="pp-seo-empty">
                    <strong>No hay 404 en esta vista.</strong>
                    <span>Cuando una URL pública falle, aparecerá aquí si no es un asset o ruta interna.</span>
                </div>
            <?php else: ?>
                <div class="pp-seo-404-list">
                    <?php foreach ($notFound as $n): ?>
                        <article class="pp-seo-404">
                            <div class="pp-seo-404__main">
                                <code><?= e((string) $n['requested_path']) ?></code>
                                <span><?= (int) $n['hit_count'] ?> visitas · Última: <?= e($fmtDate((string) $n['last_seen_at'])) ?></span>
                                <?php if (!empty($n['referrer'])): ?><small>Origen: <?= e((string) $n['referrer']) ?></small><?php endif; ?>
                            </div>
                            <form method="POST" action="<?= e(base_url('admin/seo/redirects')) ?>" class="pp-seo-404__redirect">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="source_path" value="<?= e((string) $n['requested_path']) ?>">
                                <input type="hidden" name="from_404_id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="status_code" value="301">
                                <label>Enviar a
                                    <input class="pp-input" name="target_path" placeholder="/destino">
                                </label>
                                <button class="pp-btn pp-btn--primary pp-btn--sm">Crear 301</button>
                            </form>
                            <form method="POST" action="<?= e(base_url('admin/seo/404/' . (int) $n['id'])) ?>" class="pp-seo-row__actions">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button class="pp-btn pp-btn--secondary pp-btn--sm" name="action" value="resolved">Marcar resuelto</button>
                                <button class="pp-btn pp-btn--ghost pp-btn--sm" name="action" value="ignore">Ignorar</button>
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
                    <h3>Enlaces internos</h3>
                    <p>Revisión de botones y enlaces dentro del contenido. Sustituye al panel antiguo de Enlaces.</p>
                </div>
            </div>
            <?php if (empty($linkIssues)): ?>
                <div class="pp-seo-empty">
                    <strong>Todos los enlaces internos apuntan a páginas publicadas.</strong>
                    <span>No hay botones rotos en el contenido estructurado.</span>
                </div>
            <?php else: ?>
                <?php foreach ($linksByPage as $pageId => $group): ?>
                    <div class="pp-seo-link-group">
                        <div class="pp-seo-link-group__head">
                            <h4><?= e((string) $group['title']) ?></h4>
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages/' . (int) $pageId . '/edit')) ?>">Editar página</a>
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
                    <h3>Auditoría técnica avanzada</h3>
                    <p>Esta zona es para revisión técnica. Si no tienes claro qué significa un aviso, es mejor no cambiar nada y pedir ayuda.</p>
                </div>
            </div>
            <div class="pp-seo-advanced-note">
                <strong>Zona avanzada</strong>
                <span>Estos avisos no significan siempre que haya un problema visible. Sirven para detectar señales que pueden afectar a indexación, accesibilidad o enlaces compartidos.</span>
            </div>
            <?php if (empty($technicalIssues)): ?>
                <div class="pp-seo-empty">
                    <strong>No hay avisos técnicos relevantes.</strong>
                    <span>Las páginas publicadas tienen una estructura básica correcta para esta revisión.</span>
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
                            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e($editUrl) ?>">Abrir editor</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
