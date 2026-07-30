<?php

declare(strict_types=1);

/**
 * STUDIO-2 C3-FIX — La generación de páginas usa las fotos del negocio.
 *
 * Reproduce el caso real reportado: un sitio con 9 fotos propias descritas
 * generaba una página con 2 fotos descargadas de Unsplash, porque el
 * emparejado por palabras no encontraba sinónimos y caía al banco.
 *
 * Inserta filas temporales en `media` (site 1) y las borra al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\OnboardingController;
use App\Services\MediaLibraryService;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . ($extra !== '' ? ' — ' . $extra : '') . PHP_EOL;
    if (!$ok) $failed++;
}

$siteId = 1;
$marker = 'pp-test-gen-';
$ids = [];

// Fotos propias como las que describe nuestra IA de visión al subirlas.
$fotos = [
    'Grupo de alumnos en clase tomando apuntes con la profesora al fondo',
    'Fachada del centro de formación con rótulo y puerta de cristal',
    'Profesora explicando en una pizarra blanca con esquemas',
    'Mesa de estudio con libros de texto y bolígrafos',
    'Pasillo del centro con taquillas y luz natural',
    'Dos personas sonriendo en el mostrador de recepción',
    'Sala de ordenadores con puestos individuales',
    'Estantería con manuales y temarios ordenados',
    'Grupo posando en la entrada tras la entrega de diplomas',
];

// Briefs como los que devuelve DESCRIBE_REFERENCE_LAYOUT: mismo significado
// que las fotos, otras palabras. Ahí fallaba el emparejado léxico.
$plan = [];
foreach ([
    'estudiantes en un aula preparando oposiciones',
    'equipo docente del centro',
    'ambiente de estudio y concentración',
    'instalaciones del centro educativo',
    'jóvenes celebrando un logro académico',
] as $subject) {
    $plan[] = [
        'role' => 'Sección de ' . $subject,
        'goal' => '',
        'theme' => 'image',
        'image_brief' => ['subject' => $subject, 'orientation' => 'landscape', 'count' => 1],
        'images' => [],
    ];
}

try {
    foreach ($fotos as $i => $alt) {
        Database::execute(
            "INSERT INTO media (site_id, filename, original_name, mime_type, file_size, path, alt_text, width, height, source)
             VALUES (?, ?, ?, 'image/jpeg', 1000, ?, ?, 1600, 900, 'upload')",
            [$siteId, $marker . $i . '.jpg', 'IMG_20' . $i . '.jpg', 'storage/uploads/' . $siteId . '/' . $marker . $i . '.jpg', $alt]
        );
        $ids[] = (int) Database::lastInsertId();
    }
    check('9 fotos propias en la biblioteca', count(MediaLibraryService::images($siteId, 50, true)) >= 9);

    $bankBefore = (int) Database::selectOne("SELECT COUNT(*) c FROM media WHERE site_id = ? AND source = 'unsplash'", [$siteId])['c'];

    $resolve = new ReflectionMethod(OnboardingController::class, 'resolvePlanImages');
    $resolved = $resolve->invoke(null, $siteId, $plan);

    $bankAfter = (int) Database::selectOne("SELECT COUNT(*) c FROM media WHERE site_id = ? AND source = 'unsplash'", [$siteId])['c'];
    check('NO se descarga nada del banco', $bankAfter === $bankBefore, "antes {$bankBefore}, después {$bankAfter}");

    // Lo asignado debe ser propio; lo no asignado se resuelve por el pool.
    $assignedOwn = 0;
    $assignedOther = 0;
    foreach ($resolved as $block) {
        foreach ((array) ($block['images'] ?? []) as $img) {
            if (!empty($img['own'])) $assignedOwn++; else $assignedOther++;
        }
    }
    check('ninguna imagen asignada es de banco', $assignedOther === 0, "de banco: {$assignedOther}");

    // El umbral de 2 palabras evita emparejar por casualidad. "equipo docente
    // del centro" comparte solo "centro" con varias fotos de instalaciones:
    // ninguna es la foto de la profesora, que es la que de verdad encaja.
    $pool = MediaLibraryService::images($siteId, 50, true);
    $flojo = MediaLibraryService::bestMatch($pool, 'equipo docente del centro', [], 'landscape', 2);
    check('con umbral 2 no empareja "equipo docente" por casualidad',
        $flojo === null, $flojo ? (string) $flojo['alt_text'] : 'ninguna');

    // Con umbral 1 (comportamiento anterior) sí emparejaba, y mal: elegía una
    // foto que solo comparte la palabra incidental "centro".
    $antiguo = MediaLibraryService::bestMatch($pool, 'equipo docente del centro', [], 'landscape', 1);
    $palabrasCompartidas = $antiguo
        ? count(array_intersect(
            MediaLibraryService::keywords('equipo docente del centro'),
            MediaLibraryService::keywords((string) $antiguo['alt_text'])
        ))
        : 0;
    check('con umbral 1 emparejaba con una sola palabra en común (el fallo que se arregla)',
        $antiguo !== null && $palabrasCompartidas === 1,
        $antiguo ? '"' . $antiguo['alt_text'] . '" (' . $palabrasCompartidas . ' palabra)' : 'ninguna');

    // Coincidencia clara: sigue asignándose sin molestar al modelo.
    $claro = MediaLibraryService::bestMatch($pool, 'pizarra blanca con esquemas de la profesora', [], 'landscape', 2);
    check('coincidencia clara sí se asigna', $claro !== null && str_contains((string) $claro['alt_text'], 'pizarra'));

    // Las secciones "a sangre" no se degradan si quedan fotos propias en el pool.
    $conservanImagen = 0;
    foreach ($resolved as $block) if (($block['theme'] ?? '') === 'image') $conservanImagen++;
    check('las bandas de foto no se degradan a "dark" habiendo pool', $conservanImagen === count($plan), "quedan {$conservanImagen} de " . count($plan));

    // Sin ninguna foto propia, el pool desaparece y las bandas "a sangre" sí se
    // degradan: es el único caso en que el banco tiene sentido.
    foreach ($ids as $id) Database::execute('UPDATE media SET source = "unsplash" WHERE id = ?', [$id]);
    $sinPropias = MediaLibraryService::images($siteId, 50, true);
    if ($sinPropias === []) {
        $sinPool = $resolve->invoke(null, $siteId, $plan);
        $degradadas = 0;
        foreach ($sinPool as $block) if (($block['theme'] ?? '') === 'dark') $degradadas++;
        check('sin fotos propias las bandas de foto se degradan', $degradadas > 0, "degradadas: {$degradadas}");
    } else {
        echo "SKIP degradación sin fotos propias — el sitio de dev tiene "
            . count($sinPropias) . " subida(s) real(es); no se toca\n";
    }
    echo "     (el déficit real contra Unsplash no se ejercita: gastaría cuota de red)\n";
} finally {
    foreach ($ids as $id) Database::execute('DELETE FROM media WHERE id = ? AND site_id = ?', [$id, $siteId]);
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
