<?php

declare(strict_types=1);

// ASST-V2 F4f — el asistente edita servicios de reserva (con horario),
// productos y recursos, y crea servicios y productos desactivados. Sin IA:
// registro por módulo, permisos, preparación (precio, horario, campos fuera de
// alcance), lo sensible, el trabajo (firma y «lo he revisado»), que se guarda
// sin perder lo que no se toca y el deshacer. Fixtures en el sitio 1 que se
// borran al terminar.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Booking\ServiceStore;
use App\Modules\Commerce\ProductStore;
use App\Modules\Resources\ResourceStore;
use App\Services\ActivityService;
use App\Services\AssistantCapabilityRegistry;
use App\Services\AssistantProposal;
use App\Services\CatalogAssistantEditor;
use App\Services\Permissions;
use App\Services\SiteAssistantJobs;
use App\Services\SiteAssistantPlanner;
use Core\Database;

$failed = 0;
function checkV2F4f(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 900) . PHP_EOL;
    }
}

// --- Registro y permisos --------------------------------------------------------
$on = ['booking' => ['available' => true, 'enabled' => true], 'commerce' => ['available' => true, 'enabled' => true], 'resources' => ['available' => true, 'enabled' => true]];
$byId = AssistantCapabilityRegistry::byId(AssistantCapabilityRegistry::catalogForState($on));
$auto = static fn (string $id): bool => ($byId[$id]['mode'] ?? '') === 'automatic';
checkV2F4f('catalog_executors_with_modules_on', $auto('booking.service.edit') && $auto('booking.service.create') && $auto('commerce.product.edit')
    && $auto('commerce.product.create') && $auto('resources.edit'));
checkV2F4f('edits_live_creates_draft', ($byId['booking.service.edit']['risk'] ?? '') === 'live' && ($byId['booking.service.edit']['undo'] ?? false) === true
    && ($byId['commerce.product.create']['risk'] ?? '') === 'draft' && ($byId['commerce.product.create']['undo'] ?? true) === false);
checkV2F4f('payments_stay_manual', ($byId['commerce.manage']['mode'] ?? '') === 'manual' && ($byId['booking.manage']['mode'] ?? '') === 'manual');
$offById = AssistantCapabilityRegistry::byId(AssistantCapabilityRegistry::catalogForState(['booking' => ['available' => true, 'enabled' => false]]));
checkV2F4f('no_executor_when_module_off', ($offById['booking.service.edit']['mode'] ?? 'manual') !== 'automatic' && !isset($offById['commerce.product.edit']));
checkV2F4f('clients_role_for_bookings_and_shop', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_ADMIN, 'booking.service.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_EDITOR, 'booking.service.edit')
    && !AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_EDITOR, 'commerce.product.create'));
checkV2F4f('content_role_for_resources', AssistantCapabilityRegistry::roleCanExecute(Permissions::ROLE_REDACTOR, 'resources.edit'));

// --- Puros ----------------------------------------------------------------------------
$svcA = ['name' => 'Consulta', 'description' => 'Primera visita', 'price_label' => '45 €', 'duration_min' => 30, 'buffer_min' => 0, 'capacity' => 1,
    'min_notice_hours' => 12, 'max_advance_days' => 60, 'hours' => [[['start' => '09:00', 'end' => '14:00']], [], [], [], [], [], []]];
$svcB = $svcA;
$svcB['hours'][5] = [['start' => '10:00', 'end' => '13:00']];
$svcB['price_label'] = '50 €';
$svcB['description'] = 'Primera visita completa';
$diff = array_column(CatalogAssistantEditor::diff('service', $svcA, $svcB), null, 'key');
checkV2F4f('diff_marks_sensitive_rows', ($diff['price_label']['sensitive'] ?? false) === true && ($diff['hours']['sensitive'] ?? false) === true
    && ($diff['description']['sensitive'] ?? true) === false, json_encode($diff, JSON_UNESCAPED_UNICODE));
checkV2F4f('diff_hours_names_the_day', str_contains((string) ($diff['hours']['detail'] ?? ''), mb_strtolower(__('bk.day.sat'))), json_encode($diff['hours'] ?? null, JSON_UNESCAPED_UNICODE));

