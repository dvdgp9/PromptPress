<?php

// FORMS-LANG — Los formularios en el idioma de la web.
//
// Cubre las dos mitades del plan:
//   Fase 1 — un formulario nuevo nace en el idioma principal del sitio y el
//            renderizador no cuela castellano en una web en otro idioma.
//   Fase 2 — un mismo formulario se pinta en el idioma de cada página.
//
// Sin llamadas a IA: la traducción se simula pasando el JSON que devolvería el
// modelo, que es justo lo que hay que saber sanear.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use App\Services\FormI18n;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  → ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

// ======================================================================
// 1. Plantillas: el texto se traduce, los `name` NO
// ======================================================================
$es = FormTemplates::content('contact', 'es');
$fr = FormTemplates::content('contact', 'fr');

check('plantilla_es_en_castellano', $es['heading'] === 'Contacta con nosotros', $es['heading']);
check('plantilla_fr_en_frances', $fr['heading'] === 'Contactez-nous', $fr['heading']);
check('boton_fr_traducido', $fr['submit_text'] === 'Envoyer', $fr['submit_text']);

$namesEs = array_column($es['fields'], 'name');
$namesFr = array_column($fr['fields'], 'name');
check('names_no_se_traducen', $namesEs === $namesFr && $namesEs === ['nombre', 'email', 'mensaje'], implode(',', $namesFr));
check('labels_si_se_traducen', array_column($fr['fields'], 'label') === ['Nom', 'E-mail', 'Message'], implode(',', array_column($fr['fields'], 'label')));
check('idioma_base_sellado', ($fr['language'] ?? '') === 'fr', (string) ($fr['language'] ?? ''));

// El autorespondedor conserva sus variables en todos los idiomas.
$tokensOk = true;
foreach (array_keys(LanguageService::LANGUAGES) as $code) {
    $c = FormTemplates::content('contact', $code);
    if (!str_contains($c['autoresponder_body'], '{{nombre}}') || !str_contains($c['autoresponder_body'], '{{sitio}}')) {
        $tokensOk = false;
    }
}
check('autorespondedor_conserva_variables', $tokensOk);

// El periodo de conservación del RGPD también viaja traducido.
check('retencion_traducida', $fr['retention_period'] === Microcopy::t('form.retention_default', 'fr'), $fr['retention_period']);
check('retencion_job_traducida', FormTemplates::content('job', 'fr')['retention_period'] === Microcopy::t('form.retention_job', 'fr'));

// ======================================================================
// 2. Microcopy: ningún idioma se queda sin las claves de formulario
// ======================================================================
$formKeys = array_values(array_filter(Microcopy::keys(), static fn(string $k): bool => str_starts_with($k, 'form.')));
$holes = [];
foreach (array_keys(LanguageService::LANGUAGES) as $code) {
    foreach ($formKeys as $key) {
        if (trim(Microcopy::t($key, $code)) === '' && $key !== 'form.tpl.autoresponder_body') {
            $holes[] = $code . '/' . $key;
        }
    }
}
check('microcopy_formularios_sin_huecos', $holes === [], implode(' ', $holes));

// ======================================================================
// 3. FormI18n: resolución, saneado e invariantes
// ======================================================================
$base = [
    'language' => 'fr',
    'heading' => 'Contactez-nous',
    'submit_text' => 'Envoyer',
    'success_message' => 'Merci !',
    'autoresponder_body' => "Bonjour {{nombre}},\n\n{{sitio}}",
    'fields' => [
        ['label' => 'Nom', 'name' => 'nombre', 'field_type' => 'text', 'required' => '1', 'placeholder' => ''],
        ['label' => 'Message', 'name' => 'mensaje', 'field_type' => 'textarea', 'required' => '1', 'placeholder' => ''],
    ],
];

// Lo que devolvería la IA traduciendo al castellano, con basura incluida.
$aiOutput = [
    'heading' => 'Contacta con nosotros',
    'submit_text' => 'Enviar',
    'success_message' => '¡Gracias!',
    'lawful_basis' => 'consent',                    // no es texto: debe ignorarse
    'fields' => [
        'nombre'    => ['label' => 'Nombre', 'name' => 'name'],  // `name` debe ignorarse
        'mensaje'   => ['label' => 'Mensaje'],
        'inventado' => ['label' => 'Campo que no existe'],       // debe ignorarse
    ],
];
$withEs = FormI18n::withTranslation($base, 'es', $aiOutput);

