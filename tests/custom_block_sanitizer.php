<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Renderer\CustomBlockSanitizer;

$valid = '<div class="ppb-container ppb-stack" style="color:red">'
    . '<h1 class="ppb-heading-xl" data-pp-field="header.heading" data-pp-type="text">Hola</h1>'
    . '<p class="ppb-lead" data-pp-field="header.lead" data-pp-type="richtext">Texto</p>'
    . '<a class="pp-btn pp-btn--primary unknown" href="/contacto" data-pp-field="cta.primary" data-pp-type="cta">Contacto</a>'
    . '</div>';

$cases = [
    'valid_cleaned' => [
        'html' => $valid,
        'ok' => true,
        'contains' => ['ppb-container', 'header.heading', 'Contacto'],
        'not_contains' => ['style=', 'unknown'],
    ],
    'reject_script' => [
        'html' => '<div class="ppb-container"><script>alert(1)</script><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div>',
        'ok' => false,
        'error' => 'forbidden_tag',
    ],
    'reject_js_href' => [
        'html' => '<div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2><a href="javascript:alert(1)" data-pp-field="cta.primary" data-pp-type="cta">Bad</a></div>',
        'ok' => false,
        'error' => 'invalid_url',
    ],
    'reject_missing_alt' => [
        'html' => '<div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2><img src="/uploads/x.webp" data-pp-field="media.image" data-pp-type="image"></div>',
        'ok' => false,
        'error' => 'missing_img_alt',
    ],
    'reject_duplicate_field' => [
        'html' => '<div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2><p data-pp-field="header.heading" data-pp-type="text">Y</p></div>',
        'ok' => false,
        'error' => 'duplicate_field',
    ],
    'unwrap_section' => [
        'html' => '<section><div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div></section>',
        'ok' => true,
        'contains' => ['ppb-container', 'header.heading'],
        'not_contains' => ['<section'],
    ],
    'utf8_preserved' => [
        'html' => '<div class="ppb-container"><p data-pp-field="headline" data-pp-type="text">Clínica ágil y diseño útil</p></div>',
        'ok' => true,
        'contains' => ['Clínica ágil', 'diseño útil'],
    ],
    // D-MB2 R2 — dirección de arte
    'art_theme_extracted' => [
        'html' => '<div data-ppb-theme="tint" data-ppb-pad="lg"><div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div></div>',
        'ok' => true,
        'art' => ['theme' => 'tint', 'pad' => 'lg'],
        'contains' => ['data-ppb-theme="tint"'],
    ],
    'art_invalid_theme_removed' => [
        'html' => '<div data-ppb-theme="neon"><div class="ppb-container"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div></div>',
        'ok' => true,
        'art' => ['theme' => '', 'pad' => ''],
        'not_contains' => ['data-ppb-theme'],
    ],
    'art_nested_attr_stripped' => [
        'html' => '<div data-ppb-theme="dark"><div class="ppb-container" data-ppb-theme="primary"><h2 data-pp-field="header.heading" data-pp-type="text">X</h2></div></div>',
        'ok' => true,
        'art' => ['theme' => 'dark', 'pad' => ''],
        'not_contains' => ['data-ppb-theme="primary"'],
    ],
    'inverted_inside_card_stripped' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-card"><div class="ppb-panel ppb-panel--inverted"><h3 data-pp-field="items.0.title" data-pp-type="text">X</h3></div></div></div></div>',
        'ok' => true,
        'not_contains' => ['ppb-panel--inverted'],
    ],
    'inverted_inside_dark_theme_stripped' => [
        'html' => '<div data-ppb-theme="dark"><div class="ppb-container"><div class="ppb-panel ppb-panel--inverted"><h3 data-pp-field="header.heading" data-pp-type="text">X</h3></div></div></div>',
        'ok' => true,
        'not_contains' => ['ppb-panel--inverted'],
    ],
    'nested_cover_demoted' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--3"><article class="ppb-card"><div class="ppb-cover"><figure class="ppb-cover__bg"><img src="/storage/uploads/1/a.jpg" alt="F" data-pp-field="items.0.image" data-pp-type="image"></figure><div class="ppb-cover__content"><h3 data-pp-field="items.0.title" data-pp-type="text">X</h3></div></div></article></div></div></div>',
        'ok' => true,
        'not_contains' => ['ppb-cover'],
        'contains' => ['items.0.title'],
    ],
    'duplicate_image_removed' => [
        'html' => '<div><div class="ppb-container"><figure class="ppb-media"><img src="/storage/uploads/1/a.jpg" alt="F" data-pp-field="img.a" data-pp-type="image"></figure><figure class="ppb-media"><img src="/storage/uploads/1/a.jpg" alt="F" data-pp-field="img.b" data-pp-type="image"></figure><h2 data-pp-field="h" data-pp-type="text">X</h2></div></div>',
        'ok' => true,
        'contains' => ['img.a'],
        'not_contains' => ['img.b'],
    ],
    'emoji_stripped' => [
        'html' => '<div><div class="ppb-container"><h2 data-pp-field="h" data-pp-type="text">Rapidez real \u{26A1}\u{1F680}</h2><p data-pp-field="p" data-pp-type="text">Sin \u{2705} emojis \u{2B50}</p></div></div>',
        'ok' => true,
        'contains' => ['Rapidez real', 'Sin', 'emojis'],
        'not_contains' => ["\u{26A1}", "\u{1F680}", "\u{2705}", "\u{2B50}"],
    ],
    'icon_valid_kept_and_emptied' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-item"><span class="ppb-item__icon" data-ppb-icon="shield-check">\u{1F6E1}</span><h3 data-pp-field="t" data-pp-type="text">Seguridad</h3></div></div></div>',
        'ok' => true,
        'contains' => ['data-ppb-icon="shield-check"', 'aria-hidden="true"'],
        'not_contains' => ["\u{1F6E1}"],
    ],
    'icon_alias_canonicalized' => [
        'html' => '<div><div class="ppb-container"><span class="ppb-item__icon" data-ppb-icon="lightning"></span><h3 data-pp-field="t" data-pp-type="text">X</h3></div></div>',
        'ok' => true,
        'contains' => ['data-ppb-icon="zap"'],
    ],
    'icon_unknown_dropped' => [
        'html' => '<div><div class="ppb-container"><span class="ppb-item__icon" data-ppb-icon="unicornio-3d"></span><h3 data-pp-field="t" data-pp-type="text">X</h3></div></div>',
        'ok' => true,
        'not_contains' => ['data-ppb-icon'],
    ],
    'grid_mixed_images_uniformed' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--3"><article class="ppb-card"><figure class="ppb-media"><img src="/storage/uploads/1/a.jpg" alt="A" data-pp-field="cards.0.image" data-pp-type="image"></figure><h3 data-pp-field="cards.0.title" data-pp-type="text">Uno</h3><p data-pp-field="cards.0.text" data-pp-type="text">Texto uno.</p></article><article class="ppb-card"><h3 data-pp-field="cards.1.title" data-pp-type="text">Dos</h3><p data-pp-field="cards.1.text" data-pp-type="text">Texto dos.</p></article></div></div></div>',
        'ok' => true,
        'not_contains' => ['<img', 'cards.0.image'],
        'contains' => ['Texto uno', 'Texto dos'],
    ],
    'grid_all_images_kept' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--2"><article class="ppb-card"><figure class="ppb-media"><img src="/storage/uploads/1/a.jpg" alt="A" data-pp-field="cards.0.image" data-pp-type="image"></figure><h3 data-pp-field="cards.0.title" data-pp-type="text">Uno</h3></article><article class="ppb-card"><figure class="ppb-media"><img src="/storage/uploads/1/b.jpg" alt="B" data-pp-field="cards.1.image" data-pp-type="image"></figure><h3 data-pp-field="cards.1.title" data-pp-type="text">Dos</h3></article></div></div></div>',
        'ok' => true,
        'contains' => ['/storage/uploads/1/a.jpg', '/storage/uploads/1/b.jpg'],
    ],
    'grid_orphan_rebalanced_3in2' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--2"><article class="ppb-card"><h3 data-pp-field="c.0.t" data-pp-type="text">T0</h3><p data-pp-field="c.0.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.1.t" data-pp-type="text">T1</h3><p data-pp-field="c.1.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.2.t" data-pp-type="text">T2</h3><p data-pp-field="c.2.p" data-pp-type="text">Texto.</p></article></div></div></div>',
        'ok' => true,
        'contains' => ['ppb-grid--3'],
        'not_contains' => ['ppb-grid--2'],
    ],
    'grid_orphan_rebalanced_4in3' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--3"><article class="ppb-card"><h3 data-pp-field="c.0.t" data-pp-type="text">T0</h3><p data-pp-field="c.0.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.1.t" data-pp-type="text">T1</h3><p data-pp-field="c.1.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.2.t" data-pp-type="text">T2</h3><p data-pp-field="c.2.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.3.t" data-pp-type="text">T3</h3><p data-pp-field="c.3.p" data-pp-type="text">Texto.</p></article></div></div></div>',
        'ok' => true,
        'contains' => ['ppb-grid--2'],
        'not_contains' => ['ppb-grid--3'],
    ],
    'grid_even_untouched' => [
        'html' => '<div><div class="ppb-container"><div class="ppb-grid ppb-grid--3"><article class="ppb-card"><h3 data-pp-field="c.0.t" data-pp-type="text">T0</h3><p data-pp-field="c.0.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.1.t" data-pp-type="text">T1</h3><p data-pp-field="c.1.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.2.t" data-pp-type="text">T2</h3><p data-pp-field="c.2.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.3.t" data-pp-type="text">T3</h3><p data-pp-field="c.3.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.4.t" data-pp-type="text">T4</h3><p data-pp-field="c.4.p" data-pp-type="text">Texto.</p></article><article class="ppb-card"><h3 data-pp-field="c.5.t" data-pp-type="text">T5</h3><p data-pp-field="c.5.p" data-pp-type="text">Texto.</p></article></div></div></div>',
        'ok' => true,
        'contains' => ['ppb-grid--3'],
    ],
    'cover_classes_allowed' => [
        'html' => '<div data-ppb-theme="image"><div class="ppb-cover"><figure class="ppb-cover__bg"><img src="/storage/uploads/1/a.jpg" alt="Foto" data-pp-field="cover.image" data-pp-type="image"></figure><div class="ppb-cover__content"><div class="ppb-container"><h1 data-pp-field="header.heading" data-pp-type="text">X</h1></div></div></div></div>',
        'ok' => true,
        'contains' => ['ppb-cover__bg', 'ppb-cover__content'],
    ],
];

$failed = 0;
foreach ($cases as $name => $case) {
    $result = CustomBlockSanitizer::sanitize($case['html'], ['is_first_section' => true]);
    $ok = $result['ok'] === $case['ok'];

    foreach (($case['contains'] ?? []) as $needle) {
        $ok = $ok && str_contains($result['html'] . json_encode($result['fields'], JSON_UNESCAPED_UNICODE), $needle);
    }
    foreach (($case['not_contains'] ?? []) as $needle) {
        $ok = $ok && !str_contains($result['html'], $needle);
    }
    if (isset($case['art'])) {
        $ok = $ok && ($result['art'] ?? null) === $case['art'];
    }
    if (isset($case['error'])) {
        $codes = array_column($result['errors'], 'code');
        $ok = $ok && in_array($case['error'], $codes, true);
    }

    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }
}

exit($failed > 0 ? 1 : 0);
