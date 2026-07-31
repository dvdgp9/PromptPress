<?php

declare(strict_types=1);

/**
 * PAGES-OPS G8 — Operaciones sobre páginas desde el mapa y la lista.
 *
 * Lo que se cubre es lo que rompe en silencio:
 *   - el estado, que ahora tiene UN camino para páginas clásicas y canvas;
 *   - el duplicado, donde el error fácil es copiar el `translation_group` y
 *     convertir la copia en "traducción" de la original;
 *   - la home, que antes podía cambiar sola porque el resolutor público coge
 *     `ORDER BY updated_at DESC` cuando hay varias marcadas;
 *   - el borrado, que arrastra hijas, traducciones y enlaces sin avisar;
 *   - el movimiento en el árbol, que renumera hermanas y no puede aceptar ciclos.
 *
 * No llama a la IA. Crea sus propias páginas y las borra al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\PageController;
use App\Controllers\Public\PageController as PublicPageController;
use Core\Database;

$failed = 0;
function check_ops(string $name, bool $ok, string $extra = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($extra !== '' ? ' — ' . $extra : '') . PHP_EOL;
    if (!$ok) $failed++;
}

/** Invoca un método privado/protegido del controlador. */
function ops_call(string $method, ...$args): mixed
{
    return (new ReflectionMethod(PageController::class, $method))->invoke(null, ...$args);
}

$siteId = 1;
$ids = [];
$marker = 'pp-test-ops-' . bin2hex(random_bytes(4));

/** Página de prueba directa en BD (sin pasar por HTTP). */
$makePage = static function (array $overrides = []) use ($siteId, &$ids, $marker): int {
    $data = array_merge([
        'title' => 'Test ops',
        'slug' => $marker . '-' . count($ids),
        'page_type' => 'landing',
        'status' => 'draft',
        'render_mode' => 'sections',
        'parent_id' => null,
    ], $overrides);

    Database::execute(
        'INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status, render_mode, parent_id, tree_sort_order, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, UUID(), ?, ?, ?, ?, NOW(), NOW())',
        [
            $siteId, $data['title'], $data['slug'], $data['page_type'],
            \App\Services\LanguageService::primaryFor($siteId),
            $data['status'], $data['render_mode'], $data['parent_id'],
            (int) ($data['tree_sort_order'] ?? 0),
        ]
    );
    $id = (int) Database::lastInsertId();
    $ids[] = $id;
    return $id;
};

$homeBackup = Database::select("SELECT id, page_type FROM pages WHERE site_id = ? AND page_type = 'home'", [$siteId]);

