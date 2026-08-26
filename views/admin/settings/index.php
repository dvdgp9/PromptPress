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

<?php \Core\View::start('title'); ?><?= e(__('settings.title')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('settings.title')) ?></h2>
</div>

<nav class="pp-settings-tabs" aria-label="<?= e(__('settings.tabs_aria')) ?>">
    <a href="<?= e(base_url('admin/settings')) ?>" class="is-active"><?= e(__('settings.tab.general')) ?></a>
    <a href="<?= e(base_url('admin/settings/ai')) ?>"><?= e(__('settings.tab.ai')) ?></a>
    <a href="<?= e(base_url('admin/settings/mail')) ?>"><?= e(__('settings.tab.mail')) ?></a>
</nav>

<p class="pp-page-intro">
    <?= e(__('settings.intro')) ?>
    <a href="<?= e(base_url('admin/onboarding?step=1')) ?>" class="pp-settings-onboarding-link" title="<?= e(__('settings.review_onboarding_title')) ?>"><?= e(__('settings.review_onboarding')) ?></a>
</p>

<?php if ($notice): ?>
    <div class="pp-alert pp-alert--success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="pp-alert pp-alert--error">
        <strong><?= e(__('settings.errors_intro')) ?></strong>
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
        <h3><?= e(__('settings.identity')) ?></h3>

        <div class="pp-form-group <?= isset($errors['name']) ? 'has-error' : '' ?>">
            <label for="pp-site-name"><?= e(__('settings.site_name')) ?> <span class="pp-req">*</span></label>
            <input type="text" id="pp-site-name" name="name"
                   value="<?= e((string) ($site['name'] ?? '')) ?>"
                   maxlength="255" required>
            <?php if (isset($errors['name'])): ?>
                <small class="pp-err"><?= e($errors['name']) ?></small>
            <?php endif; ?>
        </div>

        <div class="pp-form-group <?= isset($errors['url']) ? 'has-error' : '' ?>">
            <label for="pp-site-url"><?= e(__('settings.site_url')) ?> <span class="pp-req">*</span></label>
            <input type="url" id="pp-site-url" name="url"
                   value="<?= e((string) ($site['url'] ?? '')) ?>"
                   maxlength="500" placeholder="https://tudominio.com" required>
            <small><?= e(__('settings.site_url_help')) ?></small>
            <?php if (isset($errors['url'])): ?>
                <small class="pp-err"><?= e($errors['url']) ?></small>
            <?php endif; ?>
        </div>
    </div>

    <div class="pp-form-card">
        <h3><?= e(__('settings.localization')) ?></h3>

        <div class="pp-form-row">
            <div class="pp-form-group <?= isset($errors['language']) ? 'has-error' : '' ?>">
                <label for="pp-site-language"><?= e(__('settings.primary_language')) ?></label>
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
                <label for="pp-site-timezone"><?= e(__('settings.timezone')) ?></label>
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
        <h3><?= e(__('settings.extra_languages')) ?></h3>
        <p class="pp-form-help">
            <?= __('settings.extra_languages_help.html') ?>
        </p>

        <ul class="pp-lang-list">
            <?php foreach ($activeLanguages as $code): ?>
                <li class="pp-lang-item">
                    <span class="pp-lang-name"><?= e($languages[$code] ?? $code) ?></span>
                    <?php if ($code === $primaryLanguage): ?>
                        <span class="pp-lang-tag"><?= e(__('settings.lang_primary_tag')) ?></span>
                    <?php else: ?>
                        <span class="pp-lang-tag">/<?= e($code) ?>/</span>
                        <button type="submit" form="pp-lang-remove-<?= e($code) ?>" class="pp-btn-link pp-btn-link--danger">
                            <?= e(__('settings.lang_disable')) ?>
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
                <label for="pp-lang-new" class="pp-sr-only"><?= e(__('settings.lang_add_label')) ?></label>
                <select id="pp-lang-new" name="code">
                    <?php foreach ($available as $code): ?>
                        <option value="<?= e($code) ?>"><?= e($languages[$code]) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="pp-btn pp-btn--secondary"><?= e(__('settings.lang_add')) ?></button>
            </form>
        <?php endif; ?>

        <p class="pp-form-help pp-form-help--muted">
            <?= __('settings.lang_disable_help.html') ?>
        </p>
    </div>

    <div class="pp-form-card">
        <h3><?= e(__('settings.editorial')) ?></h3>

        <div class="pp-form-group">
            <label for="pp-article-template"><?= e(__('settings.article_template')) ?></label>
            <select id="pp-article-template" name="article_template">
                <?php foreach ($articleTemplateOptions as $slug => $label): ?>
                    <option value="<?= e($slug) ?>" <?= (($articleTemplate ?? 'classic') === $slug) ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(__('settings.article_template_help')) ?></small>
        </div>
    </div>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <span class="pp-icon pp-icon--check"></span>
            <?= e(__('settings.save')) ?>
        </button>
    </div>
