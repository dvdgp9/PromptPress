<?php

/**
 * ONB2 O2.8 — Paso 2 del onboarding: identidad visual.
 *
 * Cubre lo que se puede romper en silencio: la extracción de color del logo,
 * el validador de contraste (que es quien decide si una paleta se enseña o se
 * tira), la persistencia de la paleta y su consumo por el motor, y que un sitio
 * SIN paleta a medida se comporte exactamente igual que antes.
 *
 * No llama a la IA: la generación se prueba con la respuesta ya recibida.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandColorExtractor;
use App\Services\BrandPaletteService;
use App\Services\BrandService;
use App\Services\DesignSystem;
use App\Services\VisualStyleService;
use Core\Database;

$failed = 0;
function check_onb(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);
if ($siteId <= 0) {
    echo 'FAIL sin sitio en la base de datos' . PHP_EOL;
    exit(1);
}

// La paleta del sitio se toca en varias comprobaciones: se guarda al empezar y
// se restaura al final, pase lo que pase.
$originalPalette = BrandPaletteService::forSite($siteId);

$tmpDir = sys_get_temp_dir() . '/pp-onb2-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

// =====================================================================
// 1. Extracción de color del logo
// =====================================================================

$png = $tmpDir . '/logo.png';
$im = imagecreatetruecolor(300, 120);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
imagefilledrectangle($im, 0, 0, 140, 119, imagecolorallocate($im, 0x1f, 0x4e, 0xff));
imagefilledrectangle($im, 141, 0, 240, 119, imagecolorallocate($im, 0xff, 0x8a, 0x3d));
imagefilledrectangle($im, 241, 0, 299, 119, imagecolorallocate($im, 0x0a, 0x0a, 0x0a));
imagepng($im, $png);
imagedestroy($im);

$colors = BrandColorExtractor::fromFile($png);
check_onb(
    'logo_png_devuelve_los_colores_de_marca',
    $colors === ['#1f4eff', '#ff8a3d'],
    'obtenido: ' . implode(', ', $colors)
);
check_onb('logo_png_descarta_el_negro_de_contorno', !in_array('#0a0a0a', $colors, true));

$svg = $tmpDir . '/logo.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
    . '<rect fill="#1f4eff" width="5" height="10"/><path fill="#ff8a3d" d="M5 0h3v10H5z"/>'
    . '<circle style="fill:#0a0a0a" cx="9" cy="5" r="1"/></svg>');
check_onb(
    'logo_svg_lee_los_colores_del_xml',
    BrandColorExtractor::fromFile($svg) === ['#1f4eff', '#ff8a3d'],
    'obtenido: ' . implode(', ', BrandColorExtractor::fromFile($svg))
);

// Un logo monocromo no tiene "paleta": devolver cinco grises casi iguales sería
// ruido disfrazado de información. Se espera UN neutro (la tinta).
$mono = $tmpDir . '/mono.png';
$im = imagecreatetruecolor(200, 80);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);
imagefilledrectangle($im, 10, 10, 190, 70, imagecolorallocate($im, 0x22, 0x24, 0x29));
imagepng($im, $mono);
imagedestroy($im);
$monoColors = BrandColorExtractor::fromFile($mono);
check_onb(
    'logo_monocromo_devuelve_un_solo_neutro',
    count($monoColors) === 1,
    'obtenido: ' . implode(', ', $monoColors)
);

check_onb('logo_inexistente_no_revienta', BrandColorExtractor::fromFile($tmpDir . '/no-existe.png') === []);

// =====================================================================
// 2. Validador de contraste
// =====================================================================

// Paleta deliberadamente mala: la clase de propuesta que devuelve un modelo
// cuando "cuida los contrastes" de boquilla.
$mala = [
    'bg' => '#ffffff', 'surface' => '#fefefe', 'text' => '#bbbbbb', 'muted' => '#dddddd',
    'line' => '#fefefe', 'accent' => '#ffe680', 'accent_dark' => '#fff0b0', 'accent_2' => '#f5f5f5',
];
check_onb('paleta_mala_se_detecta', BrandPaletteService::contrastIssues($mala) !== []);

$corregida = BrandPaletteService::enforceContrast($mala);
check_onb('paleta_mala_se_corrige', $corregida !== null);
check_onb(
    'paleta_corregida_cumple_todos_los_minimos',
    $corregida !== null && BrandPaletteService::contrastIssues($corregida) === [],
    $corregida === null ? 'descartada' : implode(' | ', BrandPaletteService::contrastIssues($corregida))
);
check_onb(
    'texto_y_texto_apagado_no_acaban_en_el_mismo_tono',
    $corregida !== null && $corregida['text'] !== $corregida['muted']
        && BrandPaletteService::contrast($corregida['text'], $corregida['bg'])
           > BrandPaletteService::contrast($corregida['muted'], $corregida['bg']),
    $corregida === null ? '-' : ('text=' . $corregida['text'] . ' muted=' . $corregida['muted'])
);
check_onb(
    'la_etiqueta_del_boton_la_decide_el_contraste',
    $corregida !== null
        && BrandPaletteService::contrast(BrandPaletteService::labelOn($corregida['accent']), $corregida['accent']) >= 4.5
);

// Una paleta oscura ya correcta no se toca.
$oscura = [
    'bg' => '#0d121c', 'surface' => '#16203a', 'text' => '#f4f6fb', 'muted' => '#8a92ad',
    'line' => '#283456', 'accent' => '#ff8a3d', 'accent_dark' => '#cc5e15', 'accent_2' => '#5fc8d7',
];
check_onb('paleta_correcta_se_respeta', BrandPaletteService::enforceContrast($oscura) === $oscura);

check_onb('paleta_incompleta_se_rechaza', BrandPaletteService::normalize(['bg' => '#ffffff']) === null);
check_onb('hex_de_3_digitos_se_expande', BrandPaletteService::normalize([
    'bg' => '#fff', 'surface' => '#eee', 'text' => '#111', 'muted' => '#666',
    'line' => '#ccc', 'accent' => '#f00', 'accent_dark' => '#900', 'accent_2' => '#00f',
])['bg'] === '#ffffff');

// Reserva sin IA: tienen que salir paletas y todas legibles.
$fallback = BrandPaletteService::fallbackProposals(['#1f4eff']);
check_onb('propuestas_de_reserva_sin_ia', count($fallback) >= 2, 'obtenidas: ' . count($fallback));
$fallbackOk = true;
foreach ($fallback as $proposal) {
    if (BrandPaletteService::contrastIssues($proposal['tokens']) !== []) $fallbackOk = false;
}
check_onb('propuestas_de_reserva_cumplen_contraste', $fallbackOk);

// =====================================================================
// 3. Persistencia y consumo
// =====================================================================

$elegida = [
    'bg' => '#222429', 'surface' => '#2d3036', 'text' => '#f1f5f9', 'muted' => '#94a3b8',
    'line' => '#334155', 'accent' => '#16a34a', 'accent_dark' => '#15803d', 'accent_2' => '#ff8a3d',
];
BrandPaletteService::save($siteId, $elegida);
check_onb('paleta_guardada_se_recupera_igual', BrandPaletteService::forSite($siteId) === $elegida);

$slug = (string) (Database::selectOne(
    'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
    [$siteId, VisualStyleService::SETTING_KEY]
)['setting_value'] ?? 'default');
check_onb(
    'el_motor_sirve_la_paleta_a_medida',
    VisualStyleService::paletteForSite($siteId, $slug) === $elegida
);

$tokens = DesignSystem::applyCustomPaletteToTokens($siteId, DesignSystem::load($siteId));
check_onb('los_tokens_del_design_system_reciben_la_paleta',
    $tokens['colors']['primary'] === $elegida['accent']
    && $tokens['colors']['bg'] === $elegida['bg']
    && $tokens['colors']['text'] === $elegida['text']
    && $tokens['colors']['border'] === $elegida['line']);

check_onb(
    'la_paleta_elegida_gana_al_skin_inferido',
    str_contains(DesignSystem::renderHead($siteId), '--pp-bg: ' . $elegida['bg']),
    'el <head> público no lleva el fondo de la paleta'
);

// Sin paleta a medida, el motor tiene que comportarse como antes de ONB2.
$sinPaleta = BrandPaletteService::forSite($siteId);
BrandPaletteService::clear($siteId);
check_onb('sin_paleta_a_medida_no_hay_paleta', BrandPaletteService::forSite($siteId) === null);
$tokensSin = DesignSystem::applyCustomPaletteToTokens($siteId, DesignSystem::load($siteId));
check_onb(
    'sin_paleta_a_medida_los_tokens_no_se_tocan',
    $tokensSin === DesignSystem::load($siteId)
);
check_onb(
    'sin_paleta_a_medida_el_motor_vuelve_al_catalogo',
    VisualStyleService::paletteForSite($siteId, $slug) !== $elegida
);

// =====================================================================
// 4. Contrato con el resto del panel
// =====================================================================

// El paso 2 escribe estas claves; si BrandService las renombra, el logo subido
// en el onboarding dejaría de verse y nadie se enteraría hasta producción.
check_onb(
    'las_variantes_de_logo_son_las_que_espera_el_panel',
    (BrandService::LOGO_VARIANTS['light']['setting'] ?? '') === 'site_logo_path'
    && (BrandService::LOGO_VARIANTS['dark']['setting'] ?? '') === 'site_logo_dark_path'
);
check_onb(
    'la_paleta_declara_las_mismas_claves_que_el_catalogo',
    BrandPaletteService::KEYS === array_keys(\App\Services\PalettePresets::tokens('studio-mono'))
);

// =====================================================================
// PALETA-2 — El segundo color de marca tiene que LLEGAR a la web.
//
// Se guardaba bien y no lo pintaba nadie: el token «secundario» recibía el
// color del TEXTO, y el prompt de composición no incluía `var(--pp-accent)`
// en su lista cerrada de tokens permitidos ("SOLO vía tokens del sitio"), así
// que el modelo no podía usarlo aunque quisiera. Medido en la base de dev:
// 142 usos de --pp-primary y 0 de --pp-accent en 20 páginas canvas.
// =====================================================================

$dosPalette = BrandPaletteService::enforceContrast([
    'bg' => '#ffffff', 'surface' => '#f2f7f3', 'text' => '#12211a', 'muted' => '#5b6b62',
    'line' => '#d8e6dc', 'accent' => '#0f7a4a', 'accent_dark' => '#0a5c37', 'accent_2' => '#e0559b',
]);
check_onb('la_paleta_de_dos_colores_de_marca_es_valida', $dosPalette !== null);

if ($dosPalette !== null) {
    BrandPaletteService::save($siteId, $dosPalette);
    $paletteTokens = DesignSystem::applyCustomPaletteToTokens($siteId, DesignSystem::load($siteId));
    $colors = $paletteTokens['colors'] ?? [];

    check_onb('el_principal_es_el_acento_de_la_paleta',
        ($colors['primary'] ?? '') === $dosPalette['accent']);
    check_onb('el_acento_es_el_segundo_color_de_marca',
        ($colors['accent'] ?? '') === $dosPalette['accent_2']);
    // La regresión concreta que reportó el usuario: «el rosa se quedó como
    // secundario pero no se ve». Secundario era el color del texto.
    check_onb('secundario_es_el_segundo_color_de_marca_y_no_el_texto',
        ($colors['secondary'] ?? '') === $dosPalette['accent_2']
        && ($colors['secondary'] ?? '') !== $dosPalette['text']);

    $publicCss = DesignSystem::renderCssVars($paletteTokens, $siteId);
    check_onb('el_segundo_color_sale_en_el_css_publico',
        str_contains($publicCss, '--pp-accent: ' . $dosPalette['accent_2']));
}

// Contrato del prompt: si `var(--pp-accent)` desaparece de la lista de tokens
// permitidos, la generación vuelve a ignorar el segundo color en silencio.
$compose = \App\Services\AI\Actions::get(\App\Services\AI\Actions::COMPOSE_CANVAS_PAGE);
$instruction = (string) ($compose['instruction'] ?? '');
check_onb('el_prompt_de_composicion_permite_el_acento',
    str_contains($instruction, 'var(--pp-accent)'));
check_onb('el_prompt_pide_usarlo_menos_que_el_principal',
    str_contains($instruction, 'ACENTO SECUNDARIO') && str_contains($instruction, 'menos presencia'));

// =====================================================================
// Limpieza: ni ficheros ni filas de prueba.
// =====================================================================

foreach ([$png, $svg, $mono] as $file) @unlink($file);
@rmdir($tmpDir);

if ($originalPalette !== null) {
    BrandPaletteService::save($siteId, $originalPalette);
} else {
    BrandPaletteService::clear($siteId);
}
check_onb(
    'el_estado_del_sitio_queda_como_estaba',
    BrandPaletteService::forSite($siteId) === $originalPalette
);
check_onb('sin_ficheros_temporales', !is_dir($tmpDir));

echo PHP_EOL . ($failed === 0 ? 'OK — todas las comprobaciones pasan' : "FALLOS: {$failed}") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
