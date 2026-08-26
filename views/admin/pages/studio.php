<?php
/**
 * AI Page Studio.
 * @var string $csrf
 * @var array $pageTypes
 * @var array $aiMeta
 * @var array $pages
 * @var array $seedPages
 * @var array $documents
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('studio.title')) ?><?php \Core\View::end(); ?>
<?php \Core\View::start('bodyClass'); ?>pp-studio-mode<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<?php $jsPath = PP_ROOT . '/admin/assets/js/page-studio.js'; $jsVer = file_exists($jsPath) ? filemtime($jsPath) : (defined('PP_VERSION') ? PP_VERSION : '1'); ?>
<script src="<?= e(base_url('admin/assets/js/page-studio.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>

<section class="pp-page-studio"
         id="pp-page-studio"
         data-csrf="<?= e($csrf) ?>"
         data-base-url="<?= e(base_url('')) ?>"
         data-ai-configured="<?= !empty($aiMeta['configured']) ? '1' : '0' ?>">

    <div class="pp-page-studio__top">
        <a href="<?= e(base_url('admin/pages')) ?>" class="pp-page-header__back">← <?= e(__('nav.pages')) ?></a>
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
            <h2><?= e(__('studio.hero_1')) ?><br><span class="pp-page-studio__hero-accent"><?= e(__('studio.hero_2')) ?></span></h2>
            <p><?= e(__('studio.hero_help')) ?></p>
            <div class="pp-page-studio__model-pill" title="<?= e((string) ($aiMeta['provider'] ?? '') . ' · ' . (($aiMeta['model_light'] ?? '') ?: ($aiMeta['model'] ?? ''))) ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/>
                    <path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/>
                </svg>
                <span><?= e(__('studio.generated_with')) ?></span>
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
        <aside class="pp-page-studio__rail" aria-label="<?= e(__('studio.progress_aria')) ?>">
            <div class="pp-studio-rail-line" aria-hidden="true"></div>
            <div class="pp-studio-step is-active" data-step-indicator="opportunities">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/></svg>
                </span>
                <div>
                    <strong><?= e(__('studio.step_opportunity')) ?></strong>
                    <small><?= e(__('studio.step_opportunity_help')) ?></small>
                </div>
            </div>
            <div class="pp-studio-step" data-step-indicator="brief">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>
                </span>
                <div>
                    <strong><?= e(__('studio.step_plan')) ?></strong>
                    <small><?= e(__('studio.step_plan_help')) ?></small>
                </div>
            </div>
            <div class="pp-studio-step" data-step-indicator="generate">
                <span class="pp-studio-step__dot">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/></svg>
                </span>
                <div>
                    <strong><?= e(__('status.draft')) ?></strong>
                    <small><?= e(__('js.studio.create_full_page')) ?></small>
                </div>
            </div>
        </aside>

        <main class="pp-page-studio__main">
            <section class="pp-studio-mode-switch" role="tablist" aria-label="<?= e(__('studio.mode_aria')) ?>">
                <button type="button" class="is-active" data-studio-mode="idea" role="tab" aria-selected="true"><?= e(__('studio.from_idea')) ?></button>
                <button type="button" data-studio-mode="reference" role="tab" aria-selected="false"><?= e(__('studio.from_reference')) ?></button>
            </section>

            <section class="pp-studio-panel" data-studio-mode-panel="reference" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3><?= e(__('studio.ref_title')) ?></h3>
                        <p><?= e(__('studio.ref_help')) ?></p>
                    </div>
                </div>

                <form class="pp-studio-template-form pp-studio-reference-form" id="pp-studio-reference-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                    <!-- Paso 1 — Contenido -->
                    <fieldset class="pp-ref-step">
                        <legend class="pp-ref-step__head"><span class="pp-ref-step__num">1</span> <?= e(__('studio.your_content')) ?></legend>

                        <div class="pp-ref-grid">
                            <label><?= e(__('studio.page_title')) ?><input type="text" name="title" id="pp-reference-title" required maxlength="200" placeholder="<?= e(__('studio.title_placeholder')) ?>"></label>
                            <label><?= e(__('page_form.page_type')) ?>
                                <select name="page_type" id="pp-reference-type">
                                    <?php foreach (['landing' => __('page_type.landing'), 'service' => __('page_type.service'), 'product' => __('page_type.product'), 'contact' => __('page_type.contact')] as $tv => $tl): ?>
                                        <option value="<?= e($tv) ?>"><?= e($tl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <label><?= e(__('js.studio.goal')) ?><textarea name="ai_page_goal" id="pp-reference-goal" rows="2" required placeholder="<?= e(__('studio.goal_placeholder')) ?>"></textarea></label>

                        <div class="pp-ref-source">
                            <div class="pp-ref-source__tabs" role="tablist" aria-label="<?= e(__('studio.source_aria')) ?>">
                                <button type="button" class="is-active" data-ref-source="write" role="tab" aria-selected="true"><?= e(__('studio.write')) ?></button>
                                <?php if (!empty($documents)): ?>
                                    <button type="button" data-ref-source="doc" role="tab" aria-selected="false"><?= e(__('studio.from_document')) ?></button>
                                <?php endif; ?>
                            </div>

                            <div data-ref-source-panel="write">
                                <label class="pp-ref-source__label"><?= e(__('studio.page_content')) ?>
                                    <textarea name="source_content" id="pp-reference-content" rows="6" placeholder="<?= e(__('studio.content_placeholder')) ?>"></textarea>
                                </label>
                            </div>

                            <?php if (!empty($documents)): ?>
                                <div data-ref-source-panel="doc" hidden>
                                    <label class="pp-ref-source__label"><?= e(__('studio.pick_document')) ?>
                                        <select name="document_id" id="pp-reference-doc">
                                            <option value="">— <?= e(__('studio.select_document')) ?> —</option>
                                            <?php foreach ($documents as $d): ?>
                                                <option value="<?= (int) $d['id'] ?>"><?= e($d['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <p class="pp-ref-hint"><?= e(__('studio.doc_hint')) ?> <a href="<?= e(base_url('admin/documents')) ?>" target="_blank" rel="noopener"><?= e(__('studio.manage_documents')) ?></a></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </fieldset>

                    <!-- Paso 2 — Referencia y base -->
                    <fieldset class="pp-ref-step">
                        <legend class="pp-ref-step__head"><span class="pp-ref-step__num">2</span> <?= e(__('studio.visual_ref')) ?></legend>

                        <div class="pp-dropzone" id="pp-reference-dropzone" tabindex="0" role="button"
                             aria-label="<?= e(__('studio.upload_refs')) ?>">
                            <input type="file" id="pp-reference-input" name="references[]" accept="image/png,image/jpeg,image/webp" multiple hidden>
                            <div class="pp-dropzone__empty" id="pp-reference-empty">
                                <span class="pp-dropzone__icon" aria-hidden="true">🖼️</span>
                                <strong><?= e(__('studio.drop_capture')) ?></strong>
                                <small><?= e(__('studio.optional_if_base')) ?></small>
                                <small><?= e(__('studio.ref_formats')) ?></small>
                            </div>
                            <div class="pp-dropzone__previews" id="pp-reference-previews" hidden></div>
                        </div>

                        <label><?= e(__('studio.base_page')) ?>
                            <select name="seed_page_id" id="pp-reference-seed">
                                <?php if (empty($seedPages)): ?>
                                    <option value=""><?= e(__('studio.brand_style_only_empty')) ?></option>
                                <?php else: ?>
                                    <?php foreach ($seedPages as $sp): ?>
                                        <?php $isHome = ($sp['page_type'] ?? '') === 'home'; $homeSuffix = ($isHome && mb_strtolower(trim((string) $sp['title'])) !== 'inicio') ? ' · Inicio' : ''; ?>
                                        <option value="<?= (int) $sp['id'] ?>"><?= e($sp['title']) ?><?= $homeSuffix ?></option>
                                    <?php endforeach; ?>
                                    <option value=""><?= e(__('studio.brand_style_only')) ?></option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <p class="pp-ref-hint"><?= e(__('studio.ref_hint')) ?></p>

                        <details class="pp-ref-advanced">
                            <summary><?= e(__('studio.advanced')) ?></summary>
                            <label><?= e(__('studio.audience_optional')) ?><input type="text" name="ai_target_audience" placeholder="<?= e(__('studio.audience_placeholder')) ?>"></label>
                            <label><?= e(__('studio.details_optional')) ?><textarea name="ai_extra_context" rows="2" placeholder="<?= e(__('studio.details_placeholder')) ?>"></textarea></label>
                        </details>
                    </fieldset>

                    <div class="pp-studio-template-form__actions pp-studio-reference-form__actions">
                        <p id="pp-reference-status" class="pp-studio-status" aria-live="polite"></p>
                        <button type="submit" class="pp-btn pp-btn--primary" id="pp-reference-submit" disabled><?= e(__('js.studio.generate_page')) ?></button>
                    </div>

                    <div class="pp-studio-progress pp-studio-reference-progress" id="pp-reference-progress" aria-live="polite" hidden>
                        <div class="pp-studio-progress__bar"><span></span></div>
                        <ol>
                            <li class="is-active"><?= e(__('studio.prog_ref_1')) ?></li>
                            <li><?= e(__('studio.prog_ref_2')) ?></li>
                            <li><?= e(__('studio.prog_ref_3')) ?></li>
                            <li><?= e(__('studio.prog_ref_4')) ?></li>
                            <li><?= e(__('studio.prog_almost')) ?></li>
                        </ol>
                    </div>
                </form>
            </section>

            <div data-studio-mode-panel="idea">
            <section class="pp-studio-panel" data-studio-panel="opportunities">
                <div class="pp-studio-panel__head">
                    <div>
                        <h3><?= e(__('studio.what_now')) ?></h3>
                        <p><?= e(__('studio.what_now_help')) ?></p>
                    </div>
                    <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="pp-studio-refresh">
                        <?= e(__('studio.refresh_suggestions')) ?>
                    </button>
                </div>

                <div class="pp-studio-context">
                    <strong><?= count($pages) ?></strong>
                    <span><?= e(__('studio.pages_analyzed')) ?></span>
                </div>

                <div class="pp-studio-opportunities" id="pp-studio-opportunities" aria-live="polite">
                    <div class="pp-studio-skeleton">
                        <span></span><span></span><span></span>
                    </div>
                </div>

                <div class="pp-studio-compose">
                    <label for="pp-studio-idea">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>
                        <?= e(__('studio.or_describe')) ?>
                    </label>
                    <textarea id="pp-studio-idea" rows="4" placeholder="<?= e(__('studio.idea_placeholder')) ?>"></textarea>
                    <div class="pp-studio-compose__foot">
                        <input type="text" id="pp-studio-notes" placeholder="<?= e(__('studio.notes_placeholder')) ?>">
                        <button type="button" class="pp-btn pp-btn--primary pp-studio-cta" id="pp-studio-brief-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 1.8 4.7L18 9.5l-4.2 1.8L12 16l-1.8-4.7L6 9.5l4.2-1.8L12 3z"/></svg>
                            <?= e(__('studio.prepare_plan')) ?>
                        </button>
                    </div>
                </div>
            </section>

            <section class="pp-studio-panel" data-studio-panel="brief" hidden>
                <div class="pp-studio-panel__head">
                    <div>
                        <h3><?= e(__('studio.page_plan')) ?></h3>
                        <p><?= e(__('studio.page_plan_help')) ?></p>
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
                        <h3><?= e(__('studio.creating_draft')) ?></h3>
                        <p><?= e(__('studio.creating_help')) ?></p>
                    </div>
                </div>
                <div class="pp-studio-progress" id="pp-studio-progress" aria-live="polite">
                    <div class="pp-studio-progress__bar"><span></span></div>
                    <ol>
                        <li class="is-active"><?= e(__('studio.prog_1')) ?></li>
                        <li><?= e(__('studio.prog_2')) ?></li>
                        <li><?= e(__('studio.prog_3')) ?></li>
                        <li><?= e(__('studio.prog_4')) ?></li>
                        <li><?= e(__('studio.prog_5')) ?></li>
                    </ol>
                </div>
            </section>
            </div>
        </main>
    </div>
</section>
