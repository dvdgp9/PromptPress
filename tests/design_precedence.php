<?php

declare(strict_types=1);

/**
 * DESIGN-MANDA T8 — Cadena de precedencia del design system.
 *
 * Comprueba que lo que el usuario decide en el panel MANDA sobre lo que el
 * sistema deduce (skin de personalidad), que era el bug de origen: el panel
 * enseñaba un color y la web pintaba otro.
 *
 * Trabaja sobre el sitio de dev y RESTAURA al terminar las dos claves de
 * settings que toca (`site_palette_custom`, `design_manual_tokens`). No borra
 * ni modifica `design_system` ni `sites.skin_json`.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandPaletteService;
use App\Services\DesignSystem;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '     ' . $detail . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId === 0) {
    echo 'SKIP: no hay ningún sitio en la base de datos.' . PHP_EOL;
    exit(0);
}

/** Copia de seguridad de lo que vamos a tocar. */
$backup = [];
foreach ([BrandPaletteService::SETTING_KEY, DesignSystem::MANUAL_TOKENS_KEY] as $key) {
    $row = Database::selectOne(
        'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
        [$siteId, $key]
    );
    $backup[$key] = $row === null ? null : (string) $row['setting_value'];
}

$restore = function () use ($siteId, $backup): void {
    foreach ($backup as $key => $value) {
        if ($value === null) {
            Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
        } else {
            Database::execute(
                'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$siteId, $key, $value]
            );
        }
    }
    DesignSystem::forgetSkin($siteId);
};

try {
    $hasSkin = DesignSystem::loadSkin($siteId) !== null;
    echo "Sitio {$siteId} · skin de personalidad: " . ($hasSkin ? 'sí' : 'no') . PHP_EOL . PHP_EOL;

    // --- 1. La paleta a medida manda sobre el skin -------------------------
    BrandPaletteService::clear($siteId);
    DesignSystem::clearManualTokens($siteId);
    $inherited = DesignSystem::effective($siteId);

    $palette = [
        'accent' => '#123456', 'accent_dark' => '#0a1a2a', 'accent_2' => '#654321',
        'bg' => '#ffffff', 'surface' => '#f5f5f5', 'text' => '#101010',
        'muted' => '#5a5a5a', 'line' => '#dddddd',
    ];
    check('la paleta se guarda', BrandPaletteService::save($siteId, $palette));

    $withPalette = DesignSystem::effective($siteId);
    check(
        'el color de texto de la paleta manda sobre el skin',
        $withPalette['colors']['text'] === '#101010',
        'esperado #101010, obtenido ' . $withPalette['colors']['text']
    );
    check(
        'el color principal de la paleta manda sobre el skin',
        $withPalette['colors']['primary'] === '#123456',
        'esperado #123456, obtenido ' . $withPalette['colors']['primary']
    );

    // --- 2. Los overrides manuales mandan sobre el skin --------------------
    $inheritedScale = (string) $inherited['typography']['scale_ratio'];
    $otherScale = $inheritedScale === '1.125' ? '1.250' : '1.125';

    DesignSystem::saveManualTokens($siteId, ['typography' => ['scale_ratio' => $otherScale]]);
    $withManual = DesignSystem::effective($siteId);
    check(
        'la escala tipográfica manual manda sobre el skin',
        (string) $withManual['typography']['scale_ratio'] === $otherScale,
        "esperado {$otherScale}, obtenido " . $withManual['typography']['scale_ratio']
    );

    // Y llega hasta las variables CSS, que es lo único que ve el navegador.
    $vars = DesignSystem::toCssVars($withManual, $siteId);
    check(
        'la escala manual llega a --pp-font-scale',
        (string) $vars['--pp-font-scale'] === $otherScale,
        'obtenido ' . (string) $vars['--pp-font-scale']
    );

    // --- 3. Un override que vuelve a su valor heredado desaparece ----------
    $baseline = DesignSystem::baseline($siteId);
    $submittedIgual = ['typography' => ['scale_ratio' => $baseline['typography']['scale_ratio']]];
    check(
        'reenviar el valor heredado no crea override',
        DesignSystem::diffManualTokens($submittedIgual, $baseline) === [],
        'diff: ' . json_encode(DesignSystem::diffManualTokens($submittedIgual, $baseline))
    );

    $submittedDistinto = ['typography' => ['scale_ratio' => $otherScale]];
    $diff = DesignSystem::diffManualTokens($submittedDistinto, $baseline);
    check(
        'enviar un valor distinto sí crea override',
        ($diff['typography']['scale_ratio'] ?? null) === $otherScale,
        'diff: ' . json_encode($diff)
    );

    // Comparación laxa: "10" (string del formulario) vs 10 (int del token) NO
    // es un cambio. Sin esto, cada guardado inventaría overrides fantasma.
    $baseRadius = ['buttons' => ['radius' => 10]];
    check(
        'string del formulario vs int del token no cuenta como cambio',
        DesignSystem::diffManualTokens(['buttons' => ['radius' => '10']], $baseRadius) === [],
        'diff: ' . json_encode(DesignSystem::diffManualTokens(['buttons' => ['radius' => '10']], $baseRadius))
    );

    // --- 4. Los colores NO viajan como override manual --------------------
    $diffColors = DesignSystem::diffManualTokens(
        ['colors' => ['text' => '#abcdef']],
        ['colors' => ['text' => '#000000']]
    );
    check(
        'los colores no se guardan como override (van por la paleta)',
        $diffColors === [],
        'diff: ' . json_encode($diffColors)
    );

    // --- 5. Limpiar devuelve el control al skin ----------------------------
    BrandPaletteService::clear($siteId);
    DesignSystem::clearManualTokens($siteId);
    $back = DesignSystem::effective($siteId);
    check(
        'al limpiar, el texto vuelve al valor heredado',
        $back['colors']['text'] === $inherited['colors']['text'],
        'esperado ' . $inherited['colors']['text'] . ', obtenido ' . $back['colors']['text']
    );
    check(
        'al limpiar, la escala vuelve al valor heredado',
        (string) $back['typography']['scale_ratio'] === $inheritedScale,
        "esperado {$inheritedScale}, obtenido " . $back['typography']['scale_ratio']
    );

    // --- 6. `load()` sigue siendo crudo -----------------------------------
    BrandPaletteService::save($siteId, $palette);
    $raw = DesignSystem::load($siteId);
    check(
        'load() NO aplica la paleta (sigue siendo el valor crudo)',
        $raw['colors']['text'] !== '#101010' || $inherited['colors']['text'] === '#101010',
        'load() devolvió ' . $raw['colors']['text']
    );
} finally {
    $restore();
    echo PHP_EOL . 'Estado restaurado.' . PHP_EOL;
}

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : "{$failed} comprobaciones fallidas") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
