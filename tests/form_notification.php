<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_CORE . '/Helpers.php';

use App\Controllers\Admin\FormsController;
use App\Controllers\Public\FormController;
use App\Services\AdminI18n;
use App\Services\FormNotification;
use App\Services\FormSubmissionService;
use App\Services\FormAssistantEditor;
use App\Services\FormI18n;

AdminI18n::setLocale('es');
$failed = 0;
function check_notification(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$meta = ['page_title' => 'Contacto', 'site_name' => 'YROÄ', 'date' => '12/11/2026 13:54'];
$fields = [
    ['name' => 'nombre', 'label' => 'Nombre', 'field_type' => 'text'],
    ['name' => 'servicios', 'label' => 'Servicios', 'field_type' => 'checkbox_group'],
];
$content = ['heading' => 'Cuéntanos qué estás buscando', 'fields' => $fields];
$payload = [
    'Nombre' => 'Ana',
    'Mensaje' => "Primera línea\nSegunda línea",
    'Servicios' => ['Diseño', 'Desarrollo'],
    'Archivo' => ['type' => 'file', 'original_name' => 'brief.pdf', 'size' => 2048],
];
$sender = ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '612 84 79 53'];
$default = FormNotification::render($content, $meta, $payload, $sender);
check_notification('asunto predeterminado identifica el formulario',
    $default['subject'] === 'Nueva respuesta: Cuéntanos qué estás buscando');
check_notification('HTML y texto plano incluyen respuestas legibles y página real',
    str_contains($default['text'], "Servicios\nDiseño, Desarrollo")
    && str_contains($default['text'], 'Contacto · 12/11/2026 13:54')
    && str_contains($default['html'], 'brief.pdf (2 KB)')
    && str_contains($default['html'], 'YROÄ')
    && !str_contains($default['html'], 'Formularios (sistema)'));
$legacy = $content + ['notify_body' => 'Contactar en 24 horas.'];
check_notification('mensajes de la versión anterior conservan automáticamente las respuestas',
    str_contains(FormNotification::render($legacy, $meta, $payload)['text'], "Contactar en 24 horas.\n\nNombre\nAna")
    && FormNotification::bodyTemplate($legacy) === "Contactar en 24 horas.\n\n{{respuestas}}");
$custom = $content + [
    'notify_subject' => 'Solicitud de {{nombre}} para {{sitio}}',
    'notify_body' => "Hola equipo:\n\n{{nombre}} solicita {{campo:servicios}}.\n{{email}} · {{telefono}}\n\n{{respuestas}}\n\nGracias.",
    'notify_template_version' => 2,
];
$result = FormNotification::render($custom, $meta, $payload, $sender);
check_notification('asunto, cuerpo y campos individuales se personalizan',
    $result['subject'] === 'Solicitud de Ana para YROÄ'
    && str_starts_with($result['text'], "Hola equipo:\n\nAna solicita Diseño, Desarrollo.")
    && str_contains($result['text'], 'ana@example.com · 612 84 79 53'));
check_notification('respeta la posición elegida para las respuestas sin duplicarlas',
    substr_count($result['text'], "Nombre\nAna") === 1
    && strpos($result['text'], "Nombre\nAna") < strpos($result['text'], 'Gracias.')
    && !str_contains($result['text'], 'Contacto')
    && !str_contains($result['text'], '12/11/2026'));
$custom['notify_body'] = 'Llama a {{nombre}}: {{telefono}}.';
$result = FormNotification::render($custom, $meta, $payload, $sender);
check_notification('permite redactar todo el correo sin secciones fijas',
    $result['text'] === 'Llama a Ana: 612 84 79 53.' && !str_contains($result['html'], 'brief.pdf'));
$custom['notify_body'] = '{{respuestas}}';
$custom['notify_subject'] = "Hola {{nombre}}";
$attack = ['Nombre' => '<img src=x onerror=alert(1)> {{respuestas}}', 'Enlace' => '<a href="https://evil.example">clic</a>'];
$result = FormNotification::render($custom, $meta, $attack, ['name' => "Ana\r\nBcc: other@example.com"]);
check_notification('escapa HTML de campos y no reinterpreta tokens en respuestas',
    str_contains($result['html'], '&lt;img src=x') && !str_contains($result['html'], '<img')
    && !str_contains($result['html'], '<a href=') && substr_count($result['text'], '{{respuestas}}') === 1);
check_notification('el asunto no permite saltos de línea en cabeceras',
    !str_contains($result['subject'], "\n") && !str_contains($result['subject'], "\r"));
