<?php
/**
 * @var array $formsList
 */

$basisLabels = [
    'legitimate_interest' => __('privacy.basis.legitimate'),
    'consent'             => __('privacy.basis.consent'),
    'contract'            => __('privacy.basis.contract'),
];
?>

<?php if (empty($formsList)): ?>
<div class="pp-privacy-soon">
    <div class="pp-privacy-soon__icon" aria-hidden="true">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="3" width="16" height="18" rx="2"/>
            <line x1="8" y1="8" x2="16" y2="8"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
            <line x1="8" y1="16" x2="13" y2="16"/>
        </svg>
    </div>
    <h3><?= e(__('privacy.forms.empty')) ?></h3>
    <p><?= e(__('privacy.forms.empty_help')) ?></p>
</div>
<?php else: ?>

<div class="pp-privacy-notice pp-privacy-notice--info">
    <?= __('privacy.forms.intro.html') ?>
</div>

<div class="pp-privacy-forms">
    <?php foreach ($formsList as $f):
        $basisLabel = $basisLabels[$f['lawful_basis']] ?? $f['lawful_basis'];
    ?>
    <article class="pp-privacy-formcard">
        <header class="pp-privacy-formcard__head">
            <div>
                <h3><?= e($f['heading'] !== '' ? $f['heading'] : __('privacy.forms.untitled')) ?></h3>
                <p class="pp-privacy-formcard__meta">
                    <?= e(__('privacy.forms.on_page')) ?> <strong><?= e($f['page_title']) ?></strong>
                    · <?= e(__($f['fields_count'] === 1 ? 'forms.fields_one' : 'forms.fields_other', ['n' => (int) $f['fields_count']])) ?>
                    <?php if ($f['page_status'] !== 'published'): ?>
                        · <span class="pp-privacy-formcard__badge pp-privacy-formcard__badge--draft"><?= e(__('status.draft')) ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <a class="pp-btn pp-btn--secondary pp-btn--sm"
               href="<?= e(base_url('admin/pages/' . (int) $f['page_id'] . '/edit#sec-' . (int) $f['section_id'])) ?>">
                <?= e(__('privacy.forms.edit_form')) ?>
            </a>
        </header>

        <dl class="pp-privacy-formcard__grid">
            <div>
                <dt><?= e(__('privacy.forms.legal_basis')) ?></dt>
                <dd>
                    <span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--<?= e($f['lawful_basis']) ?>"><?= e($basisLabel) ?></span>
                </dd>
            </div>
            <div>
                <dt><?= e(__('privacy.forms.retention')) ?></dt>
                <dd><?= e($f['retention_period']) ?></dd>
            </div>
            <div>
                <dt><?= e(__('privacy.forms.marketing_optin')) ?></dt>
                <dd>
                    <?= $f['marketing_opt_in']
                        ? '<span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--marketing">' . e(__('common.yes')) . '</span>'
                        : '<span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--off">' . e(__('common.no')) . '</span>' ?>
                </dd>
            </div>
        </dl>
    </article>
    <?php endforeach; ?>
</div>

<div class="pp-privacy-summary__hint" style="margin-top: 24px;">
    <strong><?= e(__('privacy.forms.how')) ?></strong>
    <p><?= __('privacy.forms.how_help.html') ?></p>
</div>

<?php endif; ?>
