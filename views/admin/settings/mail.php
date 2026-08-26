<?php
/**
 * @var array   $form         valores del formulario (from_email, from_name, host, port, encryption, user)
 * @var bool    $configured
 * @var bool    $has_password
 * @var array   $recent_log
 * @var array   $errors
 * @var ?string $notice
 * @var ?string $error
 * @var string  $csrf
 */
\Core\View::extend('admin/layout');

// Las claves son valores guardados; solo la etiqueta se traduce.
$encLabels = ['tls' => __('mail.enc.tls'), 'ssl' => __('mail.enc.ssl'), 'none' => __('mail.enc.none')];
?>

<?php \Core\View::start('title'); ?><?= e(__('mail.title')) ?><?php \Core\View::end(); ?>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    // E4b — catálogo de proveedores: rellena servidor/puerto/cifrado por el dominio del email.
    var PROVIDERS = {
        'gmail.com':      { key: 'gmail',     host: 'smtp.gmail.com',         port: 587, enc: 'tls' },
        'googlemail.com': { key: 'gmail',     host: 'smtp.gmail.com',         port: 587, enc: 'tls' },
        'outlook.com':    { key: 'outlook',   host: 'smtp-mail.outlook.com',  port: 587, enc: 'tls' },
        'hotmail.com':    { key: 'outlook',   host: 'smtp-mail.outlook.com',  port: 587, enc: 'tls' },
        'live.com':       { key: 'outlook',   host: 'smtp-mail.outlook.com',  port: 587, enc: 'tls' },
        'msn.com':        { key: 'outlook',   host: 'smtp-mail.outlook.com',  port: 587, enc: 'tls' },
        'yahoo.com':      { key: 'yahoo',     host: 'smtp.mail.yahoo.com',    port: 465, enc: 'ssl' },
        'yahoo.es':       { key: 'yahoo',     host: 'smtp.mail.yahoo.com',    port: 465, enc: 'ssl' },
        'icloud.com':     { key: 'icloud',    host: 'smtp.mail.me.com',       port: 587, enc: 'tls' },
        'me.com':         { key: 'icloud',    host: 'smtp.mail.me.com',       port: 587, enc: 'tls' },
        'zoho.com':       { key: 'zoho',      host: 'smtp.zoho.eu',           port: 465, enc: 'ssl' },
        'zoho.eu':        { key: 'zoho',      host: 'smtp.zoho.eu',           port: 465, enc: 'ssl' },
        'gmx.es':         { key: 'gmx',       host: 'mail.gmx.com',           port: 587, enc: 'tls' },
        'gmx.com':        { key: 'gmx',       host: 'mail.gmx.com',           port: 587, enc: 'tls' }
    };
    // Notas por proveedor (consejos en cristiano).
    var NOTES = {
        gmail:   pp.t('js.mail.note_gmail'),
        outlook: pp.t('js.mail.note_outlook'),
        yahoo:   pp.t('js.mail.note_yahoo'),
        icloud:  pp.t('js.mail.note_icloud'),
        zoho:    pp.t('js.mail.note_zoho'),
        gmx:     pp.t('js.mail.note_gmx'),
        hosting: pp.t('js.mail.note_hosting')
    };

    var emailIn = document.getElementById('pp-mail-from');
    var hostIn  = document.getElementById('pp-mail-host');
    var portIn  = document.getElementById('pp-mail-port');
    var encIn   = document.getElementById('pp-mail-enc');
    var providerSel = document.getElementById('pp-mail-provider');
    var note    = document.getElementById('pp-mail-provider-note');

    function applyProvider(p, isHosting) {
        if (!p) return;
        hostIn.value = p.host;
        portIn.value = p.port;
        encIn.value  = p.enc;
        var noteKey = isHosting ? 'hosting' : p.key;
        note.textContent = NOTES[noteKey] || '';
        note.hidden = !note.textContent;
    }

    function domainOf(email) {
        var at = String(email || '').indexOf('@');
        return at === -1 ? '' : email.slice(at + 1).trim().toLowerCase();
    }

    // Autodetección al escribir el remitente.
    function detect() {
        var dom = domainOf(emailIn.value);
        if (!dom) return;
        if (PROVIDERS[dom]) {
            providerSel.value = PROVIDERS[dom].key;
            applyProvider(PROVIDERS[dom], false);
        } else if (providerSel.value === '' || providerSel.value === 'hosting') {
            // Dominio propio → patrón típico de hosting compartido.
            providerSel.value = 'hosting';
            applyProvider({ host: 'mail.' + dom, port: 465, enc: 'ssl' }, true);
        }
    }

    // Selección manual del proveedor.
    var MANUAL = {
        gmail:   { host: 'smtp.gmail.com',        port: 587, enc: 'tls', key: 'gmail' },
        outlook: { host: 'smtp-mail.outlook.com', port: 587, enc: 'tls', key: 'outlook' },
        m365:    { host: 'smtp.office365.com',    port: 587, enc: 'tls', key: 'outlook' },
        yahoo:   { host: 'smtp.mail.yahoo.com',   port: 465, enc: 'ssl', key: 'yahoo' },
        icloud:  { host: 'smtp.mail.me.com',      port: 587, enc: 'tls', key: 'icloud' },
        zoho:    { host: 'smtp.zoho.eu',          port: 465, enc: 'ssl', key: 'zoho' },
        ionos:   { host: 'smtp.ionos.es',         port: 587, enc: 'tls', key: 'hosting' },
        ovh:     { host: 'ssl0.ovh.net',          port: 465, enc: 'ssl', key: 'hosting' },
        hostinger:{ host: 'smtp.hostinger.com',   port: 465, enc: 'ssl', key: 'hosting' }
    };

    providerSel.addEventListener('change', function () {
        var v = providerSel.value;
        if (v === 'hosting') {
            var dom = domainOf(emailIn.value);
            applyProvider({ host: dom ? 'mail.' + dom : 'mail.tudominio.com', port: 465, enc: 'ssl' }, true);
        } else if (v === 'manual') {
            note.textContent = pp.t('js.mail.note_manual');
            note.hidden = false;
        } else if (MANUAL[v]) {
            applyProvider({ host: MANUAL[v].host, port: MANUAL[v].port, enc: MANUAL[v].enc, key: MANUAL[v].key }, MANUAL[v].key === 'hosting');
        }
    });

    emailIn.addEventListener('blur', detect);
})();
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header pp-ai-settings-header">
    <div>
        <span class="pp-ai-kicker"><?= e(__('mail.kicker')) ?></span>
        <h2><?= e(__('mail.title')) ?></h2>
        <p class="pp-page-intro">
            <?= e(__('mail.intro')) ?>
        </p>
    </div>
    <div class="pp-ai-status-card <?= $configured ? 'is-ready' : 'is-empty' ?>">
        <span class="pp-ai-status-dot"></span>
        <strong><?= e($configured ? __('mail.connected') : __('settings_ai.not_configured')) ?></strong>
        <small><?= e($configured ? __('mail.connected_help') : __('mail.not_configured_help')) ?></small>
    </div>
