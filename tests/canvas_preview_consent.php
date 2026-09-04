<?php

declare(strict_types=1);

// STUDIO-UX A1 — El preview del Studio no es el sitio público: dentro del
// editor no hay a quién pedirle consentimiento, y el banner ocupaba el pie de
// forma permanente (en marco móvil, ~40% del viewport). El pie sigue siendo el
// mismo; lo que desaparece es el chrome de consentimiento.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\BrandService;
use Core\Database;

$failed = 0;
function previewConsentCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
previewConsentCheck('hay sitio para probar', $siteId > 0);

// --- El pie público no cambia -----------------------------------------------
$public = BrandService::publicFooter($siteId);
previewConsentCheck('publico_monta_el_banner', str_contains($public, 'pp-cookie-banner-root'));
previewConsentCheck('publico_lleva_la_config_de_consent', str_contains($public, 'PP_COOKIE_CONFIG'));
previewConsentCheck('publico_es_un_pie_completo', str_contains($public, 'pp-site-footer'));

// --- El pie del editor: mismo pie, sin consentimiento ------------------------
$editor = BrandService::publicFooter($siteId, null, null, false);
previewConsentCheck('editor_sin_raiz_de_banner', !str_contains($editor, 'pp-cookie-banner-root'), $editor);
previewConsentCheck('editor_sin_config_de_consent', !str_contains($editor, 'PP_COOKIE_CONFIG'));
previewConsentCheck('editor_sin_enlace_de_reabrir', !str_contains($editor, 'data-cb-reopen'));
previewConsentCheck('editor_sigue_siendo_un_pie_completo', str_contains($editor, 'pp-site-footer'));
previewConsentCheck(
    'editor_conserva_el_copyright',
    str_contains($editor, 'pp-site-footer__copy')
);

// El resto del pie tiene que ser byte a byte lo mismo: lo único que cambia es
// el consentimiento, no la marca, la navegación ni los enlaces legales.
$publicWithoutConsent = preg_replace('~<div id="pp-cookie-banner-root".*$~s', '', $public);
$publicWithoutConsent = preg_replace(
    '~<a class="pp-site-footer__link" href="#" data-cb-reopen>.*?</a>~s',
    '',
    (string) $publicWithoutConsent
);
previewConsentCheck(
    'editor_solo_pierde_el_consentimiento',
    strip_tags((string) $publicWithoutConsent) === strip_tags($editor),
    'público: ' . mb_substr(strip_tags((string) $publicWithoutConsent), -160)
        . ' | editor: ' . mb_substr(strip_tags($editor), -160)
);

// --- El Studio lo pide así ---------------------------------------------------
$controller = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/CanvasController.php');
previewConsentCheck(
    'preview_del_studio_pide_el_pie_sin_consentimiento',
    str_contains($controller, 'BrandService::publicFooter($siteId, null, $pageLang, false)')
);

echo $failed === 0 ? PHP_EOL . 'OK' . PHP_EOL : PHP_EOL . $failed . ' FALLOS' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
