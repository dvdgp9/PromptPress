<?php

namespace App\Controllers\Admin;

use App\Services\AdminI18n;
use App\Services\LanguageService;
use App\Services\Permissions;
use App\Services\UserService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\RememberToken;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * EQUIPO T6 — Mi cuenta.
 *
 * Es la única pantalla del panel abierta a cualquier rol (`OPEN_SEGMENTS` en
 * `Permissions`): todo el mundo tiene que poder cambiarse la contraseña, sea
 * administrador o redactor.
 *
 * Aquí vive ahora el idioma del panel, que estaba suelto en Ajustes. Desde que
 * Ajustes es solo para administradores, dejarlo allí significaba que un editor
 * no podía cambiar el idioma de SU panel — y es lo más personal que hay en
 * toda la configuración.
 */
class ProfileController
{
    public function index(): void
    {
        $this->render();
    }

    /** Nombre y email. La contraseña y el idioma van por su propio formulario. */
    public function update(): void
    {
        CSRF::check();
        $userId = $this->requireUserId();

        $input = [
            'username' => trim((string) Request::post('username', '')),
            'email'    => trim((string) Request::post('email', '')),
            // El rol no se toca desde aquí, pero `validate()` lo exige: se le
            // pasa el que ya tiene para que no lo dé por inválido.
            'role'     => (string) (UserService::find($userId)['role'] ?? Permissions::ROLE_REDACTOR),
        ];

        $errors = UserService::validate($input, $userId);
        if ($errors !== []) {
            $this->render(array_map(static fn(string $k): string => __($k), $errors), $input);
            return;
        }

        UserService::updateOwnProfile($userId, $input);
        Session::flash('success', __('profile.flash.saved'));
        Response::redirect(base_url('admin/profile'));
    }

    /**
     * Mi contraseña. Se pide la actual: sin eso, cualquiera que se encuentre
     * una sesión abierta se queda con la cuenta para siempre.
     */
    public function password(): void
    {
        CSRF::check();
        $userId = $this->requireUserId();

        $current = (string) Request::post('current_password', '');
        $new     = (string) Request::post('password', '');
        $confirm = (string) Request::post('password_confirm', '');

        if (!UserService::verifyPassword($userId, $current)) {
            Session::flash('error', __('profile.err.current_wrong'));
            Response::redirect(base_url('admin/profile'));
        }
        if (mb_strlen($new) < UserService::MIN_PASSWORD) {
            Session::flash('error', __('users.err.password_len'));
            Response::redirect(base_url('admin/profile'));
        }
        if ($new !== $confirm) {
            Session::flash('error', __('users.err.password_match'));
            Response::redirect(base_url('admin/profile'));
        }

        // Revoca TODOS los tokens de esta persona, que es lo que se quiere si
        // le han robado la cuenta...
        $hadCookie = RememberToken::hasCookie();
        UserService::setPassword($userId, $new);

        // ...pero el navegador desde el que la está cambiando no debería
        // perder el suyo: él no ha desmarcado nada.
        if ($hadCookie) {
            RememberToken::issue($userId);
        }

        Session::flash('success', __('profile.flash.password_changed'));
        Response::redirect(base_url('admin/profile'));
    }

    /**
     * Idioma del panel. Movido aquí desde Ajustes (ADMIN-I18N T0.4): es una
     * preferencia de quien mira, no una opción del sitio.
     */
    public function language(): void
    {
        CSRF::check();
        $userId = $this->requireUserId();

        $code = strtolower(trim((string) Request::post('panel_language', '')));
        $inherit = $code === '';

        if (!$inherit && !in_array($code, AdminI18n::LOCALES, true)) {
            Session::flash('error', __('settings.panel_language.unavailable'));
            Response::redirect(base_url('admin/profile'));
        }

        Database::execute('UPDATE users SET language = ? WHERE id = ?', [$inherit ? null : $code, $userId]);

        // Sin esto el aviso saldría en el idioma ANTERIOR: `locale()` ya está
        // resuelto y cacheado para esta petición.
        AdminI18n::forget();
        if (!$inherit) {
            AdminI18n::setLocale($code);
        }

        Session::flash('success', $inherit
            ? __('settings.panel_language.saved_inherit')
            : __('settings.panel_language.saved', ['idioma' => LanguageService::label($code)]));

        Response::redirect(base_url('admin/profile'));
    }

    /**
     * @param string[] $errors
     * @param array<string, mixed> $old
     */
    private function render(array $errors = [], array $old = []): void
    {
        $userId = $this->requireUserId();
        $user = UserService::find($userId);
        if ($user === null) {
            Auth::logout();
            Response::redirect(base_url('admin/login'));
        }

        $siteLanguage = 'es';
        $site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
        if ($site !== null) {
            $siteLanguage = LanguageService::primaryFor((int) $site['id']);
        }

        $panelLanguages = [];
        foreach (AdminI18n::LOCALES as $code) {
            $panelLanguages[$code] = LanguageService::label($code);
        }

        View::send('admin/profile/index', [
            'user'            => $user,
            'errors'          => $errors,
            'old'             => $old,
            'panelLanguages'  => $panelLanguages,
            'panelLanguage'   => (string) ($user['language'] ?? ''),
            'panelLanguageInherited' => LanguageService::label(
                AdminI18n::resolveFrom(null, $siteLanguage, null)
            ),
            'csrf'            => CSRF::token(),
        ]);
    }

    private function requireUserId(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            Response::redirect(base_url('admin/login'));
        }
        return $userId;
    }
}