</div>

<nav class="pp-settings-tabs" aria-label="<?= e(__('settings.tabs_aria')) ?>">
    <a href="<?= e(base_url('admin/settings')) ?>"><?= e(__('settings.tab.general')) ?></a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>"><?= e(__('settings.tab.ai')) ?></a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>" class="is-active"><?= e(__('settings.tab.mail')) ?></a>
</nav>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="pp-alert pp-alert--error"><?= e($error) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong><?= e(__('settings_ai.check_errors')) ?></strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/settings/mail')) ?>" class="pp-form pp-ai-settings-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-ai-config-panel" aria-labelledby="pp-mail-sender-title">
        <div class="pp-ai-section-head">
            <div>
                <h3 id="pp-mail-sender-title"><?= e(__('mail.sender_title')) ?></h3>
                <p><?= __('mail.sender_help.html') ?></p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-from"><?= e(__('mail.from_email')) ?></label>
            <input type="email" id="pp-mail-from" name="from_email" required maxlength="255"
                   value="<?= e($form['from_email']) ?>" placeholder="info@tudominio.com">
            <small><?= e(__('mail.from_email_help')) ?></small>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-from-name"><?= e(__('mail.from_name')) ?> <span class="pp-ai-optional-tag"><?= e(__('common.optional')) ?></span></label>
            <input type="text" id="pp-mail-from-name" name="from_name" maxlength="120"
                   value="<?= e($form['from_name']) ?>" placeholder="<?= e(__('mail.from_name_placeholder')) ?>">
            <small><?= e(__('mail.from_name_help')) ?></small>
        </div>
    </section>

    <section class="pp-ai-config-panel" aria-labelledby="pp-mail-server-title">
        <div class="pp-ai-section-head">
            <div>
                <h3 id="pp-mail-server-title"><?= e(__('mail.server_title')) ?></h3>
                <p><?= e(__('mail.server_help')) ?></p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-provider"><?= e(__('mail.provider')) ?></label>
            <select id="pp-mail-provider" name="provider_hint">
                <option value=""><?= e(__('mail.provider_auto')) ?></option>
                <option value="gmail">Gmail / Google Workspace</option>
                <option value="outlook">Outlook / Hotmail</option>
                <option value="m365"><?= e(__('mail.provider_m365')) ?></option>
                <option value="yahoo">Yahoo</option>
                <option value="icloud">iCloud</option>
                <option value="zoho">Zoho</option>
                <option value="ionos">IONOS</option>
                <option value="ovh">OVH</option>
                <option value="hostinger">Hostinger</option>
                <option value="hosting"><?= e(__('mail.provider_hosting')) ?></option>
                <option value="manual"><?= e(__('mail.provider_manual')) ?></option>
            </select>
            <small id="pp-mail-provider-note" class="pp-design-hint" hidden></small>
        </div>

        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-mail-host"><?= e(__('mail.host')) ?></label>
                <input type="text" id="pp-mail-host" name="host" required maxlength="255"
                       value="<?= e($form['host']) ?>" placeholder="smtp.tudominio.com">
            </div>
            <div class="pp-form-group">
                <label for="pp-mail-port"><?= e(__('mail.port')) ?></label>
                <input type="number" id="pp-mail-port" name="port" required min="1" max="65535"
                       value="<?= e($form['port']) ?>" placeholder="587">
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-enc"><?= e(__('mail.encryption')) ?></label>
            <select id="pp-mail-enc" name="encryption">
                <?php foreach ($encLabels as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $form['encryption'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small><?= e(__('mail.encryption_help')) ?></small>
        </div>

        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-mail-user"><?= e(__('mail.user')) ?></label>
                <input type="text" id="pp-mail-user" name="user" maxlength="255"
                       value="<?= e($form['user']) ?>" placeholder="<?= e(__('mail.user_placeholder')) ?>" autocomplete="off">
                <small><?= e(__('mail.user_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="pp-mail-pass"><?= e(__('auth.password')) ?></label>
                <input type="password" id="pp-mail-pass" name="pass" autocomplete="new-password"
                       placeholder="<?= e($has_password ? __('settings_ai.key_placeholder_set') : __('mail.pass_placeholder')) ?>">
                <small>
                    <?= e($has_password ? __('mail.pass_exists') : __('mail.pass_app_password')) ?>
                </small>
            </div>
        </div>

        <div class="pp-ai-security-note">
            <strong><?= e(__('settings_ai.secure_title')) ?></strong>
            <span><?= e(__('mail.secure_text')) ?></span>
        </div>

        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="test_on_save" value="1" checked>
                <?= e(__('mail.test_on_save')) ?>
            </label>
            <small><?= e(__('mail.test_on_save_help')) ?></small>
        </div>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            <?= e(__('common.save')) ?>
        </button>
    </div>
</form>

<?php if ($configured): ?>
<section class="pp-ai-config-panel" aria-labelledby="pp-mail-test-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-mail-test-title"><?= e(__('mail.send_test')) ?></h3>
            <p><?= e(__('mail.send_test_help')) ?></p>
        </div>
    </div>
    <form method="POST" action="<?= e(base_url('admin/settings/mail/test')) ?>" class="pp-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-form-group">
            <label for="pp-mail-test-to"><?= e(__('mail.send_to')) ?></label>
            <input type="email" id="pp-mail-test-to" name="test_to" maxlength="255"
                   value="<?= e($form['from_email']) ?>" placeholder="tu@correo.com">
            <small><?= e(__('mail.send_to_help')) ?></small>
        </div>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--secondary"><?= e(__('mail.send_test')) ?> →</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if (!empty($recent_log)): ?>
<section class="pp-ai-config-panel" aria-labelledby="pp-mail-log-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-mail-log-title"><?= e(__('mail.log_title')) ?></h3>
            <p><?= e(__('mail.log_help')) ?></p>
        </div>
    </div>
    <div class="pp-mail-log">
        <?php foreach ($recent_log as $row): ?>
            <?php $ok = ($row['status'] ?? '') === 'sent'; ?>
            <div class="pp-mail-log__row">
                <span class="pp-mail-log__badge <?= $ok ? 'is-ok' : 'is-fail' ?>"><?= e($ok ? __('mail.sent') : __('mail.failed')) ?></span>
                <span class="pp-mail-log__to"><?= e((string) $row['recipient']) ?></span>
                <span class="pp-mail-log__subject"><?= e((string) $row['subject']) ?></span>
                <span class="pp-mail-log__date"><?= e((string) $row['created_at']) ?></span>
                <?php if (!$ok && !empty($row['error'])): ?>
                    <span class="pp-mail-log__error"><?= e((string) $row['error']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
