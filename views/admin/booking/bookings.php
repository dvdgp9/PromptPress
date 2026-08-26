<?php
/**
 * Reservas — gestión de reservas recibidas (FEAT-3 B5).
 *
 * @var array   $bookings     filas de booking_bookings + service_name
 * @var array   $services     servicios del sitio (para el filtro)
 * @var string  $timezone     zona del sitio (las horas se muestran en local)
 * @var array   $filters      {status, service, scope}
 * @var int     $pendingCount pendientes próximas
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');

$statusLabels = ['pending' => __('bk.st.pending'), 'confirmed' => __('bk.st.confirmed'), 'cancelled' => __('bk.st.cancelled')];
$statusPills  = ['pending' => '', 'confirmed' => ' pp-status-pill--green', 'cancelled' => ' pp-status-pill--muted'];
$tz = new DateTimeZone($timezone);
$fmt = static function (string $utc) use ($tz): string {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i');
};
?>

<?php \Core\View::start('title'); ?><?= e(__('bk.received')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('bk.received')) ?></h2>
        <p class="pp-page-header__lead">
            <a href="<?= e(base_url('admin/booking')) ?>">← <?= e(__('bk.services')) ?></a>
            <?php if ($pendingCount > 0): ?>
                · <?= e(__($pendingCount === 1 ? 'bk.pending_one' : 'bk.pending_many', ['n' => (string) (int) $pendingCount])) ?>
            <?php endif; ?>
            · <?= e(__('bk.hours_in', ['zona' => $timezone])) ?>
        </p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<?php /* Confirmar o cancelar sin correo configurado no avisa a nadie: se dice
         aquí, que es donde se pulsa el botón. */ ?>
<?php if (empty($mailReady)): ?>
<div class="pp-alert pp-alert--warning pp-booking-mailwarn" data-pp-persist>
    <div>
        <strong><?= e(__('bk.mail_off.title')) ?></strong>
        <p><?= e(__('bk.mail_off.text')) ?></p>
    </div>
    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/settings/mail')) ?>"><?= e(__('bk.mail_off.cta')) ?></a>
</div>
<?php endif; ?>

<div class="pp-card pp-booking-filters">
    <form method="get" action="<?= e(base_url('admin/booking/reservas')) ?>" class="pp-booking-filters__form">
        <select name="scope">
            <option value="upcoming" <?= $filters['scope'] === 'upcoming' ? 'selected' : '' ?>><?= e(__('bk.scope.upcoming')) ?></option>
            <option value="past"     <?= $filters['scope'] === 'past' ? 'selected' : '' ?>><?= e(__('bk.scope.past')) ?></option>
            <option value="all"      <?= $filters['scope'] === 'all' ? 'selected' : '' ?>><?= e(__('bk.scope.all')) ?></option>
        </select>
        <select name="status">
            <option value=""><?= e(__('bk.any_status')) ?></option>
            <?php foreach ($statusLabels as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="service">
            <option value="0"><?= e(__('bk.all_services')) ?></option>
            <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $filters['service'] === (int) $s['id'] ? 'selected' : '' ?>><?= e((string) $s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('bk.filter')) ?></button>
    </form>
</div>

<?php if ($bookings === []): ?>
    <div class="pp-card pp-booking-empty">
        <p><?= e(__('bk.empty_bookings')) ?></p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th><?= e(__('bk.col.datetime')) ?></th>
                    <th><?= e(__('bk.col.service')) ?></th>
                    <th><?= e(__('order.customer')) ?></th>
                    <th><?= e(__('table.status')) ?></th>
                    <th>Email</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): $st = (string) $b['status']; ?>
                <tr>
                    <td><strong><?= e($fmt((string) $b['starts_at_utc'])) ?></strong></td>
                    <td><?= e((string) $b['service_name']) ?></td>
                    <td>
                        <?= e((string) $b['customer_name']) ?><br>
                        <span class="pp-booking-soft"><?= e((string) $b['customer_email']) ?><?= $b['customer_phone'] !== null ? ' · ' . e((string) $b['customer_phone']) : '' ?></span>
                        <?php if ($b['notes'] !== null && trim((string) $b['notes']) !== ''): ?>
                            <br><span class="pp-booking-soft">«<?= e(mb_substr((string) $b['notes'], 0, 140)) ?>»</span>
                        <?php endif; ?>
                        <?php /* MODULOS M8 — respuestas a los campos propios del servicio.
                                 Se guardan con su etiqueta, así que una reserva vieja se
                                 sigue leyendo aunque el campo ya no exista. */ ?>
                        <?php foreach (\App\Modules\Booking\BookingFields::answers($b) as $ans): ?>
                            <br><span class="pp-booking-soft"><strong><?= e($ans['label']) ?>:</strong> <?= e(mb_substr($ans['value'], 0, 140)) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><span class="pp-status-pill<?= $statusPills[$st] ?? '' ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                    <td>
                        <?php $es = (string) $b['email_status']; ?>
                        <span class="pp-booking-soft" <?= $es === 'failed' && $b['email_error'] !== null ? 'title="' . e((string) $b['email_error']) . '"' : '' ?>>
                            <?= e(['sent' => __('mail.sent'), 'failed' => '⚠ ' . __('bk.mail.failed'), 'skipped' => __('bk.mail.skipped'), 'unknown' => '—'][$es] ?? $es) ?>
                        </span>
                    </td>
                    <td class="pp-table__actions">
                        <?php if ($st === 'pending'): ?>
                            <form method="post" action="<?= e(base_url('admin/booking/reservas/' . (int) $b['id'] . '/status')) ?>" class="pp-inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="status" value="confirmed">
                                <input type="hidden" name="f_status" value="<?= e((string) $filters['status']) ?>">
                                <input type="hidden" name="f_service" value="<?= (int) $filters['service'] ?>">
                                <input type="hidden" name="f_scope" value="<?= e((string) $filters['scope']) ?>">
                                <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm"><?= e(__('bk.confirm')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($st !== 'cancelled'): ?>
                            <form method="post" action="<?= e(base_url('admin/booking/reservas/' . (int) $b['id'] . '/status')) ?>" class="pp-inline-form"
                                  onsubmit="return confirm(<?= e(json_encode(__('bk.confirm_cancel', ['nombre' => (string) $b['customer_name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="status" value="cancelled">
                                <input type="hidden" name="f_status" value="<?= e((string) $filters['status']) ?>">
                                <input type="hidden" name="f_service" value="<?= (int) $filters['service'] ?>">
                                <input type="hidden" name="f_scope" value="<?= e((string) $filters['scope']) ?>">
                                <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text"><?= e(__('common.cancel')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
