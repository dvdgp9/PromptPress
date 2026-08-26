<?php
/**
 * @var array $schema       DesignSystem::schema()
 * @var array $tokens       categoría => [key => value]
 * @var array $errors       "categoría.key" => mensaje
 * @var array $fontOptions  valor => label
 * @var array $cssVars      [--var => value] para el preview inicial
 * @var array $googleFonts  nombres de Google Fonts a precargar
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

// Pre-compute inline style para el preview inicial
$previewInline = '';
foreach ($cssVars as $var => $val) {
    $previewInline .= $var . ': ' . $val . '; ';
}
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.design')) ?><?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<?php if (!empty($googleFonts)):
    $families = implode('&family=', array_map(fn($f) => str_replace(' ', '+', $f) . ':wght@300;400;500;600;700;800;900', $googleFonts));
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=<?= $families ?>&display=swap" rel="stylesheet">
<?php endif; ?>
<?php if (!empty($customFontCss)): ?>
<?php /* FONTS — Los @font-face del sitio, para que el preview y las muestras
         del panel se vean con la tipografía real y no con un sustituto. */ ?>
<style id="pp-admin-custom-fonts"><?= $customFontCss ?></style>
<?php endif; ?>
<script>
window.PP_DESIGN_SCHEMA = <?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PP_DESIGN_FONTS = <?= json_encode($fontOptions, JSON_UNESCAPED_UNICODE) ?>;
window.PP_CUSTOM_FONTS = <?= json_encode(array_column($customFonts ?? [], 'name', 'token'), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(base_url('admin/assets/js/design-system.js')) ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('design.title')) ?></h2>
    <div class="pp-page-header__actions" style="display:inline-flex; gap:8px;">
        <form method="POST" action="<?= e(base_url('admin/design/regenerate')) ?>"
              onsubmit="return confirm(<?= e(json_encode(__('design.confirm_regenerate'), JSON_UNESCAPED_UNICODE)) ?>);"
              style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm" title="<?= e(__('design.regenerate_title')) ?>">
                ✨ <?= e(__('design.regenerate')) ?>
            </button>
        </form>
        <form method="POST" action="<?= e(base_url('admin/design/reset')) ?>"
              onsubmit="return confirm(<?= e(json_encode(__('design.confirm_reset'), JSON_UNESCAPED_UNICODE)) ?>);"
              style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">
                <span class="pp-icon pp-icon--reset"></span>
                Restablecer
            </button>
        </form>
    </div>
</div>

<p class="pp-page-intro">
    <?= e(__('design.intro')) ?>
</p>

<section class="pp-logo-card" aria-labelledby="pp-design-logo-title">
    <header class="pp-logo-card__head">
        <div>
            <h3 id="pp-design-logo-title"><?= e(__('design.logo_title')) ?></h3>
            <p>
                <?= __('design.logo_help.html') ?>
            </p>
        </div>
    </header>

    <div class="pp-logo-grid">
        <?php foreach ($logoSlots as $variant => $slot): ?>
        <div class="pp-logo-slot pp-logo-slot--<?= e($variant) ?><?= $slot['primary'] ? ' is-primary' : '' ?>">
            <div class="pp-logo-slot__head">
                <strong><?= e($slot['label']) ?></strong>
                <?php if ($slot['primary']): ?><span class="pp-logo-slot__badge">Principal</span><?php endif; ?>
            </div>

            <div class="pp-logo-slot__preview">
                <?php if ($slot['url'] !== ''): ?>
                    <img src="<?= e($slot['url']) ?>" alt="<?= e($slot['label']) ?>">
                <?php else: ?>
                    <span><?= e(__('design.no_logo')) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($slot['missing']): ?>
            <p class="pp-logo-slot__warning"><?= e(__('design.logo_missing')) ?></p>
            <?php endif; ?>

            <form method="POST" action="<?= e(base_url('admin/design/logo')) ?>" enctype="multipart/form-data" class="pp-logo-slot__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="variant" value="<?= e($variant) ?>">
                <label class="pp-logo-picker">
                    <span><?= e(__('design.choose_file')) ?></span>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required data-logo-file>
                </label>
                <span class="pp-logo-filename" data-logo-filename><?= e(__('onboarding.type.no_files')) ?></span>
                <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm" data-logo-submit disabled>
                    <?= e($slot['url'] !== '' ? __('design.replace') : __('media.upload')) ?>
                </button>
            </form>

            <div class="pp-logo-slot__actions">
                <?php if (!$slot['primary'] && $slot['url'] !== ''): ?>
                <form method="POST" action="<?= e(base_url('admin/design/logo/primary')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="variant" value="<?= e($variant) ?>">
                    <button type="submit" class="pp-logo-link"><?= e(__('design.set_primary')) ?></button>
                </form>
                <?php endif; ?>
                <?php if ($slot['url'] !== '' || $slot['missing']): ?>
                <form method="POST" action="<?= e(base_url('admin/design/logo/delete')) ?>"
                      onsubmit="return confirm(<?= e(json_encode(__('design.confirm_delete_logo'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="variant" value="<?= e($variant) ?>">
                    <button type="submit" class="pp-logo-link pp-logo-link--danger"><?= e(__('common.delete')) ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="pp-logo-card__hint">
        <?= __('design.primary_hint.html') ?>
    </p>
