<?php
/**
 * T18.6 — Galería de plantillas para crear página con IA.
 *
 * @var array  $cards         lista de plantillas con thumb SVG inline
 * @var bool   $bankAvailable
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Crear página desde plantilla<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Crear página desde plantilla</h2>
    <a href="<?= e(base_url('admin/pages')) ?>" class="pp-btn pp-btn--secondary">← Volver</a>
</div>

<p class="pp-page-intro">
    Elige una plantilla. La IA rellena cada sección con tu contexto y, si has activado el banco de imágenes,
    descarga fotografías reales para los bloques que las necesitan.
    <?php if (!$bankAvailable): ?>
        <br><small style="color:#64748b">Banco de imágenes no configurado: las secciones con imagen quedarán vacías y deberás añadirlas a mano.</small>
    <?php endif; ?>
</p>

<ul class="pp-tpl-grid">
    <?php foreach ($cards as $c): ?>
        <li class="pp-tpl-card" data-slug="<?= e($c['slug']) ?>" data-label="<?= e($c['label']) ?>" data-needs-bank="<?= $c['needs_bank'] ? '1' : '0' ?>" data-preview="<?= e($c['preview_url']) ?>">
            <div class="pp-tpl-card__thumb">
                <div class="pp-tpl-card__frame-wrap">
                    <div class="pp-tpl-card__spacer">
                        <iframe class="pp-tpl-card__frame" data-src="<?= e($c['preview_url']) ?>" loading="lazy" title="Vista previa: <?= e($c['label']) ?>" sandbox="allow-same-origin" tabindex="-1"></iframe>
                    </div>
                </div>
                <a href="<?= e($c['preview_url']) ?>" target="_blank" rel="noopener" class="pp-tpl-card__expand" title="Abrir vista previa completa">↗</a>
            </div>
            <div class="pp-tpl-card__body">
                <h3 class="pp-tpl-card__title"><?= e($c['label']) ?></h3>
                <p class="pp-tpl-card__desc"><?= e($c['description']) ?></p>
                <p class="pp-tpl-card__meta">
                    <span class="pp-pill"><?= (int) $c['sections'] ?> secciones</span>
                    <span class="pp-pill pp-pill--muted"><?= e($c['page_type']) ?></span>
                    <?php if ($c['needs_bank']): ?>
                        <span class="pp-pill pp-pill--accent">imágenes</span>
                    <?php endif; ?>
                </p>
                <div class="pp-tpl-card__actions">
                    <a href="<?= e($c['preview_url']) ?>" target="_blank" rel="noopener" class="pp-btn pp-btn--secondary">Vista completa</a>
                    <button type="button" class="pp-btn pp-btn--primary pp-tpl-card__btn">Usar plantilla</button>
                </div>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<!-- Modal de detalles -->
<div id="pp-tpl-modal" class="pp-modal" hidden>
    <div class="pp-modal__backdrop" data-close></div>
    <div class="pp-modal__panel" role="dialog" aria-labelledby="pp-tpl-modal-title">
        <header class="pp-modal__head">
            <h3 id="pp-tpl-modal-title">Crear página desde plantilla</h3>
            <button type="button" class="pp-modal__close" data-close aria-label="Cerrar">×</button>
        </header>
        <form id="pp-tpl-form" class="pp-modal__body">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="template_slug" id="pp-tpl-slug">

            <p id="pp-tpl-summary" class="pp-tpl-summary"></p>

            <div class="pp-form-group">
                <label for="pp-tpl-title">Título de la página *</label>
                <input id="pp-tpl-title" name="title" type="text" required maxlength="200" placeholder="Ej. Servicios para hostelería" class="pp-input">
            </div>

            <div class="pp-form-group">
                <label for="pp-tpl-goal">Objetivo de la página *</label>
                <textarea id="pp-tpl-goal" name="goal" rows="3" required placeholder="Ej. captar leads de restaurantes interesados en consultoría de digitalización" class="pp-input"></textarea>
            </div>

            <div class="pp-form-group">
                <label for="pp-tpl-audience">Público objetivo (opcional)</label>
                <input id="pp-tpl-audience" name="audience" type="text" maxlength="200" placeholder="Ej. dueños de restaurantes pequeños" class="pp-input">
            </div>

            <div class="pp-form-group">
                <label for="pp-tpl-details">Detalles adicionales (opcional)</label>
                <textarea id="pp-tpl-details" name="details" rows="3" placeholder="Datos del negocio, propuestas concretas, números, etc." class="pp-input"></textarea>
            </div>

            <div id="pp-tpl-status" class="pp-tpl-status" aria-live="polite"></div>

            <footer class="pp-modal__foot">
                <button type="button" class="pp-btn pp-btn--secondary" data-close>Cancelar</button>
                <button type="submit" class="pp-btn pp-btn--primary" id="pp-tpl-submit">Generar página</button>
            </footer>
        </form>
    </div>
</div>

<script>
(function () {
    const PREVIEW_WIDTH = 1440; // ancho lógico — cubre cualquier --pp-container-max (max 1440)

    /**
     * Recalcula escala + alturas para una sola card.
     * Se llama:
     *   - al cargar la página
     *   - al cargar el iframe (tenemos altura real del documento)
     *   - cuando ResizeObserver detecta que la card cambió de ancho
     *   - retries tras 700ms / 1800ms por si imágenes (picsum) llegan tarde
     */
    function fitCard(wrap) {
        const w = wrap.clientWidth;
        if (w <= 0) return;
        const scale = w / PREVIEW_WIDTH;
        const spacer = wrap.querySelector('.pp-tpl-card__spacer');
        const frame  = wrap.querySelector('.pp-tpl-card__frame');
        if (!spacer || !frame) return;

        frame.style.transform = 'scale(' + scale.toFixed(4) + ')';

        // Altura real del documento renderizado (si ya cargó).
        let contentH = 0;
        try {
            const doc = frame.contentDocument || frame.contentWindow?.document;
            if (doc) {
                contentH = Math.max(
                    doc.body ? doc.body.scrollHeight : 0,
                    doc.documentElement ? doc.documentElement.scrollHeight : 0
                );
            }
        } catch (e) { /* cross-origin no debería pasar */ }

        if (contentH > 100) {
            frame.style.height = contentH + 'px';
            spacer.style.height = (contentH * scale) + 'px';
        } else {
            // Aún no hay altura real: dejamos un placeholder cómodo.
            spacer.style.height = (1800 * scale) + 'px';
        }
    }

    function fitAll() {
        document.querySelectorAll('.pp-tpl-card__frame-wrap').forEach(fitCard);
    }
    fitAll();

    // ResizeObserver: si el card cambia de ancho (sidebar abre/cierra, resize ventana,
    // fonts cargan tarde y reflowean…), recalculamos. Más fiable que escuchar window.resize.
    if ('ResizeObserver' in window) {
        const ro = new ResizeObserver(entries => {
            entries.forEach(e => fitCard(e.target));
        });
        document.querySelectorAll('.pp-tpl-card__frame-wrap').forEach(w => ro.observe(w));
    } else {
        window.addEventListener('resize', fitAll);
    }

    // Lazy-load iframes con IntersectionObserver — evitamos pegar 15 cargas al servidor a la vez.
    const frames = document.querySelectorAll('.pp-tpl-card__frame');
    function loadFrame(frame) {
        if (!frame.dataset.src) return;
        const wrap = frame.closest('.pp-tpl-card__frame-wrap');
        const onLoadOrTick = () => fitCard(wrap);
        frame.addEventListener('load', () => {
            onLoadOrTick();
            // Reintentos por si imágenes (picsum) llegan tarde y empujan el layout.
            setTimeout(onLoadOrTick, 700);
            setTimeout(onLoadOrTick, 1800);
        }, { once: false });
        frame.src = frame.dataset.src;
        frame.removeAttribute('data-src');
    }
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    loadFrame(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '300px' });
        frames.forEach(f => io.observe(f));
    } else {
        frames.forEach(loadFrame);
    }

    const cards  = document.querySelectorAll('.pp-tpl-card');
    const modal  = document.getElementById('pp-tpl-modal');
    const slug   = document.getElementById('pp-tpl-slug');
    const sumEl  = document.getElementById('pp-tpl-summary');
    const form   = document.getElementById('pp-tpl-form');
    const submit = document.getElementById('pp-tpl-submit');
    const status = document.getElementById('pp-tpl-status');
    const bankAvailable = <?= $bankAvailable ? 'true' : 'false' ?>;

    cards.forEach(card => {
        card.querySelector('.pp-tpl-card__btn').addEventListener('click', () => openModal(card));
    });
    modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', closeModal));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    function openModal(card) {
        slug.value = card.dataset.slug;
        const needsBank = card.dataset.needsBank === '1';
        sumEl.innerHTML = 'Plantilla: <strong>' + escHtml(card.dataset.label) + '</strong>'
            + (needsBank && !bankAvailable ? '<br><small style="color:#b45309">Esta plantilla espera imágenes y el banco no está configurado: las secciones con foto quedarán vacías.</small>' : '');
        status.textContent = '';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        document.getElementById('pp-tpl-title').focus();
    }
    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        form.reset();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        submit.disabled = true;
        status.textContent = 'Generando con IA… esto puede tardar entre 30s y 2 min según número de secciones.';
        const fd = new FormData(form);
        fetch('<?= e(base_url('admin/pages/ai-create-from-template')) ?>', {
            method: 'POST', credentials: 'same-origin', body: fd,
        })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    submit.disabled = false;
                    status.textContent = 'Error: ' + (data.error || 'No se pudo generar la página.');
                    return;
                }
                status.innerHTML = '✓ Página creada con ' + data.sections_count + ' secciones'
                    + (data.images_applied ? ' y ' + data.images_applied + ' imágenes del banco' : '')
                    + '. Redirigiendo…';
                setTimeout(() => { window.location = data.edit_url; }, 800);
            })
            .catch(err => {
                submit.disabled = false;
                status.textContent = 'Error de conexión: ' + err.message;
            });
    });

    function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
})();
</script>

