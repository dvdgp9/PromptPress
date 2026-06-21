<?php

// FORMS-R T1 — Tests del catálogo de plantillas tipadas. Sin IA ni BD.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\FormTemplates;
use App\Services\FormStore;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) { $failed++; if ($detail !== '') echo '  → ' . $detail . PHP_EOL; }
}

$catalog = FormTemplates::catalog();
foreach (['contact', 'newsletter', 'quote', 'booking', 'job'] as $k) {
    check("catálogo tiene '$k'", isset($catalog[$k]));
    check("'$k' tiene label y content", !empty($catalog[$k]['label']) && !empty($catalog[$k]['content']));
}

// Cada content sella su form_type y trae defaults RGPD + campos.
foreach ($catalog as $key => $tpl) {
    $c = $tpl['content'];
    check("'$key' form_type correcto", ($c['form_type'] ?? null) === $key, var_export($c['form_type'] ?? null, true));
    check("'$key' tiene campos", is_array($c['fields'] ?? null) && count($c['fields']) >= 1);
    check("'$key' tiene lawful_basis", !empty($c['lawful_basis']));
    check("'$key' tiene autoresponder defaults", isset($c['autoresponder_enabled'], $c['autoresponder_subject']));
}

// Reglas específicas de propósito.
check('newsletter es consent + marketing opt-in', $catalog['newsletter']['content']['lawful_basis'] === 'consent'
    && $catalog['newsletter']['content']['marketing_opt_in'] === '1');
check('contact es legitimate_interest', $catalog['contact']['content']['lawful_basis'] === 'legitimate_interest');
$jobFields = array_column($catalog['job']['content']['fields'], 'field_type');
check('job incluye campo file (CV)', in_array('file', $jobFields, true));
$bookingFields = array_column($catalog['booking']['content']['fields'], 'field_type');
check('booking incluye campo date', in_array('date', $bookingFields, true));

// Helpers.
check('exists() true para clave válida', FormTemplates::exists('quote'));
check('exists() false para clave inventada', !FormTemplates::exists('inventada'));
check('content() cae a contact si clave inválida', (FormTemplates::content('inventada')['form_type'] ?? '') === 'contact');
check('label() devuelve etiqueta', FormTemplates::label('booking') === 'Reserva / cita');
check('keys() lista las 5', count(FormTemplates::keys()) === 5);

// FormStore::defaultContact delega en el catálogo (sin duplicar schema).
check('defaultContact == plantilla contact', FormStore::defaultContact() === FormTemplates::content('contact'));

echo PHP_EOL . ($failed === 0 ? "TODOS OK" : "FALLARON $failed") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
