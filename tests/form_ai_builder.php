<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\FormDefinition;
use App\Services\FormI18n;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\Permissions;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\Renderer\SectionRenderer;
use App\Controllers\Admin\FormsController;
use App\Controllers\Public\FormController;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok): void {
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
function rejects(callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; }
}
// Reference questionnaire: use actual structured questions rather than an email template.
$draft = ['heading'=>'Cuéntanos qué estás buscando', 'description'=>'Nos ayuda a preparar mejor el primer contacto contigo.', 'submit_text'=>'Enviar respuestas', 'success_message'=>'Gracias por tus respuestas.', 'fields'=>[]];
foreach ([
    ['¿Qué zona te gustaría mejorar?', ['Pecho','Abdomen/cuerpo','Rostro','Zona íntima','Otra']],
    ['¿Qué resultado buscas principalmente?', ['Aumentar volumen','Reducir volumen','Elevar/reafirmar','Remodelar','Rejuvenecer','No estoy segura']],
    ['¿Te has realizado anteriormente alguna cirugía estética?', ['Sí','No']],
    ['¿En qué momento te gustaría realizarla?', ['Lo antes posible','1–3 meses','3–6 meses','Todavía me estoy informando']],
    ['¿Qué es lo más importante para ti?', ['Resultado natural','Seguridad','Acompañamiento','Recuperación','Presupuesto']],
] as [$label, $options]) $draft['fields'][] = ['label'=>$label, 'field_type'=>'radio', 'required'=>true, 'options'=>$options];
$form = FormDefinition::fromAI($draft);
check('preserva cinco preguntas y todas sus opciones', array_column($form['fields'], 'options') === array_column($draft['fields'], 'options'));
check('no impone email', !in_array('email', array_column($form['fields'], 'field_type'), true));
check('normaliza obligatoriedad', array_unique(array_column($form['fields'], 'required')) === ['1']);
$optional = $draft;
$optional['fields'][0]['required'] = '0';
$optional['fields'][0]['placeholder'] = 'Selecciona una opción';
$optional = FormDefinition::fromAI($optional);
check('cero no se interpreta como obligatorio y conserva ayuda', $optional['fields'][0]['required'] === '0' && $optional['fields'][0]['placeholder'] === 'Selecciona una opción');
check('normalización idempotente al crear tras revisar', FormDefinition::fromAI($form) === $form);
$long = $draft;
for ($i = 6; $i <= 50; $i++) $long['fields'][] = ['label'=>'Pregunta ' . $i, 'field_type'=>'text', 'required'=>'0'];
check('admite 50 campos sin el límite anterior de seis', count(FormDefinition::fromAI($long)['fields']) === 50);
$long['fields'][] = ['label'=>'Demasiados', 'field_type'=>'text'];
check('rechaza exceso sin recortar silenciosamente', rejects(fn() => FormDefinition::fromAI($long)));
foreach (['missing_fields','invalid_type','empty_options','nested_option','malformed_required','duplicate_label'] as $case) {
    $bad = $draft;
    if ($case === 'missing_fields') unset($bad['fields']);
    if ($case === 'invalid_type') $bad['fields'][0]['field_type'] = 'html';
    if ($case === 'empty_options') $bad['fields'][0]['options'] = [];
    if ($case === 'nested_option') $bad['fields'][0]['options'] = [['nested']];
    if ($case === 'malformed_required') $bad['fields'][0]['required'] = 'false';
    if ($case === 'duplicate_label') $bad['fields'][] = $bad['fields'][0];
    check('rechaza ' . $case, rejects(fn() => FormDefinition::fromAI($bad)));
}
$names = $draft;
$names['fields'] = [['label'=>'Área', 'field_type'=>'text'], ['label'=>'Area', 'field_type'=>'text'], ['label'=>'company_url', 'field_type'=>'text']];
$normalized = FormDefinition::fromAI($names);
check('evita colisiones de nombres y honeypot', array_column($normalized['fields'], 'name') === ['area','area_2','field_company_url']);
check('multiselección válida ordenada y sin duplicados', FormDefinition::multipleValues(['B','A','B'], ['A','B','C'], true) === [['A','B'], true]);
check('multiselección obligatoria vacía se rechaza', FormDefinition::multipleValues([], ['A'], true) === [[], false]);
check('multiselección opcional vacía se admite', FormDefinition::multipleValues([], ['A'], false) === [[], true]);
check('opción falsificada se rechaza', FormDefinition::multipleValues(['inventada'], ['A'], false) === [[], false]);
check('array anidado se rechaza', FormDefinition::multipleValues([['A']], ['A'], false) === [[], false]);
$method = new ReflectionMethod(AIActionRunner::class, 'normalizeActionData');
$method->setAccessible(true);
check('runner normaliza el contrato antes de devolverlo', $method->invoke(null, Actions::DESIGN_FORM, $draft) === $form);
$render = SectionRenderer::render(['id'=>76543, 'section_type'=>'form', 'content'=>json_encode($form), 'style'=>'{}']);
check('renderiza grupos accesibles con opciones tipo píldora', substr_count($render, '<fieldset') === 5 && substr_count($render, 'type="radio"') === 22 && str_contains($render, '<legend'));
check('radio obligatorio y sin selección predefinida', str_contains($render, ' required') && !str_contains($render, ' checked'));
$multi = ['label'=>'Preferencias', 'name'=>'preferencias', 'field_type'=>'checkbox_group', 'required'=>'1', 'placeholder'=>'', 'options'=>['0','A','B']];
$form['fields'][] = $multi;
$render = SectionRenderer::render(['id'=>76543, 'section_type'=>'form', 'content'=>json_encode($form), 'style'=>'{}']);
check('multiselección usa array y conserva opción cero', str_contains($render, 'name="preferencias[]" value="0"') && str_contains($render, 'data-pp-choice-required'));
$collect = new ReflectionMethod(FormController::class, 'collectPayload');
$collect->setAccessible(true);
$_POST = ['preferencias'=>['B','0']];
[$payload,$errors] = $collect->invoke(new FormController(), [$multi], 0, 0);
check('guarda múltiples respuestas válidas', $errors === [] && $payload['Preferencias'] === ['0','B']);
$_POST = ['preferencias'=>['falsa']];
[, $errors] = $collect->invoke(new FormController(), [$multi], 0, 0);
check('servidor rechaza opciones falsas', $errors === ['Preferencias']);
$_POST = [$form['fields'][0]['name']=>['Pecho']];
[, $errors] = $collect->invoke(new FormController(), [$form['fields'][0]], 0, 0);
check('radio rechaza array manipulado', $errors !== []);
$_POST = [$form['fields'][0]['name']=>'falsa'];
[, $errors] = $collect->invoke(new FormController(), [$form['fields'][0]], 0, 0);
check('radio rechaza valor no configurado', $errors !== []);
$_POST = ['heading'=>$form['heading'], 'fields'=>$form['fields']];
$collect = new ReflectionMethod(FormsController::class, 'collectInput'); $collect->setAccessible(true);
$saved = $collect->invoke(new FormsController());
check('editor conserva radio, multiselección y sus opciones', $saved['fields'] === $form['fields']);
$validate = new ReflectionMethod(FormsController::class, 'validate'); $validate->setAccessible(true);
check('formulario del ejemplo se puede guardar', $validate->invoke(new FormsController(), $saved) === []);
$saved['fields'][1]['name'] = $saved['fields'][0]['name'];
check('editor rechaza identificadores duplicados', $validate->invoke(new FormsController(), $saved) !== []);
check('redactor no puede generar ni crear formularios', !Permissions::allows(Permissions::ROLE_REDACTOR, '/admin/formularios/design') && !Permissions::allows(Permissions::ROLE_REDACTOR, '/admin/formularios/create-ai'));
check('admin puede generar formularios', Permissions::allows(Permissions::ROLE_ADMIN, '/admin/formularios/design'));
$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')['id'] ?? 0);
if ($siteId) {
    $id = FormStore::create($siteId, array_replace(FormTemplates::content('contact'), $form));
    try {
        $stored = FormStore::find($siteId, $id);
        check('persistencia mantiene preguntas y opciones completas', $stored['fields'] === $form['fields']);
        $hasForm = false;
        $embedded = \App\Services\Canvas\CanvasService::expandPlaceholders('{{form:' . $id . '}}', $siteId, $hasForm, 'es');
        check('Canvas inserta el cuestionario real y conserva multiselección', $hasForm && substr_count($embedded, '<fieldset') === 6 && str_contains($embedded, 'name="preferencias[]"'));

        $translation = ['fields'=>['preferencias'=>['label'=>'Preferences','options'=>['0','A en','B en']]]];
        $translated = FormI18n::withTranslation($form, 'en', $translation);
        check('opciones múltiples se traducen sin cambiar identificadores', FormI18n::resolve($translated, 'en')['fields'][5]['options'] === ['0','A en','B en']);
    } finally { Database::execute('DELETE FROM page_sections WHERE id = ?', [$id]); }
}
$_POST = [];
echo $failed ? "$failed FALLOS\n" : "OK\n";
exit($failed ? 1 : 0);