<style>
.pp-tpl-grid { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }
.pp-tpl-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; transition: transform .18s cubic-bezier(.16,1,.3,1), box-shadow .18s ease; }
.pp-tpl-card:hover { transform: translateY(-2px); box-shadow: 0 14px 28px -16px rgba(15,23,42,.18); }
.pp-tpl-card__thumb {
    position: relative;
    height: 360px;
    background: #f8fafc;
    overflow: hidden;
    border-bottom: 1px solid #f1f5f9;
}
.pp-tpl-card__frame-wrap {
    position: absolute;
    inset: 0;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    /* Scrollbar discreta */
    scrollbar-width: thin;
    scrollbar-color: rgba(15, 23, 42, .25) transparent;
}
.pp-tpl-card__frame-wrap::-webkit-scrollbar { width: 6px; }
.pp-tpl-card__frame-wrap::-webkit-scrollbar-track { background: transparent; }
.pp-tpl-card__frame-wrap::-webkit-scrollbar-thumb { background: rgba(15,23,42,.25); border-radius: 3px; }
.pp-tpl-card__frame-wrap::-webkit-scrollbar-thumb:hover { background: rgba(15,23,42,.45); }

/* Spacer: ocupa el ANCHO real del card y la ALTURA post-escala calculada por JS.
   Da al wrapper (overflow:auto) las dimensiones correctas para scroll proporcional.
   El iframe está dentro en posición absoluta y se escala con transform — su layout
   sigue siendo 1440px pero visualmente queda a `cardWidth`. */
