<?php

// STUDIO-STRUCTURE S1 — Contrato puro para manipular secciones top-level.
// No toca BD ni HTTP: fija primero las reglas DOM que consumirá el endpoint S2.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;

$failed = 0;
function structureCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

/** @return string[] */
function structureIds(?string $html): array
{
    if ($html === null) return [];
    return array_column(CanvasService::listSections($html), 'id');
}

$base = '<section data-pp-section="hero"><h1>Inicio</h1></section>'
    . '<section data-pp-section="features"><h2>Ventajas</h2></section>'
    . '<section data-pp-section="cta"><p>Cierre</p></section>';
$new = '<section data-pp-section="manual-text-abc" data-pp-label="Texto"><h2>Título</h2></section>';

// Inserción explícita: antes/después de ancla y extremos sin ancla.
$inserted = CanvasService::insertSectionRelative($base, $new, 'features', 'before');
structureCheck('insert_before_anchor', structureIds($inserted) === ['hero', 'manual-text-abc', 'features', 'cta'], (string) $inserted);
structureCheck('insert_keeps_label', str_contains((string) $inserted, 'data-pp-label="Texto"'), (string) $inserted);

$inserted = CanvasService::insertSectionRelative($base, $new, 'features', 'after');
structureCheck('insert_after_anchor', structureIds($inserted) === ['hero', 'features', 'manual-text-abc', 'cta'], (string) $inserted);

$inserted = CanvasService::insertSectionRelative($base, $new, '', 'before');
structureCheck('insert_without_anchor_at_start', structureIds($inserted) === ['manual-text-abc', 'hero', 'features', 'cta'], (string) $inserted);

$inserted = CanvasService::insertSectionRelative($base, $new, '', 'after');
structureCheck('insert_without_anchor_at_end', structureIds($inserted) === ['hero', 'features', 'cta', 'manual-text-abc'], (string) $inserted);

structureCheck('insert_unknown_anchor_rejected', CanvasService::insertSectionRelative($base, $new, 'missing', 'after') === null);
structureCheck('insert_invalid_position_rejected', CanvasService::insertSectionRelative($base, $new, 'hero', 'middle') === null);
structureCheck(
    'insert_duplicate_id_rejected',
    CanvasService::insertSectionRelative($base, '<section data-pp-section="hero"><p>Duplicada</p></section>', 'cta', 'after') === null
);
structureCheck(
    'insert_requires_one_top_level_section',
    CanvasService::insertSectionRelative($base, '<div data-pp-section="loose">No es section</div>', 'cta', 'after') === null
        && CanvasService::insertSectionRelative($base, $new . '<section data-pp-section="second"></section>', 'cta', 'after') === null
);

// Eliminación exacta, incluido el estado vacío; un id anidado no cuenta.
$deleted = CanvasService::deleteSection($base, 'features');
structureCheck('delete_middle_section', structureIds($deleted) === ['hero', 'cta'] && !str_contains((string) $deleted, 'Ventajas'), (string) $deleted);
structureCheck('delete_unknown_rejected', CanvasService::deleteSection($base, 'missing') === null);
structureCheck(
    'delete_nested_anchor_rejected',
    CanvasService::deleteSection('<section data-pp-section="outer"><div data-pp-section="inner">X</div></section>', 'inner') === null
);
structureCheck(
    'delete_last_section_returns_empty_page',
    CanvasService::deleteSection('<section data-pp-section="only"><p>Única</p></section>', 'only') === ''
);

// Movimiento por un solo paso y límites como no-op recuperable.
$moved = CanvasService::moveSection($base, 'features', 'up');
structureCheck('move_section_up', structureIds($moved) === ['features', 'hero', 'cta'], (string) $moved);

$moved = CanvasService::moveSection($base, 'features', 'down');
structureCheck('move_section_down', structureIds($moved) === ['hero', 'cta', 'features'], (string) $moved);

$moved = CanvasService::moveSection($base, 'hero', 'up');
structureCheck('move_first_up_is_noop', structureIds($moved) === ['hero', 'features', 'cta'], (string) $moved);

$moved = CanvasService::moveSection($base, 'cta', 'down');
structureCheck('move_last_down_is_noop', structureIds($moved) === ['hero', 'features', 'cta'], (string) $moved);

structureCheck('move_unknown_rejected', CanvasService::moveSection($base, 'missing', 'up') === null);
structureCheck('move_invalid_direction_rejected', CanvasService::moveSection($base, 'features', 'left') === null);

// Los bloques dinámicos son texto persistido y deben sobrevivir sin expansión.
$embedPage = '<section data-pp-section="hero"><h1>Hero</h1></section>'
    . '<section data-pp-section="resources-x" data-pp-label="Ressources">{{resources:featured|limit=2}}</section>'
    . '<section data-pp-section="form-x">{{form:391}}</section>';
$movedEmbed = CanvasService::moveSection($embedPage, 'resources-x', 'down');
structureCheck(
    'move_preserves_dynamic_placeholders',
    structureIds($movedEmbed) === ['hero', 'form-x', 'resources-x']
        && str_contains((string) $movedEmbed, '{{resources:featured|limit=2}}')
        && str_contains((string) $movedEmbed, '{{form:391}}'),
    (string) $movedEmbed
);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
