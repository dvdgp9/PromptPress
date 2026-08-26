<?php
/**
 * @var array $site
 * @var string $csrf
 * @var array $steps
 * @var int $step
 * @var array $memoryFields
 * @var array $memoryValues
 * @var array $designValues
 * @var array $brandValues
 * @var array $referenceValues
 * @var array $aiValues
 * @var array $aiModels
 * @var array $swatches
 * @var array $typographyOptions
 * @var ?array $document
 * @var array $documents
 * @var array $businessPhotos
 * @var int $photosMax
 */
\Core\View::extend('admin/layout');

$stepMeta = [
    1 => ['eyebrow' => __('onboarding.step1.eyebrow'), 'title' => __('onboarding.step1.title'), 'subtitle' => __('onboarding.step1.subtitle'), 'action' => __('common.next')],
    2 => ['eyebrow' => __('onboarding.step2.eyebrow'), 'title' => __('onboarding.step2.title'), 'subtitle' => __('onboarding.step2.subtitle'), 'action' => __('common.next')],
    3 => ['eyebrow' => __('onboarding.step3.eyebrow'), 'title' => __('onboarding.step3.title'), 'subtitle' => __('onboarding.step3.subtitle'), 'action' => __('common.next')],
    // ONB-FOTOS — el paso deja de ser solo documentos: fotos y documentos son la
    // misma pregunta ("¿qué material tienes ya?") y las fotos son lo que evita
    // que la web se genere entera con banco de imágenes.
    4 => ['eyebrow' => __('onboarding.step4.eyebrow'), 'title' => __('onboarding.step4.title'), 'subtitle' => __('onboarding.step4.subtitle'), 'action' => __('common.continue')],
    5 => ['eyebrow' => __('onboarding.step5.eyebrow'), 'title' => __('onboarding.step5.title'), 'subtitle' => __('onboarding.step5.subtitle'), 'action' => __('onboarding.step5.action')],
];
// La clave es un identificador estable ('seo' se compara más abajo); la
// etiqueta se traduce aparte. Antes la clave ERA la etiqueta en castellano.
$groups = [
    'essential' => ['business_description', 'target_audience', 'tone_of_voice'],
    'offer'     => ['services', 'value_proposition', 'unique_selling_points'],
    'seo'       => ['keywords', 'contact_info'],
];
?>

