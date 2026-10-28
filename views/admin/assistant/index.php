<?php
/**
 * FEAT-5 F5-T1 — Asistente central del sitio.
 *
 * @var string $csrf
 * @var int $maxSize
 * @var array $allowedExt
 */
\Core\View::extend('admin/layout');
$maxMb = round($maxSize / 1024 / 1024);
// ASST-CONV AC1 — el clip admite también imágenes (varias a la vez).
$accept = '.' . implode(',.', $allowedExt) . ',.jpg,.jpeg,.png,.webp,.gif';
// ASST-V2 F1 — conversación a retomar y las recientes de esta persona.
$conversation = $conversation ?? null;
$recentConversations = $recentConversations ?? [];
$notice = \Core\Session::flash('notice');
?>

<?php \Core\View::start('title'); ?>Asistente<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<?php
$assistantMode = 'page';
include __DIR__ . '/_config.php';
foreach ($assistantAssets as $assetUrl): ?>
<script src="<?= e($assetUrl) ?>"></script>
<?php endforeach; ?>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('assistant.title')) ?></h2>
    <div class="ppa-conversations">
        <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/assistant?nueva=1')) ?>"><?= e(__('assistant.new_conversation')) ?></a>
        <?php if ($recentConversations !== []): ?>
        <details class="ppa-conversations__menu">
            <summary class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('asst.conv.recent')) ?></summary>
            <ul class="ppa-conversations__list">
                <?php foreach ($recentConversations as $c): ?>
                <li class="<?= $conversation !== null && (int) $conversation['id'] === (int) $c['id'] ? 'is-current' : '' ?>">
                    <a href="<?= e(base_url('admin/assistant?c=' . (int) $c['id'])) ?>">
                        <span><?= e($c['title'] !== '' ? $c['title'] : __('asst.conv.untitled')) ?></span>
                        <small><?= e(date('d/m H:i', strtotime((string) $c['updated_at']) ?: time())) ?></small>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if ($conversation !== null): ?>
                <li class="ppa-conversations__delete">
                    <form method="POST" action="<?= e(base_url('admin/assistant/conversations/' . (int) $conversation['id'] . '/delete')) ?>"
                          data-confirm="<?= e(__('asst.conv.delete_confirm')) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm"><?= e(__('asst.conv.delete')) ?></button>
                    </form>
                </li>
                <?php endif; ?>
            </ul>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>

<p class="pp-page-intro">
    <?= e(__('assistant.intro')) ?>
</p>

<?php include __DIR__ . '/_chat.php'; ?>
