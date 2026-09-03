<!DOCTYPE html>
<html class="no-js" lang="<?= e(\App\Services\AdminI18n::htmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(\Core\View::section('title', __('layout.title_fallback'))) ?> — PromptPress</title>
    <script>document.documentElement.classList.remove('no-js');</script>
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
            $navSiteId = \Core\Auth::siteId();
            $enabledNavModules = [];
            if ($navSiteId !== null) {
                foreach (array_keys(\App\Modules\ModuleRegistry::MODULES) as $moduleKey) {
                    if (\App\Modules\ModuleRegistry::isEnabled($navSiteId, $moduleKey)) {
                        $enabledNavModules[] = $moduleKey;
                    }
                }
            }

            $navigation = \App\Services\AdminNavigation::build($currentPath, $enabledNavModules);
            ?>
            <ul class="pp-nav-list">
            <?php foreach ($navigation as $navEntry): ?>
                <?php if (($navEntry['type'] ?? '') === 'link'):
                    $activeClass = !empty($navEntry['active']) ? ' is-active' : '';
                ?>
                <li class="pp-nav-list__item">
                    <a href="<?= e(base_url($navEntry['url'])) ?>"
                       class="pp-nav-item pp-nav-item--root<?= $activeClass ?>"
                       <?= !empty($navEntry['active']) ? 'aria-current="page"' : '' ?>>
                        <span class="pp-nav-item__icon pp-icon--<?= e($navEntry['icon']) ?>" aria-hidden="true"></span>
                        <span class="pp-nav-item__label"><?= e(__($navEntry['label_key'])) ?></span>
                        <span class="pp-nav-visual-tooltip" aria-hidden="true"><?= e(__($navEntry['label_key'])) ?></span>
                    </a>
                </li>
                <?php else:
                    $groupOpen = !empty($navEntry['active']);
                    $groupPanelId = 'pp-nav-group-' . (string) $navEntry['key'];
                ?>
                <li class="pp-nav-group<?= $groupOpen ? ' is-active' : '' ?>" data-pp-nav-group="<?= e($navEntry['key']) ?>">
                    <button type="button"
                            class="pp-nav-group__toggle"
                            data-pp-nav-group-toggle
                            data-pp-nav-group-active="<?= $groupOpen ? '1' : '0' ?>"
                            aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>"
                            aria-controls="<?= e($groupPanelId) ?>">
                        <span class="pp-nav-item__icon pp-icon--<?= e($navEntry['icon']) ?>" aria-hidden="true"></span>
                        <span class="pp-nav-group__label"><?= e(__($navEntry['label_key'])) ?></span>
                        <span class="pp-nav-group__chevron" aria-hidden="true"></span>
                        <span class="pp-nav-visual-tooltip" aria-hidden="true"><?= e(__($navEntry['label_key'])) ?></span>
                    </button>
                    <ul id="<?= e($groupPanelId) ?>" class="pp-nav-group__items"<?= $groupOpen ? '' : ' hidden' ?>>
                        <?php foreach ($navEntry['items'] as $item):
                            $activeClass = !empty($item['active']) ? ' is-active' : '';
                        ?>
                        <li>
                            <a href="<?= e(base_url($item['url'])) ?>"
                               class="pp-nav-item pp-nav-item--child<?= $activeClass ?>"
                               <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>>
                                <span class="pp-nav-item__icon pp-icon--<?= e($item['icon']) ?>" aria-hidden="true"></span>
                                <span class="pp-nav-item__label"><?= e(__($item['label_key'])) ?></span>
                                <span class="pp-nav-visual-tooltip" aria-hidden="true"><?= e(__($item['label_key'])) ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endif; ?>
            <?php endforeach; ?>
            </ul>
        </nav>

        <div class="pp-sidebar__footer">
            <button type="button"
                    class="pp-sidebar__collapse"
                    id="pp-sidebar-collapse"
                    aria-controls="pp-sidebar"
                    aria-expanded="true"
                    aria-label="<?= e(__('nav.collapse')) ?>"
                    data-label-expand="<?= e(__('nav.expand')) ?>"
                    data-label-collapse="<?= e(__('nav.collapse')) ?>">
                <span class="pp-sidebar__collapse-icon" aria-hidden="true"></span>
                <span class="pp-sidebar__collapse-label"><?= e(__('nav.collapse')) ?></span>
                <span class="pp-nav-visual-tooltip" aria-hidden="true"><?= e(__('nav.collapse')) ?></span>
            </button>
            <span class="pp-sidebar__version">v<?= e(PP_VERSION) ?></span>
        </div>
    </aside>

    <!-- Main area -->
    <div class="pp-main">

        <!-- Topbar -->
        <header class="pp-topbar">
            <button class="pp-topbar__toggle" id="pp-sidebar-toggle" type="button"
                    aria-label="<?= e(__('common.menu')) ?>"
                    aria-controls="pp-sidebar" aria-expanded="false">
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
