<?php
/**
 * @var array $anchors
 * @var array|null $currentVector
 */
\Core\View::extend('admin/layout');

$nearestId = null;
if (is_array($currentVector)) {
    $near = \App\Services\Personality\SkinAnchors::nearestN($currentVector, 1);
    $nearestId = $near[0]['anchor']['id'] ?? null;
}
?>
<?php \Core\View::start('title'); ?>Showcase de skin anchors<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Showcase de skin anchors</h2>
        <p class="pp-page-intro" style="margin-top:4px;">
            Los 8 puntos materializados que usa el composer para interpolar el skin de cada sitio.
            <?php if ($nearestId): ?>
                <strong>El skin actual de este sitio se interpola más cerca de <code><?= e($nearestId) ?></code>.</strong>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= e(base_url('admin/design')) ?>" class="pp-btn pp-btn--secondary">← Volver a Diseño</a>
</div>

<div class="pp-anchor-grid">
    <?php foreach ($anchors as $a):
        $p = $a['palette']; $t = $a['typography']; $r = $a['radii'];
        $isNearest = $nearestId === $a['id'];
        // Construir la cadena de CSS variables scope.
        $vars = sprintf(
            '--anc-primary:%s;--anc-primary-dark:%s;--anc-accent:%s;--anc-bg:%s;--anc-surface:%s;--anc-text:%s;--anc-muted:%s;--anc-border:%s;--anc-font-h:%s;--anc-font-b:%s;--anc-weight:%s;--anc-scale:%s;--anc-letter:%s;--anc-r-btn:%dpx;--anc-r-card:%dpx;',
            e($p['primary']), e($p['primary_dark']), e($p['accent']),
            e($p['bg']), e($p['surface']), e($p['text']), e($p['text_muted']), e($p['border']),
            e('"' . $t['font_heading'] . '", system-ui, sans-serif'),
            e('"' . $t['font_body']    . '", system-ui, sans-serif'),
            e($t['weight_bold']), e($t['scale_ratio']), e($t['letter_spacing_heading']),
            (int) $r['btn'], (int) $r['card']
        );
        // Familias para cargar de Google Fonts (whitelist mínima).
        $googleFamilies = array_unique(array_filter([
            $t['font_heading'] !== 'system' ? $t['font_heading'] : null,
            $t['font_body']    !== 'system' && $t['font_body'] !== $t['font_heading'] ? $t['font_body'] : null,
        ]));
    ?>
    <article class="pp-anchor-card<?= $isNearest ? ' is-nearest' : '' ?>" style="<?= $vars ?>">
        <?php foreach ($googleFamilies as $fam): ?>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= e(str_replace(' ', '+', $fam)) ?>:wght@400;600;700;800;900&display=swap">
        <?php endforeach; ?>
        <header class="pp-anchor-card__head">
            <h3><?= e($a['id']) ?><?php if ($isNearest): ?> <span class="pp-anchor-card__badge">Tu skin</span><?php endif; ?></h3>
            <p class="pp-anchor-card__vector">
                W <?= number_format($a['vector']['warmth'], 2) ?> ·
                F <?= number_format($a['vector']['formality'], 2) ?> ·
                M <?= number_format($a['vector']['modernity'], 2) ?> ·
                E <?= number_format($a['vector']['energy'], 2) ?>
            </p>
        </header>
        <div class="pp-anchor-card__preview">
            <p class="pp-anc-eyebrow">Estudio de marca</p>
            <h4 class="pp-anc-heading">Diseñamos lo que tu marca necesita</h4>
            <p class="pp-anc-sub">Subtítulo de ejemplo con un par de líneas para ver el cuerpo del texto.</p>
            <div class="pp-anc-actions">
                <button type="button" class="pp-anc-btn pp-anc-btn--primary">Acción principal</button>
                <button type="button" class="pp-anc-btn pp-anc-btn--ghost">Saber más</button>
            </div>
            <div class="pp-anc-card">
                <p class="pp-anc-card__title">Beneficio destacado</p>
                <p class="pp-anc-card__body">Tarjeta de ejemplo sobre el surface del anchor para ver el contraste.</p>
            </div>
            <div class="pp-anc-swatches">
                <?php foreach (['primary','primary_dark','accent','bg','surface','text','text_muted','border'] as $k): ?>
                    <span class="pp-anc-swatch" title="<?= e($k) ?>: <?= e($p[$k]) ?>" style="background:<?= e($p[$k]) ?>"></span>
                <?php endforeach; ?>
            </div>
            <ul class="pp-anchor-card__meta">
                <li>Heading: <?= e($t['font_heading']) ?> <?= e($t['weight_bold']) ?> · escala <?= e($t['scale_ratio']) ?> · case <?= e($t['label_case']) ?></li>
                <li>Body: <?= e($t['font_body']) ?> · radii <?= (int) $r['btn'] ?>/<?= (int) $r['card'] ?>px · sombra <?= e($a['shadow_level']) ?> · motion <?= e($a['motion']) ?></li>
            </ul>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<style>
