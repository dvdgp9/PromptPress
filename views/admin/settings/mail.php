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

$encLabels = ['tls' => 'TLS / STARTTLS (normalmente puerto 587)', 'ssl' => 'SSL (normalmente puerto 465)', 'none' => 'Sin cifrado (no recomendado)'];
?>

<?php \Core\View::start('title'); ?>Ajustes · Correo<?php \Core\View::end(); ?>

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
        gmail:   'Gmail / Google Workspace. Si tienes la verificación en dos pasos activada (lo normal), necesitas una "contraseña de aplicación", no tu contraseña habitual.',
        outlook: 'Outlook / Hotmail / Microsoft 365. Si usas un dominio propio con Microsoft 365, el servidor suele ser smtp.office365.com.',
        yahoo:   'Yahoo. Necesitas generar una "contraseña de aplicación" desde la seguridad de tu cuenta.',
        icloud:  'iCloud. Requiere una "contraseña específica para la app" generada en appleid.apple.com.',
        zoho:    'Zoho Mail. Usa el servidor de tu región (smtp.zoho.eu para Europa, smtp.zoho.com para EE. UU.).',
        gmx:     'GMX. Activa "Acceso POP3/IMAP" en los ajustes de tu cuenta GMX.',
        hosting: 'Tu correo está alojado en tu hosting (cPanel/Plesk). El servidor suele ser mail.tudominio.com. Si no funciona, mira en tu panel de hosting → "Configurar cliente de correo / Email accounts", ahí salen el servidor y el puerto exactos.'
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
            note.textContent = 'Introduce a mano los datos que te dé tu proveedor de correo.';
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
        <span class="pp-ai-kicker">Comunicaciones</span>
        <h2>Ajustes · Correo</h2>
        <p class="pp-page-intro">
            Conecta tu correo para que el sitio pueda enviar emails: avisos cuando alguien rellena un formulario y respuestas automáticas. Usa un buzón que ya tengas (el de tu dominio, Gmail, etc.).
        </p>
    </div>
    <div class="pp-ai-status-card <?= $configured ? 'is-ready' : 'is-empty' ?>">
        <span class="pp-ai-status-dot"></span>
        <strong><?= $configured ? 'Correo conectado' : 'Sin configurar' ?></strong>
        <small><?= $configured ? 'El sitio ya puede enviar correos.' : 'Configúralo abajo y envía una prueba.' ?></small>
    </div>
</div>

<nav class="pp-settings-tabs" aria-label="Secciones de ajustes">
    <a href="<?= e(base_url('admin/settings')) ?>">General</a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>">IA</a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>" class="is-active">Correo</a>
