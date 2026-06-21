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
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Studio — <?= e($page['title']) ?></title>
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
      data-versions-url="<?= e(base_url('admin/canvas/' . $pageId . '/versions')) ?>"
      data-restore-url="<?= e(base_url('admin/canvas/' . $pageId . '/restore')) ?>"
      data-publish-url="<?= e(base_url('admin/canvas/' . $pageId . '/publish')) ?>"
      data-section-url="<?= e(base_url('admin/canvas/' . $pageId . '/section')) ?>"
      data-insert-form-url="<?= e(base_url('admin/canvas/' . $pageId . '/insert-form')) ?>"
      data-media-url="<?= e(base_url('admin/media/library')) ?>"
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
      data-published="<?= $isPublished ? '1' : '0' ?>">

<header class="cvstudio-top">
  <div class="cvstudio-top__zone cvstudio-top__left">
    <a class="cvstudio-iconbtn" href="<?= e(base_url('admin/pages')) ?>" title="Volver a páginas" aria-label="Volver a páginas"><?= $icon('back') ?></a>
    <div class="cvstudio-title">
      <strong><?= e($page['title']) ?></strong>
      <span class="cvstudio-titlemeta">
        <span class="cvstudio-status <?= $isPublished ? 'is-live' : '' ?>" id="studio-status">
          <?= $isPublished ? 'Publicada' : 'Borrador' ?>
        </span>
        <span class="cvstudio-saved" id="studio-saved" hidden>Guardado</span>
      </span>
    </div>
  </div>

  <div class="cvstudio-top__zone cvstudio-top__center">
    <div class="cvstudio-segment" role="group" aria-label="Tamaño de pantalla">
      <div class="cvstudio-viewport" role="group" aria-label="Tamaño de pantalla">
        <button type="button" class="is-active" data-vp="desktop" title="Vista ordenador" aria-label="Vista ordenador"><?= $icon('desktop') ?></button>
        <button type="button" data-vp="mobile" title="Vista móvil" aria-label="Vista móvil"><?= $icon('mobile') ?></button>
      </div>
    </div>
    <span class="cvstudio-divider" aria-hidden="true"></span>
    <div class="cvstudio-undo" role="group" aria-label="Deshacer y rehacer">
      <button type="button" class="cvstudio-icon-btn" id="studio-undo-btn" title="Deshacer (Ctrl/Cmd+Z)" disabled aria-label="Deshacer"><?= $icon('undo') ?></button>
      <button type="button" class="cvstudio-icon-btn" id="studio-redo-btn" title="Rehacer (Ctrl/Cmd+Mayús+Z)" disabled aria-label="Rehacer"><?= $icon('redo') ?></button>
    </div>
  </div>

  <div class="cvstudio-top__zone cvstudio-top__right">
    <button type="button" class="cvstudio-iconbtn" id="studio-history-btn" title="Historial de versiones" aria-label="Historial de versiones"><?= $icon('history') ?></button>
    <button type="button" class="cvstudio-iconbtn" id="studio-settings-btn" title="Ajustes de la página" aria-label="Ajustes de la página"><?= $icon('settings') ?></button>
    <a class="cvstudio-iconbtn" id="studio-view-link"
       href="<?= e($isPublished ? base_url(ltrim((string) $page['slug'], '/')) : base_url('admin/canvas/' . $pageId . '/preview') . '?clean=1') ?>"
       target="_blank" rel="noopener"
       title="<?= $isPublished ? 'Ver página en el sitio' : 'Previsualizar borrador' ?>"
       aria-label="<?= $isPublished ? 'Ver página en el sitio' : 'Previsualizar borrador' ?>"><?= $icon('external') ?></a>
    <span class="cvstudio-divider" aria-hidden="true"></span>
    <!-- Publicar: en borrador, acción primaria llamativa. Publicada: menú discreto "⋯". -->
    <div class="cvstudio-publish" id="studio-publish" data-published="<?= $isPublished ? '1' : '0' ?>">
      <button type="button" class="cvstudio-primary-btn" id="studio-publish-btn"
              title="Publicar la página en el sitio"<?= $isPublished ? ' hidden' : '' ?>>
        Publicar
      </button>
      <div class="cvstudio-menu" id="studio-more"<?= $isPublished ? '' : ' hidden' ?>>
        <button type="button" class="cvstudio-iconbtn" id="studio-more-btn"
                aria-haspopup="true" aria-expanded="false"
                title="Más acciones" aria-label="Más acciones"><?= $icon('more') ?></button>
        <div class="cvstudio-menu__pop" id="studio-more-menu" hidden role="menu">
          <button type="button" class="cvstudio-menu__item is-danger" id="studio-unpublish-btn" role="menuitem">
            Despublicar
          </button>
        </div>
      </div>
    </div>
  </div>
