<?php
/**
 * Reservas — listado de servicios reservables (FEAT-3 B2) + integración externa (B6).
 *
 * @var array   $services        filas de booking_services + upcoming_count
 * @var ?string $apiKey          API key en claro (null si aún no se generó)
 * @var string  $allowedOrigins  orígenes permitidos, uno por línea
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');
$widgetUrl = base_url('public/js/pp-booking-widget.js');
$firstServiceId = $services !== [] ? (int) $services[0]['id'] : 0;
?>

<?php \Core\View::start('title'); ?>Reservas<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2>Reservas</h2>
        <p class="pp-page-header__lead">Define los servicios que tus clientes pueden reservar: duración, horario semanal y excepciones. Las reservas llegan por email y se gestionan desde aquí.</p>
    </div>
    <div>
        <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/booking/reservas')) ?>">Reservas recibidas</a>
    </div>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="pp-card pp-booking-new">
    <form method="post" action="<?= e(base_url('admin/booking/services')) ?>" class="pp-booking-new__form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="text" name="name" maxlength="120" required
               placeholder="Ej: Consulta inicial, Clase de yoga, Corte de pelo…">
        <button type="submit" class="pp-btn pp-btn--primary">+ Nuevo servicio</button>
    </form>
</div>

<?php if ($services === []): ?>
    <div class="pp-card pp-booking-empty">
        <p>Todavía no hay servicios reservables. Crea el primero con el nombre de lo que ofreces y configura su horario.</p>
    </div>
<?php else: ?>
    <div class="pp-card">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Servicio</th>
                    <th>Duración</th>
                    <th>Plazas</th>
                    <th>Reservas próximas</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                    <td>
                        <a href="<?= e(base_url('admin/booking/services/' . (int) $s['id'])) ?>"><strong><?= e((string) $s['name']) ?></strong></a>
                        <?php if (!empty($s['price_label'])): ?><span class="pp-booking-price"><?= e((string) $s['price_label']) ?></span><?php endif; ?>
                    </td>
                    <td><?= (int) $s['duration_min'] ?> min<?= (int) $s['buffer_min'] > 0 ? ' + ' . (int) $s['buffer_min'] . ' de margen' : '' ?></td>
                    <td><?= (int) $s['capacity'] ?></td>
                    <td><?= (int) $s['upcoming_count'] ?></td>
                    <td>
                        <?php if ((int) $s['active'] === 1): ?>
                            <span class="pp-status-pill pp-status-pill--green">Activo</span>
                        <?php else: ?>
                            <span class="pp-status-pill">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/booking/services/' . (int) $s['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(base_url('admin/booking/services/' . (int) $s['id'] . '/delete')) ?>"
                              onsubmit="return confirm('¿Eliminar «<?= e((string) $s['name']) ?>»? Se borrarán también sus reservas.');"
                              class="pp-inline-form">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="pp-card pp-booking-integration">
    <h3>Reservas desde otras webs</h3>
    <p class="pp-booking-soft">Incrusta el calendario de reservas en cualquier web externa con este snippet. Necesita una clave de API y que el dominio de esa web esté en la lista de orígenes permitidos.</p>

    <form method="post" action="<?= e(base_url('admin/booking/integration')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="pp-form-group">
            <label for="pp-bk-origins">Orígenes permitidos <span class="pp-ai-optional-tag">uno por línea</span></label>
            <textarea id="pp-bk-origins" name="allowed_origins" rows="2" placeholder="https://www.otraweb.com"><?= e($allowedOrigins) ?></textarea>
            <small>Solo estas webs podrán usar la API con tu clave. Sin esquema ni barra final: <code>https://www.otraweb.com</code></small>
        </div>

        <?php if ($apiKey !== null): ?>
        <div class="pp-form-group">
            <label>Clave de API</label>
            <code class="pp-booking-key"><?= e($apiKey) ?></code>
        </div>
        <div class="pp-form-group">
            <label>Snippet para pegar en la web externa</label>
            <pre class="pp-booking-snippet">&lt;script src="<?= e($widgetUrl) ?>"
        data-service="<?= $firstServiceId > 0 ? (int) $firstServiceId : 'ID_DEL_SERVICIO' ?>" data-key="<?= e($apiKey) ?>" defer&gt;&lt;/script&gt;</pre>
            <small>Cambia <code>data-service</code> por el id del servicio que quieras mostrar (lo ves en la URL al editarlo). En páginas de este mismo sitio no hace falta <code>data-key</code>.</small>
        </div>
        <?php endif; ?>

        <div class="pp-booking-integration__actions">
            <button type="submit" class="pp-btn pp-btn--primary">Guardar</button>
            <?php if ($apiKey !== null): ?>
            <button type="submit" name="regenerate_key" value="1" class="pp-btn pp-btn--ghost pp-btn--danger-text"
                    onclick="return confirm('¿Regenerar la clave? Los snippets ya instalados dejarán de funcionar hasta actualizarlos.');">
                Regenerar clave
            </button>
            <?php else: ?>
            <button type="submit" name="regenerate_key" value="1" class="pp-btn pp-btn--secondary">Generar clave de API</button>
            <?php endif; ?>
        </div>
    </form>
</div>