.pp-tpl-card__spacer {
    position: relative;
    width: 100%;
    height: 360px; /* placeholder hasta que el JS calcule la altura real */
}
.pp-tpl-card__frame {
    position: absolute;
    top: 0;
    left: 0;
    width: 1440px; /* ancho lógico — cubre el max --pp-container-max permitido */
    height: 2000px; /* placeholder; JS ajusta a la altura real del documento */
    transform-origin: top left;
    border: 0;
    background: #fff;
    display: block;
}
/* Estados de carga */
.pp-tpl-card__frame:not([src]) { opacity: 0; }
.pp-tpl-card__frame[src] { opacity: 1; transition: opacity .3s ease; }
.pp-tpl-card__frame-wrap::before {
    content: "";
    position: absolute; inset: 0;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    background-image: linear-gradient(90deg, transparent 0%, rgba(255,255,255,.5) 50%, transparent 100%);
    background-size: 200% 100%;
    animation: pp-tpl-shimmer 1.6s infinite;
    z-index: 0;
    pointer-events: none;
}
.pp-tpl-card__frame-wrap:has(.pp-tpl-card__frame[src])::before { display: none; }
@keyframes pp-tpl-shimmer { from { background-position: -200% 0 } to { background-position: 200% 0 } }

/* Indicador "scroll interno disponible" — desaparece tras la primera interacción */
.pp-tpl-card__thumb::after {
    content: "↕ scroll";
    position: absolute;
    left: 50%;
    bottom: 8px;
    transform: translateX(-50%);
    font: 600 10px/1 system-ui, sans-serif;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #fff;
    background: rgba(15, 23, 42, .65);
    padding: 4px 10px;
    border-radius: 999px;
    pointer-events: none;
    opacity: 0;
    transition: opacity .2s ease;
    z-index: 3;
    backdrop-filter: blur(4px);
}
.pp-tpl-card:hover .pp-tpl-card__thumb::after { opacity: 1; }