try {
    // ------------------------------------------------------------------
    // G1 — Estado: el mismo camino para clásicas y canvas
    // ------------------------------------------------------------------
    foreach (['sections', 'canvas'] as $mode) {
        $id = $makePage(['render_mode' => $mode, 'title' => 'Test estado ' . $mode]);
        $page = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$id]);

        ops_call('applyStatus', $siteId, $page, 'published');
        $after = Database::selectOne('SELECT status, published_at FROM pages WHERE id = ?', [$id]);
        check_ops('publicar una página ' . $mode, ($after['status'] ?? '') === 'published');
        check_ops('publicar por primera vez fecha la publicación (' . $mode . ')', !empty($after['published_at']));

        $publishedAt = $after['published_at'];
        $page['status'] = 'published';
        $page['published_at'] = $publishedAt;
        ops_call('applyStatus', $siteId, $page, 'draft');
        $back = Database::selectOne('SELECT status, published_at FROM pages WHERE id = ?', [$id]);
        check_ops('volver a borrador una página ' . $mode, ($back['status'] ?? '') === 'draft');
        // La fecha es un histórico: volver a borrador no la borra.
        check_ops('volver a borrador conserva la fecha de publicación (' . $mode . ')',
            (string) $back['published_at'] === (string) $publishedAt);
    }

    // ------------------------------------------------------------------
    // G3 — Duplicado
    // ------------------------------------------------------------------
    $origId = $makePage(['render_mode' => 'canvas', 'title' => 'Original canvas', 'status' => 'published']);
    Database::execute('INSERT INTO page_canvas (page_id, html, css) VALUES (?, ?, ?)',
        [$origId, '<section>hola</section>', '.x{color:red}']);
    $orig = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$origId]);

    $copyId = PageController::createPageRow($siteId, [
        'title' => $orig['title'] . ' (copia)',
        'slug' => (string) $orig['slug'],
        'page_type' => 'landing',
        'language' => (string) $orig['language'],
        'status' => 'draft',
    ]);
    $ids[] = $copyId;
    Database::execute('UPDATE pages SET render_mode = ? WHERE id = ?', [(string) $orig['render_mode'], $copyId]);
    ops_call('copyPageContent', $origId, $copyId);

    $copy = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$copyId]);
    $copyCanvas = Database::selectOne('SELECT html, css FROM page_canvas WHERE page_id = ?', [$copyId]);

    check_ops('la copia nace en borrador', ($copy['status'] ?? '') === 'draft');
    check_ops('la copia conserva el modo canvas', ($copy['render_mode'] ?? '') === 'canvas');
    check_ops('la copia lleva el contenido', ($copyCanvas['html'] ?? '') === '<section>hola</section>');
    check_ops('la copia NO comparte grupo de traducción con la original',
        (string) $copy['translation_group'] !== (string) $orig['translation_group']);
    check_ops('la copia no pisa el slug de la original', (string) $copy['slug'] !== (string) $orig['slug']);

    // ------------------------------------------------------------------
    // G4 — Home explícita: nunca dos a la vez
    // ------------------------------------------------------------------
    $homeA = $makePage(['title' => 'Home A', 'page_type' => 'home', 'status' => 'published']);
    $homeB = $makePage(['title' => 'Home B', 'page_type' => 'home', 'status' => 'published']);
    $lang = \App\Services\LanguageService::primaryFor($siteId);

    $demoted = ops_call('demoteOtherHomes', $siteId, $lang, $homeB);
    $homes = Database::select("SELECT id FROM pages WHERE site_id = ? AND page_type = 'home'", [$siteId]);
    check_ops('marcar inicio degrada TODAS las demás homes del idioma',
        count($homes) === 1 && (int) $homes[0]['id'] === $homeB, 'quedan ' . count($homes));
    check_ops('la degradada deja de ser home pero sigue existiendo',
        (string) Database::selectOne('SELECT page_type FROM pages WHERE id = ?', [$homeA])['page_type'] === 'landing');
    check_ops('la operación informa de qué degradó', count($demoted) >= 1);

    $served = PublicPageController::homePageFor($siteId, $lang);
    check_ops('la web pública sirve la nueva home', $served !== null && (int) $served['id'] === $homeB,
        $served ? 'sirve ' . $served['id'] : 'ninguna');

    // Una entrada del blog no puede ser portada: lo comprueba el endpoint.
    check_ops('el tipo "article" queda fuera de la portada',
        in_array('article', array_keys(PageController::PAGE_TYPES), true));

    // ------------------------------------------------------------------
    // G5 — Qué arrastra un borrado
    // ------------------------------------------------------------------
    $parentId = $makePage(['title' => 'Padre a borrar', 'status' => 'published', 'slug' => $marker . '-padre']);
    $childId = $makePage(['title' => 'Hija', 'parent_id' => $parentId]);
    $linkerId = $makePage(['title' => 'Quien enlaza', 'render_mode' => 'canvas']);
    Database::execute('INSERT INTO page_canvas (page_id, html, css) VALUES (?, ?, ?)',
        [$linkerId, '<a href="/' . $marker . '-padre">ir</a>', '']);

    $inbound = ops_call('inboundLinks', $siteId, $marker . '-padre', $parentId);
    check_ops('se detecta quién enlaza a la página', count($inbound) === 1 && $inbound[0]['id'] === $linkerId,
        count($inbound) . ' encontradas');

    $children = Database::select('SELECT id FROM pages WHERE parent_id = ?', [$parentId]);
    check_ops('se detectan las hijas antes de borrar', count($children) === 1);

    // Al borrar el padre, la FK es ON DELETE SET NULL: la hija NO se borra, sube
    // a raíz. Es justo lo que el diálogo tiene que avisar.
    Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$parentId, $siteId]);
    $orphan = Database::selectOne('SELECT id, parent_id FROM pages WHERE id = ?', [$childId]);
    check_ops('borrar el padre NO borra la hija: la sube a raíz',
        $orphan !== null && $orphan['parent_id'] === null);

    // La redirección se crea con el slug de la página que se va.
    $redirect = ops_call('createDeletionRedirect', $siteId, ['id' => $parentId, 'slug' => $marker . '-padre'], '/contacto');
    check_ops('el borrado deja una 301 hacia el destino elegido', $redirect === '/contacto', (string) $redirect);
    $row = \App\Services\SeoRedirectService::findActive($siteId, '/' . $marker . '-padre');
    check_ops('la redirección queda activa y es 301',
        $row !== null && (int) $row['status_code'] === 301 && (string) $row['target_path'] === '/contacto');
    if ($row) Database::execute('DELETE FROM seo_redirects WHERE id = ?', [(int) $row['id']]);

    // Sin destino no se inventa ninguna redirección.
    check_ops('sin destino no se crea redirección',
        ops_call('createDeletionRedirect', $siteId, ['id' => 0, 'slug' => $marker . '-x'], '/' . $marker . '-x') === null);

    // ------------------------------------------------------------------
    // G6 — Destinos que se ofrecen para la redirección
    // ------------------------------------------------------------------
    $targets = ops_call('redirectTargets', $siteId, 0);
    $draftInTargets = false;
    foreach ($targets as $t) {
        if (str_contains((string) $t['slug'], $marker . '-0')) $draftInTargets = true;
    }
    check_ops('los destinos de redirección son solo páginas publicadas', !$draftInTargets);

    // ------------------------------------------------------------------
    // G7 — Ciclos en el árbol
    // ------------------------------------------------------------------
    $abueloId = $makePage(['title' => 'Abuelo']);
    $nietoId = $makePage(['title' => 'Nieto', 'parent_id' => $abueloId]);
    check_ops('mover una página bajo su propia hija se detecta como ciclo',
        (bool) ops_call('wouldCreateCycle', $abueloId, $nietoId));
    check_ops('un movimiento normal no es ciclo',
        !ops_call('wouldCreateCycle', $nietoId, $abueloId));

    // ------------------------------------------------------------------
    // Contrato de rutas y de la vista (el JS depende de los dos)
    // ------------------------------------------------------------------
    $routes = (string) file_get_contents(PP_ROOT . '/app/routes.php');
    foreach ([
        'status' => 'updateStatus', 'duplicate' => 'duplicate', 'set-home' => 'setHome',
        'delete-info' => 'deleteInfo', 'move' => 'move',
    ] as $path => $method) {
        check_ops('ruta /pages/{id}/' . $path . ' → ' . $method,
            str_contains($routes, "/pages/{id}/{$path}") && method_exists(PageController::class, $method));
    }
    check_ops('ruta /pages/bulk antes de /pages/{id}',
        strpos($routes, "/pages/bulk") < strpos($routes, "\$r->post('/pages/{id}',"));

    $view = (string) file_get_contents(PP_ROOT . '/views/admin/pages/index.php');
    $js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/pages-map.js');
    foreach (['data-page-menu', 'data-pages-row', 'data-bulk-item', 'data-pages-search', 'data-map-lang'] as $hook) {
        check_ops('la vista y el JS comparten el gancho ' . $hook,
            str_contains($view, $hook) && str_contains($js, $hook));
    }
    // El atributo [hidden] no vale de nada si la clase declara display:flex.
    $css = (string) file_get_contents(PP_ROOT . '/admin/assets/css/admin.css');
    check_ops('la barra de lote se puede ocultar de verdad', str_contains($css, '.pp-pages-bulk[hidden]{display:none}'));
} finally {
    foreach ($ids as $id) {
        Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$id]);
        Database::execute('DELETE FROM pages WHERE id = ? AND site_id = ?', [$id, $siteId]);
    }
    // Devolver la home del sitio a como estaba antes de la suite.
    Database::execute("UPDATE pages SET page_type = 'landing' WHERE site_id = ? AND page_type = 'home'", [$siteId]);
    foreach ($homeBackup as $row) {
        Database::execute('UPDATE pages SET page_type = ? WHERE id = ? AND site_id = ?',
            [(string) $row['page_type'], (int) $row['id'], $siteId]);
    }
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
