<?php

declare(strict_types=1);

/**
 * ASST-CONV AC4 — cuántas imágenes ve la IA en cada paso del Asistente.
 * El planificador necesita ver todo lo adjuntado (varias capturas + páginas de
 * un PDF); quien aplica un cambio solo necesita las de su sección.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AssistantVisionImages;

$failed = 0;
function checkVisionLimit(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

checkVisionLimit('planner_sees_eight_images', AIActionRunner::visionImageLimit(Actions::PLAN_SITE_CHANGES) === 8,
    (string) AIActionRunner::visionImageLimit(Actions::PLAN_SITE_CHANGES));
checkVisionLimit('section_edit_sees_three', AIActionRunner::visionImageLimit(Actions::EDIT_CANVAS_SECTION) === 3);
checkVisionLimit('page_edit_sees_three', AIActionRunner::visionImageLimit(Actions::EDIT_CANVAS_PAGE) === 3);
checkVisionLimit('other_actions_keep_four', AIActionRunner::visionImageLimit(Actions::DESIGN_FORM) === 4);
checkVisionLimit('unknown_action_keeps_four', AIActionRunner::visionImageLimit('no_such_action') === 4);
checkVisionLimit('assistant_prepares_up_to_eight', AssistantVisionImages::MAX_IMAGES === 8);

exit($failed > 0 ? 1 : 0);