</form>

<?php /* ADMIN-I18N — El idioma del PANEL, que no es el de la web.
       Va en su propio formulario y FUERA del de arriba: es una preferencia de
       quien está logueado, no un ajuste del sitio, y guardarla no debe arrastrar
       el resto de campos. */ ?>
<section class="pp-form-card">
    <h3><?= e(__('settings.panel_language')) ?></h3>
    <p class="pp-form-help">
        <?= __('settings.panel_language_help.html') ?>
    </p>

    <form method="POST" action="<?= e(base_url('admin/settings/panel-language')) ?>" class="pp-lang-add">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="pp-panel-language" class="pp-sr-only"><?= e(__('settings.panel_language')) ?></label>
        <select id="pp-panel-language" name="panel_language">
            <option value="">
                <?= e(__('settings.panel_language_inherit', ['idioma' => $panelLanguageInherited])) ?>
            </option>
            <?php foreach ($panelLanguages as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= ($panelLanguage === $code) ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn--secondary"><?= e(__('settings.panel_language_submit')) ?></button>
    </form>

    <p class="pp-form-help pp-form-help--muted">
        <?= e(__('settings.panel_language_note', ['idiomas' => implode(', ', $panelLanguages)])) ?>
    </p>
</section>

<?php if (is_array($updateStatus ?? null)): ?>
<section class="pp-form-card pp-update-card">
    <div class="pp-form-card__head">
        <h3><?= e(__('settings.updates')) ?></h3>
        <form method="POST" action="<?= e(base_url('admin/settings/check-updates')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--secondary"><?= e(__('settings.update.check_now')) ?></button>
        </form>
    </div>
    <div class="pp-update-grid">
        <p><strong><?= e(__('settings.update.installed')) ?>:</strong> <?= e((string) ($updateStatus['current_version'] ?? PP_VERSION)) ?></p>
        <p><strong><?= e(__('settings.update.latest')) ?>:</strong> <?= e((string) (($updateStatus['latest_version'] ?? null) ?: '—')) ?></p>
        <p><strong><?= e(__('settings.update.checked_at')) ?>:</strong> <?= e((string) (($updateStatus['checked_at'] ?? null) ?: __('settings.update.never'))) ?></p>
        <p><strong><?= e(__('settings.update.channel')) ?>:</strong> <?= e((string) (config('updates.channel', 'stable'))) ?></p>
        <p><strong><?= e(__('settings.update.checksum')) ?>:</strong> <?= e(!empty($updateStatus['checksum_sha256']) ? __('settings.update.available') : __('settings.update.not_reported')) ?></p>
        <p><strong><?= e(__('settings.update.signature')) ?>:</strong> <?= e((!empty($updateStatus['signature']) && trim((string) config('updates.signature_key', '')) !== '') ? __('settings.update.verified_hmac') : __('settings.update.not_active')) ?></p>
    </div>
    <div class="pp-alert <?= !empty($updateStatus['has_update']) ? 'pp-alert--info' : 'pp-alert--success' ?>">
        <?= e((string) ($updateStatus['message'] ?? '')) ?>
    </div>
    <?php if (!empty($updateStatus['has_update']) && !empty($updateStatus['download_url'])): ?>
        <form method="POST" action="<?= e(base_url('admin/settings/apply-update')) ?>" class="pp-update-actions">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('settings.update.apply')) ?></button>
        </form>
    <?php endif; ?>
    <?php if (!empty($updateStatus['changelog_url'])): ?>
        <p><a href="<?= e((string) $updateStatus['changelog_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('settings.update.changelog')) ?></a></p>
    <?php endif; ?>

    <?php /* UPD — Actualizar subiendo el ZIP a mano. */ ?>
    <div class="pp-update-manual">
        <h4><?= e(__('settings.update.manual_title')) ?></h4>
        <p class="pp-update-manual__intro">
            <?= __('settings.update.manual_intro.html') ?>
        </p>

        <form method="POST" action="<?= e(base_url('admin/settings/upload-update')) ?>"
              enctype="multipart/form-data" class="pp-update-manual__form"
              onsubmit="return confirm(<?= e(json_encode(__('settings.update.confirm_upload'), JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label class="pp-update-file">
                <span><?= e(__('settings.update.choose_zip')) ?></span>
                <input type="file" name="package" accept=".zip,application/zip" required data-update-file>
            </label>
            <span class="pp-update-filename" data-update-filename><?= e(__('settings.update.no_file')) ?></span>

            <label class="pp-update-checksum">
                <span><?= e(__('settings.update.checksum_label')) ?> <em><?= e(__('settings.update.optional')) ?></em></span>
                <input type="text" name="checksum" maxlength="80" placeholder="<?= e(__('settings.update.checksum_placeholder')) ?>">
            </label>

            <button type="submit" class="pp-btn pp-btn--primary" data-update-submit disabled><?= e(__('settings.update.do_update')) ?></button>
        </form>
    </div>

    <?php /* UPD — Copias de seguridad para volver atrás. */ ?>
    <div class="pp-update-backups">
        <h4><?= e(__('settings.backups')) ?></h4>
        <?php if (empty($updateBackups)): ?>
            <p class="pp-update-manual__intro"><?= e(__('settings.backups.empty')) ?></p>
        <?php else: ?>
            <p class="pp-update-manual__intro">
                <?= __('settings.backups.help.html') ?>
            </p>
            <ul class="pp-update-backup-list">
                <?php foreach ($updateBackups as $b): ?>
                <li>
                    <div>
                        <strong><?= e((string) $b['created_at']) ?></strong>
                        <small><?= e((string) $b['name']) ?> · <?= e((string) $b['size_human']) ?></small>
                    </div>
                    <form method="POST" action="<?= e(base_url('admin/settings/restore-update')) ?>"
                          onsubmit="return confirm(<?= e(json_encode(__('settings.backups.confirm_restore'), JSON_UNESCAPED_UNICODE)) ?>);">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="backup" value="<?= e((string) $b['name']) ?>">
                        <button type="submit" class="pp-btn pp-btn--secondary pp-btn--sm"><?= e(__('settings.backups.restore')) ?></button>
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
        if (name) name.textContent = f ? (f.name + ' · ' + Math.round(f.size / 1024 / 1024 * 10) / 10 + ' MB') : <?= json_encode(__('settings.update.no_file'), JSON_UNESCAPED_UNICODE) ?>;
        if (submit) submit.disabled = !f;
    });
})();
</script>
<?php endif; ?>

