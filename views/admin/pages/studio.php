<?php
/**
 * AI Page Studio.
 * @var string $csrf
 * @var array $pageTypes
 * @var array $aiMeta
 * @var array $pages
 * @var array $templateCards
 * @var array $visualStyleCards
 * @var string $selectedVisualStyle
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Crear página con IA<?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-studio-mode<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script src="<?= e(base_url('admin/assets/js/page-studio.js')) ?>"></script>
<?php \Core\View::end(); ?>

<section class="pp-page-studio"
         id="pp-page-studio"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>"
         data-ai-configured="<?= !empty($aiMeta['configured']) ? '1' : '0' ?>">

    <div class="pp-page-studio__top">
        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-page-header__back">← Páginas</a>
        <a href="<?= e(base_url('admin/pages/create')) ?>" class="pp-studio-manual-link">
            Editar a mano
        </a>
    </div>

    <header class="pp-page-studio__hero">
        <div class="pp-page-studio__hero-aurora" aria-hidden="true"></div>
        <div class="pp-page-studio__hero-content">
            <span class="pp-page-studio__eyebrow">
                <span class="pp-page-studio__live-dot" aria-hidden="true"></span>
                AI Page Studio
            </span>
            <h2>Describe la página.<br><span class="pp-page-studio__hero-accent">PromptPress hace el resto.</span></h2>
            <p>El estudio analiza tu memoria, documentos y páginas existentes para proponerte qué conviene crear, preparar el plan y generar un borrador completo en segundos.</p>
            <div class="pp-page-studio__model-pill" title="<?= e((string) ($aiMeta['provider'] ?? '') . ' · ' . (($aiMeta['model_light'] ?? '') ?: ($aiMeta['model'] ?? ''))) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/>
                    <path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/>
                </svg>
                <span>Generado con</span>
                <code><?= e((string) (($aiMeta['model_light'] ?? '') ?: ($aiMeta['model'] ?? 'IA'))) ?></code>
            </div>
        </div>
    </header>

    <?php if (empty($aiMeta['configured'])): ?>
    <div class="pp-alert pp-alert--error">
        Configura primero el proveedor de IA en <a href="<?= e(base_url('admin/settings/ai')) ?>">Ajustes IA</a> para usar este flujo.
    </div>
    <?php endif; ?>

    <div class="pp-page-studio__layout">
        <aside class="pp-page-studio__rail" aria-label="Progreso">
            <div class="pp-studio-rail-line" aria-hidden="true"></div>
            <div class="pp-studio-step is-active" data-step-indicator="opportunities">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/></svg>
                </span>
                <div>
                    <strong>Oportunidad</strong>
                    <small>Elegir o describir</small>
                </div>
            </div>
            <div class="pp-studio-step" data-step-indicator="brief">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>
                </span>
                <div>
                    <strong>Plan</strong>
                    <small>Revisar estructura</small>
                </div>
            </div>
            <div class="pp-studio-step" data-step-indicator="generate">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/></svg>
                </span>
                <div>
                    <strong>Borrador</strong>
                    <small>Crear página completa</small>
                </div>
            </div>
        </aside>

        <main class="pp-page-studio__main">
            <section class="pp-studio-mode-switch" role="tablist" aria-label="Modo de creación">
                <button type="button" class="is-active" data-studio-mode="idea" role="tab" aria-selected="true">Desde idea</button>
                <button type="button" data-studio-mode="template" role="tab" aria-selected="false">Desde estilo</button>
                <button type="button" data-studio-mode="reference" role="tab" aria-selected="false">Desde una referencia</button>
            </section>

            <section class="pp-studio-panel" data-studio-mode-panel="reference" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3>Diseña una página a partir de una referencia</h3>
                        <p>Sube la captura de una página que te guste. La IA verá su estructura y creará secciones editables inspiradas en ella, con el contenido escrito para tu negocio.</p>
                    </div>
                </div>

                <form class="pp-studio-template-form pp-studio-reference-form" id="pp-studio-reference-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                    <div class="pp-dropzone" id="pp-reference-dropzone" tabindex="0" role="button"
                         aria-label="Subir capturas de referencia">
                        <input type="file" id="pp-reference-input" name="references[]" accept="image/png,image/jpeg,image/webp" multiple hidden>
                        <div class="pp-dropzone__empty" id="pp-reference-empty">
                            <span class="pp-dropzone__icon" aria-hidden="true">🖼️</span>
                            <strong>Arrastra una captura aquí o haz clic para elegir</strong>
                            <small>PNG, JPG o WebP · hasta 4 imágenes · máx. 8 MB cada una</small>
                        </div>
                        <div class="pp-dropzone__previews" id="pp-reference-previews" hidden></div>
                    </div>

                    <label>Título de tu página<input type="text" name="title" id="pp-reference-title" required maxlength="200" placeholder="Ej. Clínica Dental Sonrisa"></label>
                    <label>Objetivo<textarea name="ai_page_goal" id="pp-reference-goal" rows="2" required placeholder="Ej. captar solicitudes de cita"></textarea></label>
                    <label>Público (opcional)<input type="text" name="ai_target_audience" placeholder="Ej. familias de la zona"></label>
                    <label>Detalles (opcional)<textarea name="ai_extra_context" rows="2" placeholder="Servicios, tono o requisitos que deba respetar la IA…"></textarea></label>

                    <div class="pp-studio-template-form__actions pp-studio-reference-form__actions">
                        <p id="pp-reference-status" class="pp-studio-status" aria-live="polite"></p>
                        <button type="submit" class="pp-btn pp-btn--primary" id="pp-reference-submit" disabled>Diseñar desde la referencia</button>
                    </div>

                    <div class="pp-studio-progress pp-studio-reference-progress" id="pp-reference-progress" aria-live="polite" hidden>
                        <div class="pp-studio-progress__bar"><span></span></div>
                        <ol>
                            <li class="is-active">Analizando tu referencia</li>
                            <li>Traduciendo el estilo a bloques editables</li>
                            <li>Redactando contenido para tu marca</li>
                            <li>Optimizando el SEO</li>
                            <li>Casi listo…</li>
                        </ol>
                    </div>
                </form>
            </section>

            <section class="pp-studio-panel" data-studio-mode-panel="template" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3>Elige dirección visual</h3>
                        <p>Estos estilos se adaptan a tus colores y fuentes. La estructura sigue siendo inteligente, no sectorial.</p>
                    </div>
                </div>
                <div class="pp-studio-template-grid pp-studio-style-grid" id="pp-studio-template-grid">
                    <?php foreach ($visualStyleCards as $i => $style): ?>
                        <?php $isActive = ($style['slug'] ?? '') === ($selectedVisualStyle ?? ''); ?>
                        <article class="pp-studio-template-card pp-studio-style-card<?= $isActive || ($i === 0 && empty($selectedVisualStyle)) ? ' is-active' : '' ?>"
                                 data-template-slug="<?= e($style['template_slug']) ?>"
                                 data-template-label="<?= e($style['label']) ?>"
                                 data-visual-style="<?= e($style['slug']) ?>"
                                 data-template-preview="<?= e($style['preview_url']) ?>">
                            <div class="pp-studio-template-card__preview">
                                <iframe src="<?= e($style['preview_url']) ?>" loading="lazy" title="Preview <?= e($style['label']) ?>"></iframe>
                            </div>
                            <div class="pp-studio-template-card__body">
                                <strong><?= e($style['label']) ?></strong>
                                <p><?= e($style['description']) ?></p>
                                <small><?= e($style['heading_font']) ?> + <?= e($style['body_font']) ?></small>
                                <span class="pp-studio-style-card__swatches" aria-hidden="true">
                                    <i style="background:<?= e($style['palette']['bg'] ?? '#fff') ?>"></i>
                                    <i style="background:<?= e($style['palette']['text'] ?? '#111') ?>"></i>
                                    <i style="background:<?= e($style['palette']['accent'] ?? '#ea580c') ?>"></i>
                                    <i style="background:<?= e($style['palette']['accent_2'] ?? '#f5f5f5') ?>"></i>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form class="pp-studio-template-form" id="pp-studio-template-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="template_slug" id="pp-studio-template-slug" value="<?= e($visualStyleCards[0]['template_slug'] ?? ($templateCards[0]['slug'] ?? '')) ?>">
                    <input type="hidden" name="visual_style" id="pp-studio-visual-style" value="<?= e($selectedVisualStyle ?: ($visualStyleCards[0]['slug'] ?? '')) ?>">
                    <label>Título<input type="text" name="title" id="pp-studio-template-title" required maxlength="200" placeholder="Ej. Diseño web para clínicas dentales"></label>
                    <label>Objetivo<textarea name="goal" id="pp-studio-template-goal" rows="2" required placeholder="Ej. captar solicitudes de presupuesto"></textarea></label>
                    <label>Público (opcional)<input type="text" name="audience" placeholder="Ej. directores de clínicas"></label>
                    <label>Detalles (opcional)<textarea name="details" rows="2" placeholder="Servicios concretos, tono o requisitos que deba respetar la IA…"></textarea></label>
                    <div class="pp-studio-template-form__actions">
                        <p id="pp-studio-template-status" aria-live="polite"></p>
                        <button type="submit" class="pp-btn pp-btn--primary" id="pp-studio-template-submit">Crear con este estilo</button>
                    </div>
                </form>
            </section>

            <div data-studio-mode-panel="idea">
            <section class="pp-studio-panel" data-studio-panel="opportunities">
                <div class="pp-studio-panel__head">
                    <div>
                        <h3>Qué página tiene sentido crear ahora</h3>
                        <p>Las sugerencias salen del contexto real del sitio. También puedes escribir una petición propia.</p>
                    </div>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-studio-refresh">
                        Actualizar sugerencias
                    </button>
                </div>

                <div class="pp-studio-context">
                    <strong><?= count($pages) ?></strong>
                    <span>páginas existentes analizadas</span>
                </div>

                <div class="pp-studio-opportunities" id="pp-studio-opportunities" aria-live="polite">
                    <div class="pp-studio-skeleton">
                        <span></span><span></span><span></span>
                    </div>
                </div>

                <div class="pp-studio-compose">
                    <label for="pp-studio-idea">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>
                        O cuéntame qué página quieres crear
                    </label>
                    <textarea id="pp-studio-idea" rows="4" placeholder="Ej: crea una landing para captar solicitudes de presupuesto del servicio de diseño web para clínicas dentales"></textarea>
                    <div class="pp-studio-compose__foot">
                        <input type="text" id="pp-studio-notes" placeholder="Matiz opcional: más premium, más SEO, incluir precios…">
                        <button type="button" class="pp-btn pp-btn--primary pp-studio-cta" id="pp-studio-brief-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/></svg>
                            Preparar plan
                        </button>
                    </div>
                </div>
            </section>

            <section class="pp-studio-panel" data-studio-panel="brief" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3>Plan de página</h3>
                        <p>Revísalo como un briefing. La generación final usará esta estructura.</p>
                    </div>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-studio-back="opportunities">
                        Cambiar idea
                    </button>
                </div>
                <div id="pp-studio-brief" class="pp-studio-brief" aria-live="polite"></div>
            </section>

            <section class="pp-studio-panel" data-studio-panel="generate" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3>Creando borrador completo</h3>
                        <p>PromptPress generará metadata, SEO, secciones, copy y formularios necesarios.</p>
                    </div>
                </div>
                <div class="pp-studio-progress" id="pp-studio-progress" aria-live="polite">
                    <div class="pp-studio-progress__bar"><span></span></div>
                    <ol>
                        <li class="is-active">Analizando el brief aprobado</li>
                        <li>Preparando estructura final</li>
                        <li>Escribiendo secciones</li>
                        <li>Creando formulario si procede</li>
                        <li>Optimizando SEO</li>
                    </ol>
                </div>
            </section>
            </div>
        </main>
    </div>
</section>
