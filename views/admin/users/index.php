<?php
/**
 * EQUIPO T5 — El equipo que entra al panel.
 *
 * @var array  $users      filas de users
 * @var ?int   $currentId  quién está mirando
 * @var int    $adminCount cuántos administradores quedan
 * @var array  $roles
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
?>

<?php \Core\View::start('title'); ?><?= e(__('users.title')) ?><?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2><?= e(__('users.title')) ?></h2>
    <a href="<?= e(base_url('admin/users/create')) ?>" class="pp-btn pp-btn--primary">
        <?= e(__('users.new')) ?>
    </a>
</div>

<p class="pp-page-intro"><?= e(__('users.intro')) ?></p>

<table class="pp-table pp-users-table">
    <thead>
        <tr>
            <th><?= e(__('users.col.user')) ?></th>
            <th><?= e(__('users.col.role')) ?></th>
            <th><?= e(__('users.col.can')) ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $id = (int) $u['id'];
        $role = (string) $u['role'];
        $isMe = $id === $currentId;
        // Un administrador solo se puede borrar si queda otro detrás.
        $isLastAdmin = $role === 'admin' && $adminCount <= 1;
        $roleBadge = match ($role) {
            'admin'    => 'pp-badge--success',
            'editor'   => 'pp-badge--muted',
            default    => 'pp-badge--muted',
        };
    ?>
        <tr>
            <td>
                <a href="<?= e(base_url('admin/users/' . $id . '/edit')) ?>">
                    <strong><?= e($u['username']) ?></strong>
                </a>
                <?php if ($isMe): ?>
                <span class="pp-badge pp-badge--muted pp-users-you"><?= e(__('users.you')) ?></span>
                <?php endif; ?>
                <div class="pp-users-email"><?= e($u['email']) ?></div>
            </td>
            <td><span class="pp-badge <?= $roleBadge ?>"><?= e(__('users.role.' . $role)) ?></span></td>
            <td class="pp-users-can"><?= e(__('users.role.' . $role . '.summary')) ?></td>
            <td class="pp-users-actions">
                <a href="<?= e(base_url('admin/users/' . $id . '/edit')) ?>" class="pp-btn pp-btn--secondary pp-btn--sm">
                    <?= e(__('common.edit')) ?>
                </a>
                <?php if ($isMe): ?>
                <span class="pp-users-locked" title="<?= e(__('users.err.self_delete')) ?>"><?= e(__('common.delete')) ?></span>
                <?php elseif ($isLastAdmin): ?>
                <span class="pp-users-locked" title="<?= e(__('users.err.last_admin')) ?>"><?= e(__('common.delete')) ?></span>
                <?php else: ?>
                <form method="POST" action="<?= e(base_url('admin/users/' . $id . '/delete')) ?>"
                      class="pp-users-delete-form"
                      onsubmit="return confirm('<?= e(__('users.confirm_delete', ['nombre' => $u['username']])) ?>');">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button type="submit" class="pp-btn pp-btn--ghost pp-btn--danger-text pp-btn--sm">
                        <?= e(__('common.delete')) ?>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
