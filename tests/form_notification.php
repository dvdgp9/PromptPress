<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_CORE . '/Helpers.php';

use App\Controllers\Admin\FormsController;
use App\Services\AdminI18n;
use App\Services\FormSubmissionService;

AdminI18n::setLocale('es');
$failed = 0;
function check_notification(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$meta = ['page_title' => 'Contacto', 'section_heading' => 'Pide presupuesto'];
$payload = [
    'Nombre' => 'Ana',
    'Mensaje' => "Primera línea\nSegunda línea",
    'Servicios' => ['Diseño', 'Desarrollo'],
    'Archivo' => ['type' => 'file', 'original_name' => 'brief.pdf', 'size' => 2048],
];
$default = FormSubmissionService::emailBody($meta, $payload);
$intro = __('form.mail.new_message');
check_notification('formularios existentes mantienen el mensaje predeterminado', str_starts_with($default, $intro . "\n\n"));
check_notification('mensaje vacío o solo espacios conserva el aviso original',
    FormSubmissionService::emailBody($meta, $payload, '') === $default
    && FormSubmissionService::emailBody($meta, $payload, " \n\t ") === $default);

$message = "Nueva solicitud de presupuesto.\nContactar en un plazo de 24 horas.";
$custom = FormSubmissionService::emailBody($meta, $payload, $message);
check_notification('mensaje personalizado conserva todos los metadatos y respuestas',
    $custom === $message . substr($default, strlen($intro)));
check_notification('aviso conserva mensaje multilínea, selección múltiple y archivo',
    str_contains($custom, "- Mensaje: Primera línea\nSegunda línea")
    && str_contains($custom, '- Servicios: ["Diseño","Desarrollo"]')
    && str_contains($custom, '- Archivo: brief.pdf (2 KB)'));

$originalPost = $_POST;
try {
    $collect = new ReflectionMethod(FormsController::class, 'collectInput');
    $controller = new FormsController();
    $_POST = ['notify_body' => $message, 'autoresponder_body' => 'Gracias por escribirnos.'];
    $first = $collect->invoke($controller);
    $_POST = ['notify_body' => 'Nueva candidatura recibida.'];
    $second = $collect->invoke($controller);
    $_POST = [];
    $legacy = $collect->invoke($controller);
    check_notification('cada formulario conserva su aviso independiente de la autorrespuesta',
        $first['notify_body'] === $message
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
