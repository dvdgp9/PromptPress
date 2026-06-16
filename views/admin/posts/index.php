<?php
/**
 * F21.T21.1 — Listado de entradas (blog).
 *
 * @var array  $posts          rows con campos page + post_meta
 * @var string $filter         '', 'draft', 'published'
 * @var int    $countAll
 * @var int    $countPublished
 * @var int    $countDraft
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$flashSuccess = \Core\Session::flash('success');
$flashError   = \Core\Session::flash('error');

$fmtDate = static function (?string $dt, bool $relative = true): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    if (!$t) return '—';
    if ($relative) {
        $diff = time() - $t;
        if ($diff < 60) return 'ahora';
        if ($diff < 3600) return floor($diff / 60) . ' min';
        if ($diff < 86400) return floor($diff / 3600) . ' h';
        if ($diff < 86400 * 7) return floor($diff / 86400) . ' d';
    }
    return date('j M Y', $t);
};
?>

<?php \Core\View::start('title'); ?>Entradas<?php \Core\View::end(); ?>

<header class="pp-posts-header">
    <div class="pp-posts-header__intro">
        <span class="pp-posts-header__eyebrow">Contenido editorial</span>
        <h2 class="pp-posts-header__title">Entradas</h2>
        <p class="pp-posts-header__desc">Tu blog: artículos, novedades y contenido que ayuda al SEO. Cada entrada es una página tipo artículo con metadatos propios.</p>
    </div>
    <div class="pp-posts-header__actions">
        <?php if ($countAll > 0): ?>
            <button type="button" class="pp-btn pp-btn--secondary" data-suggest-related>✨ Proponer ideas</button>
        <?php endif; ?>
        <a href="<?= e(base_url('admin/posts/new')) ?>" class="pp-btn pp-btn--primary">Nueva entrada</a>
    </div>
</header>

<?php if ($flashSuccess): ?><div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError):   ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>

<?php if ($countAll === 0): ?>
    <section class="pp-posts-empty">
        <div class="pp-posts-empty__art" aria-hidden="true">
            <svg viewBox="0 0 200 140" width="200" height="140" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="20" y="20" width="120" height="80" rx="6" opacity=".4"/>
                <rect x="35" y="38" width="70" height="3" rx="1.5"/>
                <rect x="35" y="50" width="90" height="3" rx="1.5"/>
                <rect x="35" y="62" width="60" height="3" rx="1.5"/>
                <rect x="35" y="80" width="40" height="6" rx="3" fill="currentColor"/>
                <path d="M150 30l25-10 6 14-24 12z" opacity=".6"/>
                <path d="M156 36l16 4"/>
            </svg>
        </div>
        <h3 class="pp-posts-empty__title">Aún no tienes entradas</h3>
        <p class="pp-posts-empty__desc">Las entradas son el motor de SEO orgánico: la IA puede generarlas a partir de una idea, de un documento subido o de tus propias entradas anteriores.</p>
        <div class="pp-posts-empty__cta">
            <a href="<?= e(base_url('admin/posts/new')) ?>" class="pp-btn pp-btn--primary">Crear la primera entrada</a>
        </div>
    </section>
<?php else: ?>
    <nav class="pp-posts-tabs" role="tablist">
        <a href="<?= e(base_url('admin/posts')) ?>"               class="pp-posts-tab<?= $filter === '' ? ' is-active' : '' ?>">Todas <span><?= (int) $countAll ?></span></a>
        <a href="<?= e(base_url('admin/posts?status=published')) ?>" class="pp-posts-tab<?= $filter === 'published' ? ' is-active' : '' ?>">Publicadas <span><?= (int) $countPublished ?></span></a>
        <a href="<?= e(base_url('admin/posts?status=draft')) ?>"     class="pp-posts-tab<?= $filter === 'draft' ? ' is-active' : '' ?>">Borradores <span><?= (int) $countDraft ?></span></a>
    </nav>

    <ul class="pp-posts-list">
        <?php foreach ($posts as $p):
            $id      = (int) $p['id'];
            $title   = (string) $p['title'];
            $slug    = (string) $p['slug'];
            $status  = (string) $p['status'];
            $excerpt = trim((string) ($p['excerpt'] ?? ''));
            $img     = trim((string) ($p['featured_image_path'] ?? ''));
            $imgAlt  = (string) ($p['featured_image_alt'] ?? '');
            $author  = (string) ($p['author_name'] ?? '');
            $reading = (int) ($p['reading_minutes'] ?? 0);
            $published = $p['published_at'] ?? null;
            $updated   = $p['updated_at'] ?? null;
            $dateDisplay = $published ?: $updated;
            $dateLabel = $published ? 'Publicada' : 'Actualizada';
            $previewUrl = '/' . ltrim($slug, '/');
        ?>
        <li class="pp-post-row">
            <?php if ($img): ?>
                <a class="pp-post-row__thumb" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>">
                    <img src="<?= e(base_url(ltrim($img, '/'))) ?>" alt="<?= e($imgAlt) ?>" loading="lazy">
                </a>
            <?php else: ?>
                <a class="pp-post-row__thumb pp-post-row__thumb--empty" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>" aria-label="Sin imagen destacada">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </a>
            <?php endif; ?>

            <div class="pp-post-row__body">
                <div class="pp-post-row__head">
                    <a class="pp-post-row__title" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>"><?= e($title) ?></a>
                    <span class="pp-post-status pp-post-status--<?= e($status) ?>"><?= $status === 'published' ? 'Publicada' : 'Borrador' ?></span>
                </div>
                <?php if ($excerpt !== ''): ?>
                    <p class="pp-post-row__excerpt"><?= e($excerpt) ?></p>
                <?php endif; ?>
                <p class="pp-post-row__meta">
                    <span><?= e($dateLabel) ?>: <strong><?= e($fmtDate($dateDisplay)) ?></strong></span>
                    <?php if ($author !== ''): ?><span>· <?= e($author) ?></span><?php endif; ?>
                    <?php if ($reading > 0): ?><span>· <?= (int) $reading ?> min lectura</span><?php endif; ?>
                </p>
            </div>

            <div class="pp-post-row__actions">
                <?php if ($status === 'published'): ?>
                    <a class="pp-post-row__action" href="<?= e(base_url($previewUrl)) ?>" target="_blank" rel="noopener" title="Ver en el sitio">↗</a>
                <?php endif; ?>
                <a class="pp-post-row__action" href="<?= e(base_url('admin/pages/' . $id . '/edit')) ?>" title="Editar">Editar</a>
                <form method="POST" action="<?= e(base_url('admin/posts/' . $id . '/delete')) ?>" onsubmit="return confirm('¿Eliminar &quot;<?= e(addslashes($title)) ?>&quot;? Esta acción no se puede deshacer.');">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="pp-post-row__action pp-post-row__action--danger" title="Eliminar">Borrar</button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<!-- T21.7 — Modal de sugerencias de entradas -->
<div id="pp-suggest-modal" class="pp-suggest-modal" hidden data-base-url="<?= e(base_url('')) ?>" data-csrf="<?= e($csrf) ?>">
    <div class="pp-suggest-modal__backdrop" data-close></div>
    <div class="pp-suggest-modal__panel" role="dialog" aria-modal="true" aria-labelledby="pp-suggest-title">
        <header class="pp-suggest-modal__head">
            <div>
                <span class="pp-posts-header__eyebrow">Brainstorming</span>
                <h3 id="pp-suggest-title">Ideas para entradas nuevas</h3>
                <p class="pp-suggest-modal__sub">La IA mira tus entradas y propone temas que las complementan.</p>
            </div>
            <button type="button" class="pp-suggest-modal__close" data-close aria-label="Cerrar">×</button>
        </header>

        <div class="pp-suggest-modal__body">
            <div class="pp-suggest-modal__status" data-status aria-live="polite"></div>
            <ul class="pp-suggest-modal__list" data-list></ul>
        </div>

        <footer class="pp-suggest-modal__foot">
            <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-refresh>🔁 Otras ideas</button>
            <button type="button" class="pp-btn pp-btn--secondary" data-close>Cerrar</button>
        </footer>
    </div>
</div>

<script>
(function () {
    const trigger = document.querySelector('[data-suggest-related]');
    const modal   = document.getElementById('pp-suggest-modal');
    if (!trigger || !modal) return;

    const baseUrl = modal.dataset.baseUrl.replace(/\/$/, '');
    const csrf    = modal.dataset.csrf;
    const status  = modal.querySelector('[data-status]');
    const list    = modal.querySelector('[data-list]');
    const refresh = modal.querySelector('[data-refresh]');

    function open() {
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        fetchSuggestions();
    }
    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }
    trigger.addEventListener('click', open);
    modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
    refresh.addEventListener('click', fetchSuggestions);

    function fetchSuggestions() {
        list.innerHTML = '';
        status.textContent = 'La IA está mirando tu blog y pensando ideas… (10-30s)';
        status.className = 'pp-suggest-modal__status is-loading';
        refresh.disabled = true;
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('count', '5');
        fetch(baseUrl + '/admin/posts/ai-suggest-related', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r => r.json())
            .then(data => {
                refresh.disabled = false;
                if (!data.ok) {
                    status.textContent = 'Error: ' + (data.error || 'No hay sugerencias');
                    status.className = 'pp-suggest-modal__status is-error';
                    return;
                }
                status.textContent = data.suggestions.length + ' propuestas';
                status.className = 'pp-suggest-modal__status';
                render(data.suggestions);
            })
            .catch(err => {
                refresh.disabled = false;
                status.textContent = 'Error: ' + err.message;
                status.className = 'pp-suggest-modal__status is-error';
            });
    }

    function render(items) {
        list.innerHTML = '';
        items.forEach((it, idx) => {
            const li = document.createElement('li');
            li.className = 'pp-suggest-item';
            li.innerHTML = ''
                + '<div class="pp-suggest-item__body">'
                + '<strong class="pp-suggest-item__title">' + escHtml(it.title) + '</strong>'
                + (it.angle ? '<p class="pp-suggest-item__angle">' + escHtml(it.angle) + '</p>' : '')
                + '<div class="pp-suggest-item__meta">'
                +   (it.audience ? '<span><b>Para:</b> ' + escHtml(it.audience) + '</span>' : '')
                +   (it.why_now ? '<span class="pp-suggest-item__why"><b>Por qué:</b> ' + escHtml(it.why_now) + '</span>' : '')
                + '</div>'
                + '</div>'
                + '<button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-pick="' + idx + '">Crear esta →</button>';
            list.appendChild(li);
            li.querySelector('[data-pick]').addEventListener('click', () => createFromSuggestion(it, li));
        });
    }

    function createFromSuggestion(suggestion, liEl) {
        const btn = liEl.querySelector('[data-pick]');
        btn.disabled = true;
        btn.textContent = 'Generando…';
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('topic',    suggestion.title);
        fd.append('audience', suggestion.audience || '');
        fd.append('tone',     'profesional y cercano');
        fd.append('length',   'medio');
        fd.append('details',  suggestion.angle || '');
        fetch(baseUrl + '/admin/posts/ai-create', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    btn.disabled = false; btn.textContent = 'Crear esta →';
                    alert(data.error || 'No se pudo generar.');
                    return;
                }
                btn.textContent = '✓ Creada · redirigiendo';
                setTimeout(() => { window.location = data.edit_url; }, 500);
            })
            .catch(err => {
                btn.disabled = false; btn.textContent = 'Crear esta →';
                alert('Error: ' + err.message);
            });
    }

    function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
})();
</script>

<style>
.pp-posts-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin: 0 0 28px; flex-wrap: wrap; }
.pp-posts-header__eyebrow { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #6366f1; margin-bottom: 6px; }
.pp-posts-header__title { margin: 0 0 6px; font-size: 1.7rem; letter-spacing: -.02em; }
.pp-posts-header__desc { margin: 0; color: #475569; max-width: 56ch; line-height: 1.5; }

.pp-posts-tabs { display: flex; gap: 4px; margin: 0 0 18px; padding: 0; border-bottom: 1px solid #e2e8f0; }
.pp-posts-tab { padding: 10px 14px; color: #64748b; text-decoration: none; font-size: .9rem; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: color .12s ease, border-color .12s ease; }
.pp-posts-tab:hover { color: #1e293b; text-decoration: none; }
.pp-posts-tab.is-active { color: #0f172a; border-bottom-color: #6366f1; }
.pp-posts-tab span { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 999px; font-size: .72rem; background: #f1f5f9; color: #475569; font-weight: 600; }
.pp-posts-tab.is-active span { background: #eef2ff; color: #4338ca; }

.pp-posts-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
.pp-post-row { display: grid; grid-template-columns: 88px 1fr auto; gap: 18px; align-items: center; padding: 16px 12px; border-bottom: 1px solid #f1f5f9; transition: background .15s ease; }
.pp-post-row:hover { background: #fafbfc; }
.pp-post-row:hover .pp-post-row__actions { opacity: 1; }
.pp-post-row:last-child { border-bottom: 0; }

.pp-post-row__thumb { display: block; width: 88px; height: 64px; border-radius: 10px; overflow: hidden; background: #f1f5f9; flex: none; }
.pp-post-row__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pp-post-row__thumb--empty { display: flex; align-items: center; justify-content: center; color: #cbd5e1; }

.pp-post-row__body { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.pp-post-row__head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pp-post-row__title { font-size: 1.02rem; font-weight: 600; color: #0f172a; text-decoration: none; letter-spacing: -.005em; overflow: hidden; text-overflow: ellipsis; }
.pp-post-row__title:hover { color: #6366f1; text-decoration: none; }
.pp-post-row__excerpt { margin: 0; color: #475569; font-size: .88rem; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.pp-post-row__meta { margin: 2px 0 0; color: #94a3b8; font-size: .78rem; display: flex; gap: 4px; flex-wrap: wrap; }
.pp-post-row__meta strong { color: #475569; font-weight: 600; }

.pp-post-status { display: inline-block; font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; flex: none; }
.pp-post-status--published { background: #ecfdf5; color: #047857; }
.pp-post-status--draft { background: #f1f5f9; color: #64748b; }

.pp-post-row__actions { display: flex; gap: 6px; opacity: 0; transition: opacity .15s ease; align-items: center; }
.pp-post-row__action { padding: 6px 10px; font-size: .82rem; color: #475569; text-decoration: none; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; font-family: inherit; line-height: 1.2; }
.pp-post-row__action:hover { border-color: #cbd5e1; background: #f8fafc; color: #0f172a; text-decoration: none; }
.pp-post-row__action--danger:hover { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }
.pp-post-row__actions form { margin: 0; display: inline; }

@media (max-width: 720px) {
    .pp-post-row { grid-template-columns: 64px 1fr; gap: 12px; padding: 14px 8px; }
    .pp-post-row__thumb { width: 64px; height: 64px; }
    .pp-post-row__actions { grid-column: 1 / -1; opacity: 1; justify-content: flex-end; }
}

.pp-posts-empty { text-align: center; padding: 64px 24px; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); border: 1px dashed #e2e8f0; border-radius: 16px; }
.pp-posts-empty__art { color: #cbd5e1; margin: 0 auto 18px; display: inline-flex; }
.pp-posts-empty__title { margin: 0 0 8px; font-size: 1.2rem; letter-spacing: -.01em; }
.pp-posts-empty__desc { margin: 0 auto 22px; color: #64748b; max-width: 48ch; line-height: 1.5; }
.pp-posts-empty__cta { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* T21.7 — Modal de sugerencias */
.pp-suggest-modal { position: fixed; inset: 0; z-index: 1000; }
.pp-suggest-modal[hidden] { display: none !important; }
.pp-suggest-modal__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.55); backdrop-filter: blur(3px); }
.pp-suggest-modal__panel {
    position: relative;
    max-width: 720px;
    width: calc(100% - 32px);
    max-height: 88vh;
    margin: 6vh auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 30px 80px -20px rgba(15,23,42,.4);
    display: flex; flex-direction: column;
}
.pp-suggest-modal__head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    padding: 20px 24px 16px;
    border-bottom: 1px solid #f1f5f9;
}
.pp-suggest-modal__head h3 { margin: 2px 0 4px; font-size: 1.1rem; letter-spacing: -.01em; }
.pp-suggest-modal__sub { margin: 0; color: #64748b; font-size: .88rem; }
.pp-suggest-modal__close { background: transparent; border: 0; cursor: pointer; color: #64748b; font-size: 1.6rem; line-height: 1; padding: 0 8px; border-radius: 6px; }
.pp-suggest-modal__close:hover { background: #f1f5f9; color: #0f172a; }
.pp-suggest-modal__body { padding: 16px 24px; overflow-y: auto; flex: 1; }
.pp-suggest-modal__status { padding: 10px 0 16px; font-size: .88rem; color: #475569; min-height: 1.4em; }
.pp-suggest-modal__status.is-loading { color: var(--pp-primary, #ea580c); }
.pp-suggest-modal__status.is-error { color: #b91c1c; }
.pp-suggest-modal__list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; }
.pp-suggest-item {
    display: flex; gap: 14px; align-items: flex-start;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.pp-suggest-item:hover { border-color: var(--pp-primary, #ea580c); box-shadow: 0 8px 18px -10px rgba(234,88,12,.2); transform: translateY(-1px); }
.pp-suggest-item__body { flex: 1; min-width: 0; }
.pp-suggest-item__title { display: block; font-size: 1.02rem; color: #0f172a; letter-spacing: -.01em; margin-bottom: 4px; }
.pp-suggest-item__angle { margin: 0 0 8px; color: #475569; font-size: .9rem; line-height: 1.5; }
.pp-suggest-item__meta { display: flex; flex-direction: column; gap: 4px; font-size: .82rem; color: #64748b; }
.pp-suggest-item__meta b { color: #1e293b; font-weight: 600; margin-right: 4px; }
.pp-suggest-modal__foot { display: flex; gap: 10px; justify-content: space-between; padding: 14px 24px; border-top: 1px solid #f1f5f9; }
@media (max-width: 640px) {
    .pp-suggest-item { flex-direction: column; align-items: stretch; }
}
</style>