</section>

<?php $flashSuccess = \Core\Session::flash('success'); $flashError = \Core\Session::flash('error'); ?>
<?php /* DESIGN-MANDA T5 — El aviso de contraste no es un error: el color se ha
       guardado. Va aparte para que no se confunda con un fallo de validación. */ ?>
<?php $flashWarning = \Core\Session::flash('warning'); ?>
<?php if ($flashSuccess): ?><div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashWarning): ?><div class="pp-alert pp-alert--warning"><?= e($flashWarning) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <strong><?= e(__('memory.check_errors')) ?></strong>
    <ul style="margin:8px 0 0 20px;">
        <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/design')) ?>" class="pp-form pp-design-form" id="pp-design-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <?php /* "Estilo del sitio" (dirección visual) se renderiza plegado al final del formulario. */ ?>

    <div class="pp-design-layout">
        <!-- Columna izquierda: tabs + fields -->
        <section class="pp-design-editor">
            <!-- Tabs -->
            <nav class="pp-tabs" role="tablist">
                <?php $first = true; foreach ($schema as $cat => $def): ?>
                <button type="button" class="pp-tab<?= $first ? ' is-active' : '' ?>"
                        role="tab"
                        data-tab="<?= e($cat) ?>"
                        id="pp-tab-<?= e($cat) ?>"
                        aria-controls="pp-panel-<?= e($cat) ?>"
                        aria-selected="<?= $first ? 'true' : 'false' ?>">
                    <span class="pp-icon pp-icon--<?= e($def['icon']) ?>"></span>
                    <?= e($def['label']) ?>
                </button>
                <?php $first = false; endforeach; ?>
            </nav>

            <?php
            // Campos que se muestran dentro del panel de Colores aunque pertenezcan
            // a otra categoría. Mantienen su `name` real (cat[key]) para no romper
            // el guardado: solo cambia DÓNDE se renderizan, no cómo se persisten.
            $relocateToColors = ['buttons' => ['radius'], 'spacing' => ['radius_card']];

            /** Render de un único campo del design system. */
            // FONTS — token `custom:{slug}` => nombre real, para poder pintar cada
            // opción del desplegable con su propia tipografía.
            $customFontNames = array_column($customFonts ?? [], 'name', 'token');

            // DESIGN-MANDA T5 — `$fontRoleOwner` (del controlador): qué campo de
            // tipografía está mandado por una familia de marca.
            $fontRoleOwner = $fontRoleOwner ?? [];

            $renderField = function (string $cat, array $f) use ($tokens, $errors, $fontOptions, $customFontNames, $fontRoleOwner) {
                $value = $tokens[$cat][$f['key']] ?? $f['default'];
                $errKey = $cat . '.' . $f['key'];
                $hasErr = isset($errors[$errKey]);
                $fieldName = $cat . '[' . $f['key'] . ']';
                $fieldId = 'pp-' . $cat . '-' . $f['key'];
                $cssVar = $f['css_var'] ?? '';
                ?>
                <div class="pp-design-field <?= $hasErr ? 'has-error' : '' ?>"
                     data-css-var="<?= e($cssVar) ?>"
                     data-type="<?= e($f['type']) ?>"
                     <?= !empty($f['unit']) ? 'data-unit="' . e($f['unit']) . '"' : '' ?>>
                    <label for="<?= e($fieldId) ?>"><?= e($f['label']) ?></label>

                    <?php if ($f['type'] === 'color'): ?>
                        <div class="pp-color-input">
                            <input type="color" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                                   value="<?= e($value) ?>"
                                   data-pp-design-input="color">
                            <input type="text" class="pp-color-hex"
                                   value="<?= e($value) ?>"
                                   maxlength="7"
                                   data-pp-design-sync="color"
                                   aria-label="<?= e(__('design.hex')) ?>">
                        </div>

                    <?php elseif ($f['type'] === 'font'): ?>
                        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                                data-pp-design-input="font">
                            <?php foreach ($fontOptions as $val => $label):
                                $familyName = $customFontNames[$val] ?? ($val === 'system' ? '' : $val);
                                $optionFont = $familyName === '' ? 'system-ui, sans-serif' : "'" . e($familyName) . "', sans-serif";
                            ?>
                            <option value="<?= e($val) ?>"
                                    <?= (string) $value === (string) $val ? 'selected' : '' ?>
                                    style="font-family: <?= $optionFont ?>">
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($f['type'] === 'range'): ?>
                        <div class="pp-range-input">
                            <input type="range" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                                   value="<?= e($value) ?>"
                                   min="<?= e($f['min'] ?? 0) ?>"
                                   max="<?= e($f['max'] ?? 100) ?>"
                                   step="<?= e($f['step'] ?? 1) ?>"
                                   data-pp-design-input="range">
                            <span class="pp-range-value">
                                <span class="pp-range-value__num"><?= e($value) ?></span><?php if (!empty($f['unit'])): ?><span class="pp-range-value__unit"><?= e($f['unit']) ?></span><?php endif; ?>
                            </span>
                        </div>

                    <?php elseif ($f['type'] === 'select'): ?>
                        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                                data-pp-design-input="select">
                            <?php foreach ($f['options'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= (string) $value === (string) $val ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <?php if (!empty($f['hint'])): ?>
                    <small class="pp-design-hint"><?= e($f['hint']) ?></small>
                    <?php endif; ?>
                    <?php /* DESIGN-MANDA T5 — Si una tipografía de marca tiene
                            asignado este rol, manda ella sobre el desplegable.
                            Callarlo reproduce el mismo problema de confianza que
                            teníamos con los colores: el campo dice una cosa y la
                            web pinta otra. */ ?>
                    <?php if (!empty($fontRoleOwner[$f['key']])): ?>
                    <small class="pp-design-hint pp-design-hint--locked">
                        <?= e(__('design.font_role_wins', ['familia' => $fontRoleOwner[$f['key']]])) ?>
                    </small>
                    <?php endif; ?>
                    <?php if ($hasErr): ?>
                    <small class="pp-err"><?= e($errors[$errKey]) ?></small>
                    <?php endif; ?>
                </div>
                <?php
            };
            ?>

            <!-- Panels -->
            <?php $first = true; foreach ($schema as $cat => $def): ?>
            <div class="pp-tab-panel<?= $first ? ' is-active' : '' ?>"
                 role="tabpanel"
                 id="pp-panel-<?= e($cat) ?>"
                 aria-labelledby="pp-tab-<?= e($cat) ?>"
                 data-panel="<?= e($cat) ?>">

                <?php if (!empty($def['hint'])): ?>
                <p class="pp-design-panel-hint"><?= e($def['hint']) ?></p>
                <?php endif; ?>

                <?php if ($cat === 'colors'): ?>
                <?php /* DESIGN-MANDA T10 — Los colores de marca y la generación
                        de paleta vivían solo en el paso 2 del onboarding, un
                        flujo de un solo uso. Aquí son AYUDANTES: rellenan los
                        campos de abajo, no guardan por su cuenta, para que el
                        usuario vea y pueda retocar lo que va a guardar. */ ?>
                <div class="pp-brandcolors" data-brand-colors
                     data-max="<?= (int) \App\Services\BrandPaletteService::BRAND_COLORS_MAX ?>">
                    <div class="pp-brandcolors__head">
                        <strong><?= e(__('design.brand_colors')) ?></strong>
                        <small><?= e(__('design.brand_colors_hint')) ?></small>
                    </div>

                    <div class="pp-brandcolors__list" data-brand-colors-list>
                        <?php foreach ($brandColors as $hex): ?>
                        <span class="pp-brandcolors__item">
                            <input type="color" value="<?= e($hex) ?>" data-brand-color
                                   aria-label="<?= e(__('onboarding.color.swatch_aria')) ?>">
                            <button type="button" data-brand-color-remove
                                    aria-label="<?= e(__('onboarding.color.remove_aria')) ?>">×</button>
                        </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="pp-brandcolors__actions">
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-color-add>
                            + <?= e(__('onboarding.color.add')) ?>
                        </button>
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-color-extract>
                            <?= e(__('onboarding.color.extract')) ?>
                        </button>
                        <button type="button" class="pp-btn pp-btn--primary pp-btn--sm" data-palette-generate>
                            ✨ <?= e(__('design.palette_generate')) ?>
                        </button>
                        <small data-brand-colors-status aria-live="polite"></small>
                    </div>

                    <div class="pp-brandcolors__proposals" data-palette-proposals hidden></div>
                </div>
                <?php endif; ?>

                <div class="<?= $cat === 'colors' ? 'pp-color-grid' : 'pp-design-fields' ?>">
                    <?php foreach ($def['fields'] as $f): ?>
                        <?php if (isset($relocateToColors[$cat]) && in_array($f['key'], $relocateToColors[$cat], true)) continue; ?>
                        <?php $renderField($cat, $f); ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($cat === 'typography'): ?>
                <p class="pp-design-fonts-pointer">
                    <?= e(__('design.own_fonts_q')) ?>
                    <a href="#fonts"><?= e(__('design.upload_here')) ?></a> <?= e(__('design.will_appear')) ?>
                </p>
                <?php endif; ?>

                <?php if ($cat === 'colors'): ?>
                <div class="pp-design-shape">
                    <h4 class="pp-design-subhead"><?= e(__('design.corners')) ?></h4>
                    <div class="pp-design-fields">
                        <?php foreach ($relocateToColors as $srcCat => $keys): ?>
                            <?php foreach ($keys as $k): ?>
                                <?php foreach ($schema[$srcCat]['fields'] as $sf): ?>
                                    <?php if ($sf['key'] === $k) $renderField($srcCat, $sf); ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php $first = false; endforeach; ?>

            <div class="pp-form-actions pp-design-actions">
                <span><?= e(__('design.tokens_help')) ?></span>
                <button type="submit" class="pp-btn pp-btn--primary">
                    <span class="pp-icon pp-icon--check"></span>
                    <?= e(__('design.save')) ?>
                </button>
            </div>
        </section>

        <!-- Columna derecha: preview sticky -->
        <aside class="pp-design-preview-wrap">
            <div class="pp-design-preview-head">
                <h4><?= e(__('design.preview')) ?></h4>
                <div class="pp-viewport-toggle" role="group" aria-label="<?= e(__('design.viewport_aria')) ?>">
                    <button type="button" class="pp-vp-btn is-active" data-viewport="desktop" title="<?= e(__('chrome.desktop')) ?>">
                        <span class="pp-icon pp-icon--desktop"></span>
                    </button>
                    <button type="button" class="pp-vp-btn" data-viewport="tablet" title="Tablet">
                        <span class="pp-icon pp-icon--tablet"></span>
                    </button>
                    <button type="button" class="pp-vp-btn" data-viewport="mobile" title="<?= e(__('chrome.mobile')) ?>">
                        <span class="pp-icon pp-icon--mobile"></span>
                    </button>
                </div>
            </div>

            <div class="pp-design-preview-frame" id="pp-design-preview-frame" data-viewport="desktop">
<?php /* e() obligatorio: los valores de fuente llevan comillas dobles ("Inter", …)
         y sin escapar cierran el atributo style, dejando el preview sin tipografía. */ ?>
                <div class="pp-design-preview" id="pp-design-preview" style="<?= e($previewInline) ?>">
                    <!-- Navbar -->
                    <header class="pp-dp-nav">
                        <div class="pp-dp-nav__brand"><?= e(__('design.demo.brand')) ?></div>
                        <nav class="pp-dp-nav__menu">
                            <a href="#" class="pp-dp-nav__link"><?= e(__('page_type.home')) ?></a>
                            <a href="#" class="pp-dp-nav__link"><?= e(__('design.demo.services')) ?></a>
                            <a href="#" class="pp-dp-nav__link"><?= e(__('design.demo.pricing')) ?></a>
                            <a href="#" class="pp-dp-nav__link"><?= e(__('page_type.contact')) ?></a>
                        </nav>
                        <button type="button" class="pp-dp-btn pp-dp-btn--primary pp-dp-btn--sm"><?= e(__('design.demo.start')) ?></button>
                    </header>

                    <!-- Hero -->
                    <section class="pp-dp-hero">
                        <span class="pp-dp-badge"><?= e(__('design.demo.new')) ?></span>
                        <h1 class="pp-dp-h1"><?= e(__('design.demo.h1')) ?></h1>
                        <p class="pp-dp-lead">
                            <?= e(__('design.preview_note')) ?>
                            Ajusta los valores y observa los cambios al instante.
                        </p>
                        <div class="pp-dp-cta-group">
                            <button type="button" class="pp-dp-btn pp-dp-btn--primary"><?= e(__('design.demo.try')) ?></button>
                            <button type="button" class="pp-dp-btn pp-dp-btn--secondary"><?= e(__('design.demo.see_demo')) ?></button>
                        </div>
                    </section>

                    <!-- Features / benefits -->
                    <section class="pp-dp-section">
                        <div class="pp-dp-section-head">
                            <h2 class="pp-dp-h2"><?= e(__('design.demo.all_you_need')) ?></h2>
                            <p class="pp-dp-lead pp-dp-lead--center"><?= e(__('design.demo.three_reasons')) ?></p>
                        </div>
                        <div class="pp-dp-cards">
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon">
                                    <span class="pp-icon pp-icon--check"></span>
                                </div>
                                <h3 class="pp-dp-h3"><?= e(__('design.demo.fast')) ?></h3>
                                <p class="pp-dp-body"><?= e(__('design.demo.fast_text')) ?></p>
                            </div>
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon" style="background: var(--pp-accent);">
                                    <span class="pp-icon pp-icon--palette"></span>
                                </div>
                                <h3 class="pp-dp-h3"><?= e(__('design.demo.customizable')) ?></h3>
                                <p class="pp-dp-body"><?= e(__('design.demo.custom_text')) ?></p>
                            </div>
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon" style="background: var(--pp-success);">
                                    <span class="pp-icon pp-icon--ai"></span>
                                </div>
                                <h3 class="pp-dp-h3"><?= e(__('design.demo.with_ai')) ?></h3>
                                <p class="pp-dp-body"><?= e(__('design.demo.ai_text')) ?></p>
                            </div>
                        </div>
                    </section>

                    <!-- FAQ -->
                    <section class="pp-dp-section pp-dp-section--alt">
                        <div class="pp-dp-section-head">
                            <h2 class="pp-dp-h2"><?= e(__('design.demo.faq')) ?></h2>
                        </div>
                        <div class="pp-dp-faq">
                            <details>
                                <summary><?= e(__('design.demo.q1')) ?></summary>
                                <p class="pp-dp-body"><?= e(__('design.demo.a1')) ?></p>
                            </details>
                            <details>
                                <summary><?= e(__('design.demo.q2')) ?></summary>
                                <p class="pp-dp-body"><?= e(__('design.demo.a2')) ?></p>
                            </details>
                            <details>
                                <summary><?= e(__('design.demo.q3')) ?></summary>
                                <p class="pp-dp-body"><?= e(__('design.demo.a3')) ?></p>
                            </details>
                        </div>
                    </section>

                    <!-- CTA band -->
                    <section class="pp-dp-cta-band">
                        <h2 class="pp-dp-h2 pp-dp-h2--light"><?= e(__('design.demo.ready')) ?></h2>
                        <p class="pp-dp-lead pp-dp-lead--light"><?= e(__('design.demo.ready_help')) ?></p>
                        <button type="button" class="pp-dp-btn pp-dp-btn--on-dark"><?= e(__('design.demo.create_site')) ?></button>
                    </section>

                    <!-- Mini footer -->
                    <footer class="pp-dp-footer">
                        <div>© 2026 <?= e(__('design.demo.brand')) ?></div>
                        <div class="pp-dp-footer__links">
                            <a href="#" class="pp-dp-link"><?= e(__('design.demo.legal')) ?></a>
                            <a href="#" class="pp-dp-link"><?= e(__('nav.privacy')) ?></a>
                        </div>
                    </footer>
                </div>
            </div>
        </aside>
    </div>

    <?php /* DESIGN-MANDA T7 — "Estilo del sitio" (dirección visual) retirado del
           panel: no emitía nada en ningún sitio con skin, su CSS ataca clases del
           sistema de bloques que las páginas Canvas no usan, y nunca llegaba al
           prompt de Canvas. Los colores se editan en Colores y las tipografías y
           radios en sus pestañas. `VisualStyleService` sigue existiendo para el
           body class de lo ya publicado. */ ?>
</form>

<?php /* FONTS — Tipografías propias del cliente. Va fuera del <form> de diseño
         a propósito: cada acción (subir, asignar, borrar) se aplica al momento,
         sin depender de "Guardar diseño". */ ?>
<section class="pp-fonts-card" id="fonts" aria-labelledby="pp-fonts-title">
    <header class="pp-fonts-card__head">
        <div>
            <h3 id="pp-fonts-title"><?= e(__('design.fonts_title')) ?></h3>
            <p>
                <?= e(__('design.fonts_help')) ?>
                en lugar de las de la lista. Formatos: <strong>WOFF2, WOFF, TTF u OTF</strong>, hasta 3 MB por archivo.
            </p>
        </div>
        <?php if (!empty($customFonts)): ?>
        <span class="pp-fonts-card__count"><?= e(__(count($customFonts) === 1 ? 'design.fonts_count_one' : 'design.fonts_count_other', ['n' => count($customFonts)])) ?></span>
        <?php endif; ?>
    </header>

    <?php foreach (($fontWeightGaps ?? []) as $gap): ?>
    <p class="pp-fonts-warn">
        <?= __('design.font_gap.html', [
            'familia' => '<strong>' . e($gap['family']) . '</strong>',
            'rol'     => e($gap['role']),
            'pesos'   => '<strong>' . e(implode(', ', $gap['missing'])) . '</strong>',
        ]) ?>
    </p>
    <?php endforeach; ?>

    <?php if (!empty($fontHeavyFiles['files'])): ?>
    <p class="pp-fonts-warn pp-fonts-warn--info">
        <?= __('design.heavy_fonts.html', ['total' => '<strong>' . e((string) $fontHeavyFiles['total']) . '</strong>']) ?>
        <?php $n = count($fontHeavyFiles['files']); ?>
        <?= e(__($n === 1 ? 'design.heavy_one' : 'design.heavy_other', ['n' => $n])) ?>:
        <?php foreach ($fontHeavyFiles['files'] as $i => $f): ?>
            <?= $i > 0 ? ' · ' : '' ?><strong><?= e($f['name']) ?> <?= e($f['label']) ?></strong> — <?= e($f['format']) ?>, <?= e($f['size']) ?><?php endforeach; ?>.
        <?= __('design.woff2_hint.html') ?>
    </p>
    <?php endif; ?>

    <?php if (empty($customFonts)): ?>
    <p class="pp-fonts-empty"><?= e(__('design.fonts_empty')) ?></p>
    <?php else: ?>
    <ul class="pp-fonts-list">
        <?php foreach ($customFonts as $fam): ?>
        <li class="pp-fonts-family<?= $fam['role'] === 'none' ? ' is-unused' : '' ?>">
            <div class="pp-fonts-family__head">
                <div class="pp-fonts-family__id">
                    <strong style="font-family: <?= e($fam['files'] === [] ? 'inherit' : '"' . $fam['name'] . '", sans-serif') ?>"><?= e($fam['name']) ?></strong>
                    <small><?= e(__(count($fam['files']) === 1 ? 'design.weights_one' : 'design.weights_other', ['n' => count($fam['files'])])) ?><?= $fam['role'] === 'none' ? ' · ' . e(__('design.unused_on_site')) : '' ?></small>
                </div>
                <form method="POST" action="<?= e(base_url('admin/design/fonts/role')) ?>" class="pp-fonts-role">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="family_id" value="<?= (int) $fam['id'] ?>">
                    <label for="pp-font-role-<?= (int) $fam['id'] ?>"><?= e(__('design.use_in')) ?></label>
                    <select name="role" id="pp-font-role-<?= (int) $fam['id'] ?>" onchange="this.form.submit()">
                        <?php foreach ($customFontRoles as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $fam['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('design.apply')) ?></button></noscript>
                </form>
            </div>

            <?php if ($fam['files'] === []): ?>
            <p class="pp-fonts-family__empty"><?= e(__('design.font_no_files')) ?></p>
            <?php else: ?>
            <ul class="pp-fonts-cuts">
                <?php foreach ($fam['files'] as $file): ?>
                <li class="pp-fonts-cut">
                    <span class="pp-fonts-cut__sample"
                          style="font-family:'<?= e($fam['name']) ?>', sans-serif; font-weight:<?= (int) $file['weight'] ?>; font-style:<?= e($file['style']) ?>;">Aa</span>
                    <form method="POST" action="<?= e(base_url('admin/design/fonts/file/cut')) ?>" class="pp-fonts-cut__form">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                        <select name="weight" onchange="this.form.submit()" aria-label="<?= e(__('design.weight_of', ['archivo' => $file['original_name']])) ?>">
                            <?php foreach ($customFontWeights as $w => $label): ?>
                            <option value="<?= (int) $w ?>" <?= (int) $file['weight'] === (int) $w ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="style" onchange="this.form.submit()" aria-label="<?= e(__('design.style_of', ['archivo' => $file['original_name']])) ?>">
                            <option value="normal" <?= $file['style'] === 'normal' ? 'selected' : '' ?>><?= e(__('design.normal')) ?></option>
                            <option value="italic" <?= $file['style'] === 'italic' ? 'selected' : '' ?>><?= e(__('design.italic')) ?></option>
                        </select>
                        <noscript><button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('design.apply')) ?></button></noscript>
                    </form>
                    <span class="pp-fonts-cut__file" title="<?= e($file['original_name']) ?>"><?= e($file['original_name']) ?></span>
                    <form method="POST" action="<?= e(base_url('admin/design/fonts/file/delete')) ?>"
                          onsubmit="return confirm(<?= e(json_encode(__('design.confirm_delete_weight'), JSON_UNESCAPED_UNICODE)) ?>);" class="pp-fonts-cut__del">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                        <button type="submit" class="pp-fonts-x" aria-label="<?= e(__('design.delete_file', ['archivo' => $file['label']])) ?>">×</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <footer class="pp-fonts-family__foot">
                <form method="POST" action="<?= e(base_url('admin/design/fonts')) ?>" enctype="multipart/form-data" class="pp-fonts-add">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="family_id" value="<?= (int) $fam['id'] ?>">
                    <label class="pp-fonts-picker">
                        <span>+ <?= e(__('design.add_weight')) ?></span>
                        <input type="file" name="font_files[]" accept=".woff2,.woff,.ttf,.otf" multiple data-fonts-file>
                    </label>
                    <span class="pp-fonts-filename" data-fonts-filename></span>
                    <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm" data-fonts-submit disabled><?= e(__('media.upload')) ?></button>
                </form>
                <form method="POST" action="<?= e(base_url('admin/design/fonts/delete')) ?>"
                      onsubmit="return confirm(<?= e(json_encode(__('design.confirm_delete_family', ['nombre' => $fam['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="family_id" value="<?= (int) $fam['id'] ?>">
                    <button type="submit" class="pp-fonts-remove"><?= e(__('design.delete_font')) ?></button>
                </form>
            </footer>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="POST" action="<?= e(base_url('admin/design/fonts')) ?>" enctype="multipart/form-data" class="pp-fonts-new">
        <h4><?= e(__('design.upload_new_font')) ?></h4>
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-fonts-new__grid">
            <label class="pp-fonts-field">
                <span><?= e(__('onboarding.type.font_name')) ?></span>
                <input type="text" name="family_name" maxlength="120" placeholder="<?= e(__('onboarding.type.heading_placeholder')) ?>" required>
                <small><?= e(__('design.font_name_help')) ?></small>
            </label>
            <label class="pp-fonts-field">
                <span><?= e(__('design.where_used')) ?></span>
                <select name="role">
                    <option value="both"><?= e(__('js.onb.for_both')) ?></option>
                    <option value="heading"><?= e(__('design.only_headings')) ?></option>
                    <option value="body"><?= e(__('design.only_body')) ?></option>
                    <option value="none"><?= e(__('design.nowhere_yet')) ?></option>
                </select>
                <small><?= e(__('design.can_change')) ?></small>
            </label>
            <label class="pp-fonts-field pp-fonts-field--file">
                <span><?= e(__('design.files')) ?></span>
                <label class="pp-fonts-picker">
                    <span><?= e(__('design.choose_files')) ?></span>
                    <input type="file" name="font_files[]" accept=".woff2,.woff,.ttf,.otf" multiple required data-fonts-file>
                </label>
                <span class="pp-fonts-filename" data-fonts-filename><?= e(__('onboarding.type.no_files')) ?></span>
                <small><?= e(__('design.multi_select_hint')) ?></small>
            </label>
        </div>
        <div class="pp-fonts-new__actions">
            <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm" data-fonts-submit disabled><?= e(__('design.upload_font')) ?></button>
            <small class="pp-fonts-legal"><?= e(__('design.font_license')) ?></small>
        </div>
    </form>
</section>

<script>
// FONTS — Feedback del selector de archivos: sin esto el usuario no sabe si
// ha elegido algo, y el botón "Subir" deshabilitado no explica por qué.
document.querySelectorAll('[data-fonts-file]').forEach(function (input) {
    input.addEventListener('change', function () {
        var wrap = input.closest('form');
        if (!wrap) return;
        var label = wrap.querySelector('[data-fonts-filename]');
        var submit = wrap.querySelector('[data-fonts-submit]');
        var n = input.files ? input.files.length : 0;
        if (label) {
            label.textContent = n === 0 ? pp.t('js.onb.no_file')
                : (n === 1 ? input.files[0].name : n + ' archivos seleccionados');
        }
        if (submit) submit.disabled = n === 0;
    });
});
</script>


<?php /* DESIGN-MANDA T10 — Editor de paleta: colores de marca, extracción del
        logo y propuestas de IA. Todo RELLENA los campos del formulario; nada
        guarda por su cuenta. El guardado sigue siendo el botón de siempre. */ ?>
<script>
(function () {
    const root = document.querySelector('[data-brand-colors]');
    const form = document.getElementById('pp-design-form');
    if (!root || !form) return;

    const csrf = <?= json_encode($csrf) ?>;
    const urls = {
        extract:  <?= json_encode(base_url('admin/design/extract-logo-colors')) ?>,
        generate: <?= json_encode(base_url('admin/design/generate-palette')) ?>,
        save:     <?= json_encode(base_url('admin/design/brand-colors')) ?>
    };
    const MAX = parseInt(root.dataset.max || '5', 10);
    const list = root.querySelector('[data-brand-colors-list]');
    const status = root.querySelector('[data-brand-colors-status]');
    const box = root.querySelector('[data-palette-proposals]');

    // Paleta (8 claves) => campos del formulario. Mismo mapeo que el servidor
    // usa al guardar, en el sentido contrario.
    const TOKEN_TO_FIELD = {
        accent: 'colors[primary]', accent_dark: 'colors[primary_dark]',
        accent_2: 'colors[accent]', bg: 'colors[bg]', surface: 'colors[surface]',
        text: 'colors[text]', muted: 'colors[text_muted]', line: 'colors[border]'
    };

    function say(msg, isError) {
        if (!status) return;
        status.textContent = msg || '';
        status.style.color = isError ? 'var(--pp-danger, #dc2626)' : '';
    }

    function colors() {
        return [...list.querySelectorAll('[data-brand-color]')].map(i => i.value);
    }

    function addSwatch(hex) {
        if (list.querySelectorAll('[data-brand-color]').length >= MAX) return;
        const wrap = document.createElement('span');
        wrap.className = 'pp-brandcolors__item';
        wrap.innerHTML = '<input type="color" data-brand-color>'
                       + '<button type="button" data-brand-color-remove aria-label="×">×</button>';
        wrap.querySelector('input').value = hex || '#888888';
        list.appendChild(wrap);
    }

    function persist() {
        const body = new URLSearchParams();
        body.append('_csrf', csrf);
        colors().forEach(c => body.append('brand_palette[]', c));
        return fetch(urls.save, { method: 'POST', body, credentials: 'same-origin' });
    }

    // Rellena los campos del formulario y dispara `input` para que la
    // previsualización en vivo se entere.
    function applyPalette(tokens) {
        Object.entries(TOKEN_TO_FIELD).forEach(([key, name]) => {
            if (!tokens[key]) return;
            form.querySelectorAll('[name="' + name + '"]').forEach(el => {
                el.value = tokens[key];
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    root.addEventListener('click', function (ev) {
        const rm = ev.target.closest('[data-brand-color-remove]');
        if (rm) { rm.closest('.pp-brandcolors__item').remove(); persist(); return; }

        if (ev.target.closest('[data-brand-color-add]')) { addSwatch('#888888'); return; }

        if (ev.target.closest('[data-brand-color-extract]')) {
            say(pp.t('js.design.extracting'));
            const body = new URLSearchParams({ _csrf: csrf });
            fetch(urls.extract, { method: 'POST', body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) { say(d.error || pp.t('js.design.palette_error'), true); return; }
                    list.innerHTML = '';
                    (d.colors || []).forEach(addSwatch);
                    persist();
                    say('');
                })
                .catch(() => say(pp.t('js.design.palette_error'), true));
            return;
        }

        if (ev.target.closest('[data-palette-generate]')) {
            const brand = colors();
            if (!brand.length) { say(pp.t('js.design.need_brand_color'), true); return; }
            say(pp.t('js.design.generating'));
            const body = new URLSearchParams();
            body.append('_csrf', csrf);
            brand.forEach(c => body.append('brand_palette[]', c));
            persist();
            fetch(urls.generate, { method: 'POST', body, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) { say(d.error || pp.t('js.design.palette_error'), true); return; }
                    renderProposals(d.palettes || []);
                    say(d.notice || '');
                })
                .catch(() => say(pp.t('js.design.palette_error'), true));
        }
    });

    function renderProposals(palettes) {
        box.innerHTML = '';
        box.hidden = palettes.length === 0;
        palettes.forEach(p => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'pp-palette-card';
            const swatches = ['bg', 'surface', 'text', 'accent', 'accent_2']
                .map(k => '<i style="background:' + (p.tokens[k] || '#fff') + '"></i>').join('');
            card.innerHTML = '<span class="pp-palette-card__sw">' + swatches + '</span>'
                           + '<strong></strong><small></small>';
            card.querySelector('strong').textContent = p.name || '';
            card.querySelector('small').textContent = p.rationale || '';
            card.addEventListener('click', function () {
                applyPalette(p.tokens);
                box.querySelectorAll('.pp-palette-card').forEach(c => c.classList.remove('is-active'));
                card.classList.add('is-active');
                say(pp.t('js.design.palette_applied'));
            });
            box.appendChild(card);
        });
    }
})();
</script>
