<?php

declare(strict_types=1);

// ASST-V2 F4e — el asistente cambia colores, tipografías y radios del diseño
// global. Sin IA: preparación (validación y fuentes), diff, guardado por el
// mismo camino que /admin/design, cambio entre medias y deshacer desde
// Actividad (sitio 1; los dos ajustes se restauran al terminar).

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ActivityService;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\BrandPaletteService;
use App\Services\DesignAssistantEditor;
use App\Services\DesignSystem;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4e(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Registro y permisos --------------------------------------------------------
$catalog = AssistantCapabilityRegistry::catalogForState();
$byId = AssistantCapabilityRegistry::byId($catalog);
checkV2F4e('design_edit_is_automatic', ($byId['design.edit']['mode'] ?? '') === 'automatic' && ($byId['design.edit']['handler'] ?? '') === 'design_edit');
checkV2F4e('design_edit_live_and_undoable', ($byId['design.edit']['risk'] ?? '') === 'live' && ($byId['design.edit']['undo'] ?? false) === true);
checkV2F4e('brand_assets_stay_manual', ($byId['design.manage']['mode'] ?? '') === 'manual');
checkV2F4e('appearance_role_runs_design', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_EDITOR, 'design.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'design.edit'));

$normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
$items = $normalizer->invoke(null, [[
    'capability_id' => 'design.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
    'page_id' => 7, 'instruction' => 'Pon el color principal verde',
]], [7 => ['id' => 7, 'title' => 'X', 'slug' => 'x', 'editable' => true, 'sections' => []]], $catalog, null, []);
checkV2F4e('design_item_is_site_wide', ($items[0]['category'] ?? '') === 'automatable_now' && ($items[0]['page_id'] ?? -1) === 0, json_encode($items[0] ?? null, JSON_UNESCAPED_UNICODE));

// --- Diff puro ----------------------------------------------------------------------
$a = ['colors' => ['primary' => '#112233', 'bg' => '#ffffff'], 'typography' => ['font_heading' => 'Inter', 'font_body' => 'Inter', 'base_size' => 16],
    'buttons' => ['radius' => 8], 'spacing' => ['radius_card' => 12]];
$b = $a;
$b['colors']['primary'] = '#0a7d5a';
$b['typography']['font_heading'] = 'Lora';
$b['buttons']['radius'] = 24;
$diff = DesignAssistantEditor::diff($a, $b);
$byKey = array_column($diff, null, 'key');
checkV2F4e('diff_colour_has_both_hex', ($byKey['colors.primary']['from'] ?? '') === '#112233' && ($byKey['colors.primary']['to'] ?? '') === '#0a7d5a'
    && ($byKey['colors.primary']['label'] ?? '') === __('ds.colors.primary.label'), json_encode($diff, JSON_UNESCAPED_UNICODE));
checkV2F4e('diff_font_named', str_contains((string) ($byKey['typography.font_heading']['detail'] ?? ''), 'Lora'));
checkV2F4e('diff_radius_with_unit', str_contains((string) ($byKey['buttons.radius']['detail'] ?? ''), '24 px'));
checkV2F4e('diff_empty_when_equal', DesignAssistantEditor::diff($a, $a) === []);

// --- Con BD -------------------------------------------------------------------------
$site = 1;
$keys = [BrandPaletteService::SETTING_KEY, DesignSystem::MANUAL_TOKENS_KEY];
$readRaw = static function () use ($site, $keys): array {
    $out = [];
    foreach ($keys as $k) {
        $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?', [$site, $k]);
        $out[$k] = $row !== null ? (string) $row['setting_value'] : null;
    }
    return $out;
};
$writeRaw = static function (array $raw) use ($site): void {
    foreach ($raw as $k => $v) {
        if ($v === null) {
            Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$site, $k]);
        } else {
            Database::execute('INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$site, $k, $v]);
        }
    }
};
$backup = $readRaw();
$maxVersion = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS id FROM versions')['id'] ?? 0);
$jobIds = [];
try {
    $current = DesignAssistantEditor::current($site);
    checkV2F4e('current_has_editable_subset', count($current['colors']) === 8 && isset($current['typography']['font_heading'], $current['buttons']['radius'], $current['spacing']['radius_card']),
        json_encode($current, JSON_UNESCAPED_UNICODE));

    $newPrimary = $current['colors']['primary'] === '#0a7d5a' ? '#1d4ed8' : '#0a7d5a';
    $newFont = $current['typography']['font_heading'] === 'Lora' ? 'Merriweather' : 'Lora';
    $prepared = DesignAssistantEditor::prepare($site, [
        'colors'     => ['primary' => strtoupper($newPrimary), 'text' => 'azul', 'shadow' => '#000000'],
        'typography' => ['font_heading' => $newFont, 'font_body' => 'Comic Sans'],
        'buttons'    => ['radius' => 99],
    ]);
    checkV2F4e('prepare_keeps_valid_changes', $prepared['ok'] === true && $prepared['content']['colors']['primary'] === $newPrimary
        && $prepared['content']['typography']['font_heading'] === $newFont, json_encode($prepared, JSON_UNESCAPED_UNICODE));
    checkV2F4e('prepare_drops_invalid_values', $prepared['content']['colors']['text'] === $current['colors']['text']
        && $prepared['content']['typography']['font_body'] === $current['typography']['font_body']
        && (int) $prepared['content']['buttons']['radius'] === (int) $current['buttons']['radius']
        && !isset($prepared['content']['colors']['shadow']));
    checkV2F4e('prepare_explains_ignored', count($prepared['notes'] ?? []) >= 3, json_encode($prepared['notes'] ?? null, JSON_UNESCAPED_UNICODE));

    $nothing = DesignAssistantEditor::prepare($site, ['colors' => ['primary' => $current['colors']['primary']]]);
    checkV2F4e('no_change_is_an_error', $nothing['ok'] === false && $nothing['error'] === __('asst.err.design_no_valid_change'));

    $preview = DesignAssistantEditor::preview($site, $prepared['before'], $prepared['content'], $prepared['notes']);
    checkV2F4e('preview_has_mock_data', $preview['kind'] === 'design' && count($preview['changes']) === 2
        && isset($preview['fonts'][$newFont]) && str_contains((string) $preview['font_href'], str_replace(' ', '+', $newFont)),
        json_encode($preview, JSON_UNESCAPED_UNICODE));

    $instruction = 'Color principal verde y titulares con más carácter';
    $token = AssistantProposal::seal('design', $site, $site, $instruction, $prepared['content'], $prepared['base_hash']);
    $created = SiteAssistantJobs::createJob($site, 'Prueba F4e', 'Diseño', [[
        'capability_id' => 'design.edit', 'category' => 'automatable_now', 'status' => 'aplicar',
        'page_id' => 0, 'section' => '', 'instruction' => $instruction, 'preview_token' => $token,
    ]], null);
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    $step = SiteAssistantJobs::stepJob($jobId, $site);
    $done = $step['job']['items'][0] ?? [];
    checkV2F4e('step_saves_previewed_design', ($done['status'] ?? '') === 'done' && ($done['capability_id'] ?? '') === 'design.edit', json_encode($done, JSON_UNESCAPED_UNICODE));
    $effective = DesignSystem::effective($site);
    checkV2F4e('site_paints_new_design', $effective['colors']['primary'] === $newPrimary && $effective['typography']['font_heading'] === $newFont,
        json_encode([$effective['colors']['primary'], $effective['typography']['font_heading']]));
    checkV2F4e('colours_go_to_custom_palette', (BrandPaletteService::forSite($site)['accent'] ?? '') === $newPrimary);
    checkV2F4e('font_goes_to_manual_override', (DesignSystem::loadManualTokens($site)['typography']['font_heading'] ?? '') === $newFont);
    checkV2F4e('other_colours_untouched', $effective['colors']['text'] === $current['colors']['text'] && $effective['colors']['bg'] === $current['colors']['bg']);

    $publish = SiteAssistantJobs::publishJob($jobId, $site, 1);
    checkV2F4e('publish_ignores_design', ($publish['pages'] ?? null) === []);
    $stale = DesignAssistantEditor::commit($site, $prepared['content'], $prepared['base_hash'], null);
    checkV2F4e('stale_design_proposal_not_saved', $stale['ok'] === false && $stale['error'] === __('asst.err.design_changed_since_preview'));

    $entries = array_values(array_filter(ActivityService::entries($site, Permissions::ROLE_EDITOR, 'assistant'), static fn (array $e): bool => $e['type'] === 'design'));
    checkV2F4e('activity_lists_design', $entries !== [] && $entries[0]['actor'] === 'assistant');
    checkV2F4e('redactor_does_not_see_design', array_filter(ActivityService::entries($site, Permissions::ROLE_REDACTOR, 'all'), static fn (array $e): bool => $e['type'] === 'design') === []);
    $denied = ActivityService::undo($site, Permissions::ROLE_REDACTOR, null, 'design', $site, (int) ($entries[0]['version_id'] ?? 0));
    checkV2F4e('redactor_cannot_undo_design', $denied['ok'] === false);

    $undo = ActivityService::undo($site, Permissions::ROLE_EDITOR, null, 'design', $site, (int) ($entries[0]['version_id'] ?? 0));
    checkV2F4e('undo_restores_both_settings', $undo['ok'] === true && $readRaw() === $backup, json_encode([$undo, $readRaw(), $backup]));
    checkV2F4e('undo_paints_old_design', DesignSystem::effective($site)['colors']['primary'] === $current['colors']['primary']);
} finally {
    $writeRaw($backup);
    Database::execute('DELETE FROM versions WHERE id > ? AND entity_type = ? AND entity_id = ?', [$maxVersion, DesignAssistantEditor::ENTITY, $site]);
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    \App\Services\CacheService::flush($site);
}
checkV2F4e('original_settings_restored', $readRaw() === $backup);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
