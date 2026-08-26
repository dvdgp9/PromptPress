<!DOCTYPE html>
<html lang="<?= e(\App\Services\AdminI18n::htmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(\Core\View::section('title', __('layout.title_fallback'))) ?> — PromptPress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..900&family=Geist+Mono:wght@400..700&display=swap">
    <?php $cssPath = PP_ROOT . '/admin/assets/css/admin.css'; $cssVer = file_exists($cssPath) ? filemtime($cssPath) : PP_VERSION; ?>
    <link rel="stylesheet" href="<?= e(base_url('admin/assets/css/admin.css')) ?>?v=<?= e($cssVer) ?>">
    <?= \Core\View::section('head', '') ?>
</head>
<body class="pp-admin <?= e(\Core\View::section('bodyClass', '')) ?>">

    <!-- Sidebar -->
    <aside class="pp-sidebar" id="pp-sidebar">
        <div class="pp-sidebar__brand">
            <a href="<?= e(base_url('admin/')) ?>">
                <?php if (!empty($siteLogoUrl)): ?>
                    <span class="pp-sidebar__logo pp-sidebar__logo--image"><img src="<?= e($siteLogoUrl) ?>" alt="<?= e($siteName ?? __('layout.site_alt')) ?>"></span>
                <?php else: ?>
                    <span class="pp-sidebar__logo">P</span>
                <?php endif; ?>
                <span class="pp-sidebar__name"><?= e($siteName ?? 'PromptPress') ?></span>
            </a>
        </div>

        <nav class="pp-sidebar__nav">
            <?php
            $currentPath = \Core\Request::path();
            $navItems = [
                ['url' => 'admin/',           'icon' => 'dashboard', 'label' => __('nav.dashboard'),     'match' => '/admin'],
                ['url' => 'admin/assistant',  'icon' => 'ai',        'label' => __('nav.assistant'),      'match' => '/admin/assistant'],
                ['url' => 'admin/pages',      'icon' => 'pages',     'label' => __('nav.pages'),        'match' => '/admin/pages'],
                ['url' => 'admin/posts',      'icon' => 'posts',     'label' => __('nav.posts'),       'match' => '/admin/posts'],
                ['url' => 'admin/media',      'icon' => 'media',     'label' => __('nav.media'),         'match' => '/admin/media'],
                ['url' => 'admin/formularios','icon' => 'forms',     'label' => __('nav.forms'),    'match' => '/admin/formularios'],
                ['url' => 'admin/forms',      'icon' => 'messages',  'label' => __('nav.messages'),       'match' => '/admin/forms'],
                ['url' => 'admin/memory',     'icon' => 'memory',    'label' => __('nav.knowledge'),   'match' => '/admin/memory'],
                ['url' => 'admin/documents',  'icon' => 'documents', 'label' => __('nav.documents'),     'match' => '/admin/documents'],
                ['url' => 'admin/design',     'icon' => 'design',    'label' => __('nav.design'),         'match' => '/admin/design'],
                ['url' => 'admin/chrome',     'icon' => 'chrome',    'label' => __('nav.chrome'),   'match' => '/admin/chrome'],
                ['url' => 'admin/seo',        'icon' => 'seo',       'label' => __('nav.seo'),            'match' => '/admin/seo'],
                ['url' => 'admin/marketing',  'icon' => 'marketing', 'label' => __('nav.marketing'),      'match' => '/admin/marketing'],
                ['url' => 'admin/ai/usage',   'icon' => 'ai',        'label' => __('nav.ai'),             'match' => '/admin/ai'],
                ['url' => 'admin/privacy',    'icon' => 'privacy',   'label' => __('nav.privacy'),     'match' => '/admin/privacy'],
                ['url' => 'admin/modules',    'icon' => 'settings',  'label' => __('nav.modules'),        'match' => '/admin/modules'],
                ['url' => 'admin/settings',   'icon' => 'settings',  'label' => __('nav.settings'),        'match' => '/admin/settings'],
            ];
            // FEAT-3 — entradas de módulos activables (solo si están activos).
            $navSiteId = \Core\Auth::siteId();
            if ($navSiteId !== null && \App\Modules\ModuleRegistry::isEnabled($navSiteId, 'resources')) {
                array_splice($navItems, 13, 0, [
                    ['url' => 'admin/resources', 'icon' => 'resources', 'label' => __('nav.resources'), 'match' => '/admin/resources'],
                ]);
            }
            if ($navSiteId !== null && \App\Modules\ModuleRegistry::isEnabled($navSiteId, 'commerce')) {
                array_splice($navItems, 13, 0, [
                    ['url' => 'admin/commerce', 'icon' => 'commerce', 'label' => __('nav.shop'), 'match' => '/admin/commerce'],
                ]);
            }
            if ($navSiteId !== null && \App\Modules\ModuleRegistry::isEnabled($navSiteId, 'booking')) {
                array_splice($navItems, 13, 0, [
                    ['url' => 'admin/booking', 'icon' => 'booking', 'label' => __('nav.bookings'), 'match' => '/admin/booking'],
                ]);
            }
            if ($navSiteId !== null && \App\Modules\ModuleRegistry::isEnabled($navSiteId, 'analytics')) {
                array_splice($navItems, 13, 0, [
                    ['url' => 'admin/analytics', 'icon' => 'analytics', 'label' => __('nav.analytics'), 'match' => '/admin/analytics'],
                ]);
            }
            foreach ($navItems as $item):
                // Match por segmento: '/admin/forms' NO debe activar '/admin/formularios'
                // (el carácter tras el prefijo ha de ser '/' o el fin de la ruta).
                $m = $item['match'];
                $segmentMatch = $currentPath === $m
                    || str_starts_with($currentPath, $m . '/');
                $isActive = ($m === '/admin' && ($currentPath === '/admin' || $currentPath === '/admin/'))
                    || ($m !== '/admin' && $segmentMatch);
                $activeClass = $isActive ? ' is-active' : '';
            ?>
            <a href="<?= e(base_url($item['url'])) ?>" class="pp-nav-item<?= $activeClass ?>">
                <span class="pp-nav-item__icon pp-icon--<?= $item['icon'] ?>"></span>
                <span class="pp-nav-item__label"><?= e($item['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="pp-sidebar__footer">
            <span class="pp-sidebar__version">v<?= e(PP_VERSION) ?></span>
        </div>
    </aside>

    <!-- Main area -->
    <div class="pp-main">

        <!-- Topbar -->
        <header class="pp-topbar">
            <button class="pp-topbar__toggle" id="pp-sidebar-toggle" type="button" aria-label="<?= e(__('common.menu')) ?>">
                <span class="pp-hamburger"></span>
            </button>

            <div class="pp-topbar__actions">
                <?php if (isset($siteName)): ?>
                <a href="<?= e(base_url('/')) ?>" class="pp-topbar__site" target="_blank" title="<?= e(__('common.view_site')) ?>">
                    <?= e($siteName) ?>
                    <span class="pp-icon--external"></span>
                </a>
                <?php endif; ?>

                <?php if (\Core\Auth::check()): ?>
                <div class="pp-topbar__user">
                    <span class="pp-topbar__username"><?= e($userName ?? 'Admin') ?></span>
                    <form method="POST" action="<?= e(base_url('admin/logout')) ?>" class="pp-logout-form">
                        <input type="hidden" name="_csrf" value="<?= e(\Core\CSRF::token()) ?>">
                        <button type="submit" class="pp-topbar__logout" title="<?= e(__('common.logout_title')) ?>"><?= e(__('common.logout')) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Content -->
        <main class="pp-content">
            <?php
            // Flash messages
            $flashSuccess = \Core\Session::flash('success');
            $flashError   = \Core\Session::flash('error');
            $flashWarning = \Core\Session::flash('warning');
            if ($flashSuccess): ?>
            <div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div>
            <?php endif;
            if ($flashError): ?>
            <div class="pp-alert pp-alert--error"><?= e($flashError) ?></div>
            <?php endif;
            if ($flashWarning): ?>
            <div class="pp-alert pp-alert--warning"><?= e($flashWarning) ?></div>
            <?php endif; ?>

            <?= \Core\View::section('content') ?>
        </main>

    </div>

    <!-- Overlay para mobile -->
    <div class="pp-overlay" id="pp-overlay"></div>

    <?php /* ADMIN-I18N — Solo viajan al navegador las claves `js.`: mandar las
             ~2.000 cadenas del panel en cada carga sería tirar ancho de banda.
             Va ANTES de admin.js porque `pp.t()` lo lee al arrancar. */ ?>
    <script>window.PP_I18N = <?= json_encode(
        \App\Services\AdminI18n::jsCatalog(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;</script>
    <script src="<?= e(base_url('admin/assets/js/pp-i18n.js')) ?>"></script>
    <script src="<?= e(base_url('admin/assets/js/admin.js')) ?>"></script>
    <?= \Core\View::section('scripts', '') ?>
</body>
</html>
