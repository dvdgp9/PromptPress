<?php
/**
 * @var array $submissions
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$fmtDate = function ($d) {
    $ts = strtotime((string) $d);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
};
$formatValue = function (mixed $value): string {
    if (is_array($value) && ($value['type'] ?? '') === 'file') {
        return (string) ($value['original_name'] ?? 'Archivo adjunto');
    }
    if (is_array($value)) {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $value;
};
$formatBytes = function (int $bytes): string {
    return \App\Services\FormSubmissionService::formatBytes($bytes);
};
?>

<?php \Core\View::start('title'); ?>Mensajes<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Mensajes recibidos</h2>
        <p class="pp-page-intro">Leads enviados desde los formularios públicos creados en las páginas.</p>
    </div>
</div>

<?php if (empty($submissions)): ?>
<div class="pp-empty">
    <div class="pp-empty__title">Aún no hay mensajes</div>
    <div class="pp-empty__text">Cuando una página publicada reciba un formulario, aparecerá aquí.</div>
</div>
<?php else: ?>
<div class="pp-submissions-list">
    <?php foreach ($submissions as $s): ?>
        <?php
        $payload = json_decode((string) ($s['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $sender = (string) ($s['sender_name'] ?: $s['sender_email'] ?: $s['sender_phone'] ?: 'Mensaje sin nombre');
        $previewParts = array_values(array_filter(array_map($formatValue, array_slice($payload, 0, 3))));
        $preview = trim(implode(' · ', $previewParts));
        if (mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137) . '...';
        }
        ?>
        <details class="pp-submission-row <?= $s['status'] === 'unread' ? 'is-unread' : '' ?>">
            <summary class="pp-submission-row__summary">
                <div class="pp-submission-row__main">
                    <span class="pp-submission-row__date"><?= e($fmtDate($s['created_at'])) ?></span>
                    <strong><?= e($sender) ?></strong>
                    <span><?= e($preview ?: (string) ($s['section_heading'] ?: 'Formulario')) ?></span>
                </div>
                <div class="pp-submission-row__page">
                    <a href="<?= e(base_url('admin/pages/' . (int) $s['page_id'] . '/edit')) ?>"><?= e((string) $s['page_title']) ?></a>
                    <code>/<?= e((string) $s['slug']) ?></code>
                </div>
                <div class="pp-submission-row__badges">
                    <span class="pp-badge <?= $s['status'] === 'unread' ? 'pp-badge--warning' : 'pp-badge--muted' ?>">
                        <?= $s['status'] === 'unread' ? 'Nuevo' : 'Leído' ?>
                    </span>
                    <span class="pp-badge <?= $s['email_status'] === 'sent' ? 'pp-badge--success' : ($s['email_status'] === 'failed' ? 'pp-badge--danger' : 'pp-badge--muted') ?>">
                        Email: <?= e((string) $s['email_status']) ?>
                    </span>
                </div>
            </summary>

            <div class="pp-submission-row__body">
                <dl class="pp-submission-fields">
                    <?php foreach ($payload as $label => $value): ?>
                        <div>
                            <dt><?= e((string) $label) ?></dt>
                            <?php if (is_array($value) && ($value['type'] ?? '') === 'file'): ?>
                                <?php $downloadKey = (string) ($value['field_name'] ?? $label); ?>
                                <?php $downloadUrl = base_url('admin/forms/submissions/' . (int) $s['id'] . '/files/' . rawurlencode($downloadKey)); ?>
                                <dd>
                                    <a class="pp-submission-file" href="<?= e($downloadUrl) ?>">
                                        <strong><?= e((string) ($value['original_name'] ?? 'Archivo adjunto')) ?></strong>
                                        <span><?= e((string) ($value['extension'] ?? 'archivo')) ?> · <?= e($formatBytes((int) ($value['size'] ?? 0))) ?></span>
                                    </a>
                                </dd>
                            <?php else: ?>
                                <dd><?= nl2br(e($formatValue($value)), false) ?></dd>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <?php if (!empty($s['email_error'])): ?>
                    <div class="pp-submission-row__note"><?= e((string) $s['email_error']) ?></div>
                <?php endif; ?>

                <div class="pp-submission-row__actions">
                    <?php if ($s['status'] === 'unread'): ?>
                        <form method="POST" action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/read')) ?>">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Marcar como leído</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST"
                          action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/delete')) ?>"
                          onsubmit="return confirm('¿Eliminar este mensaje? Esta acción no se puede deshacer.');">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="pp-btn pp-btn--danger pp-btn--sm">Eliminar</button>
                    </form>
                </div>
            </div>
        </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>
