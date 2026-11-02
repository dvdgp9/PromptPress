<?php

declare(strict_types=1);

// ASST-V2 F6c — el composer de formularios desaparece: crear y cambiar
// formularios con IA pasa por el panel del asistente. Sin BD ni IA: se revisa
// el código servido (rutas, vistas y scripts) y los textos nuevos.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\FormsController;

$failed = 0;
function checkV2F6c(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$read = static fn (string $path): string => (string) @file_get_contents(PP_ROOT . '/' . $path);
$routes = $read('app/routes.php');
$list = $read('views/admin/forms/list.php');
$edit = $read('views/admin/forms/edit.php');
$panel = $read('admin/assets/js/assistant.js');
$css = $read('admin/assets/css/admin.css');

// --- El composer ya no existe ---------------------------------------------------
checkV2F6c('composer_files_removed', !is_file(PP_ROOT . '/views/admin/forms/ai-composer.php') && !is_file(PP_ROOT . '/admin/assets/js/forms-ai.js'));
checkV2F6c('composer_routes_removed', !str_contains($routes, '/formularios/design') && !str_contains($routes, '/formularios/create-ai'));
checkV2F6c('composer_actions_removed', !method_exists(FormsController::class, 'design') && !method_exists(FormsController::class, 'createWithAI'));
checkV2F6c('views_do_not_load_composer', !str_contains($list . $edit, 'ai-composer') && !str_contains($list . $edit, 'forms-ai.js') && !str_contains($edit, 'data-form-ai'));
checkV2F6c('composer_css_removed', !str_contains($css, 'pp-form-ai-composer') && !str_contains($css, 'pp-form-ai-review'));
// La autorrespuesta con IA del editor sigue (no era parte del composer).
checkV2F6c('autoresponder_draft_kept', str_contains($edit, 'draft_form_autoresponder'));

// --- La lista abre el panel ---------------------------------------------------------
checkV2F6c('list_opens_panel', substr_count($list, 'data-assistant-open') >= 1 && str_contains($list, "forms.ask_assistant_prompt"));
checkV2F6c('prompt_text_exists', __('forms.ask_assistant_prompt') !== 'forms.ask_assistant_prompt' && __('forms.ask_assistant') !== 'forms.ask_assistant');

// --- El editor y el panel se avisan -------------------------------------------------
checkV2F6c('panel_announces_apply', str_contains($panel, "emit('ppa:before-apply'") && str_contains($panel, "emit('ppa:applied'"));
checkV2F6c('panel_respects_block', (bool) preg_match('/ppa:before-apply[\s\S]{0,400}\.block/', $panel));
checkV2F6c('editor_blocks_unsaved', str_contains($edit, "'ppa:before-apply'") && str_contains($edit, 'js.form_edit.unsaved_assistant'));
checkV2F6c('editor_goes_stale_after_apply', str_contains($edit, "'ppa:applied'") && str_contains($edit, 'id="pp-form-stale"'));
foreach (['js.form_edit.unsaved_assistant', 'form_edit.stale_title', 'form_edit.stale_text', 'form_edit.stale_reload'] as $key) {
    checkV2F6c('string_' . $key, __($key) !== $key);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
