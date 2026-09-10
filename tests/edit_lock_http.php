<?php

declare(strict_types=1);

/**
 * EDIT-LOCK L2/L6 — Dos personas contra un servidor de verdad.
 *
 * `tests/edit_lock.php` prueba el modelo; esto prueba que está ENCHUFADO a las
 * escrituras del Studio. Es la parte que importa: el Studio autoguarda a cada
 * acción, así que una pestaña sin lock que siguiera escribiendo se llevaría por
 * delante la página entera de quien sí la tiene.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function lhCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$password = 'lock-' . $suffix;
$hash = password_hash($password, PASSWORD_BCRYPT);
$ana = 'lockh_ana_' . $suffix;
Database::execute('INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,?)',
    [$ana, $ana . '@ejemplo.invalid', $hash, 'editor']);
$anaId = (int) Database::lastInsertId();

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
$now = date('Y-m-d H:i:s');
$slug = 'lockhttp-' . $suffix;
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Lock HTTP', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();
CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Intacto</h1></section>', '', 'test', 'Base');

$port = 8798;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root
);
usleep(500000);
$baseUrl = 'http://127.0.0.1:' . $port;

function lhHttp(string $jar, string $method, string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body, json_decode($body, true)];
}

function lhCsrf(string $html): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1) return $m[1];
    if (preg_match('/<meta name="csrf" content="([^"]+)"/', $html, $m) === 1) return $m[1];
    return '';
}

function lhLogin(string $jar, string $baseUrl, string $user, string $pass): string
{
    [, $html] = lhHttp($jar, 'GET', $baseUrl . '/admin/login');
    lhHttp($jar, 'POST', $baseUrl . '/admin/login', [
        'identifier' => $user, 'password' => $pass, '_csrf' => lhCsrf($html),
    ]);
    [, $page] = lhHttp($jar, 'GET', $baseUrl . '/admin/profile');
    return lhCsrf($page);
}

$jarAna = (string) tempnam(sys_get_temp_dir(), 'lh-ana-');
$jarAdmin = (string) tempnam(sys_get_temp_dir(), 'lh-adm-');
$tokenAna = str_repeat('a', 32);
$tokenAdmin = str_repeat('b', 32);
$lockUrl = $baseUrl . '/admin/canvas/' . $pageId . '/lock';
$sectionUrl = $baseUrl . '/admin/canvas/' . $pageId . '/section';

try {
    $csrfAna = lhLogin($jarAna, $baseUrl, $ana, $password);
    $csrfAdmin = lhLogin($jarAdmin, $baseUrl, 'admin', 'supersecret123');
    lhCheck('las_dos_sesiones_entran', $csrfAna !== '' && $csrfAdmin !== '');

    // Ana coge la página.
    [$st, , $json] = lhHttp($jarAna, 'POST', $lockUrl, ['_csrf' => $csrfAna, 'op' => 'take', 'token' => $tokenAna]);
    lhCheck('ana_coge_la_pagina', $st === 200 && ($json['ok'] ?? false) === true);

    // Admin no puede, y se le dice de quién es.
    [$st, , $json] = lhHttp($jarAdmin, 'POST', $lockUrl, ['_csrf' => $csrfAdmin, 'op' => 'take', 'token' => $tokenAdmin]);
    lhCheck('admin_no_la_coge', $st === 409 && ($json['ok'] ?? true) === false);
    lhCheck('y_le_dicen_que_es_de_ana', ($json['status']['username'] ?? '') === $ana, json_encode($json) ?: '');

    // Y sobre todo: NO puede escribir.
    [$st, , $json] = lhHttp($jarAdmin, 'POST', $sectionUrl, [
        '_csrf' => $csrfAdmin, 'section' => 'hero',
        'html' => '<section data-pp-section="hero"><h1>Pisado</h1></section>',
        'lock_token' => $tokenAdmin,
    ]);
    lhCheck('admin_no_puede_escribir', $st === 409, (string) $st);
    lhCheck('el_error_nombra_a_quien_la_tiene', str_contains((string) ($json['error'] ?? ''), $ana), (string) ($json['error'] ?? ''));
    lhCheck(
        'la_pagina_de_ana_sigue_intacta',
        str_contains(CanvasService::get($pageId)['html'], 'Intacto'),
        mb_substr(CanvasService::get($pageId)['html'], 0, 160)
    );

    // Ana sí escribe.
    [$st] = lhHttp($jarAna, 'POST', $sectionUrl, [
        '_csrf' => $csrfAna, 'section' => 'hero',
        'html' => '<section data-pp-section="hero"><h1>Ana estuvo aqui</h1></section>',
        'lock_token' => $tokenAna,
    ]);
    lhCheck('ana_si_escribe', $st === 200, (string) $st);
    lhCheck('y_su_texto_esta', str_contains(CanvasService::get($pageId)['html'], 'Ana estuvo aqui'));

    // Admin toma el control.
    [$st, , $json] = lhHttp($jarAdmin, 'POST', $lockUrl, [
        '_csrf' => $csrfAdmin, 'op' => 'take', 'token' => $tokenAdmin, 'force' => '1',
    ]);
    lhCheck('admin_toma_el_control', $st === 200 && ($json['ok'] ?? false) === true);

    // A Ana le falla el latido: es la señal que le enseña el aviso.
    [$st, , $json] = lhHttp($jarAna, 'POST', $lockUrl, ['_csrf' => $csrfAna, 'op' => 'ping', 'token' => $tokenAna]);
    lhCheck('a_ana_le_falla_el_latido', $st === 409 && ($json['ok'] ?? true) === false);
    lhCheck('y_ve_quien_se_la_quito', ($json['status']['username'] ?? '') === 'admin');

    // Y ahora es Ana quien no puede escribir.
    [$st] = lhHttp($jarAna, 'POST', $sectionUrl, [
        '_csrf' => $csrfAna, 'section' => 'hero',
        'html' => '<section data-pp-section="hero"><h1>Ana insiste</h1></section>',
        'lock_token' => $tokenAna,
    ]);
    lhCheck('ana_ya_no_escribe', $st === 409, (string) $st);
    lhCheck('y_no_ha_dejado_rastro', !str_contains(CanvasService::get($pageId)['html'], 'Ana insiste'));

    // Al soltar, la página queda libre para el siguiente.
    lhHttp($jarAdmin, 'POST', $lockUrl, ['_csrf' => $csrfAdmin, 'op' => 'release', 'token' => $tokenAdmin]);
    [$st, , $json] = lhHttp($jarAna, 'POST', $lockUrl, ['_csrf' => $csrfAna, 'op' => 'take', 'token' => $tokenAna]);
    lhCheck('al_soltarla_la_coge_el_siguiente', $st === 200 && ($json['ok'] ?? false) === true);

    // Sin token no se escribe, aunque la página esté libre: es lo que impide
    // que un cliente despistado se salte el bloqueo sin enterarse.
    lhHttp($jarAna, 'POST', $lockUrl, ['_csrf' => $csrfAna, 'op' => 'release', 'token' => $tokenAna]);
    [$st] = lhHttp($jarAdmin, 'POST', $sectionUrl, [
        '_csrf' => $csrfAdmin, 'section' => 'hero',
        'html' => '<section data-pp-section="hero"><h1>Sin token</h1></section>',
    ]);
    lhCheck('sin_token_no_se_escribe', $st === 409, (string) $st);

    // Pero CON token y la página libre, la escritura se queda el lock y sigue:
    // un fallo de red al arrancar el Studio no puede dejar a nadie sin guardar.
    [$st] = lhHttp($jarAdmin, 'POST', $sectionUrl, [
        '_csrf' => $csrfAdmin, 'section' => 'hero',
        'html' => '<section data-pp-section="hero"><h1>Auto lock</h1></section>',
        'lock_token' => $tokenAdmin,
    ]);
    lhCheck('con_token_y_libre_se_coge_solo', $st === 200, (string) $st);
    lhCheck('y_el_lock_queda_a_su_nombre', str_contains(CanvasService::get($pageId)['html'], 'Auto lock'));
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    @unlink($jarAna); @unlink($jarAdmin);
    Database::execute('DELETE FROM edit_locks WHERE entity_id = ?', [$pageId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
    Database::execute('DELETE FROM users WHERE id = ?', [$anaId]);
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
