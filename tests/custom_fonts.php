<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\CustomFontService;
use App\Services\DesignSystem;
use Core\Database;

$failed = 0;
function check_font(string $name, bool $ok, string $detail = ''): void
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

$tmpDir = sys_get_temp_dir() . '/pp-font-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0775, true);

/** Crea un archivo temporal con la cabecera mágica indicada. */
function fake_font(string $dir, string $filename, string $magic): array
{
    $path = $dir . '/' . $filename;
    file_put_contents($path, $magic . str_repeat("\x00", 512));
    return [
        'name' => $filename,
        'tmp_name' => $path,
        'size' => filesize($path),
        'error' => UPLOAD_ERR_OK,
    ];
}

// Limpieza previa (el test es re-ejecutable).
foreach (CustomFontService::families($siteId) as $fam) {
    if (str_starts_with($fam['slug'], 'test-brandbook')) {
        CustomFontService::deleteFamily($siteId, (int) $fam['id']);
    }
}

// El sitio real puede tener sus propias tipografías de marca: las apartamos
// (guardando su rol) para que no compitan con las del test, y las devolvemos
// a su sitio al final. Sin esto el test pasa o falla según qué haya subido el
// usuario, que es la peor clase de test.
$realFamilies = [];
foreach (CustomFontService::families($siteId) as $fam) {
    $realFamilies[(int) $fam['id']] = (string) $fam['role'];
    Database::execute('UPDATE site_font_families SET role = ? WHERE id = ?', ['none', (int) $fam['id']]);
}
$realTypography = DesignSystem::load($siteId)['typography'];

$restore = static function () use ($realFamilies, $realTypography, $siteId): void {
    foreach ($realFamilies as $id => $role) {
        Database::execute('UPDATE site_font_families SET role = ? WHERE id = ?', [$role, $id]);
    }
    DesignSystem::saveCategory($siteId, 'typography', $realTypography);
};
register_shutdown_function($restore);

// ---------------------------------------------------------------
// 1. Alta de familia con 3 cortes
// ---------------------------------------------------------------
$familyId = CustomFontService::ensureFamily($siteId, 'Test Brandbook Sans', 'both');
check_font('ensureFamily crea la familia', $familyId > 0);

$r1 = CustomFontService::addFile($siteId, $familyId, fake_font($tmpDir, 'TestBrandbook-Regular.woff2', 'wOF2'));
$r2 = CustomFontService::addFile($siteId, $familyId, fake_font($tmpDir, 'TestBrandbook-Bold.woff2', 'wOF2'));
$r3 = CustomFontService::addFile($siteId, $familyId, fake_font($tmpDir, 'TestBrandbook-LightItalic.woff', 'wOFF'));

check_font('archivo 1 aceptado', $r1['ok'], (string) $r1['error']);
check_font('peso deducido del nombre: Regular → 400', $r1['weight'] === 400, 'weight=' . var_export($r1['weight'], true));
check_font('peso deducido del nombre: Bold → 700', $r2['weight'] === 700, 'weight=' . var_export($r2['weight'], true));
check_font('peso deducido del nombre: LightItalic → 300', $r3['weight'] === 300, 'weight=' . var_export($r3['weight'], true));
check_font('estilo deducido: LightItalic → italic', $r3['style'] === 'italic', 'style=' . var_export($r3['style'], true));

$fam = CustomFontService::familyBySlug($siteId, 'test-brandbook-sans');
check_font('la familia tiene 3 archivos', $fam !== null && count($fam['files']) === 3, 'files=' . count($fam['files'] ?? []));

// ---------------------------------------------------------------
// 2. @font-face: un bloque por archivo, con weight y style reales
// ---------------------------------------------------------------
$css = CustomFontService::renderFontFaceCss($siteId);
$ownBlocks = substr_count($css, 'font-family:"Test Brandbook Sans"');
check_font('@font-face: 3 bloques de la familia del test', $ownBlocks === 3, 'bloques=' . $ownBlocks);
check_font('@font-face: font-weight:700 presente', str_contains($css, 'font-weight:700'));
check_font('@font-face: font-style:italic presente', str_contains($css, 'font-style:italic'));
check_font('@font-face: font-display:swap en cada bloque', substr_count($css, 'font-display:swap') === substr_count($css, '@font-face'));
check_font('@font-face: formato woff declarado', str_contains($css, 'format("woff")'));
check_font('@font-face: apunta a la ruta servida por PHP', str_contains($css, 'brand-assets/' . $siteId . '/font/'));

// ---------------------------------------------------------------
// 3. Rechazo de archivos que no son fuentes
// ---------------------------------------------------------------
$png = fake_font($tmpDir, 'malicioso.woff2', "\x89PNG");
$bad = CustomFontService::addFile($siteId, $familyId, $png);
check_font('un PNG renombrado a .woff2 se rechaza', !$bad['ok'], 'error=' . var_export($bad['error'], true));

$exe = fake_font($tmpDir, 'algo.exe', 'MZ__');
$bad2 = CustomFontService::addFile($siteId, $familyId, $exe);
check_font('una extensión no permitida se rechaza', !$bad2['ok']);

