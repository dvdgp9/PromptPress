<?php
/**
 * @var array  $config  config de chrome (mergeada)
 * @var array  $pages   [{id,title,page_type,status}]
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
$h = $config['header'] ?? [];
$hl = $h['layout'] ?? [];
$hs = $h['style'] ?? [];
$hBorder = $hs['border'] ?? [];
$hb = $h['brand'] ?? [];
$cta = $h['cta'] ?? [];
$f = $config['footer'] ?? [];
$fs = $f['style'] ?? [];
$fBorder = $fs['border'] ?? [];
$fb = $f['brand'] ?? [];
$fl = $f['labels'] ?? [];
$fc = $f['contact'] ?? [];
$fn = $f['newsletter'] ?? [];
$sel = static fn($a, $b) => ((string) $a === (string) $b) ? ' selected' : '';
$borderValue = static fn($border, string $side, string $key): string => (string) (($border[$side][$key] ?? ''));
$borderControls = static function (string $prefix, array $border) use ($sel, $borderValue): string {
    $mode = (string) ($border['mode'] ?? 'all');
    $sideLabels = ['top' => __('chrome.side.top'), 'right' => __('chrome.side.right'), 'bottom' => __('chrome.side.bottom'), 'left' => __('chrome.side.left')];
    $hasBorder = false;
    foreach (['all', 'top', 'right', 'bottom', 'left'] as $bs) {
        if ((int) ($border[$bs]['width'] ?? 0) > 0) { $hasBorder = true; break; }
    }
    ob_start(); ?>
    <details class="pp-chrome-advanced"<?= $hasBorder ? ' open' : '' ?>>
        <summary class="pp-chrome-advanced__summary"><?= e(__('chrome.borders')) ?> <span class="pp-chrome-advanced__tag"><?= e(__('chrome.advanced')) ?></span></summary>
    <div class="pp-chrome-border" data-border-editor="<?= e($prefix) ?>">
        <div class="pp-form-row pp-form-row--compact">
            <div class="pp-form-group">
                <label for="<?= e($prefix) ?>_border_mode"><?= e(__('chrome.apply_to')) ?></label>
                <select id="<?= e($prefix) ?>_border_mode" data-border-mode="<?= e($prefix) ?>">
                    <option value="all"<?= $sel($mode, 'all') ?>><?= e(__('chrome.all_together')) ?></option>
                    <option value="sides"<?= $sel($mode, 'sides') ?>><?= e(__('chrome.per_side')) ?></option>
                </select>
            </div>
        </div>
        <div class="pp-chrome-border__all" data-border-all="<?= e($prefix) ?>">
            <div class="pp-form-row pp-form-row--compact">
                <div class="pp-form-group">
                    <label for="<?= e($prefix) ?>_border_all_width">Grosor</label>
                    <input type="number" id="<?= e($prefix) ?>_border_all_width" min="0" max="24" step="1" value="<?= e($borderValue($border, 'all', 'width')) ?>" placeholder="0">
                </div>
                <div class="pp-form-group">
                    <label for="<?= e($prefix) ?>_border_all_color">Color</label>
                    <input type="color" id="<?= e($prefix) ?>_border_all_color" value="<?= e($borderValue($border, 'all', 'color') ?: '#e5e7eb') ?>">
                </div>
            </div>
        </div>
        <div class="pp-chrome-border__sides" data-border-sides="<?= e($prefix) ?>">
            <?php foreach ($sideLabels as $side => $label): ?>
                <div class="pp-chrome-border__side">
                    <span><?= e($label) ?></span>
                    <input type="number" id="<?= e($prefix . '_border_' . $side . '_width') ?>" min="0" max="24" step="1" value="<?= e($borderValue($border, $side, 'width')) ?>" placeholder="0" aria-label="Grosor <?= e($label) ?>">
                    <input type="color" id="<?= e($prefix . '_border_' . $side . '_color') ?>" value="<?= e($borderValue($border, $side, 'color') ?: '#e5e7eb') ?>" aria-label="Color <?= e($label) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    </details>
    <?php return (string) ob_get_clean();
};
?>

<?php \Core\View::start('title'); ?>Header y pie<?php \Core\View::end(); ?>

<?php \Core\View::start('head'); ?>
<script>
  window.PP_CHROME = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.PP_PAGES  = <?= json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.PP_BASEURL = "<?= e(rtrim(base_url(''), '/')) ?>";
  window.PP_CSRF = "<?= e($csrf) ?>";
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('nav.chrome')) ?></h2>
    <p class="pp-page-intro"><?= e(__('chrome.intro')) ?></p>
</div>

<?php if (!empty($isMultilingual)): ?>
    <div class="pp-chrome-langbar" id="pp-chrome-langbar"
         data-primary="<?= e($primaryLang) ?>">
        <div class="pp-chrome-langbar__label">
            <strong><?= e(__('chrome.editing_texts_in')) ?></strong>
            <span><?= e(__('chrome.shared_note')) ?></span>
        </div>
        <div class="pp-chrome-langbar__tabs" role="tablist">
            <?php foreach ($languages as $code): ?>
                <button type="button" role="tab"
                        class="pp-chrome-langtab<?= $code === $primaryLang ? ' is-active' : '' ?>"
                        data-lang="<?= e($code) ?>"
                        aria-selected="<?= $code === $primaryLang ? 'true' : 'false' ?>">
                    <?= e($languageLabels[$code] ?? $code) ?><?= $code === $primaryLang ? ' · ' . e(__('chrome.primary')) : '' ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="pp-chrome-langhint" id="pp-chrome-langhint" hidden></p>
<?php endif; ?>

<div class="pp-chrome-editor">
    <form method="POST" action="<?= e(base_url('admin/chrome')) ?>" class="pp-chrome-editor__form" id="chrome-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="pp-tabs pp-chrome-tabs" role="tablist" aria-label="<?= e(__('chrome.tabs_aria')) ?>">
            <button type="button" class="pp-tab is-active" data-chrome-tab="header" role="tab" aria-selected="true" aria-controls="chrome-panel-header">Header</button>
            <button type="button" class="pp-tab" data-chrome-tab="footer" role="tab" aria-selected="false" aria-controls="chrome-panel-footer"><?= e(__('chrome.footer')) ?></button>
        </div>

        <div class="pp-tab-panel is-active" id="chrome-panel-header" data-chrome-panel="header" role="tabpanel">
        <section class="pp-form-card pp-chrome-panel">
            <div class="pp-chrome-panel__head">
                <div>
                    <h3>Header</h3>
                    <p class="pp-design-hint"><?= e(__('chrome.header_help')) ?></p>
                </div>
            </div>
            <div class="pp-chrome-panel__body">
                <div class="pp-chrome-section">
                    <h4><?= e(__('chrome.menu')) ?></h4>
                    <div id="menu-list" class="pp-chrome-list"></div>
                    <div class="pp-chrome-addrow">
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-menu="page">+ <?= e(__('js.onb.page')) ?></button>
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-menu="link">+ <?= e(__('js.chrome.link')) ?></button>
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-menu="dropdown">+ <?= e(__('js.chrome.submenu')) ?></button>
                    </div>
                </div>

                <div class="pp-chrome-section">
                    <h4><?= e(__('chrome.button')) ?></h4>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group">
                            <label for="cta_mode"><?= e(__('chrome.mode')) ?></label>
                            <select id="cta_mode" name="_cta_mode">
                                <option value="auto"<?= $sel($cta['mode'] ?? 'auto', 'auto') ?>><?= e(__('chrome.automatic')) ?></option>
                                <option value="custom"<?= $sel($cta['mode'] ?? '', 'custom') ?>>Personalizado</option>
                                <option value="off"<?= $sel($cta['mode'] ?? '', 'off') ?>><?= e(__('chrome.no_button')) ?></option>
                            </select>
                        </div>
                        <div class="pp-form-group">
                            <label for="cta_style"><?= e(__('chrome.style')) ?></label>
                            <select id="cta_style" name="_cta_style">
                                <option value="primary"<?= $sel($cta['style'] ?? 'primary', 'primary') ?>>Primario</option>
                                <option value="ghost"<?= $sel($cta['style'] ?? '', 'ghost') ?>>Contorno</option>
                            </select>
                        </div>
                    </div>
                    <div class="pp-form-row pp-form-row--compact" data-cta-custom>
                        <div class="pp-form-group">
                            <label for="cta_label"><?= e(__('js.chrome.text')) ?></label>
                            <input type="text" id="cta_label" name="_cta_label" maxlength="60" value="<?= e((string) ($cta['label'] ?? '')) ?>" placeholder="Reserva una cita">
                        </div>
                        <div class="pp-form-group">
                            <label for="cta_url"><?= e(__('chrome.target')) ?></label>
                            <input type="text" id="cta_url" name="_cta_url" maxlength="300" value="<?= e((string) ($cta['url'] ?? '')) ?>" placeholder="/contacto">
                        </div>
                    </div>
                    <div class="pp-form-group">
                        <label for="h_mobile_cta"><?= e(__('chrome.mobile_button')) ?></label>
                        <select id="h_mobile_cta">
                            <option value="show"<?= $sel($hl['mobile_cta'] ?? 'show', 'show') ?>>Mostrar</option>
                            <option value="hide"<?= $sel($hl['mobile_cta'] ?? '', 'hide') ?>>Ocultar</option>
                        </select>
                    </div>
                </div>

                <div class="pp-chrome-section">
                    <h4><?= e(__('chrome.layout')) ?></h4>
                    <label class="pp-switch-row">
                        <span class="pp-switch">
                            <input type="checkbox" id="h_sticky"<?= !empty($hl['sticky']) ? ' checked' : '' ?>>
                            <span class="pp-switch__track"></span>
                            <span class="pp-switch__knob"></span>
                        </span>
                        <span><?= e(__('chrome.sticky')) ?></span>
                    </label>
                    <label class="pp-switch-row">
                        <span class="pp-switch">
                            <input type="checkbox" id="h_transparent"<?= !empty($hl['transparent_over_hero']) ? ' checked' : '' ?>>
                            <span class="pp-switch__track"></span>
                            <span class="pp-switch__knob"></span>
                        </span>
                        <span><?= e(__('chrome.transparent')) ?></span>
                    </label>
                    <div class="pp-form-group">
                        <label for="h_brand_url"><?= e(__('chrome.brand_target')) ?></label>
                        <input type="text" id="h_brand_url" maxlength="300" value="<?= e((string) ($hb['url'] ?? '')) ?>" placeholder="Portada por defecto">
                    </div>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group">
                            <label for="h_density"><?= e(__('chrome.density')) ?></label>
                            <select id="h_density">
                                <option value="compact"<?= $sel($hl['density'] ?? '', 'compact') ?>>Compacta</option>
                                <option value="regular"<?= $sel($hl['density'] ?? 'regular', 'regular') ?>>Normal</option>
                                <option value="tall"<?= $sel($hl['density'] ?? '', 'tall') ?>>Amplia</option>
                            </select>
                        </div>
                        <div class="pp-form-group">
                            <label for="h_logo"><?= e(__('chrome.logo_position')) ?></label>
                            <select id="h_logo">
                                <option value="left"<?= $sel($hl['logo_position'] ?? 'left', 'left') ?>>Izquierda</option>
                                <option value="center"<?= $sel($hl['logo_position'] ?? '', 'center') ?>>Centro</option>
                            </select>
                        </div>
                    </div>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group">
                            <label for="h_width"><?= e(__('chrome.width')) ?></label>
                            <select id="h_width">
                                <option value="contained"<?= $sel($hl['width'] ?? 'contained', 'contained') ?>>Contenida</option>
                                <option value="full"<?= $sel($hl['width'] ?? '', 'full') ?>>Ancho completo</option>
                            </select>
                        </div>
                        <div class="pp-form-group">
                            <label for="h_nav_alignment"><?= e(__('chrome.nav_alignment')) ?></label>
                            <select id="h_nav_alignment">
                                <option value="right"<?= $sel($hl['nav_alignment'] ?? 'right', 'right') ?>>Derecha</option>
                                <option value="center"<?= $sel($hl['nav_alignment'] ?? '', 'center') ?>>Centro</option>
                                <option value="left"<?= $sel($hl['nav_alignment'] ?? '', 'left') ?>>Izquierda</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="pp-chrome-section">
                    <h4><?= e(__('chrome.appearance')) ?></h4>
                    <div class="pp-form-group">
                        <label for="h_bg"><?= e(__('chrome.bg_color')) ?></label>
                        <select id="h_bg">
                            <option value="auto"<?= $sel($hs['background'] ?? 'auto', 'auto') ?>><?= e(__('chrome.automatic')) ?></option>
                            <option value="light"<?= $sel($hs['background'] ?? '', 'light') ?>>Claro</option>
                            <option value="dark"<?= $sel($hs['background'] ?? '', 'dark') ?>>Oscuro</option>
                            <option value="brand"<?= $sel($hs['background'] ?? '', 'brand') ?>>Color de marca</option>
                            <option value="transparent"<?= $sel($hs['background'] ?? '', 'transparent') ?>>Transparente</option>
                        </select>
                    </div>
                    <?= $borderControls('h', (array) $hBorder) ?>
                </div>
            </div>
        </section>
        </div>

        <div class="pp-tab-panel" id="chrome-panel-footer" data-chrome-panel="footer" role="tabpanel" hidden>
        <?php
        $allFBlocks = ['brand', 'nav', 'legal', 'contact', 'social', 'newsletter'];
        $fblockTitles = [
            'brand' => __('chrome.block.brand'), 'nav' => __('chrome.block.nav'), 'legal' => __('chrome.block.legal'),
            'contact' => 'Contacto', 'social' => 'Redes sociales', 'newsletter' => 'Newsletter',
        ];
        $fblockAuto = ['nav' => __('chrome.auto_published'), 'legal' => __('chrome.auto_legal')];
        $savedFBlocks = array_values(array_filter((array) ($f['blocks'] ?? []), 'is_string'));
        $defaultFOn = ['brand', 'nav', 'legal'];
        $enabledFBlocks = $savedFBlocks ?: $defaultFOn;
        $fOrder = $savedFBlocks ?: $defaultFOn;
        foreach ($allFBlocks as $b) { if (!in_array($b, $fOrder, true)) $fOrder[] = $b; }

        $fblockBody = function (string $b) use ($f, $fb, $fl, $fc, $fn): string {
            ob_start();
            switch ($b) {
                case 'brand': ?>
                    <div class="pp-form-group"><label for="f_brand_name">Nombre en el pie</label><input type="text" id="f_brand_name" maxlength="120" value="<?= e((string) ($fb['name'] ?? '')) ?>" placeholder="Nombre del sitio"></div>
                    <div class="pp-form-group"><label for="f_tagline">Lema</label><input type="text" id="f_tagline" maxlength="200" value="<?= e((string) ($f['tagline'] ?? '')) ?>" placeholder="Memoria del negocio"></div>
                    <div class="pp-form-group"><label for="f_copyright"><?= e(__('chrome.copyright')) ?></label><input type="text" id="f_copyright" maxlength="160" value="<?= e((string) ($f['copyright'] ?? '')) ?>" placeholder="<?= e(__('chrome.copyright_placeholder')) ?>"></div>
                <?php break;
                case 'nav': ?>
                    <div class="pp-form-group"><label for="f_label_nav"><?= e(__('chrome.column_title')) ?></label><input type="text" id="f_label_nav" maxlength="60" value="<?= e((string) ($fl['nav'] ?? '')) ?>" placeholder="<?= e(__('chrome.explore')) ?>"></div>
                    <div id="footernav-list" class="pp-chrome-list"></div>
                    <div class="pp-chrome-addrow">
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-footernav="page">+ <?= e(__('js.onb.page')) ?></button>
                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-footernav="link">+ <?= e(__('js.chrome.link')) ?></button>
                    </div>
                    <p class="pp-fblock__hint"><?= e(__('chrome.auto_pages_hint')) ?></p>
                <?php break;
                case 'legal': ?>
                    <div class="pp-form-group"><label for="f_label_legal"><?= e(__('chrome.column_title')) ?></label><input type="text" id="f_label_legal" maxlength="60" value="<?= e((string) ($fl['legal'] ?? '')) ?>" placeholder="Legal"></div>
                    <p class="pp-fblock__hint"><?= e(__('chrome.legal_hint')) ?></p>
                <?php break;
                case 'contact': ?>
                    <div class="pp-form-group"><label for="f_label_contact"><?= e(__('chrome.column_title')) ?></label><input type="text" id="f_label_contact" maxlength="60" value="<?= e((string) ($fl['contact'] ?? '')) ?>" placeholder="<?= e(__('page_type.contact')) ?>"></div>
                    <div class="pp-form-group"><label for="c_address"><?= e(__('chrome.address')) ?></label><textarea id="c_address" rows="2" maxlength="300"><?= e((string) ($fc['address'] ?? '')) ?></textarea></div>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group"><label for="c_phone"><?= e(__('field.tel')) ?></label><input type="text" id="c_phone" maxlength="60" value="<?= e((string) ($fc['phone'] ?? '')) ?>"></div>
                        <div class="pp-form-group"><label for="c_email">Email</label><input type="text" id="c_email" maxlength="120" value="<?= e((string) ($fc['email'] ?? '')) ?>"></div>
                    </div>
                    <div class="pp-form-group"><label for="c_hours">Horario</label><input type="text" id="c_hours" maxlength="120" value="<?= e((string) ($fc['hours'] ?? '')) ?>" placeholder="L-V 9:00-18:00"></div>
                <?php break;
                case 'social': ?>
                    <div class="pp-form-group"><label for="f_label_social"><?= e(__('chrome.column_title')) ?></label><input type="text" id="f_label_social" maxlength="60" value="<?= e((string) ($fl['social'] ?? '')) ?>" placeholder="<?= e(__('chrome.follow_us')) ?>"></div>
                    <div id="social-list" class="pp-chrome-list"></div>
                    <div class="pp-chrome-addrow"><button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="add-social">+ Red social</button></div>
                <?php break;
                case 'newsletter': ?>
                    <div class="pp-form-group"><label for="f_label_newsletter"><?= e(__('chrome.column_title')) ?></label><input type="text" id="f_label_newsletter" maxlength="60" value="<?= e((string) ($fl['newsletter'] ?? '')) ?>" placeholder="Newsletter"></div>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group"><label for="n_heading"><?= e(__('chrome.headline')) ?></label><input type="text" id="n_heading" maxlength="120" value="<?= e((string) ($fn['heading'] ?? '')) ?>" placeholder="<?= e(__('chrome.newsletter_placeholder')) ?>"></div>
                        <div class="pp-form-group"><label for="n_form">Destino</label><input type="text" id="n_form" maxlength="120" value="<?= e((string) ($fn['form_ref'] ?? '')) ?>" placeholder="/contacto"></div>
                    </div>
                    <div class="pp-form-group"><label for="n_cta_label"><?= e(__('form_edit.button_text')) ?></label><input type="text" id="n_cta_label" maxlength="60" value="<?= e((string) ($fn['cta_label'] ?? '')) ?>" placeholder="<?= e(__('chrome.subscribe')) ?>"></div>
                    <p class="pp-fblock__hint"><?= e(__('chrome.newsletter_hint')) ?></p>
                <?php break;
            }
            return (string) ob_get_clean();
        };
        ?>
        <section class="pp-form-card pp-chrome-panel">
            <div class="pp-chrome-panel__head">
                <div>
                    <h3><?= e(__('chrome.footer_blocks')) ?></h3>
                    <p class="pp-design-hint"><?= e(__('chrome.footer_blocks_help')) ?></p>
                </div>
            </div>
            <div class="pp-chrome-panel__body">
                <div class="pp-fblocks" id="footer-blocks">
                    <?php foreach ($fOrder as $b): $on = in_array($b, $enabledFBlocks, true); ?>
                    <div class="pp-fblock<?= $on ? '' : ' is-off' ?>" data-fblock="<?= e($b) ?>">
                        <div class="pp-fblock__head">
                            <label class="pp-switch" title="<?= e(__('chrome.show_block')) ?>">
                                <input type="checkbox" class="pp-fblock-on"<?= $on ? ' checked' : '' ?> aria-label="Mostrar <?= e($fblockTitles[$b]) ?>">
                                <span class="pp-switch__track"></span>
                                <span class="pp-switch__knob"></span>
                            </label>
                            <button type="button" class="pp-fblock__toggle" aria-expanded="false">
                                <span class="pp-fblock__name"><?= e($fblockTitles[$b]) ?></span>
                                <?php if (isset($fblockAuto[$b])): ?><span class="pp-fblock__auto"><?= e($fblockAuto[$b]) ?></span><?php endif; ?>
                                <span class="pp-fblock__chev" aria-hidden="true">⌄</span>
                            </button>
                            <div class="pp-fblock__reorder">
                                <button type="button" class="pp-chrome-row__btn" data-fblock-up title="<?= e(__('chrome.move_up')) ?>">↑</button>
                                <button type="button" class="pp-chrome-row__btn" data-fblock-down title="<?= e(__('chrome.move_down')) ?>">↓</button>
                            </div>
                        </div>
                        <div class="pp-fblock__body" hidden><?= $fblockBody($b) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="pp-chrome-section">
                    <h4><?= e(__('chrome.footer_appearance')) ?></h4>
                    <div class="pp-form-row pp-form-row--compact">
                        <div class="pp-form-group">
                            <label for="f_bg"><?= e(__('chrome.background')) ?></label>
                            <select id="f_bg">
                                <option value="dark"<?= $sel($fs['background'] ?? 'dark', 'dark') ?>>Oscuro</option>
                                <option value="light"<?= $sel($fs['background'] ?? '', 'light') ?>>Claro</option>
                                <option value="brand"<?= $sel($fs['background'] ?? '', 'brand') ?>>Color de marca</option>
                            </select>
                        </div>
                        <div class="pp-form-group">
                            <label for="f_columns"><?= e(__('chrome.columns')) ?></label>
                            <select id="f_columns">
                                <option value="0"<?= $sel($fs['columns'] ?? 0, 0) ?>><?= e(__('chrome.automatic')) ?></option>
                                <option value="2"<?= $sel($fs['columns'] ?? 0, 2) ?>>2</option>
                                <option value="3"<?= $sel($fs['columns'] ?? 0, 3) ?>>3</option>
                                <option value="4"<?= $sel($fs['columns'] ?? 0, 4) ?>>4</option>
                            </select>
                        </div>
                    </div>
                    <?= $borderControls('f', (array) $fBorder) ?>
                </div>
            </div>
        </section>
        </div>

        <div class="pp-chrome-actions">
            <button type="submit" class="pp-btn pp-btn--primary" id="chrome-save"><?= e(__('common.save_changes')) ?></button>
            <span class="pp-chrome-dirty" id="chrome-dirty" hidden><span class="pp-chrome-dirty__dot" aria-hidden="true"></span>Cambios sin guardar</span>
        </div>
    </form>

    <aside class="pp-chrome-editor__preview">
        <div class="pp-chrome-preview-head">
            <span><?= e(__('js.post_new.preview')) ?></span>
            <div class="pp-chrome-devtoggle" role="group" aria-label="<?= e(__('chrome.screen_size')) ?>">
                <button type="button" data-device="desktop" class="is-active"><?= e(__('chrome.desktop')) ?></button>
                <button type="button" data-device="mobile"><?= e(__('chrome.mobile')) ?></button>
            </div>
        </div>
        <div class="pp-chrome-preview-frame" id="chrome-preview-frame">
            <iframe id="chrome-preview" title="<?= e(__('chrome.preview_title')) ?>" scrolling="no"></iframe>
        </div>
    </aside>
</div>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/chrome-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/chrome-editor.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>
