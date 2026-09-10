<?php

declare(strict_types=1);

/**
 * EDIT-LOCK L1 — El contrato del bloqueo de edición.
 *
 * Lo que se protege aquí son los casos por los que un bloqueo se convierte en
 * un estorbo en vez de en una ayuda: que caduque solo, que se pueda tomar, que
 * distinga dos pestañas de la misma persona, y que al desalojado se le pueda
 * avisar.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\EditLock;
use Core\Database;

$failed = 0;
function lockCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$hash = password_hash('x-' . $suffix, PASSWORD_BCRYPT);
$users = [];
foreach (['ana', 'luis'] as $name) {
    $u = 'lock_' . $name . '_' . $suffix;
    Database::execute('INSERT INTO users (username, email, password_hash, role) VALUES (?,?,?,?)',
        [$u, $u . '@ejemplo.invalid', $hash, 'editor']);
    $users[$name] = ['id' => (int) Database::lastInsertId(), 'username' => $u];
}

// Una entidad que no choca con nada real.
$pageId = 900000 + random_int(1, 99999);
$E = EditLock::ENTITY_PAGE;

try {
    // --- Libre de partida -------------------------------------------------
    $status = EditLock::status($E, $pageId);
    lockCheck('una_pagina_sin_nadie_esta_libre', $status['held'] === false);

    // --- Ana coge el lock -------------------------------------------------
    $anaToken = EditLock::newToken();
    $r = EditLock::acquire($E, $pageId, $users['ana']['id'], $anaToken);
    lockCheck('ana_lo_coge', $r['ok'] === true);
    lockCheck('y_consta_como_suyo', EditLock::heldBy($E, $pageId, $anaToken));
    $status = EditLock::status($E, $pageId);
    lockCheck('el_estado_dice_quien_es', $status['username'] === $users['ana']['username'], (string) $status['username']);

    // --- Luis no puede -----------------------------------------------------
    $luisToken = EditLock::newToken();
    $r = EditLock::acquire($E, $pageId, $users['luis']['id'], $luisToken);
    lockCheck('luis_no_lo_coge', $r['ok'] === false);
    lockCheck('y_le_dicen_de_quien_es', ($r['status']['username'] ?? '') === $users['ana']['username']);
    lockCheck('luis_no_puede_escribir', !EditLock::heldBy($E, $pageId, $luisToken));
    lockCheck('ana_sigue_pudiendo', EditLock::heldBy($E, $pageId, $anaToken));

    // --- La MISMA persona en otra pestaña tampoco -------------------------
    // Este es el caso que un lock por user_id dejaría pasar.
    $anaSegundaPestana = EditLock::newToken();
    $r = EditLock::acquire($E, $pageId, $users['ana']['id'], $anaSegundaPestana);
    lockCheck('la_segunda_pestana_de_ana_no_lo_coge', $r['ok'] === false);
    lockCheck('la_segunda_pestana_no_escribe', !EditLock::heldBy($E, $pageId, $anaSegundaPestana));

    // --- Recargar con el mismo token lo renueva, no lo pierde -------------
    $r = EditLock::acquire($E, $pageId, $users['ana']['id'], $anaToken);
    lockCheck('recargar_no_pierde_el_lock', $r['ok'] === true && EditLock::heldBy($E, $pageId, $anaToken));

    // --- Latido ------------------------------------------------------------
    lockCheck('el_latido_de_ana_vale', EditLock::heartbeat($E, $pageId, $anaToken) === true);
    lockCheck('el_latido_de_luis_no', EditLock::heartbeat($E, $pageId, $luisToken) === false);

    // --- Caducidad ---------------------------------------------------------
    // Se envejece el latido a mano: esperar 90 segundos en un test no es opción.
    Database::execute(
        'UPDATE edit_locks SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND)
         WHERE entity_type = ? AND entity_id = ?',
        [EditLock::TTL_SECONDS + 10, $E, $pageId]
    );
    $status = EditLock::status($E, $pageId);
    lockCheck('un_lock_sin_noticias_caduca', $status['held'] === false, json_encode($status) ?: '');
    lockCheck('y_ana_deja_de_poder_escribir', !EditLock::heldBy($E, $pageId, $anaToken));

    $r = EditLock::acquire($E, $pageId, $users['luis']['id'], $luisToken);
    lockCheck('luis_puede_coger_el_caducado', $r['ok'] === true && EditLock::heldBy($E, $pageId, $luisToken));

    // Justo por debajo del TTL sigue vivo: un latido perdido no echa a nadie.
    Database::execute(
        'UPDATE edit_locks SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND)
         WHERE entity_type = ? AND entity_id = ?',
        [EditLock::TTL_SECONDS - 10, $E, $pageId]
    );
    lockCheck('por_debajo_del_ttl_aguanta', EditLock::status($E, $pageId)['held'] === true);
    lockCheck('el_ttl_da_margen_a_tres_latidos', EditLock::TTL_SECONDS >= EditLock::HEARTBEAT_SECONDS * 3);

    // --- Tomar el control --------------------------------------------------
    $anaToken2 = EditLock::newToken();
    $r = EditLock::acquire($E, $pageId, $users['ana']['id'], $anaToken2, true);
    lockCheck('ana_toma_el_control', $r['ok'] === true && EditLock::heldBy($E, $pageId, $anaToken2));
    // Y al desalojado se le nota: su latido deja de valer, que es la señal
    // con la que el navegador de Luis puede avisarle.
    lockCheck('al_desalojado_le_falla_el_latido', EditLock::heartbeat($E, $pageId, $luisToken) === false);

    // --- Soltar ------------------------------------------------------------
    EditLock::release($E, $pageId, 'un-token-que-no-es');
    lockCheck('no_se_suelta_un_lock_ajeno', EditLock::status($E, $pageId)['held'] === true);
    EditLock::release($E, $pageId, $anaToken2);
    lockCheck('el_dueno_si_lo_suelta', EditLock::status($E, $pageId)['held'] === false);

    // --- Un token vacío nunca da permiso ----------------------------------
    EditLock::acquire($E, $pageId, $users['ana']['id'], EditLock::newToken());
    lockCheck('token_vacio_no_escribe', !EditLock::heldBy($E, $pageId, ''));

    // --- Limpieza de muertos ----------------------------------------------
    Database::execute(
        'UPDATE edit_locks SET heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND)
         WHERE entity_type = ? AND entity_id = ?',
        [EditLock::TTL_SECONDS + 60, $E, $pageId]
    );
    EditLock::prune();
    $row = Database::selectOne('SELECT id FROM edit_locks WHERE entity_type = ? AND entity_id = ?', [$E, $pageId]);
    lockCheck('prune_barre_los_muertos', $row === null);
} finally {
    Database::execute('DELETE FROM edit_locks WHERE entity_id = ?', [$pageId]);
    foreach ($users as $u) {
        Database::execute('DELETE FROM users WHERE id = ?', [$u['id']]);
    }
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