</nav>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="pp-alert pp-alert--error"><?= e($error) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong>Revisa lo siguiente:</strong>
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
                <h3 id="pp-mail-sender-title">¿Desde qué dirección se envían los correos?</h3>
                <p>Es la dirección que verán tus visitantes como remitente. Lo ideal es una de tu propio dominio (ej. <code>info@tudominio.com</code>).</p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-from">Dirección de remitente</label>
            <input type="email" id="pp-mail-from" name="from_email" required maxlength="255"
                   value="<?= e($form['from_email']) ?>" placeholder="info@tudominio.com">
            <small>Al escribirla, rellenamos por ti los datos técnicos si reconocemos tu proveedor.</small>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-from-name">Nombre del remitente <span class="pp-ai-optional-tag">opcional</span></label>
            <input type="text" id="pp-mail-from-name" name="from_name" maxlength="120"
                   value="<?= e($form['from_name']) ?>" placeholder="Ej: Academia Federico García Lorca">
            <small>El nombre que aparece junto a la dirección. Si lo dejas vacío, se usa la dirección.</small>
        </div>
    </section>

    <section class="pp-ai-config-panel" aria-labelledby="pp-mail-server-title">
        <div class="pp-ai-section-head">
            <div>
                <h3 id="pp-mail-server-title">Conexión con tu correo</h3>
                <p>Estos son los datos del servidor de envío. Si reconocemos tu proveedor los rellenamos solos; si no, elígelo en la lista o míralos en el panel de tu hosting.</p>
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-provider">¿Qué proveedor de correo usas?</label>
            <select id="pp-mail-provider" name="provider_hint">
                <option value="">Detectar automáticamente</option>
                <option value="gmail">Gmail / Google Workspace</option>
                <option value="outlook">Outlook / Hotmail</option>
                <option value="m365">Microsoft 365 (dominio propio)</option>
                <option value="yahoo">Yahoo</option>
                <option value="icloud">iCloud</option>
                <option value="zoho">Zoho</option>
                <option value="ionos">IONOS</option>
                <option value="ovh">OVH</option>
                <option value="hostinger">Hostinger</option>
                <option value="hosting">Mi hosting (cPanel / Plesk)</option>
                <option value="manual">Otro / introducir a mano</option>
            </select>
            <small id="pp-mail-provider-note" class="pp-design-hint" hidden></small>
        </div>

        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-mail-host">Servidor de correo saliente</label>
                <input type="text" id="pp-mail-host" name="host" required maxlength="255"
                       value="<?= e($form['host']) ?>" placeholder="smtp.tudominio.com">
            </div>
            <div class="pp-form-group">
                <label for="pp-mail-port">Puerto</label>
                <input type="number" id="pp-mail-port" name="port" required min="1" max="65535"
                       value="<?= e($form['port']) ?>" placeholder="587">
            </div>
        </div>

        <div class="pp-form-group">
            <label for="pp-mail-enc">Cifrado de la conexión</label>
            <select id="pp-mail-enc" name="encryption">
                <?php foreach ($encLabels as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= $form['encryption'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small>Si una opción no funciona, prueba la otra: TLS suele ir con el puerto 587 y SSL con el 465.</small>
        </div>

        <div class="pp-form-grid-2">
            <div class="pp-form-group">
                <label for="pp-mail-user">Usuario</label>
                <input type="text" id="pp-mail-user" name="user" maxlength="255"
                       value="<?= e($form['user']) ?>" placeholder="Normalmente tu dirección completa" autocomplete="off">
                <small>Casi siempre es tu propia dirección de correo.</small>
            </div>
            <div class="pp-form-group">
                <label for="pp-mail-pass">Contraseña</label>
                <input type="password" id="pp-mail-pass" name="pass" autocomplete="new-password"
                       placeholder="<?= $has_password ? '•••••••••••••• (deja vacío para no cambiar)' : 'Contraseña del correo' ?>">
                <small>
                    <?php if ($has_password): ?>
                        Ya hay una guardada. Déjalo en blanco para mantenerla.
                    <?php else: ?>
                        En Gmail/Outlook/Yahoo con verificación en dos pasos, usa una "contraseña de aplicación".
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <div class="pp-ai-security-note">
            <strong>Guardado seguro</strong>
            <span>La contraseña se cifra con AES-256-GCM y no vuelve a mostrarse en pantalla.</span>
        </div>

        <div class="pp-form-group pp-ai-test-row">
            <label class="pp-checkbox-label">
                <input type="checkbox" name="test_on_save" value="1" checked>
                Enviarme un correo de prueba al guardar
            </label>
            <small>Comprueba que todo funciona enviando un email a tu propia dirección de remitente.</small>
        </div>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            Guardar
        </button>
    </div>
</form>

<?php if ($configured): ?>
<section class="pp-ai-config-panel" aria-labelledby="pp-mail-test-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-mail-test-title">Enviar un correo de prueba</h3>
            <p>Manda un email de comprobación para asegurarte de que llega bien.</p>
        </div>
    </div>
    <form method="POST" action="<?= e(base_url('admin/settings/mail/test')) ?>" class="pp-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-form-group">
            <label for="pp-mail-test-to">Enviar a</label>
            <input type="email" id="pp-mail-test-to" name="test_to" maxlength="255"
                   value="<?= e($form['from_email']) ?>" placeholder="tu@correo.com">
            <small>Por defecto, a tu propia dirección de remitente.</small>
        </div>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--secondary">Enviar correo de prueba →</button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if (!empty($recent_log)): ?>
<section class="pp-ai-config-panel" aria-labelledby="pp-mail-log-title" style="margin-top:2rem;">
    <div class="pp-ai-section-head">
        <div>
            <h3 id="pp-mail-log-title">Últimos envíos</h3>
            <p>Registro de los correos más recientes y su resultado.</p>
        </div>
    </div>
    <div class="pp-mail-log">
        <?php foreach ($recent_log as $row): ?>
            <?php $ok = ($row['status'] ?? '') === 'sent'; ?>
            <div class="pp-mail-log__row">
                <span class="pp-mail-log__badge <?= $ok ? 'is-ok' : 'is-fail' ?>"><?= $ok ? 'Enviado' : 'Falló' ?></span>
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