$systemMeta = $meta;
$systemMeta['page_title'] = 'Formularios (sistema)';
$systemMeta['page_slug'] = '__forms';
$result = FormNotification::render($content, $systemMeta, $payload);
check_notification('el contenedor interno no se filtra al correo ni con origen desconocido',
    !str_contains($result['text'] . $result['html'] . $result['subject'], 'Formularios (sistema)'));
check_notification('adaptador de texto usa el mismo formato que el envío HTML',
    str_contains(FormSubmissionService::emailBody($meta, $payload, 'Aviso anterior'), "Aviso anterior\n\nNombre\nAna"));
$contact = $content;
$contact['fields'][] = ['name' => 'email', 'label' => 'Email', 'field_type' => 'email'];
$contact['fields'][] = ['name' => 'telefono', 'label' => 'Teléfono', 'field_type' => 'tel'];
$example = FormNotification::preview($contact, $meta);
check_notification('vista previa usa los campos configurados y ofrece contactos pulsables',
    str_contains($example['html'], 'href="mailto:alex@example.com"')
    && str_contains($example['html'], 'href="tel:+34612847953"')
    && str_contains($example['text'], "Nombre\nAlex"));
$translated = FormI18n::withTranslation($custom, 'en', ['heading' => 'Contact us', 'notify_body' => 'No sobrescribir']);
$merged = FormAssistantEditor::merge($custom, $content);
check_notification('traducciones y ediciones del asistente preservan la plantilla del destinatario',
    $translated['notify_body'] === $custom['notify_body']
    && $merged['notify_body'] === $custom['notify_body']
    && $merged['notify_subject'] === $custom['notify_subject']
    && $merged['notify_template_version'] === 2);

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $connection = new ReflectionProperty(\Core\Database::class, 'pdo');
    $connection->setAccessible(true);
    $originalConnection = $connection->getValue();
    try {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE pages (id INTEGER, site_id INTEGER, title TEXT, slug TEXT, page_type TEXT, status TEXT)');
        $db->exec("INSERT INTO pages VALUES (1, 7, 'Contacto real', 'contacto', 'landing', 'published'), (2, 7, 'Inicio real', '', 'home', 'published')");
        $connection->setValue(null, $db);
        $origin = new ReflectionMethod(FormController::class, 'resolveOriginPage');
        $origin->setAccessible(true);
        $fallback = ['page_id' => 8, 'page_title' => 'Formularios (sistema)', 'slug' => '__forms', 'content' => json_encode($content)];
        $controller = new FormController();
        $contactPage = $origin->invoke($controller, 7, '/contacto', $fallback);
        $homePage = $origin->invoke($controller, 7, '/', $fallback);
        $unknownPage = $origin->invoke($controller, 7, '/recurso-sin-pagina', $fallback);
        $otherSite = $origin->invoke($controller, 9, '/contacto', $fallback);
        check_notification('origen usa la página pública real y nunca el contenedor interno',
            $contactPage['title'] === 'Contacto real'
            && $homePage['title'] === 'Inicio real'
            && $unknownPage['title'] === $content['heading']
            && $otherSite['title'] === $content['heading']);
    } finally {
        $connection->setValue(null, $originalConnection);
    }
} else {
    echo "SKIP origen en SQLite: controlador no disponible\n";
}

$originalPost = $_POST;
try {
    $collect = new ReflectionMethod(FormsController::class, 'collectInput');
    $collect->setAccessible(true);
    $controller = new FormsController();
    $_POST = ['notify_body' => 'Mensaje independiente.', 'notify_subject' => 'Nueva solicitud', 'autoresponder_body' => 'Gracias por escribirnos.'];
    $first = $collect->invoke($controller);
    $_POST = ['notify_body' => 'Nueva candidatura recibida.'];
    $second = $collect->invoke($controller);
    $_POST = [];
    $legacy = $collect->invoke($controller);
    check_notification('cada formulario conserva su aviso independiente de la autorrespuesta',
        $first['notify_body'] === 'Mensaje independiente.'
        && $first['notify_subject'] === 'Nueva solicitud'
        && $first['notify_template_version'] === 2
        && $first['autoresponder_body'] === 'Gracias por escribirnos.'
        && $second['notify_body'] === 'Nueva candidatura recibida.'
        && $legacy['notify_body'] === '');
    $_POST = ['notify_body' => str_repeat('á', 4001)];
    check_notification('límite de 4000 caracteres respeta caracteres multibyte',
        $collect->invoke($controller)['notify_body'] === str_repeat('á', 4000));
} finally {
    $_POST = $originalPost;
}

echo $failed ? "$failed FALLOS\n" : "OK\n";
exit($failed ? 1 : 0);
