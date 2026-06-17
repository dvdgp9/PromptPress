<?php
/**
 * @var array $site
 * @var array $languages
 * @var array $timezones
 * @var array $errors
 * @var ?string $notice
 * @var string $csrf
 * @var array|null $updateStatus
 * @var string $articleTemplate
 * @var array $articleTemplateOptions
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?>Ajustes · General<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Ajustes · General</h2>
</div>

<nav class="pp-settings-tabs" aria-label="Secciones de ajustes">
    <a href="<?= e(base_url('admin/settings')) ?>" class="is-active">General</a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>">IA</a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>">Correo</a>
</nav>

<p class="pp-page-intro">
    Configura los datos base del sitio. Estos valores se usan en el panel, en el render público
    y como contexto para mantener coherente la experiencia generada.
    <a href="<?= e(base_url('admin/onboarding?step=1')) ?>" class="pp-settings-onboarding-link" title="Abre el onboarding sin borrar páginas ni documentos">Revisar onboarding</a>
</p>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong>Revisa los errores del formulario:</strong>
        <ul style="margin:8px 0 0 20px">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" action="<?= e(base_url('admin/settings')) ?>" class="pp-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="pp-form-card">
        <h3>Identidad del sitio</h3>

        <div class="pp-form-group <?= isset($errors['name']) ? 'has-error' : '' ?>">
            <label for="pp-site-name">Nombre del sitio <span class="pp-req">*</span></label>
            <input type="text" id="pp-site-name" name="name"
                   value="<?= e((string) ($site['name'] ?? '')) ?>"
                   maxlength="255" required>
            <?php if (isset($errors['name'])): ?>
                <small class="pp-err"><?= e($errors['name']) ?></small>
            <?php endif; ?>
        </div>

        <div class="pp-form-group <?= isset($errors['url']) ? 'has-error' : '' ?>">
            <label for="pp-site-url">URL pública <span class="pp-req">*</span></label>
            <input type="url" id="pp-site-url" name="url"
                   value="<?= e((string) ($site['url'] ?? '')) ?>"
                   maxlength="500" placeholder="https://tudominio.com" required>
            <small>Se usa para enlaces absolutos, SEO y futuras integraciones públicas.</small>
            <?php if (isset($errors['url'])): ?>
                <small class="pp-err"><?= e($errors['url']) ?></small>
            <?php endif; ?>
        </div>
    </div>

    <div class="pp-form-card">
        <h3>Localización</h3>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($errors['language']) ? 'has-error' : '' ?>">
                <label for="pp-site-language">Idioma principal</label>
                <select id="pp-site-language" name="language">
                    <?php foreach ($languages as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= (($site['language'] ?? 'es') === $code) ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['language'])): ?>
                    <small class="pp-err"><?= e($errors['language']) ?></small>
                <?php endif; ?>
            </div>

            <div class="pp-form-group <?= isset($errors['timezone']) ? 'has-error' : '' ?>">
                <label for="pp-site-timezone">Zona horaria</label>
                <select id="pp-site-timezone" name="timezone">
                    <?php foreach ($timezones as $tz => $label): ?>
                        <option value="<?= e($tz) ?>" <?= (($site['timezone'] ?? 'Europe/Madrid') === $tz) ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['timezone'])): ?>
                    <small class="pp-err"><?= e($errors['timezone']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pp-form-card">
        <h3>Contenido editorial</h3>

        <div class="pp-form-group">
            <label for="pp-article-template">Estilo de entradas</label>
            <select id="pp-article-template" name="article_template">
                <?php foreach ($articleTemplateOptions as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= (($articleTemplate ?? 'classic') === $slug) ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Controla la presentación pública de las entradas del blog. El contenido y el editor de bloques no cambian.</small>
        </div>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            Guardar ajustes
        </button>
    </div>
</form>

<?php if (is_array($updateStatus ?? null)): ?>
<section class="pp-form-card pp-update-card">
    <div class="pp-form-card__head">
        <h3>Actualizaciones</h3>
        <form method="POST" action="<?= e(base_url('admin/settings/check-updates')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--secondary">Comprobar ahora</button>
        </form>
    </div>
    <div class="pp-update-grid">
        <p><strong>Versión instalada:</strong> <?= e((string) ($updateStatus['current_version'] ?? PP_VERSION)) ?></p>
        <p><strong>Última conocida:</strong> <?= e((string) (($updateStatus['latest_version'] ?? null) ?: '—')) ?></p>
        <p><strong>Última comprobación:</strong> <?= e((string) (($updateStatus['checked_at'] ?? null) ?: 'Nunca')) ?></p>
        <p><strong>Canal:</strong> <?= e((string) (config('updates.channel', 'stable'))) ?></p>
        <p><strong>Checksum:</strong> <?= !empty($updateStatus['checksum_sha256']) ? 'Disponible' : 'No informado' ?></p>
        <p><strong>Firma:</strong> <?= (!empty($updateStatus['signature']) && trim((string) config('updates.signature_key', '')) !== '') ? 'Verificada (HMAC)' : 'No activa' ?></p>
    </div>
    <div class="pp-alert <?= !empty($updateStatus['has_update']) ? 'pp-alert--info' : 'pp-alert--success' ?>">
        <?= e((string) ($updateStatus['message'] ?? '')) ?>
    </div>
    <?php if (!empty($updateStatus['has_update']) && !empty($updateStatus['download_url'])): ?>
        <form method="POST" action="<?= e(base_url('admin/settings/apply-update')) ?>" class="pp-update-actions">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--primary">Aplicar actualización</button>
        </form>
    <?php endif; ?>
    <?php if (!empty($updateStatus['changelog_url'])): ?>
        <p><a href="<?= e((string) $updateStatus['changelog_url']) ?>" target="_blank" rel="noopener noreferrer">Ver changelog</a></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="pp-danger-zone" id="pp-reset-site">
    <div>
        <span>Zona peligrosa</span>
        <h3>Empezar de cero</h3>
        <p>Borra todo el contenido del sitio (páginas, memoria, diseño, documentos, mensajes recibidos). Tu cuenta y la API de IA se conservan. Después tendrás que pasar de nuevo por el onboarding.</p>
    </div>
    <button type="button" class="pp-btn pp-btn--danger" data-reset-open>Reiniciar el sitio</button>
</section>

<div class="pp-reset-modal" data-reset-modal hidden>
    <div class="pp-reset-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-reset-title">
        <h3 id="pp-reset-title">Esto borra todo el contenido del sitio</h3>
        <p>Vas a perder:</p>
        <ul>
            <li><?= (int) ($resetCounts['pages'] ?? 0) ?> páginas</li>
            <li><?= (int) ($resetCounts['documents'] ?? 0) ?> documentos</li>
            <li><?= (int) ($resetCounts['messages'] ?? 0) ?> mensajes recibidos</li>
            <li>Toda la memoria del negocio</li>
        </ul>
        <p>Esta es la única acción de esta pantalla que borra páginas y documentos.</p>
        <p>Para confirmar, escribe el nombre del sitio: "<?= e((string) ($site['name'] ?? '')) ?>"</p>
        <form method="POST" action="<?= e(base_url('admin/settings/reset-site')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="text" name="confirmation" data-reset-confirm autocomplete="off">
            <div class="pp-reset-modal__actions">
                <button type="button" class="pp-btn pp-btn--secondary" data-reset-close>Cancelar</button>
                <button type="submit" class="pp-btn pp-btn--danger" data-reset-submit data-site-name="<?= e((string) ($site['name'] ?? '')) ?>" disabled>Reiniciar definitivamente</button>
            </div>
        </form>
    </div>
</div>

<?php \Core\View::start('scripts'); ?>
<script>
(function () {
    var modal = document.querySelector('[data-reset-modal]');
    var open = document.querySelector('[data-reset-open]');
    var close = document.querySelector('[data-reset-close]');
    var input = document.querySelector('[data-reset-confirm]');
    var submit = document.querySelector('[data-reset-submit]');
    if (!modal || !open || !input || !submit) return;
    open.addEventListener('click', function () { modal.hidden = false; input.focus(); });
    close && close.addEventListener('click', function () { modal.hidden = true; });
    modal.addEventListener('click', function (event) { if (event.target === modal) modal.hidden = true; });
    input.addEventListener('input', function () {
        submit.disabled = input.value !== (submit.dataset.siteName || '');
    });
})();
</script>
<?php \Core\View::end(); ?>
