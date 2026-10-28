<?php

declare(strict_types=1);

// ASST-V2 F1 — conversaciones guardadas y Actividad con deshacer.
// La parte con BD crea sus propios datos en el sitio 1 y los borra al acabar.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ActivityService;
use App\Services\AssistantConversation;
use App\Services\AssistantConversationStore;
use App\Services\FormStore;
use App\Services\FormTemplates;
use Core\Database;

$failed = 0;
function checkV2F1(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Saneado de la foto (sin BD) --------------------------------------------
$plan = ['reply' => 'Hola', 'items' => [['capability_id' => 'forms.edit', 'form_id' => 7, 'instruction' => 'x']], '_thumbs' => ['a' => 1], '_applied' => true];
$clean = AssistantConversationStore::sanitize([
    'state' => [
        'turns' => [['role' => 'user', 'text' => 'Cambia el formulario'], ['role' => 'hacker', 'text' => 'x']],
        'plan' => $plan,
        'source_token' => 'bad token<script>',
    ],
    'log' => [
        ['type' => 'user', 'text' => "  Cambia   el\nformulario  "],
        ['type' => 'plan', 'plan' => $plan],
        ['type' => 'html', 'text' => '<b>no</b>'],
        ['type' => 'note', 'text' => ''],
        'basura',
    ],
]);
checkV2F1('sanitize_keeps_known_entries', array_column($clean['log'], 'type') === ['user', 'plan'], json_encode($clean['log']));
checkV2F1('sanitize_strips_thumbs', !isset($clean['log'][1]['plan']['_thumbs']) && ($clean['log'][1]['plan']['_applied'] ?? false) === true);
checkV2F1('sanitize_title_from_first_user_message', $clean['title'] === 'Cambia el formulario', $clean['title']);
checkV2F1('sanitize_rejects_bad_token', $clean['state']['source_token'] === '');
checkV2F1('sanitize_filters_turn_roles', count($clean['state']['turns']) === 1, json_encode($clean['state']['turns']));
$huge = AssistantConversationStore::sanitize(['log' => [['type' => 'plan', 'plan' => ['items' => [], 'reply' => str_repeat('a', 130000)]]]]);
checkV2F1('sanitize_drops_oversized_plan', $huge['log'] === []);

// --- Plan vigente conserva form_id (arreglo de ASST-FORMS) ------------------
$current = AssistantConversation::parseCurrentPlan(json_encode([
    ['capability_id' => 'forms.edit', 'form_id' => 7, 'instruction' => 'x'],
    ['capability_id' => 'pages.canvas.edit', 'page_id' => 3, 'instruction' => 'y'],
]));
checkV2F1('current_plan_keeps_form_id', ($current[0]['form_id'] ?? 0) === 7 && !isset($current[1]['form_id']), json_encode($current));

// --- Actividad pura ---------------------------------------------------------
checkV2F1('page_actor_classification',
    ActivityService::pageActor('assistant') === 'assistant' && ActivityService::pageActor('chat') === 'ai'
    && ActivityService::pageActor('inline') === 'manual' && ActivityService::pageActor('restore') === 'restore'
);
checkV2F1('form_actor_classification',
    ActivityService::formActor('before_ai_edit') === 'assistant' && ActivityService::formActor('before_manual_update') === 'manual'
    && ActivityService::formActor('before_delete') === null
);
$sample = [
    ['actor' => 'manual', 'at' => '2026-10-27 10:00:00', 'version_id' => 1],
    ['actor' => 'assistant', 'at' => '2026-10-28 09:00:00', 'version_id' => 2],
    ['actor' => 'ai', 'at' => '2026-10-28 11:00:00', 'version_id' => 3],
];
checkV2F1('activity_sorted_newest_first', array_column(ActivityService::filterAndSort($sample, 'all'), 'version_id') === [3, 2, 1]);
checkV2F1('activity_filter_assistant', array_column(ActivityService::filterAndSort($sample, 'assistant'), 'version_id') === [2]);
checkV2F1('activity_filter_manual_includes_editor_ai', array_column(ActivityService::filterAndSort($sample, 'manual'), 'version_id') === [3, 1]);

// --- Con BD local -----------------------------------------------------------
$site = 1;
$users = array_map('intval', array_column(Database::select('SELECT id FROM users ORDER BY id LIMIT 2'), 'id'));
$me = $users[0];
$other = $users[1] ?? null;

$id = AssistantConversationStore::save($site, $me, null, ['state' => ['turns' => []], 'log' => [['type' => 'user', 'text' => 'Prueba F1']]]);
checkV2F1('db_conversation_created', is_int($id) && $id > 0);
$again = AssistantConversationStore::save($site, $me, $id, ['log' => [['type' => 'user', 'text' => 'Prueba F1'], ['type' => 'note', 'text' => 'Aplicado']]]);
checkV2F1('db_conversation_updated_in_place', $again === $id);
$found = AssistantConversationStore::find($site, $me, (int) $id);
checkV2F1('db_conversation_roundtrip', count($found['log'] ?? []) === 2 && ($found['title'] ?? '') === 'Prueba F1', json_encode($found));
checkV2F1('db_latest_is_this_one', (AssistantConversationStore::latest($site, $me)['id'] ?? 0) === $id);
if ($other !== null) {
    checkV2F1('db_other_user_cannot_read', AssistantConversationStore::find($site, $other, (int) $id) === null);
    checkV2F1('db_other_user_cannot_overwrite', AssistantConversationStore::save($site, $other, $id, ['log' => [['type' => 'user', 'text' => 'x']]]) === null);
}
checkV2F1('db_conversation_deleted', AssistantConversationStore::delete($site, $me, (int) $id) && AssistantConversationStore::find($site, $me, (int) $id) === null);

// Formulario de prueba: cambio a mano → aparece en Actividad → deshacer.
$content = FormTemplates::content('contact', 'es');
$content['heading'] = 'Prueba Actividad F1';
$formId = FormStore::create($site, $content);
FormStore::snapshot($site, $formId, $me, 'before_manual_update');
$changed = $content;
$changed['heading'] = 'Prueba Actividad F1 cambiado';
FormStore::update($site, $formId, $changed);

$entries = array_values(array_filter(
    ActivityService::entries($site, 'admin', 'all'),
    static fn (array $e): bool => $e['type'] === 'form' && $e['entity_id'] === $formId
));
checkV2F1('db_form_change_listed', count($entries) === 1 && $entries[0]['actor'] === 'manual' && $entries[0]['undoable'], json_encode($entries));
checkV2F1('db_redactor_does_not_see_forms', array_filter(
    ActivityService::entries($site, 'redactor', 'all'),
    static fn (array $e): bool => $e['type'] === 'form'
) === []);
checkV2F1('db_redactor_cannot_undo_form',
    !ActivityService::undo($site, 'redactor', $me, 'form', $formId, (int) ($entries[0]['version_id'] ?? 0))['ok']
);
$undo = ActivityService::undo($site, 'admin', $me, 'form', $formId, (int) ($entries[0]['version_id'] ?? 0));
checkV2F1('db_form_undo_restores_previous', $undo['ok'] && (FormStore::find($site, $formId)['heading'] ?? '') === 'Prueba Actividad F1', json_encode($undo));
$afterUndo = array_values(array_filter(
    ActivityService::entries($site, 'admin', 'all'),
    static fn (array $e): bool => $e['type'] === 'form' && $e['entity_id'] === $formId
));
checkV2F1('db_undo_is_itself_listed', ($afterUndo[0]['actor'] ?? '') === 'restore', json_encode(array_column($afterUndo, 'actor')));
checkV2F1('db_undo_wrong_version_fails', !ActivityService::undo($site, 'admin', $me, 'form', $formId, 999999999)['ok']);
checkV2F1('db_undo_page_wrong_site_fails', !ActivityService::undo($site, 'admin', $me, 'page', 999999999, 1)['ok']);

// Limpieza: versiones y formulario de prueba.
Database::execute("DELETE FROM versions WHERE entity_type = 'page_section' AND entity_id = ?", [$formId]);
Database::execute('DELETE FROM page_sections WHERE id = ?', [$formId]);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
