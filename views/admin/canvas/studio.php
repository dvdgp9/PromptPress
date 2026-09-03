<?php
/**
 * FH3 — Studio Live: página en vivo + chat de cambios.
 * Vista standalone (sin layout admin). Variables: $page, $sections, $versionsCount.
 */
$pageId = (int) $page['id'];
$isPublished = ($page['status'] ?? '') === 'published';
// Cache-busting por filemtime (igual que views/admin/layout.php): sin esto el
// navegador sirve versiones viejas del CSS/JS del studio tras cada cambio.
$cssPath = PP_ROOT . '/admin/assets/css/admin.css';
$jsPath  = PP_ROOT . '/admin/assets/js/canvas-studio.js';
$cssVer  = file_exists($cssPath) ? filemtime($cssPath) : PP_VERSION;
$jsVer   = file_exists($jsPath) ? filemtime($jsPath) : PP_VERSION;
$resourceCounts = [];
if (!empty($publishedResources)) {
    $available = count($publishedResources);
    $resourceCounts = array_values(array_unique([1, min(3, $available), min(6, $available)]));
    sort($resourceCounts);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(__('cv.studio')) ?> — <?= e($page['title']) ?></title>
<link rel="stylesheet" href="<?= e(base_url('admin/assets/css/admin.css')) ?>?v=<?= e($cssVer) ?>">
<meta name="csrf" content="<?= e(\Core\CSRF::token()) ?>">
<?php /* FH9 — tokens de marca disponibles en el chrome del Studio (no solo en el iframe). */ ?>
<style><?= $brandVars ?? '' ?></style>
</head>
<?php
// FH9 — set de iconos SVG consistente para la barra del Studio.
$icon = static function (string $name): string {
    $paths = [
        'back'     => '<path d="M15 18l-6-6 6-6"/>',
        'undo'     => '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/>',
        'redo'     => '<path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h1"/>',
        'history'  => '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l3 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/>',
        'more'     => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
        'desktop'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'mobile'   => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
?>
<body class="cvstudio-body"
      data-page-id="<?= $pageId ?>"
      data-preview-url="<?= e(base_url('admin/canvas/' . $pageId . '/preview')) ?>"
      data-chat-url="<?= e(base_url('admin/canvas/' . $pageId . '/chat')) ?>"
      data-cancel-url="<?= e(base_url('admin/canvas/' . $pageId . '/cancel')) ?>"
      data-versions-url="<?= e(base_url('admin/canvas/' . $pageId . '/versions')) ?>"
      data-restore-url="<?= e(base_url('admin/canvas/' . $pageId . '/restore')) ?>"
      data-publish-url="<?= e(base_url('admin/canvas/' . $pageId . '/publish')) ?>"
      data-section-url="<?= e(base_url('admin/canvas/' . $pageId . '/section')) ?>"
      data-structure-url="<?= e(base_url('admin/canvas/' . $pageId . '/structure')) ?>"
      data-insert-form-url="<?= e(base_url('admin/canvas/' . $pageId . '/insert-form')) ?>"
      data-insert-booking-url="<?= e(base_url('admin/canvas/' . $pageId . '/insert-booking')) ?>"
      data-insert-resources-url="<?= e(base_url('admin/canvas/' . $pageId . '/insert-resources')) ?>"
      data-copy-sources-url="<?= e(base_url('admin/canvas/' . $pageId . '/copy-sources')) ?>"
      data-copy-section-url="<?= e(base_url('admin/canvas/' . $pageId . '/copy-section')) ?>"
      data-media-url="<?= e(base_url('admin/media/library')) ?>"
      data-media-upload-url="<?= e(base_url('admin/media')) ?>"
      data-bank-search-url="<?= e(base_url('admin/media/bank/search')) ?>"
      data-bank-import-url="<?= e(base_url('admin/media/bank/import')) ?>"
      data-bank-available="<?= !empty($bankAvailable) ? '1' : '0' ?>"
      data-undo-url="<?= e(base_url('admin/canvas/' . $pageId . '/undo')) ?>"
      data-redo-url="<?= e(base_url('admin/canvas/' . $pageId . '/redo')) ?>"
      data-settings-url="<?= e(base_url('admin/canvas/' . $pageId . '/settings')) ?>"
      data-ai-url="<?= e(base_url('admin/ai/actions/run')) ?>"
      data-public-base="<?= e(rtrim(base_url(''), '/') . '/') ?>"
      data-page-type="<?= e((string) ($page['page_type'] ?? '')) ?>"
      data-page-title="<?= e((string) $page['title']) ?>"
      data-public-url="<?= e(base_url(ltrim((string) $page['slug'], '/'))) ?>"
      data-clean-preview-url="<?= e(base_url('admin/canvas/' . $pageId . '/preview') . '?clean=1') ?>"
      data-can-undo="<?= !empty($history['can_undo']) ? '1' : '0' ?>"
      data-can-redo="<?= !empty($history['can_redo']) ? '1' : '0' ?>"
      data-published="<?= $isPublished ? '1' : '0' ?>"
      data-has-unpublished="<?= !empty($history['has_unpublished']) ? '1' : '0' ?>">

<header class="cvstudio-top">
  <div class="cvstudio-top__zone cvstudio-top__left">
    <a class="cvstudio-iconbtn" href="<?= e(base_url('admin/pages')) ?>" title="<?= e(__('cv.back_to_pages')) ?>" aria-label="<?= e(__('cv.back_to_pages')) ?>"><?= $icon('back') ?></a>
    <div class="cvstudio-title">
      <strong><?= e($page['title']) ?></strong>
      <span class="cvstudio-titlemeta">
        <span class="cvstudio-status <?= $isPublished ? 'is-live' : '' ?>" id="studio-status"></span>
        <span class="cvstudio-saved" id="studio-saved" hidden><?= e(__('js.saved')) ?></span>
      </span>
    </div>
  </div>

  <div class="cvstudio-top__zone cvstudio-top__center">
    <div class="cvstudio-segment" role="group" aria-label="<?= e(__('chrome.screen_size')) ?>">
      <div class="cvstudio-viewport" role="group" aria-label="<?= e(__('chrome.screen_size')) ?>">
        <button type="button" class="is-active" data-vp="desktop" title="<?= e(__('cv.desktop_view')) ?>" aria-label="<?= e(__('cv.desktop_view')) ?>"><?= $icon('desktop') ?></button>
        <button type="button" data-vp="mobile" title="<?= e(__('cv.mobile_view')) ?>" aria-label="<?= e(__('cv.mobile_view')) ?>"><?= $icon('mobile') ?></button>
      </div>
    </div>
    <span class="cvstudio-divider" aria-hidden="true"></span>
    <div class="cvstudio-undo" role="group" aria-label="<?= e(__('cv.undo_redo')) ?>">
      <button type="button" class="cvstudio-icon-btn" id="studio-undo-btn" title="<?= e(__('cv.undo')) ?>" disabled aria-label="Deshacer"><?= $icon('undo') ?></button>
      <button type="button" class="cvstudio-icon-btn" id="studio-redo-btn" title="<?= e(__('cv.redo')) ?>" disabled aria-label="<?= e(__('cv.redo_short')) ?>"><?= $icon('redo') ?></button>
    </div>
  </div>

  <div class="cvstudio-top__zone cvstudio-top__right">
    <button type="button" class="cvstudio-iconbtn" id="studio-history-btn" title="<?= e(__('cv.history')) ?>" aria-label="Historial de versiones"><?= $icon('history') ?></button>
    <button type="button" class="cvstudio-iconbtn" id="studio-settings-btn" title="<?= e(__('cv.page_settings')) ?>" aria-label="Ajustes de la página"><?= $icon('settings') ?></button>
    <a class="cvstudio-iconbtn" id="studio-view-link"
       href="<?= e($isPublished ? base_url(ltrim((string) $page['slug'], '/')) : base_url('admin/canvas/' . $pageId . '/preview') . '?clean=1') ?>"
       target="_blank" rel="noopener"
       title="<?= e($isPublished ? __('cv.view_on_site') : __('cv.preview_draft')) ?>"
       aria-label="<?= e($isPublished ? __('cv.view_on_site') : __('cv.preview_draft')) ?>"><?= $icon('external') ?></a>
    <span class="cvstudio-divider" aria-hidden="true"></span>
    <!-- Publicar: en borrador, acción primaria llamativa. Publicada: menú discreto "⋯". -->
    <div class="cvstudio-publish" id="studio-publish" data-published="<?= $isPublished ? '1' : '0' ?>">
      <button type="button" class="cvstudio-primary-btn" id="studio-publish-btn"
              title="<?= e(__('cv.publish_title')) ?>"<?= $isPublished ? ' hidden' : '' ?>>
        <?= e(__('pages.publish')) ?>
      </button>
      <div class="cvstudio-menu" id="studio-more"<?= $isPublished ? '' : ' hidden' ?>>
        <button type="button" class="cvstudio-iconbtn" id="studio-more-btn"
                aria-haspopup="true" aria-expanded="false"
                title="<?= e(__('pages.more_actions')) ?>" aria-label="<?= e(__('pages.more_actions')) ?>"><?= $icon('more') ?></button>
        <div class="cvstudio-menu__pop" id="studio-more-menu" hidden role="menu">
          <button type="button" class="cvstudio-menu__item is-danger" id="studio-unpublish-btn" role="menuitem">
            <?= e(__('post_edit.unpublish')) ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="cvstudio-main">
  <div class="cvstudio-stage">
    <div class="cvstudio-frame" id="studio-frame-wrap">
      <iframe id="studio-iframe" src="<?= e(base_url('admin/canvas/' . $pageId . '/preview')) ?>" title="<?= e(__('cv.iframe_title')) ?>"></iframe>
    </div>

    <!-- STUDIO-2 A3 — el chat vive flotando sobre la página, no en la barra:
         así la barra lateral queda entera para la edición manual. -->
    <div class="cvstudio-dock" id="chat-dock">
      <button type="button" class="cvstudio-dock__pill" id="chat-pill" aria-expanded="false" aria-controls="chat-panel">
        <span class="cvstudio-dock__icon" aria-hidden="true">✦</span>
        <span class="cvstudio-dock__label" id="chat-pill-label"><?= e(__('cv.ask_change_js')) ?></span>
        <span class="cvstudio-dock__dot" id="chat-pill-dot" hidden aria-hidden="true"></span>
      </button>

      <section class="cvstudio-dock__panel" id="chat-panel" aria-label="<?= e(__('cv.design_assistant')) ?>">
        <header class="cvstudio-dock__head">
          <strong><?= e(__('cv.tell_me')) ?></strong>
          <button type="button" id="chat-minimize" title="<?= e(__('cv.hide_chat')) ?>" aria-label="<?= e(__('cv.hide_chat')) ?>">▾</button>
        </header>

        <div class="cvstudio-chat__messages" id="chat-messages" aria-live="polite">
          <div class="pp-chat-msg pp-chat-msg--assistant">
            <p><?= e(__('cv.live_page')) ?></p>
            <p class="pp-chat-hint"><?= e(__('cv.intro_hint')) ?></p>
          </div>
        </div>

        <div class="cvstudio-chat__composer">
          <div class="cvstudio-context" id="chat-context" hidden>
            <span><?= e(__('cv.changing')) ?>: <strong id="chat-context-label"></strong></span>
            <button type="button" id="chat-context-clear" title="<?= e(__('cv.clear_selection')) ?>">✕</button>
          </div>
          <form id="chat-form">
            <textarea id="chat-input" rows="2" maxlength="1200"
              placeholder="<?= e(__('cv.chat_placeholder')) ?>"></textarea>
            <p class="cvstudio-insert__hint"><?= e(__('cv.reference_hint')) ?></p>
            <div class="cvstudio-chat__formfoot">
              <button type="submit" id="chat-send" class="cvstudio-primary-btn"><?= e(__('cv.apply_change')) ?></button>
              <button type="button" id="chat-cancel" class="cvstudio-cancel-btn" hidden><?= e(__('cv.stop')) ?></button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>

  <aside class="cvstudio-side">
    <!-- FH7 — panel contextual de edición directa (se muestra al seleccionar) -->
    <div class="cvstudio-panel" id="edit-panel" hidden></div>

    <!-- STUDIO-STRUCTURE S3 — la estructura permanece visible incluso cuando
         el panel contextual está abierto: seleccionar una parte no puede hacer
         desaparecer las acciones para moverla o eliminarla. -->
    <div class="cvstudio-side__structure">
      <h3 class="cvstudio-side__title"><?= e(__('cv.page_parts')) ?></h3>
      <ul class="cvstudio-seclist" id="side-sections">
        <li class="cvstudio-side__hint"><?= e(__('cv.loading')) ?></li>
      </ul>
      <div class="cvstudio-structure-status" id="structure-status" role="status" aria-live="polite" hidden></div>
    </div>

    <!-- STUDIO-2 A1 — la barra nunca está vacía: sin selección explica cómo se
         edita y ofrece las partes de la página para llegar a cada una. -->
    <div class="cvstudio-side__empty" id="side-empty">
      <div class="cvstudio-side__intro">
        <strong><?= e(__('cv.manual_edit')) ?></strong>
        <p><?= __('cv.manual_edit_help.html') ?></p>
      </div>

      <div class="cvstudio-side__block" id="studio-add-block">
        <h3 class="cvstudio-side__title"><?= e(__('cv.add_to_page')) ?></h3>
        <p class="cvstudio-insert-placement" id="studio-insert-placement" hidden></p>
        <!-- STUDIO-STRUCTURE S4 — un único selector conserva el punto elegido y
             reúne contenido básico + bloques funcionales disponibles. -->
        <div class="cvstudio-block-picker" id="studio-block-picker">
          <button type="button" class="cvstudio-primary-btn cvstudio-insert__btn cvstudio-block-picker__trigger"
                  id="studio-block-picker-btn" aria-haspopup="true" aria-expanded="false"
                  aria-controls="studio-block-picker-menu">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <?= e(__('cv.block_picker_button')) ?>
          </button>
          <div class="cvstudio-menu__pop cvstudio-block-picker__menu" id="studio-block-picker-menu" hidden
               role="group" aria-label="<?= e(__('cv.block_picker_button')) ?>">
            <?php /* STUDIO-UX F8 — Lo primero es partir de algo que YA funciona en
                     esta página; las plantillas neutras van al final porque caen
                     desentonando dentro de una página con carácter. */ ?>
            <strong class="cvstudio-block-picker__category"><?= e(__('cv.block_reuse_category')) ?></strong>

            <div class="cvstudio-insert" id="studio-duplicate-part">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" id="studio-duplicate-part-btn"
                      aria-haspopup="true" aria-expanded="false" aria-controls="studio-duplicate-part-menu">
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg></span>
                <span><strong><?= e(__('cv.duplicate_part')) ?></strong><small><?= e(__('cv.duplicate_part_desc')) ?></small></span>
              </button>
              <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-duplicate-part-menu" hidden
                   role="group" aria-label="<?= e(__('cv.duplicate_part')) ?>"></div>
            </div>

            <?php /* STUDIO-UX F6 — copiar una parte de otra página, sin IA. */ ?>
            <div class="cvstudio-insert" id="studio-copy-section">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" id="studio-copy-btn"
                      aria-haspopup="true" aria-expanded="false" aria-controls="studio-copy-menu">
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/></svg></span>
                <span><strong><?= e(__('cv.copy_from_page')) ?></strong><small><?= e(__('cv.copy_from_page_desc')) ?></small></span>
              </button>
              <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-copy-menu" hidden role="group"
                   aria-label="<?= e(__('cv.copy_from_page')) ?>"></div>
            </div>

            <strong class="cvstudio-block-picker__category"><?= e(__('cv.block_content_category')) ?></strong>

            <button type="button" class="cvstudio-block-option" data-section-template="text">
              <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 6h14M5 11h10M5 16h13"/></svg></span>
              <span><strong><?= e(__('cv.block_text')) ?></strong><small><?= e(__('cv.block_text_desc')) ?></small></span>
            </button>
            <button type="button" class="cvstudio-block-option" data-section-template="text_image">
              <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m6 17 4-4 3 3 2-2 3 3"/></svg></span>
              <span><strong><?= e(__('cv.block_text_image')) ?></strong><small><?= e(__('cv.block_text_image_desc')) ?></small></span>
            </button>
            <button type="button" class="cvstudio-block-option" data-section-template="cta">
              <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 7h9M5 12h6M5 17h8M15 12h5M18 9l3 3-3 3"/></svg></span>
              <span><strong><?= e(__('cv.block_cta')) ?></strong><small><?= e(__('cv.block_cta_desc')) ?></small></span>
            </button>

            <strong class="cvstudio-block-picker__category"><?= e(__('cv.block_functional_category')) ?></strong>

            <!-- FORMS-R T3 — elegir uno existente o crearlo desde plantilla. -->
            <div class="cvstudio-insert" id="studio-insert-form">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" id="studio-insert-btn"
                      aria-haspopup="true" aria-expanded="false" aria-controls="studio-insert-menu">
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 4h12v16H6zM9 8h6M9 12h6M9 16h4"/></svg></span>
                <span><strong><?= e(__('js.studio.form')) ?></strong><small><?= e(__('cv.block_form_desc')) ?></small></span>
              </button>
              <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-insert-menu" hidden role="group">
            <strong class="cvstudio-insert__title"><?= e(__('cv.use_existing')) ?></strong>
            <div id="studio-existing-forms">
            <?php if (empty($forms)): ?><p class="cvstudio-insert__empty"><?= e(__('forms.empty_title')) ?></p><?php else: foreach ($forms as $f): ?>
              <button type="button" class="cvstudio-menu__item" data-form-id="<?= (int) $f['id'] ?>">
                <?= e($f['heading']) ?> <span class="cvstudio-insert__meta"><?= e(__('forms.fields_other', ['n' => (int) $f['field_count']])) ?></span>
              </button>
            <?php endforeach; endif; ?>
            </div>
            <strong class="cvstudio-insert__title"><?= e(__('cv.from_template')) ?></strong>
            <?php foreach (($formTemplates ?? []) as $key => $template): ?>
              <button type="button" class="cvstudio-menu__item" data-form-template="<?= e((string) $key) ?>">
                <?= e((string) ($template['label'] ?? $key)) ?>
                <span class="cvstudio-insert__meta"><?= e((string) ($template['description'] ?? '')) ?></span>
              </button>
            <?php endforeach; ?>
            <label class="cvstudio-insert__source">
              <span><?= e(__('cv.source_label')) ?></span>
              <input type="text" id="studio-form-source" maxlength="160" placeholder="<?= e(__('cv.source_placeholder')) ?>">
            </label>
            <p class="cvstudio-insert__hint" id="studio-insert-hint"><?= e(__('cv.insert_hint')) ?></p>
              </div>
            </div>

            <?php /* MODULOS M2 — solo aparece si existe un servicio viable. */ ?>
            <?php if (!empty($bookingServices)): ?>
            <div class="cvstudio-insert" id="studio-insert-booking">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" id="studio-insert-booking-btn"
                      aria-haspopup="true" aria-expanded="false" aria-controls="studio-insert-booking-menu">
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="6" width="16" height="14" rx="2"/><path d="M8 3v6M16 3v6M4 11h16"/></svg></span>
                <span><strong><?= e(__('cv.booking.button')) ?></strong><small><?= e(__('cv.block_booking_desc')) ?></small></span>
              </button>
              <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-insert-booking-menu" hidden role="group">
                <button type="button" class="cvstudio-menu__item" data-booking-service="auto">
                  <?= e(__('cv.booking.auto')) ?>
                  <span class="cvstudio-insert__meta"><?= e($bookingServices[0]['name']) ?></span>
                </button>
                <strong class="cvstudio-insert__title"><?= e(__('cv.booking.pick_service')) ?></strong>
                <?php foreach ($bookingServices as $svc): ?>
                  <button type="button" class="cvstudio-menu__item" data-booking-service="<?= (int) $svc['id'] ?>">
                    <?= e($svc['name']) ?>
                    <span class="cvstudio-insert__meta"><?= (int) $svc['duration_min'] ?> min<?= $svc['price_label'] !== '' ? ' · ' . e($svc['price_label']) : '' ?></span>
                  </button>
                <?php endforeach; ?>
                <p class="cvstudio-insert__hint"><?= e(__('cv.booking.insert_hint')) ?></p>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($publishedResources)): ?>
            <div class="cvstudio-insert" id="studio-insert-resources">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" id="studio-insert-resources-btn"
                      aria-haspopup="true" aria-expanded="false" aria-controls="studio-insert-resources-menu">
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 5h14v14H5zM9 5v14M12 9h4M12 13h4"/></svg></span>
                <span><strong><?= e(__('cv.resources.button')) ?></strong><small><?= e(__('cv.block_resources_desc')) ?></small></span>
              </button>
              <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-insert-resources-menu" hidden role="group">
                <strong class="cvstudio-insert__title"><?= e(__('cv.resources.pick_amount')) ?></strong>
                <?php foreach ($resourceCounts as $count): ?>
                  <button type="button" class="cvstudio-menu__item" data-resources-limit="<?= (int) $count ?>">
                    <?= e(__('cv.resources.option', ['n' => $count])) ?>
                    <span class="cvstudio-insert__meta"><?= e(__($count === 1 ? 'cv.resources.option_meta_one' : 'cv.resources.option_meta', ['n' => $count])) ?></span>
                  </button>
                <?php endforeach; ?>
                <p class="cvstudio-insert__hint"><?= e(__('cv.resources.insert_hint')) ?></p>
              </div>
            </div>
            <?php elseif (!empty($resourcesModuleEnabled) && !empty($hasPublishedResources)): ?>
            <div class="cvstudio-insert cvstudio-insert--unavailable">
              <button type="button" class="cvstudio-block-option cvstudio-insert__btn" disabled>
                <span class="cvstudio-block-option__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 5h14v14H5zM9 5v14M12 9h4M12 13h4"/></svg></span>
                <span><strong><?= e(__('cv.resources.button')) ?></strong><small><?= e(__('cv.resources.language_mismatch', ['idioma' => (string) ($resourcePageLanguage ?? '')])) ?></small></span>
              </button>
              <p class="cvstudio-insert__notice"><a href="<?= e(base_url('admin/resources')) ?>" target="_blank" rel="noopener"><?= e(__('cv.resources.review_languages')) ?></a></p>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </aside>
</div>

<div class="cvstudio-modal" id="history-modal" hidden>
  <div class="cvstudio-modal__panel">
    <div class="cvstudio-modal__head">
      <strong><?= e(__('cv.page_history')) ?></strong>
      <button type="button" id="history-close" title="<?= e(__('common.close')) ?>">✕</button>
    </div>
    <p class="pp-chat-hint"><?= e(__('cv.history_hint')) ?></p>
    <ul class="cvstudio-versions" id="history-list"></ul>
  </div>
</div>

<div class="cvstudio-modal" id="settings-modal" hidden>
  <div class="cvstudio-modal__panel">
    <div class="cvstudio-modal__head">
      <strong><?= e(__('cv.page_settings')) ?></strong>
      <button type="button" id="settings-close" title="<?= e(__('common.close')) ?>">✕</button>
    </div>
    <p class="pp-chat-hint"><?= e(__('cv.settings_hint')) ?></p>
    <?php
      // Botón "sugerir con IA" reutilizable por campo (FH8). Una sola pieza
      // para que título, descripción y URL compartan el mismo affordance.
      $aiChip = static function (string $field, string $aria): string {
          return '<button type="button" class="cvstudio-ai-chip" data-ai-field="' . e($field) . '"'
              . ' title="' . e(__('cv.suggest_ai')) . '" aria-label="' . e($aria) . '">'
              . '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">'
              . '<path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2zm6 11l.9 2.6L21.6 16l-2.6.9L18 19.6l-.9-2.6L14.5 16l2.6-.9L18 13z"/>'
              . '</svg><span>' . e(__('cv.suggest')) . '</span></button>';
      };
    ?>
    <div class="cvstudio-panel__body cvstudio-settings">
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-meta-title"><?= e(__('cv.google_title')) ?></label>
          <?= $aiChip('meta_title', __('cv.suggest_title')) ?>
        </div>
        <input type="text" id="settings-meta-title" maxlength="70"
               value="<?= e((string) ($page['meta_title'] ?? '')) ?>"
               placeholder="<?= e((string) $page['title']) ?>">
        <small class="cvstudio-hint"><span data-count="settings-meta-title">0</span> caracteres · ideal por debajo de 60</small>
      </div>
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-meta-desc"><?= e(__('cv.google_desc')) ?></label>
          <?= $aiChip('meta_description', __('cv.suggest_desc')) ?>
        </div>
        <textarea id="settings-meta-desc" rows="3" maxlength="320"
                  placeholder="<?= e(__('cv.google_desc_placeholder')) ?>"><?= e((string) ($page['meta_description'] ?? '')) ?></textarea>
        <small class="cvstudio-hint"><span data-count="settings-meta-desc">0</span> caracteres · ideal por debajo de 155</small>
      </div>
      <?php if (($page['page_type'] ?? '') !== 'home'): ?>
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-slug"><?= e(__('cv.page_url')) ?></label>
          <?= $aiChip('slug', __('cv.suggest_slug')) ?>
        </div>
        <input type="text" id="settings-slug" value="<?= e(ltrim((string) $page['slug'], '/')) ?>" placeholder="mi-pagina">
        <small class="cvstudio-hint"><?= e(__('cv.url_will_be')) ?>: <span id="settings-url-preview"></span></small>
        <p class="cvstudio-warn" id="settings-slug-warn" hidden><?= e(__('cv.slug_warn')) ?></p>
      </div>
      <?php endif; ?>
      <?php /* STUDIO-UX F7 (mitad superviviente) — El modelo se elige aquí, no
               en la caja donde se escribe el cambio: un usuario no técnico no
               tiene por qué decidir eso delante del composer. */ ?>
      <?php if (!empty($aiModels)): ?>
      <div class="cvstudio-field">
        <label for="settings-ai-model"><?= e(__('cv.settings_model')) ?></label>
        <select id="settings-ai-model">
          <?php foreach ($aiModels as $m): ?>
          <option value="<?= e((string) $m['id']) ?>"<?= !empty($m['default']) ? ' selected' : '' ?>><?= e((string) $m['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="cvstudio-hint"><?= e(__('cv.settings_model_help')) ?></small>
      </div>
      <?php endif; ?>

      <details class="cvstudio-advanced">
        <summary><?= e(__('page_form.advanced_index')) ?></summary>
        <div class="cvstudio-field">
          <label class="cvstudio-check">
            <input type="checkbox" id="settings-seo-noindex" value="1" <?= (int) ($page['seo_noindex'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span><?= e(__('page_form.noindex')) ?></span>
          </label>
          <small class="cvstudio-hint"><?= e(__('page_form.noindex_help')) ?></small>
        </div>
        <div class="cvstudio-field">
          <label class="cvstudio-check">
            <input type="checkbox" id="settings-seo-exclude-sitemap" value="1" <?= (int) ($page['seo_exclude_sitemap'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span><?= e(__('page_form.no_sitemap')) ?></span>
          </label>
          <small class="cvstudio-hint"><?= e(__('page_form.no_sitemap_help')) ?></small>
        </div>
        <div class="cvstudio-field">
          <label for="settings-canonical-url"><?= e(__('page_form.canonical')) ?></label>
          <input type="url" id="settings-canonical-url" maxlength="500"
                 value="<?= e((string) ($page['canonical_url'] ?? '')) ?>"
                 placeholder="https://tudominio.com/pagina-principal">
          <small class="cvstudio-hint"><?= e(__('page_form.canonical_help')) ?></small>
        </div>
      </details>
    </div>
    <div class="cvstudio-settings__foot">
      <span class="cvstudio-settings__status" id="settings-status" hidden></span>
      <button type="button" class="cvstudio-primary-btn" id="settings-save-btn"><?= e(__('settings.save')) ?></button>
    </div>
  </div>
</div>

<div class="cvstudio-modal" id="media-modal" hidden>
  <div class="cvstudio-modal__panel cvstudio-modal__panel--wide">
    <div class="cvstudio-modal__head">
      <strong><?= e(__('cv.pick_image')) ?></strong>
      <button type="button" id="media-close" title="<?= e(__('common.close')) ?>">✕</button>
    </div>
    <div class="cvstudio-media-bar">
      <div class="cvstudio-media-tabs" role="tablist">
        <button type="button" class="is-active" data-media-tab="library" role="tab"><?= e(__('cv.your_library')) ?></button>
        <?php if (!empty($bankAvailable)): ?>
        <button type="button" data-media-tab="unsplash" role="tab"><?= e(__('media.search_unsplash')) ?></button>
        <?php endif; ?>
      </div>
      <label class="cvstudio-media-upload" title="<?= e(__('cv.upload_from_device')) ?>">
        <input type="file" id="media-upload-input" accept="image/*" hidden>
        <span>⬆ <?= e(__('cv.upload_image')) ?></span>
      </label>
    </div>
    <form class="cvstudio-media-search" id="media-search-form" hidden>
      <input type="search" id="media-search-input" autocomplete="off"
             placeholder="<?= e(__('cv.search_photos')) ?>">
      <button type="submit" class="cvstudio-ghost-btn"><?= e(__('bank.search')) ?></button>
    </form>
    <p class="pp-chat-hint" id="media-hint"><?= e(__('cv.media_hint')) ?></p>
    <div class="cvstudio-media-grid" id="media-grid"></div>
  </div>
</div>

<script>
  window.PP_LINK_TARGETS = <?= json_encode(array_map(static fn($p) => [
      'title' => (string) $p['title'],
      'url' => '/' . ltrim((string) $p['slug'], '/'),
  ], $linkTargets ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<?php /* El Studio es standalone (sin `admin/layout`), así que tiene que traerse
         el catálogo del navegador y `pp.t` por su cuenta: sin esto sus ~94
         llamadas a `pp.t()` lanzaban "pp is not defined" y cortaban el script. */ ?>
<script>window.PP_I18N = <?= json_encode(
    \App\Services\AdminI18n::jsCatalog(),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;</script>
<script src="<?= e(base_url('admin/assets/js/pp-i18n.js')) ?>"></script>
<script src="<?= e(base_url('admin/assets/js/canvas-studio.js')) ?>?v=<?= e($jsVer) ?>"></script>
</body>
</html>
