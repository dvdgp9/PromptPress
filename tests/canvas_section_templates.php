<?php

declare(strict_types=1);

// STUDIO-STRUCTURE S4 — las plantillas manuales son deterministas, seguras y
// escriben contenido inicial en el idioma de la página, no en el del panel.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\Canvas\CanvasSectionTemplates;
use App\Services\Canvas\CanvasService;

$failed = 0;
function templateCheck(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

$catalog = CanvasSectionTemplates::catalog();
templateCheck('catálogo exacto y estable', array_keys($catalog) === ['text', 'text_image', 'cta']);

$textFr = CanvasSectionTemplates::render('text', 'fr', 'a1b2c3', '/public/assets/img/studio-placeholder.svg');
templateCheck('texto francés usa idioma de página',
    is_array($textFr)
    && str_contains($textFr['html'], 'Une idée à retenir')
    && !str_contains($textFr['html'], 'Una idea para recordar'),
    (string) ($textFr['html'] ?? '')
);
templateCheck('texto tiene sección única y campos editables',
    ($textFr['id'] ?? '') === 'manual-text-a1b2c3'
    && str_contains($textFr['html'] ?? '', 'data-pp-section="manual-text-a1b2c3"')
    && str_contains($textFr['html'] ?? '', '<h2')
    && str_contains($textFr['html'] ?? '', '<p')
);

$imageEn = CanvasSectionTemplates::render('text_image', 'en', 'd4e5f6', '/public/assets/img/studio-placeholder.svg');
templateCheck('texto con imagen es reemplazable y responsive',
    is_array($imageEn)
    && str_contains($imageEn['html'], 'ppb-split--media-right')
    && str_contains($imageEn['html'], '<img src="/public/assets/img/studio-placeholder.svg"')
    && str_contains($imageEn['html'], 'A visual related to this content')
);

$ctaPt = CanvasSectionTemplates::render('cta', 'pt', '998877', '/public/assets/img/studio-placeholder.svg');
templateCheck('CTA portugués incluye enlace editable',
    is_array($ctaPt)
    && str_contains($ctaPt['html'], 'Dê o próximo passo')
    && str_contains($ctaPt['html'], '<a href="#" class="pp-btn')
);

templateCheck('plantilla desconocida se rechaza',
    CanvasSectionTemplates::render('unknown', 'es', 'x', '/x.svg') === null
);

$page = '<section data-pp-section="hero"><h1>Hero</h1></section>';
$inserted = is_array($textFr)
    ? CanvasService::insertSectionRelative($page, $textFr['html'], 'hero', 'after')
    : null;
templateCheck('plantilla resultante cumple contrato DOM Canvas',
    is_string($inserted)
    && array_column(CanvasService::listSections($inserted), 'id') === ['hero', 'manual-text-a1b2c3']
);

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
