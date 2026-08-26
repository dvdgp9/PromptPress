<?php
/** @var array $status */
/** @var array $manifest */
$gaps = $status['gaps'] ?? [];
$level = $status['level'] ?? 'green';
?>

<div class="pp-privacy-summary">
    <?php if ($level === 'green'): ?>
        <div class="pp-privacy-summary__card pp-privacy-summary__card--ok">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="M9 12l2 2 4-4"/>
            </svg>
            <div>
                <h3><?= e(__('privacy.level.green')) ?></h3>
                <p><?= e(__('privacy.all_ok_help')) ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="pp-privacy-summary__card pp-privacy-summary__card--<?= e($level) ?>">
            <div>
                <h3>
                    <?= e(match ($level) {
                        'red'    => __('privacy.head_red'),
                        'orange' => __('privacy.head_orange'),
                        default  => __('privacy.level.yellow'),
                    }) ?>
                </h3>
                <p><?= e(__('privacy.gaps_help')) ?></p>
            </div>
        </div>

        <ul class="pp-privacy-gaps">
            <?php foreach ($gaps as $g): ?>
            <li class="pp-privacy-gap pp-privacy-gap--<?= e($g['severity']) ?>">
                <div class="pp-privacy-gap__text">
                    <strong><?= e($g['title']) ?></strong>
                    <span><?= e($g['description'] ?? '') ?></span>
                </div>
                <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url(ltrim($g['cta_url'], '/'))) ?>">
                    <?= e($g['cta_label']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="pp-privacy-summary__hint">
        <strong><?= e(__('privacy.why')) ?></strong>
        <p><?= e(__('privacy.why_help')) ?></p>
    </div>

    <div class="pp-privacy-summary__wizard-cta">
        <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/privacy/wizard')) ?>">
            <?= e(__('privacy.reopen_wizard')) ?>
        </a>
        <small><?= e(__('privacy.wizard_hint')) ?></small>
    </div>
</div>
