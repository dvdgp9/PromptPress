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

<?php \Core\View::start('title'); ?>Diseño<?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<?php if (!empty($googleFonts)):
    $families = implode('&family=', array_map(fn($f) => str_replace(' ', '+', $f) . ':wght@300;400;500;600;700;800;900', $googleFonts));
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=<?= $families ?>&display=swap" rel="stylesheet">
<?php endif; ?>
<script>
window.PP_DESIGN_SCHEMA = <?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PP_DESIGN_FONTS = <?= json_encode(\App\Services\DesignSystem::FONT_OPTIONS, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(base_url('admin/assets/js/design-system.js')) ?>"></script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Diseño del sitio</h2>
    <div class="pp-page-header__actions" style="display:inline-flex; gap:8px;">
        <form method="POST" action="<?= e(base_url('admin/design/regenerate')) ?>"
              onsubmit="return confirm('Esto recalculará tu diseño completo basándose en los datos de tu negocio. ¿Continuar?');"
              style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm" title="Regenera el diseño con IA usando tu memoria del sitio">
                ✨ Regenerar con IA
            </button>
        </form>
        <form method="POST" action="<?= e(base_url('admin/design/reset')) ?>"
              onsubmit="return confirm('¿Restablecer todo a los valores por defecto?');"
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
    Define la identidad visual del sitio. Los cambios se aplican en tiempo real al panel de previsualización
    y se generarán como variables CSS para las páginas públicas.
</p>

<section class="pp-design-logo-card" aria-labelledby="pp-design-logo-title">
    <div class="pp-design-logo-preview">
        <?php if ($logoPath !== ''): ?>
            <img src="<?= e(base_url($logoPath)) ?>" alt="Logo actual">
        <?php else: ?>
            <span>Sin logo</span>
        <?php endif; ?>
    </div>
    <div class="pp-design-logo-content">
        <h3 id="pp-design-logo-title">Logo de la empresa</h3>
        <p>Se utiliza en la cabecera pública y en el panel. PNG, JPG o WebP, hasta 2 MB.</p>
        <form method="POST" action="<?= e(base_url('admin/design/logo')) ?>" enctype="multipart/form-data" class="pp-design-logo-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
            <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= $logoPath !== '' ? 'Sustituir logo' : 'Subir logo' ?></button>
        </form>
        <?php if ($logoPath !== ''): ?>
        <form method="POST" action="<?= e(base_url('admin/design/logo/delete')) ?>" onsubmit="return confirm('¿Eliminar el logo actual?');">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--danger pp-btn--sm">Eliminar logo</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<?php $flashSuccess = \Core\Session::flash('success'); $flashError = \Core\Session::flash('error'); ?>
<?php if ($flashSuccess): ?><div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="pp-alert pp-alert--error"><?= e($flashError) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <strong>Revisa los errores:</strong>
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
            $renderField = function (string $cat, array $f) use ($tokens, $errors, $fontOptions) {
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
                                   aria-label="Hex">
                        </div>

                    <?php elseif ($f['type'] === 'font'): ?>
                        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
                                data-pp-design-input="font">
                            <?php foreach ($fontOptions as $val => $label): ?>
                            <option value="<?= e($val) ?>"
                                    <?= (string) $value === (string) $val ? 'selected' : '' ?>
                                    style="font-family: <?= $val === 'system' ? 'system-ui, sans-serif' : '\'' . e($val) . '\', sans-serif' ?>">
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

                <div class="<?= $cat === 'colors' ? 'pp-color-grid' : 'pp-design-fields' ?>">
                    <?php foreach ($def['fields'] as $f): ?>
                        <?php if (isset($relocateToColors[$cat]) && in_array($f['key'], $relocateToColors[$cat], true)) continue; ?>
                        <?php $renderField($cat, $f); ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($cat === 'colors'): ?>
                <div class="pp-design-shape">
                    <h4 class="pp-design-subhead">Esquinas y forma</h4>
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
                <button type="submit" class="pp-btn pp-btn--primary">
                    <span class="pp-icon pp-icon--check"></span>
                    Guardar diseño
                </button>
            </div>
        </section>

        <!-- Columna derecha: preview sticky -->
        <aside class="pp-design-preview-wrap">
            <div class="pp-design-preview-head">
                <h4>Previsualización</h4>
                <div class="pp-viewport-toggle" role="group" aria-label="Tamaño del viewport">
                    <button type="button" class="pp-vp-btn is-active" data-viewport="desktop" title="Escritorio">
                        <span class="pp-icon pp-icon--desktop"></span>
                    </button>
                    <button type="button" class="pp-vp-btn" data-viewport="tablet" title="Tablet">
                        <span class="pp-icon pp-icon--tablet"></span>
                    </button>
                    <button type="button" class="pp-vp-btn" data-viewport="mobile" title="Móvil">
                        <span class="pp-icon pp-icon--mobile"></span>
                    </button>
                </div>
            </div>

            <div class="pp-design-preview-frame" id="pp-design-preview-frame" data-viewport="desktop">
                <div class="pp-design-preview" id="pp-design-preview" style="<?= $previewInline ?>">
                    <!-- Navbar -->
                    <header class="pp-dp-nav">
                        <div class="pp-dp-nav__brand">Tu Marca</div>
                        <nav class="pp-dp-nav__menu">
                            <a href="#" class="pp-dp-nav__link">Inicio</a>
                            <a href="#" class="pp-dp-nav__link">Servicios</a>
                            <a href="#" class="pp-dp-nav__link">Precios</a>
                            <a href="#" class="pp-dp-nav__link">Contacto</a>
                        </nav>
                        <button type="button" class="pp-dp-btn pp-dp-btn--primary pp-dp-btn--sm">Empezar</button>
                    </header>

                    <!-- Hero -->
                    <section class="pp-dp-hero">
                        <span class="pp-dp-badge">Novedad</span>
                        <h1 class="pp-dp-h1">Un título que convierte visitas en clientes</h1>
                        <p class="pp-dp-lead">
                            Con esta paleta y tipografía se construirán todas las páginas de tu sitio.
                            Ajusta los valores y observa los cambios al instante.
                        </p>
                        <div class="pp-dp-cta-group">
                            <button type="button" class="pp-dp-btn pp-dp-btn--primary">Probar gratis</button>
                            <button type="button" class="pp-dp-btn pp-dp-btn--secondary">Ver demo</button>
                        </div>
                    </section>

                    <!-- Features / benefits -->
                    <section class="pp-dp-section">
                        <div class="pp-dp-section-head">
                            <h2 class="pp-dp-h2">Todo lo que necesitas</h2>
                            <p class="pp-dp-lead pp-dp-lead--center">Tres razones para elegirnos.</p>
                        </div>
                        <div class="pp-dp-cards">
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon">
                                    <span class="pp-icon pp-icon--check"></span>
                                </div>
                                <h3 class="pp-dp-h3">Rápido</h3>
                                <p class="pp-dp-body">Rendimiento optimizado de serie, páginas que cargan al instante.</p>
                            </div>
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon" style="background: var(--pp-accent);">
                                    <span class="pp-icon pp-icon--palette"></span>
                                </div>
                                <h3 class="pp-dp-h3">Personalizable</h3>
                                <p class="pp-dp-body">Controla cada color, fuente y espaciado desde un solo sitio.</p>
                            </div>
                            <div class="pp-dp-card">
                                <div class="pp-dp-card__icon" style="background: var(--pp-success);">
                                    <span class="pp-icon pp-icon--ai"></span>
                                </div>
                                <h3 class="pp-dp-h3">Con IA</h3>
                                <p class="pp-dp-body">Genera contenido con un solo clic respetando tu estilo.</p>
                            </div>
                        </div>
                    </section>

                    <!-- FAQ -->
                    <section class="pp-dp-section pp-dp-section--alt">
                        <div class="pp-dp-section-head">
                            <h2 class="pp-dp-h2">Preguntas frecuentes</h2>
                        </div>
                        <div class="pp-dp-faq">
                            <details>
                                <summary>¿Cómo funciona?</summary>
                                <p class="pp-dp-body">Una explicación breve de cómo se usa el producto, accesible desde cualquier dispositivo.</p>
                            </details>
                            <details>
                                <summary>¿Necesito conocimientos técnicos?</summary>
                                <p class="pp-dp-body">No. Todo se configura desde la interfaz visual.</p>
                            </details>
                            <details>
                                <summary>¿Puedo cancelar cuando quiera?</summary>
                                <p class="pp-dp-body">Sí, sin permanencia ni penalizaciones.</p>
                            </details>
                        </div>
                    </section>

                    <!-- CTA band -->
                    <section class="pp-dp-cta-band">
                        <h2 class="pp-dp-h2 pp-dp-h2--light">¿Listo para empezar?</h2>
                        <p class="pp-dp-lead pp-dp-lead--light">Crea tu primera página en menos de 2 minutos.</p>
                        <button type="button" class="pp-dp-btn pp-dp-btn--on-dark">Crear mi sitio</button>
                    </section>

                    <!-- Mini footer -->
                    <footer class="pp-dp-footer">
                        <div>© 2026 Tu Marca</div>
                        <div class="pp-dp-footer__links">
                            <a href="#" class="pp-dp-link">Aviso legal</a>
                            <a href="#" class="pp-dp-link">Privacidad</a>
                        </div>
                    </footer>
                </div>
            </div>
        </aside>
    </div>

    <?php if (!empty($visualStyleCards)): ?>
    <?php
    $currentCard = null;
    foreach ($visualStyleCards as $c) {
        if (($c['slug'] ?? '') === $visualStyleCurrent) { $currentCard = $c; break; }
    }
    ?>
    <details class="pp-design-advanced" data-visual-styles>
        <summary class="pp-design-advanced__summary">
            <span>
                <strong>Estilo del sitio</strong>
                <small>Avanzado · activo: <?= e($currentCard['label'] ?? $visualStyleCurrent) ?></small>
            </span>
            <span class="pp-design-advanced__chevron" aria-hidden="true">▾</span>
        </summary>

        <div class="pp-design-advanced__body">
            <p class="pp-design-visual__desc">
                Define la <strong>dirección visual base</strong> (tipografía, espacios y ritmo de composición).
                Tiene <strong>efecto pleno en las páginas clásicas por secciones</strong>. En las páginas de
                <strong>Canvas (HTML libre)</strong>, donde el diseño lo genera la IA, su influencia se limita
                sobre todo a las <strong>fuentes por defecto</strong>. Por eso lo dejamos aquí, como ajuste
                secundario: en la mayoría de casos no necesitas cambiarlo.
            </p>

            <ul class="pp-design-visual__grid" role="radiogroup" aria-label="Dirección visual">
                <?php foreach ($visualStyleCards as $card): $slug = (string) $card['slug']; $isActive = $slug === $visualStyleCurrent; ?>
                <li>
                    <label class="pp-design-visual-card<?= $isActive ? ' is-active' : '' ?>" data-style-card="<?= e($slug) ?>">
                        <input type="radio" name="visual_style" value="<?= e($slug) ?>" <?= $isActive ? 'checked' : '' ?>>
                        <span class="pp-design-visual-card__check" aria-hidden="true"></span>
                        <span class="pp-design-visual-card__thumb">
                            <span class="pp-design-visual-card__frame-wrap">
                                <span class="pp-design-visual-card__spacer">
                                    <iframe class="pp-design-visual-card__frame"
                                            data-src="<?= e($card['preview_url']) ?>"
                                            loading="lazy"
                                            title="Preview de <?= e($card['label']) ?>"
                                            sandbox="allow-same-origin"
                                            tabindex="-1"></iframe>
                                </span>
                            </span>
                            <a class="pp-design-visual-card__open"
                               href="<?= e($card['preview_url']) ?>"
                               target="_blank"
                               rel="noopener"
                               title="Abrir preview completo"
                               onclick="event.stopPropagation()">↗</a>
                        </span>
                        <span class="pp-design-visual-card__body">
                            <strong><?= e($card['label']) ?></strong>
                            <em><?= e($card['description']) ?></em>
                            <small><?= e($card['heading_font']) ?> · <?= e($card['body_font']) ?></small>
                        </span>
                    </label>
                </li>
                <?php endforeach; ?>
            </ul>

            <p class="pp-design-visual__hint" data-current-label>Cambia el estilo y pulsa <strong>Guardar diseño</strong> arriba. <strong></strong></p>
        </div>
    </details>
    <?php endif; ?>
</form>

<?php if (!empty($visualStyleCards)): ?>
<script>
(function () {
    const root = document.querySelector('[data-visual-styles]');
    if (!root) return;

    const PREVIEW_WIDTH = 1440;

    function fitCard(wrap) {
        const w = wrap.clientWidth;
        if (w <= 0) return;
        const scale = w / PREVIEW_WIDTH;
        const spacer = wrap.querySelector('.pp-design-visual-card__spacer');
        const frame  = wrap.querySelector('.pp-design-visual-card__frame');
        if (!spacer || !frame) return;
        frame.style.transform = 'scale(' + scale.toFixed(4) + ')';
        let h = 0;
        try {
            const doc = frame.contentDocument || frame.contentWindow?.document;
            if (doc) h = Math.max(doc.body?.scrollHeight || 0, doc.documentElement?.scrollHeight || 0);
        } catch (e) {}
        if (h > 100) {
            frame.style.height = h + 'px';
            spacer.style.height = (h * scale) + 'px';
        } else {
            spacer.style.height = (1800 * scale) + 'px';
        }
    }

    function fitAll() {
        root.querySelectorAll('.pp-design-visual-card__frame-wrap').forEach(fitCard);
    }
    fitAll();
    if ('ResizeObserver' in window) {
        const ro = new ResizeObserver(entries => entries.forEach(e => fitCard(e.target)));
        root.querySelectorAll('.pp-design-visual-card__frame-wrap').forEach(w => ro.observe(w));
    } else {
        window.addEventListener('resize', fitAll);
    }

    function loadFrame(frame) {
        if (!frame.dataset.src) return;
        const wrap = frame.closest('.pp-design-visual-card__frame-wrap');
        frame.addEventListener('load', () => {
            fitCard(wrap);
            setTimeout(() => fitCard(wrap), 700);
            setTimeout(() => fitCard(wrap), 1800);
        });
        frame.src = frame.dataset.src;
        frame.removeAttribute('data-src');
    }
    const frames = root.querySelectorAll('.pp-design-visual-card__frame');
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) { loadFrame(e.target); io.unobserve(e.target); } });
        }, { rootMargin: '300px' });
        frames.forEach(f => io.observe(f));
    } else {
        frames.forEach(loadFrame);
    }

    // Sync visual de la card activa + badge "Activo" cuando el usuario marca otra.
    const labelEl = root.querySelector('[data-current-label] strong');
    root.addEventListener('change', function (e) {
        if (e.target.name !== 'visual_style') return;
        root.querySelectorAll('.pp-design-visual-card').forEach(c => c.classList.remove('is-active'));
        const card = e.target.closest('.pp-design-visual-card');
        if (card) card.classList.add('is-active');
        if (labelEl) {
            const body = card?.querySelector('.pp-design-visual-card__body strong');
            if (body) labelEl.textContent = body.textContent + ' (sin guardar)';
        }
    });
})();
</script>
<?php endif; ?>
