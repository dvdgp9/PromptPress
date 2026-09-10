<?php

declare(strict_types=1);

// EQUIPO T2 — Contrato puro del reparto de permisos.
// No toca BD ni sesión: fija la matriz rol×capacidad y el mapeo ruta → área.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\Permissions;

$failed = 0;
function permCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 800) . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// 1. La matriz rol × capacidad, entera y explícita.
// ---------------------------------------------------------------------------

$matrix = [
    // capacidad        => [admin, editor, redactor]
    'content'    => [true,  true,  true],
    'forms'      => [true,  true,  false],
    'assistant'  => [true,  true,  false],
    'appearance' => [true,  true,  false],
    'visibility' => [true,  true,  false],
    'clients'    => [true,  false, false],
    'settings'   => [true,  false, false],
];

foreach ($matrix as $capability => $expected) {
    foreach (['admin', 'editor', 'redactor'] as $i => $role) {
        permCheck(
            "matrix_{$role}_{$capability}",
            Permissions::roleHas($role, $capability) === $expected[$i],
            "esperado " . ($expected[$i] ? 'sí' : 'no')
        );
    }
}

permCheck(
    'matrix_covers_every_capability',
    array_keys($matrix) === Permissions::CAPABILITIES,
    json_encode(Permissions::CAPABILITIES) ?: ''
);

// ---------------------------------------------------------------------------
// 2. Ruta → área. Aquí viven los casos frontera de verdad.
// ---------------------------------------------------------------------------

$paths = [
    // ruta                                   => capacidad esperada
    '/admin'                                  => '',
    '/admin/'                                 => '',
    '/admin/profile'                          => '',
    '/admin/logout'                           => '',
    '/admin/pages'                            => 'content',
    '/admin/pages/12/edit'                    => 'content',
    '/admin/pages/ai/templates/servicios/preview' => 'content',
    '/admin/posts/3/body'                     => 'content',
    '/admin/canvas/9/publish'                 => 'content',
    '/admin/media/bank/search'                => 'content',
    '/admin/resources/4/delete'               => 'content',
    // El par que casi se llama igual y no es lo mismo:
    '/admin/formularios'                      => 'forms',
    '/admin/formularios/2/translate'          => 'forms',
    '/admin/forms'                            => 'clients',
    '/admin/forms/submissions/7/read'         => 'clients',
    '/admin/booking/reservas'                 => 'clients',
    '/admin/commerce/pedidos/1/status'        => 'clients',
    '/admin/assistant/plan'                   => 'assistant',
    '/admin/memory'                           => 'assistant',
    '/admin/documents/5/retry'                => 'assistant',
    '/admin/design/fonts/delete'              => 'appearance',
    '/admin/chrome/preview'                   => 'appearance',
    '/admin/seo/redirects/3'                  => 'visibility',
    '/admin/marketing/custom'                 => 'visibility',
    '/admin/analytics/data'                   => 'visibility',
    '/admin/settings/mail/test'               => 'settings',
    '/admin/modules/toggle'                   => 'settings',
    '/admin/privacy/wizard/step1'             => 'settings',
    '/admin/ai/usage'                         => 'settings',
    '/admin/users/3/edit'                     => 'settings',
    '/admin/onboarding/step/2'                => 'settings',
    // Sin mapear todavía:
    '/admin/algo-que-no-existe'               => null,
];

foreach ($paths as $path => $expected) {
    permCheck(
        'path' . str_replace('/', '_', $path),
        Permissions::capabilityForPath($path) === $expected,
        'devolvió ' . var_export(Permissions::capabilityForPath($path), true)
    );
}

// Query string y barra final no deben cambiar la respuesta.
permCheck('path_ignores_query', Permissions::capabilityForPath('/admin/pages?filter=draft') === 'content');
permCheck('path_ignores_trailing_slash', Permissions::capabilityForPath('/admin/settings/') === 'settings');

// Fuera del panel no mandamos nosotros.
permCheck('path_outside_admin_is_open', Permissions::capabilityForPath('/contacto') === '');
permCheck('segment_outside_admin_is_null', Permissions::adminSegment('/contacto') === null);

// ---------------------------------------------------------------------------
// 3. allows(): la pregunta que hará el router.
// ---------------------------------------------------------------------------

permCheck('allows_redactor_pages',       Permissions::allows('redactor', '/admin/pages') === true);
permCheck('allows_redactor_dashboard',   Permissions::allows('redactor', '/admin') === true);
permCheck('allows_redactor_profile',     Permissions::allows('redactor', '/admin/profile') === true);
permCheck('denies_redactor_design',      Permissions::allows('redactor', '/admin/design') === false);
permCheck('denies_redactor_formularios', Permissions::allows('redactor', '/admin/formularios') === false);
permCheck('denies_redactor_settings',    Permissions::allows('redactor', '/admin/settings') === false);
permCheck('allows_editor_design',        Permissions::allows('editor', '/admin/design') === true);
permCheck('allows_editor_seo',           Permissions::allows('editor', '/admin/seo') === true);
permCheck('denies_editor_settings',      Permissions::allows('editor', '/admin/settings') === false);
permCheck('denies_editor_users',         Permissions::allows('editor', '/admin/users') === false);
permCheck('denies_editor_messages',      Permissions::allows('editor', '/admin/forms') === false);
permCheck('denies_editor_booking',       Permissions::allows('editor', '/admin/booking') === false);
permCheck('allows_admin_everything',     Permissions::allows('admin', '/admin/settings/reset-site') === true);

// Deny by default: una ruta sin área solo la pasa el admin.
permCheck('unmapped_denied_for_editor',   Permissions::allows('editor', '/admin/nueva-cosa') === false);
permCheck('unmapped_denied_for_redactor', Permissions::allows('redactor', '/admin/nueva-cosa') === false);
permCheck('unmapped_allowed_for_admin',   Permissions::allows('admin', '/admin/nueva-cosa') === true);

// Un rol que no existe no pasa por ningún sitio, ni al escritorio.
permCheck('unknown_role_denied',      Permissions::allows('superjefe', '/admin/pages') === false);
permCheck('null_role_denied',         Permissions::allows(null, '/admin') === false);
permCheck('unknown_role_no_caps',     Permissions::capabilitiesFor('superjefe') === []);

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
