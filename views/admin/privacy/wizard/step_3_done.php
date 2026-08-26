<?php
/** Pantalla final tras generación exitosa. */
$pages = array_filter($legalPagesState, fn ($p) => $p !== null);
$totalTodos = 0; // Lo calculamos por contenido si quisiéramos; de momento 0.
?>

<div class="pp-wizard__done">
    <div class="pp-wizard__done-icon" aria-hidden="true">✓</div>
    <h3><?= e(__('privacy.wizard.done_title', ['n' => count($legalTypes)])) ?></h3>
    <p><?= __('privacy.wizard.done_help.html') ?></p>
</div>

<div class="pp-wizard__done-pages">
    <?php foreach ($legalTypes as $key => $info):
        $p = $legalPagesState[$key] ?? null;
        if (!$p) continue;
    ?>
    <article class="pp-wizard__done-card">
        <h4><?= e($info['label']) ?></h4>
        <p class="pp-wizard__done-url"><code><?= e(base_url(ltrim((string) $info['slug'], '/'))) ?></code></p>
        <div class="pp-wizard__done-actions">
            <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/posts/' . (int) $p['id'] . '/edit')) ?>"><?= e(__('common.edit')) ?></a>
            <a class="pp-btn pp-btn--ghost pp-btn--sm" target="_blank" rel="noopener" href="<?= e(base_url(ltrim((string) $p['slug'], '/'))) ?>"><?= e(__('privacy.pages.see_public')) ?> ↗</a>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<div class="pp-wizard__done-cta">
    <a class="pp-btn pp-btn--primary" href="<?= e(base_url('admin/privacy?tab=summary')) ?>"><?= e(__('privacy.wizard.go_panel')) ?></a>
</div>
