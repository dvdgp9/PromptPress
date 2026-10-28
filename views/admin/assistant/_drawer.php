<?php
/**
 * ASST-V2 F2 — Panel lateral del asistente, disponible en todo el panel.
 *
 * Lo incluye admin/layout.php. El HTML es ligero y va oculto; los scripts del
 * chat (composer, PDF, assistant.js) se cargan la primera vez que se abre.
 *
 * @var array{type:string,id:int,label:string}|null $assistantContext
 */
$assistantMode = 'drawer';
$maxMb = round(\App\Controllers\Admin\DocumentController::MAX_SIZE / 1024 / 1024);
$accept = '.' . implode(',.', \App\Controllers\Admin\DocumentController::ALLOWED_EXT) . ',.jpg,.jpeg,.png,.webp,.gif';
include __DIR__ . '/_config.php';
$drawerJs = PP_ROOT . '/admin/assets/js/assistant-drawer.js';
?>
<button type="button" class="ppa-launcher" id="ppa-launcher"
        aria-controls="ppa-drawer" aria-expanded="false" aria-keyshortcuts="Control+K Meta+K"
        title="<?= e(__('asst.drawer.open_title')) ?>">
    <span class="ppa-launcher__icon pp-icon--ai" aria-hidden="true"></span>
    <span class="ppa-launcher__label"><?= e(__('nav.assistant')) ?></span>
    <kbd class="ppa-launcher__kbd" data-mac="⌘K" data-other="Ctrl K">Ctrl K</kbd>
    <span class="ppa-launcher__badge" id="ppa-launcher-badge" hidden aria-hidden="true"></span>
    <span class="pp-sr-only" id="ppa-launcher-status" role="status" aria-live="polite"></span>
</button>

<aside class="ppa-drawer" id="ppa-drawer" aria-labelledby="ppa-drawer-title" hidden
       data-assets="<?= e(json_encode($assistantAssets, JSON_UNESCAPED_SLASHES)) ?>">
    <header class="ppa-drawer__head">
        <div class="ppa-drawer__bar">
            <h2 class="ppa-drawer__title" id="ppa-drawer-title">
                <span class="ppa-launcher__icon pp-icon--ai" aria-hidden="true"></span><?= e(__('nav.assistant')) ?>
            </h2>
            <div class="ppa-drawer__tools">
                <button type="button" class="ppa-icon-btn" id="ppa-drawer-new"
                        title="<?= e(__('assistant.new_conversation')) ?>" aria-label="<?= e(__('assistant.new_conversation')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>
                </button>
                <a class="ppa-icon-btn" id="ppa-drawer-expand" href="<?= e(base_url('admin/assistant')) ?>"
                   title="<?= e(__('asst.drawer.expand')) ?>" aria-label="<?= e(__('asst.drawer.expand')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>
                </a>
                <button type="button" class="ppa-icon-btn" id="ppa-drawer-close"
                        title="<?= e(__('asst.drawer.close')) ?>" aria-label="<?= e(__('asst.drawer.close')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>
        <div class="ppa-context" id="ppa-context" hidden>
            <span class="ppa-context__label"><?= e(__('asst.ctx.viewing')) ?></span>
            <strong class="ppa-context__value" id="ppa-context-value"></strong>
            <button type="button" class="ppa-context__clear" id="ppa-context-clear"
                    title="<?= e(__('asst.ctx.clear')) ?>" aria-label="<?= e(__('asst.ctx.clear')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 7l10 10M17 7 7 17"/></svg>
            </button>
        </div>
    </header>
    <div class="ppa-drawer__body">
        <div class="ppa-drawer__skeleton" id="ppa-drawer-skeleton" aria-hidden="true">
            <span></span><span></span><span class="is-short"></span>
        </div>
        <?php include __DIR__ . '/_chat.php'; ?>
    </div>
</aside>
<script src="<?= e(base_url('admin/assets/js/assistant-drawer.js')) ?>?v=<?= e((string) (file_exists($drawerJs) ? filemtime($drawerJs) : PP_VERSION)) ?>" defer></script>
