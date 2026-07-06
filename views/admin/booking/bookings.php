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

$statusLabels = ['pending' => 'Pendiente', 'confirmed' => 'Confirmada', 'cancelled' => 'Cancelada'];
$statusPills  = ['pending' => '', 'confirmed' => ' pp-status-pill--green', 'cancelled' => ' pp-status-pill--muted'];
$tz = new DateTimeZone($timezone);
$fmt = static function (string $utc) use ($tz): string {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i');
};
?>

<?php \Core\View::start('title'); ?>Reservas recibidas<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Reservas recibidas</h2>
        <p class="pp-page-header__lead">
            <a href="<?= e(base_url('admin/booking')) ?>">← Servicios</a>
            <?php if ($pendingCount > 0): ?>
                · <strong><?= (int) $pendingCount ?></strong> pendiente<?= $pendingCount === 1 ? '' : 's' ?> de confirmar
            <?php endif; ?>
            · Horas en <?= e($timezone) ?>
        </p>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-card pp-booking-filters">
    <form method="get" action="<?= e(base_url('admin/booking/reservas')) ?>" class="pp-booking-filters__form">
        <select name="scope">
            <option value="upcoming" <?= $filters['scope'] === 'upcoming' ? 'selected' : '' ?>>Próximas</option>
            <option value="past"     <?= $filters['scope'] === 'past' ? 'selected' : '' ?>>Pasadas</option>
            <option value="all"      <?= $filters['scope'] === 'all' ? 'selected' : '' ?>>Todas</option>
        </select>
        <select name="status">
            <option value="">Cualquier estado</option>
            <?php foreach ($statusLabels as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="service">
            <option value="0">Todos los servicios</option>
            <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $filters['service'] === (int) $s['id'] ? 'selected' : '' ?>><?= e((string) $s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Filtrar</button>
    </form>
</div>

<?php if ($bookings === []): ?>
    <div class="pp-card pp-booking-empty">
        <p>No hay reservas con estos filtros. Cuando alguien reserve desde tu web (o desde el widget externo), aparecerá aquí.</p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Servicio</th>
                    <th>Cliente</th>
                    <th>Estado</th>
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
                    </td>
                    <td><span class="pp-status-pill<?= $statusPills[$st] ?? '' ?>"><?= e($statusLabels[$st] ?? $st) ?></span></td>
                    <td>
                        <?php $es = (string) $b['email_status']; ?>
                        <span class="pp-booking-soft" <?= $es === 'failed' && $b['email_error'] !== null ? 'title="' . e((string) $b['email_error']) . '"' : '' ?>>
                            <?= e(['sent' => 'Enviado', 'failed' => '⚠ Falló', 'skipped' => 'Sin configurar', 'unknown' => '—'][$es] ?? $es) ?>
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
                                <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm">Confirmar</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($st !== 'cancelled'): ?>
                            <form method="post" action="<?= e(base_url('admin/booking/reservas/' . (int) $b['id'] . '/status')) ?>" class="pp-inline-form"
                                  onsubmit="return confirm('¿Cancelar la reserva de <?= e((string) $b['customer_name']) ?>? Avisaremos al cliente por email.');">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="status" value="cancelled">
                                <input type="hidden" name="f_status" value="<?= e((string) $filters['status']) ?>">
                                <input type="hidden" name="f_service" value="<?= (int) $filters['service'] ?>">
                                <input type="hidden" name="f_scope" value="<?= e((string) $filters['scope']) ?>">
                                <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text">Cancelar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
