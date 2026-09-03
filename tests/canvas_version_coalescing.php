<?php

declare(strict_types=1);

// STUDIO-UX F4 — Agrupar los micro-cambios en una sola versión.
//
// Hasta ahora cada clic del panel guardaba y creaba una fila en `page_versions`:
// en la página 136 de dev quedaron las versiones 144→147 creadas a las 15:43:14,
// :15, :16 y :17 (cuatro toques de un botón). Con el tope en 25 versiones, ajustar
// un titular a ojo expulsaba del historial cuatro hitos de verdad, y «Deshacer»
// pasaba a significar «quita el último píxel».
//
// Regla: los guardados `inline` consecutivos sobre la MISMA sección y dentro de
// la ventana actualizan la versión en curso en vez de crear otra.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$failed = 0;
function coalCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
coalCheck('hay sitio para probar', $siteId > 0);
if ($siteId <= 0) exit(1);

$now = date('Y-m-d H:i:s');
$slug = 'studio-f4-' . substr(bin2hex(random_bytes(4)), 0, 8);
Database::execute(
    "INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (?, 'Studio F4', ?, 'landing', 'canvas', 'draft', 0, 999, ?, ?)",
    [$siteId, $slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();

function versionCount(int $pageId): int
{
    return (int) (Database::selectOne('SELECT COUNT(*) AS n FROM page_versions WHERE page_id = ?', [$pageId])['n'] ?? 0);
}
function pointer(int $pageId): int
{
    return (int) (Database::selectOne('SELECT current_version_id FROM page_canvas WHERE page_id = ?', [$pageId])['current_version_id'] ?? 0);
}
function body(int $pageId): string
{
    return (string) (CanvasService::get($pageId)['html'] ?? '');
}
function page(string $heroText): string
{
    return '<section data-pp-section="hero"><h1>' . $heroText . '</h1></section>'
        . '<section data-pp-section="cta"><p>Cierre</p></section>';
}

try {
    // Punto de partida: una generación (no fusionable) y su versión.
    CanvasService::save($pageId, page('Original'), '', 'generate', 'Generación inicial');
    $afterGenerate = versionCount($pageId);
    $generateVersion = pointer($pageId);
    coalCheck('la generación crea su versión', $afterGenerate === 1 && $generateVersion > 0);

    // --- La ráfaga: cinco retoques seguidos de la misma sección ---------------
    for ($i = 1; $i <= 5; $i++) {
        CanvasService::save($pageId, page('Retoque ' . $i), '', 'inline', 'Hero — Edición directa');
    }
    coalCheck(
        'cinco clics seguidos dejan UNA sola versión',
        versionCount($pageId) === $afterGenerate + 1,
        'versiones: ' . versionCount($pageId)
    );
    coalCheck('la versión fusionada guarda el último estado', str_contains(body($pageId), 'Retoque 5'), body($pageId));
    $burstVersion = pointer($pageId);
    coalCheck('el puntero apunta a la versión fusionada', $burstVersion > $generateVersion);

    // --- Y «Deshacer» devuelve al estado ANTERIOR a la ráfaga -----------------
    CanvasService::undo($pageId);
    coalCheck(
        'deshacer tras la ráfaga vuelve al estado previo, no al penúltimo píxel',
        str_contains(body($pageId), 'Original') && pointer($pageId) === $generateVersion,
        body($pageId)
    );
    CanvasService::redo($pageId);
    coalCheck('rehacer devuelve el último estado de la ráfaga', str_contains(body($pageId), 'Retoque 5'), body($pageId));

    // --- Otra sección NO se fusiona con la anterior ---------------------------
    $before = versionCount($pageId);
    CanvasService::save($pageId, page('Retoque 5') . '<!--x-->', '', 'inline', 'Cta — Edición directa');
    coalCheck('un retoque de otra sección abre versión propia', versionCount($pageId) === $before + 1);

    // --- Un origen que no es `inline` nunca se fusiona ------------------------
    $before = versionCount($pageId);
    CanvasService::save($pageId, page('Por chat'), '', 'chat', 'Hero — Chat IA');
    CanvasService::save($pageId, page('Por chat 2'), '', 'chat', 'Hero — Chat IA');
    coalCheck('dos cambios de IA seguidos son dos versiones', versionCount($pageId) === $before + 2);

    $before = versionCount($pageId);
    CanvasService::save($pageId, page('Movida'), '', 'structure', 'Hero — Cambio');
    CanvasService::save($pageId, page('Movida otra vez'), '', 'structure', 'Hero — Cambio');
    coalCheck('dos cambios de estructura seguidos son dos versiones', versionCount($pageId) === $before + 2);

    // --- Fuera de la ventana temporal: versión nueva --------------------------
    CanvasService::save($pageId, page('Sesión A'), '', 'inline', 'Hero — Edición directa');
    $sessionA = pointer($pageId);
    Database::execute(
        'UPDATE page_versions SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?',
        [$sessionA]
    );
    $before = versionCount($pageId);
    CanvasService::save($pageId, page('Sesión B'), '', 'inline', 'Hero — Edición directa');
    coalCheck(
        'un retoque diez minutos después no se cuela en la versión vieja',
        versionCount($pageId) === $before + 1 && pointer($pageId) !== $sessionA
    );

    // --- Tras deshacer, el siguiente cambio NO fusiona con una versión huérfana
    CanvasService::save($pageId, page('Antes de deshacer'), '', 'inline', 'Hero — Edición directa');
    CanvasService::undo($pageId);
    $pointerAfterUndo = pointer($pageId);
    CanvasService::save($pageId, page('Rama nueva'), '', 'inline', 'Hero — Edición directa');
    coalCheck(
        'editar después de deshacer abre versión nueva, no reescribe la anterior',
        pointer($pageId) !== $pointerAfterUndo && str_contains(body($pageId), 'Rama nueva'),
        body($pageId)
    );
    coalCheck(
        'la versión a la que se deshizo queda intacta',
        !str_contains(
            (string) (Database::selectOne('SELECT html FROM page_versions WHERE id = ?', [$pointerAfterUndo])['html'] ?? ''),
            'Rama nueva'
        )
    );

    // --- La fusión no dispara el podado prematuro ----------------------------
    $countBefore = versionCount($pageId);
    for ($i = 0; $i < 10; $i++) {
        CanvasService::save($pageId, page('Fusionado ' . $i), '', 'inline', 'Hero — Edición directa');
    }
    coalCheck(
        'diez clics más no añaden diez versiones',
        versionCount($pageId) === $countBefore,
        'antes ' . $countBefore . ' ahora ' . versionCount($pageId)
    );
} finally {
    Database::execute('DELETE FROM page_versions WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
    Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
}

// --- Contrato del cliente ----------------------------------------------------
$js = (string) file_get_contents(PP_ROOT . '/admin/assets/js/canvas-studio.js');

coalCheck('el guardado de sección se agrupa antes de salir a la red',
    str_contains($js, 'function queueSectionSave(') && str_contains($js, 'function flushSectionSave(')
);
coalCheck('las acciones que leen del servidor fuerzan el guardado pendiente',
    substr_count($js, 'flushSectionSave()') >= 3
);
coalCheck('cerrar la pestaña no se lleva el cambio pendiente',
    str_contains($js, "addEventListener('beforeunload'") && str_contains($js, 'keepalive')
);
coalCheck('el estado de guardado es visible mientras dura',
    str_contains($js, "pp.t('js.cv.saving')")
);
coalCheck('el aviso de guardado deja de estar cableado en español',
    !str_contains($js, "showSaved('Guardado')") && !str_contains($js, "showSaved('No se pudo guardar'")
);

foreach (['es', 'en', 'fr', 'pt'] as $lang) {
    /** @var array<string,string> $catalog */
    $catalog = require PP_ROOT . '/lang/admin/' . $lang . '.php';
    $missing = array_values(array_filter(
        ['js.cv.saving', 'js.cv.saved', 'js.cv.save_failed'],
        static fn(string $k): bool => trim((string) ($catalog[$k] ?? '')) === ''
    ));
    coalCheck('microcopia de guardado completa en ' . $lang, $missing === [], implode(', ', $missing));
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
