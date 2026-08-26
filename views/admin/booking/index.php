<?php
/**
 * Reservas — servicios reservables (FEAT-3 B2) y, en segundo plano, cómo
 * publicarlos (MODULOS M2/M5).
 *
 * Jerarquía a propósito: lo que se gestiona a diario son los SERVICIOS y las
 * RESERVAS, así que ocupan la pantalla. Publicar el calendario (en tu web o en
 * una ajena) es algo que se hace una vez, y vive plegado al final.
 *
 * Sin ningún servicio creado no se enseña nada de publicar: no habría nada que
 * publicar. La pantalla se reduce a un único paso, crear el primer servicio.
 *
 * @var array   $services        filas de booking_services + upcoming_count
 * @var ?string $apiKey          API key en claro (null si aún no se generó)
 * @var string  $allowedOrigins  orígenes permitidos, uno por línea
 * @var int     $pendingCount    reservas futuras pendientes de confirmar
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');
$widgetUrl = base_url('public/js/pp-booking-widget.js');
$firstServiceId = $services !== [] ? (int) $services[0]['id'] : 0;
$hasServices = $services !== [];
?>

<?php \Core\View::start('title'); ?><?= e(__('bk.title')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <div>
        <h2><?= e(__('bk.title')) ?></h2>
        <p class="pp-page-header__lead"><?= e($hasServices ? __('bk.lead') : __('bk.lead_empty')) ?></p>
    </div>
    <?php if ($hasServices): ?>
    <div>
        <?php /* Las pendientes se ven ya desde aquí: es lo que reclama atención. */ ?>
        <a class="pp-btn <?= $pendingCount > 0 ? 'pp-btn--primary' : 'pp-btn--secondary' ?>"
           href="<?= e(base_url('admin/booking/reservas')) ?>">
            <?= e(__('bk.received')) ?><?= $pendingCount > 0 ? ' · ' . (int) $pendingCount : '' ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if ($notice): ?><div class="pp-alert pp-alert--success"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="pp-alert pp-alert--error"><?= e($error) ?></div><?php endif; ?>

<?php /* MODULOS M9 — Sin correo configurado las reservas entran pero nadie se
         entera. Se avisa donde se trabaja, con el enlace para arreglarlo. */ ?>
<?php if ($hasServices && empty($mailReady)): ?>
<div class="pp-alert pp-alert--warning pp-booking-mailwarn" data-pp-persist>
    <div>
        <strong><?= e(__('bk.mail_off.title')) ?></strong>
        <p><?= e(__('bk.mail_off.text')) ?></p>
    </div>
    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/settings/mail')) ?>"><?= e(__('bk.mail_off.cta')) ?></a>
</div>
<?php endif; ?>

<?php if (!$hasServices): ?>
    <?php /* Un solo paso posible: crear el primer servicio. */ ?>
    <div class="pp-card pp-booking-first">
        <div class="pp-empty">
            <div class="pp-empty__title"><?= e(__('bk.first.title')) ?></div>
            <p class="pp-empty__text"><?= e(__('bk.first.text')) ?></p>
            <form method="post" action="<?= e(base_url('admin/booking/services')) ?>" class="pp-booking-new__form pp-booking-first__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="text" name="name" maxlength="120" required
                       placeholder="<?= e(__('bk.new_placeholder')) ?>">
                <button type="submit" class="pp-btn pp-btn--primary">+ <?= e(__('bk.new_service')) ?></button>
            </form>
            <p class="pp-empty__text pp-booking-first__note"><?= e(__('bk.first.note')) ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="pp-card pp-booking-services">
        <?php /* El alta rápida va DENTRO de la tarjeta de la tabla: una caja menos
                 y el listado, que es lo que se mira, manda en la pantalla. */ ?>
        <div class="pp-booking-services__head">
            <h3><?= e(__('bk.services')) ?></h3>
            <form method="post" action="<?= e(base_url('admin/booking/services')) ?>" class="pp-booking-new__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="text" name="name" maxlength="120" required
                       placeholder="<?= e(__('bk.new_placeholder')) ?>">
                <button type="submit" class="pp-btn pp-btn--primary">+ <?= e(__('bk.new_service')) ?></button>
            </form>
        </div>
        <table class="pp-table">
            <thead>
                <tr>
                    <th><?= e(__('bk.col.service')) ?></th>
                    <th><?= e(__('bk.col.duration')) ?></th>
                    <th><?= e(__('bk.col.capacity')) ?></th>
                    <th><?= e(__('bk.col.upcoming')) ?></th>
                    <th><?= e(__('table.status')) ?></th>
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
                    <td><?= (int) $s['duration_min'] ?> min<?= (int) $s['buffer_min'] > 0 ? ' + ' . e(__('bk.buffer_suffix', ['min' => (string) (int) $s['buffer_min']])) : '' ?></td>
                    <td><?= (int) $s['capacity'] ?></td>
                    <td><?= (int) $s['upcoming_count'] ?></td>
                    <td>
                        <?php if ((int) $s['active'] === 1): ?>
                            <span class="pp-status-pill pp-status-pill--green"><?= e(__('modules.active')) ?></span>
                        <?php else: ?>
                            <span class="pp-status-pill"><?= e(__('modules.inactive')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="pp-table__actions">
                        <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url('admin/booking/services/' . (int) $s['id'])) ?>"><?= e(__('common.edit')) ?></a>
                        <form method="post" action="<?= e(base_url('admin/booking/services/' . (int) $s['id'] . '/delete')) ?>"
                              onsubmit="return confirm(<?= e(json_encode(__('bk.confirm_delete', ['nombre' => (string) $s['name']]), JSON_UNESCAPED_UNICODE)) ?>);"
                              class="pp-inline-form">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text"><?= e(__('common.delete')) ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($hasServices): ?>