check('traduccion_guardada_bajo_idioma', isset($withEs['i18n']['es']['heading']));
check('base_intacta', $withEs['heading'] === 'Contactez-nous', $withEs['heading']);
check('no_cuela_claves_no_texto', !isset($withEs['i18n']['es']['lawful_basis']));
check('no_cuela_campos_inventados', !isset($withEs['i18n']['es']['fields']['inventado']));
check('no_cuela_name_traducido', !isset($withEs['i18n']['es']['fields']['nombre']['name']));

$resEs = FormI18n::resolve($withEs, 'es');
$resFr = FormI18n::resolve($withEs, 'fr');
check('resuelve_es', $resEs['heading'] === 'Contacta con nosotros' && $resEs['fields'][0]['label'] === 'Nombre');
check('resuelve_fr_base', $resFr['heading'] === 'Contactez-nous' && $resFr['fields'][0]['label'] === 'Nom');
check('name_igual_en_los_dos_idiomas',
    array_column($resEs['fields'], 'name') === array_column($resFr['fields'], 'name'));

$resPt = FormI18n::resolve($withEs, 'pt');
check('idioma_sin_traduccion_cae_a_la_base', $resPt['heading'] === 'Contactez-nous', $resPt['heading']);

// Una traducción que se come las variables del autorespondedor se descarta.
$sinTokens = FormI18n::withTranslation($base, 'es', ['autoresponder_body' => 'Hola, gracias.']);
check('autorespondedor_sin_variables_rechazado', !isset($sinTokens['i18n']['es']['autoresponder_body']));
$conTokens = FormI18n::withTranslation($base, 'es', ['autoresponder_body' => "Hola {{nombre}},\n\n{{sitio}}"]);
check('autorespondedor_con_variables_aceptado', isset($conTokens['i18n']['es']['autoresponder_body']));

// Reescribir la base: es el arreglo de las webs que heredaron el castellano.
$esBase = ['language' => 'es', 'heading' => 'Contacta con nosotros', 'submit_text' => 'Enviar',
    'fields' => [['label' => 'Nombre', 'name' => 'nombre', 'field_type' => 'text']]];
$rebased = FormI18n::withBaseTexts($esBase, 'fr', ['heading' => 'Contactez-nous', 'submit_text' => 'Envoyer',
    'fields' => ['nombre' => ['label' => 'Nom']]]);
check('base_reescrita', $rebased['heading'] === 'Contactez-nous' && $rebased['language'] === 'fr');
check('base_reescrita_conserva_name', $rebased['fields'][0]['name'] === 'nombre');

// Formulario heredado sin `language`: se asume castellano, no se rompe.
check('form_heredado_asume_castellano', FormI18n::baseLanguage(['heading' => 'Hola']) === 'es');

// ======================================================================
// 4. Render: el formulario se pinta en el idioma que se le pide
// ======================================================================
$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);

