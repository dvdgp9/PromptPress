<?php
/**
 * D-Slice 5 (S5.6) — Test page generator.
 * Grid de todas las variantes (tipo×variant) con iframe del SectionRenderer real.
 *
 * @var array<string,array<string,mixed>> $catalog
 */
\Core\View::extend('admin/layout');
?>
<?php \Core\View::start('title'); ?>Preview de variantes<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Preview de todas las variantes</h2>
        <p class="pp-page-intro" style="margin-top:4px;">
            Cada miniatura es el render real del <code>SectionRenderer</code> con el skin compuesto actual del sitio y contenido placeholder.
            Útil para detectar regresiones visuales tras tocar el catálogo o `DesignSystem`.
        </p>
    </div>
    <a href="<?= e(base_url('admin/design')) ?>" class="pp-btn pp-btn--secondary">← Volver a Diseño</a>
</div>

<?php foreach ($catalog as $type => $variants): ?>
<section class="pp-preview-type">
    <header class="pp-preview-type__head">
        <h3><?= e($type) ?></h3>
        <span class="pp-preview-type__count"><?= count($variants) ?> variantes</span>
    </header>
    <div class="pp-preview-grid">
        <?php foreach ($variants as $variant => $meta):
            $url = base_url('admin/sections/variant-preview?type=' . urlencode($type) . '&variant=' . urlencode($variant));
            $axes = (array) ($meta['axes'] ?? []);
            $req = (array) ($meta['requires'] ?? []);
            $bad = (array) ($meta['incompatible_skin'] ?? []);
        ?>
        <article class="pp-preview-card">
            <header class="pp-preview-card__head">
                <h4><?= e($variant) ?></h4>
                <span class="pp-preview-card__axes" title="density · hierarchy · alignment · composition">
                    <?= number_format((float) ($axes['density'] ?? 0.5), 2) ?>·<?= number_format((float) ($axes['hierarchy'] ?? 0.5), 2) ?>·<?= number_format((float) ($axes['alignment_bias'] ?? 0.5), 2) ?>·<?= number_format((float) ($axes['compositional_balance'] ?? 0.5), 2) ?>
                </span>
            </header>
            <div class="pp-preview-frame">
                <iframe loading="lazy" tabindex="-1" aria-hidden="true" src="<?= e($url) ?>" title="<?= e($type) ?>/<?= e($variant) ?>"></iframe>
            </div>
            <footer class="pp-preview-card__foot">
                <?php if ($req): ?>
                    <span class="pp-preview-card__tag pp-preview-card__tag--req">requires: <?= e(implode(', ', $req)) ?></span>
                <?php endif; ?>
                <?php foreach ($bad as $rule): ?>
                    <span class="pp-preview-card__tag pp-preview-card__tag--bad">incompat: <?= e($rule['axis'] . ' ' . $rule['op'] . ' ' . $rule['value']) ?></span>
                <?php endforeach; ?>
            </footer>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<style>
.pp-preview-type { margin: 22px 0 34px; }
.pp-preview-type__head { display: flex; align-items: baseline; gap: 14px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--pp-border); }
.pp-preview-type__head h3 { margin: 0; font-family: ui-monospace, monospace; font-size: 1.05rem; }
.pp-preview-type__count { color: var(--pp-text-muted); font-size: 0.82rem; }

.pp-preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px; }

.pp-preview-card { background: #fff; border: 1px solid var(--pp-border); border-radius: 12px; overflow: hidden; }
.pp-preview-card__head { display: flex; justify-content: space-between; align-items: baseline; padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid var(--pp-border); }
.pp-preview-card__head h4 { margin: 0; font-family: ui-monospace, monospace; font-size: 0.88rem; color: var(--pp-text); }
.pp-preview-card__axes { font-family: ui-monospace, monospace; font-size: 0.72rem; color: var(--pp-text-muted); }

.pp-preview-frame { position: relative; aspect-ratio: 16/10; width: 100%; overflow: hidden; background: #f1f5f9; }
.pp-preview-frame iframe { width: 1200px; height: 800px; border: 0; transform-origin: top left; transform: scale(0.3); display: block; pointer-events: none; }

.pp-preview-card__foot { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 14px; min-height: 30px; }
.pp-preview-card__tag { font-family: ui-monospace, monospace; font-size: 0.7rem; padding: 2px 8px; border-radius: 999px; }
.pp-preview-card__tag--req { background: #fef3c7; color: #92400e; }
.pp-preview-card__tag--bad { background: #fee2e2; color: #991b1b; }
</style>

<script>
// Ajustar el scale del iframe al ancho real del frame (similar al variant chip preview).
(function() {
    function fit() {
        document.querySelectorAll('.pp-preview-frame').forEach(function(frame) {
            var iframe = frame.querySelector('iframe');
            if (!iframe) return;
            var w = frame.getBoundingClientRect().width;
            if (!w) return;
            var scale = (w / 1200).toFixed(4);
            iframe.style.transform = 'scale(' + scale + ')';
            // Ajustar altura aparente del iframe al alto real escalado.
            iframe.style.height = (frame.getBoundingClientRect().height / scale) + 'px';
            iframe.style.width = '1200px';
        });
    }
    fit();
    window.addEventListener('resize', fit);
})();
</script>
