<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\DesignSystem;
use App\Services\Renderer\SectionRenderer;

$blocks = [
    '<div class="ppb-container ppb-stack ppb-align-start">'
    . '<header class="ppb-header ppb-measure-lg">'
    . '<p class="ppb-eyebrow" data-pp-field="header.eyebrow" data-pp-type="text">Consultoria web</p>'
    . '<h1 class="ppb-heading-xl" data-pp-field="header.heading" data-pp-type="text">Una web clara para explicar valor y abrir conversaciones</h1>'
    . '<p class="ppb-lead" data-pp-field="header.lead" data-pp-type="richtext">Un bloque flexible generado en PromptPress-friendly HTML, renderizado con tokens de marca y sin CSS inline.</p>'
    . '</header>'
    . '<div class="ppb-actions ppb-actions--stack-mobile">'
    . '<a class="pp-btn pp-btn--primary pp-btn--lg" href="/contacto" data-pp-field="cta.primary" data-pp-type="cta">Pedir propuesta</a>'
    . '<a class="pp-btn pp-btn--ghost" href="/servicios" data-pp-field="cta.secondary" data-pp-type="cta">Ver servicios</a>'
    . '</div>'
    . '</div>',

    '<div class="ppb-container ppb-stack ppb-gap-lg">'
    . '<header class="ppb-header ppb-align-center ppb-measure-md">'
    . '<p class="ppb-eyebrow" data-pp-field="header.eyebrow" data-pp-type="text">Como trabajamos</p>'
    . '<h2 class="ppb-heading-lg" data-pp-field="header.heading" data-pp-type="text">Un proceso pensado para avanzar sin perder criterio</h2>'
    . '<p class="ppb-lead" data-pp-field="header.lead" data-pp-type="richtext">Cada decision se conecta con el objetivo comercial de la pagina.</p>'
    . '</header>'
    . '<div class="ppb-grid ppb-grid--3" data-pp-repeat="items">'
    . '<article class="ppb-card ppb-card--flat ppb-item" data-pp-field="items.0" data-pp-type="group"><span class="ppb-badge ppb-badge--accent" data-pp-field="items.0.kicker" data-pp-type="text">01</span><h3 class="ppb-item__title" data-pp-field="items.0.title" data-pp-type="text">Mensaje ordenado</h3><p class="ppb-item__text" data-pp-field="items.0.text" data-pp-type="richtext">La informacion se entiende rapido y reduce dudas.</p></article>'
    . '<article class="ppb-card ppb-card--flat ppb-item" data-pp-field="items.1" data-pp-type="group"><span class="ppb-badge ppb-badge--accent" data-pp-field="items.1.kicker" data-pp-type="text">02</span><h3 class="ppb-item__title" data-pp-field="items.1.title" data-pp-type="text">Prueba visible</h3><p class="ppb-item__text" data-pp-field="items.1.text" data-pp-type="richtext">Senales de confianza integradas en el recorrido.</p></article>'
    . '<article class="ppb-card ppb-card--flat ppb-item" data-pp-field="items.2" data-pp-type="group"><span class="ppb-badge ppb-badge--accent" data-pp-field="items.2.kicker" data-pp-type="text">03</span><h3 class="ppb-item__title" data-pp-field="items.2.title" data-pp-type="text">Accion natural</h3><p class="ppb-item__text" data-pp-field="items.2.text" data-pp-type="richtext">El siguiente paso queda claro sin forzar al visitante.</p></article>'
    . '</div>'
    . '</div>',

    '<div class="ppb-container ppb-split ppb-split--media-right ppb-split--text-heavy">'
    . '<div class="ppb-stack ppb-gap-md">'
    . '<p class="ppb-eyebrow" data-pp-field="header.eyebrow" data-pp-type="text">Diagnostico antes de disenar</p>'
    . '<h2 class="ppb-heading-lg" data-pp-field="header.heading" data-pp-type="text">Primero entendemos que debe conseguir la pagina</h2>'
    . '<p class="ppb-copy" data-pp-field="body.copy" data-pp-type="richtext">La referencia visual se convierte en una estructura util para el negocio, no en una copia superficial.</p>'
    . '<div class="ppb-actions"><a class="pp-btn pp-btn--primary" href="/contacto" data-pp-field="cta.primary" data-pp-type="cta">Hablar del proyecto</a></div>'
    . '</div>'
    . '<figure class="ppb-media ppb-media--frame ppb-media--landscape">'
    . '<img src="https://images.unsplash.com/photo-1552664730-d307ca884978" alt="Equipo revisando una arquitectura web en una mesa de trabajo" loading="lazy" decoding="async" data-pp-field="media.image" data-pp-type="image">'
    . '<figcaption class="ppb-caption" data-pp-field="media.caption" data-pp-type="text">La estructura se adapta a tu marca y objetivo.</figcaption>'
    . '</figure>'
    . '</div>',
];

$sections = [];
foreach ($blocks as $i => $html) {
    $sections[] = [
        'id' => 9100 + $i,
        'sort_order' => $i,
        'section_type' => 'custom_block',
        'content' => json_encode(['version' => 'ppb:1', 'html' => $html], JSON_UNESCAPED_UNICODE),
        'style' => null,
    ];
}

$tokens = DesignSystem::defaults();
$css = DesignSystem::renderCssVars($tokens) . "\n" . DesignSystem::renderSectionBaseCss();
$body = SectionRenderer::renderMany($sections);

?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>PromptPress custom_block demo</title>
    <style><?= $css ?></style>
</head>
<body>
<?= $body ?>
</body>
</html>
