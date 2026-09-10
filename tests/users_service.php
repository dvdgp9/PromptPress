<?php

declare(strict_types=1);

// EQUIPO T5 — Las reglas que impiden dejar el panel sin dueño.
//
// El resto de la pantalla se puede comprobar a ojo; esto no. Un fallo aquí no
// se ve hasta el día en que alguien borra la cuenta equivocada y ya no hay
// forma de entrar sin tocar la base de datos a mano.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\UserService;
use Core\Database;

$failed = 0;
function usrCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$created = [];
$make = static function (string $role) use ($suffix, &$created): int {
    $name = 'ut_' . $role . '_' . $suffix . '_' . count($created);
    $id = UserService::create([
        'username' => $name,
        'email'    => $name . '@ejemplo.invalid',
        'password' => 'contrasena-larga',
        'role'     => $role,
    ]);
    $created[] = $id;
    return $id;
};

try {
    // ---------------------------------------------------------------------
    // Validación de formato
    // ---------------------------------------------------------------------
    $base = [
        'username' => 'alguien_' . $suffix,
        'email'    => 'alguien_' . $suffix . '@ejemplo.invalid',
        'role'     => 'editor',
        'password' => 'contrasena-larga',
        'password_confirm' => 'contrasena-larga',
    ];
    usrCheck('validacion_acepta_datos_correctos', UserService::validate($base) === [], json_encode(UserService::validate($base)) ?: '');

    usrCheck('rechaza_usuario_corto', in_array('users.err.username_len', UserService::validate(['username' => 'ab'] + $base), true));
    usrCheck('rechaza_usuario_con_espacios', in_array('users.err.username_chars', UserService::validate(['username' => 'con espacio'] + $base), true));
    usrCheck('rechaza_usuario_con_acentos', in_array('users.err.username_chars', UserService::validate(['username' => 'josé'] + $base), true));
    usrCheck('rechaza_email_invalido', in_array('users.err.email', UserService::validate(['email' => 'no-es-un-email'] + $base), true));
    usrCheck('rechaza_rol_inventado', in_array('users.err.role', UserService::validate(['role' => 'superjefe'] + $base), true));
    usrCheck('rechaza_contrasena_corta', in_array('users.err.password_len', UserService::validate(['password' => 'corta', 'password_confirm' => 'corta'] + $base), true));
    usrCheck('rechaza_contrasenas_distintas', in_array('users.err.password_match', UserService::validate(['password_confirm' => 'otra-cosa-larga'] + $base), true));

    // En un alta la contraseña es obligatoria; editando, en blanco = no tocar.
    $sinPassword = $base;
    unset($sinPassword['password'], $sinPassword['password_confirm']);
    usrCheck('alta_exige_contrasena', in_array('users.err.password_len', UserService::validate($sinPassword), true));

    // ---------------------------------------------------------------------
    // Unicidad
    // ---------------------------------------------------------------------
    $editorId = $make('editor');
    $editor = UserService::find($editorId);
    usrCheck('crear_devuelve_usuario', $editor !== null && $editor['role'] === 'editor');

    $duplicado = [
        'username' => (string) $editor['username'],
        'email'    => 'otro_' . $suffix . '@ejemplo.invalid',
        'role'     => 'editor',
        'password' => 'contrasena-larga',
        'password_confirm' => 'contrasena-larga',
    ];
    usrCheck('rechaza_usuario_repetido', in_array('users.err.username_taken', UserService::validate($duplicado), true));
    $duplicado['username'] = 'otro_' . $suffix;
    $duplicado['email'] = (string) $editor['email'];
    usrCheck('rechaza_email_repetido', in_array('users.err.email_taken', UserService::validate($duplicado), true));

    // Editarse a sí mismo con sus propios datos no debe chocar consigo mismo.
    $propio = [
        'username' => (string) $editor['username'],
        'email'    => (string) $editor['email'],
        'role'     => 'editor',
    ];
    usrCheck('editarse_con_los_propios_datos_no_choca', UserService::validate($propio, $editorId) === [], json_encode(UserService::validate($propio, $editorId)) ?: '');

    // ---------------------------------------------------------------------
    // Las tres reglas
    // ---------------------------------------------------------------------
    $adminOriginal = Database::selectOne('SELECT id FROM users WHERE role = "admin" ORDER BY id ASC LIMIT 1');
    $adminId = (int) ($adminOriginal['id'] ?? 0);
    usrCheck('hay_un_admin_de_partida', $adminId > 0);

    // 1. Nadie se borra a sí mismo.
    usrCheck('no_puedo_borrarme', UserService::blocksDelete($adminId, $adminId) === 'users.err.self_delete');

    // 2. Nadie se quita a sí mismo el rol de administrador.
    usrCheck(
        'no_puedo_quitarme_admin',
        UserService::blocksRoleChange($adminId, $adminId, 'editor') === 'users.err.self_demote'
    );

    // 3. Nunca cero administradores. Con un solo admin, otro no puede bajarlo.
    $otroAdminId = $make('admin');
    usrCheck('ahora_hay_dos_admins', UserService::countAdmins() >= 2);
    usrCheck(
        'con_dos_admins_si_se_puede_bajar_a_uno',
        UserService::blocksRoleChange($otroAdminId, $adminId, 'editor') === null,
        (string) UserService::blocksRoleChange($otroAdminId, $adminId, 'editor')
    );
    usrCheck('con_dos_admins_si_se_puede_borrar_a_uno', UserService::blocksDelete($otroAdminId, $adminId) === null);

    // Lo bajamos de verdad y volvemos a quedarnos con uno solo.
    UserService::update($otroAdminId, [
        'username' => (string) UserService::find($otroAdminId)['username'],
        'email'    => (string) UserService::find($otroAdminId)['email'],
        'role'     => 'redactor',
    ]);
    usrCheck('el_cambio_de_rol_se_guarda', (UserService::find($otroAdminId)['role'] ?? '') === 'redactor');
    usrCheck('vuelve_a_haber_un_solo_admin', UserService::countAdmins() === 1);
    usrCheck('es_el_ultimo_admin', UserService::isLastAdmin($adminId) === true);
    usrCheck('un_editor_nunca_es_el_ultimo_admin', UserService::isLastAdmin($editorId) === false);

    // Y ahora el último administrador no se puede borrar ni bajar, lo pida quien lo pida.
    usrCheck('no_se_borra_al_ultimo_admin', UserService::blocksDelete($adminId, $otroAdminId) === 'users.err.last_admin');
    usrCheck('no_se_baja_al_ultimo_admin', UserService::blocksRoleChange($adminId, $otroAdminId, 'editor') === 'users.err.last_admin');

    // Un usuario que ya no existe.
    usrCheck('usuario_inexistente', UserService::blocksDelete(999999, $adminId) === 'users.err.not_found');

    // Dejar el rol como está nunca se bloquea, ni siquiera en el último admin.
    usrCheck('mismo_rol_no_se_bloquea', UserService::blocksRoleChange($adminId, $adminId, 'admin') === null);

    // ---------------------------------------------------------------------
    // Contraseñas y sesiones recordadas
    // ---------------------------------------------------------------------
    $hashAntes = (string) Database::selectOne('SELECT password_hash FROM users WHERE id = ?', [$editorId])['password_hash'];
    UserService::setPassword($editorId, 'otra-contrasena-larga');
    $hashDespues = (string) Database::selectOne('SELECT password_hash FROM users WHERE id = ?', [$editorId])['password_hash'];
    usrCheck('la_contrasena_cambia', $hashAntes !== $hashDespues);
    usrCheck('la_nueva_contrasena_vale', password_verify('otra-contrasena-larga', $hashDespues));

    // Cambiar la contraseña tira las sesiones recordadas de esa cuenta.
    Database::execute(
        'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))',
        [$editorId, substr(bin2hex(random_bytes(16)), 0, 32), str_repeat('a', 64)]
    );
    $antes = (int) Database::selectOne('SELECT COUNT(*) AS n FROM auth_tokens WHERE user_id = ?', [$editorId])['n'];
    UserService::setPassword($editorId, 'y-otra-mas-larga');
    $despues = (int) Database::selectOne('SELECT COUNT(*) AS n FROM auth_tokens WHERE user_id = ?', [$editorId])['n'];
    usrCheck('cambiar_contrasena_tira_las_sesiones', $antes === 1 && $despues === 0, "antes={$antes} despues={$despues}");

    // ---------------------------------------------------------------------
    // T6 — Mi cuenta
    // ---------------------------------------------------------------------
    usrCheck('verifica_la_contrasena_buena', UserService::verifyPassword($editorId, 'y-otra-mas-larga') === true);
    usrCheck('rechaza_la_contrasena_mala', UserService::verifyPassword($editorId, 'no-es-esta') === false);
    usrCheck('usuario_inexistente_no_verifica', UserService::verifyPassword(999999, 'lo-que-sea') === false);

    // El perfil propio NO puede cambiar el rol, ni aunque se lo cuelen.
    $rolAntes = (string) UserService::find($editorId)['role'];
    UserService::updateOwnProfile($editorId, [
        'username' => 'renombrado_' . $suffix,
        'email'    => 'renombrado_' . $suffix . '@ejemplo.invalid',
        'role'     => 'admin',
    ]);
    $despuesPerfil = UserService::find($editorId);
    usrCheck('el_perfil_cambia_el_nombre', ($despuesPerfil['username'] ?? '') === 'renombrado_' . $suffix);
    usrCheck('el_perfil_NO_cambia_el_rol', ($despuesPerfil['role'] ?? '') === $rolAntes, (string) ($despuesPerfil['role'] ?? ''));

    // Y borrar la cuenta se lleva sus tokens por la clave foránea.
    Database::execute(
        'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))',
        [$editorId, substr(bin2hex(random_bytes(16)), 0, 32), str_repeat('b', 64)]
    );
    UserService::delete($editorId);
    $huerfanos = (int) Database::selectOne('SELECT COUNT(*) AS n FROM auth_tokens WHERE user_id = ?', [$editorId])['n'];
    usrCheck('borrar_la_cuenta_se_lleva_sus_tokens', $huerfanos === 0, (string) $huerfanos);
    usrCheck('la_cuenta_borrada_ya_no_esta', UserService::find($editorId) === null);
} finally {
    foreach ($created as $id) {
        Database::execute('DELETE FROM users WHERE id = ?', [$id]);
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
