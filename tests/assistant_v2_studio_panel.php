<?php

declare(strict_types=1);

// ASST-V2 F6e — el panel del asistente también vive en el Studio, sin botón
// flotante: el chat del Studio le pasa lo que no es de la página. Sin IA ni
// navegador: contexto de pantalla, vista y scripts servidos.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantScreenContext;
use Core\Database;

$failed = 0;
function checkV2F6e(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$read = static fn (string $path): string => (string) @file_get_contents(PP_ROOT . '/' . $path);
$view = $read('views/admin/canvas/studio.php');
$js = $read('admin/assets/js/canvas-studio.js');
$css = $read('admin/assets/css/admin.css');

// --- El panel sabe en qué página está -------------------------------------------
$page = Database::selectOne("SELECT id, title FROM pages WHERE site_id = 1 AND render_mode = 'canvas' AND slug NOT LIKE '\\_\\_%' ORDER BY id LIMIT 1");
$ctx = $page ? AssistantScreenContext::forPath(1, '/admin/canvas/' . (int) $page['id']) : null;
checkV2F6e('studio_context_is_the_page', $ctx !== null && $ctx['type'] === 'page' && $ctx['id'] === (int) $page['id'], json_encode($ctx, JSON_UNESCAPED_UNICODE));
checkV2F6e('unknown_page_no_context', AssistantScreenContext::forPath(1, '/admin/canvas/99999999') === null);

// --- Vista: el panel se incluye solo con permiso y sin lanzador propio ---------------
checkV2F6e('studio_includes_drawer', str_contains($view, "assistant/_drawer.php") && str_contains($view, "'/admin/assistant'"));
checkV2F6e('studio_marks_assistant', str_contains($view, 'data-assistant='));
checkV2F6e('launcher_hidden_in_studio', str_contains($css, '.cvstudio-body .ppa-launcher{display:none}'));
checkV2F6e('studio_offers_panel_link', str_contains($view, 'cv.ask_assistant_other') && str_contains($view, 'data-assistant-open'));

// --- Script del Studio ---------------------------------------------------------------
checkV2F6e('detects_outside_page', str_contains($js, 'function outsidePage(') && str_contains($js, 'js.cv.outside_page'));
checkV2F6e('hands_over_with_prompt', str_contains($js, "setAttribute('data-assistant-send'") && str_contains($js, 'dataset.assistantPrompt'));
checkV2F6e('can_keep_it_here', str_contains($js, 'js.cv.outside_keep_here') && str_contains($js, 'skipOutsideCheck'));
checkV2F6e('blocks_panel_on_this_page', str_contains($js, "'ppa:before-apply'") && str_contains($js, 'js.cv.panel_same_page'));
checkV2F6e('reloads_after_panel', (bool) preg_match("/'ppa:applied'[\\s\\S]{0,300}reloadPreview\\(/", $js));
checkV2F6e('escape_not_stolen', str_contains($js, "closest('#ppa-drawer')"));

foreach (['cv.ask_assistant_other', 'js.cv.outside_page', 'js.cv.outside_go', 'js.cv.outside_keep_here', 'js.cv.panel_same_page'] as $key) {
    checkV2F6e('string_' . $key, __($key) !== $key);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
