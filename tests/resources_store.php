<?php

declare(strict_types=1);

/**
 * FEAT-RESOURCES R1 — contrato de ResourceStore.
 *
 * Cubre aislamiento por sitio, defaults seguros, slug por idioma, grupo de
 * traducción, relaciones del mismo sitio, publicación y borrado. No toca aún
 * el sistema de archivos: R2 será dueño de la subida y descarga real.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Resources\ResourceStore;
use App\Services\FormStore;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check_rs(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

/** @param callable():mixed $fn */
function throws_rs(string $name, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check_rs($name, false, 'no lanzó excepción');
    } catch (InvalidArgumentException $e) {
        check_rs($name, $contains === '' || str_contains($e->getMessage(), $contains), $e->getMessage());
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
check_rs('hay site para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$primary = LanguageService::primaryFor($siteId);
$enWasActive = LanguageService::isActive($siteId, 'en');
$created = [];
$mediaId = 0;
$formId = 0;

try {
    if (!$enWasActive) {
        $enabled = LanguageService::enable($siteId, 'en');
        check_rs('idioma secundario temporal habilitado', ($enabled['ok'] ?? false) === true, json_encode($enabled));
    }

    // Relaciones temporales y del mismo sitio; se eliminan al final.
    Database::execute(
        'INSERT INTO media
            (site_id, filename, original_name, mime_type, file_size, path, alt_text, source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
        [$siteId, 'resource-r1-cover.jpg', 'resource-r1-cover.jpg', 'image/jpeg', 10,
         'storage/uploads/' . $siteId . '/resource-r1-cover.jpg', 'Portada R1', 'upload']
    );
    $mediaId = (int) Database::lastInsertId();

    $containerId = FormStore::containerPageId($siteId);
    Database::execute(
        "INSERT INTO page_sections
            (page_id, section_type, sort_order, content, status, created_at, updated_at)
         VALUES (?, 'form', 99999, ?, 'editable', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        [$containerId, json_encode(['heading' => 'Formulario R1', 'fields' => []], JSON_UNESCAPED_UNICODE)]
    );
    $formId = (int) Database::lastInsertId();

    // Defaults: borrador, idioma principal, grupo y sin archivo.
    $id = ResourceStore::create($siteId, ['title' => '  Guía práctica R1  ']);
    $created[] = $id;
    $r = ResourceStore::find($siteId, $id);
    check_rs('create devuelve id', $id > 0);
    check_rs('título se normaliza', ($r['title'] ?? '') === 'Guía práctica R1', (string) ($r['title'] ?? ''));
    check_rs('slug se genera', ($r['slug'] ?? '') === 'guia-practica-r1', (string) ($r['slug'] ?? ''));
    check_rs('nace en borrador', ($r['status'] ?? '') === 'draft');
    check_rs('usa idioma principal', ($r['language'] ?? '') === $primary, (string) ($r['language'] ?? ''));
    check_rs('grupo de traducción UUID', preg_match('/^[0-9a-f-]{36}$/i', (string) ($r['translation_group'] ?? '')) === 1);
    check_rs('sin archivo permitido en borrador', ($r['file_path'] ?? null) === null && ($r['file_size'] ?? null) === null);
    check_rs('acceso directo por defecto', ($r['access_mode'] ?? '') === 'direct' && ($r['form_id'] ?? null) === null);

    // Slug único por sitio+idioma y reutilizable en otro idioma.
    $id2 = ResourceStore::create($siteId, ['title' => 'Guía práctica R1', 'language' => $primary]);
    $created[] = $id2;
    check_rs('slug duplicado mismo idioma usa -2', (ResourceStore::find($siteId, $id2)['slug'] ?? '') === 'guia-practica-r1-2');

    $idEn = ResourceStore::create($siteId, [
        'title' => 'Guía práctica R1',
        'language' => 'en',
        'translation_group' => (string) $r['translation_group'],
    ]);
    $created[] = $idEn;
    $rEn = ResourceStore::find($siteId, $idEn);
    check_rs('mismo slug permitido en otro idioma', ($rEn['slug'] ?? '') === 'guia-practica-r1');
    check_rs('variante conserva translation_group', ($rEn['translation_group'] ?? '') === $r['translation_group']);

    // Relaciones válidas e hidratación de portada/formulario.
    $ok = ResourceStore::update($siteId, $id, [
        'cover_media_id' => $mediaId,
        'access_mode' => 'form',
        'form_id' => $formId,
        'category' => ' Guías ',
        'description' => ' Una descripción ',
    ]);
    $r = ResourceStore::find($siteId, $id);
    check_rs('update parcial conserva campos no enviados', $ok && ($r['title'] ?? '') === 'Guía práctica R1');
    check_rs('relaciones del sitio se guardan', (int) $r['cover_media_id'] === $mediaId && (int) $r['form_id'] === $formId);
    check_rs('find hidrata portada y formulario', ($r['cover_path'] ?? '') !== '' && ($r['form_heading'] ?? '') === 'Formulario R1');
    check_rs('texto opcional se normaliza', ($r['category'] ?? '') === 'Guías' && ($r['description'] ?? '') === 'Una descripción');

    // Publicación: metadatos de archivo obligatorios y form válido en modo form.
    throws_rs('no publica sin archivo', static fn () => ResourceStore::update($siteId, $id, ['status' => 'published']), 'archivo');
    $ok = ResourceStore::update($siteId, $id, [
        'status' => 'published',
        'file_path' => 'storage/resources/' . $siteId . '/r1-test.pdf',
        'original_filename' => 'Guía práctica.pdf',
        'file_mime' => 'application/pdf',
        'file_size' => 1234,
    ]);
    $r = ResourceStore::find($siteId, $id);
    check_rs('publica con archivo y formulario válidos', $ok && ($r['status'] ?? '') === 'published');
    check_rs('published_at se fija', !empty($r['published_at']));
    check_rs('findPublishedBySlug filtra idioma y estado',
        ResourceStore::findPublishedBySlug($siteId, $primary, 'guia-practica-r1') !== null
        && ResourceStore::findPublishedBySlug($siteId, 'en', 'guia-practica-r1') === null);

    ResourceStore::update($siteId, $id, ['status' => 'draft']);
    $r = ResourceStore::find($siteId, $id);
    check_rs('volver a borrador limpia published_at', ($r['status'] ?? '') === 'draft' && $r['published_at'] === null);

    // Guardas: título, idioma activo y pertenencia de relaciones.
    throws_rs('título obligatorio', static fn () => ResourceStore::create($siteId, ['title' => '   ']), 'título');
    throws_rs('idioma debe estar activo', static fn () => ResourceStore::create($siteId, ['title' => 'x', 'language' => 'fr']), 'idioma');
    throws_rs('portada de otro sitio se rechaza', static fn () => ResourceStore::create($siteId + 999999, [
        'title' => 'x', 'cover_media_id' => $mediaId,
    ]), 'portada');
    throws_rs('formulario de otro sitio se rechaza', static fn () => ResourceStore::create($siteId + 999999, [
        'title' => 'x', 'access_mode' => 'form', 'form_id' => $formId,
    ]), 'formulario');

    // Si el formulario se borra suavemente, no se puede publicar.
    Database::execute("UPDATE page_sections SET status = 'deleted' WHERE id = ?", [$formId]);
    throws_rs('formulario eliminado bloquea publicación', static fn () => ResourceStore::update($siteId, $id, [
        'status' => 'published',
    ]), 'formulario');
    Database::execute("UPDATE page_sections SET status = 'editable' WHERE id = ?", [$formId]);

    // Renombrar regenera slug; otros updates lo conservan.
    ResourceStore::update($siteId, $id2, ['category' => 'Plantillas']);
    check_rs('update sin cambiar título conserva slug', (ResourceStore::find($siteId, $id2)['slug'] ?? '') === 'guia-practica-r1-2');
    ResourceStore::update($siteId, $id2, ['title' => 'Manual R1']);
    check_rs('cambiar título regenera slug', (ResourceStore::find($siteId, $id2)['slug'] ?? '') === 'manual-r1');

    // Listado e aislamiento por site.
    $all = ResourceStore::all($siteId);
    $mine = array_filter($all, static fn (array $row): bool => in_array((int) $row['id'], $created, true));
    check_rs('all incluye los recursos creados', count($mine) === 3);
    check_rs('find aislado por site', ResourceStore::find($siteId + 999999, $id) === null);
    check_rs('update ajeno devuelve false', ResourceStore::update($siteId + 999999, $id, ['title' => 'No']) === false);
    check_rs('delete ajeno devuelve false', ResourceStore::delete($siteId + 999999, $id) === false);
} finally {
    foreach ($created as $resourceId) {
        Database::execute('DELETE FROM resources WHERE id = ?', [$resourceId]);
    }
    if ($formId > 0) Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);
    if ($mediaId > 0) Database::execute('DELETE FROM media WHERE id = ?', [$mediaId]);
    if (!$enWasActive) {
        LanguageService::forget($siteId);
        LanguageService::disable($siteId, 'en');
        LanguageService::forget($siteId);
    }
}

check_rs('delete scoped funciona', (function () use ($siteId): bool {
    $id = ResourceStore::create($siteId, ['title' => 'Borrado R1']);
    $ok = ResourceStore::delete($siteId, $id);
    return $ok && ResourceStore::find($siteId, $id) === null;
})());

echo $failed === 0 ? 'ALL PASS' . PHP_EOL : $failed . ' FAILED' . PHP_EOL;
exit($failed === 0 ? 0 : 1);

