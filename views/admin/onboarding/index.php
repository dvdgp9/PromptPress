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
    1 => ['eyebrow' => 'Paso 1 de 5 · Conoce tu negocio', 'title' => 'Cuéntale a la IA quién eres', 'subtitle' => 'Esta información se usa cada vez que la IA escriba algo: páginas, secciones, SEO. Cuanto más concreto, mejor.', 'action' => 'Siguiente'],
    2 => ['eyebrow' => 'Paso 2 de 5 · Marca y referencias', 'title' => 'Dale dirección visual a la IA', 'subtitle' => 'Sube el logo y capturas de webs que te gusten. Canvas usará esas referencias como inspiración de estructura, ritmo y composición.', 'action' => 'Siguiente'],
    3 => ['eyebrow' => 'Paso 3 de 5 · Modelo IA', 'title' => 'Elige el motor que va a crear tu web', 'subtitle' => 'Te proponemos una selección limitada para empezar bien. Después podrás cambiarlo desde Ajustes · IA.', 'action' => 'Siguiente'],
    // ONB-FOTOS — el paso deja de ser solo documentos: fotos y documentos son la
    // misma pregunta ("¿qué material tienes ya?") y las fotos son lo que evita
    // que la web se genere entera con banco de imágenes.
    4 => ['eyebrow' => 'Paso 4 de 5 · Materiales · opcional', 'title' => '¿Qué material tienes ya?', 'subtitle' => 'Fotos reales de tu negocio y documentos que te describan. Las fotos se usan en las páginas; los documentos, como contexto para escribir con criterio.', 'action' => 'Continuar'],
    5 => ['eyebrow' => 'Paso 5 de 5 · Web inicial', 'title' => 'Tu web, paso a paso', 'subtitle' => 'Primero elige qué páginas crear. Después verás un preview de tu estilo, hecho a medida desde tus datos.', 'action' => 'Continuar al estilo'],
];
$groups = [
    'Esencial' => ['business_description', 'target_audience', 'tone_of_voice'],
    'Sobre lo que ofreces' => ['services', 'value_proposition', 'unique_selling_points'],
    'Para SEO y contacto' => ['keywords', 'contact_info'],
];
?>

