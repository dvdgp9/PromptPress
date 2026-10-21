<?php

declare(strict_types=1);

/**
 * ASST-CONV AC8 — «Publicar los cambios» desde el informe del Asistente.
 * Solo se publica lo que el asistente dejó y nada más: ni páginas que siguen
 * en borrador, ni las que otra persona está editando, ni las que cambiaron
 * después del asistente.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\SiteAssistantJobs;

$failed = 0;
function checkPublish(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

$jobAt = '2026-10-21 10:00:00';
$published = ['status' => 'published'];
$free = ['held' => false, 'user_id' => null, 'username' => null];
$fromAssistant = ['current_version_id' => 20, 'published_version_id' => 10, 'origin' => 'assistant', 'created_at' => '2026-10-21 10:00:30'];

$d = SiteAssistantJobs::publishDecision($published, $fromAssistant, $free, 1, $jobAt);
checkPublish('published_page_with_assistant_change_is_published', $d['publish'] === true && $d['reason'] === '', json_encode($d));

$d = SiteAssistantJobs::publishDecision(['status' => 'draft'], $fromAssistant, $free, 1, $jobAt);
checkPublish('draft_page_is_left_for_the_editor', $d['publish'] === false && $d['reason'] === 'draft', json_encode($d));

$d = SiteAssistantJobs::publishDecision($published, $fromAssistant, ['held' => true, 'user_id' => 7, 'username' => 'lucia'], 1, $jobAt);
checkPublish('page_locked_by_someone_else_is_skipped', $d['publish'] === false && $d['reason'] === 'locked' && $d['username'] === 'lucia', json_encode($d));

$d = SiteAssistantJobs::publishDecision($published, $fromAssistant, ['held' => true, 'user_id' => 1, 'username' => 'admin'], 1, $jobAt);
checkPublish('own_lock_does_not_block', $d['publish'] === true, json_encode($d));

$manual = array_merge($fromAssistant, ['origin' => 'inline']);
$d = SiteAssistantJobs::publishDecision($published, $manual, $free, 1, $jobAt);
checkPublish('page_edited_after_the_assistant_is_skipped', $d['publish'] === false && $d['reason'] === 'changed', json_encode($d));

$older = array_merge($fromAssistant, ['created_at' => '2026-10-21 09:59:00']);
$d = SiteAssistantJobs::publishDecision($published, $older, $free, 1, $jobAt);
checkPublish('assistant_change_from_an_older_job_is_skipped', $d['publish'] === false && $d['reason'] === 'changed', json_encode($d));

$live = array_merge($fromAssistant, ['published_version_id' => 20]);
$d = SiteAssistantJobs::publishDecision($published, $live, $free, 1, $jobAt);
checkPublish('already_live_is_reported', $d['publish'] === false && $d['reason'] === 'already', json_encode($d));

$d = SiteAssistantJobs::publishDecision($published, null, $free, 1, $jobAt);
checkPublish('page_without_canvas_is_skipped', $d['publish'] === false && $d['reason'] === 'changed', json_encode($d));

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
