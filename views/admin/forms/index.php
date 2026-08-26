<?php
/** @var array $submissions */
\Core\View::extend('admin/layout');

$filters = $filters ?? [];
$metrics = $metrics ?? [];
$forms = $forms ?? [];
$originPages = $originPages ?? [];
$fmtDate = static fn($d): string => ($ts = strtotime((string) $d)) ? date('d/m/Y H:i', $ts) : __('inbox.no_date');
$formatValue = static function (mixed $value): string {
    if (is_array($value) && ($value['type'] ?? '') === 'file') return (string) ($value['original_name'] ?? __('inbox.attachment'));
    return is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;
};
$mailLabel = static function (string $status, bool $visitor): string {
    $labels = $visitor
        ? ['unknown' => __('inbox.mail.unknown'), 'disabled' => __('inbox.mail.disabled'), 'skipped' => __('inbox.mail.not_sent'), 'sent' => __('inbox.mail.sent_f'), 'failed' => __('inbox.mail.failed')]
        : ['skipped' => __('inbox.mail.not_configured'), 'sent' => __('inbox.mail.sent_m'), 'failed' => __('inbox.mail.failed')];
    return $labels[$status] ?? __('inbox.mail.no_data');
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
$periodLabels = ['7' => __('inbox.period.7'), '30' => __('inbox.period.30'), '90' => __('inbox.period.90'), 'custom' => __('inbox.period.custom')];
$deliveryLabels = ['issues' => __('inbox.delivery.issues'), 'sent' => __('inbox.delivery.sent'), 'autoresponder_off' => __('inbox.delivery.ar_off')];
?>

<?php \Core\View::start('title'); ?><?= e(__('nav.messages')) ?><?php \Core\View::end(); ?>

<section class="pp-inbox">
    <header class="pp-inbox__header">
        <div>
            <span class="pp-inbox__eyebrow"><?= e(__('forms.title')) ?></span>
            <h2><?= e(__('inbox.title')) ?></h2>
            <p><?= e(__('inbox.intro')) ?></p>
        </div>
        <a class="pp-btn pp-btn--secondary" href="<?= e(base_url('admin/formularios')) ?>"><?= e(__('inbox.manage_forms')) ?></a>
    </header>

    <div class="pp-inbox-metrics" aria-label="<?= e(__('inbox.metrics_aria')) ?>">
        <div><strong><?= (int) ($metrics['unread'] ?? 0) ?></strong><span><?= e(__('inbox.new')) ?></span></div>
        <div><strong><?= (int) ($metrics['recent'] ?? 0) ?></strong><span><?= e(__('inbox.last_30')) ?></span></div>
        <div><strong><?= (int) ($metrics['mail_errors'] ?? 0) ?></strong><span><?= e(__('inbox.mail_errors')) ?></span></div>
        <div><strong><?= (int) ($metrics['total'] ?? 0) ?></strong><span><?= e(__('inbox.total')) ?></span></div>
    </div>

    <form class="pp-inbox-toolbar" method="GET" action="<?= e(base_url('admin/forms')) ?>" data-inbox-filters>
        <input type="hidden" name="status" value="<?= e((string) ($filters['status'] ?? '')) ?>">
        <div class="pp-inbox-search">
            <label class="pp-visually-hidden" for="inbox-search"><?= e(__('inbox.search_label')) ?></label>
            <input id="inbox-search" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(__('inbox.search_placeholder')) ?>">
            <button class="pp-inbox-search__submit" type="submit"><?= e(__('bank.search')) ?></button>
        </div>
        <nav class="pp-inbox-status" aria-label="<?= e(__('inbox.status_aria')) ?>">
            <a class="<?= ($filters['status'] ?? '') === '' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => ''])) ?>"><?= e(__('inbox.all')) ?></a>
            <a class="<?= ($filters['status'] ?? '') === 'unread' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => 'unread'])) ?>"><?= e(__('inbox.new')) ?></a>
            <a class="<?= ($filters['status'] ?? '') === 'read' ? 'is-active' : '' ?>" href="<?= e($urlWith(['status' => 'read'])) ?>"><?= e(__('inbox.read')) ?></a>
        </nav>
        <a class="pp-inbox-clear <?= $hasFilters ? '' : 'is-disabled' ?>" href="<?= e(base_url('admin/forms')) ?>" aria-disabled="<?= $hasFilters ? 'false' : 'true' ?>"><?= e(__('inbox.clear_filters')) ?></a>
        <label class="pp-inbox-filter"><span><?= e(__('inbox.form')) ?></span><select name="form_id"><option value="0"><?= e(__('inbox.all_forms')) ?></option><?php foreach ($forms as $form): ?><option value="<?= (int) $form['id'] ?>" <?= (int) ($filters['form_id'] ?? 0) === (int) $form['id'] ? 'selected' : '' ?>><?= e((string) $form['heading']) ?></option><?php endforeach; ?></select></label>
        <label class="pp-inbox-filter"><span><?= e(__('inbox.page')) ?></span><select name="page_id"><option value="0"><?= e(__('inbox.all_pages')) ?></option><?php foreach ($originPages as $origin): ?><option value="<?= (int) $origin['id'] ?>" <?= (int) ($filters['page_id'] ?? 0) === (int) $origin['id'] ? 'selected' : '' ?>><?= e((string) $origin['title']) ?></option><?php endforeach; ?></select></label>
        <label class="pp-inbox-filter"><span><?= e(__('inbox.period')) ?></span><select name="period" data-inbox-period><option value=""><?= e(__('inbox.any_date')) ?></option><?php foreach ($periodLabels as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $effectivePeriod === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="pp-inbox-filter"><span><?= e(__('inbox.delivery')) ?></span><select name="delivery"><option value=""><?= e(__('inbox.any_status')) ?></option><?php foreach ($deliveryLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($filters['delivery'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <div class="pp-inbox-custom-dates" data-inbox-custom-dates <?= $effectivePeriod === 'custom' ? '' : 'hidden' ?>>
            <label><span><?= e(__('inbox.from')) ?></span><input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>"></label>
            <label><span><?= e(__('inbox.to')) ?></span><input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>"></label>
        </div>
        <?php if (($filters['email_status'] ?? '') !== ''): ?><input type="hidden" name="email_status" value="<?= e((string) $filters['email_status']) ?>"><?php endif; ?>
        <?php if (($filters['autoresponder_status'] ?? '') !== ''): ?><input type="hidden" name="autoresponder_status" value="<?= e((string) $filters['autoresponder_status']) ?>"><?php endif; ?>
    </form>

    <div class="pp-inbox-results">
        <span><?= e(__((int) $total === 1 ? 'inbox.count_one' : 'inbox.count_other', ['n' => (int) $total])) ?></span>
        <?php if ($hasFilters): ?><small><?= e(__('inbox.with_filters')) ?></small><?php endif; ?>
    </div>

    <?php if (empty($submissions)): ?>
        <div class="pp-inbox-empty">
            <strong><?= e($hasFilters ? __('inbox.empty_filtered') : __('inbox.empty')) ?></strong>
            <span><?= e($hasFilters ? __('inbox.empty_filtered_hint') : __('inbox.empty_hint')) ?></span>
            <?php if ($hasFilters): ?><a href="<?= e(base_url('admin/forms')) ?>"><?= e(__('inbox.see_all')) ?></a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="pp-submissions-list">
        <?php foreach ($submissions as $s):
            $payload = json_decode((string) ($s['payload'] ?? '{}'), true);
            $payload = is_array($payload) ? $payload : [];
            $sender = (string) ($s['sender_name'] ?: $s['sender_email'] ?: $s['sender_phone'] ?: __('inbox.unidentified'));
            $preview = trim(implode(' · ', array_values(array_filter(array_map($formatValue, array_slice($payload, 0, 3))))));
            if (mb_strlen($preview) > 120) $preview = mb_substr($preview, 0, 117) . '...';
            $arStatus = (string) ($s['autoresponder_status'] ?? 'unknown');
            $editUrl = ($s['render_mode'] ?? '') === 'canvas'
                ? base_url('admin/canvas/' . (int) $s['page_id'])
                : base_url('admin/pages/' . (int) $s['page_id'] . '/edit');
        ?>
            <details class="pp-submission-row <?= $s['status'] === 'unread' ? 'is-unread' : '' ?>">
                <summary class="pp-submission-row__summary">
                    <span class="pp-submission-row__state" aria-label="<?= e($s['status'] === 'unread' ? __('inbox.aria_new') : __('inbox.aria_read')) ?>"></span>
                    <div class="pp-submission-row__main">
                        <strong><?= e($sender) ?></strong>
                        <span><?= e($preview ?: (string) ($s['section_heading'] ?: __('inbox.a_form'))) ?></span>
                    </div>
                    <div class="pp-submission-row__origin">
                        <span><?= e((string) ($s['section_heading'] ?: __('inbox.a_form'))) ?></span>
                        <small><?= e((string) $s['page_title']) ?><?= !empty($s['source_label']) ? ' · ' . e((string) $s['source_label']) : '' ?></small>
                    </div>
                    <time datetime="<?= e((string) $s['created_at']) ?>"><?= e($fmtDate($s['created_at'])) ?></time>
                    <span class="pp-submission-row__chevron" aria-hidden="true"></span>
                </summary>

                <div class="pp-submission-row__body">
                    <header class="pp-submission-detail__header">
                        <div>
                            <span><?= e(__('inbox.message_from')) ?></span>
                            <h3><?= e($sender) ?></h3>
                            <div class="pp-submission-detail__contacts">
                                <?php if (!empty($s['sender_email'])): ?><a href="mailto:<?= e((string) $s['sender_email']) ?>"><?= e((string) $s['sender_email']) ?></a><?php endif; ?>
                                <?php if (!empty($s['sender_phone'])): ?><a href="tel:<?= e((string) $s['sender_phone']) ?>"><?= e((string) $s['sender_phone']) ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="pp-submission-detail__source">
                            <span><?= e(__('inbox.origin')) ?></span>
                            <a href="<?= e($editUrl) ?>"><?= e((string) $s['page_title']) ?></a>
                            <code>/<?= e((string) $s['slug']) ?></code>
                            <?php if (!empty($s['source_label'])): ?><small><?= e((string) $s['source_label']) ?></small><?php endif; ?>
                        </div>
                    </header>

                    <div class="pp-submission-detail__grid">
                        <section>
                            <h4><?= e(__('inbox.submitted_data')) ?></h4>
                            <dl class="pp-submission-fields">
                            <?php foreach ($payload as $label => $value): ?>
                                <div>
                                    <dt><?= e((string) $label) ?></dt>
                                    <?php if (is_array($value) && ($value['type'] ?? '') === 'file'):
                                        $downloadKey = (string) ($value['field_name'] ?? $label);
                                        $downloadUrl = base_url('admin/forms/submissions/' . (int) $s['id'] . '/files/' . rawurlencode($downloadKey)); ?>
                                        <dd><a class="pp-submission-file" href="<?= e($downloadUrl) ?>"><strong><?= e((string) ($value['original_name'] ?? __('inbox.attachment'))) ?></strong><span><?= e(\App\Services\FormSubmissionService::formatBytes((int) ($value['size'] ?? 0))) ?></span></a></dd>
                                    <?php else: ?><dd><?= nl2br(e($formatValue($value)), false) ?></dd><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            </dl>
                        </section>

                        <aside class="pp-submission-delivery">
                            <h4><?= e(__('inbox.mail_delivery')) ?></h4>
                            <div class="pp-submission-delivery__item is-<?= e($mailTone((string) $s['email_status'])) ?>">
                                <i aria-hidden="true"></i><div><span><?= e(__('inbox.admin_notice')) ?></span><strong><?= e($mailLabel((string) $s['email_status'], false)) ?></strong><?php if (!empty($s['email_error'])): ?><small><?= e((string) $s['email_error']) ?></small><?php endif; ?></div>
                            </div>
                            <div class="pp-submission-delivery__item is-<?= e($mailTone($arStatus)) ?>">
                                <i aria-hidden="true"></i><div><span><?= e(__('inbox.visitor_reply')) ?></span><strong><?= e($mailLabel($arStatus, true)) ?></strong><?php if (!empty($s['autoresponder_error'])): ?><small><?= e((string) $s['autoresponder_error']) ?></small><?php endif; ?></div>
                            </div>
                        </aside>
                    </div>

                    <footer class="pp-submission-row__actions">
                        <span><?= e(__('inbox.received_on', ['fecha' => $fmtDate($s['created_at'])])) ?></span>
                        <div>
                        <?php if ($s['status'] === 'unread'): ?><form method="POST" action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/read')) ?>"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('inbox.mark_read')) ?></button></form><?php endif; ?>
                        <form method="POST" action="<?= e(base_url('admin/forms/submissions/' . (int) $s['id'] . '/delete')) ?>" onsubmit="return confirm(<?= e(json_encode(__('inbox.confirm_delete'), JSON_UNESCAPED_UNICODE)) ?>);"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button type="submit" class="pp-btn pp-btn--danger pp-btn--sm"><?= e(__('common.delete')) ?></button></form>
                        </div>
                    </footer>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
        <nav class="pp-pagination" aria-label="<?= e(__('inbox.pagination_aria')) ?>">
            <?php if ($page > 1): ?><a href="<?= e($queryUrl($page - 1)) ?>" class="pp-btn pp-btn--secondary"><?= e(__('common.previous')) ?></a><?php endif; ?>
            <span class="pp-pagination__info"><?= e(__('common.page_of', ['n' => (int) $page, 'total' => (int) $totalPages])) ?></span>
            <?php if ($page < $totalPages): ?><a href="<?= e($queryUrl($page + 1)) ?>" class="pp-btn pp-btn--secondary"><?= e(__('common.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