<?php \Core\View::start('title'); ?>Onboarding<?php \Core\View::end(); ?>
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
            <button type="submit">Salir al panel →</button>
        </form>
    </header>

    <main class="pp-onboarding-shell">
        <nav class="pp-onboarding-progress" aria-label="Progreso del onboarding">
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
                            <span>Entrada rápida</span>
                            <h2>¿Ya tienes documentos del negocio?</h2>
                            <p>Sube uno o varios PDF, DOCX o TXT y la IA intentará rellenar estos campos cruzando la información. Después podrás revisar todo antes de continuar.</p>
                        </div>
                        <label>
                            <input type="file" name="dossier[]" accept=".pdf,.docx,.txt" multiple data-memory-autofill-file>
                            <strong data-memory-autofill-file-label>Elegir documentos</strong>
                            <small>PDF, DOCX o TXT. Puedes seleccionar varios · 10 MB por archivo.</small>
                        </label>
                        <button type="button" class="pp-btn pp-btn--secondary" data-memory-autofill-button>Rellenar con IA</button>
                        <p data-memory-autofill-status></p>
                    </section>
                    <?php foreach ($groups as $groupLabel => $keys): ?>
                        <?php $isSeo = $groupLabel === 'Para SEO y contacto'; ?>
                        <<?= $isSeo ? 'details' : 'div' ?> class="pp-onboarding-fieldset" <?= $isSeo ? '' : '' ?>>
                            <?php if ($isSeo): ?><summary><?= e($groupLabel) ?></summary><?php else: ?><h2><?= e($groupLabel) ?></h2><?php endif; ?>
                            <?php foreach ($keys as $key): $field = $memoryFields[$key]; ?>
                                <label class="pp-onboarding-field" data-field-key="<?= e($key) ?>">
                                    <span>
                                        <?= e($field['label']) ?>
                                        <?php if ($key === 'business_description'): ?><em>* recomendado</em><?php else: ?><em>opcional</em><?php endif; ?>
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
                                    <small><?= e($key === 'tone_of_voice' ? 'Esto define cómo va a sonar tu marca en cada texto.' : (string) ($field['help'] ?? '')) ?></small>
                                    <?php if ($key === 'business_description'): ?>
                                        <details class="pp-onboarding-example">
                                            <summary>Ver un ejemplo</summary>
                                            <p>Somos un estudio de diseño web para clínicas dentales que necesitan atraer pacientes sin depender de plantillas genéricas. Creamos páginas claras, rápidas y orientadas a reservar cita, con una comunicación cercana y profesional.</p>
                                        </details>
                                        <p class="pp-onboarding-warning" data-business-warning hidden>Con más detalle la IA acertará más. Suma alguna frase si puedes.</p>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </<?= $isSeo ? 'details' : 'div' ?>>
                    <?php endforeach; ?>

                    <!-- E-GDPR G6 — Datos legales opcionales (panel desplegable) -->
                    <details class="pp-onboarding-fieldset pp-onboarding-legal">
                        <summary>
                            <span>Datos legales · opcional</span>
                            <em>Te ahorra trabajo después</em>
                        </summary>
                        <p class="pp-onboarding-legal__intro">Si los rellenas ahora, PromptPress podrá generar tu política de privacidad y aviso legal con un clic. Los puedes completar más tarde desde el panel.</p>
                        <label class="pp-onboarding-field">
                            <span>Razón social / nombre <em>opcional</em></span>
                            <input type="text" name="legal_name" maxlength="255" placeholder="Mi Empresa SL · Juan García López">
                            <small>El nombre legal con el que facturas. Si eres autónomo, tu nombre completo.</small>
                        </label>
                        <label class="pp-onboarding-field">
                            <span>NIF / CIF / NIE <em>opcional</em></span>
                            <input type="text" name="legal_tax_id" maxlength="20" placeholder="B12345678">
                        </label>
                        <label class="pp-onboarding-field">
                            <span>Dirección completa <em>opcional</em></span>
                            <input type="text" name="legal_address" maxlength="500" placeholder="Calle, número, código postal, ciudad">
                        </label>
                        <label class="pp-onboarding-field">
                            <span>Email de contacto legal <em>opcional</em></span>
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
                                <h2>Tu marca</h2>
                                <label class="pp-onboarding-field">
                                    <span>Nombre de la empresa <em>recomendado</em></span>
                                    <input type="text" name="site_name" value="<?= e((string) ($brandValues['name'] ?? '')) ?>" maxlength="255" data-brand-name>
                                    <small>Lo usaremos en encabezados, SEO y llamadas a la acción.</small>
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
                                                <strong><?= e((string) $cfg['label']) ?></strong>
                                                <small>PNG, JPG, WEBP o SVG. Hasta 2 MB.</small>
                                                <em data-logo-state><?= $has ? 'Cargado. Puedes sustituirlo.' : 'Sin subir.' ?></em>
                                            </label>
                                            <label class="pp-onboarding-logo-primary">
                                                <input type="radio" name="logo_primary" value="<?= e($variant) ?>"
                                                       <?= (($brandValues['logo_primary'] ?? 'light') === $variant) ? 'checked' : '' ?>>
                                                <span>Usar esta por defecto</span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="pp-onboarding-logos__hint">Si solo tienes una, sube esa: la usaremos en los dos sitios. La versión para fondos oscuros es la que va en el pie y en las secciones en negativo.</p>
                            </section>

                            <section class="pp-onboarding-block">
                                <h2>Inspiración</h2>
                                <label class="pp-onboarding-reference-field pp-onboarding-reference-field--hero" data-reference-dropzone>
                                    <input type="file" name="visual_references[]" accept="image/png,image/jpeg,image/webp" multiple>
                                    <span aria-hidden="true"></span>
                                    <strong>Inspiración visual para Canvas</strong>
                                    <small>Sube capturas de webs que te gusten. La IA tomará como referencia su estructura, ritmo y composición, siempre adaptadas a tu marca.</small>
                                    <em data-reference-state>
                                        <?php if (($referenceValues['count'] ?? 0) > 0): ?>
                                            <?= (int) $referenceValues['count'] ?> referencia<?= (int) $referenceValues['count'] === 1 ? '' : 's' ?> guardada<?= (int) $referenceValues['count'] === 1 ? '' : 's' ?>. Puedes sustituirlas.
                                        <?php else: ?>
                                            PNG, JPG o WebP. Hasta 4 imágenes · 8 MB cada una.
                                        <?php endif; ?>
                                    </em>
                                </label>
                            </section>

                            <section class="pp-onboarding-block">
                                <h2>Color</h2>
                                <?php // ONB2 O2.4 — Los colores de la marca del usuario: la materia prima
                                      // con la que se deriva después la paleta de la web. ?>
                                <div class="pp-onboarding-brandpalette" data-brand-palette
                                     data-max="<?= (int) \App\Controllers\Admin\OnboardingController::BRAND_PALETTE_MAX ?>">
                                    <span>Los colores de tu marca <em>opcional</em></span>
                                    <p>Si tienes manual de marca, ponlos aquí. Nos sirven para derivar la paleta de la web sin inventarnos tu identidad.</p>
                                    <div class="pp-onboarding-brandpalette__list" data-brand-palette-list>
                                        <?php foreach ((array) ($brandValues['brand_palette'] ?? []) as $hex): ?>
                                            <div class="pp-onboarding-brandpalette__item">
                                                <input type="text" name="brand_palette[]" value="<?= e((string) $hex) ?>" maxlength="7" data-pp-color aria-label="Color de marca">
                                                <button type="button" data-brand-palette-remove aria-label="Quitar este color">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="pp-onboarding-brandpalette__actions">
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-palette-add>+ Añadir color</button>
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-brand-palette-extract>Extraer del logo</button>
                                        <small data-brand-palette-status></small>
                                    </div>
                                </div>
                                <?= design_swatches('primary_color', 'Color principal', (string) $designValues['primary_color'], $swatches) ?>
                                <?php // ONB2 O2.5 — Las paletas las propone la IA a partir de los colores
                                      // de marca, y el contraste lo garantiza el servidor. Aquí ya no se
                                      // elige un preset del catálogo ni un "color de texto" suelto: el
                                      // texto, los fondos y las líneas los decide la paleta. ?>
                                <div class="pp-onboarding-field pp-onboarding-palette-field" data-palette-field>
                                    <span>Paleta de la web <em>la deriva la IA de tus colores</em></span>
                                    <div class="pp-onboarding-palette-grid" data-palette-grid>
                                        <?php if (!empty($currentPalette)): ?>
                                            <?= palette_card('Tu paleta actual', 'La que ya tiene guardada este sitio.', (array) $currentPalette, true) ?>
                                        <?php endif; ?>
                                    </div>
                                    <p class="pp-onboarding-palette-empty" data-palette-empty <?= !empty($currentPalette) ? 'hidden' : '' ?>>
                                        Todavía no hay paleta. Pulsa el botón y te proponemos tres, con los contrastes ya comprobados.
                                    </p>
                                    <div class="pp-onboarding-palette-actions">
                                        <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-palette-generate>Generar paletas con IA</button>
                                        <small data-palette-status></small>
                                    </div>
                                    <input type="hidden" name="palette_custom" value="<?= e(!empty($currentPalette) ? json_encode($currentPalette) : '') ?>" data-palette-value>
                                    <small>Fondos, texto, texto secundario, líneas y acentos salen de aquí. El color principal solo es el punto de partida.</small>
                                </div>
                            </section>

                            <section class="pp-onboarding-block pp-onboarding-block--duo">
                                <h2>Tipografía y forma</h2>
                                <label class="pp-onboarding-field">
                                    <span>Tipografía <em>opcional</em></span>
                                    <select name="typography_pair" data-preview-font>
                                        <?php foreach ($typographyOptions as $value => $opt): ?>
                                            <option value="<?= e($value) ?>" data-heading="<?= e((string) $opt['heading']) ?>" data-body="<?= e((string) $opt['body']) ?>" <?= $designValues['typography_pair'] === $value ? 'selected' : '' ?>><?= e($value . ' — ' . $opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="pp-onboarding-field">
                                    <span>Esquinas <em>opcional</em></span>
                                    <div class="pp-onboarding-radius-control">
                                        <input type="range" name="border_radius" min="0" max="60" step="1" value="<?= e((string) $designValues['border_radius']) ?>" data-radius-range>
                                        <div><span>Rectas</span><strong data-radius-label><?= e((string) $designValues['border_radius']) ?> px</strong><span>Redondas</span></div>
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
                                    'heading' => ['label' => 'Para los títulos', 'placeholder' => 'Ej. Helvetica Now Display'],
                                    'body'    => ['label' => 'Para los textos',  'placeholder' => 'Ej. Inter'],
                                ];
                                ?>
                                <details class="pp-onboarding-fonts" <?= $ownFonts !== [] ? 'open' : '' ?>>
                                    <summary>
                                        <strong>¿Tu marca tiene sus propias tipografías?</strong>
                                        <small><?= $ownFonts !== [] ? e(implode(' · ', array_map(fn(array $f): string => (string) $f['name'], $ownFonts))) : 'Súbelas y las usaremos en toda la web' ?></small>
                                    </summary>
                                    <div class="pp-onboarding-fonts__body">
                                        <label class="pp-onboarding-fonts__same">
                                            <input type="checkbox" name="custom_font_same" value="1" <?= $sameForBoth ? 'checked' : '' ?> data-fonts-same>
                                            <span>Uso la misma tipografía para títulos y textos</span>
                                        </label>
                                        <div class="pp-onboarding-fonts__slots">
                                            <?php foreach ($fontSlots as $role => $slot):
                                                $current = $fontByRole[$role] ?? null;
                                                $files = (array) ($current['files'] ?? []);
                                            ?>
                                                <div class="pp-onboarding-fonts__slot" data-font-slot="<?= e($role) ?>">
                                                    <strong><?= e($slot['label']) ?></strong>
                                                    <label class="pp-onboarding-field">
                                                        <span>Nombre <em>opcional</em></span>
                                                        <input type="text" name="custom_font_name[<?= e($role) ?>]" maxlength="120"
                                                               placeholder="<?= e($slot['placeholder']) ?>"
                                                               value="<?= e((string) ($current['name'] ?? '')) ?>">
                                                    </label>
                                                    <label class="pp-onboarding-fonts__file">
                                                        <input type="file" name="custom_fonts_<?= e($role) ?>[]" accept=".woff2,.woff,.ttf,.otf" multiple data-onboarding-fonts>
                                                        <span aria-hidden="true"></span>
                                                        <strong>Subir archivos</strong>
                                                        <small>WOFF2, WOFF, TTF u OTF · hasta 3 MB. Un archivo por peso (Regular, Bold…): detectamos el peso por el nombre.</small>
                                                        <em data-onboarding-fonts-state>
                                                            <?php if ($files !== []): ?>
                                                                <?= count($files) ?> archivo<?= count($files) === 1 ? '' : 's' ?> guardado<?= count($files) === 1 ? '' : 's' ?>. Puedes añadir más.
                                                            <?php else: ?>
                                                                Ningún archivo seleccionado.
                                                            <?php endif; ?>
                                                        </em>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="pp-onboarding-fonts__legal">Necesitas licencia de uso web (webfont) para los archivos que subas. Si prefieres, puedes hacerlo luego desde Diseño.</p>
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
                                <i data-preview-brand-kicker><?= e((string) ($brandValues['name'] ?: 'Tu marca')) ?></i>
                            </span>
                            <h2><span data-preview-brand-name><?= e((string) ($brandValues['name'] ?: 'Tu marca')) ?></span> en acción</h2>
                            <p>Una página clara, con una llamada a la acción visible y una tarjeta de confianza para que el visitante sepa qué hacer después.</p>
                            <div><button type="button">Pedir información</button><button type="button">Ver servicios</button></div>
                            <hr>
                            <article><b></b><strong>Mensaje consistente</strong><small>La IA usa esta identidad como punto de partida.</small></article>
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
                                <input type="radio" name="ai_model_choice" value="<?= e($modelId) ?>" <?= (($aiValues['model'] ?? '') === $modelId || (($aiValues['model'] ?? '') === '' && $modelId === 'google/gemini-3-flash-preview')) ? 'checked' : '' ?>>
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
                        Recomendación inicial: Gemini 3 Flash para crear páginas. Gemini 3.5 Flash si prefieres calidad extra. Para tareas pequeñas usaremos Gemini 3.1 Flash Lite.
                    </p>
                    <details class="pp-onboarding-advanced-models" <?= empty($aiValues['is_recommended']) ? 'open' : '' ?>>
                        <summary>Más modelos</summary>
                        <label class="pp-onboarding-ai-card pp-onboarding-ai-card--advanced">
                            <input type="radio" name="ai_model_choice" value="advanced" <?= empty($aiValues['is_recommended']) ? 'checked' : '' ?>>
                            <span>
                                <small>Avanzado</small>
                                <strong>Usar otro modelo de OpenRouter</strong>
                                <em>Solo si ya sabes qué ID quieres probar. Puedes cambiarlo luego en Ajustes · IA.</em>
                            </span>
                        </label>
                        <div class="pp-onboarding-advanced-grid">
                            <label class="pp-onboarding-field">
                                <span>Modelo principal</span>
                                <input type="text" name="ai_model_advanced" value="<?= e((string) ($aiValues['model'] ?? 'google/gemini-3-flash-preview')) ?>" maxlength="100" placeholder="google/gemini-3-flash-preview">
                            </label>
                            <label class="pp-onboarding-field">
                                <span>Modelo auxiliar</span>
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
                            <h2>Fotos de tu negocio</h2>
                            <p>Tu local, tu equipo, tu producto, tu trabajo terminado. La IA las mira, las describe y las coloca en las páginas. <strong>No son las capturas de webs del paso 2</strong>: aquello era inspiración de diseño, esto es tu material real.</p>
                        </header>

                        <label class="pp-onboarding-dropzone pp-onboarding-dropzone--photos" data-photos-dropzone>
                            <input type="file" name="business_photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-photos-input>
                            <span></span>
                            <strong>Arrastra tus fotos aquí o haz click para elegir</strong>
                            <small>JPG, PNG, WebP o GIF · hasta 10 MB cada una · máximo <?= (int) $photosMax ?> fotos. Con 4-6 buenas fotos ya cubrimos una web entera.</small>
                        </label>

                        <p class="pp-onboarding-photos__status" data-photos-status <?= empty($businessPhotos) ? '' : 'hidden' ?>>
                            Si no subes ninguna, usaremos un banco de imágenes genérico.
                        </p>

                        <ul class="pp-onboarding-photos__grid" data-photos-grid<?= empty($businessPhotos) ? ' hidden' : '' ?>>
                            <?php foreach ($businessPhotos as $photo): ?>
                                <li class="pp-onboarding-photo" data-photo-id="<?= (int) $photo['id'] ?>">
                                    <div class="pp-onboarding-photo__thumb">
                                        <img src="<?= e((string) $photo['url']) ?>" alt="">
                                        <button type="button" class="pp-onboarding-photo__remove" data-photo-remove aria-label="Quitar esta foto">×</button>
                                    </div>
                                    <textarea class="pp-onboarding-photo__alt" rows="3" data-photo-alt
                                              placeholder="Sin descripción"><?= e((string) $photo['alt_text']) ?></textarea>
                                    <small data-photo-state><?= $photo['alt_text'] === '' ? 'Sin describir' : 'Descrita' ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <h2 class="pp-onboarding-photos__divider">Documentos que te describan</h2>
                    <p class="pp-onboarding-photos__divider-hint">Brochures, plan de negocio, catálogo, tarifas o dosieres. La IA los usa como contexto extra al escribir.</p>

                    <?php if (!empty($documents)): ?>
                        <section class="pp-onboarding-doc-current">
                            <strong>Documentos ya cargados</strong>
                            <p>La IA usará estos documentos como contexto cuando genere la web. Puedes mantenerlos y añadir más.</p>
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
                        <strong><?= !empty($documents) ? 'Añadir más documentos' : 'Arrastra archivos aquí o haz click para elegir' ?></strong>
                        <small>PDF, DOCX o TXT. Puedes seleccionar varios · 10 MB por archivo.</small>
                        <p data-file-state><?php if ($document): ?>Último: <?= e((string) $document['original_filename']) ?> · <?= e((string) $document['status']) ?><?php endif; ?></p>
                    </label>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action'], 'Saltar este paso') ?>
                </form>
            <?php else: ?>
                <div class="pp-onboarding-architecture" data-architecture-step data-intent-saved="<?= e($savedIntent ?? '') ?>">
                    <!-- F22.T22.1 — Selector de intent (qué quiere conseguir el usuario) -->
                    <div class="pp-onboarding-intent" data-intent-picker>
                        <header class="pp-onboarding-intent__head">
                            <span class="pp-onboarding-intent__eyebrow">Antes de proponerte una arquitectura</span>
                            <h2 class="pp-onboarding-intent__title">¿Qué quieres conseguir con tu web?</h2>
                            <p class="pp-onboarding-intent__desc">Elige el objetivo principal. Adaptaremos la propuesta de páginas y, si te interesa el SEO orgánico, también te dejaremos preparado un blog con entradas iniciales.</p>
                        </header>
                        <ul class="pp-onboarding-intent__grid" role="radiogroup" aria-label="Objetivo del sitio">
                            <?php
                            $intents = [
                                'presence'  => ['emoji' => '🪧', 'title' => 'Presencia mínima',           'desc' => 'Aparecer online con lo básico: una página principal y un contacto. Ideal si solo necesitas existir.'],
                                'services'  => ['emoji' => '🤝', 'title' => 'Captar clientes (servicios)', 'desc' => 'Explicar lo que ofreces, generar confianza y abrir conversaciones. La opción más común para PYMES.'],
                                'seo'       => ['emoji' => '🔍', 'title' => 'Aparecer en Google (SEO)',     'desc' => 'Atraer tráfico orgánico con contenido. Te montamos blog + entradas iniciales para empezar con buen pie.'],
                                'portfolio' => ['emoji' => '🎨', 'title' => 'Mostrar mi trabajo',           'desc' => 'Portfolio o galería de proyectos. Para creativos, fotógrafos, estudios, freelancers de cualquier oficio.'],
                                'product'   => ['emoji' => '🚀', 'title' => 'Lanzar un producto',          'desc' => 'Página de aterrizaje optimizada para conversión. Producto, evento, app o lanzamiento.'],
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
                            <button type="button" class="pp-btn pp-btn--secondary" data-intent-skip>Saltar (sin preferencia)</button>
                            <button type="button" class="pp-btn pp-btn--primary" data-intent-go disabled>Ver mi arquitectura →</button>
                        </div>
                    </div>

                    <div class="pp-onboarding-arch-loading" data-arch-loading hidden>
                        <div><span></span><span></span><span></span></div>
                        <p data-loading-msg>Pensando en la mejor arquitectura para tu negocio…</p>
                    </div>
                    <div data-arch-result hidden></div>
                    <div data-arch-error hidden>
                        <p>No hemos podido analizar tu sitio en este momento. Puedes seguir e iniciar el mapa vacío — la propuesta estará disponible más tarde.</p>
                        <form method="POST" action="<?= e(base_url('admin/onboarding/skip')) ?>">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="step" value="5">
                            <button class="pp-btn pp-btn--primary" type="submit">Empezar desde el mapa vacío</button>
                        </form>
                    </div>
                    <?php // ONB-REV T1 — oculto hasta que se pinta la propuesta; si no, duplica los CTAs del picker de intent. ?>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action'], 'Saltar', true) ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php
function onboarding_footer(int $step, string $csrf, string $action, string $skip = 'Saltar', bool $hidden = false): string
{
    ob_start(); ?>
    <footer class="pp-onboarding-footer" data-onboarding-footer <?= $hidden ? 'hidden' : '' ?>>
        <?php if ($step > 1): ?><a href="<?= e(base_url('admin/onboarding?step=' . ($step - 1))) ?>">← Atrás</a><?php else: ?><span></span><?php endif; ?>
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
        <span><?= e($label) ?> <em>opcional</em></span>
        <div>
            <?php foreach ($swatches as $color): ?>
                <label style="--swatch: <?= e($color) ?>"><input type="radio" name="<?= e($name) ?>" value="<?= e($color) ?>" <?= strtolower($value) === strtolower($color) ? 'checked' : '' ?>><i></i></label>
            <?php endforeach; ?>
        </div>
        <?php // ONB2 O2.3 — El diálogo nativo de color se sustituye por el picker propio,
              // que se monta sobre este campo HEX (ver admin/assets/js/color-picker.js). ?>
        <div class="pp-onboarding-hex">
            <input type="text" name="<?= e($name) ?>_hex" value="<?= e($value) ?>" maxlength="7" data-color-hex="<?= e($name) ?>" data-pp-color inputmode="text" autocomplete="off" aria-label="<?= e($label) ?> en HEX">
        </div>
        <?php if ($help !== ''): ?><small><?= e($help) ?></small><?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
?>
