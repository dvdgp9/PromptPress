<?php
/**
 * @var array $site
 * @var array $languages
 * @var array $activeLanguages
 * @var string $primaryLanguage
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
        <h3>Idiomas adicionales</h3>
        <p class="pp-form-help">
            Por defecto tu web tiene un solo idioma: el principal de arriba. Puedes añadir más y
            tener la misma web en varios idiomas a la vez. El idioma principal <strong>mantiene sus
            URLs actuales</strong> (<code>/contacto</code>); cada idioma adicional vive bajo su
            prefijo (<code>/fr/contact</code>), así que activar uno no cambia ni una URL de las que ya tienes.
        </p>

        <ul class="pp-lang-list">
            <?php foreach ($activeLanguages as $code): ?>
                <li class="pp-lang-item">
                    <span class="pp-lang-name"><?= e($languages[$code] ?? $code) ?></span>
                    <?php if ($code === $primaryLanguage): ?>
                        <span class="pp-lang-tag">principal · sin prefijo</span>
                    <?php else: ?>
                        <span class="pp-lang-tag">/<?= e($code) ?>/</span>
                        <button type="submit" form="pp-lang-remove-<?= e($code) ?>" class="pp-btn-link pp-btn-link--danger">
                            Desactivar
                        </button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php foreach ($activeLanguages as $code): ?>
            <?php if ($code !== $primaryLanguage): ?>
                <form id="pp-lang-remove-<?= e($code) ?>" method="post"
                      action="<?= e(base_url('admin/settings/languages/remove')) ?>" class="pp-hidden-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="code" value="<?= e($code) ?>">
                </form>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php $available = array_diff(array_keys($languages), $activeLanguages); ?>
        <?php if ($available !== []): ?>
            <form method="post" action="<?= e(base_url('admin/settings/languages/add')) ?>" class="pp-lang-add">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label for="pp-lang-new" class="pp-sr-only">Idioma a añadir</label>
                <select id="pp-lang-new" name="code">
                    <?php foreach ($available as $code): ?>
                        <option value="<?= e($code) ?>"><?= e($languages[$code]) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="pp-btn pp-btn--secondary">Añadir idioma</button>
            </form>
        <?php endif; ?>

        <p class="pp-form-help pp-form-help--muted">
            Desactivar un idioma <strong>nunca borra contenido</strong>: si todavía tiene páginas,
            te lo diremos y no se hará nada.
        </p>
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

    <?php /* UPD — Actualizar subiendo el ZIP a mano. */ ?>
    <div class="pp-update-manual">
        <h4>Actualizar desde un archivo</h4>
        <p class="pp-update-manual__intro">
            Si tienes el ZIP de una versión, súbelo aquí y la plataforma se actualiza sola:
            hace una copia de seguridad, sustituye el código y aplica los cambios de base de datos.
            <strong>No toca</strong> tu configuración, tus imágenes ni tus páginas.
            Mientras dura, los visitantes ven un aviso de «volvemos enseguida»; tú puedes seguir aquí.
        </p>

        <form method="POST" action="<?= e(base_url('admin/settings/upload-update')) ?>"
              enctype="multipart/form-data" class="pp-update-manual__form"
              onsubmit="return confirm('Se va a sustituir el código de la plataforma. Antes se guarda una copia de seguridad para poder volver. ¿Continuar?');">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label class="pp-update-file">
                <span>Elegir ZIP</span>
                <input type="file" name="package" accept=".zip,application/zip" required data-update-file>
            </label>
            <span class="pp-update-filename" data-update-filename>Ningún archivo seleccionado</span>

            <label class="pp-update-checksum">
                <span>Checksum SHA-256 <em>opcional</em></span>
                <input type="text" name="checksum" maxlength="80" placeholder="Pégalo si lo tienes, para comprobar que el ZIP llegó entero">
            </label>

            <button type="submit" class="pp-btn pp-btn--primary" data-update-submit disabled>Actualizar la plataforma</button>
        </form>
    </div>

    <?php /* UPD — Copias de seguridad para volver atrás. */ ?>
    <div class="pp-update-backups">
        <h4>Copias de seguridad</h4>
        <?php if (empty($updateBackups)): ?>
            <p class="pp-update-manual__intro">Todavía no hay ninguna. Se crea una automáticamente cada vez que actualizas.</p>
        <?php else: ?>
            <p class="pp-update-manual__intro">
                Restaurar devuelve los <strong>archivos</strong> al estado de esa copia: sustituye los que cambiaron,
                pero no borra los que la versión nueva añadió. La <strong>base de datos no vuelve atrás</strong>,
                así que úsalo para deshacer una actualización que ha roto algo, cuanto antes mejor.
                Antes de restaurar guardamos el estado actual, por si acaso.
            </p>
            <ul class="pp-update-backup-list">
                <?php foreach ($updateBackups as $b): ?>
                <li>
                    <div>
                        <strong><?= e((string) $b['created_at']) ?></strong>
                        <small><?= e((string) $b['name']) ?> · <?= e((string) $b['size_human']) ?></small>
                    </div>
                    <form method="POST" action="<?= e(base_url('admin/settings/restore-update')) ?>"
                          onsubmit="return confirm('Se van a restaurar los archivos de esta copia. El estado actual se guardará antes por si necesitas volver. ¿Continuar?');">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="backup" value="<?= e((string) $b['name']) ?>">
                        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm">Restaurar</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var input = document.querySelector('[data-update-file]');
    if (!input) return;
    var name = document.querySelector('[data-update-filename]');
    var submit = document.querySelector('[data-update-submit]');
    input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (name) name.textContent = f ? (f.name + ' · ' + Math.round(f.size / 1024 / 1024 * 10) / 10 + ' MB') : 'Ningún archivo seleccionado';
        if (submit) submit.disabled = !f;
    });
})();
</script>
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