<?php /* Publicar el calendario: plegado y al final. Se configura una vez y no
         se vuelve a tocar, así que no puede pesar más que el listado. Dentro,
         primero TU web (dos líneas, sin código) y luego las webs ajenas, que
         es lo que necesita clave y snippet. */ ?>
<details class="pp-seo-advanced pp-booking-publish">
    <summary><?= e(__('bk.publish.title')) ?></summary>
    <div class="pp-seo-advanced__body">

        <section class="pp-booking-publish__block">
            <h4><?= e(__('bk.own.title')) ?></h4>
            <p class="pp-booking-soft"><?= e(__('bk.own.lead')) ?></p>
            <ol class="pp-booking-own__steps">
                <li><?= __('bk.own.step_section.html') ?></li>
                <li><?= __('bk.own.step_assistant.html') ?></li>
            </ol>
            <p class="pp-booking-soft"><?= e(__('bk.own.note')) ?></p>
            <div class="pp-booking-integration__actions">
                <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/pages')) ?>"><?= e(__('bk.own.go_pages')) ?></a>
            </div>
        </section>

        <section class="pp-booking-publish__block pp-booking-integration">
            <h4><?= e(__('bk.ext.title')) ?></h4>
            <p class="pp-booking-soft"><?= e(__('bk.ext.lead')) ?></p>

            <form method="post" action="<?= e(base_url('admin/booking/integration')) ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div class="pp-form-group">
                    <label for="pp-bk-origins"><?= e(__('bk.ext.origins')) ?> <span class="pp-ai-optional-tag"><?= e(__('bk.ext.one_per_line')) ?></span></label>
                    <textarea id="pp-bk-origins" name="allowed_origins" rows="2" placeholder="https://www.otraweb.com"><?= e($allowedOrigins) ?></textarea>
                    <small><?= __('bk.ext.origins_help.html') ?></small>
                </div>

                <?php if ($apiKey !== null): ?>
                <div class="pp-form-group">
                    <label><?= e(__('bk.ext.api_key')) ?></label>
                    <code class="pp-booking-key"><?= e($apiKey) ?></code>
                </div>
                <div class="pp-form-group">
                    <label><?= e(__('bk.ext.snippet')) ?></label>
                    <pre class="pp-booking-snippet">&lt;script src="<?= e($widgetUrl) ?>"
        data-service="<?= $firstServiceId > 0 ? (int) $firstServiceId : 'ID_DEL_SERVICIO' ?>" data-key="<?= e($apiKey) ?>" defer&gt;&lt;/script&gt;</pre>
                    <small><?= __('bk.ext.snippet_help.html') ?></small>
                </div>
                <?php endif; ?>

                <div class="pp-booking-integration__actions">
                    <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm"><?= e(__('common.save')) ?></button>
                    <?php if ($apiKey !== null): ?>
                    <button type="submit" name="regenerate_key" value="1" class="pp-btn pp-btn--ghost pp-btn--sm pp-btn--danger-text"
                            onclick="return confirm(<?= e(json_encode(__('bk.ext.confirm_regen'), JSON_UNESCAPED_UNICODE)) ?>);">
                        <?= e(__('bk.ext.regen')) ?>
                    </button>
                    <?php else: ?>
                    <button type="submit" name="regenerate_key" value="1" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('bk.ext.generate')) ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>
</details>
<?php endif; ?>
