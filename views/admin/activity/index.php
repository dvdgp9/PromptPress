<?php
/**
 * ASST-V2 F1 — Actividad: cambios recientes con deshacer.
 *
 * @var array<int,array<string,mixed>> $entries
 * @var string $filter
 * @var string|null $notice
 * @var string|null $error
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayLabel = static function (string $day) use ($today, $yesterday): string {
    if ($day === $today) return __('activity.today');
    if ($day === $yesterday) return __('activity.yesterday');
    return date('d/m/Y', strtotime($day) ?: time());
};
$who = static function (array $e): string {
    $name = (string) $e['username'];
    return match ($e['actor']) {
        'assistant' => __('activity.who.assistant'),
        'ai' => __('activity.who.ai'),
        default => $name !== '' ? $name : __('activity.who.someone'),
    };
};
$byDay = [];
foreach ($entries as $e) {
    $byDay[substr((string) $e['at'], 0, 10)][] = $e;
}
?>

<?php \Core\View::start('title'); ?><?= e(__('activity.title')) ?><?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('activity.title')) ?></h2>
</div>

<p class="pp-page-intro"><?= e(__('activity.intro', ['dias' => \App\Services\ActivityService::DAYS])) ?></p>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<nav class="pp-activity__filters" aria-label="<?= e(__('activity.filter_label')) ?>">
    <?php foreach (['all', 'assistant', 'manual'] as $f): ?>
        <a href="<?= e(base_url('admin/actividad' . ($f === 'all' ? '' : '?f=' . $f))) ?>"
           class="pp-activity__filter<?= $filter === $f ? ' is-active' : '' ?>"
           <?= $filter === $f ? 'aria-current="page"' : '' ?>><?= e(__('activity.filter.' . $f)) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($entries === []): ?>
    <div class="pp-activity__empty">
        <p class="pp-activity__empty-title"><?= e(__('activity.empty_title')) ?></p>
        <p><?= e(__('activity.empty_body')) ?></p>
        <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/assistant')) ?>"><?= e(__('activity.empty_cta')) ?></a>
    </div>
<?php else: ?>
    <?php foreach ($byDay as $day => $dayEntries): ?>
    <section class="pp-activity__day">
        <h3 class="pp-activity__day-title"><?= e($dayLabel($day)) ?></h3>
        <ul class="pp-activity__list">
            <?php foreach ($dayEntries as $e): ?>
            <?php
            $lineKey = 'activity.line.' . $e['type'] . ($e['actor'] === 'restore' ? '_restore' : '');
            $line = __($lineKey, ['quien' => $who($e), 'titulo' => (string) $e['title']]);
            ?>
            <li class="pp-activity__item pp-activity__item--<?= e($e['actor']) ?><?= $e['undone'] ? ' is-undone' : '' ?>">
                <span class="pp-activity__icon pp-icon--<?= ['form' => 'forms', 'seo' => 'seo', 'chrome' => 'chrome', 'design' => 'design', 'booking_service' => 'booking', 'commerce_product' => 'commerce', 'resource' => 'resources'][$e['type']] ?? 'pages' ?>" aria-hidden="true"></span>
                <div class="pp-activity__text">
                    <p class="pp-activity__line"><?= e($line) ?></p>
                    <p class="pp-activity__meta">
                        <?= e(date('H:i', strtotime((string) $e['at']) ?: time())) ?>
                        <?php if ($e['actor'] === 'assistant' && $e['username'] !== ''): ?>
                            · <?= e(__('activity.requested_by', ['nombre' => (string) $e['username']])) ?>
                        <?php endif; ?>
                        <?php if ($e['detail'] !== ''): ?> · <?= e(mb_strimwidth((string) $e['detail'], 0, 140, '…')) ?><?php endif; ?>
                    </p>
                </div>
                <div class="pp-activity__actions">
                    <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e((string) $e['edit_url']) ?>"><?= e(__('activity.open')) ?></a>
                    <?php if ($e['undone']): ?>
                        <span class="pp-activity__undone"><?= e(__('activity.undone')) ?></span>
                    <?php elseif ($e['undoable']): ?>
                    <form method="POST" action="<?= e(base_url('admin/actividad/undo')) ?>"
                          data-confirm="<?= e(__('activity.undo_confirm.' . $e['type'])) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="type" value="<?= e($e['type']) ?>">
                        <input type="hidden" name="entity_id" value="<?= (int) $e['entity_id'] ?>">
                        <input type="hidden" name="version_id" value="<?= (int) $e['version_id'] ?>">
                        <input type="hidden" name="f" value="<?= e($filter) ?>">
                        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('activity.undo')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
<?php endif; ?>
