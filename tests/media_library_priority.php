<?php

declare(strict_types=1);

/**
 * STUDIO-2 C1/C3 — La biblioteca para la IA prioriza las fotos propias.
 * Inserta filas temporales en `media` (site 1), verifica orden/formato/matching
 * y las borra al final (también si algo falla).
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\MediaLibraryService;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

$siteId = 1;
$marker = 'pp-test-medialib-';
$ids = [];

$insert = static function (string $name, string $alt, string $source, int $w, int $h) use (&$ids, $siteId, $marker): int {
    Database::execute(
        "INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
         VALUES (?, ?, ?, 'image/jpeg', 1000, ?, ?, ?, ?, ?)",
        [$siteId, $marker . $name, $name, 'storage/uploads/' . $siteId . '/' . $marker . $name, $alt !== '' ? $alt : null, $w, $h, $source]
    );
    $id = (int) Database::lastInsertId();
    $ids[] = $id;
    return $id;
};

try {
    // Banco reciente (id más alto) + propias más antiguas: las propias deben ganar.
    $ownA = $insert('fachada-centro.jpg', 'Fachada del centro de formación con cristalera', 'upload', 1600, 900);
    $ownB = $insert('aula.jpg', '', 'upload', 900, 1400); // sin alt: describe por nombre
    $bank = $insert('stock.jpg', 'Escritorio moderno con portátil', 'unsplash', 1600, 900);

    $rows = MediaLibraryService::images($siteId, 200);
    $pos = [];
    foreach ($rows as $i => $r) $pos[(int) $r['id']] = $i;
    check('propias antes que banco', isset($pos[$ownA], $pos[$ownB], $pos[$bank])
        && $pos[$ownA] < $pos[$bank] && $pos[$ownB] < $pos[$bank]);

    $own = MediaLibraryService::images($siteId, 200, true);
    $ownIds = array_map(static fn ($r) => (int) $r['id'], $own);
    check('ownOnly excluye banco', in_array($ownA, $ownIds, true) && !in_array($bank, $ownIds, true));
    check('hasOwnImages', MediaLibraryService::hasOwnImages($siteId));

    $prompt = MediaLibraryService::forAi($siteId, 200);
    $posOwnBlock = mb_strpos($prompt, 'FOTOS PROPIAS DEL NEGOCIO');
    $posBankBlock = mb_strpos($prompt, 'BANCO DE IMÁGENES');
    check('prompt separa propias y banco', $posOwnBlock !== false && $posBankBlock !== false && $posOwnBlock < $posBankBlock);
    check('prompt incluye la propia descrita', str_contains($prompt, 'Fachada del centro'));
    check('sin alt cae al nombre de archivo', str_contains($prompt, 'aula (sin descripción)'));
    check('orientación calculada', str_contains($prompt, 'horizontal') && str_contains($prompt, 'vertical'));

    // Matching de briefs (C3): por descripción y con desempate de orientación.
    $match = MediaLibraryService::bestMatch($own, 'fachada del edificio', [], 'landscape');
    check('bestMatch encuentra por descripción', $match !== null && (int) $match['id'] === $ownA);
    $match2 = MediaLibraryService::bestMatch($own, 'fachada del edificio', [$ownA], 'landscape');
    check('bestMatch respeta usados', $match2 === null || (int) $match2['id'] !== $ownA);
    check('bestMatch null sin coincidencia', MediaLibraryService::bestMatch($own, 'paella valenciana', []) === null);
    check('keywords filtra vacío y cortas', MediaLibraryService::keywords('la foto de El') === []);
} finally {
    foreach ($ids as $id) {
        Database::execute('DELETE FROM media WHERE id = ? AND site_id = ?', [$id, $siteId]);
    }
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
