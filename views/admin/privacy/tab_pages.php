<?php
/**
 * @var array $manifest
 * @var array $legalPagesState  ['privacy_policy'=>['id','title',...]|null, ...]
 * @var array $legalTypes       LegalPageGenerator::TYPES
 * @var string $csrf
 */

$controller = (array) ($manifest['controller'] ?? []);
$controllerReady = trim((string) ($controller['legal_name'] ?? '')) !== ''
                && trim((string) ($controller['address'] ?? '')) !== ''
                && trim((string) ($controller['email'] ?? '')) !== '';
?>

<?php if (!$controllerReady): ?>
<div class="pp-privacy-notice pp-privacy-notice--warning">
    <?= __('privacy.pages.before.html') ?>
    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/privacy?tab=legal')) ?>"><?= e(__('privacy.pages.fill_data')) ?></a>
</div>
<?php endif; ?>

<?php
$missingPages = array_filter($legalTypes, fn ($info, $key) => ($legalPagesState[$key] ?? null) === null, ARRAY_FILTER_USE_BOTH);
$missingCount = count($missingPages);
$typesCount   = count($legalTypes);

// Config para el progreso por página (privacy-generate.js).
$genTypes = [];
foreach ($legalTypes as $typeKey => $typeInfo) {
    $genTypes[] = ['key' => $typeKey, 'label' => $typeInfo['label']];
}
?>
<?php if ($controllerReady): ?>
<div class="pp-privacy-bulk">
    <form method="POST" action="<?= e(base_url('admin/privacy/pages/generate-all')) ?>" class="pp-privacy-bulk__form"
          data-legal-generate="<?= e(json_encode($genTypes, JSON_UNESCAPED_UNICODE)) ?>"
          data-generate-url="<?= e(base_url('admin/privacy/pages/generate')) ?>"
          data-done-url="<?= e(base_url('admin/privacy?tab=pages')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-privacy-bulk__text">
            <strong><?= e($missingCount === $typesCount ? __('privacy.pages.generate_all', ['n' => $typesCount]) : __('privacy.pages.regenerate_all')) ?></strong>
            <p><?= __('privacy.pages.bulk_help.html') ?></p>
        </div>
        <button type="submit" class="pp-btn pp-btn--primary">
            <?= e($missingCount > 0 ? __('privacy.pages.generate_n', ['n' => $typesCount]) : __('privacy.pages.regenerate_n', ['n' => $typesCount])) ?>
        </button>
    </form>
</div>
<?php endif; ?>

<div class="pp-privacy-pages">
    <?php foreach ($legalTypes as $typeKey => $info):
        $existing = $legalPagesState[$typeKey] ?? null;
        $generated = $existing !== null;
    ?>
    <article class="pp-privacy-pagecard <?= $generated ? 'is-generated' : 'is-missing' ?>">
        <header class="pp-privacy-pagecard__head">
            <div>
                <h3><?= e($info['label']) ?></h3>
                <?php if ($generated): ?>
                    <p class="pp-privacy-pagecard__meta">
                        <span class="pp-privacy-pagecard__badge pp-privacy-pagecard__badge--ok"><?= e(__('privacy.pages.generated')) ?></span>
                        <span><?= e(__('privacy.pages.last_update')) ?>: <?= e(date('d/m/Y H:i', strtotime($existing['updated_at']))) ?></span>
                    </p>
                <?php else: ?>
                    <p class="pp-privacy-pagecard__meta">
                        <span class="pp-privacy-pagecard__badge pp-privacy-pagecard__badge--missing"><?= e(__('privacy.pages.not_created')) ?></span>
                        <span><?= e(__('privacy.pages.will_generate')) ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </header>

        <div class="pp-privacy-pagecard__actions">
            <form method="POST" action="<?= e(base_url('admin/privacy/pages/generate')) ?>" class="pp-privacy-pagecard__form"
                  data-legal-generate="<?= e(json_encode([['key' => $typeKey, 'label' => $info['label']]], JSON_UNESCAPED_UNICODE)) ?>"
                  data-progress-target=".pp-privacy-pagecard"
                  data-done-url="<?= e(base_url('admin/privacy?tab=pages')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="type" value="<?= e($typeKey) ?>">
                <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm" <?= !$controllerReady ? 'disabled aria-disabled="true"' : '' ?>>
                    <?= e($generated ? __('privacy.pages.regenerate_ai') : __('privacy.pages.generate_ai')) ?>
                </button>
            </form>
            <?php if ($generated): ?>
                <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/posts/' . (int) $existing['id'] . '/edit')) ?>"><?= e(__('common.edit')) ?></a>
                <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url(ltrim((string) $existing['slug'], '/'))) ?>" target="_blank" rel="noopener"><?= e(__('privacy.pages.see_public')) ?> ↗</a>
            <?php endif; ?>
        </div>

        <div class="pp-privacy-pagecard__url">
            <code><?= e(base_url(ltrim((string) $info['slug'], '/'))) ?></code>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<div class="pp-privacy-summary__hint" style="margin-top: 24px;">
    <strong><?= e(__('privacy.how_it_works')) ?></strong>
    <p><?= __('privacy.pages.how_help.html') ?></p>
</div>
