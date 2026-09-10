<?php

declare(strict_types=1);

/**
 * RSRC-FORM — Crear la puerta de descarga desde el propio editor de recursos.
 *
 * Lo que se protege aquí:
 *  - que el formulario nazca bien (plantilla `download`, idioma del sitio,
 *    encabezado con el título del recurso);
 *  - que el botón NO esté al alcance de un redactor, que puede editar recursos
 *    (`content`) pero no crear formularios (`forms`). Esa es la parte que un
 *    repaso a ojo no ve: hace falta entrar con ese rol para descubrirlo.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Resources\ResourceStore;
use App\Services\FormStore;
use App\Services\FormTemplates;
use Core\Database;

$failed = 0;
function rfCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// 1. La plantilla nueva, como modelo puro.
// ---------------------------------------------------------------------------

rfCheck('la_plantilla_download_existe', FormTemplates::exists('download'));
rfCheck('esta_en_el_catalogo', in_array('download', FormTemplates::keys(), true));

$tpl = FormTemplates::content('download', 'es');
rfCheck('pide_nombre_y_email', array_column($tpl['fields'], 'name') === ['nombre', 'email'], json_encode(array_column($tpl['fields'], 'name')) ?: '');
rfCheck('los_dos_son_obligatorios', array_column($tpl['fields'], 'required') === ['1', '1']);
// Le pides el email a cambio del archivo: eso es consentimiento, no «interés legítimo».
rfCheck('base_legal_consentimiento', ($tpl['lawful_basis'] ?? '') === 'consent', (string) ($tpl['lawful_basis'] ?? ''));
// No presuponer marketing: el consentimiento es para mandarle el recurso.
rfCheck('sin_marketing_por_defecto', ($tpl['marketing_opt_in'] ?? '1') === '0');
rfCheck('form_type_propio', ($tpl['form_type'] ?? '') === 'download');

// FORMS-LANG — el texto se copia a la BD en el idioma que se pida.
$tplEn = FormTemplates::content('download', 'en');
rfCheck('la_plantilla_habla_ingles', ($tplEn['language'] ?? '') === 'en' && $tplEn['heading'] !== $tpl['heading'], (string) ($tplEn['heading'] ?? ''));
rfCheck('los_name_no_se_traducen', array_column($tplEn['fields'], 'name') === ['nombre', 'email']);

// Etiqueta del catálogo para el panel, en los cuatro idiomas.
foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    rfCheck("catalogo_traducido_{$lang}", isset($catalog['form_tpl.download.label'], $catalog['form_tpl.download.desc']));
}

// ---------------------------------------------------------------------------
// 2. La vista: el botón vive tras la capacidad `forms`.
// ---------------------------------------------------------------------------

$view = (string) file_get_contents(PP_ROOT . '/views/admin/resources/edit.php');
rfCheck('el_boton_pregunta_por_la_capacidad', str_contains($view, 'Permissions::CAP_FORMS'));
rfCheck('el_desplegable_se_pinta_siempre', !str_contains($view, 'if ($forms === []):'));
// El enlace «afinar» sí compone base_url('admin/formularios') . '/' . id, y eso
// es legítimo: abre EL formulario en otra pestaña. Lo que no debe volver es el
// enlace pelado a la lista, que era el que se llevaba por delante el editor.
rfCheck(
    'ya_no_manda_fuera_del_editor',
    !str_contains($view, 'href="<?= e(base_url(\'admin/formularios\')) ?>"'),
    'sigue habiendo un enlace pelado a la lista de formularios'
);
rfCheck('el_atajo_abre_en_otra_pestana', str_contains($view, 'data-form-tune') && str_contains($view, 'target="_blank"'));

$controller = (string) file_get_contents(PP_ROOT . '/app/Modules/Resources/ResourceAdminController.php');
rfCheck('el_servidor_tambien_lo_comprueba', str_contains($controller, 'Auth::can(Permissions::CAP_FORMS)'));
// Enganchar el formulario al recurso aquí convertiría un recurso publicado con
// descarga directa en uno que exige formulario, sin que nadie pulse Guardar.
// Se mira SOLO el cuerpo de createForm(): `ResourceStore::update` es legítimo
// en el update() del recurso, que es quien sí debe escribir.
$fromCreate = strpos($controller, 'public function createForm(');
$nextMethod = $fromCreate === false ? false : strpos($controller, "\n    private function renderEditor", $fromCreate);
$createFormBody = ($fromCreate !== false && $nextMethod !== false)
    ? substr($controller, $fromCreate, $nextMethod - $fromCreate)
    : '';
rfCheck('createForm_localizado', $createFormBody !== '');
rfCheck('el_endpoint_no_toca_el_recurso', $createFormBody !== '' && !str_contains($createFormBody, 'ResourceStore::update'));
rfCheck('el_endpoint_crea_el_formulario', str_contains($createFormBody, 'FormStore::create('));

$routes = (string) file_get_contents(PP_ROOT . '/app/Modules/Resources/routes.php');
rfCheck('la_ruta_existe', str_contains($routes, "resources/{id}/form"));

// ---------------------------------------------------------------------------
// 3. Contra la base de datos: el formulario que se crea de verdad.
// ---------------------------------------------------------------------------

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
rfCheck('hay_sitio_para_probar', $siteId > 0);

$resourceId = 0;
$formId = 0;
try {
    $title = 'Recurso de prueba RSRC-FORM ' . substr(bin2hex(random_bytes(4)), 0, 8);
    $resourceId = ResourceStore::create($siteId, ['title' => $title, 'status' => 'draft']);

    // Lo mismo que hace el endpoint.
    $lang = \App\Services\LanguageService::primaryFor($siteId);
    $content = FormTemplates::content('download', $lang);
    $content['heading'] = \App\Services\Microcopy::t('form.tpl.download.heading_for', $lang, ['recurso' => $title]);
    $formId = FormStore::create($siteId, $content);

    $saved = FormStore::find($siteId, $formId);
    rfCheck('el_formulario_se_guarda', $saved !== null);
    rfCheck(
        'el_encabezado_lleva_el_titulo_del_recurso',
        $saved !== null && str_contains((string) $saved['heading'], $title),
        (string) ($saved['heading'] ?? '')
    );
    rfCheck('se_guarda_como_download', ($saved['form_type'] ?? '') === 'download');
    rfCheck('nace_en_el_idioma_del_sitio', ($saved['language'] ?? '') === $lang);

    // Y aparece en la lista que alimenta el desplegable del paso 03.
    $ids = array_map(static fn(array $f): int => (int) $f['id'], FormStore::all($siteId));
    rfCheck('sale_en_el_desplegable', in_array($formId, $ids, true));
} finally {
    if ($formId > 0) FormStore::delete($siteId, $formId);
    if ($resourceId > 0) Database::execute('DELETE FROM resources WHERE id = ?', [$resourceId]);
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