.pp-anchor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 18px;
    margin-top: 18px;
}
.pp-anchor-card {
    background: var(--anc-bg, #fff);
    color: var(--anc-text, #1f2937);
    border: 1px solid var(--anc-border, #e5e7eb);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 12px -8px rgba(15,23,42,0.12);
    transition: transform 200ms ease, box-shadow 200ms ease;
}
.pp-anchor-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -12px rgba(15,23,42,0.18); }
.pp-anchor-card.is-nearest { outline: 3px solid var(--anc-primary, #6366f1); outline-offset: 2px; }
.pp-anchor-card__head { padding: 12px 18px; background: var(--anc-surface, #f9fafb); border-bottom: 1px solid var(--anc-border, #e5e7eb); }
.pp-anchor-card__head h3 { margin: 0; font-family: ui-monospace, monospace; font-size: 0.88rem; color: var(--anc-text, #1f2937); }
.pp-anchor-card__badge { display: inline-block; margin-left: 8px; font-family: system-ui; font-size: 0.66rem; padding: 2px 8px; border-radius: 999px; background: var(--anc-primary); color: #fff; vertical-align: middle; }
.pp-anchor-card__vector { margin: 4px 0 0; font-family: ui-monospace, monospace; font-size: 0.72rem; color: var(--anc-muted, #6b7280); }

.pp-anchor-card__preview { padding: 20px 22px; }
.pp-anc-eyebrow { margin: 0 0 6px; font-family: var(--anc-font-h); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--anc-primary); }
.pp-anc-heading { margin: 0 0 8px; font-family: var(--anc-font-h); font-weight: var(--anc-weight); letter-spacing: var(--anc-letter); font-size: calc(1rem * var(--anc-scale) * var(--anc-scale)); line-height: 1.15; color: var(--anc-text); }
.pp-anc-sub { margin: 0 0 14px; font-family: var(--anc-font-b); color: var(--anc-muted); font-size: 0.92rem; line-height: 1.5; }
.pp-anc-actions { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.pp-anc-btn { font-family: var(--anc-font-b); font-size: 0.85rem; font-weight: 600; padding: 9px 16px; border-radius: var(--anc-r-btn); border: 1px solid transparent; cursor: pointer; line-height: 1.2; }
.pp-anc-btn--primary { background: var(--anc-primary); color: #fff; border-color: var(--anc-primary); }
.pp-anc-btn--ghost { background: transparent; color: var(--anc-text); border-color: var(--anc-border); }
.pp-anc-card { background: var(--anc-surface); border: 1px solid var(--anc-border); border-radius: var(--anc-r-card); padding: 14px 16px; margin-bottom: 14px; }
.pp-anc-card__title { margin: 0 0 4px; font-family: var(--anc-font-h); font-weight: var(--anc-weight); color: var(--anc-text); font-size: 0.95rem; }
.pp-anc-card__body  { margin: 0; font-family: var(--anc-font-b); color: var(--anc-muted); font-size: 0.82rem; line-height: 1.45; }

.pp-anc-swatches { display: flex; gap: 4px; margin-bottom: 10px; }
.pp-anc-swatch { width: 22px; height: 22px; border-radius: 4px; border: 1px solid rgba(0,0,0,0.08); cursor: help; }

.pp-anchor-card__meta { list-style: none; margin: 0; padding: 12px 0 0; border-top: 1px dashed var(--anc-border); font-family: ui-monospace, monospace; font-size: 0.7rem; color: var(--anc-muted); }
.pp-anchor-card__meta li { margin-bottom: 2px; }
</style>
