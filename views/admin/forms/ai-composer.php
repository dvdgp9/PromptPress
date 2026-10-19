<?php
$aiEditor = $aiEditor ?? false;
$aiTexts = [];
foreach (['generating','ready','failed','saving','description_error','image_error','required','optional','applied','undone','too_many_fields'] as $key) $aiTexts[$key] = __('forms.ai.' . $key);
$aiTypeLabels = [];
foreach (\App\Services\FormDefinition::TYPES as $type) $aiTypeLabels[$type] = $type === 'url' ? 'URL' : __('field.' . $type);
?>
<details class="pp-form-ai-composer" data-form-ai <?= $aiEditor ? '' : 'open' ?>
         data-design-url="<?= e(base_url('admin/formularios/design')) ?>"
         data-create-url="<?= e(base_url('admin/formularios/create-ai')) ?>"
         data-csrf="<?= e($csrf) ?>" data-form-id="<?= $aiEditor ? (int) $form_id : 0 ?>"
         data-texts="<?= e(json_encode($aiTexts, JSON_UNESCAPED_UNICODE)) ?>"
         data-types="<?= e(json_encode($aiTypeLabels, JSON_UNESCAPED_UNICODE)) ?>">
    <summary><?= e(__('forms.ai.title')) ?></summary>
    <div class="pp-form-ai-composer__body">
        <p><?= e(__('forms.ai.help')) ?></p>
        <label class="pp-fb-control" for="pp-ai-description"><span><?= e(__('forms.ai.prompt')) ?></span>
            <textarea id="pp-ai-description" rows="4" maxlength="12000" data-ai-description placeholder="<?= e(__('forms.ai.placeholder')) ?>"></textarea>
        </label>
        <div class="pp-form-ai-composer__reference">
            <label class="pp-fb-control" for="pp-ai-reference"><span><?= e(__('forms.ai.reference')) ?></span>
                <input type="file" id="pp-ai-reference" accept="image/png,image/jpeg,image/webp" data-ai-reference aria-describedby="pp-ai-image-help">
                <small id="pp-ai-image-help"><?= e(__('forms.ai.image_help')) ?></small>
            </label>
            <button type="button" class="pp-btn pp-btn--ghost pp-btn--sm" data-ai-example><?= e(__('forms.ai.example')) ?></button>
        </div>
        <div class="pp-form-ai-composer__actions">
            <button type="button" class="pp-btn pp-btn--primary" data-ai-generate><?= e(__('forms.ai.generate')) ?></button>
            <button type="button" class="pp-btn pp-btn--secondary" data-ai-undo hidden><?= e(__('forms.ai.undo')) ?></button>
        </div>
        <p data-ai-status role="status" aria-live="polite" hidden></p>
        <section class="pp-form-ai-review" data-ai-review hidden aria-labelledby="pp-ai-review-title">
            <h3 id="pp-ai-review-title"><?= e(__('forms.ai.review')) ?></h3>
            <div data-ai-preview></div>
            <div class="pp-form-ai-composer__actions">
                <?php if ($aiEditor): ?>
                <label class="pp-fb-control"><span><?= e(__('forms.ai.mode')) ?></span>
                    <select data-ai-mode><option value="replace"><?= e(__('forms.ai.replace')) ?></option><option value="append"><?= e(__('forms.ai.append')) ?></option></select>
                </label>
                <?php endif; ?>
                <button type="button" class="pp-btn pp-btn--primary" data-ai-apply><?= e(__($aiEditor ? 'forms.ai.apply' : 'forms.ai.create')) ?></button>
            </div>
        </section>
    </div>
</details>