<section class="pp-danger-zone" id="pp-reset-site">
    <div>
        <span><?= e(__('settings.danger_zone')) ?></span>
        <h3><?= e(__('settings.reset.title')) ?></h3>
        <p><?= e(__('settings.reset.text')) ?></p>
    </div>
    <button type="button" class="pp-btn pp-btn--danger" data-reset-open><?= e(__('settings.reset.button')) ?></button>
</section>

<div class="pp-reset-modal" data-reset-modal hidden>
    <div class="pp-reset-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pp-reset-title">
        <h3 id="pp-reset-title"><?= e(__('settings.reset.modal_title')) ?></h3>
        <p><?= e(__('settings.reset.you_will_lose')) ?></p>
        <ul>
            <li><?= e(__('settings.reset.count_pages', ['n' => (int) ($resetCounts['pages'] ?? 0)])) ?></li>
            <li><?= e(__('settings.reset.count_documents', ['n' => (int) ($resetCounts['documents'] ?? 0)])) ?></li>
            <li><?= e(__('settings.reset.count_messages', ['n' => (int) ($resetCounts['messages'] ?? 0)])) ?></li>
            <li><?= e(__('settings.reset.all_memory')) ?></li>
        </ul>
        <p><?= e(__('settings.reset.only_action')) ?></p>
        <p><?= e(__('settings.reset.confirm_prompt', ['sitio' => (string) ($site['name'] ?? '')])) ?></p>
        <form method="POST" action="<?= e(base_url('admin/settings/reset-site')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="text" name="confirmation" data-reset-confirm autocomplete="off">
            <div class="pp-reset-modal__actions">
                <button type="button" class="pp-btn pp-btn--secondary" data-reset-close><?= e(__('common.cancel')) ?></button>
                <button type="submit" class="pp-btn pp-btn--danger" data-reset-submit data-site-name="<?= e((string) ($site['name'] ?? '')) ?>" disabled><?= e(__('settings.reset.confirm_button')) ?></button>
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
