<?php

declare(strict_types=1);

/**
 * ASST-CONV AC5 — el asistente habla: responde con un resumen, dice qué ha
 * entendido de las referencias y distingue imágenes de referencia (la web que
 * le gusta al cliente) de fotos para publicar. El rol viaja hasta quien aplica.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\AI\Actions;
use App\Services\AssistantSourceEnvelope;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkDesignRef(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

$bundle = [
    'status' => 'ready',
    'blocks' => [['id' => 'B1', 'type' => 'paragraph', 'text' => 'Mira esta web']],
    'media' => [
        ['ref' => 'IMG-1', 'media_id' => 41, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'Captura'],
        ['ref' => 'IMG-2', 'media_id' => 42, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'Foto equipo'],
        ['ref' => 'IMG-3', 'media_id' => 43, 'status' => 'stored', 'source_kind' => 'media', 'alt' => 'Sin rol'],
    ],
];

// --- Normalización de la parte conversacional del plan -------------------
$conv = SiteAssistantPlanner::normalizeConversation([
    'reply' => '  He revisado las capturas. Te propongo rehacer la portada.  ',
    'understanding' => ['Portada a pantalla completa', '', 'Tres columnas de servicios', 'Portada a pantalla completa', 'a', 'b', 'c', 'd', 'e'],
    'media_roles' => [
        ['media_id' => 41, 'role' => 'reference'],
        ['media_id' => 42, 'role' => 'asset'],
        ['media_id' => 999, 'role' => 'asset'],
        ['media_id' => 41, 'role' => 'asset'],
        'basura',
    ],
], $bundle);
checkDesignRef('reply_is_trimmed', $conv['reply'] === 'He revisado las capturas. Te propongo rehacer la portada.', $conv['reply']);
checkDesignRef('understanding_is_deduplicated_and_capped', $conv['understanding'] === ['Portada a pantalla completa', 'Tres columnas de servicios', 'a', 'b', 'c', 'd'], json_encode($conv['understanding'], JSON_UNESCAPED_UNICODE));
checkDesignRef('roles_only_for_stored_media_first_wins', $conv['media_roles'] === [41 => 'reference', 42 => 'asset', 43 => 'reference'], json_encode($conv['media_roles']));

$long = SiteAssistantPlanner::normalizeConversation(['reply' => str_repeat('x', 3000)], $bundle);
checkDesignRef('reply_is_capped', mb_strlen($long['reply']) === 1500, (string) mb_strlen($long['reply']));
checkDesignRef('missing_fields_are_empty', $long['understanding'] === [] && $long['media_roles'] === [41 => 'reference', 42 => 'reference', 43 => 'reference']);

$withRoles = SiteAssistantPlanner::applyMediaRoles($bundle, $conv['media_roles']);
checkDesignRef('roles_are_written_into_the_bundle', array_column($withRoles['media'], 'role') === ['reference', 'asset', 'reference'], json_encode($withRoles['media']));

// --- El rol sobrevive al token firmado -------------------------------------
$fixture = Database::selectOne(
    "SELECT p.id AS page_id, p.site_id, m.id AS media_id, m.path
     FROM pages p JOIN media m ON m.site_id = p.site_id
     WHERE p.render_mode = 'canvas' ORDER BY p.id, m.id LIMIT 1"
);
if ($fixture) {
    $siteId = (int) $fixture['site_id'];
    $mediaId = (int) $fixture['media_id'];
    $realBundle = [
        'status' => 'ready',
        'blocks' => [['id' => 'B1', 'type' => 'image', 'text' => 'Captura', 'media_ref' => 'IMG-1']],
        'media' => [[
            'ref' => 'IMG-1', 'media_id' => $mediaId, 'status' => 'stored', 'source_kind' => 'media',
            'alt' => 'Captura de la web que le gusta', 'role' => 'reference',
        ]],
    ];
    $item = [
        'status' => 'aplicar', 'category' => 'automatable_now', 'capability_id' => 'pages.canvas.edit',
        'page_id' => (int) $fixture['page_id'], 'section' => 'hero',
        'instruction' => 'Rehaz la portada como en la referencia.', 'source_block_ids' => [], 'media_ids' => [$mediaId],
    ];
    $opened = AssistantSourceEnvelope::open(AssistantSourceEnvelope::issue($siteId, $realBundle, [$item]), $siteId);
    checkDesignRef('role_survives_signed_token', ($opened['bundle']['media'][0]['role'] ?? '') === 'reference', json_encode($opened['bundle']['media']));

    $forged = $realBundle;
    $forged['media'][0]['role'] = 'publish-me';
    $sanitized = AssistantSourceEnvelope::sanitizeBundle($forged);
    checkDesignRef('unknown_role_is_dropped', !isset($sanitized['media'][0]['role']), json_encode($sanitized['media']));

    $job = ['source_bundle_json' => json_encode($opened['bundle'])];
    $jobItem = ['source_block_ids_json' => '[]', 'media_ids_json' => json_encode([$mediaId])];
    $context = SiteAssistantJobs::buildSourceContext($job, $jobItem, $siteId);
    checkDesignRef('reference_is_labelled_as_visual_model', str_contains($context, 'REFERENCIA VISUAL'), $context);
    checkDesignRef('reference_path_is_not_offered_for_insertion', !str_contains($context, ltrim((string) $fixture['path'], '/')), $context);

    $assetJob = $opened['bundle'];
    $assetJob['media'][0]['role'] = 'asset';
    $assetContext = SiteAssistantJobs::buildSourceContext(['source_bundle_json' => json_encode($assetJob)], $jobItem, $siteId);
    checkDesignRef('asset_keeps_its_path_to_be_inserted', str_contains($assetContext, ltrim((string) $fixture['path'], '/')) && !str_contains($assetContext, 'REFERENCIA VISUAL'), $assetContext);
} else {
    echo "SKIP token/context checks: no canvas page with media\n";
}

// --- Contrato del prompt ---------------------------------------------------
$instruction = (string) (Actions::get(Actions::PLAN_SITE_CHANGES)['instruction'] ?? '');
checkDesignRef('prompt_asks_for_reply_and_understanding', str_contains($instruction, '"reply"') && str_contains($instruction, '"understanding"') && str_contains($instruction, '"media_roles"'));
checkDesignRef('prompt_keeps_brand_over_reference', str_contains($instruction, 'REFERENCIAS DE DISEÑO') && str_contains($instruction, 'colores y tipografías de la marca'));

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