if ($siteId > 0) {
    $section = [
        'id' => 0,
        'section_type' => 'form',
        'content' => json_encode($withEs, JSON_UNESCAPED_UNICODE),
        'style' => '{}',
    ];

    SectionRenderer::setSiteContext($siteId, 'fr');
    $htmlFr = SectionRenderer::render($section);
    SectionRenderer::setSiteContext($siteId, 'es');
    $htmlEs = SectionRenderer::render($section);

    check('render_fr_usa_textos_fr', str_contains($htmlFr, 'Contactez-nous') && str_contains($htmlFr, 'Envoyer'), mb_substr($htmlFr, 0, 200));
    check('render_es_usa_textos_es', str_contains($htmlEs, 'Contacta con nosotros') && str_contains($htmlEs, 'Enviar'), mb_substr($htmlEs, 0, 200));
    check('render_marca_el_idioma', str_contains($htmlFr, 'name="_lang" value="fr"'));

    // Nota de privacidad y microcopy incrustado, en francés.
    check('privacidad_en_frances', str_contains($htmlFr, 'Vos données seront traitées'), mb_substr($htmlFr, -400));
    check('privacidad_no_deja_castellano', !str_contains($htmlFr, 'Tus datos se tratarán'));

    // Un select y un file en francés: los textos que estaban incrustados.
    $withExtras = $withEs;
    $withExtras['fields'][] = ['label' => 'Sujet', 'name' => 'asunto', 'field_type' => 'select', 'required' => '0', 'placeholder' => '', 'options' => ['A', 'B']];
    $withExtras['fields'][] = ['label' => 'CV', 'name' => 'cv', 'field_type' => 'file', 'required' => '0', 'placeholder' => '', 'file_accept' => 'documents', 'file_max_mb' => 5];
    SectionRenderer::setSiteContext($siteId, 'fr');
    $htmlExtras = SectionRenderer::render(['id' => 0, 'section_type' => 'form',
        'content' => json_encode($withExtras, JSON_UNESCAPED_UNICODE), 'style' => '{}']);
    check('select_en_frances', str_contains($htmlExtras, 'Sélectionnez une option'), '');
    check('file_en_frances', str_contains($htmlExtras, 'Choisir un fichier') && str_contains($htmlExtras, 'Aucun fichier sélectionné'), '');
    check('ayuda_de_archivo_en_frances', str_contains($htmlExtras, 'Formats acceptés'), '');
    check('nada_de_castellano_incrustado',
        !str_contains($htmlExtras, 'Selecciona una opción')
        && !str_contains($htmlExtras, 'Seleccionar archivo')
        && !str_contains($htmlExtras, 'Ningún archivo seleccionado')
        && !str_contains($htmlExtras, 'Formatos permitidos'), '');
}

// ======================================================================
// 5. El canvas no pierde el idioma por el camino (T1)
// ======================================================================
if ($siteId > 0) {
    $formId = FormStore::createFromTemplate($siteId, 'contact');
    $form = FormStore::find($siteId, $formId);
    $primary = LanguageService::primaryFor($siteId);
    check('form_nuevo_nace_en_idioma_principal', FormI18n::baseLanguage((array) $form) === $primary,
        FormI18n::baseLanguage((array) $form) . ' vs ' . $primary);

    // Se le añade a mano una traducción al francés y se pide el canvas en fr.
    $content = (array) $form;
    unset($content['id']);
    $content = FormI18n::withTranslation($content, 'fr', [
        'heading' => 'Contactez-nous', 'submit_text' => 'Envoyer',
        'fields' => ['nombre' => ['label' => 'Nom']],
    ]);
    FormStore::update($siteId, $formId, $content);

    $hasForm = false;
    $out = CanvasService::expandPlaceholders('{{form:' . $formId . '}}', $siteId, $hasForm, 'fr');
    check('canvas_respeta_el_idioma_pedido', str_contains($out, 'Envoyer') && str_contains($out, 'Nom'), mb_substr($out, 0, 300));

    $hasForm2 = false;
    $outEs = CanvasService::expandPlaceholders('{{form:' . $formId . '}}', $siteId, $hasForm2, $primary);
    check('canvas_en_idioma_base_sigue_igual', !str_contains($outEs, 'Envoyer'), mb_substr($outEs, 0, 300));

    // Limpieza: el formulario de prueba fuera.
    FormStore::delete($siteId, $formId);
    Database::execute("DELETE FROM page_sections WHERE id = ?", [$formId]);
}

$left = Database::selectOne(
    "SELECT COUNT(*) c FROM page_sections ps JOIN pages p ON p.id = ps.page_id
     WHERE p.slug = '__forms' AND ps.section_type = 'form' AND ps.content LIKE '%Contactez-nous%'
       AND ps.status != 'deleted'"
);
check('test_no_deja_rastro', (int) ($left['c'] ?? 0) === 0, 'quedan ' . (int) ($left['c'] ?? 0));

echo PHP_EOL . ($failed > 0 ? $failed . ' FALLOS' : 'OK') . PHP_EOL;
exit($failed > 0 ? 1 : 0);
