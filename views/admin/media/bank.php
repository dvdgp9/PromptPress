<?php
/**
 * Banco de imágenes (Unsplash) — T18.4
 *
 * @var bool   $available  ¿está configurada la access key global?
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('bank.title')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('bank.title')) ?></h2>
    <a href="<?= e(base_url('admin/media')) ?>" class="pp-btn pp-btn--secondary">← <?= e(__('bank.back_to_media')) ?></a>
</div>

<?php if (!$available): ?>
    <div class="pp-alert pp-alert--info">
        <strong><?= e(__('bank.unavailable')) ?></strong>
        <?= __('bank.unavailable_help.html', ['enlace' => '<a href="' . e(base_url('admin/settings/ai')) . '">' . e(__('settings_ai.title')) . '</a>']) ?>
    </div>
<?php else: ?>

    <p class="pp-page-intro">
        <?= e(__('bank.intro')) ?>
    </p>

    <div class="pp-bank">
        <form class="pp-bank__form" id="pp-bank-form" autocomplete="off">
            <div class="pp-bank__row">
                <input type="search" id="pp-bank-q" class="pp-input" placeholder="<?= e(__('bank.search_placeholder')) ?>" required minlength="2">
                <select id="pp-bank-orientation" class="pp-input pp-input--small">
                    <option value="landscape"><?= e(__('bank.landscape')) ?></option>
                    <option value="portrait"><?= e(__('bank.portrait')) ?></option>
                    <option value="squarish"><?= e(__('bank.square')) ?></option>
                </select>
                <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('bank.search')) ?></button>
            </div>
            <small class="pp-bank__hint"><?= e(__('bank.cache_hint')) ?></small>
        </form>

        <div id="pp-bank-status" class="pp-bank__status" aria-live="polite"></div>
        <ul id="pp-bank-results" class="pp-bank__grid"></ul>
    </div>

    <script>
    (function () {
        const form    = document.getElementById('pp-bank-form');
        const qInput  = document.getElementById('pp-bank-q');
        const oInput  = document.getElementById('pp-bank-orientation');
        const status  = document.getElementById('pp-bank-status');
        const results = document.getElementById('pp-bank-results');
        const csrf    = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;

        let lastQuery = '';
        let lastOrientation = '';

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            search();
        });

        function search() {
            const q = qInput.value.trim();
            const o = oInput.value;
            if (q.length < 2) return;
            lastQuery = q;
            lastOrientation = o;
            results.innerHTML = '';
            status.textContent = pp.t('js.bank.searching');
            const url = '<?= e(base_url('admin/media/bank/search')) ?>?q=' + encodeURIComponent(q) + '&orientation=' + encodeURIComponent(o);
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) { status.textContent = data.error || pp.t('js.bank.search_error'); return; }
                    if (!data.items.length) { status.textContent = pp.t('js.bank.no_results', { q: q }); return; }
                    status.textContent = pp.t('js.bank.results', { n: data.items.length });
                    renderResults(data.items);
                })
                .catch(err => { status.textContent = pp.t('js.bank.connection_error', { error: err.message }); });
        }

        function renderResults(items) {
            results.innerHTML = '';
            items.forEach(item => {
                const li = document.createElement('li');
                li.className = 'pp-bank__item';
                li.innerHTML = ''
                    + '<figure class="pp-bank__figure">'
                    + '<img loading="lazy" src="' + escAttr(item.preview) + '" alt="' + escAttr(item.alt || item.description) + '">'
                    + '<figcaption class="pp-bank__caption">'
                    + '<span class="pp-bank__photog">' + pp.t('js.bank.by') + ' <a href="' + escAttr(item.profile_url) + '?utm_source=promptpress&utm_medium=referral" target="_blank" rel="noopener">' + escHtml(item.photographer) + '</a></span>'
                    + '<button type="button" class="pp-btn pp-btn--primary pp-btn--small" data-import="' + escAttr(item.id) + '">' + pp.t('js.bank.import') + '</button>'
                    + '</figcaption>'
                    + '</figure>';
                li.querySelector('[data-import]').addEventListener('click', function () { importImage(item, this); });
                results.appendChild(li);
            });
        }

        function importImage(item, btn) {
            btn.disabled = true;
            btn.textContent = pp.t('js.bank.importing');
            const body = new FormData();
            body.append('_csrf', csrf);
            body.append('result_id', item.id);
            body.append('query', lastQuery);
            body.append('orientation', lastOrientation);
            body.append('alt', item.alt || item.description || '');
            fetch('<?= e(base_url('admin/media/bank/import')) ?>', {
                method: 'POST', credentials: 'same-origin', body: body,
            })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) { btn.disabled = false; btn.textContent = pp.t('js.bank.import'); alert(data.error || pp.t('js.bank.import_failed')); return; }
                    btn.textContent = '✓ ' + pp.t('js.bank.imported');
                    btn.classList.remove('pp-btn--primary');
                    btn.classList.add('pp-btn--success');
                })
                .catch(err => { btn.disabled = false; btn.textContent = pp.t('js.bank.import'); alert(pp.t('js.bank.error', { error: err.message })); });
        }

        function escAttr(s) { return String(s == null ? '' : s).replace(/[&"<>]/g, c => ({'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c])); }
        function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
    })();
    </script>

    <style>
    .pp-bank__form { margin: 0 0 24px; }
    .pp-bank__row { display: flex; gap: 8px; align-items: center; }
    .pp-bank__row .pp-input { flex: 1; min-width: 0; }
    .pp-input--small { flex: 0 0 160px; }
    .pp-bank__hint { display: block; margin-top: 8px; color: #64748b; font-size: .85rem; }
    .pp-bank__status { margin: 0 0 16px; color: #475569; font-size: .9rem; min-height: 1.4em; }
    .pp-bank__grid { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .pp-bank__item { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
    .pp-bank__figure { margin: 0; display: flex; flex-direction: column; }
    .pp-bank__figure img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #f1f5f9; }
    .pp-bank__caption { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; font-size: .8rem; color: #475569; }
    .pp-bank__photog a { color: #475569; }
    .pp-btn--small { padding: 4px 10px; font-size: .8rem; }
    .pp-btn--success { background: #10b981 !important; color: #fff; cursor: default; }
    </style>

<?php endif; ?>