// --- Con BD ---------------------------------------------------------------------------
$site = 1;
$maxVersion = (int) (Database::selectOne('SELECT COALESCE(MAX(id), 0) AS id FROM versions')['id'] ?? 0);
$jobIds = [];
$serviceIds = [];
$productIds = [];
$resourceIds = [];
try {
    $serviceId = ServiceStore::create($site, ['name' => 'Prueba F4f · Consulta', 'description' => 'Primera visita', 'price_label' => '45 €', 'duration_min' => 30, 'active' => 1]);
    $serviceIds[] = $serviceId;
    $fieldsJson = '[{"name":"nombre","label":"Nombre","type":"text","required":true}]';
    Database::execute('UPDATE booking_services SET fields_json = ? WHERE id = ?', [$fieldsJson, $serviceId]);
    ServiceStore::update($site, $serviceId, array_merge(ServiceStore::find($site, $serviceId), []),
        [0 => [['start' => '09:00', 'end' => '14:00']], 2 => [['start' => '16:00', 'end' => '20:00']]],
        [['date' => '2030-12-24', 'closed' => true, 'start' => null, 'end' => null]]);
    $productId = ProductStore::create($site, ['name' => 'Prueba F4f · Crema', 'description' => 'Hidratante', 'price_cents' => 2500, 'tax_rate' => 10, 'stock' => 8, 'active' => 1]);
    $productIds[] = $productId;
    $resourceId = ResourceStore::create($site, ['title' => 'Prueba F4f · Guía', 'description' => 'Guía breve', 'status' => 'draft']);
    $resourceIds[] = $resourceId;

    $catalog = CatalogAssistantEditor::siteCatalog($site);
    checkV2F4f('site_catalog_lists_fixtures', isset($catalog['service:' . $serviceId], $catalog['product:' . $productId], $catalog['resource:' . $resourceId]),
        json_encode(array_keys($catalog)));

    $normalizer = new ReflectionMethod(SiteAssistantPlanner::class, 'normalizeItems');
    $planned = $normalizer->invoke(null, [
        ['capability_id' => 'booking.service.edit', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 3, 'target_id' => $serviceId, 'instruction' => 'Abre los sábados de 10 a 13'],
        ['capability_id' => 'commerce.product.edit', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'target_id' => 99999999, 'instruction' => 'Sube el precio'],
        ['capability_id' => 'booking.service.create', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'target_id' => 0, 'instruction' => 'Crea «Revisión anual», 20 min, 30 €'],
    ], [], AssistantCapabilityRegistry::catalogForState($on), null, [], $catalog);
    checkV2F4f('planner_keeps_target', ($planned[0]['target_id'] ?? 0) === $serviceId && ($planned[0]['category'] ?? '') === 'automatable_now'
        && ($planned[0]['page_id'] ?? -1) === 0 && ($planned[0]['target_title'] ?? '') === 'Prueba F4f · Consulta', json_encode($planned[0] ?? null, JSON_UNESCAPED_UNICODE));
    checkV2F4f('planner_rejects_unknown_target', ($planned[1]['category'] ?? '') === 'needs_input');
    checkV2F4f('planner_create_needs_no_target', ($planned[2]['category'] ?? '') === 'automatable_now');

    // Servicio: texto, precio, horario; lo inválido se descarta y se explica.
    $prepared = CatalogAssistantEditor::prepare($site, 'service', $serviceId, [
        'description' => 'Primera visita completa',
        'price_label' => '50 €',
        'hours'       => ['sat' => [['start' => '10:00', 'end' => '13:00']], 'tue' => [['start' => '12:00', 'end' => '11:00']]],
        'capacity'    => 'muchas',
        'auto_confirm' => 1,
    ]);
    checkV2F4f('prepare_service_changes', $prepared['ok'] === true && $prepared['content']['price_label'] === '50 €'
        && $prepared['content']['hours'][5] === [['start' => '10:00', 'end' => '13:00']]
        && $prepared['content']['hours'][0] === [['start' => '09:00', 'end' => '14:00']], json_encode($prepared, JSON_UNESCAPED_UNICODE));
    checkV2F4f('prepare_drops_bad_values', $prepared['content']['hours'][1] === [] && (int) $prepared['content']['capacity'] === 1
        && !isset($prepared['content']['auto_confirm']) && count($prepared['notes']) >= 2, json_encode($prepared['notes'] ?? null, JSON_UNESCAPED_UNICODE));
    checkV2F4f('price_and_hours_are_sensitive', $prepared['sensitive'] === true);
    $textOnly = CatalogAssistantEditor::prepare($site, 'service', $serviceId, ['description' => 'Otra descripción']);
    checkV2F4f('text_change_not_sensitive', $textOnly['ok'] === true && $textOnly['sensitive'] === false);
    $overlap = CatalogAssistantEditor::prepare($site, 'service', $serviceId, ['hours' => ['mon' => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '13:00']]]]);
    checkV2F4f('overlapping_hours_rejected', $overlap['ok'] === false, json_encode($overlap, JSON_UNESCAPED_UNICODE));

    $preview = CatalogAssistantEditor::preview($site, 'service', $prepared['before'], $prepared['content'], $prepared['notes'], $prepared['sensitive']);
    checkV2F4f('preview_is_catalog_card', $preview['kind'] === 'catalog' && $preview['item_kind'] === 'service' && $preview['sensitive'] === true
        && count($preview['weekdays']) === 7, json_encode($preview, JSON_UNESCAPED_UNICODE));

    // Trabajo: sin firma no se aplica; sensible sin «revisado», tampoco.
    $instruction = 'Precio 50 €, descripción completa y abrir los sábados de 10 a 13';
    $token = AssistantProposal::seal('catalog_service', $site, $serviceId, $instruction, $prepared['content'], $prepared['base_hash']);
    $base = ['capability_id' => 'booking.service.edit', 'category' => 'automatable_now', 'status' => 'aplicar', 'page_id' => 0, 'target_id' => $serviceId, 'instruction' => $instruction];
    checkV2F4f('unsigned_catalog_edit_rejected', SiteAssistantJobs::createJob($site, 'Prueba F4f', 'Sin firma', [$base], null)['ok'] === false);
    checkV2F4f('sensitive_needs_review', SiteAssistantJobs::createJob($site, 'Prueba F4f', 'Sin revisar', [$base + ['preview_token' => $token]], null)['ok'] === false);
    $wrongTarget = $base + ['preview_token' => $token, 'reviewed' => true];
    $wrongTarget['target_id'] = $productId;
    $wrongTarget['capability_id'] = 'commerce.product.edit';
    checkV2F4f('token_bound_to_kind_and_target', SiteAssistantJobs::createJob($site, 'Prueba F4f', 'Cruzado', [$wrongTarget], null)['ok'] === false);

    $created = SiteAssistantJobs::createJob($site, 'Prueba F4f', 'Servicio', [$base + ['preview_token' => $token, 'reviewed' => true]], null);
    $jobId = (int) ($created['job']['id'] ?? 0);
    $jobIds[] = $jobId;
    checkV2F4f('reviewed_job_created', $created['ok'] === true, json_encode($created, JSON_UNESCAPED_UNICODE));
    $step = SiteAssistantJobs::stepJob($jobId, $site);
    $done = $step['job']['items'][0] ?? [];
    checkV2F4f('step_saves_service', ($done['status'] ?? '') === 'done' && ($done['capability_id'] ?? '') === 'booking.service.edit'
        && ($done['catalog']['title'] ?? '') === 'Prueba F4f · Consulta', json_encode($done, JSON_UNESCAPED_UNICODE));
    $saved = ServiceStore::find($site, $serviceId);
    checkV2F4f('service_saved', $saved['price_label'] === '50 €' && $saved['description'] === 'Primera visita completa'
        && ($saved['hours'][5] ?? null) === [['start' => '10:00', 'end' => '13:00']]);
    checkV2F4f('service_keeps_untouched', (string) $saved['fields_json'] === $fieldsJson && count($saved['exceptions']) === 1
        && ($saved['hours'][2] ?? null) === [['start' => '16:00', 'end' => '20:00']] && (int) $saved['active'] === 1);
    $stale = CatalogAssistantEditor::commit($site, 'service', $serviceId, $prepared['content'], $prepared['base_hash'], null);
    checkV2F4f('stale_proposal_not_saved', $stale['ok'] === false && $stale['error'] === __('asst.err.catalog_changed_since_preview'));

    // Actividad y deshacer.
    $entries = array_values(array_filter(ActivityService::entries($site, Permissions::ROLE_ADMIN, 'assistant'), static fn (array $e): bool => $e['type'] === 'booking_service'));
    checkV2F4f('activity_lists_service', $entries !== [] && $entries[0]['entity_id'] === $serviceId);
    checkV2F4f('editor_does_not_see_bookings', array_filter(ActivityService::entries($site, Permissions::ROLE_EDITOR, 'all'), static fn (array $e): bool => $e['type'] === 'booking_service') === []);
    $undo = ActivityService::undo($site, Permissions::ROLE_ADMIN, null, 'booking_service', $serviceId, (int) ($entries[0]['version_id'] ?? 0));
    $back = ServiceStore::find($site, $serviceId);
    checkV2F4f('undo_restores_service', $undo['ok'] === true && $back['price_label'] === '45 €' && !isset($back['hours'][5]) && count($back['exceptions']) === 1,
        json_encode([$undo, $back['price_label'], $back['hours']], JSON_UNESCAPED_UNICODE));

    // Producto: precio en euros, stock; imagen e impuestos intactos.
    Database::execute('UPDATE commerce_products SET media_id = NULL, tax_rate = 10 WHERE id = ?', [$productId]);
    $p = CatalogAssistantEditor::prepare($site, 'product', $productId, ['price' => '29,90', 'stock' => 3, 'tax_rate' => 0]);
    checkV2F4f('product_price_in_cents', $p['ok'] === true && $p['content']['price_cents'] === 2990 && $p['content']['stock'] === 3 && $p['sensitive'] === true
        && !isset($p['content']['tax_rate']), json_encode($p, JSON_UNESCAPED_UNICODE));
    $c = CatalogAssistantEditor::commit($site, 'product', $productId, $p['content'], $p['base_hash'], null);
    $prod = ProductStore::find($site, $productId);
    checkV2F4f('product_saved_keeps_tax', $c['ok'] === true && (int) $prod['price_cents'] === 2990 && (float) $prod['tax_rate'] === 10.0 && (int) $prod['active'] === 1);
    $pEntries = array_values(array_filter(ActivityService::entries($site, Permissions::ROLE_ADMIN, 'all'), static fn (array $e): bool => $e['type'] === 'commerce_product' && $e['entity_id'] === $productId));
    $pUndo = ActivityService::undo($site, Permissions::ROLE_ADMIN, null, 'commerce_product', $productId, (int) ($pEntries[0]['version_id'] ?? 0));
    $prodBack = ProductStore::find($site, $productId);
    checkV2F4f('undo_restores_product_price', $pUndo['ok'] === true && (int) $prodBack['price_cents'] === 2500 && (int) $prodBack['stock'] === 8);

    // Recurso: solo textos.
    $r = CatalogAssistantEditor::prepare($site, 'resource', $resourceId, ['title' => 'Prueba F4f · Guía práctica', 'status' => 'published']);
    checkV2F4f('resource_text_only', $r['ok'] === true && $r['sensitive'] === false && !isset($r['content']['status']));
    CatalogAssistantEditor::commit($site, 'resource', $resourceId, $r['content'], $r['base_hash'], null);
    $res = ResourceStore::find($site, $resourceId);
    checkV2F4f('resource_saved_stays_draft', $res['title'] === 'Prueba F4f · Guía práctica' && $res['status'] === 'draft');

    // Crear: nace desactivado.
    $new = CatalogAssistantEditor::prepare($site, 'service', 0, ['name' => 'Prueba F4f · Revisión', 'duration_min' => 20, 'price_label' => '30 €',
        'hours' => ['thu' => [['start' => '09:00', 'end' => '11:00']]]]);
    checkV2F4f('prepare_new_service', $new['ok'] === true && $new['before'] === null && $new['content']['name'] === 'Prueba F4f · Revisión');
    $made = CatalogAssistantEditor::create($site, 'service', $new['content']);
    $serviceIds[] = (int) ($made['entity_id'] ?? 0);
    $newRow = ServiceStore::find($site, (int) ($made['entity_id'] ?? 0));
    checkV2F4f('new_service_inactive', $made['ok'] === true && (int) $newRow['active'] === 0 && ($newRow['hours'][3] ?? null) === [['start' => '09:00', 'end' => '11:00']]);
    $noName = CatalogAssistantEditor::prepare($site, 'product', 0, ['price' => 10]);
    checkV2F4f('new_product_needs_name', $noName['ok'] === false);
    $newProduct = CatalogAssistantEditor::prepare($site, 'product', 0, ['name' => 'Prueba F4f · Sérum', 'price' => 19]);
    $madeP = CatalogAssistantEditor::create($site, 'product', $newProduct['content']);
    $productIds[] = (int) ($madeP['entity_id'] ?? 0);
    $prodRow = ProductStore::find($site, (int) ($madeP['entity_id'] ?? 0));
    checkV2F4f('new_product_inactive', $madeP['ok'] === true && (int) $prodRow['active'] === 0 && (int) $prodRow['price_cents'] === 1900);
} finally {
    foreach ($jobIds as $id) if ($id > 0) Database::execute('DELETE FROM assistant_jobs WHERE id = ?', [$id]);
    foreach ($serviceIds as $id) if ($id > 0) ServiceStore::delete($site, $id);
    foreach ($productIds as $id) if ($id > 0) ProductStore::delete($site, $id);
    foreach ($resourceIds as $id) if ($id > 0) ResourceStore::delete($site, $id);
    Database::execute("DELETE FROM versions WHERE id > ? AND entity_type IN ('booking_service', 'commerce_product', 'resource')", [$maxVersion]);
    \App\Services\CacheService::flush($site);
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
