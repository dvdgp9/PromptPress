<?php
/**
 * EQUIPO T6 — Mi cuenta. La única pantalla que ve cualquier rol.
 *
 * @var array  $user
 * @var array  $errors  ya traducidos
 * @var array  $old
 * @var array  $panelLanguages
 * @var string $panelLanguage
 * @var string $panelLanguageInherited
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
$value = static fn(string $f): string => (string) ($old[$f] ?? ($user[$f] ?? ''));
$role  = (string) $user['role'];
?>

<?php \Core\View::start('title'); ?><?= e(__('profile.title')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('profile.title')) ?></h2>
</div>

<p class="pp-page-intro"><?= e(__('profile.intro')) ?></p>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <ul class="pp-alert__list">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<section class="pp-form-card">
    <div class="pp-form-card__head">
        <h3><?= e(__('profile.identity')) ?></h3>
        <p><?= e(__('profile.role_is', ['rol' => __('users.role.' . $role)])) ?></p>
    </div>

    <form method="POST" action="<?= e(base_url('admin/profile')) ?>" class="pp-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-form-row">
            <div class="pp-form-group">
                <label for="username"><?= e(__('users.field.username')) ?></label>
                <input type="text" id="username" name="username" required minlength="3" maxlength="50"
                       pattern="[a-zA-Z0-9._\-]+" value="<?= e($value('username')) ?>">
            </div>
            <div class="pp-form-group">
                <label for="email"><?= e(__('users.field.email')) ?></label>
                <input type="email" id="email" name="email" required maxlength="255"
                       value="<?= e($value('email')) ?>">
            </div>
        </div>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('common.save')) ?></button>
        </div>
    </form>
</section>

<section class="pp-form-card">
    <div class="pp-form-card__head">
        <h3><?= e(__('profile.password.title')) ?></h3>
        <p><?= e(__('profile.password.help')) ?></p>
    </div>

    <form method="POST" action="<?= e(base_url('admin/profile/password')) ?>" class="pp-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-form-group">
            <label for="current_password"><?= e(__('profile.field.current_password')) ?></label>
            <input type="password" id="current_password" name="current_password" required
                   autocomplete="current-password">
        </div>
        <div class="pp-form-row">
            <div class="pp-form-group">
                <label for="password"><?= e(__('profile.field.new_password')) ?></label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password">
            </div>
            <div class="pp-form-group">
                <label for="password_confirm"><?= e(__('users.field.password_confirm')) ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
                       autocomplete="new-password">
            </div>
        </div>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--primary"><?= e(__('profile.password.submit')) ?></button>
        </div>
    </form>
</section>

<?php /* ADMIN-I18N — Vivía en Ajustes, pero Ajustes es solo para
   administradores: un editor no podía cambiar el idioma de SU panel. */ ?>
<section class="pp-form-card">
    <div class="pp-form-card__head">
        <h3><?= e(__('settings.panel_language')) ?></h3>
        <p><?= e(__('profile.language.help')) ?></p>
    </div>

    <form method="POST" action="<?= e(base_url('admin/profile/language')) ?>" class="pp-lang-add">
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
