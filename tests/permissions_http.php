<?php

declare(strict_types=1);

// EQUIPO T3 — El guard, contra un servidor de verdad.
//
// `tests/permissions.php` prueba el modelo; esto prueba que el modelo está
// ENCHUFADO: crea un editor y un redactor reales, entra con cada uno y
// comprueba qué le contesta el servidor. Sin esto, un mapa perfecto podría
// estar sin colgar del router y nadie lo notaría.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use Core\Database;

$failed = 0;
function permHttpCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

// Usuarios de usar y tirar, uno por rol.
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$password = 'permisos-' . $suffix;
$hash = password_hash($password, PASSWORD_BCRYPT);
$created = [];
foreach (['editor', 'redactor'] as $role) {
    $username = 'test_' . $role . '_' . $suffix;
    Database::execute(
        'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
        [$username, $username . '@ejemplo.invalid', $hash, $role]
    );
    $created[$role] = ['id' => (int) Database::lastInsertId(), 'username' => $username];
}

$port = 8796;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
usleep(500000);
$baseUrl = 'http://127.0.0.1:' . $port;

$cookieJar = '';
function permHttp(string $method, string $url, array $post = [], array $headers = []): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        // Sin seguir redirecciones: queremos ver el 403 o el 302 tal cual sale.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (EQUIPO permisos test)',
        CURLOPT_HTTPHEADER => array_merge(['Accept: text/html'], $headers),
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}

function permCsrf(string $html): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1) return $m[1];
    if (preg_match('/<meta name="csrf" content="([^"]+)"/', $html, $m) === 1) return $m[1];
    return '';
}

function permLogin(string $baseUrl, string $identifier, string $password): bool
{
    global $cookieJar;
    if ($cookieJar !== '' && is_file($cookieJar)) @unlink($cookieJar);
    $cookieJar = (string) tempnam(sys_get_temp_dir(), 'permisos-');
    [, $html] = permHttp('GET', $baseUrl . '/admin/login');
    [$status] = permHttp('POST', $baseUrl . '/admin/login', [
        'identifier' => $identifier,
        'password' => $password,
        '_csrf' => permCsrf($html),
    ]);
    return $status === 302;
}

// Qué debería contestar cada rol en cada sitio. 200 = pasa, 403 = cortado.
$expectations = [
    'admin' => [
        '/admin' => 200, '/admin/profile' => 200, '/admin/pages' => 200, '/admin/formularios' => 200,
        '/admin/design' => 200, '/admin/seo' => 200, '/admin/forms' => 200,
        '/admin/settings' => 200, '/admin/modules' => 200, '/admin/memory' => 200,
        '/admin/users' => 200, '/admin/users/create' => 200,
    ],
    'editor' => [
        '/admin' => 200, '/admin/profile' => 200, '/admin/pages' => 200, '/admin/formularios' => 200,
        '/admin/design' => 200, '/admin/seo' => 200, '/admin/memory' => 200,
        '/admin/forms' => 403, '/admin/settings' => 403, '/admin/modules' => 403,
        '/admin/users' => 403, '/admin/users/create' => 403,
    ],
    'redactor' => [
        '/admin' => 200, '/admin/profile' => 200, '/admin/pages' => 200, '/admin/posts' => 200, '/admin/media' => 200,
        '/admin/formularios' => 403, '/admin/design' => 403, '/admin/chrome' => 403,
        '/admin/seo' => 403, '/admin/memory' => 403, '/admin/forms' => 403,
        '/admin/settings' => 403, '/admin/modules' => 403, '/admin/users' => 403,
    ],
];

$credentials = [
    'admin'    => ['admin', 'supersecret123'],
    'editor'   => [$created['editor']['username'], $password],
    'redactor' => [$created['redactor']['username'], $password],
];

try {
    foreach ($expectations as $role => $paths) {
        [$identifier, $pass] = $credentials[$role];
        permHttpCheck("login_{$role}", permLogin($baseUrl, $identifier, $pass), $identifier);

        foreach ($paths as $path => $expected) {
            [$status] = permHttp('GET', $baseUrl . $path);
            permHttpCheck(
                $role . '_' . str_replace('/', '_', $path) . '_' . $expected,
                $status === $expected,
                "contestó {$status}, esperado {$expected}"
            );
        }
    }

    // Un POST prohibido tampoco pasa: el guard corre antes que el controlador,
    // así que ni siquiera hace falta CSRF válido para que corte.
    permLogin($baseUrl, $created['redactor']['username'], $password);
    [$status] = permHttp('POST', $baseUrl . '/admin/settings/reset-site', ['confirmation' => 'lo que sea']);
    permHttpCheck('redactor_no_puede_resetear_el_sitio', $status === 403, (string) $status);

    // Y en fetch contesta JSON, no un <h1> que reventaría el response.json().
    [$status, $body] = permHttp('POST', $baseUrl . '/admin/modules/toggle', ['module' => 'booking'], [
        'X-Requested-With: fetch',
    ]);
    $json = json_decode($body, true);
    permHttpCheck(
        'fetch_prohibido_contesta_json',
        $status === 403 && is_array($json) && ($json['ok'] ?? null) === false,
        (string) $status . ' ' . mb_substr($body, 0, 200)
    );

    // RSRC-FORM — El editor de recursos vive en `content`, pero el botón de
    // crear el formulario de descarga es `forms`. Un redactor llega al editor y
    // NO puede crear el formulario; un editor sí. Es el único sitio del panel
    // donde una pantalla mezcla dos capacidades, así que conviene fijarlo.
    $recursoInexistente = $baseUrl . '/admin/resources/999999/form';

    permLogin($baseUrl, $created['redactor']['username'], $password);
    [, $perfil] = permHttp('GET', $baseUrl . '/admin/profile');
    [$status, $body] = permHttp('POST', $recursoInexistente, ['_csrf' => permCsrf($perfil)], [
        'Accept: application/json',
    ]);
    permHttpCheck(
        'redactor_no_crea_formularios_desde_recursos',
        $status === 403,
        (string) $status . ' ' . mb_substr($body, 0, 200)
    );

    // El editor sí pasa la capacidad: lo que le corta es que ese recurso no
    // existe (404), no el permiso. Si esto contestara 403, la barrera estaría
    // puesta en el sitio equivocado.
    permLogin($baseUrl, $created['editor']['username'], $password);
    [, $perfil] = permHttp('GET', $baseUrl . '/admin/profile');
    [$status, $body] = permHttp('POST', $recursoInexistente, ['_csrf' => permCsrf($perfil)], [
        'Accept: application/json',
    ]);
    permHttpCheck(
        'editor_si_puede_y_le_corta_el_404',
        $status === 404,
        (string) $status . ' ' . mb_substr($body, 0, 200)
    );

    // Sin sesión sigue mandando el login, no el 403: el guard no se come a requireAuth.
    if ($cookieJar !== '' && is_file($cookieJar)) @unlink($cookieJar);
    $cookieJar = (string) tempnam(sys_get_temp_dir(), 'permisos-anon-');
    [$status] = permHttp('GET', $baseUrl . '/admin/settings');
    permHttpCheck('sin_sesion_va_al_login', $status === 302, (string) $status);
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    if ($cookieJar !== '' && is_file($cookieJar)) @unlink($cookieJar);
    foreach ($created as $user) {
        Database::execute('DELETE FROM users WHERE id = ?', [$user['id']]);
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
