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
 */
\Core\View::extend('admin/layout');

$stepMeta = [
    1 => ['eyebrow' => 'Paso 1 de 5 · Conoce tu negocio', 'title' => 'Cuéntale a la IA quién eres', 'subtitle' => 'Esta información se usa cada vez que la IA escriba algo: páginas, secciones, SEO. Cuanto más concreto, mejor.', 'action' => 'Siguiente'],
    2 => ['eyebrow' => 'Paso 2 de 5 · Identidad visual', 'title' => 'Cómo se ve tu marca', 'subtitle' => 'Nombre, logo y una base visual sencilla para que las primeras páginas no nazcan genéricas.', 'action' => 'Siguiente'],
    3 => ['eyebrow' => 'Paso 3 de 5 · Modelo IA', 'title' => 'Elige el motor que va a crear tu web', 'subtitle' => 'Te proponemos una selección limitada para empezar bien. Después podrás cambiarlo desde Ajustes · IA.', 'action' => 'Siguiente'],
    4 => ['eyebrow' => 'Paso 4 de 5 · Documento base · opcional', 'title' => '¿Tienes un documento que te describa?', 'subtitle' => 'Brochure, plan de negocio, catálogo… La IA lo lee una vez y lo usa como contexto extra. Si no, sigue sin ello.', 'action' => 'Continuar'],
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
<script src="<?= e(base_url('admin/assets/js/onboarding.js')) ?>"></script>
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

        <section class="pp-onboarding-card">
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
                            <h2>¿Ya tienes un dossier comercial?</h2>
                            <p>Sube un PDF, DOCX o TXT y la IA intentará rellenar estos campos por ti. Después podrás revisar todo antes de continuar.</p>
                        </div>
                        <label>
                            <input type="file" name="dossier" accept=".pdf,.docx,.txt" data-memory-autofill-file>
                            <strong data-memory-autofill-file-label>Elegir dossier</strong>
                            <small>PDF, DOCX o TXT. Hasta 10 MB.</small>
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
                            <label class="pp-onboarding-field">
                                <span>Nombre de la empresa <em>recomendado</em></span>
                                <input type="text" name="site_name" value="<?= e((string) ($brandValues['name'] ?? '')) ?>" maxlength="255" data-brand-name>
                                <small>Lo usaremos en encabezados, SEO y llamadas a la acción.</small>
                            </label>
                            <label class="pp-onboarding-logo-field" data-logo-dropzone>
                                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg">
                                <span>
                                    <?php if (!empty($brandValues['logo_path'])): ?>
                                        <img src="<?= e(base_url((string) $brandValues['logo_path'])) ?>" alt="">
                                    <?php else: ?>
                                        <b></b>
                                    <?php endif; ?>
                                </span>
                                <strong>Logo opcional</strong>
                                <small>PNG, JPG, WEBP o SVG. Hasta 2 MB.</small>
                                <em data-logo-state><?= !empty($brandValues['logo_path']) ? 'Logo actual cargado. Puedes sustituirlo.' : 'No hay logo subido todavía.' ?></em>
                            </label>
                            <label class="pp-onboarding-reference-field" data-reference-dropzone>
                                <input type="file" name="visual_references[]" accept="image/png,image/jpeg,image/webp" multiple>
                                <span aria-hidden="true"></span>
                                <strong>Referencias visuales opcionales</strong>
                                <small>Sube capturas de webs que te gusten. La IA usará su estructura y ritmo, vestidos con tu marca.</small>
                                <em data-reference-state>
                                    <?php if (($referenceValues['count'] ?? 0) > 0): ?>
                                        <?= (int) $referenceValues['count'] ?> referencia<?= (int) $referenceValues['count'] === 1 ? '' : 's' ?> guardada<?= (int) $referenceValues['count'] === 1 ? '' : 's' ?>. Puedes sustituirlas.
                                    <?php else: ?>
                                        PNG, JPG o WebP. Hasta 4 imágenes · 8 MB cada una.
                                    <?php endif; ?>
                                </em>
                            </label>
                            <?= design_swatches('primary_color', 'Color principal', (string) $designValues['primary_color'], $swatches) ?>
                            <div class="pp-onboarding-field pp-onboarding-palette-field" data-palette-field>
                                <span>Paleta generada <em>basada en tu color</em></span>
                                <div class="pp-onboarding-palette-grid">
                                    <?php foreach (($paletteCards ?? []) as $card): ?>
                                        <label class="pp-onboarding-palette-card">
                                            <input type="radio" name="palette_preset" value="<?= e((string) $card['slug']) ?>" <?= (($selectedPalettePreset ?? '') === $card['slug']) ? 'checked' : '' ?>>
                                            <span>
                                                <strong><?= e((string) $card['label']) ?></strong>
                                                <i data-palette-swatches="<?= e((string) $card['slug']) ?>">
                                                    <?php foreach (($card['swatches'] ?? []) as $swatch): ?><b style="background:<?= e((string) $swatch) ?>"></b><?php endforeach; ?>
                                                </i>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <small>El color principal manda; la paleta solo decide fondos, contraste y acentos compatibles.</small>
                            </div>
                            <?= design_swatches('secondary_color', 'Color de texto', (string) $designValues['secondary_color'], $swatches, 'Puedes ajustarlo si quieres un texto más claro u oscuro.') ?>
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
                        </div>
                        <aside class="pp-onboarding-preview" data-design-preview>
                            <span class="pp-onboarding-preview-brand">
                                <?php if (!empty($brandValues['logo_path'])): ?>
                                    <img src="<?= e(base_url((string) $brandValues['logo_path'])) ?>" alt="" data-preview-logo>
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
                        Recomendación inicial: Gemini 3 Flash para crear páginas. Gemini 3.1 Pro si prefieres calidad extra aunque tarde más. Para tareas pequeñas usaremos Gemini Flash Lite.
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
                                <input type="text" name="ai_model_light_advanced" value="<?= e((string) ($aiValues['model_light'] ?? 'google/gemini-3.1-flash-lite-preview')) ?>" maxlength="100" placeholder="google/gemini-3.1-flash-lite-preview">
                            </label>
                        </div>
                    </details>
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action']) ?>
                </form>
            <?php elseif ($step === 4): ?>
                <form method="POST" enctype="multipart/form-data" action="<?= e(base_url('admin/onboarding/step/4')) ?>" class="pp-onboarding-form" data-onboarding-form>
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <?php if ($document): ?>
                        <section class="pp-onboarding-doc-current">
                            <strong>Documento ya cargado</strong>
                            <p>Ya tenemos <em><?= e((string) $document['original_filename']) ?></em> (estado: <?= e((string) $document['status']) ?>). Puedes mantenerlo o sustituirlo ahora.</p>
                        </section>
                    <?php endif; ?>
                    <label class="pp-onboarding-dropzone" data-dropzone>
                        <input type="file" name="file" accept=".pdf,.docx,.txt">
                        <span></span>
                        <strong><?= $document ? 'Sustituir documento base' : 'Arrastra un archivo aquí o haz click para elegir' ?></strong>
                        <small>PDF, DOCX o TXT. Hasta 10 MB.</small>
                        <p data-file-state><?php if ($document): ?><?= e((string) $document['original_filename']) ?> · <?= e((string) $document['status']) ?><?php endif; ?></p>
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
                    <?= onboarding_footer($step, $csrf, $stepMeta[$step]['action']) ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php
function onboarding_footer(int $step, string $csrf, string $action, string $skip = 'Saltar'): string
{
    ob_start(); ?>
    <footer class="pp-onboarding-footer">
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
            <label class="is-custom"><input type="color" name="<?= e($name) ?>_custom" value="<?= e($value) ?>" data-color-custom="<?= e($name) ?>"><i></i><strong>Libre</strong></label>
        </div>
        <div class="pp-onboarding-hex">
            <span>HEX</span>
            <input type="text" name="<?= e($name) ?>_hex" value="<?= e($value) ?>" maxlength="7" data-color-hex="<?= e($name) ?>" inputmode="text" autocomplete="off">
        </div>
        <?php if ($help !== ''): ?><small><?= e($help) ?></small><?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
?>
