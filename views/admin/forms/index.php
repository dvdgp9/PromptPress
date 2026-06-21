<?php
/** @var array $submissions */
\Core\View::extend('admin/layout');

$filters = $filters ?? [];
$metrics = $metrics ?? [];
$forms = $forms ?? [];
$originPages = $originPages ?? [];
$fmtDate = static fn($d): string => ($ts = strtotime((string) $d)) ? date('d/m/Y H:i', $ts) : 'Sin fecha';
$formatValue = static function (mixed $value): string {
    if (is_array($value) && ($value['type'] ?? '') === 'file') return (string) ($value['original_name'] ?? 'Archivo adjunto');
    return is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
};
$mailLabel = static function (string $status, bool $visitor): string {
    $labels = $visitor
        ? ['unknown' => 'Sin datos históricos', 'disabled' => 'Desactivada', 'skipped' => 'No enviada', 'sent' => 'Enviada', 'failed' => 'Error al enviar']
        : ['skipped' => 'No configurado', 'sent' => 'Enviado', 'failed' => 'Error al enviar'];
    return $labels[$status] ?? 'Sin datos';
};
$mailTone = static fn(string $status): string => $status === 'sent' ? 'success' : ($status === 'failed' ? 'danger' : 'muted');
$queryUrl = static function (int $targetPage) use ($filters): string {
    $query = array_filter(array_merge($filters, ['page' => $targetPage]), static fn($v) => $v !== '' && $v !== 0);
    return base_url('admin/forms?' . http_build_query($query));
};
$hasFilters = count(array_filter($filters, static fn($v) => $v !== '' && $v !== 0)) > 0;
$urlWith = static function (array $changes) use ($filters): string {
    $query = array_merge($filters, $changes, ['page' => 1]);
    $query = array_filter($query, static fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return base_url('admin/forms' . ($query ? '?' . http_build_query($query) : ''));
};
$effectivePeriod = (string) ($filters['period'] ?? '');
if ($effectivePeriod === '' && (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '')) $effectivePeriod = 'custom';
$advancedCount = 0;
if (($filters['form_id'] ?? 0) > 0) $advancedCount++;
if (($filters['page_id'] ?? 0) > 0) $advancedCount++;
if ($effectivePeriod !== '') $advancedCount++;
if (($filters['delivery'] ?? '') !== '' || ($filters['email_status'] ?? '') !== '' || ($filters['autoresponder_status'] ?? '') !== '') $advancedCount++;
$formNames = array_column($forms, 'heading', 'id');
$pageNames = array_column($originPages, 'title', 'id');
$periodLabels = ['7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días', 'custom' => 'Fechas personalizadas'];
$deliveryLabels = ['issues' => 'Con incidencias', 'sent' => 'Todo enviado', 'autoresponder_off' => 'Autorrespuesta desactivada'];
$chips = [];
if (($filters['q'] ?? '') !== '') $chips[] = ['label' => 'Búsqueda: ' . $filters['q'], 'remove' => ['q' => '']];
if (($filters['form_id'] ?? 0) > 0) $chips[] = ['label' => 'Formulario: ' . ($formNames[(int) $filters['form_id']] ?? '#' . (int) $filters['form_id']), 'remove' => ['form_id' => 0]];
if (($filters['page_id'] ?? 0) > 0) $chips[] = ['label' => 'Página: ' . ($pageNames[(int) $filters['page_id']] ?? '#' . (int) $filters['page_id']), 'remove' => ['page_id' => 0]];
if (($filters['period'] ?? '') !== '') $chips[] = ['label' => $periodLabels[$filters['period']] ?? 'Periodo', 'remove' => ['period' => '', 'date_from' => '', 'date_to' => '']];
elseif (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') $chips[] = ['label' => 'Fechas personalizadas', 'remove' => ['date_from' => '', 'date_to' => '']];
if (($filters['delivery'] ?? '') !== '') $chips[] = ['label' => 'Entrega: ' . ($deliveryLabels[$filters['delivery']] ?? 'Filtrada'), 'remove' => ['delivery' => '']];
if (($filters['email_status'] ?? '') !== '') $chips[] = ['label' => 'Aviso: ' . $mailLabel($filters['email_status'], false), 'remove' => ['email_status' => '']];
if (($filters['autoresponder_status'] ?? '') !== '') $chips[] = ['label' => 'Respuesta: ' . $mailLabel($filters['autoresponder_status'], true), 'remove' => ['autoresponder_status' => '']];
?>

<?php \Core\View::start('title'); ?>Mensajes<?php \Core\View::end(); ?>

<section class="pp-inbox">
    <header class="pp-inbox__header">
        <div>
            <span class="pp-inbox__eyebrow">Formularios</span>
            <h2>Mensajes recibidos</h2>
            <p>Consulta solicitudes, comprueba su origen y revisa qué correos se enviaron.</p>
        </div>
        <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/formularios')) ?>">Gestionar formularios</a>
    </header>

    <div class="pp-inbox-metrics" aria-label="Resumen de mensajes">
        <div><strong><?= (int) ($metrics['unread'] ?? 0) ?></strong><span>Nuevos</span></div>
        <div><strong><?= (int) ($metrics['recent'] ?? 0) ?></strong><span>Últimos 30 días</span></div>
        <div><strong><?= (int) ($metrics['mail_errors'] ?? 0) ?></strong><span>Errores de correo</span></div>
        <div><strong><?= (int) ($metrics['total'] ?? 0) ?></strong><span>Total histórico</span></div>
    </div>

    <form class="pp-inbox-toolbar" method="GET" action="<?= e(base_url('admin/forms')) ?>">
        <input type="hidden" name="status" value="<?= e((string) ($filters['status'] ?? '')) ?>">
        <label class="pp-inbox-search">
            <span class="pp-visually-hidden">Buscar mensajes</span>
            <input type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Nombre, email, teléfono o contenido">
        </label>
        <button class="pp-inbox-search__submit" type="submit">Buscar</button>
        <?php foreach (['form_id','page_id','period','delivery','date_from','date_to','email_status','autoresponder_status'] as $key): ?>
            <?php if (($filters[$key] ?? '') !== '' && ($filters[$key] ?? 0) !== 0): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e((string) $filters[$key]) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <nav class="pp-inbox-status" aria-label="Estado de lectura">
            <a class="<?= ($filters['status'] ?? '') === '' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => ''])) ?>">Todos</a>
            <a class="<?= ($filters['status'] ?? '') === 'unread' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => 'unread'])) ?>">Nuevos</a>
            <a class="<?= ($filters['status'] ?? '') === 'read' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => 'read'])) ?>">Leídos</a>
        </nav>
        <button class="pp-inbox-filter-toggle <?= $advancedCount > 0 ? 'is-active' : '' ?>" type="button" data-inbox-filter-toggle aria-expanded="<?= $advancedCount > 0 ? 'true' : 'false' ?>" aria-controls="inbox-advanced-filters">
            Filtros<?= $advancedCount > 0 ? ' · ' . $advancedCount : '' ?>
        </button>
    </form>

    <form class="pp-inbox-advanced" id="inbox-advanced-filters" method="GET" action="<?= e(base_url('admin/forms')) ?>" <?= $advancedCount > 0 ? '' : 'hidden' ?> data-inbox-advanced>
        <input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
        <input type="hidden" name="status" value="<?= e((string) ($filters['status'] ?? '')) ?>">
        <label><span>Formulario</span><select name="form_id"><option value="0">Todos los formularios</option><?php foreach ($forms as $form): ?><option value="<?= (int) $form['id'] ?>" <?= (int) ($filters['form_id'] ?? 0) === (int) $form['id'] ? 'selected' : '' ?>><?= e((string) $form['heading']) ?></option><?php endforeach; ?></select></label>
        <label><span>Página de origen</span><select name="page_id"><option value="0">Todas las páginas</option><?php foreach ($originPages as $origin): ?><option value="<?= (int) $origin['id'] ?>" <?= (int) ($filters['page_id'] ?? 0) === (int) $origin['id'] ? 'selected' : '' ?>><?= e((string) $origin['title']) ?></option><?php endforeach; ?></select></label>
        <label><span>Periodo</span><select name="period" data-inbox-period><option value="">Cualquier fecha</option><?php foreach ($periodLabels as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $effectivePeriod === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label><span>Entrega de correos</span><select name="delivery"><option value="">Cualquier estado</option><?php foreach ($deliveryLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($filters['delivery'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <div class="pp-inbox-custom-dates" data-inbox-custom-dates <?= $effectivePeriod === 'custom' ? '' : 'hidden' ?>>
            <label><span>Desde</span><input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>"></label>
            <label><span>Hasta</span><input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>"></label>
        </div>
        <?php if (($filters['email_status'] ?? '') !== ''): ?><input type="hidden" name="email_status" value="<?= e((string) $filters['email_status']) ?>"><?php endif; ?>
        <?php if (($filters['autoresponder_status'] ?? '') !== ''): ?><input type="hidden" name="autoresponder_status" value="<?= e((string) $filters['autoresponder_status']) ?>"><?php endif; ?>
        <div class="pp-inbox-advanced__actions"><button class="pp-btn pp-btn--primary pp-btn--sm" type="submit">Ver resultados</button></div>
    </form>

    <?php if ($chips !== [] || ($filters['status'] ?? '') !== ''): ?>
    <div class="pp-inbox-active" aria-label="Filtros activos">
        <?php if (($filters['status'] ?? '') !== ''): ?><a href="<?= e($urlWith(['status' => ''])) ?>"><?= ($filters['status'] ?? '') === 'unread' ? 'Nuevos' : 'Leídos' ?><span aria-hidden="true">×</span></a><?php endif; ?>
        <?php foreach ($chips as $chip): ?><a href="<?= e($urlWith($chip['remove'])) ?>"><?= e($chip['label']) ?><span aria-hidden="true">×</span></a><?php endforeach; ?>
        <a class="pp-inbox-active__clear" href="<?= e(base_url('admin/forms')) ?>">Limpiar todo</a>
    </div>
    <?php endif; ?>

    <div class="pp-inbox-results">
        <span><?= (int) $total ?> <?= (int) $total === 1 ? 'mensaje' : 'mensajes' ?></span>
        <?php if ($hasFilters): ?><small>con los filtros actuales</small><?php endif; ?>
    </div>

    <?php if (empty($submissions)): ?>
        <div class="pp-inbox-empty">
            <strong><?= $hasFilters ? 'No hay mensajes que coincidan.' : 'Aún no hay mensajes.' ?></strong>
            <span><?= $hasFilters ? 'Prueba a quitar algún filtro o ampliar las fechas.' : 'Cuando alguien complete un formulario publicado, aparecerá aquí.' ?></span>
            <?php if ($hasFilters): ?><a href="<?= e(base_url('admin/forms')) ?>">Ver todos los mensajes</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="pp-submissions-list">
        <?php foreach ($submissions as $s):
            $payload = json_decode((string) ($s['payload'] ?? '{}'), true);
            $payload = is_array($payload) ? $payload : [];
            $sender = (string) ($s['sender_name'] ?: $s['sender_email'] ?: $s['sender_phone'] ?: 'Mensaje sin identificar');
            $preview = trim(implode(' · ', array_values(array_filter(array_map($formatValue, array_slice($payload, 0, 3))))));
            if (mb_strlen($preview) > 120) $preview = mb_substr($preview, 0, 117) . '...';
            $arStatus = (string) ($s['autoresponder_status'] ?? 'unknown');
            $editUrl = ($s['render_mode'] ?? '') === 'canvas'
                ? base_url('admin/canvas/' . (int) $s['page_id'])
                : base_url('admin/pages/' . (int) $s['page_id'] . '/edit');
        ?>
            <details class="pp-submission-row <?= $s['status'] === 'unread' ? 'is-unread' : '' ?>">
                <summary class="pp-submission-row__summary">
                    <span class="pp-submission-row__state" aria-label="<?= $s['status'] === 'unread' ? 'Mensaje nuevo' : 'Mensaje leído' ?>"></span>
                    <div class="pp-submission-row__main">
                        <strong><?= e($sender) ?></strong>
                        <span><?= e($preview ?: (string) ($s['section_heading'] ?: 'Formulario')) ?></span>
                    </div>
                    <div class="pp-submission-row__origin">
                        <span><?= e((string) ($s['section_heading'] ?: 'Formulario')) ?></span>
                        <small><?= e((string) $s['page_title']) ?><?= !empty($s['source_label']) ? ' · ' . e((string) $s['source_label']) : '' ?></small>
                    </div>
                    <time datetime="<?= e((string) $s['created_at']) ?>"><?= e($fmtDate($s['created_at'])) ?></time>
                    <span class="pp-submission-row__chevron" aria-hidden="true"></span>
                </summary>

                <div class="pp-submission-row__body">
                    <header class="pp-submission-detail__header">
                        <div>
                            <span>Mensaje de</span>
                            <h3><?= e($sender) ?></h3>
                            <div class="pp-submission-detail__contacts">
                                <?php if (!empty($s['sender_email'])): ?><a href="mailto:<?= e((string) $s['sender_email']) ?>"><?= e((string) $s['sender_email']) ?></a><?php endif; ?>
                                <?php if (!empty($s['sender_phone'])): ?><a href="tel:<?= e((string) $s['sender_phone']) ?>"><?= e((string) $s['sender_phone']) ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="pp-submission-detail__source">
                            <span>Origen</span>
                            <a href="<?= e($editUrl) ?>"><?= e((string) $s['page_title']) ?></a>
                            <code>/<?= e((string) $s['slug']) ?></code>
                            <?php if (!empty($s['source_label'])): ?><small><?= e((string) $s['source_label']) ?></small><?php endif; ?>
                        </div>
                    </header>

                    <div class="pp-submission-detail__grid">
                        <section>
                            <h4>Datos enviados</h4>
                            <dl class="pp-submission-fields">
                            <?php foreach ($payload as $label => $value): ?>
                                <div>
                                    <dt><?= e((string) $label) ?></dt>
                                    <?php if (is_array($value) && ($value['type'] ?? '') === 'file'):
                                        $downloadKey = (string) ($value['field_name'] ?? $label);
                                        $downloadUrl = base_url('admin/forms/submissions/' . (int) $s['id'] . '/files/' . rawurlencode($downloadKey)); ?>
                                        <dd><a class="pp-submission-file" href="<?= e($downloadUrl) ?>"><strong><?= e((string) ($value['original_name'] ?? 'Archivo adjunto')) ?></strong><span><?= e(\App\Services\FormSubmissionService::formatBytes((int) ($value['size'] ?? 0))) ?></span></a></dd>
                                    <?php else: ?><dd><?= nl2br(e($formatValue($value)), false) ?></dd><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            </dl>
                        </section>

                        <aside class="pp-submission-delivery">
                            <h4>Entrega de correos</h4>
                            <div class="pp-submission-delivery__item is-<?= e($mailTone((string) $s['email_status'])) ?>">
                                <i aria-hidden="true"></i><div><span>Aviso al administrador</span><strong><?= e($mailLabel((string) $s['email_status'], false)) ?></strong><?php if (!empty($s['email_error'])): ?><small><?= e((string) $s['email_error']) ?></small><?php endif; ?></div>
                            </div>
                            <div class="pp-submission-delivery__item is-<?= e($mailTone($arStatus)) ?>">
                                <i aria-hidden="true"></i><div><span>Respuesta al visitante</span><strong><?= e($mailLabel($arStatus, true)) ?></strong><?php if (!empty($s['autoresponder_error'])): ?><small><?= e((string) $s['autoresponder_error']) ?></small><?php endif; ?></div>
                            </div>
                        </aside>
                    </div>

                    <footer class="pp-submission-row__actions">
                        <span>Recibido el <?= e($fmtDate($s['created_at'])) ?></span>
                        <div>
                        <?php if ($s['status'] === 'unread'): ?><form method="POST" action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/read')) ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Marcar como leído</button></form><?php endif; ?>
                        <form method="POST" action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/delete')) ?>" onsubmit="return confirm('¿Eliminar este mensaje? Esta acción no se puede deshacer.');"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button type="submit" class="pp-btn pp-btn--danger pp-btn--sm">Eliminar</button></form>
                        </div>
                    </footer>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pp-pagination" aria-label="Paginación de mensajes">
            <?php if ($page > 1): ?><a href="<?= e($queryUrl($page - 1)) ?>" class="pp-btn pp-btn--secondary">Anterior</a><?php endif; ?>
            <span class="pp-pagination__info">Página <?= (int) $page ?> de <?= (int) $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a href="<?= e($queryUrl($page + 1)) ?>" class="pp-btn pp-btn--secondary">Siguiente</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
