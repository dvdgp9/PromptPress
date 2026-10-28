<?php

declare(strict_types=1);

// ASST-V2 F2 — panel lateral: contexto de pantalla y botones «Llévame».
// La parte con BD crea un formulario de prueba en el sitio 1 y lo borra.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AdminNavigation;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantScreenContext;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F2(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Etiqueta de pantalla por ruta ----------------------------------------
checkV2F2('label_for_form_editor', AdminNavigation::labelKeyForPath('/admin/formularios/12') === 'nav.forms');
checkV2F2('label_longest_match_wins', AdminNavigation::labelKeyForPath('/admin/actividad') === 'nav.activity');
checkV2F2('label_for_dashboard_only_exact', AdminNavigation::labelKeyForPath('/admin') === 'nav.dashboard');
checkV2F2('label_unknown_path', AdminNavigation::labelKeyForPath('/admin/nada-de-nada') === null);

// --- Contexto ---------------------------------------------------------------
$site = 1;
checkV2F2('no_context_on_dashboard', AssistantScreenContext::forPath($site, '/admin') === null);
$screen = AssistantScreenContext::forPath($site, '/admin/design');
checkV2F2('screen_context_uses_nav_label', ($screen['type'] ?? '') === 'screen' && ($screen['label'] ?? '') === __('nav.design'), json_encode($screen));
checkV2F2('unknown_form_has_no_context', AssistantScreenContext::forPath($site, '/admin/formularios/999999999') === null);
checkV2F2('resolve_rejects_unknown_type', AssistantScreenContext::resolve($site, 'user', 1) === null);
checkV2F2('prompt_block_empty_without_context', AssistantScreenContext::promptBlock(null) === '');
$block = AssistantScreenContext::promptBlock(['type' => 'form', 'id' => 7, 'label' => 'Formulario «Pide información»']);
checkV2F2('prompt_block_names_form', str_contains($block, 'form_id=7') && str_contains($block, 'Pide información'), $block);

// --- «Llévame» ---------------------------------------------------------------
$link = AssistantScreenContext::deepLink(['form_id' => 7], $site);
checkV2F2('deep_link_to_form', ($link['url'] ?? '') === base_url('admin/formularios/7'), json_encode($link));
$link = AssistantScreenContext::deepLink(['admin_path' => '/admin/chrome'], $site);
checkV2F2('deep_link_to_screen_with_label', ($link['url'] ?? '') === base_url('admin/chrome')
    && str_contains((string) ($link['label'] ?? ''), __('nav.chrome')), json_encode($link));
checkV2F2('deep_link_none_without_path', AssistantScreenContext::deepLink(['admin_path' => ''], $site) === null);
checkV2F2('deep_link_ignores_external_path', AssistantScreenContext::deepLink(['admin_path' => 'https://evil.test'], $site) === null);

// --- El planner conserva el formulario en forms.manage (para el enlace) ------
$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [[
    'capability_id' => 'forms.manage', 'category' => 'manual_in_platform', 'status' => 'no_viable',
    'form_id' => 7, 'page_id' => 0, 'instruction' => 'Cambiar el destinatario',
]], [], AssistantCapabilityRegistry::catalogForState(), null, [7 => ['id' => 7, 'heading' => 'Pide información', 'fields' => [], 'pages' => [], 'translated' => []]]);
checkV2F2('forms_manage_keeps_form_id', ($items[0]['form_id'] ?? 0) === 7 && ($items[0]['category'] ?? '') === 'manual_in_platform', json_encode($items[0]));

// --- Con BD: contexto de un formulario real --------------------------------
$content = FormTemplates::content('contact', 'es');
$content['heading'] = 'Prueba panel F2';
$formId = FormStore::create($site, $content);
$ctx = AssistantScreenContext::forPath($site, '/admin/formularios/' . $formId);
checkV2F2('form_context_resolved', ($ctx['type'] ?? '') === 'form' && ($ctx['id'] ?? 0) === $formId
    && str_contains((string) ($ctx['label'] ?? ''), 'Prueba panel F2'), json_encode($ctx));
Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
