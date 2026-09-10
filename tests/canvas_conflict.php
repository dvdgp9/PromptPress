<?php

declare(strict_types=1);

/**
 * EDIT-LOCK L4/L5 — La red que hay debajo del bloqueo.
 *
 * El lock evita que dos personas coincidan. Esto responde a la otra pregunta:
 * ¿y el día que el lock no estaba puesto? Caducó, alguien tomó el control, se
 * cayó la sesión. Sin esta comprobación, ese día se pierde trabajo en silencio.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasConflictException;
use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function confCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
$now = date('Y-m-d H:i:s');
$slug = 'conflict-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Conflicto L4', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();

try {
    $base = '<section data-pp-section="hero"><h1>Original</h1></section>';
    CanvasService::save($pageId, $base, '', 'test', 'Base');
    $v1 = (int) Database::selectOne('SELECT current_version_id FROM page_canvas WHERE page_id = ?', [$pageId])['current_version_id'];
    confCheck('hay_version_inicial', $v1 > 0);

    // Guardar diciendo la versión correcta: pasa.
    CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Ana</h1></section>', '', 'test', 'Ana', $v1);
    $v2 = (int) Database::selectOne('SELECT current_version_id FROM page_canvas WHERE page_id = ?', [$pageId])['current_version_id'];
    confCheck('guardar_sobre_la_version_actual_pasa', $v2 !== $v1 && $v2 > 0);
    confCheck('y_el_contenido_es_el_de_ana', str_contains(CanvasService::get($pageId)['html'], 'Ana'));

    // Ahora Luis guarda creyendo estar sobre v1, que ya no es la actual.
    $conflicto = false;
    try {
        CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Luis</h1></section>', '', 'test', 'Luis', $v1);
    } catch (CanvasConflictException $e) {
        $conflicto = true;
        confCheck('la_excepcion_dice_cual_es_la_actual', $e->currentVersionId === $v2, (string) $e->currentVersionId);
        confCheck('y_sobre_cual_creia_estar', $e->baseVersionId === $v1);
    }
    confCheck('guardar_sobre_una_version_vieja_da_conflicto', $conflicto);

    // Y —lo que de verdad importa— el trabajo de Ana sigue ahí.
    $html = CanvasService::get($pageId)['html'];
    confCheck('el_trabajo_de_ana_no_se_pisa', str_contains($html, 'Ana') && !str_contains($html, 'Luis'), mb_substr($html, 0, 200));

    // Sin versión base se comporta como siempre: el llamante que no la manda
    // (una restauración, el arranque) no se ve afectado por esto.
    CanvasService::save($pageId, '<section data-pp-section="hero"><h1>Sin base</h1></section>', '', 'test', 'Sin base');
    confCheck('sin_version_base_no_hay_conflicto', str_contains(CanvasService::get($pageId)['html'], 'Sin base'));

    // --- L5: el historial dice quién ---------------------------------------
    $col = Database::selectOne("SHOW COLUMNS FROM page_versions LIKE 'created_by'");
    confCheck('page_versions_guarda_autor', $col !== null);

    $row = Database::selectOne(
        'SELECT created_by FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT 1',
        [$pageId]
    );
    // En CLI no hay sesión, así que created_by es NULL: lo que se comprueba es
    // que la columna se escribe, no su valor.
    confCheck('la_columna_se_escribe', $row !== null && array_key_exists('created_by', $row));

    // Borrar a quien hizo una versión no puede borrar la versión.
    $u = 'conflict_' . substr(bin2hex(random_bytes(3)), 0, 6);
    Database::execute('INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,?)',
        [$u, $u . '@ejemplo.invalid', password_hash('x', PASSWORD_BCRYPT), 'editor']);
    $userId = (int) Database::lastInsertId();
    Database::execute('UPDATE page_versions SET created_by = ? WHERE page_id = ? ORDER BY id DESC LIMIT 1', [$userId, $pageId]);
    $versionId = (int) Database::selectOne('SELECT id FROM page_versions WHERE page_id = ? AND created_by = ? LIMIT 1', [$pageId, $userId])['id'];
    Database::execute('DELETE FROM users WHERE id = ?', [$userId]);
    $after = Database::selectOne('SELECT created_by FROM page_versions WHERE id = ?', [$versionId]);
    confCheck('borrar_al_autor_no_borra_la_version', $after !== null, 'la versión desapareció con el usuario');
    confCheck('y_su_autoria_queda_en_blanco', $after !== null && $after['created_by'] === null);
} finally {
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