</header>

<div class="cvstudio-main">
  <div class="cvstudio-stage">
    <div class="cvstudio-frame" id="studio-frame-wrap">
      <iframe id="studio-iframe" src="<?= e(base_url('admin/canvas/' . $pageId . '/preview')) ?>" title="Vista previa de la página"></iframe>
    </div>
  </div>

  <aside class="cvstudio-chat">
    <!-- FH7 — panel contextual de edición directa (se muestra al seleccionar) -->
    <div class="cvstudio-panel" id="edit-panel" hidden></div>

    <div class="cvstudio-chat__messages" id="chat-messages" aria-live="polite">
      <div class="pp-chat-msg pp-chat-msg--assistant">
        <p>Esta es tu página, en vivo.</p>
        <p class="pp-chat-hint">Haz clic en un texto para corregirlo al momento, o en una foto para cambiarla. Para cambios de diseño, cuéntamelo aquí: si antes haces clic en una parte de la página, el cambio se aplicará solo ahí.</p>
      </div>
    </div>

    <div class="cvstudio-chat__composer">
      <!-- FORMS-R T3 — elegir uno existente o crearlo desde plantilla. -->
      <div class="cvstudio-insert" id="studio-insert-form">
        <button type="button" class="cvstudio-ghost-btn cvstudio-insert__btn" id="studio-insert-btn"
                aria-haspopup="true" aria-expanded="false">+ Insertar formulario</button>
        <div class="cvstudio-menu__pop cvstudio-insert__pop" id="studio-insert-menu" hidden role="menu">
          <strong class="cvstudio-insert__title">Usar uno existente</strong>
          <div id="studio-existing-forms">
          <?php if (empty($forms)): ?><p class="cvstudio-insert__empty">Todavia no tienes formularios.</p><?php else: foreach ($forms as $f): ?>
            <button type="button" class="cvstudio-menu__item" data-form-id="<?= (int) $f['id'] ?>" role="menuitem">
              <?= e($f['heading']) ?> <span class="cvstudio-insert__meta"><?= (int) $f['field_count'] ?> campos</span>
            </button>
          <?php endforeach; endif; ?>
          </div>
          <strong class="cvstudio-insert__title">Crear desde plantilla</strong>
          <?php foreach (($formTemplates ?? []) as $key => $template): ?>
            <button type="button" class="cvstudio-menu__item" data-form-template="<?= e((string) $key) ?>" role="menuitem">
              <?= e((string) ($template['label'] ?? $key)) ?>
              <span class="cvstudio-insert__meta"><?= e((string) ($template['description'] ?? '')) ?></span>
            </button>
          <?php endforeach; ?>
          <label class="cvstudio-insert__source">
            <span>Etiqueta de origen (opcional)</span>
            <input type="text" id="studio-form-source" maxlength="160" placeholder="Ej.: Contacto desde servicios">
          </label>
          <p class="cvstudio-insert__hint" id="studio-insert-hint">Selecciona una parte de la pagina para insertarlo justo despues.</p>
        </div>
      </div>
      <div class="cvstudio-context" id="chat-context" hidden>
        <span>Cambiando: <strong id="chat-context-label"></strong></span>
        <button type="button" id="chat-context-clear" title="Quitar selección — el cambio afectará a toda la página">✕</button>
      </div>
      <form id="chat-form">
        <textarea id="chat-input" rows="2" maxlength="1200"
          placeholder="Ej.: pon el titular más grande y el botón en otro color"></textarea>
        <button type="submit" id="chat-send" class="cvstudio-primary-btn">Aplicar cambio</button>
      </form>
    </div>
  </aside>
</div>

<div class="cvstudio-modal" id="history-modal" hidden>
  <div class="cvstudio-modal__panel">
    <div class="cvstudio-modal__head">
      <strong>Historial de la página</strong>
      <button type="button" id="history-close" title="Cerrar">✕</button>
    </div>
    <p class="pp-chat-hint">Cada cambio guarda una versión. Puedes volver a cualquiera.</p>
    <ul class="cvstudio-versions" id="history-list"></ul>
  </div>
</div>

