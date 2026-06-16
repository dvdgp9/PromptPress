<?php
/**
 * Pantalla de login del admin.
 * @var string|null $error
 * @var string $identifier
 * @var string $csrf
 */
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión — PromptPress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..900&family=Geist+Mono:wght@400..700&display=swap">
    <link rel="stylesheet" href="<?= e(base_url('admin/assets/css/admin.css')) ?>">
</head>
<body class="pp-admin pp-login-page">

    <main class="pp-login">
        <div class="pp-login__card">
            <div class="pp-login__brand">
                <span class="pp-sidebar__logo">P</span>
                <h1>PromptPress</h1>
            </div>

            <p class="pp-login__subtitle">Inicia sesión para acceder al panel</p>

            <?php if (!empty($error)): ?>
            <div class="pp-alert pp-alert--error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php
            $flashSuccess = \Core\Session::flash('success');
            if ($flashSuccess): ?>
            <div class="pp-alert pp-alert--success"><?= e($flashSuccess) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= e(base_url('admin/login')) ?>" class="pp-login__form" novalidate>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div class="pp-form-group">
                    <label for="identifier">Usuario o email</label>
                    <input type="text" id="identifier" name="identifier"
                           value="<?= e($identifier) ?>"
                           autocomplete="username"
                           autofocus required>
                </div>

                <div class="pp-form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" class="pp-btn pp-btn--primary pp-btn--block">
                    Iniciar sesión
                </button>
            </form>

            <div class="pp-login__footer">
                <small>v<?= e(PP_VERSION) ?></small>
            </div>
        </div>
    </main>

</body>
</html>