<?php \Core\View::start('title'); ?><?= e(__('onboarding.title')) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-onboarding-mode<?php \Core\View::end(); ?>
<?php \Core\View::start('head'); ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300..900&family=DM+Sans:wght@300..900&family=Fraunces:opsz,wght,SOFT,WONK@9..144,300..900,0..100,0..1&family=IBM+Plex+Sans:wght@300..700&family=Lora:wght@400..700&family=Manrope:wght@300..800&family=Montserrat:wght@300..800&family=Open+Sans:wght@300..800&family=Outfit:wght@300..900&family=Playfair+Display:wght@400..900&family=Plus+Jakarta+Sans:wght@300..800&family=Source+Sans+3:wght@300..900&family=Space+Grotesk:wght@300..700&display=swap">
<?php \Core\View::end(); ?>
<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/color-picker.js')) ?>?v=<?= @filemtime(PP_ROOT . '/admin/assets/js/color-picker.js') ?: '1' ?>"></script>
<script src="<?= e(base_url('admin/assets/js/onboarding.js')) ?>?v=<?= @filemtime(PP_ROOT . '/admin/assets/js/onboarding.js') ?: '1' ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-onboarding" id="pp-onboarding" data-step="<?= (int) $step ?>" data-csrf="<?= e($csrf) ?>" data-base-url="<?= e(base_url('')) ?>">
    <header class="pp-onboarding-topbar">
        <strong>PromptPress</strong>
        <form method="POST" action="<?= e(base_url('admin/onboarding/exit')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit"><?= e(__('onboarding.exit')) ?> →</button>
        </form>
    </header>

    <main class="pp-onboarding-shell">
        <nav class="pp-onboarding-progress" aria-label="<?= e(__('onboarding.progress_aria')) ?>">
            <?php foreach ($steps as $i => $label): ?>
                <div class="<?= $i < $step ? 'is-done' : ($i === $step ? 'is-current' : 'is-pending') ?>">
                    <span></span>
                    <small><?= e($label) ?></small>
                </div>
            <?php endforeach; ?>
        </nav>

        <?php // ONB2 O2.1 — El paso 2 es el único a dos columnas: necesita más ancho
              // que los pasos de una sola, donde ensanchar alargaría las líneas de texto. ?>
        <section class="pp-onboarding-card<?= $step === 2 ? ' pp-onboarding-card--wide' : '' ?>">
            <div class="pp-onboarding-step">
                <p class="pp-onboarding-eyebrow"><?= e($stepMeta[$step]['eyebrow']) ?></p>
                <h1><?= e($stepMeta[$step]['title']) ?></h1>
                <p class="pp-onboarding-subtitle"><?= e($stepMeta[$step]['subtitle']) ?></p>
            </div>

            <?php if ($step === 1): ?>
                <form method="POST" action="<?= e(base_url('admin/onboarding/step/1')) ?>" class="pp-onboarding-form" data-onboarding-form>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <section class="pp-onboarding-autofill" data-memory-autofill>
                        <div>
                            <span><?= e(__('onboarding.autofill.eyebrow')) ?></span>
                            <h2><?= e(__('onboarding.autofill.title')) ?></h2>
                            <p><?= e(__('onboarding.autofill.text')) ?></p>
                        </div>
                        <label>
                            <input type="file" name="dossier[]" accept=".pdf,.docx,.txt" multiple data-memory-autofill-file>
                            <strong data-memory-autofill-file-label><?= e(__('onboarding.autofill.choose')) ?></strong>
                            <small><?= e(__('onboarding.docs.formats')) ?></small>
                        </label>
                        <button type="button" class="pp-btn pp-btn--secondary" data-memory-autofill-button><?= e(__('onboarding.autofill.button')) ?></button>
                        <p data-memory-autofill-status></p>
                    </section>
                    <?php foreach ($groups as $groupKey => $keys): ?>
                        <?php $isSeo = $groupKey === 'seo'; $groupLabel = __('onboarding.group.' . $groupKey); ?>
                        <<?= $isSeo ? 'details' : 'div' ?> class="pp-onboarding-fieldset" <?= $isSeo ? '' : '' ?>>
                            <?php if ($isSeo): ?><summary><?= e($groupLabel) ?></summary><?php else: ?><h2><?= e($groupLabel) ?></h2><?php endif; ?>
                            <?php foreach ($keys as $key): $field = $memoryFields[$key]; ?>
                                <label class="pp-onboarding-field" data-field-key="<?= e($key) ?>">
                                    <span>
                                        <?= e($field['label']) ?>
                                        <?php if ($key === 'business_description'): ?><em>* <?= e(__('common.recommended')) ?></em><?php else: ?><em><?= e(__('common.optional')) ?></em><?php endif; ?>
                                    </span>
                                    <?php if (($field['type'] ?? '') === 'select'): ?>
                                        <select name="<?= e($key) ?>">
                                            <?php foreach (($field['options'] ?? []) as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= (($memoryValues[$key] ?? '') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <textarea name="<?= e($key) ?>" rows="<?= (int) ($field['rows'] ?? 3) ?>" placeholder="<?= e((string) ($field['placeholder'] ?? '')) ?>"><?= e((string) ($memoryValues[$key] ?? '')) ?></textarea>
                                    <?php endif; ?>
                                    <small><?= e($key === 'tone_of_voice' ? __('onboarding.tone_help') : (string) ($field['help'] ?? '')) ?></small>
                                    <?php if ($key === 'business_description'): ?>
                                        <details class="pp-onboarding-example">
                                            <summary><?= e(__('onboarding.see_example')) ?></summary>
                                            <p><?= e(__('onboarding.example_text')) ?></p>
                                        </details>
                                        <p class="pp-onboarding-warning" data-business-warning hidden><?= e(__('onboarding.business_warning')) ?></p>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </<?= $isSeo ? 'details' : 'div' ?>>
                    <?php endforeach; ?>

                    <!-- E-GDPR G6 — Datos legales opcionales (panel desplegable) -->
                    <details class="pp-onboarding-fieldset pp-onboarding-legal">
                        <summary>
                            <span><?= e(__('onboarding.legal.title')) ?></span>
                            <em><?= e(__('onboarding.legal.hint')) ?></em>
                        </summary>
                        <p class="pp-onboarding-legal__intro"><?= e(__('onboarding.legal.intro')) ?></p>
                        <label class="pp-onboarding-field">
                            <span><?= e(__('onboarding.legal.name')) ?> <em><?= e(__('common.optional')) ?></em></span>
                            <input type="text" name="legal_name" maxlength="255" placeholder="<?= e(__('onboarding.legal.name_placeholder')) ?>">
                            <small><?= e(__('onboarding.legal.name_help')) ?></small>
                        </label>
                        <label class="pp-onboarding-field">
                            <span><?= e(__('onboarding.legal.tax_id')) ?> <em><?= e(__('common.optional')) ?></em></span>
                            <input type="text" name="legal_tax_id" maxlength="20" placeholder="B12345678">
                        </label>
                        <label class="pp-onboarding-field">
                            <span><?= e(__('onboarding.legal.address')) ?> <em><?= e(__('common.optional')) ?></em></span>
                            <input type="text" name="legal_address" maxlength="500" placeholder="<?= e(__('onboarding.legal.address_placeholder')) ?>">
                        </label>
                        <label class="pp-onboarding-field">
                            <span><?= e(__('onboarding.legal.email')) ?> <em><?= e(__('common.optional')) ?></em></span>
                            <input type="email" name="legal_email" maxlength="255" placeholder="contacto@tu-dominio.com">
                        </label>
                    </details>

                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action']) ?>
                </form>
            <?php elseif ($step === 2): ?>
                <form method="POST" enctype="multipart/form-data" action="<?= e(base_url('admin/onboarding/step/2')) ?>" class="pp-onboarding-form" data-onboarding-form data-design-form>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="pp-onboarding-design-grid">
                        <div class="pp-onboarding-design-fields">
                            <?php // ONB2 O2.1 — Cuatro bloques: Marca · Inspiración · Color · Tipografía y forma. ?>
                            <section class="pp-onboarding-block">
                                <h2><?= e(__('onboarding.brand.title')) ?></h2>
                                <label class="pp-onboarding-field">
                                    <span><?= e(__('onboarding.brand.company_name')) ?> <em><?= e(__('common.recommended')) ?></em></span>
                                    <input type="text" name="site_name" value="<?= e((string) ($brandValues['name'] ?? '')) ?>" maxlength="255" data-brand-name>
                                    <small><?= e(__('onboarding.brand.company_name_help')) ?></small>
                                </label>
                                <?php // ONB2 O2.2 — Dos versiones del logo, nombradas por el FONDO donde
                                      // van (no por su color: "logo oscuro" es ambiguo). Y cuál manda
                                      // cuando no se sabe el fondo. ?>
                                <div class="pp-onboarding-logos">
                                    <?php foreach (\App\Services\BrandService::LOGO_VARIANTS as $variant => $cfg):
                                        $isDark = $variant === 'dark';
                                        $url = $isDark ? ($brandValues['logo_dark_url'] ?? '') : ($brandValues['logo_url'] ?? '');
                                        $has = $isDark ? !empty($brandValues['logo_dark_path']) : !empty($brandValues['logo_path']);
                                    ?>
                                        <div class="pp-onboarding-logo-slot<?= $isDark ? ' is-dark' : '' ?>">
                                            <label class="pp-onboarding-logo-field" data-logo-dropzone="<?= e($variant) ?>">
                                                <input type="file" name="<?= $isDark ? 'logo_dark' : 'logo' ?>" accept=".png,.jpg,.jpeg,.webp,.svg">
                                                <span>
                                                    <?php if ($has): ?>
                                                        <img src="<?= e((string) $url) ?>" alt="">
                                                    <?php else: ?>
                                                        <b></b>
                                                    <?php endif; ?>
                                                </span>
                                                <strong><?= e(\App\Services\BrandService::variantLabel($variant)) ?></strong>
                                                <small><?= e(__('onboarding.brand.logo_formats')) ?></small>
                                                <em data-logo-state><?= e($has ? __('onboarding.brand.logo_loaded') : __('onboarding.brand.logo_empty')) ?></em>
                                            </label>
                                            <label class="pp-onboarding-logo-primary">
                                                <input type="radio" name="logo_primary" value="<?= e($variant) ?>"
                                                       <?= (($brandValues['logo_primary'] ?? 'light') === $variant) ? 'checked' : '' ?>>
                                                <span><?= e(__('onboarding.brand.logo_default')) ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="pp-onboarding-logos__hint"><?= e(__('onboarding.brand.logo_hint')) ?></p>
                            </section>

                            <section class="pp-onboarding-block">
                                <h2><?= e(__('onboarding.inspiration.title')) ?></h2>
                                <label class="pp-onboarding-reference-field pp-onboarding-reference-field--hero" data-reference-dropzone>
                                    <input type="file" name="visual_references[]" accept="image/png,image/jpeg,image/webp" multiple>
                                    <span aria-hidden="true"></span>
                                    <strong><?= e(__('onboarding.inspiration.label')) ?></strong>
                                    <small><?= e(__('onboarding.inspiration.help')) ?></small>
                                    <em data-reference-state>
                                        <?php $refCount = (int) ($referenceValues['count'] ?? 0); ?>
                                        <?php if ($refCount > 0): ?>
                                            <?= e(__($refCount === 1 ? 'onboarding.inspiration.saved_one' : 'onboarding.inspiration.saved_other', ['n' => $refCount])) ?>
                                        <?php else: ?>
                                            <?= e(__('onboarding.inspiration.formats')) ?>
                                        <?php endif; ?>
                                    </em>
                                </label>
                            </section>

                            <section class="pp-onboarding-block">
                                <h2><?= e(__('onboarding.color.title')) ?></h2>
                                <?php // ONB2 O2.4 — Los colores de la marca del usuario: la materia prima
                                      // con la que se deriva después la paleta de la web. ?>
                                <div class="pp-onboarding-brandpalette" data-brand-palette
                                     data-max="<?= (int) \App\Controllers\Admin\OnboardingController::BRAND_PALETTE_MAX ?>">
                                    <span><?= e(__('onboarding.color.brand_colors')) ?> <em><?= e(__('common.optional')) ?></em></span>
                                    <p><?= e(__('onboarding.color.brand_colors_help')) ?></p>
                                    <div class="pp-onboarding-brandpalette__list" data-brand-palette-list>
                                        <?php foreach ((array) ($brandValues['brand_palette'] ?? []) as $hex): ?>
                                            <div class="pp-onboarding-brandpalette__item">
                                                <input type="text" name="brand_palette[]" value="<?= e((string) $hex) ?>" maxlength="7" data-pp-color aria-label="<?= e(__('onboarding.color.swatch_aria')) ?>">
                                                <button type="button" data-brand-palette-remove aria-label="<?= e(__('onboarding.color.remove_aria')) ?>">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="pp-onboarding-brandpalette__actions">
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-palette-add>+ <?= e(__('onboarding.color.add')) ?></button>
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-palette-extract><?= e(__('onboarding.color.extract')) ?></button>
                                        <small data-brand-palette-status></small>
                                    </div>
                                </div>
                                <?= design_swatches('primary_color', __('onboarding.color.primary'), (string) $designValues['primary_color'], $swatches) ?>
                                <?php // ONB2 O2.5 — Las paletas las propone la IA a partir de los colores
                                      // de marca, y el contraste lo garantiza el servidor. Aquí ya no se
                                      // elige un preset del catálogo ni un "color de texto" suelto: el
                                      // texto, los fondos y las líneas los decide la paleta. ?>
                                <div class="pp-onboarding-field pp-onboarding-palette-field" data-palette-field>
                                    <span><?= e(__('onboarding.color.palette')) ?> <em><?= e(__('onboarding.color.palette_hint')) ?></em></span>
                                    <div class="pp-onboarding-palette-grid" data-palette-grid>
                                        <?php if (!empty($currentPalette)): ?>
                                            <?= palette_card(__('onboarding.color.current_palette'), __('onboarding.color.current_palette_desc'), (array) $currentPalette, true) ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="pp-onboarding-palette-empty" data-palette-empty <?= !empty($currentPalette) ? 'hidden' : '' ?>>
                                        <?= e(__('onboarding.color.palette_empty')) ?>
                                    </p>
                                    <div class="pp-onboarding-palette-actions">
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-palette-generate><?= e(__('onboarding.color.generate')) ?></button>
                                        <small data-palette-status></small>
                                    </div>
                                    <input type="hidden" name="palette_custom" value="<?= e(!empty($currentPalette) ? json_encode($currentPalette) : '') ?>" data-palette-value>
                                    <small><?= e(__('onboarding.color.palette_note')) ?></small>
                                </div>
                            </section>

                            <section class="pp-onboarding-block pp-onboarding-block--duo">
                                <h2><?= e(__('onboarding.type.title')) ?></h2>
                                <label class="pp-onboarding-field">
                                    <span><?= e(__('onboarding.type.font')) ?> <em><?= e(__('common.optional')) ?></em></span>
                                    <select name="typography_pair" data-preview-font>
                                        <?php foreach ($typographyOptions as $value => $opt): ?>
                                            <option value="<?= e($value) ?>" data-heading="<?= e((string) $opt['heading']) ?>" data-body="<?= e((string) $opt['body']) ?>" <?= $designValues['typography_pair'] === $value ? 'selected' : '' ?>><?= e($value . ' — ' . $opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="pp-onboarding-field">
                                    <span><?= e(__('onboarding.type.corners')) ?> <em><?= e(__('common.optional')) ?></em></span>
                                    <div class="pp-onboarding-radius-control">
                                        <input type="range" name="border_radius" min="0" max="60" step="1" value="<?= e((string) $designValues['border_radius']) ?>" data-radius-range>
                                        <div><span><?= e(__('onboarding.type.square')) ?></span><strong data-radius-label><?= e((string) $designValues['border_radius']) ?> px</strong><span><?= e(__('onboarding.type.round')) ?></span></div>
                                    </div>
                                </div>
                                <?php /* FONTS · ONB2 O2.7 — Tipografía propia del cliente, ahora con
                                         DOS huecos: títulos y textos. El modelo de datos ya distinguía
                                         los roles; el paso solo dejaba subir una familia. Plegado para
                                         no recargar el paso: quien no tiene manual de marca ni lo abre. */ ?>
                                <?php
                                $ownFonts = (array) ($brandValues['custom_fonts'] ?? []);
                                $fontByRole = ['heading' => null, 'body' => null];
                                foreach ($ownFonts as $family) {
                                    $role = (string) ($family['role'] ?? '');
                                    if ($role === 'both') { $fontByRole['heading'] = $fontByRole['heading'] ?: $family; $fontByRole['body'] = $fontByRole['body'] ?: $family; }
                                    elseif (isset($fontByRole[$role])) { $fontByRole[$role] = $family; }
                                }
                                $sameForBoth = $ownFonts !== [] && ($ownFonts[0]['role'] ?? '') === 'both';
                                $fontSlots = [
                                    'heading' => ['label' => __('onboarding.type.for_headings'), 'placeholder' => __('onboarding.type.heading_placeholder')],
                                    'body'    => ['label' => __('onboarding.type.for_body'),     'placeholder' => __('onboarding.type.body_placeholder')],
                                ];
                                ?>
                                <details class="pp-onboarding-fonts" <?= $ownFonts !== [] ? 'open' : '' ?>>
                                    <summary>
                                        <strong><?= e(__('onboarding.type.own_fonts')) ?></strong>
                                        <small><?= $ownFonts !== [] ? e(implode(' · ', array_map(fn(array $f): string => (string) $f['name'], $ownFonts))) : __('onboarding.type.own_fonts_hint') ?></small>
                                    </summary>
                                    <div class="pp-onboarding-fonts__body">
                                        <label class="pp-onboarding-fonts__same">
                                            <input type="checkbox" name="custom_font_same" value="1" <?= $sameForBoth ? 'checked' : '' ?> data-fonts-same>
                                            <span><?= e(__('onboarding.type.same_font')) ?></span>
                                        </label>
                                        <div class="pp-onboarding-fonts__slots">
                                            <?php foreach ($fontSlots as $role => $slot):
                                                $current = $fontByRole[$role] ?? null;
                                                $files = (array) ($current['files'] ?? []);
                                            ?>
                                                <div class="pp-onboarding-fonts__slot" data-font-slot="<?= e($role) ?>">
                                                    <strong><?= e($slot['label']) ?></strong>
                                                    <label class="pp-onboarding-field">
                                                        <span><?= e(__('onboarding.type.font_name')) ?> <em><?= e(__('common.optional')) ?></em></span>
                                                        <input type="text" name="custom_font_name[<?= e($role) ?>]" maxlength="120"
                                                               placeholder="<?= e($slot['placeholder']) ?>"
                                                               value="<?= e((string) ($current['name'] ?? '')) ?>">
                                                    </label>
                                                    <label class="pp-onboarding-fonts__file">
                                                        <input type="file" name="custom_fonts_<?= e($role) ?>[]" accept=".woff2,.woff,.ttf,.otf" multiple data-onboarding-fonts>
                                                        <span aria-hidden="true"></span>
                                                        <strong><?= e(__('onboarding.type.upload_files')) ?></strong>
                                                        <small><?= e(__('onboarding.type.font_formats')) ?></small>
                                                        <em data-onboarding-fonts-state>
                                                            <?php if ($files !== []): ?>
                                                                <?= e(__(count($files) === 1 ? 'onboarding.type.files_one' : 'onboarding.type.files_other', ['n' => count($files)])) ?>
                                                            <?php else: ?>
                                                                <?= e(__('onboarding.type.no_files')) ?>
                                                            <?php endif; ?>
                                                        </em>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="pp-onboarding-fonts__legal"><?= e(__('onboarding.type.font_license')) ?></p>
                                    </div>
                                </details>
                            </section>
                        </div>
                        <aside class="pp-onboarding-preview" data-design-preview>
                            <span class="pp-onboarding-preview-brand">
                                <?php if (!empty($brandValues['logo_path'])): ?>
                                    <img src="<?= e((string) $brandValues['logo_url']) ?>" alt="" data-preview-logo>
                                <?php else: ?>
                                    <b data-preview-logo-fallback></b>
                                <?php endif; ?>
                                <i data-preview-brand-kicker><?= e((string) ($brandValues['name'] ?: __('onboarding.preview.your_brand'))) ?></i>
                            </span>
                            <h2><span data-preview-brand-name><?= e((string) ($brandValues['name'] ?: __('onboarding.preview.your_brand'))) ?></span> <?= e(__('onboarding.preview.in_action')) ?></h2>
                            <p><?= e(__('onboarding.preview.text')) ?></p>
                            <div><button type="button"><?= e(__('onboarding.preview.cta1')) ?></button><button type="button"><?= e(__('onboarding.preview.cta2')) ?></button></div>
                            <hr>
                            <article><b></b><strong><?= e(__('onboarding.preview.card_title')) ?></strong><small><?= e(__('onboarding.preview.card_text')) ?></small></article>
                        </aside>
                    </div>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action']) ?>
                </form>
            <?php elseif ($step === 3): ?>
                <form method="POST" action="<?= e(base_url('admin/onboarding/step/3')) ?>" class="pp-onboarding-form" data-onboarding-form data-ai-form>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <div class="pp-onboarding-ai-choice">
                        <?php foreach ($aiModels as $modelId => $model): ?>
                            <label class="pp-onboarding-ai-card">
                                <input type="radio" name="ai_model_choice" value="<?= e($modelId) ?>" <?= (($aiValues['model'] ?? '') === $modelId || (($aiValues['model'] ?? '') === '' && $modelId === 'google/gemini-3.7-flash')) ? 'checked' : '' ?>>
                                <span>
                                    <small><?= e((string) $model['badge']) ?></small>
                                    <strong><?= e((string) $model['name']) ?></strong>
                                    <em><?= e((string) $model['summary']) ?></em>
                                    <code><?= e($modelId) ?></code>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="pp-onboarding-ai-note">
                        <?= e(__('onboarding.ai.note')) ?>
                    </p>
                    <details class="pp-onboarding-advanced-models" <?= empty($aiValues['is_recommended']) ? 'open' : '' ?>>
                        <summary><?= e(__('onboarding.ai.more_models')) ?></summary>
                        <label class="pp-onboarding-ai-card pp-onboarding-ai-card--advanced">
                            <input type="radio" name="ai_model_choice" value="advanced" <?= empty($aiValues['is_recommended']) ? 'checked' : '' ?>>
                            <span>
                                <small><?= e(__('onboarding.ai.advanced')) ?></small>
                                <strong><?= e(__('onboarding.ai.other_model')) ?></strong>
                                <em><?= e(__('onboarding.ai.other_model_help')) ?></em>
                            </span>
                        </label>
                        <div class="pp-onboarding-advanced-grid">
                            <label class="pp-onboarding-field">
                                <span><?= e(__('onboarding.ai.main_model')) ?></span>
                                <input type="text" name="ai_model_advanced" value="<?= e((string) ($aiValues['model'] ?? 'google/gemini-3.7-flash')) ?>" maxlength="100" placeholder="google/gemini-3.7-flash">
                            </label>
                            <label class="pp-onboarding-field">
                                <span><?= e(__('onboarding.ai.light_model')) ?></span>
                                <input type="text" name="ai_model_light_advanced" value="<?= e((string) ($aiValues['model_light'] ?? 'google/gemini-3.1-flash-lite')) ?>" maxlength="100" placeholder="google/gemini-3.1-flash-lite">
                            </label>
                        </div>
                    </details>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action']) ?>
                </form>
            <?php elseif ($step === 4): ?>
                <form method="POST" enctype="multipart/form-data" action="<?= e(base_url('admin/onboarding/step/4')) ?>" class="pp-onboarding-form" data-onboarding-form>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                    <?php // ONB-FOTOS — Fotos reales del negocio. Sin ellas la web se
                          // genera con banco de imágenes; con ellas, la generación las
                          // prefiere. Se suben de una en una por AJAX y se describen con
                          // IA antes de dejar avanzar: sin descripción, la foto llega al
                          // modelo como un nombre de archivo y acaba descartada. ?>
                    <section class="pp-onboarding-photos" data-photos
                             data-max="<?= (int) $photosMax ?>"
                             data-upload-url="<?= e(base_url('admin/onboarding/upload-photo')) ?>"
                             data-alt-url="<?= e(base_url('admin/onboarding/photo-alt')) ?>"
                             data-delete-url="<?= e(base_url('admin/onboarding/photo-delete')) ?>"
                             data-describe-url="<?= e(base_url('admin/media/describe-missing')) ?>">
                        <header class="pp-onboarding-photos__head">
                            <h2><?= e(__('onboarding.photos.title')) ?></h2>
                            <p><?= __('onboarding.photos.intro.html') ?></p>
                        </header>

                        <label class="pp-onboarding-dropzone pp-onboarding-dropzone--photos" data-photos-dropzone>
                            <input type="file" name="business_photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-photos-input>
                            <span></span>
                            <strong><?= e(__('onboarding.photos.dropzone')) ?></strong>
                            <small><?= e(__('onboarding.photos.formats', ['max' => (int) $photosMax])) ?></small>
                        </label>

                        <p class="pp-onboarding-photos__status" data-photos-status <?= empty($businessPhotos) ? '' : 'hidden' ?>>
                            <?= e(__('onboarding.photos.none_note')) ?>
                        </p>

                        <ul class="pp-onboarding-photos__grid" data-photos-grid<?= empty($businessPhotos) ? ' hidden' : '' ?>>
                            <?php foreach ($businessPhotos as $photo): ?>
                                <li class="pp-onboarding-photo" data-photo-id="<?= (int) $photo['id'] ?>">
                                    <div class="pp-onboarding-photo__thumb">
                                        <img src="<?= e((string) $photo['url']) ?>" alt="">
                                        <button type="button" class="pp-onboarding-photo__remove" data-photo-remove aria-label="<?= e(__('onboarding.photos.remove_aria')) ?>">×</button>
                                    </div>
                                    <textarea class="pp-onboarding-photo__alt" rows="3" data-photo-alt
                                              placeholder="<?= e(__('onboarding.photos.no_alt')) ?>"><?= e((string) $photo['alt_text']) ?></textarea>
                                    <small data-photo-state><?= e($photo['alt_text'] === '' ? __('onboarding.photos.undescribed') : __('onboarding.photos.described')) ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <h2 class="pp-onboarding-photos__divider"><?= e(__('onboarding.docs.title')) ?></h2>
                    <p class="pp-onboarding-photos__divider-hint"><?= e(__('onboarding.docs.hint')) ?></p>

                    <?php if (!empty($documents)): ?>
                        <section class="pp-onboarding-doc-current">
                            <strong><?= e(__('onboarding.docs.already')) ?></strong>
                            <p><?= e(__('onboarding.docs.already_help')) ?></p>
                            <ul>
                                <?php foreach (array_slice($documents, 0, 5) as $doc): ?>
                                    <li><em><?= e((string) $doc['original_filename']) ?></em> · <?= e((string) $doc['status']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endif; ?>
                    <label class="pp-onboarding-dropzone" data-dropzone>
                        <input type="file" name="files[]" accept=".pdf,.docx,.txt" multiple>
                        <span></span>
                        <strong><?= e(!empty($documents) ? __('onboarding.docs.add_more') : __('onboarding.docs.dropzone')) ?></strong>
                        <small><?= e(__('onboarding.docs.formats')) ?></small>
                        <p data-file-state><?php if ($document): ?><?= e(__('onboarding.docs.last')) ?>: <?= e((string) $document['original_filename']) ?> · <?= e((string) $document['status']) ?><?php endif; ?></p>
                    </label>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action'], __('onboarding.skip_step')) ?>
                </form>
            <?php else: ?>
                <div class="pp-onboarding-architecture" data-architecture-step data-intent-saved="<?= e($savedIntent ?? '') ?>">
                    <!-- F22.T22.1 — Selector de intent (qué quiere conseguir el usuario) -->
                    <div class="pp-onboarding-intent" data-intent-picker>
                        <header class="pp-onboarding-intent__head">
                            <span class="pp-onboarding-intent__eyebrow"><?= e(__('onboarding.intent.eyebrow')) ?></span>
                            <h2 class="pp-onboarding-intent__title"><?= e(__('onboarding.intent.title')) ?></h2>
                            <p class="pp-onboarding-intent__desc"><?= e(__('onboarding.intent.desc')) ?></p>
                        </header>
                        <ul class="pp-onboarding-intent__grid" role="radiogroup" aria-label="<?= e(__('onboarding.intent.aria')) ?>">
                            <?php
                            // Los slugs son valores que se guardan y viajan a la IA: no se traducen.
                            $intents = [
                                'presence'  => ['emoji' => '🪧', 'title' => __('onboarding.intent.presence.title'),  'desc' => __('onboarding.intent.presence.desc')],
                                'services'  => ['emoji' => '🤝', 'title' => __('onboarding.intent.services.title'),  'desc' => __('onboarding.intent.services.desc')],
                                'seo'       => ['emoji' => '🔍', 'title' => __('onboarding.intent.seo.title'),       'desc' => __('onboarding.intent.seo.desc')],
                                'portfolio' => ['emoji' => '🎨', 'title' => __('onboarding.intent.portfolio.title'), 'desc' => __('onboarding.intent.portfolio.desc')],
                                'product'   => ['emoji' => '🚀', 'title' => __('onboarding.intent.product.title'),   'desc' => __('onboarding.intent.product.desc')],
                            ];
                            foreach ($intents as $slug => $cfg): ?>
                                <li>
                                    <label class="pp-onboarding-intent-card" data-intent="<?= e($slug) ?>">
                                        <input type="radio" name="intent" value="<?= e($slug) ?>">
                                        <span class="pp-onboarding-intent-card__emoji" aria-hidden="true"><?= $cfg['emoji'] ?></span>
                                        <span class="pp-onboarding-intent-card__body">
                                            <strong><?= e($cfg['title']) ?></strong>
                                            <em><?= e($cfg['desc']) ?></em>
                                        </span>
                                        <span class="pp-onboarding-intent-card__check" aria-hidden="true"></span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="pp-onboarding-intent__actions">
                            <button type="button" class="pp-btn pp-btn--secondary" data-intent-skip><?= e(__('onboarding.intent.skip')) ?></button>
                            <button type="button" class="pp-btn pp-btn--primary" data-intent-go disabled><?= e(__('onboarding.intent.go')) ?> →</button>
                        </div>
                    </div>

                    <div class="pp-onboarding-arch-loading" data-arch-loading hidden>
                        <div><span></span><span></span><span></span></div>
                        <p data-loading-msg><?= e(__('onboarding.arch.loading')) ?></p>
                    </div>
                    <div data-arch-result hidden></div>
                    <div data-arch-error hidden>
                        <p><?= e(__('onboarding.arch.error')) ?></p>
                        <form method="POST" action="<?= e(base_url('admin/onboarding/skip')) ?>">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="step" value="5">
                            <button class="pp-btn pp-btn--primary" type="submit"><?= e(__('onboarding.arch.empty_map')) ?></button>
                        </form>
                    </div>
                    <?php // ONB-REV T1 — oculto hasta que se pinta la propuesta; si no, duplica los CTAs del picker de intent. ?>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action'], __('onboarding.skip'), true) ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php
function onboarding_footer(int $step, string $csrf, string $action, ?string $skip = null, bool $hidden = false): string
{
    $skip ??= __('onboarding.skip');
    ob_start(); ?>
    <footer class="pp-onboarding-footer" data-onboarding-footer <?= $hidden ? 'hidden' : '' ?>>
        <?php if ($step > 1): ?><a href="<?= e(base_url('admin/onboarding?step=' . ($step - 1))) ?>">← <?= e(__('common.back')) ?></a><?php else: ?><span></span><?php endif; ?>
        <?php if ($step < 5): ?>
            <input type="hidden" name="step" value="<?= (int) $step ?>">
            <button type="submit" class="pp-onboarding-skip" formmethod="POST" formaction="<?= e(base_url('admin/onboarding/skip')) ?>"><?= e($skip) ?></button>
        <?php else: ?>
            <form method="POST" action="<?= e(base_url('admin/onboarding/skip')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="step" value="5">
                <button type="submit" class="pp-onboarding-skip"><?= e($skip) ?></button>
            </form>
        <?php endif; ?>
        <button type="<?= $step === 5 ? 'button' : 'submit' ?>" class="pp-btn pp-btn--primary" data-next-button><?= e($action) ?> →</button>
    </footer>
    <?php return ob_get_clean();
}

/**
 * ONB2 O2.5 — Tarjeta de paleta. Se pinta igual desde PHP (la guardada) que
 * desde JS (las que propone la IA); si cambia el marcado, cambian los dos.
 *
 * @param array<string,string> $tokens
 */
function palette_card(string $name, string $rationale, array $tokens, bool $checked = false): string
{
    $order = ['bg', 'surface', 'text', 'accent', 'accent_2'];
    ob_start(); ?>
    <label class="pp-onboarding-palette-card">
        <input type="radio" name="palette_choice" <?= $checked ? 'checked' : '' ?>
               data-palette-tokens="<?= e(json_encode($tokens, JSON_UNESCAPED_SLASHES)) ?>">
        <span>
            <strong><?= e($name) ?></strong>
            <i>
                <?php foreach ($order as $key): ?><b style="background:<?= e((string) ($tokens[$key] ?? '#ffffff')) ?>"></b><?php endforeach; ?>
            </i>
            <?php if ($rationale !== ''): ?><em><?= e($rationale) ?></em><?php endif; ?>
        </span>
    </label>
    <?php return ob_get_clean();
}

function design_swatches(string $name, string $label, string $value, array $swatches, string $help = ''): string
{
    $value = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '#ea580c';
    ob_start(); ?>
    <div class="pp-onboarding-field pp-onboarding-swatches">
        <span><?= e($label) ?> <em><?= e(__('common.optional')) ?></em></span>
        <div>
            <?php foreach ($swatches as $color): ?>
                <label style="--swatch: <?= e($color) ?>"><input type="radio" name="<?= e($name) ?>" value="<?= e($color) ?>" <?= strtolower($value) === strtolower($color) ? 'checked' : '' ?>><i></i></label>
            <?php endforeach; ?>
        </div>
        <?php // ONB2 O2.3 — El diálogo nativo de color se sustituye por el picker propio,
              // que se monta sobre este campo HEX (ver admin/assets/js/color-picker.js). ?>
        <div class="pp-onboarding-hex">
            <input type="text" name="<?= e($name) ?>_hex" value="<?= e($value) ?>" maxlength="7" data-color-hex="<?= e($name) ?>" data-pp-color inputmode="text" autocomplete="off" aria-label="<?= e(__('onboarding.color.hex_aria', ['campo' => $label])) ?>">
        </div>
        <?php if ($help !== ''): ?><small><?= e($help) ?></small><?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
?>