/* Botón flotante "abrir en grande" */
.pp-tpl-card__expand {
    position: absolute;
    top: 8px; right: 8px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: rgba(15, 23, 42, .82);
    color: #fff;
    text-decoration: none;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    z-index: 2;
    backdrop-filter: blur(4px);
    transition: background .15s ease, transform .15s ease;
    pointer-events: auto;
}
.pp-tpl-card__expand:hover { background: rgba(15, 23, 42, .95); transform: scale(1.08); text-decoration: none; }

.pp-tpl-card__body { padding: 16px 18px 18px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
.pp-tpl-card__actions { display: flex; gap: 8px; align-items: center; margin-top: auto; }
.pp-tpl-card__actions .pp-btn { flex: 1; text-align: center; justify-content: center; }
.pp-tpl-card__title { margin: 0; font-size: 1.05rem; letter-spacing: -.01em; color: #0f172a; }
.pp-tpl-card__desc { margin: 0; color: #475569; font-size: .9rem; line-height: 1.5; flex: 1; }
.pp-tpl-card__meta { margin: 0; display: flex; gap: 6px; flex-wrap: wrap; }
.pp-tpl-card__btn { align-self: flex-start; }
.pp-pill { display: inline-block; font-size: .72rem; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; letter-spacing: .04em; text-transform: uppercase; }
.pp-pill--muted { background: #f1f5f9; color: #475569; }
.pp-pill--accent { background: #fef3c7; color: #92400e; }

.pp-modal { position: fixed; inset: 0; z-index: 1000; }
.pp-modal[hidden] { display: none !important; }
.pp-modal__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,.55); backdrop-filter: blur(2px); }
.pp-modal__panel { position: relative; max-width: 560px; width: calc(100% - 32px); max-height: 90vh; overflow: auto; margin: 5vh auto; background: #fff; border-radius: 16px; box-shadow: 0 30px 80px -20px rgba(15,23,42,.4); }
.pp-modal__head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; border-bottom: 1px solid #f1f5f9; }
.pp-modal__head h3 { margin: 0; font-size: 1.05rem; }
.pp-modal__close { background: none; border: 0; font-size: 1.6rem; line-height: 1; cursor: pointer; color: #64748b; padding: 0 4px; }
.pp-modal__body { padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
.pp-modal__foot { display: flex; gap: 8px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #f1f5f9; margin: 0 -22px -2px; padding: 14px 22px 0; }
.pp-tpl-summary { margin: 0 0 4px; padding: 10px 12px; background: #f8fafc; border-radius: 8px; font-size: .9rem; color: #334155; }
.pp-tpl-status { min-height: 1.5em; font-size: .9rem; color: #475569; }
</style>
