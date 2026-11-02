<?php

declare(strict_types=1);

// ASST-V2 F6d — la conversación del chat del Studio se guarda en
// assistant_conversations (una por página) y sobrevive a recargar. Sin IA:
// se escriben turnos de prueba (sitio 1; se borran al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AssistantConversationStore as Store;
use Core\Database;

$failed = 0;
function checkV2F6d(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$read = static fn (string $path): string => (string) @file_get_contents(PP_ROOT . '/' . $path);
checkV2F6d('migration_and_schema', is_file(PP_ROOT . '/database/migrations/2026_11_02_assistant_conversation_page.php')
    && str_contains($read('install/schema.sql'), 'page_id INT UNSIGNED NULL,'));
checkV2F6d('chat_endpoint_saves_turns', str_contains($read('app/Controllers/Admin/CanvasController.php'), 'AssistantConversationStore::appendStudioTurn('));
checkV2F6d('studio_restores_thread', str_contains($read('views/admin/canvas/studio.php'), 'studio-thread')
    && str_contains($read('admin/assets/js/canvas-studio.js'), "getElementById('studio-thread')"));

$site = 1;
$user = (int) (Database::selectOne('SELECT id FROM users ORDER BY id LIMIT 1')['id'] ?? 0) ?: null;
$page = Database::selectOne("SELECT id, title FROM pages WHERE site_id = ? AND slug NOT LIKE '\\_\\_%' ORDER BY id LIMIT 1", [$site]);
$pageId = (int) ($page['id'] ?? 0);
$ids = [];
try {
    // Una conversación del asistente, para ver que no se mezclan.
    $assistantId = Store::save($site, $user, null, ['state' => [], 'log' => [['type' => 'user', 'text' => 'Prueba F6d asistente']]]);
    $ids[] = (int) $assistantId;

    $first = Store::appendStudioTurn($site, $user, $pageId, 'Pon el título en azul', 'He puesto el título en azul.', 'Portada');
    $ids[] = (int) $first;
    $second = Store::appendStudioTurn($site, $user, $pageId, 'Y más grande', 'Listo, más grande.', '');
    checkV2F6d('same_thread_per_page', $first !== null && $first === $second, json_encode([$first, $second]));

    $thread = Store::studioThread($site, $user, $pageId);
    checkV2F6d('thread_found', $thread !== null && (int) $thread['id'] === $first);
    $log = $thread['log'] ?? [];
    checkV2F6d('log_has_both_turns', count($log) === 4 && $log[0] === ['type' => 'user', 'text' => 'Pon el título en azul', 'scope' => 'Portada']
        && $log[3] === ['type' => 'assistant', 'text' => 'Listo, más grande.'], json_encode($log, JSON_UNESCAPED_UNICODE));
    $history = $thread['history'] ?? [];
    checkV2F6d('history_for_the_ai', $history === [
        ['q' => 'Pon el título en azul', 'a' => 'He puesto el título en azul.', 'scope' => 'Portada'],
        ['q' => 'Y más grande', 'a' => 'Listo, más grande.', 'scope' => ''],
    ], json_encode($history, JSON_UNESCAPED_UNICODE));
    checkV2F6d('title_is_first_request', ($thread['title'] ?? '') === 'Pon el título en azul');

    // El asistente no reabre ni sobrescribe la conversación del Studio.
    $latest = Store::latest($site, $user);
    checkV2F6d('assistant_latest_skips_studio', $latest !== null && (int) $latest['id'] === (int) $assistantId, json_encode($latest['id'] ?? null));
    checkV2F6d('assistant_cannot_open_studio', Store::find($site, $user, (int) $first) === null);
    checkV2F6d('assistant_cannot_overwrite_studio', Store::save($site, $user, (int) $first, ['state' => [], 'log' => []]) === null);
    $recent = array_column(Store::recent($site, $user), null, 'id');
    checkV2F6d('recent_lists_studio_with_page', isset($recent[$first]) && $recent[$first]['page_id'] === $pageId
        && $recent[$first]['page_title'] === (string) $page['title'] && $recent[$assistantId]['page_id'] === 0, json_encode($recent[$first] ?? null, JSON_UNESCAPED_UNICODE));

    // Otra persona no ve esta conversación; empezar de cero abre otra.
    checkV2F6d('other_user_sees_nothing', Store::studioThread($site, $user === null ? 999999 : null, $pageId) === null);
    $fresh = Store::appendStudioTurn($site, $user, $pageId, 'Empezamos otra vez', 'Vale.', '', true);
    $ids[] = (int) $fresh;
    checkV2F6d('fresh_starts_new_thread', $fresh !== null && $fresh !== $first && (int) (Store::studioThread($site, $user, $pageId)['id'] ?? 0) === $fresh);

    // Límites: textos largos recortados, memoria de 8 turnos y 80 entradas.
    for ($i = 0; $i < 45; $i++) Store::appendStudioTurn($site, $user, $pageId, 'Cambio ' . $i . ' ' . str_repeat('x', 5000), 'Hecho ' . $i, '');
    $long = Store::studioThread($site, $user, $pageId);
    checkV2F6d('log_capped', count($long['log']) === 80 && mb_strlen($long['log'][79]['text']) <= 1200 + 20 && end($long['log'])['text'] === 'Hecho 44',
        (string) count($long['log']));
    checkV2F6d('history_capped', count($long['history']) === 8 && str_starts_with($long['history'][7]['q'], 'Cambio 44'));
    checkV2F6d('rejects_empty', Store::appendStudioTurn($site, $user, $pageId, '   ', 'x', '') === null && Store::appendStudioTurn($site, $user, 0, 'x', 'y', '') === null);
} finally {
    foreach (array_filter($ids) as $id) Database::execute('DELETE FROM assistant_conversations WHERE id = ?', [$id]);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