<div class="cvstudio-modal" id="settings-modal" hidden>
  <div class="cvstudio-modal__panel">
    <div class="cvstudio-modal__head">
      <strong>Ajustes de la página</strong>
      <button type="button" id="settings-close" title="Cerrar">✕</button>
    </div>
    <p class="pp-chat-hint">Cómo aparece tu página en Google y al compartirla. Si no rellenas algo, usamos el título de la página.</p>
    <?php
      // Botón "sugerir con IA" reutilizable por campo (FH8). Una sola pieza
      // para que título, descripción y URL compartan el mismo affordance.
      $aiChip = static function (string $field, string $aria): string {
          return '<button type="button" class="cvstudio-ai-chip" data-ai-field="' . e($field) . '"'
              . ' title="Sugerir con IA" aria-label="' . e($aria) . '">'
              . '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" fill="currentColor">'
              . '<path d="M12 2l1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8L12 2zm6 11l.9 2.6L21.6 16l-2.6.9L18 19.6l-.9-2.6L14.5 16l2.6-.9L18 13z"/>'
              . '</svg><span>Sugerir</span></button>';
      };
    ?>
    <div class="cvstudio-panel__body cvstudio-settings">
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-meta-title">Título para Google</label>
          <?= $aiChip('meta_title', 'Sugerir el título con IA') ?>
        </div>
        <input type="text" id="settings-meta-title" maxlength="70"
               value="<?= e((string) ($page['meta_title'] ?? '')) ?>"
               placeholder="<?= e((string) $page['title']) ?>">
        <small class="cvstudio-hint"><span data-count="settings-meta-title">0</span> caracteres · ideal por debajo de 60</small>
      </div>
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-meta-desc">Descripción para Google</label>
          <?= $aiChip('meta_description', 'Sugerir la descripción con IA') ?>
        </div>
        <textarea id="settings-meta-desc" rows="3" maxlength="320"
                  placeholder="Una o dos frases que resuman la página y enganchen."><?= e((string) ($page['meta_description'] ?? '')) ?></textarea>
        <small class="cvstudio-hint"><span data-count="settings-meta-desc">0</span> caracteres · ideal por debajo de 155</small>
      </div>
      <?php if (($page['page_type'] ?? '') !== 'home'): ?>
      <div class="cvstudio-field">
        <div class="cvstudio-field__label">
          <label for="settings-slug">Dirección de la página (URL)</label>
          <?= $aiChip('slug', 'Sugerir la dirección con IA') ?>
        </div>
        <input type="text" id="settings-slug" value="<?= e(ltrim((string) $page['slug'], '/')) ?>" placeholder="mi-pagina">
        <small class="cvstudio-hint">Tu página estará en: <span id="settings-url-preview"></span></small>
        <p class="cvstudio-warn" id="settings-slug-warn" hidden>Esta página está publicada. Si cambias la dirección, los enlaces antiguos dejarán de funcionar.</p>
      </div>
      <?php endif; ?>
      <details class="cvstudio-advanced">
        <summary>Indexación avanzada</summary>
        <div class="cvstudio-field">
          <label class="cvstudio-check">
            <input type="checkbox" id="settings-seo-noindex" value="1" <?= (int) ($page['seo_noindex'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>No mostrar esta página en buscadores</span>
          </label>
          <small class="cvstudio-hint">Úsalo para páginas privadas, duplicadas o temporales. La página seguirá existiendo si alguien tiene el enlace.</small>
        </div>
        <div class="cvstudio-field">
          <label class="cvstudio-check">
            <input type="checkbox" id="settings-seo-exclude-sitemap" value="1" <?= (int) ($page['seo_exclude_sitemap'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>Excluir del sitemap</span>
          </label>
          <small class="cvstudio-hint">Normalmente conviene dejarlo desactivado.</small>
        </div>
        <div class="cvstudio-field">
          <label for="settings-canonical-url">Canonical personalizada</label>
          <input type="url" id="settings-canonical-url" maxlength="500"
                 value="<?= e((string) ($page['canonical_url'] ?? '')) ?>"
                 placeholder="https://tudominio.com/pagina-principal">
          <small class="cvstudio-hint">Solo si esta página duplica otra URL principal.</small>
        </div>
      </details>
    </div>
    <div class="cvstudio-settings__foot">
      <span class="cvstudio-settings__status" id="settings-status" hidden></span>
      <button type="button" class="cvstudio-primary-btn" id="settings-save-btn">Guardar ajustes</button>
    </div>
  </div>
</div>

<div class="cvstudio-modal" id="media-modal" hidden>
  <div class="cvstudio-modal__panel cvstudio-modal__panel--wide">
    <div class="cvstudio-modal__head">
      <strong>Elige una imagen</strong>
      <button type="button" id="media-close" title="Cerrar">✕</button>
    </div>
    <p class="pp-chat-hint">Imágenes de tu biblioteca. La nueva imagen sustituirá a la que has tocado.</p>
    <div class="cvstudio-media-grid" id="media-grid"></div>
  </div>
</div>

<script>
  window.PP_LINK_TARGETS = <?= json_encode(array_map(static fn($p) => [
      'title' => (string) $p['title'],
      'url' => '/' . ltrim((string) $p['slug'], '/'),
  ], $linkTargets ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(base_url('admin/assets/js/canvas-studio.js')) ?>?v=<?= e($jsVer) ?>"></script>
</body>
</html>
