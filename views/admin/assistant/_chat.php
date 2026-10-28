<?php
/**
 * ASST-V2 F2 — El chat del asistente (hilo + composer + selector de medios).
 * Lo pintan la página /admin/assistant y el panel lateral de todo el panel.
 * assistant.js lo busca por id: solo puede haber uno por página.
 *
 * @var int|float $maxMb
 * @var string $accept
 */
?>
<div class="ppa-media-picker" id="ppa-media-picker" role="presentation" hidden>
    <section class="ppa-media-picker__dialog" role="dialog" aria-modal="true"
             aria-labelledby="ppa-media-picker-title">
        <header class="ppa-media-picker__head">
            <div>
                <h3 id="ppa-media-picker-title"><?= e(__('assistant.media_picker_title')) ?></h3>
                <p><?= e(__('assistant.media_picker_help')) ?></p>
            </div>
            <button type="button" class="ppa-media-picker__close" id="ppa-media-picker-close"
                    aria-label="<?= e(__('common.close')) ?>">×</button>
        </header>
        <div class="ppa-media-picker__status" id="ppa-media-picker-status" role="status"></div>
        <div class="ppa-media-picker__grid" id="ppa-media-picker-grid"></div>
    </section>
</div>

<div class="ppa-chat" id="ppa-chat">
    <div class="ppa-thread" id="ppa-thread">
        <div class="ppa-msg ppa-msg--assistant">
            <div class="ppa-msg__bubble"><?= e(__('assistant.greeting')) ?></div>
        </div>
    </div>

    <div class="ppa-composer" id="ppa-composer" data-drop-label="<?= e(__('assistant.drop_here')) ?>">
        <div class="ppa-attachments" id="ppa-attachments" hidden></div>

        <div class="ppa-composer__tools" aria-label="<?= e(__('assistant.editor_tools')) ?>">
            <button type="button" class="ppa-composer__attach" id="ppa-attach-btn"
                    title="<?= e(__('assistant.attach_title', ['mb' => (int) $maxMb])) ?>"
                    aria-label="<?= e(__('assistant.attach_title', ['mb' => (int) $maxMb])) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 12.5 14.9 6a3 3 0 0 1 4.2 4.2l-8.5 8.5a5 5 0 0 1-7.1-7.1l8.2-8.2"/></svg>
            </button>
            <button type="button" class="ppa-composer__tool" id="ppa-paste-plain">
                <?= e(__('assistant.paste_plain')) ?>
            </button>
            <input type="file" id="ppa-file-input" accept="<?= e($accept) ?>" multiple hidden>
        </div>

        <div class="ppa-composer__row">
            <div class="ppa-composer__editor" id="ppa-rich-input" contenteditable="true" role="textbox"
                 aria-multiline="true" aria-label="<?= e(__('assistant.editor_label')) ?>"
                 aria-describedby="ppa-hint ppa-rich-status" data-placeholder="<?= e(__('assistant.placeholder')) ?>" hidden></div>
            <textarea class="ppa-composer__input" id="ppa-input" rows="3"
                      maxlength="<?= (int) \App\Services\AssistantContentNormalizer::DEFAULT_MAX_CHARS ?>"
                      aria-label="<?= e(__('assistant.editor_label')) ?>"
                      placeholder="<?= e(__('assistant.placeholder')) ?>"></textarea>
            <button type="button" class="pp-btn pp-btn--primary ppa-composer__send" id="ppa-send" disabled><?= e(__('assistant.propose_plan')) ?></button>
        </div>
        <div class="ppa-composer__status" id="ppa-rich-status" role="status" aria-live="polite" hidden></div>
        <div class="ppa-composer__hint" id="ppa-hint">
            <?= e(__('assistant.rich_hint')) ?>
        </div>
    </div>
</div>
