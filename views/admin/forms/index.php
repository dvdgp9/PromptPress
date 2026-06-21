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

    <form class="pp-inbox-filters" method="GET" action="<?= e(base_url('admin/forms')) ?>">
        <label class="pp-inbox-search">
            <span>Buscar</span>
            <input type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Nombre, email, teléfono o contenido">
        </label>
        <label><span>Estado</span><select name="status">
            <option value="">Todos</option>
            <option value="unread" <?= ($filters['status'] ?? '') === 'unread' ? 'selected' : '' ?>>Nuevos</option>
            <option value="read" <?= ($filters['status'] ?? '') === 'read' ? 'selected' : '' ?>>Leídos</option>
        </select></label>
        <label><span>Formulario</span><select name="form_id">
            <option value="0">Todos</option>
            <?php foreach ($forms as $form): ?><option value="<?= (int) $form['id'] ?>" <?= (int) ($filters['form_id'] ?? 0) === (int) $form['id'] ? 'selected' : '' ?>><?= e((string) $form['heading']) ?></option><?php endforeach; ?>
        </select></label>
        <label><span>Página de origen</span><select name="page_id">
            <option value="0">Todas</option>
            <?php foreach ($originPages as $origin): ?><option value="<?= (int) $origin['id'] ?>" <?= (int) ($filters['page_id'] ?? 0) === (int) $origin['id'] ? 'selected' : '' ?>><?= e((string) $origin['title']) ?></option><?php endforeach; ?>
        </select></label>
        <label><span>Aviso al administrador</span><select name="email_status">
            <option value="">Todos</option>
            <option value="sent" <?= ($filters['email_status'] ?? '') === 'sent' ? 'selected' : '' ?>>Enviado</option>
            <option value="failed" <?= ($filters['email_status'] ?? '') === 'failed' ? 'selected' : '' ?>>Con error</option>
            <option value="skipped" <?= ($filters['email_status'] ?? '') === 'skipped' ? 'selected' : '' ?>>No configurado</option>
        </select></label>
        <label><span>Respuesta al visitante</span><select name="autoresponder_status">
            <option value="">Todas</option>
            <option value="sent" <?= ($filters['autoresponder_status'] ?? '') === 'sent' ? 'selected' : '' ?>>Enviada</option>
            <option value="failed" <?= ($filters['autoresponder_status'] ?? '') === 'failed' ? 'selected' : '' ?>>Con error</option>
            <option value="skipped" <?= ($filters['autoresponder_status'] ?? '') === 'skipped' ? 'selected' : '' ?>>No enviada</option>
            <option value="disabled" <?= ($filters['autoresponder_status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Desactivada</option>
            <option value="unknown" <?= ($filters['autoresponder_status'] ?? '') === 'unknown' ? 'selected' : '' ?>>Sin datos históricos</option>
        </select></label>
        <label><span>Desde</span><input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>"></label>
        <label><span>Hasta</span><input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>"></label>
        <div class="pp-inbox-filters__actions">
            <?php if ($hasFilters): ?><a href="<?= e(base_url('admin/forms')) ?>">Limpiar</a><?php endif; ?>
            <button class="pp-btn pp-btn--primary pp-btn--sm" type="submit">Aplicar filtros</button>
        </div>
    </form>

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