$big = fake_font($tmpDir, 'grande.woff2', 'wOF2');
$big['size'] = 5 * 1024 * 1024;
$bad3 = CustomFontService::addFile($siteId, $familyId, $big);
check_font('un archivo de más de 3 MB se rechaza', !$bad3['ok']);

// ---------------------------------------------------------------
// 4. Sustitución de corte (subir Bold otra vez no duplica)
// ---------------------------------------------------------------
$again = CustomFontService::addFile($siteId, $familyId, fake_font($tmpDir, 'TestBrandbook-Bold.woff2', 'wOF2'));
$fam = CustomFontService::familyBySlug($siteId, 'test-brandbook-sans');
check_font('resubir el mismo corte sustituye, no duplica', $again['ok'] && count($fam['files']) === 3, 'files=' . count($fam['files'] ?? []));

// ---------------------------------------------------------------
// 5. Roles: un rol, una familia
// ---------------------------------------------------------------
$otherId = CustomFontService::ensureFamily($siteId, 'Test Brandbook Serif');
CustomFontService::addFile($siteId, $otherId, fake_font($tmpDir, 'TestSerif-Regular.otf', 'OTTO'));
CustomFontService::assignRole($siteId, $otherId, 'heading');

$sans = CustomFontService::familyBySlug($siteId, 'test-brandbook-sans');
$serif = CustomFontService::familyBySlug($siteId, 'test-brandbook-serif');
check_font('la familia que reclama títulos se queda con títulos', $serif['role'] === 'heading', 'role=' . ($serif['role'] ?? '?'));
check_font('la que era "ambas" conserva solo textos', $sans['role'] === 'body', 'role=' . ($sans['role'] ?? '?'));

$forHeading = CustomFontService::familyForRole($siteId, 'heading');
$forBody = CustomFontService::familyForRole($siteId, 'body');
check_font('familyForRole(heading) → serif', ($forHeading['slug'] ?? '') === 'test-brandbook-serif');
check_font('familyForRole(body) → sans', ($forBody['slug'] ?? '') === 'test-brandbook-sans');

// ---------------------------------------------------------------
// 6. Integración con DesignSystem
// ---------------------------------------------------------------
$options = DesignSystem::fontOptions($siteId);
check_font('las fuentes propias aparecen en las opciones del select', isset($options['custom:test-brandbook-sans']));

$cssValue = DesignSystem::fontCssValue('custom:test-brandbook-sans', $siteId);
check_font('fontCssValue resuelve custom: → nombre real', str_starts_with($cssValue, '"Test Brandbook Sans",'), $cssValue);

$tokens = DesignSystem::defaults();
$tokens['typography']['font_heading'] = 'custom:test-brandbook-serif';
$tokens['typography']['font_body'] = 'custom:test-brandbook-sans';
$google = DesignSystem::googleFontsUsed($tokens);
check_font('las fuentes propias NO se piden a Google', $google === [], implode(',', $google));

[$validTokens, $errors] = DesignSystem::validateCategory('typography', array_merge($tokens['typography'], ['font_heading' => 'custom:test-brandbook-serif']), $siteId);
check_font('validateCategory acepta una fuente propia existente', !isset($errors['font_heading']), (string) ($errors['font_heading'] ?? ''));

[, $errors2] = DesignSystem::validateCategory('typography', array_merge($tokens['typography'], ['font_heading' => 'custom:no-existe']), $siteId);
check_font('validateCategory rechaza una fuente propia inexistente', isset($errors2['font_heading']));

// ---------------------------------------------------------------
// 7. Precedencia: la fuente propia gana al skin y a la dirección visual
// ---------------------------------------------------------------
$head = DesignSystem::renderHead($siteId, 'editorial-serif');
check_font('renderHead incluye el @font-face', str_contains($head, '@font-face'));
check_font('renderHead impone la fuente propia en títulos', str_contains($head, '--pp-font-heading: "Test Brandbook Serif"'), substr($head, -400));
check_font('renderHead impone la fuente propia en textos', str_contains($head, '--pp-font-body: "Test Brandbook Sans"'));

// El bloque de fuentes propias debe ir DESPUÉS del CSS de la dirección visual.
$posOverride = strrpos($head, 'pp-custom-fonts');
check_font('el override de fuentes propias va al final del head', $posOverride !== false && $posOverride > (int) strpos($head, '<link'));

// ---------------------------------------------------------------
// 8. Borrado
// ---------------------------------------------------------------
$fileToDelete = (int) $sans['files'][0]['id'];
$absolute = PP_ROOT . '/' . $sans['files'][0]['path'];
check_font('el archivo existe en disco antes de borrar', is_file($absolute));
CustomFontService::deleteFile($siteId, $fileToDelete);
check_font('deleteFile borra también el archivo del disco', !is_file($absolute));

CustomFontService::deleteFamily($siteId, $familyId);
CustomFontService::deleteFamily($siteId, $otherId);
check_font('deleteFamily deja el sitio sin fuentes de prueba', CustomFontService::familyBySlug($siteId, 'test-brandbook-sans') === null);

$leftovers = Database::select('SELECT id FROM site_font_files WHERE family_id IN (?, ?)', [$familyId, $otherId]);
check_font('no quedan archivos huérfanos en BD', $leftovers === []);

// Limpieza
array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : $failed . ' fallos') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
