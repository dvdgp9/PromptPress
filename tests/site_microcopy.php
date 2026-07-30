<?php

declare(strict_types=1);

/**
 * Microcopy del frontend (paso 2): los textos automáticos del sitio siguen a
 * `sites.language`, y lo que ha escrito el usuario NO se pisa jamás.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\LanguageService;
use App\Services\Microcopy;

$failed = 0;
function checkMicrocopy(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
        }
    }
}

// ---------------------------------------------------------------------------
// 1. El diccionario está completo en TODOS los idiomas admitidos
// ---------------------------------------------------------------------------

$incomplete = [];
foreach (array_keys(LanguageService::LANGUAGES) as $code) {
    $missing = Microcopy::missing($code);
    if ($missing !== []) {
        $incomplete[] = $code . ': ' . implode(', ', array_slice($missing, 0, 6));
    }
}
checkMicrocopy(
    'dictionary_complete_for_every_language',
    $incomplete === [],
    implode("\n", $incomplete)
);

checkMicrocopy(
    'translations_actually_differ_from_spanish',
    Microcopy::t('form.submit', 'fr') === 'Envoyer'
        && Microcopy::t('form.submit', 'eu') === 'Bidali'
        && Microcopy::t('page.home', 'fr') === 'Accueil'
        && Microcopy::t('cookies.accept', 'fr') === 'Tout accepter',
    Microcopy::t('form.submit', 'fr') . ' / ' . Microcopy::t('page.home', 'fr')
);

checkMicrocopy(
    'unknown_language_falls_back_to_spanish',
    Microcopy::t('form.submit', 'klingon') === 'Enviar',
    Microcopy::t('form.submit', 'klingon')
);

checkMicrocopy(
    'unknown_key_returns_empty_string',
    Microcopy::t('no.existe', 'fr') === '',
    Microcopy::t('no.existe', 'fr')
);

// ---------------------------------------------------------------------------
// 2. resolve(): el texto del usuario manda; el default castellano no
// ---------------------------------------------------------------------------

checkMicrocopy(
    'user_text_is_never_overwritten',
    Microcopy::resolve('Escríbenos ya', 'form.submit', 'fr') === 'Escríbenos ya',
    Microcopy::resolve('Escríbenos ya', 'form.submit', 'fr')
);

checkMicrocopy(
    'empty_text_uses_site_language',
    Microcopy::resolve('', 'form.submit', 'fr') === 'Envoyer'
        && Microcopy::resolve(null, 'cookies.accept', 'pt') === 'Aceitar todas',
    Microcopy::resolve('', 'form.submit', 'fr')
);

// Instalaciones anteriores tienen guardado el default castellano sin haberlo
// elegido nadie: eso sí se traduce.
checkMicrocopy(
    'legacy_spanish_default_is_translated',
    Microcopy::resolve('Aceptar todas', 'cookies.accept', 'fr') === 'Tout accepter',
    Microcopy::resolve('Aceptar todas', 'cookies.accept', 'fr')
);

// ...pero en un sitio en castellano el resultado sigue siendo el mismo texto.
checkMicrocopy(
    'spanish_site_is_unchanged',
    Microcopy::resolve('Aceptar todas', 'cookies.accept', 'es') === 'Aceptar todas'
        && Microcopy::resolve('', 'form.submit', 'es') === 'Enviar',
    'regresión: un sitio en castellano no debe notar ningún cambio'
);

// ---------------------------------------------------------------------------
// 3. Los puntos de uso ya no llevan el texto fijo en castellano
// ---------------------------------------------------------------------------

$wired = [
    'app/Services/Renderer/SectionRenderer.php' => ["'Enviar'", "'Gracias, hemos recibido tu mensaje.'"],
    'app/Controllers/Public/FormController.php' => ["?: 'Gracias, hemos recibido tu mensaje.'"],
    'app/Services/BrandService.php'             => ["'Explora'", "'Síguenos'", "'Suscribirme'", '>Configurar cookies<'],
    'app/Services/Compliance/CookieBanner.php'  => ["?? 'Aceptar todas'", "?? 'Cookies en este sitio'", "'Guardar elección',"],
];
$leftovers = [];
foreach ($wired as $file => $needles) {
    $src = (string) file_get_contents(PP_ROOT . '/' . $file);
    foreach ($needles as $needle) {
        if (str_contains($src, $needle)) {
            $leftovers[] = $file . ' → ' . $needle;
        }
    }
}
checkMicrocopy(
    'render_layer_has_no_hardcoded_spanish',
    $leftovers === [],
    implode("\n", $leftovers)
);

// El menú automático sale de los títulos de página: el plan de reserva del
// onboarding tiene que estar localizado o el menú nace en castellano.
$onboarding = (string) file_get_contents(PP_ROOT . '/app/Controllers/Admin/OnboardingController.php');
checkMicrocopy(
    'fallback_page_plan_is_localised',
    !str_contains($onboarding, "'title' => 'Inicio'")
        && !str_contains($onboarding, "'title' => 'Servicios'")
        && str_contains($onboarding, "\$t('page.home')"),
    'El plan base del onboarding debe usar Microcopy para los títulos'
);

checkMicrocopy(
    'page_hierarchy_lookup_is_language_aware',
    str_contains($onboarding, 'findPageIdByType($siteId, \'home\')')
        && str_contains($onboarding, "slugify(\$localHome)"),
    'resolveParentIdForPage debe encontrar la home aunque no se llame "Inicio"'
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
