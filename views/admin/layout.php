<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(\Core\View::section('title', 'Panel')) ?> — PromptPress</title>
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
                    <span class="pp-sidebar__logo pp-sidebar__logo--image"><img src="<?= e($siteLogoUrl) ?>" alt="<?= e($siteName ?? 'Sitio') ?>"></span>
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
                ['url' => 'admin/',           'icon' => 'dashboard', 'label' => 'Escritorio',     'match' => '/admin'],
                ['url' => 'admin/pages',      'icon' => 'pages',     'label' => 'Páginas',        'match' => '/admin/pages'],
                ['url' => 'admin/posts',      'icon' => 'posts',     'label' => 'Entradas',       'match' => '/admin/posts'],
                ['url' => 'admin/media',      'icon' => 'media',     'label' => 'Medios',         'match' => '/admin/media'],
                ['url' => 'admin/formularios','icon' => 'forms',     'label' => 'Formularios',    'match' => '/admin/formularios'],
                ['url' => 'admin/forms',      'icon' => 'messages',  'label' => 'Mensajes',       'match' => '/admin/forms'],
                ['url' => 'admin/memory',     'icon' => 'memory',    'label' => 'Conocimiento',   'match' => '/admin/memory'],
                ['url' => 'admin/documents',  'icon' => 'documents', 'label' => 'Documentos',     'match' => '/admin/documents'],
                ['url' => 'admin/design',     'icon' => 'design',    'label' => 'Diseño',         'match' => '/admin/design'],
                ['url' => 'admin/chrome',     'icon' => 'chrome',    'label' => 'Header y pie',   'match' => '/admin/chrome'],
                ['url' => 'admin/seo',        'icon' => 'seo',       'label' => 'SEO',            'match' => '/admin/seo'],
                ['url' => 'admin/marketing',  'icon' => 'marketing', 'label' => 'Marketing',      'match' => '/admin/marketing'],
                ['url' => 'admin/ai/usage',   'icon' => 'ai',        'label' => 'IA',             'match' => '/admin/ai'],
                ['url' => 'admin/privacy',    'icon' => 'privacy',   'label' => 'Privacidad',     'match' => '/admin/privacy'],
                ['url' => 'admin/settings',   'icon' => 'settings',  'label' => 'Ajustes',        'match' => '/admin/settings'],
            ];
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
            <button class="pp-topbar__toggle" id="pp-sidebar-toggle" type="button" aria-label="Menú">
                <span class="pp-hamburger"></span>
            </button>

            <div class="pp-topbar__actions">
                <?php if (isset($siteName)): ?>
                <a href="<?= e(base_url('/')) ?>" class="pp-topbar__site" target="_blank" title="Ver sitio">
                    <?= e($siteName) ?>
                    <span class="pp-icon--external"></span>
                </a>
                <?php endif; ?>

                <?php if (\Core\Auth::check()): ?>
                <div class="pp-topbar__user">
                    <span class="pp-topbar__username"><?= e($userName ?? 'Admin') ?></span>
                    <form method="POST" action="<?= e(base_url('admin/logout')) ?>" class="pp-logout-form">
                        <input type="hidden" name="_csrf" value="<?= e(\Core\CSRF::token()) ?>">
                        <button type="submit" class="pp-topbar__logout" title="Cerrar sesión">Salir</button>
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

    <script src="<?= e(base_url('admin/assets/js/admin.js')) ?>"></script>
    <?= \Core\View::section('scripts', '') ?>
</body>
</html>
