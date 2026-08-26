<?php
/**
 * Layout del instalador.
 * Variables esperadas:
 *   $title    string
 *   $content  string (HTML del paso)
 *   $stepKey  string (clave del paso actual)
 *   $stepName string (nombre legible)
 *   $steps    array<string,string> (todos los pasos)
 *   $stepIdx  int (índice 0-based del paso actual)
 */
?><!doctype html>
<html lang="<?= e(\App\Services\AdminI18n::htmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> — <?= e(__('inst.app_title')) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('install/assets/install.css')) ?>?v=<?= e(PP_VERSION) ?>">
</head>
<body class="pp-install">

<header class="pp-install__header">
    <div class="pp-install__brand">
        <strong>PromptPress</strong>
        <span class="pp-install__version">v<?= e(PP_VERSION) ?></span>
    </div>
    <div class="pp-install__subtitle"><?= e(__('inst.subtitle')) ?></div>
    <div class="pp-install__lang">
        <?php foreach (\App\Services\AdminI18n::LOCALES as $loc): ?>
            <a href="<?= e(InstallerApp::stepUrl($stepKey) . '&lang=' . $loc) ?>"
               class="pp-install__lang-link<?= $loc === InstallerApp::language() ? ' is-current' : '' ?>"
               hreflang="<?= e($loc) ?>"><?= e(strtoupper($loc)) ?></a>
        <?php endforeach; ?>
    </div>
</header>

<nav class="pp-steps" aria-label="<?= e(__('inst.steps_aria')) ?>">
    <ol>
        <?php $i = 0; foreach ($steps as $key => $label): ?>
            <li class="pp-steps__item <?php
                if ($i < $stepIdx) echo 'is-done';
                elseif ($i === $stepIdx) echo 'is-current';
                else echo 'is-pending';
            ?>">
                <span class="pp-steps__num"><?= $i + 1 ?></span>
                <span class="pp-steps__label"><?= e($label) ?></span>
            </li>
            <?php $i++; endforeach; ?>
    </ol>
</nav>

<main class="pp-install__main">
    <div class="pp-card">
        <?= $content /* ya HTML escapado donde corresponde */ ?>
    </div>
</main>

<footer class="pp-install__footer">
    <small>PromptPress &copy; <?= date('Y') ?> · <?= e(__('inst.footer')) ?></small>
</footer>

</body>
</html>
