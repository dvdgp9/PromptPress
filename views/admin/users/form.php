<?php
/**
 * EQUIPO T5 — Alta y edición de una cuenta.
 *
 * @var ?array $user   null en un alta
 * @var array  $roles
 * @var array  $errors ya traducidos por el controlador
 * @var array  $old    lo que se acaba de teclear, cuando hubo errores
 * @var string $csrf
 */
\Core\View::extend('admin/layout');

$isNew  = $user === null;
$old    = $old ?? [];
$value  = static fn(string $field, string $fallback = ''): string
    => (string) ($old[$field] ?? ($user[$field] ?? $fallback));
$action = $isNew
    ? base_url('admin/users')
    : base_url('admin/users/' . (int) $user['id']);
$title  = $isNew ? __('users.new') : __('users.edit_title', ['nombre' => (string) $user['username']]);
?>

<?php \Core\View::start('title'); ?><?= e($title) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e($title) ?></h2>
    <a href="<?= e(base_url('admin/users')) ?>" class="pp-btn pp-btn--secondary"><?= e(__('common.back')) ?></a>
</div>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert--error">
    <ul class="pp-alert__list">
        <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="<?= e($action) ?>" class="pp-form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <section class="pp-form-card">
        <div class="pp-form-row">
            <div class="pp-form-group">
                <label for="username"><?= e(__('users.field.username')) ?></label>
                <input type="text" id="username" name="username" required minlength="3" maxlength="50"
                       pattern="[a-zA-Z0-9._\-]+" autocomplete="off"
                       value="<?= e($value('username')) ?>">
                <small><?= e(__('users.field.username_help')) ?></small>
            </div>
            <div class="pp-form-group">
                <label for="email"><?= e(__('users.field.email')) ?></label>
                <input type="email" id="email" name="email" required maxlength="255"
                       autocomplete="off" value="<?= e($value('email')) ?>">
            </div>
        </div>

        <div class="pp-form-group">
            <label for="role"><?= e(__('users.field.role')) ?></label>
            <select id="role" name="role" required>
                <?php foreach ($roles as $role): ?>
                <option value="<?= e($role) ?>" <?= $value('role', 'redactor') === $role ? 'selected' : '' ?>>
                    <?= e(__('users.role.' . $role)) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small><?= e(__('users.field.role_help')) ?></small>
        </div>

        <ul class="pp-users-roles-legend">
            <?php foreach ($roles as $role): ?>
            <li>
                <strong><?= e(__('users.role.' . $role)) ?></strong>
                <span><?= e(__('users.role.' . $role . '.summary')) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="pp-form-card">
        <div class="pp-form-card__head">
            <h3><?= e(__('users.password.title')) ?></h3>
            <p><?= e($isNew ? __('users.password.new_help') : __('users.password.edit_help')) ?></p>
        </div>

        <div class="pp-form-row">
            <div class="pp-form-group">
                <label for="password"><?= e(__('users.field.password')) ?></label>
                <input type="password" id="password" name="password"
                       <?= $isNew ? 'required' : '' ?> minlength="8" autocomplete="new-password">
            </div>
            <div class="pp-form-group">
                <label for="password_confirm"><?= e(__('users.field.password_confirm')) ?></label>
                <input type="password" id="password_confirm" name="password_confirm"
                       <?= $isNew ? 'required' : '' ?> minlength="8" autocomplete="new-password">
            </div>
        </div>
    </section>

    <div class="pp-form-actions">
        <button type="submit" class="pp-btn pp-btn--primary">
            <?= e($isNew ? __('users.create_submit') : __('common.save')) ?>
        </button>
    </div>
</form>
